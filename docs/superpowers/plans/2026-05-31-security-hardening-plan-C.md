# Security Hardening (Audit Plan C) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the MEDIUM/LOW defense-in-depth findings from the 2026-05-30 security audit (booking anti-abuse, webhook hardening, GET-side-effect decision links, over-broad capabilities, HMAC domain separation, encryption-key-at-rest notice) without touching the OAuth-callback findings already owned by Plan B.

**Architecture:** SlashBooking is a PSR-12, PHP 8.1+ WordPress plugin (namespace root `Slash\Booking`). REST controllers live under `src/Http/`, domain entities under `src/Domain/`, Google sync under `src/Google/`, admin glue under `src/Admin/`, bootstrap in `src/Plugin.php`. Hardening is applied surgically: rate-limit logic is refactored into pure, injectable helpers so it can be unit-tested; the webhook handler gains expiry + resource-id + dedup checks; decision/cancel GET endpoints render an interstitial that POSTs the mutation; capabilities default to administrator-only with a migration that revokes editor; a context-separated HMAC key derivation is added to `DecisionTokenSigner`/`OAuthState`; and the encryption-key admin notice is escalated.

**Tech Stack:** PHP 8.1, WordPress REST API, PHPUnit (config `phpunit.xml`, run via `composer test` / `vendor/bin/phpunit`), PHPStan level 8 (`phpstan.neon`), PHPCS custom (DO NOT run `cs:fix`).

---

## File Structure

| File | Responsibility |
| --- | --- |
| `src/Http/PublicBookingController.php` | MODIFY (`isRateLimited()` lines 217-231, `createBooking()` IP read line 162). Fail-closed rate limit, IPv6 /64 normalization, global per-minute cap; extract a pure `clientIp()` + `normalizeIpForKey()` helper. |
| `src/Support/ClientIp.php` | CREATE. Pure, framework-free helper: resolve `REMOTE_ADDR`, normalize IPv6 to /64 prefix for keying. Unit-testable without WordPress. |
| `src/Admin/TurnstileNotice.php` | CREATE. Registers an `admin_notices` warning (persistent, `manage_options`-gated) when the public booking form has no Turnstile secret configured. |
| `src/Http/GoogleWebhookController.php` | MODIFY (`handle()` lines 38-105). Add `watchExpiresAt()` validity check, `hash_equals` for channel-id AND resource-id (`X-Goog-Resource-Id`), and a short transient dedup lock before `enqueuePull`. |
| `src/Domain/GoogleAccount.php` | MODIFY. Add `verifyWatchResourceId()` and `watchActive(DateTimeImmutable $now)` helpers (parallel to existing `verifyWatchToken()` at lines 139-145). |
| `src/Http/DecisionController.php` | MODIFY (`registerRoutes()` + `handle()`). GET renders a confirmation interstitial (button that POSTs); POST performs the mutation; fix the `$e->getMessage()` info leak (line 79) -> fixed message + server-side log. |
| `src/Http/PublicCancelController.php` | MODIFY (`registerRoutes()` + `handle()`). GET renders an interstitial confirmation page; POST performs the cancel. |
| `src/Admin/Capabilities.php` | MODIFY. `GRANTED_ROLES = ['administrator']`; add `apply_filters('slashbooking_manage_roles', ...)`; bump `REVISION` to 3; migration removes MANAGE+VIEW from `editor`. |
| `src/Booking/DecisionTokenSigner.php` | MODIFY. Derive a context-separated key (`hash_hmac('sha256', 'slashbooking:decision-token:v1', $secret)`) so the raw `sb_decision_secret` is never used directly. |
| `src/Google/OAuthState.php` | MODIFY. Derive a *different* context-separated key (`...:oauth-state:v1`) from the same root secret, giving domain separation between decision tokens and OAuth state. |
| `src/Plugin.php` | MODIFY (admin-notice block lines 396-403; controller wiring). Escalate the encryption-key notice (persistent, prominent `notice-error`); register `TurnstileNotice`; pass a logger closure into `DecisionController`/`PublicCancelController` if needed. |
| `src/Activator.php` | MODIFY. `ensureDecisionSecret()` stays (root secret). No second option needed (keys are derived), but add a docblock noting domain separation is derived, not stored. |
| `tests/Unit/Support/ClientIpTest.php` | CREATE. Unit tests for IP resolution + IPv6 /64 normalization + empty handling. |
| `tests/Unit/Http/PublicBookingRateLimitTest.php` | CREATE. Unit tests for fail-closed + global cap + per-IP cap via injected transient store + injected IP. |
| `tests/Unit/Domain/GoogleAccountWatchTest.php` | CREATE. Unit tests for `verifyWatchResourceId()` + `watchActive()`. |
| `tests/Integration/GoogleWebhookControllerTest.php` | MODIFY. Add cases: expired watch -> 200 ack-ignore; wrong resource-id -> 200 ack-ignore; dedup within window -> single enqueue. |
| `tests/Integration/DecisionControllerTest.php` | MODIFY. Add cases: GET renders interstitial and does NOT mutate; POST mutates; DomainException renders fixed message (no leak). |
| `tests/Integration/PublicCancelControllerTest.php` | CREATE. GET renders interstitial (no mutation); POST cancels. |
| `tests/Integration/CapabilitiesTest.php` | MODIFY. Editor loses caps after `syncOnUpgrade()`; filter adds a custom role. |
| `tests/Unit/Booking/DecisionTokenSignerTest.php` | MODIFY. Add: same payload signed by signer != raw `hash_hmac` with root secret (proves derivation), and OAuthState with same root secret produces different signatures (domain separation). |
| `slashbooking.php` + `src/Plugin.php` + `readme.txt` + `CHANGELOG.md` | MODIFY at the end. Version bump (see "Version coordination"). |

### Version coordination (READ BEFORE STARTING)

- Plan B (OAuth broker) targets **1.1.0**.
- If Plan C ships **in the same release as Plan B**: do NOT bump version here — fold all C changes into Plan B's `1.1.0` bump (skip Task 14's version edits, keep only the CHANGELOG entries which Plan B will merge).
- If Plan C ships **separately/before Plan B**: bump to **1.0.25** (patch) in Task 14.
- Confirm with the maintainer which case applies before Task 14. The default assumption for this plan is **separate ship -> 1.0.25**.

### Conventions verified in the codebase (do not deviate)

- All files start with `<?php` then `declare(strict_types=1);` then `namespace Slash\Booking\...;`.
- Classes are `final`. Constructor property promotion with `private readonly` is used throughout.
- REST controllers register via `register_rest_route(Plugin::REST_NAMESPACE, '/path', [...])`. `Plugin::REST_NAMESPACE === 'slashbooking/v1'`.
- HTML responses use `new WP_REST_Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8'])` (see `DecisionController::htmlResponse`).
- Existing constant-time secret compare lives in `GoogleAccount::verifyWatchToken()` (`hash_equals`).
- PHPCS suppressions for superglobal reads use the exact comment already in `PublicBookingController`:
  `// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized`
- Tests are PHPUnit. Unit tests (`tests/Unit/...`) construct objects directly and stub WP functions guarded by `if (!function_exists('xxx'))`. Run a single test file with `vendor/bin/phpunit --filter ClassName`.

---

## Tasks

### Task 1: Pure client-IP helper (resolve + IPv6 /64 normalization)

**Files:**
- Create: `src/Support/ClientIp.php`
- Test: `tests/Unit/Support/ClientIpTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Support\ClientIp;

final class ClientIpTest extends TestCase
{
    public function test_returns_empty_string_when_remote_addr_absent(): void
    {
        self::assertSame('', ClientIp::fromServer([]));
    }

    public function test_returns_trimmed_ipv4(): void
    {
        self::assertSame('203.0.113.7', ClientIp::fromServer(['REMOTE_ADDR' => ' 203.0.113.7 ']));
    }

    public function test_ipv4_key_is_the_full_address(): void
    {
        self::assertSame('203.0.113.7', ClientIp::normalizeForKey('203.0.113.7'));
    }

    public function test_ipv6_key_collapses_to_64_bit_prefix(): void
    {
        // Two addresses in the same /64 must collapse to the same key.
        $a = ClientIp::normalizeForKey('2001:db8:abcd:0012:0000:0000:0000:0001');
        $b = ClientIp::normalizeForKey('2001:db8:abcd:0012:ffff:ffff:ffff:ffff');
        self::assertSame($a, $b);
        self::assertSame('2001:db8:abcd:12::/64', $a);
    }

    public function test_different_ipv6_64_prefixes_differ(): void
    {
        $a = ClientIp::normalizeForKey('2001:db8:abcd:0012::1');
        $b = ClientIp::normalizeForKey('2001:db8:abcd:0013::1');
        self::assertNotSame($a, $b);
    }

    public function test_invalid_address_returns_empty_key(): void
    {
        self::assertSame('', ClientIp::normalizeForKey('not-an-ip'));
        self::assertSame('', ClientIp::normalizeForKey(''));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**
  - Command: `vendor/bin/phpunit --filter ClientIpTest`
  - Expected failure: `Error: Class "Slash\Booking\Support\ClientIp" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Support;

