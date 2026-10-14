<?php

namespace App\Console\Commands;

use App\Support\Voice\TextModel;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Shows or switches where the radio's texts are written (App\Support\Voice\TextModel): `caster:text opencode` hands
 * every text to the workstation's opencode CLI, `caster:text anthropic` back to the server. Takes effect with the next
 * text: no deploy, no stream restart.
 */
#[Signature('caster:text {provider? : anthropic or opencode}')]
#[Description('Show or switch the text writer of the radio (anthropic or the workstation\'s opencode)')]
class CasterTextCommand extends Command
{
    public function handle(): int
    {
        $provider = $this->argument('provider');
        if (is_string($provider)) {
            if (! in_array($provider, TextModel::PROVIDERS, true)) {
                $this->error('Use one of: '.implode(', ', TextModel::PROVIDERS));

                return self::FAILURE;
            }
            TextModel::switchTo($provider);
        }

        $this->line('Text writer: '.TextModel::active().(TextModel::usesOpencode() ? ' ('.config('esports.voice.opencode.model').')' : ''));

        if (TextModel::usesOpencode()) {
            $health = rtrim((string) preg_replace('#/v1/?$#', '', (string) config('esports.voice.opencode.url')), '/').'/health';
            try {
                $reachable = Http::timeout(3)->get($health)->ok();
            } catch (\Throwable) {
                $reachable = false;
            }
            $reachable ? $this->info("Workstation reachable ({$health})") : $this->warn("Workstation NOT reachable ({$health}): no texts until caster:text-gateway runs there");
        }

        return self::SUCCESS;
    }
}
