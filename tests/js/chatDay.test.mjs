/**
 * The chats' new day (resources/js/chatTime.js onChatDay(); the Hyperbitcoinization chats draw their labels once
 * and relabel when it fires): a page left open overnight turns yesterday's "20:24" into "Mo 20:24" at the next
 * LOCAL midnight, also on the night the clocks go forward (Berlin, Sunday 28 March 2027: that day has 23 hours).
 * The Hyperbitcoinization chats draw their labels once and relabel when it fires (hyper/chat.js, hyper/teamChat.js).
 * A file of its own: the watcher is one per page, so each test file gets a fresh one.
 *
 * Run by tests/Feature/ChatTimeTest.php; runnable alone with `node --test`.
 */
process.env.TZ = 'Europe/Berlin';

import assert from 'node:assert/strict';
import { mock, test } from 'node:test';

const listeners = {};

// A page that accepts whatever the chats do to it: any element answers every method with itself. Only the list of
// message times is real, so what the chats write into it can be read back.
const times = [];
function element(own = {}) {
    const target = { dataset: {}, classList: { add() {}, remove() {}, toggle() {}, contains: () => false }, ...own };
    const self = new Proxy(target, { get: (t, key) => (key in t || typeof key === 'symbol' ? t[key] : (...args) => (key === 'querySelectorAll' ? [] : self)) });

    return self;
}
const list = element({ querySelectorAll: (selector) => (selector === '.cm-time' ? times : []) });
const elements = { '#chat-list': list, '#team-list': list };
globalThis.document = {
    documentElement: { lang: 'de' },
    visibilityState: 'visible',
    addEventListener: (type, fn) => (listeners[type] = fn),
    querySelector: (selector) => (selector.startsWith('meta') ? null : (elements[selector] ?? element())),
    createElement: () => element(),
};
globalThis.window = { addEventListener() {}, removeEventListener() {}, matchMedia: () => ({ matches: false }) };
globalThis.localStorage = { getItem: () => null, setItem() {} };

const { chatTime, nextDayStart, onChatDay } = await import('../../resources/js/chatTime.js');
const { startChat } = await import('../../resources/js/hyper/chat.js');
const { startTeamChat } = await import('../../resources/js/hyper/teamChat.js');

const at = (y, m, d, h = 0, min = 0) => new Date(y, m - 1, d, h, min).getTime();
const HOUR = 3_600_000;

test('the next midnight is a calendar day away, not 24 hours: 23 hours on the night the clocks go forward', () => {
    assert.equal(nextDayStart(at(2027, 3, 27, 23, 50)), at(2027, 3, 28));
    assert.equal(nextDayStart(at(2027, 3, 28, 0, 30)), at(2027, 3, 29));
    assert.equal(nextDayStart(at(2027, 3, 28, 0, 30)) - at(2027, 3, 28, 0, 30), 22.5 * HOUR);
});

test('a chat that asked is told the new day at each local midnight, also the short one, and not after it left', () => {
    mock.timers.enable({ apis: ['setTimeout', 'Date'], now: at(2027, 3, 27, 23, 50) });
    const days = [];
    const leave = onChatDay((today) => days.push(today));

    mock.timers.tick(10 * 60_000 + 999);
    assert.deepEqual(days, [], 'a second past midnight, not before');
    mock.timers.tick(1);
    assert.deepEqual(days, [at(2027, 3, 28)]);

    // The day of the clock change has 23 hours: the next midnight is 23 hours after this one's second.
    mock.timers.tick(23 * HOUR - 1);
    assert.deepEqual(days, [at(2027, 3, 28)], 'not yet: 24 hours later would be an hour late');
    mock.timers.tick(1);
    assert.deepEqual(days, [at(2027, 3, 28), at(2027, 3, 29)]);

    leave();
    mock.timers.tick(24 * HOUR);
    assert.equal(days.length, 2, 'a chat that left hears nothing');

    mock.timers.reset();
});

test('a page that comes back into view the next morning learns the day at once', () => {
    mock.timers.enable({ apis: ['Date'], now: at(2027, 4, 2, 7, 15) });
    const days = [];
    const leave = onChatDay((today) => days.push(today));

    document.visibilityState = 'hidden';
    listeners.visibilitychange();
    assert.deepEqual(days, [], 'in the background nothing is relabelled');

    document.visibilityState = 'visible';
    listeners.visibilitychange();
    listeners.visibilitychange();
    assert.deepEqual(days, [at(2027, 4, 2)], 'once, however often it is asked');

    leave();
    mock.timers.reset();
});

// Thursday, 1 April 2027, 20:24 in Berlin: a message the page was opened with.
const sent = new Date(at(2027, 4, 1, 20, 24));

/** Opens a chat on `from`, lets the clock run to `to`, and reports what the chat made of the message's time label. */
function labelAfterMidnight(open, from, to) {
    mock.timers.enable({ apis: ['Date'], now: from });
    try {
        times.splice(0, times.length, { dateTime: sent.toISOString(), textContent: '20:24' });
        open();
        assert.equal(times[0].textContent, '20:24', 'drawing the chat does not touch the labels it was given');
        mock.timers.setTime(to);
        listeners.visibilitychange();

        return times[0].textContent;
    } finally {
        mock.timers.reset();
    }
}

test('the table chat relabels its message times at the next day, in its own locale', () => {
    assert.equal(labelAfterMidnight(() => startChat({ channel: null, relays: [], locale: 'en-GB' }), at(2027, 4, 2, 7, 15), at(2027, 4, 3, 7, 15)), 'Thu 20:24');
});

test('the team chat relabels its message times at the next day, in the page\'s language', () => {
    assert.equal(labelAfterMidnight(() => startTeamChat({}, { http: null }), at(2027, 4, 3, 7, 15), at(2027, 4, 4, 7, 15)), 'Do 20:24');
});
