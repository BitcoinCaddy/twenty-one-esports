/**
 * A gift wrap the signer could not open just now (resources/js/roomChat.js,
 * receive()): a remote signer timed out, an extension's prompt was closed,
 * the signer was busy with the history. Until 2026-10-12 such a wrap was
 * cached as null and never opened again, in this session or any later one:
 * the message was gone for this reader (tournament 27, "messages do not
 * arrive"). Now a transient unwrap failure is retried and kept out of the
 * cache; only a wrap that is no valid NIP-17 message for me stays null.
 * Run by tests/Feature/Tournaments/ChatUnwrapRetryTest.php; runnable alone
 * with `node --test`.
 */
import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';
import { finalizeEvent, generateSecretKey, getPublicKey } from 'nostr-tools/pure';
import * as nip44 from 'nostr-tools/nip44';

const store = new Map();
globalThis.localStorage = { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, String(v)), removeItem: (k) => store.delete(k) };
globalThis.window = { addEventListener() {}, removeEventListener() {} };
globalThis.document = { addEventListener() {}, querySelector: () => null };

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

const { wrapGroupMessage } = await import('../../resources/js/nostrChat.js');
const { UNWRAP_RETRIES, roomChat } = await import('../../resources/js/roomChat.js');

// Short retries for the test; the production delays are seconds.
UNWRAP_RETRIES.splice(0, UNWRAP_RETRIES.length, 20, 20, 20);

function keySigner(secret, { failDecrypts = 0 } = {}) {
    let failures = 0;
    const signer = {
        decryptCalls: 0,
        getPublicKey: async () => getPublicKey(secret),
        signEvent: async (template) => finalizeEvent(template, secret),
        nip44: {
            encrypt: async (pubkey, text) => nip44.encrypt(text, nip44.getConversationKey(secret, pubkey)),
            decrypt: async (pubkey, payload) => {
                signer.decryptCalls++;
                if (failures < failDecrypts) {
                    failures++;
                    throw new Error('signer timeout');
                }

                return nip44.decrypt(payload, nip44.getConversationKey(secret, pubkey));
            },
        },
    };

    return signer;
}

const keys = { me: generateSecretKey(), opp: generateSecretKey() };
const pk = { me: getPublicKey(keys.me), opp: getPublicKey(keys.opp) };
const NOW = Math.floor(Date.now() / 1000);
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const made = [];
afterEach(() => made.splice(0).forEach((comp) => comp.destroy()));

function room(signer) {
    store.clear();
    window.nostr = signer;
    const comp = roomChat({
        me: pk.me,
        members: [{ pubkey: pk.me, name: 'me' }, { pubkey: pk.opp, name: 'opp' }],
        casual: null,
        match: 53,
        since: NOW - 86400,
        settled: null,
        relays: ['wss://chat.one'],
        lookupRelays: [],
        muted: [],
        labels: new Proxy({}, { get: (_, key) => String(key) }),
    });
    comp.makePool = () => ({ subscribe: () => ({ close() {} }), publish: (relays) => relays.map(() => Promise.resolve('')), destroy() {} });
    comp.init();
    made.push(comp);

    return comp;
}

/** A message from opp to me, as its wrap addressed to me. */
async function wrapToMe(content) {
    const { wraps, targets } = await wrapGroupMessage(keySigner(keys.opp), { sender: pk.opp, recipients: [pk.me], content, match: 53, now: NOW });

    return wraps[targets.indexOf(pk.me)];
}

test('a wrap the signer could not open is not cached and shows up once the signer answers again', async () => {
    const signer = keySigner(keys.me, { failDecrypts: 1 });
    const comp = room(signer);
    const wrap = await wrapToMe('hello from the desk');

    await comp.receive(wrap);

    assert.equal(Object.hasOwn(comp.cache, wrap.id), false, 'a failed unwrap is not cached: ' + JSON.stringify(comp.cache));
    assert.equal(store.size, 0, 'nothing is persisted for it either');
    assert.deepEqual(comp.rumors, []);

    await wait(60);

    assert.deepEqual(comp.rumors.map((r) => r.content), ['hello from the desk']);
    assert.ok(comp.cache[wrap.id], 'the opened message is cached');
    assert.ok(signer.decryptCalls >= 3, 'the wrap was tried again: ' + signer.decryptCalls);
});

test('a signer that keeps failing gives up after the retries, and the wrap is still not cached as null', async () => {
    const signer = keySigner(keys.me, { failDecrypts: 100 });
    const comp = room(signer);
    const wrap = await wrapToMe('never opened here');

    await comp.receive(wrap);
    await wait(120);

    assert.equal(Object.hasOwn(comp.cache, wrap.id), false);
    assert.equal(signer.decryptCalls, 1 + UNWRAP_RETRIES.length, 'one attempt per retry: ' + signer.decryptCalls);
    assert.deepEqual(comp.rumors, []);
});

test('a wrap that is no message for me stays null in the cache and is not retried', async () => {
    const signer = keySigner(keys.me);
    const comp = room(signer);
    // Addressed to opp, not to me: no valid wrap for this reader.
    const { wraps } = await wrapGroupMessage(keySigner(keys.opp), { sender: pk.opp, recipients: [pk.opp], content: 'to someone else', match: 53, now: NOW });

    await comp.receive(wraps[0]);
    await wait(60);

    assert.equal(comp.cache[wraps[0].id], null);
    assert.equal(signer.decryptCalls, 0);
    assert.deepEqual(comp.rumors, []);
});

test('destroy() cancels the retries', async () => {
    const signer = keySigner(keys.me, { failDecrypts: 100 });
    const comp = room(signer);
    const wrap = await wrapToMe('closed before the retry');

    await comp.receive(wrap);
    comp.destroy();
    await wait(80);

    assert.equal(signer.decryptCalls, 1);
});
