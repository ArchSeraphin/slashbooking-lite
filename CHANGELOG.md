# Changelog

All notable changes to **SlashBooking** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

---

## [1.0.5] — 2026-06-15

### Added

- Complete **French (`fr_FR`)** translation bundled in `/languages` (`.po`/`.mo` plus a JS catalog for the admin app), covering the admin screens, the transactional emails, the public booking form and the settings SPA.

### Changed

- **The source language is now English (`en_US`).** Every user-facing string — PHP and the React admin app — was converted from French to English so that `en_US` is the canonical base locale (the WordPress.org standard), making the plugin translatable into any language via translate.wordpress.org. French is shipped as the first translation.
- Translations are loaded with `load_textdomain()` on `init` (PHP — loads the bundled `.mo` directly, without the discouraged `load_plugin_textdomain()`) and `wp_set_script_translations()` plus an injected locale catalog (admin app and public form).
- Replaced the bundled plugin logo assets (`assets/logo/*`) with the current SlashBooking calendar mark.

## [1.0.4] — 2026-06-12

### Removed

- The optional Cloudflare Turnstile anti-bot integration is removed from the free edition. The plugin now makes **no external requests** and connects to **no third-party service** — it is fully self-contained. The built-in honeypot and per-IP rate limiting continue to protect the booking form.
- Deleted `TurnstileVerifier`, `TurnstileNotice` and their tests; removed the Turnstile admin settings (site/secret keys), the public widget enqueue + front-end JS, and the `.sb-turnstile` styles. The "External services" readme section now states the plugin makes no external calls.

## [1.0.3] — 2026-06-12

Hardening pass against the full WordPress.org Plugin Check ruleset (stricter than the project's own `phpcs.xml.dist`). No functional changes.

### Changed

- Renamed the internal extensibility hooks from a slash-namespaced form to an underscore prefix (`slashbooking/booking_*` → `slashbooking_booking_*`) so the global-prefix sniff recognises them as valid; updated the in-plugin listeners accordingly.
- Prefixed the loader variable in the main plugin file (`$autoload` → `$slashbooking_autoload`) to avoid polluting the global scope.
- Renamed the Cloudflare Turnstile script handle (`sb-turnstile` → `slashbooking-turnstile`).

### Documented (annotations, no behaviour change)

- Data-access layer (`*Repository`, `Activator`): added justified `phpcs:ignore`/`disable` for the `WordPress.DB.PreparedSQL*` and `PluginCheck.Security.DirectDB` sniffs — table names come from `$wpdb->prefix` (trusted) and every user value is bound through `$wpdb->prepare()`.
- Internal domain/application exceptions: annotated `WordPress.Security.EscapeOutput.ExceptionNotEscaped` — the messages are caught and converted to `WP_Error`/logs, never echoed to the browser.
- `CacheCompat`: annotated the de-facto-standard `DONOTCACHEPAGE` constant and the LiteSpeed `litespeed_control_set_nocache` hook (third-party integration points that cannot be prefixed).
- `Shortcode`: annotated the opt-in Cloudflare Turnstile widget enqueue — the only permitted external resource (a CAPTCHA service whose script must load from Cloudflare's CDN), already disclosed in the readme "External services" section.

## [1.0.2] — 2026-06-12

Second WordPress.org review compliance pass (naming conventions + remote-file false positive).

### Changed

- Renamed every plugin option from the short `sb_` prefix to the unique `slashbooking_` prefix (`slashbooking_decision_secret`, `slashbooking_legal_page_id`, `slashbooking_booking_retention_days`, `slashbooking_notification_email`, `slashbooking_company_logo`, `slashbooking_company_phone`, `slashbooking_form_disclaimer`, `slashbooking_form_primary_color`, `slashbooking_form_accent_color`, `slashbooking_turnstile_site_key`, `slashbooking_turnstile_secret_key`, `slashbooking_db_version`). The custom cron schedule (`sb_monthly` → `slashbooking_monthly`) and the rate-limit transient keys (`sb_rate_*` → `slashbooking_rate_*`) were renamed the same way. Prefixes are now ≥ 4 characters and distinct to the plugin.
- Replaced the example logo-URL placeholder (`https://exemple.com/logo.png`) in the email settings with a plain-text hint, so an automated scan no longer reads it as a remotely loaded file. The string was only placeholder text inside a URL `<TextControl>` the admin fills in; the plugin never fetched it. The admin bundle (`assets/dist/`) was rebuilt.

## [1.0.1] — 2026-06-11

WordPress.org review compliance pass.

### Changed

- Public booking-widget and dashboard-widget CSS is now enqueued via `wp_enqueue_style()` / `wp_add_inline_style()` instead of inline `<style>` tags.
- `.ics` calendar attachments are written to a hardened subfolder of the uploads directory (with an `index.html` guard) and deleted immediately after sending, instead of the system temp directory.
- Shortcode output escapes the REST URL attribute with `esc_url()` (output context) instead of `esc_url_raw()`.
- The admin menu uses a lower position so it no longer competes with core menu items.

### Removed

- The redundant `load_plugin_textdomain()` call — WordPress loads translations automatically for plugins hosted on WordPress.org.

### Added

- Documentation of the public source repository and the admin-bundle build steps; the React/SCSS sources now ship in the plugin ZIP alongside the compiled assets.

## [1.0.0] — 2026-06-08

First public release.

### Added

- Public booking form via the `[slashbooking]` shortcode, with real-time slot availability.
- One-click email confirmation: signed (HMAC-SHA256) Confirm/Decline links, no login required.
- Transactional emails with automatic `.ics` calendar attachments.
- Per-service opening hours, durations and before/after buffers.
- GDPR compliance: explicit consent, WP_Privacy exporters/erasers, configurable retention and automatic anonymisation.
- Anti-spam: honeypot, per-IP rate limiting, and optional Cloudflare Turnstile (bring your own keys).
