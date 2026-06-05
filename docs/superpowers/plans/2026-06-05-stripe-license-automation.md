# Stripe License Automation ("brique C") Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A customer pays a SlashBooking Pro subscription through a Stripe Payment Link; a signature-verified webhook on the dashboard creates the license in the shared SQLite store and emails the key via Brevo SMTP; renewals extend `expires`.

**Architecture:** New public route `POST /stripe/webhook` in `slashbooking-dashboard` (mounted before the auth gate), reusing `lib/keygen` + `lib/licenses-repo`. Expiry-driven lifecycle: create on checkout, extend on `invoice.paid`, do nothing on cancellation. Additive SQLite migration mirrored in the broker. Static site gets two Payment Links and a `/merci` page.

**Tech Stack:** Node 20+/Express 5, better-sqlite3, `stripe` SDK (signature verification + subscription retrieve), `nodemailer` (Brevo SMTP relay), Jest + supertest.

**Spec:** `docs/superpowers/specs/2026-06-05-stripe-license-automation-design.md`

## File structure

**`slashbooking-dashboard`** (Tasks 1–8):
- Modify: `package.json` (deps), `config.js` (optional Stripe/SMTP config), `lib/db.js` (3 columns), `lib/licenses-repo.js` (Stripe helpers), `app.js` (mount webhook), `DEPLOY.md` (env vars)
- Create: `lib/mailer.js` (Brevo transport + license email), `routes/stripe-webhook.js` (webhook router)
- Tests: create `test/db.test.js`, `test/mailer.test.js`, `test/stripe-webhook.test.js`; modify `test/licenses-repo.test.js`

**`slashbooking-broker`** (Task 9):
- Modify: `lib/db.js` (mirror schema)

**Ops — manual with Nicolas** (Task 10): Stripe + Brevo setup (test mode).

**`slashbooking-site`** (Task 11):
- Modify: `index.html` (Pro CTA), `sb-app.js` (toggle swaps CTA href)
- Create: `merci/index.html`

**Deployment + E2E + live switch** (Task 12).

Conventions to respect (match the existing codebase):
- CommonJS, `'use strict';` first line, single quotes, semicolons.
- Tests: temp SQLite file in `os.tmpdir()`, `cleanup()` in `afterEach`, supertest against `buildApp(cfg, opts)` with injected deps (see `test/routes.test.js`).
- Run tests from the dashboard repo root: `npm test` (jest `--runInBand`).

---

### Task 1: Dependencies

**Files:**
- Modify: `slashbooking-dashboard/package.json`

- [ ] **Step 1: Install runtime deps**

```bash
cd /Users/seraphin/Projects/slashbooking-dashboard
npm install stripe@^18 nodemailer@^7
```

Expected: `package.json` gains `stripe` and `nodemailer` in `dependencies`; install succeeds without compilation (both are pure JS).

- [ ] **Step 2: Verify existing suite still green**

Run: `npm test`
Expected: `Test Suites: 5 passed` (22 tests).

- [ ] **Step 3: Commit**

```bash
git add package.json package-lock.json
git commit -m "chore(deps): add stripe + nodemailer for brique C"
```

---

### Task 2: Optional Stripe/SMTP config

The dashboard must boot WITHOUT any Stripe config (feature flag = presence of `STRIPE_WEBHOOK_SECRET`). No new `required()` entries.

**Files:**
- Modify: `slashbooking-dashboard/config.js` (inside the `Object.freeze({...})`, after `trustProxy`)

- [ ] **Step 1: Add the optional config block**

```js
  // --- Stripe license automation (brique C) — all optional: the dashboard
  // boots without Stripe; the webhook route mounts only when
  // STRIPE_WEBHOOK_SECRET is set.
  stripeSecretKey: optional('STRIPE_SECRET_KEY', ''),
  stripeWebhookSecret: optional('STRIPE_WEBHOOK_SECRET', ''),
  stripePriceMonthly: optional('STRIPE_PRICE_MONTHLY', ''),
  stripePriceYearly: optional('STRIPE_PRICE_YEARLY', ''),
  stripePortalUrl: optional('STRIPE_PORTAL_URL', ''),
  smtpHost: optional('SMTP_HOST', 'smtp-relay.brevo.com'),
  smtpPort: parseInt(optional('SMTP_PORT', '587'), 10),
  smtpUser: optional('SMTP_USER', ''),
  smtpKey: optional('SMTP_KEY', ''),
  mailFrom: optional('MAIL_FROM', 'SlashBooking <hello@slashbooking.fr>'),
```

No dedicated test: `config.js` is env plumbing read at require time; behavior is covered through injected `cfg` objects in the webhook tests (Tasks 6–7).

- [ ] **Step 2: Run suite (no regression)**

Run: `npm test` — Expected: 5 suites pass.

- [ ] **Step 3: Commit**

```bash
git add config.js
git commit -m "feat(config): optional Stripe + Brevo SMTP settings (brique C)"
```

---

### Task 3: DB migration — 3 additive columns

**Files:**
- Modify: `slashbooking-dashboard/lib/db.js`
- Test: `slashbooking-dashboard/test/db.test.js` (create)

- [ ] **Step 1: Write the failing test**

Create `test/db.test.js`:

