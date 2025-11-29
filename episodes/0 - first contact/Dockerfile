FROM ghcr.io/roadrunner-server/roadrunner:2024 as roadrunner
FROM php:8.5-alpine

RUN --mount=type=bind,from=mlocati/php-extension-installer:2,source=/usr/bin/install-php-extensions,target=/usr/local/bin/install-php-extensions \
    install-php-extensions @composer-2 opcache zip intl sockets protobuf

COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr

EXPOSE 8080/tcp
WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1

# Copy from app directory
COPY ./app/composer.* .
RUN composer install --optimize-autoloader --no-dev

COPY ../../app .

CMD ["rr", "serve"]