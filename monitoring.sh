#!/usr/bin/env bash
set -euo pipefail

# ============================================================
# REST API Messages — Health Monitoring Script
# ============================================================

HEALTH_URL="https://api.your-domain.com/health"
ALERT_EMAIL="admin@your-domain.com"
LOG_FILE="/var/log/rest-api-monitoring.log"

check_health() {
    local status
    status=$(curl -sf -o /dev/null -w "%{http_code}" "$HEALTH_URL" 2>/dev/null || echo "000")

    if [ "$status" != "200" ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] ALERT: Health check failed (HTTP $status)" | tee -a "$LOG_FILE"

        # Отправка уведомления (если настроена почта)
        if command -v mail &> /dev/null; then
            echo "REST API Health Check Failed! HTTP Status: $status" | \
                mail -s "ALERT: REST API Down" "$ALERT_EMAIL" 2>/dev/null || true
        fi

        # Попытка перезапуска
        cd /var/www/rest_api
        sudo docker compose restart 2>/dev/null || true

        # Повторная проверка через 10 секунд
        sleep 10
        status=$(curl -sf -o /dev/null -w "%{http_code}" "$HEALTH_URL" 2>/dev/null || echo "000")

        if [ "$status" != "200" ]; then
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] CRITICAL: Service still down after restart" | tee -a "$LOG_FILE"
        else
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] RECOVERED: Service restored after restart" | tee -a "$LOG_FILE"
        fi
    else
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] OK: Health check passed (HTTP $status)" >> "$LOG_FILE"
    fi
}

check_health
