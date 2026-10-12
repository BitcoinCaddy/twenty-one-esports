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
