# SlashBooking — Stripe license automation ("brique C")

**Date:** 2026-06-05
**Status:** Approved (design)
**Repo:** `slashbooking-dashboard` (webhook + email + DB migration)
**Also touches:** `slashbooking-site` (Pro CTA wiring + `/merci` page), Stripe + Brevo + Plesk (ops config)

## Goal

Automate Pro license issuance end to end: a customer pays through Stripe, a webhook
creates the license in the shared SQLite store, and the key is emailed automatically.
No manual step. Renewals extend the license; cancellation lets it lapse naturally.

Pricing (already decided by the marketing handoff): **Pro at 5,99 €/month or
60 €/year** (annual = "2 months free"). Free tier needs no license.

## Scope

**This spec:**
- Stripe product/prices/Payment Links + webhook endpoint configuration.
- `POST /stripe/webhook` public route in the dashboard app (signature-verified).
- License creation + renewal extension driven by Stripe events.
- License key email via Brevo SMTP relay (nodemailer).
- Additive SQLite migration (3 nullable columns).
- Rewire the marketing site's "Passer à Pro" CTA (currently "Bientôt disponible")
  to the Payment Links; add a static `/merci` success page.

**Out of scope:**
- Refund/fraud handling — stays manual (revoke in the dashboard).
- Customer self-service beyond Stripe's no-code customer portal.
- Legal pages (CGV…) — separate task, noted in the prod runbook.
- Per-seat / multi-site pricing tiers.

## Architecture

```
slashbooking.fr (static)         Stripe                     dashboard.slashbooking.fr
┌──────────────────────┐   ┌───────────────────────┐   ┌─────────────────────────────┐
│ CTA Pro mensuel ─────┼──►│ Payment Link (monthly) │   │ POST /stripe/webhook        │
│ CTA Pro annuel  ─────┼──►│ Payment Link (yearly)  │──►│  1. verify signature        │
│ /merci (static)      │◄──┤ success_url → /merci   │   │  2. create/extend license   │
└──────────────────────┘   └───────────────────────┘   │  3. email key (Brevo SMTP)  │
                                                        └─────────────────────────────┘
```

The webhook lives in the dashboard app because license creation logic
(`lib/keygen`, `lib/licenses-repo`) and the writable SQLite connection already live
there. No new service, no code on the static site beyond two links.

## Stripe configuration (manual, one-time)

1. Product **SlashBooking Pro** with two recurring prices: **5,99 €/month** and
   **60 €/year**.
2. Two **Payment Links** (one per price). `success_url` →
   `https://slashbooking.fr/merci`. Stripe collects the customer email.
3. Webhook endpoint `https://dashboard.slashbooking.fr/stripe/webhook`, subscribed
   to `checkout.session.completed` and `invoice.paid`. Signing secret → env.
4. Enable the no-code **customer portal** login link (used in the license email so
   customers can manage/cancel their subscription).

## Webhook design

**Route:** `POST /stripe/webhook`, mounted in `app.js` BEFORE the auth gate (like
`/public`) and registered with `express.raw({ type: 'application/json' })` —
signature verification needs the raw body.

**Lifecycle model — expiry-driven (no state sync):**

| Event | Action |
|---|---|
| `checkout.session.completed` (mode `subscription`) | Generate key (`createUniqueKey`), create license: `plan` from price ID (`pro-monthly` / `pro-yearly`), `customer_email`/`customer_name` from the session, `max_sites = 1`, `expires` = subscription `current_period_end` **+ 3 days grace**, store `stripe_customer_id` + `stripe_subscription_id`. Send the key email; set `email_sent_at` on success. |
| `invoice.paid`, `billing_reason = subscription_cycle` | Find license by `stripe_subscription_id`, extend `expires` to the new period end + 3 days grace. |
| `invoice.paid`, `billing_reason = subscription_create` | No-op (creation handled by `checkout.session.completed`). |
| Cancellation / payment failure | **Nothing.** The license lapses when `expires` passes — exact subscription semantics. Immediate revocation (refund/fraud) stays a manual dashboard action. |

