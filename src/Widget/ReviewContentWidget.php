<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Widget;

use Porta\DiffPlugin\DiffDocument;
use Porta\I18n\Translator;
use Porta\ReviewPlugin\Editor\LineTranslator;
use Porta\ReviewPlugin\Model\Anchor;
use Porta\ReviewPlugin\Model\Comment;
use Porta\ReviewPlugin\Model\Side;
use Porta\ReviewPlugin\Model\Thread;
use Porta\Support\AnsiText;
use Porta\Ui\ClickZones;
use Porta\Ui\Glyphs;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Markup\MarkupParser;
use Porta\Ui\Role;
use Porta\Ui\ScreenDensity;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Tooltip;
use Porta\Ui\TooltipProvider;
use Porta\Widget\PaneNavigationCallbacks;
use Porta\Widget\Render\FieldBox;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Support\FillsPaneTrait;
use Porta\Widget\Support\OptionTagState;
use Porta\Widget\Support\PanelRowStyle;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The review view's right pane: one file's review diff with each thread drawn
 * under the line it is about, or the review's own conversation.
 *
 * A file with no diff to show — a local review of a file with nothing
 * uncommitted, or a forge review whose commits are not fetched — shows the
 * working copy instead, so a thread always has its line to sit under. A line
 * no longer where the thread says is placed by its text, as the editor does.
 *
 * Every row is one terminal line; a thread's block is as many rows as its
 * comments wrap to, so the caret walks through a conversation as it walks
 * through code, and `r` / `x` act on the thread it is in.
 */
final class ReviewContentWidget extends AbstractWidget implements FocusableInterface, VerticallyExpandableInterface, MenuOverlayHost, TooltipProvider
{
    use MenuOverlayHostTrait;
    use FillsPaneTrait;
    use FocusableTrait;
    use KeybindingsTrait;
    use PaneNavigationCallbacks;
    use PanelRowStyle;

    /** @var list<array{text: string, anchor: ?Anchor, thread: ?Thread}> each row as markup, and what it stands for */
    private array $rows = [];

    private string $title = '';

    private readonly ListSelection $list;

    /** The width the rows were built for; a thread's text re-wraps when it changes. */
    private int $builtWidth = 0;

    /** @var \Closure(int): list<array{text: string, anchor: ?Anchor, thread: ?Thread}>|null rebuilds the rows at a width */
    private ?\Closure $builder = null;

    /** @var array<string, \Closure(?Anchor, ?Thread): void> */
    private array $verbs = [];

    /** Mirror of the view's show-resolved state, for the toolbar toggle. */
    private bool $showResolved = false;

    /** @var (\Closure(): void)|null the toggle was clicked or its key pressed */
    private ?\Closure $onToggleResolved = null;

    /** The toolbar toggle's columns in the last render. */
    private readonly ClickZones $buttonZones;

    /** The toggle under the pointer, or null. Visual only. */
    private ?string $hoveredButton = null;

    /** The header's height in the last render: a boxed toggle, or one row where the screen is short. */
    private int $headerRows = 1;

    private readonly ScreenDensity $density;

    public function __construct(
        private readonly Translator $translator,
        ?Styles $styles = null,
        ?ScreenDensity $density = null,
    ) {
        $this->initStyles($styles);
        $this->list = new ListSelection();
        $this->buttonZones = new ClickZones();
        $this->density = $density ?? new ScreenDensity();
    }

    /** @param \Closure(): void $toggle */
    public function onToggleResolved(\Closure $toggle): self
    {
        $this->onToggleResolved = $toggle;

        return $this;
    }

    /** Reflect the view's show-resolved state on the toolbar toggle. */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the state being mirrored.
    public function showResolved(bool $on): void
    {
        if ($on !== $this->showResolved) {
            $this->showResolved = $on;
            $this->invalidate();
        }
    }

    /**
     * A click on the header toggles the button under it; a click on a row puts
     * the caret there. Focus is the router's.
     */
    public function handleClick(int $relRow, int $relCol): void
    {
        if ($relRow < $this->headerRows) {
            if (null !== $this->buttonZones->at($relCol) && null !== $this->onToggleResolved) {
                ($this->onToggleResolved)();
            }

            return;
        }
        if ([] !== $this->rows) {
            $this->list->select(\min(\count($this->rows) - 1, $this->list->scroll() + $relRow - $this->headerRows));
            $this->invalidate();
        }
    }

