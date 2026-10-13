<?php

use App\Support\Caster\Blocks\BlockComposer;
use App\Support\Caster\Blocks\BlockRenderer;
use App\Support\Caster\Blocks\JingleLibrary;
use App\Support\Caster\Blocks\JingleSynth;
use App\Support\Caster\Blocks\StudioAudience;
use App\Support\Caster\CasterFetcher;
use App\Support\Caster\Idents;
use App\Support\Caster\Pcm;
use App\Support\Caster\Programme\Roasts;
use App\Support\Caster\Programme\Rubrics;
use App\Support\Caster\Programme\Shows;
use App\Support\Caster\Programme\TalkShow;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * The studio around a show rendered on the workstation (plan "TWENTY ONE Radio", P20): the format bed under every show
 * of a block, applause in the pauses, the talk show's theme and its slower breath between turns.
 */

beforeEach(function () {
    Storage::fake('local');
    config([
        'tv.commentary.voicestudio.url' => 'http://voicestudio.test',
        'tv.commentary.voicestudio.retry_seconds' => 0,
        'tv.commentary.voicestudio.pause.jitter' => 0.0,
        'tv.commentary.voicestudio.profiles' => ['markus' => 'mmmm', 'sirius' => 'ssss', 'genossin' => 'gggg'],
        'tv.commentary.voicestudio.keep_local_dir' => '',
        'tv.commentary.blocks.blips.share' => 0.0,
        'tv.commentary.idents.enabled' => true,
        'tv.commentary.idents.bed_at_night' => true,
        'tv.commentary.idents.applause.enabled' => true,
        'tv.commentary.idents.talk_theme.enabled' => true,
    ]);
});

/** One chunk of a script for StudioAudience: a speaker and its sentences. */
function studioChunk(string $speaker, string ...$lines): array
{
    return ['speaker' => $speaker, 'lines' => array_values($lines)];
}

/** A talk show in chunks: cold open, welcome, a hit and its reaction, the fact check, the closing round. */
function studioTalkChunks(): array
{
    return [
        studioChunk('sirius', 'Es ist einundzwanzig Uhr, Zeit für Turm und Genossin, der Talk.'),
        studioChunk('genossin', 'Der Staat kann sich gar nicht pleite drucken, Herr Turm!'),
        studioChunk('markus', 'Das sagen alle, kurz bevor das Brot doppelt so teuer ist.'),
        studioChunk('sirius', 'Guten Abend und willkommen, bei mir sind Markus Turm und Genossin Gisela.'),
        studioChunk('markus', 'Jeder Sparer zahlt die Schulden, und keiner hat ihn gefragt.'),
        studioChunk('genossin', 'Sie verwechseln schon wieder Geld mit Gold, Herr Turm!'),
        studioChunk('sirius', 'Das hat gesessen, Herr Turm, was sagen Sie jetzt?'),
        studioChunk('markus', 'Ich zähle nur nach, und das macht in Ihrer Partei keiner.'),
        studioChunk('sirius', 'Dann machen wir jetzt mal einen kleinen Faktencheck, wer hat recht?'),
        studioChunk('genossin', 'Das waren die Energiepreise und gierige Konzerne, sonst nichts.'),
        studioChunk('sirius', 'Wir müssen zum Ende kommen, die Schlussrunde, ein Satz pro Person.'),
        studioChunk('genossin', 'Geld gehört der Gesellschaft, und die entscheidet darüber.'),
        studioChunk('markus', 'Geld gehört dem, der dafür gearbeitet hat.'),
        studioChunk('sirius', 'Halten wir fest, Sie sind sich in gar nichts einig.'),
    ];
}

/** A VoiceStudio answer: `$seconds` of loud tone (amplitude 8000), so any word stands out against the quiet studio. */
function studioWav(float $seconds): string
{
    $pcm = '';
    for ($i = 0; $i < (int) (24000 * $seconds); $i++) {
        $pcm .= pack('v', ((int) (8000 * sin($i / 8))) & 0xFFFF);
    }

    return Pcm::toWav($pcm, 24000);
}

