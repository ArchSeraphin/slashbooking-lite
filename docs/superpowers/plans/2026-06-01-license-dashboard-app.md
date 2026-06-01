# License Dashboard App — Implementation Plan (Plan B of 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A small admin web app (`slashbooking-dashboard`) for Nicolas to list / create / revoke / extend SlashBooking licenses, writing to the same SQLite store the broker reads (Plan A).

**Architecture:** New Node/Express app, server-rendered HTML (no build/SPA). `better-sqlite3` against the shared `LICENSES_DB` file. Single-admin auth via env credentials (scrypt-hashed password) + an HMAC-signed session cookie (SameSite=Strict). Deployed on `dashboard.slashbooking.fr` under the broker's Plesk subscription so it shares the filesystem.

**Tech Stack:** Node 20, Express, better-sqlite3, `cookie`, helmet, Jest + supertest. Node stdlib `crypto` for hashing/sessions (no extra native dep).

**Spec:** `plugins-booking/docs/superpowers/specs/2026-06-01-license-dashboard-design.md`
**Depends on:** Plan A (broker now reads the same SQLite `licenses` table).

**Repo for this plan:** a NEW sibling repo `../slashbooking-dashboard` (created in Task 1). All paths below are relative to it. Commit per task; do NOT push/tag (controller handles release).

**Schema note:** `lib/db.js` here uses the SAME canonical `licenses` DDL as the broker's `lib/db.js` (idempotent `CREATE TABLE IF NOT EXISTS`). The spec is the source of truth for columns.

---

### Task 1: Repo scaffold + `lib/db.js` + `lib/licenses-repo.js` (TDD the repo)

**Files:**
- Create: `package.json`, `.gitignore`, `lib/db.js`, `lib/licenses-repo.js`, `test/licenses-repo.test.js`

- [ ] **Step 1: Init the repo + deps**

```bash
mkdir -p ../slashbooking-dashboard && cd ../slashbooking-dashboard
git init
npm init -y
npm install express better-sqlite3@^11.3.0 cookie helmet
npm install --save-dev jest supertest
```

- [ ] **Step 2: Set `package.json` scripts + jest config**

Edit `package.json` so it contains (merge with what `npm init` produced — keep the dependency versions):

```json
{
  "name": "slashbooking-dashboard",
  "version": "1.0.0",
  "description": "Admin dashboard to manage SlashBooking licenses (shared SQLite store).",
  "license": "UNLICENSED",
  "private": true,
  "engines": { "node": ">=20" },
  "main": "app.js",
  "scripts": {
    "start": "node server.js",
    "test": "jest --runInBand"
  },
  "jest": { "testEnvironment": "node", "testMatch": ["**/test/**/*.test.js"] }
}
```

- [ ] **Step 3: Create `.gitignore`**

```
node_modules/
*.sqlite
*.sqlite-wal
*.sqlite-shm
.env
```

- [ ] **Step 4: Write the failing repo test (`test/licenses-repo.test.js`)**

```js
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { openDb } = require('../lib/db');
const repo = require('../lib/licenses-repo');

function tmpDb() {
  const file = path.join(os.tmpdir(), `dash-${Date.now()}-${Math.random()}.sqlite`);
  const db = openDb(file);
  return { db, cleanup: () => { db.close(); fs.unlinkSync(file); } };
}

describe('licenses-repo', () => {
  let ctx;
  afterEach(() => ctx && ctx.cleanup());

  test('create + get round-trips fields and defaults status to active', () => {
    ctx = tmpDb();
    const id = repo.createLicense(ctx.db, {
      key: 'SB-AAAA-BBBB-CCCC', plan: 'pro', customer_email: 'a@b.com',
      customer_name: 'Client', notes: 'n', sites: ['https://c.com'], expires: '2027-01-01',
      created_at: '2026-01-01T00:00:00Z',
    });
    const row = repo.getLicense(ctx.db, id);
    expect(row.key).toBe('SB-AAAA-BBBB-CCCC');
    expect(row.status).toBe('active');
    expect(row.plan).toBe('pro');
    expect(JSON.parse(row.sites)).toEqual(['https://c.com']);
    expect(row.expires).toBe('2027-01-01');
  });

  test('create stores null sites when none given', () => {
    ctx = tmpDb();
    const id = repo.createLicense(ctx.db, { key: 'SB-X', created_at: '2026-01-01T00:00:00Z' });
    expect(repo.getLicense(ctx.db, id).sites).toBeNull();
  });

  test('list returns newest first and filters by status', () => {
    ctx = tmpDb();
    repo.createLicense(ctx.db, { key: 'SB-1', created_at: '2026-01-01T00:00:00Z' });
    repo.createLicense(ctx.db, { key: 'SB-2', created_at: '2026-02-01T00:00:00Z' });
    const all = repo.listLicenses(ctx.db);
    expect(all.map((r) => r.key)).toEqual(['SB-2', 'SB-1']);

    const id2 = repo.getLicense(ctx.db, all[0].id).id;
    repo.revokeLicense(ctx.db, id2, '2026-03-01T00:00:00Z');
    expect(repo.listLicenses(ctx.db, { status: 'active' }).map((r) => r.key)).toEqual(['SB-1']);
    expect(repo.listLicenses(ctx.db, { status: 'revoked' }).map((r) => r.key)).toEqual(['SB-2']);
    expect(repo.listLicenses(ctx.db, { status: 'all' }).length).toBe(2);
  });

  test('revoke sets status + revoked_at', () => {
    ctx = tmpDb();
    const id = repo.createLicense(ctx.db, { key: 'SB-R', created_at: '2026-01-01T00:00:00Z' });
    expect(repo.revokeLicense(ctx.db, id, '2026-03-01T00:00:00Z')).toBe(1);
    const row = repo.getLicense(ctx.db, id);
    expect(row.status).toBe('revoked');
    expect(row.revoked_at).toBe('2026-03-01T00:00:00Z');
  });

  test('update sets expires and sites (sites null when empty)', () => {
    ctx = tmpDb();
    const id = repo.createLicense(ctx.db, { key: 'SB-U', created_at: '2026-01-01T00:00:00Z' });
    repo.updateLicense(ctx.db, id, { expires: '2099-01-01', sites: ['https://x.com'] });
    let row = repo.getLicense(ctx.db, id);
    expect(row.expires).toBe('2099-01-01');
    expect(JSON.parse(row.sites)).toEqual(['https://x.com']);
    repo.updateLicense(ctx.db, id, { expires: null, sites: [] });
    row = repo.getLicense(ctx.db, id);
    expect(row.expires).toBeNull();
    expect(row.sites).toBeNull();
  });

  test('getLicense returns null for a missing id', () => {
    ctx = tmpDb();
    expect(repo.getLicense(ctx.db, 999)).toBeNull();
  });
});
```

