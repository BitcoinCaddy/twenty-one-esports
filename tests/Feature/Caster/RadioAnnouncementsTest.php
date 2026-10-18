<?php

use App\Models\CasterClip;
use App\Models\CasterNews;
use App\Models\CasterScript;
use App\Models\ChatterLine;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\CommentaryTimeline;
use App\Support\Caster\NewsFeeds;
use App\Support\Caster\Programme\Announcements;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\NewsBulletin;
use App\Support\Caster\Programme\Rubrics;
use App\Support\Caster\Programme\Rundown;
use App\Support\Caster\SceneDirector;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\Caster\WorldMemory;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio: programme announcements (plan "TWENTY ONE Radio", P5)
|--------------------------------------------------------------------------
|
| "Gleich nach den Nachrichten …", "Um Viertel nach gibt's die
| Liga-Rubrik …", "Zur vollen Stunde die Nachrichten": written by the
| model from the rundown as data, and only a line that names a slot that
| really follows, at the time it says, goes on air. Before a slot (within
| five minutes, at a clip boundary) and at the end of the :30 bulletin.
|
*/

beforeEach(function () {
    Storage::fake('local');
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.ai' => true,
        'tv.commentary.news.enabled' => true,
        'tv.commentary.programme.enabled' => true,
        'tv.commentary.programme.rubrics.enabled' => true,
        // The rotation alone; the long formats on their scheduled slots are RadioShowsTest's.
        'tv.commentary.programme.shows.enabled' => false,
        'tv.commentary.programme.rubrics.rotation' => ['geschichte', 'chat'],
        'tv.commentary.programme.rubrics.sirius_chance' => 0.0,
        'tv.commentary.programme.teasers.enabled' => true,
        'tv.commentary.programme.bulletin.sirius_chance' => 0.0,
        'tv.commentary.programme.bulletin.league' => false,
        'tv.commentary.repeat_hours' => 0,
        'tv.commentary.spool.quiet_hours.from' => '00:00',
        'tv.commentary.spool.quiet_hours.to' => '00:00',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 14:52:00', 'Europe/Berlin'));
    WorldMemory::forget();
    // A chat question, so the Chat-Stunde has material and the two rubric slots differ.
    ChatterLine::query()->create(['pubkey' => str_repeat('a', 64), 'name' => 'Ada', 'message' => 'Wann startet eigentlich der nächste Schach-Cup hier?', 'said_at' => now()->subMinutes(20)->getTimestamp()]);
});

/** The name of the rubric planned at `$time` (Berlin). */
function announcePlanned(string $time): string
{
    $slot = BroadcastClock::fromConfig()->slotAt(CarbonImmutable::parse($time, 'Europe/Berlin'));

    return Rubrics::CATALOGUE[app(Rubrics::class)->plannedFor($slot, CarbonImmutable::now())]['name'];
}

