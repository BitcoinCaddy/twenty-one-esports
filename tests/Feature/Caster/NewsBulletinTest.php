<?php

use App\Models\CasterNews;
use App\Support\Caster\NewsFeeds;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\NewsBulletin;
use App\Support\Caster\ScriptGuard;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio: the news bulletin (plan "TWENTY ONE Radio", P2)
|--------------------------------------------------------------------------
|
| Four to six items at :00, three at :30, from at least three outlets,
| EINUNDZWANZIG News among the feeds; read factually (no opinion word, the
| outlet named, no digit or name-like word the headline does not have),
| then Markus' commentary clearly set apart. A headline is read in one
| bulletin only; thin news make a shorter bulletin, never filler.
|
*/

beforeEach(function () {
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.ai' => true,
        'tv.commentary.news.enabled' => true,
        'tv.commentary.programme.enabled' => true,
        'tv.commentary.programme.bulletin.sirius_chance' => 0.0,
        'tv.commentary.programme.bulletin.league' => false,
        'tv.commentary.spool.quiet_hours.from' => '00:00',
        'tv.commentary.spool.quiet_hours.to' => '00:00',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 13:55:00', 'Europe/Berlin'));
});

function bulletinHeadline(string $title, string $source, string $section, int $minutesAgo = 20): CasterNews
{
    return CasterNews::query()->create([
        'hash' => NewsFeeds::hash($title), 'section' => $section, 'feed' => 'test', 'source' => $source, 'language' => 'de',
        'title' => $title, 'link' => null, 'published_at' => CarbonImmutable::now()->subMinutes($minutesAgo),
    ]);
}

/** Two headlines per outlet, six outlets in three sections (Deutschland, Bitcoin, Technik), no two on the same story. */
function bulletinNewsDesk(): void
{
    bulletinHeadline('Bundestag beschließt Reform der Grundsteuer für Eigentümer', 'tagesschau', CasterNews::GERMANY, 10);
    bulletinHeadline('Länder streiten über Finanzierung neuer Schulgebäude', 'tagesschau', CasterNews::GERMANY, 40);
    bulletinHeadline('Koalition legt Entwurf für Pflegereform vor', 'Spiegel', CasterNews::GERMANY, 15);
    bulletinHeadline('Bahn kündigt Sanierung wichtiger Strecken im Westen an', 'Spiegel', CasterNews::GERMANY, 50);
    bulletinHeadline('Ledger-Hack durch offiziellen Distributor: Warum versiegelte Boxen keinen Schutz mehr bieten', 'EINUNDZWANZIG News', CasterNews::BITCOIN, 300);
    bulletinHeadline('Miner verlagern Rechenleistung nach Skandinavien', 'BTC-Echo', CasterNews::BITCOIN, 12);
    bulletinHeadline('Lightning-Zahlungen wachsen bei Händlern in Europa', 'BTC-Echo', CasterNews::BITCOIN, 45);
    bulletinHeadline('Wallet-Anbieter schließt Sicherheitslücke nach Hinweis', 'Blocktrainer', CasterNews::BITCOIN, 30);
    bulletinHeadline('Chatkontrolle: Messenger-Dienste drohen mit Rückzug aus Europa', 'heise online', CasterNews::TECH, 14);
    bulletinHeadline('Open-Source-Router-Firmware bekommt verschlüsseltes DNS', 'heise online', CasterNews::TECH, 35);
    bulletinHeadline('Neue Grafikkarten verbrauchen deutlich weniger Strom', 'Golem', CasterNews::TECH, 25);
    bulletinHeadline('Forscher knacken Passwortmanager über manipulierte Browser-Erweiterung', 'Golem', CasterNews::TECH, 55);
}

/** Items of the prompt: number, source, headline. @return list<array{0: string, 1: string, 2: string}> */
function bulletinPromptItems(string $prompt): array
{
    preg_match_all('/^(\d+)\. Rubrik: [^|]+\| Quelle: ([^|]+) \| Meldung: (.+)$/mu', $prompt, $items, PREG_SET_ORDER);

    return array_map(fn (array $item): array => [$item[1], trim($item[2]), trim($item[3])], $items);
}

/**
 * A model answer for the prompt: each item read factually with its source; `$spoil` replaces item n's text.
 *
 * @param  array<int, string>  $spoil
 */