- [ ] **Step 5: Run it — expect FAIL** (`Cannot find module '../lib/db'`): `npx jest test/licenses-repo.test.js`

- [ ] **Step 6: Create `lib/db.js`** (identical canonical schema to the broker)

```js
'use strict';

const Database = require('better-sqlite3');

// Canonical licenses schema — MUST stay identical to the broker's lib/db.js.
// Source of truth: docs/superpowers/specs/2026-06-01-license-dashboard-design.md
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

function openDb(file) {
  const db = new Database(file);
  db.pragma('journal_mode = WAL');
  db.exec(SCHEMA);
  return db;
}

module.exports = { openDb, SCHEMA };
```

- [ ] **Step 7: Create `lib/licenses-repo.js`**

```js
'use strict';

function createLicense(db, data) {
  const info = db
    .prepare(
      `INSERT INTO licenses
         (key, status, plan, customer_email, customer_name, notes, sites, expires, created_at)
       VALUES
         (@key, 'active', @plan, @customer_email, @customer_name, @notes, @sites, @expires, @created_at)`
    )
    .run({
      key: data.key,
      plan: data.plan ?? null,
      customer_email: data.customer_email ?? null,
      customer_name: data.customer_name ?? null,
      notes: data.notes ?? null,
      sites: Array.isArray(data.sites) && data.sites.length > 0 ? JSON.stringify(data.sites) : null,
      expires: data.expires || null,
      created_at: data.created_at || new Date().toISOString(),
    });
  return info.lastInsertRowid;
}

function listLicenses(db, { status } = {}) {
  if (status && status !== 'all') {
    return db.prepare('SELECT * FROM licenses WHERE status = ? ORDER BY created_at DESC, id DESC').all(status);
  }
  return db.prepare('SELECT * FROM licenses ORDER BY created_at DESC, id DESC').all();
}

function getLicense(db, id) {
  return db.prepare('SELECT * FROM licenses WHERE id = ?').get(id) || null;
}

function revokeLicense(db, id, now) {
  return db
    .prepare("UPDATE licenses SET status = 'revoked', revoked_at = ? WHERE id = ?")
    .run(now || new Date().toISOString(), id).changes;
}

function updateLicense(db, id, { expires, sites }) {
  return db
    .prepare('UPDATE licenses SET expires = @expires, sites = @sites WHERE id = @id')
    .run({
      id,
      expires: expires || null,
      sites: Array.isArray(sites) && sites.length > 0 ? JSON.stringify(sites) : null,
    }).changes;
}

module.exports = { createLicense, listLicenses, getLicense, revokeLicense, updateLicense };
```

- [ ] **Step 8: Run it — expect PASS:** `npx jest test/licenses-repo.test.js`

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat(dashboard): scaffold repo + SQLite db + licenses repository"
```

---

### Task 2: `lib/keygen.js` (unique `SB-XXXX-XXXX-XXXX` keys)

**Files:** Create `lib/keygen.js`, `test/keygen.test.js`

- [ ] **Step 1: Write the failing test**

```js
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { openDb } = require('../lib/db');
const repo = require('../lib/licenses-repo');
const { generateKey, createUniqueKey } = require('../lib/keygen');

describe('keygen', () => {
  test('generateKey matches SB-XXXX-XXXX-XXXX (Crockford base32, no I L O U)', () => {
    for (let i = 0; i < 200; i++) {
      expect(generateKey()).toMatch(/^SB-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/);
    }
  });

  test('createUniqueKey returns a key not already in the db', () => {
    const file = path.join(os.tmpdir(), `kg-${Date.now()}-${Math.random()}.sqlite`);
    const db = openDb(file);
    const key = createUniqueKey(db);
    repo.createLicense(db, { key, created_at: '2026-01-01T00:00:00Z' });
    const key2 = createUniqueKey(db);
    expect(key2).not.toBe(key);
    db.close();
    fs.unlinkSync(file);
  });
});
```

- [ ] **Step 2: Run it — expect FAIL:** `npx jest test/keygen.test.js`

- [ ] **Step 3: Implement `lib/keygen.js`**

```js
'use strict';

const crypto = require('crypto');

// Crockford base32 alphabet (excludes I, L, O, U to avoid ambiguity).
const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

