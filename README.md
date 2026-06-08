# SlashBooking

Self-hosted online appointment booking for WordPress: a real-time public
calendar via shortcode, with one-click email confirmation.

This is the **free edition**, prepared for the
[WordPress.org plugin directory](https://wordpress.org/plugins/). A full
version with extra features (Google Calendar sync, customisable emails,
automatic reminders) is available at <https://slashbooking.fr/>.

## Features

- Public booking form via the `[slashbooking]` shortcode, with real-time
  slot availability.
- One-click email confirmation: signed (HMAC-SHA256) Confirm/Decline links,
  no login required.
- Transactional emails with automatic `.ics` calendar attachments.
- Per-service opening hours, durations and before/after buffers.
- GDPR: explicit consent, WP_Privacy exporters/erasers, configurable
  retention and automatic anonymisation.
- Anti-spam: honeypot, per-IP rate limiting, and optional Cloudflare
  Turnstile (bring your own keys).

No third-party runtime dependencies.

## Install (from source)

```bash
composer install
npm install && npm run build
```

Then symlink or copy this folder into `wp-content/plugins/` and activate
**SlashBooking**. Drop `[slashbooking]` into any page.

## Build a distribution ZIP

```bash
bash bin/build-release.sh
# → build/slashbooking-<version>.zip
```

## Develop

```bash
composer stan   # PHPStan
composer cs     # PHPCS
composer test   # unit tests
npm run lint:js # ESLint on the admin SPA
```

## License

GPL-2.0-or-later.
