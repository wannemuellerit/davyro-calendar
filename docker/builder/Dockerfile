FROM node:22-alpine3.24@sha256:fe4f6d74e20370acb04ad889077d14d0f64a9c8ea6a42bc60cddf1e844699914 AS node-bin
FROM composer:2.10.3@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc AS composer-bin
FROM php:8.5.12-cli-alpine3.24@sha256:e899a75c775804e5466dbd9c96b31f8b6c6ae2ddb053a8466223a0c32992ff8e
RUN apk upgrade --no-cache \
    && apk add --no-cache bash ca-certificates curl git unzip icu-libs libzip libstdc++ \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev \
    && docker-php-ext-install -j4 intl zip \
    && apk del .build-deps
COPY --from=node-bin /usr/local/ /usr/local/
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
# The Node 22 image bundles an older npm/Corepack dependency tree.
# Only npm is used by this builder; keep a patched, pinned CLI.
RUN npm install --global npm@12.2.1 --ignore-scripts --no-audit --no-fund \
    && rm -rf /usr/local/lib/node_modules/corepack \
        /usr/local/bin/corepack /usr/local/bin/pnpm /usr/local/bin/pnpx \
        /usr/local/bin/yarn /usr/local/bin/yarnpkg
# npm 12.2.1 still bundles these vulnerable patch-level dependencies.
COPY npm-security/package.json npm-security/package-lock.json /tmp/davyro-npm-security/
RUN npm ci --prefix /tmp/davyro-npm-security --ignore-scripts --omit=dev --no-audit --no-fund \
    && node -e 'for (const [p,v] of Object.entries({"ip-address":"10.7.2","undici":"6.29.0"})) if (require("/usr/local/lib/node_modules/npm/node_modules/"+p+"/package.json").version !== v) throw Error("Unexpected npm dependency: "+p)' \
    && rm -rf /usr/local/lib/node_modules/npm/node_modules/ip-address /usr/local/lib/node_modules/npm/node_modules/undici \
    && cp -R /tmp/davyro-npm-security/node_modules/ip-address /tmp/davyro-npm-security/node_modules/undici /usr/local/lib/node_modules/npm/node_modules/ \
    && rm -rf /tmp/davyro-npm-security
WORKDIR /app
COPY build.sh /usr/local/bin/build.sh
RUN chmod +x /usr/local/bin/build.sh
ENTRYPOINT ["/usr/local/bin/build.sh"]
