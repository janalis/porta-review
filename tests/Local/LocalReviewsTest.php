<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests\Local;

use PHPUnit\Framework\TestCase;
use Porta\ReviewPlugin\Local\LocalReviews;
use Porta\ReviewPlugin\Local\LocalSource;
use Porta\ReviewPlugin\Model\Anchor;
use Porta\ReviewPlugin\Model\Comment;
use Porta\ReviewPlugin\Model\Review;
use Porta\ReviewPlugin\Model\Side;
use Porta\ReviewPlugin\Model\SourceKind;
use Porta\Tests\Support\TempProject;

/**
 * The directory an agent writes and the IDE answers in: read as a review,
 * written only by adding files, so neither side ever rewrites the other's.
 */
final class LocalReviewsTest extends TestCase
{
    private TempProject $project;

    private int $clock = 0;

    private function reviews(array $files = []): LocalReviews
    {
        $this->project = new TempProject($files);

        return new LocalReviews($this->project->dir, fn (): string => \sprintf('2026T%04d', ++$this->clock));
    }

    public function testADirectoryReadsAsAReview(): void
    {
        $reviews = $this->reviews([
            '.porta/reviews/agent/review.json' => '{"title":"Tidy the loop","base":"main"}',
            '.porta/reviews/agent/t1/thread.json' => '{"path":"/src/A.php","line":4,"side":"old","lineText":"$x;"}',
            '.porta/reviews/agent/t1/2026T01--claude.md' => "Use array_sum.\n",
            '.porta/reviews/agent/t1/2026T02--pierre.md' => 'Done',
            '.porta/reviews/agent/t1/resolved' => '',
            '.porta/reviews/agent/t1/.2026T03--x.md.tmp' => 'half written',
            '.porta/reviews/agent/t2/thread.json' => '{"path":"src/B.php","line":0}',
            '.porta/reviews/agent/t2/2026T01--claude.md' => 'bad line',
            '.porta/reviews/agent/t3/thread.json' => '{"path":"src/B.php","line":2}',
            '.porta/reviews/agent/t4/2026T01--claude.md' => 'no thread.json yet',
            '.porta/reviews/agent/conversation/2026T01--claude.md' => 'Overall fine',
            '.porta/reviews/agent/conversation/nameless.md' => 'who?',
            '.porta/reviews/agent/notes.txt' => 'not a thread',
        ]);

        $review = $reviews->read('agent');

        $this->assertInstanceOf(Review::class, $review);
        $this->assertSame([SourceKind::Local, 'agent', 'Tidy the loop', 'main'], [$review->kind, $review->id, $review->title, $review->baseSha]);
        $this->assertCount(1, $review->threads, 'a line 0, a thread with no comment yet and one with no thread.json are skipped');
        $thread = $review->threads[0];
        $this->assertSame(['t1', 'src/A.php', 4, Side::Old, '$x;', true], [
            $thread->id,
            $thread->anchor->path,
            $thread->anchor->line,
            $thread->anchor->side,
            $thread->anchor->lineText,
            $thread->resolved,
        ]);
        $this->assertSame(
            [
                ['claude', 'Use array_sum.', '2026T01'],
                ['pierre', 'Done',           '2026T02'],
            ],
            \array_map(static fn ($c): array => [$c->author, $c->body, $c->createdAt], $thread->comments),
            'in file-name order, the temporary sibling of a write left out',
        );
        $this->assertSame(
            [
                ['claude', 'Overall fine'],
                ['?',      'who?'],
            ],
            \array_map(static fn (Comment $c): array => [$c->author, $c->body], $review->conversation),
        );
        $this->assertNull($reviews->read('missing'));
    }

    public function testATitleAndABaseAreOptional(): void
    {
        $reviews = $this->reviews(['.porta/reviews/quick/t1/thread.json' => '{"path":"a","line":1}', '.porta/reviews/quick/t1/1--a.md' => 'x', '.porta/reviews/quick/review.json' => '{"title":""}']);

        $review = $reviews->read('quick');

        $this->assertSame(['quick', null, Side::New, null], [$review?->title, $review?->baseSha, $review?->threads[0]->anchor->side, $review?->threads[0]->anchor->lineText]);
    }

