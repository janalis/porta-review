<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Ide;
use Porta\ReviewPlugin\Tests\Support\FakeForge;
use Porta\ReviewPlugin\Tests\Support\UsesFakeForge;
use Porta\ReviewPlugin\Widget\ReplyBoxWidget;
use Porta\ReviewPlugin\Widget\ReviewContentWidget;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RendersWidget;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Tests\Support\Widgets;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A pull request through a real IDE, with `gh` faked by a script that answers
 * GraphQL reads and records every write — and a real repository under it, so
 * the review's diff (base...head) and the editor's line translation (head to
 * working copy) are git's own answers.
 */
final class GitHubReviewE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use GitBinary;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;
    use UsesFakeForge;
    use RendersWidget;

    private FakeForge $forge;
    private string $base = '';
    private string $head = '';

    /**
     * The branch `feature/tidy` on github.com:acme/app: the base commit, the
     * reviewed head (line 3 rewritten), and a working copy with a line added
     * at the top — so the head's line 3 is the working copy's line 4.
     */
    private function project(string $display = 'gutter', string $resolved = 'false'): TempProject
    {
        $this->skipWithoutGit();
        \putenv('PATH='.\PORTA_TEST_PRISTINE_PATH);
        $project = new TempProject([
            'src/A.php' => "<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n",
            'src/B.php' => "<?php\n",
            '.porta.json' => '{"enabledPlugins":["diff","git","review"],"plugins":{"review":{"ghBinary":"'.FakeForge::SCRIPT.'","editorDisplay":"'.$display.'","showResolved":'.$resolved.'}}}',
        ]);
        $project->initGit('feature/tidy');
        $project->git('remote', 'add', 'origin', 'git@github.com:acme/app.git');
        $project->commitAll('base');
        $this->base = $project->git('rev-parse', 'HEAD');
        $project->write('src/A.php', "<?php\n\$a = 1;\n\$bee = 2;\n\$c = 3;\n");
        $project->commitAll('head');
        $this->head = $project->git('rev-parse', 'HEAD');
        $project->write('src/A.php', "<?php\n// added\n\$a = 1;\n\$bee = 2;\n\$c = 3;\n");

        $this->forge = new FakeForge();
        $this->forge->route('query(', $this->pullRequest([['PRRT_1', 3, false, [['bob', "Why bee?\nIt was b."]]], ['PRRT_2', 4, true, [['carol', 'Fine.']]]]));
        $this->forge->route('mutation(', ['data' => ['ok' => true]]);
        $this->forge->route('pulls/12/comments', ['id' => 1]);

        return $project;
    }

    /**
     * @param list<array{string, int, bool, list<array{string, string}>}> $threads id, line, resolved, comments
     *
     * @return array<string, mixed>
     */
    private function pullRequest(array $threads, int $conversation = 1): array
    {
        $comments = static fn (array $list): array => ['nodes' => \array_map(static fn (array $c): array => [
            'author' => ['login' => $c[0]],
            'body' => $c[1],
            'createdAt' => '2026-09-28T10:00:00Z',
        ], $list)];

        return [
            'data' => [
                'repository' => [
                    'pullRequests' => [
                        'nodes' => [[
                            'id' => 'PR_node',
                            'number' => 12,
                            'title' => 'Tidy the names',
                            'url' => 'https://github.com/acme/app/pull/12',
                            'baseRefOid' => $this->base,
                            'headRefOid' => $this->head,
                            'comments' => $comments(\array_slice(
                                [
                                    ['alice', 'Please review.'],
                                    ['bob',   'On it.'],
                                ],
                                offset: 0,
                                length: $conversation,
                            )),
                            'reviewThreads' => ['nodes' => \array_map(static fn (array $t): array => [
                                'id' => $t[0],
                                'isResolved' => $t[2],
                                'isOutdated' => false,
                                'path' => 'src/A.php',
                                'line' => $t[1],
                                'originalLine' => $t[1],
                                'diffSide' => 'RIGHT',
                                'comments' => $comments($t[3]),
                            ], $threads)],
                        ]],
                    ],
                ],
            ],
        ];
    }

    /** Whether the pull request was read after the first call mentioning $needle. */
    private function readAfter(string $needle): bool
    {
        $seen = false;
        foreach ($this->forge->calls() as $call) {
            $seen = $seen || \str_contains(\implode(' ', $call['argv']), $needle);
            if ($seen && \str_contains($call['stdin'], '"query":"query(')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the GraphQL queries and REST paths the fake saw after the reads, in order */
    private function writes(): array
    {
        $writes = [];
        foreach ($this->forge->calls() as $call) {
            $query = (string) (\json_decode($call['stdin'], associative: true, flags: \JSON_THROW_ON_ERROR)['query'] ?? '');
            if (\str_starts_with($query, 'query(')) {
                continue;
            }
            $writes[] = '' === $query ? $call['argv'][3] : (string) \preg_replace('/\(.*/s', replacement: '', subject: \substr($query, offset: \strpos($query, needle: '{') + 2));
        }

        return $writes;
    }

    /** Every git process the plugin started has answered — its runner's lane, reached through the view. */
    private function awaitGitIdle(Ide $ide): void
    {
        $lane = Probe::get(Probe::get(Services::resolve($ide, ViewRegistry::class)->find('review'), 'git'), 'lane');
        $this->await(static fn (): bool => 0 === $lane->inFlight(), what: 'the plugin\'s git');
    }

    /** The review's reload has answered, and the pane it re-reads with it. */
    private function awaitReloaded(Ide $ide): void
    {
        $store = Probe::get(Services::resolve($ide, ViewRegistry::class)->find('review'), 'store');
        $this->await(static fn (): bool => !$store->loading, what: 'the review to reload');
        $this->awaitGitIdle($ide);
    }

    private function awaitToast(Ide $ide, string $message): void
    {
        $this->await(static fn (): bool => \in_array($message, Toasts::all($ide), strict: true), what: $message);
    }

    public function testTheEditorMarksTheThreadWhereItsLineIsNow(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $marks = static fn (): array => Widgets::editor($tui)->decorations->commentMarks;
                    $this->await(static fn (): bool => [] !== $marks(), what: 'the gutter mark');
                    $this->assertSame([3 => 'bob: Why bee?'], $marks(), 'head line 3 is the working copy\'s 4th; the resolved thread is hidden');
                    $this->awaitPainted($tui, $terminal, '✎ PR #12 · 1');

                    // The caret on the marked line: "show conversation" opens the view on it.
                    Widgets::editor($tui)->jumpTo(3, 0);
                    Services::resolve($ide, CommandRegistry::class)->run('review:show_conversation');
                    $this->awaitPainted($tui, $terminal, '+$bee = 2;');
                    $this->assertTrue(Services::resolve($ide, ViewRegistry::class)->find('review')?->isOpen());
                    $this->assertStringContainsString('-$b = 2;', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the review\'s own diff, base...head');
                },
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    // Enter on the thread (the content pane's caret is on it) opens the file there.
                    $tui->setFocus($tui->getById('review-diff'));
                    $tui->handleInput("\r");
                    $this->await(static fn (): bool => true !== Services::resolve($ide, ViewRegistry::class)->find('review')?->isOpen(), what: 'the view to close');
                    $this->assertSame(2, Widgets::editor($tui)->getDocument()->cursorLine, 'the head\'s line 3, as the thread names it');

                    Widgets::editor($tui)->jumpTo(0, 0);
                    Services::resolve($ide, CommandRegistry::class)->run('review:show_conversation');
                    $this->awaitToast($ide, 'No conversation on this line');

                    Services::resolve($ide, CommandRegistry::class)->run('review:copy_link');
                    $this->awaitToast($ide, 'Copied https://github.com/acme/app/pull/12');
                },
            ],
        );
    }

    public function testRepliesResolvesCommentsAndTheConversationGoToGitHub(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('review:open');
                    $this->awaitPainted($tui, $terminal, 'Tidy the names');
                    // The conversation first: `c` in the list writes to the review.
                    $this->awaitPainted($tui, $terminal, 'Please review.');
                    $tui->handleInput('c');
                    $this->awaitPainted($tui, $terminal, 'Comment on PR #12');
                    $tui->handleInput('Ready');
                    $tui->handleInput("\x1b[13;5u");
                    $this->awaitToast($ide, 'Sent');
                },
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $tui->setFocus($tui->getById('review-threads'));
                    $tui->handleInput("\x1b[B"); // the thread
                    $this->awaitPainted($tui, $terminal, '+$bee = 2;');
                    $tui->handleInput('r');
                    $this->awaitPainted($tui, $terminal, 'Reply to bob on src/A.php:3');
                    $tui->handleInput('Renamed');
                    $tui->handleInput("\x1b[13;5u");
                    $this->await(fn (): bool => 2 === \count($this->writes()), what: 'the reply');
                },
                400 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $tui->setFocus($tui->getById('review-threads'));
                    $tui->handleInput('x');
                    $this->awaitToast($ide, 'Thread resolved');
                    // The toast is the write's answer; the reload it starts re-reads the
                    // diff and puts the cursor back on the thread — after the keys below,
                    // under load, which took the `c` off its line.
                    $this->awaitReloaded($ide);
                    // Tab round the three panes, then `c` on a diff line starts a thread there.
                    $tui->handleInput("\t");
                    $this->assertSame($tui->getById('review-reply'), $tui->getFocus());
                    $tui->handleInput("\t");
                    $this->assertSame($tui->getById('review-diff'), $tui->getFocus());
                    $tui->handleInput("\x1b[A"); // from the thread's head to the added line it is under
                    $tui->handleInput('c');
                    $this->awaitPainted($tui, $terminal, 'New comment on src/A.php:3');
                    $tui->handleInput('Nit');
                    $tui->handleInput("\x1b[13;5u");
                    // Typed while the comment is on its way: its answer must not take this with it.
                    $tui->handleInput('x');
                    // The barrier is a read logged AFTER the comment's own call: the reload
                    // a successful write starts, right after the box is cleared.
                    $this->await(fn (): bool => $this->readAfter('pulls/12/comments'), what: 'the reload after the comment');
                    $this->assertSame('Nitx', Widgets::byId($tui, 'review-reply', ReplyBoxWidget::class)->text(), 'not what was sent, so kept');
                },
                500 => function (Tui $tui, $terminal, Ide $ide): void {
                    $this->assertSame(['addComment', 'addPullRequestReviewThreadReply', 'resolveReviewThread', 'repos/acme/app/pulls/12/comments'], $this->writes());
                    $rest = \array_values(\array_filter(\array_map($this->forge->body(...), \array_keys($this->forge->calls())), static fn (array $b): bool => \array_key_exists(
                        'commit_id',
                        $b,
                    )));
                    $this->assertSame([['body' => 'Nit', 'commit_id' => $this->head, 'path' => 'src/A.php', 'line' => 3, 'side' => 'RIGHT']], $rest);

                    // A refused write says why, and keeps the draft.
                    $this->forge->route('mutation(', ['errors' => [['message' => 'Thread is locked']]]);
                    $tui->setFocus($tui->getById('review-threads'));
                    $tui->handleInput('r');
                    $tui->handleInput('Again');
                    $tui->handleInput("\x1b[13;5u");
                    $this->awaitToast($ide, 'Thread is locked');
                    $this->assertSame('Again', Widgets::byId($tui, 'review-reply', ReplyBoxWidget::class)->text());
                },
            ],
        );
    }

    /**
     * A reload that lands while a thread's file is still loading keeps that
     * thread. The pane still showed what was there before, so asking IT what
     * to focus answered nothing, and the newer load put the caret on the hunk
     * header instead — the review E2e's `c` then commented on no line.
     */
    public function testAReloadDuringAThreadsLoadKeepsTheThread(): void
    {
        $project = $this->project();
        // The review's own diff (base...head) answers late; nothing else does.
        $slow = new TempProject(['git' => \sprintf("#!/bin/sh\nfor a in \"\$@\"; do case \"\$a\" in *...*) sleep 1.5 ;; esac; done\nexec %s \"\$@\"\n", \escapeshellarg((string) $this->whichGit()))]);
        \chmod($slow->path('git'), permissions: 0o755);
        \putenv('PATH='.$slow->dir.':'.\PORTA_TEST_PRISTINE_PATH);

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('review:open');
                    $this->awaitPainted($tui, $terminal, 'Why bee?');
                    $tui->setFocus($tui->getById('review-threads'));
                    $tui->handleInput("\x1b[B"); // the thread: its file starts loading
                    $tui->handleInput('R'); // and a reload overtakes it
                    $this->awaitReloaded($ide);

                    $this->assertSame('PRRT_1', Widgets::byId($tui, 'review-diff', ReviewContentWidget::class)->currentThread()?->id, 'the caret is on the thread that was asked for');
                },
            ],
        );
    }

    public function testTheSummaryModeWritesTheThreadAfterItsLine(): void
    {
        $project = $this->project('summary');

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function (Tui $tui, $terminal): void {
                    $this->await(static fn (): bool => [] !== Widgets::editor($tui)->decorations->lineAnnotations, what: 'the summary');
                    $this->assertSame([3 => '✎ bob: Why bee?'], Widgets::editor($tui)->decorations->lineAnnotations);
                    $this->assertSame([], Widgets::editor($tui)->decorations->lineBlocksAt(3));
                },
            ],
        );
    }

    public function testTheInlineModeShowsTheWholeThreadUnderItsLine(): void
    {
        $project = $this->project('inline', 'true');

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $editor = Widgets::editor($tui);
                    $this->await(static fn (): bool => [] !== $editor->decorations->lineBlocksAt(3), what: 'the block');
                    $this->assertSame(['✎ bob: Why bee?', '    It was b.'], $editor->decorations->lineBlocksAt(3));
                    $this->assertSame(['✎ carol: Fine.  (resolved)'], $editor->decorations->lineBlocksAt(4), 'shown resolved when asked');
                    $this->assertSame([], $editor->decorations->lineAnnotations);
                    $this->awaitPainted($tui, $terminal, 'It was b.');
                },
            ],
        );
    }

    /**
     * A diff that answers after the editor moved on to another file is
     * dropped: painting it would put the first file's summaries on the second.
     */
    public function testAnOvertakenLineTranslationIsDropped(): void
    {
        $project = $this->project('summary');

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                    $editor = Widgets::editor($tui);
                    $this->await(static fn (): bool => [] !== $editor->decorations->lineAnnotations, what: 'A\'s summary');
                    $buffers = Services::resolve($ide, BufferService::class);

                    $buffers->openFile($project->path('src/B.php'), OpenFocus::Editor);
                    $buffers->openFile($project->path('src/A.php'), OpenFocus::Editor); // a diff starts
                    $buffers->openFile($project->path('src/B.php'), OpenFocus::Editor); // and is overtaken
                    $this->awaitGitIdle($ide);
                    $this->frame($tui);

                    $this->assertStringEndsWith('src/B.php', (string) $editor->getFilePath());
                    $this->assertSame([], $editor->decorations->lineAnnotations, 'A\'s late answer did not paint over B');
                },
            ],
        );
    }

    /** The same for the view: a file that loads after the conversation was picked does not replace it. */
    public function testAnOvertakenFileLoadInTheViewIsDropped(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('review:open');
                    $this->awaitPainted($tui, $terminal, 'Please review.');
                    $this->awaitGitIdle($ide);

                    $tui->handleInput("\x1b[B"); // the thread: its file starts loading
                    $tui->handleInput("\x1b[A"); // back to the conversation before it answers
                    $this->awaitGitIdle($ide);
                    $this->frame($tui);

                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('PR #12 · Tidy the names', $screen, 'the conversation\'s title');
                    $this->assertStringNotContainsString('+$bee = 2;', \implode("\n", $this->rows(Widgets::byId($tui, 'review-diff', ReviewContentWidget::class)->render(new RenderContext(80, 20)))));
                },
            ],
        );
    }
}
