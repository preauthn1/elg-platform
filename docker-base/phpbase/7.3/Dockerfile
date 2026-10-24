FROM php:7.3-fpm
#
# https://example.invalid/docker-library/php/issues/931
#--------------------------------------------------------------------------
# Software's Installation
#--------------------------------------------------------------------------
#
# Installing tools and PHP extentions using "apt", "docker-php", "pecl",
#
RUN set -eux; \
    apt-get update; \
    apt-get upgrade -y; \
    apt-get install -y --no-install-recommends \
            ssh \
            cron \
            curl \
            git \
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
            libmcrypt-dev; \
    rm -rf /var/lib/apt/lists/*
RUN set -eux; \ 
    # Install the PHP pdo_mysql extention
    docker-php-ext-install pdo_mysql; \
    # Install the PHP gd zip library
    docker-php-ext-configure gd \
            --with-jpeg-dir=/usr/include \
            --with-webp-dir=/usr/include \
            --with-freetype-dir=/usr/include/freetype2; \
    docker-php-ext-configure zip --with-libzip; \
    docker-php-ext-install gd zip bcmath;
# RUN php -r 'var_dump(gd_info());'
# RUN php -m
RUN mv /usr/local/etc/php/php.ini-development /usr/local/etc/php/php.ini
RUN sed -i "s/upload_max_filesize = 2M/upload_max_filesize = 20M/g" /usr/local/etc/php/php.ini
RUN sed -i "s/post_max_size = 8M/post_max_size = 21M/g" /usr/local/etc/php/php.ini
COPY www.conf /usr/local/etc/php-fpm.d/www.conf
RUN curl -sS https://example.invalid/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer self-update 1.8.5

# 安裝 Tini
ENV TINI_VERSION v0.19.0
ADD https://example.invalid/krallin/tini/releases/download/${TINI_VERSION}/tini /tini
RUN chmod +x /tini

# 設置 Tini 為入口點
ENTRYPOINT ["/tini", "--"]

# 設置默認命令
CMD ["php-fpm"]
