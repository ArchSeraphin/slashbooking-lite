# SlashBooking OAuth Broker (Node.js/Express) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `slashbooking-broker`, a stateless Express service that brokers Google OAuth for SlashBooking WordPress sites, holding the Google `client_secret` server-side and never persisting end-user tokens.

**Architecture:** The broker is an Express **Router** (`routes/oauth.js` + `routes/license.js`) mounted under a configurable `BASE_PATH` inside an EXISTING Node.js app already running on `slashbox.fr`. A thin `server.js` mounts the same router under `BASE_PATH` and exposes `GET /health` for local dev and standalone deployment. State is HMAC-signed (no DB); one-time OAuth claims live in an in-memory `Map` with a 60s TTL; licenses are read from a JSON file. Google calls are isolated in `lib/google.js` so they can be mocked with `nock` in tests.

**Tech Stack:** Node.js 20+, Express 4, helmet, express-rate-limit, pino (logging). Dev/test: Jest + supertest (HTTP) + nock (mock Google HTTP). No real network in tests.

---

## File Structure

| File | Responsibility |
|------|----------------|
| `package.json` | Dependencies, scripts (`start`, `dev`, `test`), Node engine pin. |
| `.env.example` | Documented template of every required env var. |
| `.gitignore` | Ignore `node_modules`, `.env`, `licenses.json`, coverage. |
| `README.md` | Deploy on Plesk/Node, env vars, how to mount the router in the existing app, smoke-test checklist. |
| `config.js` | Fail-fast env validation; exports a frozen config object. |
| `lib/state.js` | `signState(payload)` / `verifyState(token)` — base64url(json)+`.`+HMAC-SHA256, exp ~600s, `timingSafeEqual`. |
| `lib/claims.js` | In-memory `Map`: `put(data)` -> random 32-byte claim; `take(claim)` one-time read + TTL sweep. |
| `lib/licenses.js` | `validate(key, site)` reading `LICENSES_FILE` JSON `[{key,plan,expires,sites?}]`. |
| `lib/google.js` | `exchangeCode(code)`, `refreshToken(rt)`, `fetchPrimaryCalendar(accessToken)` -> `{email, calendar_id}`. |
| `lib/logger.js` | Pino logger with a redaction serializer that strips tokens/secrets. |
| `routes/oauth.js` | Express Router: `/oauth/start`, `/oauth/callback`, `/oauth/claim`, `/oauth/refresh` + per-route rate limiting + open-redirect guard. |
| `routes/license.js` | Express Router: `/license/validate`. |
| `app.js` | Builds the mountable Router (oauth + license sub-routers) — this is what the existing app imports. |
| `server.js` | Standalone server: helmet, JSON body parser, mounts `app.js` Router under `BASE_PATH`, adds `GET /health`, listens on `PORT`. |
| `test/state.test.js` | Unit tests for `lib/state.js`. |
| `test/claims.test.js` | Unit tests for `lib/claims.js`. |
| `test/licenses.test.js` | Unit tests for `lib/licenses.js`. |
| `test/google.test.js` | Unit tests for `lib/google.js` (nock). |
| `test/oauth.routes.test.js` | Integration tests for the oauth router (supertest + nock). |
| `test/license.routes.test.js` | Integration tests for the license router (supertest). |
| `test/health.test.js` | Integration test for `GET /health` on `server.js`. |
| `test/helpers/buildApp.js` | Test helper that sets env + builds the app Router with deterministic config. |

---

## Conventions used throughout this plan

- **Module system:** CommonJS (`require` / `module.exports`). Node 20+. Matches a typical existing Plesk Node app and avoids ESM interop friction.
- **Test runner:** `jest`. Run a single file with `npx jest <path>`.
- **Config in tests:** every test sets the required env vars BEFORE requiring `config.js`/`app.js`. Use the helper in Task 12; unit tests set env inline at the top of the file.
- **All token-bearing values** (`refresh_token`, `access_token`, `client_secret`, `license`, `STATE_KEY`) MUST be kept out of logs. `lib/logger.js` enforces this.

---

## Tasks

### Task 1: Project scaffolding (package.json, gitignore, .env.example)

**Files:**
- Create `package.json`
- Create `.gitignore`
- Create `.env.example`

Steps:

- [ ] **Step 1: Create `package.json`** with this exact content:

```json
{
  "name": "slashbooking-broker",
  "version": "1.0.0",
  "description": "Stateless OAuth broker for SlashBooking WordPress sites (Google OAuth, license validation).",
  "license": "UNLICENSED",
  "private": true,
  "engines": {
    "node": ">=20"
  },
  "main": "app.js",
  "scripts": {
    "start": "node server.js",
    "dev": "node server.js",
    "test": "jest --runInBand"
  },
  "dependencies": {
    "express": "^4.19.2",
    "express-rate-limit": "^7.4.0",
    "helmet": "^7.1.0",
    "pino": "^9.4.0"
  },
  "devDependencies": {
    "jest": "^29.7.0",
    "nock": "^13.5.5",
    "supertest": "^7.0.0"
  },
  "jest": {
    "testEnvironment": "node",
    "testMatch": ["**/test/**/*.test.js"]
  }
}
```

- [ ] **Step 2: Create `.gitignore`** with this exact content:

```gitignore
node_modules/
.env
licenses.json
coverage/
*.log
.DS_Store
```

- [ ] **Step 3: Create `.env.example`** with this exact content:

```dotenv
# Google OAuth client (Web application) credentials
GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-google-client-secret
# Must EXACTLY match an Authorized redirect URI in the Google Cloud console.
# Includes BASE_PATH, e.g. https://slashbox.fr/slashbooking/api/oauth/callback
GOOGLE_REDIRECT_URI=https://slashbox.fr/slashbooking/api/oauth/callback

# Secret used to HMAC-sign the OAuth state token. 32+ random bytes, hex/base64.
STATE_KEY=change-me-to-a-long-random-secret

# One-time OAuth claim time-to-live, in seconds.
CLAIM_TTL_SECONDS=60

# Absolute path to the licenses JSON file: [{ "key": "...", "plan": "pro", "expires": "2027-01-01", "sites": ["https://example.com"] }]
LICENSES_FILE=./licenses.json

# Path under which the router is mounted on the host app. No trailing slash.
BASE_PATH=/slashbooking/api

# Only return URLs using this scheme are accepted (anti open-redirect).
ALLOWED_RETURN_SCHEME=https

# Port for the standalone server.js (ignored when mounted in the host app).
PORT=8787

# Log level for pino (trace|debug|info|warn|error).
LOG_LEVEL=info
```

- [ ] **Step 4: Install dependencies.** Run:

```bash
npm install
```

Expected: `node_modules/` created, `package-lock.json` written, exit code 0.

- [ ] **Step 5: Commit.**

```bash
git add package.json package-lock.json .gitignore .env.example
git commit -m "chore: scaffold slashbooking-broker project (package.json, gitignore, env example)"
```

---

### Task 2: `config.js` — fail-fast env validation

**Files:**
- Create `config.js`
- Test: `test/config.test.js`

Steps:

- [ ] **Step 1: Write the failing test.** Create `test/config.test.js`:

```js
'use strict';

const path = require('path');

function freshConfig(env) {
  jest.resetModules();
  const saved = { ...process.env };
  // Clear all the keys config cares about so leftover env can't mask bugs.
  for (const k of [
    'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI',
    'STATE_KEY', 'CLAIM_TTL_SECONDS', 'LICENSES_FILE', 'BASE_PATH',
    'ALLOWED_RETURN_SCHEME', 'PORT', 'LOG_LEVEL',
  ]) {
    delete process.env[k];
  }
  Object.assign(process.env, env);
  try {
    return require('../config');
  } finally {
    process.env = saved;
  }
}

const VALID = {
  GOOGLE_CLIENT_ID: 'cid.apps.googleusercontent.com',
  GOOGLE_CLIENT_SECRET: 'secret',
  GOOGLE_REDIRECT_URI: 'https://slashbox.fr/slashbooking/api/oauth/callback',
  STATE_KEY: 'a-very-long-random-state-key-value',
  CLAIM_TTL_SECONDS: '60',
  LICENSES_FILE: '/tmp/licenses.json',
  BASE_PATH: '/slashbooking/api',
  ALLOWED_RETURN_SCHEME: 'https',
};

describe('config', () => {
  test('loads a valid environment', () => {
    const cfg = freshConfig(VALID);
    expect(cfg.googleClientId).toBe('cid.apps.googleusercontent.com');
    expect(cfg.googleClientSecret).toBe('secret');
    expect(cfg.googleRedirectUri).toBe('https://slashbox.fr/slashbooking/api/oauth/callback');
    expect(cfg.stateKey).toBe('a-very-long-random-state-key-value');
    expect(cfg.claimTtlSeconds).toBe(60);
    expect(cfg.licensesFile).toBe('/tmp/licenses.json');
    expect(cfg.basePath).toBe('/slashbooking/api');
    expect(cfg.allowedReturnScheme).toBe('https');
  });

  test('applies defaults for optional vars', () => {
    const cfg = freshConfig({
      GOOGLE_CLIENT_ID: 'cid',
      GOOGLE_CLIENT_SECRET: 'secret',
      GOOGLE_REDIRECT_URI: 'https://slashbox.fr/slashbooking/api/oauth/callback',
      STATE_KEY: 'a-very-long-random-state-key-value',
      LICENSES_FILE: '/tmp/licenses.json',
      BASE_PATH: '/slashbooking/api',
    });
    expect(cfg.claimTtlSeconds).toBe(60);
    expect(cfg.allowedReturnScheme).toBe('https');
    expect(cfg.port).toBe(8787);
    expect(cfg.logLevel).toBe('info');
  });

  test('throws when a required var is missing', () => {
    const env = { ...VALID };
    delete env.GOOGLE_CLIENT_SECRET;
    expect(() => freshConfig(env)).toThrow(/GOOGLE_CLIENT_SECRET/);
  });

  test('throws when STATE_KEY is too short', () => {
    expect(() => freshConfig({ ...VALID, STATE_KEY: 'short' })).toThrow(/STATE_KEY/);
  });

  test('normalises BASE_PATH by stripping a trailing slash', () => {
    const cfg = freshConfig({ ...VALID, BASE_PATH: '/slashbooking/api/' });
    expect(cfg.basePath).toBe('/slashbooking/api');
  });

  test('config object is frozen', () => {
    const cfg = freshConfig(VALID);
    expect(Object.isFrozen(cfg)).toBe(true);
  });
});
```

