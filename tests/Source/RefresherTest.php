<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests\Source;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Process\GitProcessRunner;
use Porta\ReviewPlugin\Local\LocalReviews;
use Porta\ReviewPlugin\Source\Refresher;
use Porta\ReviewPlugin\Source\ReviewStore;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

/** What re-reads the review unasked: an agent writing, a branch switch, and an optional poll. */
final class RefresherTest extends TestCase
{
    use GitBinary;
    use PumpsLoop;

    private int $refreshes = 0;

    private int $seconds = 0;

    private TempProject $project;

    private function refresher(): Refresher
    {
        $this->skipWithoutGit();
        $this->project = new TempProject(['a' => "x\n"]);
        $this->project->initGit('main');
        $this->project->commitAll('init');
        $store = new ReviewStore(function (\Closure $onDone): void {
            ++$this->refreshes;
            $onDone(null);
        });

        return new Refresher($store, new LocalReviews($this->project->dir), new GitProcessRunner($this->project->dir), fn (): int => $this->seconds);
    }

    public function testAnAgentsWriteIsNoticedByThePoll(): void
    {
        $refresher = $this->refresher();
        $refresher->start();

        // The two negatives call the poll's own handler rather than pumping a
        // whole 2s interval each: what they prove is the fingerprint, not the
        // timer, and the pumps made this a 6.8s test against a 10s limit. The
        // await in between is the real timer firing.
        try {
            Probe::invoke($refresher, 'pollLocal');
            $this->assertSame(0, $this->refreshes, 'nothing changed');

            $this->project->write('.porta/reviews/r/t/1--a.md', 'x');
            $this->await(fn (): bool => 1 === $this->refreshes, 5.0, 'the poll to notice');
            Probe::invoke($refresher, 'pollLocal');
            $this->assertSame(1, $this->refreshes, 'once per change');
        } finally {
            $refresher->stop();
            $refresher->stop();
        }
    }

    public function testTheForgePollFollowsTheSetting(): void
    {
        $refresher = $this->refresher();
        $this->seconds = 1;
        $refresher->start();

        try {
            $this->await(fn (): bool => $this->refreshes >= 1, 3.0, 'the forge poll');
            $this->seconds = 0;
            $refresher->rearm();
            $seen = $this->refreshes;
            $this->pump(1.3);
            $this->assertSame($seen, $this->refreshes, '0 turns it off');
        } finally {
            $refresher->stop();
        }
    }

    public function testOnlyAnotherBranchReloads(): void
    {
        $refresher = $this->refresher();
        $refresher->start();

        try {
            $this->pump(0.3); // the starting branch is read
            $refresher->branchMayHaveMoved();
            $this->pump(0.3);
            $this->assertSame(0, $this->refreshes, 'the same branch');

            $this->project->git('checkout', '-q', '-b', 'other');
            $refresher->branchMayHaveMoved();
            $this->await(fn (): bool => 1 === $this->refreshes, 5.0, 'the branch switch');
        } finally {
            $refresher->stop();
        }
    }
}
