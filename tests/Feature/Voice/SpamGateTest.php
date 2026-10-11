<?php

use App\Support\Voice\SpamGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The spam gate of the bot's chat and profile posts (P1)
|--------------------------------------------------------------------------
|
| It only ever holds a post back. Each rule on its own; every skip counts
| its reason for `voice:usage`.
|
*/

beforeEach(function () {
    config(['esports.stream_bot.quiet_hours' => null, 'esports.stream_bot.interval_minutes' => 22, 'esports.stream_bot.jitter_minutes' => 6]);
});

function spamGate(): SpamGate
{
    return app(SpamGate::class);
}

function spamNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-11 14:00:00', 'Europe/Berlin');
}

it('lets the first post through', function () {
    expect(spamGate()->skip(SpamGate::CHAT, 'win', ['t1'], spamNow()))->toBeNull();
});

it('lets everything through when switched off', function () {
    config(['esports.voice.spam.enabled' => false]);
    spamGate()->record(SpamGate::CHAT, 'Satoshi gewinnt.', 'win', ['t1'], spamNow());

    expect(spamGate()->skip(SpamGate::CHAT, 'win', ['t1'], spamNow()))->toBeNull()
        ->and(spamGate()->textSkip(SpamGate::CHAT, 'Satoshi gewinnt.', spamNow()))->toBeNull();
});

it('holds a post back in the quiet hours', function () {
    config(['esports.stream_bot.quiet_hours' => '13-15']);

    expect(spamGate()->skip(SpamGate::CHAT, 'win', ['t1'], spamNow()))->toBe(SpamGate::QUIET_HOURS);
});

it('holds a chat post back once the stream bot daily cap is reached', function () {
    config(['esports.stream_bot.daily_cap' => 2, 'esports.stream_bot.interval_minutes' => 1, 'esports.stream_bot.jitter_minutes' => 0]);
    spamGate()->record(SpamGate::CHAT, 'Eins.', 'win', ['a'], spamNow()->subHours(3));
    spamGate()->record(SpamGate::CHAT, 'Zwei.', 'win', ['b'], spamNow()->subHours(2));

    expect(spamGate()->skip(SpamGate::CHAT, 'win', ['c'], spamNow()))->toBe(SpamGate::DAILY_CAP);
});

it('holds a chat post back within the stream bot interval', function () {
    spamGate()->record(SpamGate::CHAT, 'Satoshi gewinnt.', 'win', ['t1'], spamNow()->subMinutes(10));

    expect(spamGate()->skip(SpamGate::CHAT, 'win', ['t2'], spamNow()))->toBe(SpamGate::INTERVAL)
        ->and(spamGate()->skip(SpamGate::CHAT, 'win', ['t2'], spamNow()->addMinutes(7)))->toBeNull();
});

it('holds a profile note back within the profile gap', function () {
    spamGate()->record(SpamGate::PROFILE, 'Satoshi gewinnt.', 'win', ['t1'], spamNow()->subMinutes(120));

    expect(spamGate()->skip(SpamGate::PROFILE, 'win', ['t2'], spamNow()))->toBe(SpamGate::INTERVAL)
        ->and(spamGate()->skip(SpamGate::PROFILE, 'win', ['t2'], spamNow()->addMinutes(61)))->toBeNull();
});

it('doubles the chat interval after three unanswered posts and resets when a human writes', function () {
    foreach ([90, 60, 30] as $minutesAgo) {
        spamGate()->record(SpamGate::CHAT, "Post vor {$minutesAgo} Minuten.", 'win', ["t{$minutesAgo}"], spamNow()->subMinutes($minutesAgo));
    }

    expect(spamGate()->intervalSeconds(SpamGate::CHAT))->toBe(2 * 16 * 60)
        ->and(spamGate()->skip(SpamGate::CHAT, 'win', ['new'], spamNow()))->toBe(SpamGate::INTERVAL);

    spamGate()->humanWrote();

    expect(spamGate()->intervalSeconds(SpamGate::CHAT))->toBe(16 * 60)
        ->and(spamGate()->skip(SpamGate::CHAT, 'win', ['new'], spamNow()))->toBeNull();
});

