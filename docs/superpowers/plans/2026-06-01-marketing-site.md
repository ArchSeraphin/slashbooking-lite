# Marketing Site (brique D, v1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Productionize the hi-fi design handoff into a static one-pager repo `slashbooking-site`, with wired CTAs and self-hosted fonts, ready to deploy on `slashbooking.fr`.

**Architecture:** Static HTML/CSS/JS (no backend, no build). The handoff under `plugins-booking/design_handoff_slashbooking/site/` is the source; this plan copies + adapts it.

**Tech Stack:** Plain HTML/CSS/JS. `curl` to fetch fonts + the plugin ZIP.

**Spec:** `plugins-booking/docs/superpowers/specs/2026-06-01-marketing-site-design.md`

**Repo for this plan:** a NEW sibling repo `../slashbooking-site` (created in Task 1). Source handoff: `../plugins-booking/design_handoff_slashbooking/site/`. No automated tests — this is a static site; acceptance is the manual checklist in Task 5. Commit per task; do NOT push/tag (controller handles repo creation + push).

---

### Task 1: Scaffold `slashbooking-site` from the handoff

**Files:** new repo `../slashbooking-site`

- [ ] **Step 1: Create the repo + copy the handoff sources**

```bash
HANDOFF="/Users/seraphin/Library/CloudStorage/SynologyDrive/02_Trinity/Projet/github/plugins-booking/design_handoff_slashbooking/site"
DEST="/Users/seraphin/Library/CloudStorage/SynologyDrive/02_Trinity/Projet/github/slashbooking-site"
mkdir -p "$DEST"
cp "$HANDOFF/SlashBooking.html" "$DEST/index.html"
cp "$HANDOFF/sb-styles.css" "$DEST/sb-styles.css"
cp "$HANDOFF/sb-app.js" "$DEST/sb-app.js"
mkdir -p "$DEST/assets/logo" "$DEST/fonts" "$DEST/downloads"
cp -R "$HANDOFF/assets/logo/." "$DEST/assets/logo/"
cd "$DEST" && git init
```

- [ ] **Step 2: Create `.gitignore`**

```
.DS_Store
```

(Note: `downloads/slashbooking.zip` IS committed — it is the shipped artifact — so do NOT ignore it.)

- [ ] **Step 3: Sanity check the copy**

Run: `ls -R "$DEST" | head -40` and confirm `index.html`, `sb-styles.css`, `sb-app.js`, `assets/logo/*`, empty `fonts/` and `downloads/` exist.
Open `index.html` in a browser (or `node --check` is N/A for HTML): visually it should match the handoff (fonts still from Google CDN at this point — fixed in Task 2).

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore(site): scaffold slashbooking-site from the design handoff"
```

---

### Task 2: Self-host the fonts (RGPD)

**Files:** `fonts/*.woff2`, `sb-styles.css`, `index.html`

- [ ] **Step 1: Fetch the Google Fonts CSS with a modern-browser UA**

A browser UA makes Google return `woff2` URLs. From the repo root:

```bash
curl -s -H "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36" \
  "https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Manrope:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" \
  -o /tmp/gf.css
head -40 /tmp/gf.css
```

You should see `@font-face` blocks with `src: url(https://fonts.gstatic.com/....woff2)`. (There are multiple per family — one per unicode-range subset. Keep `latin` and `latin-ext` subsets; you may drop cyrillic/greek/vietnamese to reduce files.)

- [ ] **Step 2: Download the woff2 files into `fonts/` and build a local `@font-face` block**

For each `latin`/`latin-ext` woff2 URL in `/tmp/gf.css`, download it into `fonts/` with a stable name like `space-grotesk-600.woff2`, `manrope-400.woff2`, `jetbrains-mono-500.woff2` (family-weight; if you keep latin-ext separately, suffix `-ext`). Example for one file:

```bash
curl -s "https://fonts.gstatic.com/s/spacegrotesk/.../xxxx.woff2" -o fonts/space-grotesk-600.woff2
```

Then write the corresponding `@font-face` rules at the TOP of `sb-styles.css`, e.g.:

```css
@font-face {
  font-family: 'Space Grotesk';
  font-style: normal;
  font-weight: 600;
  font-display: swap;
  src: url('fonts/space-grotesk-600.woff2') format('woff2');
}
/* ...repeat for each weight/family you downloaded:
   Space Grotesk 400/500/600/700, Manrope 400/500/600/700, JetBrains Mono 400/500/600 ... */
