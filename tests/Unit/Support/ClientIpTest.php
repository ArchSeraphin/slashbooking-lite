<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Support\ClientIp;

final class ClientIpTest extends TestCase
{
    public function test_returns_empty_string_when_remote_addr_absent(): void
    {
        self::assertSame('', ClientIp::fromServer([]));
    }

    public function test_returns_trimmed_ipv4(): void
    {
        self::assertSame('203.0.113.7', ClientIp::fromServer(['REMOTE_ADDR' => ' 203.0.113.7 ']));
    }

    public function test_ipv4_key_is_the_full_address(): void
    {
        self::assertSame('203.0.113.7', ClientIp::normalizeForKey('203.0.113.7'));
    }

    public function test_ipv6_key_collapses_to_64_bit_prefix(): void
    {
        // Two addresses in the same /64 must collapse to the same key.
        $a = ClientIp::normalizeForKey('2001:db8:abcd:0012:0000:0000:0000:0001');
        $b = ClientIp::normalizeForKey('2001:db8:abcd:0012:ffff:ffff:ffff:ffff');
        self::assertSame($a, $b);
        self::assertSame('2001:db8:abcd:12::/64', $a);
    }

    public function test_different_ipv6_64_prefixes_differ(): void
    {
        $a = ClientIp::normalizeForKey('2001:db8:abcd:0012::1');
        $b = ClientIp::normalizeForKey('2001:db8:abcd:0013::1');
        self::assertNotSame($a, $b);
    }

    public function test_invalid_address_returns_empty_key(): void
    {
        self::assertSame('', ClientIp::normalizeForKey('not-an-ip'));
        self::assertSame('', ClientIp::normalizeForKey(''));
    }
}
