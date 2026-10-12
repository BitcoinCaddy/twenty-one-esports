<?php

namespace App\Support\Caster;

use App\Models\CasterScript;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The commentators' game of TWENTY ONE TV (user 2026-10-12: "Sirius ist so ein
 * Side-Kommentator, der jeweils immer das zuletzt Gesagte von Markus
 * kommentiert und seinen Senf dazugibt … Nicht langweilig hin und her,
 * sondern mehr Varianz: mal hin und her, dann mal einmal Markus mit zweimal
 * Sirius hintereinander"): decides which scene script becomes a duet and
 * draws its turn sequence (`tv.commentary.duet`).
 *
 * Whether: about `share` of the league voice's scene scripts, rolling over
 * the last `window_segments` of them (isDue()): above the share plus
 * `tolerance` the next one stays Markus alone, below it minus the tolerance
 * (with half the window filled) it is a duet, in between a draw at `share`.
 * Never in the quiet hours.
 *
 * Which turns: the weighted `patterns` (M = Markus, S = Sirius) walked turn
 * by turn (sequence()). Where the turns so far form a whole pattern, the duet
 * ends with the `stop` chance of that turn count (rising), or for sure when no
 * pattern continues; otherwise the next speaker is drawn by the summed weight
 * of the patterns that continue with him. Patterns with more than `max_run`
 * turns of one speaker in a row or outside `turns` are ignored, and Sirius
 * never opens two duets in a row. Sirius' share of the duets' spoken seconds
 * over the last `sirius_share.window_minutes` (siriusShare()) steers the
 * draw: above `max` only the patterns Markus opens with a single Sirius
 * turn are left (M·S, M·S·M, M·M·S), below `min` a pattern's weight is
 * divided by `cooldown` for each Sirius turn beyond the first.
 *
 * No endless loop: a duet is closed when its sequence ends; the next duet
 * sees at most the previous one's last two turns (lastTurns()).
 *
 * The randomness is injectable (`$random`, a float in [0, 1)) so the
 * grammar's constraints are tested over seeded draws.
 */
final class DuetDirector
{
    public const MARKUS = 'markus';

    public const SIRIUS = 'sirius';

    /** @var array<string, string> */
    private const SYMBOLS = ['M' => self::MARKUS, 'S' => self::SIRIUS];

    /** @var Closure(): float */
    private Closure $random;

