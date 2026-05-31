<?php
declare(strict_types=1);

namespace Slash\Booking\Support;

/**
 * Pure, framework-free client-IP utilities.
 *
 * NOTE: REMOTE_ADDR is intentionally the ONLY source. Forwarded headers
 * (X-Forwarded-For etc.) are attacker-controlled and are not trusted here.
 */
final class ClientIp
{
    /**
     * @param array<string, mixed> $server Typically $_SERVER.
     */
    public static function fromServer(array $server): string
    {
        $raw = isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : '';
        return trim($raw);
    }

    /**
     * Returns a stable bucket key for rate limiting.
     *
     * IPv4: the full address.
     * IPv6: the /64 network prefix (so rotating the low 64 bits — trivial for
     *       an attacker who controls a /64 — cannot mint fresh buckets).
     * Invalid/empty: '' (caller must treat as "no usable IP").
     */
    public static function normalizeForKey(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return '';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $ip;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return '';
        }

        $bin = inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return '';
        }
        // Keep the high 64 bits, zero the low 64 bits.
        $prefixBin = substr($bin, 0, 8) . str_repeat("\0", 8);
        $prefix = inet_ntop($prefixBin);
        if ($prefix === false) {
            return '';
        }
        return $prefix . '/64';
    }
}
