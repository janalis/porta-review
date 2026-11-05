<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Model;

use Porta\Support\Files;

/**
 * A file's path relative to the project root — what a review names files by.
 *
 * The root may be spelled through a symlink the editor's paths have resolved
 * (macOS's `/var` is `/private/var`), so both spellings of it are tried.
 */
final readonly class ProjectPath
{
    public static function relative(string $root, string $path): ?string
    {
        foreach (\array_unique([$root, Files::canonical($root)]) as $candidate) {
            $prefix = \rtrim($candidate, characters: '/').'/';
            if (\str_starts_with($path, $prefix)) {
                return \substr($path, \strlen($prefix));
            }
        }

        return null;
    }
}
