# Production Deployment Guide — REST API Messages v1.0

## Содержание

1. [Анализ технологического стека](#1-анализ-технологического-стека)
2. [Архитектура приложения](#2-архитектура-приложения)
3. [Подготовка production-сервера](#3-подготовка-production-сервера)
4. [Настройка переменных окружения](#4-настройка-переменных-окружения)
5. [Оптимизированные файлы контейнеризации](#5-оптимизированные-файлы-контейизации)
6. [Настройка обратного прокси-сервера с SSL](#6-настройка-обратного-прокси-сервера-с-ssl)
7. [Автоматизация непрерывного развертывания (CI/CD)](#7-автоматизация-непрерывного-развертывания-cicd)
8. [Безопасность и отказоустойчивость](#8-безопасность-и-отказоустойчивость)
9. [Мониторинг и логирование](#9-мониторинг-и-логирование)
10. [Troubleshooting](#10-troubleshooting)

---

## 1. Анализ технологического стека

| Компонент | Технология | Версия | Назначение |
|-----------|-----------|--------|------------|
| Язык | PHP | 8.4 (FPM) | Обработка запросов |
| Фреймворк | Slim Framework | ^4.14 | Маршрутизация, PSR-7/15 |
| PSR-7 реализация | slim/psr7 | ^1.7 | HTTP-сообщения |
| Переменные окружения | vlucas/phpdotenv | ^5.6 | Загрузка `.env` |
| Генерация OpenAPI | zircote/swagger-php | ^5.0 | Аннотации API (dev) |
| Веб-сервер (контейнер) | Nginx | Alpine | Reverse proxy → PHP-FPM |
| Контейнеризация | Docker + Compose | — | Изоляция сервисов |
| Хранилище данных | JSON-файл | — | `data/messages.json` |

**Ключевые зависимости:**
- [`composer.json`](composer.json) — PHP `^8.2`, slim/slim `^4.14`, slim/psr7 `^1.7`, vlucas/phpdotenv `^5.6`
- Точка входа: [`public/index.php`](public/index.php)
- Контроллеры: [`src/Controllers/MessageController.php`](src/Controllers/MessageController.php), [`src/Controllers/HealthController.php`](src/Controllers/HealthController.php)
- Сервис: [`src/Services/MessageService.php`](src/Services/MessageService.php)
- Middleware: [`src/Middleware/CorsMiddleware.php`](src/Middleware/CorsMiddleware.php)

---

## 2. Архитектура приложения

```
┌──────────────────────────────────────────────────────────┐
│                    Production Server                     │
│                                                          │
│  ┌──────────────┐     ┌───────────────────────────────┐  │
│  │ Host Nginx   │     │     Docker Compose Stack      │  │
│  │ (Reverse     │───> │  ┌──────────┐   ┌───────────┐ │  │
│  │  Proxy+SSL)  │     │  │  Nginx   │─> │ PHP-FPM   │ │  │
│  │  :443/:80    │     │  │  (Alpine)│   │ (8.4-FPM) │ │  │
│  └──────────────┘     │  └────┬─────┘   └─────┬─────┘ │  │
│                       │       │   php-socket  │       │  │
│                       │       └───────────────┘       │  │
│                       │            (volume)           │  │
│                       └───────────────────────────────┘  │
│                                     │                    │
│                       ┌─────────────┴───────────────┐    │
│                       │  data/messages.json (JSON)  │    │
│                       └─────────────────────────────┘    │
└──────────────────────────────────────────────────────────┘
```

**Поток запроса:**
1. Клиент → Host Nginx (:443 SSL)
2. Host Nginx → Docker Nginx (:8080)
3. Docker Nginx → PHP-FPM через Unix-сокет `/var/run/php/php-fpm.sock`
4. PHP-FPM → Slim Framework → [`public/index.php`](public/index.php) → Controller → Service → JSON-файл

**Коммуникация между контейнерами:**
- Nginx и PHP-FPM связаны через named volume `php-socket` (Unix domain socket)
- Оба контейнера в bridge-сети `rest_api_network`
- Nginx слушает порт 80 внутри контейнера, проброшен на `:8080` хоста

---

## 3. Подготовка production-сервера

### 3.1. Минимальные требования

| Ресурс | Минимум | Рекомендация |
|--------|---------|--------------|
| CPU | 1 vCPU | 2+ vCPU |
| RAM | 1 GB | 2+ GB |
| Disk | 10 GB SSD | 20+ GB SSD |
| OS | Ubuntu 22.04 LTS / Debian 12 / AlmaLinux 9 | Ubuntu 24.04 LTS |

### 3.2. Первоначальная настройка сервера (Ubuntu/Debian)

```bash
# Обновление системы
sudo apt update && sudo apt upgrade -y

# Установка базовых утилит
sudo apt install -y curl wget git unzip htop fail2ban ufw

# Создание пользователя для деплоя (если ещё нет)
sudo adduser deploy
sudo usermod -aG sudo deploy

# Настройка SSH (отключение root-входа)
sudo sed -i 's/^PermitRootLogin yes/PermitRootLogin no/' /etc/ssh/sshd_config
sudo sed -i 's/^#PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
sudo systemctl restart sshd
```

### 3.3. Установка Docker и Docker Compose

```bash
# Удаление старых версий (если есть)
sudo apt remove -y docker docker-engine docker.io containerd runc 2>/dev/null

# Установка зависимостей
sudo apt install -y ca-certificates curl gnupg lsb-release

# Добавление GPG-ключа Docker
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | \
    sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

# Добавление репозитория
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu \
  $(lsb_release -cs) stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

# Установка Docker
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io \
    docker-buildx-plugin docker-compose-plugin

# Запуск и автозапуск Docker
sudo systemctl enable --now docker

# Добавление пользователя deploy в группу docker
sudo usermod -aG docker deploy
newgrp docker

# Проверка
docker --version
docker compose version
```

### 3.4. Для AlmaLinux / CentOS 9

```bash
sudo dnf update -y
sudo dnf install -y dnf-utils curl git unzip htop

# Docker
sudo dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
sudo dnf install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
sudo systemctl enable --now docker
sudo usermod -aG docker deploy

# Firewall
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload
```

### 3.5. Настройка Firewall (UFW для Ubuntu)

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp    # SSH
sudo ufw allow 80/tcp    # HTTP
sudo ufw allow 443/tcp   # HTTPS
sudo ufw --force enable
sudo ufw status
```

### 3.6. Настройка Fail2Ban

```bash
sudo cp /etc/fail2ban/jail.conf /etc/fail2ban/jail.local
sudo sed -i 's/bantime  = 10m/bantime  = 1h/' /etc/fail2ban/jail.local
sudo sed -i 's/maxretry = 5/maxretry = 3/' /etc/fail2ban/jail.local
sudo systemctl enable --now fail2ban
```

---

## 4. Настройка переменных окружения

### 4.1. Создание `.env` файла

```bash
cd /var/www
sudo git clone <repository_url> rest_api
cd rest_api

# Копирование шаблона
cp .env.example .env
```

### 4.2. Содержимое `.env` для production

```bash
# OpenAPI Servers configuration
# Production server URL
OPENAPI_SERVERS='[{"url":"https://api.your-domain.com/api/v1.0","description":"Production server"}]'
```

### 4.3. Защита `.env` файла

```bash
# Владелец — deploy, группа — www-data (или docker)
sudo chown deploy:deploy .env
sudo chmod 600 .env

# Убедиться, что .env в .gitignore (уже есть)
grep -q '\.env' .gitignore && echo "OK: .env in .gitignore" || echo "WARNING: .env NOT in .gitignore"
```

### 4.4. Переменные окружения для Docker

Создайте файл `docker.env` для Docker-сервисов (если нужны дополнительные переменные):

```bash
# docker.env
PHP_MEMORY_LIMIT=256M
PHP_MAX_EXECUTION_TIME=30
PHP_POST_MAX_SIZE=20M
PHP_UPLOAD_MAX_FILESIZE=10M
TZ=Europe/Moscow
```

---

## 5. Оптимизированные файлы контейнеризации

### 5.1. Оптимизированный [`Dockerfile`](Dockerfile) для production

Замените текущий `Dockerfile` на production-версию с multi-stage build:

```dockerfile
# ============================================================
# Stage 1: Build dependencies
# ============================================================
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --classmap-authoritative

# ============================================================
# Stage 2: Production image
# ============================================================
FROM php:8.4-fpm-alpine

LABEL maintainer="devops@your-domain.com"
LABEL description="REST API Messages - Production"

# Установка расширений PHP (если потребуются)
RUN apk add --no-cache \
    icu-libs \
    && docker-php-ext-install opcache

# Системные утилиты для healthcheck
RUN apk add --no-cache curl

# Создание пользователя
RUN addgroup -g 1000 appgroup && \
    adduser -u 1000 -G appgroup -s /bin/sh -D appuser

# Рабочая директория
WORKDIR /var/www/html

# Копирование зависимостей из builder
COPY --from=vendor /app/vendor ./vendor
COPY --chown=appuser:appgroup . .

# Создание директории data с правильными правами
RUN mkdir -p data && chown -R appuser:appgroup data

# Копирование и применение production PHP-FPM конфигурации
COPY docker/php/production-www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/php/production-opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/production-php.ini /usr/local/etc/php/conf.d/production.ini

USER appuser

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:9000/health || exit 1

CMD ["php-fpm"]
```

### 5.2. Production PHP-FPM конфигурация

Создайте файл `docker/php/production-www.conf`:

```ini
[www]
user = appuser
group = appgroup

listen = 9000
listen.owner = appuser
listen.group = appgroup
listen.mode = 0660

; Process Manager
pm = dynamic
pm.max_children = 20
pm.start_servers = 5
pm.min_spare_servers = 3
pm.max_spare_servers = 10
pm.max_requests = 500
pm.process_idle_timeout = 10s

; Status page (для мониторинга)
pm.status_path = /fpm-status
ping.path = /fpm-ping
ping.response = pong

; Logging
access.log = /proc/self/fd/2
access.format = "%R - %u %t \"%m %r%Q%q\" %s %f %{mili}d %{kilo}M %C%%"

; Security
security.limit_extensions = .php
catch_workers_output = yes
decorate_workers_output = no
```

### 5.3. Production OPcache конфигурация

Создайте файл `docker/php/production-opcache.ini`:

```ini
[opcache]
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.max_wasted_percentage = 10
opcache.validate_timestamps = 0
opcache.revalidate_freq = 0
opcache.save_comments = 0
opcache.enable_file_override = 1
opcache.jit = tracing
opcache.jit_buffer_size = 128M
```

### 5.4. Production PHP конфигурация

Создайте файл `docker/php/production-php.ini`:

```ini
[PHP]
; Security
expose_php = Off
display_errors = Off
display_startup_errors = Off
log_errors = On
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT

; Performance
memory_limit = 256M
max_execution_time = 30
max_input_time = 30
post_max_size = 20M
upload_max_filesize = 10M
max_input_vars = 1000

; Session
session.use_strict_mode = 1
session.use_cookies = 1
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = Lax

; Timezone
date.timezone = UTC
```

### 5.5. Оптимизированный [`docker-compose.yml`](docker-compose.yml) для production

```yaml
services:
  # PHP-FPM контейнер
  php:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: rest_api_php
    restart: unless-stopped
    volumes:
      - app-data:/var/www/html/data
      - php-socket:/var/run/php
    networks:
      - rest_api_network
    environment:
      - TZ=UTC
    env_file:
      - .env
    deploy:
      resources:
        limits:
          cpus: '1.0'
          memory: 512M
        reservations:
          cpus: '0.25'
          memory: 128M
    healthcheck:
      test: ["CMD-SHELL", "curl -f http://localhost:9000/fpm-ping || exit 1"]
      interval: 30s
      timeout: 3s
      retries: 3
      start_period: 10s
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

  # Nginx контейнер
  nginx:
    build:
      context: ./docker/nginx
      dockerfile: Dockerfile
    container_name: rest_api_nginx
    restart: unless-stopped
    ports:
      - "127.0.0.1:8080:80"
    volumes:
      - app-public:/var/www/html/public:ro
      - app-data:/var/www/html/data:ro
      - php-socket:/var/run/php:ro
    depends_on:
      php:
        condition: service_healthy
    networks:
      - rest_api_network
    deploy:
      resources:
        limits:
          cpus: '0.5'
          memory: 128M
    healthcheck:
      test: ["CMD-SHELL", "curl -f http://localhost:80/health || exit 1"]
      interval: 30s
      timeout: 3s
      retries: 3
      start_period: 5s
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

volumes:
  php-socket:
    driver: local
  app-data:
    driver: local
  app-public:
    driver: local

networks:
  rest_api_network:
    driver: bridge
    ipam:
      config:
        - subnet: 172.28.0.0/16
```

### 5.6. Оптимизированный Nginx конфиг для контейнера

Замените [`docker/nginx/nginx.conf`](docker/nginx/nginx.conf):

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml;

    # Request limits
    client_max_body_size 20m;
    client_body_buffer_size 128k;

    # Timeouts
    fastcgi_read_timeout 30;
    fastcgi_send_timeout 30;

    # Static files caching
    location ~* \.(ico|css|js|gif|jpg|jpeg|png|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    # Main location
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM через Unix-сокет
    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;

        # FastCGI buffering
        fastcgi_buffer_size 128k;
        fastcgi_buffers 4 256k;
        fastcgi_busy_buffers_size 256k;
    }

    # Запрет доступа к скрытым файлам
    location ~ /\. {
        deny all;
        return 404;
    }

    # Запрет доступа к чувствительным файлам
    location ~* \.(env|log|git|htaccess|htpasswd)$ {
        deny all;
        return 404;
    }

    # Health check endpoint (без логирования)
    location = /health {
        access_log off;
        try_files $uri /index.php?$query_string;
    }

    # Disable access to sensitive paths
    location ~ ^/(composer\.(json|lock)|Dockerfile|docker-compose\.yml) {
        deny all;
        return 404;
    }
}
```

### 5.7. Обновлённый [`docker/nginx/Dockerfile`](docker/nginx/Dockerfile)

```dockerfile
FROM nginx:1.27-alpine

LABEL maintainer="devops@your-domain.com"

# Удаление дефолтного конфига
RUN rm -f /etc/nginx/conf.d/default.conf

# Копирование production-конфига
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Создание пользователя nginx (уже есть в alpine, но явно)
RUN addgroup -g 1000 -S appgroup 2>/dev/null || true && \
    adduser -u 1000 -S appuser -G appgroup 2>/dev/null || true

# Создание директорий с правильными правами
RUN mkdir -p /var/www/html/public /var/www/html/data /var/run/php && \
    chown -R nginx:nginx /var/www/html /var/run/php /var/cache/nginx /var/log/nginx && \
    chmod -R 755 /var/www/html

# Healthcheck
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD wget -qO- http://localhost:80/health || exit 1

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
```

---

## 6. Настройка обратного прокси-сервера с SSL

### 6.1. Установка Nginx на хосте

```bash
sudo apt install -y nginx
sudo systemctl enable nginx
```

### 6.2. Конфигурация HTTP → HTTPS redirect

```bash
sudo tee /etc/nginx/sites-available/rest-api.conf > /dev/null <<'EOF'
# HTTP → HTTPS redirect
server {
    listen 80;
    listen [::]:80;
    server_name api.your-domain.com;

    # Let's Encrypt challenge
    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
        allow all;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

# HTTPS server
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name api.your-domain.com;

    # SSL certificates (Let's Encrypt)
    ssl_certificate /etc/letsencrypt/live/api.your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.your-domain.com/privkey.pem;

    # SSL configuration (modern, TLS 1.2+)
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    ssl_session_tickets off;

    # OCSP Stapling
    ssl_stapling on;
    ssl_stapling_verify on;
    resolver 8.8.8.8 8.8.4.4 valid=300s;
    resolver_timeout 5s;

    # Security headers
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;

    # Request limits
    client_max_body_size 20m;
    client_body_buffer_size 128k;

    # Gzip
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml;

    # Logging
    access_log /var/log/nginx/rest-api-access.log;
    error_log /var/log/nginx/rest-api-error.log warn;

    # Proxy to Docker Nginx
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;

        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;

        # Timeouts
        proxy_connect_timeout 10s;
        proxy_send_timeout 30s;
        proxy_read_timeout 30s;

        # Buffering
        proxy_buffer_size 128k;
        proxy_buffers 4 256k;
        proxy_busy_buffers_size 256k;
    }

    # Health check (для мониторинга)
    location = /health {
        proxy_pass http://127.0.0.1:8080/health;
        access_log off;
    }

    # Rate limiting
    location /api/ {
        limit_req zone=api burst=20 nodelay;
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
EOF
```

### 6.3. Rate limiting конфигурация

Добавьте в `/etc/nginx/nginx.conf` в блок `http`:

```nginx
# Rate limiting zones
limit_req_zone $binary_remote_addr zone=api:10m rate=30r/s;
limit_req_zone $binary_remote_addr zone=login:10m rate=5r/m;

# Connection limiting
limit_conn_zone $binary_remote_addr zone=addr:10m;
```

### 6.4. Активация конфигурации

```bash
# Создание директории для certbot
sudo mkdir -p /var/www/certbot

# Проверка конфигурации
sudo nginx -t

# Активация сайта
sudo ln -sf /etc/nginx/sites-available/rest-api.conf /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default

# Перезагрузка Nginx
sudo systemctl reload nginx
```

### 6.5. Получение SSL-сертификата Let's Encrypt

```bash
# Установка Certbot
sudo apt install -y certbot python3-certbot-nginx

# Получение сертификата (сначала остановите Nginx или используйте webroot)
sudo certbot certonly --webroot -w /var/www/certbot \
    -d api.your-domain.com \
    --email admin@your-domain.com \
    --agree-tos \
    --no-eff-email

# Или через nginx plugin
sudo certbot --nginx -d api.your-domain.com \
    --email admin@your-domain.com \
    --agree-tos \
    --redirect

# Проверка
sudo ls -la /etc/letsencrypt/live/api.your-domain.com/

# Перезагрузка Nginx
sudo systemctl reload nginx
```

### 6.6. Автообновление SSL-сертификата

```bash
# Проверка автообновления (уже настроено по умолчанию)
sudo certbot renew --dry-run

# Cron-задача для обновления (если не настроено)
sudo tee /etc/cron.d/certbot-renew > /dev/null <<'EOF'
0 3 * * * root certbot renew --quiet --post-hook "systemctl reload nginx"
EOF
```

---

## 7. Автоматизация непрерывного развертывания (CI/CD)

### 7.1. GitHub Actions

Создайте файл `.github/workflows/deploy.yml`:

```yaml
name: Deploy to Production

on:
  push:
    branches: [main, master]
  workflow_dispatch:

env:
  DOCKER_IMAGE: rest-api-messages
  CONTAINER_NAME: rest_api

jobs:
  deploy:
    name: Deploy
    runs-on: ubuntu-latest
    environment: production

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          tools: composer:v2

      - name: Validate composer.json
        run: composer validate --strict

      - name: Install dependencies
        run: composer install --no-dev --optimize-autoloader --no-interaction

      - name: Run tests (if exist)
        run: |
          if [ -f "phpunit.xml" ]; then
            composer test
          fi

      - name: Generate OpenAPI spec
        run: composer openapi || true

      - name: Deploy via SSH
        uses: appleboy/ssh-action@v1
        with:
          host: ${{ secrets.SERVER_HOST }}
          username: ${{ secrets.SERVER_USER }}
          key: ${{ secrets.SSH_PRIVATE_KEY }}
          script: |
            cd /var/www/rest_api

            # Backup current state
            sudo docker compose down || true

            # Pull latest changes
            git fetch origin
            git reset --hard origin/main

            # Update .env if needed
            # cp /path/to/production/.env .env

            # Build and start
            sudo docker compose build --no-cache
            sudo docker compose up -d

            # Wait for health check
            sleep 10

            # Verify deployment
            curl -sf http://localhost:8080/health || exit 1

            # Cleanup old images
            sudo docker image prune -f

      - name: Notify on success
        if: success()
        run: |
          echo "✅ Deployment successful!"
          # Optional: send notification
          # curl -X POST ${{ secrets.WEBHOOK_URL }} -d '{"text":"Deployment successful"}'

      - name: Notify on failure
        if: failure()
        run: |
          echo "❌ Deployment failed!"
          # Optional: send notification
```

### 7.2. GitLab CI/CD

Создайте файл `.gitlab-ci.yml`:

```yaml
stages:
  - test
  - build
  - deploy

variables:
  DOCKER_IMAGE: rest-api-messages
  CONTAINER_NAME: rest_api

# Тестирование
test:
  stage: test
  image: php:8.4-cli
  before_script:
    - apt-get update && apt-get install -y git unzip
    - curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
  script:
    - composer validate --strict
    - composer install --no-dev --optimize-autoloader
    - composer openapi || true
  only:
    - main
    - master

# Сборка образа
build:
  stage: build
  image: docker:24
  services:
    - docker:24-dind
  before_script:
    - docker login -u $CI_REGISTRY_USER -p $CI_REGISTRY_PASSWORD $CI_REGISTRY
  script:
    - docker build -t $CI_REGISTRY_IMAGE:$CI_COMMIT_SHA .
    - docker push $CI_REGISTRY_IMAGE:$CI_COMMIT_SHA
    - docker tag $CI_REGISTRY_IMAGE:$CI_COMMIT_SHA $CI_REGISTRY_IMAGE:latest
    - docker push $CI_REGISTRY_IMAGE:latest
  only:
    - main
    - master

# Деплой
deploy:
  stage: deploy
  image: alpine:latest
  before_script:
    - apk add --no-cache openssh-client
    - eval $(ssh-agent -s)
    - echo "$SSH_PRIVATE_KEY" | ssh-add -
    - mkdir -p ~/.ssh
    - echo "$SSH_KNOWN_HOSTS" >> ~/.ssh/known_hosts
    - chmod 600 ~/.ssh/*
  script:
    - |
      ssh $DEPLOY_USER@$DEPLOY_HOST << 'ENDSSH'
        cd /var/www/rest_api
        git fetch origin
        git reset --hard origin/main
        sudo docker compose build --no-cache
        sudo docker compose up -d
        sleep 10
        curl -sf http://localhost:8080/health || exit 1
        sudo docker image prune -f
      ENDSSH
  only:
    - main
    - master
  when: manual
  environment:
    name: production
    url: https://api.your-domain.com
```

### 7.3. Deploy-скрипт для ручного развертывания

Создайте файл `deploy.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

# Конфигурация
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

    # Бэкап данных
    if [ -d "${APP_DIR}/data" ]; then
        cp -r "${APP_DIR}/data" "${backup_path}/"
    fi

    # Бэкап .env
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
            log "Health check passed ✅"
            return 0
        fi
        retries=$((retries + 1))
        log "Health check attempt ${retries}/${MAX_RETRIES} failed, retrying in ${RETRY_INTERVAL}s..."
        sleep $RETRY_INTERVAL
    done

    log "Health check failed after ${MAX_RETRIES} attempts ❌"
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

    # Восстановление данных
    if [ -d "${latest_backup}/data" ]; then
        cp -r "${latest_backup}/data" "${APP_DIR}/"
    fi

    # Перезапуск
    cd "$APP_DIR"
    sudo docker compose down
    sudo docker compose up -d

    log "Rollback completed"
}

# Основной процесс деплоя
deploy() {
    log "=== Starting deployment ==="

    cd "$APP_DIR"

    # Бэкап
    backup

    # Pull последних изменений
    log "Pulling latest changes..."
    git fetch origin
    git reset --hard origin/main

    # Остановка старых контейнеров
    log "Stopping old containers..."
    sudo docker compose down

    # Сборка новых образов
    log "Building new images..."
    sudo docker compose build --no-cache

    # Запуск
    log "Starting new containers..."
    sudo docker compose up -d

    # Проверка здоровья
    if health_check; then
        log "=== Deployment successful ✅ ==="
    else
        log "=== Deployment failed, rolling back... ==="
        rollback
        exit 1
    fi

    # Очистка старых образов
    log "Cleaning up old images..."
    sudo docker image prune -f

    log "=== Deployment completed ==="
}

# Запуск
deploy "$@"
```

```bash
# Сделать скрипт исполняемым
chmod +x deploy.sh

# Запуск деплоя
sudo ./deploy.sh
```

### 7.4. Systemd-сервис для автозапуска

```bash
sudo tee /etc/systemd/system/rest-api.service > /dev/null <<'EOF'
[Unit]
Description=REST API Messages (Docker Compose)
Requires=docker.service
After=docker.service
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=/var/www/rest_api
ExecStart=/usr/bin/docker compose up -d
ExecStop=/usr/bin/docker compose down
ExecReload=/usr/bin/docker compose restart
TimeoutStartSec=120
TimeoutStopSec=30

# Environment
Environment=COMPOSE_PROJECT_NAME=rest_api

# Restart policy
Restart=on-failure
RestartSec=10

# Logging
StandardOutput=journal
StandardError=journal
SyslogIdentifier=rest-api

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now rest-api.service
sudo systemctl status rest-api.service
```

---

## 8. Безопасность и отказоустойчивость

### 8.1. Чек-лист безопасности

| # | Проверка | Статус |
|---|----------|--------|
| 1 | `.env` не в git | ✅ В `.gitignore` |
| 2 | `expose_php = Off` | ✅ В production-php.ini |
| 3 | `display_errors = Off` | ✅ В production-php.ini |
| 4 | HTTPS включён | ✅ Let's Encrypt |
| 5 | HSTS header | ✅ `max-age=63072000` |
| 6 | Rate limiting | ✅ Nginx `limit_req` |
| 7 | Security headers | ✅ X-Frame-Options, CSP и др. |
| 8 | Non-root пользователь в контейнере | ✅ `appuser` |
| 9 | Read-only volumes | ✅ `:ro` для public/data |
| 10 | Docker socket не проброшен | ✅ |
| 11 | Fail2Ban | ✅ Настроен |
| 12 | Firewall | ✅ UFW: 22, 80, 443 |

### 8.2. Дополнительные меры безопасности

```bash
# Ограничение доступа к Docker socket
sudo chmod 660 /var/run/docker.sock

# Аудит контейнеров
sudo docker inspect rest_api_php | grep -i "privileged"
sudo docker inspect rest_api_nginx | grep -i "privileged"

# Проверка открытых портов
sudo ss -tlnp | grep -E ':(80|443|8080)\s'

# Сканирование уязвимостей (Trivy)
sudo docker run --rm -v /var/run/docker.sock:/var/run/docker.sock \
    aquasec/trivy:latest image rest_api_php
```

### 8.3. Резервное копирование данных

Создайте скрипт `backup.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="/var/backups/rest_api"
APP_DIR="/var/www/rest_api"
TIMESTAMP=$(date '+%Y%m%d_%H%M%S')
RETENTION_DAYS=30

mkdir -p "$BACKUP_DIR"

# Бэкап данных
tar -czf "${BACKUP_DIR}/data_${TIMESTAMP}.tar.gz" -C "$APP_DIR" data/

# Бэкап .env
cp "${APP_DIR}/.env" "${BACKUP_DIR}/env_${TIMESTAMP}.bak"

# Удаление старых бэкапов
find "$BACKUP_DIR" -name "*.tar.gz" -mtime +${RETENTION_DAYS} -delete
find "$BACKUP_DIR" -name "*.bak" -mtime +${RETENTION_DAYS} -delete

echo "Backup completed: ${BACKUP_DIR}/data_${TIMESTAMP}.tar.gz"
```

```bash
# Cron для ежедневного бэкапа в 2:00
sudo tee /etc/cron.d/rest-api-backup > /dev/null <<'EOF'
0 2 * * * root /var/www/rest_api/backup.sh >> /var/log/rest-api-backup.log 2>&1
EOF

chmod +x backup.sh
```

### 8.4. Мониторинг доступности

Создайте файл `monitoring.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

HEALTH_URL="https://api.your-domain.com/health"
ALERT_EMAIL="admin@your-domain.com"
LOG_FILE="/var/log/rest-api-monitoring.log"

check_health() {
    local status
    status=$(curl -sf -o /dev/null -w "%{http_code}" "$HEALTH_URL" 2>/dev/null || echo "000")

    if [ "$status" != "200" ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] ALERT: Health check failed (HTTP $status)" | tee -a "$LOG_FILE"

        # Отправка уведомления
        echo "REST API Health Check Failed! HTTP Status: $status" | \
            mail -s "ALERT: REST API Down" "$ALERT_EMAIL"

        # Попытка перезапуска
        cd /var/www/rest_api
        sudo docker compose restart

        # Повторная проверка через 10 секунд
        sleep 10
        status=$(curl -sf -o /dev/null -w "%{http_code}" "$HEALTH_URL" 2>/dev/null || echo "000")

        if [ "$status" != "200" ]; then
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] CRITICAL: Service still down after restart" | tee -a "$LOG_FILE"
        fi
    else
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] OK: Health check passed" >> "$LOG_FILE"
    fi
}

check_health
```

```bash
# Cron для проверки каждые 5 минут
sudo tee /etc/cron.d/rest-api-monitoring > /dev/null <<'EOF'
*/5 * * * * root /var/www/rest_api/monitoring.sh
EOF

chmod +x monitoring.sh
```

---

## 9. Мониторинг и логирование

### 9.1. Просмотр логов

```bash
# Логи Docker
sudo docker compose logs -f
sudo docker compose logs -f php
sudo docker compose logs -f nginx

# Логи Nginx (хост)
sudo tail -f /var/log/nginx/rest-api-access.log
sudo tail -f /var/log/nginx/rest-api-error.log

# PHP-FPM логи
sudo docker compose exec php cat /proc/self/fd/2
```

### 9.2. Метрики PHP-FPM

```bash
# Статус PHP-FPM
sudo docker compose exec php curl -s http://localhost:9000/fpm-status

# Ping check
sudo docker compose exec php curl -s http://localhost:9000/fpm-ping
```

### 9.3. Использование ресурсов

```bash
# Статистика контейнеров
sudo docker stats

# Проверка здоровья
sudo docker compose ps
sudo docker inspect --format='{{.State.Health.Status}}' rest_api_php
sudo docker inspect --format='{{.State.Health.Status}}' rest_api_nginx
```

---

## 10. Troubleshooting

### 10.1. Частые проблемы

| Проблема | Решение |
|----------|---------|
| `502 Bad Gateway` | Проверьте, что PHP-FPM запущен: `docker compose ps php` |
| `Permission denied` на `data/` | `sudo chown -R 1000:1000 data/` |
| Контейнер не стартует | `docker compose logs php` — проверьте ошибки |
| SSL certificate expired | `sudo certbot renew && sudo systemctl reload nginx` |
| `No space left on device` | `sudo docker system prune -a` |
| Медленные ответы | Проверьте OPcache: `docker compose exec php php -i \| grep opcache` |

### 10.2. Полезные команды

```bash
# Полная пересборка с очисткой
sudo docker compose down -v
sudo docker system prune -af
sudo docker compose build --no-cache
sudo docker compose up -d

# Вход в контейнер
sudo docker compose exec php sh
sudo docker compose exec nginx sh

# Проверка конфигурации Nginx
sudo docker compose exec nginx nginx -t

# Проверка PHP конфигурации
sudo docker compose exec php php -i | grep -E "memory_limit|max_execution_time"

# Перезапуск только PHP
sudo docker compose restart php

# Просмотр volume данных
sudo docker volume inspect rest_api_app-data
```

### 10.3. Восстановление после сбоя

```bash
# 1. Остановка всех контейнеров
sudo docker compose down

# 2. Проверка целостности данных
ls -la /var/www/rest_api/data/
cat /var/www/rest_api/data/messages.json | python3 -m json.tool

# 3. Восстановление из бэкапа (если нужно)
sudo cp /var/backups/rest_api/backup_YYYYMMDD_HHMMSS/data/messages.json /var/www/rest_api/data/

# 4. Пересборка и запуск
sudo docker compose build --no-cache
sudo docker compose up -d

# 5. Проверка
curl -sf http://localhost:8080/health
curl -sf http://localhost:8080/api/v1.0/messages
```

---

## Быстрый старт (TL;DR)

```bash
# 1. Подготовка сервера
sudo apt update && sudo apt upgrade -y
sudo apt install -y docker.io docker-compose-v2 nginx certbot python3-certbot-nginx
sudo systemctl enable --now docker

# 2. Деплой приложения
cd /var/www
sudo git clone <repo> rest_api && cd rest_api
cp .env.example .env
# Отредактируйте .env

# 3. Сборка и запуск
sudo docker compose up -d --build

# 4. Настройка Nginx + SSL
sudo certbot --nginx -d api.your-domain.com

# 5. Проверка
curl -sf https://api.your-domain.com/health
```
