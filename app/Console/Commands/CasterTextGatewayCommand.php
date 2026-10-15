<?php

namespace App\Console\Commands;

use App\Support\Caster\OpencodeGateway;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * The workstation's text writer for TextModel's "opencode" provider: one long-running `opencode serve` on
 * 127.0.0.1:`--opencode-port` (a password of its own per start), App\Support\Caster\OpencodeGateway served twice
 * (`php -S`): on `--port` with `--workers` workers for every text, on `--chat-port` with `--chat-workers` for the live
 * chat reactions alone (TextModel::asChat()), so they never wait behind the rest; and a reverse SSH tunnel that puts
 * both ports on the server's loopback (`--ssh`, default `esports.voice.opencode.gateway_ssh`; empty: no tunnel). A
 * child that exits comes back after five seconds; SIGTERM stops all. Run by the systemd user unit
 * `twentyone-text-gateway`.
 */
#[Signature('caster:text-gateway {--port=4097 : Port for every text} {--workers=6 : Parallel texts} {--chat-port=4099 : Port for the live chat reactions} {--chat-workers=3 : Parallel chat reactions} {--opencode-port=4098 : Local port of opencode serve} {--ssh= : user@host for the reverse tunnel (default from config)}')]
#[Description('Serve the radio texts from the local opencode CLI and tunnel the endpoint to the server')]
class CasterTextGatewayCommand extends Command
{
    private bool $stopping = false;

    public function handle(): int
    {
        $port = (int) $this->option('port');
        $ssh = (string) ($this->option('ssh') ?: config('esports.voice.opencode.gateway_ssh', ''));
        $regie = (string) config('esports.voice.opencode.regie_file');
        if ($regie !== '' && ! is_file($regie)) {
            @mkdir(dirname($regie), 0755, true);
            file_put_contents($regie, "// Regie for every radio text the workstation writes (opencode), every model. Read on every request: a change\n// takes effect with the next text, no deploy, no restart. Lines starting with // are notes, not sent.\n");
        }
        $modelRegie = $regie !== '' ? OpencodeGateway::regieFileFor($regie, (string) config('esports.voice.opencode.model')) : '';
        if ($modelRegie !== '' && ! is_file($modelRegie)) {
            file_put_contents($modelRegie, '// Regie only for '.config('esports.voice.opencode.model')."; it is sent after the common file and wins their\n// conflicts, so one model's tuning never reaches another. Lines starting with // are notes, not sent.\n");
        }

        $chatPort = (int) $this->option('chat-port');
        $opencodePort = (int) $this->option('opencode-port');
        $password = bin2hex(random_bytes(24));
        $workDir = (getenv('XDG_CACHE_HOME') ?: getenv('HOME').'/.cache').'/twentyone-opencode';
        @mkdir($workDir, 0700, true);
        $gateway = fn (int $listen, int $workers): Process => new Process([PHP_BINARY, '-S', "127.0.0.1:{$listen}", base_path('scripts/opencode/router.php')], base_path(), [
            'PHP_CLI_SERVER_WORKERS' => (string) max(1, $workers),
            'OPENCODE_GATEWAY_CONFIG_DIR' => base_path('scripts/opencode'),
            'OPENCODE_GATEWAY_WORK_DIR' => $workDir,
            'OPENCODE_GATEWAY_MODEL' => (string) config('esports.voice.opencode.model'),
            'OPENCODE_GATEWAY_REGIE' => $regie,
            'OPENCODE_GATEWAY_SERVER' => "http://127.0.0.1:{$opencodePort}",
            'OPENCODE_SERVER_PASSWORD' => $password,
        ], null, null);

        $children = [
            'opencode' => fn (): Process => new Process(['opencode', 'serve', '--hostname', '127.0.0.1', '--port', (string) $opencodePort], $workDir, [
                'OPENCODE_CONFIG_DIR' => base_path('scripts/opencode'),
                'OPENCODE_DISABLE_CLAUDE_CODE' => '1',
                'OPENCODE_DISABLE_AUTOUPDATE' => '1',
                'OPENCODE_SERVER_PASSWORD' => $password,
            ], null, null),
            'server' => fn (): Process => $gateway($port, (int) $this->option('workers')),
            'chat' => fn (): Process => $gateway($chatPort, (int) $this->option('chat-workers')),
        ];
        if ($ssh !== '') {
            $children['tunnel'] = fn (): Process => new Process(['ssh', '-N', '-o', 'ExitOnForwardFailure=yes', '-o', 'ServerAliveInterval=30', '-o', 'ServerAliveCountMax=3',
                '-o', 'BatchMode=yes', '-R', "127.0.0.1:{$port}:127.0.0.1:{$port}", '-R', "127.0.0.1:{$chatPort}:127.0.0.1:{$chatPort}", $ssh], null, null, null, null);
        }

        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->stopping = true;
        });

        /** @var array<string, Process> $running */
        $running = [];
        /** @var array<string, float> $restartAt */
        $restartAt = array_fill_keys(array_keys($children), 0.0);
        $this->info("Text gateway on 127.0.0.1:{$port}, chat lane on {$chatPort}, opencode serve on {$opencodePort}".($ssh === '' ? ' (no tunnel)' : " tunnelled to {$ssh}").', model '.config('esports.voice.opencode.model'));

        while (! $this->stopping) {
            foreach ($children as $name => $make) {
                $process = $running[$name] ?? null;
                if ($process !== null && $process->isRunning()) {
                    $this->relay($name, $process);

                    continue;
                }
                if ($process !== null) {
                    $this->relay($name, $process);
                    $this->warn("{$name} exited ({$process->getExitCode()}), restarting in 5 s");
                    unset($running[$name]);
                    $restartAt[$name] = microtime(true) + 5;
                }
                if (microtime(true) >= $restartAt[$name]) {
                    $running[$name] = $make();
                    $running[$name]->start();
                }
            }
            usleep(500_000);
        }

        foreach ($running as $process) {
            $process->stop(5);
        }

        return self::SUCCESS;
    }

    private function relay(string $name, Process $process): void
    {
        foreach (preg_split('/\R/', trim($process->getIncrementalOutput()."\n".$process->getIncrementalErrorOutput())) ?: [] as $line) {
            if (trim($line) !== '' && ! str_contains($line, 'Accepted') && ! str_contains($line, 'Closing')) {
                $this->line("[{$name}] {$line}");
            }
        }
    }
}
