<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Source;

use Porta\ReviewPlugin\Model\Anchor;
use Porta\ReviewPlugin\Model\Review;
use Porta\ReviewPlugin\Model\Thread;

/**
 * Where reviews come from and where answers go: a GitHub pull request, a
 * GitLab merge request, or a local review an agent wrote into the working copy.
 *
 * Every verb is asynchronous — a forge is a subprocess away — and answers
 * through its callback exactly once. An error is a sentence for the user, never
 * an exception: the loop has no caller to throw to.
 */
interface ReviewSource
{
    /**
     * Find the current branch's review and read it whole.
     *
     * @param \Closure(?Review, ?string): void $onDone the review, or null with the reason; null and null means there is none
     */
    public function load(\Closure $onDone): void;

    /** @param \Closure(?string): void $onDone null on success, else why not */
    public function reply(Review $review, Thread $thread, string $body, \Closure $onDone): void;

    /** @param \Closure(?string): void $onDone */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the request: resolved or not.
    public function setResolved(Review $review, Thread $thread, bool $resolved, \Closure $onDone): void;

    /** @param \Closure(?string): void $onDone */
    public function comment(Review $review, Anchor $anchor, string $body, \Closure $onDone): void;

    /** @param \Closure(?string): void $onDone */
    public function converse(Review $review, string $body, \Closure $onDone): void;
}