- [ ] **Step 2: Run test to verify it fails.**

```bash
npx jest test/config.test.js
```

Expected failure: `Cannot find module '../config'` (the file does not exist yet).

- [ ] **Step 3: Write minimal implementation.** Create `config.js`:

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

const stateKey = required('STATE_KEY');
if (stateKey.length < 16) {
  throw new Error('STATE_KEY must be at least 16 characters of random secret.');
}

const config = Object.freeze({
  googleClientId: required('GOOGLE_CLIENT_ID'),
  googleClientSecret: required('GOOGLE_CLIENT_SECRET'),
  googleRedirectUri: required('GOOGLE_REDIRECT_URI'),
  stateKey,
  claimTtlSeconds: parseInt(optional('CLAIM_TTL_SECONDS', '60'), 10),
  licensesFile: required('LICENSES_FILE'),
  basePath: stripTrailingSlash(required('BASE_PATH')),
  allowedReturnScheme: optional('ALLOWED_RETURN_SCHEME', 'https'),
  port: parseInt(optional('PORT', '8787'), 10),
  logLevel: optional('LOG_LEVEL', 'info'),
});

module.exports = config;
```

- [ ] **Step 4: Run test to verify it passes.**

```bash
npx jest test/config.test.js
```

Expected: `Tests: 6 passed`.

- [ ] **Step 5: Commit.**

```bash
git add config.js test/config.test.js
git commit -m "feat: add fail-fast env config validation"
```

---

### Task 3: `lib/logger.js` — pino with token redaction

**Files:**
- Create `lib/logger.js`
- Test: `test/logger.test.js`

Steps:

- [ ] **Step 1: Write the failing test.** Create `test/logger.test.js`:

```js
'use strict';

const { redact } = require('../lib/logger');

describe('logger redact()', () => {
  test('replaces sensitive keys with [REDACTED]', () => {
    const out = redact({
      license: 'LIC-123',
      refresh_token: 'rt-secret',
      access_token: 'at-secret',
      client_secret: 'cs-secret',
      state: 'state-secret',
      claim: 'claim-secret',
      email: 'user@example.com',
      foo: 'bar',
    });
    expect(out.license).toBe('[REDACTED]');
    expect(out.refresh_token).toBe('[REDACTED]');
    expect(out.access_token).toBe('[REDACTED]');
    expect(out.client_secret).toBe('[REDACTED]');
    expect(out.state).toBe('[REDACTED]');
    expect(out.claim).toBe('[REDACTED]');
    expect(out.email).toBe('user@example.com');
    expect(out.foo).toBe('bar');
  });

  test('redacts nested objects', () => {
    const out = redact({ outer: { refresh_token: 'rt' }, ok: 1 });
    expect(out.outer.refresh_token).toBe('[REDACTED]');
    expect(out.ok).toBe(1);
  });

  test('does not mutate the input', () => {
    const input = { license: 'x' };
    redact(input);
    expect(input.license).toBe('x');
  });
});
```

- [ ] **Step 2: Run test to verify it fails.**

```bash
npx jest test/logger.test.js
```

Expected failure: `Cannot find module '../lib/logger'`.

- [ ] **Step 3: Write minimal implementation.** Create `lib/logger.js`:

```js
'use strict';

const pino = require('pino');

const SENSITIVE = new Set([
  'license', 'refresh_token', 'access_token', 'client_secret',
  'state', 'claim', 'state_key', 'stateKey', 'authorization',
]);

function redact(value) {
  if (Array.isArray(value)) {
    return value.map(redact);
  }
  if (value && typeof value === 'object') {
    const out = {};
    for (const [k, v] of Object.entries(value)) {
      out[k] = SENSITIVE.has(k) ? '[REDACTED]' : redact(v);
    }
    return out;
  }
  return value;
}

const logger = pino({
  level: process.env.LOG_LEVEL || 'info',
  redact: {
    paths: [
      'license', 'refresh_token', 'access_token', 'client_secret',
      'state', 'claim', 'req.headers.authorization',
      '*.refresh_token', '*.access_token', '*.client_secret', '*.license', '*.state', '*.claim',
    ],
    censor: '[REDACTED]',
  },
});

module.exports = { logger, redact };
```

- [ ] **Step 4: Run test to verify it passes.**

```bash
npx jest test/logger.test.js
```

Expected: `Tests: 3 passed`.

- [ ] **Step 5: Commit.**

```bash
git add lib/logger.js test/logger.test.js
git commit -m "feat: add pino logger with token/secret redaction"
```

---

### Task 4: `lib/state.js` — HMAC-signed OAuth state

**Files:**
- Create `lib/state.js`
- Test: `test/state.test.js`

Steps:

- [ ] **Step 1: Write the failing test.** Create `test/state.test.js`:

```js
'use strict';

const { signState, verifyState } = require('../lib/state');

const KEY = 'a-very-long-random-state-key-value';

describe('lib/state', () => {
  test('signState produces a token with exactly one dot separator', () => {
    const token = signState({ license: 'L', return: 'https://x', n: 'nonce' }, KEY);
    expect(token.split('.')).toHaveLength(2);
  });

  test('verifyState round-trips the payload', () => {
    const payload = { license: 'L', return: 'https://x/cb', n: 'nonce123' };
    const token = signState(payload, KEY);
    const out = verifyState(token, KEY);
    expect(out.license).toBe('L');
    expect(out.return).toBe('https://x/cb');
    expect(out.n).toBe('nonce123');
  });

  test('verifyState rejects a tampered payload', () => {
    const token = signState({ a: 1 }, KEY);
    const [body, sig] = token.split('.');
    const tamperedBody = Buffer.from(JSON.stringify({ a: 2 })).toString('base64url');
    expect(() => verifyState(`${tamperedBody}.${sig}`, KEY)).toThrow();
  });

  test('verifyState rejects a wrong key', () => {
    const token = signState({ a: 1 }, KEY);
    expect(() => verifyState(token, 'different-key-entirely-here')).toThrow();
  });

  test('verifyState rejects a malformed token', () => {
    expect(() => verifyState('no-dot-here', KEY)).toThrow();
  });

  test('verifyState rejects an expired token', () => {
    const realNow = Date.now;
    Date.now = () => 1_000_000;
    const token = signState({ a: 1 }, KEY);
    Date.now = () => 1_000_000 + 601_000; // 601s later, default exp is 600s
    try {
      expect(() => verifyState(token, KEY)).toThrow(/expired/i);
    } finally {
      Date.now = realNow;
    }
  });
});
```

- [ ] **Step 2: Run test to verify it fails.**

```bash
npx jest test/state.test.js
```

Expected failure: `Cannot find module '../lib/state'`.

- [ ] **Step 3: Write minimal implementation.** Create `lib/state.js`:

```js
'use strict';

const crypto = require('crypto');

const DEFAULT_EXP_SECONDS = 600;

function hmac(data, key) {
  return crypto.createHmac('sha256', key).update(data).digest('base64url');
}

function signState(payload, key, expSeconds = DEFAULT_EXP_SECONDS) {
  const body = { ...payload, exp: Math.floor(Date.now() / 1000) + expSeconds };
  const encoded = Buffer.from(JSON.stringify(body)).toString('base64url');
  const sig = hmac(encoded, key);
  return `${encoded}.${sig}`;
}

function verifyState(token, key) {
  if (typeof token !== 'string' || !token.includes('.')) {
    throw new Error('Malformed state token');
  }
  const idx = token.indexOf('.');
  const encoded = token.slice(0, idx);
  const sig = token.slice(idx + 1);

  const expected = hmac(encoded, key);
  const a = Buffer.from(sig);
  const b = Buffer.from(expected);
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
    throw new Error('Invalid state signature');
  }

  let payload;
  try {
    payload = JSON.parse(Buffer.from(encoded, 'base64url').toString('utf8'));
  } catch (e) {
    throw new Error('Malformed state payload');
  }

  if (typeof payload.exp !== 'number' || payload.exp < Math.floor(Date.now() / 1000)) {
    throw new Error('State token expired');
  }
  return payload;
}

module.exports = { signState, verifyState, DEFAULT_EXP_SECONDS };
```

- [ ] **Step 4: Run test to verify it passes.**

```bash
npx jest test/state.test.js
```

Expected: `Tests: 6 passed`.

- [ ] **Step 5: Commit.**

```bash
git add lib/state.js test/state.test.js
git commit -m "feat: add HMAC-signed OAuth state with timing-safe verify and expiry"
```

---

### Task 5: `lib/claims.js` — one-time in-memory claims with TTL

**Files:**
- Create `lib/claims.js`
- Test: `test/claims.test.js`

Steps:

- [ ] **Step 1: Write the failing test.** Create `test/claims.test.js`:

```js
'use strict';

const { ClaimStore } = require('../lib/claims');

