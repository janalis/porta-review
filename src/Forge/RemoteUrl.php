<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Forge;

/**
 * A git remote URL taken apart into the two things a forge API wants: the host
 * and the project path (`owner/repo`, or a GitLab `group/sub/repo`).
 *
 * The three spellings git accepts — `https://host/path`, `ssh://user@host:port/path`
 * and the scp-like `user@host:path` — plus the `.git` suffix any of them may
 * carry. A local path or a `file://` URL is no forge, and parses to null.
 */
final readonly class RemoteUrl
{
    private function __construct(
        public string $host,
        public string $path,
    ) {}

    public static function parse(string $url): ?self
    {
        $url = \trim($url);
        $m = [];
        if (1 === \preg_match('#^(?:https?|ssh|git)://(?:[^@/]+@)?([^/:]+)(?::\d+)?/(.+)$#i', $url, $m) || 1 === \preg_match('#^(?:[^@/]+@)?([^/:]+):(?!/)(.+)$#', $url, $m)) {
            $path = \trim(\preg_replace('#\.git$#', replacement: '', subject: \trim($m[2], characters: '/')) ?? '', characters: '/');

            return \str_contains($path, '/') ? new self(\strtolower($m[1]), $path) : null;
        }

        return null;
    }

    /** The owner — everything before the last segment. */
    public function owner(): string
    {
        return \substr($this->path, offset: 0, length: (int) \strrpos($this->path, needle: '/'));
    }

    /** The repository's own name — the last segment. */
    public function name(): string
    {
        return \substr($this->path, (int) \strrpos($this->path, needle: '/') + 1);
    }
}
