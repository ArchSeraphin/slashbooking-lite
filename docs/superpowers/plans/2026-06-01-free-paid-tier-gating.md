# Free / Paid Tier Gating — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gate Google sync, email-template customization, and automatic reminders behind a valid SlashBooking license (Paid), leaving everything else Free.

**Architecture:** A single source of truth `Config::isPaid()` (= `sb_license_status === 'valid'`). Each Paid feature is enforced server-side (the UI lock is cosmetic on top). The SPA reads `isPaid` from the localized bootstrap and locks the email-templates section with a reusable `<PaidLock>` upsell.

**Tech Stack:** PHP 8.1 (WordPress plugin, PSR-12), PHPUnit, PHPStan, React (`@wordpress/element` + `@wordpress/components`), webpack via `wp-scripts`.

**Spec:** `docs/superpowers/specs/2026-06-01-free-paid-tier-gating-design.md`

**Conventions for this repo:**
- Do NOT run `composer cs:fix` (custom PHpCS config). Write PSR-12 by hand.
- `composer test` runs the **unit** suite (`phpunit --testsuite unit --bootstrap tests/bootstrap.php`). Integration tests need `/tmp/wordpress-tests-lib` (absent) and do not run locally or in CI.
- PHPStan: `vendor/bin/phpstan analyse --memory-limit=2G` — there are **2 pre-existing** `Updates/UpdateChecker.php` errors that are expected (baseline). Your changes must add **0 new** errors.
- `final` + repo-bound classes (`BookingRepository`, `MailTemplateRepository`) are not unit-mockable; the repo's convention is to verify those via PHPStan + review rather than unit tests. Only `Config::isPaid()` gets a real unit test here.

---

### Task 1: `Config::isPaid()` + unit test

**Files:**
- Modify: `src/Config.php`
- Test: `tests/Unit/Google/ConfigTest.php`

- [ ] **Step 1: Write the failing test**

Append this test method inside the `ConfigTest` class in `tests/Unit/Google/ConfigTest.php` (after `test_trailing_slash_is_stripped`):

```php
    public function test_isPaid_is_true_only_for_a_valid_license(): void
    {
        $GLOBALS['__sb_options'] = ['sb_license_status' => 'valid'];
        self::assertTrue(Config::isPaid());

        foreach (['absent', 'invalid', 'unknown', ''] as $status) {
            $GLOBALS['__sb_options'] = ['sb_license_status' => $status];
            self::assertFalse(Config::isPaid(), "status={$status}");
        }

        // Option missing entirely -> default 'absent' -> not paid.
        $GLOBALS['__sb_options'] = [];
        self::assertFalse(Config::isPaid());
    }
```

And add a `get_option` stub next to the existing `apply_filters` stub at the bottom of the file (inside the `namespace Slash\Booking;` block):

```php
if (!function_exists('Slash\Booking\get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['__sb_options'][$name] ?? $default;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Google/ConfigTest.php --filter test_isPaid`
Expected: FAIL — `Error: Call to undefined method Slash\Booking\Config::isPaid()`.

- [ ] **Step 3: Implement `isPaid()` in `src/Config.php`**

Add this method to the `Config` class (after `brokerUrl()`):

```php
    /**
     * True when the install holds a valid (Paid) SlashBooking license.
     * Single source of truth for Free vs Paid feature gating.
     */
    public static function isPaid(): bool
    {
        return (string) get_option('sb_license_status', 'absent') === 'valid';
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Google/ConfigTest.php`
Expected: PASS (all ConfigTest methods green).

- [ ] **Step 5: Commit**

```bash
git add src/Config.php tests/Unit/Google/ConfigTest.php
git commit -m "feat(tier): add Config::isPaid() license gate + unit test"
```

---

### Task 2: Gate automatic reminders

**Files:**
- Modify: `src/Notifications/ReminderScheduler.php`

The daily reminder cron must do nothing in Free. The cron stays scheduled (cheap no-op) and resumes automatically on upgrade.

- [ ] **Step 1: Add the gate at the top of `run()`**

In `src/Notifications/ReminderScheduler.php`, add the import and the early return.

Add to the `use` block at the top (after the existing `use Slash\Booking\Persistence\BookingRepository;`):

