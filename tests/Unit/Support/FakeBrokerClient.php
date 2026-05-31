<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Support;

use Slash\Booking\Google\BrokerGateway;

/**
 * In-memory BrokerGateway for tests. Scriptable per-method; mirrors the
 * FakeCalendarGateway pattern (no network, public state for assertions).
 */
final class FakeBrokerClient implements BrokerGateway
{
    public string $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?state=fake';

    /** @var array{refresh_token:string, access_token:string, expires_in:int, scope:string, email:string, calendar_id:string} */
    public array $claimBundle = [
        'refresh_token' => 'fake-refresh',
        'access_token'  => 'fake-access',
        'expires_in'    => 3600,
        'scope'         => 'calendar.events calendar.readonly',
        'email'         => 'fake@example.test',
        'calendar_id'   => 'fake@example.test',
    ];

    /** @var array{access_token:string, expires_in:int} */
    public array $refreshResult = ['access_token' => 'refreshed-access', 'expires_in' => 3600];

    /** @var array{valid:bool, plan:?string, expires:?string} */
    public array $licenseResult = ['valid' => true, 'plan' => 'pro', 'expires' => null];

    public ?\Throwable $throwOnStart = null;
    public ?\Throwable $throwOnClaim = null;
    public ?\Throwable $throwOnRefresh = null;

    /** @var list<string> */
    public array $startCalls = [];
    /** @var list<string> */
    public array $claimCalls = [];
    /** @var list<string> */
    public array $refreshCalls = [];

    public function startUrl(string $returnUrl, string $n): string
    {
        $this->startCalls[] = $n;
        if ($this->throwOnStart !== null) {
            throw $this->throwOnStart;
        }
        return $this->authUrl;
    }

    public function claim(string $claimCode): array
    {
        $this->claimCalls[] = $claimCode;
        if ($this->throwOnClaim !== null) {
            throw $this->throwOnClaim;
        }
        return $this->claimBundle;
    }

    public function refresh(string $refreshToken): array
    {
        $this->refreshCalls[] = $refreshToken;
        if ($this->throwOnRefresh !== null) {
            throw $this->throwOnRefresh;
        }
        return $this->refreshResult;
    }

    public function validateLicense(string $siteUrl): array
    {
        return $this->licenseResult;
    }
}
