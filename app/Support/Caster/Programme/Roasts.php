<?php

namespace App\Support\Caster\Programme;

use App\Support\Caster\QuietHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The eight roast formats of TWENTY ONE Radio (plan "TWENTY ONE Radio",
 * P10; user 2026-10-12: "Alles aus echten Daten, alles roasten"). They air
 * at the rubric slots like the other rubrics (Rubrics: rotation, plan kept
 * per slot, teaser only for what follows) and are written by RoastScripts:
 *
 * - Zentralbank-Kläranlage: the newest ECB and Fed statements, quoted;
 * - Schwarzbuch-Stunde: a waste case of the Bund der Steuerzahler (evergreen);
 * - Altcoin-Hype-Detektor: altcoin headlines of Cointelegraph, Decrypt, CoinDesk;
 * - Liga-Pranger: the league's chess week, sporting roast only;
 * - Phrasendrescher der Woche: quoted political phrases of tagesschau and Spiegel;
 * - Nostr-Fundbüro: public posts of the EINUNDZWANZIG relays, each once ever;
 * - Corporate-Stacker-Watch: companies with bitcoin on the balance sheet;
 * - Inflationsbeichte: Destatis' inflation rate and sourced price comparisons (evergreen).
 *
 * A format airs only within its `roasts.formats.<id>.hours` (the fresh ones
 * 09:00 to midnight, the evergreen ones any time), and never right after
 * another roast format (the previous rubric slot's plan). The material is
 * RoastMaterial's; what a format aired is remembered for its own window.
 *
 * @phpstan-import-type RubricMaterial from Rubrics
 */
class Roasts
{
    public const ZENTRALBANK = 'zentralbank';

    public const SCHWARZBUCH = 'schwarzbuch';

    public const ALTCOIN = 'altcoin_hype';

    public const LIGA_PRANGER = 'liga_pranger';

    public const PHRASEN = 'phrasendrescher';

    public const NOSTR = 'nostr_fundbuero';

    public const STACKER = 'corporate_stacker';

    public const INFLATION = 'inflationsbeichte';

    /**
     * Each format: how it is named on air, what it brings (a teaser's data), and how a line names it.
     *
     * @var array<string, array{name: string, what: string, pattern: string}>
     */
    public const CATALOGUE = [
        self::ZENTRALBANK => ['name' => 'die Zentralbank-Kläranlage', 'what' => 'die neueste Mitteilung von EZB und Fed, wörtlich zitiert und zerlegt', 'pattern' => '/zentralbank[\s-]*kläranlage/iu'],
        self::SCHWARZBUCH => ['name' => 'die Schwarzbuch-Stunde', 'what' => 'ein echter Fall von Steuergeldverschwendung aus dem Schwarzbuch', 'pattern' => '/schwarzbuch[\s-]*stunde/iu'],
        self::ALTCOIN => ['name' => 'der Altcoin-Hype-Detektor', 'what' => 'die heißesten Altcoin-Schlagzeilen der Kryptopresse, gnadenlos geprüft', 'pattern' => '/altcoin[\s-]*hype[\s-]*detektor/iu'],
        self::LIGA_PRANGER => ['name' => 'der Liga-Pranger', 'what' => 'die bittersten Niederlagen, Aufgaben und Pechsträhnen der Ligawoche', 'pattern' => '/liga[\s-]*pranger/iu'],
        self::PHRASEN => ['name' => 'der Phrasendrescher der Woche', 'what' => 'die leersten Politikersätze der Woche, wörtlich aus den Schlagzeilen', 'pattern' => '/phrasen[\s-]*drescher/iu'],
        self::NOSTR => ['name' => 'das Nostr-Fundbüro', 'what' => 'die schrägsten öffentlichen Posts von den EINUNDZWANZIG-Relays', 'pattern' => '/nostr[\s-]*fundbüro/iu'],
        self::STACKER => ['name' => 'die Corporate-Stacker-Watch', 'what' => 'welche Firmen Bitcoin in der Bilanz haben, wer kauft, wer verkauft und wer unter Wasser ist', 'pattern' => '/corporate[\s-]*stacker/iu'],
        self::INFLATION => ['name' => 'die Inflationsbeichte', 'what' => 'die neueste Inflationsrate und echte Preise von damals und heute', 'pattern' => '/inflations[\s-]*beichte/iu'],
    ];

    /** The formats that may air again on a later day (the day stock, the replay): no date-bound facts in them. */
    public const EVERGREEN = [self::SCHWARZBUCH, self::INFLATION];

    public function __construct(private readonly RoastMaterial $material, private readonly ?BroadcastClock $clock = null) {}

    /** Whether `$id` is one of the roast formats. */
    public static function owns(string $id): bool
    {
        return isset(self::CATALOGUE[$id]);
    }

    /** Whether the roast formats run (`programme.roasts.enabled`). */
    public static function enabled(): bool
    {
        return config('tv.commentary.programme.roasts.enabled') === true;
    }

    /** Whether format `$id` may air at `$at` (its `hours` window in Berlin; none: any time). */
    public static function allowedAt(string $id, CarbonImmutable $at): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $hours = (array) config('tv.commentary.programme.roasts.formats.'.$id.'.hours', []);

        if (! is_string($hours['from'] ?? null) || ! is_string($hours['to'] ?? null)) {
            return true;
        }

        return QuietHours::within($at, $hours['from'], $hours['to'], (string) config('tv.commentary.programme.timezone', 'Europe/Berlin'));
    }

    /**
     * Whether a roast format may take the rubric slot `$slot`: its hours, and the rubric slot before it planned no
     * roast format (two roasts never back to back).
     *
     * @param  array{type: string, minute: int, key: int}  $slot
     */
    public function mayTake(string $id, array $slot, string $planKey): bool
    {
        if (! self::allowedAt($id, CarbonImmutable::createFromTimestamp($slot['key']))) {
            return false;
        }

        $previous = $this->previousRubricKey($slot['key']);
        $planned = $previous === null ? null : Cache::get($planKey.$previous);

        return ! (is_string($planned) && self::owns($planned));
    }

    /** The key of the rubric slot before `$key` within ninety minutes, or null. */
    public function previousRubricKey(int $key): ?int
    {
        $slots = array_values(array_filter(($this->clock ?? BroadcastClock::fromConfig())->upcoming(CarbonImmutable::createFromTimestamp($key - 5400), 89),
            fn (array $slot): bool => $slot['type'] === BroadcastClock::RUBRIC && $slot['key'] < $key));

        return $slots === [] ? null : end($slots)['key'];
    }

    /**
     * The material of format `$id` now, or null (source down, nothing new).
     *
     * @return RubricMaterial|null
     */
    public function material(string $id, CarbonImmutable $now): ?array
    {
        try {
            return match ($id) {
                self::ZENTRALBANK => $this->material->centralBanks($now),
                self::SCHWARZBUCH => $this->material->wasteCase($now),
                self::ALTCOIN => $this->material->altcoinHype($now),
                self::LIGA_PRANGER => $this->material->leaguePillory($now),
                self::PHRASEN => $this->material->emptyPhrases($now),
                self::NOSTR => $this->material->nostrFinds($now),
                self::STACKER => $this->material->corporateStackers($now),
                self::INFLATION => $this->material->inflation($now),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Remembers what a format aired: its posts, cases, headlines and prices are not offered again within its window.
     *
     * @param  RubricMaterial  $material
     */
    public function aired(string $id, array $material, CarbonImmutable $now): void
    {
        $this->material->markUsed($id, $material['keys'], $now);
    }
}
