#!/usr/bin/env bash
set -euo pipefail

# ============================================================
# REST API Messages — Production Deploy Script
# ============================================================

APP_DIR="/var/www/rest_api"
BACKUP_DIR="/var/backups/rest_api"
LOG_FILE="/var/log/rest-api-deploy.log"
HEALTH_URL="http://localhost:8080/health"
MAX_RETRIES=5
RETRY_INTERVAL=3

# Логирование
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

# Создание бэкапа
backup() {
    local timestamp
    timestamp=$(date '+%Y%m%d_%H%M%S')
    local backup_path="${BACKUP_DIR}/backup_${timestamp}"

    log "Creating backup at ${backup_path}..."
    mkdir -p "$backup_path"

    if [ -d "${APP_DIR}/data" ]; then
        cp -r "${APP_DIR}/data" "${backup_path}/"
    fi

    if [ -f "${APP_DIR}/.env" ]; then
        cp "${APP_DIR}/.env" "${backup_path}/"
    fi

    # Храним последние 5 бэкапов
    ls -dt "${BACKUP_DIR}"/backup_* 2>/dev/null | tail -n +6 | xargs rm -rf 2>/dev/null || true

    log "Backup created: ${backup_path}"
}

# Проверка здоровья
health_check() {
    local retries=0
    while [ $retries -lt $MAX_RETRIES ]; do
        if curl -sf "$HEALTH_URL" > /dev/null 2>&1; then
            log "Health check passed"
            return 0
        fi
        retries=$((retries + 1))
        log "Health check attempt ${retries}/${MAX_RETRIES} failed, retrying in ${RETRY_INTERVAL}s..."
        sleep $RETRY_INTERVAL
    done

    log "Health check failed after ${MAX_RETRIES} attempts"
    return 1
}

# Откат при ошибке
rollback() {
    log "Rolling back to previous version..."

    local latest_backup
    latest_backup=$(ls -dt "${BACKUP_DIR}"/backup_* 2>/dev/null | head -1)

    if [ -z "$latest_backup" ]; then
        log "No backup found for rollback!"
        return 1
    fi

    if [ -d "${latest_backup}/data" ]; then
        cp -r "${latest_backup}/data" "${APP_DIR}/"
    fi

    cd "$APP_DIR"
    sudo docker compose down
    sudo docker compose up -d

    log "Rollback completed"
}

# Основной процесс деплоя
deploy() {
    log "=== Starting deployment ==="

    cd "$APP_DIR"

    backup

    log "Pulling latest changes..."
    git fetch origin
    git reset --hard origin/main

    log "Stopping old containers..."
    sudo docker compose down

    log "Building new images..."
    sudo docker compose build --no-cache

    log "Starting new containers..."
    sudo docker compose up -d

    if health_check; then
        log "=== Deployment successful ==="
    else
        log "=== Deployment failed, rolling back... ==="
        rollback
        exit 1
    fi

    log "Cleaning up old images..."
    sudo docker image prune -f

    log "=== Deployment completed ==="
}

deploy "$@"