```php
use Slash\Booking\Config;
```

Change the start of `run()` from:

```php
    public function run(): void
    {
        $now   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
```

to:

```php
    public function run(): void
    {
        // Paid feature: no automatic J-1 reminders in Free.
        if (!Config::isPaid()) {
            return;
        }

        $now   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
```

- [ ] **Step 2: Verify syntax + no new PHPStan errors**

Run: `php -l src/Notifications/ReminderScheduler.php`
Expected: `No syntax errors detected`.

Run: `vendor/bin/phpstan analyse --memory-limit=2G src/Notifications/ReminderScheduler.php src/Config.php`
Expected: `[OK] No errors` (this path has no baseline errors).

- [ ] **Step 3: Commit**

```bash
git add src/Notifications/ReminderScheduler.php
git commit -m "feat(tier): skip automatic reminders in Free"
```

---

### Task 3: Gate email-template customization (server-side)

**Files:**
- Modify: `src/Http/AdminMailTemplateController.php`

Block the mutating/customization routes — `save` (POST), `restore` (DELETE), and `test` (POST, sends a custom test email) — when not Paid. Keep `list`/`get`/`preview` on the capability check so the locked UI can still render read-only.

- [ ] **Step 1: Add the import**

In `src/Http/AdminMailTemplateController.php`, add to the `use` block (after `use Slash\Booking\Admin\Capabilities;`):

```php
use Slash\Booking\Config;
```

- [ ] **Step 2: Add a Paid permission callback and apply it to the mutating routes**

Change `registerRoutes()` from:

```php
        $cap = static fn (): bool => current_user_can(Capabilities::MANAGE);
        $ns  = Plugin::REST_NAMESPACE;

        register_rest_route($ns, '/admin/mail-templates', [
            ['methods' => 'GET', 'callback' => [$this, 'list'], 'permission_callback' => $cap],
        ]);
        register_rest_route($ns, '/admin/mail-templates/(?P<event_key>[a-z0-9_.]+)', [
            ['methods' => 'GET',    'callback' => [$this, 'get'],     'permission_callback' => $cap],
            ['methods' => 'POST',   'callback' => [$this, 'save'],    'permission_callback' => $cap],
            ['methods' => 'DELETE', 'callback' => [$this, 'restore'], 'permission_callback' => $cap],
        ]);
        register_rest_route($ns, '/admin/mail-templates/(?P<event_key>[a-z0-9_.]+)/preview', [
            ['methods' => 'POST', 'callback' => [$this, 'preview'], 'permission_callback' => $cap],
        ]);
        register_rest_route($ns, '/admin/mail-templates/(?P<event_key>[a-z0-9_.]+)/test', [
            ['methods' => 'POST', 'callback' => [$this, 'test'], 'permission_callback' => $cap],
        ]);
```

to:

```php
        $cap = static fn (): bool => current_user_can(Capabilities::MANAGE);
        // Email template customization is a Paid feature: editing/restoring/testing
        // requires both the manage capability AND a valid license. Reading (list/get)
        // and read-only preview stay on the capability check so the locked UI renders.
        $paidCap = static fn (): bool => current_user_can(Capabilities::MANAGE) && Config::isPaid();
        $ns  = Plugin::REST_NAMESPACE;

        register_rest_route($ns, '/admin/mail-templates', [
            ['methods' => 'GET', 'callback' => [$this, 'list'], 'permission_callback' => $cap],
        ]);
        register_rest_route($ns, '/admin/mail-templates/(?P<event_key>[a-z0-9_.]+)', [
            ['methods' => 'GET',    'callback' => [$this, 'get'],     'permission_callback' => $cap],
            ['methods' => 'POST',   'callback' => [$this, 'save'],    'permission_callback' => $paidCap],
            ['methods' => 'DELETE', 'callback' => [$this, 'restore'], 'permission_callback' => $paidCap],
        ]);
        register_rest_route($ns, '/admin/mail-templates/(?P<event_key>[a-z0-9_.]+)/preview', [
            ['methods' => 'POST', 'callback' => [$this, 'preview'], 'permission_callback' => $cap],
        ]);
        register_rest_route($ns, '/admin/mail-templates/(?P<event_key>[a-z0-9_.]+)/test', [
            ['methods' => 'POST', 'callback' => [$this, 'test'], 'permission_callback' => $paidCap],
        ]);
```

