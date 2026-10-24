FROM asia-east1-docker.pkg.dev/tp-wz3-rd/bucket2024/target/docker-base/phpbase/8-minimal:ae4fd248

# 更新 openssl 設定
COPY .gitlab-ci/build/openssl.cnf /etc/ssl/openssl.cnf

WORKDIR /app

# 先拷貝 composer 檔案 → 利用 cache 加快 build
COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

# 拷貝完整專案原始碼
COPY . .

# Filament 前端資源建置
RUN php artisan filament:assets

# 清掉 build 過程中產生的暫存和 cache
RUN rm -rf \
    /root/.composer \
    /tmp/* \
    /var/tmp/*

# 使用 tini 作為 PID 1 管理器
ENTRYPOINT ["/usr/bin/tini", "--"]

# PHP-FPM 以 foreground 運行
CMD ["php-fpm", "-F"]