    /** Tint the toolbar toggle under the pointer (widget-relative); whether it changed. */
    public function hoverHeader(int $relRow, int $relCol): bool
    {
        $hovered = $relRow >= 0 && $relRow < $this->headerRows ? $this->buttonZones->at($relCol) : null;
        if ($hovered === $this->hoveredButton) {
            return false;
        }
        $this->hoveredButton = $hovered;
        $this->invalidate();

        return true;
    }

    /** The pointer left the pane: drop the toolbar tint. */
    public function clearHeaderHover(): bool
    {
        return $this->hoverHeader(-1, -1);
    }

    /** The toggle says what it does and whether it is on, as the git diff's do. */
    #[\Override]
    public function tooltipAt(int $row, int $col): ?Tooltip
    {
        $span = $row >= 0 && $row < $this->headerRows && null !== $this->buttonZones->at($col) ? $this->buttonZones->span('resolved') : null;
        if (null === $span) {
            return null;
        }
        $state = $this->showResolved ? $this->translator->trans('on') : $this->translator->trans('off');

        return new Tooltip('review:resolved', $this->translator->trans('Show resolved conversations').' · '.$state, 0, $span[0], $span[1], $this->headerRows);
    }

    /**
     * The title doubles as a toolbar, as the git diff pane's does: the title
     * on the left, the show-resolved toggle on the right — boxed where the
     * screen is roomy, a one-row tag where it is not, dropped where the pane
     * is too narrow to seat it.
     *
     * @return list<string>
     */
    private function header(string $title, int $width): array
    {
        $this->buttonZones->clear();
        $caption = \trim($this->translator->trans(' S show-resolved '));
        $bar = $this->styles->of(Role::HeaderBar)->withBold();
        $rows = FieldBox::rowsFor($this->density->current());
        $col = $width - FieldBox::toggleWidth($caption, $rows) - 1;
        if ($col < 0 && FieldBox::ROWS === $rows) {
            $rows = 1;
            $col = $width - FieldBox::toggleWidth($caption, $rows) - 1;
        }
        if ($col < 0) {
            return [$bar->apply(AnsiText::truncate($title, $width, pad: true))];
        }
        $toggle = FieldBox::toggle($caption, OptionTagState::of($this->showResolved, 'resolved' === $this->hoveredButton), $rows, $this->styles);
        $this->buttonZones->add('resolved', $col, $col + FieldBox::toggleWidth($caption, $rows));
        $lines = [];
        foreach ($toggle as $i => $line) {
            $left = \intdiv($rows, num2: 2) === $i ? AnsiText::truncate($title, $col, pad: true) : AnsiText::blank($col);
            $lines[] = $bar->apply($left.$line.' ');
        }

        return $lines;
    }

    /**
     * A key's verb, handed the row's anchor (a code line) and thread (a
     * thread's block): `reply`, `resolve`, `comment` and `open`.
     *
     * @param \Closure(?Anchor, ?Thread): void $verb
     */
    public function bind(string $action, \Closure $verb): self
    {
        $this->verbs[$action] = $verb;

        return $this;
    }

    /**
     * One file: its review diff when there is one, else its text, with the
     * threads under their lines. $focus names the thread the caret lands on.
     *
     * @param list<Thread> $threads the file's threads
     */
    public function showFile(string $path, ?DiffDocument $diff, string $text, array $threads, ?string $focus = null): void
    {
        $this->title = $path;
        $this->builder = fn (int $width): array => null !== $diff && [] !== $diff->hunks
            ? $this->diffRows($path, $diff, $threads, $width)
            : $this->fileRows($path, $text, $threads, $width);
        $this->rebuild(null, $focus);
    }

    /** @param list<Comment> $comments */
    public function showConversation(string $title, array $comments): void
    {
        $this->title = $title;
        $this->builder = function (int $width) use ($comments): array {
            $rows = [];
            foreach ($comments as $comment) {
                \array_push($rows, ...$this->commentRows($comment, null, $width, ''));
            }

            return [] === $rows ? [['text' => '<dim> '.MarkupParser::escape($this->translator->trans('No comments yet — press c to write one')).'</>', 'anchor' => null, 'thread' => null]] : $rows;
        };
        $this->rebuild(null, null);
    }

    public function clearContent(): void
    {
        $this->title = '';
        $this->builder = null;
        $this->rows = [];
        $this->list->reset();
        $this->invalidate();
    }

