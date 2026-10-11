<?php

namespace App\Support\Caster;

use App\Models\ChatterLine;
use App\Support\Moderation\SiteModeration;
use Carbon\CarbonImmutable;

/**
 * The rolling chat memory of TWENTY ONE TV (user 2026-10-12): every trusted
 * chat message the caster saw (sanitised by ChatReactions::sanitizeMessage(),
 * with the speakable name) and the first words of Markus' reply, kept
 * `tv.commentary.chat.memory_hours` (default 24). Untrusted chatters are
 * never stored; a chatter muted later drops out of every block at once.
 *
 * A reply prompt gets two blocks as data, never as instructions: the asking
 * person's own thread and the latest lines of everybody else, each newest
 * first and at most `memory_tokens` (characters / 4). Rows hold strings and
 * ints only; the table is pruned by caster:prune and emptied per pubkey by
 * ChatterCache::forget().
 */
class ChatMemory
{
    /** How much of Markus' reply is kept, in words. */
    public const REPLY_WORDS = 25;

    /** Rows read per block at most, before the token budget trims them. */
    private const SCAN = 200;

    /** Stores a trusted chat message, or nothing when it carries no letter. */
    public function remember(string $pubkey, ?string $name, string $message, CarbonImmutable $now): ?ChatterLine
    {
        $message = ChatReactions::sanitizeMessage($message);

        if (preg_match('/\p{L}/u', $message) !== 1) {
            return null;
        }

        return ChatterLine::query()->create([
            'pubkey' => $pubkey,
            'name' => $name === null ? null : mb_substr($name, 0, 64),
            'message' => $message,
            'said_at' => $now->getTimestamp(),
        ]);
    }

    /** Markus' reply to a stored line: its first REPLY_WORDS words, markers left out. */
    public function answer(ChatterLine $line, string $reply): void
    {
        $line->reply = self::firstWords($reply);
        $line->save();
    }

    /** The first `$words` words of a script on one line, without markers. */
    public static function firstWords(string $text, int $words = self::REPLY_WORDS): string
    {
        $plain = trim((string) preg_replace('/\s+/u', ' ', ScriptGuard::withoutMarkers(str_replace("\n", ' ', $text))));
        $parts = $plain === '' ? [] : explode(' ', $plain);
        $kept = implode(' ', array_slice($parts, 0, max(1, $words)));

        return mb_substr(count($parts) > $words ? $kept.' …' : $kept, 0, 300);
    }

    /** The asking person's own lines of the last memory_hours, newest first, or null. */
    public function thread(string $pubkey, CarbonImmutable $now): ?string
    {
        if (SiteModeration::isHidden($pubkey)) {
            return null;
        }

        $lines = ChatterLine::query()->where('pubkey', $pubkey)->where('said_at', '>=', $this->since($now))
            ->orderByDesc('said_at')->orderByDesc('id')->limit(self::SCAN)->get();

        return $this->trimmed($lines->map(fn (ChatterLine $line): string => '- '.self::clock($line->said_at, $now).' schrieb die Person „'.$line->message.'“'
            .($line->reply === null ? '' : ' und du hast geantwortet „'.$line->reply.'“'))->all());
    }

    /** The latest lines of everybody but `$pubkey` in the last memory_hours, newest first, or null. */
    public function others(string $pubkey, CarbonImmutable $now): ?string
    {
        $lines = ChatterLine::query()->where('pubkey', '!=', $pubkey)->where('said_at', '>=', $this->since($now))
            ->orderByDesc('said_at')->orderByDesc('id')->limit(self::SCAN)->get()
            ->reject(fn (ChatterLine $line): bool => SiteModeration::isHidden($line->pubkey));

        return $this->trimmed($lines->map(fn (ChatterLine $line): string => '- '.self::clock($line->said_at, $now).' '.($line->name ?? 'jemand aus dem Chat').': „'.$line->message.'“'
            .($line->reply === null ? '' : ', du hast geantwortet „'.$line->reply.'“'))->values()->all());
    }

    /** @return int lines removed (older than memory_hours) */
    public function prune(CarbonImmutable $now): int
    {
        return ChatterLine::query()->where('said_at', '<', $this->since($now))->delete();
    }

    private function since(CarbonImmutable $now): int
    {
        return $now->subHours(max(1, (int) config('tv.commentary.chat.memory_hours', 24)))->getTimestamp();
    }

    /**
     * The lines from the newest on, as many as fit `memory_tokens` (characters / 4).
     *
     * @param  array<int, string>  $lines  newest first
     */
    private function trimmed(array $lines): ?string
    {
        $budget = max(1, (int) config('tv.commentary.chat.memory_tokens', 1_500)) * 4;
        $kept = [];
        $used = 0;

        foreach ($lines as $line) {
            $used += mb_strlen($line) + 1;

            if ($used > $budget) {
                break;
            }

            $kept[] = $line;
        }

        return $kept === [] ? null : implode("\n", $kept);
    }

    /** The Berlin wall-clock time of a line ("14:05", "gestern 23:10"; the voice says "14 Uhr 5", Pronunciation::dates). */
    private static function clock(int $timestamp, CarbonImmutable $now): string
    {
        $at = CarbonImmutable::createFromTimestamp($timestamp)->setTimezone('Europe/Berlin');

        return ($at->toDateString() === $now->setTimezone('Europe/Berlin')->toDateString() ? 'heute ' : 'gestern ').$at->format('H:i');
    }
}
