/**
 * What was read in a chat, per chat (`<me>:<chat>`): the newest message read on this device, in ms (DerCaddy,
 * 2026-10-18: "Die letzte Nachricht ist von mir, trotzdem steht da neu"). The game chat keeps its games here
 * (`<me>:<match>`, gameChat.js), "Your chats" its match rooms (`<me>:room:<number>`, chatInbox.js); the tournament
 * desk keeps its own marker (deskChat.js). The newest READ_KEEP chats are kept.
 */
export const READ_KEY = 'esports.chat.read';
const READ_KEEP = 300;

export function readMarkers() {
    try {
        const markers = JSON.parse(localStorage.getItem(READ_KEY) ?? '{}');

        return markers !== null && typeof markers === 'object' && !Array.isArray(markers) ? markers : {};
    } catch {
        return {};
    }
}

export function saveReadMarker(chat, at) {
    const markers = readMarkers();
    if ((Number(markers[chat]) || 0) >= at) return;
    markers[chat] = at;
    const newest = Object.keys(markers).sort((a, b) => markers[b] - markers[a]).slice(0, READ_KEEP);

    try {
        localStorage.setItem(READ_KEY, JSON.stringify(Object.fromEntries(newest.map((key) => [key, markers[key]]))));
    } catch {
        // private mode or full storage: the badge just forgets what was read
    }
}
