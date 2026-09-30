#!/usr/bin/env bash
#
# Prepara o servidor integrar-server para a Gestão Financeira Familiar
# sem alterar a aplicação Integrar que já está no ar.
#
# Ambiente observado em 2026-09-30 (somente leitura):
#   - Ubuntu 24.04, Apache em 80/443, PHP 8.3-FPM, MySQL nativo
#   - Integrar em /home/fabiano/Projetos/integrar
#   - VHost :80  sites-enabled/integrar.conf
#   - VHost :443 sites-enabled/000-default-le-ssl.conf
#   - PHP global em conf-enabled/integrar-laravel.conf (pool www, 5 filhos)
#   - PostgreSQL do Evolution API só dentro do Docker, sem porta no host
#   - Redis ausente; portas 5432 e 6379 livres no host
#
# Este script NÃO faz:
#   - a2dissite, edição de vhost/conf do Integrar, ou troca do DocumentRoot dele
#   - alteração de /etc/php/8.3/fpm/pool.d/www.conf ou dos php.ini
#   - restart/stop de apache2, mysql, php8.3-fpm, docker ou units integrar-*
#   - apt upgrade, autoremove, ou instalação de MySQL/Apache/PHP base
#
# Uso no servidor, depois do código em /home/fabiano/Projetos/gestao-financeira-familiar:
#   sudo ./script-manutencao/instalar-nativo-producao.sh
#   sudo ./script-manutencao/instalar-nativo-producao.sh --dry-run --yes
#
set -Eeuo pipefail

readonly SCRIPT_NAME="$(basename "$0")"
readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly DEFAULT_DOMAIN="gestaofinanceirafamiliar.integraexpert.com.br"
readonly DEFAULT_DB_NAME="gestao_financeira_familiar"
readonly DEFAULT_DB_USER="gff"
readonly EXPECTED_HOSTNAME="integrar-server"
readonly SITE_HTTP="zzz-gestaofinanceirafamiliar.conf"
readonly SITE_SSL="gestaofinanceirafamiliar-le-ssl.conf"
readonly FPM_POOL_FILE="/etc/php/8.3/fpm/pool.d/gestaofinanceirafamiliar.conf"
readonly FPM_SOCKET="/run/php/php8.3-fpm-gff.sock"
readonly UNIT_HORIZON="/etc/systemd/system/gff-horizon.service"
readonly UNIT_SCHEDULER="/etc/systemd/system/gff-scheduler.service"

PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
APP_USER="${SUDO_USER:-fabiano}"
APP_GROUP="www-data"
DOMAIN="$DEFAULT_DOMAIN"
DB_NAME="$DEFAULT_DB_NAME"
DB_USER="$DEFAULT_DB_USER"
DB_PASSWORD=""
SSL_EMAIL=""
YES=false
DRY_RUN=false
SKIP_PACKAGES=false
SKIP_DATABASE=false
SKIP_APP=false
SKIP_SSL=false
SKIP_BUILD=false
REDIS_WAS_ACTIVE=false
INTEGRAR_STATUS_BEFORE=""

declare -a PROTECTED_FILES=(
    /etc/apache2/sites-available/integrar.conf
    /etc/apache2/sites-enabled/integrar.conf
    /etc/apache2/sites-enabled/000-default-le-ssl.conf
    /etc/apache2/conf-available/integrar-laravel.conf
    /etc/apache2/conf-enabled/integrar-laravel.conf
    /etc/php/8.3/fpm/pool.d/www.conf
    /etc/php/8.3/fpm/php.ini
    /etc/php/8.3/cli/php.ini
    /etc/systemd/system/integrar-queue.service
    /etc/systemd/system/integrar-scheduler.service
    /etc/systemd/system/integrar-queue-automacoes.service
    /etc/supervisor/conf.d/gmft-extractor.conf
)
declare -a PROTECTED_SUMS=()
declare -a COMPLETED_ACTIONS=()

color() {
    if [[ -t 1 ]]; then
        printf '\033[%sm' "$1"
    fi
}
reset_color() { color 0; }
info() { color "1;34"; printf 'ℹ %s\n' "$*"; reset_color; }
success() { color "1;32"; printf '✓ %s\n' "$*"; reset_color; }
warn() { color "1;33"; printf '⚠ %s\n' "$*" >&2; reset_color >&2; }
die() { color "1;31"; printf '✗ %s\n' "$*" >&2; reset_color >&2; exit 1; }

