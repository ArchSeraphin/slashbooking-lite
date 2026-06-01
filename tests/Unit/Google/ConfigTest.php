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

    public function test_isPaid_is_true_only_for_a_valid_license(): void
    {
        $GLOBALS['__sb_options'] = ['sb_license_status' => 'valid'];
        self::assertTrue(Config::isPaid());

        foreach (['absent', 'invalid', 'unknown', ''] as $status) {
            $GLOBALS['__sb_options'] = ['sb_license_status' => $status];
            self::assertFalse(Config::isPaid(), "status={$status}");
        }

        // Option missing entirely -> default 'absent' -> not paid.
        $GLOBALS['__sb_options'] = [];
        self::assertFalse(Config::isPaid());
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

if (!function_exists('Slash\Booking\get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['__sb_options'][$name] ?? $default;
    }
}
