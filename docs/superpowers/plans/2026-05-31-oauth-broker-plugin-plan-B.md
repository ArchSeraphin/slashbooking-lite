# OAuth Broker Integration (Plugin Side) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the per-client Google OAuth client (client_id/client_secret in WP options) with a license-gated, 1-click connection through the SlashBooking broker on slashbox.fr, so the plugin ships zero Google secrets.

**Architecture:** A new `BrokerClient` PHP service talks server-to-server to the broker HTTP API (start/claim/refresh/validate). `AdminGoogleController` builds the consent URL via the broker and ingests tokens via a one-time `claim`; `GoogleClientBuilder` refreshes access tokens via the broker while still calling Google Calendar directly with the Bearer token. The anti-CSRF nonce `n` reuses the existing `OAuthState` class. The license lives in option `sb_license_key` and gates the connect flow. Existing refresh tokens break on upgrade, so a migration deletes the old options and shows a "reconnect" notice.

**Tech Stack:** PHP 8.1+ (namespace `Slash\Booking`, PSR-12), PHPUnit 10.5, `wp_remote_post`, React admin SPA (`@wordpress/scripts`), PHPStan level 8.

---

## File Structure

| File | Create / Modify | Responsibility |
|---|---|---|
| `src/Google/Exceptions/BrokerUnavailable.php` | Create | Retryable error (network / 5xx). Caller keeps the account connected, never deletes tokens. |
| `src/Google/Exceptions/TokenRevoked.php` | Create | Google `invalid_grant`. Caller marks "reconnection required", keeps data. |
| `src/Google/BrokerClient.php` | Create | HTTP client for the broker: `startUrl`, `claim`, `refresh`, `validateLicense`. Maps HTTP outcomes to exceptions. Injectable HTTP callable for tests. |
| `src/Config.php` | Create | `SB_BROKER_URL` default + `sb_broker_url` filter resolution (`Config::brokerUrl()`). |
| `tests/Unit/Support/FakeBrokerClient.php` | Create | In-memory test double mirroring `FakeCalendarGateway`. |
| `tests/Unit/Google/BrokerClientTest.php` | Create | Unit tests for `BrokerClient` (mocked HTTP). |
| `tests/Unit/Google/ConfigTest.php` | Create | Unit tests for `Config::brokerUrl()` default + filter. |
| `tests/Unit/Http/AdminGoogleSettingsControllerTest.php` | Create | Unit tests for license read/write controller. |
| `tests/Unit/Google/GoogleClientBuilderRefreshTest.php` | Create | Unit tests for refresh via broker + exception mapping. |
| `tests/Unit/Domain/GoogleAccountReconnectTest.php` | Create | Unit tests for the new `markReconnectRequired()` flag. |
| `tests/Unit/MigrationTest.php` | Create | Unit tests for the broker migration routine. |
| `src/Http/AdminGoogleSettingsController.php` | Modify (whole file) | Repurpose from Google client_id/secret to license read/write + validate. |
| `src/Http/AdminGoogleController.php` | Modify (ctor + `start` + `callback`) | License gate, broker-built consent URL, claim ingestion. |
| `src/Google/GoogleClientBuilder.php` | Modify (whole file) | Stop setting client_secret; refresh via `BrokerClient`; map exceptions. |
| `src/Domain/GoogleAccount.php` | Modify (add field + methods) | `markReconnectRequired()` / `clearReconnectRequired()` / `reconnectRequired()`. |
| `src/Persistence/GoogleAccountRepository.php` | Modify (`toRow` + `fromRow` already in domain) | Persist the `reconnect_required` column. |
| `src/Persistence/Migrator.php` | Modify | Add `reconnect_required` column to the `sb_google_accounts` table DDL + bump `Plugin::DB_VERSION`. |
| `src/Http/RestRouter.php` | Modify (lines 148-184) | Construct `BrokerClient`, drop `OAuthClient`, inject into `AdminGoogleController`; pass license to settings controller. |
| `src/Google/OAuthClient.php` | Delete | Exchange/refresh/authUrl now live on the broker. |
| `tests/Unit/Google/OAuthClientTest.php` | Delete | Tests for the deleted class. |
| `src/Migration/BrokerMigration.php` | Create | Idempotent upgrade routine: delete old options, flag reconnect, register admin notice. |
| `src/Plugin.php` | Modify (`register()`) | Call `BrokerMigration::run()` once per version on boot. |
| `src/Admin/react-app/src/api.js` | Modify | Replace `fetchGoogleSettings`/`saveGoogleSettings` with `fetchLicenseStatus`/`saveLicense`. |
| `src/Admin/react-app/src/GooglePage.jsx` | Modify | License card + status + connect button gated on valid license. |
| `src/Admin/react-app/src/GoogleSetupWizard.jsx` | Delete | No longer needed (no GCP setup). |
| `slashbooking.php` | Modify (line 6) | Version → 1.1.0. |
| `src/Plugin.php` | Modify (line 8) | `VERSION` → 1.1.0. |
| `readme.txt` | Modify | Stable tag + changelog + upgrade notice. |
| `CHANGELOG.md` | Modify | 1.1.0 entry. |

---

## Conventions (verified from the codebase — match these exactly)

- Namespace root `Slash\Booking`, files under `src/`, PSR-4. Tests namespace `Slash\Booking\Tests`, files under `tests/`.
- Unit tests run with: `vendor/bin/phpunit --testsuite unit --bootstrap tests/bootstrap.php` (alias `composer test`). `tests/bootstrap.php` requires `vendor/autoload.php` and stubs `__()`, `do_action()`, `WP_REST_Response` (constructor `(mixed $data, int $status)`, getters `get_data()` / `get_status()`).
- Test class shape: `declare(strict_types=1);`, `final class XTest extends \PHPUnit\Framework\TestCase`, methods `test_snake_case()`, assertions `self::assertX(...)`.
- HTTP-injection pattern to mirror (`src/PublicFront/TurnstileVerifier.php`): constructor takes a nullable `?Closure $httpPost` defaulting to a private `defaultPost()` that calls `wp_remote_post`. Tests pass a closure so no network is hit.
- `Encryption` API (used in `GoogleClientBuilder`/`AdminGoogleController`): `encrypt(string): string`, `decrypt(string): string`.
- `OAuthState` API (verified, full source read): `issue(int $userId, ?int $now = null): string`, `verify(string $token, ?int $now = null): ?int`. Built in `RestRouter` with `new OAuthState((string) get_option('sb_decision_secret'))`.
- `GoogleAccount::connect(string $label, string $calendarId, string $refreshTokenEnc, string $accessTokenEnc, DateTimeImmutable $expiresAt): self`; `rotateAccessToken(string $accessTokenEnc, DateTimeImmutable $expiresAt): void`; `assignId(int): void`; `setCalendarId(string): void`; getters `id()`, `label()`, `calendarId()`, `refreshTokenEnc()`, `accessTokenEnc()`, `expiresAt()`. Constructed via private ctor through `connect()` / `fromRow(array)`.
- `GoogleAccountRepository`: `save(GoogleAccount): void`, `findSingle(): ?GoogleAccount`, `findById(int): ?GoogleAccount`, `delete(int): void`. `toRow()` maps domain → DB columns; `fromRow()` lives on `GoogleAccount`.
- REST namespace constant: `Plugin::REST_NAMESPACE = 'slashbooking/v1'`.
- DO NOT run `composer cs:fix` (user preference — PHPCS is custom-configured).

---

## Tasks

### Task 1: Create the `BrokerUnavailable` exception

**Files:**
- Create: `src/Google/Exceptions/BrokerUnavailable.php`
- Test: `tests/Unit/Google/BrokerClientTest.php` (created later in Task 4; this task ships only the class — it has no behavior of its own, mirroring the existing zero-body `OAuthFailure`)

The existing exceptions in this directory (`OAuthFailure.php`) are empty bodies extending `\RuntimeException`. We follow the same pattern. Because these are pure marker classes with no behavior, they are exercised by the `BrokerClient` tests (Task 4) rather than dedicated tests — consistent with `OAuthFailure` which has no standalone test.

- [ ] **Step 1: Write the class**
```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Google\Exceptions;

/**
 * Broker is unreachable (network failure or 5xx). Retryable: the caller MUST
 * keep the Google account connected and MUST NOT delete tokens.
 */
final class BrokerUnavailable extends \RuntimeException
{
}
```

- [ ] **Step 2: Verify it is syntactically valid**
Run: `php -l src/Google/Exceptions/BrokerUnavailable.php`
Expected: `No syntax errors detected in src/Google/Exceptions/BrokerUnavailable.php`

- [ ] **Step 3: Commit**
```
git add src/Google/Exceptions/BrokerUnavailable.php
git commit -m "feat(google): add BrokerUnavailable exception (retryable broker errors)"
```

---

### Task 2: Create the `TokenRevoked` exception

**Files:**
- Create: `src/Google/Exceptions/TokenRevoked.php`

Same marker-class pattern. Exercised by the `BrokerClient` and `GoogleClientBuilder` tests.

- [ ] **Step 1: Write the class**
```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Google\Exceptions;

/**
 * Google returned invalid_grant (refresh_token permanently revoked). The caller
 * MUST mark the account "reconnection required" and MUST keep the booking data.
 */
final class TokenRevoked extends \RuntimeException
{
}
```

- [ ] **Step 2: Verify it is syntactically valid**
Run: `php -l src/Google/Exceptions/TokenRevoked.php`
Expected: `No syntax errors detected in src/Google/Exceptions/TokenRevoked.php`

- [ ] **Step 3: Commit**
```
git add src/Google/Exceptions/TokenRevoked.php
git commit -m "feat(google): add TokenRevoked exception (invalid_grant handling)"
```

---

### Task 3: Add `Config::brokerUrl()` (default + filter)

**Files:**
- Create: `src/Config.php`
- Test: `tests/Unit/Google/ConfigTest.php`

The canonical default is `https://slashbox.fr/slashbooking/api`, overridable via the `sb_broker_url` filter. We expose a static helper so `RestRouter` and `BrokerClient` callers share one source of truth. `apply_filters` is a WP function; we stub it inside the test (the unit bootstrap only stubs `__`/`do_action`/`WP_REST_Response`, so this test must define `apply_filters` itself before requiring the class).

