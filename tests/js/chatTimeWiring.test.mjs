/**
 * What each chat does with the shared time rule (resources/js/chatTime.js): the match room and a game's chat keep
 * 24 hours in every language, the game channel and the live chat the locale of their own config. chatTime.test.mjs
 * proves the rule; this proves each chat calls it with its own clock and locale.
 *
 * Run by tests/Feature/ChatTimeTest.php; runnable alone with `node --test`.
 */
process.env.TZ = 'Europe/Berlin';

import assert from 'node:assert/strict';
import { mock, test } from 'node:test';

globalThis.localStorage = { getItem: () => null, setItem: () => {} };
globalThis.window = { matchMedia: () => ({ matches: false }), addEventListener() {}, removeEventListener() {} };
globalThis.document = { documentElement: { lang: 'de' }, visibilityState: 'visible', addEventListener() {}, querySelector: () => null };

const { gameChat } = await import('../../resources/js/gameChat.js');
const { roomChat } = await import('../../resources/js/roomChat.js');
const { gameChannel } = await import('../../resources/js/gameChannel.js');
const { liveChat } = await import('../../resources/js/liveChat.js');

// Monday, 19 October 2026, noon, on the chats' own clock: the labels read Date.now().
const at = (d, h, min) => new Date(2026, 9, d, h, min).getTime();
mock.method(Date, 'now', () => at(19, 12, 0));
const today = new Date(at(19, 13, 34));
const yesterday = new Date(at(18, 13, 34));
const labels = new Proxy({}, { get: (_, key) => String(key) });

test('the match room and a game\'s chat print 24 hours whatever the page language is', () => {
    document.documentElement.lang = 'en';
    const room = roomChat({ me: 'a'.repeat(64), members: [], match: 'm', relays: [], labels, casual: null });
    const game = gameChat({ me: 'a'.repeat(64), meName: 'me', opponent: { pubkey: 'b'.repeat(64), name: 'o' }, match: 'board:1', relays: [], labels });

    assert.equal(room.time(today.getTime()), '13:34');
    assert.equal(game.time(today.getTime()), '13:34');
    assert.match(room.time(yesterday.getTime()), /\b13:34$/);
    document.documentElement.lang = 'de';
});

test('the game channel and the live chat print in their own configured locale, not the page\'s', () => {
    const channel = gameChannel({ channel: 'c'.repeat(64), creator: 'd'.repeat(64), relays: [], labels, me: null, meName: 'me', meCounts: false, locale: 'en-US' });
    const live = liveChat({ relays: [], labels, locale: 'en-US' });

    // en-US: 12-hour clock with a day period; the page (de) would print 13:34.
    assert.match(channel.time(today.getTime() / 1000), /^0?1:34\s?PM$/);
    assert.match(live.time(today.getTime() / 1000), /^0?1:34\s?PM$/);
    // The day before: the weekday in that locale (the old per-chat code printed the bare time).
    assert.match(channel.time(yesterday.getTime() / 1000), /^Sun\s0?1:34\s?PM$/);
    assert.match(live.time(yesterday.getTime() / 1000), /^Sun\s0?1:34\s?PM$/);
});
