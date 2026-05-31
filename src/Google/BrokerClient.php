<?php
declare(strict_types=1);

namespace Slash\Booking\Google;

use Closure;
use Slash\Booking\Google\Exceptions\BrokerUnavailable;
use Slash\Booking\Google\Exceptions\OAuthFailure;
use Slash\Booking\Google\Exceptions\TokenRevoked;

/**
 * HTTP client for the SlashBooking OAuth broker.
 *
 * The broker holds the single Google OAuth client (id + secret). This plugin
 * never ships a Google secret: it asks the broker to build the consent URL,
 * exchange the auth code (one-time claim), and refresh access tokens. Calendar
 * API calls stay direct (WP -> Google) using the Bearer access token.
 *
 * @phpstan-type HttpResponse array{status:int, json:mixed}
 */
final class BrokerClient implements BrokerGateway
{
    /**
     * @param Closure(string, array<string, mixed>): array{status:int, json:mixed}|null $httpPost
     *   Injectable HTTP callable for tests. Default uses wp_remote_post.
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $license,
        private readonly ?Closure $httpPost = null,
    ) {
    }

    /**
     * POST /license/validate.
     *
     * @return array{valid: bool, plan: ?string, expires: ?string}
     */
    public function validateLicense(string $siteUrl): array
    {
        $res = ($this->httpPost ?? $this->defaultPost(...))(
            $this->baseUrl . '/license/validate',
            ['license' => $this->license, 'site' => $siteUrl],
        );

        $json = is_array($res['json']) ? $res['json'] : [];
        if ($res['status'] !== 200 || ($json['valid'] ?? null) !== true) {
            return ['valid' => false, 'plan' => null, 'expires' => null];
        }

        return [
            'valid'   => true,
            'plan'    => isset($json['plan']) ? (string) $json['plan'] : null,
            'expires' => isset($json['expires']) ? (string) $json['expires'] : null,
        ];
    }

    /**
     * POST /oauth/start. Returns the Google consent URL (broker-signed state).
     *
     * @throws BrokerUnavailable network failure / 5xx (retryable)
     * @throws OAuthFailure      invalid license or invalid return URL (4xx)
     */
    public function startUrl(string $returnUrl, string $n): string
    {
        $res = ($this->httpPost ?? $this->defaultPost(...))(
            $this->baseUrl . '/oauth/start',
            ['license' => $this->license, 'return' => $returnUrl, 'n' => $n],
        );

        $this->guardTransport($res['status']);

        $json = is_array($res['json']) ? $res['json'] : [];
        if ($res['status'] !== 200 || !isset($json['auth_url'])) {
            throw new OAuthFailure($this->errorMessage('oauth/start', $res['status'], $json));
        }

        return (string) $json['auth_url'];
    }

    /**
     * POST /oauth/claim. One-time: the broker destroys the claim after this call.
     *
     * @return array{refresh_token:string, access_token:string, expires_in:int, scope:string, email:string, calendar_id:string}
     * @throws BrokerUnavailable network failure / 5xx (retryable)
     * @throws OAuthFailure      claim missing/expired/used or invalid license (4xx)
     */
    public function claim(string $claimCode): array
    {
        $res = ($this->httpPost ?? $this->defaultPost(...))(
            $this->baseUrl . '/oauth/claim',
            ['license' => $this->license, 'claim' => $claimCode],
        );

        $this->guardTransport($res['status']);

        $json = is_array($res['json']) ? $res['json'] : [];
        if (
            $res['status'] !== 200
            || !isset($json['refresh_token'], $json['access_token'], $json['expires_in'])
        ) {
            throw new OAuthFailure($this->errorMessage('oauth/claim', $res['status'], $json));
        }

        return [
            'refresh_token' => (string) $json['refresh_token'],
            'access_token'  => (string) $json['access_token'],
            'expires_in'    => (int) $json['expires_in'],
            'scope'         => isset($json['scope']) ? (string) $json['scope'] : '',
            'email'         => isset($json['email']) ? (string) $json['email'] : '',
            'calendar_id'   => isset($json['calendar_id']) ? (string) $json['calendar_id'] : 'primary',
        ];
    }

    /**
     * POST /oauth/refresh. Stateless on the broker side.
     *
     * @return array{access_token:string, expires_in:int}
     * @throws BrokerUnavailable network failure / 5xx (retryable, keep tokens)
     * @throws TokenRevoked      Google invalid_grant -> mark reconnection required
     * @throws OAuthFailure      invalid license (4xx)
     */
    public function refresh(string $refreshToken): array
    {
        $res = ($this->httpPost ?? $this->defaultPost(...))(
            $this->baseUrl . '/oauth/refresh',
            ['license' => $this->license, 'refresh_token' => $refreshToken],
        );

        $this->guardTransport($res['status']);

        $json = is_array($res['json']) ? $res['json'] : [];
        $err  = isset($json['error']) ? (string) $json['error'] : '';

        if ($res['status'] === 401 && $err === 'token_revoked') {
            throw new TokenRevoked('Google refresh token revoked (invalid_grant).');
        }
        if ($res['status'] !== 200 || !isset($json['access_token'], $json['expires_in'])) {
            throw new OAuthFailure($this->errorMessage('oauth/refresh', $res['status'], $json));
        }

        return [
            'access_token' => (string) $json['access_token'],
            'expires_in'   => (int) $json['expires_in'],
        ];
    }

    /**
     * Throw BrokerUnavailable for "no response" (0) and 5xx server errors.
     */
    private function guardTransport(int $status): void
    {
        if ($status === 0 || $status >= 500) {
            throw new BrokerUnavailable(sprintf('Broker unavailable (HTTP %d).', $status));
        }
    }

    /**
     * @param array<string, mixed> $json
     */
    private function errorMessage(string $endpoint, int $status, array $json): string
    {
        $err = isset($json['error']) ? (string) $json['error'] : 'unknown';
        return sprintf('Broker %s returned %d (%s).', $endpoint, $status, $err);
    }

    /**
     * Default transport: wp_remote_post + JSON body.
     *
     * @param array<string, mixed> $body
     * @return array{status:int, json:mixed}
     */
    private function defaultPost(string $url, array $body): array
    {
        $resp = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body'    => (string) wp_json_encode($body),
        ]);

        if (is_wp_error($resp)) {
            // 0 signals "no HTTP response"; callers map this to BrokerUnavailable.
            return ['status' => 0, 'json' => null];
        }

        $status = (int) wp_remote_retrieve_response_code($resp);
        $raw    = (string) wp_remote_retrieve_body($resp);
        $json   = json_decode($raw, true);

        return ['status' => $status, 'json' => $json];
    }
}
