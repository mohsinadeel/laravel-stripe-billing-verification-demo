FROM php:8.4-cli-alpine

RUN apk add --no-cache sqlite-dev && docker-php-ext-install pdo_sqlite bcmath
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --no-scripts --prefer-dist
COPY . .
RUN touch .env
RUN composer dump-autoload --optimize --no-scripts
RUN mkdir -p /data && chown -R www-data:www-data /app/storage /app/bootstrap/cache /data
USER www-data
EXPOSE 8000
CMD ["sh", "-c", "touch /data/database.sqlite && php artisan migrate --force && php artisan db:seed --force && cd public && php -S 0.0.0.0:8000 -t . /app/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"]
