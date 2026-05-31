<?php
declare(strict_types=1);

namespace Slash\Booking\Migration;

use Slash\Booking\Persistence\GoogleAccountRepository;

/**
 * One-time migration to the broker-based OAuth model.
 *
 * - Deletes the obsolete Google OAuth options (sb_google_client_id/secret).
 * - Flags any existing GoogleAccount as "reconnection required" (existing
 *   refresh tokens were issued by the client's own GCP project and cannot be
 *   refreshed by the broker). Booking data is kept untouched.
 *
 * Guarded by the sb_broker_migrated option so it runs at most once.
 */
final class BrokerMigration
{
    public function __construct(private readonly GoogleAccountRepository $accounts)
    {
    }

    public function run(): void
    {
        if ((string) get_option('sb_broker_migrated', '') === '1') {
            return;
        }

        delete_option('sb_google_client_id');
        delete_option('sb_google_client_secret');

        $account = $this->accounts->findSingle();
        if ($account !== null) {
            $account->markReconnectRequired();
            $this->accounts->save($account);
        }

        update_option('sb_broker_migrated', '1', true);
    }
}