it('accepts only an announcement that names a slot that really follows, at the time it says', function () {
    $entries = app(Announcements::class)->entries(CarbonImmutable::now());
    $first = announcePlanned('2026-10-12 15:15:00');
    $second = announcePlanned('2026-10-12 15:45:00');
    $other = array_values(array_diff(array_column(Rubrics::CATALOGUE, 'name'), [$first, $second]))[0];

    expect(array_column($entries, 'minute'))->toBe([0, 15, 30, 45])->and($first)->not->toBe($second);

    $cases = [
        ['Zur vollen Stunde kommen die Nachrichten mit allem, was heute wirklich wichtig ist.', 0, true],
        ["Um Viertel nach gibt es {$first} mit allem, was ihr dazu wissen müsst.", 15, true],
        ["Gleich nach den Nachrichten kommt {$first} mit allem, was ihr wissen müsst.", 15, true],
        ['Um fünfzehn Uhr kommen die Nachrichten mit allem Wichtigen des Tages.', 0, true],
        ["Um fünfzehn Uhr fünfzehn kommt {$first} mit allem Wichtigen von heute.", 15, true],
        ["Um Viertel vor gibt es {$second}, und das solltet ihr nicht verpassen.", 45, true],
        // The wrong time, a rubric that is not planned, a slot that does not exist.
        ["Um halb kommt {$first} mit allem, was ihr wissen müsst.", 15, false],
        ["Um Viertel nach gibt es {$other} mit allem, was ihr wissen müsst.", 15, false],
        ["Um Viertel nach gibt es {$first}, und danach auch noch {$other}.", 15, false],
        ["Um zwanzig nach gibt es {$first} mit allem Wichtigen.", 20, false],
        // "nach den Nachrichten" for a slot that follows a rubric; "gleich" for a slot fifty minutes away.
        ['Gleich nach den Nachrichten kommen die Kurznachrichten mit drei Meldungen.', 30, false],
        ["Gleich kommt {$second} mit allem Wichtigen des Tages.", 45, false],
        // A digit, a wrong hour, minutes the line cannot know.
        ['Um 15 Uhr kommen die Nachrichten mit allem Wichtigen des Tages.', 0, false],
        ['Um sechzehn Uhr kommen die Nachrichten mit allem Wichtigen des Tages.', 0, false],
        ['In acht Minuten kommen die Nachrichten mit allem Wichtigen des Tages.', 0, false],
        // The line does not name its slot at all.
        ['Um Viertel nach geht es hier spannend weiter, also bleibt unbedingt dran.', 15, false],
    ];

    foreach ($cases as [$line, $minute, $true]) {
        expect(Announcements::verify($line, $minute, $entries))->toBe($true, $line);
    }

    // Minutes away are fine where the time of airing is known, within a minute.
    expect(Announcements::verify('In acht Minuten kommen die Nachrichten mit allem Wichtigen des Tages.', 0, $entries, exactTime: true))->toBeTrue()
        ->and(Announcements::verify('In zwanzig Minuten kommen die Nachrichten mit allem Wichtigen des Tages.', 0, $entries, exactTime: true))->toBeFalse();
});

/** The minute a clock phrase claims, independent of Announcements; null without one. */
function announceClaimedMinute(string $line): ?int
{
    return match (true) {
        (bool) preg_match('/vollen?\s+stunde/iu', $line) => 0,
        (bool) preg_match('/viertel\s+nach/iu', $line) => 15,
        (bool) preg_match('/(?<!\p{L})halb(?!\p{L})/iu', $line) => 30,
        (bool) preg_match('/viertel\s+vor/iu', $line) => 45,
        default => null,
    };
}

/** A teaser answer: half the time a true first line for the first entry, else a random one; a random second line. */
function announceTeaserAnswer(string $prompt): array
{
    preg_match_all('/^\[(\d+)\] um [^:]+: (die [^(]+?) \(/mu', $prompt, $entries, PREG_SET_ORDER);
    $names = [...array_column(Rubrics::CATALOGUE, 'name'), 'die Nachrichten', 'die Kurznachrichten'];
    $phrases = [0 => 'Zur vollen Stunde', 15 => 'Um Viertel nach', 30 => 'Um halb', 45 => 'Um Viertel vor'];
    $random = function () use ($names, $phrases): string {
        $minute = [0, 15, 20, 30, 45][mt_rand(0, 4)];
        $phrase = [...array_values($phrases), 'Gleich', 'Gleich nach den Nachrichten'][mt_rand(0, 5)];

        return "[{$minute}] {$phrase} gibt es ".$names[mt_rand(0, count($names) - 1)].' mit allem, was ihr heute wissen müsst.';
    };

    $first = isset($entries[0]) && mt_rand(0, 1) === 1
        ? "[{$entries[0][1]}] ".($phrases[(int) $entries[0][1]] ?? 'Gleich').' gibt es '.$entries[0][2].' mit allem, was ihr heute wissen müsst.'
        : $random();

    return ['text' => $first."\n".$random(), 'canon_id' => null, 'mood' => 'fakten'];
}

/** Twelve distinct long sentences for a rubric draft. */
function announceRubricAnswer(): array
{
    $lines = array_map(fn (int $n): string => 'Markus: '.['Heute', 'Gestern', 'Morgen', 'Vorhin', 'Später', 'Damals', 'Neulich', 'Bald', 'Jetzt', 'Nachher', 'Früher', 'Übermorgen'][$n]
        .' erzähle ich euch ausführlich eine Geschichte über Bauern, Steuern und die ewige Gier der Obrigkeit nach fremdem Geld.', range(0, 11));

    return ['text' => implode("\n", $lines), 'canon_id' => null, 'mood' => 'fakten'];
}

