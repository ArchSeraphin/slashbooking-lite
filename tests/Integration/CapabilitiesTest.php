<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use Slash\Booking\Activator;
use WP_UnitTestCase;

final class CapabilitiesTest extends WP_UnitTestCase
{
    public function test_admin_role_has_booking_caps(): void
    {
        Activator::activate();
        $role = get_role('administrator');
        self::assertNotNull($role);
        self::assertTrue($role->has_cap('slashbooking_manage'));
        self::assertTrue($role->has_cap('slashbooking_view'));
    }

    public function test_editor_loses_caps_after_upgrade_to_admin_only(): void
    {
        // Simulate a site activated under the old layout (editor had caps).
        $editor = get_role('editor');
        self::assertNotNull($editor);
        $editor->add_cap(\Slash\Booking\Admin\Capabilities::MANAGE);
        $editor->add_cap(\Slash\Booking\Admin\Capabilities::VIEW);
        update_option('slashbooking_caps_revision', 2, false);

        \Slash\Booking\Admin\Capabilities::syncOnUpgrade();

        self::assertFalse(get_role('editor')->has_cap(\Slash\Booking\Admin\Capabilities::MANAGE));
        self::assertFalse(get_role('editor')->has_cap(\Slash\Booking\Admin\Capabilities::VIEW));
        self::assertTrue(get_role('administrator')->has_cap(\Slash\Booking\Admin\Capabilities::MANAGE));
    }

    public function test_filter_can_add_a_custom_role(): void
    {
        add_filter('slashbooking_manage_roles', static function (array $roles): array {
            $roles[] = 'shop_manager';
            return $roles;
        });
        // Ensure the custom role exists.
        if (get_role('shop_manager') === null) {
            add_role('shop_manager', 'Shop Manager', []);
        }

        \Slash\Booking\Admin\Capabilities::install();

        self::assertTrue(get_role('shop_manager')->has_cap(\Slash\Booking\Admin\Capabilities::MANAGE));
        remove_all_filters('slashbooking_manage_roles');
    }
}