function group() {
  const bytes = crypto.randomBytes(4);
  let s = '';
  for (let i = 0; i < 4; i++) {
    s += ALPHABET[bytes[i] % 32];
  }
  return s;
}

function generateKey() {
  return `SB-${group()}-${group()}-${group()}`;
}

/**
 * Generate a key guaranteed not to collide with an existing row.
 * @param {import('better-sqlite3').Database} db
 */
function createUniqueKey(db) {
  const exists = db.prepare('SELECT 1 FROM licenses WHERE key = ?');
  for (let i = 0; i < 20; i++) {
    const key = generateKey();
    if (!exists.get(key)) {
      return key;
    }
  }
  throw new Error('Could not generate a unique license key');
}

module.exports = { generateKey, createUniqueKey };
```

- [ ] **Step 4: Run it — expect PASS:** `npx jest test/keygen.test.js`

- [ ] **Step 5: Commit**

```bash
git add lib/keygen.js test/keygen.test.js
git commit -m "feat(dashboard): unique license key generator (Crockford base32)"
```

---

### Task 3: `lib/auth.js` + `scripts/hash-password.js` (password + session)

**Files:** Create `lib/auth.js`, `test/auth.test.js`, `scripts/hash-password.js`

- [ ] **Step 1: Write the failing test**

```js
'use strict';

const { hashPassword, verifyPassword, signSession, verifySession } = require('../lib/auth');

describe('auth', () => {
  test('hash + verify password round-trips and rejects wrong password', () => {
    const stored = hashPassword('s3cret');
    expect(stored).toContain(':');
    expect(verifyPassword('s3cret', stored)).toBe(true);
    expect(verifyPassword('nope', stored)).toBe(false);
    expect(verifyPassword('s3cret', 'garbage')).toBe(false);
  });

  test('sign + verify session returns the username before expiry', () => {
    const now = 1_000_000;
    const token = signSession('nicolas', 'sekret', 60_000, now);
    expect(verifySession(token, 'sekret', now + 1)).toBe('nicolas');
  });

  test('verify session rejects expired, tampered, and wrong-secret tokens', () => {
    const now = 1_000_000;
    const token = signSession('nicolas', 'sekret', 60_000, now);
    expect(verifySession(token, 'sekret', now + 61_000)).toBeNull(); // expired
    expect(verifySession(token + 'x', 'sekret', now + 1)).toBeNull(); // tampered
    expect(verifySession(token, 'other', now + 1)).toBeNull(); // wrong secret
    expect(verifySession('a.b', 'sekret', now + 1)).toBeNull(); // malformed
  });
});
```

- [ ] **Step 2: Run it — expect FAIL:** `npx jest test/auth.test.js`

- [ ] **Step 3: Implement `lib/auth.js`**

```js
'use strict';

const crypto = require('crypto');
const cookie = require('cookie');

const SESSION_COOKIE = 'sb_dash_session';

function hashPassword(plain) {
  const salt = crypto.randomBytes(16);
  const hash = crypto.scryptSync(String(plain), salt, 64);
  return `${salt.toString('hex')}:${hash.toString('hex')}`;
}

function verifyPassword(plain, stored) {
  const [saltHex, hashHex] = String(stored || '').split(':');
  if (!saltHex || !hashHex) {
    return false;
  }
  let salt;
  let expected;
  try {
    salt = Buffer.from(saltHex, 'hex');
    expected = Buffer.from(hashHex, 'hex');
  } catch (e) {
    return false;
  }
  if (expected.length === 0) {
    return false;
  }
  const actual = crypto.scryptSync(String(plain), salt, expected.length);
  return actual.length === expected.length && crypto.timingSafeEqual(actual, expected);
}

function signSession(username, secret, ttlMs, now) {
  const exp = now + ttlMs;
  const payload = `${Buffer.from(String(username)).toString('base64url')}.${exp}`;
  const sig = crypto.createHmac('sha256', secret).update(payload).digest('base64url');
  return `${payload}.${sig}`;
}

function verifySession(token, secret, now) {
  if (typeof token !== 'string') {
    return null;
  }
  const parts = token.split('.');
  if (parts.length !== 3) {
    return null;
  }
  const [userB64, expStr, sig] = parts;
  const payload = `${userB64}.${expStr}`;
  const expected = crypto.createHmac('sha256', secret).update(payload).digest('base64url');
  const a = Buffer.from(sig);
  const b = Buffer.from(expected);
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
    return null;
  }
  const exp = parseInt(expStr, 10);
  if (!Number.isFinite(exp) || exp < now) {
    return null;
  }
  return Buffer.from(userB64, 'base64url').toString('utf8');
}

const SESSION_TTL_MS = 12 * 60 * 60 * 1000; // 12h

/**
 * Express middleware factory: 302 -> /login when there is no valid session.
 */
function requireAuth(cfg) {
  return (req, res, next) => {
    const cookies = cookie.parse(req.headers.cookie || '');
    const user = verifySession(cookies[SESSION_COOKIE], cfg.sessionSecret, Date.now());
    if (!user) {
      return res.redirect(302, `${cfg.basePath === '/' ? '' : cfg.basePath}/login`);
    }
    req.dashUser = user;
    return next();
  };
}

module.exports = {
  SESSION_COOKIE,
  SESSION_TTL_MS,
  hashPassword,
  verifyPassword,
  signSession,
  verifySession,
  requireAuth,
};
```

- [ ] **Step 4: Run it — expect PASS:** `npx jest test/auth.test.js`

- [ ] **Step 5: Create `scripts/hash-password.js`** (admin generates `DASH_PASS_HASH` for env)

```js
'use strict';

