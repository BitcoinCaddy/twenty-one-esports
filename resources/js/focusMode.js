/**
 * Focus mode on the game pages of a phone (plan "Refactor und Design-Revamp" P7, user decision 2026-10-11): instead of
 * hiding the tab bar for good, it slides away while the player plays and comes back at once when they reach for it.
 *
 * Only on a page that marks itself `data-focus-page` (the chess and board game pages of a running game) and only below
 * 1024 px. It hides when the player taps the board (`data-focus-board`) or scrolls down; it shows again on a scroll
 * up, a swipe up from the bottom edge, or a tap on the bottom edge (a 24 px strip, `data-test=focus-edge`, while the
 * bar is away). Every change dispatches `focus-mode`, so a board that fits the first screen (boardGame.js fitBoard) takes
 * the height the bar gave up; so does its start, for a board that fitted itself before it. The root class `is-focus` slides the bar and the page's own bottom bars down
 * (revamp-league.css); the body keeps its room, so nothing on the page moves. Reduced motion: no slide, the bar is shown or hidden at once (revamp-league.css).
 * One listener set on window, released on every wire:navigate swap; a page without the mark never hides the bar.
 */

const PHONE = '(width < 64rem)';
const STEP = 8;
const EDGE = 48;

function root() {
    return document.documentElement;
}

function setFocus(on) {
    const html = root();
    if (html.classList.contains('is-focus') === on) {
        return;
    }
    html.classList.toggle('is-focus', on);
    html.dataset.focus = on ? 'hidden' : 'shown';
    // The board refits to the height the bar gave up or takes back (after the bar's own frame). Not `resize`: a phone's
    // board ignores a height-only resize (the address bar), so the page listens for this event of its own.
    // Once the bars have slid (200 ms, none with reduced motion): first `resize`, so the action dock re-measures where
    // its tab sits on the page's bar (matchDock.js measure()), then, a frame later, the board fits to the new edge.
    // The 220 ms is a guess at the slide (200 ms): on a busy phone the first frame of the slide can come later than that, and a
    // board that fitted then read the bars half-way down and stayed 12 px short for good. So the event waits for the slide itself.
    const slide = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 220;
    setTimeout(async () => {
        await slideDone();
        window.dispatchEvent(new Event('resize'));
        requestAnimationFrame(() => requestAnimationFrame(() => window.dispatchEvent(new CustomEvent('focus-mode', { detail: { hidden: on } }))));
    }, slide);
}

/** The slide of the bars still running (their CSS transitions on `translate`, revamp-league.css). */
function sliding() {
    return (document.getAnimations?.() ?? []).filter((animation) => animation.transitionProperty === 'translate' && animation.playState === 'running');
}

/** Resolves once no bar slides any more: a fit reads the bars' place, which is final only then. A cancelled slide counts as over. */
async function slideDone() {
    for (let running = sliding(); running.length > 0; running = sliding()) {
        await Promise.allSettled(running.map((animation) => animation.finished));
    }
}

let teardown = null;

function start() {
    teardown?.abort();
    teardown = null;
    setFocus(false);
    delete root().dataset.focus;

    if (!document.querySelector('[data-focus-page]')) {
        return;
    }

    teardown = new AbortController();
    const signal = teardown.signal;
    const phone = () => window.matchMedia(PHONE).matches;
    let lastY = window.scrollY;
    let touch = null;
    root().dataset.focus = 'shown';
    // Ready: a board that fitted itself before this ran (Alpine starts first) fits again, and on a phone too short for it
    // slides the bar away at once (boardGame.js fitBoard).
    window.dispatchEvent(new CustomEvent('focus-mode', { detail: { hidden: false } }));

    window.addEventListener('scroll', () => {
        const y = window.scrollY;
        const delta = y - lastY;
        if (Math.abs(delta) < STEP) {
            return;
        }
        lastY = y;
        if (!phone()) {
            return;
        }
        setFocus(delta > 0 && y > 0);
    }, { passive: true, signal });

    document.addEventListener('pointerdown', (event) => {
        if (!phone()) {
            return;
        }
        if (event.target.closest?.('[data-test=focus-edge]')) {
            setFocus(false);

            return;
        }
        if (event.target.closest?.('[data-focus-board]')) {
            setFocus(true);
        }
    }, { signal });

    // The keyboard reaching the tab bar brings it back.
    document.addEventListener('focusin', (event) => {
        if (event.target.closest?.('[data-test=tab-bar]')) {
            setFocus(false);
        }
    }, { signal });

    // A swipe up that starts at the bottom edge brings the bar back.
    document.addEventListener('touchstart', (event) => {
        const point = event.touches[0];
        touch = point && point.clientY > window.innerHeight - EDGE ? point.clientY : null;
    }, { passive: true, signal });
    document.addEventListener('touchmove', (event) => {
        const point = event.touches[0];
        if (touch !== null && point && touch - point.clientY > 24) {
            touch = null;
            setFocus(false);
        }
    }, { passive: true, signal });

    // Back on a desktop width the bar is no longer there to hide.
    window.matchMedia(PHONE).addEventListener('change', () => setFocus(false), { signal });
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', start);
    document.addEventListener('livewire:navigated', start);
}

export { setFocus, start };
