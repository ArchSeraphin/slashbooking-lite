<?php
declare(strict_types=1);

namespace Slash\Booking\Admin;

final class Capabilities
{
    public const MANAGE = 'slashbooking_manage';
    public const VIEW   = 'slashbooking_view';

    /**
     * Default role granted full plugin access. Administrator only: managing
     * Google OAuth credentials and viewing all customer PII is an admin task.
     * Operators who delegate booking management can opt extra roles in via the
     * 'slashbooking_manage_roles' filter.
     */
    private const DEFAULT_ROLES = ['administrator'];

    /**
     * Roles that revision <=2 granted but the current layout revokes.
     */
    private const REVOKED_ON_UPGRADE = ['editor'];

    /**
     * Bumped whenever the cap layout changes. {@see syncOnUpgrade()} compares
     * this against the stored revision to decide whether to re-run the migration.
     */
    private const REVISION = 3;
    private const REVISION_OPTION = 'slashbooking_caps_revision';

    /**
     * @return list<string>
     */
    private static function grantedRoles(): array
    {
        /** @var list<string> $roles */
        $roles = apply_filters('slashbooking_manage_roles', self::DEFAULT_ROLES);
        return array_values(array_unique(array_filter($roles, 'is_string')));
    }

    public static function install(): void
    {
        foreach (self::grantedRoles() as $roleName) {
            $role = get_role($roleName);
            if ($role === null) {
                continue;
            }
            $role->add_cap(self::MANAGE);
            $role->add_cap(self::VIEW);
        }
    }

    /**
     * Idempotent migration. When the stored revision is behind {@see self::REVISION},
     * revoke caps from roles dropped by the new layout, then re-grant the current
     * layout. Designed to be called on every Plugin::register().
     */
    public static function syncOnUpgrade(): void
    {
        $stored = (int) get_option(self::REVISION_OPTION, 0);
        if ($stored >= self::REVISION) {
            return;
        }

        foreach (self::REVOKED_ON_UPGRADE as $roleName) {
            $role = get_role($roleName);
            if ($role === null) {
                continue;
            }
            $role->remove_cap(self::MANAGE);
            $role->remove_cap(self::VIEW);
        }

        self::install();
        update_option(self::REVISION_OPTION, self::REVISION, false);
    }

    public static function uninstall(): void
    {
        $roles = array_unique(array_merge(self::grantedRoles(), self::REVOKED_ON_UPGRADE));
        foreach ($roles as $roleName) {
            $role = get_role($roleName);
            if ($role === null) {
                continue;
            }
            $role->remove_cap(self::MANAGE);
            $role->remove_cap(self::VIEW);
        }
        delete_option(self::REVISION_OPTION);
    }
}