/** The loudest sample of a stretch of PCM. */
function studioPeak(string $pcm, float $from, float $to): int
{
    $samples = unpack('s*', substr($pcm, (int) round($from * 24000) * 2, (int) round(($to - $from) * 24000) * 2)) ?: [];

    return $samples === [] ? 0 : max(array_map('abs', $samples));
}

/** The RMS of a stretch of PCM. */
function studioRms(string $pcm, float $from, float $to): float
{
    $samples = unpack('s*', substr($pcm, (int) round($from * 24000) * 2, (int) round(($to - $from) * 24000) * 2)) ?: [];

    return $samples === [] ? 0.0 : sqrt(array_sum(array_map(fn (int $sample): int => $sample * $sample, $samples)) / count($samples));
}

/** Renders one script through the real BlockRenderer (real ffmpeg for the studio's files), every chunk `$seconds` of tone. */
function studioRender(array $chunks, string $topic, float $seconds = 3.0): array
{
    Http::fake(fn (Request $request) => str_ends_with($request->url(), '/generate') ? Http::response(studioWav($seconds)) : Http::response([]));
    $dir = sys_get_temp_dir().'/studio-jingles-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);
    $script = ['id' => 1, 'kind' => 'rubric', 'topic' => $topic,
        'sentences' => array_merge(...array_column($chunks, 'lines')),
        'speakers' => array_merge(...array_map(fn (array $chunk): array => array_fill(0, count($chunk['lines']), $chunk['speaker']), $chunks))];

    return (new BlockRenderer(new CasterFetcher(fn () => null), new JingleLibrary($dir, 30), Idents::fromConfig(), fn (): float => 0.0))
        ->render([$script], [], CarbonImmutable::parse('2026-10-13 20:15'));
}

it('lays the bed of its format under every show, roast and rubric of a rendered block, also the talk show and a stock episode', function () {
    $idents = Idents::fromConfig();
    $formats = [...array_keys(Shows::CATALOGUE), ...array_keys(Roasts::CATALOGUE), ...array_keys(Rubrics::CATALOGUE)];

    foreach ($formats as $format) {
        expect($idents->formatBed($format))->not->toBeNull("{$format} has no bed")
            ->and($idents->formatBed('stock:'.$format))->toBe($idents->formatBed($format));
    }

    expect($formats)->toContain(TalkShow::ID)
        ->and($idents->formatBed('bulletin'))->toBeNull()
        ->and($idents->formatBed(null))->toBeNull();

    // In a rendered block: the bed is in the steps and audible in a pause, where there is no voice.
    config(['tv.commentary.idents.applause.enabled' => false, 'tv.commentary.idents.talk_theme.enabled' => false]);
    $block = studioRender([studioChunk('markus', 'Das Tribunal tagt, und der erste Fall ist schwer.'), studioChunk('sirius', 'Der Angeklagte hat die Dame dreimal eingestellt.')], Shows::TRIBUNAL, 2.0);

    expect($block['steps'])->toContain('bed:bed-courtroom.opus');
    $voiceEnd = $block['ends'][0];
    expect(studioPeak($block['pcm'], $voiceEnd + 0.1, $voiceEnd + 0.35))->toBeGreaterThan(0)->toBeLessThan(2500);
});

it('leaves the bed out of a block in the quiet hours unless bed_at_night, like the server does', function () {
    config(['tv.commentary.idents.bed_at_night' => false, 'tv.commentary.spool.quiet_hours.from' => '00:00', 'tv.commentary.spool.quiet_hours.to' => '23:59',
        'tv.commentary.idents.applause.enabled' => false, 'tv.commentary.idents.talk_theme.enabled' => false]);

    $block = studioRender([studioChunk('markus', 'Das Tribunal tagt, und der erste Fall ist schwer.')], Shows::TRIBUNAL, 1.0);

    expect($block['steps'])->toBe(['script:1']);
});

