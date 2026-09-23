#!/usr/bin/env bash
#
# Backup do PostgreSQL e dos arquivos importados.
# Só aceita o banco gestao_financeira_familiar no container gff-postgres.
#
# Cron:
#   0 7,12,18,22 * * * /ico/fabiano/ft/gestao-financeira-familiar/script-manutencao/backup-automatico.sh >> /ico/fabiano/ft/gestao-financeira-familiar/backups/backup.log 2>&1
#
# Restaurar o banco:
#   gunzip -c backups/backup-gestao_financeira_familiar-AAAAMMDD_HHMMSS.sql.gz \
#     | docker exec -i gff-postgres psql -U postgres -d gestao_financeira_familiar
#
# Restaurar os arquivos:
#   tar -xzf backups/arquivos-gestao_financeira_familiar-AAAAMMDD_HHMMSS.tar.gz -C storage/app/private

set -Eeuo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
readonly CONTAINER="gff-postgres"
readonly EXPECTED_DATABASE="gestao_financeira_familiar"
readonly RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"

log() { echo "[$(date +'%Y-%m-%d %H:%M:%S')] $*"; }
fail() { echo "[$(date +'%Y-%m-%d %H:%M:%S')] ERRO: $*" >&2; exit 1; }

require_application_database() {
    command -v docker >/dev/null 2>&1 || fail "Docker não encontrado."
    docker inspect "$CONTAINER" >/dev/null 2>&1 || fail "Container $CONTAINER não está disponível."

    local database
    database="$(docker exec "$CONTAINER" printenv POSTGRES_DB)"
    [[ "$database" == "$EXPECTED_DATABASE" ]] || fail "Recusado: o container aponta para '$database', não para $EXPECTED_DATABASE."
}

write_database_dump() {
    local destination="$1"

    docker exec "$CONTAINER" pg_dump \
        -U postgres \
        -d "$EXPECTED_DATABASE" \
        --no-owner \
        --no-acl \
        | gzip -c > "$destination"

    gzip -t "$destination" || fail "Dump compactado inválido: $destination"

    local header
    header="$(gzip -dc "$destination" | head -n 30 || true)"
    if ! grep -q "PostgreSQL database dump" <<<"$header"; then
        rm -f "$destination"
        fail "O arquivo não parece um dump do PostgreSQL: $destination"
    fi

    log "Banco salvo em $destination ($(du -h "$destination" | cut -f1))"
}

write_files_archive() {
    local destination="$1"
    local imports_dir="$PROJECT_DIR/storage/app/private/imports"

    if [[ ! -d "$imports_dir" ]]; then
        log "Pasta de importações ausente; arquivo de documentos não gerado."
        return 0
    fi

    tar -czf "$destination" -C "$PROJECT_DIR/storage/app/private" imports
    gzip -t "$destination" || fail "Arquivo de documentos inválido: $destination"
    log "Documentos salvos em $destination ($(du -h "$destination" | cut -f1))"
}

purge_old_backups() {
    local directory="$1" removed=0

    while IFS= read -r -d '' file; do
        rm -f "$file"
        removed=$((removed + 1))
        log "Removido por retenção: $(basename "$file")"
    done < <(find "$directory" -maxdepth 1 -type f \( \
        -name "backup-${EXPECTED_DATABASE}-*.sql.gz" \
        -o -name "arquivos-${EXPECTED_DATABASE}-*.tar.gz" \
        \) -mtime +"$RETENTION_DAYS" -print0)

    if [[ "$removed" -eq 0 ]]; then
        log "Nenhum backup com mais de ${RETENTION_DAYS} dias."
    fi
}

main() {
    require_application_database

    local stamp directory
    stamp="$(date +%Y%m%d_%H%M%S)"
    directory="$PROJECT_DIR/backups"
    mkdir -p "$directory"

    log "Iniciando backup de $EXPECTED_DATABASE"
    write_database_dump "$directory/backup-${EXPECTED_DATABASE}-${stamp}.sql.gz"
    write_files_archive "$directory/arquivos-${EXPECTED_DATABASE}-${stamp}.tar.gz"
    purge_old_backups "$directory"
    log "Backup concluído"
}

main "$@"
