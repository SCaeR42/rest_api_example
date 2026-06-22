# REST API Messages v1.0

REST API для работы с сообщениями на базе Slim Framework.

## Требования

- PHP 8.2 или выше
- Composer
- Docker и Docker Compose (для запуска в контейнере)

## Установка

```bash
composer install
```

## Запуск

### Встроенный PHP-сервер

```bash
composer start
```

Сервер будет доступен по адресу: http://localhost:8080

### Docker

Архитектура состоит из двух контейнеров, объединенных bridge-сетью `rest_api_network`:
- **nginx** - веб-сервер, принимает HTTP-запросы на порту 8080
- **php** - PHP-FPM, обрабатывает динамические запросы (*.php) через fastcgi_pass

Контейнеры общаются между собой через Unix-сокет `/var/run/php/php-fpm.sock`, который проброшен через общий volume `php-socket`.

Для запуска приложения выполните:

```bash
docker-compose up -d
```

Сервер будет доступен по адресу: http://localhost:8080

Директории `src`, `data` и `public` проброшены через volumes, что позволяет изменять файлы "на лету" без пересборки контейнера.

#### Остановка контейнеров

```bash
docker-compose down
```

#### Пересборка образов

```bash
docker-compose build
```

#### Просмотр логов

```bash
docker-compose logs -f
```

#### Просмотр логов конкретного контейнера

```bash
docker-compose logs -f nginx
docker-compose logs -f php
```

### Apache

Настройте виртуальный хост с DocumentRoot на директорию `public/`.

## Развёртывание на продакшен-сервере (CentOS 9)

### 1. Подготовка сервера

```bash
# Обновление системы
sudo dnf update -y

# Установка Docker и Docker Compose
sudo dnf install -y dnf-utils
sudo dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
sudo dnf install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin

# Запуск и автозапуск Docker
sudo systemctl enable --now docker

# Добавление текущего пользователя в группу docker (опционально)
sudo usermod -aG docker $USER
newgrp docker
```

### 2. Развёртывание приложения

```bash
# Клонирование репозитория
cd /var/www
sudo git clone <repository_url> rest_api
cd rest_api

# Создание директории для данных с правильными правами
sudo mkdir -p data
sudo chown -R 1000:1000 data

# Сборка и запуск контейнеров
sudo docker compose up -d --build

# Проверка статуса
sudo docker compose ps
```

Приложение будет доступно на порту `8080`.

### 3. Настройка firewall

```bash
# Открытие порта 8080
sudo firewall-cmd --permanent --add-port=8080/tcp
sudo firewall-cmd --reload
```

### 4. Настройка SELinux

Если SELinux включён в режиме `enforcing`, необходимо добавить контексты:

```bash
# Проверка статуса SELinux
sestatus

# Разрешить Nginx подключаться к сети
sudo setsebool -P httpd_can_network_connect 1

# Добавить контекст для директории проекта
sudo semanage fcontext -a -t container_file_t "/var/www/rest_api(/.*)?"
sudo restorecon -Rv /var/www/rest_api
```

Если `semanage` отсутствует:
```bash
sudo dnf install -y policycoreutils-python-utils
```

### 5. Настройка Nginx как reverse proxy (опционально)

Для продакшена рекомендуется разместить основной Nginx перед контейнером для обработки SSL/TLS:

```bash
sudo dnf install -y nginx

# Создание конфигурации виртуального хоста
sudo tee /etc/nginx/conf.d/rest_api.conf > /dev/null <<'EOF'
server {
    listen 80;
    server_name your-domain.com;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
EOF

sudo nginx -t
sudo systemctl enable --now nginx
```

### 6. SSL-сертификат Let's Encrypt

```bash
sudo dnf install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
```

### 7. Управление приложением

```bash
# Просмотр логов
sudo docker compose logs -f

# Перезапуск
sudo docker compose restart

# Остановка
sudo docker compose down

# Обновление (пересборка после pull)
sudo git pull
sudo docker compose up -d --build
```

### 8. Автоматический запуск при перезагрузке сервера

Контейнеры уже настроены с `restart: unless-stopped` в [`docker-compose.yml`](docker-compose.yml), но для гарантии автозапуска после перезагрузки:

```bash
# Создание systemd-сервиса
sudo tee /etc/systemd/system/rest-api.service > /dev/null <<'EOF'
[Unit]
Description=REST API Messages (Docker Compose)
Requires=docker.service
After=docker.service

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=/var/www/rest_api
ExecStart=/usr/bin/docker compose up -d
ExecStop=/usr/bin/docker compose down
TimeoutStartSec=0

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now rest-api.service
```

## API Endpoints

### Health Check

- `GET /health` - Проверка статуса сервиса

### Messages API (v1.0)

#### Коллекция сообщений

- `GET /api/v1.0/messages` - Получить список всех сообщений
- `HEAD /api/v1.0/messages` - Получить метаданные списка сообщений
- `POST /api/v1.0/messages` - Создать новое сообщение
- `OPTIONS /api/v1.0/messages` - Получить список доступных методов

