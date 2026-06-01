# SlashBooking marketing site (brique D, v1)

**Date:** 2026-06-01
**Status:** Approved (design)
**New repo:** `slashbooking-site` (static one-pager for `slashbooking.fr`)
**Source:** `plugins-booking/design_handoff_slashbooking/` (hi-fi handoff)

## Goal

Ship the public marketing one-pager on `slashbooking.fr` from the approved hi-fi
design handoff: productionize the static prototype, wire the real CTAs, self-host
fonts (RGPD), and deploy as a static site on Plesk (root domain; the admin
dashboard stays on the `dashboard.slashbooking.fr` subdomain).

## Scope (v1)

- Static site only — no backend, no build step. HTML + CSS + a little vanilla JS.
- Productionize the handoff (`SlashBooking.html` → `index.html`, `sb-styles.css`,
  `sb-app.js`, logo assets) into a clean repo.
- Wire CTAs (see below).
- Self-host the three Google fonts.
- Host the plugin ZIP for the free download.

## Out of scope (separate work)
- Stripe checkout / Pro purchase — **brique C, next**. The Pro CTA is a "bientôt"
  state in this v1 and gets rewired to checkout in brique C.
- Email capture / waitlist — explicitly dropped (brique C follows immediately).
- Legal pages (mentions légales / CGV / privacy) for slashbooking.fr — later. (A
  privacy page already exists on `slashbox.fr/slashbooking/` for Google's consent
  screen; adapt for slashbooking.fr when needed.)

## CTA wiring

The handoff CTAs currently all point at `#tarifs`. Final wiring:

- **"Télécharger gratuitement"** (nav, hero, and the **Free** pricing card):
  link to the hosted plugin ZIP `downloads/slashbooking.zip` (a `download`
  attribute triggers download). The free download IS the plugin — it runs in Free
  mode without a license; a Pro license unlocks the paid features. Add a short
  **"Installation"** sub-section (3 steps: download → WP Extensions → Téléverser)
  so users know what to do after downloading.
- **"Passer à Pro"** (nav, hero): scroll to `#tarifs`.
- **Pro pricing card** CTA: a non-functional **"Bientôt disponible"** state
  (disabled pill + small note "Paiement en ligne très bientôt"). No email capture.
  Rewired to the real checkout in brique C.

## Fonts (RGPD — self-hosted)

Remove the Google Fonts `<link>` (preconnect + css2) from the `<head>`. Download
the woff2 files for **Space Grotesk** (400/500/600/700), **Manrope**
(400/500/600/700), **JetBrains Mono** (400/500/600), store them under
`fonts/`, and add `@font-face` rules (with `font-display: swap`) at the top of
`sb-styles.css`. No visitor IP leaves to Google.

## Plugin ZIP hosting

Copy the latest plugin release ZIP to `downloads/slashbooking.zip` (currently
v1.2.0 — fetch from the GitHub release of `ArchSeraphin/slashbooking`). Document
that this must be refreshed on each plugin release (a small `Makefile`/README
note; automation can come later).

## Deployment

Static hosting on Plesk for `slashbooking.fr` (root), same subscription as the
broker/dashboard. The doc root serves `index.html` + assets. No Node app for the
site. HTTPS via Let's Encrypt.

## Repo / files

- `slashbooking-site/` (new repo): `index.html` (from `SlashBooking.html`),
  `sb-styles.css` (+ `@font-face`), `sb-app.js`, `assets/logo/*`, `fonts/*.woff2`,
  `downloads/slashbooking.zip`, `README.md` (deploy + ZIP-refresh notes), `.gitignore`.
- The handoff `design_handoff_slashbooking/` stays as the design reference; the
  site repo is the productionized output.

## Testing / acceptance

No automated tests (static site). Acceptance = manual:
- Open `index.html` locally: renders identically to the handoff, fonts load from
  `fonts/` (no network call to fonts.gstatic.com — check devtools), no console errors.
- "Télécharger gratuitement" downloads `slashbooking.zip`.
- "Passer à Pro" → pricing; Pro card shows "Bientôt disponible".
- All nav anchors + the interactive bits from `sb-app.js` (mobile burger, pricing
  toggle, FAQ accordion, calendar demo, scroll reveal) still work.
- Responsive at the handoff breakpoints (1000/980/720/460px).
