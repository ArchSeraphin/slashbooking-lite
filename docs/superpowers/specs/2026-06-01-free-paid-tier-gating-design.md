# SlashBooking — Free / Paid tier gating

**Date:** 2026-06-01
**Status:** Approved (design)
**Scope:** Plugin only (`plugins-booking`). No broker, no site changes.

## Goal

Introduce a binary **Free vs Paid** tier in the plugin. The Paid tier (a valid
SlashBooking license) unlocks everything. The Free tier (no/invalid license)
loses three capabilities:

1. **Google Calendar sync** — already gated at connect time today.
2. **Email template customization** — Free uses the built-in default templates,
   cannot edit them.
3. **Automatic J-1 reminder emails** — not sent in Free.

Transactional booking emails (pending / confirmed / rejected / cancelled) **are
still sent in Free**, using the default templates. Only their *customization* and
the *reminder* are Paid.

## Tier signal — single source of truth

Add `Config::isPaid(): bool`:

```php
public static function isPaid(): bool
{
    return (string) get_option('sb_license_status', 'absent') === 'valid';
}
```

`valid` → Paid. `absent` / `invalid` / `unknown` → Free. This reuses the exact
signal already used to gate Google connect (`AdminGoogleController::start`) and
kept fresh by the existing daily license re-validation cron.

## Enforcement — server-side first

UI hiding is not a gate. Each Paid feature is enforced on the server; the UI
locks are cosmetic on top.

| Feature | Free behaviour | Server enforcement point |
|---|---|---|
| Google connect | blocked | **Unchanged** — `AdminGoogleController::start()` already returns `license_required` (403) when `sb_license_status !== 'valid'`. |
| Active Google sync (downgrade guard) | no-op | Scheduled pull cron (`sb/google_pull_all`), webhook-triggered pull, and outbound push short-circuit when `!Config::isPaid()`. Data is preserved; sync simply pauses. Resumes automatically on upgrade. |
| Email customization | read-only | `AdminMailTemplateController`: the **mutating** routes — `save` (POST) and `restore` (DELETE) — return `WP_Error('paid_feature', …, 403)` when `!isPaid()`. `list`/`get`/`preview` (GET + read-only preview) stay on the capability check so the locked UI can still render. |
| Auto reminders | not sent | `ReminderScheduler::run()` returns early when `!isPaid()`. The cron stays scheduled (cheap no-op) and resumes on upgrade. |
| Transactional emails | sent (defaults) | None — unchanged. `BookingNotifier` already falls back to `DefaultTemplates` when no custom template is stored. |

## UI (admin SPA)

- Expose the tier to the SPA through the existing admin bootstrap data
  (`is_paid` alongside `license_status`).
- Add a small reusable `<PaidLock>` component: an overlay/banner
  “🔒 Disponible en version payante” shown over a Paid-only section.
- Wrap the **email-templates** editor with `<PaidLock>` when `!is_paid`
  (section visible but disabled — chosen UX: "visible + locked + upsell").
- Google page already renders its own license-required state — leave as is.
- Reminders are automatic (no dedicated editor): enforcement is backend-only.
  If a reminders mention exists in settings UI, add a one-line locked note.

## Edge cases

- **Downgrade** (Paid → license lapses, status flips to `invalid` via the daily
  re-validation): all data is preserved (connected Google account, any saved
  custom templates). Active use stops — sync pauses, reminders stop, template
  editing locks. Re-validating a license restores everything.
- **License flips mid-session**: gates read `get_option` live each call, so the
  next request reflects the new state. No caching to invalidate.

## Non-goals

- Pricing / sales page (that is the separate site + dashboard epic).
- Multiple paid tiers — strictly binary Free/Paid. The broker's `plan` field
  stays available for future multi-tier work but is not consulted here.
- WP capability mapping for the tier (over-engineering for a boolean).

## Testing

- Unit-test `Config::isPaid()` for each `sb_license_status` value (extend
  `tests/Unit/Google/ConfigTest.php`).
- Unit-test the gate branches where classes allow it (e.g. `ReminderScheduler::run()`
  early-return when not paid; mail-template mutation rejected when not paid),
  following existing controller/test patterns. Where a class is `final` and
  repo-bound (not mockable), rely on PHPStan + review, consistent with the repo's
  established approach.
- Verify no regression: 177 unit tests stay green; PHPStan baseline unchanged.

## Files in scope (indicative)

- `src/Config.php` — add `isPaid()`.
- `src/Http/AdminMailTemplateController.php` — gate mutating routes.
- `src/Notifications/ReminderScheduler.php` — gate `run()`.
- Google sync cron/webhook/push entry points — add `isPaid()` short-circuit.
- Admin SPA bootstrap + `src/Admin/react-app/src/` — expose `is_paid`, add `<PaidLock>`, lock the email-templates section.
- `tests/Unit/...`, `CHANGELOG.md`, version bump (patch).
