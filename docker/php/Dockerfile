FROM php:8.3-cli-alpine

RUN apk add --no-cache git zip unzip icu-dev oniguruma-dev libpq-dev libzip-dev $PHPIZE_DEPS \
    && docker-php-ext-install intl mbstring zip pdo_pgsql bcmath opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN { \
    echo "memory_limit=512M"; \
    echo "upload_max_filesize=50M"; \
    echo "post_max_size=50M"; \
    echo "max_execution_time=120"; \
  } > /usr/local/etc/php/conf.d/zz-custom.ini

WORKDIR /var/www/html
