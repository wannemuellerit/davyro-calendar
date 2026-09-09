ARG PHP_IMAGE=php:8.4.25-apache-bookworm@sha256:25d70665acee86d7231af7bc5464794abd14585f80210f85f22dfb0713ac8ec7
FROM ${PHP_IMAGE}

ARG BAIKAL_VERSION=0.12.1
ARG BAIKAL_SHA256=0449abb72b151d39d9c08c63cb83a05d9e9adb065b1165ef6786b0b6a13d203c

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql \
    && curl -fsSL --retry 3 \
        -o /tmp/baikal.zip \
        "https://github.com/sabre-io/Baikal/releases/download/${BAIKAL_VERSION}/baikal-${BAIKAL_VERSION}.zip" \
    && echo "${BAIKAL_SHA256}  /tmp/baikal.zip" | sha256sum -c - \
    && unzip -q /tmp/baikal.zip -d /tmp/baikal-release \
    && mv /tmp/baikal-release/baikal /var/www/baikal \
    && rm -rf /tmp/baikal.zip /tmp/baikal-release /var/lib/apt/lists/* \
    && a2enmod rewrite headers \
    && mkdir -p /var/www/baikal/config /var/www/baikal/Specific \
    && chown -R www-data:www-data /var/www/baikal

ENV APACHE_DOCUMENT_ROOT=/var/www/baikal/html

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf

COPY apache.conf /etc/apache2/conf-available/davyro-baikal.conf
COPY bootstrap.php /opt/davyro/bootstrap.php
COPY entrypoint.sh /usr/local/bin/davyro-baikal-entrypoint

RUN a2enconf davyro-baikal \
    && chmod 755 /usr/local/bin/davyro-baikal-entrypoint \
    && chmod 644 /opt/davyro/bootstrap.php

LABEL org.opencontainers.image.title="Davyro Baikal" \
      org.opencontainers.image.version="0.12.1" \
      org.opencontainers.image.source="https://github.com/wannemuellerit/davyro-calendar" \
      org.opencontainers.image.licenses="GPL-3.0-only"

ENTRYPOINT ["/usr/local/bin/davyro-baikal-entrypoint"]
CMD ["apache2-foreground"]
