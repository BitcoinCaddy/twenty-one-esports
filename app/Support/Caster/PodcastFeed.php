<?php

namespace App\Support\Caster;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Throwable;

/**
 * The latest episodes of the EINUNDZWANZIG podcast as data for the chat
 * replies (user 2026-10-12: "Was lief so im letzten Podcast?" should get a
 * real answer): title, the day it came out and the shownotes as plain text
 * (no markup, no links, no sponsor block, at most `podcast_chars`).
 *
 * A cached fetch, no scheduler: the feed (`tv.commentary.chat.podcast_feed`)
 * is read at most once per `podcast_minutes`, with a connect and a total
 * timeout and at most `podcast_max_bytes`. A failed read keeps the last good
 * copy and is retried after ten minutes; with no copy at all the reply simply
 * goes without the block. The cache holds ints and strings only (prod's
 * Redis store refuses objects).
 */
class PodcastFeed
{
    private const EPISODES = 'caster:podcast:episodes';

    private const FRESH = 'caster:podcast:fresh';

    /** How long the last good copy outlives a feed that keeps failing. */
    private const KEEP_SECONDS = 7 * 86_400;

    private const RETRY_SECONDS = 600;

    /** German month abbreviations, so Pronunciation::dates() speaks the day ("am elften Oktober 2026"). */
    private const MONTHS = [1 => 'Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

    /**
     * The latest episodes, newest first (cached; see the class comment).
     *
     * @return list<array{title: string, published: int, notes: string}>
     */
    public function episodes(): array
    {
        $settings = (array) config('tv.commentary.chat', []);
        $url = $settings['podcast_feed'] ?? null;

        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            return [];
        }

        // One reader per period, even with several chat polls at once.
        if (Cache::add(self::FRESH, true, max(1, (int) ($settings['podcast_minutes'] ?? 60)) * 60)) {
            $episodes = $this->download($url, $settings);

            if ($episodes === null) {
                Cache::put(self::FRESH, true, self::RETRY_SECONDS);
            } else {
                Cache::put(self::EPISODES, $episodes, self::KEEP_SECONDS);
            }
        }

        $stored = Cache::get(self::EPISODES);

        return is_array($stored) ? array_values(array_filter($stored, fn (mixed $episode): bool => is_array($episode)
            && is_string($episode['title'] ?? null) && is_int($episode['published'] ?? null) && is_string($episode['notes'] ?? null))) : [];
    }

    /** The episodes as one data block for the reply prompt, or null when there are none. */
    public function block(): ?string
    {
        $lines = array_map(fn (array $episode): string => '- „'.$episode['title'].'“'
            .($episode['published'] > 0 ? ', erschienen '.self::spokenDay($episode['published']) : '')
            .($episode['notes'] === '' ? '' : ': '.$episode['notes']), $this->episodes());

        return $lines === [] ? null : implode("\n", $lines);
    }

    /** A publication day as the voice says it: "am elften Oktober 2026" (Berlin time). */
    public static function spokenDay(int $timestamp): string
    {
        $day = CarbonImmutable::createFromTimestamp($timestamp)->setTimezone('Europe/Berlin');

        return Pronunciation::dates('am '.$day->day.'. '.self::MONTHS[$day->month].' '.$day->year);
    }