    public function testIdsAreTheMostRecentlyTouchedFirst(): void
    {
        $reviews = $this->reviews(['.porta/reviews/old/t/1--a.md' => 'x', '.porta/reviews/new/t/1--a.md' => 'x']);
        \touch($this->project->path('.porta/reviews/old/t/1--a.md'), \time() - 100);
        \touch($this->project->path('.porta/reviews/old/t'), \time() - 100);
        \touch($this->project->path('.porta/reviews/old'), \time() - 100);

        $this->assertSame(['new', 'old'], $reviews->ids());
        $this->assertSame([], $this->reviews()->ids(), 'no directory, no reviews');
    }

    public function testWritesAddFilesAndNeverTouchAnotherWritersOnes(): void
    {
        $reviews = $this->reviews(['.porta/reviews/agent/t1/thread.json' => '{"path":"a","line":1}', '.porta/reviews/agent/t1/2026T00--claude.md' => 'first']);

        $this->assertTrue($reviews->append('agent', 't1', 'Pierre H.', "Done\n"));
        $this->assertTrue($reviews->append('agent', LocalReviews::CONVERSATION, "a/b\\c\n", 'Hi'), 'the conversation directory is made on first use');
        $this->assertTrue($reviews->append('agent', LocalReviews::CONVERSATION, ' .. ', 'Anyone?'));
        $this->assertFalse($reviews->append('agent', 'gone', 'x', 'y'), 'a thread that is not there');

        $thread = $reviews->open('agent', new Anchor('src/C.php', 7, Side::New, 'return 1;'), 'Pierre', 'Why 1?');
        $this->assertSame('t2026T0004', $thread);
        $this->assertSame(
            ['path' => 'src/C.php', 'line' => 7, 'side' => 'new', 'lineText' => 'return 1;'],
            \json_decode((string) \file_get_contents($this->project->path('.porta/reviews/agent/t2026T0004/thread.json')), associative: true, flags: \JSON_THROW_ON_ERROR),
        );
        $this->assertNotNull($reviews->open('agent', new Anchor('src/C.php', 8), 'Pierre', 'And 2?'));

        $this->assertTrue($reviews->resolve('agent', 't1', true));
        $this->assertFileExists($this->project->path('.porta/reviews/agent/t1/resolved'));
        $this->assertTrue($reviews->resolve('agent', 't1', false));
        $this->assertTrue($reviews->resolve('agent', 't1', false), 'unresolving twice is still unresolved');
        $this->assertFileDoesNotExist($this->project->path('.porta/reviews/agent/t1/resolved'));
        $this->assertFalse($reviews->resolve('agent', 'gone', true));

        $this->assertSame('first', \file_get_contents($this->project->path('.porta/reviews/agent/t1/2026T00--claude.md')), 'the agent\'s file is as it wrote it');
        $this->assertSame("Done\n", \file_get_contents($this->project->path('.porta/reviews/agent/t1/2026T0001--Pierre H.md')), 'a trailing dot trimmed off the name');
        $review = $reviews->read('agent');
        $this->assertSame(['a-b-c', 'anonymous'], \array_map(static fn ($c): string => $c->author, $review->conversation ?? []), 'an author is made safe to be a file name');
        $this->assertCount(3, $review->threads ?? []);
    }

    /** `.porta/` comes with a clone: a thread that is a link is neither written nor unmarked through. */
    public function testAThreadThatIsALinkIsNotWrittenThrough(): void
    {
        $elsewhere = new TempProject(['resolved' => '']);
        $reviews = $this->reviews(['.porta/reviews/agent/.keep' => '']);
        \symlink($elsewhere->dir, $this->project->path('.porta/reviews/agent/t1'));

        $this->assertFalse($reviews->append('agent', 't1', 'Pierre', 'Hi'));
        $this->assertFalse($reviews->resolve('agent', 't1', true));
        $this->assertFalse($reviews->resolve('agent', 't1', false));
        $this->assertSame(['resolved'], \array_values(\array_diff((array) \scandir($elsewhere->dir), ['.', '..'])));
    }

    public function testABrokenThreadHidesNoneAfterIt(): void
    {
        $reviews = $this->reviews([
            '.porta/reviews/agent/t1/thread.json' => '{"path":"","line":3}',
            '.porta/reviews/agent/t1/2026T01--claude.md' => 'no path',
            '.porta/reviews/agent/t2/thread.json' => '{"path":"a","line":3}',
            '.porta/reviews/agent/t2/2026T01--claude.md' => 'fine',
        ]);

        $this->assertSame(['t2'], \array_map(static fn ($t): string => $t->id, $reviews->read('agent')->threads ?? []));
    }

