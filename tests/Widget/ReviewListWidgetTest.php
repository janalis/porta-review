<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests\Widget;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\ReviewPlugin\Model\Anchor;
use Porta\ReviewPlugin\Model\Comment;
use Porta\ReviewPlugin\Model\Review;
use Porta\ReviewPlugin\Model\SourceKind;
use Porta\ReviewPlugin\Model\Thread;
use Porta\ReviewPlugin\Widget\ReplyBoxWidget;
use Porta\ReviewPlugin\Widget\ReviewListWidget;
use Porta\Tests\Support\RendersWidget;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Render\RenderContext;

final class ReviewListWidgetTest extends TestCase
{
    use RendersWidget;

    private const string DOWN = "\x1b[B";
    private const string UP = "\x1b[A";

    /** @var list<string> */
    private array $acted = [];

    private function list(): ReviewListWidget
    {
        $list = new ReviewListWidget(new Translator('en'), 40, Styles::dark());
        $list->onSelect(function (string $id): void {
            $this->acted[] = 'select '.$id;
        });
        foreach (['reply', 'resolve', 'open', 'comment', 'refresh'] as $verb) {
            $list->bind($verb, function (string $id) use ($verb): void {
                $this->acted[] = $verb.' '.$id;
            });
        }

        return $list;
    }

    private static function review(): Review
    {
        // @mago-expect lint:no-boolean-flag-parameter -- a test's builder: the flag is the fixture
        $thread = static fn (string $id, string $path, int $line, bool $resolved = false): Thread => new Thread($id, new Anchor($path, $line), [new Comment('alice', 'Rename <this>.')], $resolved);

        return new Review(
            SourceKind::GitHub,
            '12',
            'Tidy',
            [$thread(id: 'b1', path: 'b.php', line: 3), $thread(id: 'a1', path: 'a.php', line: 9, resolved: true), $thread(id: 'a2', path: 'a.php', line: 2), $thread(id: '9', path: '9', line: 1)],
            [new Comment('carol', 'hi')],
        );
    }

    /** @return list<string> */
    private function screen(ReviewListWidget $list, int $rows = 12): array
    {
        return \array_map(rtrim(...), $this->rows($list->render(new RenderContext(40, $rows))));
    }

    public function testTheReviewItsConversationAndItsThreadsByFile(): void
    {
        $list = $this->list();
        $list->show(self::review(), loading: false, error: null, showResolved: false);

        $screen = $this->screen($list);

        $this->assertStringContainsString('Review (3 open)', $screen[0]);
        $this->assertSame(' PR #12 Tidy', $screen[1]);
        $this->assertStringContainsString('Conversation (1)', $screen[2]);
        $this->assertStringContainsString(' 9 ', $screen[3], 'a path spelled like a number is still a heading');
        $this->assertStringContainsString('✎ L1 alice: Rename <this>.', $screen[4]);
        $this->assertStringContainsString(' a.php ', $screen[5]);
        $this->assertStringContainsString('✎ L2', $screen[6], 'in line order; the resolved one hidden');
        $this->assertStringContainsString(' b.php ', $screen[7]);

        $list->show(self::review(), loading: false, error: null, showResolved: true);
        $this->assertStringContainsString('✓ L9', $this->screen($list)[7], 'shown when asked, ticked, in line order after L2');
    }

    public function testTheStatusLineSaysWhatIsHappening(): void
    {
        $list = $this->list();

        $list->show(null, loading: true, error: null, showResolved: false);
        $this->assertSame(' Looking for a review…', $this->screen($list)[1]);
        $list->show(null, loading: false, error: 'Bad credentials', showResolved: false);
        $this->assertSame(' Bad credentials', $this->screen($list)[1]);
        $list->show(null, loading: false, error: null, showResolved: false);
        $this->assertSame(' No review for this branch', $this->screen($list)[1]);
        $this->assertNull($list->selectedId());
    }

