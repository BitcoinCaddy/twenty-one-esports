<?php

namespace App\Support\Caster\Blocks;

use App\Models\CasterScript;
use App\Support\Caster\CasterFetcher;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\Idents;
use App\Support\Caster\Pcm;
use App\Support\Caster\QuietHours;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;

/**
 * The workstation's block loop (plan "TWENTY ONE Radio", P6): from the
 * first claimed script on, claims consecutive scripts until the block holds
 * about `blocks.seconds.min` of airtime (estimated), never past
 * `seconds.max` unless a story told in parts still goes on, renders them
 * into one block (BlockRenderer), encodes it in one loudness pass and
 * uploads it to `api/caster/blocks`.
 *
 * What does not join the block:
 *  - a live moment or chat reaction claimed meanwhile: voiced alone and
 *    uploaded at once, it plays next;
 *  - a script the broadcast clock places (`airs_at`): it leads a block of
 *    its own after this one (the block then airs at its slot); a block it
 *    leads takes only the rest of its own story, and after a bulletin the
 *    scripts of `blocks.news_talk_topics` (Sport und Wetter, the news talk),
 *    one claim per topic;
 *  - a later part whose part before it went out in an earlier block: it
 *    leads the next block, so the server plays it right after that one.
 *
 * Below `seconds.min` with nothing left to claim, the scripts go back to the
 * queue (IDLE) while the server still has `starve_seconds` of audio; below
 * that a short block goes out, the stream must not run dry. Any failure
 * releases every script of the block.
 */
final class BlockFetcher
{
    /** Blocks rendered in one run at most (a deferred script starts the next one). */
    private const MAX_BLOCKS_PER_RUN = 4;

    /** @var list<array<string, mixed>> what the last run rendered (tests, the demo) */
    public array $rendered = [];

    /** When the server was last asked for urgent scripts during a render (answerUrgent()). */
    private float $urgentAskedAt = 0.0;

    /**
     * @param  array<array-key, mixed>  $first  the claimed script (the claim's `script`)
     * @param  array<string, string>  $terms
     */
    public function run(CasterFetcher $fetcher, array $first, array $terms, float $spoolSeconds, int $depth = 0): string
    {
        $settings = (array) config('tv.commentary.blocks', []);
        $min = (float) ($settings['seconds']['min'] ?? 180);
        $max = max($min, (float) ($settings['seconds']['max'] ?? 360));
        $maxScripts = max(1, (int) ($settings['max_scripts'] ?? 40));
        $newsTalk = array_values(array_filter((array) ($settings['news_talk_topics'] ?? []), 'is_string'));

        $batch = [$first];
        $deferred = null;
        $exhausted = false;
        $anchored = is_string($first['airs_at'] ?? null);
        $newsTalkTries = 0;

        while (count($batch) < $maxScripts) {
            $last = $batch[count($batch) - 1];
            $continues = is_array($last['part'] ?? null) && ($last['part']['continues'] ?? false) === true;
            $estimate = self::estimate($batch);
            $wantsNewsTalk = $anchored && ($first['kind'] ?? null) === CasterScript::BULLETIN && $newsTalkTries < count($newsTalk);

            if (! $continues && ! $wantsNewsTalk && ($anchored || $estimate >= $min)) {
                break;
            }

            if ($estimate >= $max * 1.5) {
                break;
            }

            $newsTalkTries += $wantsNewsTalk && ! $continues ? 1 : 0;
            $claim = $fetcher->claim();
            $script = $claim?->json('script');

            if (! is_array($script) || ! is_int($script['id'] ?? null)) {
                $exhausted = true;

                break;
            }

            if (in_array($script['kind'] ?? null, ClipSpool::LIVE_KINDS, true)) {
                $fetcher->voiceAndUpload($script, $terms);

                continue;
            }

            if ($this->fits($batch, $script, $anchored, $newsTalk)) {
                $batch[] = $script;

                continue;
            }

            $deferred = $script;

            break;
        }

        $ids = array_map(fn (array $script): int => (int) $script['id'], $batch);

        if (! $anchored && $deferred === null && $exhausted && self::estimate($batch) < $min && $spoolSeconds >= (float) ($settings['starve_seconds'] ?? 150)) {
            $this->releaseAll($fetcher, $ids);

            return CasterFetcher::IDLE;
        }

        $outcome = $this->renderAndUpload($fetcher, $batch, $terms, CarbonImmutable::now()->addSeconds((int) $spoolSeconds));

        if ($deferred !== null) {
            if ($depth + 1 >= self::MAX_BLOCKS_PER_RUN || $outcome !== CasterFetcher::VOICED) {
                $this->releaseAll($fetcher, [(int) $deferred['id']]);

                return $outcome;
            }

            return $this->run($fetcher, $deferred, $terms, $spoolSeconds + self::estimate($batch), $depth + 1);
        }

        return $outcome;
    }

