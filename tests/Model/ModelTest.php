<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests\Model;

use PHPUnit\Framework\TestCase;
use Porta\ReviewPlugin\Editor\EditorDisplay;
use Porta\ReviewPlugin\Json;
use Porta\ReviewPlugin\Model\Anchor;
use Porta\ReviewPlugin\Model\Comment;
use Porta\ReviewPlugin\Model\ProjectPath;
use Porta\ReviewPlugin\Model\Review;
use Porta\ReviewPlugin\Model\SourceKind;
use Porta\ReviewPlugin\Model\Thread;
use Porta\Tests\Support\TempProject;

final class ModelTest extends TestCase
{
    public function testAReviewIsLabelledInItsSourcesOwnWords(): void
    {
        $this->assertSame(['PR #12', 'MR !7', 'agent'], [SourceKind::GitHub->label('12'), SourceKind::GitLab->label('7'), SourceKind::Local->label('agent')]);
    }

    public function testAReviewFindsItsThreadsAndCountsTheOpenOnes(): void
    {
        // @mago-expect lint:no-boolean-flag-parameter -- a test's builder: the flag is the fixture
        $thread = static fn (string $id, string $path, int $line, bool $resolved = false): Thread => new Thread($id, new Anchor($path, $line), [new Comment('a', 'x')], $resolved);
        $review = new Review(SourceKind::Local, 'r', 't', [
            $thread(id: 'b', path: 'b.php', line: 9),
            $thread(id: 'a', path: 'a.php', line: 3, resolved: true),
            $thread(id: 'c', path: 'b.php', line: 2),
        ]);

        $this->assertSame('c', $review->thread('c')?->id);
        $this->assertNull($review->thread('z'));
        $this->assertSame(['c', 'b'], \array_map(static fn (Thread $t): string => $t->id, $review->threadsOn('b.php')), 'in line order');
        $this->assertSame(2, $review->openCount());
    }

    public function testASummaryQuotesTheFirstLineAndCountsTheReplies(): void
    {
        $one = new Thread('t', new Anchor('a', 1), [new Comment('alice', "\n  \n  Rename this.\nBecause.")]);
        $two = new Thread('t', new Anchor('a', 1), [new Comment('alice', 'Rename.'), new Comment('bob', 'Done.')]);

        $this->assertSame('alice: Rename this.', $one->summary());
        $this->assertSame('alice: Rename. · 2', $two->summary());
        $this->assertSame('', new Comment('a', "  \n ")->firstLine());
    }

    public function testWhenReadsBothStampsToTheMinute(): void
    {
        $this->assertSame('2026-09-28 10:05', new Comment('a', 'x', '2026-09-28T10:05:44.409Z')->when(), 'a forge\'s ISO-8601');
        $this->assertSame('2026-09-29 15:44', new Comment('a', 'x', '20260929T154453.517626Z')->when(), 'a local file-name stamp');
        $this->assertSame('yesterday', new Comment('a', 'x', 'yesterday')->when(), 'anything else as it came');
    }

    public function testAPathIsRelativeToEitherSpellingOfTheRoot(): void
    {
        $project = new TempProject(['src/A.php' => '']);
        $real = (string) \realpath($project->dir);

        $this->assertSame('src/A.php', ProjectPath::relative($project->dir, $project->dir.'/src/A.php'));
        $this->assertSame('src/A.php', ProjectPath::relative($project->dir.'/', $real.'/src/A.php'), 'the editor may have resolved a symlink the root has');
        $this->assertNull(ProjectPath::relative($project->dir, '/elsewhere/A.php'));
        $this->assertNull(ProjectPath::relative('/does/not/exist', '/elsewhere/A.php'));
    }

    public function testTheDisplaySettingReadsBackAndDefaultsToTheGutter(): void
    {
        $this->assertSame(EditorDisplay::Inline, EditorDisplay::fromSetting('inline'));
        $this->assertSame(EditorDisplay::Gutter, EditorDisplay::fromSetting('sideways'));
        $this->assertSame(['off', 'gutter', 'summary', 'inline'], EditorDisplay::values());
    }

    public function testJsonReadsByPathAndNarrowsEveryRead(): void
    {
        $json = Json::decode('{"a":{"b":[1,"two",true,null]},"n":3}');

        $this->assertSame(1, $json->at('a', 'b', 0)->int());
        $this->assertNull($json->at('a', 'b', 1)->int(), 'a string is not an int');
        $this->assertSame('two', $json->at('a', 'b', 1)->string());
        $this->assertNull($json->at('n')->string(), 'nor a number a string');
        $this->assertTrue($json->at('a', 'b', 2)->isTrue());
        $this->assertFalse($json->at('a', 'b', 0)->isTrue(), 'only true is true');
        $this->assertTrue($json->at('a', 'b', 3)->isNull());
        $this->assertTrue($json->at('missing', 'deeper')->isNull());
        $this->assertTrue($json->at('a')->isArray());
        $this->assertSame(['b' => [1, 'two', true, null]], $json->at('a')->array());
        $this->assertNull($json->at('n')->array());
        $this->assertCount(4, $json->at('a', 'b')->list());
        $this->assertSame([], $json->at('n')->list());
        $this->assertTrue(Json::decode('  ')->isNull(), 'no text, no value');
    }
}
