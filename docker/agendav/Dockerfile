ARG PHP_IMAGE=php:8.5.12-fpm-alpine3.24@sha256:4c2818074ec49f6abe46620f3965e0cb75bc3a5f2b417edd1aacaba6f091c40e
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
COPY runtime/httpd.conf /etc/apache2/httpd.conf
COPY runtime/web-processes.sh /usr/local/bin/davyro-web-processes
COPY runtime/apache2-foreground /usr/local/bin/apache2-foreground
RUN chmod +x /usr/local/bin/davyro-web-processes /usr/local/bin/apache2-foreground \
    && mkdir -p /etc/apache2/davyro.d \
    && printf '[www]\nlisten=127.0.0.1:9000\n' > /usr/local/etc/php-fpm.d/zz-davyro-listen.conf
COPY agendav.conf /etc/apache2/davyro.d/agendav.conf

RUN printf 'upload_max_filesize=20M\npost_max_size=24M\nmax_execution_time=180\nmemory_limit=256M\n' > /usr/local/etc/php/conf.d/davyro-import.ini

WORKDIR /app


RUN mkdir -p /app/var && chown www-data:www-data /app/var
EXPOSE 80
CMD ["apache2-foreground"]
