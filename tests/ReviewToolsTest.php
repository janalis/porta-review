<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\ReviewPlugin\Model\Anchor;
use Porta\ReviewPlugin\Model\Comment;
use Porta\ReviewPlugin\Model\Review;
use Porta\ReviewPlugin\Model\SourceKind;
use Porta\ReviewPlugin\Model\Thread;
use Porta\ReviewPlugin\ReviewTools;
use Porta\ReviewPlugin\Source\ReviewSource;
use Porta\ReviewPlugin\Source\ReviewStore;
use Porta\ReviewPlugin\Tests\Support\StubSource;
use Porta\Tests\Support\CallsTools;
use Porta\Tests\Support\PumpsLoop;
use Revolt\EventLoop;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** `review_threads`, `review_reply` and `review_comment`, over a stub source. */
final class ReviewToolsTest extends TestCase
{
    use CallsTools;
    use PumpsLoop;

    private StubSource $source;

    private ReviewStore $store;

    private PluginApi $api;

    #[\Override]
    protected function setUp(): void
    {
        $this->source = new StubSource();
        $source = $this->source;
        $this->store = new ReviewStore(static fn (\Closure $onDone) => $onDone($source));
        $settings = new PluginSettings('/p', []);
        $this->api = new PluginManager(new EventDispatcher(), $settings)->api('review', '/p', $settings);
        new ReviewTools($this->api, $this->store)->register();
    }

    private function load(?Review $review, ?string $error = null): void
    {
        $this->store->refresh();
        $this->source->answer($review, $error);
    }

    private static function review(): Review
    {
        return new Review(
            SourceKind::GitHub,
            '42',
            'Cart totals',
            [
                // Resolved FIRST: leaving it out must not end the list.
                new Thread('closed', new Anchor('src/Cart.php', 20), [new Comment('alice', 'Typo.')], resolved: true, outdated: true),
                new Thread('open', new Anchor('src/Cart.php', 9), [new Comment('alice', 'Use array_sum.', '2026-09-28T10:00:00Z'), new Comment('bob', 'Done.')]),
            ],
            [new Comment('carol', 'Overall fine.')],
            url: 'https://example.test/pull/42',
        );
    }

    /** @param array<string, mixed> $arguments */
    private function refused(string $tool, array $arguments, string $says): void
    {
        try {
            $this->callTool($this->api, $tool, $arguments);
            $this->fail($tool.' should have refused');
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $this->assertSame($says, $e->getMessage());
        }
    }

    public function testThreadsLeaveTheResolvedOutUnlessAsked(): void
    {
        $this->load(self::review());

        $this->assertSame(
            [
                'review' => self::review()->label(),
                'title' => 'Cart totals',
                'url' => 'https://example.test/pull/42',
                'threads' => [[
                    'id' => 'open',
                    'path' => 'src/Cart.php',
                    'line' => 9,
                    'resolved' => false,
                    'outdated' => false,
                    'comments' => [
                        ['author' => 'alice', 'body' => 'Use array_sum.', 'createdAt' => '2026-09-28T10:00:00Z'],
                        ['author' => 'bob', 'body' => 'Done.', 'createdAt' => ''],
                    ],
                ]],
                'conversation' => [['author' => 'carol', 'body' => 'Overall fine.', 'createdAt' => '']],
            ],
            $this->callTool($this->api, 'threads'),
        );

        $all = $this->callTool($this->api, 'threads', ['includeResolved' => true]);
        $this->assertIsArray($all);
        $this->assertSame(['closed', 'open'], \array_column($all['threads'], 'id'));
        $this->assertTrue($all['threads'][0]['outdated']);
    }

    public function testWithoutAReviewItSaysWhy(): void
    {
        $this->load(null, 'gh is not signed in');
        $this->refused('threads', [], 'gh is not signed in');

        $empty = new ReviewStore(static fn (\Closure $onDone) => $onDone(null));
        $empty->refresh();
        $settings = new PluginSettings('/p', []);
        $this->api = new PluginManager(new EventDispatcher(), $settings)->api('review', '/p', $settings);
        new ReviewTools($this->api, $empty)->register();
        $this->refused('threads', [], 'This branch has no review');
        $this->refused('comment', ['path' => 'a', 'line' => 1, 'body' => 'x'], 'This branch has no review');
    }

    public function testACallDuringALoadWaitsForItToSettle(): void
    {
        $this->store->refresh();
        $source = $this->source;
        EventLoop::delay(0.02, static fn () => $source->answer(self::review()));

        $threads = $this->callTool($this->api, 'threads');

        $this->assertIsArray($threads);
        $this->assertSame('Cart totals', $threads['title'], 'it waited for the load instead of refusing');
    }

