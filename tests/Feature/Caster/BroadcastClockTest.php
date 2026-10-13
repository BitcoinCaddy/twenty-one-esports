<?php

use App\Models\CasterClip;
use App\Models\CasterNews;
use App\Models\CasterScript;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\CommentaryTimeline;
use App\Support\Caster\NewsFeeds;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\Rundown;
use App\Support\Caster\SceneDirector;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptQueue;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio: the broadcast clock (plan "TWENTY ONE Radio", P1)
|--------------------------------------------------------------------------
|
| An hour of fixed slots in Berlin wall-clock time: news at :00, headlines
| at :30, talk and rubrics between them; at night only the full-hour news.
| The bulletin is written ahead, voiced ahead and waits in the spool for
| its slot; the director plays it at the slot, before anything else, and
| nothing cuts into it.
|
*/

beforeEach(function () {
    Storage::fake('local');
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.ai' => true,
        'tv.commentary.news.enabled' => true,
        'tv.commentary.programme.enabled' => true,
        'tv.commentary.spool.filler_below_seconds' => 10,
        'tv.commentary.programme.bulletin.sirius_chance' => 0.0,
        'tv.commentary.programme.bulletin.league' => false,
    ]);
});

/** The configured clock with the quiet hours off (an empty window). */
function radioClockAwake(): BroadcastClock
{
    config(['tv.commentary.spool.quiet_hours.from' => '00:00', 'tv.commentary.spool.quiet_hours.to' => '00:00']);

    return BroadcastClock::fromConfig();
}

/** The type the default programme puts on a minute of the hour. */
function radioExpectedType(int $minute): string
{
    return match (true) {
        $minute < 3 => 'news',
        $minute < 15 => 'talk',
        $minute < 20 => 'rubric',
        $minute < 30 => 'talk',
        $minute < 33 => 'headlines',
        $minute < 45 => 'talk',
        $minute < 50 => 'rubric',
        default => 'talk',
    };
}

it('picks the expected slot for every minute of a test hour, in Berlin time', function () {
    $clock = radioClockAwake();
    $start = CarbonImmutable::parse('2026-10-12 14:00:00', 'Europe/Berlin');

    foreach (range(0, 59) as $minute) {
        $at = $start->addMinutes($minute)->addSeconds(17);
        $slot = $clock->slotAt($at);
        $slotMinute = [0 => 0, 3 => 3, 15 => 15, 20 => 20, 30 => 30, 33 => 33, 45 => 45, 50 => 50];
        $expectedStart = max(array_filter(array_keys($slotMinute), fn (int $m): bool => $m <= $minute));

        expect($slot['type'])->toBe(radioExpectedType($minute), "minute {$minute}")
            ->and($slot['starts_at']->getTimestamp())->toBe($start->addMinutes($expectedStart)->getTimestamp(), "start at minute {$minute}");
    }

    expect($clock->slotAt($start)['stinger'])->toBeTrue()
        ->and($clock->slotAt($start->addMinutes(30))['stinger'])->toBeTrue()
        ->and($clock->slotAt($start->addMinutes(5))['stinger'])->toBeFalse();
});

it('keeps the slots on the local hour through the switch to winter time, the hour from two to three running twice', function () {
    $clock = radioClockAwake();
    // 2026-10-25 00:00 UTC is 02:00 CEST, 01:00 UTC is 02:00 CET again.
    $start = CarbonImmutable::parse('2026-10-25 00:00:00', 'UTC');
    $news = [];

    foreach (range(0, 119) as $minute) {
        $at = $start->addMinutes($minute);
        $slot = $clock->slotAt($at);

        expect($slot['type'])->toBe(radioExpectedType($minute % 60), "UTC minute {$minute}");

        if ($slot['type'] === 'news') {
            $news[$slot['key']] = $clock->spokenTime($slot['starts_at']);
        }
    }

    expect(array_keys($news))->toBe([$start->getTimestamp(), $start->addHour()->getTimestamp()])
        ->and(array_values($news))->toBe(['zwei Uhr', 'zwei Uhr']);
});

