# Changelog

All notable changes to **SlashBooking** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

---

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
