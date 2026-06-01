# License Store on SQLite (Broker) — Implementation Plan (Plan A of 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Switch the SlashBooking broker from reading `licenses.json` to reading a shared SQLite `licenses` table, and migrate the existing license, so the upcoming dashboard (Plan B) can manage licenses in a real store.

**Architecture:** Add `better-sqlite3` to the broker. A new `lib/db.js` opens the SQLite file and ensures the canonical `licenses` schema (idempotent `CREATE TABLE IF NOT EXISTS`). `lib/licenses.js` `validate()` now takes a DB handle and queries by key (status `active` + expiry + site-origin allow-list, with the empty-site skip preserved). The router opens the DB once from `LICENSES_DB` and passes the handle down. A one-shot script imports the old `licenses.json`.

**Tech Stack:** Node 20, Express, better-sqlite3, Jest.

**Spec:** `docs/superpowers/specs/2026-06-01-license-dashboard-design.md`

**Repo for this plan:** `../slashbooking-broker` (sibling of the plugin repo). All paths below are relative to that repo.

**Conventions:**
- The broker uses `npm test` (Jest). Commit each task; do NOT push or tag (the controller handles release).
- The canonical `licenses` DDL lives in `lib/db.js` (this repo) and will be duplicated identically in the dashboard repo (Plan B) as `CREATE TABLE IF NOT EXISTS`; both are idempotent. The spec is the source of truth for the columns.
- After Plan A, deploying requires: `npm ci`, run the migration script once, set `LICENSES_DB`, restart. (Documented in Task 5.)

---

### Task 1: Add `better-sqlite3` + `LICENSES_DB` config + `lib/db.js`

**Files:**
- Modify: `package.json`
- Modify: `config.js`
- Create: `lib/db.js`
- Test: `test/db.test.js`

- [ ] **Step 1: Add the dependency**

Run: `npm install better-sqlite3@^11.3.0`
Expected: `better-sqlite3` appears under `dependencies` in `package.json`; install succeeds (it compiles a native binding).

- [ ] **Step 2: Write the failing test for `lib/db.js`**

Create `test/db.test.js`:

```js
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { openDb } = require('../lib/db');

describe('lib/db openDb', () => {
  test('creates the licenses table with the expected columns', () => {
    const file = path.join(os.tmpdir(), `dbtest-${Date.now()}-${Math.random()}.sqlite`);
    const db = openDb(file);
    const cols = db.prepare('PRAGMA table_info(licenses)').all().map((c) => c.name);
    db.close();
    fs.unlinkSync(file);

    for (const expected of [
      'id', 'key', 'status', 'plan', 'customer_email',
      'customer_name', 'notes', 'sites', 'expires', 'created_at', 'revoked_at',
    ]) {
      expect(cols).toContain(expected);
    }
  });

  test('is idempotent (opening an existing db twice does not throw)', () => {
    const file = path.join(os.tmpdir(), `dbtest-${Date.now()}-${Math.random()}.sqlite`);
    openDb(file).close();
    const db2 = openDb(file);
    expect(db2.prepare('SELECT COUNT(*) AS n FROM licenses').get().n).toBe(0);
    db2.close();
    fs.unlinkSync(file);
  });
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `npx jest test/db.test.js`
Expected: FAIL — `Cannot find module '../lib/db'`.

- [ ] **Step 4: Implement `lib/db.js`**

```js
'use strict';

const Database = require('better-sqlite3');

// Canonical licenses schema. Source of truth: the license-dashboard spec.
// Duplicated identically (CREATE TABLE IF NOT EXISTS) in the dashboard repo.
const SCHEMA = `
CREATE TABLE IF NOT EXISTS licenses (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  key            TEXT NOT NULL UNIQUE,
  status         TEXT NOT NULL DEFAULT 'active',
  plan           TEXT,
  customer_email TEXT,
  customer_name  TEXT,
  notes          TEXT,
  sites          TEXT,
  expires        TEXT,
  created_at     TEXT NOT NULL,
  revoked_at     TEXT
);
`;

