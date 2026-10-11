<?php

use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Voice\BotVoice;
use App\Support\Voice\VoiceUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The posts in the league voice, against the real model (P2)
|--------------------------------------------------------------------------
|
| Costs money: runs only with `--evals` and a key, five German chat moments
| and five English profile moments through BotVoice::compose(), each with at
| most two Haiku calls. Prints every post (voice or template) and the usage
| (STDERR), so the run documents its own output and cost.
|
*/

beforeEach(function () {
    if (($_SERVER['PEST_EVALS'] ?? getenv('PEST_EVALS')) !== '1') {
        $this->markTestSkipped('Eval skipped. Run with [--evals] to evaluate against a real model.');
    }

    if (blank(config('ai.providers.anthropic.key'))) {
        $this->markTestSkipped('No ANTHROPIC_API_KEY.');
    }

    // The spam gate compares with posts that went out; these moments are separate posts of one evening, none went out.
    config(['esports.voice.spam.enabled' => false]);
});

function evalPlayer(string $name, string $hex): string
{
    User::factory()->create(['name' => $name, 'pubkey' => str_repeat($hex, 64)]);

    return 'nostr:'.NostrKeys::hexToNpub(str_repeat($hex, 64));
}

/**
 * @param  list<array{0: string, 1: string, 2: array<string, string|int|null>, 3?: list<string>}>  $moments
 */
function evalPosts(string $function, array $moments): int
{
    $voice = app(BotVoice::class);
    $voiced = 0;

    foreach ($moments as $moment) {
        [$template, $label, $values] = $moment;
        $tags = [];
        foreach ($values as $value) {
            if (is_string($value) && preg_match_all('~nostr:(npub1[02-9ac-hj-np-z]+)~', $value, $found) > 0) {
                foreach ($found[1] as $npub) {
                    $tags[] = ['p', NostrKeys::toHex($npub)];
                }
            }
        }

        $post = $voice->compose($function, $template, 0, $values, $tags, CarbonImmutable::now(), ['must_contain' => $moment[3] ?? []]);
        $voiced += $post['voiced'] ? 1 : 0;
        fwrite(STDERR, sprintf("[%s] %s (%s)%s\n%s\n\n", $function, $label, $post['voiced'] ? 'voice' : 'TEMPLATE', $post['canon'] === null ? '' : ' canon='.$post['canon'], $post['content']));
    }

    return $voiced;
}

it('writes five German chat posts and five English profile notes from real moments', function () {
    config([
        'esports.voice.functions.chat_rotation' => true,
        'esports.voice.functions.note_tournament' => true,
        'esports.voice.functions.note_champion' => true,
        'esports.voice.functions.note_pride' => true,
        'esports.voice.functions.note_weekly' => true,
        'esports.voice.functions.note_free_places' => true,
        'esports.voice.channels.stream_chat.enabled' => true,
        'esports.voice.channels.profile_note.enabled' => true,
    ]);
    $block = evalPlayer('BlockBauer', 'a');
    $kurs = evalPlayer('Kurswaechter', 'b');
    $ada = evalPlayer('Ada', 'c');
    $url = 'https://esports.einundzwanzig.space';

    $chat = evalPosts('chat_rotation', [
        ['tournament_signup', 'sign-up open', ['name' => 'Friday Blitz Cup', 'game' => 'Chess Blitz 5+3', 'starts' => 'Fr, 16. Okt 2026, 20:00 MESZ', 'spots' => '5 von 16 Plätzen belegt', 'pot' => ' · 💰 21.000 Sats im Topf', 'url' => $url.'/tournaments/41']],
        ['tournament_winner', 'tournament won', ['winner' => $block, 'name' => 'Testnet Cup', 'url' => $url.'/tournaments/38']],
        ['live_game', 'live board', ['white' => $block, 'black' => $kurs, 'mode' => 'Blitz 5+3', 'url' => $url.'/games/912']],
        ['tmnf_top', 'TMNF best time', ['name' => 'TMNF Woche 42, 2026', 'player' => $ada, 'time' => '0:25.100', 'track' => 'A01-Race', 'gap' => ', 0,300 s schneller', 'url' => $url.'/tournaments/40']],
        ['stats', 'league numbers', ['players' => '214', 'clans' => '12', 'games' => '1.873', 'url' => $url]],
    ]);

    $profile = [
        'tournament' => evalPosts('note_tournament', [['tournament_note_open', 'tournament open', ['name' => 'Friday Blitz Cup', 'game' => 'Chess Blitz 5+3', 'starts' => 'Fri, 16 Oct 2026, 8:00 PM CEST', 'pot' => '21,000', 'url' => $url.'/tournaments/41'], ['starts']]]),
        'champion' => evalPosts('note_champion', [['champion_note', 'champion', ['winner' => $block, 'name' => 'Testnet Cup', 'game' => 'Chess Blitz 5+3', 'prize' => null, 'url' => $url.'/tournaments/38']]]),
        'pride' => evalPosts('note_pride', [['pride_note_win', 'win', ['winner' => $kurs, 'loser' => 'BlockBauer', 'mode' => 'rapid chess', 'elo' => '+18 casual Elo', 'tournament' => '', 'url' => $url.'/games/915']]]),
        'weekly' => evalPosts('note_weekly', [['blockfill_note_top', 'Blockfill first place', ['name' => 'Blockfill Week 42, 2026', 'player' => $ada, 'time' => '0:50.000', 'gap' => ', 1.666 s faster than the first place before', 'ends' => 'Mon, 19 Oct 2026, 12:00 AM CEST', 'url' => $url.'/blockfill']]]),
        'places' => evalPosts('note_free_places', [['tournament_note_places', 'free places', ['free' => 3, 'places' => 8, 'name' => 'Friday Blitz Cup', 'game' => 'Chess Blitz 5+3', 'starts' => 'Fri, 16 Oct 2026, 8:00 PM CEST', 'left' => '2 days', 'url' => $url.'/tournaments/41']]]),
    ];

    $usage = app(VoiceUsage::class);
    $today = $usage->day();
    fwrite(STDERR, '[voice] usage '.json_encode($usage->totals($today, $today))."\n");
    fwrite(STDERR, '[voice] rejections '.json_encode($usage->rejections($today, $today))."\n");
    fwrite(STDERR, sprintf("[voice] estimated cost \$%.6f, voiced chat %d/5, profile %d/5\n", $usage->monthCost(), $chat, array_sum($profile)));

    expect(DB::table('voice_usage')->sum('attempts'))->toBeGreaterThanOrEqual(10)
        ->and($chat + array_sum($profile))->toBeGreaterThan(0);
});
