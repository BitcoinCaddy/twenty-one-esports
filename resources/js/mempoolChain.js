/**
 * The mempool strip of the start page (design canvas Main.dc.html `.chain`, plan "Refactor und Design-Revamp",
 * user: "Wie bei mempool.space mit der Maus die Blockchain nach rechts und links scrollen"): drag with the mouse,
 * the wheel or trackpad, the arrow keys (the viewport is focusable and scrolls natively), swipe on a phone.
 * It opens with the divider between done and waiting in view; once it is out of view, "Zur Gegenwart" brings it
 * back. The edge fades show on the side that has more. A drag never opens the cube it started on. With reduced
 * motion every jump is instant.
 *
 * History (Mempool page, plan "Refactor und Design-Revamp" P7, canvas NEU (Scope) "Historie lädt links nach"): with
 * data-history="<Livewire method>" and data-more="1" on the root, reaching the left end asks the page for older
 * blocks; once they are in, the view stays on the cube it showed (the new width is added to the scroll offset).
 */
export default function mempoolChain() {
    // Closure, not `this.*`: Alpine writes a property a parent scope also has to that parent.
    const teardown = new AbortController();

    return {
        left: false,
        right: false,
        away: false,
        dragging: false,
        loading: false,

        init() {
            const viewport = this.$refs.viewport;
            const signal = teardown.signal;
            let start = null;
            let moved = false;

            viewport.addEventListener('scroll', () => { this.measure(); this.history(); }, { passive: true, signal });
            window.addEventListener('resize', () => this.measure(), { signal });

            viewport.addEventListener('pointerdown', (event) => {
                if (event.pointerType !== 'mouse' || event.button !== 0) return;
                start = { x: event.clientX, scroll: viewport.scrollLeft };
                moved = false;
            }, { signal });
            window.addEventListener('pointermove', (event) => {
                if (start === null) return;
                const dx = event.clientX - start.x;
                if (!moved && Math.abs(dx) < 5) return;
                moved = true;
                this.dragging = true;
                viewport.scrollLeft = start.scroll - dx;
                if (start.scroll - dx < 0) this.history();
            }, { signal });
            window.addEventListener('pointerup', () => {
                start = null;
                // The click that ends a drag lands after pointerup: let it see `dragging` first.
                setTimeout(() => { this.dragging = false; }, 0);
            }, { signal });
            // At the left end an arrow key (or a horizontal swipe that cannot move further) asks for older blocks.
            viewport.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowLeft' && viewport.scrollLeft <= 0) this.history();
            }, { signal });
            viewport.addEventListener('click', (event) => {
                if (moved) {
                    event.preventDefault();
                    event.stopPropagation();
                    moved = false;
                }
            }, { capture: true, signal });
            // A vertical wheel moves the chain sideways while it can move that way; at either end the page scrolls on.
            viewport.addEventListener('wheel', (event) => {
                if (Math.abs(event.deltaY) <= Math.abs(event.deltaX)) return;
                const max = viewport.scrollWidth - viewport.clientWidth;
                const next = viewport.scrollLeft + event.deltaY;
                if (event.deltaY < 0 && viewport.scrollLeft <= 0) {
                    this.history();
                    return;
                }
                if (event.deltaY > 0 && viewport.scrollLeft >= max) return;
                event.preventDefault();
                viewport.scrollLeft = Math.max(0, Math.min(max, next));
            }, { passive: false, signal });

            this.$nextTick(() => {
                const divider = this.$refs.divider;
                if (divider && divider.offsetLeft > viewport.clientWidth - 48) {
                    // The divider near the middle, the row starting on a whole cube, never half of one.
                    const target = divider.offsetLeft - viewport.clientWidth / 2;
                    const cubes = [...viewport.querySelectorAll('.rv-cw')].map((cube) => cube.offsetLeft - viewport.firstElementChild.offsetLeft);
                    viewport.scrollLeft = Math.max(0, ...cubes.filter((left) => left <= target));
                }
                this.measure();
            });
        },

        destroy() {
            teardown.abort();
        },

        measure() {
            const viewport = this.$refs.viewport;
            const max = viewport.scrollWidth - viewport.clientWidth;
            this.left = viewport.scrollLeft > 2;
            this.right = viewport.scrollLeft < max - 2;
            const divider = this.$refs.divider;
            if (divider) {
                const x = divider.offsetLeft - viewport.scrollLeft;
                this.away = x < 0 || x > viewport.clientWidth;
            }
        },

        async history() {
            const viewport = this.$refs.viewport;
            const method = this.$el.dataset.history;
            if (!method || this.loading || this.$el.dataset.more !== '1' || viewport.scrollLeft > 120 || !this.$wire) return;
            this.loading = true;
            // Anchor on the first cube in view: after the older blocks are in, it sits where it sat.
            const edge = viewport.getBoundingClientRect().left;
            const anchor = [...viewport.querySelectorAll('[data-test=chain-cube]')].find((cube) => cube.getBoundingClientRect().right > edge);
            const href = anchor?.getAttribute('href');
            const offset = anchor ? anchor.getBoundingClientRect().left - edge : 0;
            try {
                await this.$wire.call(method);
                // The morph lands after the call resolves: wait for its frame before measuring.
                await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                const same = [...viewport.querySelectorAll('[data-test=chain-cube]')].find((cube) => cube.getAttribute('href') === href);
                if (same) {
                    viewport.scrollLeft += same.getBoundingClientRect().left - edge - offset;
                }
            } finally {
                this.loading = false;
                this.measure();
            }
        },

        present() {
            const viewport = this.$refs.viewport;
            const divider = this.$refs.divider;
            if (!divider) return;
            const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            viewport.scrollTo({ left: divider.offsetLeft - viewport.clientWidth / 2, behavior: reduced ? 'auto' : 'smooth' });
        },
    };
}
