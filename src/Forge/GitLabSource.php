<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Forge;

use Porta\ReviewPlugin\Json;
use Porta\ReviewPlugin\Model\Anchor;
use Porta\ReviewPlugin\Model\Comment;
use Porta\ReviewPlugin\Model\Review;
use Porta\ReviewPlugin\Model\Side;
use Porta\ReviewPlugin\Model\SourceKind;
use Porta\ReviewPlugin\Model\Thread;
use Porta\ReviewPlugin\Source\ReviewSource;

/**
 * A GitLab merge request as a {@see ReviewSource}, over the REST API.
 *
 * GitLab's unit is the DISCUSSION: a diff note's discussion is a line thread,
 * an individual note is one message of the review-level conversation, and a
 * threaded discussion that is not on a line joins the conversation as its
 * messages. System notes ("added 1 commit") are not conversation and are left
 * out.
 */
final readonly class GitLabSource implements ReviewSource
{
    public function __construct(
        private RepoFacts $facts,
        private ForgeTransport $transport,
    ) {}

    #[\Override]
    public function load(\Closure $onDone): void
    {
        $remote = $this->facts->remote;
        if (null === $remote) {
            $none = null;
            $onDone($none, $none); // no remote a forge could answer for

            return;
        }
        $project = 'projects/'.\rawurlencode($remote->path);
        $this->transport->request('GET', $project.'/merge_requests?state=opened&per_page=1&source_branch='.\rawurlencode($this->facts->branch), null, function (?Json $list, ?string $error) use (
            $project,
            $onDone,
        ): void {
            $iid = $list?->at(0, 'iid')->int();
            if (null === $iid) {
                $onDone(null, $error);

                return;
            }
            // The list leaves `diff_refs` out, and a new diff note has to quote them back.
            $this->transport->request('GET', $project.'/merge_requests/'.$iid, null, function (?Json $mr, ?string $error) use ($project, $iid, $onDone): void {
                if (null === $mr) {
                    $onDone(null, $error);

                    return;
                }
                $this->transport->request('GET', $project.'/merge_requests/'.$iid.'/discussions?per_page=100', null, static function (?Json $discussions, ?string $error) use ($mr, $onDone): void {
                    $onDone(null === $discussions ? null : self::review($mr, $discussions), $error);
                });
            });
        });
    }

    #[\Override]
    public function reply(Review $review, Thread $thread, string $body, \Closure $onDone): void
    {
        $this->send('POST', $this->mr($review).'/discussions/'.$thread->id.'/notes', ['body' => $body], $onDone);
    }

    #[\Override]
    public function setResolved(Review $review, Thread $thread, bool $resolved, \Closure $onDone): void
    {
        $this->send('PUT', $this->mr($review).'/discussions/'.$thread->id, ['resolved' => $resolved], $onDone);
    }

    #[\Override]
    public function comment(Review $review, Anchor $anchor, string $body, \Closure $onDone): void
    {
        $position = [
            'position_type' => 'text',
            'base_sha' => (string) $review->baseSha,
            'start_sha' => (string) $review->startSha,
            'head_sha' => (string) $review->headSha,
            'old_path' => $anchor->path,
            'new_path' => $anchor->path,
            Side::Old === $anchor->side ? 'old_line' : 'new_line' => $anchor->line,
        ];
        $this->send('POST', $this->mr($review).'/discussions', ['body' => $body, 'position' => $position], $onDone);
    }

    #[\Override]
    public function converse(Review $review, string $body, \Closure $onDone): void
    {
        $this->send('POST', $this->mr($review).'/notes', ['body' => $body], $onDone);
    }

    private function mr(Review $review): string
    {
        $remote = $this->facts->remote;
        \assert(null !== $remote, description: 'a review was loaded, so the remote parsed');

        return 'projects/'.\rawurlencode($remote->path).'/merge_requests/'.$review->id;
    }

    /**
     * @param array<string, mixed>    $body
     * @param \Closure(?string): void $onDone
     */
    private function send(string $method, string $path, array $body, \Closure $onDone): void
    {
        $this->transport->request($method, $path, $body, static fn (?Json $answer, ?string $error) => $onDone($error));
    }

    private static function review(Json $mr, Json $discussions): Review
    {
        $head = $mr->at('diff_refs', 'head_sha')->string();
        $threads = [];
        $conversation = [];
        foreach ($discussions->list() as $discussion) {
            $notes = \array_values(\array_filter($discussion->at('notes')->list(), static fn (Json $n): bool => $n->isArray() && !$n->at('system')->isTrue()));
            if ([] === $notes) {
                continue;
            }
            $comments = \array_map(self::note(...), $notes);
            $position = $notes[0]->at('position');
            $anchor = self::anchor($position);
            if (null === $anchor) {
                \array_push($conversation, ...$comments);

                continue;
            }
            $threads[] = new Thread(
                (string) $discussion->at('id')->string(),
                $anchor,
                $comments,
                $notes[0]->at('resolved')->isTrue(),
                // Written against an earlier head: the line it names is the old diff's.
                null !== $head && ($position->at('head_sha')->string() ?? $head) !== $head,
            );
        }
        \usort($conversation, static fn (Comment $a, Comment $b): int => $a->createdAt <=> $b->createdAt);

        return new Review(
            SourceKind::GitLab,
            (string) $mr->at('iid')->int(),
            (string) $mr->at('title')->string(),
            $threads,
            $conversation,
            baseSha: $mr->at('diff_refs', 'base_sha')->string(),
            headSha: $head,
            url: $mr->at('web_url')->string(),
            startSha: $mr->at('diff_refs', 'start_sha')->string(),
        );
    }

    private static function anchor(Json $position): ?Anchor
    {
        $new = $position->at('new_line')->int();
        $line = $new ?? $position->at('old_line')->int();
        $path = null !== $new ? $position->at('new_path')->string() : $position->at('old_path')->string();

        return null !== $path && null !== $line ? new Anchor($path, $line, null !== $new ? Side::New : Side::Old) : null;
    }

    private static function note(Json $note): Comment
    {
        return new Comment($note->at('author', 'username')->string() ?? '?', (string) $note->at('body')->string(), (string) $note->at('created_at')->string());
    }
}
