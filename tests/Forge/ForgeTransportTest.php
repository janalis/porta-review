<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests\Forge;

use PHPUnit\Framework\TestCase;
use Porta\ReviewPlugin\Forge\ForgeTransport;
use Porta\ReviewPlugin\Json;
use Porta\ReviewPlugin\Tests\Support\FakeForge;
use Porta\ReviewPlugin\Tests\Support\UsesFakeForge;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PumpsLoop;

final class ForgeTransportTest extends TestCase
{
    use PreservesPath;
    use PumpsLoop;
    use UsesFakeForge;

    /** @var array{0: ?Json, 1: ?string}|null */
    private ?array $answer = null;

    /** @param array<string, mixed>|null $body */
    private function send(ForgeTransport $transport, string $method, string $path, ?array $body = null): void
    {
        $this->answer = null;
        $transport->request($method, $path, $body, function (?Json $json, ?string $error): void {
            $this->answer = [$json, $error];
        });
        $this->await(fn (): bool => null !== $this->answer, what: 'the answer');
    }

    public function testTheCliCarriesTheMethodThePathTheHostAndTheBodyOnStdin(): void
    {
        $forge = new FakeForge()->answerJson(['data' => ['ok' => true]])->answer('');
        $transport = $forge->transport('ghe.corp.com');

        $this->send($transport, 'POST', 'graphql', ['query' => 'q', 'variables' => ['a' => 'é/']]);
        $this->assertTrue($this->answer[0]?->at('data', 'ok')->isTrue());
        $this->assertNull($this->answer[1]);
        $this->assertSame(['api', '--method', 'POST', 'graphql', '--hostname', 'ghe.corp.com', '--input', '-'], $forge->calls()[0]['argv']);
        $this->assertSame(['query' => 'q', 'variables' => ['a' => 'é/']], $forge->body(0));

        // No body: no --input, and an empty answer is a success with nothing in it.
        $this->send($transport, 'GET', 'repos/a/b');
        $this->assertSame(['api', '--method', 'GET', 'repos/a/b', '--hostname', 'ghe.corp.com'], $forge->calls()[1]['argv']);
        $this->assertInstanceOf(Json::class, $this->answer[0]);
        $this->assertNull($this->answer[1]);
    }

    public function testAFailureSaysTheApisOwnSentenceElseTheToolsLastLine(): void
    {
        $forge = new FakeForge()
            ->answer('{"message":"Not Found"}', 1, "gh: Not Found (HTTP 404)\n")
            ->answer('{"message":{"base":["Line is not in the diff"]}}', 1)
            ->answer('{"errors":[{"message":"Could not resolve to a node"}],"data":null}')
            ->answer('not json', 1, "gh: bad\ngh: worse\n")
            ->answer('', 1);
        $transport = $forge->transport();

        $this->send($transport, 'GET', 'x');
        $this->assertSame([null, 'Not Found'], $this->answer);
        $this->send($transport, 'GET', 'x');
        $this->assertSame([null, 'Line is not in the diff'], $this->answer, 'GitLab\'s validation shape');
        $this->send($transport, 'POST', 'graphql', []);
        $this->assertSame([null, 'Could not resolve to a node'], $this->answer, 'GraphQL fails inside a 200');
        $this->send($transport, 'GET', 'x');
        $this->assertSame([null, 'not json'], $this->answer, 'the last line of what it printed');
        $this->send($transport, 'GET', 'x');
        $this->assertSame([null, 'The request failed'], $this->answer, 'and something, when it printed nothing');
    }

    public function testWithoutTheCliATokenGoesToCurlAsAConfigOnStdin(): void
    {
        $forge = new FakeForge()->answerJson(['id' => 1]);
        $transport = $forge->curlTransport();
        \putenv('FAKE_FORGE_TOKEN=');
        \putenv('FAKE_FORGE_TOKEN_2=s3cr"et');

        $this->send($transport, 'POST', 'repos/a/b/pulls/1/comments', ['body' => "say \"hi\"\n\\o/"]);

        $this->assertSame(1, $this->answer[0]?->at('id')->int());
        $call = $forge->calls()[0];
        $this->assertSame(['curl', ['--config', '-']], [$call['name'], $call['argv']], 'nothing secret on the command line');
        $this->assertStringContainsString('url = "https://api.test/repos/a/b/pulls/1/comments"', $call['stdin']);
        $this->assertStringContainsString('request = "POST"', $call['stdin']);
        $this->assertStringContainsString('header = "Authorization: Bearer s3cr\\"et"', $call['stdin'], 'the first token set, quoted');
        $this->assertStringContainsString('data-binary = "{\\"body\\":\\"say \\\\\\"hi\\\\\\"\\\\n\\\\\\\\o/\\"}"', $call['stdin'], 'the body, escaped for a config string');
        $this->assertStringContainsString('fail-with-body', $call['stdin']);

        $this->send($transport, 'GET', 'repos/a/b');
        $this->assertStringNotContainsString('data-binary', $forge->calls()[1]['stdin'], 'a GET sends no body');
    }

    public function testWithNeitherItSaysHowToGetIn(): void
    {
        $transport = new FakeForge()->curlTransport();

        $this->send($transport, 'GET', 'x');

        $this->assertSame([null, 'Install and log in to gh, or set FAKE_FORGE_TOKEN'], $this->answer);
    }
}
