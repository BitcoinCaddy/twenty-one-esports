<?php

namespace Tests\Support;

use App\Support\Caster\Blocks\AutoPilot;
use App\Support\Caster\Blocks\StockLibrary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/**
 * A second-by-second model of the autopilot (plan "TWENTY ONE Radio", P18):
 * the workstation is off for `$hours`, the server's stock library holds
 * `$stockHours` of unique blocks and the podcast library `$podcasts`
 * episodes. The decisions are the real ones: StockLibrary::pick() and
 * markAired() on a real library in a temporary directory (block files are
 * stand-ins, the index is the real one), AutoPilot::musicGap(),
 * podcastDue() and pickEpisode(), QuietHours. The feeder looks every
 * `poll_seconds` (StockFeeder: the next block once the air was free for a
 * music gap); the news-free station ID plays at every full hour (cutting the
 * item on air at its next sentence end, assumed 8 s, which then resumes); a
 * podcast airing starts a minute past the full hour once due.
 *
 * Assumptions, named: block lengths 240 to 420 s (stock.seconds 180 to 360,
 * a block runs past `min` until no talk is cut), the formats in equal shares
 * (StockScripts picks the format with the fewest library blocks), subjects
 * from the configured pools (25 questions, 25 tutorials, 2 podcast digests,
 * one league subject, 12 inflation cases; history and Schwarzbuch stories
 * unique), episodes 60 to 110 minutes, the station ID 15 s.
 */
final class AutoPilotSimulator
{
    /** @var array<int, array{stock: float, repeat: float, podcast: float, id: float, music: float, empty: float, blocks: int, repeats: int}> per day */
    public array $days = [];

    public int $maxRepeatsOfOneBlock = 0;

    public int $subjectSpacingMisses = 0;

    public int $podcastAirings = 0;

    public int $hourIds = 0;

    public float $uniqueSecondsAired = 0.0;

    private string $dir;

    public function __construct(private readonly float $hours = 72, private readonly float $stockHours = 60, private readonly int $podcasts = 7, private readonly int $seed = 21) {}

    public function run(string $start = '2026-10-14 08:00'): self
    {
        mt_srand($this->seed);
        $this->dir = storage_path('framework/testing/autopilot-sim-'.uniqid());
        File::ensureDirectoryExists($this->dir);
        config(['tv.commentary.blocks.stock.library.dir' => $this->dir]);
        $library = StockLibrary::fromConfig();
        $subjects = $this->fill($library, CarbonImmutable::parse($start, 'Europe/Berlin')->subDay());
        $episodes = [];

        for ($i = 0; $i < $this->podcasts; $i++) {
            $episodes[] = ['key' => 'einundzwanzig', 'guid' => 'episode-'.$i, 'published_at' => 1_700_000_000 + $i * 86_400, 'seconds' => (float) mt_rand(3600, 6600)];
        }

        $from = CarbonImmutable::parse($start, 'Europe/Berlin')->getTimestamp();
        $until = $from + (int) round($this->hours * 3600);
        $air = null;
        $freeSince = $from;
        $gap = null;
        $podcastAt = null;
        $podcastAired = [];
        $pendingPodcast = null;
        $previousFormat = null;
        $subjectAired = [];
        $airings = [];

        for ($t = $from; $t < $until; $t++) {
            $now = CarbonImmutable::createFromTimestamp($t);
            $day = intdiv($t - $from, 86_400);
            $this->days[$day] ??= ['stock' => 0.0, 'repeat' => 0.0, 'podcast' => 0.0, 'id' => 0.0, 'music' => 0.0, 'empty' => 0.0, 'blocks' => 0, 'repeats' => 0];
            $topOfHour = $t % 3600 === 0;

            // Ten minutes before a full hour: a podcast airing for a minute past it, when one is due (StockFeeder::podcast()).
            if ($t % 3600 === 3000 && $pendingPodcast === null && ($air === null || $air['kind'] !== 'podcast') && AutoPilot::podcastDue($podcastAt, $now)) {
                $episode = AutoPilot::pickEpisode(array_map(fn (array $e): array => array_diff_key($e, ['seconds' => true]), $episodes), $podcastAired, $now);

                if ($episode !== null) {
                    $pendingPodcast = ['at' => $t + 660, 'seconds' => collect($episodes)->firstWhere('guid', $episode['guid'])['seconds']];
                    $podcastAt = $t;
                    $podcastAired[$episode['guid']] = $t;
                }
            }

            // The station ID at :00 cuts the item on air at its next sentence end; the item resumes after it. A podcast is
            // never cut for it (only a bulletin cuts a podcast, SceneDirector): the ID expires unplayed then.
            if ($topOfHour && ($air === null || $air['kind'] !== 'podcast')) {
                $this->hourIds++;
                $held = $air;
                $air = ['kind' => 'id', 'left' => 15 + ($held === null ? 0 : 8), 'held' => $held];
            }

            if ($air !== null) {
                $kind = $air['kind'];
                $this->days[$day][$kind === 'block' ? ($air['repeat'] ? 'repeat' : 'stock') : $kind]++;
                $air['left']--;

                if ($air['left'] <= 0) {
                    $air = $air['held'] ?? null;
                    $freeSince = $t + 1;
                    $gap = null;
                }

                continue;
            }

            // The air is free: a due podcast first, else the next block after the music gap (looked at every poll).
            if ($pendingPodcast !== null && $t >= $pendingPodcast['at']) {
                $air = ['kind' => 'podcast', 'left' => (int) $pendingPodcast['seconds']];
                $pendingPodcast = null;
                $this->podcastAirings++;

                continue;
            }

            $gap ??= AutoPilot::musicGap($now);
            $poll = (int) config('tv.commentary.blocks.autopilot.poll_seconds', 15);

            if ($pendingPodcast !== null && $pendingPodcast['at'] - $t <= 480) {
                // A podcast starts within eight minutes: no block is put in front of it (StockFeeder::airBusy()).
                $this->days[$day]['music']++;

                continue;
            }

            if ($t - $freeSince < $gap || ($t - $from) % $poll !== 0) {
                $this->days[$day]['music']++;

                continue;
            }

            $pick = $library->pick($now, true, $previousFormat);

            if ($pick === null) {
                // Nothing the rules allow: music beyond the planned gap, the empty air this model measures.
                $this->days[$day]['music']++;
                $this->days[$day]['empty']++;

                continue;
            }

            $library->markAired($pick['hash'], $now);
            $previousFormat = (string) $pick['meta']['format'];
            $airings[$pick['hash']] = ($airings[$pick['hash']] ?? 0) + 1;
            $this->days[$day]['blocks']++;
            $this->days[$day]['repeats'] += $pick['repeat'] ? 1 : 0;
            $this->uniqueSecondsAired += $pick['repeat'] ? 0.0 : (float) $pick['meta']['seconds'];

            foreach ($subjects[$pick['hash']] as $subject) {
                if (isset($subjectAired[$subject]) && $t - $subjectAired[$subject] < 3600 * (float) config('tv.commentary.blocks.stock.library.subject_spacing_hours', 6)) {
                    $this->subjectSpacingMisses++;
                }

                $subjectAired[$subject] = $t;
            }

            // A repeat opens with the replay jingle (about 4 s).
            $air = ['kind' => 'block', 'repeat' => $pick['repeat'], 'left' => (int) ceil((float) $pick['meta']['seconds']) + ($pick['repeat'] ? 4 : 0)];
        }

        $this->maxRepeatsOfOneBlock = $airings === [] ? 0 : max($airings) - 1;
        File::deleteDirectory($this->dir);

        return $this;
    }

