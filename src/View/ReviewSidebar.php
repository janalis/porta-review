<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\View;

use Porta\Layout\ResizableSidebar;
use Porta\ReviewPlugin\Widget\ReplyBoxWidget;
use Porta\ReviewPlugin\Widget\ReviewListWidget;

/**
 * The review view's left column as one resizable unit: a drag on the
 * separator, or its keyboard nudge, sets the thread list and the reply box
 * to the same width, and the layout store keeps it per project.
 */
final readonly class ReviewSidebar implements ResizableSidebar
{
    public function __construct(
        private ReviewListWidget $list,
        private ReplyBoxWidget $reply,
    ) {}

    #[\Override]
    public function setWidth(int $width): void
    {
        $this->list->setWidth($width);
        $this->reply->setWidth($width);
    }

    #[\Override]
    public function width(): int
    {
        return $this->list->width();
    }

    #[\Override]
    public function minWidth(): int
    {
        return $this->list->minWidth();
    }

    #[\Override]
    public function maxWidth(): int
    {
        return $this->list->maxWidth();
    }

    /** The diff wants the room more than the list does: the column stops at 60% of the terminal. */
    #[\Override]
    public function maxRatio(): float
    {
        return 0.6;
    }
}