```js
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const Database = require('better-sqlite3');
const { openDb } = require('../lib/db');

const STRIPE_COLS = ['stripe_customer_id', 'stripe_subscription_id', 'email_sent_at'];

function tmpFile(tag) {
  return path.join(os.tmpdir(), `db-${tag}-${Date.now()}-${Math.random()}.sqlite`);
}

describe('lib/db stripe columns', () => {
  let file;
  afterEach(() => fs.existsSync(file) && fs.unlinkSync(file));

  test('fresh database has the stripe columns', () => {
    file = tmpFile('fresh');
    const db = openDb(file);
    const cols = db.prepare('PRAGMA table_info(licenses)').all().map((c) => c.name);
    db.close();
    expect(cols).toEqual(expect.arrayContaining(STRIPE_COLS));
  });

  test('legacy database (pre-stripe) gets the columns added on open', () => {
    file = tmpFile('legacy');
    // Simulate the live store as it exists today: no stripe columns.
    const legacy = new Database(file);
    legacy.exec(`CREATE TABLE licenses (
      id INTEGER PRIMARY KEY AUTOINCREMENT, key TEXT NOT NULL UNIQUE,
      status TEXT NOT NULL DEFAULT 'active', plan TEXT, customer_email TEXT,
      customer_name TEXT, notes TEXT, sites TEXT, expires TEXT,
      created_at TEXT NOT NULL, revoked_at TEXT, max_sites INTEGER
    );`);
    legacy.close();

    const db = openDb(file);
    const cols = db.prepare('PRAGMA table_info(licenses)').all().map((c) => c.name);
    db.close();
    expect(cols).toEqual(expect.arrayContaining(STRIPE_COLS));
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `npx jest test/db.test.js -v`
Expected: FAIL — both tests, missing columns.

- [ ] **Step 3: Implement the migration**

In `lib/db.js`, add the three columns at the end of the `SCHEMA` column list (after `max_sites      INTEGER`, add a comma after `max_sites INTEGER` first):

```sql
  max_sites      INTEGER,
  stripe_customer_id     TEXT,
  stripe_subscription_id TEXT,
  email_sent_at          TEXT
```

And extend `ADD_COLUMNS`:

```js
const ADD_COLUMNS = [
  ['max_sites', 'ALTER TABLE licenses ADD COLUMN max_sites INTEGER'],
  ['stripe_customer_id', 'ALTER TABLE licenses ADD COLUMN stripe_customer_id TEXT'],
  ['stripe_subscription_id', 'ALTER TABLE licenses ADD COLUMN stripe_subscription_id TEXT'],
  ['email_sent_at', 'ALTER TABLE licenses ADD COLUMN email_sent_at TEXT'],
];
```

- [ ] **Step 4: Run tests**

Run: `npx jest test/db.test.js -v` → PASS. Then `npm test` → all suites pass.

- [ ] **Step 5: Commit**

```bash
git add lib/db.js test/db.test.js
git commit -m "feat(db): additive stripe_customer_id/subscription_id/email_sent_at columns"
```

---

### Task 4: Repo helpers (findBySubscriptionId, extendExpiry, markEmailSent, stripe IDs on create)

**Files:**
- Modify: `slashbooking-dashboard/lib/licenses-repo.js`
- Test: `slashbooking-dashboard/test/licenses-repo.test.js` (append a describe block)

- [ ] **Step 1: Write the failing tests**

Append to `test/licenses-repo.test.js` (reuse the file's existing temp-db setup helpers if present; otherwise create a local one matching this pattern):

```js
describe('stripe helpers', () => {
  let db, file;
  beforeEach(() => {
    file = path.join(os.tmpdir(), `repo-stripe-${Date.now()}-${Math.random()}.sqlite`);
    db = openDb(file);
  });
  afterEach(() => { db.close(); fs.unlinkSync(file); });

  test('createLicense stores stripe ids; findBySubscriptionId retrieves the row', () => {
    const id = repo.createLicense(db, {
      key: 'SB-TEST-0000-0001', plan: 'pro-monthly', customer_email: 'c@x.com',
      sites: [], expires: '2027-01-01', max_sites: 1,
      stripe_customer_id: 'cus_1', stripe_subscription_id: 'sub_1',
    });
    const row = repo.findBySubscriptionId(db, 'sub_1');
    expect(row).not.toBeNull();
    expect(row.id).toBe(id);
    expect(row.stripe_customer_id).toBe('cus_1');
    expect(repo.findBySubscriptionId(db, 'sub_unknown')).toBeNull();
  });

  test('extendExpiry updates expires only', () => {
    const id = repo.createLicense(db, {
      key: 'SB-TEST-0000-0002', plan: 'pro-monthly', sites: [],
      expires: '2026-07-01', stripe_subscription_id: 'sub_2',
    });
    expect(repo.extendExpiry(db, id, '2026-08-01')).toBe(1);
    expect(repo.getLicense(db, id).expires).toBe('2026-08-01');
  });

  test('markEmailSent stamps email_sent_at', () => {
    const id = repo.createLicense(db, {
      key: 'SB-TEST-0000-0003', plan: 'pro-yearly', sites: [],
      stripe_subscription_id: 'sub_3',
    });
    expect(repo.getLicense(db, id).email_sent_at).toBeNull();
    repo.markEmailSent(db, id);
    expect(repo.getLicense(db, id).email_sent_at).toBeTruthy();
  });
});
```

(If `test/licenses-repo.test.js` does not already import `fs`/`os`/`path`/`openDb`/`repo`, add the same imports used in `test/routes.test.js`.)

- [ ] **Step 2: Run to verify it fails**

Run: `npx jest test/licenses-repo.test.js -v`
Expected: FAIL — `findBySubscriptionId is not a function`, and stripe ids not persisted.

- [ ] **Step 3: Implement**

In `lib/licenses-repo.js`:

In `createLicense`, extend the INSERT statement and params:

```js
  const info = db
    .prepare(
      `INSERT INTO licenses
         (key, status, plan, customer_email, customer_name, notes, sites, expires, created_at, max_sites,
          stripe_customer_id, stripe_subscription_id)
       VALUES
         (@key, 'active', @plan, @customer_email, @customer_name, @notes, @sites, @expires, @created_at, @max_sites,
          @stripe_customer_id, @stripe_subscription_id)`
    )
    .run({
      // ...existing params unchanged...
      stripe_customer_id: data.stripe_customer_id ?? null,
      stripe_subscription_id: data.stripe_subscription_id ?? null,
    });
