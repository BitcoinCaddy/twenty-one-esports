<?php

namespace App\Console\Commands;

use App\Support\Voice\VoicePreview;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * The league voice's tone review (plan "KI-Stimme der Liga", P7): for each
 * voice function up to `--limit` real moments of this database, newest
 * first, the template next to the voice's text through the production path,
 * and the outcome (voiced, or the template with the checks' reason codes).
 * Everything goes into a JSON file for the side-by-side review; the console
 * shows the yield per function, the top rejection reasons and the cost.
 *
 * It publishes, signs, queues and stores nothing: the switches are forced
 * on in this process only and every write but the voice's usage accounting
 * is refused (VoicePreview). The model calls are real and count against the
 * monthly budget; `--dry` makes none.
 */
#[Signature('voice:preview
    {--function= : One voice function only (default: all)}
    {--limit=25 : Moments per function}
    {--json= : Where to write the JSON (default: storage/app/voice-preview-<date>.json)}
    {--dry : Templates only, no model call}')]
#[Description('Preview the league voice next to the templates on real moments; publishes and stores nothing')]
class VoicePreviewCommand extends Command
{
    public function handle(VoicePreview $preview): int
    {
        $function = $this->option('function');
        $functions = is_string($function) && $function !== '' ? [$function] : VoicePreview::FUNCTIONS;
        $unknown = array_diff($functions, VoicePreview::FUNCTIONS);

        if ($unknown !== []) {
            $this->error('Unknown voice function: '.implode(', ', $unknown).'. Known: '.implode(', ', VoicePreview::FUNCTIONS));

            return self::INVALID;
        }

        $limit = max(1, (int) $this->option('limit'));
        $dry = (bool) $this->option('dry');
        $now = CarbonImmutable::now();
        $result = $preview->run($functions, $limit, $dry, $now);
        $summary = VoicePreview::summary($result['rows']);

        $json = $this->option('json');
        $path = is_string($json) && $json !== '' ? $json : storage_path('app/voice-preview-'.$now->format('Y-m-d-His').'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode([
            'generated_at' => $now->toIso8601String(),
            'dry' => $dry,
            'limit' => $limit,
            'summary' => $summary,
            'refused_writes' => $result['refused_writes'],
            'queued_jobs' => $result['queued_jobs'],
            'errors' => $result['errors'],
            'moments' => $result['rows'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($this->output->isVerbose()) {
            foreach ($result['rows'] as $row) {
                $this->line("--- {$row['function']} · {$row['label']} · {$row['outcome']}".($row['reasons'] === [] ? '' : ' ('.implode(', ', $row['reasons']).')'));
                $this->line('  template: '.($row['template'] ?? '—'));
                $this->line('  voice:    '.($row['ai'] ?? '—'));
            }
        }

        $this->table(['Function', 'Voiced / moments'], array_map(
            fn (string $name, array $counts): array => [$name, $dry ? '— / '.$counts['total'] : $counts['voiced'].' / '.$counts['total']],
            array_keys($summary['functions']), $summary['functions'],
        ));

        $failed = array_map(fn (string $error): string => strstr($error, ':', true) ?: $error, $result['errors']);

        foreach (array_diff($functions, array_keys($summary['functions']), $failed) as $empty) {
            $this->line("{$empty}: no moment in this database");
        }

        if ($summary['reasons'] !== []) {
            $this->line('Top reasons: '.implode(', ', array_map(fn (string $reason, int $count): string => "{$reason} {$count}", array_slice(array_keys($summary['reasons']), 0, 8), array_slice($summary['reasons'], 0, 8))));
        }

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        $this->line(sprintf('Cost (voice usage): $%.4f%s', $summary['cost_usd'], $dry ? ' (dry run, no model call)' : ''));
        $this->line('Written: '.$path);

        if ($result['refused_writes'] !== [] || $result['queued_jobs'] > 0) {
            $this->error(sprintf('Held back: %d database write(s), %d queued job(s). Nothing of it reached the database or the queue.', count($result['refused_writes']), $result['queued_jobs']));

            return self::FAILURE;
        }

        $this->info('Nothing was published, signed, queued or stored besides the voice usage.');

        return self::SUCCESS;
    }
}
