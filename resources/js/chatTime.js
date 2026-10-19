/**
 * The time beside a chat message, one rule for every chat of the league (DerCaddy, 2026-10-17: "bei älteren
 * Nachrichten nicht den Wochentag bzw bei Nachrichten die älter als eine Woche sind, das Datum"). Each chat
 * printed only hours and minutes, so in a list a message of 20:24 stood above one of 09:57 with nothing to say
 * that a day lay between them.
 *
 * - today: the time, "20:24"
 * - yesterday up to six days back: the weekday and the time, "Fr 20:24" (a weekday is unique within a week)
 * - seven days back and more: the date and the time, "12.10., 20:24"
 * - another year: with the year, "12.10.2025, 20:24"
 *
 * Calendar days of the device's own clock and zone, as the times were before; the words in the page's
 * language (`<html lang>`) unless a chat passes its own locale. The clock stays each chat's own: the match room
 * and a game's chat always printed 24 hours (`hourCycle: 'h23'`), the channels and the live chat the locale's.
 *
 * Used by roomChat.js (match room, and through deskChat.js the tournament desk), gameChat.js (a game's chat),
 * gameChannel.js (a game's channel such as the Blockli chat), liveChat.js and the Hyperbitcoinization chats.
 */

const DAY_MS = 86_400_000;

function pageLocale(locale) {
    return locale || (typeof document !== 'undefined' && document.documentElement?.lang) || undefined;
}

function dayStart(ms) {
    const d = new Date(ms);

    return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
}

/** Whole calendar days from `ms` to `now`: 0 today, 1 yesterday; negative for a clock ahead of this one. */
export function daysAgo(ms, now = Date.now()) {
    return Math.round((dayStart(now) - dayStart(ms)) / DAY_MS);
}

/**
 * The label for a message sent at `ms` (unix ms).
 *
 * @param {number} ms
 * @param {{ now?: number, locale?: string, hourCycle?: 'h23' }} [options]
 */
export function chatTime(ms, { now = Date.now(), locale, hourCycle } = {}) {
    const lang = pageLocale(locale);
    const time = new Date(ms).toLocaleTimeString(lang, { hour: '2-digit', minute: '2-digit', ...(hourCycle ? { hourCycle } : {}) });
    const days = daysAgo(ms, now);

    if (days <= 0) return time;
    if (days < 7) return `${new Date(ms).toLocaleDateString(lang, { weekday: 'short' })} ${time}`;

    const sameYear = new Date(ms).getFullYear() === new Date(now).getFullYear();
    const date = new Date(ms).toLocaleDateString(lang, sameYear ? { day: '2-digit', month: '2-digit' } : { day: '2-digit', month: '2-digit', year: 'numeric' });

    return `${date}, ${time}`;
}

let timer = null;

/**
 * Today, as a reactive value for the chats' labels: Alpine.store('chatDay').today moves at local midnight and
 * when the page comes back into view, so a page left open overnight turns yesterday's "20:24" into "Mo 20:24".
 * A chat's time() reads it before formatting, which makes its labels depend on it.
 */
export function chatDay() {
    const Alpine = typeof window !== 'undefined' ? window.Alpine : undefined;
    if (!Alpine?.store) return dayStart(Date.now());

    if (!Alpine.store('chatDay')) {
        Alpine.store('chatDay', { today: dayStart(Date.now()) });
        const refresh = () => {
            const today = dayStart(Date.now());
            if (Alpine.store('chatDay').today !== today) Alpine.store('chatDay').today = today;
        };
        const schedule = () => {
            clearTimeout(timer);
            // A second past midnight, then again at the next one (unref: never what keeps Node's tests alive).
            timer = setTimeout(() => {
                refresh();
                schedule();
            }, dayStart(Date.now()) + DAY_MS - Date.now() + 1000);
            timer?.unref?.();
        };
        schedule();
        document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && refresh());
    }

    return Alpine.store('chatDay').today;
}