it('skips the lost hour of the switch to summer time and names the next slot by the new time', function () {
    $clock = radioClockAwake();
    // 2026-03-29 00:30 UTC is 01:30 CET; 01:00 UTC is 03:00 CEST (02:00 to 03:00 never happens).
    $from = CarbonImmutable::parse('2026-03-29 00:31:00', 'UTC');
    $next = $clock->nextBulletin($from);

    expect($next['type'])->toBe('news')
        ->and($next['starts_at']->toIso8601String())->toBe('2026-03-29T01:00:00+00:00')
        ->and($clock->spokenTime($next['starts_at']))->toBe('drei Uhr');

    foreach (range(0, 59) as $minute) {
        expect($clock->slotAt($from->setTime(1, $minute))['type'])->toBe(radioExpectedType($minute), "UTC 01:{$minute}");
    }
});

it('plays only the full-hour news in the quiet hours', function () {
    config(['tv.commentary.spool.quiet_hours.from' => '01:00', 'tv.commentary.spool.quiet_hours.to' => '09:00']);
    $clock = BroadcastClock::fromConfig();
    $night = CarbonImmutable::parse('2026-10-12 03:05:00', 'Europe/Berlin');

    expect($clock->slotAt($night->setTime(3, 31))['type'])->toBe('talk')
        ->and($clock->slotAt($night->setTime(3, 16))['type'])->toBe('talk')
        ->and($clock->slotAt($night->setTime(3, 1))['type'])->toBe('news')
        ->and($clock->nextBulletin($night)['starts_at']->setTimezone('Europe/Berlin')->format('H:i'))->toBe('04:00')
        ->and(array_column(Rundown::fromConfig()->next($night), 'type'))->not->toContain('headlines')->not->toContain('rubric');
});

it('lists the next hour as a rundown, so a teaser names only a slot that really follows', function () {
    radioClockAwake();
    $now = CarbonImmutable::parse('2026-10-12 14:10:00', 'Europe/Berlin');
    $rundown = Rundown::fromConfig();
    $entries = $rundown->next($now);

    expect(array_map(fn (array $entry): string => $entry['starts_at']->setTimezone('Europe/Berlin')->format('H:i').' '.$entry['type'], $entries))
        ->toBe(['14:15 rubric', '14:20 talk', '14:30 headlines', '14:33 talk', '14:45 rubric', '14:50 talk', '15:00 news', '15:03 rubric'])
        ->and($rundown->nextOf('headlines', $now)['in_minutes'])->toBe(20)
        ->and($rundown->nextOf('headlines', $now)['spoken_time'])->toBe('vierzehn Uhr dreißig')
        ->and($rundown->nextOf('news', $now)['label'])->toBe('die Nachrichten')
        ->and($rundown->nextOf('nonsense', $now))->toBeNull();
});

/** Fresh headlines of six outlets in three sections (Deutschland, Bitcoin, Technik: the balance of NewsMix), enough for a few hours of bulletins. */
function radioHeadlines(int $perOutlet = 12): void
{
    $outlets = [['tagesschau', CasterNews::GERMANY], ['Spiegel', CasterNews::GERMANY], ['BTC-Echo', CasterNews::BITCOIN], ['Blocktrainer', CasterNews::BITCOIN], ['heise online', CasterNews::TECH], ['Golem', CasterNews::TECH]];
    foreach ($outlets as $o => [$source, $section]) {
        foreach (range(1, $perOutlet) as $i) {
            // Three words of its own per headline, so no two tell the same story (NewsBulletin::sameStory()).
            $word = fn (int $n): string => 'Thema'.chr(97 + $o).chr(97 + $i).chr(97 + $n);
            $title = "Neues zu {$word(0)} und {$word(1)} aus {$word(2)} am Abend";
            CasterNews::query()->create([
                'hash' => NewsFeeds::hash($title), 'section' => $section, 'feed' => 'test', 'source' => $source, 'language' => 'de',
                'title' => $title, 'link' => null, 'published_at' => CarbonImmutable::now()->subMinutes(10 + $i),
            ]);
        }
    }
}

