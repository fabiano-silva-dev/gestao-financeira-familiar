#!/usr/bin/env bash
# Bootstrap idempotente das dependências do projeto (roda após o checkout do código).
# Não inicia serviços nem executa migrations aqui — isso fica no start.sh.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

echo "==> Instalando dependências PHP (composer)"
composer install --no-interaction --prefer-dist --no-progress

echo "==> Instalando dependências JS (npm)"
npm install

if [ ! -f .env ]; then
    echo "==> Criando .env a partir de .env.example"
    cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    echo "==> Gerando APP_KEY"
    php artisan key:generate --force
fi

echo "==> Compilando assets de frontend (vite build)"
npm run build

echo "==> Install concluído"
