/**
 * The bot's lines in the game chat of a Proof of Pong game against a bot (plan "KI-Stimme der Liga", P5): its
 * archetype teases on a few occasions (App\Support\Memes\BotBanter hands the lines over in the page config as
 * `banter`). One line at a time in the bubble at the bot's side of the field (#banter), each readable for at least
 * 4 s plus 60 ms per character (rule R17), a point's line at most every POINT_GAP_MS and only by chance, so the bot
 * does not chatter on every goal. The settings' "Bot chat" box mutes it, kept in this browser (localStorage
 * `pong-banter`); muted, nothing shows and nothing is queued.
 */

const STORE = 'pong-banter';
const POINT_GAP_MS = 9000;
const POINT_CHANCE = 0.4;

export function readBanterMuted() {
    try {
        return localStorage.getItem(STORE) === 'off';
    } catch {
        return false;
    }
}

export function createBanter(banter, { speed = 1 } = {}) {
    const box = document.getElementById('banter');
    const toggle = document.querySelector('#settings [name=banter]');
    let muted = readBanterMuted();
    let hideTimer = null;
    let lastPoint = -Infinity;
    const used = new Set();

    const hide = () => {
        if (!box) return;
        box.hidden = true;
        box.classList.remove('in');
    };

    const setMuted = (value) => {
        muted = value;
        try {
            localStorage.setItem(STORE, value ? 'off' : 'on');
        } catch {
            // Held for this visit only.
        }
        if (value) hide();
        document.body.dataset.banter = value ? 'off' : 'on';
    };

    if (toggle) {
        toggle.checked = !muted;
        toggle.addEventListener('change', (e) => setMuted(!e.target.checked));
    }
    document.body.dataset.banter = muted ? 'off' : 'on';

    /** A line of the occasion not shown yet in this game, or any of them once all were shown. */
    const pick = (occasion) => {
        const lines = banter?.lines?.[occasion] ?? [];
        const fresh = lines.filter((line) => !used.has(line));
        const pool = fresh.length > 0 ? fresh : lines;
        if (pool.length === 0) return null;
        const line = pool[Math.floor(Math.random() * pool.length)];
        used.add(line);

        return line;
    };

    /**
     * Shows a line for `occasion` unless muted; `force` skips the chance and gap of a point (the browser test's seam).
     * Returns the line shown, or null.
     */
    const say = (occasion, { force = false } = {}) => {
        if (muted || !box || !banter) return null;
        const now = performance.now();
        if (!force && (occasion === 'bot_point' || occasion === 'player_point')) {
            if (now - lastPoint < POINT_GAP_MS / speed || Math.random() > POINT_CHANCE) return null;
            lastPoint = now;
        }
        const line = pick(occasion);
        if (!line) return null;
        box.querySelector('[data-banter-text]').textContent = line;
        box.hidden = false;
        box.classList.remove('in');
        void box.offsetWidth;
        box.classList.add('in');
        clearTimeout(hideTimer);
        hideTimer = setTimeout(hide, 4000 + 60 * line.length);

        return line;
    };

    return { say, hide, muted: () => muted, setMuted, lines: () => banter?.lines ?? {} };
}
