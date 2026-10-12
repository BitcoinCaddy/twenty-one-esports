<?php

use App\Console\Commands\CasterFetchCommand;
use App\Models\CasterPodcastTranscript;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\PodcastFeed;
use App\Support\Caster\PodcastTranscriber;
use App\Support\Caster\PodcastTranscripts;
use App\Support\Caster\ScenePhase;
use App\Support\Voice\PodcastDigestAgent;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: the latest podcast episode as a transcript and its digest
|--------------------------------------------------------------------------
|
| User 2026-10-12: Markus should be able to say "in der letzten Folge …".
| The workstation transcribes the newest episode with the local Whisper and
| uploads the text (bearer token, size cap, latest episode only); the
| server writes a digest once per episode, and it goes into the cached
| system prompt of every TV call. Feed, server, Whisper, ffmpeg and the
| model are faked; never the network.
|
*/

const PODCAST_TRANSCRIPT_TOKEN = 'podcast-token-0123456789abcdef0123456789abcdef';

const PODCAST_TRANSCRIPT_FEED = 'https://anchor.fm/s/d8d3c38/podcast/rss';

beforeEach(function () {
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.ai' => true,
        'tv.commentary.fetch.token' => PODCAST_TRANSCRIPT_TOKEN,
        'tv.commentary.voicestudio.server' => 'https://tv.test',
        'tv.commentary.podcast.transcribe_url' => 'http://whisper.test/v1/audio/transcriptions',
        'tv.commentary.chat.podcast_feed' => PODCAST_TRANSCRIPT_FEED,
        'tv.commentary.spool.quiet_hours.from' => '01:00',
        'tv.commentary.spool.quiet_hours.to' => '09:00',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00', 'Europe/Berlin'));
});

