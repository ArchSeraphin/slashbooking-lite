<?php
declare(strict_types=1);

namespace Slash\Booking\Http {
    // Namespaced WP-function stubs (resolved before the global ones). Guarded so
    // other Http-namespace tests can rely on the same overrides without clashing.
    if (!function_exists('Slash\Booking\Http\get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            return $GLOBALS['__sb_options'][$name] ?? $default;
        }
        function update_option(string $name, mixed $value, bool $autoload = true): bool
        {
            $GLOBALS['__sb_options'][$name] = $value;
            return true;
        }
        function sanitize_text_field(string $s): string
        {
            return trim($s);
        }
        function rest_url(string $path = ''): string
        {
            return 'https://my-site.test/wp-json/' . ltrim($path, '/');
        }
        function site_url(): string
        {
            return 'https://my-site.test';
        }
    }
}

namespace Slash\Booking\Tests\Unit\Http {

    use PHPUnit\Framework\TestCase;
    use Slash\Booking\Http\AdminGoogleSettingsController;
    use Slash\Booking\Tests\Unit\Support\FakeBrokerClient;
    use WP_REST_Request;

    final class AdminGoogleSettingsControllerTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['__sb_options'] = [];
        }

        public function test_read_reports_no_license_when_unset(): void
        {
            $ctrl = new AdminGoogleSettingsController(new FakeBrokerClient());
            $data = $ctrl->read()->get_data();
            self::assertFalse($data['has_license']);
            self::assertSame('absent', $data['license_status']);
            self::assertArrayNotHasKey('license_key', $data);
            self::assertStringContainsString('oauth/callback', $data['redirect_uri']);
        }

        public function test_write_stores_sanitized_key_and_validates(): void
        {
            $broker = new FakeBrokerClient();
            $broker->licenseResult = ['valid' => true, 'plan' => 'pro', 'expires' => '2027-01-01'];
            $ctrl = new AdminGoogleSettingsController($broker);

            $req = new WP_REST_Request();
            $req->set_param('license_key', '  ABC-123  ');
            $data = $ctrl->write($req)->get_data();

            self::assertSame('ABC-123', $GLOBALS['__sb_options']['sb_license_key']);
            self::assertTrue($data['has_license']);
            self::assertSame('valid', $data['license_status']);
            self::assertSame('pro', $data['plan']);
            self::assertSame('2027-01-01', $data['expires']);
        }

        public function test_write_reports_invalid_license(): void
        {
            $broker = new FakeBrokerClient();
            $broker->licenseResult = ['valid' => false, 'plan' => null, 'expires' => null];
            $ctrl = new AdminGoogleSettingsController($broker);

            $req = new WP_REST_Request();
            $req->set_param('license_key', 'BAD');
            $data = $ctrl->write($req)->get_data();

            self::assertSame('invalid', $data['license_status']);
            self::assertTrue($data['has_license']); // key is stored even if invalid
        }
    }
}
