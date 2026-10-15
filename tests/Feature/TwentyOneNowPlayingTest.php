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

    // Every Wavlake and PlebRap track (116 + 38) and the house artist.
    expect(count($credits))->toBe(155);

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
    // The first ask comes before the builder fetched the picture, the next after: the kept card shows it then.
    $images->shouldReceive('musicAvatar')->andReturn(null, 'data:image/jpeg;base64,AAAA');
    $playing = new NowPlaying($images, '/nonexistent');

    expect($playing->card('wavlake/Debasement - Contra__v1.m4a')['avatar'])->toBeNull();
    $card = $playing->card('wavlake/Debasement - Contra__v1.m4a');
    expect($card)->toMatchArray(['artist' => 'Contra', 'kind' => 'lud16', 'avatar' => 'data:image/jpeg;base64,AAAA'])
        ->and($card['qr'])->toContain('<svg')
        ->and($playing->card('plebrap/unknown file__v1.m4a'))->toBeNull()
        // No timeline yet: nothing plays, no card.
        ->and($playing->at(microtime(true)))->toBeNull();
});

test('the card stands top right under the viewer badge, moves where a slide holds something there, compact on the tournament TV, and wraps a long name', function () {
    $card = ['artist' => 'DJ Bitcoin', 'avatar' => null, 'qr' => (string) file_get_contents(MusicCredits::qrFile(MusicCredits::HOUSE_LUD16, 'lud16')), 'kind' => 'lud16'];
    $renderer = SceneRenderer::fromConfig();
    $scene = ['voice_caption' => str_repeat('Markus hier, der Cup läuft. ', 6)];
    // An empty slide of our own: the card and the lower third go in before its </svg>.
    $dir = storage_path('framework/testing/now-playing-views');
    File::ensureDirectoryExists($dir);
    File::put($dir.'/blank.blade.php', '<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720"></svg>');
    View::addNamespace('np-test', $dir);

    $svg = $renderer->svg([...$scene, 'now_playing' => $card], 'np-test::blank');
    // Top right under the viewer badge: the lower third below keeps its whole width.
    expect($svg)->toContain('data-unit="now-playing" data-box="816 112 400 96"')
        ->toContain('JETZT LÄUFT')->toContain('DJ Bitcoin')->toContain('Zap den Artist')
        ->toContain('width="1152" height="109"')
        ->and($renderer->svg($scene, 'np-test::blank'))->not->toContain('now-playing')
        ->and(SceneRenderer::nowPlayingAt('stream.rotation.p1-pong'))->toBe([416, 72, 'card'])
        ->and(SceneRenderer::nowPlayingAt('stream.rotation.tv3-standings'))->toBe([1040, 112, 'compact'])
        ->and(SceneRenderer::nowPlayingAt('stream.rotation.a2-gallery'))->toBe([816, 112, 'card']);

    // Where the card stands at the lower third's height (d3, above the footers), the lower third ends beside it.
    $dir = storage_path('framework/testing/now-playing-views');
    File::put($dir.'/d3-invite.blade.php', '<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720"></svg>');
    expect(SceneRenderer::nowPlayingAt('stream.rotation.d3-invite'))->toBe([816, 512, 'card']);

    // A long name wraps to two lines instead of running under the code.
    $long = $renderer->svg(['now_playing' => [...$card, 'artist' => 'Under The Mulga Groove']], 'np-test::blank');
    expect($long)->toContain('>Under The Mulga<')->toContain('>Groove<');
});
