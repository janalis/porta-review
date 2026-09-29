<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests\Forge;

use PHPUnit\Framework\TestCase;
use Porta\ReviewPlugin\Forge\GitLabSource;
use Porta\ReviewPlugin\Forge\RemoteUrl;
use Porta\ReviewPlugin\Forge\RepoFacts;
use Porta\ReviewPlugin\Model\Anchor;
use Porta\ReviewPlugin\Model\Comment;
use Porta\ReviewPlugin\Model\Review;
use Porta\ReviewPlugin\Model\Side;
use Porta\ReviewPlugin\Model\SourceKind;
use Porta\ReviewPlugin\Tests\Support\FakeForge;
use Porta\ReviewPlugin\Tests\Support\UsesFakeForge;
use Porta\Tests\Support\PumpsLoop;

/**
 * A merge request read from `glab api` — three requests, since the list leaves
 * out the diff refs a new note must quote — and answered through it. The
 * fixtures are a real merge request and its 31 discussions from GitLab's API,
 * with the people and their words replaced: system notes, individual notes and
 * two diff-note threads, one written against an earlier head.
 */
final class GitLabSourceTest extends TestCase
{
    use PumpsLoop;
    use UsesFakeForge;

    private FakeForge $forge;

    private function source(?string $remote = 'git@gitlab.com:acme/tools/app.git'): GitLabSource
    {
        $this->forge = new FakeForge();

        return new GitLabSource(new RepoFacts('feature/x y', null === $remote ? null : RemoteUrl::parse($remote)), $this->forge->transport('gitlab.com'));
    }

    /** @return array{0: ?Review, 1: ?string} */
    private function load(GitLabSource $source): array
    {
        $answer = null;
        $source->load(static function (?Review $r, ?string $error) use (&$answer): void {
            $answer = [$r, $error];
        });
        $this->await(static function () use (&$answer): bool {
            return null !== $answer;
        }, what: 'the load');
        \assert(\is_array($answer), description: 'answered');

        return $answer;
    }

    /** @param \Closure(\Closure(?string): void): void $verb */
    private function write(\Closure $verb): ?string
    {
        $done = false;
        $error = null;
        $verb(static function (?string $e) use (&$done, &$error): void {
            $done = true;
            $error = $e;
        });
        $this->await(static function () use (&$done): bool {
            return $done;
        }, what: 'the write');

        return $error;
    }

    private function loaded(GitLabSource $source): Review
    {
        $this->forge
            ->answerJson([['iid' => 7]])
            ->answerFixture('gitlab-merge-request.json')
            ->answerFixture('gitlab-discussions.json');
        [$review] = $this->load($source);
        \assert(null !== $review, description: 'loaded');

        return $review;
    }

    public function testTheMergeRequestOfTheBranchIsReadWhole(): void
    {
        $source = $this->source();
        $review = $this->loaded($source);

        $this->assertSame([SourceKind::GitLab, '7', 'Tidy the dumper', 'MR !7'], [$review->kind, $review->id, $review->title, $review->label()]);
        $this->assertSame('https://gitlab.com/acme/app/-/merge_requests/7', $review->url);
        $this->assertNotNull($review->startSha, 'the start sha a new diff note quotes back');
        $this->assertCount(2, $review->threads, 'the two diff notes; every other discussion is conversation or system');
        $this->assertCount(6, $review->conversation, 'the notes a person wrote, system notes left out');
        $sorted = \array_map(static fn (Comment $c): string => $c->createdAt, $review->conversation);
        $copy = $sorted;
        \sort($copy);
        $this->assertSame($copy, $sorted, 'oldest first');

        [$first] = $review->threads;
        $this->assertSame([Side::New, 'src/Dumper.php', 320, true], [$first->anchor->side, $first->anchor->path, $first->anchor->line, $first->resolved]);
        $this->assertFalse($first->outdated, 'written against the head the merge request still has');

        $argv = \array_map(static fn (array $c): string => $c['argv'][3], $this->forge->calls());
        $this->assertSame(
            [
                'projects/acme%2Ftools%2Fapp/merge_requests?state=opened&per_page=1&source_branch=feature%2Fx%20y',
                'projects/acme%2Ftools%2Fapp/merge_requests/7',
                'projects/acme%2Ftools%2Fapp/merge_requests/7/discussions?per_page=100',
            ],
            $argv,
        );
    }