it('puts on air only true lines of a teaser that passes, and a twice false one only as its counted second draft: three simulated hours', function () {
    mt_srand(21);
    VoiceAgent::fake(fn (AgentPrompt|string $prompt): array => str_contains(is_string($prompt) ? $prompt : $prompt->prompt, 'Programmansage')
        ? announceTeaserAnswer(is_string($prompt) ? $prompt : $prompt->prompt)
        : announceRubricAnswer());
    $start = CarbonImmutable::parse('2026-10-12 14:00:00', 'Europe/Berlin');
    $queue = app(ScriptQueue::class);

    for ($t = $start; $t->lessThan($start->addHours(3)); $t = $t->addMinute()) {
        $this->travelTo($t);
        $queue->fillRubric(CarbonImmutable::now());
        $queue->fillTeaser(CarbonImmutable::now());
    }

    $teasers = CasterScript::query()->where('kind', CasterScript::TEASER)->get();
    $rubrics = CasterScript::query()->whole()->where('kind', CasterScript::RUBRIC)->get()
        ->mapWithKeys(fn (CasterScript $script): array => [$script->airs_at->getTimestamp() => $script->topic]);
    [$lines, $true, $secondDrafts] = [0, 0, 0];

    expect($teasers->count())->toBeGreaterThanOrEqual(5);

    foreach ($teasers as $teaser) {
        // Every slot that follows the moment it may air, by the clock alone.
        $slots = (new Rundown(BroadcastClock::fromConfig()))->next(CarbonImmutable::parse($teaser->airs_at));
        $truthful = function (string $line) use ($slots, $rubrics, $teaser): bool {
            $named = array_filter($slots, function (array $slot) use ($line, $rubrics): bool {
                $name = match ($slot['type']) {
                    BroadcastClock::NEWS => '/(?<!kurz)(?<!kurz-)nachrichten/iu',
                    BroadcastClock::HEADLINES => '/kurz[\s-]*nachrichten/iu',
                    BroadcastClock::RUBRIC => isset($rubrics[$slot['starts_at']->getTimestamp()]) ? Rubrics::CATALOGUE[$rubrics[$slot['starts_at']->getTimestamp()]]['pattern'] : null,
                    default => null,
                };

                return $name !== null && preg_match($name, $line) === 1;
            });

            return $named !== [] && $teaser->airs_at->getTimestamp() < collect($named)->min(fn (array $slot): int => $slot['starts_at']->getTimestamp());
        };

        // A teaser false in both drafts airs its second one as written (user 2026-10-18: "Nie mehr verwerfen").
        if (array_filter($teaser->sentences(), fn (string $line): bool => ! $truthful($line)) !== []) {
            $secondDrafts++;

            continue;
        }

        $true++;

        foreach ($teaser->sentences() as $line) {
            $lines++;
            $named = [];

            foreach ($slots as $slot) {
                $name = match ($slot['type']) {
                    BroadcastClock::NEWS => '/(?<!kurz)(?<!kurz-)nachrichten/iu',
                    BroadcastClock::HEADLINES => '/kurz[\s-]*nachrichten/iu',
                    BroadcastClock::RUBRIC => isset($rubrics[$slot['starts_at']->getTimestamp()]) ? Rubrics::CATALOGUE[$rubrics[$slot['starts_at']->getTimestamp()]]['pattern'] : null,
                    default => null,
                };

                if ($name !== null && preg_match($name, $line) === 1) {
                    $named[] = $slot;
                }
            }

            // It names a slot that follows, and the rubric it names is the one that really airs there.
            expect($named)->not->toBe([], $line);
            foreach (Rubrics::CATALOGUE as $id => $rubric) {
                if (preg_match($rubric['pattern'], $line) === 1) {
                    expect(collect($named)->contains(fn (array $slot): bool => ($rubrics[$slot['starts_at']->getTimestamp()] ?? null) === $id))->toBeTrue($line);
                }
            }

            $claimed = announceClaimedMinute($line);
            expect($claimed === null || in_array($claimed, array_column($named, 'minute'), true))->toBeTrue($line)
                ->and($teaser->airs_at->getTimestamp())->toBeLessThan(collect($named)->min(fn (array $slot): int => $slot['starts_at']->getTimestamp()));
        }
    }

    // The fake wrote false lines too: in a teaser that passed they never aired, and a second draft that aired anyway
    // was turned down twice and counted both times.
    expect($true)->toBeGreaterThanOrEqual(5)
        ->and($lines)->toBeGreaterThanOrEqual($true)
        ->and(2 * $secondDrafts)->toBeLessThanOrEqual((int) DB::table('voice_rejections')->where('channel', Announcements::CHANNEL)->where('reason', 'teaser_untrue')->value('count'));
});

