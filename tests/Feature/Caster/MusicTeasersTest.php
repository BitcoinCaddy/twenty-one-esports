<?php

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\Blocks\AutoPilot;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\CommentaryTimeline;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\DuetScripts;
use App\Support\Caster\Imaging;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\MusicTeasers;
use App\Support\Caster\SceneDirector;
use App\Support\Caster\ScriptQueue;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use App\Support\TwentyOne\Stream\MusicCatalog;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceUsage;
use Carbon\CarbonImmutable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio: music announcements and track-change jingles
|--------------------------------------------------------------------------
|
| "Im Radio werden doch auch die Musik-Titel angekündigt, die als nächstes
| gespielt werden" (user 2026-10-17): in a music gap the moderator announces
| the track the timeline really plays next, by the artist's collected data —
| not every track, only in the gaps between the programme. And a short
| jingle rides a track change while the air is calm (Imaging).
|
*/

const AINSLEY = "wavlake/Can't Say I'm Not Trying - Ainsley Costello__v1.m4a";

/** Writes a music timeline state file the way the stream daemon keeps it. */
function musicTimeline(array $entries, float $anchor): string
{
    // Under the checkout's testing storage, never the shared /tmp: a file left there by every run (nothing removed it)
    // also failed CasterFetchTest's "leaves no file behind" whenever it was written while that test looked.
    $path = storage_path('framework/testing/music-teasers-'.getmypid().'/music-timeline-'.bin2hex(random_bytes(4)).'.json');
    @mkdir(dirname($path), 0775, true);
    file_put_contents($path, json_encode(['anchor' => $anchor, 'entries' => array_map(
        fn (array $entry): array => [$entry[0], $entry[1], 0.0, null], $entries)], JSON_UNESCAPED_SLASHES));

    return $path;
}

/** The timeline of the standard case: something running, Ainsley next (about now + 100 s). */
function defaultTimeline(float $now): string
{
    return musicTimeline([['running.m4a', 200.0], [AINSLEY, 180.0], ['another.m4a', 150.0]], $now - 100.0);
}

beforeEach(function () {
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.ai' => true,
        'tv.commentary.programme.enabled' => true,
        'tv.commentary.programme.music_teasers.enabled' => true,
        'tv.commentary.programme.music_teasers.chance' => 1.0,
        'tv.commentary.programme.music_teasers.min_gap_minutes' => 25,
        'tv.commentary.spool.quiet_hours.from' => '00:00',
        'tv.commentary.spool.quiet_hours.to' => '00:00',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-17 14:20:00', 'Europe/Berlin'));
    $this->now = CarbonImmutable::now();
    config(['twentyone.stream.music.timeline_file' => defaultTimeline($this->now->getTimestamp())]);
});

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/music-teasers-'.getmypid()));
});

it('writes a music announcement in a calm gap, from the timeline\'s real next track and the artist\'s data', function () {
    // The artist's last note, cached the way lastNote() would have fetched it (the relays are not the test's).
    $pubkey = MusicCatalog::artists()['ainsley-costello']['pubkey'];
    Cache::put('caster:music_teaser:note:'.substr(sha1($pubkey), 0, 12), ['text' => 'Neue Single ist fertig, Leute.', 'ago' => 'vor zwei Stunden'], now()->addHour());
    // The gateway's envelope, the way the workstation's schema answers (live 2026-10-17: the first announcement
    // carried it word for word): the text is what it wraps.
    VoiceAgent::fake(fn (): array => ['text' => '{"text":"Gleich in der Musik: Ainsley Costello mit Can\'t Say I\'m Not Trying.\\nAuf Nostr schreibt sie gerade, die neue Single sei fertig.","canon_id":null,"mood":"hype"}']);

    expect(app(ScriptQueue::class)->fillMusicTeaser($this->now))->toBe(1);

    $script = CasterScript::query()->where('kind', CasterScript::MUSIC_TEASER)->first();
    expect($script)->not->toBeNull()
        ->and($script->status)->toBe(CasterScript::QUEUED)
        ->and($script->topic)->toBe('music')
        ->and($script->expires_at->getTimestamp())->toBeLessThanOrEqual($this->now->getTimestamp() + 9 * 60)
        ->and($script->speakers)->toBe([DuetDirector::MARKUS, DuetDirector::MARKUS])
        ->and($script->text)->toContain('Ainsley Costello')
        ->and($script->text)->toContain("Can't Say I'm Not Trying")
        ->and($script->text)->not->toStartWith('{');

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Ainsley Costello')
        && str_contains($prompt->prompt, "Can't Say I'm Not Trying")
        && str_contains($prompt->prompt, 'ainsleycostello@getalby.com')
        && str_contains($prompt->prompt, 'Neue Single ist fertig'));
});

