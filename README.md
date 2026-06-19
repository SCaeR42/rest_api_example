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
