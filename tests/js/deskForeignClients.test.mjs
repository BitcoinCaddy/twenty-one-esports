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

test('a wide group from another client counts as the desk at four fifths members, shows marked from half, and an outsider tagging the desk is hidden but counted', async () => {
    const { deskPlacement, deskHidden } = await import('../../resources/js/nostrChat.js');
    const players = Array.from({ length: 9 }, (_, i) => String(i + 1).repeat(64).slice(0, 64));
    const members = [me, ...players];
    const left = ['e'.repeat(64), 'f'.repeat(64), '0'.repeat(64), '9'.repeat(63) + 'e', '8'.repeat(63) + 'e', '7'.repeat(63) + 'e'];
    // DerCaddy, tournament 27: a reply in Amethyst to an older room that still holds a player who withdrew in sign-up.
    const olderRoom = rumor('6', players[0], [me, ...players.slice(1), left[0]]);
    const halfRoom = rumor('7', players[0], [me, ...players.slice(1, 5), ...left.slice(0, 4)]);
    const outsiderRoom = rumor('8', players[0], [me, ...left]);
    const intruder = rumor('9', stranger, [me, a], [['desk', '27']]);
    const opts = { me, members, desk: 27 };

    assert.equal(deskPlacement(olderRoom, opts), 'desk', 'one withdrawn player in a room of eleven');
    assert.equal(deskPlacement(halfRoom, opts), 'near', 'half the room members: shown, marked');
    assert.equal(deskPlacement(outsiderRoom, opts), null, 'mostly outsiders: not the desk');
    assert.equal(deskPlacement(intruder, opts), 'stranger', 'a desk tag from a non-member');

    const shown = deskMessages([olderRoom, halfRoom, outsiderRoom, intruder], opts).map((m) => m.content);
    assert.deepEqual(shown, ['msg 6', 'msg 7']);
    assert.equal(deskHidden([olderRoom, halfRoom, outsiderRoom, intruder, intruder], opts), 1);
});
