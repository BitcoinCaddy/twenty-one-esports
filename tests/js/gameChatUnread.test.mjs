/**
 * The game chat's "new" badge (resources/js/gameChat.js, pages/games/partials/chat.blade.php; DerCaddy,
 * 2026-10-18: "Die letzte Nachricht ist von mir, trotzdem steht da neu"): the opponent's messages since the
 * chat was last read on this device, and none before a message of one's own. The sheet used to count from
 * zero on every page load, and against all messages instead of the opponent's.
 *
 * Run by tests/Feature/GameChatUnreadTest.php; runnable alone with `node --test`.
 */
import assert from 'node:assert/strict';
import { beforeEach, test } from 'node:test';

const store = new Map();
globalThis.localStorage = { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, String(v)), removeItem: (k) => store.delete(k) };
globalThis.window = { addEventListener() {}, removeEventListener() {} };
globalThis.document = { addEventListener() {}, querySelector: () => null, visibilityState: 'visible' };

const { makeRumor } = await import('../../resources/js/nostrChat.js');
const { gameChat, READ_KEY } = await import('../../resources/js/gameChat.js');

const ME = 'a'.repeat(64);
const THEM = 'b'.repeat(64);

/** A page with this game's chat and these messages: a fresh instance, as after a page load. */
function page(messages, match = 'board:7') {
    const chat = gameChat({ me: ME, meName: 'DerCaddy', opponent: { pubkey: THEM, name: 'TickTrickSat' }, match, relays: [], labels: {} });
    chat.init();
    chat.rumors.push(...messages.map(([from, at]) => makeRumor({ sender: from === 'me' ? ME : THEM, recipient: from === 'me' ? THEM : ME, content: `${from} ${at}`, match, now: at })));

    return chat;
}

beforeEach(() => {
    store.clear();
    document.visibilityState = 'visible';
});

test('a message of one\'s own reads everything before it, also on a fresh page', () => {
    // The screenshot of 2026-10-18: the opponent wrote once, the last message is mine.
    assert.equal(page([['them', 100], ['me', 200]]).unread, 0);
    assert.equal(page([['them', 100], ['me', 200], ['them', 300], ['them', 400]]).unread, 2);
    assert.equal(page([['them', 100], ['them', 150]]).unread, 2, 'never read here and never answered: new');
});

test('what was read stays read after a reload, and only later messages of the opponent count', () => {
    const before = page([['them', 100], ['me', 120], ['them', 150]]);
    assert.equal(before.unread, 1);
    before.markRead();
    assert.equal(before.unread, 0);

    assert.equal(page([['them', 100], ['me', 120], ['them', 150]]).unread, 0, 'reloaded');
    assert.equal(page([['them', 100], ['me', 120], ['them', 150], ['them', 900]]).unread, 1, 'one more after the reload');
    assert.equal(page([['them', 100], ['them', 150]], 'board:8').unread, 2, 'another game keeps its own marker');
});

test('the desktop panel in a tab in the background reads nothing; the open sheet does', () => {
    const chat = page([['them', 100], ['them', 200]]);
    document.visibilityState = 'hidden';
    chat.markRead(true);
    assert.equal(chat.unread, 2);
    chat.markRead();
    assert.equal(chat.unread, 0);
});

test('the markers of the newest 300 chats are kept, the oldest go first', () => {
    store.set(READ_KEY, JSON.stringify(Object.fromEntries(Array.from({ length: 305 }, (_, i) => [`${ME}:board:${i}`, (i + 1) * 1000]))));
    page([['them', 999_999]], 'board:new').markRead();

    const kept = JSON.parse(store.get(READ_KEY));
    assert.equal(Object.keys(kept).length, 300);
    assert.equal(kept[`${ME}:board:new`], 999_999_000);
    assert.equal(kept[`${ME}:board:0`], undefined, 'the oldest is gone');
    assert.equal(kept[`${ME}:board:304`], 305_000);

    store.set(READ_KEY, 'not json');
    assert.equal(page([['them', 100]]).unread, 1, 'a broken store counts as never read');
});