it('places applause after the welcome, after a hit and at the close of a talk show, and only there', function () {
    $chunks = studioTalkChunks();
    $seconds = array_fill(0, count($chunks), 8.0);

    // The welcome (position 3, after the cold open), the host's reaction to the hit (6), the close (13).
    expect(StudioAudience::applause(TalkShow::ID, $chunks, $seconds))->toBe([3 => StudioAudience::OPEN, 6 => StudioAudience::HIT, 13 => StudioAudience::CLOSE])
        // A stock episode is the same format.
        ->and(StudioAudience::applause('stock:'.TalkShow::ID, $chunks, $seconds))->toBe([3 => StudioAudience::OPEN, 6 => StudioAudience::HIT, 13 => StudioAudience::CLOSE]);
});

it('places applause in any other show at its open, after a punchline the next turn laughs at, and at its close', function () {
    $chunks = [
        studioChunk('markus', 'Es ist zwanzig Uhr, Zeit für das Chat-Tribunal.'),
        studioChunk('sirius', 'Der Angeklagte nennt seine eingestellte Dame einfach Pech.'),
        studioChunk('markus', 'Das war kein Pech, das war ein Lebensstil!'),
        studioChunk('sirius', '[laughter] Ein Lebensstil, das kommt ins Urteil.'),
        studioChunk('markus', 'Das Tribunal ist geschlossen!'),
    ];

    expect(StudioAudience::applause(Shows::TRIBUNAL, $chunks, array_fill(0, 5, 12.0)))->toBe([0 => StudioAudience::OPEN, 2 => StudioAudience::HIT, 4 => StudioAudience::CLOSE]);
});

it('keeps applause apart and capped: never two within min_gap_seconds, at most max_per_show, the open and close first', function () {
    $hits = [];
    for ($i = 0; $i < 8; $i++) {
        $hits[] = studioChunk('genossin', 'Sie verwechseln schon wieder alles, Herr Turm!');
        $hits[] = studioChunk('sirius', 'Das hat gesessen, Herr Turm.');
    }
    $chunks = [studioChunk('genossin', 'Drucken ist Buchhaltung!'), studioChunk('sirius', 'Willkommen zum Talk.'), ...$hits, studioChunk('sirius', 'Gute Nacht.')];

    // Six seconds a chunk: hits every twelve seconds, so at most every other one fits the twenty seconds.
    $placed = StudioAudience::applause(TalkShow::ID, $chunks, array_fill(0, count($chunks), 6.0));
    $ends = array_map(fn (int $position): float => ($position + 1) * 6.0 + $position * 0.1, array_keys($placed));

    expect(count($placed))->toBe(4)
        ->and($placed[1])->toBe(StudioAudience::OPEN)
        ->and($placed[count($chunks) - 1])->toBe(StudioAudience::CLOSE);
    for ($i = 1; $i < count($ends); $i++) {
        expect($ends[$i] - $ends[$i - 1])->toBeGreaterThanOrEqual(20.0);
    }

    config(['tv.commentary.idents.applause.max_per_show' => 2]);
    expect(StudioAudience::applause(TalkShow::ID, $chunks, array_fill(0, count($chunks), 6.0)))->toBe([1 => StudioAudience::OPEN, count($chunks) - 1 => StudioAudience::CLOSE]);
});

it('gives no applause to news, a bulletin, Sport und Wetter, the podcast corner or a plain rubric, nor while it is switched off', function () {
    $chunks = studioTalkChunks();
    $seconds = array_fill(0, count($chunks), 6.0);

    foreach (['bulletin', 'nachrichten_talk', 'sport_wetter', Rubrics::PODCAST, Rubrics::PRESS, Rubrics::LIGA, Rubrics::HISTORY, Rubrics::CHAT, null, ''] as $topic) {
        expect(StudioAudience::applause($topic, $chunks, $seconds))->toBe([], (string) $topic);
    }

    config(['tv.commentary.idents.applause.enabled' => false]);
    expect(StudioAudience::applause(TalkShow::ID, $chunks, $seconds))->toBe([]);

    // Rendered: not one applause file in the block.
    config(['tv.commentary.idents.talk_theme.enabled' => false]);
    expect(studioRender($chunks, TalkShow::ID, 1.0)['studio'])->toBe([]);
});

