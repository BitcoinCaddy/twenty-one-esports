/**
 * chipRow() — a chip filter row as Priority+ (design canvas "Chip-Filter, Priority+", rule R8; components/ui/chips):
 * every chip that fits the row stays in it, the rest fold into the trailing "+N more" menu. The active chip never
 * folds. The server renders every chip in the row and every chip in the menu; this only hides the copies that do
 * not belong where they are, so without JavaScript the row scrolls sideways with nothing lost. Below 640 px the
 * row scrolls sideways with an edge fade (the board's phone rule) and nothing folds.
 *
 * Markup: x-ref="track" around the chips (each [data-chip="<i>"]), x-ref="more" the "+N" button wrapper with its
 * [data-more-count], and the menu's links [data-chip-menu="<i>"].
 */
export default function chipRow() {
    let observer = null;

    return {
        open: false,
        folded: 0,

        init() {
            this.$nextTick(() => this.fit());
            observer = new ResizeObserver(() => this.fit());
            observer.observe(this.$el);
        },

        destroy() {
            observer?.disconnect();
        },

        fit() {
            const track = this.$refs.track;
            const more = this.$refs.more;

            if (!track || !more) {
                return;
            }

            const chips = [...track.querySelectorAll('[data-chip]')];
            const items = [...this.$el.querySelectorAll('[data-chip-menu]')];
            chips.forEach((chip) => { chip.hidden = false; });
            more.hidden = true;

            if (window.matchMedia('(max-width: 639.98px)').matches || track.scrollWidth <= track.clientWidth + 1) {
                this.folded = 0;
                items.forEach((item) => { item.hidden = true; });

                return;
            }

            more.hidden = false;
            const hiddenIdx = new Set();
            // Fold from the end; the active chip stays in the row.
            for (let i = chips.length - 1; i >= 0 && track.scrollWidth > track.clientWidth + 1; i--) {
                if (chips[i].hasAttribute('aria-current') || chips[i].getAttribute('aria-pressed') === 'true') {
                    continue;
                }

                chips[i].hidden = true;
                hiddenIdx.add(chips[i].dataset.chip);
            }

            items.forEach((item) => { item.hidden = !hiddenIdx.has(item.dataset.chipMenu); });
            this.folded = hiddenIdx.size;
        },
    };
}

if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('chipRow', chipRow);
    });
}
