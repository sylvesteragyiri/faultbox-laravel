<?php

namespace Faultbox\Laravel\Support;

/**
 * UUID version 7: a millisecond timestamp followed by random bits, so event
 * IDs sort by time. Used as the idempotency key for each event.
 */
final class Uuid
{
    public static function v7(?int $unixMs = null): string
    {
        $ms = $unixMs ?? (int) floor(microtime(true) * 1000);
        $bytes = pack('J', $ms);                 // 8 bytes, big endian
        $bytes = substr($bytes, 2) . random_bytes(10); // 48-bit time + 80 random bits

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70); // version 7
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // RFC 4122 variant

        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
