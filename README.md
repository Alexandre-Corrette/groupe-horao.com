# Mercure-IA — Backend light (formulaire de contact)

Backend Symfony 7.3 minimal qui sert la landing page et traite le formulaire
de contact de façon sécurisée. Aucune base de données : les demandes sont
envoyées par e-mail à `CONTACT_TO`.

## Installation

```bash
composer install
php -r "echo bin2hex(random_bytes(16)).PHP_EOL;"   # → coller dans APP_SECRET (.env.local)
```

Créer un `.env.local` (non commité) :

```dotenv
APP_SECRET=<votre-secret>
MAILER_DSN=smtp://user:pass@smtp.votre-provider.com:587
CONTACT_TO=contact@groupe-horao.com
CONTACT_FROM=no-reply@groupe-horao.com
```

Lancer en dev :

```bash
symfony serve            # ou : php -S localhost:8000 -t public
```

- `GET /` → landing page (jeton CSRF + time-trap injectés par Twig)
- `POST /contact` → traitement du formulaire, réponses JSON

## Sécurité embarquée

| Protection | Implémentation |
|---|---|
| CSRF | Jeton `contact` généré par Twig, vérifié dans le contrôleur (403 sinon) |
| Honeypot | Champ `website` hors écran ; s'il est rempli → **faux succès** (le bot n'apprend rien) |
| Time-trap | `form_ts` horodaté **côté serveur** au rendu ; rejet si < 3 s ou > 1 h |
| Rate limiting | `contact_form` : 3 envois / heure / IP, fenêtre glissante (429 au-delà) |
| Validation | Contraintes serveur sur le DTO (`ContactMessage`) — les `maxlength` HTML ne sont qu'un confort |
| Anti-moissonnage | `CONTACT_TO` n'apparaît jamais dans le HTML public |
| Injection d'en-têtes | Adresses/sujet encodés par symfony/mime (pas de CRLF possible) |
| Fuite d'infos | Les exceptions mailer sont loggées, jamais renvoyées au client |
| XSS | Autoescape Twig actif ; e-mail de notification en texte brut |

## Tests manuels rapides

```bash
# La page doit poser un cookie de session et contenir _token + form_ts
curl -c /tmp/cj -s http://localhost:8000/ | grep -o 'name="_token" value="[^"]*"'

# Envoi sans CSRF → 403
curl -b /tmp/cj -s -o /dev/null -w '%{http_code}\n' -X POST http://localhost:8000/contact -d 'nom=Test'

# Honeypot rempli → 200 {"ok":true} (faux succès) mais aucun mail envoyé (voir logs)
# 4 envois valides dans l'heure → le 4ᵉ répond 429
```

## Check-list mise en production

- [ ] `APP_ENV=prod`, `APP_DEBUG=0`, secret fort dans les variables d'environnement
- [ ] HTTPS obligatoire (le cookie de session passe en `Secure` automatiquement)
- [ ] `MAILER_DSN` réel + SPF/DKIM sur groupe-horao.com pour la délivrabilité
- [ ] Derrière un reverse proxy : configurer `trusted_proxies`/`trusted_headers`
      (framework.yaml) sinon `getClientIp()` renverra l'IP du proxy et le
      rate limiting par IP sera inopérant
- [ ] En-têtes de sécurité au niveau serveur web : CSP, X-Content-Type-Options,
      Referrer-Policy, X-Frame-Options
- [ ] Si le spam persiste : ajouter Cloudflare Turnstile ou Friendly Captcha
      (RGPD-friendly) — vérification du jeton dans `SpamGuard` via HttpClient
- [ ] Si plusieurs instances : remplacer le cache local du rate limiter par un
      storage partagé (Redis)

## Structure

```
config/            framework (CSRF, session, rate limiter), mailer, twig, services
public/index.php   front controller
src/Controller/    ContactController (GET / et POST /contact)
src/Dto/           ContactMessage (contraintes Validator)
src/Service/       SpamGuard (honeypot + time-trap)
templates/         landing.html.twig (landing complète + JS d'envoi AJAX)
```
