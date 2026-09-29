<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Model;

/**
 * Where a line comment points: a path relative to the repository root, a
 * 1-based line on one side of the diff, and — when the source knows it — the
 * text that line had, which is how a local review finds the line again after
 * edits above it.
 */
final readonly class Anchor
{
    public function __construct(
        public string $path,
        public int $line,
        public Side $side = Side::New,
        public ?string $lineText = null,
    ) {}
}
