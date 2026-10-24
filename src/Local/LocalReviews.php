<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Local;

use Porta\ReviewPlugin\Json;
use Porta\ReviewPlugin\Model\Anchor;
use Porta\ReviewPlugin\Model\Comment;
use Porta\ReviewPlugin\Model\Review;
use Porta\ReviewPlugin\Model\Side;
use Porta\ReviewPlugin\Model\SourceKind;
use Porta\ReviewPlugin\Model\Thread;
use Porta\Support\Files;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Local reviews on disk: what an agent writes into the working copy for the
 * user to answer, laid out so that nobody ever rewrites anybody else's file.
 *
 *     .porta/reviews/<review>/review.json             {"title": "…"}             optional
 *     .porta/reviews/<review>/<thread>/thread.json    {"path": "src/A.php", "line": 12, "lineText": "…"}
 *     .porta/reviews/<review>/<thread>/<stamp>--<author>.md   one comment, the file is its body
 *     .porta/reviews/<review>/<thread>/resolved       present while the thread is resolved
 *     .porta/reviews/<review>/conversation/…           review-level comments, no thread.json
 *
 * APPEND-ONLY by construction: a comment is a new file, resolving creates a
 * marker and unresolving deletes it. That is what lets an agent and the IDE
 * write at the same time with plain file tools and no merge — the JSON-file
 * alternative needed a read-merge-write and a lock both sides honoured.
 * Comments sort by file name, so the stamp leads it.
 */