/**
 * Open the shared SQLite database and ensure the schema exists.
 * @param {string} file absolute path to the SQLite file
 * @returns {import('better-sqlite3').Database}
 */
function openDb(file) {
  const db = new Database(file);
  db.pragma('journal_mode = WAL'); // safe concurrent access broker <-> dashboard
  db.exec(SCHEMA);
  return db;
}

module.exports = { openDb, SCHEMA };
```

- [ ] **Step 5: Add `licensesDb` to `config.js`**

In `config.js`, the config object is built from env. Replace the line:

```js
  licensesFile: required('LICENSES_FILE'),
```

with:

```js
  licensesDb: required('LICENSES_DB'),
```

- [ ] **Step 6: Run the db test to verify it passes**

Run: `npx jest test/db.test.js`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add package.json package-lock.json config.js lib/db.js test/db.test.js
git commit -m "feat(store): add better-sqlite3 + lib/db.js schema + LICENSES_DB config"
```

---

### Task 2: `validate()` reads SQLite (preserve all current semantics + add `revoked`)

**Files:**
- Modify: `lib/licenses.js`
- Modify: `test/licenses.test.js`

The current `validate(key, site, file)` reads JSON. It becomes `validate(key, site, db)` querying SQLite. Keep: unknown→invalid, expired→invalid, site origin allow-list with the empty-site skip, return shape `{valid, plan, expires}`. Add: `status !== 'active'` → invalid.

- [ ] **Step 1: Rewrite `test/licenses.test.js` to seed a temp SQLite db**

Replace the entire contents of `test/licenses.test.js` with:

```js
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { openDb } = require('../lib/db');
const { validate } = require('../lib/licenses');

function tmpDbWith(rows) {
  const file = path.join(os.tmpdir(), `lic-${Date.now()}-${Math.random()}.sqlite`);
  const db = openDb(file);
  const insert = db.prepare(
    `INSERT INTO licenses (key, status, plan, sites, expires, created_at)
     VALUES (@key, @status, @plan, @sites, @expires, @created_at)`
  );
  for (const r of rows) {
    insert.run({
      key: r.key,
      status: r.status || 'active',
      plan: r.plan ?? null,
      sites: r.sites ? JSON.stringify(r.sites) : null,
      expires: r.expires ?? null,
      created_at: '2026-01-01T00:00:00Z',
    });
  }
  return { db, file, cleanup: () => { db.close(); fs.unlinkSync(file); } };
}

describe('lib/licenses validate()', () => {
  let ctx;
  afterEach(() => ctx && ctx.cleanup());

  test('returns valid:true with plan and expires for a known active key', () => {
    ctx = tmpDbWith([{ key: 'GOOD', plan: 'pro', expires: '2999-01-01' }]);
    expect(validate('GOOD', 'https://site.com', ctx.db)).toEqual({ valid: true, plan: 'pro', expires: '2999-01-01' });
  });

  test('returns invalid for an unknown key', () => {
    ctx = tmpDbWith([{ key: 'GOOD', plan: 'pro', expires: '2999-01-01' }]);
    expect(validate('NOPE', 'https://site.com', ctx.db)).toEqual({ valid: false, plan: null, expires: null });
  });

  test('returns invalid for an expired key', () => {
    ctx = tmpDbWith([{ key: 'OLD', plan: 'pro', expires: '2000-01-01' }]);
    expect(validate('OLD', 'https://site.com', ctx.db).valid).toBe(false);
  });

  test('returns invalid for a revoked key', () => {
    ctx = tmpDbWith([{ key: 'REV', plan: 'pro', status: 'revoked', expires: '2999-01-01' }]);
    expect(validate('REV', 'https://site.com', ctx.db).valid).toBe(false);
  });

  test('enforces site allow-list when sites[] is present', () => {
    ctx = tmpDbWith([{ key: 'BOUND', plan: 'pro', expires: '2999-01-01', sites: ['https://allowed.com'] }]);
    expect(validate('BOUND', 'https://allowed.com', ctx.db).valid).toBe(true);
    expect(validate('BOUND', 'https://other.com', ctx.db).valid).toBe(false);
  });

  test('matches the site allow-list by origin, ignoring path and query', () => {
    ctx = tmpDbWith([{ key: 'BOUND', plan: 'pro', expires: '2999-01-01', sites: ['https://allowed.com'] }]);
    expect(validate('BOUND', 'https://allowed.com/wp-json/slashbooking/v1/admin/google/oauth/callback', ctx.db).valid).toBe(true);
    expect(validate('BOUND', 'https://evil.com/wp-json/cb', ctx.db).valid).toBe(false);
  });

  test('skips the site allow-list when site is empty (claim/refresh path)', () => {
    ctx = tmpDbWith([{ key: 'BOUND', plan: 'pro', expires: '2999-01-01', sites: ['https://allowed.com'] }]);
    expect(validate('BOUND', '', ctx.db).valid).toBe(true);
  });

  test('allows any site when sites is absent', () => {
    ctx = tmpDbWith([{ key: 'ANY', plan: 'pro', expires: '2999-01-01' }]);
    expect(validate('ANY', 'https://whatever.com', ctx.db).valid).toBe(true);
  });

  test('treats a missing expires as non-expiring', () => {
    ctx = tmpDbWith([{ key: 'PERP', plan: 'lifetime' }]);
    const out = validate('PERP', 'https://site.com', ctx.db);
    expect(out.valid).toBe(true);
    expect(out.expires).toBeNull();
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx jest test/licenses.test.js`
Expected: FAIL — current `validate` reads a JSON file, so calls with a DB handle return invalid / throw. Tests red.