usage() {
    cat <<EOF
Uso: sudo ./$SCRIPT_NAME [opções]

Prepara PostgreSQL, Redis, pool PHP-FPM próprio, Apache e serviços
da Gestão Financeira Familiar no servidor do Integrar.

O Integrar permanece nos arquivos e serviços atuais. O vhost novo usa
o nome zzz- para não virar o site padrão das portas 80/443.

Opções:
  --yes                 Confirma todas as etapas.
  --dry-run             Mostra os comandos sem alterar o servidor.
  --project-dir CAMINHO Raiz do Laravel (padrão: raiz deste repositório).
  --domain DOMÍNIO      Padrão: $DEFAULT_DOMAIN
  --app-user USUÁRIO    Dono dos arquivos e dos processos (padrão: \$SUDO_USER).
  --db-name NOME        Banco PostgreSQL (padrão: $DEFAULT_DB_NAME).
  --db-user USUÁRIO     Usuário PostgreSQL (padrão: $DEFAULT_DB_USER).
  --db-password SENHA   Senha do banco. Sem isto, reutiliza o .env ou gera uma nova.
  --ssl-email EMAIL     E-mail da conta Let's Encrypt, se o Certbot pedir.
  --skip-packages       Não instala pacotes.
  --skip-database       Não cria banco nem usuário.
  --skip-app            Não configura .env, Composer, build nem migrations.
  --skip-build          Não roda npm ci / npm run build.
  --skip-ssl            Não emite certificado.
  -h, --help            Mostra esta ajuda.
EOF
}

run() {
    if $DRY_RUN; then
        printf '[dry-run]'
        printf ' %q' "$@"
        printf '\n'
    else
        "$@"
    fi
}

confirm() {
    local prompt="$1" answer
    $YES && return 0
    [[ -t 0 ]] || die "Entrada interativa indisponível. Use --yes ou execute em um terminal."
    read -r -p "$prompt [s/N] " answer
    [[ "$answer" =~ ^[SsYy]$ ]]
}

action() {
    local title="$1" callback="$2"
    printf '\n'
    if confirm "$title?"; then
        "$callback"
        COMPLETED_ACTIONS+=("$title")
        success "$title"
    else
        warn "Etapa ignorada: $title"
    fi
}

require_root() {
    if [[ ${EUID:-$(id -u)} -ne 0 ]] && ! $DRY_RUN; then
        die "Execute como root (sudo ./$SCRIPT_NAME)."
    fi
}

random_secret() {
    openssl rand -hex 24
}

