/**
 * The player's own Nostr profile (kind 0), written with the player's own signer (user 2026-10-25: players who
 * signed in with Google had no profile and no Lightning address, so their prize waited). The league never signs it.
 *
 *   1. the newest kind 0 is read from the profile relays and the player's outbox (readProfiles()), so every field
 *      the player already has (picture, about, nip05, …) stays;
 *   2. $wire.prepareProfile() checks the name and the Lightning address (a real invoice request, as a payout makes
 *      it) and returns the template with those two fields merged into the old content;
 *   3. the player's signer signs it, it goes to the player's write relays and the profile relays;
 *   4. $wire.submitProfile() hands the signed event to the league's profile cache.
 */
import { ensureSigner } from './nostrSign.js';
import { signerMessage, signTemplate } from './signing.js';
import { newest, publishToRelays, readProfiles, relayUrls, writeRelaysFor } from './relayRead.js';

export function profileEditor({ pubkey, relays = [], indexers = [], messages = {} }) {
    return {
        name: '',
        lud16: '',
        current: '',
        step: 'loading',
        error: null,
        published: 0,

        async init() {
            try {
                const { events } = await readProfiles([pubkey], { relays, indexers });
                const event = newest(events, pubkey, 0);
                const content = event ? JSON.parse(event.content || '{}') : {};
                this.current = event ? event.content : '';
                this.name = typeof content.name === 'string' ? content.name : (typeof content.display_name === 'string' ? content.display_name : '');
                this.lud16 = typeof content.lud16 === 'string' ? content.lud16 : '';
            } catch (error) {
                console.warn('[profile] reading the profile failed:', error);
            }

            this.step = 'idle';
        },

        async save() {
            if (this.step === 'saving') {
                return;
            }

            this.step = 'saving';
            this.error = null;

            try {
                const template = await this.$wire.prepareProfile(this.name, this.lud16, this.current);

                if (! template || typeof template !== 'object') {
                    this.step = 'idle';

                    return;
                }

                if (! (await ensureSigner())) {
                    this.error = messages.noSigner;
                    this.step = 'idle';

                    return;
                }

                let signed;
                try {
                    signed = await signTemplate(template, { pubkey });
                } catch (error) {
                    this.error = signerMessage(messages, error);
                    this.step = 'idle';

                    return;
                }

                const targets = [...new Set([...(await writeRelaysFor(pubkey, relays)), ...relayUrls(relays)])];
                this.published = await publishToRelays(targets, signed);

                if (this.published === 0) {
                    this.error = messages.notPosted;
                    this.step = 'idle';

                    return;
                }

                this.current = signed.content;
                this.step = (await this.$wire.submitProfile(JSON.stringify(signed))) === true ? 'done' : 'idle';
            } catch (error) {
                console.warn('[profile] saving failed:', error);
                this.error = messages.failed;
                this.step = 'idle';
            }
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('profileEditor', profileEditor);
});