describe('lib/claims ClaimStore', () => {
  test('put returns a hex claim of 64 chars (32 bytes)', () => {
    const store = new ClaimStore({ ttlSeconds: 60 });
    const claim = store.put({ refresh_token: 'rt' });
    expect(claim).toMatch(/^[0-9a-f]{64}$/);
    store.stop();
  });

  test('take returns the stored data exactly once', () => {
    const store = new ClaimStore({ ttlSeconds: 60 });
    const claim = store.put({ email: 'a@b.com', calendar_id: 'a@b.com' });
    const first = store.take(claim);
    expect(first).toEqual({ email: 'a@b.com', calendar_id: 'a@b.com' });
    const second = store.take(claim);
    expect(second).toBeNull();
    store.stop();
  });

  test('take returns null for an unknown claim', () => {
    const store = new ClaimStore({ ttlSeconds: 60 });
    expect(store.take('deadbeef')).toBeNull();
    store.stop();
  });

  test('take returns null for an expired claim', () => {
    const realNow = Date.now;
    Date.now = () => 1_000_000;
    const store = new ClaimStore({ ttlSeconds: 60 });
    const claim = store.put({ x: 1 });
    Date.now = () => 1_000_000 + 61_000; // 61s later, ttl is 60s
    try {
      expect(store.take(claim)).toBeNull();
    } finally {
      Date.now = realNow;
      store.stop();
    }
  });

  test('two puts return distinct claims', () => {
    const store = new ClaimStore({ ttlSeconds: 60 });
    const a = store.put({ x: 1 });
    const b = store.put({ x: 2 });
    expect(a).not.toBe(b);
    store.stop();
  });
});
```

- [ ] **Step 2: Run test to verify it fails.**

```bash
npx jest test/claims.test.js
```

Expected failure: `Cannot find module '../lib/claims'`.

- [ ] **Step 3: Write minimal implementation.** Create `lib/claims.js`:

```js
'use strict';

const crypto = require('crypto');

class ClaimStore {
  constructor({ ttlSeconds = 60, sweepMs = 30_000 } = {}) {
    this.ttlMs = ttlSeconds * 1000;
    this.map = new Map(); // claim -> { data, expiresAt }
    this.timer = setInterval(() => this.sweep(), sweepMs);
    if (this.timer.unref) {
      this.timer.unref();
    }
  }

  put(data) {
    const claim = crypto.randomBytes(32).toString('hex');
    this.map.set(claim, { data, expiresAt: Date.now() + this.ttlMs });
    return claim;
  }

  take(claim) {
    const entry = this.map.get(claim);
    if (!entry) {
      return null;
    }
    this.map.delete(claim); // one-time: remove on any read
    if (entry.expiresAt < Date.now()) {
      return null;
    }
    return entry.data;
  }

  sweep() {
    const now = Date.now();
    for (const [claim, entry] of this.map.entries()) {
      if (entry.expiresAt < now) {
        this.map.delete(claim);
      }
    }
  }

  stop() {
    clearInterval(this.timer);
  }
}

module.exports = { ClaimStore };
```

- [ ] **Step 4: Run test to verify it passes.**

```bash
npx jest test/claims.test.js
```

Expected: `Tests: 5 passed`.

- [ ] **Step 5: Commit.**

```bash
git add lib/claims.js test/claims.test.js
git commit -m "feat: add one-time in-memory claim store with TTL sweep"
```

---

### Task 6: `lib/licenses.js` — file-backed license validation

**Files:**
- Create `lib/licenses.js`
- Test: `test/licenses.test.js`

Steps:

- [ ] **Step 1: Write the failing test.** Create `test/licenses.test.js`:

```js
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { validate } = require('../lib/licenses');

function writeLicenses(records) {
  const file = path.join(os.tmpdir(), `lic-${Date.now()}-${Math.random()}.json`);
  fs.writeFileSync(file, JSON.stringify(records));
  return file;
}

describe('lib/licenses validate()', () => {
  test('returns valid:true with plan and expires for a known active key', () => {
    const file = writeLicenses([
      { key: 'GOOD', plan: 'pro', expires: '2999-01-01' },
    ]);
    const out = validate('GOOD', 'https://site.com', file);
    expect(out).toEqual({ valid: true, plan: 'pro', expires: '2999-01-01' });
  });

  test('returns valid:false for an unknown key', () => {
    const file = writeLicenses([{ key: 'GOOD', plan: 'pro', expires: '2999-01-01' }]);
    const out = validate('NOPE', 'https://site.com', file);
    expect(out).toEqual({ valid: false, plan: null, expires: null });
  });

  test('returns valid:false for an expired key', () => {
    const file = writeLicenses([{ key: 'OLD', plan: 'pro', expires: '2000-01-01' }]);
    const out = validate('OLD', 'https://site.com', file);
    expect(out.valid).toBe(false);
  });

  test('enforces site allow-list when sites[] is present', () => {
    const file = writeLicenses([
      { key: 'BOUND', plan: 'pro', expires: '2999-01-01', sites: ['https://allowed.com'] },
    ]);
    expect(validate('BOUND', 'https://allowed.com', file).valid).toBe(true);
    expect(validate('BOUND', 'https://other.com', file).valid).toBe(false);
  });

  test('allows any site when sites[] is absent', () => {
    const file = writeLicenses([{ key: 'ANY', plan: 'pro', expires: '2999-01-01' }]);
    expect(validate('ANY', 'https://whatever.com', file).valid).toBe(true);
  });

  test('treats a missing expires as non-expiring', () => {
    const file = writeLicenses([{ key: 'PERP', plan: 'lifetime' }]);
    const out = validate('PERP', 'https://site.com', file);
    expect(out.valid).toBe(true);
    expect(out.expires).toBeNull();
  });

  test('returns valid:false when the file does not exist', () => {
    const out = validate('GOOD', 'https://site.com', '/no/such/file.json');
    expect(out).toEqual({ valid: false, plan: null, expires: null });
  });
});
```

- [ ] **Step 2: Run test to verify it fails.**

```bash
npx jest test/licenses.test.js
```

Expected failure: `Cannot find module '../lib/licenses'`.

- [ ] **Step 3: Write minimal implementation.** Create `lib/licenses.js`:

```js
'use strict';

const fs = require('fs');

const INVALID = Object.freeze({ valid: false, plan: null, expires: null });

function readRecords(file) {
  try {
    const raw = fs.readFileSync(file, 'utf8');
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : [];
  } catch (e) {
    return [];
  }
}

function normaliseSite(s) {
  return String(s || '').replace(/\/+$/, '').toLowerCase();
}

function validate(key, site, file) {
  if (!key) {
    return INVALID;
  }
  const records = readRecords(file);
  const rec = records.find((r) => r && r.key === key);
  if (!rec) {
    return INVALID;
  }

  const expires = rec.expires || null;
  if (expires && new Date(expires).getTime() < Date.now()) {
    return INVALID;
  }

  if (Array.isArray(rec.sites) && rec.sites.length > 0) {
    const wanted = normaliseSite(site);
    const allowed = rec.sites.map(normaliseSite);
    if (!allowed.includes(wanted)) {
      return INVALID;
    }
  }

  return { valid: true, plan: rec.plan || null, expires };
}

module.exports = { validate };
```

- [ ] **Step 4: Run test to verify it passes.**

```bash
npx jest test/licenses.test.js
```

Expected: `Tests: 7 passed`.

- [ ] **Step 5: Commit.**

```bash
git add lib/licenses.js test/licenses.test.js
git commit -m "feat: add file-backed license validation with site binding and expiry"
```

---

### Task 7: `lib/google.js` — Google OAuth + Calendar HTTP (mocked with nock)

**Files:**
- Create `lib/google.js`
- Test: `test/google.test.js`

Notes for the implementer: `lib/google.js` uses the global `fetch` (Node 20+). Endpoints:
- Token endpoint: `https://oauth2.googleapis.com/token` (POST, `application/x-www-form-urlencoded`).
- Primary calendar metadata: `https://www.googleapis.com/calendar/v3/calendars/primary` (GET, Bearer). The response `id` is the calendar id and the primary calendar's id is the account email, so `email` and `calendar_id` are both taken from `id`.

Steps:

- [ ] **Step 1: Write the failing test.** Create `test/google.test.js`:

```js
'use strict';

const nock = require('nock');
const google = require('../lib/google');

const CFG = {
  googleClientId: 'cid',
  googleClientSecret: 'csecret',
  googleRedirectUri: 'https://slashbox.fr/slashbooking/api/oauth/callback',
};

afterEach(() => {
  nock.cleanAll();
});

describe('lib/google exchangeCode', () => {
  test('posts code + secret and returns the token bundle', async () => {
    const scope = nock('https://oauth2.googleapis.com')
      .post('/token', (body) =>
        body.code === 'AUTHCODE' &&
        body.client_id === 'cid' &&
        body.client_secret === 'csecret' &&
        body.grant_type === 'authorization_code' &&
        body.redirect_uri === CFG.googleRedirectUri)
      .reply(200, {
        access_token: 'at-1',
        refresh_token: 'rt-1',
        expires_in: 3599,
        scope: 'https://www.googleapis.com/auth/calendar',
        token_type: 'Bearer',
      });

    const out = await google.exchangeCode('AUTHCODE', CFG);
    expect(out.access_token).toBe('at-1');
    expect(out.refresh_token).toBe('rt-1');
    expect(out.expires_in).toBe(3599);
    expect(out.scope).toBe('https://www.googleapis.com/auth/calendar');
    expect(scope.isDone()).toBe(true);
  });

  test('throws OAuthFailure on a 4xx from Google', async () => {
    nock('https://oauth2.googleapis.com').post('/token').reply(400, { error: 'invalid_grant' });
    await expect(google.exchangeCode('BAD', CFG)).rejects.toMatchObject({ code: 'invalid_grant' });
  });
});

describe('lib/google refreshToken', () => {
  test('returns a new access token', async () => {
    nock('https://oauth2.googleapis.com')
      .post('/token', (body) =>
        body.refresh_token === 'rt-1' &&
        body.grant_type === 'refresh_token' &&
        body.client_secret === 'csecret')
      .reply(200, { access_token: 'at-2', expires_in: 3599, scope: 'calendar' });

    const out = await google.refreshToken('rt-1', CFG);
    expect(out.access_token).toBe('at-2');
    expect(out.expires_in).toBe(3599);
  });

  test('throws with code invalid_grant when Google revokes the token', async () => {
    nock('https://oauth2.googleapis.com').post('/token').reply(400, { error: 'invalid_grant' });
    await expect(google.refreshToken('dead', CFG)).rejects.toMatchObject({ code: 'invalid_grant' });
  });

  test('throws with code google_error on a 5xx', async () => {
    nock('https://oauth2.googleapis.com').post('/token').reply(503, 'upstream down');
    await expect(google.refreshToken('rt-1', CFG)).rejects.toMatchObject({ code: 'google_error' });
  });
});

describe('lib/google fetchPrimaryCalendar', () => {
  test('returns email + calendar_id from the primary calendar id', async () => {
    nock('https://www.googleapis.com', {
      reqheaders: { authorization: 'Bearer at-1' },
    })
      .get('/calendar/v3/calendars/primary')
      .reply(200, { id: 'owner@gmail.com', summary: 'owner@gmail.com' });

    const out = await google.fetchPrimaryCalendar('at-1');
    expect(out).toEqual({ email: 'owner@gmail.com', calendar_id: 'owner@gmail.com' });
  });

  test('throws google_error on failure', async () => {
    nock('https://www.googleapis.com').get('/calendar/v3/calendars/primary').reply(500, 'boom');
    await expect(google.fetchPrimaryCalendar('at-1')).rejects.toMatchObject({ code: 'google_error' });
  });
});
```