const { hashPassword } = require('../lib/auth');

// CLI: node scripts/hash-password.js '<plaintext password>'
if (require.main === module) {
  const plain = process.argv[2];
  if (!plain) {
    console.error("Usage: node scripts/hash-password.js '<password>'");
    process.exit(1);
  }
  console.log(hashPassword(plain));
}
```

- [ ] **Step 6: Commit**

```bash
git add lib/auth.js test/auth.test.js scripts/hash-password.js
git commit -m "feat(dashboard): scrypt password hashing + HMAC session cookie + auth middleware"
```

---

### Task 4: `lib/views.js` (server-rendered HTML)

**Files:** Create `lib/views.js`, `public/sb-styles.css`, `test/views.test.js`

- [ ] **Step 1: Write the failing test** (pure render functions; assert key content + escaping)

```js
'use strict';

const views = require('../lib/views');

describe('views', () => {
  test('layout wraps body and escapes the title', () => {
    const html = views.layout({ title: '<x>', body: '<p>hi</p>', basePath: '' });
    expect(html).toContain('<!DOCTYPE html>');
    expect(html).toContain('<p>hi</p>');
    expect(html).toContain('&lt;x&gt;');
  });

  test('listPage renders a row and escapes customer fields', () => {
    const html = views.listPage({
      basePath: '',
      filter: 'all',
      rows: [{
        id: 1, key: 'SB-1', status: 'active', plan: 'pro',
        customer_email: 'a@b.com', customer_name: '<b>x</b>', sites: null,
        expires: null, created_at: '2026-01-01T00:00:00Z', revoked_at: null,
      }],
    });
    expect(html).toContain('SB-1');
    expect(html).toContain('&lt;b&gt;x&lt;/b&gt;');
    expect(html).toContain('/licenses/1/revoke');
  });

  test('loginPage shows an error when given one', () => {
    expect(views.loginPage({ basePath: '', error: 'Bad' })).toContain('Bad');
  });
});
```

- [ ] **Step 2: Run it — expect FAIL:** `npx jest test/views.test.js`

- [ ] **Step 3: Implement `lib/views.js`**

```js
'use strict';

