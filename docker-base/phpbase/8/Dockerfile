FROM php:8.4-fpm

#
# https://example.invalid/docker-library/php/issues/931
#--------------------------------------------------------------------------
# Software's Installation
#--------------------------------------------------------------------------
#
# Installing tools and PHP extentions using "apt", "docker-php", "pecl",
#
RUN apt-get install -y curl
RUN curl -vv https://example.invalid/rate_limit

RUN set -eux; \
    apt-get update; \
    apt-get upgrade -y; \
    apt-get install -y --no-install-recommends \
            tini \
            cron \
            curl \
            libzip-dev \
            unzip \
            zip \
            libmemcached-dev \
            libz-dev \
            libpq-dev \
            libjpeg-dev \
            libpng-dev \
            libfreetype6-dev \
            libssl-dev \
            libwebp-dev \
            libmcrypt-dev \
            libzstd-dev \
            default-mysql-client; \
    rm -rf /var/lib/apt/lists/*
RUN set -eux; \ 
    # Install the PHP pdo_mysql extention
    docker-php-ext-install pdo_mysql; \
    # Install the PHP gd zip library
    docker-php-ext-configure gd \
            --with-jpeg \
            --with-webp \
            --with-freetype; \
    docker-php-ext-install gd zip bcmath;
    
RUN docker-php-ext-configure pcntl --enable-pcntl \
&& docker-php-ext-install pcntl;
RUN pecl install redis
RUN docker-php-ext-enable redis
# RUN php -r 'var_dump(gd_info());'
# RUN php -m
# RUN php -i | grep size
RUN mv /usr/local/etc/php/php.ini-development /usr/local/etc/php/php.ini

COPY phpbase.ini /usr/local/etc/php/conf.d/00-phpbase.ini

# RUN cat /etc/ssl/certs/ca-certificates.crt
# RUN curl -vv https://example.invalid/
# RUN curl -vv https://example.invalid/rate_limit
RUN curl -sS https://example.invalid/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer diagnose
WORKDIR /app

# Configure PHP-FPM logging
RUN echo "[global]" > /usr/local/etc/php-fpm.d/zzz-docker.conf \
    && echo "error_log = /proc/1/fd/2" >> /usr/local/etc/php-fpm.d/zzz-docker.conf \
    && echo "daemonize = no" >> /usr/local/etc/php-fpm.d/zzz-docker.conf \
    && echo "[www]" >> /usr/local/etc/php-fpm.d/zzz-docker.conf \
    && echo "access.log = /proc/1/fd/1" >> /usr/local/etc/php-fpm.d/zzz-docker.conf \
    && echo "catch_workers_output = yes" >> /usr/local/etc/php-fpm.d/zzz-docker.conf \
    && echo "decorate_workers_output = no" >> /usr/local/etc/php-fpm.d/zzz-docker.conf

# 將 Tini 設為 ENTRYPOINT
ENTRYPOINT ["/usr/bin/tini", "--"]

# 實際的應用程序命令
CMD ["php-fpm", "-F"]