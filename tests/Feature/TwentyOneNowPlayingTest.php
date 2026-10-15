<?php

use App\Support\TwentyOne\Stream\MusicCredits;
use App\Support\TwentyOne\Stream\NowPlaying;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\StreamImages;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;

/*
 * The music on air on every stream slide (user 2026-10-15): the artist, their Nostr avatar and a QR code to tip
 * them; the house artist for the uncredited instrumentals and songs.
 */

afterEach(fn () => MusicCredits::flush());

test('every credited track and the house artist have their QR code drawn, so no card ever goes missing', function () {
    MusicCredits::flush();
    $credits = [...MusicCredits::all(), MusicCredits::house()];

    expect(count($credits))->toBeGreaterThan(80);

    foreach ($credits as $credit) {
        expect(in_array($credit['kind'], MusicCredits::KINDS, true))->toBeTrue($credit['artist'])
            ->and(is_file(MusicCredits::qrFile($credit['target'], $credit['kind'])))->toBeTrue('php artisan twentyone:music-qr is missing a code for '.$credit['artist']);
    }
});

test('a Wavlake track credits its artist with the lightning address, a PlebRap track its first artist, an uncredited instrumental or own song DJ Bitcoin', function () {
    $dir = rtrim((string) config('twentyone.stream.music.dir'), '/');

    expect(MusicCredits::forFile($dir."/wavlake/Can't Say I'm Not Trying - Ainsley Costello__v1.m4a"))->toMatchArray(['artist' => 'Ainsley Costello', 'kind' => 'lud16', 'target' => 'ainsleycostello@getalby.com'])
        ->and(MusicCredits::forFile('instrumental/07 - Something__v1.m4a'))->toMatchArray(['artist' => 'DJ Bitcoin', 'kind' => 'lud16', 'target' => 'theben@getalby.com'])
        ->and(MusicCredits::forFile($dir.'/einundzwanzig-song__v1.m4a'))->toMatchArray(['artist' => 'DJ Bitcoin'])
        // A Wavlake track of an artist nobody found stays without a card, never a guess.
        ->and(MusicCredits::qrPayload('theben@getalby.com', 'lud16'))->toBe('lightning:theben@getalby.com')
        ->and(MusicCredits::qrPayload('https://njump.me/npub1x', 'njump'))->toBe('https://njump.me/npub1x');

    $plebrap = collect(json_decode((string) file_get_contents(resource_path('data/music/plebrap-index.json')), true)['tracks']);
    expect($plebrap)->toHaveCount(38)
        ->and($plebrap->firstWhere('file', 'plebrap/07 - Bank gegen Node - Too Bit to Fail, JustAnotherNode, Hanspanzer__v1.m4a')['artists'])->toBe(['Too Bit to Fail', 'JustAnotherNode', 'Hanspanzer']);
});

test('the card carries the artist, the cached avatar and the drawn code; a track without a credit or without a code has none', function () {
    $images = Mockery::mock(StreamImages::class);
    $images->shouldReceive('musicAvatar')->andReturn('data:image/jpeg;base64,AAAA');
    $playing = new NowPlaying($images, '/nonexistent');

    $card = $playing->card('instrumental/07 - Something__v1.m4a');
    expect($card)->toMatchArray(['artist' => 'DJ Bitcoin', 'kind' => 'lud16', 'avatar' => 'data:image/jpeg;base64,AAAA'])
        ->and($card['qr'])->toContain('<svg')
        ->and($playing->card('plebrap/unknown file__v1.m4a'))->toBeNull()
        // No timeline yet: nothing plays, no card.
        ->and($playing->at(microtime(true)))->toBeNull();
});

test('the card stands over every slide at the right above the footers, beside the shortened lower third, and moves where a slide holds something there', function () {
    $card = ['artist' => 'DJ Bitcoin', 'avatar' => null, 'qr' => (string) file_get_contents(MusicCredits::qrFile(MusicCredits::HOUSE_LUD16, 'lud16')), 'kind' => 'lud16'];
    $renderer = SceneRenderer::fromConfig();
    $scene = ['voice_caption' => str_repeat('Markus hier, der Cup läuft. ', 6)];
    // An empty slide of our own: the card and the lower third go in before its </svg>.
    $dir = storage_path('framework/testing/now-playing-views');
    File::ensureDirectoryExists($dir);
    File::put($dir.'/blank.blade.php', '<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720"></svg>');
    View::addNamespace('np-test', $dir);

    $svg = $renderer->svg([...$scene, 'now_playing' => $card], 'np-test::blank');
    expect($svg)->toContain('data-unit="now-playing" data-box="816 512 400 96"')
        ->toContain('JETZT LÄUFT')->toContain('DJ Bitcoin')->toContain('Zap den Artist')
        ->toContain('width="736" height="109"');

    // Without a card the lower third keeps its whole width; a moved card leaves it whole too.
    expect($renderer->svg($scene, 'np-test::blank'))->toContain('width="1152" height="109"')->not->toContain('now-playing')
        ->and(SceneRenderer::nowPlayingAt('stream.rotation.p1-pong'))->toBe([416, 72])
        ->and(SceneRenderer::nowPlayingAt('stream.rotation.a2-gallery'))->toBe([816, 512]);
});
