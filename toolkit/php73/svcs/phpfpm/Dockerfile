FROM php:7.3-fpm
RUN apt-get update && apt-get install -y \
        # For php gd ext
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        # For php zip ext
        zlib1g-dev \
        libzip-dev \
        # For memcached
        libmemcached-dev \
        # Install required packages
        default-mysql-client \
        # For ssl
        libssl-dev

RUN pecl install \
    # xdebug-3.1.5 \
    # && docker-php-ext-enable xdebug \
    && docker-php-ext-install bcmath \
    && docker-php-ext-configure mysqli \
    && docker-php-ext-install -j$(nproc) mysqli \
    && docker-php-ext-configure gettext \
    && docker-php-ext-install -j$(nproc) gettext \
    # && docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg=/usr/include/ \
    # && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-configure pdo_mysql \
    && docker-php-ext-install -j$(nproc) pdo_mysql \
    && docker-php-ext-configure zip \
    && docker-php-ext-install -j$(nproc) zip \
    && pecl install memcached \
    && docker-php-ext-enable memcached \
    && docker-php-ext-configure opcache \
    && docker-php-ext-install opcache