<?php

namespace App\Support\Caster;

use App\Models\CasterNews;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Throwable;

/**
 * Reads the headlines of the news segment (`caster:news`, every 15 minutes)
 * from the public RSS and Atom feeds in `tv.commentary.news.feeds`: one GET
 * per feed with a connect and a total timeout, a conditional request with
 * the last ETag or Last-Modified (cached), at most `max_feed_bytes` read.
 * A feed that fails is skipped (logged at most once an hour per feed); the
 * others go on.
 *
 * Headlines are stored once (by their normalised wording, across feeds);
 * one NewsFilter turns down is stored as used and skipped, so it never comes
 * up again. Bounded: rows older than `keep_hours` are deleted, and beyond
 * `max_rows` the oldest go.
 */
class NewsFeeds
{
    /** @return array{feeds: int, failed: int, stored: int, filtered: int, pruned: int} */
    public function fetch(CarbonImmutable $now): array
    {
        $settings = (array) config('tv.commentary.news', []);
        $counts = ['feeds' => 0, 'failed' => 0, 'stored' => 0, 'filtered' => 0, 'pruned' => 0];

        foreach ((array) ($settings['feeds'] ?? []) as $key => $feed) {
            if (! is_array($feed) || ! is_string($feed['url'] ?? null)) {
                continue;
            }

            $counts['feeds']++;
            $xml = $this->download((string) $key, $feed['url'], $settings);

            if ($xml === null) {
                $counts['failed']++;

                continue;
            }

            if ($xml === '') {
                // 304: nothing new since the last run.
                continue;
            }

            $items = array_slice(self::parse($xml), 0, max(1, (int) ($settings['per_feed'] ?? 20)));

            foreach ($items as $item) {
                $stored = $this->store((string) $key, $feed, $item, $now, $settings);
                $counts['stored'] += $stored === 'stored' ? 1 : 0;
                $counts['filtered'] += $stored === 'filtered' ? 1 : 0;
            }
        }

        $counts['pruned'] = $this->prune($now);

        return $counts;
    }

