<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Model;

/**
 * Which side of the diff a line comment is on: the file as the review's base
 * had it, or as its head has it. GitHub spells these LEFT / RIGHT, GitLab by
 * which of `old_line` / `new_line` it fills.
 */
enum Side: string
{
    case Old = 'old';
    case New = 'new';
}