final readonly class LocalReviews
{
    public const string DIRECTORY = '.porta/reviews';

    /** The review-level conversation's directory name, which no thread may take. */
    public const string CONVERSATION = 'conversation';

    private const string RESOLVED = 'resolved';
    private const string THREAD_FILE = 'thread.json';

    private Filesystem $files;

    /**
     * @param (\Closure(): string)|null $now a sortable UTC stamp for a new comment's file name, for a
     *                                      test to fix; by default the clock to the microsecond, so two
     *                                      comments in one second still sort
     */
    public function __construct(
        private string $root,
        private ?\Closure $now = null,
    ) {
        $this->files = new Filesystem();
    }

    public function directory(): string
    {
        return $this->root.'/'.self::DIRECTORY;
    }

    /**
     * The review ids on disk, the most recently touched first — the one an
     * agent is writing is the one to show.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        $stamped = [];
        foreach ($this->children($this->directory()) as $id) {
            $stamped[$id] = $this->latestMtime($this->directory().'/'.$id);
        }
        \arsort($stamped);

        return \array_map(\strval(...), \array_keys($stamped));
    }

    /**
     * A fingerprint of everything under the reviews directory — what the poll
     * compares to know that an agent wrote something.
     */
    public function fingerprint(): string
    {
        $parts = [];
        $walk = function (string $dir) use (&$walk, &$parts): void {
            foreach ($this->children($dir) as $name) {
                $path = $dir.'/'.$name;
                $parts[] = $path.':'.self::mtime($path).':'.(\is_file($path) ? (int) \filesize($path) : 0);
                if (\is_dir($path)) {
                    $walk($path);
                }
            }
        };
        $walk($this->directory());

        return \hash('xxh128', \implode("\n", $parts));
    }

    public function read(string $id): ?Review
    {
        $dir = self::isName($id) ? $this->directory().'/'.$id : null;
        if (null === $dir || !\is_dir($dir)) {
            return null;
        }
        $meta = $this->json($dir.'/review.json');
        $threads = [];
        $conversation = [];
        foreach ($this->children($dir) as $name) {
            $threadDir = $dir.'/'.$name;
            if (!\is_dir($threadDir)) {
                continue;
            }
            $comments = $this->comments($threadDir);
            if (self::CONVERSATION === $name) {
                $conversation = $comments;

                continue;
            }
            $anchor = $this->anchor($this->json($threadDir.'/'.self::THREAD_FILE));
            if (null === $anchor || [] === $comments) {
                continue; // half-written: the agent creates thread.json and the first comment in two steps
            }
            $threads[] = new Thread($name, $anchor, $comments, \file_exists($threadDir.'/'.self::RESOLVED));
        }
        $title = $meta->at('title')->string() ?? '';
        $base = $meta->at('base')->string() ?? '';

        return new Review(SourceKind::Local, $id, '' === $title ? $id : $title, $threads, $conversation, baseSha: '' === $base ? null : $base);
    }

    /**
     * Name a review: its title in `review.json`, beside whatever else the file
     * says (a `base`), creating the review's directory when it is new.
     */
    public function describe(string $id, string $title): bool
    {
        if (!self::isName($id)) {
            return false;
        }
        $path = $this->directory().'/'.$id.'/review.json';
        $meta = $this->json($path)->array() ?? [];
        $meta['title'] = $title;

        return $this->writeJson($path, $meta);
    }

    /** Add a comment to a thread (or the conversation); false when the thread does not exist. */
    public function append(string $id, string $thread, string $author, string $body): bool
    {
        if (!self::isName($id) || !self::isName($thread)) {
            return false;
        }
        $dir = $this->directory().'/'.$id.'/'.$thread;

        return (self::CONVERSATION === $thread || \is_dir($dir)) && $this->write($dir.'/'.$this->stamp().'--'.self::fileSafe($author).'.md', $body);
    }

    /** Start a thread on a line; the new thread's id, or null when nothing could be written. */
    public function open(string $id, Anchor $anchor, string $author, string $body): ?string
    {
        if (!self::isName($id)) {
            return null;
        }
        $thread = 't'.$this->stamp();
        $dir = $this->directory().'/'.$id.'/'.$thread;
        $meta = ['path' => $anchor->path, 'line' => $anchor->line, 'side' => $anchor->side->value];
        if (null !== $anchor->lineText) {
            $meta['lineText'] = $anchor->lineText;
        }
        $written = $this->writeJson($dir.'/'.self::THREAD_FILE, $meta);

        return $written && $this->append($id, $thread, $author, $body) ? $thread : null;
    }

    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the request: resolved or not.
    public function resolve(string $id, string $thread, bool $resolved): bool
    {
        if (!self::isName($id) || !self::isName($thread) || self::CONVERSATION === $thread) {
            return false;
        }
        $dir = $this->directory().'/'.$id.'/'.$thread;
        $marker = $dir.'/'.self::RESOLVED;

        return \is_dir($dir) && ($resolved ? $this->write($marker, '') : $this->unmark($marker));
    }

    /** Remove a thread's resolved $marker; false when it cannot be. */
    private function unmark(string $marker): bool
    {
        try {
            $this->files->remove($marker);
        } catch (IOException) {
            return false;
        }

        return true;
    }

    /** @return list<Comment> */
    private function comments(string $dir): array
    {
        $comments = [];
        foreach ($this->children($dir) as $name) {
            if (!\str_ends_with($name, '.md')) {
                continue;
            }
            $stem = \substr($name, offset: 0, length: -3);
            $split = \strpos($stem, needle: '--');
            $author = false === $split ? '' : \substr($stem, $split + 2);
            $stamp = false === $split ? $stem : \substr($stem, offset: 0, length: $split);
            $comments[] = new Comment('' === $author ? '?' : $author, \rtrim(Files::read($dir.'/'.$name)), $stamp);
        }

        return $comments;
    }

    private function anchor(Json $meta): ?Anchor
    {
        $path = $meta->at('path')->string();
        $line = $meta->at('line')->int();
        if (null === $path || '' === $path || null === $line || $line < 1) {
            return null;
        }

        return new Anchor(\ltrim($path, characters: '/'), $line, Side::tryFrom((string) $meta->at('side')->string()) ?? Side::New, $meta->at('lineText')->string());
    }

    private function json(string $path): Json
    {
        return Json::decode(Files::read($path));
    }

    /**
     * Write through a temporary sibling and a rename ({@see Filesystem::dumpFile()}),
     * creating the directories on the way, so a reader polling the directory never
     * sees half a comment.
     */
    /**
     * $meta as a file an agent reads back: pretty, unescaped, newline-ended.
     * False, like a failed write, when $meta cannot be encoded (a title of
     * invalid UTF-8) — never a throw out of the poll.
     *
     * @param array<array-key, mixed> $meta
     */
    private function writeJson(string $path, array $meta): bool
    {
        try {
            $json = \json_encode($meta, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            return false;
        }

        return $this->write($path, $json."\n");
    }

    private function write(string $path, string $contents): bool
    {
        try {
            $this->files->dumpFile($path, $contents);
        } catch (IOException) {
            return false;
        }

        return true;
    }

    /**
     * Sorted, dot-files left out — the temporary sibling of a write in flight among them.
     *
     * @return list<string>
     */
    private function children(string $dir): array
    {
        $names = \is_dir($dir) ? \scandir($dir) : false;
        $names = false === $names ? [] : $names;

        return \array_values(\array_filter($names, static fn (string $name): bool => !\str_starts_with($name, '.')));
    }

    private function latestMtime(string $dir): int
    {
        $latest = self::mtime($dir);
        foreach ($this->children($dir) as $name) {
            $path = $dir.'/'.$name;
            $latest = \max($latest, \is_dir($path) ? $this->latestMtime($path) : self::mtime($path));
        }

        return $latest;
    }

    private static function mtime(string $path): int
    {
        return \file_exists($path) ? (int) \filemtime($path) : 0;
    }

    private function stamp(): string
    {
        if (null !== $this->now) {
            return ($this->now)();
        }
        [$micro, $seconds] = \explode(' ', \microtime());

        return \gmdate('Ymd\THis', (int) $seconds).\substr($micro, offset: 1, length: 7).'Z';
    }

    /**
     * A review's or a thread's name is one directory under the reviews
     * directory: no separator, no `.` or `..`, nothing empty — a name typed on
     * a command line must not write anywhere else.
     */
    private static function isName(string $name): bool
    {
        return 1 === \preg_match('/^\w[A-Za-z0-9._-]*$/', $name);
    }

    /** An author, or a review's name, as a file-name part: no separator, no dot-file, never empty. */
    public static function fileSafe(string $author): string
    {
        $safe = \trim(\str_replace(['/', '\\', "\0", "\n"], replace: '-', subject: $author), characters: ' .-');

        return '' === $safe ? 'anonymous' : $safe;
    }
}
