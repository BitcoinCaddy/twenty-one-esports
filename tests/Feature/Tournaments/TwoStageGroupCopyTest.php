<?php

use App\Enums\TournamentFormat;
use App\Support\Tournaments\FormatCopy;

/*
| DerCaddy, 2026-10-14, Blockli Cup #1: "Oben steht, Gruppenphase = jeder gegen jeden, unten steht, dass nur ein Spiel
| sicher ist." Knockout groups were described as round-robin groups; the copy follows the group stage now.
*/

it('describes knockout groups as knockout groups, and round-robin groups as everyone against everyone', function () {
    expect(FormatCopy::for(TournamentFormat::TwoStage)['how'])->toContain('everyone plays everyone')
        ->and(FormatCopy::for(TournamentFormat::TwoStage, 'round-robin')['how'])->toContain('everyone plays everyone')
        ->and(FormatCopy::for(TournamentFormat::TwoStage, 'single-elimination')['how'])->toContain('each play a knockout bracket')->not->toContain('everyone plays everyone')
        ->and(FormatCopy::for(TournamentFormat::TwoStage, 'double-elimination')['how'])->toContain('double-elimination bracket')
        ->and(FormatCopy::for(TournamentFormat::RoundRobin, 'single-elimination'))->toBe(FormatCopy::for(TournamentFormat::RoundRobin));

    app()->setLocale('de');
    expect(__(FormatCopy::for(TournamentFormat::TwoStage, 'single-elimination')['how']))->toContain('im K.-o.-Modus')
        ->and(trans_choice('Everyone plays at least :games game in the groups. The top :advance of each group play on.|Everyone plays at least :games games in the groups. The top :advance of each group play on.', 1, ['games' => 1, 'advance' => 2]))
        ->toBe('Alle spielen mindestens 1 Spiel in der Gruppe. Die besten 2 jeder Gruppe spielen weiter.');
});
