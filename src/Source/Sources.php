<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Source;

use Porta\GitPlugin\Process\GitProcessRunner;
use Porta\GitPlugin\Process\ProcessLane;
use Porta\ReviewPlugin\Forge\ForgeTransport;
use Porta\ReviewPlugin\Forge\GitHubSource;
use Porta\ReviewPlugin\Forge\GitLabSource;
use Porta\ReviewPlugin\Forge\RemoteUrl;
use Porta\ReviewPlugin\Forge\RepoFacts;
use Porta\ReviewPlugin\Local\LocalReviews;
use Porta\ReviewPlugin\Local\LocalSource;
use Porta\ReviewPlugin\Model\SourceKind;

/**
 * Which source the current branch's review comes from.
 *
 * `provider` pins one; `auto` prefers a local review when one is on disk — an
 * agent writing one is the thing happening right now — and otherwise asks the
 * forge the branch's remote lives on. The host decides the forge: github.com
 * and gitlab.com are known, anything else is named in `hosts`
 * (`git.corp.com=gitlab, ghe.corp.com=github`).
 */
final class Sources
{
    /**
     * The local review the user picked, or '' for the branch's forge review
     * even though a local one exists; null follows `provider`.
     */
    public ?string $picked = null;

    /**
     * @param \Closure(string): (bool|int|string) $setting this plugin's setting by key
     * @param \Closure(): string                   $author  who the user is, for a local reply
     * @param \Closure(string): ?string            $find    a binary on PATH by name, or null
     */
    public function __construct(
        private readonly string $root,
        private readonly GitProcessRunner $git,
        private readonly ProcessLane $lane,
        private readonly LocalReviews $local,
        private readonly \Closure $setting,
        private readonly \Closure $author,
        private readonly \Closure $find,
    ) {}

    /** @param \Closure(?ReviewSource): void $onDone */
    public function resolve(\Closure $onDone): void
    {
        $provider = SourceKind::tryFrom((string) ($this->setting)('provider'));
        $local = SourceKind::Local === $provider || null === $provider && '' !== $this->picked && [] !== $this->local->ids();
        if ($local) {
            $onDone(new LocalSource($this->local, $this->author, fn (): ?string => $this->picked));

            return;
        }
        RepoFacts::read($this->git, function (?RepoFacts $facts) use ($provider, $onDone): void {
            $remote = $facts?->remote;
            // A pinned provider picks the forge, never the host: the token
            // still goes only to github.com, gitlab.com or a host named in
            // `hosts` — a global pin must not follow any repository's remote.
            $forge = null === $remote ? null : $this->forgeOf($remote->host);
            $kind = null === $forge ? null : $provider ?? $forge;
            $onDone(match (true) {
                null === $facts || null === $remote => null,
                SourceKind::GitHub === $kind => new GitHubSource($facts, $this->github($remote)),
                SourceKind::GitLab === $kind => new GitLabSource($facts, $this->gitlab($remote)),
                default => null,
            });
        });
    }

    private function forgeOf(string $host): ?SourceKind
    {
        foreach (\explode(',', (string) ($this->setting)('hosts')) as $pair) {
            $parts = \explode('=', $pair, limit: 2);
            $name = \strtolower(\trim($parts[0]));
            if ('' !== $name && $name === $host) {
                return SourceKind::tryFrom(\strtolower(\trim($parts[1] ?? '')));
            }
        }

        return match (true) {
            'github.com' === $host => SourceKind::GitHub,
            // Never a substring match: the remote is the repository's to name,
            // and `gitlab.attacker.tld` would be handed the user's GITLAB_TOKEN.
            'gitlab.com' === $host => SourceKind::GitLab,
            default => null,
        };
    }

    private function github(RemoteUrl $remote): ForgeTransport
    {
        $public = 'github.com' === $remote->host;

        return new ForgeTransport(
            $this->root,
            $remote->host,
            fn (): ?string => $this->binary('ghBinary', 'gh'),
            $public ? ['GH_TOKEN', 'GITHUB_TOKEN'] : ['GH_ENTERPRISE_TOKEN', 'GITHUB_ENTERPRISE_TOKEN'],
            static fn (string $path): string => match (true) {
                $public => 'https://api.github.com/'.$path,
                'graphql' === $path => 'https://'.$remote->host.'/api/graphql',
                default => 'https://'.$remote->host.'/api/v3/'.$path,
            },
            static fn (#[\SensitiveParameter] string $token): string => 'Authorization: Bearer '.$token,
            $this->lane,
            'gh',
        );
    }

    private function gitlab(RemoteUrl $remote): ForgeTransport
    {
        return new ForgeTransport(
            $this->root,
            $remote->host,
            fn (): ?string => $this->binary('glabBinary', 'glab'),
            ['GITLAB_TOKEN'],
            static fn (string $path): string => 'https://'.$remote->host.'/api/v4/'.$path,
            static fn (#[\SensitiveParameter] string $token): string => 'PRIVATE-TOKEN: '.$token,
            $this->lane,
            'glab',
        );
    }

    /** The configured path when set, else the CLI on PATH, else null — read per call, so a Settings edit applies at once. */
    private function binary(string $setting, string $name): ?string
    {
        $configured = \trim((string) ($this->setting)($setting));

        return '' !== $configured ? $configured : ($this->find)($name);
    }
}
