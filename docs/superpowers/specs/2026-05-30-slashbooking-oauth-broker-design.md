# SlashBooking — OAuth broker B1 (stateless, gated licence)

**Date :** 2026-05-30
**Statut :** conception validée (B1 stateless + licence + broker uniquement)
**Repos concernés :**
- `plugins-booking` (plugin WordPress — ce repo)
- `slashbooking-broker` (service Node.js — https://github.com/ArchSeraphin/slashbooking-broker, vide à ce jour)

---

## 1. Problème & objectif

Aujourd'hui chaque client doit créer son propre projet Google Cloud, son OAuth client, et coller `client_id`/`client_secret` dans le plugin (`sb_google_client_id`, `sb_google_client_secret` en options WP). C'est la friction n°1 et ça empêche la commercialisation : on ne peut pas « cacher » un secret en le distribuant dans le code.

**Objectif :** connexion Google **en 1 clic**, le plugin ne contenant **aucun secret ni client_id Google**. L'unique OAuth client (validé par Google) vit côté broker, sur slashbox.fr.

**Contrainte fondamentale :** un OAuth client « Web application » exige le `client_secret` pour l'échange *et* le refresh, et un `redirect_uri` exact pré-enregistré. On ne peut pas enregistrer le domaine de chaque client → **un seul `redirect_uri` = le broker**, qui relaie ensuite vers le bon site. En revanche, les appels Calendar API (lecture/écriture d'events) ne nécessitent que l'`access_token` → ils restent **directs WP → Google**.

## 2. Décisions verrouillées

| Décision | Choix |
|---|---|
| Architecture | **B1** — broker de tokens (pas de proxy complet B2) |
| Statefulness broker | **Stateless** : aucun token stocké de façon persistante ; le `refresh_token` reste chiffré (sodium) sur le WP du client |
| Auth install → broker | **Clé de licence** (gate commercial). Pas de `install_id` séparé en v1 |
| Repli config manuelle | **Aucun** — broker uniquement |
| Stack broker | **Node.js / Express** (repo dédié `slashbooking-broker`) |
| Scopes Google | **Inchangés** : `calendar.events` + `calendar.readonly` (préserve la validation Google) |
| Base URL broker | `https://slashbox.fr/slashbooking/api` (configurable, à confirmer au déploiement) |

## 3. Architecture & flux

```
CONNEXION (déclenchée par l'admin, 1 clic)
 (1) WP oauthStart  ──POST /oauth/start {license, return, n}──▶ broker
                    ◀── {auth_url}  (URL Google, state signé broker) ──
 (2) navigateur ──redirect──▶ Google (écran de consentement)
 (3) Google ──redirect ?code&state──▶ broker /oauth/callback
                    (broker ajoute le secret, échange code→tokens,
                     récupère email+calendar_id via calendarList/primary)
 (4) broker ──redirect ?sb_claim&n──▶ WP /admin/google/oauth/callback
 (5) WP ──POST /oauth/claim {license, sb_claim}──▶ broker
                    ◀── {refresh_token, access_token, expires_in, scope, email, calendar_id} ──
 (6) WP chiffre (sodium) + stocke via GoogleAccountRepository

USAGE QUOTIDIEN
 WP ──access_token (Bearer)──▶ Google Calendar API      (DIRECT, broker hors jeu)
 access expiré: WP ──POST /oauth/refresh {license, refresh_token}──▶ broker
                    ◀── {access_token, expires_in} ──

Le broker ne stocke RIEN de persistant (sauf la table de licences).
Les tokens ne transitent qu'en mémoire, le temps de l'échange/refresh.
La licence ne circule QUE de serveur à serveur (jamais dans une URL navigateur).
```

**Pourquoi `/oauth/start` est un POST serveur-à-serveur** (et non un redirect navigateur direct vers le broker) : la licence ne doit jamais apparaître dans l'historique navigateur ni dans un `Referer`. WP appelle le broker en backend, récupère l'`auth_url` Google (avec un `state` signé par le broker), et le navigateur va **directement** chez Google.