```

Add the three helpers before `module.exports`:

```js
function findBySubscriptionId(db, subId) {
  return db.prepare('SELECT * FROM licenses WHERE stripe_subscription_id = ?').get(subId) || null;
}

function extendExpiry(db, id, expires) {
  return db.prepare('UPDATE licenses SET expires = ? WHERE id = ?').run(expires, id).changes;
}

function markEmailSent(db, id, when) {
  return db
    .prepare('UPDATE licenses SET email_sent_at = ? WHERE id = ?')
    .run(when || new Date().toISOString(), id).changes;
}
```

Export them:

```js
module.exports = {
  createLicense, listLicenses, getLicense, revokeLicense, updateLicense, normMaxSites,
  findBySubscriptionId, extendExpiry, markEmailSent,
};
```

- [ ] **Step 4: Run tests**

Run: `npx jest test/licenses-repo.test.js -v` → PASS. Then `npm test` → all pass.

- [ ] **Step 5: Commit**

```bash
git add lib/licenses-repo.js test/licenses-repo.test.js
git commit -m "feat(repo): stripe ids on create + findBySubscriptionId/extendExpiry/markEmailSent"
```

---

### Task 5: Mailer (Brevo SMTP relay + license email)

**Files:**
- Create: `slashbooking-dashboard/lib/mailer.js`
- Test: `slashbooking-dashboard/test/mailer.test.js` (create)

- [ ] **Step 1: Write the failing test**

Create `test/mailer.test.js`:

```js
'use strict';

const { createMailer, licenseEmail } = require('../lib/mailer');

const cfg = {
  smtpHost: 'smtp-relay.brevo.com', smtpPort: 587, smtpUser: 'u', smtpKey: 'k',
  mailFrom: 'SlashBooking <hello@slashbooking.fr>',
  stripePortalUrl: 'https://billing.stripe.com/p/login/test_xyz',
};

