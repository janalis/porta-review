<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\View;

use Porta\Input\HitTester;
use Porta\Input\MouseEvent;
use Porta\ReviewPlugin\Widget\ReplyBoxWidget;
use Porta\ReviewPlugin\Widget\ReviewContentWidget;
use Porta\ReviewPlugin\Widget\ReviewListWidget;
use Porta\Workbench\View\ViewHost;
use Porta\Workbench\View\ViewPointer;
use Porta\Workbench\View\WheelGesture;

/**
 * The review view's pointer: a click focuses the pane it lands in and acts
 * there — an entry shows, a row takes the caret, the Send button sends, the
 * toolbar toggle toggles — and the pointer tints what a click would act on.
 * The divider between the column and the pane is core's, like every view's.
 */
final readonly class ReviewMouseRouter implements ViewPointer
{
    public function __construct(
        private HitTester $hit,
        private ViewHost $host,
        private ReviewListWidget $list,
        private ReplyBoxWidget $reply,
        private ReviewContentWidget $content,
    ) {}

    #[\Override]
    public function continueDrag(MouseEvent $mouse): bool
    {
        return false; // nothing here drags
    }

    #[\Override]
    public function cancelDrag(): void
    {
        // Nothing here drags, so a fresh press has nothing to disarm.
    }

    #[\Override]
    public function press(MouseEvent $mouse): bool
    {
        $rel = $this->hit->hit($this->list, $mouse->row, $mouse->col);
        if (null !== $rel) {
            $this->host->focus($this->list);
            $this->list->handleClick($rel['row']);

            return true;
        }
        $rel = $this->hit->hit($this->reply, $mouse->row, $mouse->col);
        if (null !== $rel) {
            $this->host->focus($this->reply);
            $this->reply->handleClick($rel['row']);

            return true;
        }
        $rel = $this->hit->hit($this->content, $mouse->row, $mouse->col);
        if (null !== $rel) {
            $this->host->focus($this->content);
            $this->content->handleClick($rel['row'], $rel['col']);

            return true;
        }

        return false;
    }

    #[\Override]
    public function hover(MouseEvent $mouse): void
    {
        $button = $this->hit->hover($mouse->row, $mouse->col, $this->reply);
        $rel = $this->hit->hit($this->content, $mouse->row, $mouse->col);
        $toggle = null === $rel ? $this->content->clearHeaderHover() : $this->content->hoverHeader($rel['row'], $rel['col']);
        if ($button || $toggle) {
            $this->host->invalidate();
        }
    }

    #[\Override]
    public function wheel(WheelGesture $gesture): bool
    {
        return false; // the panes scroll by key; the wheel falls through
    }
}
