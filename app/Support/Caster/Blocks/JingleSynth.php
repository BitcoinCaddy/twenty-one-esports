<?php

namespace App\Support\Caster\Blocks;

/**
 * The music under the Anchor's jingles (plan "TWENTY ONE Radio", P6),
 * synthesized from nothing but ffmpeg's own generators (aevalsrc sine/FM
 * expressions and its deterministic noise), in the style of
 * scripts/make-idents.sh: own work, CC0, no sample, no other station's
 * jingle as a model. Every bed carries the station motif D5 - A5 - F#5.
 *
 * A jingle is `intro` seconds of bed, then the voice over the ducked bed,
 * then a closing "button" (a bell and a hit) and a short tail. Variety
 * comes from six bed recipes, four keys (the motif transposed), four
 * treatments of the voice (dry, a small room, a slapback echo, a light
 * chorus) and the intro length; variant() picks them from a hash of the
 * jingle id, so a re-render gives the same jingle (reproducible).
 *
 * Pure: builds expressions and ffmpeg arguments, runs nothing.
 */
final class JingleSynth
{
    public const RATE = 48000;

    public const BEDS = ['rise-hit', 'pulse', 'shimmer', 'zap', 'swell', 'clock'];

    /** The motif transposed: unison, up a whole tone, down two semitones, up a major third. */
    public const KEYS = [1.0, 1.122462, 0.890899, 1.259921];

    public const EFFECTS = [
        'dry' => 'anull',
        'room' => 'aecho=0.8:0.7:35|61:0.22|0.12',
        'slap' => 'aecho=0.8:0.55:140:0.22',
        'chorus' => 'chorus=0.7:0.9:45:0.3:0.25:2',
    ];

    /**
     * The recipe of a jingle variant, from a hash of its id (stable across runs).
     *
     * @return array{bed: string, key: float, effect: string, intro: float}
     */
    public static function variant(string $line, string $category, int $variant = 0): array
    {
        $hash = crc32($line);
        // The news jingles tick or rise; breaking zaps or rises; the variants of one line differ in every part.
        $beds = match (true) {
            str_starts_with($category, 'news') || $category === 'headlines' || $category === 'back-announce' => ['clock', 'rise-hit', 'swell'],
            $category === 'breaking' => ['zap', 'rise-hit'],
            default => self::BEDS,
        };

        return [
            'bed' => $beds[($hash + $variant) % count($beds)],
            'key' => self::KEYS[(intdiv($hash, 7) + 2 * $variant) % count(self::KEYS)],
            'effect' => array_keys(self::EFFECTS)[(intdiv($hash, 31) + $variant) % count(self::EFFECTS)],
            'intro' => [0.7, 0.9, 1.1, 1.3][(intdiv($hash, 131) + $variant) % 4],
        ];
    }

    /**
     * The aevalsrc expression of a bed: `$intro` seconds before the voice,
     * the voice of `$voice` seconds, the button at its end, `$duration` in all.
     */
    public static function bed(string $recipe, float $key, float $intro, float $voice, float $duration): string
    {
        $button = $intro + $voice + 0.08;
        $pad = self::pad($key, $duration);
        $close = self::bell($button, 587.33 * $key, 2.2, 0.22).'+'.self::bell($button, 1174.66 * $key, 3.0, 0.08).'+'.self::boom($button, 0.35);

        $body = match ($recipe) {
            'rise-hit' => self::noiseRise(0, $intro, 0.3).'+'.self::boom($intro - 0.05, 0.55).'+'.self::motif($intro - 0.05, 0.16, 3.0, 0.26, $key),
            'pulse' => self::motif(0.15, 0.2, 3.2, 0.24, $key).'+0.10*exp(-mod(t,0.5)*9)*sin(2*PI*'.self::f(73.42 * $key).'*mod(t,0.5))',
            'shimmer' => self::motif(0.05, 0.12, 4.0, 0.2, $key * 2).'+'.self::motif(0.05 + 0.36, 0.12, 2.5, 0.22, $key).'+'.self::boom($intro - 0.1, 0.35),
            'zap' => self::chirp(0, 0.22, 2400, 180, 0.26).'+'.self::chirp(0.3, 0.22, 2400, 180, 0.26).'+'.self::boom(0.62, 0.5).'+'.self::motif(0.62, 0.1, 7.0, 0.3, $key),
            'swell' => self::chirp(0.0, $intro, 160 * $key, 1400 * $key, 0.2).'+'.self::boom($intro, 0.5).'+'.self::motif($intro, 0.2, 2.4, 0.22, $key),
            'clock' => '0.22*lt(t,'.self::f($intro).')*exp(-mod(t,0.125)*90)*sin(2*PI*2400*t)+'.self::boom($intro, 0.55).'+'.self::motif($intro, 0.2, 2.2, 0.24, $key).'+'.self::motif($intro, 0.2, 2.6, 0.1, $key * 2),
            default => '0',
        };

        return $body.'+'.$pad.'+'.$close;
    }

