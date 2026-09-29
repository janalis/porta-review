<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\View;

use Porta\GitPlugin\Diff\DiffDocument;
use Porta\GitPlugin\Process\GitProcessRunner;
use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Api\PluginApi;
use Porta\ReviewPlugin\Model\Anchor;
use Porta\ReviewPlugin\Model\Review;
use Porta\ReviewPlugin\Model\Side;
use Porta\ReviewPlugin\Model\SourceKind;
use Porta\ReviewPlugin\Model\Thread;
use Porta\ReviewPlugin\Source\ReviewSource;
use Porta\ReviewPlugin\Source\ReviewStore;
use Porta\ReviewPlugin\Widget\ReplyBoxWidget;
use Porta\ReviewPlugin\Widget\ReviewContentWidget;
use Porta\ReviewPlugin\Widget\ReviewListWidget;
use Porta\Support\Files;
use Porta\Workbench\Menu\ViewMenuSource;
use Porta\Workbench\View\ContributedView;
use Porta\Workbench\View\ViewPointer;
use Porta\Workbench\View\ViewServices;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * The review view: the thread list and the reply box on the left, the file's
 * review diff with its threads — or the review's conversation — on the right.
 *
 * The reply box writes to one TARGET at a time, and every verb that points it
 * somewhere says so in the box's legend before a word is typed: `r` on a
 * thread replies to it, `c` on a code line starts a thread there, `c` in the
 * list writes to the review itself.
 */
final class ReviewViewController implements ContributedView
{
    public const string ID = 'review';

    private bool $open = false;

    /** Where the reply box writes; null while it has nowhere to. */
    private ?ReplyTarget $target = null;

    /** The file the content pane shows, or null for the conversation. */
    private ?string $shownPath = null;

    private int $generation = 0;

    /** @param \Closure(): bool $showResolved */
    public function __construct(
        private readonly ViewServices $services,
        private readonly PluginApi $api,
        private readonly ReviewStore $store,
        private readonly GitProcessRunner $git,
        private readonly ReviewListWidget $list,
        private readonly ReplyBoxWidget $reply,
        private readonly ReviewContentWidget $content,
        private readonly \Closure $showResolved,
    ) {
        $services->region->sidebar()->add($list);
        $services->region->setSidebarPanes(new ReviewSidebar($list, $reply));
        $services->region->sidebar()->add($reply);
        $services->region->content()->add($content);

        $list
            ->onSelect($this->select(...))
            ->bind('reply', fn (string $id) => $this->replyTo($this->store->review?->thread($id)))
            ->bind('resolve', fn (string $id) => $this->toggleResolved($this->store->review?->thread($id)))
            ->bind('open', fn (string $id) => $this->openInEditor($this->store->review?->thread($id)?->anchor))
            ->bind('comment', $this->writeToReview(...))
            ->bind('refresh', fn () => $this->store->refresh());
        $list->onFocusNext(fn () => $this->services->host->focus($this->reply))->onExit($this->close(...));
        $reply
            ->onSubmit($this->send(...))
            ->onFocusNext(fn () => $this->services->host->focus($this->content))
            ->onExit(fn () => $this->services->host->focus($this->list));
        $content
            ->bind('reply', fn (?Anchor $anchor, ?Thread $thread) => $this->replyTo($thread))
            ->bind('resolve', fn (?Anchor $anchor, ?Thread $thread) => $this->toggleResolved($thread))
            ->bind('comment', fn (?Anchor $anchor, ?Thread $thread) => null === $anchor ? $this->replyTo($thread) : $this->commentAt($anchor))
            ->bind('open', fn (?Anchor $anchor, ?Thread $thread) => $this->openInEditor($anchor ?? $thread?->anchor));
        $content->onFocusNext(fn () => $this->services->host->focus($this->list))->onExit($this->close(...));
        $store->onChange($this->sync(...));
    }

    #[\Override]
    public function viewId(): string
    {
        return self::ID;
    }

    #[\Override]
    public function ownsMainArea(): bool
    {
        return true;
    }

    #[\Override]
    public function isOpen(): bool
    {
        return $this->open;
    }

    #[\Override]
    public function open(): void
    {
        if ($this->open) {
            return;
        }
        $this->open = true;
        $this->services->host->show($this->services->region);
        $this->services->host->focus($this->list);
        $this->sync();
        if (!$this->store->loading) {
            $this->store->refresh();
        }
    }

