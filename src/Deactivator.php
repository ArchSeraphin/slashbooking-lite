<?php

declare(strict_types=1);

namespace Slash\Booking;

final class Deactivator
{
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(\Slash\Booking\Privacy\BookingRetentionPurger::HOOK);
    }
}
