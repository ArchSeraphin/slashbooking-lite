<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Http\PublicBookingController;

final class PublicBookingRateLimitTest extends TestCase
{
    /** @var array<string, int> */
    private array $store = [];

    protected function setUp(): void
    {
        $this->store = [];
    }

    private function get(string $key): int
    {
        return $this->store[$key] ?? 0;
    }

    private function set(string $key, int $value): void
    {
        $this->store[$key] = $value;
    }

    public function test_fails_closed_when_no_usable_ip(): void
    {
        // Empty IP key => limited (fail-closed), and the global bucket still counts.
        $limited = PublicBookingController::evaluateRateLimit(
            '',
            fn (string $k): int => $this->get($k),
            fn (string $k, int $v) => $this->set($k, $v),
        );
        self::assertTrue($limited);
    }

    public function test_allows_first_five_per_ip_then_blocks(): void
    {
        $ipKey = '203.0.113.7';
        for ($i = 0; $i < 5; $i++) {
            $limited = PublicBookingController::evaluateRateLimit(
                $ipKey,
                fn (string $k): int => $this->get($k),
                fn (string $k, int $v) => $this->set($k, $v),
            );
            self::assertFalse($limited, "request #{$i} should be allowed");
        }
        $sixth = PublicBookingController::evaluateRateLimit(
            $ipKey,
            fn (string $k): int => $this->get($k),
            fn (string $k, int $v) => $this->set($k, $v),
        );
        self::assertTrue($sixth, '6th request from same IP must be blocked');
    }

    public function test_global_cap_blocks_even_with_rotating_ips(): void
    {
        // Each request uses a unique IP key (simulating IP rotation), so the
        // per-IP bucket never fills — but the global bucket must.
        $limitedAtSome = false;
        for ($i = 0; $i < PublicBookingController::GLOBAL_LIMIT_PER_MINUTE + 1; $i++) {
            $limited = PublicBookingController::evaluateRateLimit(
                'rotating-' . $i,
                fn (string $k): int => $this->get($k),
                fn (string $k, int $v) => $this->set($k, $v),
            );
            if ($limited) {
                $limitedAtSome = true;
            }
        }
        self::assertTrue($limitedAtSome, 'global cap must eventually block rotating IPs');
    }
}
