<?php
declare(strict_types=1);

namespace Slash\Booking\Http;

use Slash\Booking\Availability\SlotGenerator;
use Slash\Booking\Persistence\BookingRepository;
use Slash\Booking\Persistence\ServiceRepository;
use Slash\Booking\Plugin;

final class RestRouter
{
    /**
     * Routes intentionally exposed to unauthenticated visitors. Validated
     * separately via signed tokens (cancel/decide), Turnstile + honeypot +
     * rate-limiting (bookings), or open by design (services, availability).
     */
    private const PUBLIC_ROUTES = [
        'services',
        'availability',
        'bookings',
        'cancel',
        'decide',
    ];

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
        // Some security plugins / snippets block the entire REST API for
        // guests via rest_authentication_errors, which short-circuits before
        // our routes' '__return_true' permission_callback can run. Whitelist
        // our public endpoints so the booking widget works in incognito.
        add_filter('rest_authentication_errors', [$this, 'unblockPublicEndpoints'], 99);
    }

    /**
     * @param  mixed $result
     * @return mixed
     */
    public function unblockPublicEndpoints($result)
    {
        if (!is_wp_error($result)) {
            return $result;
        }

        // Only relax "not authenticated" style errors. Never bypass nonce or
        // cookie validation failures that indicate a real auth-state mismatch.
        $bypassable = [
            'rest_not_logged_in',
            'rest_forbidden',
            'rest_cannot_access',
            'rest_login_required',
            'rest_user_invalid',
        ];
        if (!in_array($result->get_error_code(), $bypassable, true)) {
            return $result;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($uri === '') {
            return $result;
        }

        $prefix = '/' . Plugin::REST_NAMESPACE . '/';
        foreach (self::PUBLIC_ROUTES as $route) {
            $needle = $prefix . $route;
            $pos = strpos($uri, $needle);
            if ($pos === false) {
                continue;
            }
            // Boundary check: must be followed by /, ?, &, or end of string,
            // so '/slashbooking/v1/services' does not also match a hypothetical
            // '/slashbooking/v1/services-extra' route.
            $after = $uri[$pos + strlen($needle)] ?? '';
            if ($after === '' || $after === '/' || $after === '?' || $after === '&') {
                return true;
            }
        }

        return $result;
    }

    public function registerRoutes(): void
    {
        global $wpdb;
        $services = new ServiceRepository($wpdb);
        $bookings = new BookingRepository($wpdb);
        $generator = new SlotGenerator(
            stepMinutes: 15,
            siteTimezone: wp_timezone_string(),
        );

        $createBooking = new \Slash\Booking\Booking\CreateBooking(
            slotIsFree: function (\Slash\Booking\Domain\Service $svc, \Slash\Booking\Domain\TimeSlot $slot) use ($bookings): bool {
                return $bookings->findOverlapping($svc->id ?? 0, $slot) === [];
            },
            persist: function (\Slash\Booking\Domain\Booking $b) use ($bookings): void {
                $bookings->save($b);
            },
        );

        $turnstile = new \Slash\Booking\PublicFront\TurnstileVerifier(
            (string) get_option('sb_turnstile_secret_key', ''),
        );
        (new PublicBookingController($services, $bookings, $generator, $createBooking, $turnstile))->registerRoutes();

        $signer = new \Slash\Booking\Booking\DecisionTokenSigner((string) get_option('sb_decision_secret'));
        $cancel = new \Slash\Booking\Booking\CancelBooking(
            find: fn (string $uid) => $bookings->findByPublicUid($uid),
            persist: fn (\Slash\Booking\Domain\Booking $b) => $bookings->save($b),
        );
        (new PublicCancelController($signer, $cancel))->registerRoutes();

        $confirmUC = new \Slash\Booking\Booking\ConfirmBooking(
            find: fn (int $id) => $bookings->findById($id),
            persist: fn (\Slash\Booking\Domain\Booking $b) => $bookings->save($b),
        );
        $rejectUC = new \Slash\Booking\Booking\RejectBooking(
            find: fn (int $id) => $bookings->findById($id),
            persist: fn (\Slash\Booking\Domain\Booking $b) => $bookings->save($b),
        );
        (new DecisionController(
            $signer,
            $confirmUC,
            $rejectUC,
            static function (array $entry): void {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional: rare decision-link conflict logged server-side; no WP equivalent.
                error_log('[slashbooking] ' . (string) wp_json_encode($entry));
            },
        ))->registerRoutes();

        (new AdminBookingController($bookings, $confirmUC, $rejectUC, $cancel))->registerRoutes();
        (new AdminSettingsController())->registerRoutes();
        (new AdminServiceController($services, $bookings))->registerRoutes();
    }
}
