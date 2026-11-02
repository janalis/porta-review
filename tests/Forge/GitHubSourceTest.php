<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests\Forge;

use PHPUnit\Framework\TestCase;
use Porta\ReviewPlugin\Forge\GitHubSource;
use Porta\ReviewPlugin\Forge\RemoteUrl;
use Porta\ReviewPlugin\Forge\RepoFacts;
use Porta\ReviewPlugin\Model\Anchor;
use Porta\ReviewPlugin\Model\Review;
use Porta\ReviewPlugin\Model\Side;
use Porta\ReviewPlugin\Model\SourceKind;
use Porta\ReviewPlugin\Model\Thread;
use Porta\ReviewPlugin\Tests\Support\FakeForge;
use Porta\ReviewPlugin\Tests\Support\UsesFakeForge;
use Porta\Tests\Support\PumpsLoop;

/**
 * A pull request read from `gh api graphql` and answered through it. The
 * fixture is a real answer from GitHub's API — the shape, the nulls, a thread
 * gone outdated — with the people and their words replaced, plus two threads
 * in the same shape: one current, one on the LEFT side by a deleted account.
 */
final class GitHubSourceTest extends TestCase
{
    use PumpsLoop;
    use UsesFakeForge;

    private FakeForge $forge;

    private function source(?string $remote = 'git@github.com:acme/app.git'): GitHubSource
    {
        $this->forge = new FakeForge();

        return new GitHubSource(new RepoFacts('feature/tidy', null === $remote ? null : RemoteUrl::parse($remote)), $this->forge->transport());
    }

    private function load(GitHubSource $source): ?Review
    {
        $done = false;
        $review = null;
        $source->load(static function (?Review $r, ?string $error) use (&$done, &$review): void {
            $done = true;
            $review = $r;
        });
        $this->await(static function () use (&$done): bool {
            return $done;
        }, what: 'the load');

        return $review;
    }

    /** @param \Closure(\Closure(?string): void): void $verb */
    private function write(\Closure $verb): ?string
    {
        $done = false;
        $error = null;
        $verb(static function (?string $e) use (&$done, &$error): void {
            $done = true;
            $error = $e;
        });
        $this->await(static function () use (&$done): bool {
            return $done;
        }, what: 'the write');

        return $error;
    }

    public function testThePullRequestOfTheBranchIsReadWhole(): void
    {
        $source = $this->source();
        $this->forge->answerFixture('github-pull-request.json');

        $review = $this->load($source);

        $this->assertInstanceOf(Review::class, $review);
        $this->assertSame([SourceKind::GitHub, '12', 'Tidy the dumper', 'PR #12'], [$review->kind, $review->id, $review->title, $review->label()]);
        $this->assertSame('https://github.com/acme/app/pull/12', $review->url);
        $this->assertSame(['f8e2d141185d86c0db4d833b0ee23af9a379ff47', 'e25895125a800deba1db2e7f3274ccb2eecde3e1'], [$review->baseSha, $review->headSha]);
        $this->assertSame('PR_kwDOAAb9Ss8AAAABFgX71A', $review->handle, 'the node id a review-level comment is addressed to');
        $this->assertCount(3, $review->conversation);
        $this->assertSame(['bob', "Comment 1.\nSecond line.", '2026-09-28T17:59:54Z'], [$review->conversation[0]->author, $review->conversation[0]->body, $review->conversation[0]->createdAt]);

        [$outdated, $current, $left] = $review->threads;
        $this->assertTrue($outdated->resolved);
        $this->assertTrue($outdated->outdated, '`line: null` — GitHub no longer places it');
        $this->assertSame(1555, $outdated->anchor->line, 'so it keeps the line it was written on');
        $this->assertSame(['PRRT_current', 42, Side::New, false, false], [$current->id, $current->anchor->line, $current->anchor->side, $current->resolved, $current->outdated]);
        $this->assertSame('bob: Rename this. · 2', $current->summary());
        $this->assertSame([Side::Old, 'ghost'], [$left->anchor->side, $left->first()->author], 'LEFT is the old side; a deleted account is "ghost", as on GitHub');

        $call = $this->forge->calls()[0];
        $this->assertSame('graphql', $call['argv'][3]);
        $this->assertSame(['owner' => 'acme', 'name' => 'app', 'branch' => 'feature/tidy'], $this->forge->body(0)['variables']);
    }