it('holds back the same moment about the same subject within repeat_hours', function () {
    spamGate()->record(SpamGate::PROFILE, 'Satoshi gewinnt den Cup.', 'champion', ['tournament:7'], spamNow()->subHours(5));

    expect(spamGate()->skip(SpamGate::PROFILE, 'champion', ['tournament:7'], spamNow()))->toBe(SpamGate::TOPIC_REPEAT)
        ->and(spamGate()->skip(SpamGate::PROFILE, 'champion', ['tournament:8'], spamNow()))->toBeNull();
});

it('merges a burst of one moment type into one roundup', function () {
    $moments = array_map(fn (int $i) => ['moment' => 'win', 'priority' => 1, 'facts' => ['match' => $i]], range(1, 8));

    $merged = spamGate()->collapse($moments);

    expect($merged['moment'])->toBe('roundup')
        ->and($merged['facts']['count'])->toBe(8)
        ->and($merged['facts']['items'])->toHaveCount(8);
});

it('keeps only the most important moment of a small burst', function () {
    $merged = spamGate()->collapse([
        ['moment' => 'win', 'priority' => 1, 'facts' => ['match' => 1]],
        ['moment' => 'champion', 'priority' => 5, 'facts' => ['tournament' => 7]],
    ]);

    expect($merged['moment'])->toBe('champion');
});

it('mentions a person once per day on the profile', function () {
    spamGate()->record(SpamGate::PROFILE, 'Glückwunsch.', 'win', ['t1'], spamNow()->subHours(3), mentioned: ['abc']);

    expect(spamGate()->mentionAllowed(SpamGate::PROFILE, 'abc', 'win', spamNow()))->toBeFalse()
        ->and(spamGate()->mentionAllowed(SpamGate::PROFILE, 'abc', 'win', spamNow()->addHours(22)))->toBeTrue()
        ->and(spamGate()->mentionAllowed(SpamGate::PROFILE, 'def', 'win', spamNow()))->toBeTrue();
});

it('mentions a person once per stream session in the chat', function () {
    spamGate()->record(SpamGate::CHAT, 'Glückwunsch.', 'win', ['t1'], spamNow()->subHours(3), mentioned: ['abc'], session: 'stream-1');

    expect(spamGate()->mentionAllowed(SpamGate::CHAT, 'abc', 'win', spamNow(), 'stream-1'))->toBeFalse()
        ->and(spamGate()->mentionAllowed(SpamGate::CHAT, 'abc', 'win', spamNow(), 'stream-2'))->toBeTrue();
});

it('allows one extra mention for a champion moment', function () {
    spamGate()->record(SpamGate::PROFILE, 'Glückwunsch.', 'win', ['t1'], spamNow()->subHours(3), mentioned: ['abc']);

    expect(spamGate()->mentionAllowed(SpamGate::PROFILE, 'abc', 'champion', spamNow()))->toBeTrue();

    spamGate()->record(SpamGate::PROFILE, 'Champion!', 'champion', ['t2'], spamNow()->subHours(1), mentioned: ['abc']);

    expect(spamGate()->mentionAllowed(SpamGate::PROFILE, 'abc', 'champion', spamNow()))->toBeFalse();
});

it('rejects a voice text over the surface length', function () {
    expect(spamGate()->textSkip(SpamGate::CHAT, str_repeat('Satoshi gewinnt ', 12).'.', spamNow()))->toBe(SpamGate::TOO_LONG)
        ->and(spamGate()->textSkip(SpamGate::PROFILE, str_repeat('Satoshi gewinnt ', 12).'.', spamNow()))->toBeNull();
});

it('rejects a voice text with more than two sentences', function () {
    expect(spamGate()->textSkip(SpamGate::CHAT, 'Satoshi gewinnt. Hal verliert. Weiter geht es. Wer spielt jetzt?', spamNow()))->toBe(SpamGate::TOO_MANY_SENTENCES);
});

it('does not hold a template back for its length', function () {
    expect(spamGate()->textSkip(SpamGate::CHAT, str_repeat('Satoshi gewinnt ', 12).'.', spamNow(), template: true))->toBeNull();
});

it('never lets the same text out twice', function () {
    spamGate()->record(SpamGate::CHAT, 'Satoshi gewinnt das Spiel.', 'win', ['t1'], spamNow()->subDays(5));

    expect(spamGate()->textSkip(SpamGate::CHAT, 'Satoshi gewinnt das Spiel!', spamNow(), template: true))->toBe(SpamGate::TEXT_REPEAT);
});