function bulletinAnswer(string $prompt, array $spoil = [], string $comment = ''): array
{
    $lines = array_map(fn (array $item): string => "{$item[0]}: ".($spoil[(int) $item[0]] ?? 'Wie '.$item[1].' heute berichtet, lautet die aktuelle Meldung so: '.$item[2].'.'), bulletinPromptItems($prompt));
    $lines[] = 'Kommentar: '.($comment !== '' ? $comment : 'Ich finde das alles ziemlich absurd, weil der Staat mal wieder zuerst an sich selbst denkt und dann erst an die Leute.');
    $lines[] = 'Und genau deshalb bleibt Bitcoin für mich die ehrlichere Antwort auf diesen ganzen Zirkus aus Berlin und Brüssel.';
    $lines[] = 'Sirius: Der Zirkus aus Berlin hat wenigstens noch Clowns mit Pensionsanspruch, das muss man ihm wirklich lassen.';

    return ['text' => implode("\n", $lines), 'canon_id' => null, 'mood' => 'fakten'];
}

/** The bulletin slot at the next full or half hour from now. @return array<string, mixed> */
function bulletinSlot(): array
{
    return BroadcastClock::fromConfig()->nextBulletin(CarbonImmutable::now());
}

/** The factual lines of a bulletin text: after the time line, before the separator. @return list<string> */
function bulletinFactLines(string $text): array
{
    $lines = ScriptGuard::lines($text);
    $end = array_key_first(array_filter($lines, fn (string $line): bool => str_contains($line, 'Einordnung') || str_contains($line, 'was ich davon halte') || str_contains($line, 'mein Kommentar'))) ?? count($lines);

    return array_slice($lines, 1, $end - 1);
}

it('reads EINUNDZWANZIG News from its feed as a Bitcoin source', function () {
    expect(config('tv.commentary.news.feeds.einundzwanzig-news'))->toBe([
        'url' => 'https://media.einundzwanzig.space/s/einundzwanzig-news/feed.xml', 'source' => 'EINUNDZWANZIG News', 'section' => 'bitcoin', 'language' => 'de', 'fresh_hours' => 168,
    ]);

    config(['tv.commentary.news.feeds' => ['einundzwanzig-news' => config('tv.commentary.news.feeds.einundzwanzig-news')]]);
    Http::fake(['media.einundzwanzig.space/*' => Http::response((string) file_get_contents(base_path('tests/Fixtures/caster-news/einundzwanzig-news.xml')))]);

    $counts = app(NewsFeeds::class)->fetch(CarbonImmutable::now());
    $stored = CasterNews::query()->whereNull('skipped')->orderByDesc('published_at')->get();

    expect($counts['feeds'])->toBe(1)->and($counts['failed'])->toBe(0)
        ->and($stored->pluck('title')->all())->toBe([
            'Ledger-Hack durch offiziellen Distributor: Warum versiegelte Boxen keinen Schutz mehr bieten',
            'KI-Agenten wählen nicht, wie sie bezahlen. Unternehmen wählen, wie sie bezahlt werden wollen.',
            'Make Bitcoin and Nostr fun again',
        ])
        ->and($stored->pluck('source')->unique()->all())->toBe(['EINUNDZWANZIG News'])
        ->and($stored->pluck('section')->unique()->all())->toBe([CasterNews::BITCOIN])
        // Relative links (/s/einundzwanzig-news/…) are no links.
        ->and($stored->pluck('link')->unique()->all())->toBe([null]);
});

it('mixes six to eight items at the full hour from at least three outlets, EINUNDZWANZIG News among them', function () {
    bulletinNewsDesk();

    foreach (range(1, 5) as $run) {
        $items = app(NewsBulletin::class)->select(BroadcastClock::NEWS, CarbonImmutable::now());

        expect(count($items))->toBeGreaterThanOrEqual(6)->toBeLessThanOrEqual(8)
            ->and(count(array_unique(array_column($items, 'source'))))->toBeGreaterThanOrEqual(3)
            ->and(array_column($items, 'source'))->toContain('EINUNDZWANZIG News')
            ->and(array_unique(array_column($items, 'section')))->toEqualCanonicalizing([CasterNews::GERMANY, CasterNews::BITCOIN, CasterNews::TECH]);
    }

    $headlines = app(NewsBulletin::class)->select(BroadcastClock::HEADLINES, CarbonImmutable::now());
    expect(count($headlines))->toBeGreaterThanOrEqual(4)->toBeLessThanOrEqual(5)
        ->and(count(array_unique(array_column($headlines, 'source'))))->toBe(count($headlines))
        ->and($headlines[0]['source'])->toBe('EINUNDZWANZIG News');
});

