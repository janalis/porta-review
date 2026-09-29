<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Model;

/** Where a review lives — and so which of its words the view uses: pull request, merge request, review. */
enum SourceKind: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Local = 'local';

    /** "PR #12", "MR !12", or the local review's own id. */
    public function label(string $id): string
    {
        return match ($this) {
            self::GitHub => 'PR #'.$id,
            self::GitLab => 'MR !'.$id,
            self::Local => $id,
        };
    }
}
