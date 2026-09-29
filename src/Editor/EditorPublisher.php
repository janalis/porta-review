<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Editor;

use Porta\GitPlugin\Diff\DiffDocument;
use Porta\GitPlugin\Process\GitProcessRunner;
use Porta\Plugin\Api\PluginApi;
use Porta\ReviewPlugin\Model\ProjectPath;
use Porta\ReviewPlugin\Model\Review;
use Porta\ReviewPlugin\Model\Side;
use Porta\ReviewPlugin\Model\SourceKind;
use Porta\ReviewPlugin\Model\Thread;
use Porta\ReviewPlugin\Source\ReviewStore;
use Porta\Ui\Glyphs;

/**
 * Shows the review's conversations in the editor, on the lines they are about
 * — as much of them as `editorDisplay` asks for.
 *
 * Only the ACTIVE file is decorated. The trailing notes are the active
 * document's anyway, and a mark on a hidden buffer would be stale by the time
 * it showed: the review, the file and the setting can all move meanwhile. So
 * every publish clears the file it decorated before, and a buffer switch
 * publishes the new one.
 *
 * A forge thread's line is translated through `git diff <head> -- <file>`,
 * which is a subprocess: the answer is dropped when a later publish has
 * started, so a slow diff cannot paint over the file that replaced it.
 */
final class EditorPublisher
{
    /** The file the last publish decorated, to clear when the next one starts. */
    private ?string $decorated = null;

    private int $generation = 0;

    /** @var array<int, list<Thread>> 0-based line of the active file => the threads shown there */
    public private(set) array $placed = [];

    /**
     * @param \Closure(): EditorDisplay $display      the setting, read per publish
     * @param \Closure(): bool          $showResolved likewise
     */
    public function __construct(
        private readonly PluginApi $api,
        private readonly ReviewStore $store,
        private readonly GitProcessRunner $git,
        private readonly \Closure $display,
        private readonly \Closure $showResolved,
    ) {}

    /** Decorate whatever file is active now, for the review as it is now. */
    public function publish(): void
    {
        $generation = ++$this->generation;
        $path = $this->api->activeFile();
        if (null !== $this->decorated && $path !== $this->decorated) {
            $this->api->setCommentMarks($this->decorated, []);
        }
        $this->decorated = $path;
        $review = $this->store->review;
        $relative = null === $path ? null : ProjectPath::relative($this->api->projectRoot(), $path);
        $threads =
            null === $relative || null === $review || EditorDisplay::Off === ($this->display)()
                ? []
                : $this->visible($review, $relative);
        if (null === $path || [] === $threads || null === $review) {
            $this->paint($path, []);

            return;
        }
        if (SourceKind::Local === $review->kind) {
            $this->paint($path, $this->placeByText($threads));

            return;
        }
        $this->git->run(['diff', '--no-color', '--no-ext-diff', '--unified=0', (string) $review->headSha, '--', $path], function (string $diff) use ($generation, $path, $threads): void {
            if ($generation !== $this->generation) {
                return;
            }
            $document = DiffDocument::parse($diff);
            $placed = [];
            foreach ($threads as $thread) {
                $line = $thread->outdated ? null : LineTranslator::throughDiff($document, $thread->anchor->line);
                if (null !== $line) {
                    $placed[$line - 1][] = $thread;
                }
            }
            $this->paint($path, $placed);
        });
    }

    /** @return list<Thread> the threads the editor can show for one repository-relative path */
    private function visible(Review $review, string $relative): array
    {
        $showResolved = ($this->showResolved)();

        return \array_values(\array_filter(
            $review->threadsOn($relative),
            static fn (Thread $t): bool => Side::New === $t->anchor->side && ($showResolved || !$t->resolved),
        ));
    }

    /**
     * @param list<Thread> $threads
     *
     * @return array<int, list<Thread>>
     */
    private function placeByText(array $threads): array
    {
        $lines = \explode("\n", (string) $this->api->documentText());
        $placed = [];
        foreach ($threads as $thread) {
            $anchor = $thread->anchor;
            $line = null === $anchor->lineText ? $anchor->line : LineTranslator::byText($lines, $anchor->line, $anchor->lineText);
            if (null !== $line) {
                $placed[$line - 1][] = $thread;
            }
        }

        return $placed;
    }

    /** @param array<int, list<Thread>> $placed */
    private function paint(?string $path, array $placed): void
    {
        \ksort($placed);
        $this->placed = $placed;
        $display = ($this->display)();
        $marks = [];
        $notes = [];
        $blocks = [];
        foreach ($placed as $line => $threads) {
            $summary = \implode(' · ', \array_map(static fn (Thread $t): string => $t->summary(), $threads));
            $marks[$line] = $summary;
            $notes[$line] = Glyphs::COMMENT.' '.$summary;
            $blocks[$line] = \array_merge(...\array_map($this->blockOf(...), $threads));
        }
        if (null !== $path) {
            $this->api->setCommentMarks($path, $marks);
        }
        $this->api->setLineAnnotations(EditorDisplay::Summary === $display ? $notes : []);
        $this->api->setLineBlocks(EditorDisplay::Inline === $display ? $blocks : []);
    }

    /**
     * A thread as rows under its line: each comment's author before its first
     * line, its further lines indented under it, a resolved thread saying so.
     *
     * @return list<string>
     */
    private function blockOf(Thread $thread): array
    {
        $rows = [];
        foreach ($thread->comments as $index => $comment) {
            $lines = \explode("\n", \rtrim($comment->body));
            $lead = 0 === $index ? Glyphs::COMMENT.' ' : '  ';
            $rows[] = $lead.$comment->author.': '.\array_shift($lines);
            foreach ($lines as $line) {
                $rows[] = '    '.$line;
            }
        }
        if ($thread->resolved) {
            $rows[0] .= '  ('.$this->api->trans('resolved').')';
        }

        return $rows;
    }
}