it('takes a new outlet within a tier before an outlet again, and one tier per turn (real feeds 2026-10-12: Bitcoin had only BTC-Echo)', function () {
    config(['tv.commentary.programme.bulletin.items.headlines' => ['min' => 3, 'max' => 3]]);
    bulletinHeadline('Miner verlagern Rechenleistung nach Skandinavien', 'BTC-Echo', CasterNews::BITCOIN, 10);
    bulletinHeadline('Lightning-Zahlungen wachsen bei Händlern in Europa', 'BTC-Echo', CasterNews::BITCOIN, 11);
    bulletinHeadline('Chatkontrolle: Messenger-Dienste drohen mit Rückzug aus Europa', 'heise online', CasterNews::TECH, 14);
    bulletinHeadline('Bundestag beschließt Reform der Grundsteuer für Eigentümer', 'tagesschau', CasterNews::GERMANY, 12);
    bulletinHeadline('Koalition legt Entwurf für Pflegereform vor', 'Spiegel', CasterNews::GERMANY, 13);

    $headlines = app(NewsBulletin::class)->select(BroadcastClock::HEADLINES, CarbonImmutable::now());

    expect(array_column($headlines, 'source'))->toBe(['BTC-Echo', 'heise online', 'tagesschau']);
});

it('writes the bulletin: the time line, the items with their outlets, then the commentary clearly set apart', function () {
    bulletinNewsDesk();
    config(['tv.commentary.programme.bulletin.sirius_chance' => 1.0]);
    VoiceAgent::fake(fn (string $prompt): array => bulletinAnswer($prompt));

    $bulletin = app(NewsBulletin::class)->write(bulletinSlot(), CarbonImmutable::now());
    $lines = ScriptGuard::lines($bulletin['text']);
    $facts = bulletinFactLines($bulletin['text']);

    expect($lines[0])->toBe('Es ist vierzehn Uhr, hier sind die Nachrichten auf TWENTY ONE TV.')
        ->and(count($bulletin['items']))->toBeGreaterThanOrEqual(6)->toBeLessThanOrEqual(8)
        ->and($facts)->toHaveCount(count($bulletin['items']))
        ->and(count($lines))->toBe(1 + count($facts) + 1 + 2 + 1)
        ->and($lines[count($facts) + 1])->toBeIn([
            'Soweit die Meldungen, und jetzt kommt meine ganz persönliche Einordnung dazu.',
            'Das waren die Nachrichten, und jetzt sage ich euch ganz offen, was ich davon halte.',
            'So weit die Fakten, und jetzt folgt wie immer mein Kommentar zu dieser Lage.',
        ])
        ->and($bulletin['speakers'])->toBe([...array_fill(0, count($lines) - 1, 'markus'), 'sirius']);

    foreach ($facts as $line) {
        expect(NewsBulletin::opinionWords($line))->toBe([], $line)
            ->and(str_word_count($line))->toBeGreaterThanOrEqual(7);
    }

    // The commentary is opinion and may say so.
    expect(NewsBulletin::opinionWords(implode(' ', array_slice($lines, count($facts) + 2))))->not->toBe([]);
});

