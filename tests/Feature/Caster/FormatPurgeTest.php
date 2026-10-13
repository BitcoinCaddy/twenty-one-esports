<?php

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\Blocks\DayStock;
use App\Support\Caster\Blocks\FormatPurge;
use App\Support\Caster\Blocks\ReplayArchive;
use App\Support\Caster\Blocks\StockLibrary;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\Programme\Shows;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/*
 * The purge of a format's old stock (plan "TWENTY ONE Radio", P20; user 2026-10-13: "Die Altbestände der Talks dann
 * entfernen"): server spool, stock library, day stock and replay archive, before a cut-off, dry run by default.
 */

beforeEach(function () {
    Storage::fake('local');
    $base = sys_get_temp_dir().'/purge-'.bin2hex(random_bytes(4));
    config([
        'tv.commentary.blocks.stock.library.dir' => $base.'/library',
        'tv.commentary.blocks.stock.dir' => $base.'/day-stock',
        'tv.commentary.blocks.replay.dir' => $base.'/replay',
    ]);
});

/** A script of `$topic`, written at `$at`, with its status. */
function purgeScript(string $topic, CarbonImmutable $at, string $status = CasterScript::CLAIMED): CasterScript
{
    $script = casterScript(CasterScript::RUBRIC);
    $script->forceFill(['topic' => $topic, 'status' => $status, 'created_at' => $at, 'expires_at' => $at->addDay()])->save();

    return $script;
}

/** Fills spool, library, day stock and replay archive with old and new talk shows and an old Chat-Tribunal. */
function purgeFixtures(CarbonImmutable $cut): array
{
    $spool = app(ClipSpool::class);
    $old = $cut->subHour();
    $new = $cut->addMinute();

    $oldTalk = purgeScript(Shows::STAMMTISCH, $old);
    $oldStockTalk = purgeScript('stock:'.Shows::STAMMTISCH, $old, CasterScript::QUEUED);
    $newTalk = purgeScript(Shows::STAMMTISCH, $new);
    $oldTribunal = purgeScript(Shows::TRIBUNAL, $old);
    $expiredTalk = purgeScript(Shows::STAMMTISCH, $old, CasterScript::EXPIRED);
    $talkClip = $spool->store($oldTalk, casterOpus(), 10.0, [10.0], $old);
    $newClip = $spool->store($newTalk, casterOpus(), 10.0, [10.0], $new);
    $tribunalClip = $spool->store($oldTribunal, casterOpus(), 10.0, [10.0], $old);
    // A block led by another script that carries an old talk show inside.
    $lead = purgeScript('liga', $old);
    $inside = purgeScript(Shows::STAMMTISCH, $old);
    $block = $spool->storeBlock([$lead, $inside], casterOpus(), 20.0, [10.0, 20.0], [10.0], 0.0, $old);

    $library = StockLibrary::fromConfig();
    $hashes = [];
    foreach (['old-talk' => [Shows::STAMMTISCH, $old], 'new-talk' => [Shows::STAMMTISCH, $new], 'old-tribunal' => [Shows::TRIBUNAL, $old]] as $key => [$format, $at]) {
        $opus = casterOpus(4000 + count($hashes));
        $hashes[$key] = sha1($opus);
        $library->store($hashes[$key], $opus, ['format' => $format, 'topics' => ['stock:'.$format], 'seconds' => 60.0, 'rendered_at' => $at->getTimestamp(), 'evergreen' => true], $at);
    }

    $block = ['ends' => [5.0], 'boundaries' => [], 'steps' => ['script:1'], 'seconds' => 60.0];
    $dayOld = DayStock::fromConfig()->store(casterOpus(), [['id' => 1, 'kind' => 'rubric', 'topic' => 'stock:'.Shows::STAMMTISCH]], $block, $old);
    $dayNew = DayStock::fromConfig()->store(casterOpus(), [['id' => 2, 'kind' => 'rubric', 'topic' => 'stock:'.Shows::STAMMTISCH]], $block, $new);
    $replayOld = ReplayArchive::fromConfig()->store(casterOpus(), [['id' => 3, 'kind' => 'rubric', 'topic' => 'stock:'.Shows::STAMMTISCH]], $block, $old);
    $replayTribunal = ReplayArchive::fromConfig()->store(casterOpus(), [['id' => 4, 'kind' => 'rubric', 'topic' => 'stock:'.Shows::TRIBUNAL]], $block, $old->subSecond());

    return compact('oldTalk', 'oldStockTalk', 'newTalk', 'oldTribunal', 'expiredTalk', 'talkClip', 'newClip', 'tribunalClip', 'lead', 'inside', 'hashes', 'dayOld', 'dayNew', 'replayOld', 'replayTribunal')
        + ['blockClip' => CasterClip::query()->where('caster_script_id', $lead->id)->first()];
}