**Anti-CSRF / anti-injection de compte :** le paramètre `n` est un nonce signé HMAC émis côté WP (réutilisation de la classe `OAuthState` existante, liée au `user_id`). Il fait l'aller-retour start → broker → callback et est vérifié au retour. Seul l'admin ayant initié le flux possède le nonce valide → impossible d'injecter le compte Google d'un attaquant.

## 4. Le broker — `slashbooking-broker` (Node.js / Express)

### 4.1 Arborescence cible
```
slashbooking-broker/
  package.json
  .env.example
  README.md
  src/
    server.js              # app Express, monte le router sur BASE_PATH
    config.js              # chargement + validation des env vars (fail-fast)
    lib/google.js          # exchangeCode(), refreshToken(), fetchPrimaryCalendar()
    lib/state.js           # signState() / verifyState() (HMAC sha256 + exp)
    lib/claims.js          # store claim en mémoire, one-time, TTL
    lib/licenses.js        # validateLicense() (source pluggable : JSON file v1)
    lib/logger.js          # pino + redaction tokens/secrets
    routes/oauth.js        # /oauth/start, /oauth/callback, /oauth/claim, /oauth/refresh
    routes/license.js      # /license/validate, /health
    middleware/rateLimit.js
    middleware/errors.js
  test/
    oauth.test.js          # supertest + nock (Google mocké)
    license.test.js
    state.test.js
```

### 4.2 Variables d'environnement (`.env.example`)
```
PORT=8080
BASE_PATH=/slashbooking/api
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=https://slashbox.fr/slashbooking/api/oauth/callback
STATE_KEY=<32+ bytes aléatoires base64>      # signe les states
CLAIM_TTL_SECONDS=60
LICENSES_FILE=./licenses.json                # v1 : source de vérité des licences
ALLOWED_RETURN_SCHEME=https                   # 'https' en prod, 'http,https' en dev
```
Le `GOOGLE_REDIRECT_URI` doit correspondre **exactement** à l'URI enregistré dans la console Google de l'app validée.

### 4.3 Endpoints

| Méthode + route | Auth | Entrée | Sortie / effet |
|---|---|---|---|
| `POST {BASE}/oauth/start` | licence | `{license, return, n}` | valide licence + `return` (URL https) → `state = sign({return, n, lh, exp})` → `{auth_url}` (URL Google : `client_id`, `redirect_uri`, `scope=calendar.events calendar.readonly`, `access_type=offline`, `prompt=consent`, `include_granted_scopes=true`, `state`) |
| `GET {BASE}/oauth/callback` | (Google) | `?code&state` (ou `?error`) | vérifie `state` (sig + exp) → `exchangeCode` → `fetchPrimaryCalendar` (email+calendar_id) → crée `claim` (random 32B, TTL `CLAIM_TTL_SECONDS`, lié à `lh`) → **redirect 302** vers `state.return` avec `?sb_claim=…&n=…`. Sur erreur Google → redirect `return?sb_error=…` |
| `POST {BASE}/oauth/claim` | licence | `{license, claim}` | valide licence ; vérifie claim (existe, non expiré, `lh` correspond) ; **le détruit** (one-time) → `{refresh_token, access_token, expires_in, scope, email, calendar_id}` |
| `POST {BASE}/oauth/refresh` | licence | `{license, refresh_token}` | valide licence → refresh Google → `{access_token, expires_in}`. **Ne stocke rien.** Si Google répond `invalid_grant` → `401 {error:"token_revoked"}` |
| `POST {BASE}/license/validate` | — | `{license, site}` | `{valid, plan, expires}` (option : bind `site` à la licence au 1er usage) |
| `GET {BASE}/health` | — | — | `{ok:true}` (monitoring) |

> `lh` = hash de la licence (jamais la licence en clair dans le state).