validate_inputs() {
    [[ "$PROJECT_DIR" = /* ]] || die "--project-dir deve ser um caminho absoluto."
    PROJECT_DIR="$(realpath -m "$PROJECT_DIR")"
    [[ "$DOMAIN" == "$DEFAULT_DOMAIN" ]] || die "Domínio recusado: $DOMAIN. Use apenas $DEFAULT_DOMAIN."
    [[ "$DB_NAME" =~ ^[a-z_][a-z0-9_]*$ ]] || die "Nome de banco inválido."
    [[ "$DB_USER" =~ ^[a-z_][a-z0-9_]*$ ]] || die "Usuário de banco inválido."
    [[ "$DB_NAME" != "postgres" && "$DB_NAME" != "template0" && "$DB_NAME" != "template1" ]] ||
        die "Nome de banco reservado."
    [[ "$PROJECT_DIR" != "/" && "$PROJECT_DIR" != "/home" && "$PROJECT_DIR" != "/home/fabiano" && "$PROJECT_DIR" != "/home/fabiano/Projetos" ]] ||
        die "Diretório do projeto amplo demais: $PROJECT_DIR"
    case "$PROJECT_DIR" in
        /home/fabiano/Projetos/integrar|/home/fabiano/Projetos/integrar/*|\
        /home/fabiano/Projetos/old_integrar_dalongaro|/home/fabiano/Projetos/old_integrar_dalongaro/*|\
        /home/fabiano/Projetos/gmft-extractor|/home/fabiano/Projetos/gmft-extractor/*|\
        /var/www/html|/var/www/html/*)
            die "Diretório reservado ao que já está no servidor: $PROJECT_DIR"
            ;;
    esac
    id "$APP_USER" >/dev/null 2>&1 || $DRY_RUN || die "Usuário inexistente: $APP_USER."
    getent group "$APP_GROUP" >/dev/null 2>&1 || $DRY_RUN || die "Grupo inexistente: $APP_GROUP."
}

check_platform() {
    [[ -r /etc/os-release ]] || die "Sistema sem /etc/os-release."
    # shellcheck disable=SC1091
    source /etc/os-release
    [[ "${ID:-}" == "ubuntu" && "${VERSION_ID:-}" == "24.04" ]] ||
        die "Este script só aceita Ubuntu 24.04. Detectado: ${PRETTY_NAME:-desconhecido}."
    [[ "$(hostname)" == "$EXPECTED_HOSTNAME" ]] ||
        die "Hostname $(hostname) não é $EXPECTED_HOSTNAME. Execução interrompida para não atingir outra máquina."
    command -v systemctl >/dev/null || die "systemd é obrigatório."
    command -v apache2ctl >/dev/null || die "Apache não encontrado. O Integrar depende dele; este script não instala outro servidor web."
    systemctl is-active --quiet apache2 || die "Apache está parado. Não vou continuar."
    systemctl is-active --quiet php8.3-fpm || die "php8.3-fpm está parado. Não vou continuar."
    systemctl is-active --quiet mysql || die "MySQL está parado. Não vou continuar."
    [[ -f /etc/apache2/sites-enabled/integrar.conf ]] || die "VHost do Integrar ausente."
    [[ -f /etc/apache2/sites-enabled/000-default-le-ssl.conf ]] || die "VHost SSL do Integrar ausente."
    success "Plataforma validada: ${PRETTY_NAME} ($(hostname))"
}

snapshot_protected() {
    local file sum
    PROTECTED_SUMS=()
    for file in "${PROTECTED_FILES[@]}"; do
        if [[ -e "$file" ]]; then
            sum="$(sha256sum "$file" | awk '{print $1}')"
        else
            sum="AUSENTE"
        fi
        PROTECTED_SUMS+=("$sum")
    done
}

assert_protected_unchanged() {
    local i file sum
    for i in "${!PROTECTED_FILES[@]}"; do
        file="${PROTECTED_FILES[$i]}"
        if [[ -e "$file" ]]; then
            sum="$(sha256sum "$file" | awk '{print $1}')"
        else
            sum="AUSENTE"
        fi
        [[ "$sum" == "${PROTECTED_SUMS[$i]}" ]] ||
            die "Arquivo protegido mudou: $file. Nada do Apache foi recarregado."
    done
}

assert_safe_destination() {
    case "$1" in
        /etc/apache2/sites-available/zzz-gestaofinanceirafamiliar.conf|\
        /etc/apache2/sites-available/gestaofinanceirafamiliar-le-ssl.conf|\
        /etc/php/8.3/fpm/pool.d/gestaofinanceirafamiliar.conf|\
        /etc/systemd/system/gff-horizon.service|\
        /etc/systemd/system/gff-scheduler.service)
            ;;
        *)
            die "Destino fora da lista permitida: $1"
            ;;
    esac
}

write_file() {
    local destination="$1" mode="$2" content="$3"
    assert_safe_destination "$destination"
    if $DRY_RUN; then
        printf '[dry-run] gravar %s (modo %s)\n' "$destination" "$mode"
        return
    fi
    printf '%s\n' "$content" >"$destination"
    chmod "$mode" "$destination"
}

integrar_http_code() {
    curl -sk -o /dev/null -w '%{http_code}' --max-time 20 \
        --resolve integraexpert.com.br:443:127.0.0.1 \
        https://integraexpert.com.br/ || printf '000'
}

remember_integrar_health() {
    if $DRY_RUN; then
        INTEGRAR_STATUS_BEFORE="dry-run"
        return
    fi
    INTEGRAR_STATUS_BEFORE="$(integrar_http_code)"
    info "Integrar respondeu HTTP $INTEGRAR_STATUS_BEFORE antes das mudanças."
    [[ "$INTEGRAR_STATUS_BEFORE" =~ ^(200|301|302|303|307|308|401|403)$ ]] ||
        die "O Integrar não respondeu de forma saudável ($INTEGRAR_STATUS_BEFORE). Nada foi alterado."
}

assert_integrar_still_healthy() {
    $DRY_RUN && return 0
    local attempt code
    for attempt in 1 2 3 4 5; do
        code="$(integrar_http_code)"
        if [[ "$code" == "$INTEGRAR_STATUS_BEFORE" || "$code" =~ ^(200|301|302|303|307|308)$ ]]; then
            success "Integrar continua respondendo HTTP $code."
            return 0
        fi
        sleep 2
    done
    die "O Integrar passou a responder HTTP $code (antes: $INTEGRAR_STATUS_BEFORE)."
}

assert_apache_defaults() {
    $DRY_RUN && return 0
    local dump
    dump="$(apache2ctl -S 2>/dev/null || true)"
    awk '
        $1 ~ /:80$/ { section = "80" }
        $1 ~ /:443$/ { section = "443" }
        section == "80" && /default server/ && /sites-enabled\/integrar\.conf/ { ok80 = 1 }
        section == "443" && /default server/ && /sites-enabled\/000-default-le-ssl\.conf/ { ok443 = 1 }
        section == "80" && $1 ~ /:80$/ && /sites-enabled\/integrar\.conf/ { ok80 = 1 }
        section == "443" && $1 ~ /:443$/ && /sites-enabled\/000-default-le-ssl\.conf/ { ok443 = 1 }
        END { exit (ok80 && ok443) ? 0 : 1 }
    ' <<<"$dump" || die "O Integrar deixou de ser o site padrão nas portas 80/443."
    grep -q "$DOMAIN" <<<"$dump" || die "O vhost $DOMAIN não apareceu na configuração."
}

backup_our_apache() {
    $DRY_RUN && return 0
    rm -rf /tmp/gff-apache-rollback
    mkdir -p /tmp/gff-apache-rollback
    cp -a "/etc/apache2/sites-available/$SITE_HTTP" /tmp/gff-apache-rollback/ 2>/dev/null || true
    cp -a "/etc/apache2/sites-available/$SITE_SSL" /tmp/gff-apache-rollback/ 2>/dev/null || true
    [[ -L "/etc/apache2/sites-enabled/$SITE_HTTP" ]] && touch /tmp/gff-apache-rollback/http-enabled
    [[ -L "/etc/apache2/sites-enabled/$SITE_SSL" ]] && touch /tmp/gff-apache-rollback/ssl-enabled
}

restore_our_apache() {
    $DRY_RUN && return 0
    a2dissite "$SITE_HTTP" "$SITE_SSL" >/dev/null 2>&1 || true
    rm -f \
        "/etc/apache2/sites-enabled/$SITE_HTTP" \
        "/etc/apache2/sites-enabled/$SITE_SSL" \
        "/etc/apache2/sites-available/$SITE_HTTP" \
        "/etc/apache2/sites-available/$SITE_SSL"
    if [[ -d /tmp/gff-apache-rollback ]]; then
        [[ -f "/tmp/gff-apache-rollback/$SITE_HTTP" ]] &&
            cp -a "/tmp/gff-apache-rollback/$SITE_HTTP" /etc/apache2/sites-available/
        [[ -f "/tmp/gff-apache-rollback/$SITE_SSL" ]] &&
            cp -a "/tmp/gff-apache-rollback/$SITE_SSL" /etc/apache2/sites-available/
        [[ -f /tmp/gff-apache-rollback/http-enabled ]] && a2ensite "$SITE_HTTP" >/dev/null
        [[ -f /tmp/gff-apache-rollback/ssl-enabled ]] && a2ensite "$SITE_SSL" >/dev/null
    fi
    apache2ctl configtest >/dev/null
}

package_installed() {
    dpkg -s "$1" >/dev/null 2>&1
}

install_packages() {
    local ports
    ports="$(ss -lnt | awk '{print $4}' || true)"
    if ! package_installed postgresql && grep -qE '(:5432)$' <<<"$ports"; then
        die "A porta 5432 já está em uso. PostgreSQL nativo não será instalado."
    fi
    if ! package_installed redis-server && grep -qE '(:6379)$' <<<"$ports"; then
        die "A porta 6379 já está em uso. Redis não será instalado."
    fi
    warn "php8.3-pgsql e php8.3-redis fazem o pacote recarregar o php8.3-fpm do Integrar. O pool www, o php.ini e o Apache não são editados."
    run apt-get update
    run env DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        php8.3-pgsql \
        php8.3-redis \
        postgresql \
        redis-server
    assert_protected_unchanged
    assert_integrar_still_healthy
}

configure_database() {
    run systemctl enable --now postgresql
    local password_sql env_file existing
    env_file="$PROJECT_DIR/.env"
    if [[ -z "$DB_PASSWORD" && -f "$env_file" ]]; then
        existing="$(grep -E '^DB_PASSWORD=' "$env_file" | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
        if [[ -n "$existing" && "$existing" != "null" ]]; then
            DB_PASSWORD="$existing"
            info "Senha reutilizada do .env já existente."
        fi
    fi
    if [[ -z "$DB_PASSWORD" ]]; then
        DB_PASSWORD="$(random_secret)"
        info "Senha PostgreSQL gerada."
    fi
    password_sql="${DB_PASSWORD//\'/\'\'}"
    if $DRY_RUN; then
        printf '[dry-run] criar role %s e banco %s no PostgreSQL local (senha ocultada)\n' "$DB_USER" "$DB_NAME"
        return
    fi
    sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${DB_USER}') THEN
        CREATE ROLE ${DB_USER} LOGIN PASSWORD '${password_sql}';
    ELSE
        ALTER ROLE ${DB_USER} WITH LOGIN PASSWORD '${password_sql}';
    END IF;
END
\$\$;
SELECT format('CREATE DATABASE %I OWNER %I', '${DB_NAME}', '${DB_USER}')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = '${DB_NAME}')\\gexec
SQL
    sudo -u postgres psql -v ON_ERROR_STOP=1 -d "$DB_NAME" <<SQL
ALTER SCHEMA public OWNER TO ${DB_USER};
GRANT ALL ON SCHEMA public TO ${DB_USER};
SQL
    PGPASSWORD="$DB_PASSWORD" psql -h 127.0.0.1 -U "$DB_USER" -d "$DB_NAME" -c 'SELECT 1;' >/dev/null
}

configure_redis() {
    if $REDIS_WAS_ACTIVE; then
        info "Redis já estava ativo. A configuração existente não será alterada."
    else
        run systemctl enable --now redis-server
    fi
    $DRY_RUN && return 0
    local bind
    bind="$(redis-cli CONFIG GET bind | awk 'NR==2 {print}')"
    [[ "$bind" == *"127.0.0.1"* ]] ||
        die "Redis não está preso a 127.0.0.1 (bind=$bind). Nada foi reconfigurado."
    redis-cli ping | grep -qx PONG
}

configure_php_pool() {
    local pool
    pool="[gestaofinanceirafamiliar]
user = ${APP_USER}
group = ${APP_GROUP}
listen = ${FPM_SOCKET}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 4
pm.start_servers = 1
pm.min_spare_servers = 1
pm.max_spare_servers = 2
pm.max_requests = 500
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 50M
php_admin_value[post_max_size] = 50M
php_admin_value[max_execution_time] = 120
php_admin_flag[expose_php] = off
"
    write_file "$FPM_POOL_FILE" 0644 "$pool"
    if $DRY_RUN; then
        printf '[dry-run] validar e recarregar php8.3-fpm\n'
        return
    fi
    if ! php-fpm8.3 -t; then
        rm -f "$FPM_POOL_FILE"
        die "Pool PHP inválido. O arquivo foi removido e o FPM não foi recarregado."
    fi
    assert_protected_unchanged
    systemctl reload php8.3-fpm
    [[ -S "$FPM_SOCKET" ]] || die "Socket do pool não apareceu: $FPM_SOCKET"
    assert_integrar_still_healthy
}

set_env_value() {
    local file="$1" key="$2" value="$3"
    if $DRY_RUN; then
        printf '[dry-run] definir %s em %s\n' "$key" "$file"
        return
    fi
    [[ -f "$file" ]] || die "Arquivo ausente: $file"
    if grep -q "^${key}=" "$file"; then
        awk -v key="$key" -v value="$value" '
            BEGIN { FS = OFS = "=" }
            $1 == key { print key "=" value; next }
            { print }
        ' "$file" >"${file}.tmp"
        mv "${file}.tmp" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >>"$file"
    fi
}

configure_application() {
    [[ -f "$PROJECT_DIR/artisan" && -f "$PROJECT_DIR/composer.json" ]] ||
        die "Laravel não encontrado em $PROJECT_DIR. Coloque o código lá e execute de novo."
    local env_file="$PROJECT_DIR/.env"
    if [[ ! -f "$env_file" ]]; then
        run cp "$PROJECT_DIR/.env.example" "$env_file"
    fi
    set_env_value "$env_file" APP_ENV production
    set_env_value "$env_file" APP_DEBUG false
    set_env_value "$env_file" LOG_LEVEL warning
    set_env_value "$env_file" DB_CONNECTION pgsql
    set_env_value "$env_file" DB_HOST 127.0.0.1
    set_env_value "$env_file" DB_PORT 5432
    set_env_value "$env_file" DB_DATABASE "$DB_NAME"
    set_env_value "$env_file" DB_USERNAME "$DB_USER"
    set_env_value "$env_file" DB_PASSWORD "$DB_PASSWORD"
    set_env_value "$env_file" CACHE_STORE redis
    set_env_value "$env_file" QUEUE_CONNECTION redis
    set_env_value "$env_file" REDIS_CLIENT phpredis
    set_env_value "$env_file" REDIS_HOST 127.0.0.1
    set_env_value "$env_file" REDIS_PASSWORD null
    set_env_value "$env_file" REDIS_PORT 6379
    if certificate_exists; then
        set_env_value "$env_file" APP_URL "https://${DOMAIN}"
        set_env_value "$env_file" SESSION_SECURE_COOKIE true
    else
        set_env_value "$env_file" APP_URL "http://${DOMAIN}"
        set_env_value "$env_file" SESSION_SECURE_COOKIE false
    fi

    run chown -R "$APP_USER:$APP_GROUP" "$PROJECT_DIR"
    run chmod -R ug+rwX "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"
    run chmod 600 "$env_file"

    run sudo -u "$APP_USER" -H composer install --working-dir="$PROJECT_DIR" \
        --no-dev --prefer-dist --optimize-autoloader --no-interaction

    if ! $DRY_RUN && ! grep -q '^APP_KEY=base64:' "$env_file"; then
        run sudo -u "$APP_USER" -H php "$PROJECT_DIR/artisan" key:generate --force
    fi

    if [[ ! -L "$PROJECT_DIR/public/storage" ]]; then
        run sudo -u "$APP_USER" -H php "$PROJECT_DIR/artisan" storage:link
    fi
    run sudo -u "$APP_USER" -H php "$PROJECT_DIR/artisan" migrate --force

    if ! $SKIP_BUILD; then
        run sudo -u "$APP_USER" -H env NODE_ENV=development NODE_OPTIONS="${NODE_OPTIONS:---max-old-space-size=2048}" \
            npm --prefix "$PROJECT_DIR" ci
        run sudo -u "$APP_USER" -H env NODE_ENV=production NODE_OPTIONS="${NODE_OPTIONS:---max-old-space-size=2048}" \
            npm --prefix "$PROJECT_DIR" run build
    fi

    run sudo -u "$APP_USER" -H php "$PROJECT_DIR/artisan" optimize
}

apache_app_block() {
    cat <<EOF
    DocumentRoot ${PROJECT_DIR}/public
    DirectoryIndex index.php

    <Directory ${PROJECT_DIR}/public>
        Options FollowSymLinks
        AllowOverride None
        Require all granted

        RewriteEngine On
        RewriteCond %{HTTP:Authorization} .
        RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
        RewriteCond %{HTTP:x-xsrf-token} .
        RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteCond %{REQUEST_URI} (.+)/\$
        RewriteRule ^ %1 [L,R=301]
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteRule ^ index.php [L]

        <FilesMatch "\\.php\$">
            SetHandler "proxy:unix:${FPM_SOCKET}|fcgi://gff/"
        </FilesMatch>
    </Directory>

    <FilesMatch "^\\.">
        Require all denied
    </FilesMatch>

    LimitRequestBody 52428800
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set X-GFF-Pool "gestaofinanceirafamiliar"
EOF
}

write_http_vhost() {
    local mode="$1" config
    if [[ "$mode" == "redirect" ]]; then
        config="<VirtualHost *:80>
    ServerName ${DOMAIN}
    Redirect permanent / https://${DOMAIN}/
    ErrorLog \${APACHE_LOG_DIR}/gestaofinanceirafamiliar-error.log
    CustomLog \${APACHE_LOG_DIR}/gestaofinanceirafamiliar-access.log combined
</VirtualHost>"
    else
        config="<VirtualHost *:80>
    ServerName ${DOMAIN}

$(apache_app_block)

    ErrorLog \${APACHE_LOG_DIR}/gestaofinanceirafamiliar-error.log
    CustomLog \${APACHE_LOG_DIR}/gestaofinanceirafamiliar-access.log combined
</VirtualHost>"
    fi
    write_file "/etc/apache2/sites-available/${SITE_HTTP}" 0644 "$config"
}

write_ssl_vhost() {
    local config
    config="<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName ${DOMAIN}
    SSLEngine on
    Include /etc/letsencrypt/options-ssl-apache.conf
    SSLCertificateFile /etc/letsencrypt/live/${DOMAIN}/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/${DOMAIN}/privkey.pem

    RequestHeader set X-Forwarded-Proto \"https\"
    RequestHeader set X-Forwarded-Ssl \"on\"
    SetEnv HTTPS on

$(apache_app_block)

    ErrorLog \${APACHE_LOG_DIR}/gestaofinanceirafamiliar-ssl-error.log
    CustomLog \${APACHE_LOG_DIR}/gestaofinanceirafamiliar-ssl-access.log combined
</VirtualHost>
</IfModule>"
    write_file "/etc/apache2/sites-available/${SITE_SSL}" 0644 "$config"
}

enable_and_test_apache() {
    run a2ensite "$SITE_HTTP"
    if [[ -f "/etc/apache2/sites-available/${SITE_SSL}" ]]; then
        run a2ensite "$SITE_SSL"
    fi
    if $DRY_RUN; then
        printf '[dry-run] apache2ctl configtest && conferir vhosts padrão && reload\n'
        return
    fi
    if ! apache2ctl configtest; then
        restore_our_apache || die "A configuração em disco do Apache ficou inválida. O processo em execução não foi recarregado."
        die "apache2ctl configtest falhou. A configuração anterior foi restaurada e o Apache em execução não foi recarregado."
    fi
    assert_protected_unchanged
    assert_apache_defaults
    systemctl reload apache2
    assert_integrar_still_healthy
}

configure_apache() {
    [[ -d "$PROJECT_DIR/public" ]] || die "DocumentRoot ausente: $PROJECT_DIR/public"
    [[ -S "$FPM_SOCKET" || $DRY_RUN ]] || die "Pool PHP-FPM ainda não existe. Rode a etapa do pool antes."
    backup_our_apache
    if [[ -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]]; then
        write_http_vhost redirect
        write_ssl_vhost
    else
        write_http_vhost serve
    fi
    enable_and_test_apache
}

certificate_exists() {
    [[ -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]]
}

domain_points_here() {
    local dns_ip ip
    dns_ip="$(getent ahostsv4 "$DOMAIN" | awk '{print $1; exit}')"
    [[ -n "$dns_ip" ]] || return 1
    for ip in $(hostname -I); do
        [[ "$ip" == "$dns_ip" ]] && return 0
    done
    return 1
}

configure_ssl() {
    if ! certificate_exists && ! domain_points_here; then
        warn "O DNS de $DOMAIN ainda não aponta para este servidor. O HTTP foi publicado; o certificado fica para uma nova execução."
        return
    fi
    if ! certificate_exists; then
        local -a certbot_args=(
            certonly
            --webroot
            -w "$PROJECT_DIR/public"
            -d "$DOMAIN"
            --cert-name "$DOMAIN"
            --non-interactive
            --keep-until-expiring
        )
        if [[ -n "$SSL_EMAIL" ]]; then
            certbot_args+=(--email "$SSL_EMAIL" --agree-tos --no-eff-email)
        elif certbot show_account >/dev/null 2>&1; then
            info "Será usada a conta Certbot já existente neste servidor."
        else
            warn "Não há conta Certbot. Execute de novo com --ssl-email para emitir o certificado."
            return
        fi
        if $DRY_RUN; then
            printf '[dry-run] certbot'
            printf ' %q' "${certbot_args[@]}"
            printf '\n'
            return
        fi
        certbot "${certbot_args[@]}"
        certificate_exists || die "Certbot terminou sem gravar o certificado."
    else
        info "Certificado já existe para $DOMAIN."
    fi
    backup_our_apache
    write_http_vhost redirect
    write_ssl_vhost
    enable_and_test_apache
    if [[ -f "$PROJECT_DIR/.env" ]]; then
        set_env_value "$PROJECT_DIR/.env" APP_URL "https://${DOMAIN}"
        set_env_value "$PROJECT_DIR/.env" SESSION_SECURE_COOKIE true
        chown "$APP_USER:$APP_GROUP" "$PROJECT_DIR/.env"
        chmod 600 "$PROJECT_DIR/.env"
        if [[ -f "$PROJECT_DIR/artisan" && -d "$PROJECT_DIR/vendor" ]]; then
            run sudo -u "$APP_USER" -H php "$PROJECT_DIR/artisan" optimize
        fi
    fi
}

configure_systemd() {
    local horizon scheduler
    horizon="[Unit]
Description=Gestão Financeira Familiar — Horizon
After=network.target postgresql.service redis-server.service
Wants=postgresql.service redis-server.service

[Service]
Type=simple
User=${APP_USER}
Group=${APP_GROUP}
WorkingDirectory=${PROJECT_DIR}
ExecStart=/usr/bin/php ${PROJECT_DIR}/artisan horizon
Restart=always
RestartSec=5
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target"
    scheduler="[Unit]
Description=Gestão Financeira Familiar — Scheduler
After=network.target postgresql.service

[Service]
Type=simple
User=${APP_USER}
Group=${APP_GROUP}
WorkingDirectory=${PROJECT_DIR}
ExecStart=/usr/bin/php ${PROJECT_DIR}/artisan schedule:work
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target"
    write_file "$UNIT_HORIZON" 0644 "$horizon"
    write_file "$UNIT_SCHEDULER" 0644 "$scheduler"
    run systemctl daemon-reload
    run systemctl enable --now gff-horizon gff-scheduler
    assert_protected_unchanged
}

run_health_checks() {
    $DRY_RUN && return 0
    local failed=0 code
    check() {
        local description="$1"
        shift
        if "$@" >/dev/null 2>&1; then
            success "Teste: $description"
        else
            warn "Falhou: $description"
            failed=1
        fi
    }
    check "Apache ativo" systemctl is-active --quiet apache2
    check "MySQL do Integrar ativo" systemctl is-active --quiet mysql
    check "PHP-FPM ativo" systemctl is-active --quiet php8.3-fpm
    check "Fila do Integrar ativa" systemctl is-active --quiet integrar-queue
    check "Agenda do Integrar ativa" systemctl is-active --quiet integrar-scheduler
    check "PostgreSQL ativo" systemctl is-active --quiet postgresql
    check "Redis ativo" systemctl is-active --quiet redis-server
    php -m | grep -qx pdo_pgsql || { warn "Falhou: pdo_pgsql"; failed=1; }
    php -m | grep -qx redis || { warn "Falhou: redis"; failed=1; }
    check "Socket do pool próprio" test -S "$FPM_SOCKET"
    assert_protected_unchanged
    assert_apache_defaults
    assert_integrar_still_healthy
    if ! $SKIP_APP && [[ -f "$PROJECT_DIR/artisan" ]]; then
        check "Horizon ativo" systemctl is-active --quiet gff-horizon
        check "Agenda própria ativa" systemctl is-active --quiet gff-scheduler
        local headers
        if certificate_exists; then
            headers="$(curl -skI --max-time 20 --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/up" || true)"
        else
            headers="$(curl -sI --max-time 20 -H "Host: ${DOMAIN}" "http://127.0.0.1/up" || true)"
        fi
        code="$(awk 'BEGIN{IGNORECASE=1} /^HTTP/{code=$2} END{print code}' <<<"$headers")"
        if [[ "$code" == "200" ]] && grep -qi 'X-GFF-Pool: gestaofinanceirafamiliar' <<<"$headers"; then
            success "Teste: /up respondeu 200 no vhost próprio"
        else
            warn "Falhou: /up respondeu HTTP ${code:-000} sem o vhost próprio"
            failed=1
        fi
    fi
    (( failed == 0 )) || die "Há testes falhando. O Integrar foi conferido; veja os avisos acima."
}

parse_args() {
    while (($#)); do
        case "$1" in
            --yes) YES=true ;;
            --dry-run) DRY_RUN=true ;;
            --skip-packages) SKIP_PACKAGES=true ;;
            --skip-database) SKIP_DATABASE=true ;;
            --skip-app) SKIP_APP=true ;;
            --skip-build) SKIP_BUILD=true ;;
            --skip-ssl) SKIP_SSL=true ;;
            --project-dir|--domain|--app-user|--db-name|--db-user|--db-password|--ssl-email)
                (($# >= 2)) || die "Valor ausente para $1."
                case "$1" in
                    --project-dir) PROJECT_DIR="$2" ;;
                    --domain) DOMAIN="$2" ;;
                    --app-user) APP_USER="$2" ;;
                    --db-name) DB_NAME="$2" ;;
                    --db-user) DB_USER="$2" ;;
                    --db-password) DB_PASSWORD="$2" ;;
                    --ssl-email) SSL_EMAIL="$2" ;;
                esac
                shift
                ;;
            -h|--help) usage; exit 0 ;;
            *) die "Opção desconhecida: $1." ;;
        esac
        shift
    done
}

main() {
    parse_args "$@"
    require_root
    validate_inputs
    check_platform
    if systemctl is-active --quiet redis-server; then
        REDIS_WAS_ACTIVE=true
    fi
    snapshot_protected
    remember_integrar_health

    cat <<EOF

Gestão Financeira Familiar — preparação do servidor
  Projeto:   $PROJECT_DIR
  Domínio:   $DOMAIN
  Usuário:   $APP_USER:$APP_GROUP
  Banco:     PostgreSQL ${DB_NAME} / ${DB_USER}
  Pool FPM:  $FPM_SOCKET

Fora de alcance: vhosts do Integrar, pool PHP www, MySQL, Docker,
units integrar-* e o extrator em supervisord.

EOF

    confirm "Continuar sem alterar a aplicação Integrar?" || die "Execução cancelada."

    $SKIP_PACKAGES || action "Instalar PostgreSQL, Redis e extensões PHP que faltam" install_packages
    $SKIP_DATABASE || action "Criar banco e usuário PostgreSQL locais" configure_database
    action "Ativar Redis apenas em 127.0.0.1" configure_redis
    action "Criar pool PHP-FPM exclusivo e recarregar o FPM" configure_php_pool

    if [[ ! -f "$PROJECT_DIR/artisan" ]]; then
        warn "Código ainda não está em $PROJECT_DIR."
        warn "Envie o projeto para esse caminho e execute o script de novo para Apache, build e serviços."
        assert_protected_unchanged
        assert_integrar_still_healthy
        exit 0
    fi

    $SKIP_APP || action "Configurar aplicação, dependências e migrations" configure_application
    action "Publicar vhost próprio e recarregar o Apache" configure_apache
    $SKIP_SSL || action "Emitir certificado só para ${DOMAIN}" configure_ssl
    $SKIP_APP || action "Ativar Horizon e agenda próprios" configure_systemd
    run_health_checks

    printf '\n'
    success "Ambiente preparado para https://${DOMAIN}"
    if ((${#COMPLETED_ACTIONS[@]})); then
        info "Etapas concluídas: ${COMPLETED_ACTIONS[*]}"
    fi
}

main "$@"
