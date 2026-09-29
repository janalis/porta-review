<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin;

use Porta\Plugin\Api\PluginApi;
use Porta\ReviewPlugin\Model\Anchor;
use Porta\ReviewPlugin\Model\Comment;
use Porta\ReviewPlugin\Model\Review;
use Porta\ReviewPlugin\Model\Thread;
use Porta\ReviewPlugin\Source\ReviewSource;
use Porta\ReviewPlugin\Source\ReviewStore;
use Porta\Support\FiberWait;
use Revolt\EventLoop;

/**
 * The branch's review as tools: an agent reads every conversation — a pull
 * request's, a merge request's or a local one — and answers or starts one,
 * the way the user does in the review view.
 *
 * Every write goes through {@see ReviewStore::act()}, the view's own path, so
 * the source decides what the reply looks like and the IDE reloads to show it.
 */
final readonly class ReviewTools
{
    /** @param float $loadTimeout how long a call waits for a load in flight before giving up, in seconds */
    public function __construct(
        private PluginApi $api,
        private ReviewStore $store,
        private float $loadTimeout = 30.0,
    ) {}

    public function register(): void
    {
        $this->api->registerTool(
            'threads',
            'The current branch\'s review — a GitHub pull request, a GitLab merge request, or a local review — with every line conversation (path, 1-based line, resolved, comments) and the review-level ones.',
            ['type' => 'object', 'properties' => ['includeResolved' => ['type' => 'boolean', 'description' => 'Also the resolved threads; default false']]],
            $this->threads(...),
        );
        $this->api->registerTool(
            'reply',
            'Answer a thread of the current review, by its id from review_threads.',
            [
                'type' => 'object',
                'properties' => ['thread' => ['type' => 'string'], 'body' => ['type' => 'string']],
                'required' => ['thread', 'body'],
            ],
            $this->reply(...),
        );
        $this->api->registerTool(
            'resolve',
            'Resolve a thread of the current review, by its id from review_threads — or reopen it with resolved: false (list the resolved ones with includeResolved).',
            [
                'type' => 'object',
                'properties' => ['thread' => ['type' => 'string'], 'resolved' => ['type' => 'boolean', 'description' => 'Default true']],
                'required' => ['thread'],
            ],
            $this->resolve(...),
        );
        $this->api->registerTool(
            'comment',
            'Start a conversation on a line of the current review: a project-relative path and a 1-based line of the file as it is now.',
            [
                'type' => 'object',
                'properties' => ['path' => ['type' => 'string'], 'line' => ['type' => 'integer', 'minimum' => 1], 'body' => ['type' => 'string']],
                'required' => ['path', 'line', 'body'],
            ],
            $this->comment(...),
        );
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function threads(array $arguments): array
    {
        $review = $this->review();
        $withResolved = true === ($arguments['includeResolved'] ?? false);
        $threads = [];
        foreach ($review->threads as $thread) {
            if ($thread->resolved && !$withResolved) {
                continue;
            }
            $threads[] = [
                'id' => $thread->id,
                'path' => $thread->anchor->path,
                'line' => $thread->anchor->line,
                'resolved' => $thread->resolved,
                'outdated' => $thread->outdated,
                'comments' => self::comments($thread->comments),
            ];
        }

        return [
            'review' => $review->label(),
            'title' => $review->title,
            'url' => $review->url,
            'threads' => $threads,
            'conversation' => self::comments($review->conversation),
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function reply(array $arguments): string
    {
        $body = self::text($arguments, 'body');
        $thread = $this->thread($arguments);
        $this->write(static function (ReviewSource $source, Review $review, \Closure $done) use ($thread, $body): void {
            $source->reply($review, $thread, $body, $done);
        });

        return 'Replied to '.$thread->id;
    }

    /** @param array<string, mixed> $arguments */
    private function resolve(array $arguments): string
    {
        $thread = $this->thread($arguments);
        // Only an explicit false reopens: resolving is what the verb is for.
        $resolved = false !== ($arguments['resolved'] ?? true);
        if ($resolved === $thread->resolved) {
            return \sprintf('%s is already %s', $thread->id, $resolved ? 'resolved' : 'open');
        }
        $this->write(static function (ReviewSource $source, Review $review, \Closure $done) use ($thread, $resolved): void {
            $source->setResolved($review, $thread, $resolved, $done);
        });

        return ($resolved ? 'Resolved ' : 'Reopened ').$thread->id;
    }

    /** @param array<string, mixed> $arguments */
    private function thread(array $arguments): Thread
    {
        $id = self::text($arguments, 'thread');

        return $this->review()->thread($id) ?? throw new \InvalidArgumentException(\sprintf('No thread "%s" in this review', $id));
    }

    /** @param array<string, mixed> $arguments */
    private function comment(array $arguments): string
    {
        $path = self::text($arguments, 'path');
        $body = self::text($arguments, 'body');
        $line = \is_int($arguments['line'] ?? null) ? $arguments['line'] : 0;
        if ($line < 1) {
            throw new \InvalidArgumentException('line is a positive integer');
        }
        $this->review();
        $this->write(static function (ReviewSource $source, Review $review, \Closure $done) use ($path, $line, $body): void {
            $source->comment($review, new Anchor($path, $line), $body, $done);
        });

        return \sprintf('Commented on %s:%d', $path, $line);
    }

    /**
     * The review as loaded — waiting for a load in flight to settle first,
     * so a call right after a write, or while the IDE starts, reads the
     * fresh one instead of failing. A forge that never answers is given up
     * on after {@see $loadTimeout}.
     */
    private function review(): Review
    {
        if ($this->store->loading) {
            $timer = '';
            /** @var bool $settled */
            $settled = FiberWait::for(function (\Closure $done) use (&$timer): void {
                $timer = EventLoop::delay($this->loadTimeout, static fn () => $done(false));
                $this->store->whenSettled(static fn () => $done(true));
            });
            EventLoop::cancel($timer);
            if (true !== $settled) {
                throw new \RuntimeException(\sprintf('The review is still loading after %gs — ask again later', $this->loadTimeout));
            }
        }
        if (null !== $this->store->review) {
            return $this->store->review;
        }

        throw new \RuntimeException($this->store->error ?? 'This branch has no review');
    }

    /**
     * Run a write and wait for the source's answer; its complaint is thrown.
     *
     * @param \Closure(ReviewSource, Review, \Closure(?string): void): void $verb
     */
    private function write(\Closure $verb): void
    {
        /** @var string|null $error */
        $error = FiberWait::for(fn (\Closure $done) => $this->store->act($verb, $done));
        if (null !== $error) {
            throw new \RuntimeException($error);
        }
    }

    /**
     * @param list<Comment> $comments
     *
     * @return list<array{author: string, body: string, createdAt: string}>
     */
    private static function comments(array $comments): array
    {
        return \array_map(static fn (Comment $comment): array => ['author' => $comment->author, 'body' => $comment->body, 'createdAt' => $comment->createdAt], $comments);
    }

    /** @param array<string, mixed> $arguments */
    private static function text(array $arguments, string $key): string
    {
        $value = \is_string($arguments[$key] ?? null) ? $arguments[$key] : '';
        if ('' === \trim($value)) {
            throw new \InvalidArgumentException($key.' is required');
        }

        return $value;
    }
}
