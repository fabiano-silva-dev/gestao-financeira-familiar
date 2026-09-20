#!/usr/bin/env bash
# Reconciliação por boot: sobe PostgreSQL e Redis, garante role/banco e aplica migrations.
# Precisa ser idempotente e tolerar reinicializações.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

PG_VER=16
DB_NAME=financeiro_familiar
DB_USER=financeiro
DB_PASS=financeiro

echo "==> Iniciando PostgreSQL ${PG_VER}"
if ! sudo pg_lsclusters -h 2>/dev/null | awk '{print $1"/"$2}' | grep -q "^${PG_VER}/main$"; then
    sudo pg_createcluster "${PG_VER}" main >/dev/null 2>&1 || true
fi
sudo pg_ctlcluster "${PG_VER}" main start 2>/dev/null || true

echo "==> Aguardando PostgreSQL aceitar conexões"
for _ in $(seq 1 30); do
    if sudo -u postgres pg_isready -q; then break; fi
    sleep 1
done

echo "==> Garantindo role e banco"
sudo -u postgres psql -tc "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'" | grep -q 1 \
    || sudo -u postgres psql -c "CREATE ROLE ${DB_USER} LOGIN PASSWORD '${DB_PASS}';"
sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" | grep -q 1 \
    || sudo -u postgres createdb -O "${DB_USER}" "${DB_NAME}"

echo "==> Iniciando Redis"
if ! redis-cli ping >/dev/null 2>&1; then
    sudo service redis-server start 2>/dev/null \
        || sudo redis-server /etc/redis/redis.conf --daemonize yes 2>/dev/null \
        || true
fi
redis-cli ping >/dev/null 2>&1 && echo "Redis OK" || echo "AVISO: Redis não respondeu ao ping"

if [ ! -f .env ]; then
    cp .env.example .env
fi
if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
fi

echo "==> Aplicando migrations"
php artisan migrate --force

echo "==> Start concluído"