    public function testWrittenMetaKeepsWhatElseItSaidAndReadsLikeAHandWrittenFile(): void
    {
        $reviews = $this->reviews(['.porta/reviews/agent/review.json' => '{"base":"main"}']);

        $this->assertTrue($reviews->describe('agent', 'Tidy ü/ä'));
        $this->assertSame("{\n    \"base\": \"main\",\n    \"title\": \"Tidy ü/ä\"\n}\n", \file_get_contents($this->project->path('.porta/reviews/agent/review.json')));

        $thread = $reviews->open('agent', new Anchor('src/ü.php', 2, Side::New), 'x', 'y');
        $this->assertSame("{\n    \"path\": \"src/ü.php\",\n    \"line\": 2,\n    \"side\": \"new\"\n}\n", \file_get_contents($this->project->path(".porta/reviews/agent/{$thread}/thread.json")));
    }

    public function testATitleThatCannotBeEncodedFailsLikeAWrite(): void
    {
        $reviews = $this->reviews();

        $this->assertFalse($reviews->describe('agent', "bad \xC3"));
        $this->assertNull($reviews->open('agent', new Anchor("bad \xC3", 1), 'x', 'y'));
    }

    public function testAFailedWriteSaysSo(): void
    {
        $reviews = $this->reviews(['.porta/reviews/agent/t1/thread.json' => '{"path":"a","line":1}']);
        \chmod($this->project->path('.porta/reviews/agent/t1'), permissions: 0o500);
        \chmod($this->project->path('.porta/reviews/agent'), permissions: 0o500);

        try {
            $this->assertFalse($reviews->append('agent', 't1', 'x', 'y'));
            $this->assertFalse($reviews->resolve('agent', 't1', true));
            $this->assertNull($reviews->open('agent', new Anchor('a', 1), 'x', 'y'));
        } finally {
            \chmod($this->project->path('.porta/reviews/agent/t1'), permissions: 0o700);
            \chmod($this->project->path('.porta/reviews/agent'), permissions: 0o700);
        }
        \touch($this->project->path('.porta/reviews/agent/t1/resolved'));
        \chmod($this->project->path('.porta/reviews/agent/t1'), permissions: 0o500);
        try {
            $this->assertFalse($reviews->resolve('agent', 't1', false), 'the marker could not be removed');
        } finally {
            \chmod($this->project->path('.porta/reviews/agent/t1'), permissions: 0o700);
        }
    }

    public function testTheFingerprintMovesWithEveryWrite(): void
    {
        $reviews = $this->reviews(['.porta/reviews/agent/t1/thread.json' => '{"path":"a","line":1}']);
        $before = $reviews->fingerprint();

        $this->assertSame($before, $reviews->fingerprint(), 'stable while nothing changes');
        $reviews->append('agent', 't1', 'x', 'y');
        $this->assertNotSame($before, $reviews->fingerprint());
    }

    public function testTheFingerprintSeesASizeAnMtimeAndANameChangeAlone(): void
    {
        $reviews = $this->reviews(['.porta/reviews/agent/t1/1--a.md' => 'abc']);
        $file = $this->project->path('.porta/reviews/agent/t1/1--a.md');
        \touch($file, mtime: 1_700_000_000);
        $before = $reviews->fingerprint();

        \file_put_contents($file, data: 'abcd');
        \touch($file, mtime: 1_700_000_000);
        \clearstatcache();
        $this->assertNotSame($before, $grown = $reviews->fingerprint(), 'longer, same mtime');

        \touch($file, mtime: 1_700_000_001);
        \clearstatcache();
        $this->assertNotSame($grown, $touched = $reviews->fingerprint(), 'same size, newer');

        // The directory's own mtime moves with a rename: put it back, so only the name differs.
        $dir = $this->project->path('.porta/reviews/agent/t1');
        \clearstatcache();
        $dirMtime = (int) \filemtime($dir);
        \rename($file, $dir.'/1--b.md');
        \touch($dir, $dirMtime);
        \clearstatcache();
        $this->assertNotSame($touched, $reviews->fingerprint(), 'same size and mtime, another name');
    }

