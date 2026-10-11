<?php

namespace App\Support\Caster;

use App\Support\Lightning\Bolt11;
use App\Support\Nostr\SignedEvent;

/**
 * Whether a zap receipt (NIP-57 kind 9735) is a zap of the live stream, with
 * the same rules the /live chat applies in the browser (parseZap() in
 * resources/js/streamChat.js, fed by App\Support\StreamChat\StreamChat):
 * signed by one of the stream's LNURL servers (a legacy signer only before
 * its cutoff), carrying the stream's `a` and the zap recipient as `p`; its
 * `description` a validly signed kind 9734 for the same address and `p`,
 * whose `lnurl` (when it names one) is the signer's; a positive invoice
 * amount that equals the request's `amount` when it names one.
 *
 * Pure: it reads nothing but its arguments. Anything else is ignored, never
 * repaired; duplicates are dropped by the caller, by receipt id.
 */
final class StreamZaps
{
    /**
     * @param  array<string, string|null>  $lnurls  signer pubkey => the LNURL a request it receipted must name (StreamChat::zapLnurls())
     * @param  array<string, int>  $until  legacy signer => the newest receipt time that still counts (StreamChat::zapUntil())
     * @return array{id: string, zapper: string, at: int, sats: int, comment: string}|null
     */
    public static function parse(SignedEvent $receipt, string $address, string $recipient, array $lnurls, array $until = []): ?array
    {
        if ($receipt->kind !== 9735 || ! array_key_exists($receipt->pubkey, $lnurls)
            || ! in_array($address, self::values($receipt, 'a'), true) || ! in_array($recipient, self::values($receipt, 'p'), true)) {
            return null;
        }

        if (isset($until[$receipt->pubkey]) && $receipt->createdAt >= $until[$receipt->pubkey]) {
            return null;
        }

        $description = $receipt->tag('description');
        $request = $description === null ? null : SignedEvent::fromInput(json_decode($description, true));

        if ($request === null || $request->kind !== 9734
            || ! in_array($address, self::values($request, 'a'), true) || ! in_array($recipient, self::values($request, 'p'), true)) {
            return null;
        }

        $asked = $request->tag('lnurl');
        $signersLnurl = $lnurls[$receipt->pubkey];

        if ($asked !== null && ($signersLnurl === null || strtolower($asked) !== strtolower($signersLnurl))) {
            return null;
        }

        if (! $request->hasValidSignature()) {
            return null;
        }

        $invoice = Bolt11::decode((string) $receipt->tag('bolt11'));
        $amount = $request->tag('amount');

        if ($invoice === null || $invoice->amountMsats === null || $invoice->amountMsats <= 0
            || ($amount !== null && trim($amount) !== (string) $invoice->amountMsats)) {
            return null;
        }

        return [
            'id' => $receipt->id,
            'zapper' => $request->pubkey,
            'at' => $receipt->createdAt,
            'sats' => intdiv($invoice->amountMsats, 1000),
            'comment' => mb_substr($request->content, 0, 1000),
        ];
    }

    /**
     * Zaps grouped into bundles: a bundle starts at its first zap and takes
     * every later one within `$seconds` (oldest first).
     *
     * @param  list<array{id: string, zapper: string, at: int, sats: int, comment: string}>  $zaps
     * @return list<list<array{id: string, zapper: string, at: int, sats: int, comment: string}>>
     */
    public static function bundles(array $zaps, int $seconds): array
    {
        usort($zaps, fn (array $a, array $b): int => [$a['at'], $a['id']] <=> [$b['at'], $b['id']]);
        $bundles = [];
        $start = null;

        foreach ($zaps as $zap) {
            if ($start === null || $seconds <= $zap['at'] - $start) {
                $bundles[] = [];
                $start = $zap['at'];
            }

            $bundles[array_key_last($bundles)][] = $zap;
        }

        return $bundles;
    }

    /**
     * @return list<string> the first values of the tags named `$name`
     */
    private static function values(SignedEvent $event, string $name): array
    {
        return array_values(array_filter(array_map(fn (array $tag): ?string => $tag[0] ?? null, $event->tagsNamed($name)), 'is_string'));
    }
}
