<?php

use App\Enums\TournamentFormat;
use App\Models\BotPost;
use App\Models\CasterScript;
use App\Models\NostrEvent;
use App\Models\RelayDelivery;
use App\Models\StreamBotPost;
use App\Models\Tournament;
use App\Models\User;
use App\Support\StreamBot\StreamBotPublisher;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceFactCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
 * The tone review (plan "KI-Stimme der Liga", P7): `voice:preview` puts the
 * template and the voice's text of real moments side by side, through the
 * production path, and counts the yield per function. It publishes, signs,
 * queues and stores nothing but the voice's usage accounting; the switches
 * stay as configured.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-11 12:00:00', 'UTC'));
    $this->json = storage_path('framework/testing/voice-preview-'.bin2hex(random_bytes(4)).'.json');
    $this->mock(StreamBotPublisher::class)->shouldNotReceive('publish');
});

afterEach(function () {
    File::delete($this->json);
});

/** A chess knockout of four, played out: "Friday Blitz Cup", published. */
function voicePreviewTournament(): Tournament
{
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    playOutAsDirector($tournament);
    $tournament->forceFill(['name' => 'Friday Blitz Cup', 'published_at' => now()])->save();

    return $tournament->refresh();
}

/**
 * @return array<string, mixed>
 */
function voicePreviewReport(): array
{
    return json_decode((string) file_get_contents(test()->json), true, flags: JSON_THROW_ON_ERROR);
}

it('shows the templates without asking any model with --dry', function () {
    voicePreviewTournament();
    VoiceAgent::fake();
    VoiceFactCheck::fake();

    $this->artisan('voice:preview', ['--dry' => true, '--json' => $this->json])->assertSuccessful();

    VoiceAgent::assertNeverPrompted();
    VoiceFactCheck::assertNeverPrompted();
    $report = voicePreviewReport();
    $gg = collect($report['moments'])->firstWhere('function', 'chat_gg');

    expect($gg['template'])->toContain('GG!')->toContain('Friday Blitz Cup')
        ->and($gg['ai'])->toBeNull()
        ->and($gg['outcome'])->toBe('dry')
        ->and($report['summary']['cost_usd'])->toEqual(0)
        ->and(DB::table('voice_usage')->count())->toBe(0);
});

it('writes each moment through the voice, counts the yield and publishes, stores and switches on nothing', function () {
    $tournament = voicePreviewTournament();
    $posts = [BotPost::query()->count(), StreamBotPost::query()->count(), CasterScript::query()->count(), NostrEvent::query()->count(), RelayDelivery::query()->count()];
    VoiceAgent::fake([['text' => 'Boah. {@winner} holt sich Friday Blitz Cup!', 'canon_id' => null, 'mood' => 'hype']]);

    $this->artisan('voice:preview', ['--function' => 'chat_gg', '--json' => $this->json])
        ->expectsOutputToContain('Nothing was published, signed, queued or stored besides the voice usage.')
        ->assertSuccessful();

    $report = voicePreviewReport();
    $moment = $report['moments'][0];

    expect($report['summary']['functions'])->toBe(['chat_gg' => ['total' => 1, 'voiced' => 1]])
        ->and($moment['label'])->toBe('tournament '.$tournament->id)
        ->and($moment['outcome'])->toBe('voiced')
        ->and($moment['ai'])->toContain('holt sich Friday Blitz Cup!')
        ->and($moment['template'])->toContain('🏆 GG!')
        ->and($report['refused_writes'])->toBe([])
        ->and($report['queued_jobs'])->toBe(0)
        // Nothing went out or was kept: no post, event or relay delivery rows, the switch as configured, the channel's memory untouched.
        ->and([BotPost::query()->count(), StreamBotPost::query()->count(), CasterScript::query()->count(), NostrEvent::query()->count(), RelayDelivery::query()->count()])->toBe($posts)
        ->and(config('esports.voice.functions.chat_gg'))->toBeFalse()
        ->and(config('esports.voice.channels.gg.enabled'))->toBeFalse()
        ->and(Cache::get('voice:recent:gg:texts'))->toBeNull()
        // The usage accounting is the one thing it writes: the tokens were spent.
        ->and(DB::table('voice_usage')->where('channel', 'gg')->value('accepted'))->toBe(1);
});

it('keeps the template of a text the checks turn down and names their reasons', function () {
    voicePreviewTournament();
    VoiceAgent::fake([
        ['text' => '#GG {@winner} holt sich Friday Blitz Cup!', 'canon_id' => null, 'mood' => 'hype'],
        ['text' => '#GG {@winner} holt sich Friday Blitz Cup!', 'canon_id' => null, 'mood' => 'hype'],
    ]);

    $this->artisan('voice:preview', ['--function' => 'chat_gg', '--json' => $this->json])->assertSuccessful();

    $report = voicePreviewReport();
    $moment = $report['moments'][0];

    expect($report['summary']['functions'])->toBe(['chat_gg' => ['total' => 1, 'voiced' => 0]])
        ->and($moment['outcome'])->toBe('template')
        ->and($moment['ai'])->toBeNull()
        ->and($moment['reasons'])->not->toBeEmpty()
        ->and(array_keys($report['summary']['reasons']))->toBe($moment['reasons']);
});

it('refuses a database write on the voice path, keeps nothing of it and fails the run', function () {
    voicePreviewTournament();
    $users = User::query()->count();
    VoiceAgent::fake(function (): array {
        User::factory()->create();

        return ['text' => 'Boah. {@winner} holt sich Friday Blitz Cup!', 'canon_id' => null, 'mood' => 'hype'];
    });

    $this->artisan('voice:preview', ['--function' => 'chat_gg', '--json' => $this->json])
        ->expectsOutputToContain('Held back: 1 database write(s)')
        ->assertFailed();

    $report = voicePreviewReport();

    expect($report['refused_writes'])->toHaveCount(1)
        ->and($report['refused_writes'][0])->toContain('insert into "users"')
        ->and($report['moments'][0]['outcome'])->toBe('template')
        ->and($report['moments'][0]['reasons'])->toContain('request failed')
        ->and(User::query()->count())->toBe($users);
});

it('turns an unknown function down', function () {
    $this->artisan('voice:preview', ['--function' => 'nope', '--json' => $this->json])->assertExitCode(2);

    expect(File::exists($this->json))->toBeFalse();
});
