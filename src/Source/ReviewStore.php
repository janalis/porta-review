<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Source;

use Porta\ReviewPlugin\Model\Review;

/**
 * The current branch's review as last loaded, and the one way to change it.
 *
 * Every write goes to the source and is followed by a reload, never patched
 * in locally: the forge decides what a reply looks like (its id, its author,
 * whether a resolve took), and a guess that disagreed would sit on screen until
 * the next refresh contradicted it.
 *
 * A reload that is overtaken — the branch switched, the user pressed refresh
 * twice — is dropped when it answers, by generation, so a slow first answer
 * cannot overwrite a fast second one.
 */
final class ReviewStore
{
    public private(set) ?Review $review = null;

    /** Why the last load failed, for the view to say; null after a success. */
    public private(set) ?string $error = null;

    public private(set) bool $loading = false;

    private ?ReviewSource $source = null;

    private int $generation = 0;

    /** @var list<\Closure(): void> */
    private array $listeners = [];

    /** @param \Closure(\Closure(?ReviewSource): void): void $resolve finds the source for the branch as it is now */
    public function __construct(
        private readonly \Closure $resolve,
    ) {}

    /** @param \Closure(): void $listener called after every change, loading state included */
    public function onChange(\Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function refresh(): void
    {
        $generation = ++$this->generation;
        $this->loading = true;
        $this->changed();
        ($this->resolve)(function (?ReviewSource $source) use ($generation): void {
            if ($generation !== $this->generation) {
                return;
            }
            if (null === $source) {
                $this->settle(null, null, null);

                return;
            }
            $source->load(function (?Review $review, ?string $error) use ($generation, $source): void {
                if ($generation === $this->generation) {
                    $this->settle($source, $review, $error);
                }
            });
        });
    }

    /**
     * Run a write against the loaded review, then reload.
     *
     * @param \Closure(ReviewSource, Review, \Closure(?string): void): void $verb
     * @param \Closure(?string): void                                        $onDone null on success, else why not
     */
    public function act(\Closure $verb, \Closure $onDone): void
    {
        if (null === $this->source || null === $this->review) {
            $onDone('No review is loaded');

            return;
        }
        $verb($this->source, $this->review, function (?string $error) use ($onDone): void {
            $onDone($error);
            if (null === $error) {
                $this->refresh();
            }
        });
    }

    private function settle(?ReviewSource $source, ?Review $review, ?string $error): void
    {
        $this->source = $source;
        $this->review = $review;
        $this->error = $error;
        $this->loading = false;
        $this->changed();
    }

    private function changed(): void
    {
        foreach ($this->listeners as $listener) {
            $listener();
        }
    }
}