    public function testAThreadWithoutACommentAPathOrALineIsLeftOut(): void
    {
        $source = $this->source();
        $node = static fn (array $over): array => [
            ...['id' => 'x', 'path' => 'a.php', 'line' => 1, 'diffSide' => 'RIGHT', 'comments' => ['nodes' => [['author' => ['login' => 'a'], 'body' => 'b', 'createdAt' => '']]]],
            ...$over,
        ];
        $this->forge->answerJson([
            'data' => [
                'repository' => [
                    'pullRequests' => [
                        'nodes' => [[
                            'number' => 1,
                            'title' => 't',
                            'reviewThreads' => ['nodes' => [$node(['comments' => ['nodes' => []]]), $node(['path' => null]), $node(['line' => null, 'originalLine' => null]), $node(['id' => 'kept'])]],
                        ]],
                    ],
                ],
            ],
        ]);

        $review = $this->load($source);

        $this->assertSame(['kept'], \array_map(static fn (Thread $t): string => $t->id, $review->threads ?? []));
        $this->assertSame([], $review?->conversation, 'no comments connection: no conversation');
    }

    public function testNoPullRequestNoRemoteAndAFailureAreThreeAnswers(): void
    {
        $source = $this->source();
        $this->forge->answerJson(['data' => ['repository' => ['pullRequests' => ['nodes' => []]]]]);
        $this->assertNull($this->load($source), 'no open pull request for the branch');

        $this->assertNull($this->load($this->source(null)), 'no remote: nothing asked');
        $this->assertSame([], $this->forge->calls());

        $source = $this->source();
        $this->forge->answer('{"message":"Bad credentials"}', 1);
        $error = 'unset';
        $done = false;
        $source->load(static function (?Review $r, ?string $e) use (&$error, &$done): void {
            $error = $e;
            $done = true;
        });
        $this->await(static function () use (&$done): bool {
            return $done;
        });
        $this->assertSame('Bad credentials', $error);
    }

    public function testEachWriteIsTheMutationOrRequestItNeeds(): void
    {
        $source = $this->source();
        $this->forge->answerFixture('github-pull-request.json');
        $review = $this->load($source);
        \assert(null !== $review, description: 'loaded');
        $thread = $review->threads[1];
        foreach (\range(start: 1, end: 5) as $ignored) {
            $this->forge->answerJson(['data' => []]);
        }

        $this->assertNull($this->write(static fn (\Closure $done) => $source->reply($review, $thread, 'Fixed.', $done)));
        $this->assertStringContainsString('addPullRequestReviewThreadReply', (string) $this->forge->body(1)['query']);
        $this->assertSame(['id' => 'PRRT_current', 'body' => 'Fixed.'], $this->forge->body(1)['variables']);

        $this->write(static fn (\Closure $done) => $source->setResolved($review, $thread, true, $done));
        $this->assertStringContainsString('resolveReviewThread(input: {threadId: $id})', (string) $this->forge->body(2)['query']);
        $this->assertStringNotContainsString('$body', (string) $this->forge->body(2)['query'], 'no body declared when none is sent');
        $this->write(static fn (\Closure $done) => $source->setResolved($review, $thread, false, $done));
        $this->assertStringContainsString('unresolveReviewThread', (string) $this->forge->body(3)['query']);

        $this->write(static fn (\Closure $done) => $source->converse($review, 'Ready?', $done));
        $this->assertStringContainsString('addComment', (string) $this->forge->body(4)['query']);
        $this->assertSame(['id' => 'PR_kwDOAAb9Ss8AAAABFgX71A', 'body' => 'Ready?'], $this->forge->body(4)['variables']);

        $this->write(static fn (\Closure $done) => $source->comment($review, new Anchor('src/A.php', 9, Side::Old), 'Why?', $done));
        $this->assertSame(['api', '--method', 'POST', 'repos/acme/app/pulls/12/comments'], \array_slice($this->forge->calls()[5]['argv'], offset: 0, length: 4));
        $this->assertSame(['body' => 'Why?', 'commit_id' => 'e25895125a800deba1db2e7f3274ccb2eecde3e1', 'path' => 'src/A.php', 'line' => 9, 'side' => 'LEFT'], $this->forge->body(5));
    }

    public function testANewCommentOnTheNewSideIsRight(): void
    {
        $source = $this->source();
        $this->forge->answerFixture('github-pull-request.json')->answer('{"message":"Unprocessable"}', 1);
        $review = $this->load($source);
        \assert(null !== $review, description: 'loaded');

        $error = $this->write(static fn (\Closure $done) => $source->comment($review, new Anchor('src/A.php', 3), 'Hm', $done));

        $this->assertSame('RIGHT', $this->forge->body(1)['side']);
        $this->assertSame('Unprocessable', $error, 'a refused write says why');
    }
}
