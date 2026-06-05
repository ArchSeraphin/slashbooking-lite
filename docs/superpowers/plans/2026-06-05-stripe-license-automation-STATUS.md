# Brique C — Stripe license automation : ÉTAT D'AVANCEMENT

**Dernière mise à jour :** 2026-06-05 (fin de session)
**Spec :** `docs/superpowers/specs/2026-06-05-stripe-license-automation-design.md`
**Plan :** `docs/superpowers/plans/2026-06-05-stripe-license-automation.md`

## ✅ Fait — code terminé, revu, mergé sur main (T1–T9)

| Repo | État | Référence |
|---|---|---|
| `slashbooking-dashboard` | `main` @ `eec8184` (13 commits brique C), poussé | webhook + mailer + migration + tests (46/46 verts) |
| `slashbooking-broker` | `main` @ `111c00f`, poussé | miroir schéma (colonnes + index unique), tests 68/68 verts |
| Branches `feat/stripe-license-automation` | supprimées (local + remote) après merge | — |

Ce qui est en place :
- `POST /stripe/webhook` (public, signé) : `checkout.session.completed` → licence auto (clé `SB-…`, plan `pro-monthly`/`pro-yearly`, `max_sites=1`, `expires` = fin de période + 3 j de grâce, notes `stripe:auto`) + email de la clé via Brevo ; `invoice.paid` (cycle) → prolongation ; annulation → rien (expiration naturelle)
- Idempotence : index unique partiel sur `stripe_subscription_id` (fixe une race condition de doublon trouvée en revue) + retry email via `email_sent_at`
- SMTP : `requireTLS`, fast-fail si identifiants absents, transport injectable
- **Feature flag : tout est inerte tant que `STRIPE_WEBHOOK_SECRET` n'est pas posé dans Plesk** → on peut `git pull` en prod sans risque
- Docs : section « Stripe license automation (brique C) » dans `DEPLOY.md` + variables dans `.env.example`

Contexte prod (réglé plus tôt dans la journée) :
- Le dashboard tourne sur `dashboard.slashbooking.fr`, hébergé DANS la souscription Plesk de `slashbox.fr` (même utilisateur système que le broker — ne pas re-séparer)
- Debug Plesk : log Passenger = `/var/log/passenger/passenger.log` (chercher l'Error ID de la page 500) ; variables livrées = lignes `SetEnv` dans `/var/www/vhosts/system/dashboard.slashbooking.fr/conf/httpd.conf` ; tester en `sudo -u <user vhost>`, jamais en root

## ⏳ Reste à faire

### T10 — Config Stripe (mode test) + Brevo [MANUEL Nicolas — prochaine étape]

| Où | Quoi | Valeurs à récupérer |
|---|---|---|
| Brevo | Clé SMTP + authentifier `slashbooking.fr` (DKIM/SPF dans le DNS Plesk, attendre « verified ») | `SMTP_USER`, `SMTP_KEY` |
| Stripe (test) | Produit « SlashBooking Pro », prix 5,99 €/mois + 60 €/an | 2 price IDs (`STRIPE_PRICE_MONTHLY`/`_YEARLY`) |
| Stripe (test) | 2 Payment Links, success URL `https://slashbooking.fr/merci/` | 2 URLs (`LINK_MONTH`, `LINK_YEAR`) |
| Stripe (test) | Webhook `https://dashboard.slashbooking.fr/stripe/webhook`, events `checkout.session.completed` + `invoice.paid` | `STRIPE_WEBHOOK_SECRET` (`whsec_…`) |
| Stripe (test) | Portail client no-code + clé API | `STRIPE_PORTAL_URL`, `STRIPE_SECRET_KEY` (`sk_test_…`) |

### T11 — Site marketing (bloqué par T10 : il faut les 2 Payment Links)

Repo `slashbooking-site` — voir plan Task 11 pour le code exact :
- `index.html` ~l.362 : remplacer le span `sb-soon` « Bientôt disponible » par `<a id="proCta">` (href = lien mensuel)
- `sb-app.js` toggle pricing (~l.116) : swap du href mensuel/annuel
- Créer `merci/index.html` (page de confirmation)

### T12 — Déploiement + E2E + bascule live [MANUEL]

1. Serveur : `git pull` dashboard ET broker, NPM Install (UI Plesk), poser les variables T10 (⚠️ pas d'espaces/guillemets parasites dans les champs Plesk), Restart App
2. Déployer le site (`git pull`)
3. E2E mode test : payer avec `4242 4242 4242 4242` → webhook 200 (dashboard Stripe), licence visible dans le dashboard, email reçu, broker valide la clé, « Resend » du webhook → pas de doublon
4. Bascule live : recréer produit/prix/links/webhook en mode live, swap des valeurs Plesk + des 2 links dans le site, un paiement réel remboursé pour valider
5. Marquer la brique C faite dans `docs/2026-06-01-prod-deployment-runbook.md` (⚠️ ce fichier a des modifs locales non commitées)

## 🔁 Pour reprendre

Donner à Claude les valeurs du tableau T10 et dire « lance T11 » — le plan contient le code exact de chaque étape restante.