it('stays silent while the voice ahead still fills the air, or a slot is near, or the quiet hours run', function () {
    $queue = app(ScriptQueue::class);

    // Its own lane runs on its own, whatever keeps the caster:script writer busy.
    $this->artisan('caster:music-teaser')->assertSuccessful();

    // Five minutes of voice ahead (a long ready clip): no handover to the music in sight.
    $clip = CasterScript::query()->create(['kind' => CasterScript::SCENE, 'phase' => 'break', 'text' => 'x', 'facts_hash' => 'x', 'status' => CasterScript::QUEUED, 'priority' => 0, 'estimated_seconds' => 300, 'expires_at' => now()->addYears(9)]);
    CasterClip::query()->create(['caster_script_id' => $clip->id, 'phase' => 'break', 'kind' => CasterScript::SCENE, 'path' => 'x/'.$clip->id.'.opus', 'duration_seconds' => 300, 'bytes' => 10, 'sentence_ends' => [300.0], 'status' => CasterClip::READY, 'expires_at' => now()->addMinutes(10)]);
    expect(app(ScriptQueue::class)->fillMusicTeaser($this->now))->toBe(0);
    CasterClip::query()->delete();
    CasterScript::query()->where('kind', CasterScript::SCENE)->delete();

    // The news at 15:00 are four minutes away: no announcement into the programme.
    $this->travelTo(CarbonImmutable::parse('2026-10-17 14:56:30', 'Europe/Berlin'));
    expect($queue->fillMusicTeaser(CarbonImmutable::now()))->toBe(0);

    // A clock-placed script near (a teaser that airs in two minutes).
    $this->travelTo(CarbonImmutable::parse('2026-10-17 14:20:00', 'Europe/Berlin'));
    CasterScript::query()->create(['kind' => CasterScript::TEASER, 'phase' => 'break', 'text' => 'x', 'facts_hash' => 't1', 'status' => CasterScript::QUEUED, 'priority' => 72, 'estimated_seconds' => 8, 'airs_at' => now()->addMinutes(2), 'expires_at' => now()->addYears(9)]);
    expect($queue->fillMusicTeaser(CarbonImmutable::now()))->toBe(0);
    CasterScript::query()->where('kind', CasterScript::TEASER)->delete();

    // The quiet hours: no music announcements.
    config(['tv.commentary.spool.quiet_hours.from' => '01:00', 'tv.commentary.spool.quiet_hours.to' => '09:00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-18 03:00:00', 'Europe/Berlin'));
    expect($queue->fillMusicTeaser(CarbonImmutable::now()))->toBe(0);
});

it('does not announce every track: one per min_gap_minutes, at chance, once per track', function () {
    $queue = app(ScriptQueue::class);

    // A music announcement written two minutes ago holds the next one back.
    CasterScript::query()->create(['kind' => CasterScript::MUSIC_TEASER, 'phase' => 'break', 'text' => 'x', 'facts_hash' => 'old', 'status' => CasterScript::VOICED, 'priority' => 70, 'estimated_seconds' => 8, 'created_at' => now()->subMinutes(2), 'expires_at' => now()->addYears(9)]);
    expect($queue->fillMusicTeaser($this->now))->toBe(0);
    CasterScript::query()->where('kind', CasterScript::MUSIC_TEASER)->delete();

    // No luck this time.
    config(['tv.commentary.programme.music_teasers.chance' => 0.0]);
    expect($queue->fillMusicTeaser($this->now))->toBe(0);
});

it('picks the next credited track that starts after the lead', function () {
    $now = $this->now->getTimestamp();
    // A short one running out in ten seconds (inside the lead), an uncredited instrumental, then Ainsley.
    config(['twentyone.stream.music.timeline_file' => musicTimeline(
        [['short.m4a', 70.0], ['unknown/never-seen__v1.m4a', 150.0], [AINSLEY, 180.0]],
        $now - 60.0,
    )]);

    $next = MusicTeasers::nextTrack($this->now, 45);
    expect($next)->not->toBeNull()
        ->and($next['file'])->toBe(AINSLEY)
        ->and($next['artist'])->toBe('Ainsley Costello')
        ->and($next['title'])->toBe("Can't Say I'm Not Trying")
        ->and($next['npub'])->toStartWith('npub1')
        ->and($next['lud16'])->toBe('ainsleycostello@getalby.com')
        ->and($next['hash'])->toBe('music:'.substr(sha1(AINSLEY.'|'.(string) ($now + 160.0)), 0, 20));
});