    public function testTheSelectionStepsOverHeadingsAndSurvivesAReload(): void
    {
        $list = $this->list();
        $list->show(self::review(), loading: false, error: null, showResolved: false);
        $this->assertSame(ReviewListWidget::CONVERSATION, $list->selectedId());

        $list->handleInput(self::DOWN);
        $this->assertSame('9', $list->selectedId(), 'over the heading below');
        $list->handleInput(self::DOWN);
        $list->handleInput(self::UP);
        $this->assertSame('9', $list->selectedId(), 'and over the one above');
        $list->handleInput(self::UP);
        $list->handleInput(self::UP);
        $this->assertSame(ReviewListWidget::CONVERSATION, $list->selectedId(), 'stays put at the top');
        $list->handleInput("\x1b[6~");
        $this->assertSame('b1', $list->selectedId(), 'a page to the last entry');
        $list->handleInput(self::DOWN);

        $list->show(self::review(), loading: false, error: null, showResolved: false);
        $this->assertSame('b1', $list->selectedId(), 'a reload keeps the entry');
        $this->assertFalse($list->selectId('a1'), 'a hidden thread cannot be selected');

        $this->assertSame(['select 9', 'select a2', 'select 9', 'select ', 'select b1'], $this->acted, 'every move picks, the refused one does not');
    }

    public function testTheVerbsActOnTheSelectedEntry(): void
    {
        $list = $this->list();
        $calls = [];
        $list->onFocusNext(static function () use (&$calls): void {
            $calls[] = 'next';
        })->onExit(static function () use (&$calls): void {
            $calls[] = 'exit';
        });

        $list->handleInput('r'); // nothing listed: only the review-wide verbs act
        $list->handleInput('c');
        $list->handleInput("\x1b[15~");
        $list->show(self::review(), loading: false, error: null, showResolved: false);
        $list->selectId('a2');
        foreach (['r', 'x', 'o', "\r", "\t", "\x1b", 'z'] as $key) {
            $list->handleInput($key);
        }

        $this->assertSame(['comment ', 'refresh ', 'reply a2', 'resolve a2', 'open a2', 'select a2'], $this->acted);
        $this->assertSame(['next', 'exit'], $calls);
        $this->assertTrue($list->isVerticallyExpanded());
    }

    public function testTheReplyBoxWritesOnlyToATargetAndClearsWhenItMoves(): void
    {
        $box = new ReplyBoxWidget(new Translator('en'), 40, Styles::dark());
        $sent = [];
        $calls = [];
        $box->onSubmit(static function (string $body) use (&$sent): void {
            $sent[] = $body;
        })->onFocusNext(static function () use (&$calls): void {
            $calls[] = 'next';
        })->onExit(static function () use (&$calls): void {
            $calls[] = 'exit';
        });
        $screen = fn (): string => $this->plain($box->render(new RenderContext(40, 10)));

        $box->handleInput('x');
        $this->assertSame('', $box->text(), 'no target, no typing');
        $this->assertStringContainsString('Pick a thread, or press c on a line', $screen());

        $box->target('Reply to alice');
        $box->handleInput(' ');
        $box->handleInput("\x1b[13;5u");
        $this->assertSame([], $sent, 'blank text is not sent');
        $box->handleInput('o');
        $box->handleInput('k');
        $this->assertStringContainsString('Reply to alice', $screen());
        $box->target('Reply to alice');
        $this->assertSame(' ok', $box->text(), 'the same target keeps the draft');
        $box->handleInput("\x1b[13;5u");
        $this->assertSame(['ok'], $sent, 'trimmed');

        $box->target('Comment on PR #12');
        $this->assertSame('', $box->text(), 'another target starts afresh');
        $this->assertSame('Comment on PR #12', $box->targetLabel());
        $box->handleInput('y');
        $box->clearSent('another');
        $this->assertSame('y', $box->text(), 'a draft that is not what was sent stays');
        $box->clearSent('y');
        $this->assertSame('', $box->text());
        $box->handleInput("\t");
        $box->handleInput("\x1b");
        $this->assertSame(['next', 'exit'], $calls);
    }
}
