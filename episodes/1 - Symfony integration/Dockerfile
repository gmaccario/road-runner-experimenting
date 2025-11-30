FROM ghcr.io/roadrunner-server/roadrunner:2024 as roadrunner
FROM php:8.4-alpine

RUN --mount=type=bind,from=mlocati/php-extension-installer:2,source=/usr/bin/install-php-extensions,target=/usr/local/bin/install-php-extensions \
    install-php-extensions @composer-2 opcache zip intl sockets protobuf pdo_mysql

COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr

EXPOSE 8080/tcp
WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV APP_ENV=prod

COPY ./app/composer.* ./app/symfony.lock ./
RUN composer install --optimize-autoloader --no-dev --no-scripts

COPY ./app .

CMD ["rr", "serve"]