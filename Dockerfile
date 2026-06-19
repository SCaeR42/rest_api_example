FROM php:8.4-fpm

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Установка рабочей директории
WORKDIR /var/www/html

# Копирование composer.json и composer.lock
COPY composer.json composer.lock ./

# Установка зависимостей (будут перезаписаны при монтировании vendor)
RUN composer install --no-dev --optimize-autoloader --no-interaction || true

# Копирование исходных файлов (будут перезаписаны при монтировании volumes)
COPY . .

# Настройка PHP-FPM
RUN echo '[www]\n\
user = www-data\n\
group = www-data\n\
listen = 9000\n\
pm = dynamic\n\
pm.max_children = 5\n\
pm.start_servers = 2\n\
pm.min_spare_servers = 1\n\
pm.max_spare_servers = 3\n\
' > /usr/local/etc/php-fpm.d/www.conf

# Установка прав на директорию data
RUN chown -R www-data:www-data /var/www/html/data || true

EXPOSE 9000

CMD ["php-fpm"]
