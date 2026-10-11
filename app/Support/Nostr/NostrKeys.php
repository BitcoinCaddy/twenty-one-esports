<?php

namespace App\Support\Nostr;

use Throwable;

use function BitWasp\Bech32\convertBits;
use function BitWasp\Bech32\decode;
use function BitWasp\Bech32\decodeRaw;
use function BitWasp\Bech32\encode;

/**
 * NIP-19 conversions for public keys, strict on purpose.
 *
 * swentel/nostr-php's Key::convertToHex() ignores the human-readable part and
 * the length, so it happily turns an nsec into a "pubkey". Everything here
 * checks both, and answers null instead of throwing, so a caller cannot
 * forget to fail closed.
 */
final class NostrKeys
{
    public static function isHexPubkey(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    }

    /**
     * Decode an npub to a lowercase 64-character hex pubkey, or null.
     */
    public static function npubToHex(string $npub): ?string
    {
        try {
            [$hrp, $data] = decode(trim($npub));
            $bytes = convertBits($data, count($data), 5, 8, false);
        } catch (Throwable) {
            return null;
        }

        if ($hrp !== 'npub' || count($bytes) !== 32) {
            return null;
        }

        return vsprintf(str_repeat('%02x', 32), $bytes);
    }

    /**
     * Accept either an npub or a 64-character hex pubkey and return hex.
     */
    public static function toHex(string $key): ?string
    {
        $key = trim($key);

        if (self::isHexPubkey(strtolower($key))) {
            return strtolower($key);
        }

        return self::npubToHex($key);
    }

    /**
     * A secret key from `.env`: 64 hex characters or an `nsec`, as lowercase
     * hex, or null. Strict like npubToHex(): an npub is not a secret.
     */
    public static function secretToHex(?string $secret): ?string
    {
        $secret = trim((string) $secret);

        if (preg_match('/^[0-9a-fA-F]{64}$/', $secret) === 1) {
            return strtolower($secret);
        }

        try {
            [$hrp, $data] = decode($secret);
            $bytes = convertBits($data, count($data), 5, 8, false);
        } catch (Throwable) {
            return null;
        }

        if ($hrp !== 'nsec' || count($bytes) !== 32) {
            return null;
        }

        return vsprintf(str_repeat('%02x', 32), $bytes);
    }

    /**
     * NIP-19 `nprofile`: TLV 0 = pubkey (32 bytes, exactly once), 1 = relay
     * hint (UTF-8, any number); unknown types are skipped. The pubkey as
     * lowercase hex and the hints as written, or null for anything else,
     * including a truncated TLV. Read without bech32's 90-character cap: an
     * nprofile with relay hints is longer, and the checksum still holds.
     *
     * @return array{pubkey: string, relays: list<string>}|null
     */
    public static function nprofileDecode(string $nprofile): ?array
    {
        try {
            [$hrp, $data] = decodeRaw(strtolower(trim($nprofile)));
            $bytes = convertBits($data, count($data), 5, 8, false);
        } catch (Throwable) {
            return null;
        }

        if ($hrp !== 'nprofile') {
            return null;
        }

        $binary = pack('C*', ...$bytes);
        $pubkey = null;
        $relays = [];

        for ($offset = 0; $offset < strlen($binary);) {
            if ($offset + 2 > strlen($binary)) {
                return null;
            }

            $type = ord($binary[$offset]);
            $length = ord($binary[$offset + 1]);
            $value = substr($binary, $offset + 2, $length);

            if (strlen($value) !== $length) {
                return null;
            }

            if ($type === 0) {
                if ($pubkey !== null || $length !== 32) {
                    return null;
                }
                $pubkey = bin2hex($value);
            } elseif ($type === 1 && $length > 0 && mb_check_encoding($value, 'UTF-8')) {
                $relays[] = $value;
            }

            $offset += 2 + $length;
        }

        return $pubkey === null ? null : ['pubkey' => $pubkey, 'relays' => $relays];
    }

    /**
     * An npub, nprofile or 64-character hex pubkey as the pubkey (hex) and
     * the relay hints it carries (only an nprofile has any), or null.
     *
     * @return array{pubkey: string, relays: list<string>}|null
     */
    public static function profileReference(string $reference): ?array
    {
        $reference = (string) preg_replace('/^nostr:/i', '', trim($reference));

        if (str_starts_with(strtolower($reference), 'nprofile1')) {
            return self::nprofileDecode($reference);
        }

        $hex = self::toHex($reference);

        return $hex === null ? null : ['pubkey' => $hex, 'relays' => []];
    }

    public static function hexToNpub(string $hex): string
    {
        $bytes = array_values(unpack('C*', (string) hex2bin($hex)) ?: []);

        return encode('npub', convertBits($bytes, count($bytes), 8, 5, true));
    }

    /**
     * NIP-19 `nevent`: TLV 0 = event id (32 bytes), 2 = author (32 bytes),
     * 3 = kind (4 bytes, big-endian). No relay hints.
     */
    public static function nevent(string $id, ?string $author = null, ?int $kind = null): string
    {
        $tlv = chr(0).chr(32).hex2bin($id);

        if ($author !== null) {
            $tlv .= chr(2).chr(32).hex2bin($author);
        }

        if ($kind !== null) {
            $tlv .= chr(3).chr(4).pack('N', $kind);
        }

        $bytes = array_values(unpack('C*', $tlv) ?: []);

        return encode('nevent', convertBits($bytes, count($bytes), 8, 5, true));
    }

    /**
     * An `nevent` as the designs shorten it: `nevent1qqsx7…p64a`.
     */
    public static function shortNevent(string $id, ?string $author = null, ?int $kind = null): string
    {
        $nevent = self::nevent($id, $author, $kind);

        return substr($nevent, 0, 12).'…'.substr($nevent, -4);
    }

    /**
     * NIP-19 `naddr` of an addressable event: TLV 0 = d (UTF-8), 2 = author
     * (32 bytes), 3 = kind (4 bytes, big-endian), and 1 = one relay hint
     * when given (a hint longer than 255 bytes is left out).
     */
    public static function naddr(int $kind, string $pubkey, string $d, ?string $relay = null): string
    {
        $length = strlen($d);

        // One length byte per TLV entry: a longer `d` cannot be encoded.
        if ($length > 255) {
            throw new \InvalidArgumentException('A d tag longer than 255 bytes has no naddr.');
        }

        $hint = $relay !== null && $relay !== '' && strlen($relay) <= 255 ? chr(1).chr(strlen($relay)).$relay : '';

        $tlv = chr(0).chr($length).$d
            .$hint
            .chr(2).chr(32).hex2bin($pubkey)
            .chr(3).chr(4).pack('N', $kind);

        $bytes = array_values(unpack('C*', $tlv) ?: []);

        return encode('naddr', convertBits($bytes, count($bytes), 8, 5, true));
    }
}