it('writes the teaser once, plays it within five minutes of its slot and lets it expire just before', function () {
    VoiceAgent::fake(fn (): array => ['text' => '[0] Zur vollen Stunde kommen die Nachrichten mit allem, was heute wirklich wichtig ist.', 'canon_id' => null, 'mood' => 'fakten']);
    $queue = app(ScriptQueue::class);

    $this->travelTo(CarbonImmutable::parse('2026-10-12 14:53:00', 'Europe/Berlin'));
    expect($queue->fillTeaser(CarbonImmutable::now()))->toBe(1)
        ->and($queue->fillTeaser(CarbonImmutable::now()))->toBe(0);

    $teaser = CasterScript::query()->where('kind', CasterScript::TEASER)->sole();
    expect($teaser->airs_at->setTimezone('Europe/Berlin')->format('H:i:s'))->toBe('14:55:00')
        ->and($teaser->expires_at->setTimezone('Europe/Berlin')->format('H:i:s'))->toBe('14:59:45')
        ->and($teaser->priority)->toBe(72)
        ->and($teaser->sentences())->toBe(['Zur vollen Stunde kommen die Nachrichten mit allem, was heute wirklich wichtig ist.'])
        // Not counted as airtime ahead.
        ->and($queue->airtimeAhead())->toBe(0.0);

    // Less than a minute before the next slot: no teaser for it any more.
    $this->travelTo(CarbonImmutable::parse('2026-10-12 15:14:30', 'Europe/Berlin'));
    expect($queue->fillTeaser(CarbonImmutable::now()))->toBe(0);
});

it('airs the second draft of a teaser that announces something that does not follow, counted as untrue', function () {
    VoiceAgent::fake(fn (): array => ['text' => '[15] Um Viertel nach gibt es die Podcast-Ecke mit allem, was ihr wissen müsst.', 'canon_id' => null, 'mood' => 'fakten']);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 15:08:00', 'Europe/Berlin'));

    // Nothing a writer produced is dropped any more (user 2026-10-18: "Nie mehr verwerfen"), an untrue teaser included.
    expect(app(ScriptQueue::class)->fillTeaser(CarbonImmutable::now()))->toBe(1)
        ->and(CasterScript::query()->where('kind', CasterScript::TEASER)->sole()->sentences())->toBe(['Um Viertel nach gibt es die Podcast-Ecke mit allem, was ihr wissen müsst.'])
        ->and(DB::table('voice_rejections')->where('channel', Announcements::CHANNEL)->where('reason', 'teaser_untrue')->value('count'))->toBe(2);
    VoiceAgent::assertPromptedTimes(2);
});

/** A ready clip of `$kind` airing from `$airsAt`, `$seconds` long. */
function announceClip(string $kind, int $seconds, ?CarbonImmutable $airsAt = null, ?CarbonImmutable $expiresAt = null): CasterClip
{
    $script = casterScript($kind);
    $script->update(['airs_at' => $airsAt?->utc(), 'expires_at' => ($expiresAt ?? CarbonImmutable::now()->addMinutes(10))->utc()]);

    return app(ClipSpool::class)->store($script->fresh(), casterOpus(1000 * $seconds), (float) $seconds, range(5.0, (float) $seconds, 5.0), CarbonImmutable::now());
}