- [ ] **Step 1: Write the failing test**
```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Google;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Config;

final class ConfigTest extends TestCase
{
    public function test_default_broker_url(): void
    {
        $GLOBALS['__sb_filter'] = null;
        self::assertSame('https://slashbox.fr/slashbooking/api', Config::brokerUrl());
    }

    public function test_filter_overrides_broker_url(): void
    {
        $GLOBALS['__sb_filter'] = static fn (string $hook, string $value): string =>
            $hook === 'sb_broker_url' ? 'https://example.test/api' : $value;
        self::assertSame('https://example.test/api', Config::brokerUrl());
    }

    public function test_trailing_slash_is_stripped(): void
    {
        $GLOBALS['__sb_filter'] = static fn (string $hook, string $value): string =>
            'https://example.test/api/';
        self::assertSame('https://example.test/api', Config::brokerUrl());
    }
}

// Stub apply_filters for the unit suite (real WP function unavailable here).
namespace Slash\Booking;

if (!function_exists('Slash\Booking\apply_filters')) {
    function apply_filters(string $hook, mixed $value): mixed
    {
        $cb = $GLOBALS['__sb_filter'] ?? null;
        return $cb ? $cb($hook, $value) : $value;
    }
}
```

> Note: the `Config` class below calls the unqualified `apply_filters(...)`. Inside namespace `Slash\Booking`, PHP first resolves to `Slash\Booking\apply_filters` if it exists (which the test defines), otherwise falls back to the global `\apply_filters` provided by WordPress at runtime. This is the standard testable-WP-call pattern and requires no production-code change.

- [ ] **Step 2: Run test to verify it fails**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Google/ConfigTest.php`
Expected failure: `Error: Class "Slash\Booking\Config" not found`

- [ ] **Step 3: Write minimal implementation**
```php
<?php
declare(strict_types=1);

namespace Slash\Booking;

/**
 * Plugin-wide configuration helpers.
 */
final class Config
{
    /** Default SlashBooking broker base URL (no trailing slash). */
    public const BROKER_URL_DEFAULT = 'https://slashbox.fr/slashbooking/api';

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
```

- [ ] **Step 4: Run test to verify it passes**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Google/ConfigTest.php`
Expected: `OK (3 tests, 3 assertions)`

- [ ] **Step 5: Commit**
```
git add src/Config.php tests/Unit/Google/ConfigTest.php
git commit -m "feat(config): add Config::brokerUrl with sb_broker_url filter and default"
```

---

### Task 4: Create `BrokerClient` — `validateLicense`

**Files:**
- Create: `src/Google/BrokerClient.php`
- Test: `tests/Unit/Google/BrokerClientTest.php`

`BrokerClient` mirrors `TurnstileVerifier`'s injectable-HTTP pattern. The injected callable receives `(string $url, array $body)` and returns `array{status:int, json:mixed}` so the client can branch on HTTP status. We start with `validateLicense` (simplest), then add the other methods in Tasks 5-7 to the same file with their own tests appended.

Canonical signatures (from the cross-plan contract):
- `__construct(string $baseUrl, string $license)`
- `validateLicense(string $siteUrl): array` → POST `/license/validate` `{license, site}` → `{valid, plan, expires}`

- [ ] **Step 1: Write the failing test**
```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Google;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Google\BrokerClient;

final class BrokerClientTest extends TestCase
{
    /**
     * @param array{status:int, json:mixed} $response
     * @return array{0: BrokerClient, 1: array{url:string, body:array<string,mixed>}}
     */
    private function clientCapturing(array $response): array
    {
        $captured = ['url' => '', 'body' => []];
        $http = function (string $url, array $body) use (&$captured, $response): array {
            $captured['url']  = $url;
            $captured['body'] = $body;
            return $response;
        };
        $client = new BrokerClient('https://broker.test/api', 'LIC-123', $http);
        return [$client, &$captured];
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
}
```

- [ ] **Step 2: Run test to verify it fails**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Google/BrokerClientTest.php`
Expected failure: `Error: Class "Slash\Booking\Google\BrokerClient" not found`

- [ ] **Step 3: Write minimal implementation**
```php
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
final class BrokerClient
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
```

> The `OAuthFailure` / `BrokerUnavailable` / `TokenRevoked` imports are added now so later tasks (5-7) only add methods, not imports. PHPStan level 8 tolerates unused imports? No — it does not flag unused `use` for classes referenced in later tasks within the same commit chain, but to be safe these three are all referenced by the end of Task 7. If running `composer stan` between tasks flags them, that is expected until Task 7 completes; do not remove them.

- [ ] **Step 4: Run test to verify it passes**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Google/BrokerClientTest.php`
Expected: `OK (2 tests, ...)`

- [ ] **Step 5: Commit**
```
git add src/Google/BrokerClient.php tests/Unit/Google/BrokerClientTest.php
git commit -m "feat(google): BrokerClient.validateLicense via injectable HTTP"
```

---

### Task 5: `BrokerClient::startUrl`

**Files:**
- Modify: `src/Google/BrokerClient.php` (add `startUrl` method after `validateLicense`)
- Test: `tests/Unit/Google/BrokerClientTest.php` (append two methods)

`startUrl(string $returnUrl, string $n): string` → POST `/oauth/start` `{license, return, n}` → `{auth_url}`. On 401 → `OAuthFailure` (invalid license is a config error, surfaced to the admin). On network/0/5xx → `BrokerUnavailable`. On 400 invalid_return → `OAuthFailure`.

- [ ] **Step 1: Write the failing test (append to BrokerClientTest)**
```php
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
```

- [ ] **Step 2: Run test to verify it fails**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Google/BrokerClientTest.php`
Expected failure: `Error: Call to undefined method Slash\Booking\Google\BrokerClient::startUrl()`

- [ ] **Step 3: Write minimal implementation (add to BrokerClient, after validateLicense)**
```php
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
     * Throw BrokerUnavailable for "no response" (0) and 5xx server errors.
     *
     * @param int $status
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
```

- [ ] **Step 4: Run test to verify it passes**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Google/BrokerClientTest.php`
Expected: `OK (5 tests, ...)`

- [ ] **Step 5: Commit**
```
git add src/Google/BrokerClient.php tests/Unit/Google/BrokerClientTest.php
git commit -m "feat(google): BrokerClient.startUrl maps 4xx/5xx to OAuthFailure/BrokerUnavailable"
```

---

### Task 6: `BrokerClient::claim`

**Files:**
- Modify: `src/Google/BrokerClient.php` (add `claim` after `startUrl`)
- Test: `tests/Unit/Google/BrokerClientTest.php` (append)

`claim(string $claimCode): array` → POST `/oauth/claim` `{license, claim}` → `{refresh_token, access_token, expires_in, scope, email, calendar_id}`. 404 `claim_not_found` → `OAuthFailure` (the user must restart the flow). 401 → `OAuthFailure`. network/5xx → `BrokerUnavailable`.

- [ ] **Step 1: Write the failing test (append)**
```php
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
```

- [ ] **Step 2: Run test to verify it fails**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Google/BrokerClientTest.php`
Expected failure: `Error: Call to undefined method Slash\Booking\Google\BrokerClient::claim()`

- [ ] **Step 3: Write minimal implementation (add after startUrl)**
```php
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
```

- [ ] **Step 4: Run test to verify it passes**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Google/BrokerClientTest.php`
Expected: `OK (8 tests, ...)`

- [ ] **Step 5: Commit**
```
git add src/Google/BrokerClient.php tests/Unit/Google/BrokerClientTest.php
git commit -m "feat(google): BrokerClient.claim returns one-time token bundle"
```

---

### Task 7: `BrokerClient::refresh`

**Files:**
- Modify: `src/Google/BrokerClient.php` (add `refresh` after `claim`)
- Test: `tests/Unit/Google/BrokerClientTest.php` (append)

`refresh(string $refreshToken): array` → POST `/oauth/refresh` `{license, refresh_token}` → `{access_token, expires_in}`. 401 `token_revoked` → `TokenRevoked`. 401 invalid_license → `OAuthFailure`. network/5xx (incl. 502 google_error) → `BrokerUnavailable`.

- [ ] **Step 1: Write the failing test (append)**
```php
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
```

- [ ] **Step 2: Run test to verify it fails**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Google/BrokerClientTest.php`
Expected failure: `Error: Call to undefined method Slash\Booking\Google\BrokerClient::refresh()`

- [ ] **Step 3: Write minimal implementation (add after claim)**
```php
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
```

- [ ] **Step 4: Run test to verify it passes**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Google/BrokerClientTest.php`
Expected: `OK (12 tests, ...)`

- [ ] **Step 5: Commit**
```
git add src/Google/BrokerClient.php tests/Unit/Google/BrokerClientTest.php
git commit -m "feat(google): BrokerClient.refresh maps token_revoked to TokenRevoked"
```

---

### Task 8: Create `FakeBrokerClient` test double

**Files:**
- Create: `tests/Unit/Support/FakeBrokerClient.php`

A test double mirroring `FakeCalendarGateway` (same directory, no PHPUnit dependency, plain class with public scriptable behavior). It is NOT a subclass of `BrokerClient` (that class is `final`); the consumers (`AdminGoogleController`, `GoogleClientBuilder`) will type-hint `BrokerClient` directly, so to keep them testable we will (in those tasks) accept a small interface. Define that interface here too.

> Rationale: `BrokerClient` is `final`, so we introduce a one-method-per-call interface `BrokerGateway` that both `BrokerClient` and `FakeBrokerClient` implement. This is the minimal seam needed for TDD of the controllers. It does NOT change the canonical `BrokerClient` signatures.

- [ ] **Step 1: Create the interface**
Create `src/Google/BrokerGateway.php`:
```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Google;

/**
 * Seam over BrokerClient so controllers/builders can be unit-tested with a fake.
 * BrokerClient (final) implements this; FakeBrokerClient implements it for tests.
 */
interface BrokerGateway
{
    public function startUrl(string $returnUrl, string $n): string;

    /**
     * @return array{refresh_token:string, access_token:string, expires_in:int, scope:string, email:string, calendar_id:string}
     */
    public function claim(string $claimCode): array;

    /**
     * @return array{access_token:string, expires_in:int}
     */
    public function refresh(string $refreshToken): array;

    /**
     * @return array{valid: bool, plan: ?string, expires: ?string}
     */
    public function validateLicense(string $siteUrl): array;
}
```

- [ ] **Step 2: Make `BrokerClient` implement it**
In `src/Google/BrokerClient.php`, change the class declaration line
```php
final class BrokerClient
```
to
```php
final class BrokerClient implements BrokerGateway
```

- [ ] **Step 3: Create the fake**
Create `tests/Unit/Support/FakeBrokerClient.php`:
```php
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
```

- [ ] **Step 4: Verify it loads (run the broker tests still pass with the interface)**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Google/BrokerClientTest.php`
Expected: `OK (12 tests, ...)` (interface adds no behavior; class unchanged otherwise)

- [ ] **Step 5: Commit**
```
git add src/Google/BrokerGateway.php src/Google/BrokerClient.php tests/Unit/Support/FakeBrokerClient.php
git commit -m "feat(google): add BrokerGateway seam + FakeBrokerClient test double"
```

---

### Task 9: Add `reconnect_required` flag to `GoogleAccount`

**Files:**
- Modify: `src/Domain/GoogleAccount.php`
- Test: `tests/Unit/Domain/GoogleAccountReconnectTest.php`

The domain needs to record "reconnection required" without losing data. Add a private `bool $reconnectRequired` (default `false`), wire it through the private constructor, `connect()`, and `fromRow()`, and add `markReconnectRequired()` / `clearReconnectRequired()` / `reconnectRequired()`. `connect()` must default to `false`. `fromRow()` reads `reconnect_required` (int 0/1).

- [ ] **Step 1: Write the failing test**
```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\GoogleAccount;

final class GoogleAccountReconnectTest extends TestCase
{
    private function makeAccount(): GoogleAccount
    {
        return GoogleAccount::connect(
            label: 'Commercial',
            calendarId: 'primary',
            refreshTokenEnc: 'r',
            accessTokenEnc: 'a',
            expiresAt: new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')),
        );
    }