    /** Put the caret on a thread's first row; false when it is not in this pane. */
    public function focusThread(string $id): bool
    {
        foreach ($this->rows as $index => $row) {
            if ($id !== $row['thread']?->id) {
                continue;
            }
            $this->list->select($index);
            $this->invalidate();

            return true;
        }

        return false;
    }

    /** The thread the caret is in, if any. */
    public function currentThread(): ?Thread
    {
        return $this->rows[$this->list->selected()]['thread'] ?? null;
    }

    private function rebuild(?int $width, ?string $focus): void
    {
        $width ??= $this->builtWidth > 0 ? $this->builtWidth : 80;
        $this->builtWidth = $width;
        $this->rows = null === $this->builder ? [] : ($this->builder)($width);
        $this->list->reset();
        if (null === $focus || !$this->focusThread($focus)) {
            $this->list->select(0);
        }
        $this->invalidate();
    }

    /**
     * @param list<Thread> $threads
     *
     * @return list<array{text: string, anchor: ?Anchor, thread: ?Thread}>
     */
    private function diffRows(string $path, DiffDocument $diff, array $threads, int $width): array
    {
        $rows = [];
        $placed = [];
        foreach ($diff->hunks as $hunk) {
            $rows[] = ['text' => '<hunk>'.MarkupParser::escape($hunk->header()).'</>', 'anchor' => null, 'thread' => null];
            foreach ($hunk->lines as $line) {
                if ('\\' === $line->origin) {
                    continue;
                }
                $side = '-' === $line->origin ? Side::Old : Side::New;
                $number = Side::Old === $side ? $line->oldNo : $line->newNo;
                $anchor = null === $number ? null : new Anchor($path, $number, $side, $line->text);
                $tag = match ($line->origin) {
                    '+' => 'added',
                    '-' => 'deleted',
                    default => null,
                };
                $code = self::number($line->oldNo).self::number($line->newNo).Glyphs::DIFF_COLUMN.$line->origin.MarkupParser::escape(self::untab($line->text));
                $rows[] = ['text' => null === $tag ? $code : "<{$tag}>{$code}</>", 'anchor' => $anchor, 'thread' => null];
                foreach ($threads as $thread) {
                    $onOld = Side::Old === $thread->anchor->side && '+' !== $line->origin && $thread->anchor->line === $line->oldNo;
                    $onNew = Side::New === $thread->anchor->side && '-' !== $line->origin && $thread->anchor->line === $line->newNo;
                    if ((!$onOld && !$onNew) || \array_key_exists($thread->id, $placed)) {
                        continue;
                    }
                    $placed[$thread->id] = true;
                    \array_push($rows, ...$this->threadRows($thread, $width));
                }
            }
        }
        // A thread on a line the diff does not show (outdated, or outside every
        // hunk) still gets said, after the diff, rather than silently vanishing.
        foreach ($threads as $thread) {
            if (\array_key_exists($thread->id, $placed)) {
                continue;
            }
            \array_push($rows, ...$this->threadRows($thread, $width));
        }

        return $rows;
    }

    /**
     * @param list<Thread> $threads
     *
     * @return list<array{text: string, anchor: ?Anchor, thread: ?Thread}>
     */
    private function fileRows(string $path, string $text, array $threads, int $width): array
    {
        $lines = \explode("\n", $text);
        $byLine = [];
        foreach ($threads as $thread) {
            $anchor = $thread->anchor;
            $line = null === $anchor->lineText ? $anchor->line : LineTranslator::byText($lines, $anchor->line, $anchor->lineText) ?? $anchor->line;
            $byLine[$line][] = $thread;
        }
        $rows = [];
        foreach ($lines as $index => $code) {
            $number = $index + 1;
            $rows[] = ['text' => self::number($number).Glyphs::DIFF_COLUMN.' '.MarkupParser::escape(self::untab($code)), 'anchor' => new Anchor($path, $number, Side::New, $code), 'thread' => null];
            foreach ($byLine[$number] ?? [] as $thread) {
                \array_push($rows, ...$this->threadRows($thread, $width));
            }
            unset($byLine[$number]);
        }
        foreach ($byLine as $orphans) {
            foreach ($orphans as $thread) {
                \array_push($rows, ...$this->threadRows($thread, $width));
            }
        }

        return $rows;
    }

