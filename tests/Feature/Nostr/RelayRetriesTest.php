<?php

use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Support\Nostr\RelayRetries;
use App\Support\Nostr\SignedEvent;
use Illuminate\Support\Sleep;
use Tests\Support\TestSigner;

/*
 * The public copy of a signed event (ChessStates "Public record delayed"):
 * nostr:republish backs off and stops after RelayRetries::MAX_ATTEMPTS, the
 * game page shows "retrying" only while a retry is still coming, and
 * nostr:heal-unsent sends the old records no relay ever saw (prod games of
 * 2026-09-25/26, signed while ESPORTS_RELAYS was empty).
 *
 * Closed local ports stand in for the relays: the connection is refused at
 * once and recorded as not accepted.
 */

beforeEach(function () {
    $this->freezeTime();
});

/** A stored kind-64 record queued for the relays, as GameRecords leaves it. */
function retryRecord(): NostrEvent
{
    $event = NostrEvent::fromSigned(SignedEvent::fromInput((new TestSigner)->sign(64, [['alt', 'Chess game record']], "1. f3 e5 2. g4 Qh4# 0-1\n")));
    $event->forceFill(['queued_at' => now()])->save();

    return $event;
}

test('a relay that keeps refusing is tried with backoff and given up after the last attempt', function () {
    config(['esports.relays' => ['ws://127.0.0.1:9']]);
    $event = retryRecord();
    $republish = fn (int $sent) => $this->artisan('nostr:republish')->expectsOutput("Republished {$sent} event(s).")->assertSuccessful();

    $this->travel(2)->minutes();
    $republish(1);
    $republish(0);

    // 5, 10, 20, 40, 80 minutes after the previous try; a run in between sends nothing.
    foreach ([5, 10, 20, 40, 80] as $minutes) {
        $this->travel($minutes - 2)->minutes();
        $republish(0);
        $this->travel(2)->minutes();
        $republish(1);
    }

    expect($event->deliveries()->sole()->attempts)->toBe(RelayRetries::MAX_ATTEMPTS);

    $this->travel(3)->hours();
    $republish(0);
    expect($event->deliveries()->sole()->attempts)->toBe(RelayRetries::MAX_ATTEMPTS);
});

test('the current addressable version stops going to a relay that ran out of attempts, and still reaches a relay added later', function () {
    [$refusing, $added] = ['ws://127.0.0.1:9', 'ws://127.0.0.1:19'];
    $event = NostrEvent::fromSigned(SignedEvent::fromInput((new TestSigner)->sign(31923, [['d', 'cup-1']], 'v1')));
    $event->forceFill(['queued_at' => now()])->save();
    $event->deliveries()->create(['relay' => $refusing, 'accepted' => false, 'message' => 'error: Invalid status code 403.', 'attempted_at' => now(), 'attempts' => RelayRetries::MAX_ATTEMPTS]);

    $this->travel(7)->days();
    config(['esports.relays' => [$refusing]]);
    $this->artisan('nostr:republish')->expectsOutput('Republished 0 event(s).')->assertSuccessful();

    config(['esports.relays' => [$refusing, $added]]);
    $this->artisan('nostr:republish')->expectsOutput('Republished 1 event(s).')->assertSuccessful();

    expect($event->deliveries()->pluck('attempts', 'relay')->sortKeys()->all())->toBe([$added => 1, $refusing => RelayRetries::MAX_ATTEMPTS]);
});

