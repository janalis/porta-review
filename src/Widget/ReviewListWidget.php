<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Widget;

use Porta\I18n\Translator;
use Porta\ReviewPlugin\Model\Review;
use Porta\ReviewPlugin\Model\Thread;
use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Markup\MarkupParser;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\PaneNavigationCallbacks;
use Porta\Widget\Render\HeaderRenderer;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\Rule;
use Porta\Widget\Support\FillsPaneTrait;
use Porta\Widget\Support\PanelRowStyle;
use Porta\Widget\Support\PanelWidth;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The review view's left column: which review this is, its conversation, and
 * its line threads grouped by file.
 *
 * Entries are the conversation and the threads; the file names between them
 * are headings the selection steps over. Resolved threads are dimmed and
 * ticked, and left out altogether unless `showResolved` is on.
 */
final class ReviewListWidget extends AbstractWidget implements FocusableInterface, VerticallyExpandableInterface, MenuOverlayHost
{
    use MenuOverlayHostTrait;
    use FillsPaneTrait;
    use FocusableTrait;
    use KeybindingsTrait;
    use PaneNavigationCallbacks;
    use PanelRowStyle;
    use PanelWidth;

    /** The title and the status line above the entries. */
    private const int HEADER_ROWS = 2;

    /** The entry id the conversation takes — no thread id can be empty. */
    public const string CONVERSATION = '';

    private ?Review $review = null;
    private bool $loading = false;
    private ?string $error = null;
    private bool $showResolved = false;

    /** @var list<array{heading: ?string, id: ?string, thread: ?Thread}> a row: a file heading, or an entry (the conversation's id is '') */
    private array $rows = [];

    private readonly ListSelection $list;

    /** @var (\Closure(string): void)|null an entry was chosen: a thread id, or '' for the conversation */
    private ?\Closure $onSelect = null;

    /** @var array<string, \Closure(string): void> the entry verbs: reply, resolve, open, comment, refresh */
    private array $verbs = [];

    public function __construct(
        private readonly Translator $translator,
        int $width = 44,
        ?Styles $styles = null,
    ) {
        $this->width = $width;
        $this->initStyles($styles);
        $this->list = new ListSelection();
    }

    /** @param \Closure(string): void $onSelect */
    public function onSelect(\Closure $onSelect): self
    {
        $this->onSelect = $onSelect;

        return $this;
    }

    /**
     * A key's verb, handed the selected entry's id: `reply`, `resolve`,
     * `open`, `comment` (on the review) and `refresh`.
     *
     * @param \Closure(string): void $verb
     */
    public function bind(string $action, \Closure $verb): self
    {
        $this->verbs[$action] = $verb;

        return $this;
    }

    public function show(?Review $review, bool $loading, ?string $error, bool $showResolved): void
    {
        $selected = $this->selectedId();
        $this->review = $review;
        $this->loading = $loading;
        $this->error = $error;
        $this->showResolved = $showResolved;
        $this->rows = $this->build();
        if (null === $selected || !$this->selectId($selected)) {
            $this->list->select($this->firstEntry(0, 1) ?? 0);
        }
        $this->invalidate();
    }

    /** A click on an entry selects and shows it, as Enter does; a heading or the header is no target. */
    public function handleClick(int $relRow): void
    {
        $index = $this->list->scroll() + $relRow - self::HEADER_ROWS;
        if ($relRow < self::HEADER_ROWS || null === ($this->rows[$index]['id'] ?? null)) {
            return;
        }
        $this->list->select($index);
        $this->invalidate();
        $this->choose();
    }

    /** The selected entry: a thread id, '' for the conversation, null when the list is empty. */
    public function selectedId(): ?string
    {
        return $this->rows[$this->list->selected()]['id'] ?? null;
    }

    /** Select an entry by id; false when it is not listed (resolved and hidden, or gone). */
    public function selectId(string $id): bool
    {
        foreach ($this->rows as $index => $row) {
            if ($id !== $row['id']) {
                continue;
            }
            $this->list->select($index);
            $this->invalidate();

            return true;
        }

        return false;
    }

    /** @return list<array{heading: ?string, id: ?string, thread: ?Thread}> */
    private function build(): array
    {
        if (null === $this->review) {
            return [];
        }
        $rows = [['heading' => null, 'id' => self::CONVERSATION, 'thread' => null]];
        // A list, not array keys: a path spelled like a number would come back an int.
        $paths = [];
        foreach ($this->review->threads as $thread) {
            if ($thread->resolved && !$this->showResolved) {
                continue;
            }
            $paths[] = $thread->anchor->path;
        }
        $paths = \array_values(\array_unique($paths));
        \sort($paths);
        foreach ($paths as $path) {
            $rows[] = ['heading' => $path, 'id' => null, 'thread' => null];
            foreach ($this->review->threadsOn($path) as $thread) {
                if ($thread->resolved && !$this->showResolved) {
                    continue;
                }
                $rows[] = ['heading' => null, 'id' => $thread->id, 'thread' => $thread];
            }
        }

        return $rows;
    }