it('keeps only announcements that name the artist and the title, without digits', function () {
    $teasers = app(MusicTeasers::class);
    $next = ['file' => AINSLEY, 'artist' => 'Ainsley Costello', 'title' => "Can't Say I'm Not Trying", 'hash' => 'x', 'npub' => null, 'youtube' => null, 'wavlake' => false, 'lud16' => null];

    expect($teasers->evaluate("Gleich in der Musik: Ainsley Costello mit Can't Say I'm Not Trying.", $next))->not->toBeNull()
        ->and($teasers->evaluate('Als Nächstes ein Song von Ainsley Costello.', $next))->toBeNull()
        ->and($teasers->evaluate('Ainsley Costello kommt gleich mit Can\'t Say I\'m Not Trying, Track 24.', $next))->toBeNull()
        // The sign-off goes the way of the hygiene clean: what stays is a valid announcement.
        ->and($teasers->evaluate("Gleich: Ainsley Costello mit Can't Say I'm Not Trying. Tschüss und macht's gut.", $next))->not->toBeNull();
});

it('plays the announcement only when no scene clip is left, before the filler', function () {
    $scene = CasterScript::query()->create(['kind' => CasterScript::SCENE, 'phase' => 'break', 'text' => 'x', 'facts_hash' => 's1', 'status' => CasterScript::QUEUED, 'priority' => 0, 'estimated_seconds' => 4, 'expires_at' => now()->addYears(9)]);
    CasterClip::query()->create(['caster_script_id' => $scene->id, 'phase' => 'break', 'kind' => CasterScript::SCENE, 'path' => 'x/'.$scene->id.'.opus', 'duration_seconds' => 4, 'bytes' => 10, 'sentence_ends' => [4.0], 'status' => CasterClip::READY, 'expires_at' => now()->addMinutes(10)]);
    $announcement = CasterScript::query()->create(['kind' => CasterScript::MUSIC_TEASER, 'phase' => 'break', 'text' => 'x', 'facts_hash' => 'm1', 'status' => CasterScript::QUEUED, 'priority' => 70, 'estimated_seconds' => 8, 'expires_at' => now()->addYears(9)]);
    CasterClip::query()->create(['caster_script_id' => $announcement->id, 'phase' => 'break', 'kind' => CasterScript::MUSIC_TEASER, 'path' => 'x/'.$announcement->id.'.opus', 'duration_seconds' => 8, 'bytes' => 10, 'sentence_ends' => [8.0], 'status' => CasterClip::READY, 'expires_at' => now()->addMinutes(10)]);

    $spool = app(ClipSpool::class);
    expect($spool->next('break', $this->now)['clip']->caster_script_id)->toBe($scene->id);

    $sceneClip = CasterClip::query()->where('caster_script_id', $scene->id)->first();
    $sceneClip->update(['status' => CasterClip::PLAYING]);
    // The playing scene is not fresh; the announcement is what the gap plays next.
    $picked = $spool->next('break', $this->now);
    expect($picked['clip']->caster_script_id)->toBe($announcement->id)
        ->and($picked['source'])->toBe('music');
});

it('lays a short jingle over a track change while the air is calm, once per change', function () {
    Process::fake(['*' => fn (PendingProcess $process) => Process::result(output: str_repeat("\1\0", (int) (24000 * 3.2)))]);
    $dir = sys_get_temp_dir().'/opencode/imaging-'.bin2hex(random_bytes(4));
    @mkdir($dir, 0775, true);
    file_put_contents($dir.'/sw1.opus', 'OggS');
    file_put_contents($dir.'/manifest.json', json_encode(['items' => [['id' => 'sw1', 'line' => 'Twenty One Esports Radio', 'category' => 'sweeper', 'file' => 'sw1.opus', 'seconds' => 3.2]]]));

    $clock = new BroadcastClock([], 'Europe/Berlin', false);
    $imaging = new Imaging(['dir' => $dir, 'music_change_share' => 1.0, 'music_change_every_minutes' => 20, 'repeat_minutes' => 45, 'back_to_back_seconds' => 12], 24000, fn (): float => 0.0, $clock);
    $changeAt = $this->now->getTimestamp() + 30.0;
    $director = new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000), [
        'lead_in_seconds' => 0.5, 'feed_lead_seconds' => 1.0, 'min_scene_seconds' => 20, 'bulletin_late_seconds' => 120, 'rubric_late_seconds' => 240, 'gap_seconds' => ['min' => 25, 'max' => 25],
    ], clock: $clock, imaging: $imaging, musicTracks: fn (float $from, float $to): array => $changeAt >= $from && $changeAt <= $to
        ? [['file' => 'a.m4a', 'start' => $changeAt, 'end' => $changeAt + 180.0, 'vocal' => true, 'priority' => false]]
        : []);

    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $endsAt = null;

    for ($t = $changeAt - 2.0; $t <= $changeAt + 2.0; $t += 0.25) {
        $director->tick($planner, $planner->at($t, []), $t);
        $endsAt ??= $director->timeline->endsAt();
        // Once laid, the jingle is not replaced by a second one for the same change.
        if ($endsAt !== null) {
            expect($director->timeline->endsAt())->toBe($endsAt);
        }
    }

    expect($endsAt)->not->toBeNull()->and($endsAt)->toBeGreaterThan($changeAt - 2.0);
});

