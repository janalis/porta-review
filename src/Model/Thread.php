<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Model;

/**
 * A conversation attached to a line of the review's diff.
 *
 * `outdated` is the SOURCE's word: the line it was written against has since
 * changed in the review. Where it now sits in the working copy is a separate
 * question {@see \Porta\ReviewPlugin\Editor\LineTranslator} answers.
 */
final readonly class Thread
{
    /** @param non-empty-list<Comment> $comments oldest first */
    public function __construct(
        public string $id,
        public Anchor $anchor,
        public array $comments,
        public bool $resolved = false,
        public bool $outdated = false,
    ) {}

    public function first(): Comment
    {
        return $this->comments[0];
    }

    /** "alice: rename this · 3" — the one-line form the gutter hint and the list row use. */
    public function summary(): string
    {
        $count = \count($this->comments);

        return $this->first()->author.': '.$this->first()->firstLine().($count > 1 ? ' · '.$count : '');
    }
}
