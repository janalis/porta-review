<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\View;

use Porta\ReviewPlugin\Model\Anchor;
use Porta\ReviewPlugin\Model\Thread;

/**
 * Where the reply box's words go: into a thread, onto a line as a new thread,
 * or to the review itself — exactly one of the three.
 */
final readonly class ReplyTarget
{
    private function __construct(
        public ?Thread $thread,
        public ?Anchor $anchor,
    ) {}

    public static function thread(Thread $thread): self
    {
        return new self($thread, null);
    }

    public static function line(Anchor $anchor): self
    {
        return new self(null, $anchor);
    }

    public static function review(): self
    {
        return new self(null, null);
    }
}
