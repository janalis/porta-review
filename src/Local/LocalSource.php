<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Local;

use Porta\ReviewPlugin\Model\Anchor;
use Porta\ReviewPlugin\Model\Review;
use Porta\ReviewPlugin\Model\Thread;
use Porta\ReviewPlugin\Source\ReviewSource;

/**
 * A local review as a {@see ReviewSource}: the directory an agent writes, read
 * and answered in place. Everything is a file operation, so every callback
 * runs before the verb returns.
 */
final readonly class LocalSource implements ReviewSource
{
    /**
     * @param \Closure(): string              $author   who the user is — git's `user.name`, read when a reply is written
     * @param (\Closure(): ?string)|null     $selected the review the user picked, or null for the newest
     */
    public function __construct(
        private LocalReviews $reviews,
        private \Closure $author,
        private ?\Closure $selected = null,
    ) {}

    #[\Override]
    public function load(\Closure $onDone): void
    {
        $ids = $this->reviews->ids();
        $picked = null === $this->selected ? null : ($this->selected)();
        $id = null !== $picked && \in_array($picked, $ids, strict: true) ? $picked : $ids[0] ?? null;
        $noError = null;
        $onDone(null === $id ? null : $this->reviews->read($id), $noError);
    }

    #[\Override]
    public function reply(Review $review, Thread $thread, string $body, \Closure $onDone): void
    {
        $onDone($this->reviews->append($review->id, $thread->id, ($this->author)(), $body) ? null : 'The thread is gone');
    }

    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the request: resolved or not.
    #[\Override]
    public function setResolved(Review $review, Thread $thread, bool $resolved, \Closure $onDone): void
    {
        $onDone($this->reviews->resolve($review->id, $thread->id, $resolved) ? null : 'The thread is gone');
    }

    #[\Override]
    public function comment(Review $review, Anchor $anchor, string $body, \Closure $onDone): void
    {
        $onDone(null === $this->reviews->open($review->id, $anchor, ($this->author)(), $body) ? 'Could not write the comment' : null);
    }

    #[\Override]
    public function converse(Review $review, string $body, \Closure $onDone): void
    {
        $onDone($this->reviews->append($review->id, LocalReviews::CONVERSATION, ($this->author)(), $body) ? null : 'Could not write the comment');
    }
}
