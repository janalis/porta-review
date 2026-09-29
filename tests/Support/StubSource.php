<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests\Support;

use Porta\ReviewPlugin\Model\Anchor;
use Porta\ReviewPlugin\Model\Review;
use Porta\ReviewPlugin\Model\Thread;
use Porta\ReviewPlugin\Source\ReviewSource;

/**
 * A source that answers what it is told, when it is told: loads queue up until
 * {@see answer()} releases the oldest, and every write is recorded and answered
 * with {@see $writeError}.
 */
final class StubSource implements ReviewSource
{
    /** @var list<\Closure(?Review, ?string): void> */
    public array $pending = [];

    /** @var list<string> */
    public array $writes = [];

    public ?string $writeError = null;

    public function load(\Closure $onDone): void
    {
        $this->pending[] = $onDone;
    }

    public function answer(?Review $review, ?string $error = null): void
    {
        $next = \array_shift($this->pending);
        \assert(null !== $next, description: 'a load is waiting');
        $next($review, $error);
    }

    public function reply(Review $review, Thread $thread, string $body, \Closure $onDone): void
    {
        $this->writes[] = "reply {$thread->id}: {$body}";
        $onDone($this->writeError);
    }

    // @mago-expect lint:no-boolean-flag-parameter -- the interface's own shape
    public function setResolved(Review $review, Thread $thread, bool $resolved, \Closure $onDone): void
    {
        $this->writes[] = ($resolved ? 'resolve ' : 'reopen ').$thread->id;
        $onDone($this->writeError);
    }

    public function comment(Review $review, Anchor $anchor, string $body, \Closure $onDone): void
    {
        $this->writes[] = "comment {$anchor->path}:{$anchor->line}: {$body}";
        $onDone($this->writeError);
    }

    public function converse(Review $review, string $body, \Closure $onDone): void
    {
        $this->writes[] = "converse: {$body}";
        $onDone($this->writeError);
    }
}
