# ============================================================
# OneGate Payment Gateway - Development Container
# ============================================================
# 此 Dockerfile 建立完整的 PHP + Laravel 開發環境，包含：
# - PHP 8.4 開發工具鏈
# - Laravel 12 所需的所有擴展
# - Composer、Node.js、Redis CLI
# - MCP 服務器支援（Node.js, Claude Code CLI, context7）
# ============================================================

# 使用 PHP 8.4 官方映像作為基礎（FPM 版本，用於傳統架構）
FROM php:8.4-fpm-bookworm

# 設定非互動模式（避免 apt 安裝時出現互動式提示）
ENV DEBIAN_FRONTEND=noninteractive

# 更新套件列表並安裝基礎工具
# git: 版本控制
# curl/wget: 下載工具
# gnupg/ca-certificates: 安全連線和簽章驗證
# unzip: 解壓縮（Composer 需要）
# vim: 文字編輯器
# supervisor: 進程管理（Laravel Horizon 需要）
RUN apt-get update && apt-get install -y \
    git \
    curl \
    wget \
    unzip \
    zip \
    gnupg \
    lsb-release \
    ca-certificates \
    apt-transport-https \
    vim \
    jq \
    supervisor \
    && rm -rf /var/lib/apt/lists/*

# 安裝 Laravel 12 所需的 PHP 擴展
# 參考：https://example.invalid/docs/12.x/deployment#server-requirements
RUN apt-get update && apt-get install -y \
    # PDO 相關（資料庫連接）
    libpq-dev \
    # GD 圖像處理
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    # ZIP 擴展
    libzip-dev \
    # cURL 擴展
    libcurl4-openssl-dev \
    # XML 相關擴展
    libxml2-dev \
    # Mbstring 擴展
    libonig-dev \
    # Redis 擴展依賴
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        mysqli \
        gd \
        zip \
        bcmath \
        intl \
        opcache \
        pcntl \
        exif \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# 安裝 Composer（PHP 套件管理器）
# 使用官方安裝腳本，確保安裝最新穩定版本
RUN curl -sS https://example.invalid/installer | php -- --install-dir=/usr/local/bin --filename=composer

# 安裝 Node.js 20.x（用於 Vite、MCP 服務器）
# Node.js 是前端建置工具和 MCP 服務器的運行環境
RUN curl -fsSL https://example.invalid/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# 安裝 Redis CLI（用於除錯和管理 Redis）
RUN apt-get update && apt-get install -y redis-tools \
    && rm -rf /var/lib/apt/lists/*

# 安裝 MariaDB Server 和 Client（MySQL 相容）
# MariaDB 10.11 提供完整的 MySQL 8.0 相容性
RUN apt-get update && apt-get install -y \
    default-mysql-server \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# 安裝 Claude Code CLI（Anthropic 官方 CLI 工具）
# 提供 AI 輔助開發功能，支援 MCP（Model Context Protocol）服務器
RUN npm install -g @anthropic-ai/claude-code

# 預先安裝 context7 MCP 服務器套件
# context7 提供最新的程式庫文檔查詢功能（用於查詢 Laravel、PHP SDK 等）
# postStartCommand 會確保使用最新版本，此處僅為加速首次執行
RUN npm install -g @upstash/context7-mcp 2>/dev/null || true

# 配置 PHP（生產環境優化設定）
# 複製推薦的生產環境配置作為基礎
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# 自訂 PHP 配置（針對開發環境調整）
RUN echo "memory_limit = 512M" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "upload_max_filesize = 64M" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "post_max_size = 64M" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "max_execution_time = 300" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "display_errors = On" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "error_reporting = E_ALL" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "opcache.enable = 1" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "opcache.memory_consumption = 256" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "opcache.interned_strings_buffer = 16" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "opcache.max_accelerated_files = 10000" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "opcache.validate_timestamps = 1" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "opcache.revalidate_freq = 2" >> "$PHP_INI_DIR/conf.d/custom.ini"

# 配置 PHP-FPM
# 針對開發環境調整連接池設定
RUN echo "[www]" > /usr/local/etc/php-fpm.d/zz-custom.conf \
    && echo "pm = dynamic" >> /usr/local/etc/php-fpm.d/zz-custom.conf \
    && echo "pm.max_children = 20" >> /usr/local/etc/php-fpm.d/zz-custom.conf \
    && echo "pm.start_servers = 5" >> /usr/local/etc/php-fpm.d/zz-custom.conf \
    && echo "pm.min_spare_servers = 3" >> /usr/local/etc/php-fpm.d/zz-custom.conf \
    && echo "pm.max_spare_servers = 10" >> /usr/local/etc/php-fpm.d/zz-custom.conf \
    && echo "pm.max_requests = 500" >> /usr/local/etc/php-fpm.d/zz-custom.conf

# 安裝常用 PHP 開發工具
# phpstan: PHP 靜態分析工具
# php-cs-fixer: PHP 程式碼風格修正工具
RUN composer global require phpstan/phpstan \
    && composer global require friendsofphp/php-cs-fixer

# 將 Composer global bin 加入 PATH
ENV PATH="/root/.composer/vendor/bin:$PATH"

# 建立 devuser 的主目錄和 Composer 目錄，並設定權限
# 這確保 composer install 可以正常下載套件，且 Cursor/VS Code Server 可以正常運作
RUN mkdir -p /home/devuser/.composer \
    && mkdir -p /home/devuser/.cursor-server \
    && mkdir -p /home/devuser/.vscode-server \
    && chown -R 1000:1000 /home/devuser

# 複製初始化腳本並設定權限
COPY .devcontainer/init-mysql.sh /usr/local/bin/init-mysql
RUN chmod +x /usr/local/bin/init-mysql

# 設定工作目錄並確保權限正確
# /workspace: 對應到專案根目錄（由 VS Code 掛載）
# devuser (UID=1000, GID=1000): 由 common-utils feature 自動創建，與主機用戶 UID/GID 匹配
WORKDIR /workspace
RUN chown -R 1000:1000 /workspace

# 設定預設使用者為 root
# 注意：devcontainer.json 中的 remoteUser 會覆寫為 devuser
# 容器啟動後實際使用的是 devuser，確保檔案權限與主機一致
USER root

# 保持容器運行（devcontainer 需要）
CMD ["sleep", "infinity"]
