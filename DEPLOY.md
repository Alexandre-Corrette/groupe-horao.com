# Déploiement — groupe-horao.com sur OVH mutualisé

Architecture : GitHub Actions construit l'application (vendor inclus), l'envoie
en SSH/rsync sur l'hébergement, exécute les migrations, puis bascule un symlink
`current` — déploiement atomique, rollback possible, 3 releases conservées.

```
~/sites/groupe-horao.com/
├── current -> releases/ab12cd34ef56      ← racine du domaine : current/public
├── releases/
│   ├── ab12cd34ef56/                     ← une release par commit déployé
│   └── …
└── shared/
    └── .env.local                        ← secrets de prod (créé une fois, jamais commité)
```

## 0. Prérequis côté projet (une fois, en local)

```bash
composer require symfony/orm-pack doctrine/doctrine-migrations-bundle
# Flex enregistre les bundles, crée compose.yaml (PostgreSQL local) — nos
# config/packages/doctrine*.yaml et la migration sont déjà en place.
docker compose up -d && php bin/console doctrine:migrations:migrate
```

## 1. Côté OVH (une fois)

1. **SSH** : activer SSH sur l'hébergement (offre Pro/Performance) dans le
   manager OVH → Hébergements → FTP-SSH. Ajouter la clé publique de déploiement
   dans `~/.ssh/authorized_keys` (générer une paire dédiée :
   `ssh-keygen -t ed25519 -f deploy_key -C deploy@groupe-horao.com`).
2. **Base de données** : commander une **Web Cloud Database PostgreSQL**
   (le mutualisé n'inclut que MySQL). Noter hôte, port, user, mot de passe,
   nom de base. Autoriser l'IP de l'hébergement dans la liste blanche de la
   Web Cloud Database (manager OVH → Bases de données → Utilisateurs & droits/IP).
   ⚠ Les runners GithubActions ont des IP variables : les migrations s'exécutent
   depuis l'hébergement OVH (via SSH), pas depuis le runner — seule l'IP de
   l'hébergement doit être autorisée.
3. **Arborescence + secrets** : en SSH sur l'hébergement :
   ```bash
   mkdir -p ~/sites/groupe-horao.com/{releases,shared}
   cat > ~/sites/groupe-horao.com/shared/.env.local <<'EOF'
   APP_ENV=prod
   APP_DEBUG=0
   APP_SECRET=<32 hex : php -r "echo bin2hex(random_bytes(16));">
   DATABASE_URL="postgresql://user:MDP@postgresql-xxx.database.cloud.ovh.net:20184/groupe_horao?serverVersion=16&charset=utf8&sslmode=require"
   MAILER_DSN=smtp://contact%40groupe-horao.com:MDP_BOITE@ssl0.ovh.net:465
   CONTACT_TO=contact@groupe-horao.com
   CONTACT_FROM=no-reply@groupe-horao.com
   EOF
   chmod 600 ~/sites/groupe-horao.com/shared/.env.local
   ```
   (`%40` = @ encodé dans le user SMTP ; créer la boîte no-reply si besoin.)
4. **`.ovhconfig`** : copier le fichier `.ovhconfig` du repo à la **racine du
   stockage FTP** (au même niveau que `www/`) pour fixer PHP 8.3.
5. **Domaine** : manager OVH → Hébergement → Multisite → groupe-horao.com →
   dossier racine : `sites/groupe-horao.com/current/public`, SSL Let's Encrypt
   activé, redirection HTTPS forcée.

## 2. Côté GitHub (une fois)

Dans le repo → Settings → Environments → créer `production`, puis :

| Type | Nom | Valeur |
|---|---|---|
| Secret | `OVH_SSH_KEY` | contenu de `deploy_key` (clé **privée**) |
| Secret | `OVH_SSH_HOST` | ex. `ssh.cluster0xx.hosting.ovh.net` |
| Secret | `OVH_SSH_USER` | login SSH de l'hébergement |
| Variable | `OVH_BASE_PATH` | `sites/groupe-horao.com` |
| Variable | `OVH_PHP_BIN` | `php` (ou `/usr/local/php8.3/bin/php` si le `php` par défaut n'est pas le bon) |
| Variable | `HEALTHCHECK_URL` | `https://groupe-horao.com/` (facultatif) |

## 3. Déployer

Push sur `main` → build, lint, rsync, migrations, bascule. Ou manuellement :
onglet Actions → « Déploiement OVH (production) » → Run workflow.

### Rollback

```bash
ssh $USER@$HOST
cd ~/sites/groupe-horao.com
ls -1dt releases/*/          # choisir la release précédente
ln -sfn releases/<ancienne> current.new && mv -Tf current.new current
```
(Si une migration doit être annulée : `php bin/console doctrine:migrations:migrate prev` avant la bascule.)

## Notes sécurité

- La clé SSH de déploiement est dédiée (jamais ta clé perso) et vit uniquement
  dans les secrets GitHub de l'environnement `production`.
- `shared/.env.local` n'est jamais commité ni transféré par le workflow
  (exclu du rsync) — c'est la seule source des secrets de prod.
- `sslmode=require` sur la connexion PostgreSQL (la Web Cloud Database OVH le supporte).
- Les en-têtes de sécurité (CSP, nosniff, frame-ancestors…) sont posés par
  `public/.htaccess`.
- Le rate limiter utilise `cache.app` (filesystem) : l'état repart à zéro à
  chaque déploiement — acceptable ici ; passer à un storage Redis si besoin.