- [ ] **Step 3: Rewrite `lib/licenses.js` to query SQLite**

Replace the entire contents of `lib/licenses.js` with:

```js
'use strict';

const INVALID = Object.freeze({ valid: false, plan: null, expires: null });

function normaliseSite(s) {
  return String(s || '').replace(/\/+$/, '').toLowerCase();
}

// Reduce any URL (a bare origin, or a deep callback URL with path/query) to its
// scheme+host[:port] origin, so the allow-list matches regardless of the path.
function originOf(s) {
  try {
    return new URL(String(s)).origin.toLowerCase();
  } catch (e) {
    return normaliseSite(s);
  }
}

/**
 * Validate a license key against the SQLite store.
 * @param {string} key
 * @param {string} site request site/return URL ('' to skip the site allow-list)
 * @param {import('better-sqlite3').Database} db
 * @returns {{valid: boolean, plan: string|null, expires: string|null}}
 */
function validate(key, site, db) {
  if (!key) {
    return INVALID;
  }

  let rec;
  try {
    rec = db
      .prepare('SELECT status, plan, expires, sites FROM licenses WHERE key = ?')
      .get(String(key));
  } catch (e) {
    return INVALID;
  }

  if (!rec || rec.status !== 'active') {
    return INVALID;
  }

  const expires = rec.expires || null;
  if (expires && new Date(expires).getTime() < Date.now()) {
    return INVALID;
  }

  // Enforce the site allow-list only when a site is supplied. /oauth/claim and
  // /oauth/refresh pass an empty site on purpose.
  if (site && rec.sites) {
    let allowedList = null;
    try {
      allowedList = JSON.parse(rec.sites);
    } catch (e) {
      allowedList = null;
    }
    if (Array.isArray(allowedList) && allowedList.length > 0) {
      const wanted = originOf(site);
      const allowed = allowedList.map(originOf);
      if (!allowed.includes(wanted)) {
        return INVALID;
      }
    }
  }

  return { valid: true, plan: rec.plan || null, expires };
}

module.exports = { validate };
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx jest test/licenses.test.js`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add lib/licenses.js test/licenses.test.js
git commit -m "feat(store): validate() reads licenses from SQLite (+ revoked check)"
```

---

### Task 3: Wire the DB through the router and route handlers

**Files:**
- Modify: `app.js`
- Modify: `routes/oauth.js`
- Modify: `routes/license.js`
- Modify: `test/helpers/buildApp.js`

The router opens the DB once and passes the handle to the routers; each `validate(...)` call uses the DB instead of `cfg.licensesFile`.

- [ ] **Step 1: Open the DB in `buildRouter` and pass it down (`app.js`)**

In `app.js`, change `buildRouter` so it opens the DB and passes it to both sub-routers. Replace:

```js
const { ClaimStore } = require('./lib/claims');

