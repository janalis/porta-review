<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Model;

/** One message in a conversation: who wrote it, when, and what. */
final readonly class Comment
{
    public function __construct(
        public string $author,
        public string $body,
        /** ISO-8601, as the source reported it; '' when it did not */
        public string $createdAt = '',
    ) {}

    /**
     * When, to the minute: "2026-09-28 10:00" from a forge's ISO-8601 and from a
     * local review's file-name stamp alike; anything else as it came.
     */
    public function when(): string
    {
        $parts = [];

        return 1 === \preg_match('/^(\d{4})-?(\d{2})-?(\d{2})T(\d{2}):?(\d{2})/', $this->createdAt, $parts)
            ? \sprintf('%s-%s-%s %s:%s', $parts[1], $parts[2], $parts[3], $parts[4], $parts[5])
            : $this->createdAt;
    }

    /** The first non-blank line of the body — what a one-line summary quotes. */
    public function firstLine(): string
    {
        foreach (\explode("\n", $this->body) as $line) {
            $line = \trim($line);
            if ('' !== $line) {
                return $line;
            }
        }

        return '';
    }
}
