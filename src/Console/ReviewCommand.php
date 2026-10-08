<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Console;

use Porta\Plugin\Api\PluginCommand;
use Porta\ReviewPlugin\Local\LocalReviews;
use Porta\ReviewPlugin\Model\Anchor;
use Porta\ReviewPlugin\Model\Comment;
use Porta\ReviewPlugin\Model\Review;
use Porta\ReviewPlugin\Model\Side;
use Porta\ReviewPlugin\Model\Thread;
use Porta\Support\Files;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * `porta review` — local reviews from a shell: what an agent runs to leave a
 * review for the user, and to read back what the user answered in the IDE.
 *
 * The same files the Review view reads and writes ({@see LocalReviews}), so a
 * comment added here shows in an open IDE within the poll's two seconds, and a
 * reply typed there is in the next `list`.
 */
#[AsCommand(
    name: 'review',
    description: 'Write and read local code reviews — the ones Porta shows beside the code',
    help: <<<'HELP'
        Local reviews live in <info>.porta/reviews/</info>; the IDE shows them in the Review view
        and on the lines they are about.

          <info>porta review add src/Cart.php:9 "Use array_sum here."</info>   start a thread on a line
          <info>porta review reply t20260929T101500 "Done."</info>              answer a thread
          <info>porta review resolve t20260929T101500</info>                    resolve it (<info>reopen</info> undoes)
          <info>porta review comment "Overall fine."</info>                     comment on the review itself
          <info>porta review list</info>                                        every thread and its answers (<info>--json</info>)

        A message of <info>-</info> is read from stdin. Comments are signed with <info>--author</info>, else
        $PORTA_REVIEW_AUTHOR, else git's user.name. The review is <info>--review</info>, else the newest
        one on disk; a first one is named after its author.
        HELP,
    usages: ['add src/Cart.php:9 "Use array_sum here." --author=claude', 'reply t20260929T101500 "Done."', 'list --json'],
)]
final class ReviewCommand extends PluginCommand
{
    private const array ACTIONS = ['list', 'add', 'reply', 'resolve', 'reopen', 'comment'];

    /**
     * @param string|null               $root  the project; null finds it from the working directory
     * @param (\Closure(): string)|null $stdin what `-` reads; null reads the real stdin
     */
    public function __construct(
        ?string $root = null,
        private readonly ?\Closure $stdin = null,
    ) {
        parent::__construct($root);
    }