/**
 * Builds the mountable broker Router. The host app should mount it under BASE_PATH.
 */
function buildRouter(cfg = config, opts = {}) {
  const router = express.Router();
  const claims = new ClaimStore({ ttlSeconds: cfg.claimTtlSeconds });
  router.__claims = claims; // exposed for tests to stop the sweep timer

  router.use(oauthRouter(cfg, claims, opts));
  router.use(licenseRouter(cfg));
  return router;
}
```

with:

```js
const { ClaimStore } = require('./lib/claims');
const { openDb } = require('./lib/db');

/**
 * Builds the mountable broker Router. The host app should mount it under BASE_PATH.
 * Opens the shared SQLite store once (or uses opts.db, injected by tests).
 */
function buildRouter(cfg = config, opts = {}) {
  const router = express.Router();
  const claims = new ClaimStore({ ttlSeconds: cfg.claimTtlSeconds });
  router.__claims = claims; // exposed for tests to stop the sweep timer

  const db = opts.db || openDb(cfg.licensesDb);
  router.__db = db; // exposed for tests/teardown

  router.use(oauthRouter(cfg, claims, db, opts));
  router.use(licenseRouter(cfg, db));
  return router;
}
```

- [ ] **Step 2: Thread the DB into `routes/oauth.js`**

In `routes/oauth.js`, change the function signature and the three `validate` calls. Change:

```js
function oauthRouter(cfg, claims, opts = {}) {
```

to:

```js
function oauthRouter(cfg, claims, db, opts = {}) {
```

Then replace each `licenses.validate(..., cfg.licensesFile)` with the DB handle:
- in `/oauth/start`: `const lic = licenses.validate(license, returnUrl, cfg.licensesFile);` → `const lic = licenses.validate(license, returnUrl, db);`
- in `/oauth/claim`: `const lic = licenses.validate(license, '', cfg.licensesFile);` → `const lic = licenses.validate(license, '', db);`
- in `/oauth/refresh`: `const lic = licenses.validate(license, '', cfg.licensesFile);` → `const lic = licenses.validate(license, '', db);`

- [ ] **Step 3: Thread the DB into `routes/license.js`**

In `routes/license.js`, change:

```js
function licenseRouter(cfg) {
  const router = express.Router();

  router.post('/license/validate', (req, res) => {
    const { license, site } = req.body || {};
    if (!license || !site) {
      return res.status(400).json({ error: 'invalid_request' });
    }
    const result = licenses.validate(license, site, cfg.licensesFile);
    return res.json(result);
  });

  return router;
}
```

to:

```js
function licenseRouter(cfg, db) {
  const router = express.Router();

  router.post('/license/validate', (req, res) => {
    const { license, site } = req.body || {};
    if (!license || !site) {
      return res.status(400).json({ error: 'invalid_request' });
    }
    const result = licenses.validate(license, site, db);
    return res.json(result);
  });

  return router;
}
```

- [ ] **Step 4: Update the test helper to seed SQLite (`test/helpers/buildApp.js`)**

Replace the entire contents of `test/helpers/buildApp.js` with:

```js
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const express = require('express');
const { openDb } = require('../../lib/db');

/**
 * Builds an isolated Express app with the broker router mounted at '/' for tests,
 * backed by a temp SQLite db seeded from `licenses` (same shape the suite used
 * before: { key, plan, expires, sites }, plus optional status).
 * Returns { app, db, dbFile, router, cleanup }.
 */
function buildTestApp({ licenses = [], rateLimitMax } = {}) {
  const dbFile = path.join(os.tmpdir(), `lic-${Date.now()}-${Math.random()}.sqlite`);
  const db = openDb(dbFile);
  const insert = db.prepare(
    `INSERT INTO licenses (key, status, plan, sites, expires, created_at)
     VALUES (@key, @status, @plan, @sites, @expires, @created_at)`
  );
  for (const r of licenses) {
    insert.run({
      key: r.key,
      status: r.status || 'active',
      plan: r.plan ?? null,
      sites: r.sites ? JSON.stringify(r.sites) : null,
      expires: r.expires ?? null,
      created_at: '2026-01-01T00:00:00Z',
    });
  }

  process.env.GOOGLE_CLIENT_ID = 'cid';
  process.env.GOOGLE_CLIENT_SECRET = 'csecret';
  process.env.GOOGLE_REDIRECT_URI = 'https://slashbox.fr/slashbooking/api/oauth/callback';
  process.env.STATE_KEY = 'a-very-long-random-state-key-value';
  process.env.CLAIM_TTL_SECONDS = '60';
  process.env.LICENSES_DB = dbFile;
  process.env.BASE_PATH = '/slashbooking/api';
  process.env.ALLOWED_RETURN_SCHEME = 'https';

  jest.resetModules();
  const buildRouter = require('../../app');
  const router = buildRouter(undefined, { rateLimitMax, db });

  const app = express();
  app.use(express.json());
  app.use('/', router);

  const cleanup = () => {
    if (router.__claims && router.__claims.stop) {
      router.__claims.stop();
    }
    try { db.close(); } catch (e) { /* ignore */ }
    try { fs.unlinkSync(dbFile); } catch (e) { /* ignore */ }
  };

  return { app, db, dbFile, router, cleanup };
}

module.exports = { buildTestApp };
```

- [ ] **Step 5: Run the full broker suite**

Run: `npm test`
Expected: PASS — all suites green (the oauth.start/claim/refresh/license.routes/ratelimit tests use `buildTestApp`, now SQLite-backed; the `licenses` fixture shape is unchanged so they pass without edits). `config.test.js` still checks `googleRedirectUri`/`basePath` (unaffected). If `config.test.js` references `licensesFile`/`LICENSES_FILE`, update those assertions to `licensesDb`/`LICENSES_DB` (read the file; change only the license-path assertion, leave the rest).

- [ ] **Step 6: Commit**

```bash
git add app.js routes/oauth.js routes/license.js test/helpers/buildApp.js test/config.test.js
git commit -m "feat(store): thread the SQLite handle through the router and routes"
```

---

### Task 4: One-shot migration script (`licenses.json` → SQLite)

**Files:**
- Create: `scripts/migrate-json-to-sqlite.js`
- Test: `test/migrate.test.js`

- [ ] **Step 1: Write the failing test**

Create `test/migrate.test.js`:

```js
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { openDb } = require('../lib/db');
const { importJson } = require('../scripts/migrate-json-to-sqlite');

describe('migrate-json-to-sqlite importJson', () => {
  test('imports JSON rows into the licenses table (idempotent on key)', () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'mig-'));
    const jsonFile = path.join(dir, 'licenses.json');
    const dbFile = path.join(dir, 'licenses.sqlite');
    fs.writeFileSync(jsonFile, JSON.stringify([
      { key: 'ABCD-1234', plan: 'pro', expires: '2027-01-01', sites: ['https://client.com'] },
      { key: 'NOEXP', plan: 'lifetime' },
    ]));

    const db = openDb(dbFile);
    const n1 = importJson(jsonFile, db);
    expect(n1).toBe(2);

    const row = db.prepare('SELECT * FROM licenses WHERE key = ?').get('ABCD-1234');
    expect(row.plan).toBe('pro');
    expect(row.status).toBe('active');
    expect(JSON.parse(row.sites)).toEqual(['https://client.com']);

    // Re-running does not duplicate (INSERT OR IGNORE on unique key).
    const n2 = importJson(jsonFile, db);
    expect(n2).toBe(0);
    expect(db.prepare('SELECT COUNT(*) AS n FROM licenses').get().n).toBe(2);

    db.close();
    fs.rmSync(dir, { recursive: true, force: true });
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx jest test/migrate.test.js`
Expected: FAIL — `Cannot find module '../scripts/migrate-json-to-sqlite'`.

- [ ] **Step 3: Implement `scripts/migrate-json-to-sqlite.js`**

```js
'use strict';

const fs = require('fs');
const { openDb } = require('../lib/db');

/**
 * Import a broker licenses.json array into the SQLite licenses table.
 * Idempotent on `key` (INSERT OR IGNORE). Returns the number of rows inserted.
 * @param {string} jsonFile
 * @param {import('better-sqlite3').Database} db
 * @returns {number}
 */
function importJson(jsonFile, db) {
  const raw = fs.readFileSync(jsonFile, 'utf8');
  const parsed = JSON.parse(raw);
  const rows = Array.isArray(parsed) ? parsed : [];

  const insert = db.prepare(
    `INSERT OR IGNORE INTO licenses (key, status, plan, sites, expires, created_at)
     VALUES (@key, 'active', @plan, @sites, @expires, @created_at)`
  );
  const now = new Date().toISOString();
  let inserted = 0;
  for (const r of rows) {
    if (!r || !r.key) {
      continue;
    }
    const info = insert.run({
      key: String(r.key),
      plan: r.plan ?? null,
      sites: Array.isArray(r.sites) && r.sites.length > 0 ? JSON.stringify(r.sites) : null,
      expires: r.expires ?? null,
      created_at: now,
    });
    inserted += info.changes;
  }
  return inserted;
}

// CLI: node scripts/migrate-json-to-sqlite.js <licenses.json> <licenses.sqlite>
if (require.main === module) {
  const [jsonFile, dbFile] = process.argv.slice(2);
  if (!jsonFile || !dbFile) {
    console.error('Usage: node scripts/migrate-json-to-sqlite.js <licenses.json> <licenses.sqlite>');
    process.exit(1);
  }
  const db = openDb(dbFile);
  const n = importJson(jsonFile, db);
  db.close();
  console.log(`Imported ${n} license(s) into ${dbFile}`);
}

module.exports = { importJson };
```

> Note on the `require.main === module` guard: this is a CLI script run manually (not by Passenger), so the guard is correct here (unlike the Passenger app entry).

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx jest test/migrate.test.js`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add scripts/migrate-json-to-sqlite.js test/migrate.test.js
git commit -m "feat(store): one-shot licenses.json -> SQLite migration script"
```

---

### Task 5: Docs, env, version bump, full verification

**Files:**
- Modify: `.env.example`
- Modify: `DEPLOY.md`
- Modify: `package.json` (version)

- [ ] **Step 1: Update `.env.example`**

Replace the `LICENSES_FILE` line and its comment:

```
# Absolute path to the licenses JSON file: [{ "key": "...", "plan": "pro", "expires": "2027-01-01", "sites": ["https://example.com"] }]
LICENSES_FILE=/var/www/vhosts/slashbox.fr/broker.slashbox.fr/httpdocs/licenses.json
```

with:

```
# Absolute path to the SHARED SQLite licenses store (read by the broker, written by
# the dashboard). Keep it OUTSIDE any web root, on a path both apps' user can access.
LICENSES_DB=/var/www/vhosts/slashbox.fr/private/licenses.sqlite
```

- [ ] **Step 2: Update `DEPLOY.md`**

Add a section (after the env-vars step) titled `## Migrating to the SQLite license store` with this content:

```
The broker now reads licenses from a shared SQLite file (env `LICENSES_DB`),
not `licenses.json`. On deploy of this version:

1. `npm ci` (installs better-sqlite3 — native build; Node 20).
2. Choose a shared path readable/writable by both the broker and the dashboard
   (same Plesk subscription user), outside any web root, e.g.
   `/var/www/vhosts/slashbox.fr/private/licenses.sqlite`. Set `LICENSES_DB` to it.
3. One-time import of the old JSON:
   `node scripts/migrate-json-to-sqlite.js /path/to/licenses.json "$LICENSES_DB"`
4. Restart the app (Plesk -> Restart App). Verify a known key still validates:
   `curl -s -XPOST https://broker.slashbox.fr/license/validate -H 'content-type: application/json' -d '{"license":"YOURKEY","site":"https://yoursite.com"}'` -> `{"valid":true,...}`.

`LICENSES_FILE` (JSON) is no longer used at runtime — keep the JSON only as a
backup until the SQLite store is confirmed.
```

- [ ] **Step 3: Bump the broker version**

In `package.json`, change `"version": "1.0.3",` to `"version": "1.0.4",`.

- [ ] **Step 4: Full verification**

Run: `npm test`
Expected: all suites green (db, licenses, migrate, oauth.*, license.routes, ratelimit, config, health, state, claims, google, logger).

Run: `node --check scripts/migrate-json-to-sqlite.js && node --check lib/db.js && node --check lib/licenses.js && node --check app.js`
Expected: no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add .env.example DEPLOY.md package.json
git commit -m "docs(store): document SQLite migration; bump broker to 1.0.4"
```

---

## Self-Review

**Spec coverage (for the broker-side scope of the v1 spec):**
- "Broker reads licenses from SQLite" → Tasks 2 + 3. ✓
- "validate(): active + expiry + site-origin allow-list + empty-site skip; same return shape; revoked → invalid" → Task 2 (tests cover each case). ✓
- "better-sqlite3; LICENSES_DB env; LICENSES_FILE retired" → Tasks 1 + 5. ✓
- "Canonical schema, broker only reads; dashboard duplicates DDL (Plan B)" → Task 1 `lib/db.js` SCHEMA + note. ✓
- "One-time import of licenses.json" → Task 4. ✓
- "All existing broker tests keep passing (fixtures move JSON → SQLite)" → Task 3 Step 5 + the rewritten `buildApp.js`/`licenses.test.js`. ✓
- Dashboard app, auth, key-gen, payment → NOT here by design (Plan B / v2). ✓

**Placeholder scan:** No TBD/TODO. The only conditional is Task 3 Step 5's `config.test.js` assertion ("if it references LICENSES_FILE, change that one assertion") — this is an explicit, bounded instruction to read the file and update one assertion, not a vague placeholder.

**Type/identifier consistency:** `openDb(file)` (Task 1) used identically in Tasks 2/3/4 and the test helper. `validate(key, site, db)` signature consistent across `lib/licenses.js` (Task 2) and all call sites (Task 3). `importJson(jsonFile, db)` consistent between script and test (Task 4). Column names match the `lib/db.js` SCHEMA everywhere (`status`, `sites`, `expires`, `created_at`, `revoked_at`).

**Note for execution:** This plan changes the LIVE broker's data layer. After merge it must be deployed (Task 5 steps), which touches `broker.slashbox.fr` (git pull + npm ci + migrate + restart). The existing license is preserved via the import. Plan B (dashboard) builds on this same store.