    public function test_new_account_does_not_require_reconnect(): void
    {
        self::assertFalse($this->makeAccount()->reconnectRequired());
    }

    public function test_mark_and_clear_reconnect(): void
    {
        $a = $this->makeAccount();
        $a->markReconnectRequired();
        self::assertTrue($a->reconnectRequired());
        $a->clearReconnectRequired();
        self::assertFalse($a->reconnectRequired());
    }

    public function test_from_row_reads_reconnect_required_column(): void
    {
        $a = GoogleAccount::fromRow([
            'id'                       => 5,
            'label'                    => 'Commercial',
            'calendar_id'             => 'primary',
            'oauth_refresh_token_enc' => 'r',
            'oauth_access_token_enc'  => 'a',
            'oauth_expires_at'        => '2030-01-01 00:00:00',
            'watch_channel_id'        => null,
            'watch_resource_id'       => null,
            'watch_token_secret'      => null,
            'watch_expires_at'        => null,
            'sync_token'              => null,
            'last_full_sync_at'       => null,
            'reconnect_required'      => 1,
            'created_at'              => '2025-01-01 00:00:00',
            'updated_at'              => '2025-01-01 00:00:00',
        ]);
        self::assertTrue($a->reconnectRequired());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Domain/GoogleAccountReconnectTest.php`
Expected failure: `Error: Call to undefined method Slash\Booking\Domain\GoogleAccount::reconnectRequired()`

- [ ] **Step 3a: Add the constructor parameter**
In `src/Domain/GoogleAccount.php`, in the private constructor parameter list, add a new property right after `private ?DateTimeImmutable $lastFullSyncAt,` and before `private DateTimeImmutable $createdAt,`:
```php
        private bool $reconnectRequired,
```

- [ ] **Step 3b: Default it in `connect()`**
In `connect()`, in the `new self(...)` call, add right after `lastFullSyncAt: null,` and before `createdAt: $now,`:
```php
            reconnectRequired: false,
```

- [ ] **Step 3c: Read it in `fromRow()`**
In `fromRow()`, in the `new self(...)` call, add right after `lastFullSyncAt: $parse($row['last_full_sync_at'] ?? null),` and before `createdAt: $parse((string) $row['created_at']) ?? new DateTimeImmutable('now', $utc),`:
```php
            reconnectRequired: (int) ($row['reconnect_required'] ?? 0) === 1,
```

- [ ] **Step 3d: Add the methods**
In `src/Domain/GoogleAccount.php`, add these three methods right after the `markFullSyncedAt()` method and before the getters block (the `public function id(): ?int ...` lines):
```php
    public function markReconnectRequired(): void
    {
        $this->reconnectRequired = true;
        $this->touch();
    }

    public function clearReconnectRequired(): void
    {
        $this->reconnectRequired = false;
        $this->touch();
    }
```

- [ ] **Step 3e: Add the getter**
In `src/Domain/GoogleAccount.php`, in the getters block, add right after `public function lastFullSyncAt(): ?DateTimeImmutable { return $this->lastFullSyncAt; }`:
```php
    public function reconnectRequired(): bool { return $this->reconnectRequired; }
```

- [ ] **Step 4: Run test to verify it passes**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Domain/GoogleAccountReconnectTest.php`
Expected: `OK (3 tests, ...)`

Also run the existing domain test to confirm no regression:
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Domain/GoogleAccountTest.php`
Expected: `OK` (the new ctor arg has a default path via `connect()`/`fromRow()`, both updated).

- [ ] **Step 5: Commit**
```
git add src/Domain/GoogleAccount.php tests/Unit/Domain/GoogleAccountReconnectTest.php
git commit -m "feat(domain): GoogleAccount.reconnectRequired flag (keeps data on token revoke)"
```

---

### Task 10: Persist `reconnect_required` in repository + schema

**Files:**
- Modify: `src/Persistence/GoogleAccountRepository.php` (`toRow()`)
- Modify: `src/Persistence/Migrator.php` (the `sb_google_accounts` `CREATE TABLE` DDL — verified at ~lines 85-100)
- Modify: `src/Plugin.php` (`DB_VERSION` constant, line 10)

`GoogleAccount::fromRow()` already reads the column (Task 9). Now persist it in `toRow()` and add the column to the schema (`Migrator.php`, the only file that holds the DDL — there is no `Schema.php`) so fresh installs and `dbDelta` upgrades create it.

- [ ] **Step 1: Add the column to `toRow()`**
In `src/Persistence/GoogleAccountRepository.php`, inside the array returned by `toRow()`, add right after `'last_full_sync_at'        => $fmt($a->lastFullSyncAt()),` and before `'created_at'               => $fmt($a->createdAt()),`:
```php
            'reconnect_required'       => $a->reconnectRequired() ? 1 : 0,
```

- [ ] **Step 2: Add the column to the DDL in `Migrator.php`**
In `src/Persistence/Migrator.php`, in the `CREATE TABLE {$prefix}sb_google_accounts (...)` block, add the column immediately after the `last_full_sync_at DATETIME NULL,` line and before `created_at DATETIME NOT NULL,`. Match the existing indentation (12 spaces) and uppercase-type style:
```
            reconnect_required TINYINT(1) NOT NULL DEFAULT 0,
```
The resulting fragment must read (verified against the current file — note `sync_token` is `VARCHAR(255)`):
```
                sync_token VARCHAR(255) NULL,
                last_full_sync_at DATETIME NULL,
                reconnect_required TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id)
```

- [ ] **Step 3: Bump `DB_VERSION` so `dbDelta` ALTERs existing installs**
In `src/Plugin.php`, change line 10 from `public const DB_VERSION = 1;` to `public const DB_VERSION = 2;`. (`Migrator::migrate` diffs the SQL via `dbDelta`; the comment at the top of the table block explicitly says to bump `Plugin::DB_VERSION` when a table definition changes.)

- [ ] **Step 4: Verify no syntax errors**
Run: `php -l src/Persistence/GoogleAccountRepository.php && php -l src/Persistence/Migrator.php && php -l src/Plugin.php`
Expected: `No syntax errors detected` for all three.

- [ ] **Step 5: Run the integration repository test (if integration env available)**
Run: `vendor/bin/phpunit --testsuite integration --bootstrap tests/Integration/bootstrap-wp.php --filter GoogleAccountRepositoryTest`
Expected: `OK`. If the integration suite cannot run in this environment (no WP test DB), note it and rely on the unit `fromRow`/`toRow` coverage from Task 9 plus the final full integration run in Task 21.

- [ ] **Step 6: Commit**
```
git add src/Persistence/GoogleAccountRepository.php src/Persistence/Migrator.php src/Plugin.php
git commit -m "feat(persistence): persist reconnect_required on sb_google_accounts + bump DB_VERSION"
```

---

### Task 11: Repurpose `AdminGoogleSettingsController` to license

**Files:**
- Modify: `src/Http/AdminGoogleSettingsController.php` (whole file)
- Test: `tests/Unit/Http/AdminGoogleSettingsControllerTest.php`

GET returns `{has_license, license_status, plan, expires, redirect_uri}` (never the key in clear). POST sanitizes + stores `sb_license_key`, validates via the injected `BrokerGateway->validateLicense(site_url())`, returns the resulting status. The controller now takes a `BrokerGateway` in its constructor.

The unit test stubs the WP option/URL functions in the test file's `Slash\Booking\Http` namespace (the unit bootstrap does not provide `get_option`, `update_option`, `sanitize_text_field`, `rest_url`, `site_url`). This mirrors how WordPress functions are made testable: define namespaced overrides that take precedence over the global ones.

- [ ] **Step 1: Write the failing test**
```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Http {
    // Namespaced WP-function stubs (resolved before the global ones).
    $GLOBALS['__sb_options'] = [];

    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['__sb_options'][$name] ?? $default;
    }
    function update_option(string $name, mixed $value, bool $autoload = true): bool
    {
        $GLOBALS['__sb_options'][$name] = $value;
        return true;
    }
    function sanitize_text_field(string $s): string
    {
        return trim($s);
    }
    function rest_url(string $path = ''): string
    {
        return 'https://my-site.test/wp-json/' . ltrim($path, '/');
    }
    function site_url(): string
    {
        return 'https://my-site.test';
    }
}

namespace Slash\Booking\Tests\Unit\Http {

    use PHPUnit\Framework\TestCase;
    use Slash\Booking\Http\AdminGoogleSettingsController;
    use Slash\Booking\Tests\Unit\Support\FakeBrokerClient;
    use WP_REST_Request;

    final class AdminGoogleSettingsControllerTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['__sb_options'] = [];
        }

        public function test_read_reports_no_license_when_unset(): void
        {
            $ctrl = new AdminGoogleSettingsController(new FakeBrokerClient());
            $data = $ctrl->read()->get_data();
            self::assertFalse($data['has_license']);
            self::assertSame('absent', $data['license_status']);
            self::assertArrayNotHasKey('license_key', $data);
            self::assertStringContainsString('oauth/callback', $data['redirect_uri']);
        }

        public function test_write_stores_sanitized_key_and_validates(): void
        {
            $broker = new FakeBrokerClient();
            $broker->licenseResult = ['valid' => true, 'plan' => 'pro', 'expires' => '2027-01-01'];
            $ctrl = new AdminGoogleSettingsController($broker);

            $req = new WP_REST_Request();
            $req->set_param('license_key', '  ABC-123  ');
            $data = $ctrl->write($req)->get_data();

            self::assertSame('ABC-123', $GLOBALS['__sb_options']['sb_license_key']);
            self::assertTrue($data['has_license']);
            self::assertSame('valid', $data['license_status']);
            self::assertSame('pro', $data['plan']);
            self::assertSame('2027-01-01', $data['expires']);
        }