    public function testEachStepCanComeBackEmptyOrFailed(): void
    {
        $source = $this->source();
        $this->forge->answerJson([]);
        $this->assertSame([null, null], $this->load($source), 'no open merge request for the branch');

        $source = $this->source();
        $this->forge->answerJson([['iid' => 7]])->answer('{"message":"403 Forbidden"}', 1);
        $this->assertSame([null, '403 Forbidden'], $this->load($source));

        $source = $this->source();
        $this->forge
            ->answerJson([['iid' => 7]])
            ->answerFixture('gitlab-merge-request.json')
            ->answer('{"error":"timeout"}', 1);
        $this->assertSame([null, 'timeout'], $this->load($source));

        $this->assertSame([null, null], $this->load($this->source(null)), 'no remote: nothing asked');
    }

    public function testAnOldSideNoteAndAThreadedDiscussionOffTheDiff(): void
    {
        $source = $this->source();
        $this->forge
            ->answerJson([['iid' => 7]])
            ->answerFixture('gitlab-merge-request.json')
            ->answerJson([
                [
                    'id' => 'd1',
                    'notes' => [[
                        'type' => 'DiffNote',
                        'author' => ['username' => 'bob'],
                        'body' => 'Gone?',
                        'created_at' => '1',
                        'position' => ['old_path' => 'a.php', 'new_path' => 'a.php', 'old_line' => 4, 'new_line' => null, 'head_sha' => '93f955032715667fdad584fdb8ea7b5d57692c08'],
                    ]],
                ],
                [
                    'id' => 'd2',
                    'notes' => [
                        ['type' => 'DiscussionNote', 'author' => ['username' => 'carol'], 'body' => 'Overall fine', 'created_at' => '2'],
                        ['type' => 'DiscussionNote', 'body' => 'Thanks', 'created_at' => '3'],
                    ],
                ],
                'not a discussion',
            ]);

        [$review] = $this->load($source);

        \assert(null !== $review, description: 'loaded');
        $this->assertSame([Side::Old, 4, 'a.php'], [$review->threads[0]->anchor->side, $review->threads[0]->anchor->line, $review->threads[0]->anchor->path]);
        $this->assertTrue($review->threads[0]->outdated, 'written against a head the merge request has moved past');
        $this->assertSame(['carol', '?'], \array_map(static fn (Comment $c): string => $c->author, $review->conversation), 'a threaded discussion off the diff joins the conversation');
    }

    public function testEachWriteIsTheRequestItNeeds(): void
    {
        $source = $this->source();
        $review = $this->loaded($source);
        $thread = $review->threads[1];
        foreach (\range(start: 1, end: 5) as $ignored) {
            $this->forge->answerJson([]);
        }
        $mr = 'projects/acme%2Ftools%2Fapp/merge_requests/7';

        $this->assertNull($this->write(static fn (\Closure $done) => $source->reply($review, $thread, 'Fixed.', $done)));
        $this->assertSame(['POST', $mr.'/discussions/'.$thread->id.'/notes'], \array_slice($this->forge->calls()[3]['argv'], offset: 2, length: 2));
        $this->assertSame(['body' => 'Fixed.'], $this->forge->body(3));

        $this->write(static fn (\Closure $done) => $source->setResolved($review, $thread, true, $done));
        $this->assertSame(['PUT', $mr.'/discussions/'.$thread->id], \array_slice($this->forge->calls()[4]['argv'], offset: 2, length: 2));
        $this->assertSame(['resolved' => true], $this->forge->body(4));

        $this->write(static fn (\Closure $done) => $source->converse($review, 'Ready?', $done));
        $this->assertSame(['POST', $mr.'/notes'], \array_slice($this->forge->calls()[5]['argv'], offset: 2, length: 2));

        $this->write(static fn (\Closure $done) => $source->comment($review, new Anchor('src/A.php', 9), 'Why?', $done));
        $this->assertSame(['POST', $mr.'/discussions'], \array_slice($this->forge->calls()[6]['argv'], offset: 2, length: 2));
        $position = $this->forge->body(6)['position'] ?? null;
        $this->assertIsArray($position);
        $this->assertSame(['text', 'src/A.php', 'src/A.php', 9], [$position['position_type'], $position['old_path'], $position['new_path'], $position['new_line']]);
        $this->assertSame([$review->baseSha, $review->startSha, $review->headSha], [$position['base_sha'], $position['start_sha'], $position['head_sha']]);

        $this->write(static fn (\Closure $done) => $source->comment($review, new Anchor('src/A.php', 2, Side::Old), 'Why?', $done));
        $this->assertSame(2, $this->forge->body(7)['position']['old_line'] ?? null, 'the old side is `old_line`');
    }
}
