<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Google;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Google\BrokerClient;

final class BrokerClientTest extends TestCase
{
    /**
     * @param array{status:int, json:mixed} $response
     * @return array{0: BrokerClient, 1: \ArrayObject<string, mixed>}
     */
    private function clientCapturing(array $response): array
    {
        // ArrayObject (by-handle) so the closure's writes are visible to the
        // caller after destructuring — a plain array element returned by
        // reference would be copied by value on `[$client, $captured] = ...`.
        /** @var \ArrayObject<string, mixed> $captured */
        $captured = new \ArrayObject(['url' => '', 'body' => []]);
        $http = function (string $url, array $body) use ($captured, $response): array {
            $captured['url']  = $url;
            $captured['body'] = $body;
            return $response;
        };
        $client = new BrokerClient('https://broker.test/api', 'LIC-123', $http);
        return [$client, $captured];
    }

    public function test_validate_license_posts_site_and_returns_payload(): void
    {
        [$client, $captured] = $this->clientCapturing([
            'status' => 200,
            'json'   => ['valid' => true, 'plan' => 'pro', 'expires' => '2027-01-01'],
        ]);

        $result = $client->validateLicense('https://my-site.test');

        self::assertSame('https://broker.test/api/license/validate', $captured['url']);
        self::assertSame('LIC-123', $captured['body']['license']);
        self::assertSame('https://my-site.test', $captured['body']['site']);
        self::assertTrue($result['valid']);
        self::assertSame('pro', $result['plan']);
        self::assertSame('2027-01-01', $result['expires']);
    }

    public function test_validate_license_returns_invalid_on_401(): void
    {
        [$client] = $this->clientCapturing([
            'status' => 401,
            'json'   => ['error' => 'invalid_license'],
        ]);

        $result = $client->validateLicense('https://my-site.test');

        self::assertFalse($result['valid']);
        self::assertNull($result['plan']);
        self::assertNull($result['expires']);
    }

    public function test_start_url_posts_return_and_nonce_and_returns_auth_url(): void
    {
        [$client, $captured] = $this->clientCapturing([
            'status' => 200,
            'json'   => ['auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth?state=signed'],
        ]);

        $url = $client->startUrl('https://my-site.test/wp-json/slashbooking/v1/admin/google/oauth/callback', 'NONCE-1');

        self::assertSame('https://broker.test/api/oauth/start', $captured['url']);
        self::assertSame('LIC-123', $captured['body']['license']);
        self::assertSame('https://my-site.test/wp-json/slashbooking/v1/admin/google/oauth/callback', $captured['body']['return']);
        self::assertSame('NONCE-1', $captured['body']['n']);
        self::assertSame('https://accounts.google.com/o/oauth2/v2/auth?state=signed', $url);
    }

    public function test_start_url_throws_oauth_failure_on_invalid_license(): void
    {
        [$client] = $this->clientCapturing(['status' => 401, 'json' => ['error' => 'invalid_license']]);
        $this->expectException(\Slash\Booking\Google\Exceptions\OAuthFailure::class);
        $client->startUrl('https://my-site.test/cb', 'N');
    }

    public function test_start_url_throws_broker_unavailable_on_network_error(): void
    {
        [$client] = $this->clientCapturing(['status' => 0, 'json' => null]);
        $this->expectException(\Slash\Booking\Google\Exceptions\BrokerUnavailable::class);
        $client->startUrl('https://my-site.test/cb', 'N');
    }

    public function test_claim_returns_token_bundle(): void
    {
        [$client, $captured] = $this->clientCapturing([
            'status' => 200,
            'json'   => [
                'refresh_token' => 'rt',
                'access_token'  => 'at',
                'expires_in'    => 3600,
                'scope'         => 'calendar.events calendar.readonly',
                'email'         => 'me@example.test',
                'calendar_id'   => 'me@example.test',
            ],
        ]);

        $bundle = $client->claim('CLAIM-XYZ');

        self::assertSame('https://broker.test/api/oauth/claim', $captured['url']);
        self::assertSame('CLAIM-XYZ', $captured['body']['claim']);
        self::assertSame('rt', $bundle['refresh_token']);
        self::assertSame('at', $bundle['access_token']);
        self::assertSame(3600, $bundle['expires_in']);
        self::assertSame('me@example.test', $bundle['email']);
        self::assertSame('me@example.test', $bundle['calendar_id']);
    }

    public function test_claim_throws_oauth_failure_when_not_found(): void
    {
        [$client] = $this->clientCapturing(['status' => 404, 'json' => ['error' => 'claim_not_found']]);
        $this->expectException(\Slash\Booking\Google\Exceptions\OAuthFailure::class);
        $client->claim('GONE');
    }

    public function test_claim_throws_broker_unavailable_on_5xx(): void
    {
        [$client] = $this->clientCapturing(['status' => 502, 'json' => ['error' => 'google_error']]);
        $this->expectException(\Slash\Booking\Google\Exceptions\BrokerUnavailable::class);
        $client->claim('X');
    }

    public function test_refresh_returns_access_token(): void
    {
        [$client, $captured] = $this->clientCapturing([
            'status' => 200,
            'json'   => ['access_token' => 'new-at', 'expires_in' => 3599],
        ]);

        $out = $client->refresh('rt');

        self::assertSame('https://broker.test/api/oauth/refresh', $captured['url']);
        self::assertSame('rt', $captured['body']['refresh_token']);
        self::assertSame('new-at', $out['access_token']);
        self::assertSame(3599, $out['expires_in']);
    }

    public function test_refresh_throws_token_revoked_on_invalid_grant(): void
    {
        [$client] = $this->clientCapturing(['status' => 401, 'json' => ['error' => 'token_revoked']]);
        $this->expectException(\Slash\Booking\Google\Exceptions\TokenRevoked::class);
        $client->refresh('dead');
    }

    public function test_refresh_throws_oauth_failure_on_invalid_license(): void
    {
        [$client] = $this->clientCapturing(['status' => 401, 'json' => ['error' => 'invalid_license']]);
        $this->expectException(\Slash\Booking\Google\Exceptions\OAuthFailure::class);
        $client->refresh('rt');
    }

    public function test_refresh_throws_broker_unavailable_on_google_error(): void
    {
        [$client] = $this->clientCapturing(['status' => 502, 'json' => ['error' => 'google_error']]);
        $this->expectException(\Slash\Booking\Google\Exceptions\BrokerUnavailable::class);
        $client->refresh('rt');
    }
}
