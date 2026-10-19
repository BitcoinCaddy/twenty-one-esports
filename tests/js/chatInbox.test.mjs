/**
 * "Your chats" (resources/js/chatInbox.js, App\Support\Chat\ChatInbox; DerCaddy, 2026-10-18): every chat the
 * league names, with its newest message and how many of the others' messages are new, from the messages this
 * device knows. A game reads its game chat's marker, a desk the desk's, a match room the one this list sets; one's
 * own message reads everything before it.
 *
 * Run by tests/Feature/ChatInboxTest.php; runnable alone with `node --test`.
 */
import assert from 'node:assert/strict';
import { beforeEach, test } from 'node:test';
import { finalizeEvent, generateSecretKey, getPublicKey } from 'nostr-tools/pure';
import * as nip44 from 'nostr-tools/nip44';

const store = new Map();
globalThis.localStorage = { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, String(v)), removeItem: (k) => store.delete(k) };
globalThis.window = { addEventListener() {}, removeEventListener() {} };
globalThis.document = { addEventListener() {}, querySelector: () => null, visibilityState: 'visible', documentElement: { lang: 'de' } };

// The DM relay lookup (dmInbox.js) opens its own sockets: a relay that answers every REQ with EOSE at once.
globalThis.WebSocket = class {
    constructor(url) {
        this.url = url;
        this.readyState = 1;
        queueMicrotask(() => this.onopen?.());
    }

    send(frame) {
        const [type, sub] = JSON.parse(frame);
        if (type === 'REQ') queueMicrotask(() => this.onmessage?.({ data: JSON.stringify(['EOSE', sub]) }));
    }

    close() {
        this.readyState = 3;
        queueMicrotask(() => this.onclose?.());
    }
};

const { makeGroupRumor, makeRumor, wrapMessage } = await import('../../resources/js/nostrChat.js');
const { READ_KEY } = await import('../../resources/js/chatRead.js');
const { chatInbox, deskReadKey, inboxRows, readKeyOf } = await import('../../resources/js/chatInbox.js');

const ME = 'a'.repeat(64);
const ANNA = 'b'.repeat(64);
const BEN = 'c'.repeat(64);
const CARLA = 'd'.repeat(64);

const game = { kind: 'game', key: 'board:7', me: ME, title: 'Anna', match: 'board:7', opponent: ANNA, members: [ME, ANNA], names: { [ANNA]: 'Anna' }, since: 0, settled: null };
const desk = { kind: 'desk', key: 'desk:3', me: ME, title: 'Rheinland Cup', desk: 3, members: [ME, ANNA, BEN, CARLA], managers: [BEN], names: { [ANNA]: 'Anna', [BEN]: 'Ben', [CARLA]: 'Carla' }, since: 0 };
const room = { kind: 'room', key: 'room:12', me: ME, title: 'Match #12', match: '12', members: [ME, CARLA], opponents: [CARLA], names: { [CARLA]: 'Carla' }, since: 0, settled: null };

const dm = (from, to, at, text, match = 'board:7') => makeRumor({ sender: from, recipient: to, content: text, match, now: at });
const deskMsg = (from, at, text) => makeGroupRumor({ sender: from, recipients: [ME, ANNA, BEN, CARLA].filter((p) => p !== from), content: text, match: 'desk-3', tags: [['desk', '3']], now: at });
const roomMsg = (from, at, text) => makeGroupRumor({ sender: from, recipients: [ME, CARLA].filter((p) => p !== from), content: text, match: '12', now: at });

const rows = (rumors, options = {}) => inboxRows([game, desk, room], rumors, { me: ME, labels: { you: 'du' }, now: 2_000_000_000, ...options });

beforeEach(() => store.clear());

test('each chat shows its newest message, newest chat first; a chat without messages stays last', () => {
    const list = rows([dm(ANNA, ME, 1000, 'Gutes Spiel'), deskMsg(BEN, 3000, 'Runde 2 startet\nheute'), dm(ME, ANNA, 1100, 'Danke!'), dm(ANNA, BEN, 5000, 'not ours')]);

    assert.deepEqual(list.map((r) => r.key), ['desk:3', 'board:7', 'room:12']);
    assert.equal(list[0].last.preview, 'Ben: Runde 2 startet heute');
    assert.equal(list[0].last.at, 3_000_000);
    assert.equal(list[1].last.preview, 'du: Danke!');
    assert.equal(list[2].last, null);
});

test('new: the others\' messages after the chat\'s marker and after one\'s own last message', () => {
    const rumors = [dm(ANNA, ME, 1000, 'eins'), dm(ANNA, ME, 1200, 'zwei'), deskMsg(BEN, 3000, 'Info'), deskMsg(ANNA, 3100, 'Frage'), roomMsg(CARLA, 4000, 'Lobby steht')];

    assert.deepEqual(rows(rumors).map((r) => [r.key, r.unread]), [['room:12', 1], ['desk:3', 2], ['board:7', 2]]);

    // The game chat read up to the first message, the desk up to Ben's; one's own answer in the room reads it.
    const markers = { [readKeyOf(game)]: 1000 * 1000 };
    const answered = [...rumors, roomMsg(ME, 4100, 'ok')];
    assert.deepEqual(rows(answered, { markers, deskRead: (id) => (id === 3 ? 3000 : 0) }).map((r) => [r.key, r.unread]), [['room:12', 0], ['desk:3', 1], ['board:7', 1]]);
});

