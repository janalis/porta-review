<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests\Forge;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Process\GitProcessRunner;
use Porta\ReviewPlugin\Forge\RepoFacts;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

final class RepoFactsTest extends TestCase
{
    use GitBinary;
    use PumpsLoop;

    private function facts(TempProject $project): ?RepoFacts
    {
        $done = false;
        $facts = null;
        RepoFacts::read(new GitProcessRunner($project->dir), static function (?RepoFacts $f) use (&$done, &$facts): void {
            $done = true;
            $facts = $f;
        });
        $this->await(static function () use (&$done): bool {
            return $done;
        }, what: 'the facts');

        return $facts;
    }

    private function repository(): TempProject
    {
        $this->skipWithoutGit();
        $project = new TempProject(['a' => "x\n"]);
        $project->initGit('local-name');
        $project->commitAll('init');

        return $project;
    }

    public function testWithoutAnUpstreamTheBranchIsItsOwnNameOnOrigin(): void
    {
        $project = $this->repository();
        $project->git('remote', 'add', 'origin', 'git@github.com:acme/app.git');

        $facts = $this->facts($project);

        $this->assertSame(['local-name', 'acme/app'], [$facts?->branch, $facts?->remote?->path]);
    }

    public function testAnUpstreamNamesTheRemoteAndTheBranchTheReviewWasOpenedFrom(): void
    {
        $project = $this->repository();
        $bare = new TempProject();
        $bare->git('init', '-q', '--bare', '.');
        $project->git('remote', 'add', 'fork', $bare->dir);
        $project->git('push', '-q', 'fork', 'HEAD:refs/heads/feature/tidy');
        $project->git('branch', '-q', '--set-upstream-to', 'fork/feature/tidy');

        $facts = $this->facts($project);

        $this->assertSame('feature/tidy', $facts?->branch, 'the upstream\'s name, not the local one');
        $this->assertNull($facts?->remote, 'a local path is no forge');
    }

    public function testADetachedHeadHasNoBranchToAskAbout(): void
    {
        $project = $this->repository();
        $project->git('checkout', '-q', '--detach');

        $this->assertNull($this->facts($project));
    }
}
