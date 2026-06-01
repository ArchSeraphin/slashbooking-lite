<?php
declare(strict_types=1);

namespace Slash\Booking;

/**
 * Plugin-wide configuration helpers.
 */
final class Config
{
    /** Default SlashBooking broker base URL (no trailing slash). */
    public const BROKER_URL_DEFAULT = 'https://broker.slashbox.fr';

    /**
     * Resolve the broker base URL. Overridable via the 'sb_broker_url' filter.
     * Always returned without a trailing slash so callers can append '/oauth/...'.
     */
    public static function brokerUrl(): string
    {
        $url = (string) apply_filters('sb_broker_url', self::BROKER_URL_DEFAULT);
        return rtrim($url, '/');
    }
}