/** A model answer that reads every item of the bulletin prompt factually, naming its source, then comments. */
function radioBulletinAnswer(AgentPrompt|string $prompt): array
{
    $text = is_string($prompt) ? $prompt : $prompt->prompt;
    preg_match_all('/^(\d+)\. Rubrik: [^|]+\| Quelle: ([^|]+) \| Meldung: (.+)$/mu', $text, $items, PREG_SET_ORDER);
    $lines = array_map(fn (array $item): string => "{$item[1]}: Wie ".trim($item[2]).' heute berichtet, lautet die aktuelle Meldung so: '.trim($item[3]).'.', $items);
    $lines[] = 'Kommentar: Ich finde ja, dass die Regierung schon wieder viel zu lange berät und dabei am Ende gar nichts herauskommt.';
    $lines[] = 'Und während die noch beraten, läuft die Liga hier einfach weiter, ganz ohne Ausschuss und ohne Formular.';
    $lines[] = 'Sirius: Beraten ist ja auch bequemer als Entscheiden, das weiß bei uns im Rheinland wirklich jedes Kind.';

    return ['text' => implode("\n", $lines), 'canon_id' => null, 'mood' => 'fakten'];
}

it('has every bulletin of three simulated hours in the spool at least sixty seconds before its slot', function () {
    radioClockAwake();
    $start = CarbonImmutable::parse('2026-10-12 13:50:00', 'Europe/Berlin');
    $this->travelTo($start);
    radioHeadlines();
    VoiceAgent::fake(fn (string $prompt): array => radioBulletinAnswer($prompt));
    $queue = app(ScriptQueue::class);
    $spool = app(ClipSpool::class);
    // A pessimistic workstation: it asks every 30 s and needs 150 s to voice a bulletin.
    $voicing = null;
    $ready = [];

    for ($t = $start; $t->lessThan($start->addHours(3)); $t = $t->addSeconds(30)) {
        $this->travelTo($t);

        if ($t->second === 0) {
            $queue->fillBulletin(CarbonImmutable::now());
        }

        if ($voicing !== null && $t->greaterThanOrEqualTo($voicing['done'])) {
            $clip = $spool->store($voicing['script'], casterOpus(), 100.0, [10.0, 100.0], CarbonImmutable::now());
            $ready[CasterScript::query()->find($clip->caster_script_id)->airs_at->getTimestamp()] = $t->getTimestamp();
            $voicing = null;
        }

        if ($voicing === null && ($script = $queue->claim(CarbonImmutable::now())) !== null) {
            $voicing = ['script' => $script, 'done' => $t->addSeconds(150)];
        }

        // Never on air before its slot.
        $due = $spool->bulletin(CarbonImmutable::now());
        expect($due === null || CasterScript::query()->find($due->caster_script_id)->airs_at->getTimestamp() <= $t->getTimestamp())->toBeTrue();
    }

    $slots = array_map(fn (int $i): int => $start->addMinutes(10 + 30 * $i)->getTimestamp(), range(0, 5));
    expect(array_keys($ready))->toBe($slots);

    foreach ($ready as $slot => $at) {
        expect($slot - $at)->toBeGreaterThanOrEqual(60);
    }

    $first = CasterScript::query()->where('kind', CasterScript::BULLETIN)->orderBy('id')->first();
    expect($first->sentences()[0])->toBe('Es ist vierzehn Uhr, hier sind die Nachrichten auf TWENTY ONE TV.')
        ->and(CasterScript::query()->where('kind', CasterScript::BULLETIN)->orderBy('id')->skip(1)->first()->sentences()[0])
        ->toBe('Es ist vierzehn Uhr dreißig, hier sind die Kurznachrichten auf TWENTY ONE TV.');
});