    /**
     * The ffmpeg arguments that mix the voice (input 0, a WAV) over the bed
     * (input 1, lavfi), duck the bed under the voice and fade, into a float
     * WAV (master() brings it to its loudness).
     *
     * @return list<string>
     */
    public static function mixArguments(string $ffmpeg, string $voiceFile, string $bedExpression, string $effect, float $intro, float $duration, string $out): array
    {
        $delay = (int) round($intro * 1000);
        $graph = sprintf(
            '[0:a]aresample=%1$d,aformat=channel_layouts=mono,highpass=f=70,%2$s,adelay=%3$d,apad=whole_dur=%4$.3F,asplit=2[v1][v2];'
            .'[1:a]volume=-3dB[bed];[bed][v1]sidechaincompress=threshold=0.015:ratio=7:attack=12:release=420[ducked];'
            .'[ducked][v2]amix=inputs=2:normalize=0:duration=first,afade=t=in:d=0.02,afade=t=out:st=%5$.3F:d=0.35[out]',
            self::RATE, self::EFFECTS[$effect] ?? 'anull', $delay, $duration, max(0.0, $duration - 0.35),
        );

        return [
            'nice', '-n', '19', $ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-threads', '1',
            '-i', $voiceFile,
            '-f', 'lavfi', '-i', sprintf("aevalsrc=exprs='%s':s=%d:d=%.3F", $bedExpression, self::RATE, $duration),
            '-filter_complex', $graph, '-map', '[out]', '-ac', '1', '-ar', (string) self::RATE, '-c:a', 'pcm_f32le', '-y', $out,
        ];
    }

    /**
     * The ffmpeg arguments that gain a mix by `$gainDb` (towards -16 LUFS,
     * measured by the caller like scripts/make-idents.sh does), limit its
     * sample peaks at -1 dBFS and write it as WAV (`$opus` false) or as the
     * final Ogg Opus mono jingle.
     *
     * @return list<string>
     */
    public static function masterArguments(string $ffmpeg, string $in, float $gainDb, string $out, bool $opus): array
    {
        $codec = $opus
            ? ['-c:a', 'libopus', '-b:a', '64k', '-application', 'audio', '-map_metadata', '-1',
                '-metadata', 'title=TWENTY ONE ESPORTS RADIO jingle', '-metadata', 'copyright=Music CC0 1.0 own work; voice designed in VoiceStudio (us_news_anchor)']
            : ['-c:a', 'pcm_f32le'];

        return [
            'nice', '-n', '19', $ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-threads', '1', '-i', $in,
            '-af', sprintf('volume=%.2FdB,alimiter=limit=0.891:level=disabled:attack=1:release=50', $gainDb),
            '-ac', '1', '-ar', (string) self::RATE, ...$codec, '-y', $out,
        ];
    }

    /** A quiet pad on the tonic and fifth that swells in and out over the whole jingle. */
    private static function pad(float $key, float $duration): string
    {
        $env = 'min(1,t/0.4)*min(1,max(0,('.self::f($duration).'-t)/0.6))';

        return $env.'*(0.045*sin(2*PI*'.self::f(146.83 * $key).'*t)+0.03*sin(2*PI*'.self::f(220 * $key).'*t)+0.02*sin(2*PI*'.self::f(293.66 * $key).'*t))';
    }

    /** An FM bell at `$t0`, 5 ms attack. */
    private static function bell(float $t0, float $freq, float $decay, float $amp): string
    {
        $x = '(t-'.self::f($t0).')';

        return self::f($amp).'*gte(t,'.self::f($t0).')*min(1,'.$x.'*200)*exp(-'.$x.'*'.self::f($decay).')*sin(2*PI*'.self::f($freq).'*'.$x.'+1.4*exp(-'.$x.'*7)*sin(2*PI*'.self::f($freq * 2).'*'.$x.'))';
    }

    /** A sub hit falling from about 105 Hz to 45 Hz. */
    private static function boom(float $t0, float $amp): string
    {
        $x = '(t-'.self::f(max(0.0, $t0)).')';

        return self::f($amp).'*gte(t,'.self::f(max(0.0, $t0)).')*min(1,'.$x.'*300)*exp(-'.$x.'*5)*sin(2*PI*(45*'.$x.'+4*(1-exp(-'.$x.'*15))))';
    }

    /** An exponential sine sweep from `$f0` to `$f1`, swelling in. */
    private static function chirp(float $t0, float $duration, float $f0, float $f1, float $amp): string
    {
        $x = '(t-'.self::f($t0).')';
        $d = self::f(max(0.05, $duration));
        $l = self::f(log($f1 / $f0));

        return self::f($amp).'*between(t,'.self::f($t0).','.self::f($t0 + $duration).')*pow('.$x.'/'.$d.',2)*sin(2*PI*'.self::f($f0).'*'.$d.'/'.$l.'*(exp('.$x.'/'.$d.'*'.$l.')-1))';
    }

    /** The station motif D5 A5 F#5 from `$t0`, `$step` apart. */
    private static function motif(float $t0, float $step, float $decay, float $amp, float $key): string
    {
        $t0 = max(0.0, $t0);

        return self::bell($t0, 587.33 * $key, $decay, $amp).'+'.self::bell($t0 + $step, 880 * $key, $decay, $amp).'+'.self::bell($t0 + 2 * $step, 739.99 * $key, $decay * 0.6, $amp);
    }

    /** A noise riser over `$duration` from `$t0` (deterministic noise). */
    private static function noiseRise(float $t0, float $duration, float $amp): string
    {
        return self::f($amp).'*between(t,'.self::f($t0).','.self::f($t0 + $duration).')*pow((t-'.self::f($t0).')/'.self::f(max(0.05, $duration)).',3)*(2*random(0)-1)';
    }

    private static function f(float $value): string
    {
        return rtrim(rtrim(sprintf('%.5F', $value), '0'), '.') ?: '0';
    }
}
