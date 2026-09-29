<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin;

use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Api\PluginApi;
use Porta\ReviewPlugin\Editor\EditorPublisher;
use Porta\ReviewPlugin\Local\LocalReviews;
use Porta\ReviewPlugin\Model\Anchor;
use Porta\ReviewPlugin\Model\ProjectPath;
use Porta\ReviewPlugin\Model\Side;
use Porta\ReviewPlugin\Source\ReviewStore;
use Porta\ReviewPlugin\Source\Sources;
use Porta\ReviewPlugin\View\ReviewViewController;
use Porta\Ui\Glyphs;

/**
 * The review verbs that start from the editor or a menu rather than from the
 * view: a thread at the caret, a comment on the caret's line, which review to
 * show, the status bar's word on it.
 */
final readonly class ReviewCommands
{
    /** @param \Closure(): ?ReviewViewController $view the view once core has built it */
    public function __construct(
        private PluginApi $api,
        private ReviewStore $store,
        private EditorPublisher $publisher,
        private Sources $sources,
        private LocalReviews $local,
        private \Closure $view,
    ) {}

    /** The caret's line has a conversation: open the view on it. */
    public function showConversationAtCaret(): void
    {
        $caret = $this->api->caretPosition();
        $thread = null === $caret ? null : $this->publisher->placed[$caret['line']][0] ?? null;
        if (null === $thread) {
            $this->api->flash($this->api->trans('No conversation on this line'));

            return;
        }
        ($this->view)()?->showThread($thread->id);
    }

    /** Start a thread on the caret's line, in the view's reply box. */
    public function commentAtCaret(): void
    {
        $path = $this->api->activeFile();
        $caret = $this->api->caretPosition();
        $relative = null === $path ? null : ProjectPath::relative($this->api->projectRoot(), $path);
        if (null === $relative || null === $caret) {
            $this->api->flash($this->api->trans('Open a project file to comment on'));

            return;
        }
        $lines = \explode("\n", (string) $this->api->documentText());
        $anchor = new Anchor($relative, $caret['line'] + 1, Side::New, $lines[$caret['line']] ?? null);
        ($this->view)()?->commentAt($anchor);
    }

    /** Pick which review to show: a local one by name, or the branch's pull or merge request. */
    public function choose(): void
    {
        $items = ['' => $this->api->trans('The branch\'s pull or merge request')];
        foreach ($this->local->ids() as $id) {
            $items[$id] = $this->api->trans('Local review: %id%', ['%id%' => $id]);
        }
        $this->api->pick($this->api->trans('Show which review?'), $items, function (string $picked): void {
            $this->sources->picked = $picked;
            $this->store->refresh();
        });
    }

    public function copyLink(): void
    {
        $url = $this->store->review?->url;
        if (null === $url) {
            $this->api->flash($this->api->trans('This review has no link'));

            return;
        }
        $this->api->copyToClipboard($url);
        $this->api->notify($this->api->trans('Copied %url%', ['%url%' => $url]), ToastSeverity::Success);
    }

    /** "✎ PR #12 · 3" while a review with open threads is loaded, nothing otherwise. */
    public function statusSegment(): ?string
    {
        $review = $this->store->review;

        return null === $review ? null : Glyphs::COMMENT.' '.$review->label().' · '.$review->openCount();
    }
}