```

Keep `font-display: swap`. Preserve the original `unicode-range` from `/tmp/gf.css` on each rule if you keep both `latin` and `latin-ext` (so the browser picks the right file).

- [ ] **Step 3: Remove the Google CDN references from `index.html`**

In `index.html` `<head>`, DELETE these three lines (the preconnects + the css2 stylesheet link):

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:...&display=swap" rel="stylesheet">
```

Leave the `<link rel="stylesheet" href="sb-styles.css">` (now the @font-face lives there).

- [ ] **Step 4: Verify no Google Fonts network calls remain**

```bash
grep -rn "fonts.googleapis.com\|fonts.gstatic.com" index.html sb-styles.css
```
Expected: NO matches (all font URLs are now local `fonts/*.woff2`).
Then open `index.html` in a browser with devtools Network: confirm fonts load from `fonts/` and there are zero requests to `fonts.gstatic.com`; the page still renders in Space Grotesk / Manrope / JetBrains Mono.

- [ ] **Step 5: Commit**

```bash
git add fonts sb-styles.css index.html
git commit -m "feat(site): self-host fonts (RGPD), drop Google Fonts CDN"
```

---

### Task 3: Wire the CTAs

**Files:** `index.html` (and `sb-app.js` only if needed)

Read `index.html` first to find the exact CTA elements (there are "Télécharger gratuitement" / "Passer à Pro" buttons in the nav, the hero, and the `#tarifs` Free/Pro cards).

- [ ] **Step 1: Point all "Télécharger gratuitement" buttons at the plugin ZIP**

Change every "Télécharger gratuitement" anchor (nav, hero, and the **Free** pricing card) so its `href` is `downloads/slashbooking.zip` and it carries a `download` attribute:

