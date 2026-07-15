#!/usr/bin/env bash
#
# Setup serveur OVH mutualisé (idempotent) — à lancer depuis ton Mac :
#   bash scripts/setup-ovh.sh
#
# Crée l'arborescence releases/shared, (ré)génère shared/.env.local (chmod 600),
# copie .ovhconfig à la racine FTP, teste la connexion PostgreSQL depuis
# l'hébergement. Aucun secret stocké : tout est demandé interactivement.
set -euo pipefail

KEY="${DEPLOY_KEY:-$HOME/.ssh/deploy_horao}"
[ -f "$KEY" ] || { echo "Clé $KEY introuvable — génère-la d'abord (voir DEPLOY.md §2)."; exit 1; }

read -rp  "Hôte SSH OVH (ex. ssh.cluster0xx.hosting.ovh.net) : " SSH_HOST
read -rp  "Login SSH OVH : " SSH_USER
read -rp  "Chemin du site [sites/groupe-horao.com] : " BASE
BASE="${BASE:-sites/groupe-horao.com}"

echo
echo "— Web Cloud Database PostgreSQL —"
read -rp  "Hôte PostgreSQL (ex. ca41090-001.eu.clouddb.ovh.net) : " DB_HOST
read -rp  "Port : " DB_PORT
read -rp  "Utilisateur : " DB_USER
read -rsp "Mot de passe : " DB_PASS; echo
read -rp  "Nom de la base [groupe_horao] : " DB_NAME
DB_NAME="${DB_NAME:-groupe_horao}"

echo
echo "— Boîte mail OVH pour l'envoi SMTP —"
read -rp  "Adresse d'envoi [no-reply@groupe-horao.com] : " SMTP_USER
SMTP_USER="${SMTP_USER:-no-reply@groupe-horao.com}"
read -rsp "Mot de passe de la boîte : " SMTP_PASS; echo

urlenc() { python3 -c "import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1], safe=''))" "$1"; }
APP_SECRET="$(openssl rand -hex 16)"
DB_PASS_ENC="$(urlenc "$DB_PASS")"
SMTP_USER_ENC="$(urlenc "$SMTP_USER")"
SMTP_PASS_ENC="$(urlenc "$SMTP_PASS")"

echo
echo "Test de connexion SSH…"
ssh -i "$KEY" -o BatchMode=yes "$SSH_USER@$SSH_HOST" 'echo "SSH OK sur $(hostname)"'

echo "Création de l'arborescence et de shared/.env.local…"
ssh -i "$KEY" "$SSH_USER@$SSH_HOST" "BASE='$BASE' bash -s" <<EOF
set -euo pipefail
mkdir -p "\$BASE/releases" "\$BASE/shared"
umask 177
cat > "\$BASE/shared/.env.local" <<ENV
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$APP_SECRET
DATABASE_URL="postgresql://$DB_USER:$DB_PASS_ENC@$DB_HOST:$DB_PORT/$DB_NAME?serverVersion=16&charset=utf8&sslmode=require"
MAILER_DSN=smtp://$SMTP_USER_ENC:$SMTP_PASS_ENC@ssl0.ovh.net:465
CONTACT_TO=contact@groupe-horao.com
CONTACT_FROM=$SMTP_USER
ENV
chmod 600 "\$BASE/shared/.env.local"
echo "→ \$BASE/shared/.env.local créé (droits 600)"
EOF

echo "Copie de .ovhconfig à la racine FTP…"
scp -i "$KEY" .ovhconfig "$SSH_USER@$SSH_HOST:~/.ovhconfig"

echo
echo "Test de connexion à PostgreSQL depuis l'hébergement (liste blanche IP + identifiants)…"
ssh -i "$KEY" "$SSH_USER@$SSH_HOST" \
  "php -r 'try { new PDO(\"pgsql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;sslmode=require\", \"$DB_USER\", \$argv[1]); echo \"PostgreSQL OK\n\"; } catch (Throwable \$e) { fwrite(STDERR, \"ERREUR PDO : \".\$e->getMessage().\"\n\"); exit(1); }' '$DB_PASS'" \
  || { echo "⚠ Échec PostgreSQL — le message ERREUR PDO ci-dessus dit lequel : identifiants (password authentication failed), base inconnue (does not exist), ou liste blanche IP (timeout / no pg_hba)."; exit 1; }

echo
echo "✔ Setup serveur terminé. Reste à faire :"
echo "  1. GitHub → Environments → production : OVH_SSH_HOST=$SSH_HOST / OVH_SSH_USER=$SSH_USER / var OVH_BASE_PATH=$BASE"
echo "  2. Manager OVH → Multisite : racine → $BASE/current/public + SSL"
echo "  3. Re-run du workflow GitHub Actions"