it('finds the old episodes of a format everywhere and nothing else: not newer ones, not another format, not expired scripts', function () {
    $cut = CarbonImmutable::parse('2026-10-13 05:00');
    $f = purgeFixtures($cut);

    $found = FormatPurge::fromConfig(app(ClipSpool::class))->find(Shows::STAMMTISCH, $cut);

    expect($found['scripts'])->toBe([$f['oldTalk']->id, $f['oldStockTalk']->id, $f['inside']->id])
        ->and($found['clips'])->toBe([$f['talkClip']->id, $f['blockClip']->id])
        ->and($found['library'])->toBe([$f['hashes']['old-talk']])
        ->and($found['day_stock'])->toBe([$f['dayOld']])
        ->and($found['replays'])->toBe([$f['replayOld']]);
});

it('lists only on a dry run, removes with --force, and finds nothing the second time', function () {
    $cut = CarbonImmutable::parse('2026-10-13 05:00');
    $f = purgeFixtures($cut);
    $library = StockLibrary::fromConfig();

    $this->artisan('caster:purge-format', ['format' => Shows::STAMMTISCH, '--before' => $cut->toIso8601String()])
        // One line each expectation: the summary, then what it lists.
        ->expectsOutputToContain('Would remove (dry run, --force removes) stammtisch before 2026-10-13T05:00:00+00:00: 3 scripts, 2 ready clips, 1 stock library blocks, 1 day stock blocks, 1 archived replays.')
        ->expectsOutputToContain('day_stock: '.$f['dayOld'])
        ->assertSuccessful();

    expect(CasterClip::query()->count())->toBe(4)
        ->and($f['oldTalk']->fresh()->status)->toBe(CasterScript::VOICED)
        ->and(array_keys($library->entries()))->toHaveCount(3)
        ->and(DayStock::fromConfig()->entries())->toHaveCount(2)
        ->and(ReplayArchive::fromConfig()->entries())->toHaveCount(2);

    $this->artisan('caster:purge-format', ['format' => Shows::STAMMTISCH, '--before' => $cut->toIso8601String(), '--force' => true])
        ->expectsOutputToContain('Removed stammtisch')
        ->assertSuccessful();

    expect(CasterClip::query()->pluck('id')->sort()->values()->all())->toBe([$f['newClip']->id, $f['tribunalClip']->id])
        ->and($f['oldTalk']->fresh()->status)->toBe(CasterScript::EXPIRED)
        ->and($f['oldStockTalk']->fresh()->status)->toBe(CasterScript::EXPIRED)
        ->and($f['newTalk']->fresh()->status)->toBe(CasterScript::VOICED)
        ->and($f['oldTribunal']->fresh()->status)->toBe(CasterScript::VOICED)
        ->and(Storage::disk('local')->exists($f['talkClip']->path))->toBeFalse()
        ->and(Storage::disk('local')->exists($f['newClip']->path))->toBeTrue()
        ->and(array_keys($library->entries()))->toBe([$f['hashes']['new-talk'], $f['hashes']['old-tribunal']])
        ->and(is_file($library->file($f['hashes']['old-talk'])))->toBeFalse()
        ->and(array_keys(DayStock::fromConfig()->entries()))->toBe([$f['dayNew']])
        ->and(array_keys(ReplayArchive::fromConfig()->entries()))->toBe([$f['replayTribunal']]);

    $this->artisan('caster:purge-format', ['format' => Shows::STAMMTISCH, '--before' => $cut->toIso8601String()])
        ->expectsOutputToContain('0 scripts, 0 ready clips, 0 stock library blocks, 0 day stock blocks, 0 archived replays')
        ->assertSuccessful();
});

it('refuses an unknown format or a cut-off that is neither a time nor a commit', function () {
    $this->artisan('caster:purge-format', ['format' => 'quatsch'])->expectsOutputToContain('Unknown format')->assertFailed();
    $this->artisan('caster:purge-format', ['format' => Shows::STAMMTISCH, '--before' => 'deadbeefdeadbeef'])->expectsOutputToContain('neither a time nor a commit')->assertFailed();
});
