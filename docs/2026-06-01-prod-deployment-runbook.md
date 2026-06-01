# SlashBooking — Runbook mise en prod & tests (2026-06-01)

Tout ce qu'il reste à déployer et tester. **Ordre conseillé : 1 → 2 → 3 → 4 → 5.**
Le broker est la fondation (store de licences) ; le dashboard et le plugin en dépendent.

## Vue d'ensemble

| # | Composant | Repo | Cible | État |
|---|---|---|---|---|
| 1 | Broker (SQLite) | `ArchSeraphin/slashbooking-broker` (v1.0.4) | `broker.slashbox.fr` | déjà déployé en 1.0.x → **mettre à jour** |
| 2 | Dashboard licences | `ArchSeraphin/slashbooking-dashboard` | `dashboard.slashbooking.fr` | **nouveau** |
| 3 | Site vitrine | `ArchSeraphin/slashbooking-site` | `slashbooking.fr` (racine) | **nouveau** |
| 4 | Plugin v1.2.0 | `ArchSeraphin/slashbooking` | clients WordPress | **déjà released** (rien à faire serveur) |
| 5 | Tests bout-en-bout | — | — | après 1–3 |

**Chemin du store partagé (à réutiliser partout) :**
`/var/www/vhosts/slashbox.fr/private/licenses.sqlite`

**Pré-requis Plesk :** `slashbooking.fr` ajouté à la **même souscription** que `slashbox.fr`
(même utilisateur système → le dashboard et le broker partagent le fichier SQLite sans bidouille de permissions).

---

## 1. Broker → SQLite (broker.slashbox.fr)

Passe le broker du `licenses.json` à SQLite, en conservant ta licence actuelle.

```bash
cd /var/www/vhosts/slashbox.fr/broker.slashbox.fr/httpdocs
git pull origin main
npm ci                                    # recompile better-sqlite3 (natif, Node 20)
mkdir -p /var/www/vhosts/slashbox.fr/private
# importe l'existant (adapte le chemin de ton licenses.json actuel) :
node scripts/migrate-json-to-sqlite.js ./licenses.json /var/www/vhosts/slashbox.fr/private/licenses.sqlite
```

Puis **Plesk → broker.slashbox.fr → Node.js → variables d'environnement** :
- **supprimer** `LICENSES_FILE`
- **ajouter** `LICENSES_DB=/var/www/vhosts/slashbox.fr/private/licenses.sqlite`

→ **Restart App**.