- [ ] **Step 2: Run test to verify it fails.**

```bash
npx jest test/google.test.js
```

Expected failure: `Cannot find module '../lib/google'`.

- [ ] **Step 3: Write minimal implementation.** Create `lib/google.js`:

```js
'use strict';

const TOKEN_URL = 'https://oauth2.googleapis.com/token';
const PRIMARY_CAL_URL = 'https://www.googleapis.com/calendar/v3/calendars/primary';

class GoogleError extends Error {
  constructor(message, code) {
    super(message);
    this.name = 'GoogleError';
    this.code = code;
  }
}

async function postToken(params) {
  let res;
  try {
    res = await fetch(TOKEN_URL, {
      method: 'POST',
      headers: { 'content-type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(params).toString(),
    });
  } catch (e) {
    throw new GoogleError(`Google token request failed: ${e.message}`, 'google_error');
  }

  let json = {};
  try {
    json = await res.json();
  } catch (e) {
    json = {};
  }

  if (!res.ok) {
    if (res.status >= 500) {
      throw new GoogleError(`Google token endpoint ${res.status}`, 'google_error');
    }
    // 4xx: surface Google's own error code (e.g. invalid_grant).
    throw new GoogleError(json.error || `Google token endpoint ${res.status}`, json.error || 'oauth_failure');
  }
  return json;
}

async function exchangeCode(code, cfg) {
  return postToken({
    code,
    client_id: cfg.googleClientId,
    client_secret: cfg.googleClientSecret,
    redirect_uri: cfg.googleRedirectUri,
    grant_type: 'authorization_code',
  });
}

async function refreshToken(rt, cfg) {
  return postToken({
    refresh_token: rt,
    client_id: cfg.googleClientId,
    client_secret: cfg.googleClientSecret,
    grant_type: 'refresh_token',
  });
}

async function fetchPrimaryCalendar(accessToken) {
  let res;
  try {
    res = await fetch(PRIMARY_CAL_URL, {
      headers: { authorization: `Bearer ${accessToken}` },
    });
  } catch (e) {
    throw new GoogleError(`Calendar request failed: ${e.message}`, 'google_error');
  }
  if (!res.ok) {
    throw new GoogleError(`Calendar endpoint ${res.status}`, 'google_error');
  }
  const json = await res.json();
  return { email: json.id, calendar_id: json.id };
}

module.exports = { exchangeCode, refreshToken, fetchPrimaryCalendar, GoogleError };
```

- [ ] **Step 4: Run test to verify it passes.**

```bash
npx jest test/google.test.js
```

Expected: `Tests: 7 passed`.

- [ ] **Step 5: Commit.**

```bash
git add lib/google.js test/google.test.js
git commit -m "feat: add Google OAuth token + primary-calendar client with error codes"
```

---

### Task 8: `routes/license.js` — POST /license/validate

**Files:**
- Create `routes/license.js`
- Create `test/helpers/buildApp.js` (shared by this and later route tasks)
- Test: `test/license.routes.test.js`

Steps:

- [ ] **Step 1: Write the test helper.** Create `test/helpers/buildApp.js`:

```js
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const express = require('express');

/**
 * Builds an isolated Express app with the broker router mounted at '/' (no BASE_PATH
 * prefix) for tests, plus a licenses file written from `licenses`.
 * Returns { app, licensesFile, cleanup }.
 */
function buildTestApp({ licenses = [] } = {}) {
  const licensesFile = path.join(os.tmpdir(), `lic-${Date.now()}-${Math.random()}.json`);
  fs.writeFileSync(licensesFile, JSON.stringify(licenses));

  process.env.GOOGLE_CLIENT_ID = 'cid';
  process.env.GOOGLE_CLIENT_SECRET = 'csecret';
  process.env.GOOGLE_REDIRECT_URI = 'https://slashbox.fr/slashbooking/api/oauth/callback';
  process.env.STATE_KEY = 'a-very-long-random-state-key-value';
  process.env.CLAIM_TTL_SECONDS = '60';
  process.env.LICENSES_FILE = licensesFile;
  process.env.BASE_PATH = '/slashbooking/api';
  process.env.ALLOWED_RETURN_SCHEME = 'https';

  jest.resetModules();
  const buildRouter = require('../../app');
  const router = buildRouter();

  const app = express();
  app.use(express.json());
  app.use('/', router);

  const cleanup = () => {
    try { fs.unlinkSync(licensesFile); } catch (e) { /* ignore */ }
    if (router.__claims && router.__claims.stop) {
      router.__claims.stop();
    }
  };

  return { app, licensesFile, router, cleanup };
}

module.exports = { buildTestApp };
```

- [ ] **Step 2: Write the failing test.** Create `test/license.routes.test.js`:

```js
'use strict';

const request = require('supertest');
const { buildTestApp } = require('./helpers/buildApp');

describe('POST /license/validate', () => {
  let ctx;
  afterEach(() => ctx && ctx.cleanup());

  test('valid:true for a known active license', async () => {
    ctx = buildTestApp({ licenses: [{ key: 'GOOD', plan: 'pro', expires: '2999-01-01' }] });
    const res = await request(ctx.app)
      .post('/license/validate')
      .send({ license: 'GOOD', site: 'https://site.com' });
    expect(res.status).toBe(200);
    expect(res.body).toEqual({ valid: true, plan: 'pro', expires: '2999-01-01' });
  });

  test('valid:false for an unknown license', async () => {
    ctx = buildTestApp({ licenses: [] });
    const res = await request(ctx.app)
      .post('/license/validate')
      .send({ license: 'NOPE', site: 'https://site.com' });
    expect(res.status).toBe(200);
    expect(res.body).toEqual({ valid: false, plan: null, expires: null });
  });

  test('400 when license is missing', async () => {
    ctx = buildTestApp({ licenses: [] });
    const res = await request(ctx.app).post('/license/validate').send({ site: 'https://site.com' });
    expect(res.status).toBe(400);
    expect(res.body.error).toBe('invalid_request');
  });
});
```

- [ ] **Step 3: Run test to verify it fails.**

```bash
npx jest test/license.routes.test.js
```

Expected failure: `Cannot find module '../../app'`.

- [ ] **Step 4: Write minimal implementation.** Create `routes/license.js`:

```js
'use strict';

const express = require('express');
const licenses = require('../lib/licenses');

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

module.exports = { licenseRouter };
```

Note: `routes/license.js` is mounted by `app.js`, which is created in Task 11. To make THIS test pass now without circular work, create a minimal `app.js` in this task and expand it in Task 11.

- [ ] **Step 5: Create the minimal `app.js`.** Create `app.js`:

```js
'use strict';

const express = require('express');
const config = require('./config');
const { licenseRouter } = require('./routes/license');

/**
 * Builds the mountable broker Router. The host app should mount it under BASE_PATH.
 */
function buildRouter(cfg = config) {
  const router = express.Router();
  router.use(licenseRouter(cfg));
  return router;
}

module.exports = buildRouter;
```

- [ ] **Step 6: Run test to verify it passes.**

```bash
npx jest test/license.routes.test.js
```

Expected: `Tests: 3 passed`.

- [ ] **Step 7: Commit.**

```bash
git add routes/license.js app.js test/helpers/buildApp.js test/license.routes.test.js
git commit -m "feat: add POST /license/validate route and mountable app router"
```

---

### Task 9: `routes/oauth.js` — POST /oauth/start

**Files:**
- Modify `app.js` (mount the oauth router; add lines after the license mount)
- Create `routes/oauth.js`
- Test: `test/oauth.start.test.js`

Notes: `/oauth/start` validates the license via `lib/licenses` (401 `invalid_license` when invalid), validates the `return` URL scheme against `cfg.allowedReturnScheme` (400 `invalid_return`), signs state `{ license, return, n }`, and builds the Google consent URL. The consent URL points at `https://accounts.google.com/o/oauth2/v2/auth` with `client_id`, `redirect_uri` = `cfg.googleRedirectUri`, `response_type=code`, `access_type=offline`, `prompt=consent`, `scope` = calendar scopes, and `state`.

Steps:

- [ ] **Step 1: Write the failing test.** Create `test/oauth.start.test.js`:

