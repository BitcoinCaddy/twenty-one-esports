<?php

use App\Models\WotLevel;
use App\Support\Caster\WebOfTrust;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: the web of trust within a bounded memory
|--------------------------------------------------------------------------
|
| `caster:chat --wot` hit the 512 MB limit on prod (40,430 pubkeys,
| 2026-10-11): it held every level-1 follow list at once. The lists are now
| read in batches and only counted, so the peak stays flat however many
| level-1 accounts there are.
|
*/

/** A relay that answers every kind 3 request with a generated follow list per author, `$follows` keys out of `$pool`. */
function wotGraphReader(array $anchorFollows, int $follows, int $pool): RelayReader
{
    return new class($anchorFollows, $follows, $pool) extends RelayReader
    {
        public int $largestAnswer = 0;

        public function __construct(private array $anchorFollows, private int $follows, private int $pool) {}

        public function fetch(array $filters, ?array $relays = null, array $known = [], int $perAuthor = 1): array
        {
            $events = [];

            foreach ($filters as $filter) {
                if ($filter['kinds'] !== [3]) {
                    continue;
                }

                foreach ($filter['authors'] as $author) {
                    $list = $this->anchorFollows[$author] ?? array_map(
                        fn (int $n): string => hash('sha256', 'pool'.((crc32($author) + $n * 7919) % $this->pool)),
                        range(1, $this->follows),
                    );
                    $events[] = SignedEvent::fromInput(['id' => hash('sha256', 'ev'.$author), 'pubkey' => $author, 'created_at' => 1, 'kind' => 3,
                        'tags' => array_map(fn (string $pubkey): array => ['p', $pubkey, 'wss://relay.example.com/'], $list), 'content' => '', 'sig' => str_repeat('0', 128)]);
                }
            }

            $this->largestAnswer = max($this->largestAnswer, count($events));

            return $events;
        }
    };
}

it('builds a large web of trust in batches, with a flat memory peak and hex pubkeys stored', function () {
    $anchor = hash('sha256', 'anchor');
    $levelOne = array_map(fn (int $n): string => hash('sha256', 'one'.$n), range(1, 3000));
    $reader = wotGraphReader([$anchor => $levelOne], 150, 20_000);
    $wot = new WebOfTrust($reader);

    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();

    $count = $wot->run([$anchor], CarbonImmutable::now());

    $peakMb = (memory_get_peak_usage() - $before) / 1024 / 1024;

    // Holding all 3000 lists (450,000 tags) at once took well over 100 MB here.
    expect($peakMb)->toBeLessThan(48.0)
        ->and($reader->largestAnswer)->toBeLessThanOrEqual(WebOfTrust::LISTS_PER_FETCH)
        ->and($count)->toBeGreaterThan(15_000)
        ->and(WotLevel::query()->count())->toBe($count)
        ->and(WotLevel::query()->where('level', 1)->count())->toBe(3001)
        ->and(WotLevel::query()->where('level', 2)->whereKey(hash('sha256', 'pool1'))->exists())->toBeTrue()
        ->and(WotLevel::query()->whereRaw('length(pubkey) != 64')->exists())->toBeFalse();
});

it('counts level 2 from batches exactly like the whole graph', function () {
    $anchor = hash('sha256', 'anchor');
    $levelOne = array_map(fn (int $n): string => hash('sha256', 'one'.$n), range(1, 250));
    $reader = wotGraphReader([$anchor => $levelOne], 40, 900);

    (new WebOfTrust($reader))->run([$anchor], CarbonImmutable::now());

    $follows = [$anchor => $levelOne];
    foreach ($levelOne as $author) {
        $follows[$author] = array_map(fn (int $n): string => hash('sha256', 'pool'.((crc32($author) + $n * 7919) % 900)), range(1, 40));
    }
    $expected = WebOfTrust::levels([$anchor], $follows, []);
    ksort($expected, SORT_STRING);

    expect(WotLevel::query()->orderBy('pubkey')->pluck('level', 'pubkey')->map(fn ($level): int => (int) $level)->all())->toBe($expected);
});
