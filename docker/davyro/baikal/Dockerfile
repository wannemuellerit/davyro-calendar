ARG PHP_IMAGE=php:8.4.27-fpm-alpine3.24@sha256:93aa3f61216b73cc6f878235c9772849449f4efc18dfe3d6678eed645076c00d
FROM composer:2.10.3@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc AS yaml-security
WORKDIR /yaml
COPY yaml-composer.json composer.json
COPY yaml-composer.lock composer.lock
RUN composer install --no-dev --no-scripts --no-plugins --no-interaction --prefer-dist \
    && composer audit --no-dev

FROM ${PHP_IMAGE}

ARG BAIKAL_VERSION=0.12.1
ARG BAIKAL_SHA256=0449abb72b151d39d9c08c63cb83a05d9e9adb065b1165ef6786b0b6a13d203c

RUN apk upgrade --no-cache \
    && apk add --no-cache apache2 apache2-proxy bash tini ca-certificates curl \
    && apk add --no-cache --virtual .build-deps unzip \
    && docker-php-ext-install -j4 pdo_mysql \
    && curl -fsSL --retry 3 \
        -o /tmp/baikal.zip \
        "https://github.com/sabre-io/Baikal/releases/download/${BAIKAL_VERSION}/baikal-${BAIKAL_VERSION}.zip" \
    && echo "${BAIKAL_SHA256}  /tmp/baikal.zip" | sha256sum -c - \
    && unzip -q /tmp/baikal.zip -d /tmp/baikal-release \
    && mv /tmp/baikal-release/baikal /var/www/baikal \
    && rm -rf /tmp/baikal.zip /tmp/baikal-release \
    && apk del .build-deps \
    && mkdir -p /var/www/baikal/config /var/www/baikal/Specific \
    && chown -R www-data:www-data /var/www/baikal

# Preserve uid/gid 33 for existing application data volumes.
RUN sed -i 's/^www-data:x:82:82:/www-data:x:33:33:/' /etc/passwd \
    && sed -i 's/^www-data:x:82:/www-data:x:33:/' /etc/group \
    && test "$(id -u www-data)" = 33

COPY --from=yaml-security /yaml/vendor/symfony/yaml /var/www/baikal/vendor/symfony/yaml
COPY --from=yaml-security /yaml/vendor/composer/installed.json /tmp/yaml-installed.json
COPY patch-yaml-metadata.php /tmp/patch-yaml-metadata.php
RUN php /tmp/patch-yaml-metadata.php \
    && rm /tmp/yaml-installed.json /tmp/patch-yaml-metadata.php

# Control both Apache and FPM with TERM.
STOPSIGNAL SIGTERM
ENV APACHE_DOCUMENT_ROOT=/var/www/baikal/html

COPY runtime/httpd.conf /etc/apache2/httpd.conf
COPY runtime/web-processes.sh /usr/local/bin/davyro-web-processes
COPY runtime/apache2-foreground /usr/local/bin/apache2-foreground
RUN chmod +x /usr/local/bin/davyro-web-processes /usr/local/bin/apache2-foreground \
    && mkdir -p /etc/apache2/davyro.d \
    && printf '[www]\nlisten=127.0.0.1:9000\n' > /usr/local/etc/php-fpm.d/zz-davyro-listen.conf
COPY apache.conf /etc/apache2/davyro.d/baikal.conf
COPY bootstrap.php /opt/davyro/bootstrap.php
COPY entrypoint.sh /usr/local/bin/davyro-baikal-entrypoint

RUN chmod 755 /usr/local/bin/davyro-baikal-entrypoint \
    && chmod 644 /opt/davyro/bootstrap.php

LABEL org.opencontainers.image.title="Davyro Baikal" \
    org.opencontainers.image.version="0.12.1" \
    org.opencontainers.image.source="https://github.com/wannemuellerit/davyro-calendar" \
    org.opencontainers.image.licenses="GPL-3.0-only"

ENTRYPOINT ["/usr/local/bin/davyro-baikal-entrypoint"]
CMD ["apache2-foreground"]
