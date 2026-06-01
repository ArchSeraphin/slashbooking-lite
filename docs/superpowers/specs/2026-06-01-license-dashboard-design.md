# SlashBooking — License management dashboard (v1, manual)

**Date:** 2026-06-01
**Status:** Approved (design)
**New repo:** `slashbooking-dashboard` (sibling of `slashbooking-broker`)
**Also touches:** `slashbooking-broker` (read licenses from SQLite instead of JSON)

## Goal

A small admin dashboard for Nicolas to manage SlashBooking licenses: list, create,
revoke, and extend. The dashboard and the existing OAuth broker share one SQLite
store on the same VPS. The broker (on `broker.slashbox.fr`) stays where it is and
changes only its license read path.

## Scope

**v1 (this spec):** manual license management only.
- Admin login (single admin).
- List / create / revoke / edit (expiry, sites) licenses.
- SQLite store shared with the broker.
- Broker reads licenses from SQLite.
- One-time import of the existing `licenses.json` into SQLite.

**Out of scope (separate specs later):**
- Stripe payment + automatic issuance + customer emails (v2, "brique C"). Pricing is
  already decided by the marketing handoff: **Free** vs **Pro at 5,99 €/month or
  60 €/year** (annual = "2 months free").
- Public marketing / pricing page on `slashbooking.fr` root — "brique D", separate
  spec. High-fidelity design already delivered in `design_handoff_slashbooking/`
  (one-pager, emerald SaaS style, full design system + logo assets).
- Multi-admin, 2FA.

## Architecture & hosting

- New domain **`slashbooking.fr`**, added to the **same Plesk subscription as the
  broker** (same system user) → the dashboard and broker processes share the
  filesystem with no permission gymnastics.
- The dashboard is a **separate Node.js/Express app** (`slashbooking-dashboard`),
  server-rendered HTML (no build step, no SPA), deployed on the **`dashboard.slashbooking.fr`
  subdomain** (admin-only). The `slashbooking.fr` root is reserved for the public
  marketing site (brique D — high-fidelity design already delivered in
  `design_handoff_slashbooking/`).
- **Shared SQLite file** lives OUTSIDE both web roots, in the subscription's private
  area (e.g. `/var/www/vhosts/slashbox.fr/private/licenses.sqlite`). Both apps point
  at it via an absolute-path env var (`LICENSES_DB`).
- The broker stays on `broker.slashbox.fr`; its only change is reading SQLite.

## Store — SQLite `licenses` table

Accessed via **`better-sqlite3`** (synchronous, file-based, safe concurrent writes).

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER PK | autoincrement |
| `key` | TEXT UNIQUE | license key, format `SB-XXXX-XXXX-XXXX` |
| `status` | TEXT | `active` \| `revoked`, default `active` |
| `plan` | TEXT | e.g. `pro` |
| `customer_email` | TEXT | for support / future emailing |
| `customer_name` | TEXT | optional |
| `notes` | TEXT | optional |
| `sites` | TEXT | JSON array of allowed origins; NULL/empty = any site |
| `expires` | TEXT | ISO date; NULL = never |
| `created_at` | TEXT | ISO datetime |
| `revoked_at` | TEXT | ISO datetime; NULL while active |

## Broker change (minimal — it stays in place)

- New env `LICENSES_DB` (absolute path to the shared SQLite file). `LICENSES_FILE`
  (JSON) is retired (kept only for the one-time migration).
- Add `better-sqlite3` to the broker's dependencies.
- `lib/licenses.js` `validate(key, site, db)` now queries SQLite by `key` instead of
  reading JSON. A license is **valid** when: a row exists AND `status = 'active'`
  AND (`expires` is NULL or in the future) AND (`sites` empty OR the request origin
  matches — the existing `originOf()` logic + empty-site skip are preserved). Return
  shape unchanged: `{ valid, plan, expires }`.
- No other broker behaviour changes. All existing broker tests must keep passing
  (the test fixtures move from JSON files to an in-memory/temp SQLite DB).

## Dashboard app

**Tech:** Node 20 + Express, `better-sqlite3`, server-rendered HTML (minimal template
literals or a tiny engine), HMAC-signed session cookie. Minimal dependencies, no build.

**Look & feel:** reuse the SlashBooking design-system tokens from the handoff
(`design_handoff_slashbooking/site/sb-styles.css` — emerald palette, Space Grotesk /
Manrope, `.btn`/`.card` components) so the admin is on-brand with near-zero effort
(it is vanilla CSS variables). Admin polish stays minimal — this is an internal tool,
not the marketing site.

**Auth:** single admin. Username + password from env (`DASH_USER`, `DASH_PASS_HASH`
— store a hash, not plaintext). Signed session cookie (`DASH_SESSION_SECRET`). Login
page + logout. All dashboard routes require a valid session.

**Pages / actions:**
- `GET /login`, `POST /login`, `POST /logout`.
- `GET /` — license list: table (key, status, plan, customer, sites, expires, created),
  with basic filter/sort by status and expiry.
- `GET /new` + `POST /licenses` — create: form (plan, customer email, name, optional
  sites, optional expiry) → generate a unique key (CSPRNG, `SB-XXXX-XXXX-XXXX`,
  Crockford base32) → insert → show the key to copy.
- `POST /licenses/:id/revoke` — set `status='revoked'`, `revoked_at=now`. Never delete.
- `GET /licenses/:id/edit` + `POST /licenses/:id` — edit expiry / sites (extend a client).

## Migration of existing licenses

A one-time script (run on first deploy) reads the broker's current `licenses.json`
and inserts each entry into the SQLite `licenses` table (`status='active'`), so the
current valid license carries over. After that, JSON is abandoned.

## Security

- All dashboard routes behind the login session; constant-time password check.
- HTTPS (Plesk Let's Encrypt) on the dashboard domain.
- SQLite file outside both web roots; never served.
- License keys generated with a CSPRNG.
- The dashboard is admin-only; the broker's public OAuth surface is unchanged and
  remains a separate app.

## Testing

- **Dashboard:** unit-test the pure pieces — key generation (format + uniqueness),
  the license repository (create/list/revoke/edit against a temp SQLite DB,
  `better-sqlite3` works great in tests), and auth (password check, session
  signing/verification). Integration-test the Express routes with supertest where
  practical.
- **Broker:** update `lib/licenses.js` tests to build a temp SQLite DB instead of a
  JSON fixture; keep the existing validate() cases (valid / unknown / expired /
  site-allow-list by origin / empty-site skip) plus a new `revoked` → invalid case.
  All broker tests stay green.

## Repos / files in scope

- **New repo `slashbooking-dashboard`:** Express app (auth, license routes,
  server-rendered views), `lib/db.js` (shared SQLite schema + connection),
  `lib/licenses-repo.js` (CRUD), `lib/keygen.js`, `scripts/import-json.js`
  (migration), tests, `DEPLOY.md`.
- **`slashbooking-broker`:** `lib/licenses.js` (SQLite read), `config.js`
  (`LICENSES_DB`), `package.json` (`better-sqlite3`), updated tests, `.env.example`,
  `DEPLOY.md`.
- Shared schema must be defined once and owned by the dashboard (it creates/migrates
  the table); the broker only reads.
