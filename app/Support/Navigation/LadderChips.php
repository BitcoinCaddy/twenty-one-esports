<?php

namespace App\Support\Navigation;

use App\Games\GameKind;
use App\Games\GameRegistry;
use App\Support\Chess\ChessModes;
use App\Support\GameNames;
use Illuminate\Support\Facades\Route;

/**
 * The chips of the ladder template's "Spiel" row (template T5, LadderPage.dc.html; rule R8): every ladder of the
 * league in the registry's display order, each with its short name from the registry ("Schach Blitz", "Blockli",
 * "RL 1v1"), Hyperbitcoinization's season ladder and Proof of Pong's Elo ladder among them. The current ladder is
 * marked; x-ui.chip-row folds what does not fit into "+N weitere".
 */
final class LadderChips
{
    /**
     * @param  string  $current  the current ladder: "<game>/<mode>", or a game slug for a game with one ladder page
     * @return list<array{href: string, label: string, current: bool, test: string}>
     */
    public static function items(string $current): array
    {
        $registry = app(GameRegistry::class);
        $items = [];

        foreach ($registry->all() as $slug => $game) {
            $short = __($game->assets()->shortLabel);

            if ($game->kind() === GameKind::Strategy) {
                if (Route::has('hyper.ladder')) {
                    $items[] = ['href' => route('hyper.ladder'), 'label' => $short, 'current' => $current === $slug, 'test' => 'ladder-chip-'.$slug];
                }

                continue;
            }

            if ($game->kind() === GameKind::Arcade) {
                if (Route::has('pong.ladder')) {
                    $items[] = ['href' => route('pong.ladder'), 'label' => $short, 'current' => $current === $slug, 'test' => 'ladder-chip-'.$slug];
                }

                continue;
            }

            // A score game's standings are its leaderboard page, never a ladder (template T5 lists ladders only).
            if ($game->kind() === GameKind::Score) {
                continue;
            }

            $modes = $slug === 'chess' ? ChessModes::all() : array_keys($game->modes());

            foreach ($modes as $mode) {
                $label = match (true) {
                    $slug === 'chess' => $short.' '.ChessModes::short($mode),
                    count($modes) === 1 => $short,
                    default => $short.' '.GameNames::mode($slug, $mode),
                };
                $items[] = ['href' => route('ladder.show', [$slug, $mode]), 'label' => $label, 'current' => $current === $slug.'/'.$mode, 'test' => 'ladder-chip-'.$slug.'-'.$mode];
            }
        }

        // The current ladder leads (LadderPage.dc.html: the chip of the page first), the others keep their order.
        usort($items, fn (array $a, array $b): int => $b['current'] <=> $a['current']);

        return $items;
    }
}