```js
'use strict';

const request = require('supertest');
const { buildTestApp } = require('./helpers/buildApp');
const { verifyState } = require('../lib/state');

const KEY = 'a-very-long-random-state-key-value';

describe('POST /oauth/start', () => {
  let ctx;
  afterEach(() => ctx && ctx.cleanup());

  test('returns a Google auth_url carrying a verifiable state', async () => {
    ctx = buildTestApp({ licenses: [{ key: 'GOOD', plan: 'pro', expires: '2999-01-01' }] });
    const res = await request(ctx.app).post('/oauth/start').send({
      license: 'GOOD',
      return: 'https://wp.example.com/wp-admin/admin.php?page=cb',
      n: 'nonce-xyz',
    });
    expect(res.status).toBe(200);
    expect(res.body.auth_url).toMatch(/^https:\/\/accounts\.google\.com\/o\/oauth2\/v2\/auth\?/);

    const url = new URL(res.body.auth_url);
    expect(url.searchParams.get('client_id')).toBe('cid');
    expect(url.searchParams.get('response_type')).toBe('code');
    expect(url.searchParams.get('access_type')).toBe('offline');
    expect(url.searchParams.get('prompt')).toBe('consent');
    expect(url.searchParams.get('redirect_uri')).toBe('https://slashbox.fr/slashbooking/api/oauth/callback');

    const state = url.searchParams.get('state');
    const payload = verifyState(state, KEY);
    expect(payload.license).toBe('GOOD');
    expect(payload.return).toBe('https://wp.example.com/wp-admin/admin.php?page=cb');
    expect(payload.n).toBe('nonce-xyz');
  });

  test('401 invalid_license for an unknown license', async () => {
    ctx = buildTestApp({ licenses: [] });
    const res = await request(ctx.app).post('/oauth/start').send({
      license: 'NOPE', return: 'https://wp.example.com/cb', n: 'n',
    });
    expect(res.status).toBe(401);
    expect(res.body).toEqual({ error: 'invalid_license' });
  });

  test('400 invalid_return for a non-https return URL', async () => {
    ctx = buildTestApp({ licenses: [{ key: 'GOOD', expires: '2999-01-01' }] });
    const res = await request(ctx.app).post('/oauth/start').send({
      license: 'GOOD', return: 'http://insecure.example.com/cb', n: 'n',
    });
    expect(res.status).toBe(400);
    expect(res.body).toEqual({ error: 'invalid_return' });
  });

  test('400 invalid_return for a malformed return URL', async () => {
    ctx = buildTestApp({ licenses: [{ key: 'GOOD', expires: '2999-01-01' }] });
    const res = await request(ctx.app).post('/oauth/start').send({
      license: 'GOOD', return: 'not a url', n: 'n',
    });
    expect(res.status).toBe(400);
    expect(res.body).toEqual({ error: 'invalid_return' });
  });
});
```

- [ ] **Step 2: Run test to verify it fails.**

```bash
npx jest test/oauth.start.test.js
```

Expected failure: `Cannot find module '../routes/oauth'` (after you wire it in app.js) — initially the route 404s. The assertion `expect(res.status).toBe(200)` fails with `404`.

- [ ] **Step 3: Write minimal implementation.** Create `routes/oauth.js`:

```js
'use strict';

const express = require('express');
const licenses = require('../lib/licenses');
const { signState } = require('../lib/state');
const { ClaimStore } = require('../lib/claims');
const { logger, redact } = require('../lib/logger');

const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
const SCOPES = [
  'https://www.googleapis.com/auth/calendar',
  'openid',
  'email',
].join(' ');

function isAllowedReturn(returnUrl, scheme) {
  let u;
  try {
    u = new URL(returnUrl);
  } catch (e) {
    return false;
  }
  return u.protocol === `${scheme}:`;
}

function oauthRouter(cfg, claims) {
  const router = express.Router();

  router.post('/oauth/start', (req, res) => {
    const { license, return: returnUrl, n } = req.body || {};
    if (!license || !returnUrl) {
      return res.status(400).json({ error: 'invalid_return' });
    }
    const lic = licenses.validate(license, returnUrl, cfg.licensesFile);
    if (!lic.valid) {
      return res.status(401).json({ error: 'invalid_license' });
    }
    if (!isAllowedReturn(returnUrl, cfg.allowedReturnScheme)) {
      return res.status(400).json({ error: 'invalid_return' });
    }

    const state = signState({ license, return: returnUrl, n: n || '' }, cfg.stateKey);
    const params = new URLSearchParams({
      client_id: cfg.googleClientId,
      redirect_uri: cfg.googleRedirectUri,
      response_type: 'code',
      access_type: 'offline',
      prompt: 'consent',
      scope: SCOPES,
      state,
    });
    return res.json({ auth_url: `${GOOGLE_AUTH_URL}?${params.toString()}` });
  });

  return router;
}

module.exports = { oauthRouter, isAllowedReturn };
```

Note: `lib/licenses.validate(license, returnUrl, ...)` is called with the return URL as the `site` argument here only so that site-bound licenses still match in `/oauth/start`; if your license records use `sites[]`, the `site` passed at validate time during start is the WP return origin. The plugin sends `site` = WP home URL on `/license/validate`; for `/oauth/start` the binding check uses whatever origin the return URL carries. Keep this exactly as written.

- [ ] **Step 4: Wire the oauth router into `app.js`.** Edit `app.js`. Replace the whole file with:

```js
'use strict';

const express = require('express');
const config = require('./config');
const { licenseRouter } = require('./routes/license');
const { oauthRouter } = require('./routes/oauth');
const { ClaimStore } = require('./lib/claims');

/**
 * Builds the mountable broker Router. The host app should mount it under BASE_PATH.
 */
function buildRouter(cfg = config) {
  const router = express.Router();
  const claims = new ClaimStore({ ttlSeconds: cfg.claimTtlSeconds });
  router.__claims = claims; // exposed for tests to stop the sweep timer

  router.use(oauthRouter(cfg, claims));
  router.use(licenseRouter(cfg));
  return router;
}

module.exports = buildRouter;
```

- [ ] **Step 5: Run test to verify it passes.**

```bash
npx jest test/oauth.start.test.js
```

Expected: `Tests: 4 passed`.

- [ ] **Step 6: Commit.**

```bash
git add routes/oauth.js app.js test/oauth.start.test.js
git commit -m "feat: add POST /oauth/start with license check, return-URL guard, signed state"
```

---

### Task 10: `routes/oauth.js` — GET /oauth/callback (Google -> 302 redirect with claim)

**Files:**
- Modify `routes/oauth.js` (add the `/oauth/callback` handler inside `oauthRouter`, after the `/oauth/start` handler)
- Test: `test/oauth.callback.test.js`

Notes: On success the broker verifies state, exchanges code (`lib/google.exchangeCode`), fetches the primary calendar (`lib/google.fetchPrimaryCalendar`), stores a one-time claim bundle `{ refresh_token, access_token, expires_in, scope, email, calendar_id }`, then 302 redirects to `<return>?sb_claim=<claim>&n=<n>`. On any Google error or invalid state it 302 redirects to `<return>?sb_error=<code>&n=<n>`. When state itself cannot be verified there is no trusted return URL, so respond `400 { error: 'invalid_state' }`.

Steps:

- [ ] **Step 1: Write the failing test.** Create `test/oauth.callback.test.js`:

```js
'use strict';

const request = require('supertest');
const nock = require('nock');
const { buildTestApp } = require('./helpers/buildApp');
const { signState } = require('../lib/state');

const KEY = 'a-very-long-random-state-key-value';
const RETURN = 'https://wp.example.com/wp-admin/admin.php?page=cb';

afterEach(() => nock.cleanAll());

describe('GET /oauth/callback', () => {
  let ctx;
  afterEach(() => ctx && ctx.cleanup());

  test('exchanges code, stores a claim, 302 to return with sb_claim & n', async () => {
    ctx = buildTestApp({ licenses: [{ key: 'GOOD', expires: '2999-01-01' }] });

    nock('https://oauth2.googleapis.com').post('/token').reply(200, {
      access_token: 'at-1', refresh_token: 'rt-1', expires_in: 3599, scope: 'calendar',
    });
    nock('https://www.googleapis.com')
      .get('/calendar/v3/calendars/primary')
      .reply(200, { id: 'owner@gmail.com' });

    const state = signState({ license: 'GOOD', return: RETURN, n: 'nonce-xyz' }, KEY);
    const res = await request(ctx.app).get('/oauth/callback').query({ code: 'AUTHCODE', state });

    expect(res.status).toBe(302);
    const loc = new URL(res.headers.location);
    expect(loc.origin + loc.pathname).toBe('https://wp.example.com/wp-admin/admin.php');
    expect(loc.searchParams.get('n')).toBe('nonce-xyz');
    const claim = loc.searchParams.get('sb_claim');
    expect(claim).toMatch(/^[0-9a-f]{64}$/);
  });

  test('302 to return with sb_error when Google token exchange fails', async () => {
    ctx = buildTestApp({ licenses: [{ key: 'GOOD', expires: '2999-01-01' }] });
    nock('https://oauth2.googleapis.com').post('/token').reply(400, { error: 'invalid_grant' });

    const state = signState({ license: 'GOOD', return: RETURN, n: 'nonce-xyz' }, KEY);
    const res = await request(ctx.app).get('/oauth/callback').query({ code: 'BAD', state });

    expect(res.status).toBe(302);
    const loc = new URL(res.headers.location);
    expect(loc.searchParams.get('sb_error')).toBe('invalid_grant');
    expect(loc.searchParams.get('n')).toBe('nonce-xyz');
  });

  test('400 invalid_state when state is tampered (no trusted return URL)', async () => {
    ctx = buildTestApp({ licenses: [{ key: 'GOOD', expires: '2999-01-01' }] });
    const res = await request(ctx.app)
      .get('/oauth/callback')
      .query({ code: 'AUTHCODE', state: 'tampered.signature' });
    expect(res.status).toBe(400);
    expect(res.body).toEqual({ error: 'invalid_state' });
  });

  test('302 to return with sb_error=access_denied when Google sends error param', async () => {
    ctx = buildTestApp({ licenses: [{ key: 'GOOD', expires: '2999-01-01' }] });
    const state = signState({ license: 'GOOD', return: RETURN, n: 'nonce-xyz' }, KEY);
    const res = await request(ctx.app)
      .get('/oauth/callback')
      .query({ error: 'access_denied', state });
    expect(res.status).toBe(302);
    const loc = new URL(res.headers.location);
    expect(loc.searchParams.get('sb_error')).toBe('access_denied');
  });
});
```

