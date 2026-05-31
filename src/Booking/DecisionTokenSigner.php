<?php
declare(strict_types=1);

namespace Slash\Booking\Booking;

final class DecisionTokenSigner
{
    private const CONTEXT = 'slashbooking:decision-token:v1';

    private readonly string $key;

    public function __construct(string $secret)
    {
        if (strlen($secret) < 16) {
            throw new \InvalidArgumentException('Decision secret must be at least 16 characters.');
        }
        // Domain separation: derive a context-specific key so the raw root
        // secret is never used directly and is not shared with OAuth state.
        $this->key = hash_hmac('sha256', self::CONTEXT, $secret, true);
    }

    public function sign(string $payload, int $expiresAtUnix): string
    {
        return hash_hmac('sha256', $payload . '|' . $expiresAtUnix, $this->key);
    }

    public function verify(string $payload, int $expiresAtUnix, string $signature): bool
    {
        if ($expiresAtUnix < time()) {
            return false;
        }
        $expected = $this->sign($payload, $expiresAtUnix);
        return hash_equals($expected, $signature);
    }
}
