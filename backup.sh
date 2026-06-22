#!/usr/bin/env bash
set -euo pipefail

# ============================================================
# REST API Messages — Backup Script
# ============================================================

BACKUP_DIR="/var/backups/rest_api"
APP_DIR="/var/www/rest_api"
TIMESTAMP=$(date '+%Y%m%d_%H%M%S')
RETENTION_DAYS=30

mkdir -p "$BACKUP_DIR"

# Бэкап данных (JSON-файл)
tar -czf "${BACKUP_DIR}/data_${TIMESTAMP}.tar.gz" -C "$APP_DIR" data/

# Бэкап .env
cp "${APP_DIR}/.env" "${BACKUP_DIR}/env_${TIMESTAMP}.bak"

# Удаление старых бэкапов
find "$BACKUP_DIR" -name "*.tar.gz" -mtime +${RETENTION_DAYS} -delete
find "$BACKUP_DIR" -name "*.bak" -mtime +${RETENTION_DAYS} -delete

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Backup completed: ${BACKUP_DIR}/data_${TIMESTAMP}.tar.gz"
