<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Model;

/**
 * One review of the current branch, as its source last reported it: the line
 * threads, the review-level conversation, and the two commits its diff spans.
 *
 * Immutable: every refresh builds a new one, so a widget holding the previous
 * value keeps a consistent picture until it is handed the next.
 */
final readonly class Review
{
    /**
     * @param list<Thread>  $threads
     * @param list<Comment> $conversation review-level comments, oldest first
     */
    public function __construct(
        public SourceKind $kind,
        public string $id,
        public string $title,
        public array $threads = [],
        public array $conversation = [],
        /** the diff's base; null when the source has none (a local review of uncommitted work diffs against HEAD) */
        public ?string $baseSha = null,
        /** the reviewed commit; null for a local review, which reviews the working copy */
        public ?string $headSha = null,
        public ?string $url = null,
        /** GitLab's `start_sha`, which a new diff note must quote back; null elsewhere */
        public ?string $startSha = null,
        /** the source's own handle for the review — GitHub's node id; '' when the id is enough */
        public string $handle = '',
    ) {}

    public function label(): string
    {
        return $this->kind->label($this->id);
    }

    public function thread(string $id): ?Thread
    {
        foreach ($this->threads as $thread) {
            if ($id === $thread->id) {
                return $thread;
            }
        }

        return null;
    }

    /** @return list<Thread> the threads on one repository-relative path, in line order */
    public function threadsOn(string $path): array
    {
        $on = \array_values(\array_filter($this->threads, static fn (Thread $t): bool => $path === $t->anchor->path));
        \usort($on, static fn (Thread $a, Thread $b): int => $a->anchor->line <=> $b->anchor->line);

        return $on;
    }

    public function openCount(): int
    {
        return \count(\array_filter($this->threads, static fn (Thread $t): bool => !$t->resolved));
    }
}