test('a muted player is left out, but never the tournament direction at its desk', () => {
    const list = rows([deskMsg(BEN, 3000, 'Ansage'), deskMsg(ANNA, 3100, 'Spam'), dm(ANNA, ME, 1000, 'hallo')], { muted: [ANNA, BEN] });

    assert.equal(list.find((r) => r.key === 'desk:3').last.preview, 'Ben: Ansage');
    assert.equal(list.find((r) => r.key === 'board:7').last, null);
});

test('the page starts from what this device opened in any chat, and opening a room from the list reads it', () => {
    const cached = (rumor) => ({ [`wrap-${rumor.id}`]: rumor });
    store.set(`esports.chat.cache.game.${ME}`, JSON.stringify(cached(dm(ANNA, ME, 1000, 'aus dem Partie-Chat'))));
    store.set(`esports.chat.cache.desk.${ME}`, JSON.stringify(cached(deskMsg(BEN, 3000, 'aus dem Desk'))));
    store.set(`esports.chat.cache.room.${ME}`, JSON.stringify(cached(roomMsg(CARLA, 4000, 'aus dem Raum'))));
    store.set(deskReadKey(ME, 3), '3000');

    const silent = { ...game, key: 'board:8', match: 'board:8', title: 'Ben', opponent: BEN, members: [ME, BEN], names: { [BEN]: 'Ben' } };
    const page = chatInbox({ me: ME, chats: [game, desk, room, silent], relays: [], labels: { you: 'du', unread: ':count neu' } });
    page.init();

    assert.equal(page.status, 'quiet', 'no relays: nothing to read beyond the device');
    assert.equal(page.searching, false);
    assert.deepEqual(page.rows.map((r) => [r.key, r.last?.preview, r.unread]), [['room:12', 'Carla: aus dem Raum', 1], ['desk:3', 'Ben: aus dem Desk', 0], ['board:7', 'Anna: aus dem Partie-Chat', 1], ['board:8', undefined, 0]]);
    // The list holds the chats somebody wrote in; the silent game folds below it.
    assert.deepEqual([page.active.map((r) => r.key), page.quiet.map((r) => r.key)], [['room:12', 'desk:3', 'board:7'], ['board:8']]);
    assert.equal(page.unreadTotal, 2);

    page.open(page.rows[0]);
    assert.equal(page.rows.find((r) => r.key === 'room:12').unread, 0);
    assert.equal(JSON.parse(store.get(READ_KEY))[`${ME}:room:12`], 4_000_000);
});

function keySigner(secret) {
    const signer = {
        decryptCalls: 0,
        getPublicKey: async () => getPublicKey(secret),
        signEvent: async (template) => finalizeEvent(template, secret),
        nip44: {
            encrypt: async (pubkey, text) => nip44.encrypt(text, nip44.getConversationKey(secret, pubkey)),
            decrypt: async (pubkey, payload) => {
                signer.decryptCalls++;

                return nip44.decrypt(payload, nip44.getConversationKey(secret, pubkey));
            },
        },
    };

    return signer;
}

test('from the relays: a wrap a chat opened before is not opened again, a new one once, into the game chat\'s cache', async () => {
    const keys = { me: generateSecretKey(), anna: generateSecretKey() };
    const pk = { me: getPublicKey(keys.me), anna: getPublicKey(keys.anna) };
    const now = Math.floor(Date.now() / 1000);
    const known = await wrapMessage(keySigner(keys.anna), { sender: pk.anna, recipient: pk.me, content: 'schon geöffnet', match: 'board:7', now: now - 120 });
    const fresh = await wrapMessage(keySigner(keys.anna), { sender: pk.anna, recipient: pk.me, content: 'neu', match: 'board:7', now: now - 60 });
    // The match room opened the first wrap before (every chat reads every wrap to the player).
    store.set(`esports.chat.cache.room.${pk.me}`, JSON.stringify({ [known.toRecipient.id]: known.rumor }));

    const signer = keySigner(keys.me);
    window.nostr = signer;
    const subscriptions = [];
    const chat = { ...game, me: pk.me, opponent: pk.anna, members: [pk.me, pk.anna], names: { [pk.anna]: 'Anna' } };
    const page = chatInbox({ me: pk.me, chats: [chat], relays: ['wss://relay.test'], lookupRelays: [], since: now - 3600, labels: { you: 'du' } });
    page.makePool = () => ({ subscribe: (relays, filter, handlers) => (subscriptions.push({ relays, filter, handlers }), { close() {} }), destroy() {} });

    try {
        page.init();
        assert.equal(page.status, 'live');
        assert.deepEqual(subscriptions[0].relays, ['wss://relay.test']);
        assert.deepEqual([subscriptions[0].filter.kinds, subscriptions[0].filter['#p']], [[1059], [pk.me]]);
        assert.ok(subscriptions[0].handlers.maxWait > 0, 'a silent relay does not keep the list loading');
        assert.equal(page.searching, true, 'the relays have not ended their stored messages');

        // Two relays deliver the new wrap at once; the known one comes again.
        const { onevent, oneose } = subscriptions[0].handlers;
        const delivered = Promise.all([onevent(known.toRecipient), onevent(fresh.toRecipient), onevent(fresh.toRecipient)]);
        oneose();
        assert.equal(page.searching, true, 'a wrap is still being opened');
        await delivered;

        assert.equal(page.searching, false);
        assert.equal(signer.decryptCalls, 2, 'one wrap opened: its wrap and its seal');
        assert.deepEqual(page.rows.map((r) => [r.last?.preview, r.unread]), [['Anna: neu', 2]]);
        assert.equal(JSON.parse(store.get(`esports.chat.cache.game.${pk.me}`))[fresh.toRecipient.id].content, 'neu', 'the game chat finds it opened');
    } finally {
        page.destroy();
        delete window.nostr;
    }
});