    /** The first entry row from $from walking by $step, or null past the end. */
    private function firstEntry(int $from, int $step): ?int
    {
        for ($i = $from; $i >= 0 && $i < \count($this->rows); $i += $step) {
            if (null !== $this->rows[$i]['id']) {
                return $i;
            }
        }

        return null;
    }

    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();
        if ($kb->matches($data, 'exit_view')) {
            $this->fire($this->onExit);

            return;
        }
        if ($kb->matches($data, 'focus_next_pane')) {
            $this->fire($this->onFocusNext);

            return;
        }
        $before = $this->list->selected();
        if (ListNavigation::handleKey($data, $kb, $this->list, \count($this->rows), 10)) {
            // Step over a heading in the direction of travel, or stay put at an end.
            $direction = $this->list->selected() < $before ? -1 : 1;
            $this->list->select($this->firstEntry($this->list->selected(), $direction) ?? $this->firstEntry($this->list->selected(), -$direction) ?? $before);
            $this->invalidate();
            if ($before !== $this->list->selected()) {
                $this->choose();
            }

            return;
        }
        if ($kb->matches($data, 'select')) {
            $this->choose();

            return;
        }
        foreach (\array_keys($this->verbs) as $action) {
            if (!$kb->matches($data, $action)) {
                continue;
            }
            // Refresh and a comment on the review need no entry; the rest act on the selected one.
            $id = $this->selectedId();
            if (null !== $id || \in_array($action, ['refresh', 'comment', 'toggle_resolved'], strict: true)) {
                $this->verbs[$action]($id ?? self::CONVERSATION);
            }

            return;
        }
    }

    private function choose(): void
    {
        $id = $this->selectedId();
        if (null !== $id && null !== $this->onSelect) {
            ($this->onSelect)($id);
        }
    }

    public function render(RenderContext $context): array
    {
        $width = $this->panelColumns($context);
        $rows = \max(3, $context->getRows() > 0 ? $context->getRows() : 12);
        $open = $this->review?->openCount() ?? 0;
        $lines = [HeaderRenderer::title($this->translator->trans('Review (%count% open)', ['%count%' => $open]), $width, $this->styles, suffix: 'Esc')];
        $lines[] = AnsiText::truncate(' '.$this->status(), $width, pad: true);

        $available = $rows - \count($lines);
        $scroll = $this->list->follow($available);
        for ($row = 0; $row < $available; ++$row) {
            $entry = $this->rows[$scroll + $row] ?? null;
            if (null === $entry) {
                $lines[] = AnsiText::blank($width);

                continue;
            }
            if (null !== $entry['heading']) {
                $lines[] = Rule::horizontal($width, $this->styles, $entry['heading']);

                continue;
            }
            $selected = $this->list->isSelected($scroll + $row);
            $lines[] = $this->shadeRow($this->barred(AnsiText::truncate(' '.$this->entryText($entry['thread']), $width, pad: true), $selected), $selected, hovered: false);
        }

        return $lines;
    }

    private function status(): string
    {
        return match (true) {
            $this->loading && null === $this->review => $this->styles->of(Role::Dim)->apply($this->translator->trans('Looking for a review…')),
            null !== $this->error => $this->styles->of(Role::StatusError)->apply($this->error),
            null === $this->review => $this->styles->of(Role::Dim)->apply($this->translator->trans('No review for this branch')),
            default => $this->styles->format('<bright>'.MarkupParser::escape($this->review->label()).'</> '.MarkupParser::escape($this->review->title)),
        };
    }

    private function entryText(?Thread $thread): string
    {
        if (null === $thread) {
            $count = \count($this->review->conversation ?? []);

            return $this->styles->format('<bright>'.MarkupParser::escape($this->translator->trans('Conversation (%count%)', ['%count%' => $count])).'</>');
        }
        $mark = $thread->resolved ? Glyphs::CHECKBOX_MARK_ON : Glyphs::COMMENT;
        $text = MarkupParser::escape($mark.' L'.$thread->anchor->line.' '.$thread->summary());

        return $this->styles->format($thread->resolved || $thread->outdated ? '<dim>'.$text.'</>' : $text);
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'select' => [Key::ENTER],
            'reply' => ['r'],
            'resolve' => ['x'],
            'open' => ['o'],
            'comment' => ['c'],
            'refresh' => ['f5', 'shift+r'],
            'toggle_resolved' => ['s'],
            'focus_next_pane' => [Key::TAB],
            'exit_view' => [Key::ESCAPE],
        ];
    }

    /** Menus, toasts and tooltips are composited at the column's own width, not the region's. */
    protected function overlayWidth(RenderContext $context): int
    {
        return $this->panelColumns($context);
    }
}