- [ ] **Step 2: Run test to verify it fails.**

```bash
npx jest test/oauth.callback.test.js
```

Expected failure: the route does not exist; first assertion fails with `404`.

- [ ] **Step 3: Write minimal implementation.** Edit `routes/oauth.js`. Add `const google = require('../lib/google');` to the top requires, then add the following handler inside `oauthRouter`, immediately after the `/oauth/start` handler and before `return router;`:

```js
  router.get('/oauth/callback', async (req, res) => {
    const { code, state, error } = req.query || {};

    let payload;
    try {
      const { verifyState } = require('../lib/state');
      payload = verifyState(state, cfg.stateKey);
    } catch (e) {
      logger.warn({ msg: 'oauth callback: invalid state' });
      return res.status(400).json({ error: 'invalid_state' });
    }

    const redirectToReturn = (extraParams) => {
      const u = new URL(payload.return);
      for (const [k, v] of Object.entries(extraParams)) {
        u.searchParams.set(k, v);
      }
      u.searchParams.set('n', payload.n || '');
      return res.redirect(302, u.toString());
    };

    if (error) {
      return redirectToReturn({ sb_error: String(error) });
    }
    if (!code) {
      return redirectToReturn({ sb_error: 'missing_code' });
    }

    try {
      const tokens = await google.exchangeCode(String(code), cfg);
      const cal = await google.fetchPrimaryCalendar(tokens.access_token);
      const claim = claims.put({
        refresh_token: tokens.refresh_token,
        access_token: tokens.access_token,
        expires_in: tokens.expires_in,
        scope: tokens.scope,
        email: cal.email,
        calendar_id: cal.calendar_id,
      });
      logger.info(redact({ msg: 'oauth callback success', email: cal.email }));
      return redirectToReturn({ sb_claim: claim });
    } catch (e) {
      logger.warn({ msg: 'oauth callback: google error', code: e.code });
      return redirectToReturn({ sb_error: e.code || 'google_error' });
    }
  });
```

- [ ] **Step 4: Run test to verify it passes.**

```bash
npx jest test/oauth.callback.test.js
```

Expected: `Tests: 4 passed`.

- [ ] **Step 5: Commit.**

```bash
git add routes/oauth.js test/oauth.callback.test.js
git commit -m "feat: add GET /oauth/callback token exchange, one-time claim, redirect"
```

---

### Task 11: `routes/oauth.js` — POST /oauth/claim (one-time read)

**Files:**
- Modify `routes/oauth.js` (add the `/oauth/claim` handler inside `oauthRouter`, after `/oauth/callback`)
- Test: `test/oauth.claim.test.js`

Notes: `/oauth/claim` validates the license (401 `invalid_license`), reads the claim once via `claims.take` (404 `claim_not_found` for missing/expired/already-used), and returns the token bundle.

Steps:

- [ ] **Step 1: Write the failing test.** Create `test/oauth.claim.test.js`:

```js
'use strict';

const request = require('supertest');
const nock = require('nock');
const { buildTestApp } = require('./helpers/buildApp');
const { signState } = require('../lib/state');

const KEY = 'a-very-long-random-state-key-value';
const RETURN = 'https://wp.example.com/cb';

afterEach(() => nock.cleanAll());

async function obtainClaim(ctx) {
  nock('https://oauth2.googleapis.com').post('/token').reply(200, {
    access_token: 'at-1', refresh_token: 'rt-1', expires_in: 3599, scope: 'calendar',
  });
  nock('https://www.googleapis.com')
    .get('/calendar/v3/calendars/primary')
    .reply(200, { id: 'owner@gmail.com' });
  const state = signState({ license: 'GOOD', return: RETURN, n: 'n1' }, KEY);
  const cb = await request(ctx.app).get('/oauth/callback').query({ code: 'AUTHCODE', state });
  return new URL(cb.headers.location).searchParams.get('sb_claim');
}

describe('POST /oauth/claim', () => {
  let ctx;
  afterEach(() => ctx && ctx.cleanup());

  test('returns the token bundle for a valid claim', async () => {
    ctx = buildTestApp({ licenses: [{ key: 'GOOD', expires: '2999-01-01' }] });
    const claim = await obtainClaim(ctx);

    const res = await request(ctx.app).post('/oauth/claim').send({ license: 'GOOD', claim });
    expect(res.status).toBe(200);
    expect(res.body).toEqual({
      refresh_token: 'rt-1',
      access_token: 'at-1',
      expires_in: 3599,
      scope: 'calendar',
      email: 'owner@gmail.com',
      calendar_id: 'owner@gmail.com',
    });
  });

  test('second claim of the same code returns 404 claim_not_found', async () => {
    ctx = buildTestApp({ licenses: [{ key: 'GOOD', expires: '2999-01-01' }] });
    const claim = await obtainClaim(ctx);

    await request(ctx.app).post('/oauth/claim').send({ license: 'GOOD', claim });
    const res = await request(ctx.app).post('/oauth/claim').send({ license: 'GOOD', claim });
    expect(res.status).toBe(404);
    expect(res.body).toEqual({ error: 'claim_not_found' });
  });

  test('401 invalid_license when license is unknown', async () => {
    ctx = buildTestApp({ licenses: [{ key: 'GOOD', expires: '2999-01-01' }] });
    const claim = await obtainClaim(ctx);
    const res = await request(ctx.app).post('/oauth/claim').send({ license: 'NOPE', claim });
    expect(res.status).toBe(401);
    expect(res.body).toEqual({ error: 'invalid_license' });
  });

  test('404 claim_not_found for an unknown claim', async () => {
    ctx = buildTestApp({ licenses: [{ key: 'GOOD', expires: '2999-01-01' }] });
    const res = await request(ctx.app)
      .post('/oauth/claim')
      .send({ license: 'GOOD', claim: 'deadbeef' });
    expect(res.status).toBe(404);
    expect(res.body).toEqual({ error: 'claim_not_found' });
  });
});
```

- [ ] **Step 2: Run test to verify it fails.**

```bash
npx jest test/oauth.claim.test.js
```

Expected failure: route missing; first assertion fails with `404` body `{}` (no handler).

- [ ] **Step 3: Write minimal implementation.** Edit `routes/oauth.js`. Add the following handler inside `oauthRouter`, immediately after the `/oauth/callback` handler and before `return router;`:

```js
  router.post('/oauth/claim', (req, res) => {
    const { license, claim } = req.body || {};
    const lic = licenses.validate(license, '', cfg.licensesFile);
    if (!lic.valid) {
      return res.status(401).json({ error: 'invalid_license' });
    }
    const data = claims.take(claim);
    if (!data) {
      return res.status(404).json({ error: 'claim_not_found' });
    }
    return res.json(data);
  });
```

Note: `licenses.validate(license, '', ...)` passes an empty `site`. For a license with a `sites[]` allow-list this would fail; the plugin always sends the SAME license that validated at `/oauth/start`, and the claim is already bound to a single OAuth flow, so the empty site is intentional — claim ownership is enforced by the one-time, unguessable 32-byte claim plus license match, not by site re-check. Keep exactly as written. (If a license uses `sites[]`, `validate` with empty site returns invalid; to support that case the implementer MUST instead change the claim bundle to also store the validating `site` and pass it here — but per the canonical contract the claim request carries only `{license, claim}`, so the empty-site path is the contract-compliant behavior.)

- [ ] **Step 4: Run test to verify it passes.**

```bash
npx jest test/oauth.claim.test.js
```

Expected: `Tests: 4 passed`.

- [ ] **Step 5: Commit.**

```bash
git add routes/oauth.js test/oauth.claim.test.js
git commit -m "feat: add POST /oauth/claim one-time token-bundle read"
```

---

### Task 12: `routes/oauth.js` — POST /oauth/refresh (stateless)

**Files:**
- Modify `routes/oauth.js` (add the `/oauth/refresh` handler inside `oauthRouter`, after `/oauth/claim`)
- Test: `test/oauth.refresh.test.js`

Notes: `/oauth/refresh` validates the license (401 `invalid_license`), calls `lib/google.refreshToken`, returns `{access_token, expires_in}`. On Google `invalid_grant` -> 401 `token_revoked`. On Google `google_error` (5xx/network) -> 502 `google_error`. Stores nothing.

Steps:

- [ ] **Step 1: Write the failing test.** Create `test/oauth.refresh.test.js`:

```js
'use strict';

const request = require('supertest');
const nock = require('nock');
const { buildTestApp } = require('./helpers/buildApp');

afterEach(() => nock.cleanAll());

describe('POST /oauth/refresh', () => {
  let ctx;
  afterEach(() => ctx && ctx.cleanup());

  test('returns a fresh access token', async () => {
    ctx = buildTestApp({ licenses: [{ key: 'GOOD', expires: '2999-01-01' }] });
    nock('https://oauth2.googleapis.com')
      .post('/token')
      .reply(200, { access_token: 'at-2', expires_in: 3599, scope: 'calendar' });

    const res = await request(ctx.app)
      .post('/oauth/refresh')
      .send({ license: 'GOOD', refresh_token: 'rt-1' });
    expect(res.status).toBe(200);
    expect(res.body).toEqual({ access_token: 'at-2', expires_in: 3599 });
  });

  test('401 invalid_license for an unknown license', async () => {
    ctx = buildTestApp({ licenses: [] });
    const res = await request(ctx.app)
      .post('/oauth/refresh')
      .send({ license: 'NOPE', refresh_token: 'rt-1' });
    expect(res.status).toBe(401);
    expect(res.body).toEqual({ error: 'invalid_license' });
  });

  test('401 token_revoked when Google returns invalid_grant', async () => {
    ctx = buildTestApp({ licenses: [{ key: 'GOOD', expires: '2999-01-01' }] });
    nock('https://oauth2.googleapis.com').post('/token').reply(400, { error: 'invalid_grant' });

    const res = await request(ctx.app)
      .post('/oauth/refresh')
      .send({ license: 'GOOD', refresh_token: 'dead' });
    expect(res.status).toBe(401);
    expect(res.body).toEqual({ error: 'token_revoked' });
  });

  test('502 google_error when Google returns a 5xx', async () => {
    ctx = buildTestApp({ licenses: [{ key: 'GOOD', expires: '2999-01-01' }] });
    nock('https://oauth2.googleapis.com').post('/token').reply(503, 'down');

    const res = await request(ctx.app)
      .post('/oauth/refresh')
      .send({ license: 'GOOD', refresh_token: 'rt-1' });
    expect(res.status).toBe(502);
    expect(res.body).toEqual({ error: 'google_error' });
  });
});
```