    #[\Override]
    public function close(): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        $this->services->host->restore();
    }

    #[\Override]
    public function toggle(): void
    {
        $this->open ? $this->close() : $this->open();
    }

    #[\Override]
    public function pointer(): ?ViewPointer
    {
        return null;
    }

    #[\Override]
    public function contextMenus(): ?ViewMenuSource
    {
        return null;
    }

    #[\Override]
    public function focusTarget(): AbstractWidget&FocusableInterface
    {
        return $this->list;
    }

    #[\Override]
    public function cycleFocus(int $direction): bool
    {
        $ring = [$this->list, $this->reply, $this->content];
        $at = \array_search($this->services->host->focused(), $ring, strict: true);
        $next = false === $at ? 0 : ($at + $direction + \count($ring)) % \count($ring);
        $this->services->host->focus($ring[$next]);

        return true;
    }

    /** Open the view on one thread — the editor's "show conversation" lands here. */
    public function showThread(string $id): void
    {
        $this->open();
        if ($this->list->selectId($id)) {
            $this->select($id);
        }
    }

    /** Open the view writing a new thread on a line — the editor's "comment on this line". */
    public function commentAt(Anchor $anchor): void
    {
        $this->open();
        if (null === $this->store->review) {
            $this->api->notify($this->api->trans('No review for this branch'), ToastSeverity::Warn);

            return;
        }
        if ($anchor->path !== $this->shownPath) {
            $this->loadFile($this->store->review, $anchor->path, null);
        }
        $this->aim(ReplyTarget::line($anchor), $this->api->trans('New comment on %path%:%line%', ['%path%' => $anchor->path, '%line%' => $anchor->line]));
    }

    private function sync(): void
    {
        $review = $this->store->review;
        $this->list->show($review, $this->store->loading, $this->store->error, ($this->showResolved)());
        if (!$this->open || $this->store->loading) {
            return;
        }
        // A reply target the reload took away is no target any more.
        $thread = $this->target?->thread;
        if (null !== $thread && null === $review?->thread($thread->id)) {
            $this->aim(null, '');
        }
        if (null === $review) {
            ++$this->generation;
            $this->content->clearContent();

            return;
        }
        // Re-read whatever the pane shows, now with the answer's threads.
        if (null === $this->shownPath) {
            $this->select($this->list->selectedId() ?? ReviewListWidget::CONVERSATION);

            return;
        }
        $this->loadFile($review, $this->shownPath, $this->content->currentThread()?->id);
    }

    private function select(string $id): void
    {
        $review = $this->store->review;
        \assert(null !== $review, description: 'the list has entries only while a review is loaded');
        if (ReviewListWidget::CONVERSATION === $id) {
            $this->shownPath = null;
            ++$this->generation;
            $this->content->showConversation($review->label().' · '.$review->title, $review->conversation);

            return;
        }
        $thread = $review->thread($id);
        if (null !== $thread) {
            $this->loadFile($review, $thread->anchor->path, $thread->id);
        }
    }

    private function loadFile(Review $review, string $path, ?string $focus): void
    {
        $this->shownPath = $path;
        $generation = ++$this->generation;
        $absolute = \rtrim($this->api->projectRoot(), characters: '/').'/'.$path;
        $range =
            SourceKind::Local === $review->kind || null === $review->headSha
                ? [$review->baseSha ?? 'HEAD']
                : [(string) $review->baseSha.'...'.$review->headSha];
        $this->git->run(['diff', '--no-color', '--no-ext-diff', ...$range, '--', $absolute], function (string $diff) use ($generation, $path, $absolute, $focus): void {
            if ($generation !== $this->generation) {
                return; // another file was asked for, or the review went away, since
            }
            // Read now, not captured: a reload that landed meanwhile has the newer threads.
            $review = $this->store->review;
            \assert(null !== $review, description: 'losing the review bumps the generation');
            $text = Files::read($absolute);
            $threads = \array_values(\array_filter($review->threadsOn($path), fn (Thread $t): bool => ($this->showResolved)() || !$t->resolved || $t->id === $focus));
            $this->content->showFile($path, DiffDocument::parse($diff), $text, $threads, $focus);
        });
    }

    private function replyTo(?Thread $thread): void
    {
        if (null === $thread) {
            return;
        }
        $this->aim(
            ReplyTarget::thread($thread),
            $this->api->trans('Reply to %author% on %path%:%line%', [
                '%author%' => $thread->first()->author,
                '%path%' => $thread->anchor->path,
                '%line%' => $thread->anchor->line,
            ]),
        );
    }

    private function writeToReview(): void
    {
        if (null === $this->store->review) {
            return;
        }
        $this->aim(ReplyTarget::review(), $this->api->trans('Comment on %review%', ['%review%' => $this->store->review->label()]));
    }

    private function aim(?ReplyTarget $target, string $label): void
    {
        $this->target = $target;
        $this->reply->target($label);
        if (null !== $target) {
            $this->services->host->focus($this->reply);
        }
    }

    private function send(string $body): void
    {
        $target = $this->target;
        \assert(null !== $target, description: 'the box has a legend, and so submits, only while it has a target');
        $this->store->act(static function (ReviewSource $source, Review $review, \Closure $done) use ($target, $body): void {
            match (true) {
                null !== $target->thread => $source->reply($review, $target->thread, $body, $done),
                null !== $target->anchor => $source->comment($review, $target->anchor, $body, $done),
                default => $source->converse($review, $body, $done),
            };
        }, function (?string $error) use ($body): void {
            if (null !== $error) {
                $this->api->notify($error, ToastSeverity::Error, 6.0);

                return;
            }
            $this->reply->clearSent($body);
            $this->api->notify($this->api->trans('Sent'), ToastSeverity::Success);
        });
    }

    private function toggleResolved(?Thread $thread): void
    {
        if (null === $thread) {
            return;
        }
        $this->store->act(
            static fn (ReviewSource $source, Review $review, \Closure $done) => $source->setResolved($review, $thread, !$thread->resolved, $done),
            fn (?string $error) => null === $error
                ? $this->api->notify($thread->resolved ? $this->api->trans('Thread reopened') : $this->api->trans('Thread resolved'), ToastSeverity::Success)
                : $this->api->notify($error, ToastSeverity::Error, 6.0),
        );
    }

    private function openInEditor(?Anchor $anchor): void
    {
        if (null === $anchor) {
            return;
        }
        $this->close();
        $this->api->openFile(\rtrim($this->api->projectRoot(), characters: '/').'/'.$anchor->path);
        $this->api->jumpTo(\max(0, (Side::Old === $anchor->side ? 1 : $anchor->line) - 1));
    }
}
