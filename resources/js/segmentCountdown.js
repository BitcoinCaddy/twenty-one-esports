/**
 * The segmented countdown (components/ui/countdown, design canvas "Countdown-Segmente"): days, hours and minutes
 * to `at` (ms), two digits each. The server renders the first frame; this counts on every 15 s so the minute
 * never lags by more than that. The interval is cleared in destroy(), so a wire:navigate swap leaves nothing.
 */
export default function segmentCountdown(at, parts, units = 'dhm') {
    let timer = null;

    return {
        p: parts,

        init() {
            this.tick();
            // Minutes and seconds (the match room's step clock) count every second.
            timer = setInterval(() => this.tick(), units === 'dhm' ? 15000 : 1000);
        },

        destroy() {
            clearInterval(timer);
        },

        tick() {
            const s = Math.max(0, Math.ceil((at - Date.now()) / 1000));
            const split = {
                dhm: [Math.floor(s / 86400), Math.floor((s % 86400) / 3600), Math.floor((s % 3600) / 60)],
                dhms: [Math.floor(s / 86400), Math.floor((s % 86400) / 3600), Math.floor((s % 3600) / 60), s % 60],
                hms: [Math.floor(s / 3600), Math.floor((s % 3600) / 60), s % 60],
                ms: [Math.floor(s / 60), s % 60],
            };
            this.p = (split[units] ?? split.dhm).map((n) => String(n).padStart(2, '0'));
        },
    };
}
