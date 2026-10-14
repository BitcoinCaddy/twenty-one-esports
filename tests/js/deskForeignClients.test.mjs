/**
 * The tournament desk (resources/js/nostrChat.js, deskMessages()): a reply written in another Nostr client carries no
 * `desk` tag. Tournament 27, 2026-10-14: the players' messages were readable in Amethyst and missing on the page. A
 * message whose whole room is members of the desk shows; one with a stranger in the room, or for another desk, does not.
 * Runnable alone with `node --test`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { deskMessages } from '../../resources/js/nostrChat.js';

const [me, a, b, stranger] = ['a'.repeat(64), 'b'.repeat(64), 'c'.repeat(64), 'd'.repeat(64)];
const rumor = (id, pubkey, recipients, extra = []) => ({ id: id.repeat(64).slice(0, 64), kind: 14, pubkey, created_at: 1_790_000_000 + id.charCodeAt(0), content: 'msg ' + id, tags: [...recipients.map((p) => ['p', p]), ...extra], sig: '' });

test('a reply from another client shows when its whole room is the desk, and not with a stranger or another desk', () => {
    const rumors = [
        rumor('1', a, [me, b], [['desk', '27']]),
        rumor('2', b, [me, a]),
        rumor('3', b, [me, stranger]),
        rumor('4', a, [me, b], [['desk', '28']]),
        rumor('5', a, [me]),
    ];
    const ids = deskMessages(rumors, { me, members: [me, a, b], desk: 27 }).map((m) => m.content ?? m.text);

    assert.ok(ids.includes('msg 1'), 'own desk tag');
    assert.ok(ids.includes('msg 2'), 'foreign client, room = desk');
    assert.ok(!ids.includes('msg 3'), 'stranger in the room');
    assert.ok(!ids.includes('msg 4'), 'another desk');
    assert.ok(!ids.includes('msg 5'), 'a private DM of two is not the desk');
});
