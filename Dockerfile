FROM php:8.3-cli AS base

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libxml2-dev \
    unzip \
    && docker-php-ext-install pdo_pgsql pgsql zip intl bcmath opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Development stage
FROM base AS development

RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g pnpm

COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader

COPY package.json pnpm-lock.yaml* ./
RUN pnpm install --frozen-lockfile 2>/dev/null || pnpm install

COPY . .
RUN composer dump-autoload --optimize

EXPOSE 8000 5173

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

# Production stage
FROM base AS production

ENV APP_ENV=production
ENV APP_DEBUG=false

RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g pnpm

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --optimize-autoloader

COPY package.json pnpm-lock.yaml* ./
RUN pnpm install --frozen-lockfile --prod 2>/dev/null || pnpm install

COPY . .
RUN composer dump-autoload --optimize \
    && pnpm build \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
