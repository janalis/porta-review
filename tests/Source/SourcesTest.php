<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests\Source;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Process\GitProcessRunner;
use Porta\GitPlugin\Process\ProcessLane;
use Porta\ReviewPlugin\Forge\GitHubSource;
use Porta\ReviewPlugin\Forge\GitLabSource;
use Porta\ReviewPlugin\Local\LocalReviews;
use Porta\ReviewPlugin\Local\LocalSource;
use Porta\ReviewPlugin\Model\Review;
use Porta\ReviewPlugin\Source\ReviewSource;
use Porta\ReviewPlugin\Source\Sources;
use Porta\ReviewPlugin\Tests\Support\FakeForge;
use Porta\ReviewPlugin\Tests\Support\UsesFakeForge;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

/** Which source a branch's review comes from, decided from the settings, the disk and the remote. */
final class SourcesTest extends TestCase
{
    use GitBinary;
    use PreservesPath;
    use PumpsLoop;
    use UsesFakeForge;

    private TempProject $project;

    /** @var array<string, string> */
    private array $settings = ['provider' => 'auto', 'hosts' => '', 'ghBinary' => '', 'glabBinary' => ''];

    private function sources(string $remote = '', array $files = []): Sources
    {
        $this->skipWithoutGit();
        $this->project = new TempProject(['README' => "x\n", ...$files]);
        $this->project->initGit('feature/tidy');
        $this->project->commitAll('init'); // an unborn HEAD names no branch
        if ('' !== $remote) {
            $this->project->git('remote', 'add', 'origin', $remote);
        }
        $root = $this->project->dir;

        return new Sources(
            $root,
            new GitProcessRunner($root),
            new ProcessLane(),
            new LocalReviews($root),
            fn (string $key): string => $this->settings[$key] ?? '',
            static fn (): string => 'me',
            static fn (string $name): ?string => 'gh' === $name ? '/usr/local/bin/gh-on-path' : null,
        );
    }

    private function resolve(Sources $sources): ?ReviewSource
    {
        $done = false;
        $found = null;
        $sources->resolve(static function (?ReviewSource $source) use (&$done, &$found): void {
            $done = true;
            $found = $source;
        });
        $this->await(static function () use (&$done): bool {
            return $done;
        }, what: 'the source');

        return $found;
    }

    public function testAutoPrefersALocalReviewThenTheRemotesForge(): void
    {
        $local = ['.porta/reviews/r/t/thread.json' => '{"path":"a","line":1}', '.porta/reviews/r/t/1--a.md' => 'x'];

        $this->assertInstanceOf(LocalSource::class, $this->resolve($this->sources('git@github.com:acme/app.git', $local)));

        $sources = $this->sources('git@github.com:acme/app.git', $local);
        $sources->picked = '';
        $this->assertInstanceOf(GitHubSource::class, $this->resolve($sources), 'the user picked the forge over it');

        $this->assertInstanceOf(GitHubSource::class, $this->resolve($this->sources('https://github.com/acme/app.git')));
        $this->assertInstanceOf(GitLabSource::class, $this->resolve($this->sources('git@gitlab.com:g/app.git')));
        $this->assertNull($this->resolve($this->sources('git@gitlab.example.org:g/app.git')), 'a host that merely says gitlab gets no token');
        $this->assertNull($this->resolve($this->sources('git@git.corp.com:g/app.git')), 'a host nobody named');
        $this->assertNull($this->resolve($this->sources()), 'no remote at all');
    }

    public function testSelfHostedForgesAreNamedAndAProviderPinsOne(): void
    {
        $this->settings['hosts'] = ' ghe.corp.com = GitHub , =x, git.corp.com=gitlab';
        $this->assertInstanceOf(GitLabSource::class, $this->resolve($this->sources('git@git.corp.com:g/app.git')));
        $this->assertInstanceOf(GitHubSource::class, $this->resolve($this->sources('git@ghe.corp.com:g/app.git')));

        $this->settings['provider'] = 'gitlab';
        $this->assertInstanceOf(GitLabSource::class, $this->resolve($this->sources('git@github.com:g/app.git')));
        $this->settings['provider'] = 'local';
        $this->assertInstanceOf(LocalSource::class, $this->resolve($this->sources('git@github.com:g/app.git')), 'even with nothing on disk yet');
    }

