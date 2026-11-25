FROM php:8.5-cli-alpine
RUN apk add --no-cache libpq sqlite-libs oniguruma \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev sqlite-dev oniguruma-dev \
    && docker-php-ext-install pdo_pgsql pdo_sqlite mbstring \
    && apk del .build-deps
RUN apk add --no-cache unzip git
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
