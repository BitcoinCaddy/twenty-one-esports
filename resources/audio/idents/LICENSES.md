# TWENTY ONE TV station sound: licenses

Every file in this folder is **own work**, synthesized from ffmpeg's built-in
signal generators (`aevalsrc` sine/FM expressions and its deterministic
pseudo-random noise). No sample, loop, preset or recording of anybody else
goes in, and no other station's jingle was used as a model.

**License: CC0 1.0 Universal** (public domain dedication),
<https://creativecommons.org/publicdomain/zero/1.0/>.

| File | Length | Use | Loudness (integrated, as encoded) | License |
|---|---|---|---|---|
| `news-stinger.opus` | 3.6 s | before a news segment or bulletin | -16.4 LUFS | own work, CC0 1.0 |
| `station-ident.opus` | 2.6 s | before a segment (history, followed profiles) | -16.3 LUFS | own work, CC0 1.0 |
| `breaking-sting.opus` | 2.0 s | breaking / zap moments (slot `breaking`) | -16.4 LUFS | own work, CC0 1.0 |
| `news-bed.opus` | 96 s, loops | under the speech of a bulletin | -28.1 LUFS | own work, CC0 1.0 |
| `sfx-bass-drop.opus` | 2.4 s | bass drop; rotates as `breaking` stinger | -16.0 LUFS, true peak -2.5 dBFS | own work, CC0 1.0 |
| `sfx-coin-8bit.opus` | 0.8 s | 8-bit coin | -16.0 LUFS, true peak -7.7 dBFS | own work, CC0 1.0 |
| `sfx-glitch.opus` | 1.5 s | glitch stutter of the motif | -16.0 LUFS, true peak -2.2 dBFS | own work, CC0 1.0 |
| `sfx-hit-boom.opus` | 1.8 s | closing hit (sub boom and the motif) | -16.8 LUFS, true peak -1.7 dBFS | own work, CC0 1.0 |
| `sfx-hit-metal.opus` | 1.5 s | closing hit (metallic bells) | -16.2 LUFS, true peak -1.6 dBFS | own work, CC0 1.0 |
| `sfx-laser-double.opus` | 1.3 s | double laser zap | -16.0 LUFS, true peak -3.2 dBFS | own work, CC0 1.0 |
| `sfx-laser-zap.opus` | 0.9 s | laser zap | -16.0 LUFS, true peak -4.4 dBFS | own work, CC0 1.0 |
| `sfx-news-clock.opus` | 2.9 s | news stinger (clock); rotates as `news` stinger | -16.0 LUFS, true peak -1.7 dBFS | own work, CC0 1.0 |
| `sfx-news-pulse.opus` | 3.2 s | news stinger (pulse); rotates as `news` stinger | -16.0 LUFS, true peak -1.9 dBFS | own work, CC0 1.0 |
| `sfx-news-rise.opus` | 3.4 s | news stinger (riser); rotates as `news` stinger | -16.1 LUFS, true peak -2.0 dBFS | own work, CC0 1.0 |
| `sfx-reverse-swell.opus` | 2.6 s | reverse swell into a hit; rotates as `ident` stinger | -16.0 LUFS, true peak -3.0 dBFS | own work, CC0 1.0 |
| `sfx-riser-long.opus` | 3.6 s | long riser into a hit (long station ID) | -16.4 LUFS, true peak -2.0 dBFS | own work, CC0 1.0 |
| `sfx-riser-short.opus` | 2.0 s | short riser into a hit; rotates as `ident` stinger | -16.4 LUFS, true peak -3.0 dBFS | own work, CC0 1.0 |
| `sfx-tape-stop.opus` | 1.4 s | tape stop | -16.0 LUFS, true peak -5.1 dBFS | own work, CC0 1.0 |
| `sfx-whoosh-down.opus` | 1.2 s | whoosh, falling | -16.0 LUFS, true peak -2.7 dBFS | own work, CC0 1.0 |
| `sfx-whoosh-up.opus` | 1.4 s | whoosh, rising | -15.9 LUFS, true peak -4.3 dBFS | own work, CC0 1.0 |
| `bed-courtroom.opus` | 16.0 s, loops (16 s) | courtroom bed (Chat-Tribunal) | -16.1 LUFS, true peak -1.5 dBFS | own work, CC0 1.0 |
| `bed-late-night.opus` | 16.0 s, loops (16 s) | late-night bed | -16.1 LUFS, true peak -1.6 dBFS | own work, CC0 1.0 |
| `bed-news-drive.opus` | 16.0 s, loops (16 s) | news bed variant (drive), rotates under bulletins | -16.1 LUFS, true peak -1.2 dBFS | own work, CC0 1.0 |
| `bed-news-pulse.opus` | 16.0 s, loops (16 s) | news bed variant (pulse), rotates under bulletins | -16.2 LUFS, true peak -1.3 dBFS | own work, CC0 1.0 |
| `bed-sport.opus` | 16.0 s, loops (16 s) | sport bed (Sport und Wetter) | -16.2 LUFS, true peak -1.6 dBFS | own work, CC0 1.0 |
| `bed-tutorial.opus` | 16.0 s, loops (16 s) | tutorial bed | -16.0 LUFS, true peak -1.6 dBFS | own work, CC0 1.0 |
| `bed-weather.opus` | 16.0 s, loops (16 s) | weather bed | -16.0 LUFS, true peak -2.0 dBFS | own work, CC0 1.0 |