it('knows the words that judge, and leaves a neutral sentence and a word that only starts like one alone', function () {
    foreach ([
        'Die Regierung hat leider wieder die Steuern erhöht.',
        'Das ist ein Skandalurteil aus Karlsruhe.',
        'Die Reform ist absurd teuer geworden.',
        'Ein großartiger Tag für Bitcoin.',
        'Die Koalition streitet mal wieder über den Haushalt.',
        'Das ist eine tolle Nachricht für Sparer.',
        'Ich finde, die Zentralbank liegt falsch.',
    ] as $sentence) {
        expect(NewsBulletin::opinionWords($sentence))->not->toBe([], $sentence);
    }

    foreach ([
        'Der Bundestag hat am Donnerstag ein neues Gesetz zur Grundsteuer beschlossen.',
        'Die Superbowl findet in diesem Jahr in einer neuen Stadt statt.',
        'Die Tollwut breitet sich unter Füchsen im Süden aus.',
        'Das Gericht nannte die Werbung irreführend und untersagte sie.',
    ] as $sentence) {
        expect(NewsBulletin::opinionWords($sentence))->toBe([], $sentence);
    }

    // A word the outlet's headline has is the outlet's: retelling it is no opinion of ours.
    expect(NewsBulletin::opinionWords('Wie Spiegel berichtet, sorgt ein Skandal um Masken für Streit.', 'Skandal um Masken sorgt für Streit'))->toBe([]);
});

it('leaves out an item with an opinion word, an invented number or acronym, or without its outlet, and keeps the rest', function () {
    bulletinNewsDesk();
    config(['tv.commentary.programme.bulletin.items.news' => ['min' => 6, 'max' => 6]]);
    VoiceAgent::fake(fn (string $prompt): array => bulletinAnswer($prompt, [
        1 => 'Wie tagesschau berichtet, hat der Bundestag leider die Reform der Grundsteuer für Eigentümer beschlossen.',
        2 => 'Wie Spiegel berichtet, legt die Koalition einen Entwurf für die Pflegereform mit 40 Milliarden vor.',
        3 => 'Wie EINUNDZWANZIG News berichtet, warnt die EZB nach dem Ledger-Hack vor versiegelten Boxen.',
        4 => 'Die Miner verlagern nach dieser Meldung ihre Rechenleistung nach Skandinavien in den Norden.',
    ]));

    $bulletin = app(NewsBulletin::class)->write(bulletinSlot(), CarbonImmutable::now());
    $text = $bulletin['text'];

    expect($bulletin['items'])->toHaveCount(2)
        ->and($text)->not->toContain('leider')->not->toContain('40 Milliarden')->not->toContain('EZB')->not->toContain('Miner verlagern nach dieser');

    foreach (bulletinFactLines($text) as $line) {
        expect(NewsBulletin::opinionWords($line))->toBe([], $line)
            ->and($line)->toStartWith('Wie ');
    }

    expect(NewsBulletin::factual('Wie tagesschau berichtet, hat der Bundestag am Donnerstag die Reform beschlossen.', ['key' => 'x', 'section' => 'germany', 'source' => 'tagesschau', 'title' => 'Bundestag beschließt Reform', 'news_id' => null]))->toBe([])
        ->and(NewsBulletin::factual('Wie tagesschau berichtet, hat der Bundestag 2026 die Reform beschlossen.', ['key' => 'x', 'section' => 'germany', 'source' => 'tagesschau', 'title' => 'Bundestag beschließt Reform', 'news_id' => null]))->toBe([NewsBulletin::NUMBER])
        ->and(NewsBulletin::factual('Wie tagesschau berichtet, hat die CDU im Bundestag die Reform beschlossen.', ['key' => 'x', 'section' => 'germany', 'source' => 'tagesschau', 'title' => 'Bundestag beschließt Reform', 'news_id' => null]))->toBe([NewsBulletin::NAME])
        ->and(NewsBulletin::factual('Der Bundestag hat nach einer langen Debatte die Reform beschlossen.', ['key' => 'x', 'section' => 'germany', 'source' => 'tagesschau', 'title' => 'Bundestag beschließt Reform', 'news_id' => null]))->toBe([NewsBulletin::SOURCE_MISSING]);
});

it('reads a headline in one bulletin only: the next bulletins of the day pick others', function () {
    bulletinNewsDesk();
    config(['tv.commentary.programme.bulletin.items' => ['news' => ['min' => 4, 'max' => 4], 'headlines' => ['min' => 3, 'max' => 3]]]);
    VoiceAgent::fake(fn (string $prompt): array => bulletinAnswer($prompt));
    $read = [];

    foreach (['13:55', '14:25'] as $time) {
        $this->travelTo(CarbonImmutable::parse("2026-10-12 {$time}:00", 'Europe/Berlin'));
        $bulletin = app(NewsBulletin::class)->write(bulletinSlot(), CarbonImmutable::now());
        expect($bulletin)->not->toBeNull()
            ->and(array_intersect($read, $bulletin['items']))->toBe([]);
        $read = [...$read, ...$bulletin['items']];
    }

    expect(CasterNews::query()->whereNotNull('bulletin_at')->count())->toBe(count($read))
        ->and(CasterNews::query()->whereNotNull('bulletin_at')->whereNull('used_at')->count())->toBe(0);
});

