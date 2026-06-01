<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Google;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Config;

final class ConfigTest extends TestCase
{
    public function test_default_broker_url(): void
    {
        $GLOBALS['__sb_filter'] = null;
        self::assertSame('https://broker.slashbox.fr', Config::brokerUrl());
    }

    public function test_filter_overrides_broker_url(): void
    {
        $GLOBALS['__sb_filter'] = static fn (string $hook, string $value): string =>
            $hook === 'sb_broker_url' ? 'https://example.test/api' : $value;
        self::assertSame('https://example.test/api', Config::brokerUrl());
    }

    public function test_trailing_slash_is_stripped(): void
    {
        $GLOBALS['__sb_filter'] = static fn (string $hook, string $value): string =>
            'https://example.test/api/';
        self::assertSame('https://example.test/api', Config::brokerUrl());
    }
}

// Stub apply_filters for the unit suite (real WP function unavailable here).
namespace Slash\Booking;

if (!function_exists('Slash\Booking\apply_filters')) {
    function apply_filters(string $hook, mixed $value): mixed
    {
        $cb = $GLOBALS['__sb_filter'] ?? null;
        return $cb ? $cb($hook, $value) : $value;
    }
}