/** A feed whose newest item is episode 335 with an mp3 enclosure. */
function podcastTranscriptFeed(): string
{
    return '<?xml version="1.0"?><rss xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"><channel>'
        .'<item><title>#335 - 51% Attacke auf Deutschland</title><guid>ep-335</guid><pubDate>Sat, 10 Oct 2026 08:00:00 +0000</pubDate>'
        .'<enclosure url="https://cdn.test/335.mp3" type="audio/mpeg" length="1000"/></item>'
        .'<item><title>#334 - Alt gegen Kölsch</title><guid>ep-334</guid><pubDate>Sat, 03 Oct 2026 08:00:00 +0000</pubDate>'
        .'<enclosure url="https://cdn.test/334.mp3" type="audio/mpeg" length="1000"/></item>'
        .'</channel></rss>';
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function podcastTranscriptUpload(array $overrides = []): array
{
    return ['guid' => 'ep-335', 'title' => '#335 - 51% Attacke auf Deutschland', 'episode_number' => 335, 'published_at' => 1_791_100_000, 'transcript' => 'Heute reden wir über die Attacke auf Deutschland.', ...$overrides];
}

it('takes a transcript with the bearer token only', function () {
    $this->postJson(route('caster.podcast-transcript'), podcastTranscriptUpload())->assertStatus(401);
    $this->withToken('wrong-token-0123456789abcdef0123456789abcdef')->postJson(route('caster.podcast-transcript'), podcastTranscriptUpload())->assertStatus(401);
    $this->withToken(PODCAST_TRANSCRIPT_TOKEN)->postJson(route('caster.podcast-status'))->assertOk()->assertExactJson(['guid' => null]);

    $this->withToken(PODCAST_TRANSCRIPT_TOKEN)->postJson(route('caster.podcast-transcript'), podcastTranscriptUpload())->assertStatus(201);

    $this->withToken(PODCAST_TRANSCRIPT_TOKEN)->postJson(route('caster.podcast-status'))->assertExactJson(['guid' => 'ep-335']);
    // Fail closed: no token configured, no access.
    config(['tv.commentary.fetch.token' => null]);
    $this->withToken(PODCAST_TRANSCRIPT_TOKEN)->postJson(route('caster.podcast-status'))->assertStatus(401);
});

it('refuses a transcript over the size cap and a malformed one', function () {
    config(['tv.commentary.podcast.max_bytes' => 2_000]);

    $this->withToken(PODCAST_TRANSCRIPT_TOKEN)->postJson(route('caster.podcast-transcript'), podcastTranscriptUpload(['transcript' => str_repeat('Wort ', 500)]))->assertStatus(413);
    $this->withToken(PODCAST_TRANSCRIPT_TOKEN)->postJson(route('caster.podcast-transcript'), podcastTranscriptUpload(['transcript' => '']))->assertStatus(422);
    $this->withToken(PODCAST_TRANSCRIPT_TOKEN)->postJson(route('caster.podcast-transcript'), podcastTranscriptUpload(['published_at' => 'gestern']))->assertStatus(422);

    expect(CasterPodcastTranscript::query()->count())->toBe(0);
});

it('keeps the latest episode only: a newer one replaces it, an older one is refused, the same one is replaced', function () {
    $post = fn (array $data) => $this->withToken(PODCAST_TRANSCRIPT_TOKEN)->postJson(route('caster.podcast-transcript'), podcastTranscriptUpload($data));

    $post(['guid' => 'ep-334', 'title' => '#334 - Alt gegen Kölsch', 'episode_number' => 334, 'published_at' => 1_790_500_000])->assertStatus(201);
    CasterPodcastTranscript::query()->update(['digest' => 'Themen: Bier']);
    $post([])->assertStatus(201);
    $post(['guid' => 'ep-333', 'published_at' => 1_789_900_000])->assertStatus(409);
    $post(['transcript' => 'Die korrigierte Fassung.'])->assertStatus(201);

    expect(CasterPodcastTranscript::query()->get(['guid', 'transcript', 'digest'])->toArray())->toBe([['guid' => 'ep-335', 'transcript' => 'Die korrigierte Fassung.', 'digest' => null]]);
});

it('writes the digest once per episode and puts it into the cached system prompt, not into the per-call message', function () {
    app(PodcastTranscripts::class)->store(['guid' => 'ep-335', 'title' => '#335 - 51% Attacke auf Deutschland', 'episode_number' => 335, 'published_at' => 1_791_100_000, 'transcript' => 'Heute reden wir über die Attacke auf Deutschland.']);
    PodcastDigestAgent::fake(["Themen:\n- **Die 51%-Attacke** auf Deutschland\nRunning Gags:\n- Alt gegen Kölsch #bier"]);

    expect(app(PodcastTranscripts::class)->ensureDigest())->toBeTrue()
        ->and(app(PodcastTranscripts::class)->ensureDigest())->toBeFalse();
    // Not again after the retry window either: the episode has its digest.
    $this->travel(31)->minutes();
    expect(app(PodcastTranscripts::class)->ensureDigest())->toBeFalse();
    PodcastDigestAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, "<<<transkript\nHeute reden wir über die Attacke auf Deutschland.\ntranskript>>>"));
    PodcastDigestAgent::assertPromptedTimes(1);

    $voice = app(CasterVoice::class);
    $block = app(PodcastTranscripts::class)->digestBlock();
    expect($block)->toContain('Folge 335, „51% Attacke auf Deutschland“')
        ->and($block)->toContain('- Die 51%-Attacke auf Deutschland')
        ->and($block)->not->toContain('#')
        ->and($block)->not->toContain('**')
        ->and($voice->fixedPart())->toEndWith($block."\n")
        ->and($voice->variablePart('scene', ScenePhase::PRE_SHOW, ['players' => 3], 2, 8, [], [], [], [], material: CasterVoice::LEAGUE))->not->toContain('Letzte Podcast-Folge');

    VoiceAgent::fake([['text' => "Ada, in der letzten Folge ging es um die Attacke auf Deutschland, und das war wild.\nIch bleib dabei, dass Bitcoin am Ende gewinnt, egal wie laut der Staat schreit.", 'canon_id' => null, 'mood' => 'call']]);
    $voice->chatReply('Worum ging es in der letzten Folge?', 'Ada');
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->agent->instructions(), 'Running Gags:') && ! str_contains($prompt->prompt, 'Running Gags:'));
});

it('reads the newest episode with audio, its guid and its number from the feed', function () {
    expect(PodcastFeed::newestAudio(podcastTranscriptFeed()))->toBe(['guid' => 'ep-335', 'title' => '#335 - 51% Attacke auf Deutschland', 'published_at' => CarbonImmutable::parse('2026-10-10 08:00:00 UTC')->getTimestamp(), 'url' => 'https://cdn.test/335.mp3', 'episode_number' => 335])
        ->and(PodcastFeed::newestAudio('<rss><channel><item><title>Ohne Audio</title></item></channel></rss>'))->toBeNull();
});

it('skips an episode the server has, without downloading anything', function () {
    Http::fake([
        PODCAST_TRANSCRIPT_FEED => Http::response(podcastTranscriptFeed()),
        'tv.test/api/caster/podcast-transcript/status' => Http::response(['guid' => 'ep-335']),
        '*' => Http::response('unexpected', 500),
    ]);
    Process::fake();

    expect((new PodcastTranscriber)->run(CarbonImmutable::now()))->toBe(PodcastTranscriber::DONE);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'cdn.test') || str_contains($request->url(), 'whisper.test'));
    Process::assertNothingRan();
});