    /** @param list<string> $words */
    public function __invoke(
        OutputInterface $output,
        #[Argument('list, add, reply, resolve, reopen or comment')]
        string $action,
        #[Argument('add: FILE:LINE and the message · reply: the thread and the message · resolve/reopen: the thread · comment: the message')]
        array $words = [],
        #[Option('The review to write to or read, by name')]
        ?string $review = null,
        #[Option('Who signs the comment')]
        ?string $author = null,
        #[Option('Name the review — its title in the IDE')]
        ?string $title = null,
        #[Option('list: as JSON, for a program to read')]
        bool $json = false,
    ): int {
        $errors = self::errors($output);
        $root = $this->projectRoot();
        $reviews = new LocalReviews($root);
        $who = $author ?? self::defaultAuthor($root);
        $id = $review ?? $reviews->ids()[0] ?? LocalReviews::fileSafe($who);
        if (null !== $title) {
            $reviews->describe($id, $title);
        }

        $failure = match ($action) {
            'list' => $this->list($reviews, $id, $json, $output),
            'add' => $this->add($reviews, $root, $id, $who, $words, $output),
            'reply' => $this->write($reviews->append($id, $words[0] ?? '', $who, $this->message(\array_slice($words, offset: 1))), 'No thread "'.($words[0] ?? '').'" in review "'.$id.'"'),
            'resolve', 'reopen' => $this->write($reviews->resolve($id, $words[0] ?? '', 'resolve' === $action), 'No thread "'.($words[0] ?? '').'" in review "'.$id.'"'),
            'comment' => $this->write($reviews->append($id, LocalReviews::CONVERSATION, $who, $this->message($words)), 'Could not write the comment'),
            default => 'Unknown action "'.$action.'" — one of '.\implode(', ', self::ACTIONS),
        };
        if (null !== $failure) {
            $errors->writeln('porta review: '.$failure);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @param list<string> $words */
    private function add(LocalReviews $reviews, string $root, string $id, string $author, array $words, OutputInterface $output): ?string
    {
        $at = \array_shift($words) ?? '';
        $parts = [];
        if (1 !== \preg_match('/^(.+):(\d+)$/', $at, $parts) || 0 === (int) $parts[2]) {
            return 'add takes FILE:LINE first, a 1-based line — "'.$at.'" is not one';
        }
        $path = \ltrim($parts[1], characters: '/');
        $line = (int) $parts[2];
        $lines = \explode("\n", Files::read($root.'/'.$path));
        $body = $this->message($words);
        if ('' === $body) {
            return 'add needs a message';
        }
        $thread = $reviews->open($id, new Anchor($path, $line, Side::New, $lines[$line - 1] ?? null), $author, $body);
        if (null === $thread) {
            return 'Could not write the thread';
        }
        $output->writeln($thread);

        return null;
    }

    // @mago-expect lint:no-boolean-flag-parameter -- --json IS the choice of format.
    private function list(LocalReviews $reviews, string $id, bool $json, OutputInterface $output): ?string
    {
        $review = $reviews->read($id);
        if (null === $review) {
            return 'No review "'.$id.'"';
        }
        if ($json) {
            $output->writeln(\json_encode(self::asArray($review), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

            return null;
        }
        $output->writeln($review->id.' — '.$review->title);
        foreach ($review->threads as $thread) {
            $output->writeln(\sprintf('  %s  %s:%d  %s', $thread->id, $thread->anchor->path, $thread->anchor->line, $thread->resolved ? 'resolved' : 'open'));
            foreach ($thread->comments as $comment) {
                $output->writeln('    '.$comment->author.': '.\str_replace("\n", replace: "\n      ", subject: $comment->body));
            }
        }
        foreach ($review->conversation as $comment) {
            $output->writeln('  review: '.$comment->author.': '.$comment->body);
        }

        return null;
    }

    // @mago-expect lint:no-boolean-flag-parameter -- the bool is the write's outcome, turned into a sentence.
    private function write(bool $written, string $failure): ?string
    {
        return $written ? null : $failure;
    }

    /** @param list<string> $words the message, or `-` to read it from stdin */
    private function message(array $words): string
    {
        $text = ['-'] === $words ? ($this->stdin ?? static fn (): string => (string) \stream_get_contents(\STDIN))() : \implode(' ', $words);

        return \trim($text);
    }

    /** @return array<string, mixed> */
    private static function asArray(Review $review): array
    {
        $comments = static fn (array $list): array => \array_map(static fn (Comment $c): array => ['author' => $c->author, 'body' => $c->body, 'at' => $c->createdAt], $list);

        return [
            'id' => $review->id,
            'title' => $review->title,
            'threads' => \array_map(static fn (Thread $t): array => [
                'id' => $t->id,
                'path' => $t->anchor->path,
                'line' => $t->anchor->line,
                'side' => $t->anchor->side->value,
                'resolved' => $t->resolved,
                'comments' => $comments($t->comments),
            ], $review->threads),
            'conversation' => $comments($review->conversation),
        ];
    }

    private static function defaultAuthor(string $root): string
    {
        $env = \getenv('PORTA_REVIEW_AUTHOR');
        if (\is_string($env) && '' !== $env) {
            return $env;
        }
        $git = new Process(['git', 'config', 'user.name'], $root);
        $git->run();
        $name = \trim($git->getOutput());

        return '' === $name ? \get_current_user() : $name;
    }
}