/**
 * Pure, framework-free client-IP utilities.
 *
 * NOTE: REMOTE_ADDR is intentionally the ONLY source. Forwarded headers
 * (X-Forwarded-For etc.) are attacker-controlled and are not trusted here.
 */
final class ClientIp
{
    /**
     * @param array<string, mixed> $server Typically $_SERVER.
     */
    public static function fromServer(array $server): string
    {
        $raw = isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : '';
        return trim($raw);
    }

    /**
     * Returns a stable bucket key for rate limiting.
     *
     * IPv4: the full address.
     * IPv6: the /64 network prefix (so rotating the low 64 bits — trivial for
     *       an attacker who controls a /64 — cannot mint fresh buckets).
     * Invalid/empty: '' (caller must treat as "no usable IP").
     */
    public static function normalizeForKey(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return '';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $ip;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return '';
        }

        $bin = inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return '';
        }
        // Keep the high 64 bits, zero the low 64 bits.
        $prefixBin = substr($bin, 0, 8) . str_repeat("\0", 8);
        $prefix = inet_ntop($prefixBin);
        if ($prefix === false) {
            return '';
        }
        return $prefix . '/64';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**
  - Command: `vendor/bin/phpunit --filter ClientIpTest`
  - Expected: `OK (6 tests, ...)`.

- [ ] **Step 5: Commit**
  - `git add src/Support/ClientIp.php tests/Unit/Support/ClientIpTest.php`
  - `git commit -m "feat(security): add pure ClientIp helper with IPv6 /64 normalization"`

---

### Task 2: Refactor rate limiting into a testable, fail-closed limiter with a global cap

**Files:**
- Modify: `src/Http/PublicBookingController.php` (`isRateLimited()` lines 217-231; replace; `createBooking()` IP read at line 162 reuses the helper).
- Test: `tests/Unit/Http/PublicBookingRateLimitTest.php`

The current `isRateLimited()` reads `$_SERVER['REMOTE_ADDR']`, returns `false` (fail-OPEN) when empty, and only does a per-IP 5/min transient. We extract the pure decision into a static `evaluateRateLimit()` that takes the IP key, a transient getter, and a transient setter, so it is unit-testable. `isRateLimited()` becomes a thin wrapper that supplies WP `get_transient`/`set_transient` and `ClientIp`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Http\PublicBookingController;

final class PublicBookingRateLimitTest extends TestCase
{
    /** @var array<string, int> */
    private array $store = [];

    protected function setUp(): void
    {
        $this->store = [];
    }

    private function get(string $key): int
    {
        return $this->store[$key] ?? 0;
    }

    private function set(string $key, int $value): void
    {
        $this->store[$key] = $value;
    }

    public function test_fails_closed_when_no_usable_ip(): void
    {
        // Empty IP key => limited (fail-closed), and the global bucket still counts.
        $limited = PublicBookingController::evaluateRateLimit(
            '',
            fn (string $k): int => $this->get($k),
            fn (string $k, int $v): void => $this->set($k, $v),
        );
        self::assertTrue($limited);
    }

    public function test_allows_first_five_per_ip_then_blocks(): void
    {
        $ipKey = '203.0.113.7';
        for ($i = 0; $i < 5; $i++) {
            $limited = PublicBookingController::evaluateRateLimit(
                $ipKey,
                fn (string $k): int => $this->get($k),
                fn (string $k, int $v): void => $this->set($k, $v),
            );
            self::assertFalse($limited, "request #{$i} should be allowed");
        }
        $sixth = PublicBookingController::evaluateRateLimit(
            $ipKey,
            fn (string $k): int => $this->get($k),
            fn (string $k, int $v): void => $this->set($k, $v),
        );
        self::assertTrue($sixth, '6th request from same IP must be blocked');
    }