it('never puts two headlines on the same story into one bulletin', function () {
    bulletinHeadline('Bundestag beschließt Reform der Grundsteuer für Eigentümer', 'tagesschau', CasterNews::GERMANY, 10);
    bulletinHeadline('Grundsteuer: Bundestag beschließt Reform für Eigentümer', 'Spiegel', CasterNews::GERMANY, 11);
    bulletinHeadline('Koalition legt Entwurf für Pflegereform vor', 'Welt', CasterNews::GERMANY, 15);
    bulletinHeadline('Miner verlagern Rechenleistung nach Skandinavien', 'BTC-Echo', CasterNews::BITCOIN, 12);

    $titles = array_column(app(NewsBulletin::class)->select(BroadcastClock::NEWS, CarbonImmutable::now()), 'title');

    expect($titles)->toHaveCount(3)
        ->and(array_filter($titles, fn (string $title): bool => str_contains($title, 'Grundsteuer')))->toHaveCount(1)
        ->and(NewsBulletin::sameStory('Bundestag beschließt Reform der Grundsteuer für Eigentümer', 'Grundsteuer: Bundestag beschließt Reform für Eigentümer'))->toBeTrue()
        ->and(NewsBulletin::sameStory('Bundestag beschließt Reform der Grundsteuer', 'Miner verlagern Rechenleistung nach Skandinavien'))->toBeFalse();
});

it('makes a shorter bulletin from thin news and none at all without enough, never filler', function () {
    bulletinHeadline('Bundestag beschließt Reform der Grundsteuer für Eigentümer', 'tagesschau', CasterNews::GERMANY, 10);
    bulletinHeadline('Miner verlagern Rechenleistung nach Skandinavien', 'BTC-Echo', CasterNews::BITCOIN, 12);
    bulletinHeadline('Alte Meldung aus dem Archiv vom Vortag', 'Spiegel', CasterNews::GERMANY, 60 * 13);
    VoiceAgent::fake(fn (string $prompt): array => bulletinAnswer($prompt));

    $bulletin = app(NewsBulletin::class)->write(bulletinSlot(), CarbonImmutable::now());

    expect($bulletin['items'])->toHaveCount(2)
        ->and(bulletinFactLines($bulletin['text']))->toHaveCount(2)
        ->and(app(NewsBulletin::class)->write(bulletinSlot(), CarbonImmutable::now()))->toBeNull();
    VoiceAgent::assertPromptedTimes(1);
});

it('puts an item back where it belongs when the model forgot its number, so it airs and is marked read', function () {
    bulletinHeadline('Bundestag beschließt Reform der Grundsteuer für Eigentümer', 'tagesschau', CasterNews::GERMANY, 10);
    bulletinHeadline('Miner verlagern Rechenleistung nach Skandinavien', 'BTC-Echo', CasterNews::BITCOIN, 12);
    bulletinHeadline('Chatkontrolle: Messenger-Dienste drohen mit Rückzug aus Europa', 'heise online', CasterNews::TECH, 15);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 14:25:00', 'Europe/Berlin'));
    VoiceAgent::fake(function (string $prompt): array {
        $answer = bulletinAnswer($prompt);
        // Item two runs on below item one, without its number (real Haiku, 2026-10-12).
        $answer['text'] = (string) preg_replace('/\n2: /u', "\n", $answer['text']);

        return $answer;
    });

    $bulletin = app(NewsBulletin::class)->write(bulletinSlot(), CarbonImmutable::now());

    expect($bulletin['items'])->toHaveCount(3)
        ->and(bulletinFactLines($bulletin['text']))->toHaveCount(3)
        ->and(CasterNews::query()->whereNotNull('bulletin_at')->count())->toBe(3)
        ->and(NewsBulletin::reassign([1 => "Wie BTC-Echo berichtet, verlagern Miner ihre Rechenleistung.\nWie tagesschau berichtet, beschließt der Bundestag die Reform."], [
            ['key' => 'a', 'section' => 'bitcoin', 'source' => 'BTC-Echo', 'title' => 'x', 'news_id' => 1],
            ['key' => 'b', 'section' => 'germany', 'source' => 'tagesschau', 'title' => 'y', 'news_id' => 2],
        ]))->toBe([1 => 'Wie BTC-Echo berichtet, verlagern Miner ihre Rechenleistung.', 2 => 'Wie tagesschau berichtet, beschließt der Bundestag die Reform.']);
});