it('gives no track-change jingle when one came recently or a slot is near', function () {
    $dir = sys_get_temp_dir().'/opencode/imaging-'.bin2hex(random_bytes(4));
    @mkdir($dir, 0775, true);
    file_put_contents($dir.'/sw1.opus', 'OggS');
    file_put_contents($dir.'/manifest.json', json_encode(['items' => [['id' => 'sw1', 'line' => 'Twenty One Esports Radio', 'category' => 'sweeper', 'file' => 'sw1.opus', 'seconds' => 3.2]]]));
    $disabled = new BroadcastClock([], 'Europe/Berlin', false);

    // The share off: never.
    expect((new Imaging(['dir' => $dir, 'music_change_share' => 0.0], 24000, fn (): float => 0.0, $disabled))->trackChange($this->now))->toBeNull();

    // One just came: within music_change_every_minutes.
    $imaging = new Imaging(['dir' => $dir, 'music_change_share' => 1.0, 'music_change_every_minutes' => 20], 24000, fn (): float => 0.0, $disabled);
    expect($imaging->trackChange($this->now))->not->toBeNull()
        ->and($imaging->trackChange($this->now->addMinutes(5)))->toBeNull();

    // Two minutes before the news slot: none.
    $this->travelTo(CarbonImmutable::parse('2026-10-17 14:58:30', 'Europe/Berlin'));
    expect((new Imaging(['dir' => $dir, 'music_change_share' => 1.0, 'music_change_every_minutes' => 20], 24000, fn (): float => 0.0))->trackChange(CarbonImmutable::now()))->toBeNull();
});

it('rides a track change at night with the short station id only', function () {
    $dir = sys_get_temp_dir().'/opencode/imaging-'.bin2hex(random_bytes(4));
    @mkdir($dir, 0775, true);
    foreach (['sw' => 'sweeper', 'st' => 'station-short'] as $id => $category) {
        file_put_contents($dir.'/'.$id.'.opus', 'OggS');
    }
    file_put_contents($dir.'/manifest.json', json_encode(['items' => [
        ['id' => 'sw', 'line' => 'You are tuned to Twenty One Esports Radio', 'category' => 'sweeper', 'file' => 'sw.opus', 'seconds' => 3.2],
        ['id' => 'st', 'line' => 'Twenty One Esports Radio', 'category' => 'station-short', 'file' => 'st.opus', 'seconds' => 2.1],
    ]]));
    $disabled = new BroadcastClock([], 'Europe/Berlin', false);

    // By day the sweeper is the first choice.
    $imaging = new Imaging(['dir' => $dir, 'music_change_share' => 1.0, 'music_change_night_share' => 1.0, 'music_change_every_minutes' => 20], 24000, fn (): float => 0.0, $disabled);
    expect($imaging->trackChange($this->now))->not->toBeNull()->category->toBe('sweeper');

    // In the quiet hours (the calm stretch) only the short station ID.
    config(['tv.commentary.spool.quiet_hours.from' => '01:00', 'tv.commentary.spool.quiet_hours.to' => '09:00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-18 03:00:00', 'Europe/Berlin'));
    $night = new Imaging(['dir' => $dir, 'music_change_share' => 0.0, 'music_change_night_share' => 1.0, 'music_change_every_minutes' => 20], 24000, fn (): float => 0.0, $disabled);
    expect($night->trackChange(CarbonImmutable::now()))->not->toBeNull()->category->toBe('station-short');
});