describe('mailer', () => {
  test('licenseEmail contains the key, plan wording and portal link', () => {
    const { subject, text } = licenseEmail({ key: 'SB-AAAA-BBBB-CCCC', plan: 'pro-yearly', portalUrl: cfg.stripePortalUrl });
    expect(subject).toContain('SlashBooking Pro');
    expect(text).toContain('SB-AAAA-BBBB-CCCC');
    expect(text).toContain('annuelle');
    expect(text).toContain(cfg.stripePortalUrl);
  });

  test('licenseEmail omits the portal line without portalUrl', () => {
    const { text } = licenseEmail({ key: 'SB-AAAA-BBBB-CCCC', plan: 'pro-monthly', portalUrl: '' });
    expect(text).toContain('mensuelle');
    expect(text).not.toContain('billing.stripe.com');
  });

  test('sendLicenseEmail sends through the injected transport with from/to/subject', async () => {
    const sent = [];
    const fakeTransport = { sendMail: async (m) => { sent.push(m); return { messageId: 'x' }; } };
    const mailer = createMailer(cfg, fakeTransport);
    await mailer.sendLicenseEmail({ to: 'client@example.com', key: 'SB-AAAA-BBBB-CCCC', plan: 'pro-monthly' });
    expect(sent).toHaveLength(1);
    expect(sent[0].from).toBe(cfg.mailFrom);
    expect(sent[0].to).toBe('client@example.com');
    expect(sent[0].text).toContain('SB-AAAA-BBBB-CCCC');
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `npx jest test/mailer.test.js -v`
Expected: FAIL — `Cannot find module '../lib/mailer'`.

- [ ] **Step 3: Implement `lib/mailer.js`**

```js
'use strict';

const nodemailer = require('nodemailer');

// Customer-facing license email (French). Pure function => unit-testable.
function licenseEmail({ key, plan, portalUrl }) {
  const formule = plan === 'pro-yearly' ? 'annuelle' : 'mensuelle';
  const lines = [
    'Bonjour,',
    '',
    `Merci pour votre abonnement SlashBooking Pro (formule ${formule}) !`,
    '',
    'Votre clé de licence :',
    '',
    `    ${key}`,
    '',
    "Pour l'activer : dans WordPress, ouvrez SlashBooking → Réglages → Licence,",
    'collez la clé et enregistrez. La synchronisation Google Agenda, les e-mails',
    'personnalisables et les rappels sont débloqués immédiatement.',
    '',
  ];
  if (portalUrl) {
    lines.push(`Gérer votre abonnement (factures, annulation) : ${portalUrl}`, '');
  }
  lines.push('Une question ? Répondez simplement à cet e-mail.', '', '— L’équipe SlashBooking', 'https://slashbooking.fr');
  return { subject: 'Votre licence SlashBooking Pro', text: lines.join('\n') };
}

// transport injectable for tests; defaults to Brevo SMTP relay.
function createMailer(cfg, transport) {
  const t =
    transport ||
    nodemailer.createTransport({
      host: cfg.smtpHost,
      port: cfg.smtpPort,
      auth: { user: cfg.smtpUser, pass: cfg.smtpKey },
    });
  return {
    async sendLicenseEmail({ to, key, plan }) {
      const { subject, text } = licenseEmail({ key, plan, portalUrl: cfg.stripePortalUrl });
      await t.sendMail({ from: cfg.mailFrom, to, subject, text });
    },
  };
}

module.exports = { createMailer, licenseEmail };
```

- [ ] **Step 4: Run tests**

Run: `npx jest test/mailer.test.js -v` → PASS. Then `npm test` → all pass.

- [ ] **Step 5: Commit**

```bash
git add lib/mailer.js test/mailer.test.js
git commit -m "feat(mailer): Brevo SMTP license email (injectable transport)"
```

---### Task 6: Webhook router — signature + checkout happy path

**Files:**
- Create: `slashbooking-dashboard/routes/stripe-webhook.js`
- Modify: `slashbooking-dashboard/app.js`
- Test: `slashbooking-dashboard/test/stripe-webhook.test.js` (create)

- [ ] **Step 1: Write the failing tests (signature + creation + auth-gate interplay)**

Create `test/stripe-webhook.test.js`:

```js
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const request = require('supertest');
const Stripe = require('stripe');
const { openDb } = require('../lib/db');
const repo = require('../lib/licenses-repo');
const { hashPassword } = require('../lib/auth');

const WH_SECRET = 'whsec_test_secret';
// Offline use only: constructEvent/generateTestHeaderString need no network/key.
const stripeClient = new Stripe('sk_test_dummy');

const PERIOD_END = 1786000000; // unix seconds, arbitrary fixed date
const GRACE_DAYS = 3;
const EXPECTED_EXPIRES = new Date((PERIOD_END + GRACE_DAYS * 86400) * 1000).toISOString().slice(0, 10);

function checkoutEvent(overrides = {}) {
  return {
    id: 'evt_1', type: 'checkout.session.completed',
    data: {
      object: {
        id: 'cs_1', mode: 'subscription', subscription: 'sub_123', customer: 'cus_123',
        customer_details: { email: 'client@example.com', name: 'Jean Client' },
        ...overrides,
      },
    },
  };
}

function signedPost(app, payload) {
  const body = JSON.stringify(payload);
  const sig = stripeClient.webhooks.generateTestHeaderString({ payload: body, secret: WH_SECRET });
  return request(app)
    .post('/stripe/webhook')
    .set('stripe-signature', sig)
    .set('content-type', 'application/json')
    .send(body);
}

function buildCtx(extraCfg = {}) {
  const dbFile = path.join(os.tmpdir(), `wh-${Date.now()}-${Math.random()}.sqlite`);
  const db = openDb(dbFile);
  const cfg = {
    licensesDb: dbFile, dashUser: 'admin', dashPassHash: hashPassword('pw'),
    sessionSecret: 'test-secret', basePath: '/', port: 0, trustProxy: 0,
    stripeSecretKey: 'sk_test_dummy', stripeWebhookSecret: WH_SECRET,
    stripePriceMonthly: 'price_month', stripePriceYearly: 'price_year',
    stripePortalUrl: '', mailFrom: 'SlashBooking <hello@slashbooking.fr>',
    ...extraCfg,
  };
  const mailer = { sendLicenseEmail: jest.fn().mockResolvedValue(undefined) };
  jest.resetModules();
  const buildApp = require('../app');
  const { app } = buildApp(cfg, { db, mailer, stripeClient });
  return { app, db, mailer, cleanup: () => { db.close(); fs.unlinkSync(dbFile); } };
}

describe('POST /stripe/webhook', () => {
  let ctx;
  afterEach(() => {
    ctx && ctx.cleanup();
    jest.restoreAllMocks();
  });

  test('without STRIPE_WEBHOOK_SECRET the route is not mounted (auth gate answers)', async () => {
    ctx = buildCtx({ stripeWebhookSecret: '' });
    const res = await request(ctx.app).post('/stripe/webhook').send('{}');
    expect(res.status).toBe(302); // requireAuth redirects to /login
  });

  test('invalid signature -> 400, nothing created', async () => {
    ctx = buildCtx();
    const res = await request(ctx.app)
      .post('/stripe/webhook')
      .set('stripe-signature', 't=1,v1=bad')
      .set('content-type', 'application/json')
      .send(JSON.stringify(checkoutEvent()));
    expect(res.status).toBe(400);
    expect(repo.listLicenses(ctx.db)).toHaveLength(0);
  });

  test('checkout.session.completed -> license created (pro-monthly, max_sites 1, expiry = period end + grace) + email sent', async () => {
    ctx = buildCtx();
    jest.spyOn(stripeClient.subscriptions, 'retrieve').mockResolvedValue({
      id: 'sub_123',
      items: { data: [{ price: { id: 'price_month' }, current_period_end: PERIOD_END }] },
    });

    const res = await signedPost(ctx.app, checkoutEvent());
    expect(res.status).toBe(200);

    const lic = repo.findBySubscriptionId(ctx.db, 'sub_123');
    expect(lic).not.toBeNull();
    expect(lic.plan).toBe('pro-monthly');
    expect(lic.max_sites).toBe(1);
    expect(lic.status).toBe('active');
    expect(lic.customer_email).toBe('client@example.com');
    expect(lic.stripe_customer_id).toBe('cus_123');
    expect(lic.expires).toBe(EXPECTED_EXPIRES);
    expect(lic.email_sent_at).toBeTruthy();
    expect(ctx.mailer.sendLicenseEmail).toHaveBeenCalledWith(
      expect.objectContaining({ to: 'client@example.com', key: lic.key, plan: 'pro-monthly' })
    );
  });

  test('non-subscription checkout session -> 200 no-op', async () => {
    ctx = buildCtx();
    const res = await signedPost(ctx.app, checkoutEvent({ mode: 'payment', subscription: null }));
    expect(res.status).toBe(200);
    expect(repo.listLicenses(ctx.db)).toHaveLength(0);
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `npx jest test/stripe-webhook.test.js -v`
Expected: FAIL — the mounted-route tests get 302 (route absent), module not found once `app.js` references it.

- [ ] **Step 3: Implement `routes/stripe-webhook.js`**

```js
'use strict';

const express = require('express');
const repo = require('../lib/licenses-repo');
const { createUniqueKey } = require('../lib/keygen');

const GRACE_DAYS = 3; // breathing room past current_period_end before the broker rejects the key

function isoDatePlusGrace(unixSeconds) {
  return new Date((unixSeconds + GRACE_DAYS * 86400) * 1000).toISOString().slice(0, 10);
}

function planFromPriceId(cfg, priceId) {
  if (priceId && priceId === cfg.stripePriceMonthly) return 'pro-monthly';
  if (priceId && priceId === cfg.stripePriceYearly) return 'pro-yearly';
  return null;
}

// Public webhook (Stripe authenticates via the signed payload, not a session).
// Mounted with express.raw: signature verification needs the exact raw body.
function stripeWebhookRouter({ cfg, db, mailer, stripeClient }) {
  const router = express.Router();

  router.post('/stripe/webhook', express.raw({ type: 'application/json' }), async (req, res) => {
    let event;
    try {
      event = stripeClient.webhooks.constructEvent(
        req.body,
        req.headers['stripe-signature'],
        cfg.stripeWebhookSecret
      );
    } catch (e) {
      return res.status(400).send('invalid signature');
    }

    try {
      if (event.type === 'checkout.session.completed') {
        await onCheckoutCompleted(event.data.object);
      } else if (event.type === 'invoice.paid') {
        await onInvoicePaid(event.data.object);
      }
      return res.status(200).send('ok');
    } catch (e) {
      // Transient failure (SQLite, SMTP, Stripe API): 500 => Stripe retries.
      console.error('[stripe-webhook]', event.type, e);
      return res.status(500).send('retry');
    }
  });

  async function onCheckoutCompleted(session) {
    if (session.mode !== 'subscription' || !session.subscription) {
      return;
    }
    const subId = String(session.subscription);

    // Idempotency: Stripe replays webhooks. If the license exists, only retry
    // the email when the first attempt failed (license row without email_sent_at).
    const existing = repo.findBySubscriptionId(db, subId);
    if (existing) {
      if (!existing.email_sent_at && existing.customer_email) {
        await mailer.sendLicenseEmail({ to: existing.customer_email, key: existing.key, plan: existing.plan });
        repo.markEmailSent(db, existing.id);
      }
      return;
    }

    const sub = await stripeClient.subscriptions.retrieve(subId);
    const item = sub.items.data[0];
    // API 2025-03+ ("Basil") moved current_period_end to the subscription item.
    const periodEnd = item.current_period_end ?? sub.current_period_end;
    const plan = planFromPriceId(cfg, item.price && item.price.id);
    if (!plan) {
      // Unknown price: log loudly, ack with 200 (no retry storm), handle manually.
      console.error(`[stripe-webhook] unknown price for ${subId}: ${item.price && item.price.id} — license NOT created`);
      return;
    }

    const key = createUniqueKey(db);
    const id = repo.createLicense(db, {
      key,
      plan,
      customer_email: (session.customer_details && session.customer_details.email) || null,
      customer_name: (session.customer_details && session.customer_details.name) || null,
      notes: 'stripe:auto',
      sites: [],
      expires: isoDatePlusGrace(periodEnd),
      max_sites: 1,
      stripe_customer_id: session.customer ? String(session.customer) : null,
      stripe_subscription_id: subId,
    });

    const to = session.customer_details && session.customer_details.email;
    if (to) {
      await mailer.sendLicenseEmail({ to, key, plan });
      repo.markEmailSent(db, id);
    }
  }

  async function onInvoicePaid(invoice) {
    // Renewals only; the initial invoice is handled by checkout.session.completed.
    if (invoice.billing_reason !== 'subscription_cycle') {
      return;
    }
    // API 2025-03+ ("Basil") moved invoice.subscription under invoice.parent.
    const subId =
      (invoice.parent && invoice.parent.subscription_details && invoice.parent.subscription_details.subscription) ||
      invoice.subscription;
    if (!subId) {
      return;
    }
    const lic = repo.findBySubscriptionId(db, String(subId));
    if (!lic) {
      return; // license sold outside Stripe or manually deleted: nothing to extend
    }
    const line = invoice.lines && invoice.lines.data && invoice.lines.data[0];
    const periodEnd = line && line.period && line.period.end;
    if (!periodEnd) {
      return;
    }
    repo.extendExpiry(db, lic.id, isoDatePlusGrace(periodEnd));
  }

  return router;
}

module.exports = { stripeWebhookRouter };
```

- [ ] **Step 4: Mount it in `app.js`**

In `app.js`, between `const root = express.Router();` and `root.use(authRouter(cfg));`:

```js
  const root = express.Router();
  // Stripe webhook — public (Stripe signs its calls), mounted BEFORE the auth
  // gate. Feature-flagged: absent unless STRIPE_WEBHOOK_SECRET is configured.
  if (cfg.stripeWebhookSecret) {
    const { createMailer } = require('./lib/mailer');
    const { stripeWebhookRouter } = require('./routes/stripe-webhook');
    const mailer = opts.mailer || createMailer(cfg);
    const stripeClient = opts.stripeClient || new (require('stripe'))(cfg.stripeSecretKey);
    root.use(stripeWebhookRouter({ cfg, db, mailer, stripeClient }));
  }
  root.use(authRouter(cfg));            // /login, /logout, /logout-link (public)
```

- [ ] **Step 5: Run tests**

Run: `npx jest test/stripe-webhook.test.js -v` → PASS (5 tests). Then `npm test` → all suites pass (existing `routes.test.js` cfg has no `stripeWebhookSecret`, so nothing changes for it).

- [ ] **Step 6: Commit**

```bash
git add routes/stripe-webhook.js app.js test/stripe-webhook.test.js
git commit -m "feat(stripe): signed webhook -> auto license creation on checkout"
```

---

### Task 7: Webhook — idempotency, email retry, renewals, unknown price

**Files:**
- Modify: `slashbooking-dashboard/test/stripe-webhook.test.js` (append to the describe block)
- (Implementation already written in Task 6 — these tests lock the behavior; fix the implementation if any fails.)

- [ ] **Step 1: Append the tests**

```js
  test('replayed checkout event -> no duplicate license, no second email', async () => {
    ctx = buildCtx();
    jest.spyOn(stripeClient.subscriptions, 'retrieve').mockResolvedValue({
      id: 'sub_123',
      items: { data: [{ price: { id: 'price_month' }, current_period_end: PERIOD_END }] },
    });
    await signedPost(ctx.app, checkoutEvent());
    const res = await signedPost(ctx.app, checkoutEvent());
    expect(res.status).toBe(200);
    expect(repo.listLicenses(ctx.db)).toHaveLength(1);
    expect(ctx.mailer.sendLicenseEmail).toHaveBeenCalledTimes(1);
  });

  test('SMTP failure -> 500 (Stripe retries); replay resends email without duplicating the license', async () => {
    ctx = buildCtx();
    jest.spyOn(stripeClient.subscriptions, 'retrieve').mockResolvedValue({
      id: 'sub_123',
      items: { data: [{ price: { id: 'price_month' }, current_period_end: PERIOD_END }] },
    });
    ctx.mailer.sendLicenseEmail.mockRejectedValueOnce(new Error('smtp down'));

    const first = await signedPost(ctx.app, checkoutEvent());
    expect(first.status).toBe(500);
    const lic1 = repo.findBySubscriptionId(ctx.db, 'sub_123');
    expect(lic1).not.toBeNull();          // license persisted despite email failure
    expect(lic1.email_sent_at).toBeNull();

    const retry = await signedPost(ctx.app, checkoutEvent());
    expect(retry.status).toBe(200);
    expect(repo.listLicenses(ctx.db)).toHaveLength(1);
    expect(repo.findBySubscriptionId(ctx.db, 'sub_123').email_sent_at).toBeTruthy();
    expect(ctx.mailer.sendLicenseEmail).toHaveBeenCalledTimes(2);
  });

  test('invoice.paid subscription_cycle -> expires extended', async () => {
    ctx = buildCtx();
    const id = repo.createLicense(ctx.db, {
      key: 'SB-TEST-RENEW-0001', plan: 'pro-monthly', sites: [], expires: '2026-07-04',
      max_sites: 1, stripe_subscription_id: 'sub_123',
    });
    const NEW_END = PERIOD_END + 30 * 86400;
    const res = await signedPost(ctx.app, {
      id: 'evt_2', type: 'invoice.paid',
      data: { object: {
        id: 'in_1', billing_reason: 'subscription_cycle', subscription: 'sub_123',
        lines: { data: [{ period: { end: NEW_END } }] },
      } },
    });
    expect(res.status).toBe(200);
    const expected = new Date((NEW_END + GRACE_DAYS * 86400) * 1000).toISOString().slice(0, 10);
    expect(repo.getLicense(ctx.db, id).expires).toBe(expected);
  });

  test('invoice.paid subscription_create -> no-op', async () => {
    ctx = buildCtx();
    const res = await signedPost(ctx.app, {
      id: 'evt_3', type: 'invoice.paid',
      data: { object: { id: 'in_2', billing_reason: 'subscription_create', subscription: 'sub_999' } },
    });
    expect(res.status).toBe(200);
    expect(repo.listLicenses(ctx.db)).toHaveLength(0);
  });

  test('unknown price id -> 200, loud log, no license', async () => {
    ctx = buildCtx();
    jest.spyOn(stripeClient.subscriptions, 'retrieve').mockResolvedValue({
      id: 'sub_123',
      items: { data: [{ price: { id: 'price_other' }, current_period_end: PERIOD_END }] },
    });
    const spy = jest.spyOn(console, 'error').mockImplementation(() => {});
    const res = await signedPost(ctx.app, checkoutEvent());
    expect(res.status).toBe(200);
    expect(repo.listLicenses(ctx.db)).toHaveLength(0);
    expect(spy).toHaveBeenCalledWith(expect.stringContaining('unknown price'));
  });
```

- [ ] **Step 2: Run tests**

Run: `npx jest test/stripe-webhook.test.js -v`
Expected: PASS (10 tests). If a test fails, fix `routes/stripe-webhook.js` — the Task 6 implementation was written to satisfy these.

- [ ] **Step 3: Full suite + commit**

Run: `npm test` → all suites pass.

```bash
git add test/stripe-webhook.test.js
git commit -m "test(stripe): idempotency, email retry, renewal extension, unknown price"
```

---

### Task 8: DEPLOY.md + push dashboard

**Files:**
- Modify: `slashbooking-dashboard/DEPLOY.md`

- [ ] **Step 1: Document the new env vars and webhook**

Append to the `## Notes` section of `DEPLOY.md`:

```markdown
## Stripe license automation (brique C)

Optional — the app boots without it. To enable, set in Plesk -> Node.js:
- `STRIPE_SECRET_KEY` (sk_live_… / sk_test_…)
- `STRIPE_WEBHOOK_SECRET` (whsec_… from the endpoint config — this is the feature flag)
- `STRIPE_PRICE_MONTHLY`, `STRIPE_PRICE_YEARLY` (price IDs of Pro 5,99 €/m and 60 €/y)
- `STRIPE_PORTAL_URL` (no-code customer portal login link, used in the license email)
- `SMTP_USER`, `SMTP_KEY` (Brevo SMTP relay credentials; host/port default to smtp-relay.brevo.com:587)
- `MAIL_FROM` (default `SlashBooking <hello@slashbooking.fr>` — the domain must be authenticated in Brevo: DKIM/SPF records in the Plesk DNS)

Stripe webhook endpoint: `https://dashboard.slashbooking.fr/stripe/webhook`,
events `checkout.session.completed` + `invoice.paid`. Lifecycle is expiry-driven:
renewals extend `expires` (+3 days grace); cancellations just let the license
lapse; immediate revocation stays manual in the dashboard.

Troubleshooting: Passenger crash details are in `/var/log/passenger/passenger.log`
(search the Error ID shown on the 500 page); the env values actually delivered are
the `SetEnv` lines in `/var/www/vhosts/system/dashboard.slashbooking.fr/conf/httpd.conf`.
Test as the vhost user (`sudo -u <user>`), never as root.
```

- [ ] **Step 2: Commit and push**

```bash
git add DEPLOY.md
git commit -m "docs(deploy): brique C env vars + webhook + Plesk troubleshooting"
git push
```

---

### Task 9: Broker schema mirror

**Files:**
- Modify: `slashbooking-broker/lib/db.js`

- [ ] **Step 1: Mirror the columns**

In `/Users/seraphin/Projects/slashbooking-broker/lib/db.js`, apply EXACTLY the same two edits as Task 3 (the file is intentionally a near-duplicate — the header comment says so):

1. In `SCHEMA`, after `max_sites      INTEGER` (add trailing comma):

```sql
  max_sites      INTEGER,
  stripe_customer_id     TEXT,
  stripe_subscription_id TEXT,
  email_sent_at          TEXT
```

2. Extend `ADD_COLUMNS` to the same 4-entry list as the dashboard's `lib/db.js` (Task 3 Step 3).

- [ ] **Step 2: Run the broker suite**

```bash
cd /Users/seraphin/Projects/slashbooking-broker && npm test
```

Expected: all broker tests pass (the broker never reads the new columns).

- [ ] **Step 3: Commit and push**

```bash
git add lib/db.js
git commit -m "feat(db): mirror dashboard stripe columns (additive, unused by broker)"
git push
```

---

### Task 10: Ops — Stripe (test mode) + Brevo setup [manual, with Nicolas]

No code. Record every value produced — Tasks 11–12 consume them.

- [ ] **Step 1: Brevo**
  - Create the SMTP key (Brevo -> SMTP & API -> SMTP).
  - Authenticate `slashbooking.fr` (Brevo -> Senders & Domains): add the DKIM/SPF records Brevo provides into Plesk -> slashbooking.fr -> DNS. Wait for Brevo to show "verified".
  - Record: `SMTP_USER` (Brevo login), `SMTP_KEY`.

- [ ] **Step 2: Stripe — test mode first**
  - Product **SlashBooking Pro**; recurring prices **5,99 €/month** and **60 €/year**. Record both price IDs → `STRIPE_PRICE_MONTHLY`, `STRIPE_PRICE_YEARLY`.
  - Two **Payment Links** (one per price), `success_url` = `https://slashbooking.fr/merci/`. Record both URLs → `LINK_MONTH`, `LINK_YEAR` (Task 11).
  - Webhook endpoint `https://dashboard.slashbooking.fr/stripe/webhook`, events `checkout.session.completed` + `invoice.paid`. Record the signing secret → `STRIPE_WEBHOOK_SECRET`.
  - Enable the no-code **customer portal** and its login link. Record → `STRIPE_PORTAL_URL`.
  - Record the test API key → `STRIPE_SECRET_KEY`.

---

### Task 11: Marketing site — CTA + /merci

**Files:**
- Modify: `slashbooking-site/index.html:356-363`
- Modify: `slashbooking-site/sb-app.js:116-137` (pricing toggle)
- Create: `slashbooking-site/merci/index.html`

Use the `LINK_MONTH` / `LINK_YEAR` Payment Link URLs recorded in Task 10 Step 2 wherever they appear below.

- [ ] **Step 1: Replace the "Bientôt disponible" CTA in `index.html`**

Replace:

```html
        <span class="btn btn-primary btn-block sb-soon" aria-disabled="true">Bientôt disponible</span>
        <p class="sb-soon-note">Paiement en ligne très bientôt.</p>
```

with (paste the real monthly link as the default href):

```html
        <a id="proCta" class="btn btn-primary btn-block" href="LINK_MONTH" rel="noopener">Passer à Pro</a>
        <p class="sb-soon-note">Paiement sécurisé par Stripe. Annulable à tout moment.</p>
```

- [ ] **Step 2: Make the toggle swap the CTA target in `sb-app.js`**

In the pricing-toggle block (line ~116), declare the links and the CTA next to the existing `proNote` lookup:

```js
  var proCta = document.getElementById("proCta");
  var LINK_MONTH = "LINK_MONTH"; // Stripe Payment Link — Pro mensuel (Task 10)
  var LINK_YEAR = "LINK_YEAR";   // Stripe Payment Link — Pro annuel (Task 10)
```

Then inside the click handler, extend both branches:

```js
      if (btn.dataset.period === "year") {
        proPrice.textContent = "60€";
        proPer.textContent = "/ an";
        proNote.textContent = "Soit 5€ par mois — 2 mois offerts.";
        if (proCta) proCta.href = LINK_YEAR;
      } else {
        proPrice.textContent = "5,99€";
        proPer.textContent = "/ mois";
        proNote.textContent = "Soit 71,88€ par an.";
        if (proCta) proCta.href = LINK_MONTH;
      }
```

- [ ] **Step 3: Create `merci/index.html`**

```html
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Merci ! — SlashBooking</title>
  <meta name="robots" content="noindex">
  <link rel="stylesheet" href="../sb-styles.css">
</head>
<body>
  <main style="max-width:560px;margin:18vh auto 0;padding:0 24px;text-align:center;">
    <h1>Merci pour votre confiance&nbsp;!</h1>
    <p>Votre paiement est confirmé. <strong>Votre clé de licence arrive par e-mail</strong>
       d'ici quelques minutes (pensez à vérifier vos spams).</p>
    <p>Pour l'activer&nbsp;: WordPress → SlashBooking → Réglages → Licence.</p>
    <p>Un souci&nbsp;? Écrivez-nous&nbsp;: <a href="mailto:hello@slashbooking.fr">hello@slashbooking.fr</a></p>
    <p><a class="btn btn-primary" href="/">Retour au site</a></p>
  </main>
</body>
</html>
```

- [ ] **Step 4: Verify locally**

Open `index.html` in a browser: the Pro CTA reads « Passer à Pro », clicking the Mensuel/Annuel toggle swaps the link target (hover the CTA and check the status bar), `merci/index.html` renders with site styles.

- [ ] **Step 5: Commit and push**

```bash
cd /Users/seraphin/Projects/slashbooking-site
git add index.html sb-app.js merci/index.html
git commit -m "feat(site): wire Pro CTA to Stripe Payment Links + /merci success page"
git push
```

---

### Task 12: Deploy, E2E in test mode, switch live [manual, with Nicolas]

- [ ] **Step 1: Deploy dashboard** — on the server: `git pull` in the dashboard docroot, then Plesk -> dashboard.slashbooking.fr -> Node.js -> **NPM Install** (runs as the vhost user; the server has no build toolchain but stripe/nodemailer are pure JS) -> add the env vars from Tasks 10 (test-mode values) -> **Restart App**. Beware stray spaces/quotes in Plesk env fields (`config.js` does not trim).

- [ ] **Step 2: Deploy site** — `git pull` on the slashbooking.fr docroot (Payment Links in test mode for now).

- [ ] **Step 3: E2E in Stripe test mode**
  - Pay through the real Payment Link with card `4242 4242 4242 4242`.
  - Stripe dashboard -> webhook endpoint: both events delivered, HTTP 200.
  - Dashboard: license row exists (plan `pro-monthly` or `pro-yearly`, `max_sites` 1, expires ≈ period end + 3 days, notes `stripe:auto`).
  - Email received at the test address (from `hello@slashbooking.fr`, key present, portal link works).
  - Broker validates: `curl -s -XPOST https://broker.slashbox.fr/license/validate -H 'content-type: application/json' -d '{"license":"SB-…","site":"https://client.com"}'` → `{"valid":true,…}`.
  - Replay the webhook from the Stripe dashboard ("Resend") → still 1 license, no duplicate email.

- [ ] **Step 4: Switch to live mode** — recreate product/prices/Payment Links/webhook in live mode, swap the Plesk env values (`sk_live_…`, live `whsec_…`, live price IDs, live portal URL) and the two links in `sb-app.js`/`index.html`; Restart App; redeploy site; run one real payment and refund it.

- [ ] **Step 5: Close brique C** — in `plugins-booking/docs/2026-06-01-prod-deployment-runbook.md`, mark the « Brique C — paiement Stripe » item done (careful: the file has pending local edits — coordinate with Nicolas).

---

## Self-review notes

- Spec coverage: Stripe config (T10), webhook + signature + lifecycle + idempotency (T6–7), migration (T3) + broker mirror (T9), Brevo email + sender + DKIM (T5, T10), env vars + feature flag (T2, T8), CTA + /merci (T11), tests (T3–7), ops checklist (T10, T12). Decisions log all implemented: max_sites=1 (T6), 3-day grace (T6 `GRACE_DAYS`), hello@ sender (T2 default), expiry-driven lifecycle (T6–7).
- `LINK_MONTH`/`LINK_YEAR` are not plan placeholders: they are values produced by Task 10 and consumed verbatim in Task 11.
- Type consistency: `findBySubscriptionId/extendExpiry/markEmailSent` defined in T4 = names used in T6–7; `createMailer(cfg, transport)`/`sendLicenseEmail({to,key,plan})` consistent T5/T6; `buildApp(cfg, {db, mailer, stripeClient})` consistent T6 tests/app.js.
