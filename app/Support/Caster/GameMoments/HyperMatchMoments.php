<?php

namespace App\Support\Caster\GameMoments;

use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Support\Caster\SceneFacts;
use App\Support\Hyper\HyperMap;
use App\Support\Hyper\HyperNames;
use App\Support\TwentyOne\Stream\HyperScene;

/**
 * What a Hyperbitcoinization match gives the caster, read from its state
 * (the standing the h1 slide shows: territories, central banks, who is out)
 * and its last RECENT_ACTIONS actions:
 *
 * - `hyper_leader`: the seat ahead (most banks, then territories, as the
 *   slide's leader); dominance when it holds at least DOMINANT_SHARE of all
 *   owned territories.
 * - `hyper_duel`: two sides left and the weaker holds at least
 *   HyperScene::CLOSE_SHARE of their territories (the slide's "close duel").
 * - `hyper_bank`: a central bank fell in the recent actions.
 * - `hyper_conquests`: one seat took CONQUEST_RUN or more territories in
 *   the recent actions.
 * - `hyper_field`: seats are out; who is still in.
 *
 * A seat whose name the voice cannot speak is left out with its moments.
 */
final class HyperMatchMoments
{
    /** Actions read for the recent moments. */
    public const RECENT_ACTIONS = 8;

    /** Territories taken in the recent actions that make a run. */
    public const CONQUEST_RUN = 2;

    /** A leader with this share of all owned territories dominates. */
    public const DOMINANT_SHARE = 0.5;

    /** Central banks a voice can say as written (no "PBoC"). */
    private const SPOKEN_BANKS = ['Fed', 'EZB', 'SNB', 'Bank of England', 'Afrikanische Zentralbank'];

    public function __construct(private HyperScene $scene) {}

    /**
     * @return list<array<string, string|int>>
     */
    public function of(HyperMatch $match, int $nowMs): array
    {
        $match->loadMissing('seats.user');
        $names = [];

        foreach ($match->seats as $seat) {
            /** @var HyperSeat $seat */
            $name = SceneFacts::spoken(HyperNames::seat($seat));

            if ($name !== null) {
                $names[(int) $seat->seat] = $name;
            }
        }

        $standing = $this->scene->standing($match);
        $tension = $this->scene->tension($match, $nowMs);
        $base = ['game' => 'Hyperbitcoinization', 'round' => (int) ($match->state['round'] ?? 1)];
        $moments = [];

        $leader = $tension['leader'];
        $owned = array_sum(array_column($standing, 'territories'));
        $row = collect($standing)->firstWhere('seat', $leader);
        // Level at the top (same banks, same territories) is no lead, whichever seat the slide happens to name first.
        $tied = is_array($row) && collect($standing)->contains(fn (array $s): bool => ! $s['out'] && $s['seat'] !== $leader && [$s['banks'], $s['territories']] === [$row['banks'], $row['territories']]);
        if ($leader !== null && isset($names[$leader]) && is_array($row) && $owned > 0 && ! $tied) {
            $moments[] = array_filter([...$base, 'kind' => 'hyper_leader', 'mood' => $row['territories'] / $owned >= self::DOMINANT_SHARE ? Mood::DOMINANCE : null,
                'lead' => $names[$leader], 'hyper_leader' => $names[$leader], 'leader_territories' => Words::count($row['territories'], 'ein Gebiet', 'Gebiete'),
                'leader_banks' => $row['banks'] > 0 ? Words::count($row['banks'], 'eine Zentralbank', 'Zentralbanken') : null], fn (mixed $value): bool => $value !== null);
        }

        $duel = $tension['duel'];
        if ($duel !== null && isset($names[$duel[0]], $names[$duel[1]])) {
            $moments[] = [...$base, 'kind' => 'hyper_duel', 'mood' => Mood::CLOSE, 'duel_a' => $names[$duel[0]], 'duel_b' => $names[$duel[1]]];
        }

        $conquests = [];
        $bank = null;
        $actions = $match->actions()->reorder('ply', 'desc')->limit(self::RECENT_ACTIONS)->get();
        foreach ($actions as $action) {
            foreach ($action->events as $event) {
                $seat = isset($event['seat']) ? (int) $event['seat'] : null;

                if (($event['type'] ?? null) === 'territory_conquered' && $seat !== null) {
                    $conquests[$seat] = ($conquests[$seat] ?? 0) + 1;
                }

                if (($event['type'] ?? null) === 'bank_fallen' && $seat !== null && $bank === null) {
                    $zone = array_search((string) ($event['zone'] ?? ''), HyperMap::ZONE_KEYS, true);
                    $bank = ['seat' => $seat, 'name' => $zone === false ? null : HyperMap::ZONE_BANKS[$zone]];
                }
            }
        }

        if ($bank !== null && isset($names[$bank['seat']])) {
            $moments[] = array_filter([...$base, 'kind' => 'hyper_bank', 'mood' => Mood::TENSION, 'lead' => $names[$bank['seat']], 'bank_by' => $names[$bank['seat']],
                'bank_name' => in_array($bank['name'], self::SPOKEN_BANKS, true) ? $bank['name'] : null], fn (mixed $value): bool => $value !== null);
        }

        arsort($conquests);
        $runner = array_key_first($conquests);
        if ($runner !== null && $conquests[$runner] >= self::CONQUEST_RUN && isset($names[$runner])) {
            $moments[] = [...$base, 'kind' => 'hyper_conquests', 'mood' => Mood::TENSION, 'lead' => $names[$runner], 'conqueror' => $names[$runner], 'conquests' => Words::count($conquests[$runner], 'ein Gebiet', 'Gebiete')];
        }

        $alive = array_values(array_filter($standing, fn (array $s): bool => ! $s['out']));
        if (count($alive) < count($standing) && count($alive) >= 2) {
            $moments[] = [...$base, 'kind' => 'hyper_field', 'mood' => count($alive) === 2 ? Mood::TENSION : null, 'still_in' => Words::count(count($alive), 'ein Spieler', 'Spieler'), 'still_in_count' => count($alive), 'seats' => count($standing)];
            $moments[array_key_last($moments)] = array_filter($moments[array_key_last($moments)], fn (mixed $value): bool => $value !== null);
        }

        return $moments;
    }
}