it('rejects a near copy of a recent post', function () {
    spamGate()->record(SpamGate::CHAT, 'Satoshi gewinnt das Spiel gegen Hal deutlich.', 'win', ['t1'], spamNow()->subHours(2));

    expect(spamGate()->textSkip(SpamGate::CHAT, 'Satoshi gewinnt das Spiel gegen Hal deutlich!!', spamNow()))->not->toBeNull()
        ->and(spamGate()->textSkip(SpamGate::CHAT, 'Satoshi gewinnt das Spiel gegen Hal ganz deutlich.', spamNow()))->toBe(SpamGate::NEAR_COPY);
});

it('rejects the same opening as a recent post', function () {
    spamGate()->record(SpamGate::CHAT, 'Was für ein Abend: Satoshi gewinnt.', 'win', ['t1'], spamNow()->subHours(2));

    expect(spamGate()->textSkip(SpamGate::CHAT, 'Was für ein Abend, Hal holt den Cup.', spamNow()))->toBe(SpamGate::OPENING_REPEAT);
});

it('rejects a canon entry a recent post used', function () {
    spamGate()->record(SpamGate::CHAT, 'Satoshi stapelt Sats.', 'win', ['t1'], spamNow()->subHours(2), canonId: 'e21-sats-stapeln');

    expect(spamGate()->textSkip(SpamGate::CHAT, 'Hal stapelt heute auch.', spamNow(), canonId: 'e21-sats-stapeln'))->toBe(SpamGate::CANON_REPEAT);
});

it('rejects the same form three times in a row', function () {
    spamGate()->record(SpamGate::CHAT, 'Eins für Satoshi.', 'win', ['t1'], spamNow()->subHours(3), form: 'hype');
    spamGate()->record(SpamGate::CHAT, 'Zwei für Hal.', 'win', ['t2'], spamNow()->subHours(2), form: 'hype');

    expect(spamGate()->textSkip(SpamGate::CHAT, 'Drei für Ada.', spamNow(), form: 'hype'))->toBe(SpamGate::FORM_REPEAT)
        ->and(spamGate()->textSkip(SpamGate::CHAT, 'Drei für Ada.', spamNow(), form: 'deadpan'))->toBeNull();
});

it('rejects the same link within a day, query and fragment ignored', function () {
    spamGate()->record(SpamGate::CHAT, 'Spielt mit: https://esports.einundzwanzig.space/play', 'signup_open', ['t1'], spamNow()->subHours(5));

    expect(spamGate()->textSkip(SpamGate::CHAT, 'Komm rein https://esports.einundzwanzig.space/play/?ref=bot#top', spamNow()))->toBe(SpamGate::LINK_REPEAT)
        ->and(spamGate()->textSkip(SpamGate::CHAT, 'Heute Abend wieder offen: https://esports.einundzwanzig.space/play', spamNow()->addHours(20)))->toBeNull();
});

it('rejects a third link to the same kind of target in a day', function () {
    spamGate()->record(SpamGate::CHAT, 'A https://esports.einundzwanzig.space/tournaments/1', 'signup_open', ['t1'], spamNow()->subHours(5));
    spamGate()->record(SpamGate::CHAT, 'B https://esports.einundzwanzig.space/tournaments/2', 'signup_open', ['t2'], spamNow()->subHours(4));

    expect(spamGate()->textSkip(SpamGate::CHAT, 'Noch ein Turnier für heute https://esports.einundzwanzig.space/tournaments/3', spamNow()))->toBe(SpamGate::LINK_KIND_REPEAT);
});

it('drops a template line whose link repeats instead of repeating it', function () {
    spamGate()->record(SpamGate::CHAT, 'https://esports.einundzwanzig.space/play', 'signup_open', ['t1'], spamNow()->subHours(5));

    expect(spamGate()->withoutRepeatedLinks(SpamGate::CHAT, ['🏆 Satoshi gewinnt', '👉 https://esports.einundzwanzig.space/play'], spamNow()))
        ->toBe(['🏆 Satoshi gewinnt']);
});

it('counts every skip reason for voice:usage', function () {
    spamGate()->record(SpamGate::CHAT, 'Satoshi gewinnt.', 'win', ['t1'], spamNow()->subMinutes(5));
    spamGate()->skip(SpamGate::CHAT, 'win', ['t2'], spamNow());

    expect(DB::table('voice_rejections')->where('channel', 'spam_chat')->pluck('count', 'reason')->all())->toBe([SpamGate::INTERVAL => 1]);
});
