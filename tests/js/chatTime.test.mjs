/**
 * The time beside a chat message (resources/js/chatTime.js; DerCaddy, 2026-10-17: "bei älteren Nachrichten
 * nicht den Wochentag bzw bei Nachrichten die älter als eine Woche sind, das Datum"): the time today, the
 * weekday within the week, the date from seven days on, the year from another year. Calendar days in the
 * device's zone, Berlin here, across the end of summer time on 25 October 2026.
 *
 * Run by tests/Feature/ChatTimeTest.php; runnable alone with `node --test`.
 */
process.env.TZ = 'Europe/Berlin';

import assert from 'node:assert/strict';
import { mock, test } from 'node:test';

const listeners = {};
globalThis.document = { documentElement: { lang: 'de' }, visibilityState: 'visible', addEventListener: (type, fn) => (listeners[type] = fn) };

const { chatDay, chatTime, daysAgo } = await import('../../resources/js/chatTime.js');

const at = (y, m, d, h = 0, min = 0) => new Date(y, m - 1, d, h, min).getTime();
// Monday, 19 October 2026, noon.
const now = at(2026, 10, 19, 12, 0);

test('today the time, this week the weekday, from seven days on the date, another year with the year', () => {
    assert.equal(chatTime(at(2026, 10, 19, 9, 57), { now }), '09:57');
    assert.equal(chatTime(at(2026, 10, 19, 0, 0), { now }), '00:00', 'just after midnight is today');
    assert.equal(chatTime(at(2026, 10, 18, 20, 24), { now }), 'So 20:24');
    assert.equal(chatTime(at(2026, 10, 18, 23, 59), { now }), 'So 23:59', 'just before midnight is yesterday');
    assert.equal(chatTime(at(2026, 10, 13, 8, 5), { now }), 'Di 08:05', 'six days back: still the weekday');
    assert.equal(chatTime(at(2026, 10, 12, 20, 24), { now }), '12.10., 20:24', 'seven days back: the same weekday as today, so the date');
    assert.equal(chatTime(at(2026, 1, 2, 7, 0), { now }), '02.01., 07:00');
    assert.equal(chatTime(at(2025, 10, 12, 20, 24), { now }), '12.10.2025, 20:24');
    assert.equal(chatTime(at(2025, 12, 31, 23, 0), { now: at(2026, 1, 1, 10, 0) }), 'Mi 23:00', 'last year but yesterday: the weekday');
    assert.equal(chatTime(now + 90_000, { now }), '12:01', 'a sender\'s clock ahead of this one: today');
});

test('the words follow the page\'s language or the chat\'s own locale', () => {
    assert.equal(chatTime(at(2026, 10, 18, 20, 24), { now, locale: 'en-GB' }), 'Sun 20:24');
    assert.equal(chatTime(at(2026, 10, 12, 20, 24), { now, locale: 'en-GB' }), '12/10, 20:24');
    document.documentElement.lang = 'en-GB';
    assert.equal(chatTime(at(2026, 10, 16, 9, 0), { now }), 'Fri 09:00');
    document.documentElement.lang = 'de';
});

test('calendar days, not 24-hour spans, also across the end of summer time', () => {
    // Summer time ended on Sunday, 25 October 2026, at 03:00: that day had 25 hours.
    const monday = at(2026, 10, 26, 0, 30);
    assert.equal(daysAgo(at(2026, 10, 25, 1, 30), monday), 1);
    assert.equal(daysAgo(at(2026, 10, 24, 23, 0), monday), 2);
    assert.equal(chatTime(at(2026, 10, 25, 2, 30), { now: monday }), 'So 02:30');
    assert.equal(daysAgo(at(2026, 10, 19, 23, 59), monday), 7);
});

test('a page left open overnight gets a new day: at midnight and when it comes back into view', () => {
    const stores = {};
    globalThis.window = { Alpine: { store: (name, value) => (value === undefined ? stores[name] : (stores[name] = value)) } };
    const clock = mock.method(Date, 'now', () => at(2026, 10, 19, 23, 50));

    assert.equal(chatDay(), at(2026, 10, 19));
    assert.equal(stores.chatDay.today, at(2026, 10, 19));

    clock.mock.mockImplementation(() => at(2026, 10, 20, 7, 15));
    document.visibilityState = 'visible';
    listeners.visibilitychange();
    assert.equal(stores.chatDay.today, at(2026, 10, 20), 'back into view the next morning');
    assert.equal(chatDay(), at(2026, 10, 20));

    clock.mock.restore();
    delete globalThis.window;
});

test('a chat that always printed 24 hours keeps them in every language', () => {
    assert.equal(chatTime(at(2026, 10, 19, 13, 34), { now, locale: 'en', hourCycle: 'h23' }), '13:34');
    assert.equal(chatTime(at(2026, 10, 18, 13, 34), { now, locale: 'en', hourCycle: 'h23' }), 'Sun 13:34');
    assert.equal(chatTime(at(2026, 10, 19, 13, 34), { now, locale: 'en' }), '01:34 PM', 'the channels and the live chat: the locale\'s clock, as before');
});