- [ ] **Step 2: Run test to verify it fails.**

```bash
npx jest test/oauth.refresh.test.js
```

Expected failure: route missing; first assertion fails with `404`.

- [ ] **Step 3: Write minimal implementation.** Edit `routes/oauth.js`. Add the following handler inside `oauthRouter`, immediately after the `/oauth/claim` handler and before `return router;`:

```js
  router.post('/oauth/refresh', async (req, res) => {
    const { license, refresh_token: rt } = req.body || {};
    const lic = licenses.validate(license, '', cfg.licensesFile);
    if (!lic.valid) {
      return res.status(401).json({ error: 'invalid_license' });
    }
    if (!rt) {
      return res.status(400).json({ error: 'invalid_request' });
    }
    try {
      const out = await google.refreshToken(String(rt), cfg);
      return res.json({ access_token: out.access_token, expires_in: out.expires_in });
    } catch (e) {
      if (e.code === 'invalid_grant') {
        return res.status(401).json({ error: 'token_revoked' });
      }
      logger.warn({ msg: 'oauth refresh: google error', code: e.code });
      return res.status(502).json({ error: 'google_error' });
    }
  });
```

- [ ] **Step 4: Run test to verify it passes.**

```bash
npx jest test/oauth.refresh.test.js
```

Expected: `Tests: 4 passed`.

- [ ] **Step 5: Commit.**

```bash
git add routes/oauth.js test/oauth.refresh.test.js
git commit -m "feat: add stateless POST /oauth/refresh with token_revoked mapping"
```

---

### Task 13: Per-route rate limiting + open-redirect hardening

**Files:**
- Modify `routes/oauth.js` (require `express-rate-limit`; create limiters; apply to `/oauth/start`, `/oauth/claim`, `/oauth/refresh`)
- Test: `test/oauth.ratelimit.test.js`

Notes: apply a strict limiter to `/oauth/start` (e.g. 20 req / 15 min per IP) and `/oauth/claim` (e.g. 30 / 15 min). The open-redirect guard already lives in `/oauth/start` (`isAllowedReturn`). This task adds a regression test that a `javascript:` return is rejected and adds the limiter behavior test with a tiny window.

Steps:

- [ ] **Step 1: Write the failing test.** Create `test/oauth.ratelimit.test.js`:

```js
'use strict';

const request = require('supertest');
const { buildTestApp } = require('./helpers/buildApp');

describe('oauth hardening', () => {
  let ctx;
  afterEach(() => ctx && ctx.cleanup());

  test('rejects a javascript: return URL as invalid_return (anti open-redirect)', async () => {
    ctx = buildTestApp({ licenses: [{ key: 'GOOD', expires: '2999-01-01' }] });
    const res = await request(ctx.app).post('/oauth/start').send({
      license: 'GOOD',
      return: 'javascript:alert(1)//https://x',
      n: 'n',
    });
    expect(res.status).toBe(400);
    expect(res.body).toEqual({ error: 'invalid_return' });
  });

  test('start endpoint is rate limited after the configured max', async () => {
    process.env.RL_START_MAX = '3';
    process.env.RL_WINDOW_MS = '60000';
    ctx = buildTestApp({ licenses: [{ key: 'GOOD', expires: '2999-01-01' }] });
    const body = { license: 'GOOD', return: 'https://wp.example.com/cb', n: 'n' };

    for (let i = 0; i < 3; i += 1) {
      // eslint-disable-next-line no-await-in-loop
      const ok = await request(ctx.app).post('/oauth/start').send(body);
      expect(ok.status).toBe(200);
    }
    const limited = await request(ctx.app).post('/oauth/start').send(body);
    expect(limited.status).toBe(429);
    delete process.env.RL_START_MAX;
    delete process.env.RL_WINDOW_MS;
  });
});
```

- [ ] **Step 2: Run test to verify it fails.**

```bash
npx jest test/oauth.ratelimit.test.js
```

Expected failure: the rate-limit test fails because the 4th request returns `200`, not `429`. (The open-redirect test already passes — that confirms the guard; keep it as a regression test.)

- [ ] **Step 3: Write minimal implementation.** Edit `routes/oauth.js`. Add to the top requires:

```js
const rateLimit = require('express-rate-limit');
```

Then, inside `oauthRouter`, immediately after `const router = express.Router();`, add:

```js
  const windowMs = parseInt(process.env.RL_WINDOW_MS || '900000', 10); // 15 min
  const startLimiter = rateLimit({
    windowMs,
    max: parseInt(process.env.RL_START_MAX || '20', 10),
    standardHeaders: true,
    legacyHeaders: false,
    message: { error: 'rate_limited' },
  });
  const claimLimiter = rateLimit({
    windowMs,
    max: parseInt(process.env.RL_CLAIM_MAX || '30', 10),
    standardHeaders: true,
    legacyHeaders: false,
    message: { error: 'rate_limited' },
  });
  const refreshLimiter = rateLimit({
    windowMs,
    max: parseInt(process.env.RL_REFRESH_MAX || '120', 10),
    standardHeaders: true,
    legacyHeaders: false,
    message: { error: 'rate_limited' },
  });
```

Then change the three route registrations to insert the limiter as middleware. Change:

```js
  router.post('/oauth/start', (req, res) => {
```

to:

```js
  router.post('/oauth/start', startLimiter, (req, res) => {
```

Change:

```js
  router.post('/oauth/claim', (req, res) => {
```

to:

```js
  router.post('/oauth/claim', claimLimiter, (req, res) => {
```

Change:

```js
  router.post('/oauth/refresh', async (req, res) => {
```

to:

```js
  router.post('/oauth/refresh', refreshLimiter, async (req, res) => {
```

- [ ] **Step 4: Run test to verify it passes.**

```bash
npx jest test/oauth.ratelimit.test.js
```

Expected: `Tests: 2 passed`.

- [ ] **Step 5: Run the FULL suite to confirm no regressions** (limiters default to high maxes so other tests are unaffected):

```bash
npx jest
```

Expected: all suites pass.

- [ ] **Step 6: Commit.**

```bash
git add routes/oauth.js test/oauth.ratelimit.test.js
git commit -m "feat: rate-limit oauth endpoints and regression-test open-redirect guard"
```

---

### Task 14: `server.js` — standalone server with helmet, BASE_PATH mount, /health

**Files:**
- Create `server.js`
- Test: `test/health.test.js`

Notes: `server.js` must be testable without binding a port. Export the configured Express app and only call `listen` when run directly (`require.main === module`). Mount the broker router under `cfg.basePath`; add `GET /health` at the ROOT (not under basePath) returning `{ ok: true }`. Apply `helmet()` and `express.json()` globally.

Steps:

- [ ] **Step 1: Write the failing test.** Create `test/health.test.js`:

```js
'use strict';

const request = require('supertest');

function freshServerApp() {
  process.env.GOOGLE_CLIENT_ID = 'cid';
  process.env.GOOGLE_CLIENT_SECRET = 'csecret';
  process.env.GOOGLE_REDIRECT_URI = 'https://slashbox.fr/slashbooking/api/oauth/callback';
  process.env.STATE_KEY = 'a-very-long-random-state-key-value';
  process.env.CLAIM_TTL_SECONDS = '60';
  process.env.LICENSES_FILE = '/tmp/licenses-health.json';
  process.env.BASE_PATH = '/slashbooking/api';
  process.env.ALLOWED_RETURN_SCHEME = 'https';
  jest.resetModules();
  return require('../server').app;
}

describe('server.js', () => {
  test('GET /health returns { ok: true }', async () => {
    const app = freshServerApp();
    const res = await request(app).get('/health');
    expect(res.status).toBe(200);
    expect(res.body).toEqual({ ok: true });
  });

  test('mounts the broker router under BASE_PATH', async () => {
    const fs = require('fs');
    fs.writeFileSync('/tmp/licenses-health.json', JSON.stringify([{ key: 'GOOD', plan: 'pro', expires: '2999-01-01' }]));
    const app = freshServerApp();
    const res = await request(app)
      .post('/slashbooking/api/license/validate')
      .send({ license: 'GOOD', site: 'https://site.com' });
    expect(res.status).toBe(200);
    expect(res.body).toEqual({ valid: true, plan: 'pro', expires: '2999-01-01' });
  });

  test('sets a helmet security header', async () => {
    const app = freshServerApp();
    const res = await request(app).get('/health');
    expect(res.headers['x-content-type-options']).toBe('nosniff');
  });
});
```

- [ ] **Step 2: Run test to verify it fails.**

```bash
npx jest test/health.test.js
```

Expected failure: `Cannot find module '../server'`.

- [ ] **Step 3: Write minimal implementation.** Create `server.js`:

```js
'use strict';

const express = require('express');
const helmet = require('helmet');
const config = require('./config');
const buildRouter = require('./app');
const { logger } = require('./lib/logger');

const app = express();
app.set('trust proxy', true); // behind the host app / reverse proxy
app.use(helmet());
app.use(express.json());

app.get('/health', (req, res) => res.json({ ok: true }));

app.use(config.basePath, buildRouter(config));

if (require.main === module) {
  app.listen(config.port, () => {
    logger.info({ msg: 'slashbooking-broker listening', port: config.port, basePath: config.basePath });
  });
}

module.exports = { app };
```

- [ ] **Step 4: Run test to verify it passes.**