    /**
     * Whether `$script` joins the block in the making.
     *
     * @param  list<array<array-key, mixed>>  $batch
     * @param  array<array-key, mixed>  $script
     * @param  list<string>  $newsTalk
     */
    private function fits(array $batch, array $script, bool $anchored, array $newsTalk): bool
    {
        $part = is_array($script['part'] ?? null) ? $script['part'] : null;
        $ownStory = $part !== null && (int) ($part['index'] ?? 1) > 1
            && collect($batch)->contains(fn (array $earlier): bool => is_array($earlier['part'] ?? null)
                && $earlier['part']['group'] === $part['group'] && (int) $earlier['part']['index'] === (int) $part['index'] - 1);

        if ($ownStory) {
            return true;
        }

        // A later part whose part before it is not in this block leads the next one.
        if ($part !== null && (int) ($part['index'] ?? 1) > 1) {
            return false;
        }

        // Sport und Wetter and the news talk after a bulletin join its block (anchored themselves, they would air right
        // after it anyway).
        if ($anchored) {
            return ($batch[0]['kind'] ?? null) === CasterScript::BULLETIN && in_array($script['topic'] ?? null, $newsTalk, true);
        }

        return ! is_string($script['airs_at'] ?? null);
    }

    /**
     * @param  list<array<array-key, mixed>>  $batch
     * @param  array<string, string>  $terms
     */
    private function renderAndUpload(CasterFetcher $fetcher, array $batch, array $terms, CarbonImmutable $airAt, bool $answerUrgent = true): string
    {
        $ids = array_map(fn (array $script): int => (int) $script['id'], $batch);
        $renderer = new BlockRenderer($fetcher, JingleLibrary::fromConfig(), Idents::fromConfig(), null,
            $answerUrgent ? fn () => $this->answerUrgent($fetcher, $terms) : null);
        $block = $renderer->render($batch, $terms, $airAt);
        $opus = $block === null ? null : $fetcher->encode($block['pcm'], $block['rate']);

        if ($block === null || $opus === null) {
            $this->releaseAll($fetcher, $ids);

            return CasterFetcher::RELEASED;
        }

        $times = fn (array $values): string => implode(',', array_map(fn (float $value): string => sprintf('%.3F', $value), $values));
        $this->rendered[] = ['scripts' => $ids, 'seconds' => round(Pcm::seconds($block['pcm'], $block['rate']), 3)] + array_diff_key($block, ['pcm' => true]);

        try {
            $upload = $fetcher->server()
                ->withHeaders([
                    'X-Caster-Scripts' => implode(',', $ids),
                    'X-Caster-Duration' => sprintf('%.3F', Pcm::seconds($block['pcm'], $block['rate'])),
                    'X-Caster-Sentence-Ends' => $times($block['ends']),
                    'X-Caster-Boundaries' => $times($block['boundaries']),
                    'X-Caster-Protected' => sprintf('%.3F', $block['protected']),
                ])
                ->withBody($opus, 'audio/ogg')
                ->post($fetcher->settings()['server'].'/api/caster/blocks');
        } catch (ConnectionException) {
            $this->releaseAll($fetcher, $ids);

            return CasterFetcher::OFFLINE;
        }

        if ($upload->status() === 201) {
            // The day's archive for the quiet hours' best of (P6 replay); whether it may ever come again is decided there.
            if (ReplayArchive::enabled()) {
                ReplayArchive::fromConfig()->store($opus, $batch, ['ends' => $block['ends'], 'boundaries' => $block['boundaries'], 'steps' => $block['steps'],
                    'seconds' => round(Pcm::seconds($block['pcm'], $block['rate']), 3)], CarbonImmutable::now());
            }

            return CasterFetcher::VOICED;
        }

        $this->releaseAll($fetcher, $ids);

        return $upload->status() === 507 ? CasterFetcher::FULL : CasterFetcher::RELEASED;
    }