function esc(v) {
  return String(v ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function layout({ title, body, basePath = '' }) {
  return `<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${esc(title)} · SlashBooking</title>
  <link rel="stylesheet" href="${basePath}/public/sb-styles.css">
</head>
<body class="sb-dash">
  <header class="sb-dash-header">
    <strong>SlashBooking</strong> <span class="muted">licences</span>
    <a class="sb-logout" href="${basePath}/logout-link">déconnexion</a>
  </header>
  <main class="sb-dash-main">
${body}
  </main>
</body>
</html>`;
}

function loginPage({ basePath = '', error = '' }) {
  const body = `    <form method="post" action="${basePath}/login" class="sb-card sb-login">
      <h1>Connexion</h1>
      ${error ? `<p class="sb-error">${esc(error)}</p>` : ''}
      <label>Identifiant<input name="username" autocomplete="username" required></label>
      <label>Mot de passe<input name="password" type="password" autocomplete="current-password" required></label>
      <button class="btn btn-primary" type="submit">Se connecter</button>
    </form>`;
  return layout({ title: 'Connexion', body, basePath });
}

function badge(status) {
  return status === 'active'
    ? '<span class="sb-badge sb-badge-active">active</span>'
    : '<span class="sb-badge sb-badge-revoked">révoquée</span>';
}

function listPage({ basePath = '', rows = [], filter = 'all' }) {
  const tabs = ['all', 'active', 'revoked']
    .map((s) => `<a class="${filter === s ? 'active' : ''}" href="${basePath}/?status=${s}">${s}</a>`)
    .join(' ');

  const trs = rows.map((r) => {
    const sites = r.sites ? esc(JSON.parse(r.sites).join(', ')) : '<span class="muted">tous</span>';
    const revoke = r.status === 'active'
      ? `<form method="post" action="${basePath}/licenses/${r.id}/revoke" onsubmit="return confirm('Révoquer cette licence ?')"><button class="btn btn-danger">Révoquer</button></form>`
      : '';
    return `<tr>
      <td><code>${esc(r.key)}</code></td>
      <td>${badge(r.status)}</td>
      <td>${esc(r.plan)}</td>
      <td>${esc(r.customer_email)}<br><span class="muted">${esc(r.customer_name)}</span></td>
      <td>${sites}</td>
      <td>${esc(r.expires) || '<span class="muted">jamais</span>'}</td>
      <td><a class="btn" href="${basePath}/licenses/${r.id}/edit">Éditer</a> ${revoke}</td>
    </tr>`;
  }).join('\n');

  const body = `    <div class="sb-toolbar">
      <h1>Licences</h1>
      <a class="btn btn-primary" href="${basePath}/new">+ Nouvelle licence</a>
    </div>
    <nav class="sb-tabs">${tabs}</nav>
    <table class="sb-table">
      <thead><tr><th>Clé</th><th>Statut</th><th>Plan</th><th>Client</th><th>Sites</th><th>Expire</th><th></th></tr></thead>
      <tbody>
${trs || '<tr><td colspan="7" class="muted">Aucune licence.</td></tr>'}
      </tbody>
    </table>`;
  return layout({ title: 'Licences', body, basePath });
}

function newPage({ basePath = '', error = '' }) {
  const body = `    <form method="post" action="${basePath}/licenses" class="sb-card">
      <h1>Nouvelle licence</h1>
      ${error ? `<p class="sb-error">${esc(error)}</p>` : ''}
      <label>Plan<input name="plan" value="pro"></label>
      <label>Email client<input name="customer_email" type="email"></label>
      <label>Nom client<input name="customer_name"></label>
      <label>Sites autorisés (un par ligne, vide = tous)<textarea name="sites" rows="3"></textarea></label>
      <label>Expiration (AAAA-MM-JJ, vide = jamais)<input name="expires" placeholder="2027-01-01"></label>
      <label>Notes<textarea name="notes" rows="2"></textarea></label>
      <button class="btn btn-primary" type="submit">Créer + générer la clé</button>
      <a class="btn" href="${basePath}/">Annuler</a>
    </form>`;
  return layout({ title: 'Nouvelle licence', body, basePath });
}

function createdPage({ basePath = '', key = '' }) {
  const body = `    <div class="sb-card">
      <h1>Licence créée</h1>
      <p>Clé à transmettre au client :</p>
      <p class="sb-key"><code>${esc(key)}</code></p>
      <a class="btn btn-primary" href="${basePath}/">Retour à la liste</a>
    </div>`;
  return layout({ title: 'Licence créée', body, basePath });
}

function editPage({ basePath = '', row, error = '' }) {
  const sites = row.sites ? JSON.parse(row.sites).join('\n') : '';
  const body = `    <form method="post" action="${basePath}/licenses/${row.id}" class="sb-card">
      <h1>Éditer ${esc(row.key)}</h1>
      ${error ? `<p class="sb-error">${esc(error)}</p>` : ''}
      <label>Expiration (AAAA-MM-JJ, vide = jamais)<input name="expires" value="${esc(row.expires)}"></label>
      <label>Sites autorisés (un par ligne, vide = tous)<textarea name="sites" rows="3">${esc(sites)}</textarea></label>
      <button class="btn btn-primary" type="submit">Enregistrer</button>
      <a class="btn" href="${basePath}/">Annuler</a>
    </form>`;
  return layout({ title: 'Éditer', body, basePath });
}

module.exports = { esc, layout, loginPage, listPage, newPage, createdPage, editPage };
```

- [ ] **Step 4: Create `public/sb-styles.css`** (small on-brand subset of the design handoff tokens)

```css
:root {
  --accent-deep: #059669;
  --ink: #0c1d17;
  --muted: #61756c;
  --line: #e4ece8;
  --bg: #f6f9f7;
  --surface: #fff;
  --danger: #b42318;
}
* { box-sizing: border-box; }
body.sb-dash { margin: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color: var(--ink); background: var(--bg); }
.sb-dash-header { display: flex; align-items: center; gap: 8px; padding: 14px 24px; background: var(--surface); border-bottom: 1px solid var(--line); }
.sb-dash-header .muted { color: var(--muted); }
.sb-dash-header .sb-logout { margin-left: auto; color: var(--muted); font-size: 14px; }
.sb-dash-main { max-width: 1100px; margin: 24px auto; padding: 0 24px; }
.sb-toolbar { display: flex; align-items: center; justify-content: space-between; }
.sb-tabs { display: flex; gap: 12px; margin: 12px 0; }
.sb-tabs a { color: var(--muted); text-decoration: none; text-transform: capitalize; }
.sb-tabs a.active { color: var(--accent-deep); font-weight: 600; }
.sb-table { width: 100%; border-collapse: collapse; background: var(--surface); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; }
.sb-table th, .sb-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--line); vertical-align: top; font-size: 14px; }
.sb-badge { padding: 2px 8px; border-radius: 999px; font-size: 12px; }
.sb-badge-active { background: #d1fae5; color: #047857; }
.sb-badge-revoked { background: #fee4e2; color: var(--danger); }
.muted { color: var(--muted); }
.sb-card { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; padding: 24px; max-width: 560px; }
.sb-card label { display: block; margin: 12px 0; font-size: 14px; }
.sb-card input, .sb-card textarea { display: block; width: 100%; margin-top: 4px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; font: inherit; }
.sb-login { max-width: 360px; margin: 64px auto; }
.sb-error { color: var(--danger); }
.sb-key code { font-size: 20px; background: #ecfdf5; padding: 8px 12px; border-radius: 8px; }
.btn { display: inline-block; padding: 8px 14px; border-radius: 999px; border: 1px solid var(--line); background: var(--surface); color: var(--ink); text-decoration: none; font: inherit; cursor: pointer; }
.btn-primary { background: var(--accent-deep); color: #fff; border-color: var(--accent-deep); }
.btn-danger { background: var(--surface); color: var(--danger); border-color: var(--danger); }
form { display: inline; }
```

- [ ] **Step 5: Run the views test — expect PASS:** `npx jest test/views.test.js`

- [ ] **Step 6: Commit**

```bash
git add lib/views.js public/sb-styles.css test/views.test.js
git commit -m "feat(dashboard): server-rendered views + on-brand stylesheet"
```

---

### Task 5: routes + `app.js` + `server.js` + `passenger_app.js` + `config.js` (wire it up, supertest)

**Files:** Create `config.js`, `routes/auth.js`, `routes/licenses.js`, `app.js`, `server.js`, `passenger_app.js`, `test/routes.test.js`

- [ ] **Step 1: Create `config.js`**

```js
'use strict';

function required(name) {
  const v = process.env[name];
  if (v === undefined || v === null || String(v).trim() === '') {
    throw new Error(`Missing required environment variable: ${name}`);
  }
  return String(v);
}
function optional(name, fallback) {
  const v = process.env[name];
  return v === undefined || v === null || String(v).trim() === '' ? fallback : String(v);
}
function stripTrailingSlash(p) {
  return p.length > 1 && p.endsWith('/') ? p.slice(0, -1) : p;
}

const config = Object.freeze({
  licensesDb: required('LICENSES_DB'),
  dashUser: required('DASH_USER'),
  dashPassHash: required('DASH_PASS_HASH'),
  sessionSecret: required('DASH_SESSION_SECRET'),
  basePath: stripTrailingSlash(optional('BASE_PATH', '/')),
  port: parseInt(optional('PORT', '8788'), 10),
  trustProxy: parseInt(optional('TRUST_PROXY', '1'), 10),
});

module.exports = config;
```

- [ ] **Step 2: Create `routes/auth.js`**

```js
'use strict';

const express = require('express');
const cookie = require('cookie');
const views = require('../lib/views');
const {
  SESSION_COOKIE, SESSION_TTL_MS, verifyPassword, signSession,
} = require('../lib/auth');

function authRouter(cfg) {
  const router = express.Router();
  const bp = cfg.basePath === '/' ? '' : cfg.basePath;

  router.get('/login', (req, res) => {
    res.set('content-type', 'text/html; charset=utf-8');
    res.send(views.loginPage({ basePath: bp, error: '' }));
  });

  router.post('/login', (req, res) => {
    const { username, password } = req.body || {};
    const ok = username === cfg.dashUser && verifyPassword(password || '', cfg.dashPassHash);
    if (!ok) {
      res.status(401).set('content-type', 'text/html; charset=utf-8');
      return res.send(views.loginPage({ basePath: bp, error: 'Identifiants invalides.' }));
    }
    const token = signSession(cfg.dashUser, cfg.sessionSecret, SESSION_TTL_MS, Date.now());
    res.setHeader('Set-Cookie', cookie.serialize(SESSION_COOKIE, token, {
      httpOnly: true, secure: true, sameSite: 'strict', path: cfg.basePath, maxAge: SESSION_TTL_MS / 1000,
    }));
    return res.redirect(302, bp + '/');
  });

  // Cleared cookie + redirect to login. GET link target from the header logout anchor.
  const doLogout = (req, res) => {
    res.setHeader('Set-Cookie', cookie.serialize(SESSION_COOKIE, '', {
      httpOnly: true, secure: true, sameSite: 'strict', path: cfg.basePath, maxAge: 0,
    }));
    return res.redirect(302, bp + '/login');
  };
  router.post('/logout', doLogout);
  router.get('/logout-link', doLogout);

  return router;
}

module.exports = { authRouter };
```

> Note: the header "déconnexion" link is a GET (`/logout-link`) for simplicity; `POST /logout` is also provided. Both clear the cookie.

- [ ] **Step 3: Create `routes/licenses.js`**

```js
'use strict';

const express = require('express');
const views = require('../lib/views');
const repo = require('../lib/licenses-repo');
const { createUniqueKey } = require('../lib/keygen');

function parseSites(raw) {
  return String(raw || '')
    .split(/\r?\n/)
    .map((s) => s.trim())
    .filter((s) => s.length > 0);
}

function html(res, body) {
  res.set('content-type', 'text/html; charset=utf-8').send(body);
}

function licensesRouter(cfg, db) {
  const router = express.Router();
  const bp = cfg.basePath === '/' ? '' : cfg.basePath;

  router.get('/', (req, res) => {
    const filter = ['active', 'revoked', 'all'].includes(req.query.status) ? req.query.status : 'all';
    const rows = repo.listLicenses(db, { status: filter });
    html(res, views.listPage({ basePath: bp, rows, filter }));
  });

  router.get('/new', (req, res) => {
    html(res, views.newPage({ basePath: bp, error: '' }));
  });

  router.post('/licenses', (req, res) => {
    const b = req.body || {};
    let key;
    try {
      key = createUniqueKey(db);
    } catch (e) {
      res.status(500);
      return html(res, views.newPage({ basePath: bp, error: 'Impossible de générer une clé, réessayez.' }));
    }
    repo.createLicense(db, {
      key,
      plan: (b.plan || '').trim() || null,
      customer_email: (b.customer_email || '').trim() || null,
      customer_name: (b.customer_name || '').trim() || null,
      notes: (b.notes || '').trim() || null,
      sites: parseSites(b.sites),
      expires: (b.expires || '').trim() || null,
    });
    html(res, views.createdPage({ basePath: bp, key }));
  });

  router.post('/licenses/:id/revoke', (req, res) => {
    repo.revokeLicense(db, Number(req.params.id));
    res.redirect(302, bp + '/');
  });

  router.get('/licenses/:id/edit', (req, res) => {
    const row = repo.getLicense(db, Number(req.params.id));
    if (!row) {
      return res.redirect(302, bp + '/');
    }
    html(res, views.editPage({ basePath: bp, row, error: '' }));
  });

  router.post('/licenses/:id', (req, res) => {
    const b = req.body || {};
    repo.updateLicense(db, Number(req.params.id), {
      expires: (b.expires || '').trim() || null,
      sites: parseSites(b.sites),
    });
    res.redirect(302, bp + '/');
  });

  return router;
}

module.exports = { licensesRouter };
```

- [ ] **Step 4: Create `app.js`**

```js
'use strict';

const express = require('express');
const helmet = require('helmet');
const path = require('path');
const config = require('./config');
const { openDb } = require('./lib/db');
const { requireAuth } = require('./lib/auth');
const { authRouter } = require('./routes/auth');
const { licensesRouter } = require('./routes/licenses');

function buildApp(cfg = config, opts = {}) {
  const app = express();
  app.set('trust proxy', cfg.trustProxy);
  // Keep helmet's hardening headers, but disable CSP: this is an internal,
  // auth-gated admin tool and the views use a small inline `onsubmit` confirm.
  app.use(helmet({ contentSecurityPolicy: false }));
  app.use(express.urlencoded({ extended: false }));

  const db = opts.db || openDb(cfg.licensesDb);
  app.locals.db = db;

  const bp = cfg.basePath;
  const publicMount = `${bp === '/' ? '' : bp}/public`;
  // Static assets (incl. the stylesheet) — public, mounted BEFORE the auth gate
  // so the login page can load its CSS. The views link `${basePath}/public/...`.
  app.use(publicMount, express.static(path.join(__dirname, 'public'), { index: false }));

  const root = express.Router();
  root.use(authRouter(cfg));            // /login, /logout, /logout-link (public)
  root.use(requireAuth(cfg));           // gate everything below
  root.use(licensesRouter(cfg, db));    // / , /new, /licenses, ...
  app.use(bp, root);

  return { app, db };
}

module.exports = buildApp;
module.exports.buildApp = buildApp;
```

- [ ] **Step 5: Create `server.js`**

```js
'use strict';

const config = require('./config');
const buildApp = require('./app');

function start() {
  const { app } = buildApp(config);
  app.listen(config.port, () => {
    // eslint-disable-next-line no-console
    console.log(`slashbooking-dashboard listening on :${config.port} (base ${config.basePath})`);
  });
}

if (require.main === module) {
  start();
}

module.exports = { start, buildApp };
```

- [ ] **Step 6: Create `passenger_app.js`**

```js
'use strict';

// Phusion Passenger entry point for Plesk. Passenger does not guarantee
// `require.main === module`, so start the server explicitly here.
require('./server').start();
```

- [ ] **Step 7: Write `test/routes.test.js` (supertest: auth gate + CRUD)**

```js
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const request = require('supertest');
const { openDb } = require('../lib/db');
const { hashPassword } = require('../lib/auth');

function buildCtx() {
  const dbFile = path.join(os.tmpdir(), `routes-${Date.now()}-${Math.random()}.sqlite`);
  const db = openDb(dbFile);
  const cfg = {
    licensesDb: dbFile,
    dashUser: 'admin',
    dashPassHash: hashPassword('pw'),
    sessionSecret: 'test-secret',
    basePath: '/',
    port: 0,
    trustProxy: 0,
  };
  jest.resetModules();
  const buildApp = require('../app');
  const { app } = buildApp(cfg, { db });
  return { app, db, cleanup: () => { db.close(); fs.unlinkSync(dbFile); } };
}

async function login(agent) {
  return agent.post('/login').type('form').send({ username: 'admin', password: 'pw' });
}

describe('dashboard routes', () => {
  let ctx;
  afterEach(() => ctx && ctx.cleanup());

  test('unauthenticated access to / redirects to /login', async () => {
    ctx = buildCtx();
    const res = await request(ctx.app).get('/');
    expect(res.status).toBe(302);
    expect(res.headers.location).toBe('/login');
  });

  test('bad login is rejected (401), good login sets a cookie', async () => {
    ctx = buildCtx();
    const bad = await request(ctx.app).post('/login').type('form').send({ username: 'admin', password: 'wrong' });
    expect(bad.status).toBe(401);

    const ok = await request(ctx.app).post('/login').type('form').send({ username: 'admin', password: 'pw' });
    expect(ok.status).toBe(302);
    expect(String(ok.headers['set-cookie'])).toContain('sb_dash_session=');
  });

  test('create -> list shows the key -> revoke flips status', async () => {
    ctx = buildCtx();
    const agent = request.agent(ctx.app);
    await login(agent);

    const created = await agent.post('/licenses').type('form').send({
      plan: 'pro', customer_email: 'c@x.com', sites: 'https://c.com', expires: '2027-01-01',
    });
    expect(created.status).toBe(200);
    const m = created.text.match(/SB-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}/);
    expect(m).not.toBeNull();

    const list = await agent.get('/');
    expect(list.text).toContain(m[0]);

    const row = ctx.db.prepare('SELECT id FROM licenses WHERE key = ?').get(m[0]);
    const rev = await agent.post(`/licenses/${row.id}/revoke`).type('form').send({});
    expect(rev.status).toBe(302);
    expect(ctx.db.prepare('SELECT status FROM licenses WHERE id = ?').get(row.id).status).toBe('revoked');
  });
});
```

- [ ] **Step 8: Run the routes test — expect PASS:** `npx jest test/routes.test.js`

- [ ] **Step 9: Run the whole suite:** `npm test`
Expected: all suites green (licenses-repo, keygen, auth, views, routes).

- [ ] **Step 10: Commit**

```bash
git add config.js routes app.js server.js passenger_app.js test/routes.test.js
git commit -m "feat(dashboard): auth + license CRUD routes, app wiring, supertest"
```

---

### Task 6: `.env.example` + `DEPLOY.md` + final verification

**Files:** Create `.env.example`, `DEPLOY.md`

- [ ] **Step 1: Create `.env.example`**

```
# Shared SQLite licenses store — MUST be the same absolute path as the broker's LICENSES_DB.
LICENSES_DB=/var/www/vhosts/slashbox.fr/private/licenses.sqlite

# Admin login. Generate the hash with: node scripts/hash-password.js '<password>'
DASH_USER=admin
DASH_PASS_HASH=

# Secret for signing the session cookie (32+ random bytes, hex/base64).
DASH_SESSION_SECRET=change-me-to-a-long-random-secret

# Mount path. On a dedicated subdomain (dashboard.slashbooking.fr) keep '/'.
BASE_PATH=/

# Reverse-proxy hops in front (Plesk nginx/Passenger = 1).
TRUST_PROXY=1

# Standalone port (ignored under Passenger).
PORT=8788
```

- [ ] **Step 2: Create `DEPLOY.md`**

```
# Deploying slashbooking-dashboard on dashboard.slashbooking.fr (Plesk)

Admin-only license dashboard. Shares the SQLite license store with the broker.

## Prerequisites
- `slashbooking.fr` added to the SAME Plesk subscription as the broker (so both
  apps run as the same system user and share the SQLite file).
- The broker already migrated to SQLite (Plan A) and `LICENSES_DB` points at the
  shared file, e.g. `/var/www/vhosts/slashbox.fr/private/licenses.sqlite`.

## Steps
1. Create subdomain `dashboard.slashbooking.fr` + Let's Encrypt cert.
2. `git clone` this repo into its document root; `npm ci --omit=dev`.
3. Generate the admin password hash: `node scripts/hash-password.js '<password>'`.
4. Plesk -> subdomain -> Node.js:
   - Application Root: the dashboard directory.
   - Application Startup File: `passenger_app.js`.
   - Application Mode: production.
   - Custom environment variables: `LICENSES_DB` (same path as the broker),
     `DASH_USER`, `DASH_PASS_HASH` (from step 3), `DASH_SESSION_SECRET`,
     `BASE_PATH=/`, `TRUST_PROXY=1`.
5. Enable Node.js / Restart App.
6. Visit `https://dashboard.slashbooking.fr/` -> redirected to `/login`. Log in,
   create a license, confirm it appears; verify the broker validates that key:
   `curl -s -XPOST https://broker.slashbox.fr/license/validate -H 'content-type: application/json' -d '{"license":"SB-...","site":"https://client.com"}'` -> `{"valid":true,...}`.

## Notes
- The session cookie is HttpOnly + Secure + SameSite=Strict; serve over HTTPS only.
- The SQLite file must stay outside any web root.
```

- [ ] **Step 3: Final verification**

Run: `npm test`
Expected: all suites green (5 suites).

Run: `node --check app.js server.js passenger_app.js config.js routes/auth.js routes/licenses.js lib/db.js lib/licenses-repo.js lib/keygen.js lib/auth.js lib/views.js scripts/hash-password.js`
Expected: no syntax errors.

- [ ] **Step 4: Commit**

```bash
git add .env.example DEPLOY.md
git commit -m "docs(dashboard): .env.example + Plesk deployment guide"
```

---

## Self-Review

**Spec coverage:**
- Separate Node/Express app, server-rendered, on-brand → Tasks 4/5 + `public/sb-styles.css`. ✓
- SQLite shared store (`better-sqlite3`, `LICENSES_DB`, canonical schema) → Tasks 1 (`lib/db.js`). ✓
- Auth: single admin, env user + scrypt-hashed pass, signed session cookie, login/logout, all routes gated → Task 3 (`lib/auth.js`) + Task 5 (`routes/auth.js`, `requireAuth`). ✓
- Pages: list (filter by status) / new (generate key) / revoke / edit (expiry+sites) → Task 5 `routes/licenses.js` + Task 4 views. ✓
- Key format `SB-XXXX-XXXX-XXXX` CSPRNG unique → Task 2. ✓
- Security: HttpOnly+Secure+SameSite=Strict cookie, helmet, SQLite outside web root, CSPRNG keys → Tasks 3/5/6. ✓
- Migration of existing licenses → done in Plan A (broker repo); not repeated here. ✓
- Out of scope (Stripe, marketing site, multi-admin) → not built. ✓

**Placeholder scan:** No TBD/TODO. All files have complete code. `DASH_PASS_HASH=` is intentionally blank in `.env.example` (filled per-deploy via the hash script).

**Type/identifier consistency:** `openDb(file)` consistent across tasks. Repo fns (`createLicense/listLicenses/getLicense/revokeLicense/updateLicense`) match between `lib/licenses-repo.js` (Task 1), its test, and `routes/licenses.js` (Task 5). Auth exports (`SESSION_COOKIE`, `SESSION_TTL_MS`, `hashPassword`, `verifyPassword`, `signSession`, `verifySession`, `requireAuth`) defined in Task 3 and consumed in Task 5. `views.*` function names/props match between `lib/views.js` (Task 4) and `routes/*` (Task 5). `buildApp(cfg, opts)` injects `opts.db` in tests (Task 5 routes test) the same way the broker does. `config` fields (`licensesDb`, `dashUser`, `dashPassHash`, `sessionSecret`, `basePath`, `trustProxy`, `port`) consistent between `config.js` and consumers.

**Note for execution:** Greenfield repo created in Task 1 (`git init` in `../slashbooking-dashboard`). Deploy together with the Plan-A broker change in one VPS session (both need the same `LICENSES_DB` path). The CSS is linked at `${basePath}/public/sb-styles.css` and `app.js` mounts `express.static` at exactly that path BEFORE the auth gate, so the login page loads styled and the stylesheet is never redirected. Helmet CSP is disabled (internal auth-gated tool) so the inline revoke-confirm works. Revoke is not deletion (status flip) but there is no un-revoke UI in v1 — the confirm dialog guards against misclicks; an un-revoke action can be a v1.1 addition.
```
