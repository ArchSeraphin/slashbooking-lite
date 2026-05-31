<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Admin\TurnstileNotice;

final class TurnstileNoticeTest extends TestCase
{
    public function test_shows_when_secret_empty(): void
    {
        self::assertTrue(TurnstileNotice::shouldShow(''));
        self::assertTrue(TurnstileNotice::shouldShow('   '));
    }

    public function test_hidden_when_secret_present(): void
    {
        self::assertFalse(TurnstileNotice::shouldShow('1x0000000000000000000000000000000AA'));
    }
}
