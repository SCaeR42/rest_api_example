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
    CMD php-fpm -tt || exit 1

CMD ["php-fpm"]