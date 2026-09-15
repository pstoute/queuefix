FROM node:22.23.2-bookworm-slim@sha256:83f487e0a63425e5b4d146fb5e5be574bcbe1b7b843d3ebafdd95eaf7767a7e5 AS node-toolchain

ENV PNPM_VERSION=10.34.5
ENV PNPM_SHA512=a4ee05f2f73658255bd6a89859c065a45c28a57daefae2c893a168ee2b73168c37b91e83e57ea67654ad03f03031746430e8bce38e362e042605fb8abc80192e

RUN npm pack "pnpm@${PNPM_VERSION}" --ignore-scripts --pack-destination /tmp \
    && echo "${PNPM_SHA512}  /tmp/pnpm-${PNPM_VERSION}.tgz" | sha512sum --check --strict \
    && npm install --global --ignore-scripts "/tmp/pnpm-${PNPM_VERSION}.tgz" \
    && rm "/tmp/pnpm-${PNPM_VERSION}.tgz"

FROM php:8.3.33-cli-bookworm@sha256:177529735599a8244b2c903522f029839dce1c2ac4be122fdc00ada4b45a20e4 AS base

ENV PHPREDIS_VERSION=6.3.0
ENV PHPREDIS_SHA256=0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5

RUN sed -i \
        -e 's|^URIs: http://deb.debian.org/debian$|URIs: https://snapshot.debian.org/archive/debian/20260824T000000Z|' \
        -e 's|^URIs: http://deb.debian.org/debian-security$|URIs: https://snapshot.debian.org/archive/debian-security/20260824T000000Z|' \
        -e '/^Signed-By:/a Check-Valid-Until: no' \
        /etc/apt/sources.list.d/debian.sources \
    && apt-get update && apt-get install -y \
    git \
    curl \
    libpq-dev \
    default-libmysqlclient-dev \
    libc-client-dev \
    libkrb5-dev \
    libzip-dev \
    libicu-dev \
    libxml2-dev \
    unzip \
    && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-install pdo_pgsql pgsql pdo_mysql zip intl bcmath opcache imap pcntl \
    && curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
        --connect-timeout 10 --max-time 120 \
        --output "/tmp/redis-${PHPREDIS_VERSION}.tgz" \
        "https://pecl.php.net/get/redis-${PHPREDIS_VERSION}.tgz" \
    && echo "${PHPREDIS_SHA256}  /tmp/redis-${PHPREDIS_VERSION}.tgz" | sha256sum --check --strict \
    && pecl install "/tmp/redis-${PHPREDIS_VERSION}.tgz" \
    && docker-php-ext-enable redis \
    && rm "/tmp/redis-${PHPREDIS_VERSION}.tgz" \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.10.3@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Production PHP dependencies are shared with the frontend build so Tailwind
# can scan the framework pagination templates declared in tailwind.config.js.
FROM base AS production-dependencies

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --optimize-autoloader

# Development stage
FROM base AS development

COPY --from=node-toolchain /usr/local/bin/node /usr/local/bin/node
COPY --from=node-toolchain /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/corepack/dist/corepack.js /usr/local/bin/corepack \
    && ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && ln -s ../lib/node_modules/pnpm/bin/pnpm.cjs /usr/local/bin/pnpm \
    && ln -s ../lib/node_modules/pnpm/bin/pnpx.cjs /usr/local/bin/pnpx

COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader

COPY package.json pnpm-lock.yaml* pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile

COPY artisan ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY postcss.config.js tailwind.config.js tsconfig.json vite.config.js ./
RUN mkdir -p \
        bootstrap/cache \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
    && composer dump-autoload --optimize

EXPOSE 8000 5173

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]

# Frontend asset stage
FROM node-toolchain AS frontend

WORKDIR /app

COPY package.json pnpm-lock.yaml* pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile

COPY --from=production-dependencies /var/www/html/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
COPY --from=production-dependencies /var/www/html/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
COPY public ./public
COPY resources ./resources
COPY postcss.config.js tailwind.config.js tsconfig.json vite.config.js ./
RUN pnpm build

# Production stage
FROM base AS production

ENV APP_ENV=production
ENV APP_DEBUG=false

COPY composer.json composer.lock ./
COPY --from=production-dependencies /var/www/html/vendor ./vendor

COPY artisan ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY --from=frontend /app/public/build ./public/build
RUN mkdir -p \
        bootstrap/cache \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
    && composer dump-autoload --optimize \
    && php artisan route:cache \
    && php artisan view:cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