        public function test_write_reports_invalid_license(): void
        {
            $broker = new FakeBrokerClient();
            $broker->licenseResult = ['valid' => false, 'plan' => null, 'expires' => null];
            $ctrl = new AdminGoogleSettingsController($broker);

            $req = new WP_REST_Request();
            $req->set_param('license_key', 'BAD');
            $data = $ctrl->write($req)->get_data();

            self::assertSame('invalid', $data['license_status']);
            self::assertTrue($data['has_license']); // key is stored even if invalid
        }
    }
}
```

> The unit bootstrap stubs `WP_REST_Response` but not `WP_REST_Request`. Add a minimal `WP_REST_Request` stub to `tests/bootstrap.php` in this task (Step 3b) so this and Task 12 can construct requests.

- [ ] **Step 2: Run test to verify it fails**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Http/AdminGoogleSettingsControllerTest.php`
Expected failure: `Error: Class "WP_REST_Request" not found` (then, after Step 3b, the real failure: `Too few arguments to function ...::__construct()` because the controller has no constructor yet).

- [ ] **Step 3a: Rewrite the controller**
Replace the entire contents of `src/Http/AdminGoogleSettingsController.php` with:
```php
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
```

- [ ] **Step 3b: Add `WP_REST_Request` stub to the unit bootstrap**
In `tests/bootstrap.php`, after the existing `WP_REST_Response` stub block, add:
```php
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string, mixed> */
        private array $params = [];

        public function set_param(string $key, mixed $value): void
        {
            $this->params[$key] = $value;
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            private string $code = '',
            private string $message = '',
            private mixed $data = null
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(): mixed
        {
            return $this->data;
        }
    }
}
```

> `WP_REST_Response` in the bootstrap currently ignores the 3rd constructor arg (headers) used by the 302 redirect in `AdminGoogleController::callback`. Update its constructor signature in the same step to accept and store headers so Task 12 can assert on them:
> In `tests/bootstrap.php`, replace the `WP_REST_Response` constructor
> ```php
>         public function __construct(mixed $data = null, int $status = 200)
>         {
>             $this->data = $data;
>             $this->status = $status;
>         }
> ```
> with
> ```php
>         /** @var array<string, string> */
>         private array $headers = [];
>
>         public function __construct(mixed $data = null, int $status = 200, array $headers = [])
>         {
>             $this->data = $data;
>             $this->status = $status;
>             $this->headers = $headers;
>         }
>
>         public function get_headers(): array
>         {
>             return $this->headers;
>         }
> ```

- [ ] **Step 4: Run test to verify it passes**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Http/AdminGoogleSettingsControllerTest.php`
Expected: `OK (3 tests, ...)`

- [ ] **Step 5: Commit**
```
git add src/Http/AdminGoogleSettingsController.php tests/Unit/Http/AdminGoogleSettingsControllerTest.php tests/bootstrap.php
git commit -m "feat(http): repurpose AdminGoogleSettingsController to license read/write+validate"
```

---

### Task 12: Rewire `AdminGoogleController` start + callback to the broker

**Files:**
- Modify: `src/Http/AdminGoogleController.php` (constructor, `start()`, `callback()`, imports)
- Test: `tests/Integration/AdminGoogleControllerTest.php` is the existing home, but we add a focused UNIT test that does not require the WP DB: `tests/Unit/Http/AdminGoogleConnectTest.php`

`start()` now: requires a valid license (`get_option('sb_license_status') === 'valid'`), issues nonce `n = OAuthState->issue(userId)`, builds `auth_url = broker->startUrl(callbackUrl, n)`. `callback()` now: reads `sb_claim` + `n` params, verifies `n` via `OAuthState->verify`, calls `broker->claim($claim)`, encrypts + stores `GoogleAccount` (calendar_id from claim), clears any reconnect flag, redirects.

The controller's constructor currently takes `OAuthClient $oauthClient`. Replace that parameter with `BrokerGateway $broker`.

- [ ] **Step 1: Write the failing test**
```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Http {
    if (!function_exists('Slash\Booking\Http\get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            return $GLOBALS['__sb_options'][$name] ?? $default;
        }
    }
    if (!function_exists('Slash\Booking\Http\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return $GLOBALS['__sb_uid'] ?? 0;
        }
    }
    if (!function_exists('Slash\Booking\Http\rest_url')) {
        function rest_url(string $path = ''): string
        {
            return 'https://my-site.test/wp-json/' . ltrim($path, '/');
        }
    }
    if (!function_exists('Slash\Booking\Http\admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://my-site.test/wp-admin/' . ltrim($path, '/');
        }
    }
    if (!function_exists('Slash\Booking\Http\current_user_can')) {
        function current_user_can(string $cap): bool
        {
            return true;
        }
    }
}

namespace Slash\Booking\Tests\Unit\Http {

    use DateTimeImmutable;
    use DateTimeZone;
    use PHPUnit\Framework\TestCase;
    use Slash\Booking\Domain\GoogleAccount;
    use Slash\Booking\Google\Encryption;
    use Slash\Booking\Google\OAuthState;
    use Slash\Booking\Google\WatchChannelManager;
    use Slash\Booking\Google\GoogleClientBuilder;
    use Slash\Booking\Http\AdminGoogleController;
    use Slash\Booking\Persistence\GoogleAccountRepository;
    use Slash\Booking\Tests\Unit\Support\FakeBrokerClient;
    use WP_Error;
    use WP_REST_Request;

    final class AdminGoogleConnectTest extends TestCase
    {
        private function encryption(): Encryption
        {
            // 32-byte key for sodium secretbox.
            return new Encryption(str_repeat('k', 32));
        }

        /** @return GoogleAccountRepository&\PHPUnit\Framework\MockObject\MockObject */
        private function repo(): GoogleAccountRepository
        {
            $repo = $this->getMockBuilder(GoogleAccountRepository::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['findSingle', 'save', 'delete', 'findById'])
                ->getMock();
            $repo->method('findSingle')->willReturn(null);
            return $repo;
        }

        private function controller(FakeBrokerClient $broker, OAuthState $state): AdminGoogleController
        {
            $clientBuilder = $this->getMockBuilder(GoogleClientBuilder::class)
                ->disableOriginalConstructor()->getMock();
            $watch = $this->getMockBuilder(WatchChannelManager::class)
                ->disableOriginalConstructor()->getMock();
            return new AdminGoogleController(
                $this->repo(),
                $broker,
                $state,
                $this->encryption(),
                $watch,
                $clientBuilder,
                static function (int $id): void {},
            );
        }

        public function test_start_blocked_without_valid_license(): void
        {
            $GLOBALS['__sb_options'] = ['sb_license_status' => 'absent'];
            $GLOBALS['__sb_uid'] = 7;
            $ctrl = $this->controller(new FakeBrokerClient(), new OAuthState('secret'));
            $res = $ctrl->start(new WP_REST_Request());
            self::assertInstanceOf(WP_Error::class, $res);
            self::assertSame('license_required', $res->get_error_code());
        }

        public function test_start_returns_broker_auth_url_when_licensed(): void
        {
            $GLOBALS['__sb_options'] = ['sb_license_status' => 'valid'];
            $GLOBALS['__sb_uid'] = 7;
            $broker = new FakeBrokerClient();
            $broker->authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?state=signed';
            $ctrl = $this->controller($broker, new OAuthState('secret'));
            $res = $ctrl->start(new WP_REST_Request());
            self::assertSame('https://accounts.google.com/o/oauth2/v2/auth?state=signed', $res->get_data()['auth_url']);
            self::assertCount(1, $broker->startCalls);
        }

        public function test_callback_rejects_bad_nonce(): void
        {
            $GLOBALS['__sb_options'] = [];
            $ctrl = $this->controller(new FakeBrokerClient(), new OAuthState('secret'));
            $req = new WP_REST_Request();
            $req->set_param('sb_claim', 'CLAIM');
            $req->set_param('n', 'forged');
            $res = $ctrl->callback($req);
            self::assertInstanceOf(WP_Error::class, $res);
            self::assertSame('invalid_state', $res->get_error_code());
        }