(A blocked route returns WordPress's standard `rest_forbidden` 401/403 — the UI never calls these in Free because the editor is locked in Task 5; this is the server backstop.)

- [ ] **Step 3: Verify syntax + no new PHPStan errors**

Run: `php -l src/Http/AdminMailTemplateController.php`
Expected: `No syntax errors detected`.

Run: `vendor/bin/phpstan analyse --memory-limit=2G src/Http/AdminMailTemplateController.php`
Expected: `[OK] No errors`.

- [ ] **Step 4: Commit**

```bash
git add src/Http/AdminMailTemplateController.php
git commit -m "feat(tier): require Paid license to edit/restore/test email templates"
```

---

### Task 4: Gate active Google sync (downgrade guard)

**Files:**
- Modify: `src/Plugin.php`

A Free install can never connect Google (connect is already gated). This guard covers the **downgrade** case (was Paid, license lapsed, account still connected): pause sync without deleting data. All sync — inbound pull AND outbound reflection-push — flows through the `sb/google_pull` action (SyncEngine has only `pull()`), so one guard there covers everything automatic; the manual "pull now" admin path gets the same guard.

- [ ] **Step 1: Guard the `sb/google_pull` action handler**

In `src/Plugin.php`, find the `add_action('sb/google_pull', static function (int $accountId) use (...)` handler. Immediately after the opening `): void {` line of that closure, add:

```php
            // Paid feature: pause all Google sync when the license is not valid
            // (downgrade). Data is preserved; sync resumes on re-validation.
            if (!Config::isPaid()) {
                return;
            }
```

- [ ] **Step 2: Guard the manual "pull now" closure**

In `src/Plugin.php`, find the `$pullNow = static function (Domain\GoogleAccount $account) use (...): Google\PullResult {` closure. Immediately after its opening `{`, add:

```php
                if (!Config::isPaid()) {
                    return new Google\PullResult(0, 0, 0, 0, 'Sync is a paid feature.');
                }
```

> **Implementer note:** open `src/Google/PullResult.php` and match its real constructor signature before writing this line. If the constructor differs from `(int, int, int, int, string)`, construct it with the correct arguments representing a no-op result (all counters zero). If `PullResult` cannot represent a message, construct the zero-counter result without one. Do not invent fields.

- [ ] **Step 3: Confirm `Config` is usable in `Plugin.php`**

`Plugin.php` is in namespace `Slash\Booking`, so `Config::isPaid()` resolves to `Slash\Booking\Config` with no import needed (same as the existing `Config::brokerUrl()` usage in this file). Verify a `Config::` reference already exists; if not, the unqualified `Config` still resolves correctly within this namespace.

- [ ] **Step 4: Verify syntax + no new PHPStan errors**

Run: `php -l src/Plugin.php`
Expected: `No syntax errors detected`.

Run: `vendor/bin/phpstan analyse --memory-limit=2G` (full run)
Expected: exactly the 2 pre-existing `Updates/UpdateChecker.php` errors, 0 new.

- [ ] **Step 5: Commit**

```bash
git add src/Plugin.php
git commit -m "feat(tier): pause Google sync when license lapses (downgrade guard)"
```

---

### Task 5: SPA — expose `isPaid`, add `<PaidLock>`, lock the templates section

**Files:**
- Modify: `src/Admin/Assets.php`
- Create: `src/Admin/react-app/src/PaidLock.jsx`
- Modify: `src/Admin/react-app/src/TemplatesPage.jsx`
- Modify: `src/Admin/react-app/src/styles.scss`

- [ ] **Step 1: Expose the tier to the SPA bootstrap**

In `src/Admin/Assets.php`, change the localize array from:

```php
        wp_localize_script('slashbooking-admin', 'SlashBooking', [
            'restUrl' => esc_url_raw(rest_url(Plugin::REST_NAMESPACE)),
            'nonce'   => wp_create_nonce('wp_rest'),
            'version' => Plugin::VERSION,
        ]);
```

to:

```php
        wp_localize_script('slashbooking-admin', 'SlashBooking', [
            'restUrl'       => esc_url_raw(rest_url(Plugin::REST_NAMESPACE)),
            'nonce'         => wp_create_nonce('wp_rest'),
            'version'       => Plugin::VERSION,
            'isPaid'        => \Slash\Booking\Config::isPaid(),
            'licenseStatus' => (string) get_option('sb_license_status', 'absent'),
        ]);
```

- [ ] **Step 2: Create the reusable `<PaidLock>` component**

Create `src/Admin/react-app/src/PaidLock.jsx`:

```jsx
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Wraps a Paid-only section. When `locked`, shows an upsell notice and renders
 * the children visually disabled (dimmed + non-interactive). Otherwise renders
 * the children unchanged.
 */
export default function PaidLock( { locked, message, children } ) {
	if ( ! locked ) {
		return children;
	}
	return (
		<div className="sb-paidlock">
			<Notice status="warning" isDismissible={ false }>
				{ '🔒 ' }
				{ message ||
					__(
						'Disponible dans la version payante de SlashBooking.',
						'slashbooking'
					) }
			</Notice>
			<div className="sb-paidlock__content" aria-disabled="true">
				{ children }
			</div>
		</div>
	);
}
```

- [ ] **Step 3: Lock the templates card + editor in `TemplatesPage.jsx`**

In `src/Admin/react-app/src/TemplatesPage.jsx`:

Add the import after `import FormSettings from './FormSettings';`:

```jsx
import PaidLock from './PaidLock';
```

Add this line at the top of the component body (right after `export default function TemplatesPage() {`):

```jsx
	const isPaid = window.SlashBooking?.isPaid ?? false;
```

Change the `selected` editor guard from:

```jsx
	if ( selected ) {
		return (
			<TemplateEditor
				eventKey={ selected }
				onClose={ () => {
					setSelected( null );
					reload();
				} }
			/>
		);
	}
```

to:

```jsx
	if ( selected && isPaid ) {
		return (
			<TemplateEditor
				eventKey={ selected }
				onClose={ () => {
					setSelected( null );
					reload();
				} }
			/>
		);
	}
```

Wrap ONLY the templates `<Card>` (NOT `<EmailSettings />` / `<FormSettings />`, which stay Free) with `<PaidLock>`. Change:

```jsx
			<EmailSettings />
			<FormSettings />

			<Card>
				<CardHeader>
					<h2>{ __( 'Templates e-mail', 'slashbooking' ) }</h2>
				</CardHeader>
```

to:

```jsx
			<EmailSettings />
			<FormSettings />

			<PaidLock
				locked={ ! isPaid }
				message={ __(
					'La personnalisation des e-mails est disponible en version payante.',
					'slashbooking'
				) }
			>
			<Card>
				<CardHeader>
					<h2>{ __( 'Templates e-mail', 'slashbooking' ) }</h2>
				</CardHeader>
```

And close the `<PaidLock>` right after that `</Card>` closes. Change:

```jsx
				</CardBody>
			</Card>
		</div>
	);
}
```

to:

```jsx
				</CardBody>
			</Card>
			</PaidLock>
		</div>
	);
}
```

- [ ] **Step 4: Add the dim style**

Append to `src/Admin/react-app/src/styles.scss`:

```scss
.sb-paidlock__content {
	opacity: 0.5;
	pointer-events: none;
	user-select: none;
}
```

- [ ] **Step 5: Build the SPA and verify it compiles**

Run: `npm run build`
Expected: `webpack ... compiled` with no errors (size warnings are fine). `assets/dist/index.jsx.js` is regenerated.

- [ ] **Step 6: Commit**

```bash
git add src/Admin/Assets.php src/Admin/react-app/src/PaidLock.jsx src/Admin/react-app/src/TemplatesPage.jsx src/Admin/react-app/src/styles.scss assets/dist
git commit -m "feat(tier): lock email-template customization in the admin UI (PaidLock)"
```

---

### Task 6: Version bump, changelog, full verification, release

**Files:**
- Modify: `src/Plugin.php` (VERSION), `slashbooking.php` (header), `readme.txt` (Stable tag + changelog), `CHANGELOG.md`

This is a new feature (tiering) → minor bump **1.2.0**.

- [ ] **Step 1: Bump the version (3 spots that must agree)**

`src/Plugin.php`: `public const VERSION = '1.1.1';` → `public const VERSION = '1.2.0';`
`slashbooking.php`: ` * Version: 1.1.1` → ` * Version: 1.2.0`
`readme.txt`: `Stable tag: 1.1.1` → `Stable tag: 1.2.0`

- [ ] **Step 2: Add the readme.txt changelog entry**

In `readme.txt`, directly after the `== Changelog ==` line, insert:

```
= 1.2.0 =
*Versions Free et Payante.* La version gratuite couvre la prise de RDV et les e-mails transactionnels (modèles par défaut). La version payante (clé de licence valide) débloque la synchronisation Google Calendar, la personnalisation des e-mails et les rappels automatiques J-1.

```

- [ ] **Step 3: Add the CHANGELOG.md section**

In `CHANGELOG.md`, directly before `## [1.1.1] — 2026-06-01`, insert:

```markdown
## [1.2.0] — 2026-06-01

### Added

- **Paliers Free / Payant.** Un palier gratuit et un palier payant (licence valide). Le palier payant débloque : synchronisation Google Calendar, personnalisation des modèles d'e-mail, rappels automatiques J-1. Source de vérité unique `Config::isPaid()` (= `sb_license_status === 'valid'`).

### Changed

- La synchronisation Google se met en pause si la licence n'est plus valide (downgrade) — données conservées, reprise automatique à la re-validation.
- L'édition/restauration/test des modèles d'e-mail exige une licence valide (les modèles par défaut restent utilisés en Free). Le callback REST OAuth + les routes mail-templates appliquent le verrou côté serveur ; la SPA verrouille la section avec un encart « version payante ».

---

```

- [ ] **Step 4: Full verification**

Run: `composer test`
Expected: `OK (178 tests, ...)` — 177 existing + 1 new `isPaid` test, all green.

Run: `vendor/bin/phpstan analyse --memory-limit=2G`
Expected: exactly the 2 pre-existing `Updates/UpdateChecker.php` errors, 0 new.

Run: `npm run build`
Expected: compiles with no errors.

- [ ] **Step 5: Commit the release bump**

```bash
git add src/Plugin.php slashbooking.php readme.txt CHANGELOG.md
git commit -m "chore(release): bump to 1.2.0 (Free/Paid tier gating)"
```

- [ ] **Step 6: Tag and release (only after all checks are green)**

```bash
git push origin main
git tag -a v1.2.0 -m "SlashBooking 1.2.0 — Free/Paid tier gating"
git push origin v1.2.0
```

Then watch the release workflow:

```bash
gh run watch "$(gh run list --workflow=release.yml --limit 1 --json databaseId --jq '.[0].databaseId')" --exit-status --interval 15
```

Expected: green; `gh release view v1.2.0` shows `slashbooking-1.2.0.zip` + `.sha256`.

---

## Self-Review

**Spec coverage:**
- Tier signal `Config::isPaid()` → Task 1. ✓
- Google connect gate (unchanged) → noted; no task needed (already enforced). ✓
- Active sync downgrade guard → Task 4. ✓
- Email customization server gate → Task 3. ✓
- Reminders gate → Task 2. ✓
- Transactional emails unchanged → no task (correct; nothing to do). ✓
- UI `is_paid` + `<PaidLock>` + locked email section → Task 5. ✓
- Non-goals (pricing, multi-tier, capabilities) → respected; no tasks. ✓

**Placeholder scan:** No TBD/TODO. The only deferred detail is `PullResult`'s exact constructor (Task 4 Step 2), with an explicit instruction to read the real signature first rather than guess — this is a guard against inventing an API, not a placeholder.

**Type consistency:** `Config::isPaid()` signature identical across Tasks 1–5. SPA reads `window.SlashBooking.isPaid` (set in Task 5 Step 1, consumed in Task 5 Step 3). `<PaidLock>` prop names (`locked`, `message`, `children`) consistent between definition (Step 2) and use (Step 3).

**Scope:** Single plugin, one coherent feature, ~6 small tasks. Appropriately sized for one plan.
