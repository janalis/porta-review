<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests\Support;

use Porta\GitPlugin\Process\ProcessLane;
use Porta\ReviewPlugin\Forge\ForgeTransport;
use Porta\Tests\Support\TempProject;

/**
 * The `fake-forge` script's other half: queues the answers it gives, reads back
 * the calls it received, and builds a {@see ForgeTransport} pointed at it — as
 * the forge's CLI, or, with no CLI, as the `curl` a token falls back to.
 */
final class FakeForge
{
    public const string SCRIPT = __DIR__.'/../fixtures/fake-forge';

    public readonly TempProject $dir;

    private int $queued = 0;

    public function __construct()
    {
        $this->dir = new TempProject();
        \putenv('FAKE_FORGE_DIR='.$this->dir->dir);
    }

    /** Queue the next call's answer. */
    public function answer(string $stdout, int $code = 0, string $stderr = ''): self
    {
        ++$this->queued;
        \file_put_contents($this->dir->path($this->queued.'.out'), $stdout);
        \file_put_contents($this->dir->path($this->queued.'.code'), (string) $code);
        \file_put_contents($this->dir->path($this->queued.'.err'), $stderr);

        return $this;
    }

    /**
     * Answer every call whose argv or stdin contains $needle with $json,
     * however many there are — routes are tried before the queue.
     *
     * @param array<array-key, mixed> $json
     */
    public function route(string $needle, array $json): self
    {
        $routes = \is_file($this->dir->path('routes.json')) ? (array) \json_decode((string) \file_get_contents($this->dir->path('routes.json')), associative: true) : [];
        $file = 'route'.\count($routes).'.json';
        \file_put_contents($this->dir->path($file), (string) \json_encode($json));
        $routes[$needle] = $file;
        \file_put_contents($this->dir->path('routes.json'), (string) \json_encode($routes));

        return $this;
    }

    /** @param array<array-key, mixed> $json */
    public function answerJson(array $json): self
    {
        return $this->answer((string) \json_encode($json));
    }

    public function answerFixture(string $name): self
    {
        return $this->answer((string) \file_get_contents(__DIR__.'/../fixtures/'.$name));
    }

    /** @return list<array{argv: list<string>, stdin: string, name: string}> */
    public function calls(): array
    {
        $log = $this->dir->path('calls.jsonl');
        $calls = [];
        foreach (\is_file($log) ? (array) \file($log, \FILE_IGNORE_NEW_LINES) : [] as $line) {
            /** @var array{argv: list<string>, stdin: string, name: string} $call */
            $call = \json_decode((string) $line, associative: true);
            $calls[] = $call;
        }

        return $calls;
    }

    /** @return array<array-key, mixed> the JSON body the $index-th call sent on stdin */
    public function body(int $index): array
    {
        $body = \json_decode($this->calls()[$index]['stdin'] ?? '', associative: true);

        return \is_array($body) ? $body : [];
    }

    /** A transport whose CLI is this fake, answering for $host. */
    public function transport(string $host = 'github.com', ?ProcessLane $lane = null): ForgeTransport
    {
        return new ForgeTransport(
            $this->dir->dir,
            $host,
            static fn (): string => self::SCRIPT,
            ['FAKE_FORGE_TOKEN'],
            static fn (string $path): string => 'https://api.test/'.$path,
            static fn (#[\SensitiveParameter] string $token): string => 'Authorization: Bearer '.$token,
            $lane ?? new ProcessLane(),
            'gh',
        );
    }

    /**
     * A transport with NO CLI: it falls back to `curl`, which PATH resolves to
     * this fake — a directory holding a `curl` link to the script.
     */
    public function curlTransport(): ForgeTransport
    {
        $bin = $this->dir->path('bin');
        if (!\is_dir($bin)) {
            \mkdir($bin);
            \symlink(self::SCRIPT, $bin.'/curl');
        }
        \putenv('PATH='.$bin.':'.\PORTA_TEST_PRISTINE_PATH);

        return new ForgeTransport(
            $this->dir->dir,
            'github.com',
            static fn (): ?string => null,
            ['FAKE_FORGE_TOKEN', 'FAKE_FORGE_TOKEN_2'],
            static fn (string $path): string => 'https://api.test/'.$path,
            static fn (#[\SensitiveParameter] string $token): string => 'Authorization: Bearer '.$token,
            new ProcessLane(),
            'gh',
        );
    }
}