it('opens the talk show with its theme before the welcome, stings the fact check and the closing round, and closes with the outro', function () {
    expect(StudioAudience::theme(TalkShow::ID, studioTalkChunks()))->toBe(['before' => [3 => StudioAudience::INTRO, 8 => StudioAudience::STING, 10 => StudioAudience::STING], 'outro' => 13, 'acts' => ['fact', 'close'], 'welcome_pending' => false])
        // Only the talk show has a theme, and none while it is off.
        ->and(StudioAudience::theme(Shows::TRIBUNAL, studioTalkChunks()))->toBe(['before' => [], 'outro' => null, 'acts' => [], 'welcome_pending' => false]);

    config(['tv.commentary.idents.talk_theme.enabled' => false]);
    expect(StudioAudience::theme(TalkShow::ID, studioTalkChunks()))->toBe(['before' => [], 'outro' => null, 'acts' => [], 'welcome_pending' => false]);
});

it('mixes applause and theme into the pauses of a rendered talk show, never over a word, quiet against the voice', function () {
    // Short chunks for the fake: the twenty seconds between two applause would leave only the open and the close.
    config(['tv.commentary.idents.applause.min_gap_seconds' => 5]);
    $block = studioRender(studioTalkChunks(), TalkShow::ID, 3.0);
    $overlap = (float) config('tv.commentary.idents.talk_theme.overlap_seconds');
    $parts = array_column($block['studio'], 'part');

    expect($parts)->toBe(['theme-intro', 'applause-open', 'applause-hit', 'theme-sting', 'theme-sting', 'applause-close', 'theme-outro']);

    foreach ($block['studio'] as $sound) {
        $from = $sound['at'];
        // The intro's last overlap_seconds run under the welcome on purpose, 12 dB down and fading out.
        $to = $sound['at'] + $sound['seconds'] - ($sound['part'] === 'theme-intro' ? $overlap + 0.05 : 0.0);

        // The voice is a tone of amplitude 8000: under 5000 there is no word in the stretch, and the sound is quieter than speech.
        expect(studioPeak($block['pcm'], $from, $to))->toBeGreaterThan(30, $sound['part'].' is silent')->toBeLessThan(5000, $sound['part'].' sits on a word or is too loud');
    }

    // The host's welcome starts over the intro's last overlap_seconds, it does not wait for the intro to end.
    $intro = $block['studio'][0];
    expect(studioPeak($block['pcm'], $intro['at'] + $intro['seconds'] - $overlap + 0.45, $intro['at'] + $intro['seconds']))->toBeGreaterThan(3000);

    // Applause about 20 dB under the voice (RMS of its loudest half second against the voice's).
    $clap = $block['studio'][1];
    $loudest = max(array_map(fn (int $step): float => studioRms($block['pcm'], $clap['at'] + $step * 0.1, $clap['at'] + $step * 0.1 + 0.5), range(0, (int) floor(($clap['seconds'] - 0.5) * 10))));
    $voice = studioRms($block['pcm'], 0.3, $block['ends'][0] - 0.3);
    expect(20 * log10($voice / $loudest))->toBeGreaterThan(17.0)->toBeLessThan(23.0);
});