it('writes the bulletin once per slot and only within its writing window, also in the quiet hours', function () {
    config(['tv.commentary.spool.quiet_hours.from' => '01:00', 'tv.commentary.spool.quiet_hours.to' => '09:00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 03:45:00', 'Europe/Berlin'));
    radioHeadlines(3);
    VoiceAgent::fake(fn (string $prompt): array => radioBulletinAnswer($prompt));
    $queue = app(ScriptQueue::class);

    // 15 minutes ahead: too early; 07:00 ahead: written; again: not twice; the :30 in the night: never.
    expect($queue->fillBulletin(CarbonImmutable::now()))->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 03:53:00', 'Europe/Berlin'));
    expect($queue->fill(CarbonImmutable::now()))->toBeGreaterThanOrEqual(1)
        ->and($queue->fillBulletin(CarbonImmutable::now()))->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 04:23:00', 'Europe/Berlin'));
    expect($queue->fillBulletin(CarbonImmutable::now()))->toBe(0);

    $bulletin = CasterScript::query()->where('kind', CasterScript::BULLETIN)->sole();
    expect($bulletin->airs_at->setTimezone('Europe/Berlin')->format('H:i'))->toBe('04:00')
        ->and($bulletin->expires_at->getTimestamp() - $bulletin->airs_at->getTimestamp())->toBe(120)
        ->and($bulletin->priority)->toBe(75)
        ->and($bulletin->sentences()[0])->toBe('Es ist vier Uhr, hier sind die Nachrichten auf TWENTY ONE TV.');
});

it('hands out the bulletin to the workstation also while the spool is full', function () {
    $token = str_repeat('t', 40);
    config(['tv.commentary.fetch.token' => $token, 'tv.commentary.spool.target_seconds' => 1]);
    $scene = casterScript();
    app(ClipSpool::class)->store($scene, casterOpus(), 5.0, [5.0], CarbonImmutable::now());
    $bulletin = app(ScriptQueue::class)->enqueue(CasterScript::BULLETIN, ScenePhase::BREAK, 'Es ist vierzehn Uhr, hier sind die Nachrichten auf TWENTY ONE TV.', 'bulletin:1', 75, CarbonImmutable::now(),
        airsAt: CarbonImmutable::now()->addMinutes(5), expiresAt: CarbonImmutable::now()->addMinutes(7));

    $this->withToken($token)->postJson('/api/caster/claim')->assertOk()
        ->assertJsonPath('full', true)->assertJsonPath('script.id', $bulletin->id);
});

/** Decoded PCM per clip, by the byte length of its Opus file (casterOpus($bytes)): one second per thousand bytes. */
function radioFakeDecoding(): void
{
    Process::fake(['*' => fn (PendingProcess $process) => Process::result(output: str_repeat("\1\0", (int) (24000 * strlen((string) $process->input) / 1000)))]);
}

/** A ready clip of `$kind`, `$seconds` long (sentences every five seconds); a bulletin airing at `$airsAt`. */
function radioClip(string $kind, int $seconds, ?CarbonImmutable $airsAt = null): CasterClip
{
    $script = casterScript($kind);
    $script->update(['airs_at' => $airsAt?->utc(), 'expires_at' => ($airsAt ?? CarbonImmutable::now())->utc()->addMinutes(10)]);

    return app(ClipSpool::class)->store($script->fresh(), casterOpus(1000 * $seconds), (float) $seconds, range(5.0, (float) $seconds, 5.0), CarbonImmutable::now());
}

function radioDirector(float $stinger = 0.0): SceneDirector
{
    return new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000), [
        'min_scene_seconds' => 20, 'silent_min_scene_seconds' => 15, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100,
        'long_max_hold_seconds' => ['bulletin' => 250], 'gap_seconds' => ['min' => 25, 'max' => 25], 'stinger_seconds' => $stinger, 'bulletin_late_seconds' => 120,
    ], clock: radioClockAwake());
}

