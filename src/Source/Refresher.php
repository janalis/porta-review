<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Source;

use Porta\GitPlugin\Process\GitProcessRunner;
use Porta\ReviewPlugin\Local\LocalReviews;
use Revolt\EventLoop;

/**
 * When the review is re-read without being asked.
 *
 * - An agent wrote into `.porta/reviews/`: a fingerprint of the directory is
 *   compared every {@see LOCAL_POLL} seconds — a stat walk over a handful of
 *   files, which is what lets an agent's comment appear while the user watches.
 * - The branch changed under the IDE: checked whenever the working tree moved,
 *   because another branch has another review.
 * - Optionally every `refreshSeconds`, for a forge's comments — nothing tells
 *   the IDE that a reviewer wrote one, and a poll is the honest way to ask.
 *
 * Everything else — a save, a stage — changes where a thread's line is, not
 * what the review says, and is the editor publisher's business rather than a
 * network round-trip.
 */
final class Refresher
{
    public const float LOCAL_POLL = 2.0;

    private ?string $localTimer = null;
    private ?string $forgeTimer = null;
    private ?string $fingerprint = null;
    private ?string $branch = null;

    /** @param \Closure(): int $seconds the forge interval, 0 for never */
    public function __construct(
        private readonly ReviewStore $store,
        private readonly LocalReviews $local,
        private readonly GitProcessRunner $git,
        private readonly \Closure $seconds,
    ) {}

    public function start(): void
    {
        $this->fingerprint = $this->local->fingerprint();
        // Unreferenced: a poll is no reason for the loop to stay up.
        $this->localTimer ??= EventLoop::unreference(EventLoop::repeat(self::LOCAL_POLL, $this->pollLocal(...)));
        $this->rearm();
        $this->git->run(['rev-parse', '--abbrev-ref', 'HEAD'], function (string $out): void {
            $this->branch = \trim($out);
        });
    }

    /** Re-read the forge interval — a Settings edit applies at once. */
    public function rearm(): void
    {
        if (null !== $this->forgeTimer) {
            EventLoop::cancel($this->forgeTimer);
            $this->forgeTimer = null;
        }
        $seconds = ($this->seconds)();
        if ($seconds > 0) {
            $this->forgeTimer = EventLoop::unreference(EventLoop::repeat((float) $seconds, $this->store->refresh(...)));
        }
    }

    public function stop(): void
    {
        foreach (\array_filter([$this->localTimer, $this->forgeTimer]) as $timer) {
            EventLoop::cancel($timer);
        }
        $this->localTimer = null;
        $this->forgeTimer = null;
    }

    /** The working tree moved: if HEAD is on another branch now, that branch's review is the one to show. */
    public function branchMayHaveMoved(): void
    {
        $this->git->run(['rev-parse', '--abbrev-ref', 'HEAD'], function (string $out): void {
            $branch = \trim($out);
            if ($branch !== $this->branch) {
                $this->branch = $branch;
                $this->store->refresh();
            }
        });
    }

    private function pollLocal(): void
    {
        $fingerprint = $this->local->fingerprint();
        if ($fingerprint !== $this->fingerprint) {
            $this->fingerprint = $fingerprint;
            $this->store->refresh();
        }
    }
}
