<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests\Source;

use PHPUnit\Framework\TestCase;
use Porta\ReviewPlugin\Model\Anchor;
use Porta\ReviewPlugin\Model\Comment;
use Porta\ReviewPlugin\Model\Review;
use Porta\ReviewPlugin\Model\SourceKind;
use Porta\ReviewPlugin\Model\Thread;
use Porta\ReviewPlugin\Source\ReviewSource;
use Porta\ReviewPlugin\Source\ReviewStore;
use Porta\ReviewPlugin\Tests\Support\StubSource;

final class ReviewStoreTest extends TestCase
{
    private StubSource $source;

    /** @var list<\Closure(?ReviewSource): void> */
    private array $resolving = [];

    private int $changes = 0;

    private function store(): ReviewStore
    {
        $this->source = new StubSource();
        $store = new ReviewStore(function (\Closure $onDone): void {
            $this->resolving[] = $onDone;
        });
        $store->onChange(function (): void {
            ++$this->changes;
        });

        return $store;
    }

    private static function review(string $title = 'r'): Review
    {
        return new Review(SourceKind::Local, 'id', $title, [new Thread('t1', new Anchor('a', 1), [new Comment('a', 'x')])]);
    }

    public function testARefreshLoadsThroughTheSourceItResolves(): void
    {
        $store = $this->store();

        $store->refresh();
        $this->assertTrue($store->loading);
        $this->assertSame(1, $this->changes, 'loading is itself a change');
        \array_shift($this->resolving)($this->source);
        $this->source->answer(self::review());

        $this->assertFalse($store->loading);
        $this->assertSame('r', $store->review?->title);
        $this->assertNull($store->error);
        $this->assertSame(2, $this->changes);
    }

    public function testNoSourceAndAFailureSettleWithoutAReview(): void
    {
        $store = $this->store();
        $store->refresh();
        \array_shift($this->resolving)(null);
        $this->assertSame([null, null, false], [$store->review, $store->error, $store->loading], 'no forge, no local review');

        $store->refresh();
        \array_shift($this->resolving)($this->source);
        $this->source->answer(null, 'Bad credentials');
        $this->assertSame([null, 'Bad credentials'], [$store->review, $store->error]);
    }

    public function testAnOvertakenLoadIsDroppedWhenItAnswers(): void
    {
        $store = $this->store();
        $store->refresh();
        $first = \array_shift($this->resolving);
        $store->refresh();
        $second = \array_shift($this->resolving);

        $first($this->source); // the first resolution arrives late: nothing is asked
        $this->assertSame([], $this->source->pending);
        $second($this->source);
        $store->refresh();
        \array_shift($this->resolving)($this->source);
        $this->source->answer(self::review('stale'));
        $this->assertNull($store->review, 'the second refresh\'s answer, overtaken by the third');
        $this->source->answer(self::review('fresh'));
        $this->assertSame('fresh', $store->review?->title);
    }

    public function testWhenSettledWaitsForTheLoadInFlightAndFiresOnce(): void
    {
        $store = $this->store();
        $told = [];
        $store->whenSettled(static function () use (&$told): void {
            $told[] = 'idle';
        });
        $this->assertSame(['idle'], $told, 'nothing in flight: at once');

        $store->refresh();
        $first = \array_shift($this->resolving);
        $store->whenSettled(static function () use (&$told): void {
            $told[] = 'settled';
        });
        $store->refresh(); // overtakes the first
        $this->assertSame(['idle'], $told);
        $first($this->source); // the overtaken one answers: dropped, not a settle
        $this->assertSame(['idle'], $told);

        \array_shift($this->resolving)(null);
        $this->assertSame(['idle', 'settled'], $told, 'the load that overtook it settled');

        $store->refresh();
        \array_shift($this->resolving)(null);
        $this->assertSame(['idle', 'settled'], $told, 'one-shot');
    }

    public function testAWriteGoesToTheSourceThenReloads(): void
    {
        $store = $this->store();
        $errors = [];
        $store->act(static fn (): null => null, static function (?string $e) use (&$errors): void {
            $errors[] = $e;
        });
        $this->assertSame(['No review is loaded'], $errors);

        $store->refresh();
        \array_shift($this->resolving)($this->source);
        $this->source->answer(self::review());
        $errors = [];
        $store->act(static fn (ReviewSource $s, Review $r, \Closure $done) => $s->converse($r, 'hi', $done), static function (?string $e) use (&$errors): void {
            $errors[] = $e;
        });
        $this->assertSame(['converse: hi'], $this->source->writes);
        $this->assertSame([null], $errors);
        $this->assertTrue($store->loading, 'and the review is read again');

        $this->source->writeError = 'Forbidden';
        \array_shift($this->resolving)($this->source);
        $this->source->answer(self::review());
        $store->act(static fn (ReviewSource $s, Review $r, \Closure $done) => $s->converse($r, 'again', $done), static function (?string $e) use (&$errors): void {
            $errors[] = $e;
        });
        $this->assertSame([null, 'Forbidden'], $errors);
        $this->assertFalse($store->loading, 'a failed write reloads nothing');
    }
}