it('lets the talk show breathe like a TV talk: turns 450 to 800 ms apart, a cut-in quicker, the long breath before the fact check and after a hit', function () {
    expect(TalkShow::gapKind('sirius', ['Dann machen wir jetzt mal einen kleinen Faktencheck.']))->toBe(TalkShow::GAP_LONG)
        ->and(TalkShow::gapKind('sirius', ['Wir müssen zum Ende kommen, die Schlussrunde.']))->toBe(TalkShow::GAP_LONG)
        ->and(TalkShow::gapKind('sirius', ['Das hat gesessen, Herr Turm.']))->toBe(TalkShow::GAP_LONG)
        ->and(TalkShow::gapKind('markus', ['Das hat gesessen, sagt er.']))->toBe(TalkShow::GAP_TURN)
        ->and(TalkShow::gapKind('sirius', ['Moment, Moment, das lasse ich so nicht stehen.']))->toBe(TalkShow::GAP_INTERRUPT)
        ->and(TalkShow::gapKind('genossin', ['[surprise-oh] Nein, nein, so nicht!']))->toBe(TalkShow::GAP_INTERRUPT)
        ->and(TalkShow::gapKind('markus', ['Ich zähle nur nach.']))->toBe(TalkShow::GAP_TURN)
        ->and(TalkShow::gapFor(TalkShow::ID, TalkShow::GAP_TURN))->toBe(['min' => 0.45, 'max' => 0.8])
        ->and(TalkShow::gapFor('stock:'.TalkShow::ID, TalkShow::GAP_INTERRUPT))->toBe(['min' => 0.25, 'max' => 0.25])
        ->and(TalkShow::gapFor(TalkShow::ID, TalkShow::GAP_LONG))->toBe(['min' => 1.0, 'max' => 1.4])
        ->and(TalkShow::gapFor(Shows::TRIBUNAL, TalkShow::GAP_TURN))->toBeNull()
        ->and(TalkShow::maxInterruptions())->toBe(2)
        ->and(config('tv.commentary.voicestudio.heated_speeds.genossin'))->toBe(1.0);
});

it('cuts in quicker at most max_interruptions times, the rest of the cut-ins get the turn\'s breath', function () {
    config(['tv.commentary.idents.applause.enabled' => false, 'tv.commentary.idents.talk_theme.enabled' => false,
        'tv.commentary.programme.shows.formats.stammtisch.turn_gap_ms' => ['min' => 600, 'max' => 600], 'tv.commentary.programme.shows.formats.stammtisch.max_interruptions' => 2]);
    $chunks = [studioChunk('markus', 'Geld muss knapp sein, sonst ist es nichts wert.')];
    for ($i = 0; $i < 4; $i++) {
        $chunks[] = studioChunk('genossin', 'Moment, das ist doch Unsinn, Herr Turm.');
        $chunks[] = studioChunk('markus', 'Nee, das ist Mathematik, Genossin.');
    }

    $block = studioRender($chunks, TalkShow::ID, 1.0);
    // Every chunk is the same tone (with the fetcher's pads): the first end is one chunk's length.
    $gaps = array_map(fn (int $i): float => round($block['ends'][$i] - $block['ends'][$i - 1] - $block['ends'][0], 2), range(1, count($chunks) - 1));

    // Eight cut-ins ("Moment …", "Nee …"): the first two at 0.25 s, the other six at the turn's 0.6 s.
    expect($gaps)->toBe([0.25, 0.25, 0.6, 0.6, 0.6, 0.6, 0.6, 0.6]);
});

it('ships every studio sound it names: rendered by JingleSynth, in resources/audio/idents, with a license row', function () {
    $licenses = (string) file_get_contents(resource_path('audio/idents/LICENSES.md'));
    $named = [...array_merge(...array_values((array) config('tv.commentary.idents.applause.files'))),
        config('tv.commentary.idents.talk_theme.intro'), config('tv.commentary.idents.talk_theme.outro'), config('tv.commentary.idents.talk_theme.sting')];

    foreach (array_unique($named) as $file) {
        expect(in_array(basename($file, '.opus'), JingleSynth::STUDIO, true))->toBeTrue("{$file} is not rendered by JingleSynth")
            ->and(is_file(resource_path('audio/idents/'.$file)))->toBeTrue("{$file} is missing")
            ->and($licenses)->toContain('`'.$file.'`');
    }

    expect(JingleSynth::sound('applause-big-1')['seconds'])->toBeGreaterThanOrEqual(3.0)->toBeLessThanOrEqual(4.0)
        ->and(JingleSynth::sound('applause-short-1')['seconds'])->toBeGreaterThanOrEqual(1.0)->toBeLessThanOrEqual(2.0)
        ->and(JingleSynth::sound('theme-talk-intro')['seconds'])->toBeGreaterThanOrEqual(6.0)->toBeLessThanOrEqual(9.0)
        ->and(JingleSynth::sound('theme-talk-outro')['seconds'])->toBeGreaterThanOrEqual(5.0)->toBeLessThanOrEqual(7.0)
        ->and(JingleSynth::sound('theme-talk-sting')['seconds'])->toBeGreaterThanOrEqual(2.0)->toBeLessThanOrEqual(3.0);
});