test('the game page says "retrying" only while a retry is coming, then calmly that the league keeps the record', function () {
    config(['esports.relays' => ['ws://127.0.0.1:9', 'ws://127.0.0.1:19']]);
    $record = retryRecord();
    $game = ChessGame::factory()->rated()->finished('0-1')->create(['ply' => 4, 'record_event_id' => $record->id]);

    // Just signed, no relay answered yet: the delayed card.
    $this->get(route('games.show', $game))->assertOk()
        ->assertSee('data-test="record-delayed"', false)
        ->assertDontSee('data-test="record-kept"', false);

    // Every relay out of attempts inside the window: final, no "retrying".
    foreach (['ws://127.0.0.1:9', 'ws://127.0.0.1:19'] as $relay) {
        $record->deliveries()->create(['relay' => $relay, 'accepted' => false, 'message' => 'rate limited', 'attempted_at' => now(), 'attempts' => RelayRetries::MAX_ATTEMPTS]);
    }

    $this->get(route('games.show', $game))->assertOk()
        ->assertDontSee('data-test="record-delayed"', false)
        ->assertSee('data-test="record-kept"', false)
        ->assertDontSee('we retry on our own');

    // Never sent at all (the prod case), but past the window: final as well.
    $record->deliveries()->delete();
    $this->travel(RelayRetries::WINDOW_HOURS + 1)->hours();

    $this->get(route('games.show', $game))->assertOk()
        ->assertDontSee('data-test="record-delayed"', false)
        ->assertSee('data-test="record-kept"', false);

    // One relay took it: published.
    $record->deliveries()->create(['relay' => 'ws://127.0.0.1:9', 'accepted' => true, 'message' => '', 'attempted_at' => now()]);

    $this->get(route('games.show', $game))->assertOk()
        ->assertDontSee('data-test="record-kept"', false)
        ->assertSee('On 1 of 2 relays');
});

test('nostr:heal-unsent plans old regular events no relay accepted, sends nothing without --publish, and drops what a relay took', function () {
    Sleep::fake();
    config(['esports.relays' => ['ws://127.0.0.1:9']]);
    $unsent = retryRecord();
    $accepted = retryRecord();
    $accepted->deliveries()->create(['relay' => 'ws://127.0.0.1:9', 'accepted' => true, 'message' => '', 'attempted_at' => now()]);
    $profile = NostrEvent::fromSigned(SignedEvent::fromInput((new TestSigner)->sign(0, [], '{}')));
    $profile->forceFill(['queued_at' => now()])->save();
    $wrap = NostrEvent::fromSigned(SignedEvent::fromInput((new TestSigner)->sign(1059, [], 'sealed')));
    $wrap->forceFill(['queued_at' => now()])->save();
    $storedOnly = NostrEvent::fromSigned(SignedEvent::fromInput((new TestSigner)->sign(64, [], 'stored only')));

    $this->travel(RelayRetries::WINDOW_HOURS + 1)->hours();
    $fresh = retryRecord();

    $this->artisan('nostr:heal-unsent')
        ->expectsOutputToContain('no relay accepted: 1')
        ->expectsOutputToContain("#{$unsent->id} kind 64")
        ->doesntExpectOutputToContain("#{$accepted->id} kind")
        ->doesntExpectOutputToContain("#{$profile->id} kind")
        ->doesntExpectOutputToContain("#{$wrap->id} kind")
        ->doesntExpectOutputToContain("#{$storedOnly->id} kind")
        ->doesntExpectOutputToContain("#{$fresh->id} kind")
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect($unsent->deliveries()->count())->toBe(0);

    // The closed port refuses: sent, recorded, still unaccepted, so a second run plans it again.
    $this->artisan('nostr:heal-unsent --publish --kind=64')
        ->expectsOutputToContain("#{$unsent->id}: accepted by 0 of 1 relays")
        ->assertSuccessful();

    expect($unsent->deliveries()->sole()->accepted)->toBeFalse();

    // Once a relay took it, it drops out: the command is safe to run again.
    $unsent->deliveries()->update(['accepted' => true]);

    $this->artisan('nostr:heal-unsent --publish')
        ->expectsOutputToContain('no relay accepted: 0')
        ->expectsOutputToContain('Sent 0 event(s)')
        ->assertSuccessful();
});

test('nostr:heal-unsent refuses to run without a configured relay', function () {
    config(['esports.relays' => []]);

    $this->artisan('nostr:heal-unsent')->expectsOutputToContain('No relay is configured')->assertFailed();
});
