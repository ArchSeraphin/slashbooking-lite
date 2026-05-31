<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\GoogleAccount;

final class GoogleAccountWatchTest extends TestCase
{
    private function connected(): GoogleAccount
    {
        return GoogleAccount::connect(
            label: 'primary',
            calendarId: 'primary',
            refreshTokenEnc: 'r',
            accessTokenEnc: 'a',
            expiresAt: new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')),
        );
    }

    public function test_resource_id_compare_is_false_when_no_watch(): void
    {
        $acct = $this->connected();
        self::assertFalse($acct->verifyWatchResourceId('anything'));
    }

    public function test_resource_id_compare_matches_attached_value(): void
    {
        $acct = $this->connected();
        $acct->attachWatch(
            channelId: 'chan-1',
            resourceId: 'res-1',
            tokenSecret: 'tok',
            expiresAt: new DateTimeImmutable('+1 day', new DateTimeZone('UTC')),
        );
        self::assertTrue($acct->verifyWatchResourceId('res-1'));
        self::assertFalse($acct->verifyWatchResourceId('res-2'));
        self::assertFalse($acct->verifyWatchResourceId(''));
    }

    public function test_watch_active_false_when_no_expiry(): void
    {
        $acct = $this->connected();
        $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        self::assertFalse($acct->watchActive($now));
    }

    public function test_watch_active_true_before_expiry_false_after(): void
    {
        $acct = $this->connected();
        $acct->attachWatch(
            channelId: 'chan-1',
            resourceId: 'res-1',
            tokenSecret: 'tok',
            expiresAt: new DateTimeImmutable('2030-01-01 00:00:00', new DateTimeZone('UTC')),
        );
        $before = new DateTimeImmutable('2029-12-31 23:59:59', new DateTimeZone('UTC'));
        $after  = new DateTimeImmutable('2030-01-01 00:00:01', new DateTimeZone('UTC'));
        self::assertTrue($acct->watchActive($before));
        self::assertFalse($acct->watchActive($after));
    }
}
