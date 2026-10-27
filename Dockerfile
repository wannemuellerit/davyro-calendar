ARG PHP_CLI_IMAGE=php:8.5.12-cli-alpine3.24@sha256:e899a75c775804e5466dbd9c96b31f8b6c6ae2ddb053a8466223a0c32992ff8e
ARG PHP_IMAGE=php:8.5.12-fpm-alpine3.24@sha256:4c2818074ec49f6abe46620f3965e0cb75bc3a5f2b417edd1aacaba6f091c40e
ARG COMPOSER_IMAGE=composer:2.10.3@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc
ARG NODE_IMAGE=node:22-alpine3.24@sha256:fe4f6d74e20370acb04ad889077d14d0f64a9c8ea6a42bc60cddf1e844699914

FROM ${COMPOSER_IMAGE} AS composer-bin
FROM ${NODE_IMAGE} AS node-bin
FROM ${PHP_CLI_IMAGE} AS builder

RUN apk upgrade --no-cache \
    && apk add --no-cache ca-certificates curl git icu-libs libzip libstdc++ \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev \
    && docker-php-ext-install -j4 intl pdo_mysql zip \
    && apk del .build-deps

COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY --from=node-bin /usr/local/ /usr/local/

WORKDIR /app

COPY composer.json composer.lock /app/
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

COPY package.json package-lock.json /app/
RUN npm ci --ignore-scripts --no-audit --no-fund

COPY . /app

RUN npm run build:templates \
    && npm run build:copy \
    && npm run build:css \
    && npm run build:js \
    && rm -rf node_modules \
    && rm -rf var/log/* var/cache/twig/* var/cache/profiler/*


# This target is intentionally separate from the runtime image. It contains
# every locked PHP and Node development dependency required by CI and exposes
# the normal PHPUnit command as its default process.
FROM builder AS test

ENV AGENDAV_ENVIRONMENT=test \
    EC_VERSION=v3.2.1

RUN composer install --prefer-dist --no-interaction --no-progress \
    && npm ci --no-audit --no-fund \
    && curl --fail --silent --show-error --location \
        https://github.com/editorconfig-checker/editorconfig-checker/releases/download/v3.2.1/ec-linux-amd64.tar.gz \
        --output /tmp/ec-linux-amd64.tar.gz \
    && echo 'e6c4e61733d3f76c308468da5121bf27b3d43b0d18b7b478b5a80e4199bc91be  /tmp/ec-linux-amd64.tar.gz' | sha256sum --check --strict \
    && mkdir -p node_modules/editorconfig-checker/bin/v3.2.1 \
    && tar -xzf /tmp/ec-linux-amd64.tar.gz -C node_modules/editorconfig-checker/bin/v3.2.1 \
    && echo '7ea94f1b3f8adf73e42d6e267be7042fd254fc087a1474f4ee7b2dfc7c29080e  node_modules/editorconfig-checker/bin/v3.2.1/bin/ec-linux-amd64' | sha256sum --check --strict \
    && rm /tmp/ec-linux-amd64.tar.gz \
    && node_modules/.bin/ec -version

CMD ["composer", "test"]


# Tests and development dependencies must not leak into the production image.
FROM builder AS production-source

RUN rm -rf /app/tests


FROM ${PHP_IMAGE} AS runtime-base
RUN apk upgrade --no-cache \
    && apk add --no-cache apache2 apache2-proxy bash tini icu-libs libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev \
    && docker-php-ext-install -j4 pdo_mysql intl zip \
    && apk del .build-deps
# Preserve uid/gid 33 for existing application data volumes.
RUN sed -i 's/^www-data:x:82:82:/www-data:x:33:33:/' /etc/passwd \
    && sed -i 's/^www-data:x:82:/www-data:x:33:/' /etc/group \
    && test "$(id -u www-data)" = 33

# Control both Apache and FPM with TERM.
STOPSIGNAL SIGTERM
ENV APACHE_DOCUMENT_ROOT=/app/public
COPY docker/agendav/runtime/httpd.conf /etc/apache2/httpd.conf
COPY docker/agendav/runtime/web-processes.sh /usr/local/bin/davyro-web-processes
COPY docker/agendav/runtime/apache2-foreground /usr/local/bin/apache2-foreground
RUN chmod +x /usr/local/bin/davyro-web-processes /usr/local/bin/apache2-foreground \
    && mkdir -p /etc/apache2/davyro.d \
    && printf '[www]\nlisten=127.0.0.1:9000\n' > /usr/local/etc/php-fpm.d/zz-davyro-listen.conf
COPY docker/agendav/agendav.conf /etc/apache2/davyro.d/agendav.conf

RUN printf 'upload_max_filesize=20M\npost_max_size=24M\nmax_execution_time=180\nmemory_limit=256M\n' > /usr/local/etc/php/conf.d/davyro-import.ini

WORKDIR /app

FROM runtime-base AS runtime
COPY --from=production-source --chown=www-data:www-data /app /app

# Strip world-read on directories holding secrets (settings.php with the
# csrf secret + DB password) and runtime state (var/session.key, sessions
# DB credentials in cache, log files). Apache runs the workers as
# www-data, which retains rwx via the group.
RUN install -d -o www-data -g www-data -m 750 \
    /app/var \
    /app/var/cache \
    /app/var/cache/profiler \
    /app/var/cache/twig \
    /app/var/log \
    && chmod -R 750 /app/var /app/config

# Production image: refuse to leak stack traces by accident. docker-compose
# overrides this to 'dev' for the local development stack.
ENV AGENDAV_ENVIRONMENT=prod

EXPOSE 80

CMD ["apache2-foreground"]