/**
 * A talk show queued in parts of `$perPart` chunks (ScriptQueue::parts()), rendered in blocks of `$perBlock` parts through
 * the real BlockRenderer, block after block like the fetcher does; each block's render.
 */
function studioEpisode(array $chunks, int $perPart, int $perBlock, string $topic = TalkShow::ID, bool $evergreen = false, string $at = '2026-10-13 18:15'): array
{
    Http::fake(fn (Request $request) => str_ends_with($request->url(), '/generate') ? Http::response(studioWav(3.0)) : Http::response([]));
    $dir = sys_get_temp_dir().'/studio-jingles-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);
    $parts = array_chunk($chunks, $perPart);
    $scripts = [];
    foreach ($parts as $index => $part) {
        $scripts[] = ['id' => 100 + $index, 'kind' => 'rubric', 'topic' => $topic,
            'sentences' => array_merge(...array_column($part, 'lines')),
            'speakers' => array_merge(...array_map(fn (array $chunk): array => array_fill(0, count($chunk['lines']), $chunk['speaker']), $part)),
            'part' => ['group' => 'episode-1', 'index' => $index + 1, 'continues' => $index < count($parts) - 1]];
    }

    $renderer = new BlockRenderer(new CasterFetcher(fn () => null), new JingleLibrary($dir, 30), Idents::fromConfig(), fn (): float => 0.0);

    return array_map(fn (array $block): array => $renderer->render($block, [], CarbonImmutable::parse($at, 'Europe/Berlin'), $evergreen), array_chunk($scripts, $perBlock));
}

/** A long talk show: the opener line, the cold open, the welcome, eight hits, the fact check, the closing round. */
function studioLongTalk(): array
{
    $chunks = array_slice(studioTalkChunks(), 0, 4);
    for ($i = 0; $i < 8; $i++) {
        $chunks[] = studioChunk('genossin', 'Sie verwechseln schon wieder Geld mit Gold, Herr Turm!');
        $chunks[] = studioChunk('sirius', 'Das hat gesessen, Herr Turm, was sagen Sie jetzt?');
        $chunks[] = studioChunk('markus', 'Ich zähle nur nach, und das macht in Ihrer Partei keiner.');
    }

    return [...$chunks, ...array_slice(studioTalkChunks(), 8)];
}

it('treats a talk show queued in parts as one show over all its blocks: one intro, the stings once, one outro, applause capped per episode', function () {
    config(['tv.commentary.idents.applause.min_gap_seconds' => 5]);
    // 34 chunks in parts of two (17 parts), three parts a block: six blocks, like the 39 parts of the live talk.
    $blocks = studioEpisode(studioLongTalk(), 2, 3);
    $parts = array_merge(...array_map(fn (array $block): array => array_column($block['studio'], 'part'), $blocks));
    $count = fn (string $part): int => count(array_filter($parts, fn (string $placed): bool => $placed === $part));
    $applause = count(array_filter($parts, fn (string $placed): bool => str_starts_with($placed, 'applause-')));

    expect(count($blocks))->toBe(6)
        ->and($count('theme-intro'))->toBe(1)
        ->and($count('theme-sting'))->toBe(2)
        ->and($count('theme-outro'))->toBe(1)
        ->and($applause)->toBeLessThanOrEqual((int) config('tv.commentary.idents.applause.max_per_show'))->toBeGreaterThanOrEqual(3)
        ->and($count('applause-open'))->toBe(1)
        ->and($count('applause-close'))->toBe(1)
        // The intro and the open in the first block, the outro and the close in the last.
        ->and(array_column($blocks[0]['studio'], 'part'))->toContain('theme-intro', 'applause-open')
        ->and(array_column($blocks[5]['studio'], 'part'))->toContain('theme-outro', 'applause-close');

    // The same episode rendered again (a failed upload): the same studio, not a second share of the cap.
    $again = studioEpisode(studioLongTalk(), 2, 3);
    expect(array_merge(...array_map(fn (array $block): array => array_column($block['studio'], 'part'), $again)))->toBe($parts);
});

