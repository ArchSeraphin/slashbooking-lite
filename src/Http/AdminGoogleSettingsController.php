<?php
declare(strict_types=1);

namespace Slash\Booking\Http;

use Slash\Booking\Admin\Capabilities;
use Slash\Booking\Google\BrokerGateway;
use Slash\Booking\Plugin;
use WP_REST_Request;
use WP_REST_Response;

/**
 * License management for the broker-based Google connection.
 *
 * GET  /admin/google/settings -> { has_license, license_status, plan, expires, redirect_uri }
 * POST /admin/google/settings -> stores sb_license_key (sanitized) and validates it.
 *
 * The license key is NEVER returned in clear. It travels server-to-server only.
 */
final class AdminGoogleSettingsController
{
    public function __construct(private readonly BrokerGateway $broker)
    {
    }

    public function registerRoutes(): void
    {
        $cap = static fn (): bool => current_user_can(Capabilities::MANAGE);

        register_rest_route(Plugin::REST_NAMESPACE, '/admin/google/settings', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'read'],
                'permission_callback' => $cap,
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'write'],
                'permission_callback' => $cap,
            ],
        ]);
    }

    public function read(): WP_REST_Response
    {
        $key = (string) get_option('sb_license_key', '');
        return new WP_REST_Response([
            'has_license'    => $key !== '',
            'license_status' => $key === '' ? 'absent' : (string) get_option('sb_license_status', 'unknown'),
            'plan'           => get_option('sb_license_plan', null),
            'expires'        => get_option('sb_license_expires', null),
            'redirect_uri'   => rest_url(Plugin::REST_NAMESPACE . '/admin/google/oauth/callback'),
        ], 200);
    }

    public function write(WP_REST_Request $req): WP_REST_Response
    {
        $key = sanitize_text_field((string) $req->get_param('license_key'));
        update_option('sb_license_key', $key, false);

        if ($key === '') {
            update_option('sb_license_status', 'absent', false);
            update_option('sb_license_plan', null, false);
            update_option('sb_license_expires', null, false);
            return new WP_REST_Response([
                'has_license'    => false,
                'license_status' => 'absent',
                'plan'           => null,
                'expires'        => null,
            ], 200);
        }

        $result = $this->broker->validateLicense(site_url());
        $status = $result['valid'] ? 'valid' : 'invalid';
        update_option('sb_license_status', $status, false);
        update_option('sb_license_plan', $result['plan'], false);
        update_option('sb_license_expires', $result['expires'], false);

        return new WP_REST_Response([
            'has_license'    => true,
            'license_status' => $status,
            'plan'           => $result['plan'],
            'expires'        => $result['expires'],
        ], 200);
    }
}
