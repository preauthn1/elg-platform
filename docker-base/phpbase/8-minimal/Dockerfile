FROM php:8.4-fpm

# -------------------------------------------------
# System dependencies (Production Minimal)
# -------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
    tini \
    curl \
    unzip \
    zip \
    git \
    openssh-client \
    libzip-dev \
    libz-dev \
    libjpeg-dev \
    libpng-dev \
    libfreetype6-dev \
    libwebp-dev \
    libssl-dev \
    libzstd-dev \
    libicu-dev \
    default-mysql-client \
 && rm -rf /var/lib/apt/lists/*

# -------------------------------------------------
# PHP Extensions (Laravel + Filament Required)
# -------------------------------------------------
RUN set -eux; \
    docker-php-ext-install pdo_mysql; \
    docker-php-ext-configure gd \
        --with-jpeg \
        --with-webp \
        --with-freetype; \
    docker-php-ext-install gd zip bcmath; \
    docker-php-ext-configure pcntl --enable-pcntl; \
    docker-php-ext-install pcntl intl

# -------------------------------------------------
# Redis
# -------------------------------------------------
RUN pecl install redis \
 && docker-php-ext-enable redis

# -------------------------------------------------
# PHP.ini (Production)
# -------------------------------------------------
RUN mv /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

COPY phpbase.ini /usr/local/etc/php/conf.d/00-phpbase.ini

# -------------------------------------------------
# Composer (Official Install)
# -------------------------------------------------
RUN curl -sS https://example.invalid/installer | php -- \
    --install-dir=/usr/local/bin \
    --filename=composer

# -------------------------------------------------
# PHP-FPM Logging (for Docker / K8s)
# -------------------------------------------------
RUN echo "[global]" > /usr/local/etc/php-fpm.d/zzz-docker.conf \
    && echo "error_log = /proc/1/fd/2" >> /usr/local/etc/php-fpm.d/zzz-docker.conf \
    && echo "daemonize = no" >> /usr/local/etc/php-fpm.d/zzz-docker.conf \
    && echo "[www]" >> /usr/local/etc/php-fpm.d/zzz-docker.conf \
    && echo "access.log = /proc/1/fd/1" >> /usr/local/etc/php-fpm.d/zzz-docker.conf \
    && echo "catch_workers_output = yes" >> /usr/local/etc/php-fpm.d/zzz-docker.conf \
    && echo "decorate_workers_output = no" >> /usr/local/etc/php-fpm.d/zzz-docker.conf

# -------------------------------------------------
# Working Directory
# -------------------------------------------------
WORKDIR /app

# -------------------------------------------------
# Tini Entry
# -------------------------------------------------
ENTRYPOINT ["/usr/bin/tini", "--"]

CMD ["php-fpm", "-F"]
