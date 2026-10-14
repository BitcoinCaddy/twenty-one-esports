<?php

namespace App\Support\Caster;

use Closure;
use Symfony\Component\Process\Process;

/**
 * The workstation's text writer behind TextModel's "opencode" provider: takes an OpenAI chat-completions request (what
 * laravel/ai's openai-compatible gateway sends), runs it through the local opencode CLI headless (`opencode run
 * --format json`, the prompt on stdin) and answers in the chat-completions shape. Served by `scripts/opencode/router.php`
 * under `php -S` (`caster:text-gateway`), outside the Laravel container, so no facades here.
 *
 * opencode runs with its own config dir (`scripts/opencode/opencode.json`: one agent without any tool, every permission
 * denied, no instructions, the model's thinking off: with it glm-5.3-flash thought 77 s over a 41 kB radio prompt and
 * the first quarter hour on air timed out at 240 s, without it 9 to 33 s), in an empty work dir outside the repo (opencode would read the AGENTS.md of the tree it
 * runs in) and with the Claude Code prompts off: chat text from strangers reaches the prompt, and it must not reach a
 * shell on the workstation.
 *
 * The Regie file is read on every request and goes into every prompt ahead of the task: tone and style rules change on
 * the workstation at once, without a deploy and without a stream restart (user 2026-10-14: "dann können wir auch Sachen
 * ändern, ohne ständig zu deployen … und unterbrechen den Stream nicht ständig").
 */
final class OpencodeGateway
{
    private const MODEL_PATTERN = '/^[a-z0-9._-]+\/[a-z0-9._:-]+$/i';

    /**
     * @param  Closure(string $model, string $prompt): string|null  $runner  the CLI call (null: opencode); returns its JSON event lines
     */
    public function __construct(
        private readonly string $configDir,
        private readonly string $workDir,
        private readonly string $defaultModel,
        private readonly ?string $regieFile = null,
        private readonly string $binary = 'opencode',
        private readonly int $timeoutSeconds = 240,
        private readonly ?Closure $runner = null,
    ) {}

    /** The gateway as `caster:text-gateway` configures it for the router, through the environment. */
    public static function fromEnvironment(): self
    {
        $env = static fn (string $key, string $default = ''): string => (string) (getenv($key) ?: $default);

        return new self(
            $env('OPENCODE_GATEWAY_CONFIG_DIR'),
            $env('OPENCODE_GATEWAY_WORK_DIR', sys_get_temp_dir()),
            $env('OPENCODE_GATEWAY_MODEL', 'zai-coding-plan/glm-5.3-flash'),
            $env('OPENCODE_GATEWAY_REGIE') ?: null,
            $env('OPENCODE_GATEWAY_BINARY', 'opencode'),
            (int) $env('OPENCODE_GATEWAY_TIMEOUT', '240'),
        );
    }

    /**
     * @param  array<string, mixed>  $request  the chat-completions body
     * @return array{0: int, 1: array<string, mixed>} HTTP status and body
     */
    public function handle(array $request): array
    {
        $messages = is_array($request['messages'] ?? null) ? $request['messages'] : [];
        if ($messages === []) {
            return [400, self::error('invalid_request', 'No messages.')];
        }

        $model = is_string($request['model'] ?? null) && preg_match(self::MODEL_PATTERN, $request['model']) === 1 ? $request['model'] : $this->defaultModel;
        $schema = $request['response_format']['json_schema']['schema'] ?? null;
        $prompt = self::prompt($messages, is_array($schema) ? $schema : null, $this->regie());
        $started = microtime(true);
        // A copy of each prompt for measuring (`OPENCODE_GATEWAY_DUMP`, a directory; off by default).
        $dump = getenv('OPENCODE_GATEWAY_DUMP');
        if (is_string($dump) && $dump !== '' && is_dir($dump)) {
            file_put_contents($dump.'/'.date('His').'-'.bin2hex(random_bytes(2)).'.txt', $prompt);
        }

        try {
            $text = self::textFromEvents(($this->runner ?? $this->runOpencode(...))($model, $prompt));
        } catch (\Throwable $e) {
            error_log(sprintf('opencode gateway: %s failed after %.1fs: %s', $model, microtime(true) - $started, $e->getMessage()));

            return [502, self::error('opencode_failed', $e->getMessage())];
        }

        if (is_array($schema)) {
            $json = self::jsonObject($text);
            if ($json === null) {
                error_log(sprintf('opencode gateway: %s gave no JSON object: %s', $model, mb_substr($text, 0, 200)));

                return [502, self::error('invalid_json', 'The model gave no JSON object.')];
            }
            $text = $json;
        }

        error_log(sprintf('opencode gateway: %s answered in %.1fs (%d prompt chars, %d answer chars)', $model, microtime(true) - $started, mb_strlen($prompt), mb_strlen($text)));

        return [200, [
            'id' => 'opencode-'.bin2hex(random_bytes(6)),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $model,
            // No token counts: the workstation's calls are not billed per token, so VoiceUsage prices them at nothing.
            'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $text]]],
        ]];
    }