    public function testAReadRightAfterAWriteSeesTheReloadedReview(): void
    {
        $this->load(self::review());
        $this->callTool($this->api, 'reply', ['thread' => 'open', 'body' => 'Done']);
        $this->assertTrue($this->store->loading, 'the write reloads');
        $source = $this->source;
        $renamed = new Review(SourceKind::GitHub, '42', 'Renamed', self::review()->threads);
        EventLoop::delay(0.02, static fn () => $source->answer($renamed));

        $threads = $this->callTool($this->api, 'threads');

        $this->assertIsArray($threads);
        $this->assertSame('Renamed', $threads['title']);
    }

    public function testALoadThatNeverSettlesIsGivenUpOn(): void
    {
        $this->store->refresh(); // and never answered
        $settings = new PluginSettings('/p', []);
        $api = new PluginManager(new EventDispatcher(), $settings)->api('review', '/p', $settings);
        new ReviewTools($api, $this->store, loadTimeout: 0.05)->register();

        try {
            $this->callTool($api, 'threads');
            $this->fail('a load that never settles must not hang the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('The review is still loading after 0.05s — ask again later', $e->getMessage());
        }
    }

    public function testResolveAndReopenGoToTheSource(): void
    {
        $this->load(self::review());

        $this->assertSame('Resolved open', $this->callTool($this->api, 'resolve', ['thread' => 'open']));
        $this->source->answer(self::review());
        $this->assertSame('Reopened closed', $this->callTool($this->api, 'resolve', ['thread' => 'closed', 'resolved' => false]));
        $this->source->answer(self::review());
        $this->assertSame(['resolve open', 'reopen closed'], $this->source->writes);

        $this->assertSame('closed is already resolved', $this->callTool($this->api, 'resolve', ['thread' => 'closed', 'resolved' => true]));
        $this->assertSame('open is already open', $this->callTool($this->api, 'resolve', ['thread' => 'open', 'resolved' => false]));
        $this->assertCount(2, $this->source->writes, 'nothing to change, nothing written');
        $this->refused('resolve', ['thread' => 'nope'], 'No thread "nope" in this review');
    }

    public function testAReplyGoesToTheSourceAndItsComplaintComesBack(): void
    {
        $this->load(self::review());

        $this->assertSame('Replied to open', $this->callTool($this->api, 'reply', ['thread' => 'open', 'body' => 'Fixed in abc123']));
        $this->assertSame(['reply open: Fixed in abc123'], $this->source->writes);
        $this->assertTrue($this->store->loading, 'a write reloads, as the view\'s does');

        $this->source->answer(self::review());
        $this->source->writeError = 'Resource not accessible';
        $this->refused('reply', ['thread' => 'open', 'body' => 'x'], 'Resource not accessible');
        $this->refused('reply', ['thread' => 'nope', 'body' => 'x'], 'No thread "nope" in this review');
        $this->refused('reply', ['thread' => 'open', 'body' => ' '], 'body is required');
        $this->refused('reply', ['body' => 'x'], 'thread is required');
    }

    public function testACommentStartsAThreadOnALine(): void
    {
        $this->load(self::review());

        $this->assertSame('Commented on src/Cart.php:3', $this->callTool($this->api, 'comment', ['path' => 'src/Cart.php', 'line' => 3, 'body' => 'Why?']));
        $this->assertSame(['comment src/Cart.php:3: Why?'], $this->source->writes);

        $this->refused('comment', ['path' => 'src/Cart.php', 'line' => 0, 'body' => 'x'], 'line is a positive integer');
        $this->refused('comment', ['path' => 'src/Cart.php', 'line' => '3', 'body' => 'x'], 'line is a positive integer');
    }

    public function testAWriteThatAnswersLaterIsWaitedFor(): void
    {
        $slow = new class implements ReviewSource {
            /** @var list<\Closure(?string): void> */
            public array $waiting = [];

            public function load(\Closure $onDone): void
            {
                $onDone(new Review(SourceKind::Local, 'l', 'Local', [new Thread('open', new Anchor('a.php', 1), [new Comment('a', 'x')])]), error: null);
            }

            public function reply(Review $review, Thread $thread, string $body, \Closure $onDone): void
            {
                $this->waiting[] = $onDone;
            }

            public function setResolved(Review $review, Thread $thread, bool $resolved, \Closure $onDone): void {}

            public function comment(Review $review, Anchor $anchor, string $body, \Closure $onDone): void {}

            public function converse(Review $review, string $body, \Closure $onDone): void {}
        };
        $store = new ReviewStore(static fn (\Closure $onDone) => $onDone($slow));
        $store->refresh();
        $settings = new PluginSettings('/p', []);
        $api = new PluginManager(new EventDispatcher(), $settings)->api('review', '/p', $settings);
        new ReviewTools($api, $store)->register();
        EventLoop::delay(0.02, static function () use ($slow): void {
            $slow->waiting[0](null);
        });

        $this->assertSame('Replied to open', $this->callTool($api, 'reply', ['thread' => 'open', 'body' => 'x']));
    }
}