    /**
     * The first `$limit` items of an RSS 2.0 podcast feed, in feed order; an
     * unreadable document gives none. No network and no entity expansion
     * while parsing.
     *
     * @return list<array{title: string, published: int, notes: string}>
     */
    public static function parse(string $xml, int $limit = 3, int $chars = 600): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $document instanceof SimpleXMLElement || ! isset($document->channel->item)) {
            return [];
        }

        $episodes = [];

        foreach ($document->channel->item as $item) {
            $title = self::plain((string) $item->title);

            if ($title === '') {
                continue;
            }

            $itunes = $item->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
            $notes = (string) $item->description !== '' ? (string) $item->description : (string) $itunes->summary;

            try {
                $published = trim((string) $item->pubDate) === '' ? 0 : CarbonImmutable::parse(trim((string) $item->pubDate))->getTimestamp();
            } catch (Throwable) {
                $published = 0;
            }

            $episodes[] = ['title' => mb_substr($title, 0, 160), 'published' => $published, 'notes' => self::shownotes($notes, $chars)];

            if (count($episodes) >= $limit) {
                break;
            }
        }

        return $episodes;
    }

    /**
     * The newest item of an RSS 2.0 podcast feed with an audio enclosure, for
     * the transcript (PodcastTranscriber): its guid (the enclosure URL when it
     * has none), title, publication time, enclosure URL (https only) and
     * episode number (`itunes:episode`, else "#335 - …" or "Folge 123" in the
     * title, else null); null for an unreadable feed or no such item. No network and no
     * entity expansion while parsing.
     *
     * @return array{guid: string, title: string, published_at: int, url: string, episode_number: int|null}|null
     */
    public static function newestAudio(string $xml): ?array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $document instanceof SimpleXMLElement || ! isset($document->channel->item)) {
            return null;
        }

        foreach ($document->channel->item as $item) {
            $url = trim((string) ($item->enclosure['url'] ?? ''));
            $title = self::plain((string) $item->title);

            if ($title === '' || ! str_starts_with($url, 'https://')) {
                continue;
            }

            try {
                $published = trim((string) $item->pubDate) === '' ? 0 : CarbonImmutable::parse(trim((string) $item->pubDate))->getTimestamp();
            } catch (Throwable) {
                $published = 0;
            }

            $itunes = $item->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
            $number = ctype_digit(trim((string) $itunes->episode)) ? (int) trim((string) $itunes->episode)
                : (preg_match('/^#\s*(\d{1,5})\b|\b(?:Folge|Episode|Nr\.?)\s*(\d{1,5})\b/iu', $title, $match) === 1 ? (int) ($match[1] !== '' ? $match[1] : $match[2]) : null);
            $guid = trim((string) $item->guid);

            return ['guid' => mb_substr($guid === '' ? $url : $guid, 0, 255), 'title' => mb_substr($title, 0, 255), 'published_at' => $published, 'url' => $url, 'episode_number' => $number];
        }

        return null;
    }

    /**
     * Shownotes as plain text: list items joined by "; ", everything from the
     * sponsor block on left out, tags, links, nostr references and keys gone,
     * whitespace collapsed, at most `$chars` characters (cut at a word).
     */
    public static function shownotes(string $html, int $chars = 600): string
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // The sponsor and link blocks are discount codes and shop names, nothing a listener asks about.
        $html = (string) preg_replace('/(?:<p>\s*)?(?:Sponsoren und Freunde|Weitere Links)\b.*$/isu', '', $html);
        $html = (string) preg_replace('~</(?:li|p|h\d)>|<br\s*/?>~iu', '; ', $html);
        $text = self::plain(strip_tags($html));
        $text = trim((string) preg_replace(['/\s*;(?:\s*;)*\s*/u', '/^[;\s]+|[;\s]+$/u'], ['; ', ''], $text));

        if (mb_strlen($text) <= $chars) {
            return $text;
        }

        $cut = mb_substr($text, 0, $chars);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space === false ? $cut : mb_substr($cut, 0, $space), ' ;,.-').' …';
    }

    /** One line of plain text: entities decoded, no links, nostr references, keys or control characters. */
    private static function plain(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace([
            '~(?:https?://|nostr:|www\.)\S+~iu',
            '/\b(?:npub|nsec|note|nevent|nprofile|naddr|lnurl|lnbc)1[02-9ac-hj-np-z]{6,}\b/iu',
        ], ' ', $text);

        return trim((string) preg_replace('/[\p{C}\p{Z}\s]+/u', ' ', $text));
    }

    /**
     * The parsed episodes, or null when the feed failed (logged at most once an hour).
     *
     * @param  array<array-key, mixed>  $settings
     * @return list<array{title: string, published: int, notes: string}>|null
     */
    private function download(string $url, array $settings): ?array
    {
        try {
            $response = Http::withUserAgent('TwentyOneTV/1.0 (+https://esports.einundzwanzig.space)')
                ->accept('application/rss+xml, application/xml;q=0.9, */*;q=0.5')
                ->connectTimeout(4)
                ->timeout(max(1, (int) ($settings['podcast_timeout_seconds'] ?? 8)))
                ->get($url);
        } catch (Throwable $exception) {
            $this->logFailure($exception::class);

            return null;
        }

        $body = $response->body();

        if (! $response->successful() || $body === '' || strlen($body) > (int) ($settings['podcast_max_bytes'] ?? 12 * 1024 * 1024)) {
            $this->logFailure('status '.$response->status().', '.strlen($body).' bytes');

            return null;
        }

        $episodes = self::parse($body, max(1, (int) ($settings['podcast_episodes'] ?? 3)), max(100, (int) ($settings['podcast_chars'] ?? 600)));

        if ($episodes === []) {
            $this->logFailure('no episodes');

            return null;
        }

        return $episodes;
    }

    private function logFailure(string $reason): void
    {
        if (Cache::add('caster:podcast:failed-logged', true, 3_600)) {
            Log::warning('Podcast feed unreadable', ['reason' => $reason]);
        }
    }
}
