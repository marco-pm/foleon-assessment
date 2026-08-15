# syntax=docker/dockerfile:1
FROM dunglas/frankenphp:1.12.7-php8.5

# uid/gid of the host user, so bind-mounted files stay editable outside the container
ARG UID=1000
ARG GID=1000

RUN install-php-extensions \
        intl \
        opcache \
        zip \
        xdebug

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

RUN mv "${PHP_INI_DIR}/php.ini-development" "${PHP_INI_DIR}/php.ini"
COPY docker/php/app.ini "${PHP_INI_DIR}/conf.d/zz-app.ini"
COPY docker/php/xdebug.ini "${PHP_INI_DIR}/conf.d/zz-xdebug.ini"

RUN groupadd -g "${GID}" app \
    && useradd -u "${UID}" -g "${GID}" -m -s /bin/bash app \
    && chown -R app:app /app /data /config

USER app
WORKDIR /app
