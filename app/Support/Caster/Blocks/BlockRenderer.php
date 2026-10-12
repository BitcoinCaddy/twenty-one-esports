<?php

namespace App\Support\Caster\Blocks;

use App\Models\CasterScript;
use App\Support\Caster\CasterFetcher;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\Idents;
use App\Support\Caster\Pcm;
use App\Support\Caster\ScriptGuard;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Renders a programme block on the workstation (plan "TWENTY ONE Radio",
 * P6; user 2026-10-12: "Die Composition soll lokal erfolgen und zu einem
 * flüssigeren Radiosender führen"): the scripts in the running order of
 * BlockComposer, voiced in one batch per speaker (Sirius' lines of the whole
 * block first, then Markus': a profile switch costs VoiceStudio seconds,
 * user: "der Wechsel des Stimmprofils dauert"), then assembled in memory:
 *
 *  - the Anchor's jingles (JingleLibrary) `jingles.gain_db` under the speech
 *    level, the next speech starting over their last `crossfade_seconds`;
 *    without a jingle library the bulletin still gets its P4 stinger (Idents);
 *  - the pauses of CasterFetcher::voice() within a script, `pause_seconds`
 *    between two scripts;
 *  - short agreeing blips of the other speaker of a duet (user: "kleine
 *    Blips der Zustimmung des jeweils anderen (ja, lachen, hm, stimmt …)"),
 *    `blips.gain_db` under a turn of at least `min_turn_seconds`, at `at` of
 *    the turn, on about `share` of such turns and `min_gap_seconds` apart;
 *  - the news bed under a bulletin only where Idents::bedFor() says so.
 *
 * The result is raw PCM with its chunk ends (sentence ends for a cut), the
 * segment boundaries (the end of every script but the last: a reaction may
 * cut in there) and the protected opening (the news of a news block). The
 * caller encodes it in one loudness pass (CasterFetcher::encode()).
 */
final class BlockRenderer
{
    /** @var Closure(): float */
    private Closure $random;

    /**
     * @param  (Closure(): float)|null  $random  a number in [0, 1)
     * @param  (Closure(): void)|null  $between  called after every VoiceStudio call (BlockFetcher answers urgent scripts there)
     */
    public function __construct(
        private readonly CasterFetcher $fetcher,
        private readonly JingleLibrary $jingles,
        private readonly ?Idents $idents = null,
        ?Closure $random = null,
        private readonly ?Closure $between = null,
    ) {
        $this->random = $random ?? fn (): float => mt_rand(0, 999) / 1000;
    }

    /**
     * @param  list<array<array-key, mixed>>  $scripts  claimed scripts in order, the first the lead
     * @param  array<string, string>  $terms
     * @return array{pcm: string, rate: int, ends: list<float>, boundaries: list<float>, protected: float, steps: list<string>, blips: list<array{at: float, speaker: string, file: string}>, batches: list<string>}|null null when VoiceStudio failed
     */
    public function render(array $scripts, array $terms, CarbonImmutable $airAt, bool $evergreen = false): ?array
    {
        $settings = (array) config('tv.commentary.blocks', []);
        $voice = $this->fetcher->settings();

        // Every chunk of the block, then voiced speaker by speaker.
        $chunks = [];
        foreach ($scripts as $index => $script) {
            ['sentences' => $sentences, 'speakers' => $speakers, 'duet' => $duet] = CasterFetcher::lines($script);

            foreach ($this->fetcher->plannedChunks($sentences, $duet ? $speakers : null) as [$speaker, $lines]) {
                $chunks[] = ['script' => $index, 'speaker' => $speaker, 'lines' => $lines];
            }
        }

        if ($chunks === []) {
            return null;
        }

        $order = array_values(array_unique(array_column($chunks, 'speaker')));
        usort($order, fn (string $a, string $b): int => self::batchRank($a) <=> self::batchRank($b));
        $voiced = [];
        $rate = null;

        foreach ($order as $speaker) {
            foreach ($chunks as $key => $chunk) {
                if ($chunk['speaker'] !== $speaker) {
                    continue;
                }

                $result = $this->fetcher->voiceChunk($chunk['lines'], $speaker, $terms);

                if ($result === null || ($rate !== null && $result['rate'] !== $rate)) {
                    return null;
                }

                $rate = $result['rate'];
                $voiced[$key] = $result['pcm'];

                if ($this->between !== null) {
                    ($this->between)();
                }
            }
        }

        $rate = (int) $rate;
        $steps = BlockComposer::plan($scripts, $airAt, (float) ($settings['jingles']['sweeper_share'] ?? 0.5), $this->random, evergreen: $evergreen);
        $crossfade = (int) round((float) ($settings['crossfade_seconds'] ?? 0.45) * $rate);
        $jingleGain = (float) ($settings['jingles']['gain_db'] ?? -3.0);

        $pcm = '';
        $ends = [];
        $boundaries = [];
        $protected = 0.0;
        $afterJingle = false;
        $log = [];
        $blips = [];
        $lastBlip = -INF;

        foreach ($steps as $step) {
            if ($step['type'] === BlockComposer::STEP_JINGLE) {
                $jingle = $this->jingle($step['categories'] ?? [], $scripts, $airAt, $rate, $jingleGain);

                if ($jingle === null) {
                    continue;
                }

                if ($pcm !== '') {
                    $pcm .= Pcm::silence($this->between((array) ($settings['pause_seconds'] ?? [])) / 2, $rate);
                }

                $pcm .= $jingle['pcm'];
                $afterJingle = true;
                $log[] = 'jingle:'.$jingle['id'];

                continue;
            }

            $index = (int) ($step['index'] ?? 0);
            $script = $scripts[$index];
            $speech = $this->speech($index, $chunks, $voiced, $rate, $voice, $script, $settings, $lastBlip, Pcm::seconds($pcm, $rate), $blips);

            if ($speech === null) {
                continue;
            }

            if ($afterJingle) {
                $start = max(0, intdiv(strlen($pcm), Pcm::BYTES_PER_SAMPLE) - $crossfade);
            } else {
                if ($pcm !== '') {
                    $previous = $scripts[$index - 1] ?? null;
                    $sameStory = is_array($script['part'] ?? null) && is_array($previous['part'] ?? null) && $script['part']['group'] === $previous['part']['group'];
                    $pcm .= Pcm::silence($sameStory ? 0.45 : $this->between((array) ($settings['pause_seconds'] ?? [])), $rate);
                }

                $start = intdiv(strlen($pcm), Pcm::BYTES_PER_SAMPLE);
            }

            // The news bed under a bulletin, where the station sound asks for it (Idents::bedFor()).
            if (($script['kind'] ?? null) === CasterScript::BULLETIN && $this->idents !== null && $this->idents->bedFor(CasterScript::BULLETIN, $airAt)) {
                $speech['pcm'] = (new Idents((array) config('tv.commentary.idents', []), $rate))->underBed($speech['pcm']) ?? $speech['pcm'];
            }

            $offset = $start / $rate;
            $pcm = Pcm::overlay($pcm, $speech['pcm'], $start);
            $afterJingle = false;
            $log[] = 'script:'.($script['id'] ?? $index);

            foreach ($speech['ends'] as $end) {
                $ends[] = round($offset + $end, 3);
            }

            foreach ($speech['blips'] as $blip) {
                $blips[] = ['at' => round($offset + $blip['at'], 3), 'speaker' => $blip['speaker'], 'file' => $blip['file']];
                $lastBlip = $offset + $blip['at'];
            }

            $end = round($offset + Pcm::seconds($speech['pcm'], $rate), 3);
            $boundaries[] = $end;

            if (($script['kind'] ?? null) === CasterScript::BULLETIN && $index === 0) {
                $protected = $end;
            }
        }

        if ($pcm === '' || $ends === []) {
            return null;
        }

        // The block's own end is no boundary inside it.
        array_pop($boundaries);

        return [
            'pcm' => $pcm,
            'rate' => $rate,
            'ends' => $ends,
            'boundaries' => $boundaries,
            'protected' => $protected,
            'steps' => $log,
            'blips' => $blips,
            'batches' => $order,
        ];
    }

    /**
     * One script's speech from its voiced chunks with the pauses of
     * CasterFetcher::voice(), and the blips of the other speaker of a duet.
     *
     * @param  list<array{script: int, speaker: string, lines: list<string>}>  $chunks
     * @param  array<int, string>  $voiced
     * @param  array<string, mixed>  $voice  tv.commentary.voicestudio
     * @param  array<array-key, mixed>  $script
     * @param  array<string, mixed>  $settings  tv.commentary.blocks
     * @param  list<array{at: float, speaker: string, file: string}>  $placed  the blips of the block so far
     * @return array{pcm: string, ends: list<float>, blips: list<array{at: float, speaker: string, file: string}>}|null
     */
    private function speech(int $index, array $chunks, array $voiced, int $rate, array $voice, array $script, array $settings, float $lastBlip, float $blockOffset, array $placed): ?array
    {
        $mine = array_values(array_filter(array_keys($chunks), fn (int $key): bool => $chunks[$key]['script'] === $index));

        if ($mine === []) {
            return null;
        }

        $pause = (array) ($voice['pause'] ?? []);
        $speakerPause = (array) ($voice['speaker_pause'] ?? []);
        $speakers = array_values(array_unique(array_map(fn (int $key): string => $chunks[$key]['speaker'], $mine)));
        $duet = count($speakers) > 1;
        $blipSettings = (array) ($settings['blips'] ?? []);
        $pcm = '';
        $ends = [];
        $blips = [];
        $lastFile = $placed === [] ? null : $placed[count($placed) - 1]['file'];

        foreach ($mine as $position => $key) {
            $speaker = $chunks[$key]['speaker'];
            $turnStart = intdiv(strlen($pcm), Pcm::BYTES_PER_SAMPLE);
            $turn = $voiced[$key];
            $turnSeconds = Pcm::seconds($turn, $rate);
            $pcm .= $turn;
            $ends[] = round(Pcm::seconds($pcm, $rate), 3);

            // An agreeing sound of the other speaker of the duet under a long enough turn, sparingly.
            if ($duet && $turnSeconds >= (float) ($blipSettings['min_turn_seconds'] ?? 3.5)) {
                $at = $turnStart / $rate + $turnSeconds * $this->within((array) ($blipSettings['at'] ?? []), 0.55, 0.8);

                if ($blockOffset + $at - $lastBlip >= (float) ($blipSettings['min_gap_seconds'] ?? 25) && ($this->random)() < (float) ($blipSettings['share'] ?? 0.35)) {
                    $other = collect($speakers)->first(fn (string $candidate): bool => $candidate !== $speaker) ?? DuetDirector::SIRIUS;
                    $files = array_values(array_filter($this->jingles->blips($other), fn (string $file): bool => $file !== $lastFile));
                    $file = $files === [] ? null : $files[(int) floor(($this->random)() * count($files)) % count($files)];
                    $blip = $file === null ? null : $this->jingles->pcm($file, $rate);

                    if ($file !== null && $blip !== null) {
                        $pcm = Pcm::overlay($pcm, $blip, (int) round($at * $rate), (float) ($blipSettings['gain_db'] ?? -12.0));
                        $blips[] = ['at' => round($at, 3), 'speaker' => $other, 'file' => $file];
                        $lastBlip = $blockOffset + $at;
                        $lastFile = $file;
                    }
                }
            }

            if ($position === count($mine) - 1) {
                continue;
            }

            if ($chunks[$mine[$position + 1]]['speaker'] !== $speaker) {
                $pcm .= Pcm::silence($this->within($speakerPause, 0.45, 0.7), $rate);
            } else {
                $base = ScriptGuard::accented((string) end($chunks[$key]['lines'])) ? (float) ($pause['accent'] ?? 0.35) : (float) ($pause['sentence'] ?? 0.2);
                $pcm .= Pcm::silence(max(0.1, $base + (float) ($pause['jitter'] ?? 0.0) * (2 * ($this->random)() - 1)), $rate);
            }
        }

        // A blip may reach past the last word: the speech is as long as both.
        return ['pcm' => $pcm, 'ends' => $ends, 'blips' => $blips];
    }

    /**
     * The jingle of the first category that has one, as PCM; a bulletin without the library opens with its P4 stinger.
     *
     * @param  list<string>  $categories
     * @param  list<array<array-key, mixed>>  $scripts
     * @return array{id: string, pcm: string}|null
     */
    private function jingle(array $categories, array $scripts, CarbonImmutable $airAt, int $rate, float $gainDb): ?array
    {
        $item = $this->jingles->pick($categories, $airAt);
        $pcm = $item === null ? null : $this->jingles->pcm($item['file'], $rate, $gainDb);

        if ($item !== null && $pcm !== null) {
            return ['id' => $item['id'], 'pcm' => $pcm];
        }

        if (in_array('news', $categories, true) && ($scripts[0]['kind'] ?? null) === CasterScript::BULLETIN && $this->idents !== null) {
            $stinger = (new Idents((array) config('tv.commentary.idents', []), $rate))->stingerPcm((string) ($this->idents->slotFor(CasterScript::BULLETIN) ?? 'news'));

            return $stinger === null ? null : ['id' => 'stinger', 'pcm' => $stinger];
        }

        return null;
    }

    /** Sirius' lines first, then Markus', then any other voice (the batch order). */
    private static function batchRank(string $speaker): int
    {
        return match ($speaker) {
            DuetDirector::SIRIUS => 0,
            DuetDirector::MARKUS => 1,
            default => 2,
        };
    }

    /**
     * @param  array<array-key, mixed>  $range
     */
    private function between(array $range): float
    {
        return $this->within($range, 0.5, 0.9);
    }

    /**
     * @param  array<array-key, mixed>  $range
     */
    private function within(array $range, float $min, float $max): float
    {
        $low = (float) ($range['min'] ?? $min);
        $high = max($low, (float) ($range['max'] ?? $max));

        return $low + ($high - $low) * ($this->random)();
    }
}
