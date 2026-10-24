FROM asia-east1-docker.pkg.dev/tp-wz3-rd/bucket2024/target/docker-base/phpbase/8-minimal:ae4fd248

# 更新 openssl 設定
COPY .gitlab-ci/build/openssl.cnf /etc/ssl/openssl.cnf

# php-fpm 優雅關閉：預設 process_control_timeout=0，master 收到 SIGQUIT 後不等 worker
# 收工即砍，導致 k8s 終止 pod（rollout / HPA 縮容 / 節點回收）時「執行中」的請求被攔腰
# 中斷 —— 代付已呼叫三方但 DB 回寫尚未執行即為此情境。
# 130s 須小於 gffv3 chart 的 terminationGracePeriodSeconds(170s) 扣掉 preStop(20s)。
# 兩者為跨 repo 契約，調整其一必須同步另一方。
RUN printf '[global]\nprocess_control_timeout = 130s\n' \
    > /usr/local/etc/php-fpm.d/zzzz-graceful-shutdown.conf

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

# Horizon 儀表板不需 publish 資產（#3967 B3 前提已由套件版本消解）：
# laravel/horizon v5.47 起 horizon:publish 為 deprecated no-op，儀表板 CSS/JS
# 於 request 時自 vendor/laravel/horizon/dist/ 內嵌進 HTML 回應（Horizon::css()/js()），
# vendor/ 已隨 composer install 烤進 image，毋須額外步驟。

# 清掉 build 過程中產生的暫存和 cache
RUN rm -rf \
    /root/.composer \
    /tmp/* \
    /var/tmp/*

# 明確宣告停止信號，不依賴上游 image 的 metadata 繼承。
# SIGQUIT 才會讓 php-fpm master 進入 FINISHING（worker 走 sig_soft_quit、把手上的
# 請求跑完）；SIGTERM 走 TERMINATING，worker 的 handler 是 SIG_DFL 會即刻終止，
# 上面的 process_control_timeout 也就失去作用。
# 目前 SIGQUIT 是沿 FROM 自官方 php:8.4-fpm 繼承而來，未在本檔宣告時同樣生效，
# 但屬隱性依賴：一旦更換 base image 或上游調整 STOPSIGNAL，graceful 會無聲失效。
STOPSIGNAL SIGQUIT

# 使用 tini 作為 PID 1 管理器
ENTRYPOINT ["/usr/bin/tini", "--"]

# PHP-FPM 以 foreground 運行
CMD ["php-fpm", "-F"]
