<?php

use App\Models\ChatterLine;
use App\Models\ChatterProfile;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\Shows;
use App\Support\Caster\Programme\ShowScripts;
use App\Support\Caster\ScriptQueue;
use App\Support\Voice\StockCoherenceJudge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio long shows against the real model (plan "TWENTY ONE Radio", P21)
|--------------------------------------------------------------------------
|
| Costs money: runs only with `--evals` and a key (ANTHROPIC_API_KEY). The
| Chat-Tribunal (on made-up chatters, never published) and the
| Shitcoin-Friedhof (the curated coins) written segment by segment as on
| air, at most `LONG_EVAL_CALLS` writer calls (the shows' daily cap), then
| one StockCoherenceJudge call each. Prints the text, the estimated length,
| the judgement and a jab count (STDERR) as the listening sample.
|
*/

beforeEach(function () {
    if (($_SERVER['PEST_EVALS'] ?? getenv('PEST_EVALS')) !== '1') {
        $this->markTestSkipped('Eval skipped. Run with [--evals] to evaluate against a real model.');
    }

    if (blank(config('ai.providers.anthropic.key'))) {
        $this->markTestSkipped('No ANTHROPIC_API_KEY.');
    }

    config(['tv.commentary.enabled' => true, 'tv.commentary.ai' => true, 'tv.commentary.programme.enabled' => true, 'tv.commentary.programme.rubrics.enabled' => true,
        'tv.commentary.programme.shows.enabled' => true, 'esports.voice.model' => 'claude-sonnet-5-5', 'esports.voice.monthly_budget_usd' => 5,
        'tv.commentary.programme.shows.daily_cap' => (int) (getenv('LONG_EVAL_CALLS') ?: 8), 'tv.commentary.programme.shows.timeout_seconds' => 240]);
    $this->travelTo(CarbonImmutable::parse('2026-10-13 19:00:00', 'Europe/Berlin'));
});

it('writes a long Chat-Tribunal and a long Shitcoin-Friedhof that fill their windows', function () {
    $chatters = [
        'Fritzi' => ['Ich kaufe ab jetzt nur noch Pizza mit Sats, ehrlich.', 'Wer braucht schon eine Hardware-Wallet, mein Passwort ist sicher.'],
        'Hodlbert' => ['Ich habe meinen ganzen Stack auf einer Börse liegen, die ist ja reguliert.', 'Wann kommt endlich der nächste Bullrun, ich warte seit Wochen.'],
        'Satoshina' => ['Ich verkaufe immer genau dann, wenn es fällt, das ist meine Strategie.', 'Mein Steuerberater sagt, Bitcoin sei nur eine Blase.'],
        'Blockfritz' => ['Ich spiele Schach nur, um Blöcke zu schürfen, Schach selbst finde ich langweilig.', 'Gestern habe ich gegen einen Bot verloren und den Stecker gezogen.'],
        'Kryptoklaus' => ['Ich habe noch ein paar Altcoins, die kommen bestimmt wieder.', 'Ein Influencer hat gesagt, dieser Token macht hundert X.'],
        'Lambo-Lena' => ['Wenn Bitcoin eine Million kostet, kaufe ich mir ein Pferd.', 'Ich zahle nie Steuern, ich bin ja im Bärenmarkt.'],
    ];
    foreach (array_keys($chatters) as $index => $name) {
        $pubkey = str_repeat(chr(97 + $index), 64);
        ChatterProfile::query()->create(['pubkey' => $pubkey, 'name' => $name, 'last_chat_at' => now()]);
        foreach ($chatters[$name] as $line => $message) {
            ChatterLine::query()->create(['pubkey' => $pubkey, 'name' => $name, 'message' => $message, 'said_at' => now()->subMinutes(30 + $line)->getTimestamp(), 'reply' => null]);
        }
    }

    $clock = BroadcastClock::fromConfig();
    $calls = fn (): int => (int) DB::table('voice_usage')->where('channel', ShowScripts::CHANNEL)->sum('attempts');

    // LONG_EVAL_SHOWS=tribunal runs only those shows.
    $wanted = array_filter(explode(',', (string) (getenv('LONG_EVAL_SHOWS') ?: 'tribunal,friedhof')));

    foreach (array_intersect_key([Shows::TRIBUNAL => '20:03', Shows::FRIEDHOF => '12:03'], array_flip($wanted)) as $id => $time) {
        $before = $calls();
        $slot = $clock->slotAt(CarbonImmutable::parse('2026-10-13 '.$time, 'Europe/Berlin')->addDay($id === Shows::FRIEDHOF ? 1 : 0));
        $material = app(Shows::class)->material($id, CarbonImmutable::now());
        $writer = app(ShowScripts::class);
        $show = $material === null ? null : $writer->write($slot, $id, $material, CarbonImmutable::now());

        foreach ($writer->rejectedDrafts as $draft) {
            fwrite(STDERR, "\n----- REJECTED {$id} segment {$draft['segment']}: ".implode(', ', $draft['reasons'])."\n{$draft['raw']}\n");
        }
        $text = $show['text'] ?? '';
        $lines = $text === '' ? [] : explode("\n", $text);
        $jabs = count(array_filter($lines, fn (string $line): bool => preg_match('/\b(?:du|alter|alte)\s+\p{Lu}[\p{L}-]+|staat|zentralbank|fiat|steuer|shitcoin|bank|behörde|finanzamt/iu', $line) === 1));
        // The judge reads every line with its speaker, and a show of several cases as such (P21, user decision 2026-10-13).
        $labelled = implode("\n", array_map(fn (string $line, int $index): string => ucfirst($show['speakers'][$index] ?? 'markus').': '.$line, $lines, array_keys($lines)));
        $judge = $show === null ? null : (new StockCoherenceJudge)->prompt(StockCoherenceJudge::message(Shows::CATALOGUE[$id]['name'], $material['data'], $labelled, cases: true))->toArray();

        fwrite(STDERR, "\n===== LONG {$id}\n[slot] ".$slot['starts_at']->setTimezone('Europe/Berlin')->format('H:i').' for '.Shows::slotMinutes($id)." min\n[writer calls] ".($calls() - $before)
            ."\n[estimate] ".round(ScriptQueue::estimateSeconds($text) / 60, 1).' min, '.count($lines).' lines, '.str_word_count($text, 0, 'äöüÄÖÜß').' words'
            ."\n[jabs] {$jabs} of ".count($lines).' lines ('.(count($lines) === 0 ? 0 : round(100 * $jabs / count($lines))).' %)'
            ."\n[material]\n".($material['data'] ?? 'NULL')."\n[text]\n".($text === '' ? 'NULL (dropped)' : $labelled)."\n[judge] ".json_encode($judge, JSON_UNESCAPED_UNICODE)."\n");
    }

    fwrite(STDERR, "\n[rejections] ".json_encode(DB::table('voice_rejections')->get(['channel', 'reason', 'count'])->all(), JSON_UNESCAPED_UNICODE)."\n");

    expect(true)->toBeTrue();
});
