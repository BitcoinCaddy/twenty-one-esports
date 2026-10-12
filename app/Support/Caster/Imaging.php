<?php

namespace App\Support\Caster;

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\Blocks\BlockComposer;
use App\Support\Caster\Blocks\JingleLibrary;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\TwentyOne\Stream\ChildEnvironment;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Radio imaging on the server (plan "TWENTY ONE Radio", P11; user
 * 2026-10-12: "Mehr typische Radio-Zwischenfüller, wie man sie von einem
 * Radiosender kennt. Es ist noch nicht lebhaft genug."): the Anchor's
 * jingles the workstation uploaded (ImagingStore, manifest.json in
 * `tv.commentary.imaging.dir`) go on air between clips, independent of the
 * programme blocks. SceneDirector asks choose() for every clip it starts and
 * wraps the clip's PCM with what it gets (wrap()); the jingle becomes part
 * of the clip, so the hold, the sentence ends and the news window include it.
 *
 * The rules, in this order:
 *  - never on a chat reply or live moment, never on a later part of a story,
 *    never on a block (it carries its own jingles);
 *  - a podcast replay (P12) opens with the podcast opener of its source
 *    (podcast-open-<source>, else podcast-open) on its first chunk and closes
 *    with the back-announce (podcast-close-<source>, else podcast-close) on
 *    its last; nothing in between;
 *  - quiet hours: a station ID only, when one is due;
 *  - a bulletin opens with the news open of its slot (headlines at :30) and
 *    closes with the news close (back-announce);
 *  - a rubric opens with its format opener (rubric-<topic>, opener-<topic>),
 *    a history segment with the Geschichtsstunde, a teaser with coming-up,
 *    where the library has a jingle of that category;
 *  - else a station ID when the last one is `station_every_minutes` ago
 *    (a news open or a time-of-day ID counts as one);
 *  - else a sweeper or liner on `sweeper_share` of the transitions between two
 *    talk clips;
 *  - never two jingles back to back: after a clip that closed with one, the
 *    next clip opens without;
 *  - the same line never within `repeat_minutes` (JingleLibrary's rotation,
 *    remembered in rotation.json next to the manifest, so a restart keeps it).
 *
 * Fails open towards the programme: no manifest, a category without jingles
 * or an undecodable file leave the clip as it was. The manifest is read again
 * whenever its file changes, so an upload takes effect without a restart.
 */
final class Imaging
{
    /** The categories that name the station: each of them resets the station-ID clock. */
    public const STATION_CATEGORIES = ['station', 'station-long', 'station-short', 'news', 'headlines', 'time-morning', 'time-day', 'time-evening', 'time-night'];

    /** The clip kinds that are talk (a sweeper may stand between two of them). */
    private const TALK_KINDS = [CasterScript::SCENE, CasterScript::FILLER, CasterScript::NEWS, CasterScript::FOLLOWED, CasterScript::REPLAY];

    private ?JingleLibrary $library = null;

    private ?int $manifestMtime = null;

    /** When a station ID played last (unix seconds), null: none since the start. */
    private ?int $stationAt = null;

    /** Whether the clip that started last was talk. */
    private bool $previousTalk = false;

    /** Whether the clip that started last closes with a jingle (the next one opens without). */
    private bool $tail = false;

    /** @var Closure(): float */
    private Closure $random;

    /**
     * @param  array{dir?: string, station_every_minutes?: float|int, sweeper_share?: float|int, repeat_minutes?: float|int, pause_seconds?: float|int}  $settings
     * @param  (Closure(): float)|null  $random  a number in [0, 1), mt_rand by default
     */
    public function __construct(
        private readonly array $settings,
        public readonly int $rate = 24000,
        ?Closure $random = null,
        private readonly ?BroadcastClock $clock = null,
    ) {
        $this->random = $random ?? fn (): float => mt_rand(0, 999) / 1000;
    }

    /** The imaging of tv.commentary.imaging, or null while it is switched off. */
    public static function fromConfig(): ?self
    {
        /** @var array{enabled?: bool, dir?: string, station_every_minutes?: float|int, sweeper_share?: float|int, repeat_minutes?: float|int, pause_seconds?: float|int} $settings */
        $settings = (array) config('tv.commentary.imaging', []);

        return ($settings['enabled'] ?? false) === true ? new self($settings, (int) config('tv.commentary.mix.sample_rate', 24000)) : null;
    }

    /** The uploaded library, read again when its manifest changed. */
    public function library(): JingleLibrary
    {
        $dir = (string) ($this->settings['dir'] ?? storage_path('app/stream/imaging'));
        $manifest = rtrim($dir, '/').'/manifest.json';
        clearstatcache(true, $manifest);
        $mtime = is_file($manifest) ? (int) filemtime($manifest) : 0;

        if ($this->library === null || $mtime !== $this->manifestMtime) {
            $this->library = new JingleLibrary($dir, (float) ($this->settings['repeat_minutes'] ?? 45), strict: true);
            $this->manifestMtime = $mtime;
        }

        return $this->library;
    }

    /**
     * The jingles a clip opens and closes with at `$now`, picked (and
     * remembered as played) by the rules of the class.
     *
     * @return array{before: array{id: string, line: string, category: string, file: string, seconds: float}|null, after: array{id: string, line: string, category: string, file: string, seconds: float}|null}
     */
    public function choose(CasterClip $clip, bool $continuation, CarbonImmutable $now): array
    {
        $none = ['before' => null, 'after' => null];
        $kind = $clip->kind;

        if ($continuation) {
            return $none;
        }

        $tail = $this->tail;
        $previousTalk = $this->previousTalk;
        $this->tail = false;
        $this->previousTalk = in_array($kind, self::TALK_KINDS, true) && ! $clip->isBlock();

        if ($kind === CasterScript::PODCAST) {
            return $this->podcast($clip, $tail, $now);
        }

        // The announcement before a podcast and the back-announce after it: the podcast's own jingles stand between.
        if (in_array($kind, [CasterScript::PODCAST_INTRO, CasterScript::PODCAST_OUTRO], true)) {
            return $none;
        }

        if (in_array($kind, ClipSpool::LIVE_KINDS, true) || $clip->isBlock() || $this->library()->items() === []) {
            return $none;
        }

        $stationDue = $this->stationAt === null || $now->getTimestamp() - $this->stationAt >= 60 * (float) ($this->settings['station_every_minutes'] ?? 15);
        $before = [];
        $after = null;

        if (QuietHours::active($now)) {
            $before = $stationDue ? [['station-short', 'station']] : [];
        } else {
            $topic = $kind === CasterScript::RUBRIC ? (string) $clip->script()->value('topic') : '';
            $specific = match ($kind) {
                CasterScript::BULLETIN => $this->newsOpen($clip),
                CasterScript::RUBRIC => $topic === '' || $topic === 'nachrichten_talk' ? null : ['rubric-'.$topic, 'opener-'.$topic],
                CasterScript::HISTORY => ['rubric-geschichte', 'opener-geschichte'],
                CasterScript::TEASER => ['coming-up', 'promo'],
                default => null,
            };

            if ($specific !== null) {
                $before[] = $specific;
            }

            if ($stationDue) {
                $before[] = ($this->random)() < 0.35 ? ['time-'.BlockComposer::daypart($now), 'station'] : ['station', 'station-short'];
            }

            if ($previousTalk && $this->previousTalk && ($this->random)() < (float) ($this->settings['sweeper_share'] ?? 0.35)) {
                $before[] = ($this->random)() < 0.5 ? ['sweeper', 'liner'] : ['liner', 'sweeper'];
            }

            if ($kind === CasterScript::BULLETIN) {
                $after = ['news-close', 'back-announce'];
            }
        }

        $opened = null;

        foreach ($tail ? [] : $before as $categories) {
            $opened = $this->library()->pick($categories, $now);

            if ($opened !== null) {
                break;
            }
        }

        $closed = $after === null ? null : $this->library()->pick($after, $now);

        if ($opened !== null && in_array($opened['category'], self::STATION_CATEGORIES, true)) {
            $this->stationAt = $now->getTimestamp();
        }

        $this->tail = $closed !== null;

        return ['before' => $opened, 'after' => $closed];
    }

    /**
     * The clip's PCM with the chosen jingles around it, `pause_seconds` apart;
     * the sentence ends move behind the opener, and the end of each jingle is
     * a sentence end too (a takeover cuts after a jingle, never inside it).
     * A jingle that cannot be decoded is left out.
     *
     * @param  array{before: array{file: string}|null, after: array{file: string}|null}  $choice
     * @param  list<float>  $sentenceEnds
     * @return array{pcm: string, ends: list<float>, before: bool, after: bool}
     */
    public function wrap(array $choice, string $pcm, array $sentenceEnds): array
    {
        $pause = Pcm::silence(max(0.0, (float) ($this->settings['pause_seconds'] ?? 0.3)), $this->rate);
        $before = $choice['before'] === null ? null : $this->pcm($choice['before']['file']);
        $after = $choice['after'] === null ? null : $this->pcm($choice['after']['file']);
        $ends = $sentenceEnds;

        if ($before !== null) {
            $offset = Pcm::seconds($before.$pause, $this->rate);
            $ends = [round(Pcm::seconds($before, $this->rate), 3), ...array_map(fn (float $end): float => round($end + $offset, 3), $ends)];
            $pcm = $before.$pause.$pcm;
        }

        if ($after !== null) {
            $speech = round(Pcm::seconds($pcm, $this->rate), 3);
            $pcm .= $pause.$after;
            $ends = [...array_values(array_filter($ends, fn (float $end): bool => $end < $speech - 0.05)), $speech, round(Pcm::seconds($pcm, $this->rate), 3)];
        }

        return ['pcm' => $pcm, 'ends' => $ends, 'before' => $before !== null, 'after' => $after !== null];
    }

    /**
     * The opener of a podcast replay's first chunk and the back-announce after its last.
     *
     * @return array{before: array{id: string, line: string, category: string, file: string, seconds: float}|null, after: array{id: string, line: string, category: string, file: string, seconds: float}|null}
     */
    private function podcast(CasterClip $clip, bool $tail, CarbonImmutable $now): array
    {
        $podcast = (array) ($clip->block['podcast'] ?? []);
        $source = is_string($podcast['source'] ?? null) ? $podcast['source'] : '';
        $index = (int) ($podcast['index'] ?? 0);
        $first = $index === 1;
        $last = $index > 0 && $index === (int) ($podcast['count'] ?? 0);
        $library = $this->library()->items() === [] ? null : $this->library();

        $opened = $first && ! $tail ? $library?->pick(['podcast-open-'.$source, 'podcast-open'], $now) : null;
        $closed = $last ? $library?->pick(['podcast-close-'.$source, 'podcast-close'], $now) : null;
        $this->tail = $closed !== null;

        return ['before' => $opened, 'after' => $closed];
    }

    /**
     * The news open of a bulletin's slot: the headlines at :30, the news at :00.
     *
     * @return list<string>
     */
    private function newsOpen(CasterClip $clip): array
    {
        $airsAt = $clip->script()->value('airs_at');
        $clock = $this->clock ?? BroadcastClock::fromConfig();
        $type = $airsAt === null ? BroadcastClock::NEWS : $clock->slotAt(CarbonImmutable::parse((string) $airsAt))['type'];

        return $type === BroadcastClock::HEADLINES ? ['headlines', 'news-open', 'news'] : ['news-open', 'news'];
    }

    /** A jingle as PCM (s16le mono at the voice's rate), decoded by ffmpeg; null when it fails. */
    private function pcm(string $file): ?string
    {
        try {
            $result = Process::timeout(10)->env(ChildEnvironment::withoutSecrets())->run([
                (string) config('twentyone.stream.ffmpeg', 'ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error',
                '-i', $file, '-ac', '1', '-ar', (string) $this->rate, '-f', 's16le', 'pipe:1',
            ]);
        } catch (Throwable) {
            $result = null;
        }

        $pcm = $result !== null && $result->successful() ? $result->output() : '';
        $pcm = substr($pcm, 0, strlen($pcm) - strlen($pcm) % Pcm::BYTES_PER_SAMPLE);

        if ($pcm === '') {
            Log::warning('Caster imaging jingle failed', ['file' => basename($file)]);

            return null;
        }

        return $pcm;
    }
}
