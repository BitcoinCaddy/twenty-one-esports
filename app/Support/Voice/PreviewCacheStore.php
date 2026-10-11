<?php

namespace App\Support\Voice;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;

/**
 * The cache of a voice preview (`voice:preview`): reads fall through to the
 * real cache, writes stay in this process. The voice reads the real state
 * (the channel's recent posts, the spam gate's memory) and may "remember"
 * what it wrote as in production, but nothing it remembers reaches the
 * real cache: the next real post sees exactly what it would have seen
 * without the preview.
 */
final class PreviewCacheStore implements Store
{
    /** @var array<string, mixed> */
    private array $local = [];

    /** @var array<string, true> */
    private array $forgotten = [];

    public function __construct(private readonly Repository $base) {}

    public function get($key): mixed
    {
        if (array_key_exists($key, $this->local)) {
            return $this->local[$key];
        }

        return isset($this->forgotten[$key]) ? null : $this->base->get($key);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    public function put($key, $value, $seconds): bool
    {
        $this->local[$key] = $value;
        unset($this->forgotten[$key]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function putMany(array $values, $seconds): bool
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment($key, $value = 1): int
    {
        $next = (int) $this->get($key) + $value;
        $this->put($key, $next, 0);

        return $next;
    }

    public function decrement($key, $value = 1): int
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value): bool
    {
        return $this->put($key, $value, 0);
    }

    public function touch($key, $seconds): bool
    {
        return $this->get($key) !== null;
    }

    public function forget($key): bool
    {
        unset($this->local[$key]);
        $this->forgotten[$key] = true;

        return true;
    }

    public function flush(): bool
    {
        // A flush would empty the real cache: here it only forgets what this process wrote.
        $this->local = [];

        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }

    /**
     * The keys this process wrote (what the preview kept away from the real cache).
     *
     * @return list<string>
     */
    public function writtenKeys(): array
    {
        return array_keys($this->local);
    }
}