    /**
     * @param  (Closure(): float)|null  $random  a float in [0, 1)
     */
    public function __construct(?Closure $random = null)
    {
        $this->random = $random ?? fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /**
     * The turn sequence of the next scene script when it is a duet, null when Markus speaks alone.
     *
     * @return list<string>|null
     */
    public function next(CarbonImmutable $now): ?array
    {
        if (! $this->isDue($now)) {
            return null;
        }

        return $this->sequence($this->lastOpener(), $this->siriusShare($now));
    }

    /** Whether the next scene script is a duet: never while quiet, else by the rolling share (decide()). */
    public function isDue(CarbonImmutable $now): bool
    {
        $share = (float) config('tv.commentary.duet.share', 0.0);

        if ($share <= 0.0 || QuietHours::active($now)) {
            return false;
        }

        $window = max(1, (int) config('tv.commentary.duet.window_segments', 20));
        $recent = CasterScript::query()->whole()->where('kind', CasterScript::SCENE)->where('source', CasterScript::SOURCE_AI)
            ->orderByDesc('id')->limit($window)->pluck('speakers')
            ->map(fn (mixed $speakers): bool => in_array(self::SIRIUS, (array) (is_string($speakers) ? json_decode($speakers, true) : $speakers), true))->all();

        return self::decide(array_values($recent), ($this->random)(), $share, (float) config('tv.commentary.duet.tolerance', 0.05), $window);
    }

    /**
     * The rolling decision on its own: `$recent` says for the last scene
     * scripts (newest first) whether each was a duet.
     *
     * @param  list<bool>  $recent
     */
    public static function decide(array $recent, float $random, float $share, float $tolerance, int $window): bool
    {
        if ($share <= 0.0) {
            return false;
        }

        $recent = array_slice($recent, 0, max(1, $window));

        if ($recent !== []) {
            $rolling = count(array_filter($recent)) / count($recent);

            if ($rolling >= $share + $tolerance) {
                return false;
            }

            if ($rolling <= $share - $tolerance && count($recent) * 2 >= $window) {
                return true;
            }
        }

        return $random < $share;
    }

    /**
     * A turn sequence drawn from the weighted patterns (see the class doc).
     *
     * @param  string|null  $lastOpener  who opened the previous duet
     * @param  float|null  $siriusShare  Sirius' share of the duets' spoken seconds lately, null when there were none
     * @return list<string> one speaker per turn
     */
    public function sequence(?string $lastOpener, ?float $siriusShare): array
    {
        $weights = $this->weights($lastOpener, $siriusShare);

        if ($weights === []) {
            return [self::MARKUS, self::SIRIUS];
        }

        $stop = (array) config('tv.commentary.duet.stop', []);
        $prefix = '';

        while (true) {
            $continuing = ['M' => 0.0, 'S' => 0.0];

            foreach ($weights as $pattern => $weight) {
                if (strlen($pattern) > strlen($prefix) && str_starts_with($pattern, $prefix)) {
                    $continuing[$pattern[strlen($prefix)]] += $weight;
                }
            }

            $total = $continuing['M'] + $continuing['S'];

            if (isset($weights[$prefix]) && ($total <= 0.0 || ($this->random)() < (float) ($stop[strlen($prefix)] ?? 1.0))) {
                break;
            }

            $prefix .= ($this->random)() * $total < $continuing['M'] ? 'M' : 'S';
        }

        return array_map(fn (string $symbol): string => self::SYMBOLS[$symbol], str_split($prefix));
    }

    /**
     * The patterns a draw may take, with their weights after the constraints and Sirius' cooldown.
     *
     * @return array<string, float>
     */
    public function weights(?string $lastOpener, ?float $siriusShare): array
    {
        $settings = (array) config('tv.commentary.duet', []);
        $turns = (array) ($settings['turns'] ?? []);
        $minTurns = max(2, (int) ($turns['min'] ?? 2));
        $maxTurns = max($minTurns, (int) ($turns['max'] ?? 5));
        $maxRun = max(1, (int) ($settings['max_run'] ?? 2));
        $share = (array) ($settings['sirius_share'] ?? []);
        $cooldown = min(1.0, max(0.01, (float) ($settings['cooldown'] ?? 0.35)));
        $weights = [];

        foreach ((array) ($settings['patterns'] ?? []) as $pattern => $weight) {
            $pattern = strtoupper((string) $pattern);
            $weight = (float) $weight;

            if ($weight <= 0.0 || preg_match('/^[MS]+$/', $pattern) !== 1 || strlen($pattern) < $minTurns || strlen($pattern) > $maxTurns
                || preg_match('/M{'.($maxRun + 1).'}|S{'.($maxRun + 1).'}/', $pattern) === 1
                || ($lastOpener === self::SIRIUS && $pattern[0] === 'S')) {
                continue;
            }

            $siriusTurns = substr_count($pattern, 'S');

            if ($siriusShare !== null && $siriusShare < (float) ($share['min'] ?? 0.25)) {
                $weight /= $cooldown ** ($siriusTurns - 1);
            }

            $weights[$pattern] = $weight;
        }

        // His cooldown: above the share, Sirius gets one turn and never the first word (unless no such pattern is configured).
        $cooled = array_filter($weights, fn (string $pattern): bool => $pattern[0] === 'M' && substr_count($pattern, 'S') === 1, ARRAY_FILTER_USE_KEY);

        return $siriusShare !== null && $siriusShare > (float) ($share['max'] ?? 0.35) && $cooled !== [] ? $cooled : $weights;
    }

    /** Who opened the latest duet, null when there was none. */
    public function lastOpener(): ?string
    {
        $speakers = CasterScript::query()->whole()->whereNotNull('speakers')->orderByDesc('id')->value('speakers');
        $speakers = is_string($speakers) ? json_decode($speakers, true) : $speakers;

        return is_array($speakers) && is_string($speakers[0] ?? null) ? $speakers[0] : null;
    }

    /**
     * Sirius' share of the spoken seconds of the duets written within
     * `sirius_share.window_minutes` (expired ones left out: nobody heard
     * them), each line at its estimated seconds; null when there were none.
     */
    public function siriusShare(CarbonImmutable $now): ?float
    {
        $minutes = max(1, (int) config('tv.commentary.duet.sirius_share.window_minutes', 60));
        $seconds = [self::MARKUS => 0.0, self::SIRIUS => 0.0];

        $scripts = CasterScript::query()->whereNotNull('speakers')->where('status', '!=', CasterScript::EXPIRED)
            ->where('created_at', '>=', $now->subMinutes($minutes))->get();

        foreach ($scripts as $script) {
            $speakers = $script->lineSpeakers() ?? [];

            foreach ($script->sentences() as $index => $line) {
                $speaker = $speakers[$index] ?? self::MARKUS;
                $seconds[$speaker === self::SIRIUS ? self::SIRIUS : self::MARKUS] += ScriptQueue::estimateSeconds($line);
            }
        }

        $total = $seconds[self::MARKUS] + $seconds[self::SIRIUS];

        return $total > 0.0 ? $seconds[self::SIRIUS] / $total : null;
    }

    /**
     * The last one or two turns of the latest duet, when it was written within
     * `follow_up_minutes`: what a follow-up duet may pick up, nothing older.
     *
     * @return list<array{speaker: string, text: string}>
     */
    public function lastTurns(CarbonImmutable $now): array
    {
        $latest = CasterScript::query()->whereNotNull('speakers')->where('created_at', '>=', $now->subMinutes(max(1, (int) config('tv.commentary.duet.follow_up_minutes', 30))))
            ->orderByDesc('id')->first();

        if ($latest === null) {
            return [];
        }

        $rows = $latest->part_group === null ? collect([$latest])
            : CasterScript::query()->where('part_group', $latest->part_group)->orderBy('part_index')->get();
        [$lines, $speakers] = [[], []];

        foreach ($rows as $row) {
            $rowSpeakers = $row->lineSpeakers();

            if ($rowSpeakers === null) {
                continue;
            }

            array_push($lines, ...$row->sentences());
            array_push($speakers, ...$rowSpeakers);
        }

        return array_slice(self::turns($lines, $speakers), -2);
    }

    /**
     * Lines with their speakers as turns: consecutive lines of one speaker are one turn.
     *
     * @param  list<string>  $lines
     * @param  list<string>  $speakers
     * @return list<array{speaker: string, text: string}>
     */
    public static function turns(array $lines, array $speakers): array
    {
        $turns = [];

        foreach ($lines as $index => $line) {
            $speaker = $speakers[$index] ?? self::MARKUS;
            $last = array_key_last($turns);

            if ($last !== null && $turns[$last]['speaker'] === $speaker) {
                $turns[$last]['text'] .= ' '.$line;
            } else {
                $turns[] = ['speaker' => $speaker, 'text' => $line];
            }
        }

        return $turns;
    }
}
