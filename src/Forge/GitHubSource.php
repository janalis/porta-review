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
 * A GitHub pull request as a {@see ReviewSource}.
 *
 * GraphQL for reading, because only it says whether a thread is resolved, and
 * for replying and resolving, which only it can do on a thread. A new line
 * comment goes through REST: `pulls/{n}/comments` publishes at once, where the
 * GraphQL mutation leaves a pending review behind.
 */
final readonly class GitHubSource implements ReviewSource
{
    private const string AUTHOR = 'author { login }';
    private const string COMMENT = 'id author { login } body createdAt';

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
        $query =
            'query($owner: String!, $name: String!, $branch: String!) { repository(owner: $owner, name: $name) {'
            .' pullRequests(headRefName: $branch, states: [OPEN], first: 1, orderBy: {field: UPDATED_AT, direction: DESC}) { nodes {'
            .' id number title url baseRefOid headRefOid '
            .self::AUTHOR
            .' comments(first: 100) { nodes { '
            .self::COMMENT
            .' } }'
            .' reviewThreads(first: 100) { nodes { id isResolved isOutdated path line originalLine diffSide comments(first: 100) { nodes { '
            .self::COMMENT
            .' } } } }'
            .' } } } }';
        $this->graphql($query, ['owner' => $remote->owner(), 'name' => $remote->name(), 'branch' => $this->facts->branch], static function (?Json $data, ?string $error) use ($onDone): void {
            $pr = $data?->at('repository', 'pullRequests', 'nodes', 0);
            $onDone(null !== $pr && $pr->isArray() ? self::review($pr) : null, $error);
        });
    }

    #[\Override]
    public function reply(Review $review, Thread $thread, string $body, \Closure $onDone): void
    {
        $this->mutate('addPullRequestReviewThreadReply(input: {pullRequestReviewThreadId: $id, body: $body}) { comment { id } }', ['id' => $thread->id, 'body' => $body], $onDone);
    }

    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the request: resolved or not.
    #[\Override]
    public function setResolved(Review $review, Thread $thread, bool $resolved, \Closure $onDone): void
    {
        $verb = $resolved ? 'resolveReviewThread' : 'unresolveReviewThread';
        $this->mutate($verb.'(input: {threadId: $id}) { thread { id } }', ['id' => $thread->id], $onDone);
    }

    #[\Override]
    public function comment(Review $review, Anchor $anchor, string $body, \Closure $onDone): void
    {
        $remote = $this->facts->remote;
        \assert(null !== $remote, description: 'a review was loaded, so the remote parsed');
        $this->transport->request(
            'POST',
            \sprintf('repos/%s/pulls/%s/comments', $remote->path, $review->id),
            [
                'body' => $body,
                'commit_id' => (string) $review->headSha,
                'path' => $anchor->path,
                'line' => $anchor->line,
                'side' => Side::Old === $anchor->side ? 'LEFT' : 'RIGHT',
            ],
            static fn (?Json $answer, ?string $error) => $onDone($error),
        );
    }

    #[\Override]
    public function converse(Review $review, string $body, \Closure $onDone): void
    {
        $this->mutate('addComment(input: {subjectId: $id, body: $body}) { commentEdge { node { id } } }', ['id' => $review->handle, 'body' => $body], $onDone);
    }

    /**
     * @param array<string, string>   $variables
     * @param \Closure(?string): void $onDone
     */
    private function mutate(string $field, array $variables, \Closure $onDone): void
    {
        $declared = ['$id: ID!'];
        if (\array_key_exists('body', $variables)) {
            $declared[] = '$body: String!';
        }
        $this->graphql('mutation('.\implode(', ', $declared).') { '.$field.' }', $variables, static fn (?Json $data, ?string $error) => $onDone($error));
    }

    /**
     * @param array<string, string>          $variables
     * @param \Closure(?Json, ?string): void $onDone the `data` object
     */
    private function graphql(string $query, array $variables, \Closure $onDone): void
    {
        $this->transport->request('POST', 'graphql', ['query' => $query, 'variables' => $variables], static function (?Json $answer, ?string $error) use ($onDone): void {
            $onDone($answer?->at('data'), $error);
        });
    }

    private static function review(Json $pr): Review
    {
        $threads = [];
        foreach ($pr->at('reviewThreads', 'nodes')->list() as $node) {
            $comments = self::comments($node->at('comments', 'nodes'));
            $current = $node->at('line')->int();
            $line = $current ?? $node->at('originalLine')->int();
            $path = $node->at('path')->string();
            if ([] === $comments || null === $path || null === $line) {
                continue;
            }
            $threads[] = new Thread(
                (string) $node->at('id')->string(),
                new Anchor($path, $line, 'LEFT' === $node->at('diffSide')->string() ? Side::Old : Side::New),
                $comments,
                $node->at('isResolved')->isTrue(),
                // An outdated thread has no line in the current diff: GitHub answers `line: null`.
                $node->at('isOutdated')->isTrue() || null === $current,
            );
        }

        return new Review(
            SourceKind::GitHub,
            (string) $pr->at('number')->int(),
            (string) $pr->at('title')->string(),
            $threads,
            self::comments($pr->at('comments', 'nodes')),
            baseSha: $pr->at('baseRefOid')->string(),
            headSha: $pr->at('headRefOid')->string(),
            url: $pr->at('url')->string(),
            handle: (string) $pr->at('id')->string(),
        );
    }

    /** @return list<Comment> */
    private static function comments(Json $nodes): array
    {
        // A deleted account answers `author: null`; GitHub itself shows "ghost".
        return \array_map(
            static fn (Json $node): Comment => new Comment($node->at('author', 'login')->string() ?? 'ghost', (string) $node->at('body')->string(), (string) $node->at('createdAt')->string()),
            $nodes->list(),
        );
    }
}