        public function test_callback_stores_account_from_claim(): void
        {
            $GLOBALS['__sb_options'] = [];
            $state = new OAuthState('secret');
            $n = $state->issue(7);

            $repo = $this->repo();
            $saved = null;
            $repo->method('save')->willReturnCallback(function (GoogleAccount $a) use (&$saved): void {
                $saved = $a;
                if ($a->id() === null) {
                    $a->assignId(1);
                }
            });

            $broker = new FakeBrokerClient();
            $broker->claimBundle['calendar_id'] = 'cal@example.test';

            $clientBuilder = $this->getMockBuilder(GoogleClientBuilder::class)
                ->disableOriginalConstructor()->getMock();
            $watch = $this->getMockBuilder(WatchChannelManager::class)
                ->disableOriginalConstructor()->getMock();
            $ctrl = new AdminGoogleController(
                $repo,
                $broker,
                $state,
                $this->encryption(),
                $watch,
                $clientBuilder,
                static function (int $id): void {},
            );

            $req = new WP_REST_Request();
            $req->set_param('sb_claim', 'CLAIM');
            $req->set_param('n', $n);
            $res = $ctrl->callback($req);

            self::assertSame(302, $res->get_status());
            self::assertNotNull($saved);
            self::assertSame('cal@example.test', $saved->calendarId());
            self::assertCount(1, $broker->claimCalls);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Http/AdminGoogleConnectTest.php`
Expected failure: `TypeError: ...AdminGoogleController::__construct(): Argument #2 ($oauthClient) must be of type Slash\Booking\Google\OAuthClient, Slash\Booking\Tests\Unit\Support\FakeBrokerClient given` (or "undefined method start with license gate").

- [ ] **Step 3a: Swap the constructor dependency + imports**
In `src/Http/AdminGoogleController.php`:
Replace the import line
```php
use Slash\Booking\Google\OAuthClient;
```
with
```php
use Slash\Booking\Google\BrokerGateway;
```
And replace the constructor parameter
```php
        private readonly OAuthClient $oauthClient,
```
with
```php
        private readonly BrokerGateway $broker,
```

- [ ] **Step 3b: Rewrite `start()`**
Replace the whole `start()` method body with:
```php
    public function start(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $userId = get_current_user_id();
        if ($userId === 0) {
            return new WP_Error('not_logged_in', __('Not logged in', 'slashbooking'), ['status' => 401]);
        }
        if ((string) get_option('sb_license_status', 'absent') !== 'valid') {
            return new WP_Error(
                'license_required',
                __('Une clé de licence valide est requise pour connecter Google Calendar.', 'slashbooking'),
                ['status' => 403]
            );
        }

        $n           = $this->state->issue($userId);
        $callbackUrl = rest_url(Plugin::REST_NAMESPACE . '/admin/google/oauth/callback');

        try {
            $url = $this->broker->startUrl($callbackUrl, $n);
        } catch (\Slash\Booking\Google\Exceptions\BrokerUnavailable $e) {
            return new WP_Error('broker_unavailable', $e->getMessage(), ['status' => 503]);
        } catch (\Slash\Booking\Google\Exceptions\OAuthFailure $e) {
            return new WP_Error('oauth_failed', $e->getMessage(), ['status' => 502]);
        }

        return new WP_REST_Response(['auth_url' => $url], 200);
    }
```

- [ ] **Step 3c: Rewrite `callback()`**
Replace the whole `callback()` method body with:
```php
    public function callback(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $claim = (string) $req->get_param('sb_claim');
        $n     = (string) $req->get_param('n');

        if ($claim === '' || $this->state->verify($n) === null) {
            return new WP_Error('invalid_state', __('Invalid or expired OAuth state.', 'slashbooking'), ['status' => 403]);
        }

        try {
            $tokens = $this->broker->claim($claim);
        } catch (\Slash\Booking\Google\Exceptions\BrokerUnavailable $e) {
            return new WP_Error('broker_unavailable', $e->getMessage(), ['status' => 503]);
        } catch (\Slash\Booking\Google\Exceptions\OAuthFailure $e) {
            return new WP_Error('oauth_failed', $e->getMessage(), ['status' => 502]);
        }

        $existing  = $this->accounts->findSingle();
        $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify('+' . $tokens['expires_in'] . ' seconds');

        $refreshEnc = $this->encryption->encrypt($tokens['refresh_token']);
        $accessEnc  = $this->encryption->encrypt($tokens['access_token']);

        $label      = $existing?->label() ?? 'Commercial';
        $calendarId = $tokens['calendar_id'] !== '' ? $tokens['calendar_id'] : ($existing?->calendarId() ?? 'primary');

        $account = GoogleAccount::connect(
            label: $label,
            calendarId: $calendarId,
            refreshTokenEnc: $refreshEnc,
            accessTokenEnc: $accessEnc,
            expiresAt: $expiresAt,
        );
        if ($existing !== null && $existing->id() !== null) {
            $account->assignId($existing->id());
        }
        $account->clearReconnectRequired();

        $this->accounts->save($account);

        $redirect = admin_url('admin.php?page=slashbooking#/google?connected=1');
        return new WP_REST_Response(null, 302, ['Location' => $redirect]);
    }
```

> `__()` is stubbed in the unit bootstrap. `current_user_can`, `get_option`, `get_current_user_id`, `rest_url`, `admin_url` are stubbed in the test file's `Slash\Booking\Http` namespace block. `Encryption` is real (sodium); the test passes a 32-byte key. `GoogleAccountRepository` and `WatchChannelManager` and `GoogleClientBuilder` are mocked with disabled constructors.

- [ ] **Step 4: Run test to verify it passes**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Http/AdminGoogleConnectTest.php`
Expected: `OK (4 tests, ...)`

- [ ] **Step 5: Commit**
```
git add src/Http/AdminGoogleController.php tests/Unit/Http/AdminGoogleConnectTest.php
git commit -m "feat(http): AdminGoogleController start/callback go through the broker + license gate"
```

---

### Task 13: Refresh via broker in `GoogleClientBuilder`

**Files:**
- Modify: `src/Google/GoogleClientBuilder.php` (whole file)
- Test: `tests/Unit/Google/GoogleClientBuilderRefreshTest.php`

`buildGateway()` must stop setting `client_secret` (and `client_id`). `refresh()` now calls `broker->refresh($plainRefreshToken)`, rotates the access token, and on `BrokerUnavailable` rethrows (retryable; do NOT clear tokens), on `TokenRevoked` marks the account `reconnectRequired`, persists, and rethrows. The constructor gains a `BrokerGateway $broker` dependency.

Because `buildGateway()` instantiates a real `Google\Client` and `GoogleApiCalendarGateway`, the unit test targets the refresh logic via a dedicated public-seam: extract the refresh decision into a testable method `refreshAccessToken(GoogleAccount): array{access_token:string, expires_in:int}` that `refresh()` calls. We test `refreshAccessToken` directly (no `Google\Client` needed).

- [ ] **Step 1: Write the failing test**
```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Google;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\GoogleAccount;
use Slash\Booking\Google\Encryption;
use Slash\Booking\Google\Exceptions\BrokerUnavailable;
use Slash\Booking\Google\Exceptions\TokenRevoked;
use Slash\Booking\Google\GoogleClientBuilder;
use Slash\Booking\Persistence\GoogleAccountRepository;
use Slash\Booking\Tests\Unit\Support\FakeBrokerClient;

final class GoogleClientBuilderRefreshTest extends TestCase
{
    private function encryption(): Encryption
    {
        return new Encryption(str_repeat('k', 32));
    }

    private function expiredAccount(Encryption $enc): GoogleAccount
    {
        return GoogleAccount::connect(
            label: 'Commercial',
            calendarId: 'primary',
            refreshTokenEnc: $enc->encrypt('plain-refresh'),
            accessTokenEnc: $enc->encrypt('old-access'),
            expiresAt: new DateTimeImmutable('-1 hour', new DateTimeZone('UTC')),
        );
    }

    /** @return GoogleAccountRepository&\PHPUnit\Framework\MockObject\MockObject */
    private function repo(): GoogleAccountRepository
    {
        return $this->getMockBuilder(GoogleAccountRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save', 'findSingle', 'findById', 'delete'])
            ->getMock();
    }

    public function test_refresh_rotates_access_token_via_broker(): void
    {
        $enc     = $this->encryption();
        $account = $this->expiredAccount($enc);
        $broker  = new FakeBrokerClient();
        $broker->refreshResult = ['access_token' => 'fresh-access', 'expires_in' => 3600];
        $repo = $this->repo();
        $repo->expects(self::once())->method('save');

        $builder = new GoogleClientBuilder($enc, $repo, $broker);
        $out = $builder->refreshAccessToken($account);

        self::assertSame('fresh-access', $out['access_token']);
        self::assertSame(3600, $out['expires_in']);
        self::assertSame(['plain-refresh'], $broker->refreshCalls);
        // Rotated and persisted: decrypting the stored access token yields the fresh one.
        self::assertSame('fresh-access', $enc->decrypt($account->accessTokenEnc()));
    }

    public function test_broker_unavailable_is_rethrown_and_tokens_kept(): void
    {
        $enc     = $this->encryption();
        $account = $this->expiredAccount($enc);
        $broker  = new FakeBrokerClient();
        $broker->throwOnRefresh = new BrokerUnavailable('down');
        $repo = $this->repo();
        $repo->expects(self::never())->method('save');

        $builder = new GoogleClientBuilder($enc, $repo, $broker);

        $this->expectException(BrokerUnavailable::class);
        try {
            $builder->refreshAccessToken($account);
        } finally {
            // Tokens untouched.
            self::assertSame('old-access', $enc->decrypt($account->accessTokenEnc()));
            self::assertFalse($account->reconnectRequired());
        }
    }

    public function test_token_revoked_marks_reconnect_and_persists(): void
    {
        $enc     = $this->encryption();
        $account = $this->expiredAccount($enc);
        $broker  = new FakeBrokerClient();
        $broker->throwOnRefresh = new TokenRevoked('revoked');
        $repo = $this->repo();
        $repo->expects(self::once())->method('save');

        $builder = new GoogleClientBuilder($enc, $repo, $broker);

        $this->expectException(TokenRevoked::class);
        try {
            $builder->refreshAccessToken($account);
        } finally {
            self::assertTrue($account->reconnectRequired());
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Google/GoogleClientBuilderRefreshTest.php`
Expected failure: `TypeError: ...GoogleClientBuilder::__construct() ... Argument #3` (constructor has only 2 params) or `undefined method refreshAccessToken()`.

- [ ] **Step 3: Rewrite `GoogleClientBuilder`**
Replace the entire contents of `src/Google/GoogleClientBuilder.php` with:
```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Google;

use DateTimeImmutable;
use DateTimeZone;
use Google\Client as GoogleClient;
use Slash\Booking\Domain\GoogleAccount;
use Slash\Booking\Google\Exceptions\BrokerUnavailable;
use Slash\Booking\Google\Exceptions\TokenRevoked;
use Slash\Booking\Persistence\GoogleAccountRepository;

final class GoogleClientBuilder
{
    public const SCOPE = 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.readonly';

    public function __construct(
        private readonly Encryption $encryption,
        private readonly GoogleAccountRepository $accounts,
        private readonly BrokerGateway $broker,
    ) {
    }

    public function buildGateway(GoogleAccount $account): CalendarGateway
    {
        // No client_id / client_secret: the plugin ships no Google credentials.
        // Calendar API calls are authorized by the Bearer access token only.
        $client = new GoogleClient();
        $client->addScope(self::SCOPE);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($account->accessTokenExpired($now->modify('+30 seconds'))) {
            $tokens = $this->refreshAccessToken($account);
            $client->setAccessToken([
                'access_token' => $tokens['access_token'],
                'expires_in'   => $tokens['expires_in'],
                'created'      => $now->getTimestamp(),
            ]);
        } else {
            $client->setAccessToken([
                'access_token' => $this->encryption->decrypt($account->accessTokenEnc()),
                'expires_in'   => max(0, $account->expiresAt()->getTimestamp() - $now->getTimestamp()),
                'created'      => $now->getTimestamp(),
            ]);
        }

        return new GoogleApiCalendarGateway($client);
    }

    /**
     * Refresh the access token through the broker, rotate + persist it.
     *
     * @return array{access_token:string, expires_in:int}
     * @throws BrokerUnavailable broker down (retryable) — tokens are kept intact
     * @throws TokenRevoked      refresh token revoked — account flagged, data kept
     */
    public function refreshAccessToken(GoogleAccount $account): array
    {
        $refresh = $this->encryption->decrypt($account->refreshTokenEnc());

        try {
            $tokens = $this->broker->refresh($refresh);
        } catch (BrokerUnavailable $e) {
            // Retryable: do NOT clear tokens, do NOT persist. Caller will retry.
            throw $e;
        } catch (TokenRevoked $e) {
            $account->markReconnectRequired();
            $this->accounts->save($account);
            throw $e;
        }

        $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify('+' . $tokens['expires_in'] . ' seconds');
        $account->rotateAccessToken($this->encryption->encrypt($tokens['access_token']), $expiresAt);
        $this->accounts->save($account);

        return $tokens;
    }
}
```

> The old code referenced `OAuthClient::SCOPE`. We move the scope constant onto `GoogleClientBuilder::SCOPE` (identical value) since `OAuthClient` is deleted in Task 14. Search for other `OAuthClient::SCOPE` usages: `grep -rn "OAuthClient::SCOPE" src/`. If any exist outside `GoogleClientBuilder`, update them to `GoogleClientBuilder::SCOPE` in this task and note them in the commit.

- [ ] **Step 4: Run test to verify it passes**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Google/GoogleClientBuilderRefreshTest.php`
Expected: `OK (3 tests, ...)`

- [ ] **Step 5: Commit**
```
git add src/Google/GoogleClientBuilder.php tests/Unit/Google/GoogleClientBuilderRefreshTest.php
git commit -m "feat(google): GoogleClientBuilder refreshes via broker; handles retry vs revoke"
```

---

### Task 14: Delete `OAuthClient` + its test

**Files:**
- Delete: `src/Google/OAuthClient.php`
- Delete: `tests/Unit/Google/OAuthClientTest.php`

The class is now unused: `RestRouter` is the only remaining production reference (rewired in Task 15), and `GoogleClientBuilder`/`AdminGoogleController` no longer import it.

- [ ] **Step 1: Confirm no remaining references except RestRouter**
Run: `grep -rn "OAuthClient" src/ tests/`
Expected: matches only in `src/Http/RestRouter.php` (handled next task) and `tests/Unit/Google/OAuthClientTest.php` (about to be deleted). If matches appear elsewhere, update those call sites to `BrokerClient`/`GoogleClientBuilder::SCOPE` before deleting and note them in the commit.

- [ ] **Step 2: Delete the files**
Run: `git rm src/Google/OAuthClient.php tests/Unit/Google/OAuthClientTest.php`

- [ ] **Step 3: Verify the deletion did not break parsing of the unit suite (RestRouter not in unit suite, so this just confirms classmap)**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/Google/`
Expected: `OK` for the Google unit tests (BrokerClient, Config, GoogleClientBuilderRefresh, plus existing EncryptionTest/OAuthStateTest/etc.). No "class not found: OAuthClient".

- [ ] **Step 4: Commit**
```
git commit -m "refactor(google): delete OAuthClient (exchange/refresh now broker-side)"
```

---

### Task 15: Rewire `RestRouter`

**Files:**
- Modify: `src/Http/RestRouter.php` (lines 148-184)

Replace the `OAuthClient` construction (current lines 152-156) with a `BrokerClient`. Pass the broker into both `AdminGoogleController` (replacing the `$oauthClient` arg) and `AdminGoogleSettingsController` (which now requires it). Add the broker base URL from `Config::brokerUrl()` and the license from `get_option('sb_license_key')`. `GoogleClientBuilder` now needs the broker too.

- [ ] **Step 1: Replace the OAuth/broker wiring block**
In `src/Http/RestRouter.php`, replace these lines (currently lines 151-157):
```php
        $oauthState  = new \Slash\Booking\Google\OAuthState((string) get_option('sb_decision_secret'));
        $oauthClient = new \Slash\Booking\Google\OAuthClient(
            clientId: (string) get_option('sb_google_client_id', ''),
            clientSecret: (string) get_option('sb_google_client_secret', ''),
            redirectUri: rest_url(\Slash\Booking\Plugin::REST_NAMESPACE . '/admin/google/oauth/callback'),
        );
        $clientBuilder = new \Slash\Booking\Google\GoogleClientBuilder($encryption, $accounts);
```
with:
```php
        $oauthState  = new \Slash\Booking\Google\OAuthState((string) get_option('sb_decision_secret'));
        $broker      = new \Slash\Booking\Google\BrokerClient(
            baseUrl: \Slash\Booking\Config::brokerUrl(),
            license: (string) get_option('sb_license_key', ''),
        );
        $clientBuilder = new \Slash\Booking\Google\GoogleClientBuilder($encryption, $accounts, $broker);
```

- [ ] **Step 2: Pass the broker into AdminGoogleController**
In the `new AdminGoogleController(...)` call (currently lines 171-179), replace the second argument `$oauthClient,` with `$broker,`:
```php
        (new AdminGoogleController(
            $accounts,
            $broker,
            $oauthState,
            $encryption,
            $watchMgr,
            $clientBuilder,
            $enqueuePull,
        ))->registerRoutes();
```

- [ ] **Step 3: Pass the broker into AdminGoogleSettingsController**
Replace the line (currently line 184):
```php
        (new AdminGoogleSettingsController())->registerRoutes();
```
with:
```php
        (new AdminGoogleSettingsController($broker))->registerRoutes();
```

- [ ] **Step 4: Verify syntax + that the unused-builder reference in Plugin.php still compiles**
Run: `php -l src/Http/RestRouter.php`
Expected: `No syntax errors detected in src/Http/RestRouter.php`

Then check `src/Plugin.php` (line ~185) which also constructs `GoogleClientBuilder($encryption, $accounts)` with only 2 args — it will now fatal. Search: `grep -rn "new Google\\\\GoogleClientBuilder\|new \\\\Slash\\\\Booking\\\\Google\\\\GoogleClientBuilder\|GoogleClientBuilder(" src/`. For every construction site, add the broker as the 3rd argument. In `src/Plugin.php`, before line 185, add a broker and pass it:
```php
        $broker = new Google\BrokerClient(
            baseUrl: Config::brokerUrl(),
            license: (string) get_option('sb_license_key', ''),
        );
        $clientBuilder = new Google\GoogleClientBuilder($encryption, $accounts, $broker);
```
(Replace the existing `$clientBuilder = new Google\GoogleClientBuilder($encryption, $accounts);` line.)
Run again: `php -l src/Plugin.php`
Expected: `No syntax errors detected in src/Plugin.php`

- [ ] **Step 5: Commit**
```
git add src/Http/RestRouter.php src/Plugin.php
git commit -m "refactor(http): wire BrokerClient into RestRouter, controllers, and Plugin"
```

---

### Task 16: Create the `BrokerMigration` routine

**Files:**
- Create: `src/Migration/BrokerMigration.php`
- Test: `tests/Unit/MigrationTest.php`

Idempotent upgrade: when migrating to the broker version, delete the obsolete options `sb_google_client_id` / `sb_google_client_secret`; if a `GoogleAccount` exists, mark it `reconnectRequired` and persist (so the daily refresh stops cleanly and the UI can show the notice). Tracks completion via option `sb_broker_migrated` so it runs once.

- [ ] **Step 1: Write the failing test**
```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Migration {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['__sb_options'][$name] ?? $default;
    }
    function update_option(string $name, mixed $value, bool $autoload = true): bool
    {
        $GLOBALS['__sb_options'][$name] = $value;
        return true;
    }
    function delete_option(string $name): bool
    {
        unset($GLOBALS['__sb_options'][$name]);
        return true;
    }
}

namespace Slash\Booking\Tests\Unit {

    use PHPUnit\Framework\TestCase;
    use Slash\Booking\Domain\GoogleAccount;
    use Slash\Booking\Migration\BrokerMigration;
    use Slash\Booking\Persistence\GoogleAccountRepository;
    use DateTimeImmutable;
    use DateTimeZone;

    final class MigrationTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['__sb_options'] = [
                'sb_google_client_id'     => 'old-id',
                'sb_google_client_secret' => 'old-secret',
            ];
        }

        /** @return GoogleAccountRepository&\PHPUnit\Framework\MockObject\MockObject */
        private function repo(?GoogleAccount $existing): GoogleAccountRepository
        {
            $repo = $this->getMockBuilder(GoogleAccountRepository::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['findSingle', 'save', 'delete', 'findById'])
                ->getMock();
            $repo->method('findSingle')->willReturn($existing);
            return $repo;
        }

        public function test_deletes_old_google_oauth_options(): void
        {
            (new BrokerMigration($this->repo(null)))->run();
            self::assertArrayNotHasKey('sb_google_client_id', $GLOBALS['__sb_options']);
            self::assertArrayNotHasKey('sb_google_client_secret', $GLOBALS['__sb_options']);
            self::assertSame('1', (string) $GLOBALS['__sb_options']['sb_broker_migrated']);
        }

        public function test_flags_existing_account_for_reconnect(): void
        {
            $account = GoogleAccount::connect(
                label: 'Commercial',
                calendarId: 'primary',
                refreshTokenEnc: 'r',
                accessTokenEnc: 'a',
                expiresAt: new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')),
            );
            $account->assignId(1);

            $repo = $this->repo($account);
            $repo->expects(self::once())->method('save');

            (new BrokerMigration($repo))->run();
            self::assertTrue($account->reconnectRequired());
        }

        public function test_is_idempotent(): void
        {
            $GLOBALS['__sb_options']['sb_broker_migrated'] = '1';
            $repo = $this->repo(null);
            $repo->expects(self::never())->method('save');
            (new BrokerMigration($repo))->run();
            // Old options would already be gone in a real run; here they remain
            // untouched because run() short-circuits.
            self::assertSame('old-id', $GLOBALS['__sb_options']['sb_google_client_id']);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/MigrationTest.php`
Expected failure: `Error: Class "Slash\Booking\Migration\BrokerMigration" not found`

- [ ] **Step 3: Write minimal implementation**
```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Migration;

use Slash\Booking\Persistence\GoogleAccountRepository;

/**
 * One-time migration to the broker-based OAuth model.
 *
 * - Deletes the obsolete Google OAuth options (sb_google_client_id/secret).
 * - Flags any existing GoogleAccount as "reconnection required" (existing
 *   refresh tokens were issued by the client's own GCP project and cannot be
 *   refreshed by the broker). Booking data is kept untouched.
 *
 * Guarded by the sb_broker_migrated option so it runs at most once.
 */
final class BrokerMigration
{
    public function __construct(private readonly GoogleAccountRepository $accounts)
    {
    }

    public function run(): void
    {
        if ((string) get_option('sb_broker_migrated', '') === '1') {
            return;
        }

        delete_option('sb_google_client_id');
        delete_option('sb_google_client_secret');

        $account = $this->accounts->findSingle();
        if ($account !== null) {
            $account->markReconnectRequired();
            $this->accounts->save($account);
        }

        update_option('sb_broker_migrated', '1', true);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**
Run: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Unit/MigrationTest.php`
Expected: `OK (3 tests, ...)`

- [ ] **Step 5: Commit**
```
git add src/Migration/BrokerMigration.php tests/Unit/MigrationTest.php
git commit -m "feat(migration): BrokerMigration deletes old OAuth options + flags reconnect"
```

---

### Task 17: Run the migration on boot + admin notice

**Files:**
- Modify: `src/Plugin.php` (`register()`)

Call `BrokerMigration::run()` once on boot (after `$accounts` is constructed, which is at ~line 170). Register an `admin_notices` callback that shows "Reconnectez Google Calendar (1 clic)" when a connected account has `reconnectRequired() === true`.

- [ ] **Step 1: Add the migration call**
In `src/Plugin.php`, right after the line `$accounts    = new Persistence\GoogleAccountRepository($wpdb);` (~line 170), add:
```php
        (new Migration\BrokerMigration($accounts))->run();
```

- [ ] **Step 2: Add the admin notice**
In `src/Plugin.php`, immediately after the migration call from Step 1, add:
```php
        add_action('admin_notices', static function () use ($accounts): void {
            if (!current_user_can('manage_options')) {
                return;
            }
            $account = $accounts->findSingle();
            if ($account === null || !$account->reconnectRequired()) {
                return;
            }
            $url = admin_url('admin.php?page=slashbooking#/google');
            printf(
                '<div class="notice notice-warning"><p><strong>SlashBooking :</strong> %s <a href="%s">%s</a></p></div>',
                esc_html__('La connexion Google Calendar doit être renouvelée (1 clic).', 'slashbooking'),
                esc_url($url),
                esc_html__('Reconnecter maintenant', 'slashbooking')
            );
        });
```

> `esc_html__`, `esc_url`, `admin_url`, `current_user_can` are WP runtime functions — fine in production. This code path is not unit-tested (matches the existing `admin_notices` closure for the encryption-key fallback in the same file, which is also untested). The migration logic itself is covered by Task 16.

- [ ] **Step 3: Verify syntax**
Run: `php -l src/Plugin.php`
Expected: `No syntax errors detected in src/Plugin.php`

- [ ] **Step 4: Run the full unit suite (no regressions)**
Run: `composer test`
Expected: `OK` (all unit tests green).

- [ ] **Step 5: Commit**
```
git add src/Plugin.php
git commit -m "feat(plugin): run BrokerMigration on boot + reconnect admin notice"
```

---

### Task 18: Update `api.js` (license endpoints)

**Files:**
- Modify: `src/Admin/react-app/src/api.js`

Replace `fetchGoogleSettings` / `saveGoogleSettings` (which posted `client_id`/`client_secret`) with `fetchLicenseStatus` / `saveLicense`. Keep the same endpoint path `admin/google/settings` (the controller was repurposed in Task 11). Keep every other function (calendars, watch, pull, diagnostics, services, templates) untouched.

- [ ] **Step 1: Replace the two functions**
In `src/Admin/react-app/src/api.js`, replace:
```js
export async function fetchGoogleSettings() {
	return apiFetch( { path: 'admin/google/settings' } );
}

export async function saveGoogleSettings( { clientId, clientSecret } ) {
	return apiFetch( {
		path: 'admin/google/settings',
		method: 'POST',
		data: { client_id: clientId, client_secret: clientSecret },
	} );
}
```
with:
```js
export async function fetchLicenseStatus() {
	return apiFetch( { path: 'admin/google/settings' } );
}

export async function saveLicense( licenseKey ) {
	return apiFetch( {
		path: 'admin/google/settings',
		method: 'POST',
		data: { license_key: licenseKey },
	} );
}
```

- [ ] **Step 2: Confirm no other JS file imports the removed names**
Run: `grep -rn "fetchGoogleSettings\|saveGoogleSettings" src/Admin/react-app/src/`
Expected: matches only in `GooglePage.jsx` (rewritten in Task 19). If matched elsewhere, update those imports too.

- [ ] **Step 3: Commit**
```
git add src/Admin/react-app/src/api.js
git commit -m "feat(admin-ui): api.js exposes fetchLicenseStatus/saveLicense (drop client secret)"
```

---

### Task 19: Rewrite `GooglePage.jsx` connect card + delete the wizard

**Files:**
- Modify: `src/Admin/react-app/src/GooglePage.jsx`
- Delete: `src/Admin/react-app/src/GoogleSetupWizard.jsx`

Replace the "Configuration OAuth" card (client_id/secret form) and the `GoogleSetupWizard` block with a "Licence SlashBooking" card: a license key field, a save button (validates via the broker), a status line, and the "Connecter Google Calendar" button enabled only when `settings.license_status === 'valid'`. Keep the calendar selection, watch/pull, and diagnostics sections unchanged.

- [ ] **Step 1: Update imports**
In `src/Admin/react-app/src/GooglePage.jsx`, replace the import block:
```js
import {
	fetchGoogleStatus,
	startGoogleOAuth,
	disconnectGoogle,
	fetchGoogleSettings,
	saveGoogleSettings,
	fetchGoogleDiagnostics,
	startWatch,
	stopWatch,
	forcePullNow,
	fetchGoogleCalendars,
	setGoogleCalendar,
} from './api';
import GoogleSetupWizard from './GoogleSetupWizard';
```
with:
```js
import {
	fetchGoogleStatus,
	startGoogleOAuth,
	disconnectGoogle,
	fetchLicenseStatus,
	saveLicense,
	fetchGoogleDiagnostics,
	startWatch,
	stopWatch,
	forcePullNow,
	fetchGoogleCalendars,
	setGoogleCalendar,
} from './api';
```

- [ ] **Step 2: Swap state + data loading**
Replace the state line:
```js
	const [ secret, setSecret ] = useState( '' );
```
with:
```js
	const [ licenseKey, setLicenseKey ] = useState( '' );
	const [ licenseMsg, setLicenseMsg ] = useState( '' );
```
In `reload()`, replace `fetchGoogleSettings()` with `fetchLicenseStatus()`:
```js
			const [ st, sg ] = await Promise.all( [
				fetchGoogleStatus(),
				fetchLicenseStatus(),
			] );
```

- [ ] **Step 3: Replace the saveSettings handler**
Replace the `saveSettings` function:
```js
	const saveSettings = async () => {
		try {
			await saveGoogleSettings( {
				clientId: settings.client_id,
				clientSecret: secret,
			} );
			setSecret( '' );
			await reload();
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		}
	};
```
with:
```js
	const onSaveLicense = async () => {
		setLicenseMsg( '' );
		try {
			const res = await saveLicense( licenseKey );
			setLicenseKey( '' );
			setSettings( res );
			setLicenseMsg(
				res.license_status === 'valid'
					? __( 'Licence valide ✓', 'slashbooking' )
					: __( 'Licence invalide ou expirée.', 'slashbooking' )
			);
			await reload();
		} catch ( e ) {
			setLicenseMsg(
				__( 'Erreur : ', 'slashbooking' ) + ( e.message ?? String( e ) )
			);
		}
	};
```

- [ ] **Step 4: Replace the OAuth-config card + wizard with a license card**
Replace this block (the wizard render + the "Configuration OAuth" `<Card>`):
```jsx
			{ /* Show the guided setup only while the account is NOT connected. */ }
			{ settings && ! status?.connected && (
				<GoogleSetupWizard redirectUri={ settings.redirect_uri } />
			) }

			{ settings && (
				<Card>
					<CardHeader>
						<h2>
							{ __( 'Configuration OAuth', 'slashbooking' ) }
						</h2>
					</CardHeader>
					<CardBody>
						<p>
							<strong>
								{ __(
									'URI de redirection (à coller dans Google Cloud Console) :',
									'slashbooking'
								) }
							</strong>
						</p>
						<div className="sb-redirect-uri-box">
							<code>{ settings.redirect_uri }</code>
							<Button
								variant="secondary"
								size="small"
								onClick={ async () => {
									try {
										await navigator.clipboard.writeText( settings.redirect_uri );
										setPanelMsg( __( '✓ URI copiée dans le presse-papiers.', 'slashbooking' ) );
										setTimeout( () => setPanelMsg( '' ), 1500 );
									} catch ( e ) { /* noop */ }
								} }
							>
								📋 { __( 'Copier', 'slashbooking' ) }
							</Button>
						</div>
						<TextControl
							label={ __( 'Client ID', 'slashbooking' ) }
							value={ settings.client_id }
							onChange={ ( v ) =>
								setSettings( { ...settings, client_id: v } )
							}
						/>
						<TextControl
							label={
								settings.has_client_secret
									? __(
											'Client Secret (déjà défini — saisir pour remplacer)',
											'slashbooking'
									  )
									: __( 'Client Secret', 'slashbooking' )
							}
							type="password"
							value={ secret }
							onChange={ setSecret }
						/>
						<Button variant="primary" onClick={ saveSettings }>
							{ __( 'Enregistrer', 'slashbooking' ) }
						</Button>
					</CardBody>
				</Card>
			) }
```
with:
```jsx
			{ settings && (
				<Card>
					<CardHeader>
						<h2>{ __( 'Licence SlashBooking', 'slashbooking' ) }</h2>
					</CardHeader>
					<CardBody>
						<p style={ { marginTop: 0, color: '#475569' } }>
							{ __(
								'La connexion Google Calendar se fait en 1 clic via le service SlashBooking. Aucun projet Google Cloud à créer. Saisis ta clé de licence pour activer la connexion.',
								'slashbooking'
							) }
						</p>
						<p>
							<strong>{ __( 'Statut : ', 'slashbooking' ) }</strong>
							{ settings.license_status === 'valid' &&
								__( 'Licence valide ✓', 'slashbooking' ) }
							{ settings.license_status === 'invalid' &&
								__( 'Licence invalide ou expirée', 'slashbooking' ) }
							{ settings.license_status === 'absent' &&
								__( 'Aucune licence', 'slashbooking' ) }
							{ settings.license_status === 'unknown' &&
								__( 'Licence non vérifiée', 'slashbooking' ) }
							{ settings.plan && ` — ${ settings.plan }` }
						</p>
						<TextControl
							label={
								settings.has_license
									? __( 'Clé de licence (saisir pour remplacer)', 'slashbooking' )
									: __( 'Clé de licence', 'slashbooking' )
							}
							value={ licenseKey }
							onChange={ setLicenseKey }
						/>
						<Button
							variant="primary"
							onClick={ onSaveLicense }
							disabled={ ! licenseKey }
						>
							{ __( 'Enregistrer la licence', 'slashbooking' ) }
						</Button>
						{ licenseMsg && (
							<Notice
								status={
									licenseMsg.startsWith( 'Erreur' ) ||
									settings.license_status === 'invalid'
										? 'error'
										: 'success'
								}
								isDismissible={ false }
								style={ { marginTop: '12px' } }
							>
								{ licenseMsg }
							</Notice>
						) }
					</CardBody>
				</Card>
			) }
```

- [ ] **Step 5: Gate the connect button on a valid license**
Replace the "not connected" branch of the Google Calendar card:
```jsx
						) : (
							<>
								<p>
									{ __(
										'Aucun calendrier Google connecté.',
										'slashbooking'
									) }
								</p>
								{ ( ! settings?.client_id || ! settings?.has_client_secret ) ? (
									<>
										<Notice status="warning" isDismissible={ false }>
											{ __(
												'Renseigne le Client ID + Secret dans la carte « Configuration OAuth » ci-dessus avant de te connecter.',
												'slashbooking'
											) }
										</Notice>
										<Button variant="primary" disabled style={ { marginTop: 10 } }>
											{ __(
												'Connecter mon Google Calendar',
												'slashbooking'
											) }
										</Button>
									</>
								) : (
									<Button variant="primary" onClick={ connect }>
										{ __(
											'Connecter mon Google Calendar',
											'slashbooking'
										) }
									</Button>
								) }
							</>
						) }
```
with:
```jsx
						) : (
							<>
								<p>
									{ __(
										'Aucun calendrier Google connecté.',
										'slashbooking'
									) }
								</p>
								{ settings?.license_status !== 'valid' ? (
									<>
										<Notice status="warning" isDismissible={ false }>
											{ __(
												'Saisis une clé de licence valide dans la carte « Licence SlashBooking » ci-dessus avant de te connecter.',
												'slashbooking'
											) }
										</Notice>
										<Button variant="primary" disabled style={ { marginTop: 10 } }>
											{ __(
												'Connecter Google Calendar',
												'slashbooking'
											) }
										</Button>
									</>
								) : (
									<Button variant="primary" onClick={ connect }>
										{ __(
											'Connecter Google Calendar',
											'slashbooking'
										) }
									</Button>
								) }
							</>
						) }
```

- [ ] **Step 6: Delete the wizard**
Run: `git rm src/Admin/react-app/src/GoogleSetupWizard.jsx`

- [ ] **Step 7: Confirm no dangling references**
Run: `grep -rn "GoogleSetupWizard\|client_id\|has_client_secret\|client_secret" src/Admin/react-app/src/`
Expected: no matches. If `client_id` matches survive in `GooglePage.jsx`, remove them (the new license card does not use them).

- [ ] **Step 8: Commit**
```
git add src/Admin/react-app/src/GooglePage.jsx
git commit -m "feat(admin-ui): license card + license-gated Google connect; drop OAuth wizard"
```

---

### Task 20: Build the React app

**Files:**
- Generated: `assets/dist/index.jsx.js` (+ `.css`, `.asset.php`) via `npm run build`

The admin SPA must be rebuilt so the new license UI ships in the bundle. `npm run build` uses `@wordpress/scripts`.

- [ ] **Step 1: Build**
Run: `npm run build`
Expected: build completes with `webpack ... compiled successfully` (or `compiled with N warnings` but exit code 0). It regenerates `assets/dist/index.jsx.js`, `assets/dist/index.jsx.css`, `assets/dist/index.jsx.asset.php`.

- [ ] **Step 2: Confirm the bundle changed**
Run: `git status --short assets/dist/`
Expected: `assets/dist/index.jsx.js` (and friends) show as modified.

- [ ] **Step 3: Commit**
```
git add assets/dist/
git commit -m "build(admin-ui): rebuild bundle with broker license UI"
```

---

### Task 21: Full verification (tests + PHPStan)

**Files:** none (verification only)

- [ ] **Step 1: Run the full unit suite**
Run: `composer test`
Expected: `OK (NNN tests, ...)` with zero failures, zero errors. (`OAuthClientTest` is gone; `BrokerClientTest`, `ConfigTest`, `AdminGoogleSettingsControllerTest`, `AdminGoogleConnectTest`, `GoogleClientBuilderRefreshTest`, `GoogleAccountReconnectTest`, `MigrationTest` are green.)

- [ ] **Step 2: Run the integration suite (if the WP test DB is available)**
Run: `composer test:integration`
Expected: `OK`. If `AdminGoogleControllerTest` (integration) constructed the old `OAuthClient`, update it: it must now build `AdminGoogleController` with a `FakeBrokerClient` (or `BrokerClient` with an injected HTTP closure) in place of `OAuthClient`, and seed `update_option('sb_license_status', 'valid')` before calling `start()`. Make that edit, rerun, expect `OK`. If the integration environment is unavailable here, note it explicitly and rely on the unit coverage; the integration run is the release gate in CI.

- [ ] **Step 3: Run PHPStan**
Run: `vendor/bin/phpstan analyse --memory-limit=512M`
Expected: `[OK] No errors`. If it reports unused `use OAuthFailure` in `BrokerClient`, confirm it is referenced (it is, in `startUrl`/`claim`/`refresh`). Fix any genuine level-8 issues (e.g., array shape mismatches) before proceeding. Do NOT run `cs:fix`.

- [ ] **Step 4: Commit any fixes from Step 2/3**
```
git add -A
git commit -m "test: adapt integration tests + satisfy phpstan for broker OAuth"
```
(If no changes were needed, skip this commit.)

---

### Task 22: Version bump to 1.1.0 + docs

**Files:**
- Modify: `slashbooking.php` (line 6)
- Modify: `src/Plugin.php` (line 8)
- Modify: `readme.txt` (Stable tag + Changelog + Upgrade Notice)
- Modify: `CHANGELOG.md` (new entry)

This is a major feature (OAuth model change with a breaking reconnect) → minor bump per SemVer and the spec (§7).

- [ ] **Step 1: Bump the plugin header**
In `slashbooking.php`, change line 6 from `* Version: 1.0.24` to `* Version: 1.1.0`.

- [ ] **Step 2: Bump the constant**
In `src/Plugin.php`, change line 8 from `public const VERSION = '1.0.24';` to `public const VERSION = '1.1.0';`.

- [ ] **Step 3: Update `readme.txt`**
Change `Stable tag: 1.0.24` to `Stable tag: 1.1.0`. In the `== Changelog ==` section, add at the top:
```
= 1.1.0 =
*Connexion Google en 1 clic via le service SlashBooking.* Plus besoin de créer un projet Google Cloud ni de coller un Client ID/Secret : saisis ta clé de licence, clique « Connecter Google Calendar », c'est tout. Le plugin ne contient plus aucun secret Google. **Migration :** les connexions Google existantes doivent être renouvelées une fois (1 clic) après la mise à jour — une notice te le rappelle. Tes RDV et réglages sont conservés.
```
In `== Upgrade Notice ==`, add at the top:
```
= 1.1.0 =
Nouvelle connexion Google en 1 clic (licence SlashBooking). Après mise à jour, reconnecte Google Calendar une fois depuis l'onglet Google (tes données sont conservées).
```

- [ ] **Step 4: Update `CHANGELOG.md`**
Add a new entry directly under the `---` after the intro and before `## [1.0.24]`:
```
## [1.1.0] — 2026-05-31

### Added

- **Connexion Google Calendar en 1 clic via le broker SlashBooking.** Le plugin ne contient plus de `client_id`/`client_secret` Google. Nouveau `BrokerClient` (start/claim/refresh/validate) parlant en serveur-à-serveur au broker `https://slashbox.fr/slashbooking/api` (base configurable via la constante `SB_BROKER_URL` / le filtre `sb_broker_url`). La connexion est conditionnée à une clé de licence (`sb_license_key`) validée par le broker. Nonce anti-CSRF `n` réutilisant `OAuthState`. Claim one-time : aucun token ne transite par une URL navigateur.

### Changed

- `AdminGoogleSettingsController` gère désormais la licence (`{has_license, license_status, plan, expires}`) au lieu du Client ID/Secret Google.
- `GoogleClientBuilder` rafraîchit l'access token via le broker ; ne pose plus de `client_secret` sur le client Google (les appels Calendar restent directs en Bearer). Gestion `BrokerUnavailable` (retry, tokens conservés) et `TokenRevoked` (compte marqué « reconnexion requise », données conservées).
- UI admin : carte « Licence SlashBooking » + bouton « Connecter Google Calendar » actif uniquement avec une licence valide. Assistant Google Cloud supprimé.

### Removed

- `src/Google/OAuthClient.php` (échange/refresh/authUrl désormais côté broker).
- `GoogleSetupWizard.jsx` (plus de projet Google Cloud à configurer).
- Options `sb_google_client_id` / `sb_google_client_secret` (supprimées à la migration).

### Migration

- Les `refresh_token` existants (émis par le projet GCP du client) ne sont pas rafraîchissables par le broker → les connexions Google existantes cassent à la mise à jour. `BrokerMigration` supprime les anciennes options, marque le compte « reconnexion requise » (données conservées) et affiche une notice admin « Reconnectez Google Calendar (1 clic) ».

---
```

- [ ] **Step 5: Verify the version is consistent everywhere**
Run: `grep -rn "1\.1\.0" slashbooking.php src/Plugin.php readme.txt CHANGELOG.md`
Expected: matches in all four files for the new version.

- [ ] **Step 6: Commit**
```
git add slashbooking.php src/Plugin.php readme.txt CHANGELOG.md
git commit -m "chore(release): bump to 1.1.0 (broker OAuth) + changelog/readme"
```

---

### Task 23: Rebuild the distribution ZIP

**Files:** generated artifact (per existing packaging)

The repo builds a scoped, production ZIP via PHP-Scoper + a packaging step (see `scoper.inc.php`, `.distignore`, and the `release.yml` workflow). Reproduce the existing local packaging command. Do NOT run `cs:fix`.

- [ ] **Step 1: Identify the packaging command**
Run: `grep -rn "php-scoper\|distignore\|zip\|build" composer.json package.json .github/workflows/release.yml | head -40`
Expected: reveals the build pipeline (e.g., a `composer run` script, an `npm run` script, or the steps in `release.yml`). Use the same sequence the release workflow uses (typically: `composer install --no-dev`, scoper run, `npm run build`, then zip honoring `.distignore`).

- [ ] **Step 2: Run the packaging steps**
Execute the commands discovered in Step 1, in order, to produce `slashbooking-1.1.0.zip`. If the release is normally produced by CI on tag push (per `release.yml`), build the ZIP locally with the same commands for validation but let CI produce the canonical artifact on tag.

- [ ] **Step 3: Sanity-check the ZIP contents**
Run: `unzip -l slashbooking-1.1.0.zip | grep -E "OAuthClient|GoogleSetupWizard|BrokerClient" | head`
Expected: `BrokerClient.php` present; `OAuthClient.php` and `GoogleSetupWizard.jsx` ABSENT. Confirm the version inside the zipped `slashbooking.php` is `1.1.0`:
Run: `unzip -p slashbooking-1.1.0.zip slashbooking/slashbooking.php | grep -m1 Version`
Expected: `* Version: 1.1.0`

- [ ] **Step 4: Final full verification before declaring done**
Run: `composer test && vendor/bin/phpstan analyse --memory-limit=512M`
Expected: unit suite `OK`, PHPStan `[OK] No errors`.

- [ ] **Step 5: Commit any packaging metadata changes (if the build modified tracked files)**
```
git add -A
git commit -m "build: package slashbooking 1.1.0 distribution ZIP"
```
(If the ZIP is gitignored and no tracked files changed, skip this commit — the ZIP is a release artifact.)

---

## Cross-plan dependency

This plan depends on the **broker plan** implementing the canonical HTTP API exactly as specified in the shared contract (endpoints, request/response shapes, error codes `invalid_license` / `claim_not_found` / `token_revoked` / `google_error`, the `sb_claim`+`n` redirect on callback, and one-time claim semantics). The `FakeBrokerClient` and `BrokerClientTest` encode that contract on the plugin side; if the broker deviates, both sides break together. The broker must be mounted under `BASE_PATH` matching `SB_BROKER_URL`'s path (`/slashbooking/api`).