    public function testTheDefaultClockSortsToTheMicrosecond(): void
    {
        $project = new TempProject(['.porta/reviews/r/t/thread.json' => '{"path":"a","line":1}']);
        $reviews = new LocalReviews($project->dir);

        $reviews->append('r', 't', 'a', 'one');
        $reviews->append('r', 't', 'a', 'two');

        $this->assertSame(['one', 'two'], \array_map(static fn (Comment $c): string => $c->body, $reviews->read('r')?->threads[0]->comments ?? []));
        $this->assertMatchesRegularExpression('/^\d{8}T\d{6}\.\d{6}Z$/', $reviews->read('r')?->threads[0]->comments[0]->createdAt ?? '');
    }

    public function testTheSourceAnswersFromTheDirectory(): void
    {
        $reviews = $this->reviews([
            '.porta/reviews/a/t1/thread.json' => '{"path":"a","line":1}',
            '.porta/reviews/a/t1/1--x.md' => 'x',
            '.porta/reviews/b/t1/thread.json' => '{"path":"a","line":1}',
            '.porta/reviews/b/t1/1--x.md' => 'x',
        ]);
        $picked = null;
        $source = new LocalSource($reviews, static fn (): string => 'Pierre', static function () use (&$picked): ?string {
            return $picked;
        });
        $load = static function () use ($source): ?Review {
            $loaded = null;
            $source->load(static function (?Review $r) use (&$loaded): void {
                $loaded = $r;
            });

            return $loaded;
        };

        $picked = 'b';
        $this->assertSame('b', $load()?->id, 'the one picked');
        $picked = 'gone';
        $this->assertContains($load()?->id, ['a', 'b'], 'a pick that no longer exists falls back to the newest');
        $review = $load();
        \assert(null !== $review, description: 'loaded');

        $errors = [];
        $collect = static function (?string $e) use (&$errors): void {
            $errors[] = $e;
        };
        $source->reply($review, $review->threads[0], 'ok', $collect);
        $source->setResolved($review, $review->threads[0], true, $collect);
        $source->comment($review, new Anchor('a', 2), 'new', $collect);
        $source->converse($review, 'hi', $collect);
        $this->assertSame([null, null, null, null], $errors);
        $this->assertSame('Pierre', $reviews->read($review->id)?->conversation[0]->author, 'the user signs with git\'s name');

        $gone = new Review(SourceKind::Local, 'gone', 'x', [$review->threads[0]]);
        $errors = [];
        $source->reply($gone, $review->threads[0], 'ok', $collect);
        $source->setResolved($gone, $review->threads[0], true, $collect);
        $this->assertSame(['The thread is gone', 'The thread is gone'], $errors);

        $empty = new LocalSource($this->reviews(), static fn (): string => 'x');
        $answer = 'unset';
        $empty->load(static function (?Review $r, ?string $e) use (&$answer): void {
            $answer = [$r, $e];
        });
        $this->assertSame([null, null], $answer, 'no review on disk');
    }

    /** A name that is not one directory under the reviews directory is refused at every door. */
    public function testANameThatWouldLeaveTheReviewsDirectoryIsRefused(): void
    {
        $reviews = $this->reviews(['.porta/reviews/r/t/thread.json' => '{"path":"a","line":1}', '.porta/reviews/r/t/1--a.md' => 'x']);

        foreach (['..', '../r', 'r/t', '', '.hidden'] as $bad) {
            $this->assertNull($reviews->read($bad), $bad);
            $this->assertFalse($reviews->describe($bad, 'x'), $bad);
            $this->assertNull($reviews->open($bad, new Anchor('a', line: 1), 'a', 'b'), $bad);
            $this->assertFalse($reviews->append($bad, 't', 'a', 'b'), $bad);
            $this->assertFalse($reviews->append('r', $bad, 'a', 'b'), $bad);
            $this->assertFalse($reviews->resolve('r', $bad, true), $bad);
        }
        $this->assertFalse($reviews->resolve('r', LocalReviews::CONVERSATION, true), 'the conversation is no thread to resolve');
        $this->assertTrue($reviews->describe('r', 'Titled'));
        $this->assertSame('Titled', $reviews->read('r')?->title);
        $this->assertDirectoryDoesNotExist($this->project->path('.porta/r'));
    }
}
