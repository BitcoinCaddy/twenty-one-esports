<?php

use App\Support\Caster\Pronunciation;

it('speaks German dates, weekdays and times the way a person says them', function (string $written, string $spoken) {
    expect(Pronunciation::dates($written))->toBe($spoken);
})->with([
    ['Blockli Cup 1 startet Mi, 14. Okt 2026, 19:00 MESZ.', 'Blockli Cup 1 startet Mittwoch, der vierzehnte Oktober 2026, 19 Uhr.'],
    ['Anmeldung bis zum 11. Oktober.', 'Anmeldung bis zum elften Oktober.'],
    ['Am 3. Okt war Finale.', 'Am dritten Oktober war Finale.'],
    ['Heute ist der 1. November.', 'Heute ist der erste November.'],
    ['Start um 20:06 CEST.', 'Start um 20 Uhr 6.'],
    ['Bis Sa, 3. Okt.', 'Bis Samstag, der dritte Oktober.'],
]);