    public function testTheTransportsAskTheConfiguredCliElseThePathOneAndTheRightApi(): void
    {
        $forge = new FakeForge();
        $this->settings['ghBinary'] = FakeForge::SCRIPT;
        $this->settings['glabBinary'] = FakeForge::SCRIPT;
        $empty = ['data' => ['repository' => ['pullRequests' => ['nodes' => []]]]];
        $forge->answerJson($empty)->answerJson($empty)->answerJson([]);

        foreach (['git@github.com:a/b.git', 'git@ghe.corp.com:a/b.git'] as $remote) {
            $this->settings['hosts'] = 'ghe.corp.com=github';
            $source = $this->resolve($this->sources($remote));
            \assert(null !== $source, description: 'loaded');
            $done = false;
            $source->load(static function (?Review $r) use (&$done): void {
                $done = true;
            });
            $this->await(static function () use (&$done): bool {
                return $done;
            });
        }
        $gitlab = $this->resolve($this->sources('git@gitlab.com:a/b.git'));
        \assert(null !== $gitlab, description: 'loaded');
        $done = false;
        $gitlab->load(static function () use (&$done): void {
            $done = true;
        });
        $this->await(static function () use (&$done): bool {
            return $done;
        });

        $hosts = \array_map(static fn (array $c): string => $c['argv'][5], $forge->calls());
        $this->assertSame(['github.com', 'ghe.corp.com', 'gitlab.com'], $hosts, 'each forge asked on its own host');
    }

    public function testWithNoCliTheTokenAndTheApiUrlFollowTheHost(): void
    {
        $forge = new FakeForge();
        $bin = $forge->dir->path('bin');
        \mkdir($bin);
        \symlink(FakeForge::SCRIPT, $bin.'/curl');
        \putenv('PATH='.$bin.':'.\PORTA_TEST_PRISTINE_PATH);
        \putenv('GH_TOKEN=gh-token');
        \putenv('GH_ENTERPRISE_TOKEN=ghe-token');
        \putenv('GITLAB_TOKEN=gl-token');
        $forge->answerJson([])->answerJson([])->answerJson([])->answerJson([]);
        $this->settings['hosts'] = 'ghe.corp.com=github';

        try {
            foreach (['git@github.com:a/b.git', 'git@ghe.corp.com:a/b.git', 'git@gitlab.com:a/b.git'] as $remote) {
                $sources = $this->sources($remote);
                $sources = new Sources(
                    $this->project->dir,
                    new GitProcessRunner($this->project->dir),
                    new ProcessLane(),
                    new LocalReviews($this->project->dir),
                    fn (string $key): string => $this->settings[$key] ?? '',
                    static fn (): string => 'me',
                    static fn (): ?string => null,
                );
                $source = $this->resolve($sources);
                \assert(null !== $source, description: 'loaded');
                $done = false;
                $source->load(static function () use (&$done): void {
                    $done = true;
                });
                $this->await(static function () use (&$done): bool {
                    return $done;
                });
            }
        } finally {
            \putenv('GH_TOKEN');
            \putenv('GH_ENTERPRISE_TOKEN');
            \putenv('GITLAB_TOKEN');
        }

        $configs = \array_map(static fn (array $c): string => $c['stdin'], $forge->calls());
        $this->assertStringContainsString('url = "https://api.github.com/graphql"', $configs[0]);
        $this->assertStringContainsString('Authorization: Bearer gh-token', $configs[0]);
        $this->assertStringContainsString('url = "https://ghe.corp.com/api/graphql"', $configs[1]);
        $this->assertStringContainsString('Authorization: Bearer ghe-token', $configs[1]);
        $this->assertStringContainsString('url = "https://gitlab.com/api/v4/projects/a%2Fb/merge_requests', $configs[2]);
        $this->assertStringContainsString('PRIVATE-TOKEN: gl-token', $configs[2]);
    }
}
