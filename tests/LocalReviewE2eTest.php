<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Ide;
use Porta\Plugin\Lifecycle\PluginLifecycle;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Ui\Glyphs;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Tui\Ansi\AnsiUtils;
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
                    $this->await(static fn (): bool => [] !== $tui->getById('editor')->decorations->commentMarks, what: 'the gutter mark');
                    $this->assertSame([3 => 'claude: Use array_sum here.'], $tui->getById('editor')->decorations->commentMarks);

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
                    $this->await(static fn (): bool => [] !== $tui->getById('editor')->decorations->commentMarks, what: 'the review loaded');
                    $tui->getById('editor')->jumpTo(1, 0);
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
                    $this->assertNull($view?->pointer());
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
                    $this->assertSame('', $tui->getById('review-reply')->targetLabel(), 'no review to write to');

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
                    $marks = static fn (): array => $tui->getById('editor')->decorations->commentMarks;
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
                    $this->await(static fn (): bool => [] === $tui->getById('editor')->decorations->commentMarks, what: 'the other file, unmarked');
                    Services::resolve($ide, CommandRegistry::class)->run('review:comment_line');
                    $this->awaitToast($ide, 'Open a project file to comment on');
                    Services::resolve($ide, BufferService::class)->openFile($project->path('src/A.php'), OpenFocus::Editor);
                    $this->await(static fn (): bool => [] !== $tui->getById('editor')->decorations->commentMarks, what: 'back, marked again');
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
                    $decorations = $tui->getById('editor')->decorations;
                    $this->await(static fn (): bool => [] !== $decorations->commentMarks, what: 'the review loaded');
                    $lifecycle = Services::resolve($ide, PluginLifecycle::class);

                    $decorations->setCommentMarks([]);
                    $lifecycle->onPluginSettingChanged('review', 'editorDisplay', 'gutter');
                    $this->assertSame([3 => 'claude: Use array_sum here.'], $decorations->commentMarks, 'republished at once');

                    $lifecycle->onPluginSettingChanged('review', 'refreshSeconds', 0);

                    $project->write('.porta/reviews/agent/t9/thread.json', '{"path":"src/A.php","line":1}');
                    $project->write('.porta/reviews/agent/t9/1--claude.md', 'Header?');
                    $lifecycle->onPluginSettingChanged('review', 'showResolved', true);
                    $this->await(static fn (): bool => \array_key_exists(0, $decorations->commentMarks), what: 'the re-read');
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
}
