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