    /** @return list<array{text: string, anchor: ?Anchor, thread: ?Thread}> */
    private function threadRows(Thread $thread, int $width): array
    {
        $state = match (true) {
            $thread->resolved => $this->translator->trans('resolved'),
            $thread->outdated => $this->translator->trans('outdated'),
            default => '',
        };
        $head = Glyphs::COMMENT.' L'.$thread->anchor->line.('' === $state ? '' : '  '.$state);
        $rows = [['text' => '    <hunk>'.MarkupParser::escape($head).'</>', 'anchor' => null, 'thread' => $thread]];
        foreach ($thread->comments as $comment) {
            \array_push($rows, ...$this->commentRows($comment, $thread, $width, '    '.Glyphs::MD_QUOTE_BAR.' '));
        }

        return $rows;
    }

    /** @return list<array{text: string, anchor: ?Anchor, thread: ?Thread}> */
    private function commentRows(Comment $comment, ?Thread $thread, int $width, string $indent): array
    {
        $indentWidth = \mb_strlen($indent);
        $rows = [[
            'text' => $indent.'<bright>'.MarkupParser::escape($comment->author).'</> <dim>'.MarkupParser::escape($comment->when()).'</>',
            'anchor' => null,
            'thread' => $thread,
        ]];
        foreach (\explode("\n", \rtrim($comment->body)) as $paragraph) {
            foreach (AnsiText::wrap($paragraph, \max(10, $width - $indentWidth)) as $wrapped) {
                $rows[] = ['text' => $indent.MarkupParser::escape($wrapped), 'anchor' => null, 'thread' => $thread];
            }
        }
        $rows[] = ['text' => '', 'anchor' => null, 'thread' => $thread];

        return $rows;
    }

    /** A tab is four columns here: the pane paints text, not an editor's tab stops. */
    private static function untab(string $code): string
    {
        return \str_replace("\t", replace: '    ', subject: $code);
    }

    /** A right-aligned 4-column line number, blank for none. */
    private static function number(?int $line): string
    {
        return \str_pad(null === $line ? '' : (string) $line, length: 4, pad_string: ' ', pad_type: \STR_PAD_LEFT).' ';
    }

    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();
        match (true) {
            $kb->matches($data, 'exit_view') => $this->fire($this->onExit),
            $kb->matches($data, 'focus_next_pane') => $this->fire($this->onFocusNext),
            $kb->matches($data, 'toggle_resolved') => $this->fire($this->onToggleResolved),
            ListNavigation::handleKey($data, $kb, $this->list, \count($this->rows), 20) => $this->invalidate(),
            default => $this->runVerb($data, $kb),
        };
    }

    /** The first verb bound to the key, on the selected row's line and thread. */
    private function runVerb(string $data, Keybindings $kb): void
    {
        $action = \array_find(\array_keys($this->verbs), static fn (string $action): bool => $kb->matches($data, $action));
        $row = null === $action ? null : $this->rows[$this->list->selected()] ?? null;
        if (null !== $action && null !== $row) {
            $this->verbs[$action]($row['anchor'], $row['thread']);
        }
    }

    public function render(RenderContext $context): array
    {
        $width = \max(1, $context->getColumns());
        $height = \max(2, $context->getRows() > 0 ? $context->getRows() : 20);
        if ($width !== $this->builtWidth && null !== $this->builder) {
            $selected = $this->list->selected();
            $thread = $this->currentThread()?->id;
            $this->rebuild($width, $thread);
            if (null === $thread) {
                $this->list->select(\min($selected, \max(0, \count($this->rows) - 1)));
            }
        }
        $lines = $this->header(' '.('' === $this->title ? $this->translator->trans('Review') : $this->title), $width);
        $this->headerRows = \count($lines);
        $available = \max(1, $height - $this->headerRows);
        $scroll = $this->list->follow($available);
        for ($row = 0; $row < $available; ++$row) {
            $entry = $this->rows[$scroll + $row] ?? null;
            if (null === $entry) {
                $lines[] = AnsiText::blank($width);

                continue;
            }
            $selected = $this->list->isSelected($scroll + $row);
            $text = AnsiText::truncate($this->styles->format($entry['text']), $width, pad: true);
            $lines[] = $selected && $this->focused ? $this->shadeRow($text, true, hovered: false) : $text;
        }

        return $lines;
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'reply' => ['r'],
            'resolve' => ['x'],
            'comment' => ['c'],
            'open' => [Key::ENTER, 'o'],
            'toggle_resolved' => ['s'],
            'focus_next_pane' => [Key::TAB],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