it('plays a teaser at the first clip boundary once it is due, never before', function () {
    Process::fake(['*' => fn (PendingProcess $process) => Process::result(output: str_repeat("\1\0", (int) (24000 * strlen((string) $process->input) / 1000)))]);
    $start = CarbonImmutable::parse('2026-10-12 14:54:30', 'Europe/Berlin');
    $this->travelTo($start);
    $teaser = announceClip(CasterScript::TEASER, 10, $start->addSeconds(30), $start->addSeconds(315));
    $scene = announceClip(CasterScript::SCENE, 15);
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $director = new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000), [
        'min_scene_seconds' => 20, 'silent_min_scene_seconds' => 15, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100,
        'gap_seconds' => ['min' => 25, 'max' => 25],
    ], clock: BroadcastClock::fromConfig());
    [$teaserAt, $sceneEnd] = [null, null];

    for ($t = (float) $start->getTimestamp(); $t <= $start->getTimestamp() + 90; $t += 0.25) {
        $this->travelTo(CarbonImmutable::createFromTimestamp((int) $t));
        $director->tick($planner, $planner->at($t, []), $t);

        if ($director->clip()?->id === $scene->id) {
            $sceneEnd = $t;
        }
        if ($director->clip()?->id === $teaser->id && $teaserAt === null) {
            $teaserAt = $t;
        }
    }

    // The scene ended before the teaser fell due: the teaser waits for its moment, then cuts the music gap short.
    expect($teaserAt)->not->toBeNull()
        ->and($sceneEnd)->toBeLessThan((float) $start->addSeconds(30)->getTimestamp())
        ->and($teaserAt)->toBeGreaterThanOrEqual((float) $start->addSeconds(30)->getTimestamp())
        ->and($teaserAt - $start->addSeconds(30)->getTimestamp())->toBeLessThanOrEqual(1.0);
});

it('ends the :30 bulletin with a preview of the hour that names only what follows', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 14:24:00', 'Europe/Berlin'));
    foreach ([['Bundestag beschließt Reform der Grundsteuer für Eigentümer', 'tagesschau', CasterNews::GERMANY], ['Miner verlagern Rechenleistung nach Skandinavien', 'BTC-Echo', CasterNews::BITCOIN],
        ['Koalition legt Entwurf für Pflegereform vor', 'Spiegel', CasterNews::GERMANY], ['Wallet-Anbieter schließt Sicherheitslücke nach Hinweis', 'Blocktrainer', CasterNews::BITCOIN],
        ['Bahn kündigt Sanierung wichtiger Strecken im Westen an', 'Welt', CasterNews::GERMANY]] as [$title, $source, $section]) {
        CasterNews::query()->create(['hash' => NewsFeeds::hash($title), 'section' => $section, 'feed' => 'test', 'source' => $source, 'language' => 'de', 'title' => $title, 'link' => null, 'published_at' => now()->subMinutes(20)]);
    }
    $rubric = announcePlanned('2026-10-12 14:45:00');
    $other = array_values(array_diff(array_column(Rubrics::CATALOGUE, 'name'), [$rubric]))[0];

    VoiceAgent::fake(function (string $prompt) use ($rubric, $other): array {
        preg_match_all('/^(\d+)\. Rubrik: [^|]+\| Quelle: ([^|]+) \| Meldung: ([^|\n]+)/mu', $prompt, $items, PREG_SET_ORDER);
        $lines = array_map(fn (array $item): string => "{$item[1]}: Wie ".trim($item[2]).' heute berichtet, lautet die aktuelle Meldung so: '.trim($item[3]).'.', $items);
        $lines[] = 'Kommentar: Ich finde ja, dass die Regierung schon wieder viel zu lange berät und dabei am Ende gar nichts herauskommt.';
        $lines[] = 'Vorschau:';
        $lines[] = "[45] Um Viertel vor kommt {$rubric}, und das wird richtig gut, versprochen.";
        $lines[] = "[45] Um Viertel vor kommt außerdem noch {$other} mit allem Wichtigen.";

        return ['text' => implode("\n", $lines), 'canon_id' => null, 'mood' => 'fakten'];
    });

    $slot = BroadcastClock::fromConfig()->nextBulletin(CarbonImmutable::now());
    $bulletin = app(NewsBulletin::class)->write($slot, CarbonImmutable::now());
    $lines = ScriptGuard::lines($bulletin['text']);

    expect($slot['type'])->toBe(BroadcastClock::HEADLINES)
        ->and(end($lines))->toBe("Um Viertel vor kommt {$rubric}, und das wird richtig gut, versprochen.")
        ->and($bulletin['text'])->not->toContain($other);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Programmplan der nächsten Stunde') && str_contains($prompt->prompt, $rubric));
});