### 4.4 Détails d'implémentation broker
- **`lib/google.js`** : appels au token endpoint `https://oauth2.googleapis.com/token` (POST form) ; `fetchPrimaryCalendar` = `GET https://www.googleapis.com/calendar/v3/users/me/calendarList/primary` (l'`id` retourné = adresse e-mail du compte = `calendar_id` par défaut).
- **`lib/state.js`** : `base64url(JSON) + "." + HMAC_sha256(payload, STATE_KEY)`, `exp` ~600 s, comparaison `crypto.timingSafeEqual`.
- **`lib/claims.js`** : `Map` en mémoire `{claim → {tokens, lh, exp}}` + balayage TTL. Mono-instance OK ; si l'app passe multi-instance plus tard → remplacer par Redis (noté).
- **`lib/licenses.js`** : v1 = fichier JSON `[{key, plan, expires, sites?}]` chargé/relu ; interface `validate(key, site)` pour brancher une vraie BDD plus tard.
- **Sécurité transverse** : `helmet`, limites de taille de body, `express-rate-limit` (par IP + plus strict par licence), HTTPS only (TLS terminé par Plesk/reverse-proxy), logs **sans** tokens (redaction pino), pas de CORS (serveur-à-serveur + redirects), validation stricte de `return` (schéma autorisé) pour éviter l'open-redirect.
- **Tests** : `supertest` + `nock` pour mocker Google ; couvrir nominal, licence invalide, state expiré/altéré, claim one-time (2e claim échoue), refresh `invalid_grant`, open-redirect refusé.

## 5. Le plugin — `plugins-booking`

### 5.1 Nouveaux fichiers
- `src/Google/BrokerClient.php` — client HTTP du broker :
  - `startUrl(string $returnUrl, string $n): string` → POST `/oauth/start`, renvoie `auth_url`.
  - `claim(string $claimCode): array` → POST `/oauth/claim`.
  - `refresh(string $refreshToken): array` → POST `/oauth/refresh`.
  - `validateLicense(string $siteUrl): array` → POST `/license/validate`.
  - Lit la licence (`sb_license_key`) et la base broker (`SB_BROKER_URL`, filtrable via `sb_broker_url`). Utilise `wp_remote_post`.
- `src/Google/Exceptions/BrokerUnavailable.php` — erreur réseau / 5xx → **retry, garder le compte connecté**.
- `src/Google/Exceptions/TokenRevoked.php` — `invalid_grant` (refresh_token mort) → **marquer « reconnexion requise »** (ne pas effacer les données).
- `tests/.../FakeBrokerClient.php` — double de test (comme `FakeCalendarGateway`).

### 5.2 Fichiers modifiés
- `src/Http/AdminGoogleSettingsController.php` → gère désormais la **licence** :
  - `GET` → `{has_license, license_status, plan, expires}` (jamais la licence en clair).
  - `POST` → enregistre `sb_license_key` (sanitize), valide via broker, renvoie le statut.
- `src/Http/AdminGoogleController.php` :
  - `oauthStart` → exige une licence valide ; `n = OAuthState->issue(userId)` ; `auth_url = BrokerClient->startUrl(callbackUrl, n)`.
  - `oauthCallback` (reste `__return_true`) → vérifie `n` via `OAuthState->verify` ; lit `sb_claim` ; `tokens = BrokerClient->claim(...)` ; construit + chiffre + stocke `GoogleAccount` ; redirige.
- `src/Google/GoogleClientBuilder.php` → **ne pose plus** `client_id`/`client_secret` sur `Google\Client` (seulement l'`access_token` pour les appels Bearer) ; `refresh()` appelle `BrokerClient->refresh()` ; gère `BrokerUnavailable` (rethrow retryable, conserve le compte) et `TokenRevoked` (marque reconnexion requise).
- `src/Google/OAuthClient.php` → **supprimé** (échange/refresh/authUrl désormais côté broker). `OAuthState` → **conservé** (sert de nonce `n`).
- React `src/Admin/react-app/src/` :
  - `GoogleSetupWizard.jsx` → **supprimé**.
  - `GooglePage.jsx` → remplace le formulaire client_id/secret par : champ **Clé de licence** + statut licence + bouton **Connecter Google Calendar** (actif si licence valide). Le connect appelle `oauthStart` et redirige vers `auth_url`.
- `src/Plugin.php` (ou un fichier de constantes) → définit `SB_BROKER_URL` par défaut + filtre `sb_broker_url`.

### 5.3 Licence côté plugin
- Option `sb_license_key`. Connexion Google bloquée tant que la licence est invalide.
- Cron quotidien de re-validation (`/license/validate`). Si révoquée → la synchro s'arrête, **les données restent intactes**, notice admin.

### 5.4 Robustesse « broker uniquement »
Le broker étant l'unique chemin, le plugin doit dégrader proprement :
- Échec réseau/5xx au refresh → **retry + backoff**, garder le compte connecté, ne **jamais** effacer les tokens, journaliser. La synchro reprend dès que le broker répond (perte = retard de sync, pas de données).
- `invalid_grant` (révocation Google définitive) → marquer « reconnexion requise » + notice admin.

## 6. Sécurité

- `refresh_token`/`access_token` chiffrés sodium côté WP (déjà le cas, inchangé).
- Anti-CSRF sur le callback OAuth : nonce `n` signé HMAC (`OAuthState`), vérifié au retour.
- `claim_code` aléatoire, **one-time**, TTL court → **aucun token dans une URL navigateur**.
- Licence transmise **uniquement** serveur-à-serveur (POST), jamais dans une URL navigateur.
- Broker : aucun stockage persistant de tokens, logs masqués, rate-limiting, HTTPS strict, validation anti-open-redirect du `return`, `client_secret` en variable d'env (ne quitte jamais le serveur).
- Scopes Google inchangés → validation Google préservée.

> Cette section sera **enrichie** par les findings de l'audit de sécurité en cours (workflow `slashbooking-security-audit`) avant le démarrage de l'implémentation.

## 7. Migration (impact réel)

Un `refresh_token` n'est rafraîchissable que par le **client OAuth qui l'a émis**. Les connexions existantes (créées avec l'ancien projet GCP du client) **casseront** à la mise à jour vers la version broker.

- À l'activation/upgrade : suppression des options `sb_google_client_id` / `sb_google_client_secret`.
- Si un `GoogleAccount` existe : afficher une **notice admin « Reconnectez Google Calendar (1 clic) »**.
- Pour `a2c.voilavoila.tv` (site de Nicolas) : se générer une licence et reconnecter une fois.
- Version : feature majeure → bump **mineur** (ex. `v1.1.0`).

## 8. Tests

**Plugin** (PHPUnit, pas d'appel réseau réel) :
- `BrokerClient` : `startUrl`/`claim`/`refresh`/`validateLicense` — cas nominal + erreurs (mocks `wp_remote_*`).
- `GoogleClientBuilder` : refresh via broker, gestion `BrokerUnavailable` (retry/conserve) et `TokenRevoked`.
- Gating licence (connexion bloquée sans licence valide).
- `oauthCallback` : vérification du nonce `n` (rejet si invalide).
- `FakeBrokerClient` pour les tests d'intégration de la sync.

**Broker** (Node, `supertest` + `nock`) : voir §4.4.

## 9. Hors scope v1

- Boutique / génération / facturation des licences (v1 = validation contre `licenses.json`).
- Multi-commercial (déjà prévu V2).
- Stockage Redis des claims (mono-instance suffit en v1).

## 10. Livrables

1. **Broker** `slashbooking-broker` : app Express complète + tests + README de déploiement (Plesk/Node).
2. **Plugin** `plugins-booking` : refactor OAuth → broker, écran licence, migration + notice, tests, doc (`docs/GOOGLE_SETUP.md`, `readme.txt`, `CHANGELOG`), rebuild ZIP.
