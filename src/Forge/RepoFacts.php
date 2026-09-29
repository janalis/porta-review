<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Forge;

use Porta\GitPlugin\Process\GitProcessRunner;

/**
 * What finding the branch's review takes from git: the branch the review was
 * opened FROM — the upstream's name when there is one, since a local branch may
 * track a differently named remote one — and the remote that upstream lives on.
 */
final readonly class RepoFacts
{
    public function __construct(
        public string $branch,
        public ?RemoteUrl $remote,
    ) {}

    /** @param \Closure(?self): void $onDone null on a detached HEAD or outside a repository */
    public static function read(GitProcessRunner $git, \Closure $onDone): void
    {
        $git->run(['rev-parse', '--abbrev-ref', 'HEAD'], static function (string $head) use ($git, $onDone): void {
            $branch = \trim($head);
            if ('' === $branch || 'HEAD' === $branch) {
                $onDone(null);

                return;
            }
            $git->run(['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{upstream}'], static function (string $upstream) use ($git, $branch, $onDone): void {
                $upstream = \trim($upstream);
                $slash = \strpos($upstream, needle: '/');
                $remote = false === $slash ? 'origin' : \substr($upstream, offset: 0, length: $slash);
                $tracked = false === $slash ? $branch : \substr($upstream, $slash + 1);
                $git->run(['remote', 'get-url', $remote], static function (string $url) use ($tracked, $onDone): void {
                    $onDone(new self($tracked, RemoteUrl::parse($url)));
                });
            });
        });
    }
}
