<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Ide;
use Porta\Layout\ViewRegions;
use Porta\Overlay\Presenter\ViewWindowPresenter;
use Porta\Overlay\Region\ModalBox;
use Porta\Plugin\Lifecycle\PluginLifecycle;
use Porta\ReviewPlugin\View\ReviewMouseRouter;
use Porta\ReviewPlugin\Widget\ReplyBoxWidget;
use Porta\ReviewPlugin\Widget\ReviewContentWidget;
use Porta\ReviewPlugin\Widget\ReviewListWidget;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\Paint;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Tests\Support\Widgets;
use Porta\Ui\Glyphs;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A local review, the way an agent leaves one: files under `.porta/reviews/`,
 * read by a real IDE and answered from it — the reply lands as a new file
 * beside the agent's, never as an edit of one.
 */
final class LocalReviewE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use ScreenReads;
    use UsesBundledPlugins;

    private const string CONFIG = '{"enabledPlugins":["git","review"],"plugins":{"review":{"provider":"local"}}}';

    private static function project(): TempProject
    {
        return new TempProject([
            'src/A.php' => "<?php\n\$total = 0;\nforeach (\$items as \$item) {\n    \$total += \$item;\n}\n",
            '.porta.json' => self::CONFIG,
            '.porta/reviews/agent/review.json' => '{"title":"Tidy the loop"}',
            '.porta/reviews/agent/t1/thread.json' => '{"path":"src/A.php","line":4,"lineText":"    $total += $item;"}',
            '.porta/reviews/agent/t1/20260929T100000Z--claude.md' => "Use array_sum here.\n",
        ]);
    }

    private function awaitToast(Ide $ide, string $message): void
    {
        $this->await(static fn (): bool => \in_array($message, Toasts::all($ide), strict: true), what: $message);
    }

    public function testAnAgentsThreadIsShownAnsweredAndResolvedFromTheView(): void
    {
        \putenv('PATH=/nonexistent');
        $project = self::project();
        $thread = $project->path('.porta/reviews/agent/t1');

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    // The editor marks the thread's line — the 4th — in the gutter.
                    $this->await(static fn (): bool => [] !== Widgets::editor($tui)->decorations->commentMarks, what: 'the gutter mark');
                    $this->assertSame([3 => 'claude: Use array_sum here.'], Widgets::editor($tui)->decorations->commentMarks);

                    Services::resolve($ide, CommandRegistry::class)->run('review:open');
                    $this->awaitPainted($tui, $terminal, 'Tidy the loop');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString(Glyphs::COMMENT.' L4 claude: Use array_sum here.', $screen, 'the thread is listed under its file');
                    $this->assertStringContainsString('Review (1 open)', $screen);
                },
                300 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $tui->handleInput("\x1b[B"); // down: from the conversation to the thread
                    $this->awaitPainted($tui, $terminal, '$total += $item;');
                    $tui->handleInput('r');
                    $this->awaitPainted($tui, $terminal, 'Reply to claude on src/A.php:4');
                    $tui->handleInput('Done, thanks');
                    $tui->handleInput("\x1b[13;5u"); // ctrl+enter
                },
                400 => function (Tui $tui, VirtualTerminal $terminal) use ($thread): void {
                    $this->await(static fn (): bool => 2 === \count((array) \glob($thread.'/*.md')), what: 'the reply file');
                    $files = (array) \glob($thread.'/*.md');
                    \sort($files);
                    $this->assertSame('Done, thanks', \file_get_contents((string) $files[1]), 'the reply is its own file, the agent\'s untouched');
                    $this->assertSame("Use array_sum here.\n", \file_get_contents((string) $files[0]));
                    $this->awaitPainted($tui, $terminal, 'Done, thanks');
                },
                500 => function (Tui $tui, VirtualTerminal $terminal) use ($thread): void {
                    $tui->handleInput("\x1b"); // Esc leaves the reply box for the list
                    $this->assertSame($tui->getById('review-threads'), $tui->getFocus());
                    $tui->handleInput('x');
                    $this->await(static fn (): bool => \file_exists($thread.'/resolved'), what: 'the resolved marker');
                    $this->awaitPainted($tui, $terminal, 'Review (0 open)');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testCommentOnThisLineStartsAThreadWithTheLinesText(): void
    {
        \putenv('PATH=/nonexistent');
        $project = self::project();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->await(static fn (): bool => [] !== Widgets::editor($tui)->decorations->commentMarks, what: 'the review loaded');
                    Widgets::editor($tui)->jumpTo(1, 0);
                    Services::resolve($ide, CommandRegistry::class)->run('review:comment_line');
                    $this->awaitPainted($tui, $terminal, 'New comment on src/A.php:2');
                    $this->assertTrue(Services::resolve($ide, ViewRegistry::class)->find('review')?->isOpen());
                    $tui->handleInput('Start at 1?');
                    $tui->handleInput("\x1b[13;5u");
                },
                300 => function (Tui $tui, VirtualTerminal $terminal) use ($project): void {
                    $meta = (array) \glob($project->path('.porta/reviews/agent/t2*/thread.json'));
                    $this->await(static function () use ($project, &$meta): bool {
                        $meta = (array) \glob($project->path('.porta/reviews/agent/t2*/thread.json'));

                        return 1 === \count($meta);
                    }, what: 'the new thread');
                    $this->assertSame(
                        ['path' => 'src/A.php', 'line' => 2, 'side' => 'new', 'lineText' => '$total = 0;'],
                        \json_decode((string) \file_get_contents((string) $meta[0]), associative: true),
                    );
                    $this->awaitPainted($tui, $terminal, 'Start at 1?');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTheConversationEntryHasNoThreadToActOnAndTheAgentCanTakeThingsAway(): void
    {
        \putenv('PATH=/nonexistent');
        $project = self::project();
        $project->write('.porta/reviews/agent/t0/thread.json', '{"path":"src/A.php","line":2}');
        $project->write('.porta/reviews/agent/t0/1--claude.md', 'Start at 1?');

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $view = Services::resolve($ide, ViewRegistry::class)->find('review');
                    Services::resolve($ide, CommandRegistry::class)->run('review:open');
                    $this->awaitPainted($tui, $terminal, 'Review (2 open)');
                    $this->assertSame($tui->getById('review-threads'), $view?->focusTarget());
                    $this->assertInstanceOf(ReviewMouseRouter::class, $view?->pointer());
                    $this->assertNull($view?->contextMenus());

                    // The conversation is selected: none of the thread verbs has a thread.
                    foreach (['r', 'x', 'o'] as $key) {
                        $tui->handleInput($key);
                    }
                    $this->assertSame($tui->getById('review-threads'), $tui->getFocus(), 'nothing took the keys away');
                    $this->assertTrue($view?->isOpen());
                    $view?->open(); // already open: nothing to do

                    // The focus ring, both ways round.
                    $this->assertTrue($view?->cycleFocus(1));
                    $this->assertSame($tui->getById('review-reply'), $tui->getFocus());
                    $view?->cycleFocus(-1);
                    $view?->cycleFocus(-1);
                    $this->assertSame($tui->getById('review-diff'), $tui->getFocus());

                    Services::resolve($ide, CommandRegistry::class)->run('review:copy_link');
                    $this->awaitToast($ide, 'This review has no link');
                },
                300 => function (Tui $tui, VirtualTerminal $terminal) use ($project): void {
                    // Aim the box at a thread, then the agent deletes that thread: the box lets go.
                    $tui->setFocus($tui->getById('review-threads'));
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B"); // two moves: the first file load is overtaken
                    $tui->handleInput('r');
                    $this->awaitPainted($tui, $terminal, 'Reply to claude on src/A.php:4');
                    new Filesystem()->remove($project->path('.porta/reviews/agent/t1'));
                    $this->awaitPainted($tui, $terminal, 'Pick a thread, or press c on a line');
                },
                400 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    // The whole review goes: the pane empties, and there is nothing to comment on.
                    new Filesystem()->remove($project->path('.porta/reviews'));
                    $this->awaitPainted($tui, $terminal, 'No review for this branch');
                    $tui->setFocus($tui->getById('review-threads'));
                    $tui->handleInput('c');
                    $this->assertSame('', Widgets::byId($tui, 'review-reply', ReplyBoxWidget::class)->targetLabel(), 'no review to write to');

                    $view = Services::resolve($ide, ViewRegistry::class)->find('review');
                    $view?->close();
                    $view?->close(); // already closed
                    Services::resolve($ide, CommandRegistry::class)->run('review:comment_line');
                    $this->awaitToast($ide, 'No review for this branch');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testChoosingAReviewAndFilesOutsideTheProject(): void
    {
        \putenv('PATH=/nonexistent');
        $project = self::project();
        $project->write('.porta/reviews/other/t9/thread.json', '{"path":"src/A.php","line":1}');
        $project->write('.porta/reviews/other/t9/1--bot.md', 'Header?');
        \touch($project->path('.porta/reviews/other/t9/1--bot.md'), \time() - 1000);
        \touch($project->path('.porta/reviews/other/t9'), \time() - 1000);
        \touch($project->path('.porta/reviews/other/t9/thread.json'), \time() - 1000);
        \touch($project->path('.porta/reviews/other'), \time() - 1000);
        $outside = new TempProject(['elsewhere.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $marks = static fn (): array => Widgets::editor($tui)->decorations->commentMarks;
                    $this->await(static fn (): bool => [3 => 'claude: Use array_sum here.'] === $marks(), what: 'the newest review');
                    Services::resolve($ide, CommandRegistry::class)->run('review:choose');
                    $this->awaitPainted($tui, $terminal, 'Local review: other');
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r");
                    $this->await(static fn (): bool => [0 => 'bot: Header?'] === $marks(), what: 'the one picked');
                },
                300 => function (Tui $tui, $terminal, Ide $ide) use ($outside, $project): void {
                    // A file outside the project: no review is about it, and there is no line to comment on.
                    Services::resolve($ide, BufferService::class)->openFile($outside->path('elsewhere.php'), OpenFocus::Editor);
                    $this->await(static fn (): bool => [] === Widgets::editor($tui)->decorations->commentMarks, what: 'the other file, unmarked');
                    Services::resolve($ide, CommandRegistry::class)->run('review:comment_line');
                    $this->awaitToast($ide, 'Open a project file to comment on');
                    Services::resolve($ide, BufferService::class)->openFile($project->path('src/A.php'), OpenFocus::Editor);
                    $this->await(static fn (): bool => [] !== Widgets::editor($tui)->decorations->commentMarks, what: 'back, marked again');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /** A settings edit acts at once: the display repaints, the poll re-arms, anything else re-reads the review. */
    public function testASettingsEditTakesEffectWithoutARestart(): void
    {
        \putenv('PATH=/nonexistent');
        $project = self::project();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                    $decorations = Widgets::editor($tui)->decorations;
                    $this->await(static fn (): bool => [] !== $decorations->commentMarks, what: 'the review loaded');
                    $lifecycle = Services::resolve($ide, PluginLifecycle::class);

                    $decorations->setCommentMarks([]);
                    $lifecycle->onPluginSettingChanged('review', 'editorDisplay', 'gutter');
                    $this->assertSame([3 => 'claude: Use array_sum here.'], $decorations->commentMarks, 'republished at once');

                    $lifecycle->onPluginSettingChanged('review', 'refreshSeconds', 0);

                    $project->write('.porta/reviews/agent/t9/thread.json', '{"path":"src/A.php","line":1}');
                    $project->write('.porta/reviews/agent/t9/1--claude.md', 'Header?');
                    $lifecycle->onPluginSettingChanged('review', 'provider', 'local'); // where reviews come from: re-read
                    $this->await(static fn (): bool => \array_key_exists(0, $decorations->commentMarks), what: 'the re-read');

                    $decorations->setCommentMarks([]);
                    $lifecycle->onPluginSettingChanged('review', 'showResolved', true); // what is shown: repainted, not re-read
                    $this->assertArrayHasKey(0, $decorations->commentMarks, 'republished at once');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * An agent answers while the thread is on screen: the poll notices, and the
     * open pane shows the new comment without a key pressed — after the user's
     * own reply and a resolve, the sequence a real session had once missed.
     */
    public function testAnAgentsAnswerAppearsInTheOpenThread(): void
    {
        \putenv('PATH=/nonexistent');
        $project = self::project();
        $thread = $project->path('.porta/reviews/agent/t1');

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($thread): void {
                    Services::resolve($ide, CommandRegistry::class)->run('review:open');
                    $this->awaitPainted($tui, $terminal, 'Tidy the loop');
                    $tui->handleInput("\x1b[B");
                    $this->awaitPainted($tui, $terminal, '$total += $item;');
                    $tui->handleInput('r');
                    $tui->handleInput('Done');
                    $tui->handleInput("\x1b[13;5u");
                    $this->await(static fn (): bool => 2 === \count((array) \glob($thread.'/*.md')), what: 'the reply');
                    $tui->handleInput("\x1b");
                    $tui->handleInput('x');
                    $this->await(static fn (): bool => \file_exists($thread.'/resolved'), what: 'the resolve');
                    $this->awaitPainted($tui, $terminal, 'Review (0 open)');

                    \file_put_contents($thread.'/20260929T100500Z--claude.md', data: "Thanks, resolved on my side too.\n");
                    $this->awaitPainted($tui, $terminal, 'Thanks, resolved on my side too.');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * The view's panes host the floating layers: a menu dropdown opened over
     * the view is painted INTO them. Without it the app menu's dropdown showed
     * only its top edge — the panes painted over the rest.
     */
    /** The app menu floats over the Review window: its dropdown paints above the window's panes. */
    public function testAMenuDropdownPaintsOverTheViewsWindow(): void
    {
        \putenv('PATH=/nonexistent');
        $project = self::project();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('review:open');
                    $this->awaitPainted($tui, $terminal, 'Tidy the loop');
                    $tui->handleInput("\x1b[21~"); // F10, then ↓: the File dropdown
                    $tui->handleInput("\x1b[B");
                    $this->frame($tui);

                    $screen = $this->screenGrid($tui, $terminal);
                    $quitRow = $this->rowOf($screen, 'Quit');
                    $this->assertNotNull($quitRow, 'the dropdown is on screen');
                    $window = Services::resolve($ide, ViewWindowPresenter::class)->box();
                    $this->assertInstanceOf(ModalBox::class, $window);
                    $this->assertTrue($window->contains($quitRow, $window->col + 1), 'it hangs down over the window, painted above it');
                    $this->assertTrue(Services::resolve($ide, ViewRegistry::class)->isOpen('review'), 'opening the menu leaves the window');
                    $tui->handleInput("\x1b");
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /** The view's left column resizes as one: a drag on the separator widens the list and the reply box alike. */
    public function testTheSidebarResizesByItsSeparator(): void
    {
        \putenv('PATH=/nonexistent');
        $project = self::project();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('review:open');
                    $this->awaitPainted($tui, $terminal, 'Tidy the loop');
                    $this->frame($tui);
                    $separator = Services::resolve($ide, ViewRegions::class)->find('review')?->separator();
                    \assert(null !== $separator, description: 'the view minted its region');
                    $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($separator);
                    \assert(null !== $rect, description: 'the separator was painted');
                    $list = Widgets::byId($tui, 'review-threads', ReviewListWidget::class);
                    $before = $list->width();

                    $row = $rect->row + 3;
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $rect->col + 1, $row)); // a bare move onto it first
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 1, $row));
                    $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", $rect->col + 11, $row));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 11, $row));
                    $this->frame($tui);

                    // Like every other divider, it thickens under the pointer before the drag
                    // — from its second row: the first is the inset beside the flush diff toolbar.
                    $this->assertStringContainsString(Glyphs::PANE_DIVIDER_ACTIVE, Paint::lines($separator, new RenderContext(1, 5))[1], 'heavy under the pointer, as the others are');
                    $this->assertSame($before + 10, $list->width(), 'the list follows the drag');
                    $this->assertSame($list->width(), Widgets::byId($tui, 'review-reply', ReplyBoxWidget::class)->width(), 'and the reply box with it');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * The view by mouse: a click on a thread shows it, a click on Send sends,
     * and the toolbar's show-resolved toggle — a click, or `s` — brings the
     * resolved threads back into the list and the pane, and persists.
     */
    public function testTheViewAnswersTheMouseAndTheShowResolvedToggle(): void
    {
        \putenv('PATH=/nonexistent');
        $project = self::project();
        $project->write('.porta/reviews/agent/t0/thread.json', '{"path":"src/A.php","line":2}');
        $project->write('.porta/reviews/agent/t0/1--claude.md', 'Settled already.');
        $project->write('.porta/reviews/agent/t0/resolved', '');

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, CommandRegistry::class)->run('review:open');
                    $this->awaitPainted($tui, $terminal, 'Review (1 open)');
                    $this->frame($tui);
                    $renderer = Services::resolve($ide, Renderer::class);
                    $click = static function (int $row, int $col) use ($tui): void {
                        $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col, $row));
                        $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col, $row));
                    };
                    $list = Widgets::byId($tui, 'review-threads', ReviewListWidget::class);
                    $this->assertFalse($list->selectId('t0'), 'resolved: hidden to begin with');

                    // The list's fourth row is the thread (title, status, conversation, the file heading).
                    $at = $renderer->getWidgetRect($list);
                    \assert(null !== $at, description: 'the list was painted');
                    $click($at->row + 1, $at->col + 3); // the list's title: no entry, nothing happens
                    $click($at->row + 4, $at->col + 3); // the file heading: none either
                    $this->assertSame('', $list->selectedId());
                    $click($at->row + 5, $at->col + 3);
                    $this->awaitPainted($tui, $terminal, '$total += $item;');
                    $this->assertSame('t1', $list->selectedId());

                    $reply = Widgets::byId($tui, 'review-reply', ReplyBoxWidget::class);
                    $tui->handleInput('r');
                    $tui->handleInput('Clicked');
                    $this->frame($tui);
                    $box = $renderer->getWidgetRect($reply);
                    \assert(null !== $box, description: 'the reply box was painted');
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $box->col + 5, $box->row + $reply->buttonRow() + 1)); // the pointer on the button
                    $this->frame($tui);
                    $this->assertSame($reply->buttonRow(), Probe::get($reply, 'hoveredIndex'), 'a ready button tints under the pointer');
                    $click($box->row + $reply->buttonRow() + 1, $box->col + 5); // its first row, boxed or compact
                    $this->await(static fn (): bool => 2 === \count((array) \glob($project->path('.porta/reviews/agent/t1/*.md'))), what: 'the click to send');

                    // The toolbar toggle on the pane's header.
                    $content = Widgets::byId($tui, 'review-diff', ReviewContentWidget::class);
                    $pane = $renderer->getWidgetRect($content);
                    \assert(null !== $pane, description: 'the pane was painted');
                    $window = Services::resolve($ide, ViewWindowPresenter::class)->box();
                    $this->assertInstanceOf(ModalBox::class, $window);
                    $this->assertSame($window->startRow + 1, $pane->row, 'the toolbar is flush under the title bar');
                    $this->assertSame($window->startRow + 2, $at->row, 'the list keeps the top row as its inset');
                    // The toggle's row: the only one on a short screen, the middle one boxed.
                    $header = \array_map(AnsiUtils::stripAnsiCodes(...), Paint::lines($content, new RenderContext($pane->columns, 6)));
                    $row = \array_key_first(\array_filter($header, static fn (string $line): bool => \str_contains($line, 'S show-resolved')));
                    \assert(null !== $row, description: 'the toggle is drawn');
                    $col = (int) \mb_strpos($header[$row], needle: 'S show-resolved');
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $pane->col + $col + 2, $pane->row + $row + 1));
                    $click($pane->row + $row + 1, $pane->col + $col + 2);
                    $this->frame($tui);
                    $this->assertTrue($list->selectId('t0'), 'shown now');
                    $this->assertTrue(
                        (bool) \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true)['plugins']['review']['showResolved'],
                        'and persisted, as the git toggles are',
                    );

                    $tui->setFocus($list);
                    $tui->handleInput('s');
                    $this->frame($tui);
                    $this->assertFalse($list->selectId('t0'), '`s` hides them again');

                    $click(row: 40, col: 200); // off every pane of the view's: the pointer passes it on
                    $tui->handleInput(\sprintf("\x1b[<64;%d;%dM", $at->col + 3, $at->row + 5)); // a wheel notch: the panes scroll by key, it falls through
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