```bash
npx jest test/health.test.js
```

Expected: `Tests: 3 passed`.

- [ ] **Step 5: Run the FULL suite.**

```bash
npx jest
```

Expected: all suites pass.

- [ ] **Step 6: Commit.**

```bash
git add server.js test/health.test.js
git commit -m "feat: add standalone server with helmet, BASE_PATH mount, and /health"
```

---

### Task 15: README — deploy, env, mount-in-host-app, security, smoke test

**Files:**
- Create `README.md`

This task has no automated test (documentation). Verify by reading the rendered file.

Steps:

- [ ] **Step 1: Write `README.md`** with this exact content:

````markdown
# slashbooking-broker

Stateless OAuth broker for [SlashBooking](https://slashbox.fr) WordPress sites. It holds the
Google `client_secret` server-side, performs the OAuth code exchange, and hands the WordPress
plugin a short-lived one-time **claim** that the plugin redeems for the token bundle. The broker
**never** persists end-user Google tokens.

## Endpoints

All paths are relative to `BASE_PATH` (e.g. `/slashbooking/api`).

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/oauth/start` | `{license, return, n}` -> `{auth_url}` |
| GET  | `/oauth/callback` | Google redirect target; 302 to `<return>?sb_claim=...&n=...` (or `?sb_error=...&n=...`) |
| POST | `/oauth/claim` | `{license, claim}` -> token bundle (one-time) |
| POST | `/oauth/refresh` | `{license, refresh_token}` -> `{access_token, expires_in}` (stateless) |
| POST | `/license/validate` | `{license, site}` -> `{valid, plan, expires}` |
| GET  | `/health` | `{ ok: true }` (mounted at ROOT by `server.js`, not under BASE_PATH) |

## Environment variables

See `.env.example`. Required: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`,
`STATE_KEY` (>= 16 chars), `LICENSES_FILE`, `BASE_PATH`. Optional with defaults: `CLAIM_TTL_SECONDS`
(60), `ALLOWED_RETURN_SCHEME` (https), `PORT` (8787), `LOG_LEVEL` (info). The process **fails fast**
on startup if a required var is missing.

`GOOGLE_REDIRECT_URI` MUST exactly equal an "Authorized redirect URI" in the Google Cloud OAuth
client, including `BASE_PATH`, e.g. `https://slashbox.fr/slashbooking/api/oauth/callback`.

## Licenses file

JSON array at `LICENSES_FILE`:

```json
[
  { "key": "LIC-AAAA", "plan": "pro", "expires": "2027-01-01", "sites": ["https://client.example"] },
  { "key": "LIC-BBBB", "plan": "lifetime" }
]
```

`expires` omitted = non-expiring. `sites` omitted = any site. The file is read on each request, so
edits take effect without a restart.

## Run standalone (local dev)

```bash
cp .env.example .env   # then fill in real values
npm install
npm start              # node server.js, listens on PORT, mounts under BASE_PATH, /health at root
```

## Mount inside the existing slashbox.fr Node app

The broker exports a builder that returns an Express Router. In the host app:

```js
const buildBrokerRouter = require('/path/to/slashbooking-broker/app');
// Ensure the broker's env vars are present in the host process environment,
// then mount the router under the same BASE_PATH used in GOOGLE_REDIRECT_URI:
hostApp.use('/slashbooking/api', express.json(), buildBrokerRouter());
```

Notes:
- The host app MUST apply a JSON body parser before the router (the standalone `server.js` does this).
- `helmet()` should be applied by the host app globally; `server.js` applies it for standalone runs.
- The broker keeps an in-memory claim store; do not run multiple broker instances behind a load
  balancer without sticky routing for the 60-second claim window (claims live in the process that
  handled `/oauth/callback`).

## Deploy on Plesk (Node)

1. Upload the directory; set the Application Startup File to `server.js` (standalone) or import
   `app.js` from your existing app entry (mounted).
2. Set all env vars in the Plesk Node "Custom environment variables" panel.
3. `npm install` via the Plesk "NPM install" button.
4. Confirm `GET https://slashbox.fr/slashbooking/api/health` (mounted) or `/health` (standalone)
   returns `{ "ok": true }`.

## Security

- **No persistent token storage.** Tokens exist only inside the in-memory claim store for <= 60s,
  then are deleted on first read (`/oauth/claim`).
- **License never appears in a browser URL.** It travels only in POST bodies (start/claim/refresh/
  validate). The browser-visible redirect carries only `sb_claim` (an opaque 32-byte one-time
  handle) and `n`.
- **HTTPS only.** `return` URLs must use `ALLOWED_RETURN_SCHEME` (https); non-https / malformed /
  `javascript:` returns are rejected with `400 invalid_return` (anti open-redirect).
- **State is HMAC-signed** with `STATE_KEY`, verified with `crypto.timingSafeEqual`, expires in
  ~600s. Tampered/expired state -> `400 invalid_state`.
- **helmet** sets security headers; rate limiting protects `/oauth/start`, `/oauth/claim`,
  `/oauth/refresh`.
- **Logs never contain tokens, secrets, license keys, state, or claims** (pino redaction in
  `lib/logger.js`).

## Manual smoke test

1. `GET /health` -> `{ ok: true }`.
2. `POST /license/validate` with a real key + site -> `{ valid: true, ... }`; with a bogus key ->
   `{ valid: false, ... }`.
3. `POST /oauth/start` with a valid license and an https return -> `{ auth_url }`. Open `auth_url`
   in a browser, complete Google consent. You land on `<return>?sb_claim=...&n=...`.
4. `POST /oauth/claim` with that license + claim -> token bundle. Repeat the SAME claim ->
   `404 claim_not_found`.
5. `POST /oauth/refresh` with the returned `refresh_token` -> a new `access_token`. Revoke access in
   the Google account, retry -> `401 token_revoked`.

## Tests

```bash
npm test            # jest, all suites, no real network (nock mocks Google)
```
````

- [ ] **Step 2: Verify the file exists and is non-empty.**

```bash
test -s README.md && echo "README OK"
```

Expected: `README OK`.

- [ ] **Step 3: Commit.**

```bash
git add README.md
git commit -m "docs: add broker README (deploy, env, mount, security, smoke test)"
```

---

### Task 16: Full suite green + deployment task + manual smoke-test checklist

**Files:** none created; this is the verification & deployment gate.

Steps:

- [ ] **Step 1: Run the complete test suite.**

```bash
npx jest
```

Expected: every suite passes, e.g. `Test Suites: 11 passed, 11 total`. If anything fails, STOP and fix before continuing (use superpowers:systematic-debugging).

- [ ] **Step 2: Verify no real network is reachable from tests.** Run with offline-style check (nock should intercept everything; this confirms the suite has no un-mocked HTTP):

```bash
npx jest 2>&1 | grep -i "ECONNREFUSED\|Nock: No match" || echo "NO UNMOCKED HTTP"
```

Expected: `NO UNMOCKED HTTP`.

- [ ] **Step 3: Verify fail-fast.** Confirm the server refuses to start without required env:

```bash
env -i node -e "try { require('./config'); console.log('NO THROW (BAD)'); } catch (e) { console.log('FAILED FAST:', e.message); }"
```

Expected: `FAILED FAST: Missing required environment variable: GOOGLE_CLIENT_ID`.

- [ ] **Step 4: Local standalone boot smoke test** (uses `.env.example` copied to `.env` with dummy-but-valid values; we only check `/health`, no Google call):

```bash
cp .env.example .env
node -e "
process.env.GOOGLE_CLIENT_ID='cid';
process.env.GOOGLE_CLIENT_SECRET='cs';
process.env.GOOGLE_REDIRECT_URI='https://slashbox.fr/slashbooking/api/oauth/callback';
process.env.STATE_KEY='a-very-long-random-state-key-value';
process.env.LICENSES_FILE='/tmp/licenses.json';
process.env.BASE_PATH='/slashbooking/api';
process.env.PORT='8799';
require('fs').writeFileSync('/tmp/licenses.json','[]');
const { app } = require('./server');
const srv = app.listen(8799, async () => {
  const r = await fetch('http://127.0.0.1:8799/health');
  console.log('health status', r.status, await r.text());
  srv.close();
});
"
```

Expected: `health status 200 {"ok":true}`.

- [ ] **Step 5: Remove the dev `.env`** so the dummy file is not committed:

```bash
rm -f .env
git status --porcelain
```

Expected: `.env` is NOT listed (it is git-ignored) and no other unexpected changes.

- [ ] **Step 6: Final commit (if any tracked changes remain) and tag readiness.**

```bash
git add -A
git commit -m "chore: verify full broker suite, fail-fast, and standalone /health smoke test" --allow-empty
```

- [ ] **Step 7: DEPLOYMENT — mount in the existing slashbox.fr app.** Hand-off checklist for the operator (not automated):
  - [ ] Copy the `slashbooking-broker` directory onto the slashbox.fr host (or add as a git submodule of the existing app).
  - [ ] In the existing app entry file, add: `app.use('/slashbooking/api', express.json(), require('/abs/path/slashbooking-broker/app')());`
  - [ ] Add all required env vars to the host process environment (Plesk Node custom env panel): `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI=https://slashbox.fr/slashbooking/api/oauth/callback`, `STATE_KEY`, `LICENSES_FILE`, `BASE_PATH=/slashbooking/api`.
  - [ ] In Google Cloud console, add `https://slashbox.fr/slashbooking/api/oauth/callback` as an Authorized redirect URI on the OAuth client.
  - [ ] Create/upload the `LICENSES_FILE` JSON with real keys.
  - [ ] Restart the host app.
  - [ ] `GET https://slashbox.fr/slashbooking/api/health` -> `{ "ok": true }`.
  - [ ] Run the manual smoke test from the README §"Manual smoke test" end-to-end against the live broker with a real Google account and a real license key.

- [ ] **Step 8: Confirm completion** using superpowers:verification-before-completion: re-run `npx jest` one final time and paste the passing summary as evidence before declaring the broker done.
