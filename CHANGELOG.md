# Changelog

All notable changes to **SlashBooking** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

---

## [1.0.0] — 2026-06-08

First public release.

### Added

- Public booking form via the `[slashbooking]` shortcode, with real-time slot availability.
- One-click email confirmation: signed (HMAC-SHA256) Confirm/Decline links, no login required.
- Transactional emails with automatic `.ics` calendar attachments.
- Per-service opening hours, durations and before/after buffers.
- GDPR compliance: explicit consent, WP_Privacy exporters/erasers, configurable retention and automatic anonymisation.
- Anti-spam: honeypot, per-IP rate limiting, and optional Cloudflare Turnstile (bring your own keys).