it('plays the bulletin at its slot: never before, a scene on air cut at the end of its sentence, a stinger cue first', function () {
    $slot = CarbonImmutable::parse('2026-10-12 14:00:00', 'Europe/Berlin');
    $this->travelTo($slot->subSeconds(20));
    radioFakeDecoding();
    $bulletin = radioClip(CasterScript::BULLETIN, 90, $slot);
    radioClip(CasterScript::SCENE, 60);
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $director = radioDirector(3.0);
    $startedAt = null;
    $stinger = null;

    for ($t = (float) $slot->subSeconds(20)->getTimestamp(); $t <= $slot->getTimestamp() + 30; $t += 0.25) {
        $this->travelTo(CarbonImmutable::createFromTimestamp((int) $t));
        $director->tick($planner, $planner->at($t, []), $t);
        $stinger ??= $director->takeStinger();

        if ($director->clip()?->id === $bulletin->id && $startedAt === null) {
            $startedAt = $t;
            expect($director->bulletinOnAir())->toBe('news');
        }
    }

    // The scene started 20 s before the slot; its sentence on air ends at 20.5 + 5 s = 5.5 s after the slot.
    expect($startedAt)->not->toBeNull()
        ->and($startedAt - $slot->getTimestamp())->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(6.0)
        ->and($stinger['slot'])->toBe('news')
        ->and($stinger['voice_at'] - $stinger['at'])->toBeGreaterThanOrEqual(3.0);
});

it('cuts a long duet part on air across the hour for the bulletin and resumes its rest after the news (live 22:00, 2026-10-12)', function () {
    // Live: part one of a two-part ladder duet (34 s) started at 21:59:51; the bulletin waited for its end until 22:00:28.
    $slot = CarbonImmutable::parse('2026-10-12 22:00:00', 'Europe/Berlin');
    $this->travelTo($slot->subSeconds(9));
    config(['tv.commentary.parts.sentences' => 5, 'tv.commentary.repeat_hours' => 0]);
    $first = app(ScriptQueue::class)->enqueue(CasterScript::SCENE, ScenePhase::BREAK, implode("\n", array_map(fn (int $number): string => "Auf der Rapid Ladder steht Satz Nummer {$number}.", range(1, 10))), 'ladders:x', 50, CarbonImmutable::now());
    $second = CasterScript::query()->where('part_group', $first->part_group)->where('part_index', 2)->sole();
    radioFakeDecoding();
    $bulletin = radioClip(CasterScript::BULLETIN, 30, $slot);
    $part = app(ClipSpool::class)->store($first->refresh(), casterOpus(40_000), 40.0, range(5.0, 40.0, 5.0), CarbonImmutable::now());
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $director = radioDirector();
    $order = [];
    $last = null;

    for ($t = (float) $slot->subSeconds(9)->getTimestamp(); $t <= $slot->getTimestamp() + 120; $t += 0.25) {
        $this->travelTo(CarbonImmutable::createFromTimestamp((int) $t));
        if (abs($t - $slot->getTimestamp() - 10.0) < 0.01) {
            app(ClipSpool::class)->store($second->refresh(), casterOpus(10_000), 10.0, [5.0, 10.0], CarbonImmutable::now());
        }

        $director->tick($planner, $planner->at($t, []), $t);

        if ($director->clip()?->id !== $last) {
            $last = $director->clip()?->id;
            if ($last !== null) {
                $order[] = [$director->clip()->caster_script_id, $t - $slot->getTimestamp()];
            }
        }
    }

    // Part one started 9 s before the slot (sentences every 5 s): cut at its sentence end 1 s after the slot.
    expect(array_column($order, 0))->toBe([$first->id, $bulletin->caster_script_id, $first->id, $second->id])
        ->and($order[0][1])->toBe(-9.0)
        ->and($order[1][1])->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(6.0)
        ->and($order[2][1])->toBeGreaterThanOrEqual($order[1][1] + 30.0)
        ->and(CasterClip::query()->whereKey($part->id)->exists())->toBeFalse();
});

