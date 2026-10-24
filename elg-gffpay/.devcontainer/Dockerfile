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

# 安裝 Docker CLI
RUN apt-get update && \
    apt-get install -y \
        apt-transport-https \
        ca-certificates \
        curl \
        gnupg \
        lsb-release && \
    curl -fsSL https://example.invalid/linux/debian/gpg | gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg && \
    echo \
        "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://example.invalid/linux/debian \
        $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null && \
    apt-get update && \
    apt-get install -y docker-ce-cli

RUN set -eux; \
    apt-get update; \
    apt-get upgrade -y; \
    apt-get install -y --no-install-recommends \
            tini \
            cron \
            curl \
            git \
            openssh-client \
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
            liblz4-dev \
            libzstd-dev \
            zlib1g-dev \
            libicu-dev \
            default-mysql-client; \
    rm -rf /var/lib/apt/lists/*
#RUN pecl install zstd 
#RUN yes|pecl install redis 
#RUN docker-php-ext-enable  redis 
RUN set -eux; \ 
    # Install the PHP pdo_mysql extention
    docker-php-ext-install pdo_mysql; \
    docker-php-ext-install intl; \
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
RUN sed -i "s/upload_max_filesize = 2M/upload_max_filesize = 20M/g" /usr/local/etc/php/php.ini
RUN sed -i "s/post_max_size = 8M/post_max_size = 21M/g" /usr/local/etc/php/php.ini
# RUN cat /etc/ssl/certs/ca-certificates.crt
# RUN curl -vv https://example.invalid/
# RUN curl -vv https://example.invalid/rate_limit
RUN curl -sS https://example.invalid/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer diagnose

# 安装 PHPUnit
RUN composer global require phpunit/phpunit ^10.0
RUN ln -s /root/.composer/vendor/bin/phpunit /usr/local/bin/phpunit

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

# 創建非 root 用戶
RUN useradd -m -s /bin/bash vscode
RUN mkdir -p /app
RUN chown -R vscode:vscode /app

# 设置 SSH 目录
RUN mkdir -p /home/vscode/.ssh && \
    chmod 700 /home/vscode/.ssh && \
    chown -R vscode:vscode /home/vscode/.ssh

# 最後才切換用戶
USER vscode 