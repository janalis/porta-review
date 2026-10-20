<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Editor;

use Porta\DiffPlugin\DiffDocument;

/**
 * Where a review's line is in the working copy now.
 *
 * Two questions with two answers. A FORGE thread names a line of the reviewed
 * commit, so the answer is `git diff <head> -- <path>` read as a map: a line no
 * hunk touched shifts by what the hunks above it added and removed, and a line a
 * hunk rewrote has no place any more. A LOCAL thread was written against the
 * working copy itself and carries the text its line had, so the answer is a
 * search for that text nearest the line it was on.
 */
final class LineTranslator
{
    /** How far from the recorded line a local thread's text is looked for. */
    private const int SEARCH_RADIUS = 200;

    /**
     * $line (1-based, on the diff's OLD side) in the diff's new side, or null
     * when a hunk changed it. $zeroContext is a `--unified=0` diff: every hunk
     * is only what changed.
     */
    public static function throughDiff(DiffDocument $zeroContext, int $line): ?int
    {
        $delta = 0;
        foreach ($zeroContext->hunks as $hunk) {
            // A pure insertion (no old lines) sits AFTER oldStart.
            $lastOld = 0 === $hunk->oldCount ? $hunk->oldStart : $hunk->oldStart + $hunk->oldCount - 1;
            if ($hunk->oldCount > 0 && $line >= $hunk->oldStart && $line <= $lastOld) {
                return null;
            }
            if ($lastOld >= $line) {
                break;
            }
            $delta += $hunk->newCount - $hunk->oldCount;
        }

        return $line + $delta;
    }

    /**
     * The 1-based line of $lines holding $text, nearest $line — the line itself
     * first, then outward, the earlier of two equally near. Null when the text
     * is nowhere within reach, which reads as outdated. Compared trimmed, so a
     * re-indent does not lose the thread.
     *
     * @param list<string> $lines the file, one entry per line
     */
    public static function byText(array $lines, int $line, string $text): ?int
    {
        $wanted = \trim($text);
        $count = \count($lines);
        for ($distance = 0; $distance <= self::SEARCH_RADIUS; ++$distance) {
            foreach ([$line - $distance, $line + $distance] as $candidate) {
                if ($candidate >= 1 && $candidate <= $count && \trim($lines[$candidate - 1]) === $wanted) {
                    return $candidate;
                }
            }
            if (($line - $distance) < 1 && ($line + $distance) > $count) {
                break;
            }
        }

        return null;
    }
}
