<?php

namespace App\Support\Caster\Programme;

use App\Models\ChatterLine;
use App\Models\ChatterProfile;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\ChatterCache;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\DuetScripts;
use App\Support\Caster\PersonaCards;
use App\Support\Caster\SceneFacts;
use App\Support\Moderation\SiteModeration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The long formats of TWENTY ONE Radio (plan "TWENTY ONE Radio", P9; user
 * 2026-10-12: "Mehr Programm … damit es nicht langweilig wird, müssen wir
 * roasten!"). Each owns a rubric slot of the clock at its Berlin time
 * (`programme.shows.schedule`, Rubrics::plannedFor() asks scheduledFor()
 * first) and is written by ShowScripts:
 *
 * - Das Chat-Tribunal: the day's chatters on trial, their chat lines and
 *   their own recent Nostr notes (ChatterCache, already fetched) as the
 *   charges; Sirius judges, Markus defends uselessly. Only chatters who
 *   chatted within `chat_hours`, named by their kind 0 name only, never a
 *   gamer tag or game account (house rule), nothing sensitive.
 * - Der Shitcoin-Friedhof: a eulogy for a dead coin of the curated list
 *   (resources/data/voice/shitcoin-graveyard.json, a source per entry),
 *   none again within `repeat_days`.
 * - Die Bitcoin-Zeitreise: "heute vor X Jahren" from the dated list and
 *   Wikipedia's bitcoin events of the day (OnThisDay).
 * - Der Meetup-Radar: the EINUNDZWANZIG meetup dates of the next `days`
 *   (MeetupRadar, the portal's public list).
 * - Die Partie des Tages: the most dramatic league match of the day as a
 *   replay (MatchOfTheDay), another one at the second airing.
 * - Satoshis Hotline: the day's unanswered or best chat questions, each
 *   looked up on the web (CasterVoice::lookup(), `searches_per_question`)
 *   before the call (lookups()).
 * - Turm und Genossin, der Talk: a talk show of three on a real money
 *   headline, Sirius hosts, Markus against the guest Genossin Gisela (TalkShow;
 *   written by TalkShowScripts, not ShowScripts).
 *
 * material() is cheap (no model call, sources cached) and null when the
 * source is down or has nothing: the slot then falls back to the rubric
 * rotation, nothing is invented. The material carries what the guard needs
 * (ShowScripts::factual()): every digit, number and year of the data, the
 * names it may use, and `foreign` names it must not (other chatters, the
 * chatters' gamer tags, cities without a date, the other dead coins).
 *
 * @phpstan-import-type DatedEvent from OnThisDay
 *
 * @phpstan-type ShowMaterial array{data: string, names: list<string>, sources: list<string>, hash: string, keys: list<string>, same_story: bool, digits: list<string>, numbers: list<int>, years: list<int>, foreign: list<string>, questions: list<array{key: string, text: string}>}
 */
class Shows
{
    public const TRIBUNAL = 'tribunal';

    public const FRIEDHOF = 'friedhof';

    public const JAHRESTAG = 'jahrestag';

    public const MEETUPS = 'meetups';

    public const PARTIE = 'partie';

    public const HOTLINE = 'hotline';

    /** Turm und Genossin, der Talk (P16; internal id "stammtisch"): the talk show of three, TalkShow and TalkShowScripts. */
    public const STAMMTISCH = 'stammtisch';

    /**
     * How each format is named on air, what it brings (a teaser's data) and how a line names it (Announcements).
     *
     * @var array<string, array{name: string, what: string, pattern: string}>
     */
    public const CATALOGUE = [
        self::TRIBUNAL => ['name' => 'das Chat-Tribunal', 'what' => 'die Chatter des Tages vor Gericht, mit ihren eigenen Nachrichten und Posts als Anklage', 'pattern' => '/chat[\s-]*tribunal/iu'],
        self::FRIEDHOF => ['name' => 'die Trauerfeier auf dem Shitcoin-Friedhof', 'what' => 'eine Grabrede auf einen toten Shitcoin, mit belegten Fakten', 'pattern' => '/shitcoin[\s-]*friedhof/iu'],
        self::JAHRESTAG => ['name' => 'die Bitcoin-Zeitreise', 'what' => 'was an diesem Kalendertag in der Geschichte von Bitcoin passiert ist', 'pattern' => '/bitcoin[\s-]*zeitreise/iu'],
        self::MEETUPS => ['name' => 'das Meetup-Radar', 'what' => 'die EINUNDZWANZIG-Meetups der nächsten sieben Tage', 'pattern' => '/meetup[\s-]*radar/iu'],
        self::PARTIE => ['name' => 'die Partie des Tages', 'what' => 'das dramatischste Liga-Match des Tages als Wiederholung, neu kommentiert', 'pattern' => '/partie\s+des\s+tages/iu'],
        self::HOTLINE => ['name' => 'Satoshis Hotline', 'what' => 'die besten und unbeantworteten Fragen aus dem Chat, nachgeschlagen und beantwortet', 'pattern' => '/satoshis?[\s-]*hotline/iu'],
        self::STAMMTISCH => ['name' => 'Turm und Genossin, der Talk', 'what' => 'eine Talkshow zu einer echten Geld-Schlagzeile: Markus Turm gegen Genossin Gisela, die für die Modern Monetary Theory streitet, und Sirius moderiert', 'pattern' => '/turm\s+und\s+genossin/iu'],
    ];

    /** Formats bound to their day: never replayed from the archive (ReplayArchive::NEVER_TOPICS). */
    public const DAY_BOUND = [self::TRIBUNAL, self::MEETUPS, self::PARTIE, self::HOTLINE, self::JAHRESTAG, self::STAMMTISCH];

    private const USED = 'caster:show:used:';

    private const LOOKUP = 'caster:show:lookup:';

    public static function enabled(): bool
    {
        return config('tv.commentary.programme.shows.enabled') === true && Rubrics::enabled();
    }

    /**
     * The show windows of the schedule (P20): the local start "HH:MM" of every slot whose format has `slot_minutes`, to
     * those minutes (BroadcastClock lets the show own the window). None while the shows are off.
     *
     * @return array<string, int>
     */
    public static function windows(): array
    {
        if (config('tv.commentary.programme.shows.enabled') !== true) {
            return [];
        }

        $windows = [];

        foreach ((array) config('tv.commentary.programme.shows.schedule', []) as $time => $id) {
            $minutes = is_string($id) ? self::slotMinutes($id) : null;

            if ($minutes !== null) {
                $windows[(string) $time] = $minutes;
            }
        }

        return $windows;
    }

    /** The minutes a format's slot lasts on air (`formats.<id>.slot_minutes`, P20), or null when it has none. */
    public static function slotMinutes(string $id): ?int
    {
        $minutes = self::settings($id)['slot_minutes'] ?? null;

        return is_numeric($minutes) && (int) $minutes > 0 ? (int) $minutes : null;
    }

    public static function isShow(?string $id): bool
    {
        return $id !== null && isset(self::CATALOGUE[$id]);
    }

    /**
     * The settings of a format (`shows.formats.<id>`).
     *
     * @return array<array-key, mixed>
     */
    public static function settings(string $id): array
    {
        return (array) config('tv.commentary.programme.shows.formats.'.$id, []);
    }

    /**
     * The format the schedule puts on a rubric slot (its start in Berlin as "HH:MM"), or null.
     *
     * @param  array{type: string, key: int}  $slot
     */
    public function scheduledFor(array $slot): ?string
    {
        if (! self::enabled() || $slot['type'] !== BroadcastClock::RUBRIC) {
            return null;
        }

        $at = CarbonImmutable::createFromTimestamp($slot['key'])->setTimezone((string) config('tv.commentary.programme.timezone', 'Europe/Berlin'))->format('H:i');
        $id = ((array) config('tv.commentary.programme.shows.schedule', []))[$at] ?? null;

        return is_string($id) && self::isShow($id) ? $id : null;
    }

    /**
     * Who speaks in a format (`speakers`, the voices DuetScripts::parse() knows), Markus first; Markus and Sirius by default.
     *
     * @return list<string>
     */
    public static function cast(string $id): array
    {
        $known = DuetScripts::speakerNames();
        $speakers = array_values(array_unique(array_filter((array) (self::settings($id)['speakers'] ?? []), fn (mixed $speaker): bool => is_string($speaker) && in_array($speaker, $known, true))));

        if (count($speakers) < 2 || ! in_array(DuetDirector::MARKUS, $speakers, true)) {
            return [DuetDirector::MARKUS, DuetDirector::SIRIUS];
        }

        return [DuetDirector::MARKUS, ...array_values(array_diff($speakers, [DuetDirector::MARKUS]))];
    }

    /**
     * The material of a format now, or null (its source is down or has nothing).
     *
     * @return ShowMaterial|null
     */
    public function material(string $id, CarbonImmutable $now): ?array
    {
        try {
            return match ($id) {
                self::TRIBUNAL => $this->tribunal($now),
                self::FRIEDHOF => $this->friedhof($now),
                self::JAHRESTAG => $this->jahrestag($now),
                self::MEETUPS => $this->meetups($now),
                self::PARTIE => $this->partie($now),
                self::HOTLINE => $this->hotline($now),
                self::STAMMTISCH => app(TalkShow::class)->material($now),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Remembers what a format aired: its keys do not come again within its `repeat_days`.
     *
     * @param  list<string>  $keys
     */
    public static function markUsed(string $id, array $keys, CarbonImmutable $now): void
    {
        $used = self::used($id, $now);

        foreach ($keys as $key) {
            $used[$key] = $now->getTimestamp();
        }

        Cache::put(self::USED.$id, $used, $now->addDays(max(1, (int) (self::settings($id)['repeat_days'] ?? 1)) + 1));
    }

    /**
     * The keys a format aired within its `repeat_days` (key => timestamp).
     *
     * @return array<string, int>
     */
    public static function used(string $id, CarbonImmutable $now): array
    {
        $since = $now->subDays(max(1, (int) (self::settings($id)['repeat_days'] ?? 1)))->getTimestamp();
        $used = Cache::get(self::USED.$id);

        return is_array($used) ? array_filter($used, fn (mixed $at): bool => is_int($at) && $at > $since) : [];
    }

    /**
     * The hotline's material with a web lookup per question (cached per question for six hours, so a second try
     * does not search again); null when no question found anything (the hotline then does not air).
     *
     * @param  ShowMaterial  $material
     * @return ShowMaterial|null
     */
    public function withLookups(array $material, CasterVoice $voice): ?array
    {
        $searches = max(1, min(2, (int) (self::settings(self::HOTLINE)['searches_per_question'] ?? 2)));
        $blocks = [];
        $found = 0;

        foreach ($material['questions'] as $question) {
            $answer = Cache::get(self::LOOKUP.$question['key']);

            if (! is_string($answer)) {
                $answer = $voice->lookup($question['text'], $searches) ?? '';
                Cache::put(self::LOOKUP.$question['key'], $answer, now()->addHours(6));
            }

            if ($answer !== '') {
                $found++;
            }

            $blocks[] = $answer === '' ? '  Recherche dazu: nichts gefunden (sagt das offen, statt zu raten).' : '  Recherche dazu: '.$answer;
        }

        if ($found === 0) {
            return null;
        }

        // The data grows by the lookups: the guard's numbers come from the whole of it.
        $lines = explode("\n", $material['data']);
        $out = [];
        $index = 0;

        foreach ($lines as $line) {
            $out[] = $line;

            if (str_starts_with($line, '- Frage') && isset($blocks[$index])) {
                $out[] = $blocks[$index];
                $index++;
            }
        }

        return self::build(implode("\n", $out), $material['names'], $material['foreign'], $material['hash'], $material['keys'], $material['questions'], $material['sources']);
    }

    /**
     * A material array with the guard's numbers taken from `$data`.
     *
     * @param  list<string>  $names
     * @param  list<string>  $foreign
     * @param  list<string>  $keys
     * @param  list<array{key: string, text: string}>  $questions
     * @param  list<string>  $sources
     * @return ShowMaterial
     */
    public static function build(string $data, array $names, array $foreign, string $hash, array $keys, array $questions = [], array $sources = []): array
    {
        $data = self::withoutBlocked($data);
        preg_match_all('/\d+/u', $data, $digits);
        $numbers = array_map('intval', $digits[0]);

        // "10.000" also as ten thousand, "2.856" as two thousand eight hundred fifty-six.
        preg_match_all('/\d{1,3}(?:\.\d{3})+/u', $data, $grouped);
        foreach ($grouped[0] as $number) {
            $numbers[] = (int) str_replace('.', '', $number);
        }

        foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($data), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $value = SportWeather::value($word);
            if ($value !== null) {
                $numbers[] = $value;
            }
        }

        preg_match_all('/(?<!\d)(19\d{2}|20\d{2})(?!\d)/u', $data, $years);
        $lower = array_map('mb_strtolower', $names);

        return [
            'data' => $data,
            'names' => array_values(array_unique($names)),
            'sources' => $sources,
            'hash' => $hash,
            'keys' => $keys,
            'same_story' => false,
            'digits' => array_values(array_unique($digits[0])),
            'numbers' => array_values(array_unique($numbers)),
            'years' => array_values(array_unique(array_map('intval', $years[0]))),
            // A foreign name the data itself uses (a chatter called "Bitcoin") is no foreign name.
            'foreign' => array_values(array_unique(array_filter($foreign, fn (string $name): bool => mb_strlen($name) >= 3 && ! in_array(mb_strtolower($name), $lower, true)
                && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', $data) !== 1))),
            'questions' => $questions,
        ];
    }

    /**
     * `$data` without the sentences that carry a word the voice never says (`esports.voice.blocklist`: fees,
     * insults; VoiceGuard turns down a draft with one): a chatter's "Gebühren" in the data came back in every draft.
     * A line left empty goes.
     */
    public static function withoutBlocked(string $data): string
    {
        $blocked = array_values(array_filter(array_map(fn (mixed $word): string => mb_strtolower(trim((string) $word)), (array) config('esports.voice.blocklist', [])), fn (string $word): bool => $word !== ''));

        if ($blocked === []) {
            return $data;
        }

        $pattern = '/(?<![\p{L}\p{N}])(?:'.implode('|', array_map(fn (string $word): string => preg_quote($word, '/'), $blocked)).')(?![\p{L}\p{N}])/iu';
        $lines = [];

        foreach (explode("\n", $data) as $line) {
            if (preg_match($pattern, $line) !== 1) {
                $lines[] = $line;

                continue;
            }

            $kept = array_filter(preg_split('/(?<=[.!?…“])\s+/u', $line) ?: [], fn (string $sentence): bool => preg_match($pattern, $sentence) !== 1);
            $line = trim(implode(' ', $kept));

            if ($line !== '' && preg_match('/\p{L}{3,}/u', (string) preg_replace('/^[-\s]*[\p{L} ,-]*:\s*$/u', '', $line)) === 1) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The day's chatters on trial: at most `max_chatters` with a kind 0 name (most lines first), each with their chat
     * lines and up to `notes_per_chatter` of their own notes; null with nobody.
     *
     * @return ShowMaterial|null
     */
    private function tribunal(CarbonImmutable $now): ?array
    {
        $settings = self::settings(self::TRIBUNAL);
        $since = $now->subHours(max(1, (int) ($settings['chat_hours'] ?? 24)))->getTimestamp();
        $byChatter = [];

        foreach (ChatterLine::query()->where('said_at', '>=', $since)->where('said_at', '<=', $now->getTimestamp())->orderBy('said_at')->limit(500)->get() as $line) {
            if (! SiteModeration::isHidden($line->pubkey)) {
                $byChatter[$line->pubkey][] = $line;
            }
        }

        uasort($byChatter, fn (array $a, array $b): int => count($b) <=> count($a));
        $cache = app(ChatterCache::class);
        $profiles = ChatterProfile::query()->whereIn('pubkey', array_keys($byChatter))->get()->keyBy('pubkey');
        [$blocks, $names, $foreign, $pubkeys] = [[], [], [], []];

        foreach ($byChatter as $pubkey => $lines) {
            $tags = $cache->gamerTags((string) $pubkey);
            $foreign = [...$foreign, ...$tags];
            $profile = $profiles->get($pubkey);
            $name = $profile instanceof ChatterProfile ? SceneFacts::spoken($profile->name) : null;

            if ($name === null || mb_strlen($name) < 2 || ChatterCache::namesGameAccount($name, $tags) || count($blocks) >= max(1, (int) ($settings['max_chatters'] ?? 4))) {
                continue;
            }

            $said = array_values(array_filter(array_map(fn (ChatterLine $line): string => CasterVoice::withoutHashtags(trim($line->message)), $lines),
                fn (string $message): bool => $message !== '' && ! PersonaCards::sensitive($message) && ! ChatterCache::namesGameAccount($message, $tags)));

            if ($said === []) {
                continue;
            }

            $notes = array_slice($cache->promptNotes((string) $pubkey, $now), 0, max(0, (int) ($settings['notes_per_chatter'] ?? 3)));
            $about = array_values(array_filter($cache->promptProfile((string) $pubkey), fn (string $line): bool => str_starts_with($line, 'Über mich: ')));
            $blocks[] = "Angeklagt: {$name}\n- Im Chat geschrieben: ".implode(' / ', array_map(fn (string $message): string => '„'.$message.'“', array_slice($said, -5)))
                .($notes === [] ? '' : "\n- Eigene Nostr-Notizen: ".implode(' / ', $notes))
                .($about === [] ? '' : "\n- Profil, ".$about[0]);
            $names[] = $name;
            $pubkeys[] = (string) $pubkey;
        }

        if ($blocks === []) {
            return null;
        }

        // Every other chatter the cache knows is not on trial: the model must not drag one in.
        foreach (ChatterProfile::query()->whereNotIn('pubkey', $pubkeys)->whereNotNull('name')->orderByDesc('last_chat_at')->limit(300)->pluck('name') as $other) {
            $spoken = SceneFacts::spoken((string) $other);
            if ($spoken !== null) {
                $foreign[] = $spoken;
            }
        }

        $day = $now->setTimezone('Europe/Berlin')->format('Y-m-d');

        return self::build(implode("\n\n", $blocks), $names, $foreign, sha1('tribunal:'.$day.':'.implode(',', $pubkeys)), ['tribunal:'.$day]);
    }

    /**
     * The next dead coin of the curated list not used within `repeat_days` (the one used longest ago), or null.
     *
     * @return ShowMaterial|null
     */
    private function friedhof(CarbonImmutable $now): ?array
    {
        $coins = self::coins();
        $used = self::used(self::FRIEDHOF, $now);
        $free = array_values(array_filter($coins, fn (array $coin): bool => ! isset($used['friedhof:'.$coin['id']])));

        if ($free === []) {
            return null;
        }

        // A stable order through the list, starting at the day.
        $coin = $free[intdiv($now->getTimestamp(), 86_400) % count($free)];
        $foreign = [];

        foreach ($coins as $other) {
            if ($other['id'] !== $coin['id']) {
                $foreign = [...$foreign, ...$other['names']];
            }
        }

        $host = (string) parse_url($coin['source'], PHP_URL_HOST);
        $data = "Der Verstorbene: {$coin['name']}\n".implode("\n", array_map(fn (string $fact): string => '- '.$fact, $coin['facts']))
            ."\nQuelle: ".(str_contains($host, 'wikipedia') ? 'Wikipedia' : (str_contains($host, 'bbc') ? 'BBC' : $host));

        return self::build($data, $coin['names'], $foreign, 'friedhof:'.$coin['id'], ['friedhof:'.$coin['id']], [], [$coin['source']]);
    }

    /**
     * The curated dead coins.
     *
     * @return list<array{id: string, name: string, names: list<string>, facts: list<string>, source: string}>
     */
    public static function coins(): array
    {
        $data = json_decode((string) file_get_contents(resource_path('data/voice/shitcoin-graveyard.json')), true);
        $coins = [];

        foreach (is_array($data) && is_array($data['coins'] ?? null) ? $data['coins'] : [] as $coin) {
            if (is_array($coin) && is_string($coin['id'] ?? null) && is_string($coin['name'] ?? null) && is_string($coin['source'] ?? null) && str_starts_with($coin['source'], 'https://')
                && is_array($coin['facts'] ?? null) && $coin['facts'] !== [] && is_array($coin['names'] ?? null)) {
                $coins[] = ['id' => $coin['id'], 'name' => $coin['name'], 'names' => array_values(array_filter($coin['names'], 'is_string')),
                    'facts' => array_values(array_filter($coin['facts'], 'is_string')), 'source' => $coin['source']];
            }
        }

        return $coins;
    }

    /**
     * The day's bitcoin history, "heute vor X Jahren" counted for each event; null on a day without one.
     *
     * @return ShowMaterial|null
     */
    private function jahrestag(CarbonImmutable $now): ?array
    {
        $events = app(OnThisDay::class)->events($now);

        if ($events === []) {
            return null;
        }

        $local = $now->setTimezone('Europe/Berlin');
        $months = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
        $date = $local->day.'. '.$months[$local->month - 1];
        $lines = array_map(fn (array $event): string => '- Heute vor '.((int) $local->format('Y') - $event['year']).' Jahren, am '.$date.' '.$event['year'].': '.$event['fact']
            .' (Quelle: '.(str_contains($event['source'], 'wikipedia') ? 'Wikipedia' : (string) parse_url($event['source'], PHP_URL_HOST)).')', $events);

        return self::build("Heute ist der {$date}.\n".implode("\n", $lines), [], [], 'jahrestag:'.$local->format('Y-m-d'), ['jahrestag:'.$local->format('Y-m-d')], [],
            array_values(array_unique(array_column($events, 'source'))));
    }

    /**
     * The meetup dates of the next `days` (German-speaking countries first, at most `max_events`) and their count;
     * null when the portal is down or has none.
     *
     * @return ShowMaterial|null
     */
    private function meetups(CarbonImmutable $now): ?array
    {
        $settings = self::settings(self::MEETUPS);
        $radar = app(MeetupRadar::class);
        $dates = $radar->upcoming($now, max(1, (int) ($settings['days'] ?? 7)));

        if ($dates === null || $dates === []) {
            return null;
        }

        $countries = ['DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz'];
        $picked = array_values(array_filter($dates, fn (array $date): bool => isset($countries[$date['country']])));
        $picked = array_slice([...$picked, ...array_values(array_filter($dates, fn (array $date): bool => ! isset($countries[$date['country']])))], 0, max(1, (int) ($settings['max_events'] ?? 12)));
        usort($picked, fn (array $a, array $b): int => $a['starts_at'] <=> $b['starts_at']);

        $weekdays = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
        $clock = BroadcastClock::fromConfig();
        $lines = array_map(function (array $date) use ($weekdays, $countries, $clock): string {
            $at = CarbonImmutable::createFromTimestamp($date['starts_at']);
            $local = $at->setTimezone('Europe/Berlin');

            return '- '.$weekdays[$local->dayOfWeekIso - 1].' um '.$clock->spokenTime($at).': '.$date['meetup'].' in '.$date['city']
                .' ('.($countries[$date['country']] ?? $date['country']).')'
                .($date['title'] !== null && ! str_contains(mb_strtolower($date['title']), mb_strtolower($date['city'])) ? ', Titel „'.$date['title'].'“' : '')
                .($date['attendees'] > 0 ? ', bisher '.$date['attendees'].($date['attendees'] === 1 ? ' Zusage' : ' Zusagen') : '');
        }, $picked);

        $cities = array_values(array_unique(array_column($picked, 'city')));
        $names = [...$cities, ...array_values(array_unique(array_column($picked, 'meetup')))];
        $foreign = array_values(array_diff($radar->cities($now), array_column($dates, 'city')));
        $total = count($dates);

        return self::build("Insgesamt {$total} Meetup-Termine in den nächsten sieben Tagen laut EINUNDZWANZIG-Portal, davon diese:\n".implode("\n", $lines),
            $names, $foreign, sha1('meetups:'.implode(',', array_column($picked, 'id'))), ['meetups:'.$now->setTimezone('Europe/Berlin')->format('Y-m-d')]);
    }

    /**
     * The match of the day not shown within `repeat_days`, or null without one.
     *
     * @return ShowMaterial|null
     */
    private function partie(CarbonImmutable $now): ?array
    {
        $pick = app(MatchOfTheDay::class)->pick($now, self::used(self::PARTIE, $now));

        return $pick === null ? null : self::build($pick['data'], $pick['names'], [], 'partie:'.$pick['key'], [$pick['key']]);
    }

    /**
     * Up to `max_questions` chat questions of the last `chat_hours` (a question mark and at least four words, none
     * aired within `repeat_days`): unanswered ones first, then the longest; each with the asker's kind 0 name, else
     * "ein Zuschauer". The lookups come later (withLookups()). null without a question.
     *
     * @return ShowMaterial|null
     */
    private function hotline(CarbonImmutable $now): ?array
    {
        $settings = self::settings(self::HOTLINE);
        $used = self::used(self::HOTLINE, $now);
        $cache = app(ChatterCache::class);
        $candidates = [];

        foreach (ChatterLine::query()->where('said_at', '>=', $now->subHours(max(1, (int) ($settings['chat_hours'] ?? 24)))->getTimestamp())
            ->where('said_at', '<=', $now->getTimestamp())->orderByDesc('said_at')->limit(300)->get() as $line) {
            $message = CasterVoice::withoutHashtags(trim($line->message));
            $words = count(preg_split('/\s+/u', $message, -1, PREG_SPLIT_NO_EMPTY) ?: []);

            if (str_contains($message, '?') && $words >= 4 && ! isset($used['hotline:'.$line->id]) && ! SiteModeration::isHidden($line->pubkey)
                && ! PersonaCards::sensitive($message) && ! ChatterCache::namesGameAccount($message, $cache->gamerTags($line->pubkey))) {
                $candidates[] = ['line' => $line, 'message' => $message, 'rank' => [$line->reply === null ? 0 : 1, -$words]];
            }
        }

        usort($candidates, fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);
        $candidates = array_slice($candidates, 0, max(1, min(4, (int) ($settings['max_questions'] ?? 4))));

        if ($candidates === []) {
            return null;
        }

        $profiles = ChatterProfile::query()->whereIn('pubkey', array_map(fn (array $candidate): string => $candidate['line']->pubkey, $candidates))->get()->keyBy('pubkey');
        [$lines, $names, $questions] = [[], [], []];

        foreach ($candidates as $candidate) {
            /** @var ChatterLine $line */
            $line = $candidate['line'];
            $profile = $profiles->get($line->pubkey);
            $name = $profile instanceof ChatterProfile ? SceneFacts::spoken($profile->name) : null;
            $name = $name !== null && ! ChatterCache::namesGameAccount($name, $cache->gamerTags($line->pubkey)) ? $name : null;
            $lines[] = '- Frage von '.($name ?? 'einem Zuschauer ohne Namen').($line->reply === null ? ' (noch unbeantwortet)' : '').': „'.$candidate['message'].'“';
            $questions[] = ['key' => 'hotline:'.$line->id, 'text' => $candidate['message']];

            if ($name !== null) {
                $names[] = $name;
            }
        }

        return self::build(implode("\n", $lines), $names, [], sha1(implode(',', array_column($questions, 'key'))), array_column($questions, 'key'), $questions);
    }
}