it('names the slot time at night and adds at most one league item of the day', function () {
    config(['tv.commentary.programme.bulletin.league' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 20:55:00', 'Europe/Berlin'));
    bulletinNewsDesk();
    $world = [
        ['key' => 'tournament-end:7', 'at' => CarbonImmutable::now()->subHour()->getTimestamp(), 'kind' => 'tournament', 'line' => 'Das Turnier „Herbst-Cup“ ist zu Ende, gewonnen hat Satoshi'],
        ['key' => 'chess:9', 'at' => CarbonImmutable::now()->subHours(2)->getTimestamp(), 'kind' => 'result', 'line' => 'Schach: Alice gewinnt gegen Bob'],
    ];
    Cache::put('caster:world:items', $world, 600);
    VoiceAgent::fake(fn (string $prompt): array => bulletinAnswer($prompt));

    $first = app(NewsBulletin::class)->write(bulletinSlot(), CarbonImmutable::now());
    $this->travelTo(CarbonImmutable::parse('2026-10-12 21:55:00', 'Europe/Berlin'));
    Cache::put('caster:world:items', $world, 600);
    $second = app(NewsBulletin::class)->write(bulletinSlot(), CarbonImmutable::now());

    expect(ScriptGuard::lines($first['text'])[0])->toBe('Es ist einundzwanzig Uhr, hier sind die Nachrichten auf TWENTY ONE TV.')
        ->and(array_values(array_filter($first['items'], fn (string $key): bool => str_starts_with($key, 'league:'))))->toBe(['league:tournament-end:7'])
        ->and($first['text'])->toContain('TWENTY ONE Liga')
        ->and(array_values(array_filter($second['items'] ?? [], fn (string $key): bool => str_starts_with($key, 'league:'))))->toBe(['league:chess:9']);
});

it('tells one story once: the Klingbeil pair of 12:30 is one item, the other outlet named with it and marked read', function () {
    bulletinNewsDesk();
    bulletinHeadline('Klingbeil will SPD-Führung neu wählen lassen', 'tagesschau', CasterNews::GERMANY, 5);
    bulletinHeadline('Klingbeil kündigt Neuwahl der Parteispitze an', 'Spiegel', CasterNews::GERMANY, 6);
    VoiceAgent::fake(fn (string $prompt): array => bulletinAnswer($prompt));

    expect(NewsBulletin::sameStory('Klingbeil will SPD-Führung neu wählen lassen', 'Klingbeil kündigt Neuwahl der Parteispitze an'))->toBeTrue()
        // A shared name alone is no story: two different stories about one person stay apart.
        ->and(NewsBulletin::sameStory('Merz trifft Macron in Paris', 'Merz verteidigt Rentenpaket im Bundestag'))->toBeFalse();

    $items = app(NewsBulletin::class)->select(BroadcastClock::NEWS, CarbonImmutable::now());
    $klingbeil = array_values(array_filter($items, fn (array $item): bool => str_contains($item['title'], 'Klingbeil')));

    expect($klingbeil)->toHaveCount(1)->and($klingbeil[0]['also'])->toBe(['Spiegel']);

    $bulletin = app(NewsBulletin::class)->write(bulletinSlot(), CarbonImmutable::now());
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Dieselbe Geschichte auch bei: Spiegel'));

    expect(substr_count($bulletin['text'], 'Klingbeil'))->toBe(1)
        ->and(CasterNews::query()->where('title', 'like', 'Klingbeil%')->whereNotNull('bulletin_at')->count())->toBe(2);
});

it('keeps a story out of the next bulletin that one read within the day, from whichever outlet', function () {
    bulletinHeadline('Klingbeil will SPD-Führung neu wählen lassen', 'tagesschau', CasterNews::GERMANY, 50)->update(['bulletin_at' => now()->subMinutes(30)]);
    bulletinHeadline('Klingbeil kündigt Neuwahl der Parteispitze an', 'Welt', CasterNews::GERMANY, 5);
    bulletinHeadline('Miner verlagern Rechenleistung nach Skandinavien', 'BTC-Echo', CasterNews::BITCOIN, 12);

    expect(array_column(app(NewsBulletin::class)->select(BroadcastClock::NEWS, CarbonImmutable::now()), 'title'))->toBe(['Miner verlagern Rechenleistung nach Skandinavien']);
});

it('leaves out an item the model marks as doppelt and marks it read, without turning the draft down', function () {
    bulletinNewsDesk();
    VoiceAgent::fake(fn (string $prompt): array => bulletinAnswer($prompt, [3 => 'doppelt']));

    $bulletin = app(NewsBulletin::class)->write(bulletinSlot(), CarbonImmutable::now());

    expect($bulletin['dropped'])->toHaveCount(1)
        ->and($bulletin['items'])->not->toContain($bulletin['dropped'][0])
        ->and(bulletinFactLines($bulletin['text']))->toHaveCount(count($bulletin['items']))
        ->and($bulletin['text'])->not->toContain('doppelt')
        ->and(CasterNews::query()->whereNotNull('bulletin_at')->count())->toBe(count($bulletin['items']) + 1);
    VoiceAgent::assertPromptedTimes(1);
});

it('asks for an opinionated, witty commentary and drops a timid sentence (live 12:30: "ich kann euch dazu nicht mehr sagen")', function () {
    bulletinNewsDesk();
    VoiceAgent::fake(fn (string $prompt): array => bulletinAnswer($prompt, comment: 'Ich kann euch dazu leider nicht mehr sagen, weil die Meldung wirklich sehr knapp ausgefallen ist.'));

    $bulletin = app(NewsBulletin::class)->write(bulletinSlot(), CarbonImmutable::now());

    expect($bulletin['text'])->not->toContain('nicht mehr sagen')
        ->and($bulletin['text'])->toContain('Bitcoin für mich die ehrlichere Antwort')
        ->and(NewsBulletin::withoutTimid("Mehr weiß ich auch nicht dazu, ehrlich gesagt, liebe Leute im Chat.\nDas bleibt abzuwarten, aber ich habe da so meine Zweifel an der ganzen Sache.\nDer Staat druckt, wir stapeln, und am Ende lacht der Bitcoiner über den Euro."))
        ->toBe('Der Staat druckt, wir stapeln, und am Ende lacht der Bitcoiner über den Euro.');
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Sarkasmus über Staat, Steuern, Bürokratie und Fiatgeld') && str_contains($prompt->prompt, 'Kein Herumdrucksen'));
});

it('gives each item its feed description as background and reads foreign news as a section of its own', function () {
    bulletinNewsDesk();
    bulletinHeadline('Polen verstärkt Schutz für Regierungschef Tusk', 'n-tv', CasterNews::WORLD, 8)
        ->update(['summary' => 'Die polnische Regierung stellt dem Regierungschef Tusk mehr Personenschützer zur Seite.']);
    VoiceAgent::fake(fn (string $prompt): array => bulletinAnswer($prompt));

    $items = app(NewsBulletin::class)->select(BroadcastClock::NEWS, CarbonImmutable::now());
    expect(array_column($items, 'section'))->toContain(CasterNews::WORLD);

    app(NewsBulletin::class)->write(bulletinSlot(), CarbonImmutable::now());
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Rubrik: Welt | Quelle: n-tv | Meldung: Polen verstärkt Schutz für Regierungschef Tusk | Hintergrund: Die polnische Regierung')
        && str_contains($prompt->prompt, 'jede mit 1 bis 3 ganzen Sätzen'));

    // A name or a number from the description is no invented fact.
    expect(NewsBulletin::factual('Wie n-tv berichtet, stellt Polen dem Regierungschef Tusk mehr Personenschützer zur Seite.', $items[array_search(CasterNews::WORLD, array_column($items, 'section'), true)]))->toBe([]);
});
