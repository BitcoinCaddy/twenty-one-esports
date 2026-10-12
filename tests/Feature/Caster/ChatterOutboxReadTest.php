<?php

use App\Support\Nostr\HostResolver;
use App\Support\Nostr\RelayReader;
use Tests\Support\FakeHostResolver;

/*
 * A chatter's NIP-65 write relays are named by the chatter, so reading them
 * (RelayReader::readGuarded(), ChatterCache's outbox step) is untrusted
 * input that makes the server connect: RelayGuard decides where, and the
 * read stops at its deadline and byte budget whatever the relay does.
 * Against tests/Support/hostile-relay.php on a local port, allowed through
 * `esports.wallet.nwc_insecure_relays` as in the wallet tests.
 */

/**
 * @return array{result: list<mixed>|null, seconds: float, peak_growth_mb: float}
 */
function readHostileOutbox(string $mode, float $budget = 2.5): array
{
    $relay = proc_open([PHP_BINARY, base_path('tests/Support/hostile-relay.php'), $mode], [1 => ['pipe', 'w']], $pipes);
    $port = (int) trim((string) fgets($pipes[1]));
    $url = 'ws://127.0.0.1:'.$port;
    config(['esports.wallet.nwc_insecure_relays' => ['127.0.0.1:'.$port]]);

    $peakBefore = memory_get_peak_usage(true);
    $started = microtime(true);
    $result = app(RelayReader::class)->readGuarded([['kinds' => [1], 'authors' => [str_repeat('a', 64)], 'limit' => 5]], [$url], 5, microtime(true) + $budget)[$url];
    $seconds = microtime(true) - $started;
    $growth = (memory_get_peak_usage(true) - $peakBefore) / 1048576;

    proc_terminate($relay);
    proc_close($relay);

    return ['result' => $result, 'seconds' => $seconds, 'peak_growth_mb' => $growth];
}

beforeEach(fn () => config(['esports.relay_timeout_seconds' => 2]));

test('a relay that announces a 1e9-byte frame is a failed read at once, without allocating it', function () {
    $run = readHostileOutbox('huge');

    expect($run['result'])->toBeNull()
        ->and($run['seconds'])->toBeLessThan(2.0)
        ->and($run['peak_growth_mb'])->toBeLessThan(32.0);
});

test('a relay dripping one byte a second ends at the outbox budget', function () {
    config(['esports.relay_timeout_seconds' => 10]);
    $run = readHostileOutbox('drip', 1.5);

    expect($run['result'])->toBeNull()
        ->and($run['seconds'])->toBeGreaterThan(1.0)->toBeLessThan(2.5);
});

test('positive control: an honest relay answering EOSE is a read, not a failure', function () {
    $run = readHostileOutbox('answer');

    expect($run['result'])->toBe([])
        ->and($run['seconds'])->toBeLessThan(2.0);
});

test('a relay the guard refuses is never looked up past the guard nor read', function () {
    $resolver = new FakeHostResolver(['private.test' => ['10.0.0.7'], 'public.test' => ['93.184.215.14']]);
    app()->instance(HostResolver::class, $resolver);
    $relays = ['wss://private.test', 'ws://plain.test', 'wss://127.0.0.1', 'wss://public.test:8443', 'wss://user:pw@public.test'];

    $started = microtime(true);
    $results = app(RelayReader::class)->readGuarded([['kinds' => [1], 'authors' => [str_repeat('a', 64)]]], $relays, 5, microtime(true) + 5);

    expect($results)->toBe(array_fill_keys($relays, null))
        ->and($resolver->lookups)->toBe(['private.test'])
        ->and(microtime(true) - $started)->toBeLessThan(1.0);
});

test('no relay is started once the outbox budget is used up', function () {
    $resolver = new FakeHostResolver;
    app()->instance(HostResolver::class, $resolver);

    $results = app(RelayReader::class)->readGuarded([['kinds' => [1], 'authors' => [str_repeat('a', 64)]]], ['wss://late.test'], 5, microtime(true) - 1);

    expect($results)->toBe(['wss://late.test' => null])
        ->and($resolver->lookups)->toBe([]);
});
