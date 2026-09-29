<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests\Forge;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\ReviewPlugin\Forge\RemoteUrl;

final class RemoteUrlTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, string}> url, host, path, owner, name */
    public static function remotes(): iterable
    {
        yield 'https' => ['https://github.com/acme/app.git', 'github.com', 'acme/app', 'acme', 'app'];
        yield 'https without .git' => ['https://github.com/acme/app', 'github.com', 'acme/app', 'acme', 'app'];
        yield 'https with a user' => ['https://token@GitHub.com/acme/app.git', 'github.com', 'acme/app', 'acme', 'app'];
        yield 'ssh with a port' => ['ssh://git@git.corp.com:2222/group/sub/app.git', 'git.corp.com', 'group/sub/app', 'group/sub', 'app'];
        yield 'scp-like' => ['git@gitlab.com:group/sub/app.git', 'gitlab.com', 'group/sub/app', 'group/sub', 'app'];
        yield 'trailing slash and newline' => ["https://github.com/acme/app/\n", 'github.com', 'acme/app', 'acme', 'app'];
    }

    #[DataProvider('remotes')]
    public function testItTakesTheRemoteApart(string $url, string $host, string $path, string $owner, string $name): void
    {
        $remote = RemoteUrl::parse($url);

        $this->assertInstanceOf(RemoteUrl::class, $remote);
        $this->assertSame([$host, $path, $owner, $name], [$remote->host, $remote->path, $remote->owner(), $remote->name()]);
    }

    /** @return iterable<string, array{string}> */
    public static function notForges(): iterable
    {
        yield 'a local path' => ['/srv/git/app.git'];
        yield 'a file url' => ['file:///srv/git/app.git'];
        yield 'no owner' => ['https://github.com/app.git'];
        yield 'nothing' => [''];
    }

    #[DataProvider('notForges')]
    public function testWhatNoForgeServesParsesToNull(string $url): void
    {
        $this->assertNull(RemoteUrl::parse($url));
    }
}