    /**
     * The quiet hours' best of the day (P6 replay): while the server has
     * less than `replay.below_seconds` ready and nothing new to voice, an
     * evergreen block from the archive (ReplayArchive::pick()) goes up again
     * behind the Anchor's replay jingle, decoded and encoded by ffmpeg only,
     * no VoiceStudio call. Fails closed: replay off, day time, no evergreen
     * block, no replay jingle (the listener must hear that it is a repeat)
     * or a refusal of the server, and nothing is uploaded (null).
     */
    public function replay(CasterFetcher $fetcher, float $spoolSeconds): ?string
    {
        $settings = (array) config('tv.commentary.blocks.replay', []);
        $now = CarbonImmutable::now();

        if (! ReplayArchive::enabled() || ! QuietHours::active($now) || $spoolSeconds >= (float) ($settings['below_seconds'] ?? 120)) {
            return null;
        }

        $archive = ReplayArchive::fromConfig();
        $pick = $archive->pick($now, (float) ($settings['repeat_hours'] ?? 12));
        $library = JingleLibrary::fromConfig();
        $jingle = $pick === null ? null : $library->pick(['replay'], $now);

        if ($pick === null || $jingle === null) {
            return null;
        }

        $rate = (int) config('tv.commentary.mix.sample_rate', 24000);
        $intro = $library->pcm($jingle['file'], $rate, (float) config('tv.commentary.blocks.jingles.gain_db', -3.0));
        $body = $library->pcm($pick['file'], $rate);

        if ($intro === null || $body === null) {
            return null;
        }

        $start = max(0, intdiv(strlen($intro), Pcm::BYTES_PER_SAMPLE) - (int) round((float) config('tv.commentary.blocks.crossfade_seconds', 0.45) * $rate));
        $pcm = Pcm::overlay($intro, $body, $start);
        $offset = $start / $rate;
        $shift = fn (mixed $times): array => array_map(fn ($time): float => round($offset + (float) $time, 3), array_values(array_filter((array) $times, 'is_numeric')));
        $opus = $fetcher->encode($pcm, $rate);

        if ($opus === null) {
            return null;
        }

        $times = fn (array $values): string => implode(',', array_map(fn (float $value): string => sprintf('%.3F', $value), $values));

        try {
            $upload = $fetcher->server()
                ->withHeaders([
                    'X-Caster-Duration' => sprintf('%.3F', Pcm::seconds($pcm, $rate)),
                    'X-Caster-Sentence-Ends' => $times($shift($pick['meta']['ends'] ?? [])),
                    'X-Caster-Boundaries' => $times($shift($pick['meta']['boundaries'] ?? [])),
                ])
                ->withBody($opus, 'audio/ogg')
                ->post($fetcher->settings()['server'].'/api/caster/replays');
        } catch (ConnectionException) {
            return CasterFetcher::OFFLINE;
        }

        if ($upload->status() !== 201) {
            return null;
        }

        $archive->markReplayed($pick['name'], $now);
        $this->rendered[] = ['replay' => $pick['name'], 'jingle' => $jingle['id']];

        return CasterFetcher::VOICED;
    }

    /**
     * While a block is being voiced (minutes at VoiceStudio's pace), every
     * `urgent_poll_seconds` the server is asked for what cannot wait for it:
     * a live moment, a chat reaction or a wish is voiced and uploaded alone
     * at once, a bulletin, rubric or teaser rendered as its own block (it airs at its
     * slot). Without this a reaction would wait for the whole block.
     *
     * @param  array<string, string>  $terms
     */
    private function answerUrgent(CasterFetcher $fetcher, array $terms): void
    {
        $every = (float) config('tv.commentary.blocks.urgent_poll_seconds', 15);

        if (microtime(true) - $this->urgentAskedAt < $every) {
            return;
        }

        $this->urgentAskedAt = microtime(true);

        for ($answered = 0; $answered < 5; $answered++) {
            $script = $fetcher->claim(urgent: true)?->json('script');

            if (! is_array($script) || ! is_int($script['id'] ?? null)) {
                return;
            }

            // What the clock places (a bulletin, a rubric, a teaser) gets a block of its own, with its jingle.
            if (is_string($script['airs_at'] ?? null) || ($script['kind'] ?? null) === CasterScript::BULLETIN) {
                $this->renderAndUpload($fetcher, [$script], $terms, CarbonImmutable::now(), answerUrgent: false);
            } else {
                $fetcher->voiceAndUpload($script, $terms);
            }
        }
    }

    /**
     * @param  list<int>  $ids
     */
    private function releaseAll(CasterFetcher $fetcher, array $ids): void
    {
        foreach ($ids as $id) {
            $fetcher->release($id);
        }
    }

    /**
     * @param  list<array<array-key, mixed>>  $batch
     */
    private static function estimate(array $batch): float
    {
        return (float) array_sum(array_map(fn (array $script): float => (float) ($script['estimated_seconds'] ?? 0), $batch));
    }
}
