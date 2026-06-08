=== SlashBooking ===
Contributors: slashbooking
Tags: booking, appointment, scheduling, reservations, calendar
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
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
* **Optional anti-spam** — Built-in honeypot, per-IP rate limiting, and optional Cloudflare Turnstile (you provide your own keys).

= Who it is for =

* Freelancers and small businesses who want a self-hosted scheduling page
* Agencies packaging a website with a booking funnel
* Any business where "book a slot" opens the customer journey

== Installation ==

1. Upload the ZIP via **Plugins → Add New → Upload Plugin** (or unzip it into `wp-content/plugins/`).
2. Activate **SlashBooking** in the plugins list.
3. Configure it from the **SlashBooking** admin menu:
   * **Services** — appointment duration, before/after buffers, days and opening hours, display colour
   * **Settings** — booking form colours, consent message, GDPR retention, notification email, optional Cloudflare Turnstile keys
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

This plugin can optionally connect to one third-party service. It is **disabled by default** and only used if you choose to enable it.

**Cloudflare Turnstile (optional anti-bot check)**

If — and only if — you enter Cloudflare Turnstile keys in the plugin settings, the public booking form loads the Turnstile widget from Cloudflare, and when a visitor submits the form the plugin sends the Turnstile response token together with the visitor's IP address to Cloudflare's verification endpoint (`https://challenges.cloudflare.com/turnstile/v0/siteverify`) to confirm the request is not a bot. No data is sent to Cloudflare when Turnstile is not configured.

- Cloudflare Terms of Service: https://www.cloudflare.com/terms/
- Cloudflare Privacy Policy: https://www.cloudflare.com/privacypolicy/

== Changelog ==

= 1.0.0 =
First public release: shortcode booking form with real-time availability, one-click signed email confirmation (Confirm/Decline), `.ics` attachments, per-service opening hours and buffers, GDPR exporters/erasers and retention, honeypot + rate limiting, and optional Cloudflare Turnstile.

== Upgrade Notice ==

= 1.0.0 =
First public release.
