<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests\Console;

use PHPUnit\Framework\TestCase;
use Porta\ReviewPlugin\Console\ReviewCommand;
use Porta\ReviewPlugin\Local\LocalReviews;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** `porta review`, the way an agent drives it: write a thread, read the answers back. */
final class ReviewCommandTest extends TestCase
{
    private TempProject $project;

    private function tester(string $stdin = ''): CommandTester
    {
        $this->project ??= new TempProject(['src/Cart.php' => "<?php\n\$total = 0;\n"]);
        $application = new Application();
        $application->addCommand(new ReviewCommand($this->project->dir, static fn (): string => $stdin));

        return new CommandTester($application->find('review'));
    }

    /** @param array<string, mixed> $input */
    private function exec(array $input, string $stdin = ''): CommandTester
    {
        $tester = $this->tester($stdin);
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    private function reviews(): LocalReviews
    {
        return new LocalReviews($this->project->dir);
    }

    public function testAnAgentWritesAThreadAndReadsTheAnswersBack(): void
    {
        $add = $this->exec(['action' => 'add', 'words' => ['/src/Cart.php:2', 'Start', 'at', 'one?'], '--author' => 'claude', '--title' => 'Cart review']);
        $this->assertSame(0, $add->getStatusCode());
        $thread = \trim($add->getDisplay());

        $review = $this->reviews()->read('claude');
        $this->assertSame('Cart review', $review?->title, 'a first review is named after its author, titled as asked');
        $this->assertSame(['src/Cart.php', 2, '$total = 0;'], [$review?->threads[0]->anchor->path, $review?->threads[0]->anchor->line, $review?->threads[0]->anchor->lineText]);
        $this->assertSame('Start at one?', $review?->threads[0]->first()->body);
        $this->assertSame($thread, $review?->threads[0]->id, 'the new thread\'s id is printed, for the reply that follows');

        $this->assertSame(0, $this->exec(['action' => 'reply', 'words' => [$thread, '-'], '--author' => 'pierre'], "No, zero.\n")->getStatusCode());
        $this->assertSame(0, $this->exec(['action' => 'comment', 'words' => ['Overall', 'fine'], '--author' => 'claude'])->getStatusCode());
        $this->assertSame(0, $this->exec(['action' => 'resolve', 'words' => [$thread]])->getStatusCode());

        $json = \json_decode($this->exec(['action' => 'list', '--json' => true])->getDisplay(), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);
        $this->assertSame('claude', $json['id'], 'the newest review, when none is named');
        $this->assertTrue($json['threads'][0]['resolved']);
        $this->assertSame(['claude', 'pierre'], \array_column($json['threads'][0]['comments'], 'author'));
        $this->assertSame('No, zero.', $json['threads'][0]['comments'][1]['body'], 'a message of - is read from stdin');
        $this->assertSame([['author' => 'claude', 'body' => 'Overall fine']], \array_map(static fn (array $c): array => ['author' => $c['author'], 'body' => $c['body']], $json['conversation']));

        $this->assertSame(0, $this->exec(['action' => 'reopen', 'words' => [$thread]])->getStatusCode());
        $text = $this->exec(['action' => 'list'])->getDisplay();
        $this->assertStringContainsString('claude — Cart review', $text);
        $this->assertStringContainsString($thread.'  src/Cart.php:2  open', $text);
        $this->assertStringContainsString('    pierre: No, zero.', $text);
        $this->assertStringContainsString('  review: claude: Overall fine', $text);
    }

    public function testEveryMistakeSaysWhatWasWrongAndFails(): void
    {
        $cases = [
            [['action' => 'list', '--review' => 'none'], 'No review "none"'],
            [['action' => 'add', 'words' => ['src/Cart.php'], '--author' => 'a'], 'add takes FILE:LINE first, a 1-based line — "src/Cart.php" is not one'],
            [['action' => 'add', 'words' => ['src/Cart.php:0', 'x'], '--author' => 'a'], 'add takes FILE:LINE first, a 1-based line — "src/Cart.php:0" is not one'],
            [['action' => 'add', 'words' => ['src/Cart.php:2'], '--author' => 'a'], 'add needs a message'],
            [['action' => 'add', 'words' => ['src/Cart.php:2', 'x'], '--review' => '../escape'], 'Could not write the thread'],
            [['action' => 'reply', 'words' => ['../../etc', 'x'], '--review' => 'a'], 'No thread "../../etc" in review "a"'],
            [['action' => 'reply', 'words' => [], '--review' => 'a'], 'No thread "" in review "a"'],
            [['action' => 'resolve', 'words' => [LocalReviews::CONVERSATION], '--review' => 'a'], 'No thread "conversation" in review "a"'],
            [['action' => 'comment', 'words' => ['x'], '--review' => '..'], 'Could not write the comment'],
            [['action' => 'nope'], 'Unknown action "nope" — one of list, add, reply, resolve, reopen, comment'],
        ];
        foreach ($cases as [$input, $message]) {
            $tester = $this->exec($input);
            $this->assertSame(1, $tester->getStatusCode(), $message);
            $this->assertSame('porta review: '.$message, \trim($tester->getErrorOutput()), 'on stderr, so a piped stdout stays clean');
        }
        $this->assertDirectoryDoesNotExist($this->project->path('.porta/escape'), 'a name never leaves the reviews directory');
    }

    public function testTheAuthorFallsBackToTheEnvironmentThenGit(): void
    {
        $this->project = new TempProject(['a.php' => "x\n"]);
        $this->project->initGit(name: 'Git Name');
        \putenv('PORTA_REVIEW_AUTHOR=agent-7');

        try {
            $this->exec(['action' => 'comment', 'words' => ['one']]);
        } finally {
            \putenv('PORTA_REVIEW_AUTHOR');
        }
        $this->exec(['action' => 'comment', 'words' => ['two'], '--review' => 'agent-7']);

        $this->assertSame(['agent-7', 'Git Name'], \array_map(static fn ($c): string => $c->author, $this->reviews()->read('agent-7')->conversation ?? []));
    }

    public function testTheProjectIsFoundFromAnyDirectoryInsideIt(): void
    {
        $project = new TempProject(['deep/er/x.txt' => '', '.porta/keep' => '']);
        $cwd = (string) \getcwd();
        \chdir($project->path('deep/er'));

        try {
            $application = new Application();
            $application->addCommand(new ReviewCommand());
            $tester = new CommandTester($application->find('review'));
            $tester->execute(['action' => 'comment', 'words' => ['found'], '--author' => 'x']);
        } finally {
            \chdir($cwd);
        }

        $this->assertSame('found', new LocalReviews($project->dir)->read('x')?->conversation[0]->body);

        $bare = new TempProject(['x.txt' => '']);
        \chdir($bare->dir);
        try {
            $application = new Application();
            $application->addCommand(new ReviewCommand(stdin: static fn (): string => ' '));
            $tester = new CommandTester($application->find('review'));
            $tester->execute(['action' => 'comment', 'words' => ['-'], '--author' => 'x']);
        } finally {
            \chdir($cwd);
        }
        $this->assertSame(0, $tester->getStatusCode(), 'no project above: the working directory is the project');
        $this->assertDirectoryExists($bare->path('.porta/reviews/x/conversation'));
    }
}