**Vérif :**
```bash
curl -s https://broker.slashbox.fr/health      # {"ok":true}
curl -s -XPOST https://broker.slashbox.fr/license/validate \
  -H 'content-type: application/json' \
  -d '{"license":"TA_CLE_EXISTANTE","site":"https://a2c.voilavoila.tv"}'   # {"valid":true,...}
```
Si `valid:true` → la migration a réussi. (Garde `licenses.json` en backup jusqu'à confirmation.)

---

## 2. Dashboard licences (dashboard.slashbooking.fr)

1. Crée le sous-domaine **`dashboard.slashbooking.fr`** (sous la souscription `slashbox.fr`) + cert Let's Encrypt.
2. Dans son doc root :
```bash
git clone https://github.com/ArchSeraphin/slashbooking-dashboard.git .
npm ci --omit=dev
node scripts/hash-password.js 'TON_MOT_DE_PASSE_ADMIN'   # copie le hash affiché
openssl rand -hex 32                                       # pour DASH_SESSION_SECRET
```
3. **Plesk → dashboard.slashbooking.fr → Node.js** :
   - **Application Startup File** : `passenger_app.js`
   - **Application Mode** : `production`
   - **Variables d'env** :
     - `LICENSES_DB=/var/www/vhosts/slashbox.fr/private/licenses.sqlite` *(LE MÊME que le broker)*
     - `DASH_USER=admin`
     - `DASH_PASS_HASH=<hash de l'étape 2>`
     - `DASH_SESSION_SECRET=<openssl rand -hex 32>`
     - `BASE_PATH=/`
     - `TRUST_PROXY=1`   *(NE PAS mettre 0 en prod : ça retirerait le flag Secure du cookie de session)*

→ **Restart App**.

**Vérif :** ouvrir `https://dashboard.slashbooking.fr/` → redirige vers `/login` → se connecter → **créer une licence** (note la clé `SB-XXXX-XXXX-XXXX`) → vérifier que le broker la valide :
```bash
curl -s -XPOST https://broker.slashbox.fr/license/validate \
  -H 'content-type: application/json' \
  -d '{"license":"SB-NOUVELLE-CLE","site":"https://un-site-autorise.com"}'   # {"valid":true,...}
```

---

## 3. Site vitrine (slashbooking.fr racine)

Site **statique** (aucun back-end).

1. Configure `slashbooking.fr` (racine) en hébergement statique (pas d'app Node) + cert Let's Encrypt.
2. Dans son doc root :
```bash
git clone https://github.com/ArchSeraphin/slashbooking-site.git .
```
   (ou synchronise le contenu du repo dans le doc root).

**Vérif (navigateur) :**
- La page charge, rendu identique au handoff.
- DevTools → Network : **aucun appel à `fonts.gstatic.com`** (fonts servies depuis `/fonts/`).
- « Télécharger gratuitement » → télécharge `downloads/slashbooking.zip`.
- « Passer à Pro » → section Tarifs ; la carte Pro affiche **« Bientôt disponible »**.
- Burger mobile, toggle Mensuel/Annuel, accordéon FAQ : OK.

> 🔁 À chaque nouvelle release du plugin, rafraîchir le ZIP (voir le README du repo site).

---

## 4. Plugin v1.2.0 (clients WordPress) — rien à faire côté serveur

Déjà **released** (`v1.2.0`, Free/Paid). Les clients le reçoivent via la mise à jour 1-clic.
Rappel : depuis la **1.1.1**, les migrations de schéma tournent au boot → **plus besoin de
désactiver/réactiver** après une mise à jour. (Le site sert ce même ZIP en téléchargement gratuit.)

---

## 5. Tests bout-en-bout (après 1–3)

Sur un **WordPress de test** (ex. a2c.voilavoila.tv) avec le plugin v1.2.0 :

**A. Licence + connexion Google (chemin payant)**
1. Dans le dashboard, crée une licence pour le site de test (ou sans restriction de site).
2. WP → réglages SlashBooking → colle la **clé de licence** → statut « valide ».
3. Clique **« Connecter Google Calendar »** → consent (2 scopes calendar) → retour → **compte connecté**.
4. Vérifie qu'un RDV de test se synchronise dans Google Agenda.

**B. Gating Free (sans licence ou licence retirée)**
1. Sur une install **sans** licence valide :
   - « Connecter Google Calendar » → bloqué (licence requise).
   - Section perso des e-mails → verrouillée (encart « version payante »).
   - Pas d'e-mail de rappel J-1 envoyé.
   - Les e-mails transactionnels (confirmation/attente/refus) **partent quand même** (modèles par défaut). ✅

**C. Révocation (dashboard → broker)**
1. Dans le dashboard, **révoque** la licence du site de test.
2. Le broker doit la refuser (`/license/validate` → `valid:false`) ; le plugin repasse en mode Free
   (données conservées, synchro en pause).

**D. Site vitrine**
- `slashbooking.fr` accessible, download du plugin OK, CTA Pro « bientôt ».

---

## Rollback rapide

- **Broker** : stateless. Restaure `LICENSES_FILE` + l'ancien code (`git checkout <ancien tag>`), Restart. (Le `licenses.json` de backup sert de filet.)
- **Dashboard / Site** : nouveaux sous-domaines isolés → en cas de souci, désactive le sous-domaine, ça n'impacte ni le broker ni les sites clients.
- **Plugin** : réinstaller le ZIP de la version précédente depuis les releases GitHub.

---

## Ce qui reste APRÈS cette mise en prod

- **Brique C — paiement Stripe** : checkout → webhook → émission auto de licence (dans le store SQLite) + envoi de la clé par e-mail, puis rebrancher le CTA « Passer à Pro » du site sur le checkout (remplacer l'état « Bientôt disponible »).
- **Pages légales** du site (`mentions légales / CGV / confidentialité`) — liens footer actuellement en `#`.