    /**
     * The library before the workstation goes: `$stockHours` of unique blocks, rendered the day before.
     *
     * @return array<string, list<string>> the subjects of each block
     */
    private function fill(StockLibrary $library, CarbonImmutable $renderedFrom): array
    {
        $formats = ['talk', 'tutorial', 'geschichte', 'podcast', 'liga', 'schwarzbuch', 'inflationsbeichte'];
        $pools = ['talk' => 25, 'tutorial' => 25, 'podcast' => 2, 'liga' => 1, 'inflationsbeichte' => 12];
        $opusHead = "OggS\0\x02".str_repeat("\0", 20).'OpusHead';
        $seconds = 0.0;
        $subjects = [];

        for ($i = 0; $seconds < $this->stockHours * 3600; $i++) {
            $format = $formats[$i % count($formats)];
            $length = (float) mt_rand(240, 420);
            $subject = 'stock:'.$format.':'.(isset($pools[$format]) ? intdiv($i, count($formats)) % $pools[$format] : $i);
            $opus = $opusHead.'block-'.$i;
            $library->store(sha1($opus), $opus, ['format' => $format, 'topics' => ['stock:'.$format], 'subjects' => [$subject], 'seconds' => $length,
                'ends' => [$length], 'boundaries' => [], 'rendered_at' => $renderedFrom->getTimestamp() + $i, 'evergreen' => true], $renderedFrom);
            $subjects[sha1($opus)] = [$subject];
            $seconds += $length;
        }

        return $subjects;
    }

    /**
     * The numbers per day for the report: repeat rate (repeat seconds of all block seconds), empty-air seconds,
     * voice share.
     *
     * @return list<array{day: int, blocks: int, repeats: int, repeat_rate: float, empty_seconds: int, voice_share: float, podcast_hours: float, stock_hours: float}>
     */
    public function report(): array
    {
        return array_values(array_map(fn (array $day, int $index): array => [
            'day' => $index + 1,
            'blocks' => $day['blocks'],
            'repeats' => $day['repeats'],
            'repeat_rate' => round($day['repeat'] / max(1.0, $day['stock'] + $day['repeat']), 3),
            'empty_seconds' => (int) $day['empty'],
            'voice_share' => round(($day['stock'] + $day['repeat'] + $day['podcast'] + $day['id']) / 86_400, 3),
            'podcast_hours' => round($day['podcast'] / 3600, 1),
            'stock_hours' => round(($day['stock'] + $day['repeat']) / 3600, 1),
        ], $this->days, array_keys($this->days)));
    }
}
