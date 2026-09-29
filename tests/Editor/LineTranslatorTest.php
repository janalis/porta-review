<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests\Editor;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Diff\DiffDocument;
use Porta\ReviewPlugin\Editor\LineTranslator;

final class LineTranslatorTest extends TestCase
{
    /**
     * `git diff --unified=0` of: two lines inserted after line 2, line 5
     * rewritten, and lines 8–9 deleted.
     */
    private static function diff(): DiffDocument
    {
        return DiffDocument::parse(\implode("\n", [
            'diff --git a/a b/a',
            '@@ -2,0 +3,2 @@',
            '+new one',
            '+new two',
            '@@ -5 +7 @@',
            '-five',
            '+FIVE',
            '@@ -8,2 +9,0 @@',
            '-eight',
            '-nine',
        ])
            ."\n");
    }

    public function testALineNoHunkTouchedShiftsByWhatTheHunksAboveItChanged(): void
    {
        $diff = self::diff();

        $this->assertSame(1, LineTranslator::throughDiff($diff, 1));
        $this->assertSame(2, LineTranslator::throughDiff($diff, 2), 'the insertion is AFTER line 2');
        $this->assertSame(5, LineTranslator::throughDiff($diff, 3), 'below the insertion: two further down');
        $this->assertSame(8, LineTranslator::throughDiff($diff, 6), 'the rewrite kept the count');
        $this->assertSame(10, LineTranslator::throughDiff($diff, 10), 'the deletion takes back the two the insertion added');
        $this->assertSame(12, LineTranslator::throughDiff($diff, 12));
    }

    public function testALineAHunkRewroteOrDeletedHasNoPlace(): void
    {
        $diff = self::diff();

        $this->assertNull(LineTranslator::throughDiff($diff, 5));
        $this->assertNull(LineTranslator::throughDiff($diff, 8));
        $this->assertNull(LineTranslator::throughDiff($diff, 9));
        $this->assertSame(4, LineTranslator::throughDiff(DiffDocument::parse(''), 4), 'no diff: every line where it was');
    }

    public function testATextIsFoundNearestItsLineTrimmed(): void
    {
        $lines = ['a', '  target', 'b', 'c', 'target  ', 'd'];

        $this->assertSame(2, LineTranslator::byText($lines, 2, 'target'), 'still where it was');
        $this->assertSame(2, LineTranslator::byText($lines, 3, 'target'), 'one up beats three down');
        $this->assertSame(5, LineTranslator::byText($lines, 5, "\ttarget"), 'a re-indent does not lose it');
        $this->assertSame(5, LineTranslator::byText($lines, 99, 'target'), 'a line past the end still searches back, nearest first');
        $this->assertNull(LineTranslator::byText($lines, 3, 'gone'));
        $this->assertNull(LineTranslator::byText(\array_fill(start_index: 0, count: 500, value: 'x'), line: 1, text: 'y'), 'nor searches forever');
    }
}
