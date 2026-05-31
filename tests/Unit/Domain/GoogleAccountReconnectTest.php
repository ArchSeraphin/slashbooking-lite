<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\GoogleAccount;

final class GoogleAccountReconnectTest extends TestCase
{
    private function makeAccount(): GoogleAccount
    {
        return GoogleAccount::connect(
            label: 'Commercial',
            calendarId: 'primary',
            refreshTokenEnc: 'r',
            accessTokenEnc: 'a',
            expiresAt: new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')),
        );
    }

    public function test_new_account_does_not_require_reconnect(): void
    {
        self::assertFalse($this->makeAccount()->reconnectRequired());
    }

    public function test_mark_and_clear_reconnect(): void
    {
        $a = $this->makeAccount();
        $a->markReconnectRequired();
        self::assertTrue($a->reconnectRequired());
        $a->clearReconnectRequired();
        self::assertFalse($a->reconnectRequired());
    }

    public function test_from_row_reads_reconnect_required_column(): void
    {
        $a = GoogleAccount::fromRow([
            'id'                       => 5,
            'label'                    => 'Commercial',
            'calendar_id'             => 'primary',
            'oauth_refresh_token_enc' => 'r',
            'oauth_access_token_enc'  => 'a',
            'oauth_expires_at'        => '2030-01-01 00:00:00',
            'watch_channel_id'        => null,
            'watch_resource_id'       => null,
            'watch_token_secret'      => null,
            'watch_expires_at'        => null,
            'sync_token'              => null,
            'last_full_sync_at'       => null,
            'reconnect_required'      => 1,
            'created_at'              => '2025-01-01 00:00:00',
            'updated_at'              => '2025-01-01 00:00:00',
        ]);
        self::assertTrue($a->reconnectRequired());
    }
}