```html
<a class="btn btn-primary" href="downloads/slashbooking.zip" download>Télécharger gratuitement</a>
```
(Keep each button's existing classes.)

- [ ] **Step 2: Add a short "Installation" sub-section**

Right after the `#tarifs` section (or inside the Free card area), add a small block explaining the 3 steps. Give it `id="installation"`:

```html
<section id="installation" class="section">
  <div class="container">
    <p class="eyebrow">/ Installation</p>
    <h2>Installer en 2 minutes</h2>
    <ol class="sb-install-steps">
      <li>Téléchargez le ZipPlugin SlashBooking.</li>
      <li>Dans WordPress&nbsp;: <strong>Extensions → Ajouter → Téléverser une extension</strong>, choisissez le .zip, puis <strong>Installer</strong> et <strong>Activer</strong>.</li>
      <li>Collez le shortcode <code>[slashbooking]</code> dans une page. C'est prêt&nbsp;! La version Pro se débloque avec une clé de licence.</li>
    </ol>
  </div>
</section>
```
Fix the typo "ZipPlugin" → "ZIP du plugin" when you write it. Match the surrounding markup conventions of the handoff (`section`/`container`/`eyebrow` classes already exist in `sb-styles.css`). Add minimal CSS for `.sb-install-steps` to `sb-styles.css` if the default list styling looks off (optional).

- [ ] **Step 3: Set the Pro CTA to a "Bientôt disponible" state**

- Nav + hero "Passer à Pro": leave them scrolling to `#tarifs` (`href="#tarifs"`).
- In the **Pro** pricing card, replace its CTA button with a disabled "Bientôt disponible" pill and a small note. Example:

```html
<span class="btn btn-dark sb-soon" aria-disabled="true">Bientôt disponible</span>
<p class="sb-soon-note muted">Paiement en ligne très bientôt.</p>
```
Add to `sb-styles.css`:
```css
.sb-soon { opacity: .6; cursor: not-allowed; pointer-events: none; }
.sb-soon-note { font-size: 13px; margin-top: 8px; }
```

- [ ] **Step 4: Verify**

```bash
grep -n "downloads/slashbooking.zip\|Bientôt disponible\|id=\"installation\"" index.html
```
Expected: the download links, the Pro "Bientôt" button, and the installation section are present. Open in a browser: clicking "Télécharger gratuitement" downloads the ZIP (once Task 4 places it); the Pro button is visibly disabled; nav/hero "Passer à Pro" scroll to pricing; the pricing toggle / FAQ / burger still work (sb-app.js untouched).

- [ ] **Step 5: Commit**

```bash
git add index.html sb-styles.css
git commit -m "feat(site): wire CTAs (free download + install section, Pro 'bientôt')"
```

---

### Task 4: Host the plugin ZIP

**Files:** `downloads/slashbooking.zip`

- [ ] **Step 1: Fetch the latest plugin release ZIP**

```bash
cd /Users/seraphin/Library/CloudStorage/SynologyDrive/02_Trinity/Projet/github/slashbooking-site
gh release download v1.2.0 --repo ArchSeraphin/slashbooking --pattern 'slashbooking-1.2.0.zip' --output downloads/slashbooking.zip
ls -la downloads/slashbooking.zip
```
(If `gh release download` is unavailable, get the asset URL with `gh release view v1.2.0 --repo ArchSeraphin/slashbooking --json assets` and `curl -L` it to `downloads/slashbooking.zip`.)

- [ ] **Step 2: Sanity check the ZIP**

```bash
unzip -l downloads/slashbooking.zip | head
```
Expected: it lists `slashbooking/slashbooking.php` etc. (a valid plugin ZIP).

- [ ] **Step 3: Commit**

```bash
git add downloads/slashbooking.zip
git commit -m "chore(site): host plugin ZIP for the free download (v1.2.0)"
```

---

### Task 5: README + deploy notes + final acceptance

**Files:** `README.md`

- [ ] **Step 1: Create `README.md`**

```markdown
# slashbooking-site

Static marketing one-pager for **slashbooking.fr** (productionized from the
design handoff). No backend, no build — plain HTML/CSS/JS.

## Files
- `index.html` — the one-pager
- `sb-styles.css` — styles (+ self-hosted `@font-face`)
- `sb-app.js` — interactions (nav, pricing toggle, FAQ, calendar demo, reveal)
- `fonts/` — self-hosted woff2 (RGPD; no Google Fonts CDN)
- `assets/logo/` — logo + favicon
- `downloads/slashbooking.zip` — the plugin (free download; Pro unlocked by license)

## Deploy (Plesk, slashbooking.fr root)
1. `slashbooking.fr` on the same Plesk subscription as the broker/dashboard.
2. Point the domain document root at this repo's contents (git clone or sync).
3. HTTPS via Let's Encrypt. No Node app — pure static.

## Refresh the free download on each plugin release
```
gh release download vX.Y.Z --repo ArchSeraphin/slashbooking \
  --pattern 'slashbooking-X.Y.Z.zip' --output downloads/slashbooking.zip
git add downloads/slashbooking.zip && git commit -m "chore(site): plugin ZIP vX.Y.Z"
```

## Pending
- "Passer à Pro" is a "Bientôt disponible" placeholder until the Stripe checkout
  (brique C) is wired in.
- Legal pages (mentions légales / CGV / privacy) to add.
```

- [ ] **Step 2: Final acceptance (manual)**

Open `index.html` in a browser and confirm:
- Renders identically to the handoff; fonts load from `fonts/` (devtools Network: zero `fonts.gstatic.com`); no console errors.
- "Télécharger gratuitement" downloads `slashbooking.zip`.
- "Passer à Pro" (nav/hero) scrolls to pricing; Pro card shows "Bientôt disponible".
- Installation section present.
- Interactive bits work: mobile burger (<1000px), pricing Mensuel/Annuel toggle, FAQ accordion, calendar demo, scroll reveal.
- Responsive at 1000 / 980 / 720 / 460px.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs(site): README + deploy and ZIP-refresh notes"
```

---

## Self-Review

**Spec coverage:** static one-pager from handoff (Task 1); self-hosted fonts/RGPD (Task 2); CTA wiring — free download + install section, Pro "bientôt", no capture (Task 3); hosted plugin ZIP (Task 4); deploy/refresh docs (Task 5). All spec sections covered. ✓

**Placeholder scan:** No "TBD/implement later". The font task can't list exact gstatic URLs (they are versioned/rotating) — it gives the concrete fetch command + the naming/`@font-face` pattern to apply to whatever URLs Google returns; this is inherent to fetching live assets, not a hand-wave. The CTA task references elements by their visible label rather than line numbers because the implementer reads `index.html` (a known handoff file) — concrete actions, not vague ones.

**Consistency:** `downloads/slashbooking.zip` path identical across Tasks 3/4/5 and the README. Font files under `fonts/` referenced consistently in `sb-styles.css` and verified in Task 2 Step 4. The "Bientôt disponible" string is the grep anchor in Task 3 Step 4.

**Note:** No automated tests (static site) — acceptance is the manual checklist (Task 5 Step 2). Repo `slashbooking-site` is created locally in Task 1; creating the GitHub repo + push is a controller/deploy step (like the dashboard), not in this plan.