    /**
     * One prompt from the chat messages: the system part as instructions, the Regie, the conversation as the task, and
     * the answer schema as a closing rule (the CLI has no system field and no structured output of its own).
     *
     * @param  array<mixed>  $messages  chat messages ({role, content}); anything else is skipped
     * @param  array<string, mixed>|null  $schema
     */
    public static function prompt(array $messages, ?array $schema, ?string $regie): string
    {
        $system = [];
        $turns = [];
        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }
            $content = self::content($message['content'] ?? '');
            if (trim($content) === '') {
                continue;
            }
            match ($message['role'] ?? 'user') {
                'system', 'developer' => $system[] = $content,
                'assistant' => $turns[] = "Deine frühere Antwort:\n".$content,
                default => $turns[] = $content,
            };
        }

        $parts = [];
        if ($system !== []) {
            $parts[] = "# Anweisungen\n\n".implode("\n\n", $system);
        }
        if ($regie !== null && trim($regie) !== '') {
            $parts[] = "# Regie (gilt zusätzlich; bei Widerspruch geht die Regie vor)\n\n".trim($regie);
        }
        $parts[] = "# Aufgabe\n\n".implode("\n\n", $turns);
        if ($schema !== null) {
            $parts[] = "# Antwortformat\n\nAntworte ausschließlich mit einem einzigen JSON-Objekt nach diesem JSON-Schema, ohne Codeblock und ohne Text davor oder danach. Alle Pflichtfelder müssen vorkommen.\n\n"
                .json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n\n", $parts);
    }

    /**
     * The answer text from opencode's JSON event lines; an error event throws.
     */
    public static function textFromEvents(string $lines): string
    {
        $text = [];
        foreach (preg_split('/\R/', $lines) ?: [] as $line) {
            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }
            if (($event['type'] ?? '') === 'error') {
                throw new \RuntimeException((string) ($event['error']['message'] ?? $event['error']['type'] ?? 'opencode error'));
            }
            if (($event['type'] ?? '') === 'text' && is_string($event['part']['text'] ?? null)) {
                $text[] = $event['part']['text'];
            }
        }

        $joined = trim(implode('', $text));
        if ($joined === '') {
            throw new \RuntimeException('opencode gave no text.');
        }

        return $joined;
    }

    /** The first complete JSON object in the text (a code fence or a sentence around it dropped), re-encoded. */
    public static function jsonObject(string $text): ?string
    {
        $start = strpos($text, '{');
        while ($start !== false) {
            $depth = 0;
            $inString = false;
            for ($i = $start, $length = strlen($text); $i < $length; $i++) {
                $char = $text[$i];
                if ($inString) {
                    if ($char === '\\') {
                        $i++;
                    } elseif ($char === '"') {
                        $inString = false;
                    }

                    continue;
                }
                if ($char === '"') {
                    $inString = true;
                } elseif ($char === '{') {
                    $depth++;
                } elseif ($char === '}' && --$depth === 0) {
                    $candidate = substr($text, $start, $i - $start + 1);
                    // GLM writes line breaks between sentences raw inside a JSON string (live 2026-10-14: a chat reply
                    // lost as "no JSON object"); escaped, it decodes.
                    $decoded = json_decode($candidate, true) ?? json_decode(self::escapeRawControls($candidate), true);
                    if (is_array($decoded) && ! array_is_list($decoded)) {
                        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: null;
                    }
                    break;
                }
            }
            $start = strpos($text, '{', $start + 1);
        }

        return null;
    }

    /** Raw line breaks and tabs inside the JSON strings of `$json` escaped; everything else as it is. */
    public static function escapeRawControls(string $json): string
    {
        $out = '';
        $inString = false;
        for ($i = 0, $length = strlen($json); $i < $length; $i++) {
            $char = $json[$i];
            if ($inString && $char === '\\') {
                $out .= $char.($json[++$i] ?? '');

                continue;
            }
            if ($char === '"') {
                $inString = ! $inString;
            }
            $out .= $inString ? match ($char) {
                "\n" => '\\n', "\r" => '\\r', "\t" => '\\t', default => $char
            } : $char;
        }

        return $out;
    }

    private function regie(): ?string
    {
        if ($this->regieFile === null || ! is_readable($this->regieFile)) {
            return null;
        }

        // Lines starting with "//" are notes for whoever edits the file, not for the model.
        $lines = array_filter(preg_split('/\R/', (string) file_get_contents($this->regieFile)) ?: [], fn (string $line): bool => ! str_starts_with(ltrim($line), '//'));

        return trim(implode("\n", $lines)) ?: null;
    }

    private function runOpencode(string $model, string $prompt): string
    {
        if (! is_dir($this->workDir)) {
            mkdir($this->workDir, 0700, true);
        }

        // The workstation's long-running `opencode serve` (caster:text-gateway) when it runs; else a private one per call.
        $server = (string) getenv('OPENCODE_GATEWAY_SERVER');
        $process = new Process([$this->binary, 'run', ...($server !== '' ? ['--server', $server] : ['--standalone']), '--format', 'json', '--model', $model], $this->workDir, [
            'OPENCODE_CONFIG_DIR' => $this->configDir,
            'OPENCODE_DISABLE_CLAUDE_CODE' => '1',
            'OPENCODE_DISABLE_AUTOUPDATE' => '1',
        ], $prompt, $this->timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful() && trim($process->getOutput()) === '') {
            throw new \RuntimeException('opencode exited '.$process->getExitCode().': '.mb_substr(trim($process->getErrorOutput()), 0, 300));
        }

        return $process->getOutput();
    }

    /**
     * @param  mixed  $content  a string or a list of content parts
     */
    private static function content(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        return is_array($content) ? implode("\n", array_map(fn (mixed $part): string => is_array($part) && is_string($part['text'] ?? null) ? $part['text'] : '', $content)) : '';
    }

    /**
     * @return array{error: array{type: string, message: string}}
     */
    private static function error(string $type, string $message): array
    {
        return ['error' => ['type' => $type, 'message' => $message]];
    }
}
