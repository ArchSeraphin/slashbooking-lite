<?php
declare(strict_types=1);

namespace Slash\Booking\Http;

use Closure;
use Slash\Booking\Booking\ConfirmBooking;
use Slash\Booking\Booking\DecisionTokenSigner;
use Slash\Booking\Booking\Exceptions\BookingNotFound;
use Slash\Booking\Booking\RejectBooking;
use Slash\Booking\Plugin;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class DecisionController
{
    /**
     * @param ?Closure(array<string, mixed>): void $log Optional server-side logger.
     */
    public function __construct(
        private readonly DecisionTokenSigner $signer,
        private readonly ConfirmBooking $confirm,
        private readonly RejectBooking  $reject,
        private readonly ?Closure $log = null,
    ) {
    }

    public function registerRoutes(): void
    {
        $args = [
            'booking' => ['type' => 'integer', 'required' => true],
            'action'  => ['type' => 'string',  'required' => true, 'enum' => ['confirm', 'reject']],
            'exp'     => ['type' => 'integer', 'required' => true],
            'sig'     => ['type' => 'string',  'required' => true],
        ];

        register_rest_route(
            Plugin::REST_NAMESPACE,
            '/decide',
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

    /**
     * GET renders an interstitial confirmation page. No state change happens
     * here, so passive link prefetchers / mail-security scanners cannot trigger
     * the decision.
     */
    public function handleGet(WP_REST_Request $request): WP_REST_Response
    {
        $id     = (int) $request['booking'];
        $action = (string) $request['action'];
        $exp    = (int) $request['exp'];
        $sig    = (string) $request['sig'];

        if (!in_array($action, ['confirm', 'reject'], true)) {
            return $this->htmlResponse(400, '<h1>' . esc_html__('Action invalide', 'slashbooking') . '</h1>');
        }

        $payload = 'decide|' . $id . '|' . $action;
        if (!$this->signer->verify($payload, $exp, $sig)) {
            return $this->htmlResponse(
                403,
                '<h1>' . esc_html__('Lien invalide ou expiré', 'slashbooking') . '</h1>'
                . '<p>' . esc_html__('Demandez un nouveau lien.', 'slashbooking') . '</p>',
            );
        }

        $endpoint = esc_url(rest_url(Plugin::REST_NAMESPACE . '/decide'));
        $label = $action === 'confirm'
            ? esc_html__('Confirmer le RDV', 'slashbooking')
            : esc_html__('Refuser le RDV', 'slashbooking');
        $heading = $action === 'confirm'
            ? esc_html__('Confirmer cette réservation ?', 'slashbooking')
            : esc_html__('Refuser cette réservation ?', 'slashbooking');

        $form = '<h1>' . $heading . '</h1>'
            . '<form method="post" action="' . $endpoint . '">'
            . '<input type="hidden" name="booking" value="' . (int) $id . '">'
            . '<input type="hidden" name="action" value="' . esc_attr($action) . '">'
            . '<input type="hidden" name="exp" value="' . (int) $exp . '">'
            . '<input type="hidden" name="sig" value="' . esc_attr($sig) . '">'
            . '<button type="submit" style="font-size:16px;padding:10px 18px;cursor:pointer">'
            . $label . '</button>'
            . '</form>';

        return $this->htmlResponse(200, $form);
    }

    /**
     * POST actually performs the confirm/reject.
     */
    public function handlePost(WP_REST_Request $request): WP_REST_Response
    {
        $id     = (int) $request['booking'];
        $action = (string) $request['action'];
        $exp    = (int) $request['exp'];
        $sig    = (string) $request['sig'];

        if (!in_array($action, ['confirm', 'reject'], true)) {
            return $this->htmlResponse(400, '<h1>' . esc_html__('Action invalide', 'slashbooking') . '</h1>');
        }

        $payload = 'decide|' . $id . '|' . $action;
        if (!$this->signer->verify($payload, $exp, $sig)) {
            return $this->htmlResponse(
                403,
                '<h1>' . esc_html__('Lien invalide ou expiré', 'slashbooking') . '</h1>'
                . '<p>' . esc_html__('Demandez un nouveau lien.', 'slashbooking') . '</p>',
            );
        }

        try {
            if ($action === 'confirm') {
                $this->confirm->execute($id);
                $message = '<h1>' . esc_html__('RDV confirmé ✓', 'slashbooking') . '</h1>'
                    . '<p>' . esc_html__('Le client a été notifié.', 'slashbooking') . '</p>';
            } else {
                $this->reject->execute($id);
                $message = '<h1>' . esc_html__('RDV refusé', 'slashbooking') . '</h1>'
                    . '<p>' . esc_html__('Le client a été notifié.', 'slashbooking') . '</p>';
            }
        } catch (BookingNotFound $e) {
            return $this->htmlResponse(404, '<h1>' . esc_html__('Réservation introuvable', 'slashbooking') . '</h1>');
        } catch (\DomainException $e) {
            if ($this->log !== null) {
                ($this->log)([
                    'level'         => 'warn',
                    'action'        => 'decision_conflict',
                    'booking'       => $id,
                    'error_message' => $e->getMessage(),
                ]);
            }
            return $this->htmlResponse(
                409,
                '<h1>' . esc_html__('Impossible', 'slashbooking') . '</h1>'
                . '<p>' . esc_html__('Cette demande a déjà été traitée ou n’est plus valide.', 'slashbooking') . '</p>',
            );
        }

        return $this->htmlResponse(200, $message);
    }

    private function htmlResponse(int $status, string $body): WP_REST_Response
    {
        return new WP_REST_Response(
            $this->wrapHtml($body),
            $status,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function wrapHtml(string $inner): string
    {
        $title = esc_html__('Décision RDV', 'slashbooking');
        return <<<HTML
<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>{$title}</title>
<style>body{font-family:system-ui,sans-serif;max-width:560px;margin:80px auto;padding:0 16px;color:#111}</style>
</head><body>{$inner}</body></html>
HTML;
    }
}
