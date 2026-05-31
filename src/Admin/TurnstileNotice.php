<?php
declare(strict_types=1);

namespace Slash\Booking\Admin;

/**
 * Persistent admin warning shown when the public booking form has no
 * Cloudflare Turnstile secret configured (i.e. bot protection is off).
 */
final class TurnstileNotice
{
    public const SECRET_OPTION = 'sb_turnstile_secret_key';

    public static function shouldShow(string $secret): bool
    {
        return trim($secret) === '';
    }

    public function register(): void
    {
        add_action('admin_notices', static function (): void {
            if (!current_user_can('manage_options')) {
                return;
            }
            $secret = (string) get_option(self::SECRET_OPTION, '');
            if (!self::shouldShow($secret)) {
                return;
            }
            echo '<div class="notice notice-warning"><p><strong>SlashBooking :</strong> '
                . esc_html__(
                    'le formulaire de réservation public n’est pas protégé contre les robots. Configurez une clé secrète Cloudflare Turnstile dans les réglages pour activer la protection anti-spam.',
                    'slashbooking'
                )
                . '</p></div>';
        });
    }
}
