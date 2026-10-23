/**
 * "Your chats" (pages::me.chats, App\Support\Chat\ChatInbox; DerCaddy, 2026-10-18: "Nachrichten in Chats sollten
 * auch noch besser zu sehen sein … ein Reiter Deine Chats, wo relevante Chats angezeigt werden"): every chat the
 * league names for the player, with its newest message, its time and how many of the others' messages are new.
 *
 * The league cannot read the chats (NIP-17 from the browsers): the server lists them, the browser finds their
 * messages in the player's inbox, as each chat does. First from this device (the caches of the game chat, the match
 * room and the desk, chatCache.js: what any chat page opened before), then, with a signer that can do NIP-44, from
 * the relays: every gift wrap to the player since the window's start, opened once and stored in the game chat's
 * cache, where every chat page finds it again.
 *
 * New: the others' messages after the chat's read marker and after one's own last message (whoever answers has
 * read). The game chat and the match room keep their marker per chat (chatRead.js), the desk its own (deskChat.js);
 * opening a room from here also marks what this list showed of it.
 *
 * The list shows the chats somebody wrote in; those without a message stay folded below it (the league names every
 * chat of a game, most stay silent).
 */
import { SimplePool } from 'nostr-tools/pool';
import { entryFor, loadCache, saveCache } from './chatCache.js';
import { readMarkers, roomReadKey, saveReadMarker } from './chatRead.js';
import { chatDay, chatTime } from './chatTime.js';
import { extraInboxRelays, lookupInboxes } from './dmInbox.js';
import { isExpired } from './lobbyCards.js';
import { UnwrapError, canEncrypt, chatSince, deskMessages, gameMessages, roomMessages, unwrapMessage } from './nostrChat.js';
import { awaitSigner, ensureSigner } from './nostrSign.js';
import { registerAlpine } from './registerAlpine.js';

const nowSeconds = () => Math.floor(Date.now() / 1000);

/** The signer failed, not the wrap (as in roomChat.js): opened again on the next visit, never cached as unreadable. */
const TRANSIENT_UNWRAP = new Set(['wrap_decrypt', 'seal_decrypt']);

/** A relay that has not ended its stored messages after this long counts as done (nostr-tools' maxWait). */
const EOSE_WAIT = 4000;

/** The tournament desk's read marker (deskChat.js), unix seconds. */
export const deskReadKey = (me, desk) => `esports.desk.read.${me}.${desk}`;

/** The marker of a chat in chatRead.js: a game by its `match` tag, a match room by its number. */
export function readKeyOf(chat) {
    return chat.kind === 'room' ? roomReadKey(chat.me, chat.match) : `${chat.me}:${chat.match}`;
}

/** One chat's messages in the inbox, oldest first, as its own page reads them (nostrChat.js). */
export function messagesOf(chat, rumors, { me, muted = [] }) {
    const dm = { since: chat.since ?? null, settled: chat.settled ?? null };

    switch (chat.kind) {
        case 'game':
            return gameMessages(rumors, { me, opponent: chat.opponent, match: chat.match, muted, dm });
        case 'room':
            return roomMessages(rumors, { me, members: chat.members, match: chat.match, muted, dm: { ...dm, opponents: chat.opponents ?? [] } });
        case 'desk':
            // The direction is never muted at its desk (deskChat.js): its announcements reach everybody.
            return deskMessages(rumors, { me, members: chat.members, desk: chat.desk, deskKey: chat.deskKey ?? null, muted: muted.filter((pubkey) => !(chat.managers ?? []).includes(pubkey)) });
        default:
            return [];
    }
}

/**
 * The rows of the list: each chat with its newest message (`last`: time in ms, sender, preview) and how many of the
 * others' messages are new; those with a message first, newest first, then the others as the league ordered them.
 *
 * @param {Array<object>} chats  App\Support\Chat\ChatInbox::for()
 * @param {Array<object>} rumors  every message of the inbox this device knows
 */
export function inboxRows(chats, rumors, { me, muted = [], labels = {}, markers = {}, deskRead = () => 0, now = nowSeconds() }) {
    const live = rumors.filter((rumor) => !isExpired(rumor, now));

    const rows = chats.map((chat, index) => {
        const messages = messagesOf(chat, live, { me, muted });
        const newest = messages.at(-1) ?? null;
        const mine = Math.max(0, ...messages.filter((m) => m.pubkey === me).map((m) => m.created_at));
        const marker = chat.kind === 'desk' ? Number(deskRead(chat.desk)) || 0 : Math.floor((Number(markers[readKeyOf(chat)]) || 0) / 1000);
        const read = Math.max(mine, marker);
        const name = (pubkey) => (pubkey === me ? (labels.you ?? 'you') : (chat.names?.[pubkey] ?? `${pubkey.slice(0, 8)}…`));

        return {
            ...chat,
            index,
            unread: messages.filter((m) => m.pubkey !== me && m.created_at > read).length,
            last: newest === null ? null : {
                at: newest.created_at * 1000,
                from: newest.pubkey,
                preview: `${name(newest.pubkey)}: ${String(newest.content ?? '').replace(/\s+/g, ' ').trim().slice(0, 140)}`,
            },
        };
    });

    return rows.sort((a, b) => (b.last?.at ?? -1) - (a.last?.at ?? -1) || a.index - b.index);
}