it('reads the artist\'s last note relay by relay, newest wins, and a nothing is cached', function () {
    $pubkey = MusicCatalog::artists()['ainsley-costello']['pubkey'];
    $note = fn (int $createdAt, string $text): array => ['id' => str_repeat('a', 64), 'pubkey' => $pubkey, 'created_at' => $createdAt, 'kind' => 1, 'tags' => [], 'content' => $text, 'sig' => str_repeat('b', 128)];

    $reader = new class extends RelayReader
    {
        /** @var array<string, list<SignedEvent>|null> */
        public array $answer = [];

        public int $calls = 0;

        public function readGuarded(array $filters, array $relays, int $perAuthor, float $until, array $known = []): array
        {
            $this->calls++;

            return $this->answer;
        }
    };

    $teasers = new MusicTeasers(app(DuetScripts::class), app(VoiceUsage::class), reader: $reader);
    $next = ['artist' => 'Ainsley Costello', 'npub' => 'npub13qrrw2h4z52m7jh0spefrwtysl4psfkfv6j4j672se5hkhvtyw7qu0almy'];

    // One relay answers an older note, one a newer one (the winner), one failed (null): per-relay lists, not one list.
    $reader->answer = [
        'wss://one' => [SignedEvent::fromInput($note($this->now->getTimestamp() - 7200, 'Älterer Post vom Vortag, der nicht gewinnen darf.'))],
        'wss://two' => [SignedEvent::fromInput($note($this->now->getTimestamp() - 3600, 'Neue Single ist fertig, Leute, ich sage es euch.'))],
        'wss://three' => null,
    ];

    $read = $teasers->lastNote($next, $this->now);
    expect($read)->not->toBeNull()
        ->and($read['text'])->toBe('Neue Single ist fertig, Leute, ich sage es euch.')
        ->and($read['ago'])->toBe('eine Stunde')
        ->and($reader->calls)->toBe(1);

    // Cached: the second ask does not go to the relays again.
    expect($teasers->lastNote($next, $this->now))->toBe($read)
        ->and($reader->calls)->toBe(1);

    // Eleven minutes ago speaks as a count, not a clock face (no warning, no digit).
    $reader->answer = ['wss://one' => [SignedEvent::fromInput($note($this->now->getTimestamp() - 660, 'Elf Minuten Test.'))], 'wss://two' => null];
    Cache::forget('caster:music_teaser:note:'.substr(sha1(MusicCatalog::artists()['ainsley-costello']['pubkey']), 0, 12));
    $eleven = $teasers->lastNote($next, $this->now);
    expect($eleven)->not->toBeNull()->and($eleven['ago'])->toBe('elf Minuten');

    // The answering path asks no relay: without a cached note there is none, and no call either.
    Cache::forget('caster:music_teaser:note:'.substr(sha1(MusicCatalog::artists()['ainsley-costello']['pubkey']), 0, 12));
    $reader->calls = 0;
    expect($teasers->lastNote($next, $this->now, fetch: false))->toBeNull()
        ->and($reader->calls)->toBe(0);

    // A nothing (every relay failed or empty) is cached the same way.
    $reader->answer = ['wss://one' => null];
    $reader->calls = 0;
    $pubkeyTwo = MusicCatalog::artists()['arthur-yoria']['pubkey'];
    $second = ['artist' => 'Arthur Yoria', 'npub' => 'npub1tsswp93g2v7ukcmfy935j4c67kctu76vjdzu0w9yqrdcygc9j3xq9v2eef'];
    Cache::put('caster:music_teaser:note:'.substr(sha1($pubkeyTwo), 0, 12), false, now()->addMinutes(360));
    expect($teasers->lastNote($second, $this->now))->toBeNull()
        ->and($reader->calls)->toBe(0);
});

it('writes no music announcement while the autopilot runs: nobody could voice it', function () {
    VoiceAgent::fake(fn (): array => ['text' => 'Gleich in der Musik: Ainsley Costello.']);
    config(['tv.commentary.blocks.enabled' => true, 'tv.commentary.blocks.stock.enabled' => true, 'tv.commentary.blocks.stock.library.enabled' => true, 'tv.commentary.blocks.autopilot.enabled' => true]);
    Cache::put(ClipSpool::FETCHER_SEEN, $this->now->getTimestamp(), 3600);
    Cache::put(AutoPilot::VOICE_DOWN, $this->now->getTimestamp(), 3600);

    $this->artisan('caster:music-teaser')->assertSuccessful();

    expect(CasterScript::query()->where('kind', CasterScript::MUSIC_TEASER)->count())->toBe(0);
    VoiceAgent::assertNeverPrompted();
});