    /**
     * The items of an RSS 2.0, RSS 1.0 (RDF) or Atom document, in feed order;
     * an unreadable document gives none. No network and no entity expansion
     * while parsing.
     *
     * @return list<array{title: string, link: string|null, published_at: CarbonImmutable|null, summary: string|null}>
     */
    public static function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $document instanceof SimpleXMLElement) {
            return [];
        }

        $items = [];
        $root = $document->getName();

        if ($root === 'feed') {
            foreach ($document->children('http://www.w3.org/2005/Atom')->entry as $entry) {
                $link = null;
                foreach ($entry->link as $candidate) {
                    // The attributes carry no namespace; read in the Atom namespace they would all be empty.
                    $attributes = $candidate->attributes();
                    $rel = (string) ($attributes['rel'] ?? '');
                    if ($rel === 'alternate' || $rel === '') {
                        $link = (string) ($attributes['href'] ?? '');
                        break;
                    }
                }
                $items[] = ['title' => (string) $entry->title, 'link' => $link, 'published_at' => self::date((string) $entry->published ?: (string) $entry->updated), 'summary' => (string) $entry->summary ?: (string) $entry->content];
            }

            return self::clean($items);
        }

        $rows = $root === 'RDF' ? $document->children('http://purl.org/rss/1.0/')->item : $document->channel->item;

        foreach ($rows ?? [] as $row) {
            $dc = $row->children('http://purl.org/dc/elements/1.1/');
            $items[] = ['title' => (string) $row->title, 'link' => trim((string) $row->link) ?: null, 'published_at' => self::date((string) $row->pubDate ?: (string) $dc->date), 'summary' => (string) $row->description];
        }

        return self::clean($items);
    }

    /**
     * A headline as it is spoken: entities decoded, tags and line breaks gone,
     * typographic quotes kept, a trailing " - Outlet" or " | Outlet" left out.
     */
    public static function normalize(string $title): string
    {
        $title = html_entity_decode(strip_tags(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));

        // Golem marks its paywalled articles "(g+) …" (prod 2026-10-13): the mark is not spoken.
        $title = trim((string) preg_replace('/^\(g\+\)\s*/iu', '', $title));

        return trim((string) preg_replace('/\s+[|–-]\s+(tagesschau\.de|SPIEGEL|WELT|FAZ|n-tv\.de|Handelsblatt)$/iu', '', $title));
    }

    /**
     * The item's teaser text as plain words (tags, entities and "mehr"-links
     * out), at most `news.max_summary_chars` cut at a sentence end where
     * possible; null when empty or only the headline again.
     */
    public static function summary(string $text, string $title = ''): ?string
    {
        $text = html_entity_decode(strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s*(?:\[?(?:mehr|weiterlesen|read more)\]?|…|\.\.\.)\s*$/iu', '', trim((string) preg_replace('/\s+/u', ' ', $text))));
        $max = max(80, (int) config('tv.commentary.news.max_summary_chars', 500));

        if (mb_strlen($text) > $max) {
            $cut = mb_substr($text, 0, $max);
            $end = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, '! '), (int) mb_strrpos($cut, '? '));
            $text = $end > $max / 2 ? mb_substr($cut, 0, $end + 1) : $cut;
        }

        return $text === '' || self::hash($text) === self::hash($title) ? null : $text;
    }

    /** The key two feeds share for the same headline: letters and digits only, lower case. */
    public static function hash(string $title): string
    {
        return hash('sha256', (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($title)));
    }

    /**
     * @param  list<array{title: string, link: string|null, published_at: CarbonImmutable|null, summary?: string}>  $items
     * @return list<array{title: string, link: string|null, published_at: CarbonImmutable|null, summary: string|null}>
     */
    private static function clean(array $items): array
    {
        $clean = [];

        foreach ($items as $item) {
            $title = self::normalize($item['title']);

            if ($title !== '') {
                $clean[] = ['title' => $title, 'link' => $item['link'] !== null && str_starts_with($item['link'], 'http') ? mb_substr($item['link'], 0, 500) : null, 'published_at' => $item['published_at'],
                    'summary' => self::summary($item['summary'] ?? '', $title)];
            }
        }

        return $clean;
    }

    private static function date(string $value): ?CarbonImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value))->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The feed's body, '' when it has not changed (304), null when it failed.
     *
     * @param  array<array-key, mixed>  $settings
     */
    private function download(string $key, string $url, array $settings): ?string
    {
        $validators = Cache::get("caster:news:validators:{$key}");
        $headers = ['Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.5'];

        if (is_array($validators)) {
            if (is_string($validators['etag'] ?? null)) {
                $headers['If-None-Match'] = $validators['etag'];
            }
            if (is_string($validators['modified'] ?? null)) {
                $headers['If-Modified-Since'] = $validators['modified'];
            }
        }

        try {
            $response = Http::withUserAgent('TwentyOneTV/1.0 (+https://esports.einundzwanzig.space)')
                ->withHeaders($headers)
                ->connectTimeout((int) ($settings['connect_timeout_seconds'] ?? 4))
                ->timeout((int) ($settings['timeout_seconds'] ?? 8))
                ->get($url);
        } catch (Throwable $exception) {
            $this->logFailure($key, $exception::class);

            return null;
        }

        if ($response->status() === 304) {
            return '';
        }

        $body = $response->body();

        if (! $response->successful() || $body === '' || strlen($body) > (int) ($settings['max_feed_bytes'] ?? 2 * 1024 * 1024)) {
            $this->logFailure($key, 'status '.$response->status().', '.strlen($body).' bytes');

            return null;
        }

        Cache::put("caster:news:validators:{$key}", ['etag' => $response->header('ETag') ?: null, 'modified' => $response->header('Last-Modified') ?: null], now()->addDay());

        return $body;
    }

    /**
     * @param  array<array-key, mixed>  $feed
     * @param  array{title: string, link: string|null, published_at: CarbonImmutable|null, summary?: string|null}  $item
     * @param  array<array-key, mixed>  $settings
     * @return string stored, filtered, or skipped (known, too long, too old)
     */
    private function store(string $key, array $feed, array $item, CarbonImmutable $now, array $settings): string
    {
        $published = $item['published_at'] ?? $now;

        if ($published->greaterThan($now)) {
            $published = $now;
        }

        $section = in_array($feed['section'] ?? null, CasterNews::SECTIONS, true) ? $feed['section'] : CasterNews::GERMANY;

        if ($published->lessThan($now->subHours(NewsMix::keepHours($key, $section))) || mb_strlen($item['title']) > (int) ($settings['max_title_chars'] ?? 160)) {
            return 'skipped';
        }

        $hash = self::hash($item['title']);

        if (CasterNews::query()->where('hash', $hash)->exists()) {
            return 'skipped';
        }

        $reason = NewsFilter::reason($item['title'], ($feed['language'] ?? 'de') === 'en' ? 'en' : 'de');

        CasterNews::query()->create([
            'hash' => $hash,
            'section' => $section,
            'feed' => mb_substr($key, 0, 32),
            'source' => mb_substr((string) ($feed['source'] ?? $key), 0, 64),
            'language' => ($feed['language'] ?? 'de') === 'en' ? 'en' : 'de',
            'title' => $item['title'],
            'summary' => $item['summary'] ?? null,
            'link' => $item['link'],
            'published_at' => $published,
            'used_at' => $reason === null ? null : $now,
            'skipped' => $reason,
        ]);

        return $reason === null ? 'stored' : 'filtered';
    }

    /**
     * Rows older than `keep_hours` (a feed that stays fresh longer keeps its rows that long, NewsMix::keepHours()),
     * then the oldest beyond `max_rows`; the rows of the long-kept feeds (a few dozen, Blocktrainer writes one a day)
     * count towards neither, so the general feeds never push them out.
     */
    public function prune(CarbonImmutable $now): int
    {
        $settings = (array) config('tv.commentary.news', []);
        $keepHours = (int) ($settings['keep_hours'] ?? 48);
        $long = [];

        foreach ((array) ($settings['feeds'] ?? []) as $key => $feed) {
            $hours = NewsMix::keepHours((string) $key, is_array($feed) ? (string) ($feed['section'] ?? CasterNews::GERMANY) : CasterNews::GERMANY);

            if ($hours > $keepHours) {
                $long[(string) $key] = $hours;
            }
        }

        $deleted = CasterNews::query()->whereNotIn('feed', array_keys($long))->where('published_at', '<', $now->subHours($keepHours))->delete();

        foreach ($long as $key => $hours) {
            $deleted += CasterNews::query()->where('feed', $key)->where('published_at', '<', $now->subHours($hours))->delete();
        }

        $max = max(1, (int) ($settings['max_rows'] ?? 600));
        $general = CasterNews::query()->whereNotIn('feed', array_keys($long));
        $keep = (clone $general)->orderByDesc('published_at')->orderByDesc('id')->limit($max)->pluck('id');

        if ((clone $general)->count() > $max) {
            $deleted += (clone $general)->whereNotIn('id', $keep)->delete();
        }

        return $deleted;
    }

    private function logFailure(string $key, string $why): void
    {
        if (Cache::add("caster:news:failed-logged:{$key}", true, 3600)) {
            Log::warning('Caster news feed failed', ['feed' => $key, 'why' => $why]);
        }
    }
}