it('does nothing in the quiet hours', function () {
    Http::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-13 04:00', 'Europe/Berlin'));

    expect((new PodcastTranscriber)->run(CarbonImmutable::now()))->toBe(PodcastTranscriber::QUIET);
    Http::assertNothingSent();
});

it('transcribes a new episode in pieces, uploads the text and leaves no audio behind', function () {
    $dirs = [];
    Http::fake([
        PODCAST_TRANSCRIPT_FEED => Http::response(podcastTranscriptFeed()),
        'tv.test/api/caster/podcast-transcript/status' => Http::response(['guid' => 'ep-334']),
        'cdn.test/335.mp3' => Http::response('ID3 fake mp3 bytes'),
        'whisper.test/*' => Http::sequence()->push(['text' => ' Erster Teil der Folge. '])->push(['text' => 'Zweiter Teil der Folge.']),
        'tv.test/api/caster/podcast-transcript' => Http::response(['stored' => 'ep-335'], 201),
    ]);
    // ffmpeg cuts the download into two pieces next to it.
    Process::fake(function (PendingProcess $process) use (&$dirs) {
        $command = (array) $process->command;
        $pattern = (string) end($command);
        $dirs[] = dirname($pattern);
        file_put_contents(sprintf($pattern, 0), 'flac-0');
        file_put_contents(sprintf($pattern, 1), 'flac-1');

        return Process::result();
    });

    $transcriber = new PodcastTranscriber;

    expect($transcriber->run(CarbonImmutable::now()))->toBe(PodcastTranscriber::TRANSCRIBED)
        ->and($transcriber->bytes)->toBe(strlen("Erster Teil der Folge.\nZweiter Teil der Folge."))
        ->and($dirs)->toHaveCount(1)
        ->and(is_dir($dirs[0]))->toBeFalse();
    Process::assertRan(fn (PendingProcess $process): bool => ((array) $process->command)[0] === 'nice' && in_array('-segment_time', (array) $process->command, true));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tv.test/api/caster/podcast-transcript'
        && $request->hasHeader('Authorization', 'Bearer '.PODCAST_TRANSCRIPT_TOKEN)
        && $request->data() === ['guid' => 'ep-335', 'title' => '#335 - 51% Attacke auf Deutschland', 'episode_number' => 335, 'published_at' => CarbonImmutable::parse('2026-10-10 08:00:00 UTC')->getTimestamp(), 'transcript' => "Erster Teil der Folge.\nZweiter Teil der Folge."]);
});

it('reports Whisper down as unavailable and still deletes the audio', function () {
    $dirs = [];
    Http::fake(function (Request $request) {
        return match (true) {
            str_contains($request->url(), 'anchor.fm') => Http::response(podcastTranscriptFeed()),
            str_contains($request->url(), '/status') => Http::response(['guid' => null]),
            str_contains($request->url(), 'cdn.test') => Http::response('ID3 fake mp3 bytes'),
            default => throw new ConnectionException('Whisper is down'),
        };
    });
    Process::fake(function (PendingProcess $process) use (&$dirs) {
        $command = (array) $process->command;
        $pattern = (string) end($command);
        $dirs[] = dirname($pattern);
        file_put_contents(sprintf($pattern, 0), 'flac-0');

        return Process::result();
    });

    expect((new PodcastTranscriber)->run(CarbonImmutable::now()))->toBe(PodcastTranscriber::UNAVAILABLE)
        ->and(is_dir($dirs[0]))->toBeFalse();
});

it('waits until the next day after a failed try, and the fetch loop checks at most once per check_minutes outside the quiet hours', function () {
    Http::fake([PODCAST_TRANSCRIPT_FEED => Http::response('not a feed')]);

    $this->artisan('caster:podcast-transcript')->expectsOutputToContain('no_episode')->assertSuccessful();
    $this->artisan('caster:podcast-transcript')->expectsOutputToContain('Waiting until tomorrow')->assertSuccessful();

    config(['tv.commentary.podcast.transcribe' => true, 'tv.commentary.podcast.check_minutes' => 60]);
    expect(CasterFetchCommand::podcastDue(CarbonImmutable::now(), false))->toBeTrue()
        ->and(CasterFetchCommand::podcastDue(CarbonImmutable::now(), false))->toBeFalse();

    Cache::flush();
    expect(CasterFetchCommand::podcastDue(CarbonImmutable::now(), true))->toBeFalse()
        ->and(CasterFetchCommand::podcastDue(CarbonImmutable::parse('2026-10-13 04:00', 'Europe/Berlin'), false))->toBeFalse();
});
