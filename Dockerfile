FROM node:22-alpine AS frontend

WORKDIR /app

RUN corepack enable && corepack prepare pnpm@10.29.2 --activate

COPY package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile

COPY . .
RUN pnpm check && pnpm build


FROM php:8.3-apache-bookworm AS php-base

RUN sed -i \
        -e 's|https*://deb.debian.org/debian-security|https://security.debian.org/debian-security|g' \
        -e 's|https*://deb.debian.org/debian|https://mirror.unair.ac.id/debian|g' \
        /etc/apt/sources.list.d/debian.sources \
    && apt-get \
        -o Acquire::Retries=1 \
        -o Acquire::ForceIPv4=true \
        -o Acquire::https::Timeout=15 \
        update --error-on=any \
    && apt-get \
        -o Acquire::Retries=1 \
        -o Acquire::ForceIPv4=true \
        -o Acquire::https::Timeout=15 \
        install --yes --no-install-recommends \
        curl \
        git \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libpq-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd pdo_pgsql zip \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*


FROM php-base AS vendor

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
COPY composer.json composer.lock artisan ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY lang ./lang
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY storage ./storage

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader


FROM php-base AS runtime

ARG IMAGE_VERSION=development
ARG VCS_REF=unknown

LABEL org.opencontainers.image.title="Pondok Berkah Farm Dashboard" \
      org.opencontainers.image.version="${IMAGE_VERSION}" \
      org.opencontainers.image.revision="${VCS_REF}" \
      org.opencontainers.image.source="https://gitlab.com/pondok-berkah-bersama/dashboard"

WORKDIR /var/www/html

COPY --from=vendor /app ./
COPY --from=frontend /app/public/build ./public/build
COPY .docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY .docker/docker-entrypoint.sh /usr/local/bin/farm-dashboard-entrypoint

RUN chmod +x /usr/local/bin/farm-dashboard-entrypoint \
    && mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl --fail --silent --show-error http://127.0.0.1/up > /dev/null || exit 1

ENTRYPOINT ["farm-dashboard-entrypoint"]
CMD ["apache2-foreground"]