    public function test_global_cap_blocks_even_with_rotating_ips(): void
    {
        // Each request uses a unique IP key (simulating IP rotation), so the
        // per-IP bucket never fills — but the global bucket must.
        $limitedAtSome = false;
        for ($i = 0; $i < PublicBookingController::GLOBAL_LIMIT_PER_MINUTE + 1; $i++) {
            $limited = PublicBookingController::evaluateRateLimit(
                'rotating-' . $i,
                fn (string $k): int => $this->get($k),
                fn (string $k, int $v): void => $this->set($k, $v),
            );
            if ($limited) {
                $limitedAtSome = true;
            }
        }
        self::assertTrue($limitedAtSome, 'global cap must eventually block rotating IPs');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**
  - Command: `vendor/bin/phpunit --filter PublicBookingRateLimitTest`
  - Expected failure: `Error: Call to undefined method ...PublicBookingController::evaluateRateLimit()` (and undefined constant `GLOBAL_LIMIT_PER_MINUTE`).

- [ ] **Step 3: Write minimal implementation**

  In `src/Http/PublicBookingController.php`, add the `use` import near the other `use` lines (after line 13 `use Slash\Booking\Plugin;`):

```php
use Slash\Booking\Support\ClientIp;
```

  Replace the entire `isRateLimited()` method (lines 217-231) with:

```php
    public const PER_IP_LIMIT_PER_MINUTE  = 5;
    public const GLOBAL_LIMIT_PER_MINUTE  = 60;
    private const RATE_PREFIX             = 'sb_rate_';
    private const RATE_GLOBAL_KEY         = 'sb_rate_global';

    /**
     * Pure rate-limit decision. Increments both a per-IP bucket and a global
     * bucket. Returns true (blocked) when either bucket is exhausted, OR when
     * there is no usable IP key (fail-CLOSED).
     *
     * @param string                   $ipKey  Normalized IP key, '' when unknown.
     * @param callable(string): int    $getter Transient getter (key => count).
     * @param callable(string, int): void $setter Transient setter (key, value).
     */
    public static function evaluateRateLimit(string $ipKey, callable $getter, callable $setter): bool
    {
        // Global bucket is always counted — defeats source-IP rotation.
        $globalCount = (int) $getter(self::RATE_GLOBAL_KEY);
        $globalCount++;
        $setter(self::RATE_GLOBAL_KEY, $globalCount);
        if ($globalCount > self::GLOBAL_LIMIT_PER_MINUTE) {
            return true;
        }

        // No usable IP => fail closed (do not let CLI/edge/misconfig disable throttling).
        if ($ipKey === '') {
            return true;
        }

        $key = self::RATE_PREFIX . md5($ipKey);
        $count = (int) $getter($key);
        if ($count >= self::PER_IP_LIMIT_PER_MINUTE) {
            return true;
        }
        $setter($key, $count + 1);
        return false;
    }

    private function isRateLimited(): bool
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $ip    = ClientIp::fromServer($_SERVER);
        $ipKey = ClientIp::normalizeForKey($ip);

        return self::evaluateRateLimit(
            $ipKey,
            static fn (string $k): int => (int) get_transient($k),
            static fn (string $k, int $v): void => set_transient($k, $v, MINUTE_IN_SECONDS),
        );
    }
```

  Also update the Turnstile IP read at line 162 of `createBooking()` to use the helper for consistency. Replace:

```php
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
```

  with:

```php
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $ip = ClientIp::fromServer($_SERVER);
```

- [ ] **Step 4: Run test to verify it passes**
  - Command: `vendor/bin/phpunit --filter PublicBookingRateLimitTest`
  - Expected: `OK (3 tests, ...)`.
  - Also run the existing controller suite to confirm no regression: `vendor/bin/phpunit --filter PublicBookingControllerTest` -> existing tests still pass (the public behavior — 429 after exhaustion — is unchanged for normal IPv4 traffic).

- [ ] **Step 5: Commit**
  - `git add src/Http/PublicBookingController.php tests/Unit/Http/PublicBookingRateLimitTest.php`
  - `git commit -m "fix(security): rate limit fails closed on empty IP and adds a global per-minute cap"`

---

### Task 3: Prominent admin notice when the public booking form has no Turnstile secret

**Files:**
- Create: `src/Admin/TurnstileNotice.php`
- Modify: `src/Plugin.php` (register the notice; near the encryption-notice block ~line 396).
- Test: none (admin-notice rendering is a thin presentation hook; covered by manual QA — see Step 2 note). We still add a tiny unit test for the *decision* of whether to show it.

To keep TDD honest, the show/hide decision is a pure static method (`TurnstileNotice::shouldShow()`) that is unit-tested; the `echo` wiring is the only untested line.

- [ ] **Step 1: Write the failing test**

  Create `tests/Unit/Admin/TurnstileNoticeTest.php`:

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Admin\TurnstileNotice;

final class TurnstileNoticeTest extends TestCase
{
    public function test_shows_when_secret_empty(): void
    {
        self::assertTrue(TurnstileNotice::shouldShow(''));
        self::assertTrue(TurnstileNotice::shouldShow('   '));
    }

    public function test_hidden_when_secret_present(): void
    {
        self::assertFalse(TurnstileNotice::shouldShow('1x0000000000000000000000000000000AA'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**
  - Command: `vendor/bin/phpunit --filter TurnstileNoticeTest`
  - Expected failure: `Error: Class "Slash\Booking\Admin\TurnstileNotice" not found`.

- [ ] **Step 3: Write minimal implementation**

  Create `src/Admin/TurnstileNotice.php`:

```php
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
                    'le formulaire de réservation public n’est pas protégé contre les robots. '
                    . 'Configurez une clé secrète Cloudflare Turnstile dans les réglages pour activer la protection anti-spam.',
                    'slashbooking'
                )
                . '</p></div>';
        });
    }
}
```

- [ ] **Step 4: Run test to verify it passes**
  - Command: `vendor/bin/phpunit --filter TurnstileNoticeTest`
  - Expected: `OK (2 tests, ...)`.

- [ ] **Step 5: Wire it into Plugin.php and commit**

  In `src/Plugin.php`, immediately after the encryption-key admin-notice block (after line 403 `}`), add:

```php
        (new Admin\TurnstileNotice())->register();
```

  - `git add src/Admin/TurnstileNotice.php tests/Unit/Admin/TurnstileNoticeTest.php src/Plugin.php`
  - `git commit -m "feat(admin): prominent notice when public booking form lacks Turnstile protection"`

---

### Task 4: GoogleAccount — watch resource-id verification + validity-window helper

**Files:**
- Modify: `src/Domain/GoogleAccount.php` (add two methods after `verifyWatchToken()` at lines 139-145).
- Test: `tests/Unit/Domain/GoogleAccountWatchTest.php`

`GoogleAccount` already exposes `watchResourceId(): ?string` (line 172), `watchExpiresAt(): ?DateTimeImmutable` (line 174), `verifyWatchToken()` (139-145), and the factory `connect()` + `attachWatch()` (117-128). The new helpers add constant-time resource-id comparison and an expiry/activity check.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\GoogleAccount;

final class GoogleAccountWatchTest extends TestCase
{
    private function connected(): GoogleAccount
    {
        return GoogleAccount::connect(
            label: 'primary',
            calendarId: 'primary',
            refreshTokenEnc: 'r',
            accessTokenEnc: 'a',
            expiresAt: new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')),
        );
    }

    public function test_resource_id_compare_is_false_when_no_watch(): void
    {
        $acct = $this->connected();
        self::assertFalse($acct->verifyWatchResourceId('anything'));
    }

    public function test_resource_id_compare_matches_attached_value(): void
    {
        $acct = $this->connected();
        $acct->attachWatch(
            channelId: 'chan-1',
            resourceId: 'res-1',
            tokenSecret: 'tok',
            expiresAt: new DateTimeImmutable('+1 day', new DateTimeZone('UTC')),
        );
        self::assertTrue($acct->verifyWatchResourceId('res-1'));
        self::assertFalse($acct->verifyWatchResourceId('res-2'));
        self::assertFalse($acct->verifyWatchResourceId(''));
    }

    public function test_watch_active_false_when_no_expiry(): void
    {
        $acct = $this->connected();
        $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        self::assertFalse($acct->watchActive($now));
    }

    public function test_watch_active_true_before_expiry_false_after(): void
    {
        $acct = $this->connected();
        $acct->attachWatch(
            channelId: 'chan-1',
            resourceId: 'res-1',
            tokenSecret: 'tok',
            expiresAt: new DateTimeImmutable('2030-01-01 00:00:00', new DateTimeZone('UTC')),
        );
        $before = new DateTimeImmutable('2029-12-31 23:59:59', new DateTimeZone('UTC'));
        $after  = new DateTimeImmutable('2030-01-01 00:00:01', new DateTimeZone('UTC'));
        self::assertTrue($acct->watchActive($before));
        self::assertFalse($acct->watchActive($after));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**
  - Command: `vendor/bin/phpunit --filter GoogleAccountWatchTest`
  - Expected failure: `Error: Call to undefined method ...GoogleAccount::verifyWatchResourceId()`.

- [ ] **Step 3: Write minimal implementation**

  In `src/Domain/GoogleAccount.php`, directly after the `verifyWatchToken()` method (after line 145 `}`), insert:

```php
    public function verifyWatchResourceId(string $candidate): bool
    {
        if ($this->watchResourceId === null || $candidate === '') {
            return false;
        }
        return hash_equals($this->watchResourceId, $candidate);
    }

    /**
     * True when a watch channel is attached and not yet expired.
     * A null expiry is treated as inactive (fail-closed).
     */
    public function watchActive(DateTimeImmutable $now): bool
    {
        if ($this->watchExpiresAt === null) {
            return false;
        }
        return $now < $this->watchExpiresAt;
    }
```

- [ ] **Step 4: Run test to verify it passes**
  - Command: `vendor/bin/phpunit --filter GoogleAccountWatchTest`
  - Expected: `OK (4 tests, ...)`.

- [ ] **Step 5: Commit**
  - `git add src/Domain/GoogleAccount.php tests/Unit/Domain/GoogleAccountWatchTest.php`
  - `git commit -m "feat(domain): GoogleAccount watch resource-id compare and validity-window helpers"`

---

### Task 5: Webhook hardening — expiry check, resource-id compare, channel-id constant-time

**Files:**
- Modify: `src/Http/GoogleWebhookController.php` (`handle()` lines 38-105).
- Test: `tests/Integration/GoogleWebhookControllerTest.php` (add cases).

Decision on token storage: **keep `watch_token_secret` as-is (compared with `hash_equals`) — do NOT add a hashed column.** Justification (matches the audit's own LOW verdict, finding 11): the secret is only exposed via a full DB dump, at which point the attacker already holds the encrypted OAuth tokens and all PII; hashing the watch secret adds negligible security for a schema migration cost. The pragmatic, high-value hardening is the **expiry + resource-id + dedup** controls, which this task implements.

New `handle()` flow after token verification:
1. Reject (200 ack-ignore) if `!$account->watchActive($now)` — leaked/expired channels stop working.
2. Compare channel-id with `hash_equals` (was `!==`).
3. Compare `X-Goog-Resource-Id` with `hash_equals` when the account has one stored.
4. `sync` state -> ack (unchanged).
5. Dedup: a transient lock keyed on channel-id throttles `enqueuePull` to once per 30s.

- [ ] **Step 1: Write the failing tests**

  Open `tests/Integration/GoogleWebhookControllerTest.php` and add the following methods to the existing test class (match the file's existing setup — it already builds a `GoogleWebhookController` with a fake `GoogleAccountRepository`, an `enqueuePull` spy closure, and a `log` no-op closure; reuse those helpers). If the file lacks a helper to build a request with headers, add this private helper too:

```php
    private function requestWith(string $token, string $channelId, string $resourceId, string $state): \WP_REST_Request
    {
        $req = new \WP_REST_Request('POST', '/slashbooking/v1/google/webhook');
        $req->set_header('X-Goog-Channel-Token', $token);
        $req->set_header('X-Goog-Channel-Id', $channelId);
        $req->set_header('X-Goog-Resource-Id', $resourceId);
        $req->set_header('X-Goog-Resource-State', $state);
        return $req;
    }

    public function test_expired_watch_is_acknowledged_but_not_enqueued(): void
    {
        $pulled = [];
        $account = \Slash\Booking\Domain\GoogleAccount::connect(
            'primary', 'primary', 'r', 'a',
            new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')),
        );
        $account->assignId(1);
        $account->attachWatch(
            'chan-1', 'res-1', 'secret-token',
            new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC')), // already expired
        );

        $controller = new \Slash\Booking\Http\GoogleWebhookController(
            $this->repoReturning($account),
            function (int $id) use (&$pulled): void { $pulled[] = $id; },
            static function (array $e): void {},
        );

        $resp = $controller->handle(
            $this->requestWith('secret-token', 'chan-1', 'res-1', 'exists')
        );

        self::assertSame(200, $resp->get_status());
        self::assertSame([], $pulled, 'expired channel must not enqueue a pull');
    }

    public function test_wrong_resource_id_is_acknowledged_but_not_enqueued(): void
    {
        $pulled = [];
        $account = \Slash\Booking\Domain\GoogleAccount::connect(
            'primary', 'primary', 'r', 'a',
            new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')),
        );
        $account->assignId(1);
        $account->attachWatch(
            'chan-1', 'res-1', 'secret-token',
            new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')),
        );

        $controller = new \Slash\Booking\Http\GoogleWebhookController(
            $this->repoReturning($account),
            function (int $id) use (&$pulled): void { $pulled[] = $id; },
            static function (array $e): void {},
        );

        $resp = $controller->handle(
            $this->requestWith('secret-token', 'chan-1', 'WRONG-RES', 'exists')
        );

        self::assertSame(200, $resp->get_status());
        self::assertSame([], $pulled, 'mismatched resource id must not enqueue a pull');
    }

    public function test_valid_active_webhook_enqueues_once_then_dedups(): void
    {
        $pulled = [];
        $account = \Slash\Booking\Domain\GoogleAccount::connect(
            'primary', 'primary', 'r', 'a',
            new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')),
        );
        $account->assignId(7);
        $account->attachWatch(
            'chan-1', 'res-1', 'secret-token',
            new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')),
        );

        $controller = new \Slash\Booking\Http\GoogleWebhookController(
            $this->repoReturning($account),
            function (int $id) use (&$pulled): void { $pulled[] = $id; },
            static function (array $e): void {},
        );

        $first  = $controller->handle($this->requestWith('secret-token', 'chan-1', 'res-1', 'exists'));
        $second = $controller->handle($this->requestWith('secret-token', 'chan-1', 'res-1', 'exists'));

        self::assertSame(200, $first->get_status());
        self::assertSame(200, $second->get_status());
        self::assertSame([7], $pulled, 'second webhook within dedup window must not re-enqueue');
    }
```

  Add the `repoReturning()` helper if the file does not already have an equivalent (it returns an anonymous `GoogleAccountRepository`-typed double; if the existing tests already inject a repo double, reuse that pattern instead and delete this helper):

```php
    private function repoReturning(?\Slash\Booking\Domain\GoogleAccount $account): \Slash\Booking\Persistence\GoogleAccountRepository
    {
        return new class($account) extends \Slash\Booking\Persistence\GoogleAccountRepository {
            public function __construct(private readonly ?\Slash\Booking\Domain\GoogleAccount $a) {}
            public function findSingle(): ?\Slash\Booking\Domain\GoogleAccount { return $this->a; }
        };
    }
```

  > If `GoogleAccountRepository` is `final` or its constructor is non-trivial, do NOT subclass it. Instead reuse the exact double-construction pattern already present in the existing `GoogleWebhookControllerTest` (read the file first). The behavioral assertions above are the contract; adapt the wiring to the file's established style.

- [ ] **Step 2: Run test to verify it fails**
  - Command: `vendor/bin/phpunit --filter GoogleWebhookControllerTest`
  - Expected failure: the new expiry/resource-id cases fail because today `handle()` enqueues regardless of expiry/resource-id; the dedup case fails because today every non-`sync` POST enqueues. Expect assertion failures like `Failed asserting that Array ([0] => 7, [1] => 7) is identical to Array ([0] => 7)`.

- [ ] **Step 3: Write minimal implementation**

  Replace the body of `handle()` in `src/Http/GoogleWebhookController.php` (lines 38-105) with:

```php
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $token         = (string) $request->get_header('X-Goog-Channel-Token');
        $channelId     = (string) $request->get_header('X-Goog-Channel-Id');
        $resourceId    = (string) $request->get_header('X-Goog-Resource-Id');
        $resourceState = (string) $request->get_header('X-Goog-Resource-State');

        $account = $this->accounts->findSingle();
        if ($account === null || !$account->verifyWatchToken($token)) {
            ($this->log)([
                'level'           => 'warn',
                'direction'       => 'internal',
                'entity'          => 'watch',
                'entity_id'       => null,
                'google_event_id' => null,
                'action'          => 'webhook_rejected',
                'status'          => 'failed',
                'error_message'   => 'token mismatch',
                'payload'         => ['channelId' => $channelId, 'state' => $resourceState],
            ]);
            return new WP_REST_Response(['ok' => false], 401);
        }

        // Reject leaked/expired channels (200-ack-ignore so Google stops retrying).
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (!$account->watchActive($now)) {
            ($this->log)([
                'level'           => 'warn',
                'direction'       => 'internal',
                'entity'          => 'watch',
                'entity_id'       => $account->id(),
                'google_event_id' => null,
                'action'          => 'webhook_expired_channel',
                'status'          => 'failed',
                'error_message'   => 'watch channel expired or inactive',
                'payload'         => ['channelId' => $channelId, 'state' => $resourceState],
            ]);
            return new WP_REST_Response(['ok' => true], 200);
        }

        // Constant-time channel-id check.
        $expectedChannelId = $account->watchChannelId();
        if ($expectedChannelId !== null && !hash_equals($expectedChannelId, $channelId)) {
            ($this->log)([
                'level'           => 'warn',
                'direction'       => 'internal',
                'entity'          => 'watch',
                'entity_id'       => $account->id(),
                'google_event_id' => null,
                'action'          => 'webhook_stale_channel',
                'status'          => 'failed',
                'error_message'   => 'channel id mismatch',
                'payload'         => ['state' => $resourceState],
            ]);
            return new WP_REST_Response(['ok' => true], 200);
        }

        // Constant-time resource-id check (when one is stored).
        if ($account->watchResourceId() !== null && !$account->verifyWatchResourceId($resourceId)) {
            ($this->log)([
                'level'           => 'warn',
                'direction'       => 'internal',
                'entity'          => 'watch',
                'entity_id'       => $account->id(),
                'google_event_id' => null,
                'action'          => 'webhook_resource_mismatch',
                'status'          => 'failed',
                'error_message'   => 'resource id mismatch',
                'payload'         => ['state' => $resourceState],
            ]);
            return new WP_REST_Response(['ok' => true], 200);
        }

        if ($resourceState === 'sync') {
            ($this->log)([
                'level'           => 'info',
                'direction'       => 'internal',
                'entity'          => 'watch',
                'entity_id'       => $account->id(),
                'google_event_id' => null,
                'action'          => 'webhook_sync_ack',
                'status'          => 'ok',
                'error_message'   => null,
                'payload'         => ['channelId' => $channelId],
            ]);
            return new WP_REST_Response(['ok' => true], 200);
        }

        // Dedup/throttle: collapse bursts of notifications into one pull per window.
        $lockKey = 'sb_webhook_pull_' . md5((string) $expectedChannelId);
        if (get_transient($lockKey) !== false) {
            ($this->log)([
                'level'           => 'info',
                'direction'       => 'internal',
                'entity'          => 'watch',
                'entity_id'       => $account->id(),
                'google_event_id' => null,
                'action'          => 'webhook_throttled',
                'status'          => 'ok',
                'error_message'   => null,
                'payload'         => ['state' => $resourceState],
            ]);
            return new WP_REST_Response(['ok' => true], 200);
        }
        set_transient($lockKey, 1, 30);

        ($this->enqueuePull)((int) $account->id());

        ($this->log)([
            'level'           => 'info',
            'direction'       => 'internal',
            'entity'          => 'watch',
            'entity_id'       => $account->id(),
            'google_event_id' => null,
            'action'          => 'webhook_received',
            'status'          => 'ok',
            'error_message'   => null,
            'payload'         => ['state' => $resourceState],
        ]);

        return new WP_REST_Response(['ok' => true], 200);
    }
```

  > The dedup uses `get_transient`/`set_transient`. The integration test bootstrap must provide an in-memory transient store; if `GoogleWebhookControllerTest`'s bootstrap already stubs `get_transient`/`set_transient` (most WP test bootstraps do), nothing more is needed. If it does NOT, add a minimal in-memory stub to the integration bootstrap guarded by `if (!function_exists('get_transient'))` — see Task 5b note. Verify by reading `tests/Integration/bootstrap-wp.php` before running.

- [ ] **Step 3b (only if needed): ensure transient stubs exist for integration tests**
  - Read `tests/Integration/bootstrap-wp.php`. If `get_transient`/`set_transient`/`delete_transient` are not defined, add (guarded):

```php
if (!function_exists('get_transient')) {
    $GLOBALS['sb_test_transients'] = [];
    function get_transient(string $key) {
        return $GLOBALS['sb_test_transients'][$key] ?? false;
    }
    function set_transient(string $key, $value, int $ttl = 0): bool {
        $GLOBALS['sb_test_transients'][$key] = $value;
        return true;
    }
    function delete_transient(string $key): bool {
        unset($GLOBALS['sb_test_transients'][$key]);
        return true;
    }
}
```

  - In the new dedup test, reset the store in `setUp()`: `$GLOBALS['sb_test_transients'] = [];` (only if the stub above is used).

- [ ] **Step 4: Run test to verify it passes**
  - Command: `vendor/bin/phpunit --filter GoogleWebhookControllerTest`
  - Expected: `OK (...)` — all existing cases plus the 3 new ones pass.

- [ ] **Step 5: Commit**
  - `git add src/Http/GoogleWebhookController.php tests/Integration/GoogleWebhookControllerTest.php tests/Integration/bootstrap-wp.php`
  - `git commit -m "fix(security): webhook validates channel expiry, resource id, and dedups pulls"`

---

### Task 6: DecisionController — interstitial confirmation on GET, mutate on POST, fix info leak

**Files:**
- Modify: `src/Http/DecisionController.php` (whole file: routes + `handle()` split into GET render + POST mutate; replace `$e->getMessage()` leak).
- Modify: `src/Plugin.php` — pass a logger closure into `DecisionController` (the controller currently takes `signer, confirm, reject`; add a `log` closure so we can log the real exception server-side).
- Test: `tests/Integration/DecisionControllerTest.php` (add cases).

Approach: keep the existing GET `/decide` route but make it render an **interstitial** (a page with a `<form method="post">` whose action posts back to `/decide`). Register a second route for `POST /decide` that performs the actual `confirm`/`reject`. Passive prefetchers issue GET and therefore never mutate. The DomainException catch (currently leaking `$e->getMessage()` at line 79) is replaced with a fixed French message plus a server-side log.

- [ ] **Step 1: Write the failing tests**

  Read the existing `tests/Integration/DecisionControllerTest.php` first to reuse its fakes for `ConfirmBooking`/`RejectBooking`/`DecisionTokenSigner`. Add:

```php
    public function test_get_renders_interstitial_and_does_not_confirm(): void
    {
        $confirmed = [];
        $signer = new \Slash\Booking\Booking\DecisionTokenSigner(str_repeat('k', 32));
        $exp = time() + 3600;
        $sig = $signer->sign('decide|42|confirm', $exp);

        $controller = new \Slash\Booking\Http\DecisionController(
            $signer,
            $this->confirmSpy(function (int $id) use (&$confirmed): void { $confirmed[] = $id; }),
            $this->rejectNoop(),
            static function (array $e): void {},
        );

        $req = new \WP_REST_Request('GET', '/slashbooking/v1/decide');
        $req->set_param('booking', 42);
        $req->set_param('action', 'confirm');
        $req->set_param('exp', $exp);
        $req->set_param('sig', $sig);

        $resp = $controller->handleGet($req);

        self::assertSame(200, $resp->get_status());
        self::assertStringContainsString('<form', (string) $resp->get_data());
        self::assertSame([], $confirmed, 'GET must not perform the confirm');
    }

    public function test_post_performs_confirm(): void
    {
        $confirmed = [];
        $signer = new \Slash\Booking\Booking\DecisionTokenSigner(str_repeat('k', 32));
        $exp = time() + 3600;
        $sig = $signer->sign('decide|42|confirm', $exp);

        $controller = new \Slash\Booking\Http\DecisionController(
            $signer,
            $this->confirmSpy(function (int $id) use (&$confirmed): void { $confirmed[] = $id; }),
            $this->rejectNoop(),
            static function (array $e): void {},
        );

        $req = new \WP_REST_Request('POST', '/slashbooking/v1/decide');
        $req->set_param('booking', 42);
        $req->set_param('action', 'confirm');
        $req->set_param('exp', $exp);
        $req->set_param('sig', $sig);

        $resp = $controller->handlePost($req);

        self::assertSame(200, $resp->get_status());
        self::assertSame([42], $confirmed);
    }

    public function test_domain_exception_renders_fixed_message_and_logs(): void
    {
        $logged = [];
        $signer = new \Slash\Booking\Booking\DecisionTokenSigner(str_repeat('k', 32));
        $exp = time() + 3600;
        $sig = $signer->sign('decide|42|confirm', $exp);

        $controller = new \Slash\Booking\Http\DecisionController(
            $signer,
            $this->confirmThrows(new \DomainException('Cannot confirm from status cancelled')),
            $this->rejectNoop(),
            function (array $e) use (&$logged): void { $logged[] = $e; },
        );

        $req = new \WP_REST_Request('POST', '/slashbooking/v1/decide');
        $req->set_param('booking', 42);
        $req->set_param('action', 'confirm');
        $req->set_param('exp', $exp);
        $req->set_param('sig', $sig);

        $resp = $controller->handlePost($req);
        $body = (string) $resp->get_data();

        self::assertSame(409, $resp->get_status());
        self::assertStringNotContainsString('cancelled', $body, 'internal status must not leak');
        self::assertStringNotContainsString('Cannot confirm', $body);
        self::assertNotSame([], $logged, 'real exception must be logged server-side');
    }
```

  Add these spy helpers to the test class (adapt to the file's existing fakes if they differ; the contract is: `ConfirmBooking::execute(int)`, `RejectBooking::execute(int)`):

```php
    private function confirmSpy(\Closure $fn): \Slash\Booking\Booking\ConfirmBooking
    {
        return new class($fn) extends \Slash\Booking\Booking\ConfirmBooking {
            public function __construct(private readonly \Closure $fn) {}
            public function execute(int $bookingId): void { ($this->fn)($bookingId); }
        };
    }
    private function confirmThrows(\Throwable $e): \Slash\Booking\Booking\ConfirmBooking
    {
        return new class($e) extends \Slash\Booking\Booking\ConfirmBooking {
            public function __construct(private readonly \Throwable $e) {}
            public function execute(int $bookingId): void { throw $this->e; }
        };
    }
    private function rejectNoop(): \Slash\Booking\Booking\RejectBooking
    {
        return new class extends \Slash\Booking\Booking\RejectBooking {
            public function __construct() {}
            public function execute(int $bookingId): void {}
        };
    }
```

  > If `ConfirmBooking`/`RejectBooking` are `final` or have required constructor args, do NOT subclass them — reuse the existing fakes already in `DecisionControllerTest.php` (read it first) and just route them through the new `handleGet`/`handlePost` methods. The assertions are the contract.

- [ ] **Step 2: Run test to verify it fails**
  - Command: `vendor/bin/phpunit --filter DecisionControllerTest`
  - Expected failure: `Error: Call to undefined method ...DecisionController::handleGet()` (and `handlePost`, and the 4-arg constructor).

- [ ] **Step 3: Write minimal implementation**

  Replace the entire `src/Http/DecisionController.php` with:

```php
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
     * @param Closure(array<string, mixed>): void|null $log Optional server-side logger.
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
```

- [ ] **Step 4: Run test to verify it passes**
  - Command: `vendor/bin/phpunit --filter DecisionControllerTest`
  - Expected: `OK (...)` — existing tests (now invoking `handlePost` for the mutate path) plus the 3 new cases pass.
  - NOTE: any existing test that called `handle()` directly must be updated to call `handlePost()` (the mutate path) or `handleGet()` (interstitial). Update those in the same step.

- [ ] **Step 5: Wire the logger in Plugin.php and commit**

  In `src/Plugin.php`, locate where `DecisionController` is constructed (search `new Http\DecisionController` or its wiring in `Http\RestRouter`). The audit notes wiring lives in `src/Http/RestRouter.php` (`$signer` built at RestRouter.php:129). Read `src/Http/RestRouter.php`, find the `new DecisionController(...)` call, and add a 4th argument: a logger closure that appends to `SyncLogRepository` (an instance is available in `Plugin.php` as `$syncLogRepo`) or, if RestRouter has no log repo, pass `static fn (array $e) => error_log('[slashbooking] ' . wp_json_encode($e))`. Minimal, dependency-free version:

```php
            static function (array $entry): void {
                error_log('[slashbooking] ' . (string) wp_json_encode($entry));
            },
```

  - `git add src/Http/DecisionController.php src/Http/RestRouter.php tests/Integration/DecisionControllerTest.php`
  - `git commit -m "fix(security): decision links render interstitial on GET, mutate on POST, no message leak"`

---

### Task 7: PublicCancelController — interstitial confirmation on GET, mutate on POST

**Files:**
- Modify: `src/Http/PublicCancelController.php` (routes + split handler).
- Test: `tests/Integration/PublicCancelControllerTest.php` (create).

Mirror Task 6 for `/cancel`. Today `PublicCancelController::handle()` performs the cancel on GET and returns JSON. We split into `handleGet()` (HTML interstitial with a POST form) and `handlePost()` (performs cancel, returns JSON `{status: cancelled}` to preserve the existing success contract for the widget).

- [ ] **Step 1: Write the failing test**

  Create `tests/Integration/PublicCancelControllerTest.php`:

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Booking\CancelBooking;
use Slash\Booking\Booking\DecisionTokenSigner;
use Slash\Booking\Http\PublicCancelController;
use WP_REST_Request;

final class PublicCancelControllerTest extends TestCase
{
    private function cancelSpy(\Closure $fn): CancelBooking
    {
        return new class($fn) extends CancelBooking {
            public function __construct(private readonly \Closure $fn) {}
            public function execute(string $uid): void { ($this->fn)($uid); }
        };
    }

    public function test_get_renders_interstitial_and_does_not_cancel(): void
    {
        $cancelled = [];
        $signer = new DecisionTokenSigner(str_repeat('k', 32));
        $exp = time() + 3600;
        $sig = $signer->sign('cancel|abc123', $exp);

        $controller = new PublicCancelController(
            $signer,
            $this->cancelSpy(function (string $uid) use (&$cancelled): void { $cancelled[] = $uid; }),
        );

        $req = new WP_REST_Request('GET', '/slashbooking/v1/cancel');
        $req->set_param('uid', 'abc123');
        $req->set_param('exp', $exp);
        $req->set_param('sig', $sig);

        $resp = $controller->handleGet($req);

        self::assertSame(200, $resp->get_status());
        self::assertStringContainsString('<form', (string) $resp->get_data());
        self::assertSame([], $cancelled, 'GET must not cancel');
    }

    public function test_post_cancels(): void
    {
        $cancelled = [];
        $signer = new DecisionTokenSigner(str_repeat('k', 32));
        $exp = time() + 3600;
        $sig = $signer->sign('cancel|abc123', $exp);

        $controller = new PublicCancelController(
            $signer,
            $this->cancelSpy(function (string $uid) use (&$cancelled): void { $cancelled[] = $uid; }),
        );

        $req = new WP_REST_Request('POST', '/slashbooking/v1/cancel');
        $req->set_param('uid', 'abc123');
        $req->set_param('exp', $exp);
        $req->set_param('sig', $sig);

        $resp = $controller->handlePost($req);

        self::assertSame(200, $resp->get_status());
        self::assertSame(['abc123'], $cancelled);
    }
}
```

  > If `CancelBooking` is `final` or has required constructor args, do not subclass — read an existing test that uses `CancelBooking` (e.g. `tests/Unit/Booking/CancelBookingTest.php`) and reuse its construction pattern, routing through `handleGet`/`handlePost`.

- [ ] **Step 2: Run test to verify it fails**
  - Command: `vendor/bin/phpunit --filter PublicCancelControllerTest`
  - Expected failure: `Error: Call to undefined method ...PublicCancelController::handleGet()`.

- [ ] **Step 3: Write minimal implementation**

  Replace the entire `src/Http/PublicCancelController.php` with:

```php
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
                '<h1>' . esc_html__('Lien invalide ou expiré', 'slashbooking') . '</h1>'
                . '<p>' . esc_html__('Demandez un nouveau lien.', 'slashbooking') . '</p>',
            );
        }

        $endpoint = esc_url(rest_url(Plugin::REST_NAMESPACE . '/cancel'));
        $form = '<h1>' . esc_html__('Annuler cette réservation ?', 'slashbooking') . '</h1>'
            . '<form method="post" action="' . $endpoint . '">'
            . '<input type="hidden" name="uid" value="' . esc_attr($uid) . '">'
            . '<input type="hidden" name="exp" value="' . (int) $exp . '">'
            . '<input type="hidden" name="sig" value="' . esc_attr($sig) . '">'
            . '<button type="submit" style="font-size:16px;padding:10px 18px;cursor:pointer">'
            . esc_html__('Annuler le RDV', 'slashbooking') . '</button>'
            . '</form>';

        return $this->htmlResponse(200, $form);
    }

    public function handlePost(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $uid = (string) $request['uid'];
        $exp = (int) $request['exp'];
        $sig = (string) $request['sig'];

        if (!$this->signer->verify('cancel|' . $uid, $exp, $sig)) {
            return new WP_Error('sb_invalid_token', __('Lien invalide ou expiré.', 'slashbooking'), ['status' => 403]);
        }

        try {
            $this->cancel->execute($uid);
        } catch (BookingNotFound $e) {
            return new WP_Error('sb_not_found', __('Réservation introuvable.', 'slashbooking'), ['status' => 404]);
        }

        return new WP_REST_Response(['status' => 'cancelled'], 200);
    }

    private function htmlResponse(int $status, string $body): WP_REST_Response
    {
        $title = esc_html__('Annulation RDV', 'slashbooking');
        $html = <<<HTML
<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>{$title}</title>
<style>body{font-family:system-ui,sans-serif;max-width:560px;margin:80px auto;padding:0 16px;color:#111}</style>
</head><body>{$body}</body></html>
HTML;
        return new WP_REST_Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**
  - Command: `vendor/bin/phpunit --filter PublicCancelControllerTest`
  - Expected: `OK (2 tests, ...)`.

- [ ] **Step 5: Commit**
  - `git add src/Http/PublicCancelController.php tests/Integration/PublicCancelControllerTest.php`
  - `git commit -m "fix(security): cancel links render interstitial on GET, mutate on POST"`

---

### Task 8: Capabilities — administrator-only default, filter, and editor-revocation migration

**Files:**
- Modify: `src/Admin/Capabilities.php` (whole file).
- Test: `tests/Integration/CapabilitiesTest.php` (add cases).

Change `GRANTED_ROLES` to `['administrator']`, expose it through `apply_filters('slashbooking_manage_roles', ['administrator'])`, bump `REVISION` to 3, and on upgrade **remove** MANAGE+VIEW from `editor` (the role that previous revisions granted). `install()`/`uninstall()` iterate over the filtered role list. The migration is explicit: when crossing into revision 3, strip the caps from `editor` first, then re-`install()`.

- [ ] **Step 1: Write the failing tests**

  Read `tests/Integration/CapabilitiesTest.php` first (it already exercises install/uninstall against a WP roles stub). Add:

```php
    public function test_editor_loses_caps_after_upgrade_to_admin_only(): void
    {
        // Simulate a site activated under the old layout (editor had caps).
        $editor = get_role('editor');
        self::assertNotNull($editor);
        $editor->add_cap(\Slash\Booking\Admin\Capabilities::MANAGE);
        $editor->add_cap(\Slash\Booking\Admin\Capabilities::VIEW);
        update_option('slashbooking_caps_revision', 2, false);

        \Slash\Booking\Admin\Capabilities::syncOnUpgrade();

        self::assertFalse(get_role('editor')->has_cap(\Slash\Booking\Admin\Capabilities::MANAGE));
        self::assertFalse(get_role('editor')->has_cap(\Slash\Booking\Admin\Capabilities::VIEW));
        self::assertTrue(get_role('administrator')->has_cap(\Slash\Booking\Admin\Capabilities::MANAGE));
    }

    public function test_filter_can_add_a_custom_role(): void
    {
        add_filter('slashbooking_manage_roles', static function (array $roles): array {
            $roles[] = 'shop_manager';
            return $roles;
        });
        // Ensure the custom role exists in the stub.
        if (get_role('shop_manager') === null) {
            add_role('shop_manager', 'Shop Manager', []);
        }

        \Slash\Booking\Admin\Capabilities::install();

        self::assertTrue(get_role('shop_manager')->has_cap(\Slash\Booking\Admin\Capabilities::MANAGE));
        remove_all_filters('slashbooking_manage_roles');
    }
```

  > The WP roles stub must support `get_role`, `add_role`, `WP_Role::add_cap`, `WP_Role::remove_cap`, `WP_Role::has_cap`, plus `apply_filters`/`add_filter`/`remove_all_filters`. The existing `CapabilitiesTest` already relies on the roles stub; if `has_cap`/`apply_filters` are missing from the integration bootstrap, extend the stub (guarded by `function_exists`/`method_exists`) before running.

- [ ] **Step 2: Run test to verify it fails**
  - Command: `vendor/bin/phpunit --filter CapabilitiesTest`
  - Expected failure: `test_editor_loses_caps_after_upgrade_to_admin_only` fails because today `syncOnUpgrade()` (revision 2) never removes editor caps; `test_filter_can_add_a_custom_role` fails because `install()` ignores the filter.

- [ ] **Step 3: Write minimal implementation**

  Replace the entire `src/Admin/Capabilities.php` with:

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Admin;

final class Capabilities
{
    public const MANAGE = 'slashbooking_manage';
    public const VIEW   = 'slashbooking_view';

    /**
     * Default role granted full plugin access. Administrator only: managing
     * Google OAuth credentials and viewing all customer PII is an admin task.
     * Operators who delegate booking management can opt extra roles in via the
     * 'slashbooking_manage_roles' filter.
     */
    private const DEFAULT_ROLES = ['administrator'];

    /**
     * Roles that revision <=2 granted but the current layout revokes.
     */
    private const REVOKED_ON_UPGRADE = ['editor'];

    /**
     * Bumped whenever the cap layout changes. {@see syncOnUpgrade()} compares
     * this against the stored revision to decide whether to re-run the migration.
     */
    private const REVISION = 3;
    private const REVISION_OPTION = 'slashbooking_caps_revision';

    /**
     * @return list<string>
     */
    private static function grantedRoles(): array
    {
        /** @var list<string> $roles */
        $roles = apply_filters('slashbooking_manage_roles', self::DEFAULT_ROLES);
        return array_values(array_unique(array_filter($roles, 'is_string')));
    }

    public static function install(): void
    {
        foreach (self::grantedRoles() as $roleName) {
            $role = get_role($roleName);
            if ($role === null) {
                continue;
            }
            $role->add_cap(self::MANAGE);
            $role->add_cap(self::VIEW);
        }
    }

    /**
     * Idempotent migration. When the stored revision is behind {@see self::REVISION},
     * revoke caps from roles dropped by the new layout, then re-grant the current
     * layout. Designed to be called on every Plugin::register().
     */
    public static function syncOnUpgrade(): void
    {
        $stored = (int) get_option(self::REVISION_OPTION, 0);
        if ($stored >= self::REVISION) {
            return;
        }

        foreach (self::REVOKED_ON_UPGRADE as $roleName) {
            $role = get_role($roleName);
            if ($role === null) {
                continue;
            }
            $role->remove_cap(self::MANAGE);
            $role->remove_cap(self::VIEW);
        }

        self::install();
        update_option(self::REVISION_OPTION, self::REVISION, false);
    }

    public static function uninstall(): void
    {
        $roles = array_unique(array_merge(self::grantedRoles(), self::REVOKED_ON_UPGRADE));
        foreach ($roles as $roleName) {
            $role = get_role($roleName);
            if ($role === null) {
                continue;
            }
            $role->remove_cap(self::MANAGE);
            $role->remove_cap(self::VIEW);
        }
        delete_option(self::REVISION_OPTION);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**
  - Command: `vendor/bin/phpunit --filter CapabilitiesTest`
  - Expected: `OK (...)` — existing install/uninstall tests still pass (administrator path unchanged) plus the 2 new cases.

- [ ] **Step 5: Commit**
  - `git add src/Admin/Capabilities.php tests/Integration/CapabilitiesTest.php`
  - `git commit -m "fix(security): restrict management to administrators with opt-in filter, revoke editor on upgrade"`

---

### Task 9: HMAC domain separation between decision tokens and OAuth state

**Files:**
- Modify: `src/Booking/DecisionTokenSigner.php` (derive a context key).
- Modify: `src/Google/OAuthState.php` (derive a *different* context key from the same root secret).
- Test: `tests/Unit/Booking/DecisionTokenSignerTest.php` (add a domain-separation case).

Both classes currently key HMAC directly off `sb_decision_secret`. We derive per-context subkeys with `hash_hmac('sha256', $contextLabel, $rootSecret, true)` so the two systems no longer share an effective key. `DecisionTokenSigner` and `OAuthState` keep the same public constructor signature (`(string $secret)`) — the root secret in, derivation inside — so no wiring changes are needed.

- [ ] **Step 1: Write the failing test**

  Read `tests/Unit/Booking/DecisionTokenSignerTest.php` first. Add:

```php
    public function test_signature_is_domain_separated_from_raw_hmac(): void
    {
        $root = str_repeat('s', 32);
        $signer = new \Slash\Booking\Booking\DecisionTokenSigner($root);

        $exp = time() + 3600;
        $payload = 'decide|1|confirm';

        $sig = $signer->sign($payload, $exp);

        // Old (vulnerable) construction used the root secret directly.
        $rawHmac = hash_hmac('sha256', $payload . '|' . $exp, $root);

        self::assertNotSame($rawHmac, $sig, 'signer must derive a context subkey, not use the root secret directly');
    }

    public function test_decision_and_oauth_state_do_not_share_effective_key(): void
    {
        $root = str_repeat('s', 32);
        $signer = new \Slash\Booking\Booking\DecisionTokenSigner($root);
        $state  = new \Slash\Booking\Google\OAuthState($root);

        // Same logical payload bytes through both systems must not collide,
        // proving distinct derived keys.
        $exp = time() + 600;
        $decisionSig = $signer->sign('x', $exp);

        // OAuthState issues a token; extract its hmac segment and confirm it
        // differs from the decision signature for the same root + window.
        $token = $state->issue(0); // userId 0, default TTL
        self::assertNotSame('', $token);
        self::assertStringNotContainsString($decisionSig, $token);
    }
```

  > Confirm `OAuthState`'s public API by reading `src/Google/OAuthState.php`: per the audit it exposes `issue(int $userId): string` and `verify(string $state): ?int`, constructor `(string $secret)`. If `issue()` has a different signature, adapt the test call (the contract being asserted is "different derived key", not the exact arg list).

- [ ] **Step 2: Run test to verify it fails**
  - Command: `vendor/bin/phpunit --filter DecisionTokenSignerTest`
  - Expected failure: `test_signature_is_domain_separated_from_raw_hmac` fails because today `sign()` IS `hash_hmac('sha256', $payload.'|'.$exp, $root)` — i.e. equals `$rawHmac`.

- [ ] **Step 3: Write minimal implementation**

  In `src/Booking/DecisionTokenSigner.php`, replace the class body with (constructor signature unchanged; derive a subkey):

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Booking;

final class DecisionTokenSigner
{
    private const CONTEXT = 'slashbooking:decision-token:v1';

    private readonly string $key;

    public function __construct(string $secret)
    {
        if (strlen($secret) < 16) {
            throw new \InvalidArgumentException('Decision secret must be at least 16 characters.');
        }
        // Domain separation: derive a context-specific key so the raw root
        // secret is never used directly and is not shared with OAuth state.
        $this->key = hash_hmac('sha256', self::CONTEXT, $secret, true);
    }

    public function sign(string $payload, int $expiresAtUnix): string
    {
        return hash_hmac('sha256', $payload . '|' . $expiresAtUnix, $this->key);
    }

    public function verify(string $payload, int $expiresAtUnix, string $signature): bool
    {
        if ($expiresAtUnix < time()) {
            return false;
        }
        $expected = $this->sign($payload, $expiresAtUnix);
        return hash_equals($expected, $signature);
    }
}
```

  In `src/Google/OAuthState.php`, apply the parallel change. Read the file, then in its constructor derive:

```php
    private const CONTEXT = 'slashbooking:oauth-state:v1';
```

  and store `$this->key = hash_hmac('sha256', self::CONTEXT, $secret, true);`, replacing every internal use of the raw `$secret` in its `hash_hmac(...)` calls with `$this->key`. Keep the public constructor signature `__construct(string $secret)` and the `issue()`/`verify()` signatures unchanged.

- [ ] **Step 4: Run test to verify it passes**
  - Command: `vendor/bin/phpunit --filter DecisionTokenSignerTest` then `vendor/bin/phpunit --filter OAuthStateTest`
  - Expected: both `OK (...)`.
  - IMPORTANT: existing `OAuthStateTest` round-trip tests (issue -> verify) still pass because issue and verify both use the same derived key. The only failing case would be a test that hard-codes an expected raw-hmac string; if one exists, update its expected value (it tested an implementation detail, not behavior).

- [ ] **Step 5: Commit**
  - `git add src/Booking/DecisionTokenSigner.php src/Google/OAuthState.php tests/Unit/Booking/DecisionTokenSignerTest.php`
  - `git commit -m "fix(security): derive context-separated HMAC keys for decision tokens and OAuth state"`

---

### Task 10: Activator docblock note on derived domain separation

**Files:**
- Modify: `src/Activator.php` (`ensureDecisionSecret()` docblock, lines ~91-97).
- Test: none (documentation-only; no behavior change). Covered by the existing `ActivatorTest` continuing to pass.

The root secret `sb_decision_secret` stays the single stored secret; both signer and state derive distinct subkeys from it (Task 9). Add a docblock so a future maintainer does not "fix" this by adding a second option.

- [ ] **Step 1: (no failing test — documentation only)** Confirm `tests/Integration/ActivatorTest.php` currently passes:
  - Command: `vendor/bin/phpunit --filter ActivatorTest`
  - Expected: `OK (...)`.

- [ ] **Step 2: Edit the docblock**

  In `src/Activator.php`, replace the `ensureDecisionSecret()` method's leading line with a documented version. Change:

```php
    public static function ensureDecisionSecret(): void
    {
```

  to:

```php
    /**
     * Root HMAC secret. Both DecisionTokenSigner and Google\OAuthState derive
     * DISTINCT context subkeys from this single value (HKDF-style domain
     * separation), so do NOT add a second option — one stored secret is correct.
     */
    public static function ensureDecisionSecret(): void
    {
```

- [ ] **Step 3: Run the suite to confirm no regression**
  - Command: `vendor/bin/phpunit --filter ActivatorTest`
  - Expected: `OK (...)`.

- [ ] **Step 4: Commit**
  - `git add src/Activator.php`
  - `git commit -m "docs(security): note derived domain separation in ensureDecisionSecret"`

---

### Task 11: Escalate the encryption-key-at-rest admin notice

**Files:**
- Modify: `src/Plugin.php` (admin-notice block lines 396-403).
- Test: none new (presentation hook). The condition (`EncryptionKeyResolver::usingFallback()`) is already unit-covered indirectly via `EncryptionTest`; this task only changes notice severity/text.

The current notice is `notice-warning` and only fires for `manage_options`. Escalate to `notice-error` (more prominent), keep `manage_options` gating, and make the copy explicit that Google tokens are NOT protected at rest until the constant is set. (Per audit finding 5's mitigation: escalate the notice; do NOT auto-edit `wp-config.php`.)

- [ ] **Step 1: (no failing test)** Run the existing encryption suite to confirm baseline green:
  - Command: `vendor/bin/phpunit --filter EncryptionTest`
  - Expected: `OK (...)`.

- [ ] **Step 2: Edit the notice block**

  In `src/Plugin.php`, replace the block (lines 396-403):

```php
        // Admin notice if encryption key falls back to option.
        if ($keyResolver->usingFallback()) {
            add_action('admin_notices', function (): void {
                if (!current_user_can('manage_options')) {
                    return;
                }
                echo '<div class="notice notice-warning"><p><strong>SlashBooking :</strong> définissez <code>SLASHBOOKING_ENC_KEY</code> dans <code>wp-config.php</code> pour chiffrer les tokens Google avec une clé hors base.</p></div>';
            });
        }
```

  with:

```php
        // Admin notice if encryption key falls back to the database option.
        // Escalated to notice-error: while the fallback works, the Google tokens
        // are NOT protected at rest against a DB dump until the constant is set.
        if ($keyResolver->usingFallback()) {
            add_action('admin_notices', function (): void {
                if (!current_user_can('manage_options')) {
                    return;
                }
                echo '<div class="notice notice-error"><p><strong>SlashBooking — '
                    . esc_html__('sécurité', 'slashbooking') . ' :</strong> '
                    . esc_html__(
                        'la clé de chiffrement est stockée dans la base de données. Les tokens Google ne sont donc PAS protégés en cas de fuite de la base. Définissez la constante',
                        'slashbooking'
                    )
                    . ' <code>SLASHBOOKING_ENC_KEY</code> '
                    . esc_html__('dans', 'slashbooking')
                    . ' <code>wp-config.php</code> '
                    . esc_html__('pour utiliser une clé hors base.', 'slashbooking')
                    . '</p></div>';
            });
        }
```

- [ ] **Step 3: Run the suite to confirm no regression**
  - Command: `vendor/bin/phpunit --filter PluginTest`
  - Expected: `OK (...)` (or no PluginTest assertions touch this block — confirm green).

- [ ] **Step 4: Commit**
  - `git add src/Plugin.php`
  - `git commit -m "fix(security): escalate encryption-key-at-rest admin notice to error and clarify impact"`

---

### Task 12: Document update-channel SHA-256 verification as future hardening (no implementation)

**Files:**
- Modify: `docs/security/2026-05-30-security-audit-RAW.md` is read-only history — do NOT edit it. Instead append a short note to the plan's companion doc.
- Create: `docs/security/UPDATE_CHANNEL_HARDENING.md` (a future-work note).
- Test: none (documentation).

Per the brief: the update-channel SHA-256 verification (audit finding 2/9, re-rated LOW for this plan) is documented as future hardening and NOT implemented here unless trivial. It is not trivial (requires an `upgrader_pre_download`/`puc_pre_inject_update` hook plus fetching and enforcing the published `.sha256`), so we document it.

- [ ] **Step 1: Create the note**

  Create `docs/security/UPDATE_CHANNEL_HARDENING.md`:

```markdown
# Update channel integrity — future hardening

Status: NOT implemented in security plan C (2026-05-31). Documented for a future release.

## Gap
`src/Updates/UpdateChecker.php` uses PUC v5 against GitHub Releases. The release
workflow publishes `slashbooking-<v>.zip.sha256`, but nothing verifies it before
WordPress extracts and runs the downloaded ZIP. Anyone who can publish a Release
on the source repo can push arbitrary PHP to every install.

## Recommended fix (future)
1. Hook `upgrader_pre_download` (or PUC's `puc_pre_inject_update`).
2. Fetch the `.sha256` sidecar over HTTPS for the resolved version.
3. Reject the install unless `hash_file('sha256', $downloaded) === $published`.
4. Better: sign each ZIP (minisign/cosign) in `release.yml` and verify a detached
   signature against a public key shipped in the plugin before extraction.
5. Pin all GitHub Actions to commit SHAs and scope `contents: write` to the
   publish step only (audit finding 9).

## Why deferred
Requires CI changes plus an authenticated fetch path; out of scope for the
behavior-only hardening in plan C.
```

- [ ] **Step 2: Commit**
  - `git add docs/security/UPDATE_CHANNEL_HARDENING.md`
  - `git commit -m "docs(security): document update-channel sha256 verification as future hardening"`

---

### Task 13: Full verification — test suite + PHPStan

**Files:** none (verification only).

- [ ] **Step 1: Run the full test suite**
  - Command: `composer test`
  - Expected: `OK (...)` with zero failures, zero errors, zero risky (config has `failOnRisky="true"` and `failOnWarning="true"`).

- [ ] **Step 2: Run PHPStan at the configured level**
  - Command: `vendor/bin/phpstan analyse --no-progress`
  - Expected: `[OK] No errors`. If new errors appear, fix types in the touched files only (e.g. ensure `ClientIp::normalizeForKey` return type, the `?Closure $log` nullable handling in `DecisionController`, and the `apply_filters` `list<string>` cast in `Capabilities`).

- [ ] **Step 3: Do NOT run cs:fix** (user preference). If a PHPCS check is part of CI, run `composer phpcs` (read `composer.json` scripts to confirm the exact script name) and fix reported issues by hand, reusing the existing `phpcs:ignore` comment pattern for superglobal reads.
  - Command: `composer phpcs` (or `vendor/bin/phpcs` if no script alias) — confirm clean.

- [ ] **Step 4: Commit any verification-driven fixes**
  - `git add -A`
  - `git commit -m "chore(security): satisfy phpstan/phpcs after hardening changes"` (only if changes were needed)

---

### Task 14: Version bump + changelog (coordinate with Plan B)

**Files:**
- Modify: `slashbooking.php` (the `Version:` header), `src/Plugin.php` (`const VERSION`), `readme.txt` (`Stable tag`), `CHANGELOG.md`.

> CONDITIONAL: If Plan C ships **with** Plan B (1.1.0), SKIP the version-number edits below and ONLY add the CHANGELOG entries under the upcoming `1.1.0` heading. If Plan C ships **separately**, bump to **1.0.25** as written.

- [ ] **Step 1: Read current version markers**
  - Command: `grep -rn "1.0.24" slashbooking.php src/Plugin.php readme.txt`
  - Expected: shows the three version sites to bump (`Version: 1.0.24`, `const VERSION = '1.0.24'`, `Stable tag: 1.0.24`).

- [ ] **Step 2: Bump version to 1.0.25 (separate-ship case)**
  - In `slashbooking.php`: `Version: 1.0.24` -> `Version: 1.0.25`.
  - In `src/Plugin.php`: `public const VERSION = '1.0.24';` -> `public const VERSION = '1.0.25';`.
  - In `readme.txt`: `Stable tag: 1.0.24` -> `Stable tag: 1.0.25`.

- [ ] **Step 3: Add the CHANGELOG entry**

  Prepend a new section to `CHANGELOG.md` (match the existing heading style of prior entries):

```markdown
## 1.0.25 — Security hardening (audit plan C)

### Security
- Booking rate limit now fails closed when no client IP is available and adds a global per-minute cap; IPv6 keys collapse to the /64 prefix.
- Admin notice when the public booking form has no Cloudflare Turnstile secret configured.
- Google push webhook validates channel expiry and resource id (constant-time), and dedups bursts before scheduling a pull.
- Management restricted to administrators by default (filter `slashbooking_manage_roles`); the editor role is revoked on upgrade.
- Decision/cancel links render an interstitial confirmation page on GET and perform the mutation only on POST, so mail scanners/prefetchers can no longer trigger state changes.
- Decision page no longer reflects raw exception messages (fixed user message + server-side log).
- Decision tokens and OAuth state now use context-separated derived HMAC keys.
- Encryption-key-at-rest admin notice escalated to an error with clearer impact text.
```

- [ ] **Step 4: Run the full suite one final time**
  - Command: `composer test`
  - Expected: `OK (...)`.

- [ ] **Step 5: Commit**
  - `git add slashbooking.php src/Plugin.php readme.txt CHANGELOG.md`
  - `git commit -m "chore(release): bump to 1.0.25 for security hardening (plan C)"`

---

## Final checklist for the executor

- [ ] Every task's tests were written BEFORE the implementation and observed to fail first.
- [ ] `composer test` is green (no failures/errors/risky/warnings).
- [ ] `vendor/bin/phpstan analyse` is green at the configured level.
- [ ] `cs:fix` was NOT run; PHPCS issues (if any) were fixed by hand.
- [ ] Version coordination with Plan B was confirmed before Task 14.
- [ ] No OAuth-callback changes were made here (those belong to Plan B), except the standalone HMAC domain separation (Task 9), which was implemented.
