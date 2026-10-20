<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests\Widget;

use PHPUnit\Framework\TestCase;
use Porta\DiffPlugin\DiffDocument;
use Porta\Highlight\DarkTerminalTheme;
use Porta\Highlight\LightTerminalTheme;
use Porta\Highlight\SwitchableTerminalTheme;
use Porta\I18n\Translator;
use Porta\ReviewPlugin\Model\Anchor;
use Porta\ReviewPlugin\Model\Comment;
use Porta\ReviewPlugin\Model\Side;
use Porta\ReviewPlugin\Model\Thread;
use Porta\ReviewPlugin\Widget\ReviewContentWidget;
use Porta\Tests\Support\CompactScreen;
use Porta\Tests\Support\RendersWidget;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class ReviewContentWidgetTest extends TestCase
{
    use RendersWidget;

    /** @var list<string> */
    private array $acted = [];

    private function widget(): ReviewContentWidget
    {
        $widget = new ReviewContentWidget(new Translator('en'), Styles::dark(), CompactScreen::density());
        foreach (['reply', 'resolve', 'comment', 'open'] as $verb) {
            $widget->bind($verb, function (?Anchor $anchor, ?Thread $thread) use ($verb): void {
                $this->acted[] = $verb.' '.(null === $anchor ? '-' : $anchor->path.':'.$anchor->line.':'.$anchor->side->value.':'.$anchor->lineText).' '.($thread->id ?? '-');
            });
        }
        $widget->setFocused(true);

        return $widget;
    }

    private static function thread(string $id, int $line, Side $side = Side::New, bool $resolved = false, bool $outdated = false, ?string $lineText = null): Thread
    {
        return new Thread(
            $id,
            new Anchor('src/A.php', $line, $side, $lineText),
            [new Comment('alice', "Rename this.\n<b>Please</b>", '2026-09-28T10:00:00Z'), new Comment('bob', 'Done.', '')],
            $resolved,
            $outdated,
        );
    }

    private static function diff(): DiffDocument
    {
        return DiffDocument::parse(\implode("\n", [
            'diff --git a/src/A.php b/src/A.php',
            '@@ -1,4 +1,4 @@ class A',
            ' <?php',
            '-$old = 1;',
            "+\$new\t= 2;",
            ' $keep;',
            '\\ No newline at end of file',
        ])
            ."\n");
    }

    /** @return list<string> */
    private function screen(ReviewContentWidget $widget, int $rows = 30, int $cols = 60): array
    {
        return \array_map(rtrim(...), $this->rows($widget->render(new RenderContext($cols, $rows))));
    }

    public function testADiffShowsEachThreadUnderItsLineOnItsSide(): void
    {
        $widget = $this->widget();
        $widget->showFile('src/A.php', self::diff(), '', [self::thread('new', 2), self::thread('old', 2, Side::Old, resolved: true), self::thread('off', 40, outdated: true)], focus: 'new');

        $screen = $this->screen($widget);

        $this->assertSame('src/A.php                                 S show-resolved', \trim($screen[0]), 'the title, and the toolbar toggle');
        $this->assertSame(
            [
                ' @@ -1,4 +1,4 @@ class A',
                '   1    1 │ <?php',
                '   2      │-$old = 1;',
                '    ✎ L2  resolved',
                '    │ alice 2026-09-28 10:00',
                '    │ Rename this.',
                '    │ <b>Please</b>',
                '',
                '    │ bob',
                '    │ Done.',
                '',
                '        2 │+$new    = 2;',
                '    ✎ L2',
            ],
            \array_slice($screen, offset: 1, length: 13),
            'the old side under the deleted line, the new under the added one; a tab is four columns; markup stays text',
        );
        $outdated = \array_search('    ✎ L40  outdated', $screen, strict: true);
        $this->assertIsInt($outdated, 'a thread the diff does not show still gets said');
        $this->assertGreaterThan(\array_search('        2 │+$new    = 2;', $screen, strict: true), $outdated, 'after the diff');
        $this->assertSame('new', $widget->currentThread()?->id, 'the caret lands on the focused thread');
    }

    public function testTheCodeRowsAreTheDiffPanesOwn(): void
    {
        // Drawn by the diff plugin's renderer, as the git view draws them: the
        // green and red bands, and the changed words a shade stronger.
        $widget = $this->widget();
        $widget->setFocused(false);
        $widget->showFile('src/A.php', self::diff(), '', []);
        $styles = Styles::dark();

        $raw = $widget->render(new RenderContext(60, 12));
        $row = static fn (string $text): string => (string) \array_find($raw, static fn (string $line): bool => \str_contains(AnsiUtils::stripAnsiCodes($line), $text));

        $this->assertStringContainsString($styles->of(Role::DeletedRowBg)->getAnsiRestore(), $row('-$old'), 'the deleted line on its band');
        $this->assertStringContainsString($styles->of(Role::AddedRowBg)->getAnsiRestore(), $row('+$new'), 'the added line on its band');
        $this->assertStringContainsString($styles->of(Role::AddEmphasisBg)->getAnsiRestore(), $row('+$new'), 'its changed words stronger');
        $this->assertStringNotContainsString($styles->of(Role::AddedRowBg)->getAnsiRestore(), $row('$keep'), 'context has no band');
    }

    public function testAThemeSwitchRecoloursTheCodeAndKeepsTheCaretsThread(): void
    {
        $theme = new SwitchableTerminalTheme(new DarkTerminalTheme());
        $widget = new ReviewContentWidget(new Translator('en'), Styles::dark(), CompactScreen::density(), $theme);
        $widget->showFile('src/A.php', self::diff(), '', [self::thread('new', 2), self::thread('old', 2, Side::Old)], focus: 'new');
        $dark = $widget->render(new RenderContext(60, 20));

        $theme->switchTo(new LightTerminalTheme());
        $this->assertSame($dark, $widget->render(new RenderContext(60, 20)), 'the rows bake the palette in: nothing changes until the refresh');
        $widget->refreshTheme();
        $light = $widget->render(new RenderContext(60, 20));

        $this->assertNotSame($dark, $light, 'the code re-coloured');
        $this->assertSame(\array_map(AnsiUtils::stripAnsiCodes(...), $dark), \array_map(AnsiUtils::stripAnsiCodes(...), $light), 'the same rows');
        $this->assertSame('new', $widget->currentThread()?->id, 'the caret still on its thread');
    }

    public function testAThemeSwitchOffAThreadKeepsTheCaretsRow(): void
    {
        $widget = $this->widget();
        $widget->showFile('src/A.php', self::diff(), '', []);
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B");

        $widget->refreshTheme();

        $widget->handleInput('o');
        $this->assertSame(['open src/A.php:2:old:$old = 1; -'], $this->acted, 'still on the deleted line, not back on the hunk header');
    }

    public function testAFileWithNoDiffShowsItsTextAndPlacesThreadsByTheirLinesText(): void
    {
        $widget = $this->widget();
        $widget->showFile('src/A.php', DiffDocument::parse(''), "<?php\n\$moved;\n\$x;", [
            self::thread('t', 1, lineText: '$moved;'),
            self::thread('lost', 9, lineText: 'gone'),
            self::thread('far', 50),
        ]);

        $screen = $this->screen($widget, 40);

        $this->assertSame('   1 │ <?php', $screen[1]);
        $this->assertSame('   2 │ $moved;', $screen[2]);
        $this->assertSame('    ✎ L1', $screen[3], 'under the line its text is on now');
        $joined = \implode("\n", $screen);
        $this->assertLessThan(\strpos($joined, needle: '✎ L50'), (int) \strpos($joined, needle: '✎ L9'), 'text found nowhere keeps its line — past the end, so after the file, in line order');
        $this->assertLessThan(\strpos($joined, needle: '✎ L9'), (int) \strpos($joined, needle: '   3 │ $x;'));
        $this->assertNull($widget->currentThread(), 'no focus asked: the first row');
    }

    public function testTheCaretsRowDecidesWhatEachKeyActsOn(): void
    {
        $widget = $this->widget();
        $widget->showFile('src/A.php', self::diff(), '', [self::thread('t', 2)]);
        $this->screen($widget);

        $widget->handleInput('c'); // the hunk header: no line to comment on
        $widget->handleInput("\x1b[B");
        $widget->handleInput('c');
        $widget->handleInput("\x1b[B");
        $widget->handleInput('o');
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B");
        $widget->handleInput('r');
        $widget->handleInput('x');
        $widget->handleInput("\x1b[6~"); // page down, to the end
        $widget->handleInput("\x1b[5~");

        $this->assertSame(
            [
                'comment - -',
                'comment src/A.php:1:new:<?php -',
                'open src/A.php:2:old:$old = 1; -',
                'reply - t',
                'resolve - t',
            ],
            $this->acted,
        );
    }

    public function testTheConversationAndAnEmptyOne(): void
    {
        $widget = $this->widget();
        $widget->showConversation('PR #12 · Tidy', [new Comment('carol', 'Looks good.', '2026-09-28T11:00:00Z')]);
        $screen = $this->screen($widget, 6);
        $this->assertSame(['PR #12 · Tidy                             S show-resolved', 'carol 2026-09-28 11:00', 'Looks good.'], \array_map(trim(...), \array_slice($screen, offset: 0, length: 3)));
        $this->assertFalse($widget->focusThread('nope'));

        $widget->showConversation('PR #12', []);
        $this->assertStringContainsString('No comments yet — press c to write one', $this->screen($widget, 4)[1]);

        $widget->clearContent();
        $this->assertSame(['Review                                    S show-resolved', '', '', ''], \array_map(trim(...), $this->screen($widget, 4)));
        $widget->handleInput('r');
        $this->assertSame([], $this->acted, 'nothing to act on');
    }

    public function testARenderAtAnotherWidthRewrapsAndKeepsTheCaretsThread(): void
    {
        $widget = $this->widget();
        $long = new Thread('long', new Anchor('src/A.php', line: 1), [new Comment('alice', \str_repeat('word ', times: 30))]);
        $widget->showFile('src/A.php', self::diff(), '', [$long], focus: 'long');

        $wide = \count(\array_filter($this->screen($widget, 60, 120), static fn (string $r): bool => \str_contains($r, 'word')));
        $narrow = \count(\array_filter($this->screen($widget, 60, 40), static fn (string $r): bool => \str_contains($r, 'word')));

        $this->assertGreaterThan($wide, $narrow, 'the comment wraps to the pane');
        $this->assertSame('long', $widget->currentThread()?->id, 'still on the thread');

        $widget->showFile('src/A.php', self::diff(), '', []);
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B");
        $this->screen($widget, 60, 50);
        $widget->handleInput('o');
        $this->assertSame(['open src/A.php:2:old:$old = 1; -'], $this->acted, 'a re-render with no thread keeps the row');
    }

    public function testTabAndEscapeLeave(): void
    {
        $widget = $this->widget();
        $calls = [];
        $widget->onFocusNext(static function () use (&$calls): void {
            $calls[] = 'next';
        })->onExit(static function () use (&$calls): void {
            $calls[] = 'exit';
        });

        $widget->handleInput("\t");
        $widget->handleInput("\x1b");
        $widget->handleInput('z');

        $this->assertSame(['next', 'exit'], $calls);
        $this->assertTrue($widget->isVerticallyExpanded());
    }

    /**
     * The toolbar, as the git diff's: a boxed toggle where the screen is roomy,
     * none where the pane is too narrow — and the toggle answers its key, a
     * click, the pointer and the tooltip.
     */
    public function testTheShowResolvedToggleIsAToolbarButton(): void
    {
        $toggled = 0;
        $widget = new ReviewContentWidget(new Translator('en'), Styles::dark());
        foreach (['open'] as $verb) {
            $widget->bind($verb, function (?Anchor $anchor, ?Thread $thread) use ($verb): void {
                $this->acted[] = $verb.' '.(null === $anchor ? '-' : $anchor->path.':'.$anchor->line);
            });
        }
        $widget->onToggleResolved(static function () use (&$toggled): void {
            ++$toggled;
        });
        $widget->showFile('src/A.php', self::diff(), '', []);
        $rows = fn (int $cols = 60): array => \array_map(rtrim(...), $this->rows($widget->render(new RenderContext($cols, 10))));

        $boxed = $rows();
        $this->assertStringContainsString('S show-resolved', $boxed[1], 'boxed: the caption on the middle row');
        $this->assertStringContainsString('src/A.php', $boxed[1], 'and the title beside it');
        $this->assertStringContainsString('@@ -1,4 +1,4 @@', $boxed[3], 'the rows start under the three-row header');
        $this->assertSame(' src/A.php', $rows(12)[0], 'too narrow for the toggle: the title alone');
        $boxed = $rows();

        $zone = \mb_strpos($boxed[1], needle: 'S show-resolved');
        \assert(false !== $zone, description: 'the toggle is drawn');
        $this->assertTrue($widget->hoverHeader(1, $zone), 'the pointer on the toggle tints it');
        $this->assertFalse($widget->hoverHeader(1, $zone), 'no change, no repaint');
        $this->assertTrue($widget->clearHeaderHover());
        $this->assertStringContainsString('Show resolved conversations · off', $widget->tooltipAt(0, $zone)->text ?? '');
        $this->assertNull($widget->tooltipAt(0, 0), 'the title is no button');
        $this->assertNull($widget->tooltipAt(5, $zone), 'nor is a row under the header');

        $widget->handleClick(1, $zone);
        $widget->handleClick(1, 0); // the title: nothing
        $widget->handleInput('s');
        $this->assertSame(2, $toggled, 'a click on the toggle, and its key');

        $widget->showResolved(true);
        $widget->showResolved(true);
        $this->assertStringContainsString('Show resolved conversations · on', $widget->tooltipAt(0, $zone)->text ?? '');

        $widget->handleClick(4, 0); // a row under the header: the caret goes there
        $widget->setFocused(true);
        $widget->handleInput('o');
        $this->assertSame(['open src/A.php:1'], $this->acted);
    }
}
