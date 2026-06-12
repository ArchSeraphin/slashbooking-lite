# Changelog

All notable changes to **SlashBooking** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

---

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