export function chatInbox(config) {
    // Every wrap this device opened in any chat, and those being opened now: none is opened twice (outside Alpine's
    // reactivity, it is no state to show).
    const opened = new Set();

    return {
        rumors: [],
        cache: {},
        status: 'idle',
        // The relays sent what they hold (EOSE), and the wraps being opened right now.
        loaded: false,
        pending: 0,
        pool: null,
        sub: null,
        inboxSub: null,
        closed: false,
        // Moves when a row marks itself read, so the rows count again.
        readTick: 0,
        t: config.labels ?? {},

        init() {
            // What this device opened in any chat before: the game chat's, the match room's and the desk's caches.
            const known = new Map();

            for (const kind of ['game', 'room', 'desk']) {
                const { cache, rumors } = loadCache(kind, config.me);
                if (kind === 'game') this.cache = cache;
                for (const id of Object.keys(cache)) opened.add(id);
                for (const rumor of rumors) known.set(rumor.id, rumor);
            }

            this.rumors = [...known.values()];

            if ((config.chats ?? []).length === 0 || (config.relays ?? []).length === 0) {
                this.status = 'quiet';
            } else if (window.nostr && canEncrypt(window.nostr)) {
                this.start();
            } else if (window.nostr) {
                this.status = 'no-nip44';
            } else {
                this.startWhenSignerArrives();
            }
        },

        /** An extension's window.nostr arrives after the page; a paired remote signer comes back without a dialog. */
        async startWhenSignerArrives() {
            this.status = 'starting';
            const found = await awaitSigner();
            if (this.closed || this.status !== 'starting') return;
            if (!found) this.status = 'needs-signer';
            else if (canEncrypt(window.nostr)) this.start();
            else this.status = 'no-nip44';
        },

        /** "Load new messages": connects a signer (a remote one asks once), then reads the relays. */
        async connect() {
            this.status = 'starting';
            if (!(await ensureSigner())) {
                this.status = 'needs-signer';

                return;
            }

            if (canEncrypt(window.nostr)) this.start();
            else this.status = 'no-nip44';
        },

        destroy() {
            this.closed = true;
            this.sub?.close();
            this.inboxSub?.close();
            this.pool?.destroy();
        },

        /** The relay pool (a seam for tests). */
        makePool() {
            return new SimplePool();
        },

        start() {
            this.status = 'live';
            this.pool = this.makePool();
            const filter = { kinds: [1059], '#p': [config.me], since: chatSince(config.since) };
            const handlers = {
                onevent: (wrap) => this.receive(wrap),
                oneose: () => {
                    this.loaded = true;
                },
                maxWait: EOSE_WAIT,
                // NIP-42: the league relay serves a gift wrap only to its authenticated recipient.
                onauth: (template) => window.nostr.signEvent(template),
            };
            this.sub = this.pool.subscribe(config.relays, filter, handlers);

            // NIP-17: other clients send to the player's DM relays (10050), so read there too, as the chats do.
            const lookup = [...(config.lookupRelays ?? []), ...config.relays];
            lookupInboxes([config.me], lookup, { trusted: lookup }).catch(() => new Map()).then((inboxes) => {
                const extra = extraInboxRelays(config.me, config.relays, inboxes);
                if (!this.closed && extra.length > 0) this.inboxSub = this.pool.subscribe(extra, filter, handlers);
            });
        },

        /**
         * The wrap's message; null for none to show (expired, not to me, a broken seal, a forged sender: stays closed);
         * undefined when the signer failed just now, so the wrap is opened again when a relay brings it next.
         */
        async unwrap(wrap) {
            if (isExpired(wrap)) return null;

            try {
                return await unwrapMessage(window.nostr, wrap, config.me);
            } catch (error) {
                return error instanceof UnwrapError && TRANSIENT_UNWRAP.has(error.code) ? undefined : null;
            }
        },

        async receive(wrap) {
            // Opened before (here or in a chat's cache), or being opened: every relay delivers the same wrap.
            if (opened.has(wrap.id)) return;
            opened.add(wrap.id);
            this.pending++;

            try {
                const rumor = await this.unwrap(wrap);

                if (rumor === undefined) {
                    opened.delete(wrap.id);

                    return;
                }

                this.cache[wrap.id] = entryFor('game', rumor);
                if (this.cache[wrap.id] !== null && !this.rumors.some((r) => r.id === rumor.id)) this.rumors.push(rumor);
                saveCache('game', config.me, this.cache);
            } finally {
                this.pending--;
            }
        },

        /** Still looking: the signer is asked, the relays have not ended their stored messages, or wraps are opened. */
        get searching() {
            return this.status === 'starting' || (this.status === 'live' && (!this.loaded || this.pending > 0));
        },

        get rows() {
            chatDay();
            void this.readTick;

            return inboxRows(config.chats ?? [], this.rumors, {
                me: config.me,
                muted: config.muted ?? [],
                labels: this.t,
                markers: readMarkers(),
                deskRead: (desk) => {
                    try {
                        return Number(localStorage.getItem(deskReadKey(config.me, desk))) || 0;
                    } catch {
                        return 0;
                    }
                },
            });
        },

        /** The chats somebody wrote in (DerCaddy: "in denen ich mich beteiligt habe bzw. jemand mir geschrieben hat"). */
        get active() {
            return this.rows.filter((row) => row.last !== null);
        },

        /** The chats without a message this device knows of: folded below the list. */
        get quiet() {
            return this.rows.filter((row) => row.last === null);
        },

        time(ms) {
            chatDay();

            return chatTime(ms, { hourCycle: 'h23' });
        },

        /** A row opens its chat: what this list showed of a room is read before the room's chat draws it. */
        open(row) {
            if (row.kind === 'room' && row.last !== null) saveReadMarker(readKeyOf(row), row.last.at);
            this.readTick++;
        },
    };
}

registerAlpine(() => {
    window.Alpine.data('chatInbox', chatInbox);
});
