<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * The workstation's text writer for TextModel's "opencode" provider: serves App\Support\Caster\OpencodeGateway on
 * 127.0.0.1:`--port` (`php -S` with `--workers` workers) and holds a reverse SSH tunnel that puts that port on the
 * server's loopback (`--ssh`, default `esports.voice.opencode.gateway_ssh`; empty: no tunnel). Either child that exits
 * comes back after five seconds; SIGTERM stops both. Run by the systemd user unit `twentyone-text-gateway`.
 */
#[Signature('caster:text-gateway {--port=4097 : Local and server port} {--workers=4 : Parallel requests} {--ssh= : user@host for the reverse tunnel (default from config)}')]
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
            file_put_contents($regie, "// Regie for every radio text the workstation writes (opencode). Read on every request: a change\n// takes effect with the next text, no deploy, no restart. Lines starting with // are notes, not sent.\n");
        }

        $children = [
            'server' => fn (): Process => new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", base_path('scripts/opencode/router.php')], base_path(), [
                'PHP_CLI_SERVER_WORKERS' => (string) max(1, (int) $this->option('workers')),
                'OPENCODE_GATEWAY_CONFIG_DIR' => base_path('scripts/opencode'),
                'OPENCODE_GATEWAY_WORK_DIR' => (getenv('XDG_CACHE_HOME') ?: getenv('HOME').'/.cache').'/twentyone-opencode',
                'OPENCODE_GATEWAY_MODEL' => (string) config('esports.voice.opencode.model'),
                'OPENCODE_GATEWAY_REGIE' => $regie,
            ], null, null),
        ];
        if ($ssh !== '') {
            $children['tunnel'] = fn (): Process => new Process(['ssh', '-N', '-o', 'ExitOnForwardFailure=yes', '-o', 'ServerAliveInterval=30', '-o', 'ServerAliveCountMax=3',
                '-o', 'BatchMode=yes', '-R', "127.0.0.1:{$port}:127.0.0.1:{$port}", $ssh], null, null, null, null);
        }

        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->stopping = true;
        });

        /** @var array<string, Process> $running */
        $running = [];
        /** @var array<string, float> $restartAt */
        $restartAt = array_fill_keys(array_keys($children), 0.0);
        $this->info("Text gateway on 127.0.0.1:{$port}".($ssh === '' ? ' (no tunnel)' : " tunnelled to {$ssh}").', model '.config('esports.voice.opencode.model'));

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