Format: Ogg Opus, 48 kHz, mono (the stream's voice input is mono). Measured
2026-10-12 with `ffmpeg -af ebur128=peak=sample`; the same values after the
stream's decode to 24 kHz mono: -16.4 / -16.4 / -16.5 / -28.1 LUFS.

## How to regenerate

```bash
scripts/make-idents.sh                 # writes into resources/audio/idents
scripts/make-idents.sh /some/other/dir # or anywhere else
```

The script holds the exact generator expressions, filters and mastering
steps (ebur128 measurement, gain to target, `alimiter` at -1 dBFS, libopus).
Requirements: ffmpeg with libopus and awk. Generated with ffmpeg n9.0.2.

## The Anchor's jingles (programme blocks, P6)

The station-ID, time-of-day, news, rubric and sweeper jingles of TWENTY ONE
ESPORTS RADIO are rendered on the workstation by `php artisan caster:jingles`
into `storage/app/jingles` (not in git; about 90 small Ogg Opus files plus a
`manifest.json` naming every file's line, category, music recipe and measured
loudness). Provenance:

- **Voice:** "The Anchor (TWENTY ONE)", a VoiceStudio profile made from the
  built-in personality `us_news_anchor` ("The Anchor": male, middle-aged,
  moderate pitch, American accent). It is a *designed* voice generated from
  that description, not a clone of any real person's recording.
- **Words:** our own lines, `resources/data/voice/anchor-jingles.json`.
- **Music:** own work, synthesized from ffmpeg's built-in generators by
  `App\Support\Caster\Blocks\JingleSynth` (same method as
  `scripts/make-idents.sh`: sine/FM expressions and deterministic noise, no
  sample, no other station's jingle as a model), **CC0 1.0**.
- **Agreeing blips** ("hm", "ja", "stimmt", a laugh) under the other speaker:
  voiced with the same VoiceStudio profiles as the commentary (Markus Turm,
  Sirius, consent as for the commentary), stored as `blips/<speaker>/*.wav`.

The replay archive (`storage/app/replay`, workstation, at most 48 h and 3 GB)
holds the day's uploaded blocks of the same provenance; a replay adds only the
Anchor's "Ihr hört die Wiederholung" jingle in front.

Each jingle is mastered to -16 LUFS (ebur128 measured, gained, `alimiter` at
-1 dBFS, one correction pass); the values are in the manifest. Re-render
without any VoiceStudio call: `php artisan caster:jingles --mix-only`.

## Stingers and beds of the radio imaging (P11)

The `sfx-*.opus` stingers and `bed-*.opus` beds above are synthesized by
`App\Support\Caster\Blocks\JingleSynth::sound()` (ffmpeg `aevalsrc`
expressions, its deterministic noise, simple filters; no sample) and written
by `php artisan caster:jingles --sounds`: gained to -16 LUFS integrated in two
passes, sample peaks limited at -2 dBFS so the encoded file stays under
-1 dBTP, Ogg Opus 48 kHz mono. Every periodic part of a bed divides its 16 s
(beats of 0.25 to 4 s, frequencies on a 1/16 Hz grid, no noise), so a bed
loops without a seam. Measured 2026-10-12 with `ffmpeg -af ebur128=peak=true`.