it('opens a block that continues a story without a jingle, and only the first block of an episode with one', function () {
    $slot = CarbonImmutable::parse('2026-10-13 18:15', 'Europe/Berlin');
    $first = BlockComposer::plan([['id' => 1, 'kind' => 'scene', 'topic' => 'stock:stammtisch', 'part' => ['group' => 'g', 'index' => 1, 'continues' => true]]], $slot, 0.0, fn (): float => 0.0, evergreen: true);
    $next = BlockComposer::plan([
        ['id' => 2, 'kind' => 'scene', 'topic' => 'stock:stammtisch', 'part' => ['group' => 'g', 'index' => 13, 'continues' => true]],
        ['id' => 3, 'kind' => 'scene', 'topic' => 'stock:stammtisch', 'part' => ['group' => 'g', 'index' => 14, 'continues' => true]],
    ], $slot, 0.0, fn (): float => 0.0, evergreen: true);

    expect($first[0]['type'])->toBe('jingle')
        ->and($next)->toBe([['type' => 'script', 'index' => 0], ['type' => 'script', 'index' => 1]]);
});

it('plays the parts of one show in a block back to back: the turn breath between two parts, one bed under all of them', function () {
    config(['tv.commentary.idents.applause.enabled' => false, 'tv.commentary.idents.talk_theme.enabled' => false,
        'tv.commentary.programme.shows.formats.stammtisch.turn_gap_ms' => ['min' => 600, 'max' => 600], 'tv.commentary.programme.shows.formats.stammtisch.max_interruptions' => 0]);
    $chunks = [];
    for ($i = 0; $i < 4; $i++) {
        $chunks[] = studioChunk('markus', 'Ich zähle nur nach, und das macht in Ihrer Partei keiner.');
        $chunks[] = studioChunk('genossin', 'Sie verwechseln schon wieder Geld mit Gold, Herr Turm!');
    }

    // Eight chunks in four parts, all in one block.
    $block = studioEpisode($chunks, 2, 4)[0];
    $gaps = array_map(fn (int $i): float => round($block['ends'][$i] - $block['ends'][$i - 1] - $block['ends'][0], 2), range(1, count($chunks) - 1));

    // Inside a part and between two parts alike: the turn's 0.6 s, no gap between the parts.
    expect($gaps)->toBe(array_fill(0, 7, 0.6))
        ->and(array_values(array_filter($block['steps'], fn (string $step): bool => str_starts_with($step, 'bed:'))))->toBe(['bed:bed-news-pulse.opus']);

    // Under the bed the pause between two parts is never silent (the bed does not fade out and in at a part).
    $pause = $block['ends'][1];
    expect(studioPeak($block['pcm'], $pause + 0.15, $pause + 0.45))->toBeGreaterThan(0);
});

it('beds a stock block by air time, not by the hour it is rendered: rendered in the quiet hours it still gets its bed', function () {
    config(['tv.commentary.idents.bed_at_night' => false, 'tv.commentary.idents.applause.enabled' => false, 'tv.commentary.idents.talk_theme.enabled' => false,
        'tv.commentary.spool.quiet_hours.from' => '01:00', 'tv.commentary.spool.quiet_hours.to' => '09:00']);
    $chunks = [studioChunk('markus', 'Ich zähle nur nach, und das macht in Ihrer Partei keiner.'), studioChunk('genossin', 'Sie verwechseln schon wieder Geld mit Gold, Herr Turm!')];

    $stock = studioEpisode($chunks, 1, 2, 'stock:'.TalkShow::ID, evergreen: true, at: '2026-10-13 03:00')[0];
    Cache::flush();
    $live = studioEpisode($chunks, 1, 2, TalkShow::ID, evergreen: false, at: '2026-10-13 03:00')[0];

    expect($stock['steps'])->toContain('bed:bed-news-pulse.opus')
        ->and($live['steps'])->not->toContain('bed:bed-news-pulse.opus');
});
