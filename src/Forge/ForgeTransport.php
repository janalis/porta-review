<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Forge;

use Porta\GitPlugin\Process\ProcessLane;
use Porta\ReviewPlugin\Json;
use Porta\Support\AsyncProcess;

/**
 * One JSON request to a forge's API, over whichever way in the machine has.
 *
 * The forge's own CLI first (`gh api`, `glab api`): it carries the user's
 * login, so nothing here ever holds a secret. Without it, `curl` with a token
 * from the environment — handed to curl as a CONFIG on stdin, together with the
 * body, so the token never appears in a process list. The same order
 * `porta --self-update` follows, rewritten here because that one is
 * synchronous and private to the console.
 */
final readonly class ForgeTransport
{
    /**
     * @param \Closure(): ?string     $cli       the CLI binary, resolved per call so a Settings edit applies live; null when absent
     * @param list<string>            $tokenEnv  the environment variables a token may sit in, first found wins
     * @param \Closure(string): string $apiUrl   a request path => its absolute URL, for curl
     * @param \Closure(string): string $authHeader the token => the header that carries it
     */
    public function __construct(
        private string $root,
        private string $host,
        private \Closure $cli,
        private array $tokenEnv,
        private \Closure $apiUrl,
        private \Closure $authHeader,
        private ProcessLane $lane,
        private string $cliName,
    ) {}

    /**
     * @param array<string, mixed>|null      $body
     * @param \Closure(?Json, ?string): void $onDone the decoded answer, or null and why
     */
    public function request(string $method, string $path, ?array $body, \Closure $onDone): void
    {
        $json = null === $body ? null : (string) \json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $cli = ($this->cli)();
        if (null !== $cli) {
            $command = [$cli, 'api', '--method', $method, $path, '--hostname', $this->host];
            if (null !== $json) {
                // The type named, because `glab api --input -` sends none and
                // GitLab refuses the body ("content-type '' is not supported").
                $command[] = '--input';
                $command[] = '-';
                $command[] = '--header';
                $command[] = 'Content-Type: application/json';
            }
            $this->run($command, $json, $onDone);

            return;
        }
        $token = $this->token();
        if (null === $token) {
            $onDone(null, \sprintf('Install and log in to %s, or set %s', $this->cliName, $this->tokenEnv[0] ?? 'a token'));

            return;
        }
        $config = [
            'url = "'.self::quote(($this->apiUrl)($path)).'"',
            'request = "'.$method.'"',
            'header = "'.self::quote(($this->authHeader)($token)).'"',
            'header = "Accept: application/json"',
            'silent',
            'show-error',
            'fail-with-body',
        ];
        if (null !== $json) {
            $config[] = 'header = "Content-Type: application/json"';
            $config[] = 'data-binary = "'.self::quote($json).'"';
        }
        $this->run(['curl', '--config', '-'], \implode("\n", $config)."\n", $onDone);
    }

    /**
     * @param list<string>                   $command
     * @param \Closure(?Json, ?string): void $onDone
     */
    private function run(array $command, ?string $stdin, \Closure $onDone): void
    {
        /** @var array<string, string> $env */
        $env = \array_merge(\getenv(), ['GH_PROMPT_DISABLED' => '1', 'GLAB_NO_PROMPT' => '1', 'NO_COLOR' => '1', 'CLICOLOR' => '0', 'GH_PAGER' => '', 'PAGER' => 'cat']);
        $started = $this->lane->start(
            new AsyncProcess($command, $this->root, $stdin, captureStderr: true, env: $env, timeout: AsyncProcess::INTERACTIVE_TOOL_TIMEOUT),
            static function (int $code, string $out, AsyncProcess $process) use ($onDone): void {
                $answer = Json::decode($out);
                // GraphQL answers 200 with its failures inside.
                $errors = $answer->at('errors')->list();
                if (0 === $code && [] === $errors && ('' === \trim($out) || $answer->isArray())) {
                    $noError = null;
                    $onDone($answer, $noError);

                    return;
                }
                $onDone(null, self::message($answer) ?? self::lastLine($process->errorOutput()."\n".$out));
            },
        );
        if (!$started) {
            // @codeCoverageIgnoreStart
            $onDone(null, 'Could not start '.$command[0]);

            // @codeCoverageIgnoreEnd
        }
    }

    private function token(): ?string
    {
        foreach ($this->tokenEnv as $name) {
            $value = \getenv($name);
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }

    /** The API's own sentence for a failure — REST's `message`, GitLab's `error`, GraphQL's first error — or null. */
    private static function message(Json $answer): ?string
    {
        foreach ([['message'], ['error'], ['error_description'], ['errors', 0, 'message'], ['message', 'base', 0]] as $path) {
            $text = $answer->at(...$path)->string();
            if (null !== $text && '' !== $text) {
                return $text;
            }
        }

        return null;
    }

    /** The tool's last non-blank line — `gh: Not Found (HTTP 404)`. */
    private static function lastLine(string $output): string
    {
        $lines = \array_values(\array_filter(\array_map(\trim(...), \explode("\n", $output))));

        return $lines[\count($lines) - 1] ?? 'The request failed';
    }

    /** A value inside a double-quoted curl config string: backslash and quote escaped, and no raw newline. */
    private static function quote(string $value): string
    {
        return \str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value);
    }
}
