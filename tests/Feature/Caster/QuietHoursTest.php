<?php

use App\Support\Caster\QuietHours;
use Carbon\CarbonImmutable;

/*
| Quiet hours (user 2026-10-12: "zwischen 01:00 und 09:00 nur noch fix alle 10 Minuten ein Audio").
*/

beforeEach(fn () => config(['tv.commentary.spool.quiet_hours.from' => '01:00']));

it('is quiet from one to nine in Berlin, and not a minute outside', function (string $clock, bool $quiet) {
    expect(QuietHours::active(CarbonImmutable::parse("2026-10-12 {$clock}", 'Europe/Berlin')))->toBe($quiet);
})->with([
    'before' => ['00:59', false],
    'start' => ['01:00', true],
    'night' => ['04:30', true],
    'last minute' => ['08:59', true],
    'end' => ['09:00', false],
    'afternoon' => ['15:00', false],
]);

it('spaces clips ten minutes apart and keeps a small queue, both set per env', function () {
    expect(QuietHours::everySeconds())->toBe(600.0)
        ->and(QuietHours::aheadSeconds())->toBe(90.0);

    config(['tv.commentary.spool.quiet_hours.every_minutes' => 15, 'tv.commentary.spool.quiet_hours.from' => '22:00', 'tv.commentary.spool.quiet_hours.to' => '06:00']);

    expect(QuietHours::everySeconds())->toBe(900.0)
        ->and(QuietHours::active(CarbonImmutable::parse('2026-10-12 23:30', 'Europe/Berlin')))->toBeTrue()
        ->and(QuietHours::active(CarbonImmutable::parse('2026-10-12 12:00', 'Europe/Berlin')))->toBeFalse();
});
