<?php
declare(strict_types=1);

namespace Slash\Booking\Http;

use Slash\Booking\Booking\CancelBooking;
use Slash\Booking\Booking\DecisionTokenSigner;
use Slash\Booking\Booking\Exceptions\BookingNotFound;
use Slash\Booking\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class PublicCancelController
{
    public function __construct(
        private readonly DecisionTokenSigner $signer,
        private readonly CancelBooking $cancel,
    ) {
    }

    public function registerRoutes(): void
    {
        $args = [
            'uid' => ['type' => 'string', 'required' => true],
            'exp' => ['type' => 'integer', 'required' => true],
            'sig' => ['type' => 'string', 'required' => true],
        ];

        register_rest_route(
            Plugin::REST_NAMESPACE,
            '/cancel',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [$this, 'handleGet'],
                    'permission_callback' => '__return_true',
                    'args'                => $args,
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'handlePost'],
                    'permission_callback' => '__return_true',
                    'args'                => $args,
                ],
            ]
        );
    }

    public function handleGet(WP_REST_Request $request): WP_REST_Response
    {
        $uid = (string) $request['uid'];
        $exp = (int) $request['exp'];
        $sig = (string) $request['sig'];

        if (!$this->signer->verify('cancel|' . $uid, $exp, $sig)) {
            return $this->htmlResponse(
                403,
                '<h1>' . esc_html__('Invalid or expired link', 'slashbooking') . '</h1>'
                . '<p>' . esc_html__('Please request a new link.', 'slashbooking') . '</p>',
            );
        }

        $endpoint = esc_url(rest_url(Plugin::REST_NAMESPACE . '/cancel'));
        $form = '<h1>' . esc_html__('Cancel this booking?', 'slashbooking') . '</h1>'
            . '<form method="post" action="' . $endpoint . '">'
            . '<input type="hidden" name="uid" value="' . esc_attr($uid) . '">'
            . '<input type="hidden" name="exp" value="' . (int) $exp . '">'
            . '<input type="hidden" name="sig" value="' . esc_attr($sig) . '">'
            . '<button type="submit" style="font-size:16px;padding:10px 18px;cursor:pointer">'
            . esc_html__('Cancel the appointment', 'slashbooking') . '</button>'
            . '</form>';

        return $this->htmlResponse(200, $form);
    }

    public function handlePost(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $uid = (string) $request['uid'];
        $exp = (int) $request['exp'];
        $sig = (string) $request['sig'];

        if (!$this->signer->verify('cancel|' . $uid, $exp, $sig)) {
            return new WP_Error('sb_invalid_token', __('Invalid or expired link.', 'slashbooking'), ['status' => 403]);
        }

        try {
            $this->cancel->execute($uid);
        } catch (BookingNotFound $e) {
            return new WP_Error('sb_not_found', __('Booking not found.', 'slashbooking'), ['status' => 404]);
        }

        return new WP_REST_Response(['status' => 'cancelled'], 200);
    }

    private function htmlResponse(int $status, string $body): WP_REST_Response
    {
        // Standalone HTTP document served directly by the REST API (text/html),
        // outside the WordPress theme/wp_head() pipeline — there is no enqueue
        // target here, so the minimal page styling is set via a style attribute.
        $title = esc_html__('Appointment cancellation', 'slashbooking');
        $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>' . $title . '</title>'
            . '</head><body style="font-family:system-ui,sans-serif;max-width:560px;margin:80px auto;padding:0 16px;color:#111">'
            . $body . '</body></html>';
        return new WP_REST_Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