#### Отдельное сообщение

- `GET /api/v1.0/messages/{id}` - Получить сообщение по ID
- `HEAD /api/v1.0/messages/{id}` - Получить метаданные сообщения
- `PUT /api/v1.0/messages/{id}` - Полностью заменить сообщение
- `PATCH /api/v1.0/messages/{id}` - Частично обновить сообщение
- `DELETE /api/v1.0/messages/{id}` - Удалить сообщение
- `OPTIONS /api/v1.0/messages/{id}` - Получить список доступных методов

### OpenAPI/Swagger

- `GET /openapi.json` - Получить спецификацию OpenAPI в формате JSON

## Примеры использования

### Получить список сообщений

```bash
curl -X GET http://localhost:8080/api/v1.0/messages
```

### Создать сообщение

```bash
curl -X POST http://localhost:8080/api/v1.0/messages \
  -H "Content-Type: application/json" \
  -d '{"content": "Привет, мир!", "author": "John Doe"}'
```

### Получить сообщение по ID

```bash
curl -X GET http://localhost:8080/api/v1.0/messages/1
```

### Обновить сообщение (PATCH)

```bash
curl -X PATCH http://localhost:8080/api/v1.0/messages/1 \
  -H "Content-Type: application/json" \
  -d '{"content": "Обновленное сообщение"}'
```

### Заменить сообщение (PUT)

```bash
curl -X PUT http://localhost:8080/api/v1.0/messages/1 \
  -H "Content-Type: application/json" \
  -d '{"content": "Полностью замененное сообщение", "author": "Jane Doe"}'
```

### Удалить сообщение

```bash
curl -X DELETE http://localhost:8080/api/v1.0/messages/1
```

### Получить метаданные (HEAD)

```bash
curl -I http://localhost:8080/api/v1.0/messages
```

### Получить доступные методы (OPTIONS)

```bash
curl -X OPTIONS http://localhost:8080/api/v1.0/messages
```

## Структура проекта

```
.
├── composer.json          # Зависимости Composer
├── public/
│   ├── index.php          # Точка входа приложения
│   ├── openapi.json       # Спецификация OpenAPI
│   └── .htaccess          # Конфигурация Apache
├── src/
│   ├── Controllers/
│   │   └── MessageController.php  # Контроллер сообщений
│   ├── Middleware/
│   │   └── CorsMiddleware.php     # CORS middleware
│   └── Services/
│       └── MessageService.php     # Сервис работы с сообщениями
├── data/                  # Хранилище данных (создается автоматически)
│   └── messages.json
└── .gitignore
```

## Формат данных

### Сообщение

```json
{
  "id": 1,
  "content": "Привет, мир!",
  "author": "John Doe",
  "createdAt": "2026-06-19T12:00:00+00:00",
  "updatedAt": "2026-06-19T12:00:00+00:00"
}
```

### Создание сообщения

```json
{
  "content": "Привет, мир!",
  "author": "John Doe"
}
```

### Обновление сообщения (PATCH)

```json
{
  "content": "Обновленное сообщение",
  "author": "Jane Doe"
}
```

## HTTP заголовки

### Ответы API

- `Content-Type: application/json; charset=UTF-8`
- `X-Total-Count` - общее количество сообщений (для GET /messages и HEAD /messages)
- `X-Resource-Id` - ID сообщения (для GET /messages/{id}, HEAD /messages/{id}, PUT, PATCH)
- `Location` - URL созданного ресурса (для POST /messages)

### CORS

API поддерживает CORS с заголовками:

- `Access-Control-Allow-Origin: *`
- `Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD`
- `Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With`
- `Access-Control-Expose-Headers: X-Total-Count, X-Resource-Id, Location`

## Ошибки

API возвращает ошибки в формате:

```json
{
  "error": {
    "message": "Описание ошибки",
    "status": 422
  }
}
```

### Коды ошибок

- `404` - Ресурс не найден
- `422` - Ошибка валидации
- `500` - Внутренняя ошибка сервера



## Документация по деплою

DEPLOYMENT.md — полное руководство из 10 разделов

### Production-конфигурация PHP
- docker/php/production-www.conf — PHP-FPM pool (20 max_children, status/ping endpoints)
- docker/php/production-opcache.ini — OPcache + JIT (256MB, tracing mode)
- docker/php/production-php.ini — security hardening (expose_php=Off, cookie flags)

### CI/CD
`.github/workflows/deploy.yml` — GitHub Actions workflow

### Скрипты автоматизации
- deploy.sh — деплой с бэкапом, health check и rollback
- backup.sh — резервное копирование данных с ротацией (30 дней)
- monitoring.sh — мониторинг доступности с автоперезапуском

## Лицензия

Этот проект распространяется под лицензией MIT. См. файл [LICENSE](LICENSE) для подробностей.
