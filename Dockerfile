ARG PHP_CLI_IMAGE=php:8.5.10-cli-bookworm@sha256:b80dfc7d2bc0fc97755620a0dfb3d5e8e9cbf70a2970ea2d5c9dc64154b31422
ARG PHP_APACHE_IMAGE=php:8.5.10-apache-bookworm@sha256:824adc2ce556dd5e05e816b1597cad90948e44b0b36ac2642f7449b801fb8dbd
ARG COMPOSER_IMAGE=composer:2.8.12@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c
ARG NODE_IMAGE=node:20.19.5-bookworm-slim@sha256:9e70124bd00f47dd023e349cd587132ae61892acc0e47ed641416c3e18f401c3

FROM ${COMPOSER_IMAGE} AS composer-bin
FROM ${NODE_IMAGE} AS node-bin
FROM ${PHP_CLI_IMAGE} AS builder

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    git \
    libicu-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-install -j"$(nproc)" intl pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY --from=node-bin /usr/local/ /usr/local/

WORKDIR /app

COPY composer.json composer.lock /app/
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

COPY package.json package-lock.json /app/
RUN npm ci --ignore-scripts --legacy-peer-deps --no-audit --no-fund

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
    && npm ci --legacy-peer-deps --no-audit --no-fund \
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


FROM ${PHP_APACHE_IMAGE} AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev \
    libicu-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

ENV APACHE_DOCUMENT_ROOT=/app/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf \
    && sed -ri -e 's!^([[:space:]]*)CustomLog .*access\.log combined!\1CustomLog ${APACHE_LOG_DIR}/access.log davyro_safe!' \
    /etc/apache2/sites-available/*.conf

COPY docker/agendav/agendav.conf /etc/apache2/conf-available/agendav.conf
RUN a2enconf agendav

WORKDIR /app

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
