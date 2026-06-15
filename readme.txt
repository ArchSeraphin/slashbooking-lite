=== SlashBooking ===
Contributors: slashbooking
Tags: booking, appointment, scheduling, reservations, calendar
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted online appointment booking for WordPress: a real-time public calendar via shortcode, with one-click email confirmation.

== Description ==

**SlashBooking** turns any WordPress page into a self-service appointment funnel. A visitor picks a service, sees the available slots in real time, and books in under a minute. You receive an email alert and confirm or decline in one click — no need to log in to WordPress, no third-party SaaS, no recurring fees.

It is a self-hosted scheduling tool: your data stays in your WordPress database.

= Main features =

* **Public booking form via shortcode** — A single `[slashbooking]` in any page. Multiple services, date picker, real-time slots, customer form, fully responsive.
* **One-click email confirmation** — Every request arrives with two buttons, *Confirm* and *Decline*. No WordPress login required. The action links are signed with HMAC-SHA256 and cannot be forged.
* **Smart buffers around appointments** — Configure padding (travel, prep) between appointments. Applied automatically.
* **Multi-slot opening hours** — Monday 9–12 + 14–18, Tuesday morning only, Wednesday off… Each day and each service is configured independently.
* **Transactional emails with .ics** — Confirmation, decline and acknowledgement emails, each with an automatic `.ics` attachment so the customer adds the appointment to their calendar in one click.
* **Built-in GDPR compliance** — Explicit consent before submission, WP_Privacy exporters/erasers, configurable retention, automatic anonymisation.
* **Built-in anti-spam** — Honeypot and per-IP rate limiting, with no third-party service and no external request.

= Who it is for =

* Freelancers and small businesses who want a self-hosted scheduling page
* Agencies packaging a website with a booking funnel
* Any business where "book a slot" opens the customer journey

== Installation ==

1. Upload the ZIP via **Plugins → Add New → Upload Plugin** (or unzip it into `wp-content/plugins/`).
2. Activate **SlashBooking** in the plugins list.
3. Configure it from the **SlashBooking** admin menu:
   * **Services** — appointment duration, before/after buffers, days and opening hours, display colour
   * **Settings** — booking form colours, consent message, GDPR retention, notification email
4. Paste `[slashbooking]` into any public page to display the booking form.

== Frequently Asked Questions ==

= How do emails get sent? =

By default via `wp_mail()`. If you have an SMTP plugin installed (WP Mail SMTP, FluentSMTP, etc.), SlashBooking uses it automatically — no extra configuration.

= Is a customer account required to book? =

No. Visitors book without creating an account. You collect only the fields you need (name, email, phone, etc.).

= Is it GDPR compliant? =

Yes. The form requires explicit consent, the plugin registers WordPress privacy data exporters and erasers, supports a configurable retention period, and anonymises old bookings automatically.

= Does it work with page caching plugins? =

Yes. The page that contains the widget is automatically excluded from page caching so the form keeps working.

= Does it survive a WordPress update? =

Yes. Database schemas are versioned and migrated automatically, and options are preserved.

== External services ==

This plugin is fully self-contained. It makes no calls to any external or third-party service and sends no data off your site — every request is handled on your own WordPress installation.

== Source code and build ==

The full, human-readable source code is public and maintained at:

https://github.com/ArchSeraphin/slashbooking-lite

The PHP runs unmodified from `src/`. The admin interface bundle
(`assets/dist/index.jsx.js` and `.css`) is compiled from the React/SCSS sources
in `src/Admin/react-app/src/`, which ship inside the plugin ZIP as well.

To regenerate the compiled admin assets:

`npm install`
`npm run build`   (uses @wordpress/scripts — webpack/Babel; outputs to assets/dist/)

The Composer autoloader in `vendor/` is generated with `composer install --no-dev`.

== Changelog ==

= 1.0.5 =
* **Internationalization.** The plugin's source strings are now in English (`en_US`) — the standard base locale for WordPress.org — and a complete **French (`fr_FR`)** translation ships with the plugin, covering the admin, the transactional emails, the public booking form and the settings app. The plugin can now be translated into any language via translate.wordpress.org.
* Updated the bundled plugin logo to the current SlashBooking calendar mark.

= 1.0.4 =
* Removed the optional Cloudflare Turnstile integration from the free edition. The plugin now makes **no external requests** and connects to **no third-party service** — it is fully self-contained. The built-in honeypot and per-IP rate limiting continue to protect the booking form.

= 1.0.3 =
* Hardened the code against the full WordPress.org Plugin Check ruleset: documented the trusted, prepared database queries in the data-access layer; annotated internal exception messages (caught/converted, never displayed); renamed the internal action hooks to an underscore prefix (`slashbooking_booking_*`); prefixed the loader variable in the main file; and documented the standard cache constant/hook and the opt-in Cloudflare Turnstile widget script (the one permitted external service).

= 1.0.2 =
* Renamed all plugin option names, the custom cron schedule and the rate-limit transient keys from the short `sb_` prefix to the unique `slashbooking_` prefix, to prevent collisions with other plugins or themes.
* Replaced the example logo-URL placeholder in the email settings (it was an `https://…/logo.png` string that a code scan could mistake for a remotely loaded file) with a plain text hint. The plugin never loaded that URL; it was only placeholder text inside a URL input the admin fills in.

= 1.0.1 =
* Public booking widget and dashboard widget styles are now enqueued (no inline `<style>`).
* The `.ics` calendar attachment is written to a hardened uploads subfolder instead of the system temp directory, and removed right after sending.
* Shortcode output uses `esc_url()` for the REST URL attribute.
* Admin menu moved to a lower position so it no longer sits among core items.
* Removed the redundant `load_plugin_textdomain()` call (translations load automatically on WordPress.org).
* Documented the public source repository and admin-bundle build steps; the React/SCSS sources now ship in the plugin.

= 1.0.0 =
First public release: shortcode booking form with real-time availability, one-click signed email confirmation (Confirm/Decline), `.ics` attachments, per-service opening hours and buffers, GDPR exporters/erasers and retention, honeypot + rate limiting, and optional Cloudflare Turnstile.

== Upgrade Notice ==

= 1.0.5 =
Adds a full French translation and switches the source language to English (en_US) so the plugin can be translated into any language on WordPress.org.

= 1.0.4 =
The free edition no longer bundles Cloudflare Turnstile and is now fully self-contained (no external services). Honeypot + rate limiting still protect the form.

= 1.0.3 =
Code-quality hardening to pass the full WordPress.org Plugin Check ruleset. No functional changes.

= 1.0.2 =
Naming-convention compliance for the WordPress.org review (unique `slashbooking_` prefix on all option names).

= 1.0.1 =
Compliance and best-practice fixes for the WordPress.org review.

= 1.0.0 =
First public release.