it('lets no reaction or takeover cut into a bulletin: the chat reply follows it', function () {
    $slot = CarbonImmutable::parse('2026-10-12 14:30:00', 'Europe/Berlin');
    $this->travelTo($slot);
    radioFakeDecoding();
    $bulletin = radioClip(CasterScript::BULLETIN, 40, $slot);
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $director = radioDirector();
    $order = [];
    $last = null;

    for ($t = (float) $slot->getTimestamp(); $t <= $slot->getTimestamp() + 60; $t += 0.25) {
        $this->travelTo(CarbonImmutable::createFromTimestamp((int) $t));
        if (abs($t - $slot->getTimestamp() - 5.0) < 0.01) {
            radioClip(CasterScript::CHAT, 10);
        }

        $director->tick($planner, $planner->at($t, []), $t);

        if ($director->clip()?->id !== $last) {
            $last = $director->clip()?->id;
            if ($last !== null) {
                $order[] = [$director->clip()->kind, $t - $slot->getTimestamp()];
            }
        }
    }

    expect(array_column($order, 0))->toBe([CasterScript::BULLETIN, CasterScript::CHAT])
        ->and($order[1][1])->toBeGreaterThanOrEqual(40.0)
        ->and(CasterClip::query()->whereKey($bulletin->id)->exists())->toBeFalse();
});

it('plays the night bulletin on the hour, over the quiet hours ten-minute rhythm', function () {
    $slot = CarbonImmutable::parse('2026-10-12 04:00:00', 'Europe/Berlin');
    $this->travelTo($slot->subSeconds(30));
    config(['tv.commentary.spool.quiet_hours.from' => '01:00', 'tv.commentary.spool.quiet_hours.to' => '09:00']);
    radioFakeDecoding();
    radioClip(CasterScript::SCENE, 10);
    $bulletin = radioClip(CasterScript::BULLETIN, 30, $slot);
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $director = new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000), [
        'min_scene_seconds' => 20, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100, 'bulletin_late_seconds' => 120,
    ], clock: BroadcastClock::fromConfig());
    $startedAt = null;

    for ($t = (float) $slot->subSeconds(30)->getTimestamp(); $t <= $slot->getTimestamp() + 20 && $startedAt === null; $t += 0.25) {
        $this->travelTo(CarbonImmutable::createFromTimestamp((int) $t));
        $director->tick($planner, $planner->at($t, []), $t);

        if ($director->clip()?->id === $bulletin->id) {
            $startedAt = $t - $slot->getTimestamp();
        }
    }

    // The scene ended at -19.5 s; the quiet rhythm would hold the next clip for ten minutes, the bulletin plays on the hour.
    expect($startedAt)->toBe(0.0);
});

it('drops a bulletin that missed its slot by more than late_seconds instead of naming a wrong time', function () {
    $slot = CarbonImmutable::parse('2026-10-12 15:00:00', 'Europe/Berlin');
    $this->travelTo($slot);
    radioFakeDecoding();
    radioClip(CasterScript::BULLETIN, 30, $slot->utc());
    CasterClip::query()->update(['expires_at' => $slot->utc()->addSeconds(120)]);

    expect(app(ClipSpool::class)->bulletin($slot->addSeconds(119)))->not->toBeNull()
        ->and(app(ClipSpool::class)->bulletin($slot->addSeconds(121)))->toBeNull()
        ->and(app(ClipSpool::class)->bulletin($slot->subSecond()))->toBeNull()
        ->and(app(ClipSpool::class)->next(ScenePhase::BREAK, $slot)['clip'])->toBeNull()
        ->and(app(ClipSpool::class)->readySeconds())->toBe(0.0);
});
