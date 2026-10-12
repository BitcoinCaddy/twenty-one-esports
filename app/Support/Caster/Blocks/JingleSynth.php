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

    /** The length of every bed loop (seconds): every periodic part of a bed divides it, so the file loops seamlessly. */
    public const LOOP_SECONDS = 16;

    /**
     * The stingers (P11): hits, whooshes, risers, laser zaps, bass drops, glitch, an 8-bit coin, each an aevalsrc
     * expression, its length and the filter after it. The `news-*` stingers (2 to 4 s) rotate before a bulletin.
     */
    public const STINGERS = ['hit-boom', 'hit-metal', 'whoosh-up', 'whoosh-down', 'riser-long', 'riser-short', 'laser-zap', 'laser-double',
        'bass-drop', 'glitch', 'coin-8bit', 'reverse-swell', 'tape-stop', 'news-pulse', 'news-rise', 'news-clock'];

    /** The beds (P11), loops of LOOP_SECONDS: two news beds, sport, weather, courtroom, late night, tutorial. */
    public const LOOP_BEDS = ['news-pulse', 'news-drive', 'sport', 'weather', 'courtroom', 'late-night', 'tutorial'];

    /**
     * How an imaging category is produced (P11): the tempo factor on the Anchor's slow default length (a short ID
     * must stay short), the opening stingers (one by the line's hash), the seconds of stinger before the voice, the
     * closing hits, the seconds after the voice, the beds under it (none: the stream's own music is the bed).
     *
     * @var array<string, array{tempo: float, stingers: list<string>, intro: float, hits: list<string>, tail: float, beds: list<string>}>
     */
    public const IMAGING = [
        'station-long' => ['tempo' => 0.8, 'stingers' => ['riser-long', 'reverse-swell'], 'intro' => 2.7, 'hits' => ['hit-boom', 'bass-drop'], 'tail' => 2.2, 'beds' => ['news-drive', 'sport', 'news-pulse']],
        'station-short' => ['tempo' => 0.65, 'stingers' => ['laser-zap', 'whoosh-up', 'coin-8bit', 'glitch'], 'intro' => 0.5, 'hits' => ['hit-metal', 'hit-boom'], 'tail' => 0.7, 'beds' => []],
        'sweeper' => ['tempo' => 0.6, 'stingers' => ['whoosh-up', 'whoosh-down', 'laser-double', 'tape-stop', 'riser-short'], 'intro' => 0.8, 'hits' => ['hit-boom', 'hit-metal', 'coin-8bit'], 'tail' => 0.8, 'beds' => []],
        'liner' => ['tempo' => 0.75, 'stingers' => ['riser-short', 'whoosh-up', 'glitch'], 'intro' => 1.1, 'hits' => ['hit-boom', 'bass-drop'], 'tail' => 1.2, 'beds' => ['late-night', 'tutorial', 'weather']],
        'opener' => ['tempo' => 0.7, 'stingers' => ['riser-short', 'reverse-swell', 'laser-double'], 'intro' => 1.2, 'hits' => ['hit-boom', 'hit-metal'], 'tail' => 1.3, 'beds' => ['news-drive', 'tutorial']],
        'news-open' => ['tempo' => 0.75, 'stingers' => ['news-rise', 'news-clock', 'news-pulse'], 'intro' => 1.6, 'hits' => ['hit-boom'], 'tail' => 1.0, 'beds' => ['news-pulse', 'news-drive']],
        'news-close' => ['tempo' => 0.55, 'stingers' => ['whoosh-down'], 'intro' => 0.4, 'hits' => ['hit-metal', 'hit-boom'], 'tail' => 0.9, 'beds' => ['news-pulse']],
        'promo' => ['tempo' => 0.8, 'stingers' => ['riser-short', 'whoosh-up', 'glitch'], 'intro' => 1.4, 'hits' => ['bass-drop', 'hit-boom'], 'tail' => 1.5, 'beds' => ['tutorial', 'news-drive', 'weather']],
        'power-intro' => ['tempo' => 0.55, 'stingers' => ['whoosh-up', 'laser-zap'], 'intro' => 0.6, 'hits' => ['hit-metal'], 'tail' => 0.6, 'beds' => []],
    ];

    /**
     * The production of an imaging line (P11): its category's recipe with one stinger, hit and bed picked by a hash
     * of the line id (reproducible), the bed of its format (`$formatBeds`, tv.commentary.imaging.format_beds) first.
     *
     * @param  array<string, string>  $formatBeds
     * @return array{tempo: float, stinger: string, intro: float, hit: string, tail: float, bed: string|null, effect: string}
     */
    public static function imagingRecipe(string $id, string $category, array $formatBeds = []): array
    {
        $family = match (true) {
            str_starts_with($category, 'opener-') => 'opener',
            str_starts_with($category, 'promo') => 'promo',
            default => $category,
        };
        $spec = self::IMAGING[$family] ?? self::IMAGING['sweeper'];
        $format = (string) preg_replace('/^(opener|promo)-/', '', $category);
        $hash = crc32($id);
        $bed = $formatBeds[$format] ?? ($spec['beds'] === [] ? null : $spec['beds'][$hash % count($spec['beds'])]);

        return [
            'tempo' => $spec['tempo'],
            'stinger' => $spec['stingers'][$hash % count($spec['stingers'])],
            'intro' => $spec['intro'],
            'hit' => $spec['hits'][intdiv($hash, 7) % count($spec['hits'])],
            'tail' => $spec['tail'],
            'bed' => in_array($bed, self::LOOP_BEDS, true) ? $bed : null,
            'effect' => ['dry', 'room', 'slap'][intdiv($hash, 31) % 3],
        ];
    }

    /**
     * A stinger or a bed (`bed:<name>`) as an aevalsrc expression, its length and the filter after it (anull: none).
     *
     * @return array{expression: string, seconds: float, filter: string}
     */
    public static function sound(string $name): array
    {
        $q = fn (float $frequency): float => round($frequency * self::LOOP_SECONDS) / self::LOOP_SECONDS;
        $sine = fn (float $frequency, float $amp): string => self::f($amp).'*sin(2*PI*'.self::f($q($frequency)).'*t)';
        $lfo = fn (float $period): string => '(0.6+0.4*sin(2*PI*t/'.self::f($period).'))';
        $pluck = fn (float $every, float $offset, float $frequency, float $decay, float $amp): string => self::f($amp).'*exp(-mod(t+'.self::f($every - $offset).','.self::f($every).')*'.self::f($decay).')*sin(2*PI*'.self::f($q($frequency)).'*t)';
        $kick = fn (float $every, float $amp): string => self::f($amp).'*exp(-mod(t,'.self::f($every).')*10)*sin(2*PI*(42*mod(t,'.self::f($every).')+3*(1-exp(-mod(t,'.self::f($every).')*18))))';
        $tick = fn (float $every, float $offset, float $amp): string => self::f($amp).'*exp(-mod(t+'.self::f($every - $offset).','.self::f($every).')*70)*sin(2*PI*'.self::f($q(2400)).'*t+6*sin(2*PI*'.self::f($q(3700)).'*t))';
        $arp = fn (array $notes, float $step, float $decay, float $amp): string => implode('+', array_map(
            fn (float $note, int $k): string => 'eq(floor(mod(t,'.self::f($step * count($notes)).')/'.self::f($step).'),'.$k.')*'.self::f($amp).'*exp(-mod(t,'.self::f($step).')*'.self::f($decay).')*sin(2*PI*'.self::f($q($note)).'*t)',
            $notes, array_keys($notes)));
        $noise = fn (float $t0, float $d, float $amp, int $power): string => self::f($amp).'*between(t,'.self::f($t0).','.self::f($t0 + $d).')*pow((t-'.self::f($t0).')/'.self::f($d).','.$power.')*(2*random(0)-1)';
        $swoosh = fn (float $t0, float $d, float $amp): string => self::f($amp).'*between(t,'.self::f($t0).','.self::f($t0 + $d).')*pow(sin(PI*(t-'.self::f($t0).')/'.self::f($d).'),2)*(2*random(0)-1)';

        $sounds = [
            'hit-boom' => [self::boom(0.0, 0.7).'+'.self::bell(0.0, 587.33, 2.6, 0.18).'+'.self::bell(0.0, 880, 2.6, 0.12).'+'.self::bell(0.0, 739.99, 2.0, 0.1), 1.8, 'anull'],
            'hit-metal' => [self::bell(0.0, 1046.5, 3.2, 0.25).'+'.self::bell(0.0, 1479.98, 4.0, 0.15).'+'.self::bell(0.0, 2217.46, 5.0, 0.08).'+'.self::boom(0.0, 0.35), 1.5, 'anull'],
            'whoosh-up' => [$swoosh(0.0, 1.2, 0.6).'+'.self::chirp(0.0, 1.2, 200, 2400, 0.12), 1.4, 'lowpass=f=4500,highpass=f=300'],
            'whoosh-down' => [$swoosh(0.0, 1.0, 0.6).'+'.self::chirp(0.0, 1.0, 2400, 180, 0.12), 1.2, 'lowpass=f=3500,highpass=f=200'],
            'riser-long' => [$noise(0.0, 2.6, 0.35, 3).'+'.self::chirp(0.0, 2.6, 110, 1760, 0.22).'+'.self::boom(2.6, 0.75).'+'.self::motif(2.6, 0.14, 3.0, 0.22, 1.0), 3.6, 'highpass=f=60'],
            'riser-short' => [$noise(0.0, 1.2, 0.35, 3).'+'.self::chirp(0.0, 1.2, 220, 1760, 0.2).'+'.self::boom(1.2, 0.6), 2.0, 'highpass=f=60'],
            'laser-zap' => [self::chirp(0.0, 0.35, 3200, 160, 0.4).'+'.self::bell(0.35, 1174.66, 6.0, 0.15), 0.9, 'anull'],
            'laser-double' => [self::chirp(0.0, 0.25, 3600, 200, 0.35).'+'.self::chirp(0.3, 0.25, 3600, 200, 0.35).'+'.self::boom(0.6, 0.45), 1.3, 'anull'],
            'bass-drop' => ['0.75*min(1,t*40)*exp(-t*1.4)*sin(2*PI*(30*t+40*(1-exp(-t*2.5))))+'.self::bell(0.0, 293.66, 2.0, 0.12), 2.4, 'lowpass=f=900'],
            'glitch' => ['lt(mod(t,0.09),0.045)*('.self::motif(0.0, 0.18, 2.5, 0.3, 1.0).')+'.self::boom(0.9, 0.5), 1.5, 'anull'],
            'coin-8bit' => ['0.22*lt(t,0.08)*sgn(sin(2*PI*987.77*t))+0.22*between(t,0.08,0.7)*exp(-(t-0.08)*5)*sgn(sin(2*PI*1318.51*t))', 0.75, 'lowpass=f=7000'],
            'reverse-swell' => ['0.32*lt(t,2.0)*pow(t/2.0,4)*(sin(2*PI*587.33*t)+0.7*sin(2*PI*880*t)+0.5*sin(2*PI*739.99*t)+0.4*sin(2*PI*146.83*t))+'.self::boom(2.0, 0.7), 2.6, 'anull'],
            'tape-stop' => ['0.3*lt(t,1.2)*(1-t/1.2)*sin(2*PI*(660*t-250*t*t))+0.25*lt(t,1.2)*(1-t/1.2)*sin(2*PI*(330*t-125*t*t))', 1.4, 'anull'],
            'news-pulse' => ['0.22*lt(t,1.5)*exp(-mod(t,0.25)*60)*sin(2*PI*1760*t)+'.self::boom(1.5, 0.6).'+'.self::motif(1.5, 0.18, 2.4, 0.26, 1.0).'+'.self::motif(1.5, 0.18, 3.0, 0.1, 2.0), 3.2, 'anull'],
            'news-rise' => [$noise(0.0, 1.6, 0.25, 3).'+'.self::chirp(0.0, 1.6, 147, 1175, 0.2).'+'.self::boom(1.6, 0.6).'+'.self::motif(1.6, 0.2, 2.2, 0.26, 1.122462), 3.4, 'highpass=f=60'],
            'news-clock' => ['0.2*lt(t,1.2)*exp(-mod(t,0.125)*90)*sin(2*PI*2400*t)+'.self::boom(1.2, 0.55).'+'.self::motif(1.2, 0.16, 2.6, 0.26, 0.890899).'+'.self::bell(1.9, 1174.66, 2.5, 0.1), 2.9, 'anull'],
            // The beds: every part periodic within LOOP_SECONDS (beats of 0.25 to 4 s, frequencies on a 1/16 Hz grid, no noise).
            'bed:news-pulse' => [$kick(0.5, 0.3).'+'.$tick(0.25, 0.125, 0.06).'+'.$lfo(8).'*('.$sine(146.83, 0.05).'+'.$sine(220, 0.035).'+'.$sine(174.61, 0.03).')', self::LOOP_SECONDS, 'anull'],
            'bed:news-drive' => [$kick(0.5, 0.28).'+'.$tick(0.25, 0.0, 0.05).'+'.$arp([73.42, 73.42, 87.31, 65.41], 0.25, 9, 0.22).'+'.$lfo(16).'*('.$sine(293.66, 0.03).'+'.$sine(440, 0.02).')', self::LOOP_SECONDS, 'anull'],
            'bed:sport' => [$kick(0.5, 0.32).'+'.$tick(0.5, 0.25, 0.09).'+'.$arp([98.0, 98.0, 123.47, 146.83], 0.25, 8, 0.2).'+'.$arp([587.33, 783.99, 987.77, 783.99], 0.5, 6, 0.06), self::LOOP_SECONDS, 'anull'],
            'bed:weather' => [$lfo(8).'*('.$sine(261.63, 0.05).'+'.$sine(329.63, 0.04).'+'.$sine(392.0, 0.035).'+'.$sine(493.88, 0.025).')+'.$pluck(2.0, 0.0, 1046.5, 3.0, 0.07).'+'.$pluck(2.0, 1.0, 1318.51, 3.0, 0.05), self::LOOP_SECONDS, 'anull'],
            'bed:courtroom' => [$sine(73.42, 0.09).'+'.$sine(110.0, 0.05).'+'.$lfo(16).'*'.$sine(146.83, 0.03).'+0.4*exp(-mod(t,2)*4)*sin(2*PI*(55*mod(t,2)+2*(1-exp(-mod(t,2)*12))))+'.$tick(4.0, 3.0, 0.2), self::LOOP_SECONDS, 'anull'],
            'bed:late-night' => [$kick(1.0, 0.22).'+'.$lfo(16).'*('.$sine(110.0, 0.05).'+'.$sine(130.81, 0.04).'+'.$sine(164.81, 0.035).')+'.$pluck(1.0, 0.5, 659.25, 5.0, 0.05), self::LOOP_SECONDS, 'anull'],
            'bed:tutorial' => [$arp([392.0, 493.88, 587.33, 493.88], 0.5, 5, 0.09).'+'.$lfo(8).'*('.$sine(196.0, 0.04).'+'.$sine(293.66, 0.03).')+'.$kick(1.0, 0.12), self::LOOP_SECONDS, 'anull'],
        ];
        [$expression, $seconds, $filter] = $sounds[$name] ?? $sounds['hit-boom'];

        return ['expression' => $expression, 'seconds' => (float) $seconds, 'filter' => $filter];
    }

    /**
     * The ffmpeg arguments that render a stinger or bed (sound()) into a float WAV of `$seconds` (its own length by
     * default); masterArguments() brings it to -16 LUFS.
     *
     * @return list<string>
     */
    public static function soundArguments(string $ffmpeg, string $name, string $out, ?float $seconds = null): array
    {
        $sound = self::sound($name);

        return [
            'nice', '-n', '19', $ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-threads', '1',
            '-f', 'lavfi', '-i', sprintf("aevalsrc=exprs='%s':s=%d:d=%.3F", $sound['expression'], self::RATE, $seconds ?? $sound['seconds']),
            '-af', $sound['filter'], '-ac', '1', '-ar', (string) self::RATE, '-c:a', 'pcm_f32le', '-y', $out,
        ];
    }

    /**
     * The ffmpeg arguments of a produced imaging item (P11): the opening stinger from 0, the voice `$intro` seconds
     * in, the closing hit right after the voice, the bed looped under all of it and ducked under the voice, `$tail`
     * seconds after the voice, faded out; into a float WAV (masterArguments() brings it to -16 LUFS).
     *
     * @return list<string>
     */
    public static function produceArguments(string $ffmpeg, string $voiceFile, float $voiceSeconds, string $stingerFile, string $hitFile, ?string $bedFile, string $effect, float $intro, float $tail, string $out): array
    {
        $duration = $intro + $voiceSeconds + $tail;
        $withBed = $bedFile !== null;
        $bed = $withBed ? sprintf(
            '[3:a]aresample=%1$d,aformat=channel_layouts=mono,atrim=duration=%2$.3F,volume=-9dB,afade=t=in:d=0.4,afade=t=out:st=%3$.3F:d=0.8[bedraw];[bedraw][vk]sidechaincompress=threshold=0.015:ratio=6:attack=15:release=450[bed];',
            self::RATE, $duration, max(0.0, $duration - 0.8),
        ) : '';
        $graph = sprintf(
            '[0:a]aresample=%1$d,aformat=channel_layouts=mono,highpass=f=70,%2$s,adelay=%3$d,apad=whole_dur=%4$.3F%5$s;'
            .'[1:a]aresample=%1$d,aformat=channel_layouts=mono,volume=-2dB,apad=whole_dur=%4$.3F[st];'
            .'[2:a]aresample=%1$d,aformat=channel_layouts=mono,volume=-1dB,adelay=%6$d,apad=whole_dur=%4$.3F[hit];'
            .'%7$s[v][st][hit]%8$samix=inputs=%9$d:normalize=0:duration=first,atrim=duration=%4$.3F,afade=t=out:st=%10$.3F:d=0.3[out]',
            self::RATE, self::EFFECTS[$effect] ?? 'anull', (int) round($intro * 1000), $duration, $withBed ? ',asplit=2[v][vk]' : '[v]',
            (int) round(($intro + $voiceSeconds + 0.05) * 1000), $bed, $withBed ? '[bed]' : '', $withBed ? 4 : 3, max(0.0, $duration - 0.3),
        );
        $inputs = ['-i', $voiceFile, '-i', $stingerFile, '-i', $hitFile];

        if ($bedFile !== null) {
            array_push($inputs, '-stream_loop', '-1', '-i', $bedFile);
        }

        return [
            'nice', '-n', '19', $ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-threads', '1', ...$inputs,
            '-filter_complex', $graph, '-map', '[out]', '-ac', '1', '-ar', (string) self::RATE, '-c:a', 'pcm_f32le', '-y', $out,
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