**Idempotency:** Stripe retries webhooks. Before creating, look up the license by
`stripe_subscription_id`: if it exists, no-op — except if `email_sent_at` is null,
retry sending the email (covers "license created but SMTP failed" retries).

**Responses:**
- Invalid signature → `400` (no retry).
- Unknown price ID on a completed checkout → log loudly, return `200` (avoid a
  retry storm; manual follow-up).
- Transient failure (SQLite, SMTP) → `500` so Stripe retries.
- Everything else → `200` fast.

## Data model (additive migration)

Three nullable columns on `licenses`, same idempotent `ADD_COLUMNS` pattern as
`max_sites` (must be mirrored in the broker's `lib/db.js` schema constant, but the
broker ignores columns it doesn't read — zero behavioral impact):

- `stripe_customer_id TEXT`
- `stripe_subscription_id TEXT`
- `email_sent_at TEXT`

New repo helpers: `findBySubscriptionId`, `extendExpiry`, `markEmailSent`,
and `createLicense` accepts the two Stripe IDs.

## Email (Brevo SMTP relay)

- `nodemailer` → `smtp-relay.brevo.com:587`, Brevo SMTP key auth.
- **Sender:** `SlashBooking <hello@slashbooking.fr>`.
- **Deliverability prerequisite:** authenticate `slashbooking.fr` in Brevo (add
  their DKIM/SPF DNS records in Plesk) before going live.
- Content (French): the `SB-…` key, how to activate it in the plugin settings,
  the Stripe customer portal link, support contact.
- The mailer is injected (`buildApp(cfg, { db, mailer })`, same pattern as
  `opts.db`) so tests mock it.

## Environment variables (Plesk, dashboard domain)

`STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_MONTHLY`,
`STRIPE_PRICE_YEARLY`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_KEY`,
`MAIL_FROM`. All required at boot **only if** Stripe routing is enabled — the
config keeps them optional so the dashboard still boots without Stripe configured
(feature-flag by presence of `STRIPE_WEBHOOK_SECRET`).

## Site changes (`slashbooking-site`)

- "Passer à Pro" CTA: replace the "Bientôt disponible" state with the two Payment
  Links, matching the monthly/annual toggle from the design handoff.
- New static `/merci` page: payment confirmed, "your key is on its way by email,
  check spam", support contact.

## Testing

- Jest + supertest, same conventions as the existing suite:
  - signature rejection (bad/missing header → 400, nothing created);
  - checkout → license created with correct plan/expiry/max_sites + email sent;
  - idempotent replay (no duplicate, email resent only if `email_sent_at` null);
  - `invoice.paid` cycle → expiry extended; `subscription_create` → no-op;
  - unknown price → 200 + no license.
  - Use `stripe.webhooks.generateTestHeaderString` to build valid signed payloads;
    mock the mailer via injection.
- E2E in Stripe **test mode** before live: pay with `4242…` through the real
  Payment Link, verify license row, email received, broker validates the key.

## Ops / deployment checklist

1. Brevo: authenticate domain, create SMTP key.
2. Stripe: product, prices, Payment Links, webhook endpoint (live mode), portal.
3. Plesk: add the env vars on `dashboard.slashbooking.fr`, Restart App.
4. Deploy dashboard (git pull + NPM Install via Plesk UI — see deployment memory:
   no build toolchain on the server, native deps need prebuilds).
5. Mirror the schema constant in `slashbooking-broker/lib/db.js`.
6. Site: deploy CTA + `/merci`.
7. Update the prod runbook: mark brique C done.

## Decisions log

- **Webhook host:** dashboard app (reuses keygen/repo/DB; broker untouched).
- **Checkout:** Payment Links (no checkout code, static site keeps zero JS).
- **Lifecycle:** expiry-driven; no action on cancellation; manual revoke for fraud.
- **`max_sites`:** 1 per Pro license (for now).
- **Grace period:** 3 days past `current_period_end`.
- **Email:** Brevo SMTP relay, sender `hello@slashbooking.fr`.
