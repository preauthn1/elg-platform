# OneGate Development Container

此 devcontainer 為 OneGate 四方金流聚合支付系統提供完整的 PHP + Laravel 開發環境。

## 技術棧

- **PHP**: 8.4 (FPM)
- **Laravel**: 12
- **MariaDB**: 10.11 (MySQL 相容)
- **Node.js**: 20.x (用於 Vite 前端建置)
- **Composer**: 最新穩定版
- **開發工具**: PHPStan, PHP-CS-Fixer

## 已安裝的 PHP 擴展

### Laravel 12 必需擴展 ✓

根據 [Laravel 12 官方文檔](https://example.invalid/docs/12.x/deployment#server-requirements)，以下擴展已全部安裝：

- ✅ **Ctype** - 字元類型檢測
- ✅ **cURL** - HTTP 請求
- ✅ **DOM** - XML 文檔操作
- ✅ **Fileinfo** - 文件類型檢測
- ✅ **Filter** - 數據過濾
- ✅ **Hash** - 雜湊函數
- ✅ **Mbstring** - 多位元組字串處理
- ✅ **OpenSSL** - 加密和 HTTPS
- ✅ **PCRE** - 正則表達式
- ✅ **PDO** - 資料庫抽象層
- ✅ **Session** - 會話管理
- ✅ **Tokenizer** - PHP 代碼分析
- ✅ **XML** - XML 處理

### 資料庫支援

- ✅ **PDO MySQL** - MySQL/MariaDB 連接
- ✅ **PDO PostgreSQL** - PostgreSQL 連接
- ✅ **MySQLi** - MySQL 改進版擴展

### 額外安裝的擴展（支付系統需要）

- ✅ **BCMath** - 高精度數學運算（金額計算必需）
- ✅ **GD** - 圖像處理（二維碼生成）
- ✅ **ZIP** - 壓縮文件處理
- ✅ **Intl** - 國際化和本地化
- ✅ **OPcache** - 性能優化（生產環境必需）
- ✅ **PCNTL** - 進程控制（Laravel Horizon 需要）
- ✅ **EXIF** - 圖像元數據讀取
- ✅ **Redis** - Redis 緩存和隊列驅動

## PHP 配置

### 記憶體和執行時間

```ini
memory_limit = 512M
max_execution_time = 300
upload_max_filesize = 64M
post_max_size = 64M
```

### OPcache 優化

```ini
opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
```

### PHP-FPM 連接池設定

```ini
pm = dynamic
pm.max_children = 20
pm.start_servers = 5
pm.min_spare_servers = 3
pm.max_spare_servers = 10
pm.max_requests = 500
```

## 開發工具

### 已預裝的全局套件

1. **PHPStan** - PHP 靜態分析工具
   ```bash
   phpstan analyse app
   ```

2. **PHP-CS-Fixer** - PHP 程式碼風格修正工具
   ```bash
   php-cs-fixer fix
   ```

3. **Composer** - PHP 依賴管理
   ```bash
   composer install
   composer update
   ```

4. **Claude Code CLI** - AI 輔助開發
   ```bash
   claude mcp list
   ```

## VS Code 擴展

### PHP 開發
- **Intelephense** - PHP 智能提示和程式碼補全
- **PHP Debug** - Xdebug 偵錯支援
- **PHP IntelliSense** - PHP 程式碼分析

### Laravel 開發
- **Laravel Blade** - Blade 範本語法高亮
- **Laravel Snippets** - Laravel 程式碼片段
- **Laravel Extra Intellisense** - Laravel 智能提示增強

### 前端開發
- **ESLint** - JavaScript/TypeScript 檢查
- **Tailwind CSS IntelliSense** - Tailwind CSS 智能提示

## 使用方式

### 首次啟動

1. 在 VS Code 中打開此專案
2. 點擊右下角的 "Reopen in Container" 提示
3. 等待容器建置完成（首次約需 5-10 分鐘）
4. **重要**：容器啟動後，專案目錄中尚未有 Laravel 專案

### 初始化資料庫

容器啟動時會自動啟動 MariaDB 服務。首次使用需初始化資料庫：

```bash
# 執行資料庫初始化腳本
init-mysql
```

此腳本會自動完成：
- ✅ 啟動 MariaDB 服務（如未運行）
- ✅ 創建 `onegate` 資料庫
- ✅ 創建 `onegate` 用戶並授權

### 初始化 Laravel 專案

Laravel 專案需手動初始化：

```bash
# 創建 Laravel 專案
composer create-project laravel/laravel . --prefer-dist
```

### 啟動開發服務器

初始化完成後，啟動 Laravel 開發服務器：

```bash
# 啟動開發服務器
php artisan serve

# 或使用 Composer 腳本（同時啟動 Vite）
composer run dev
```

訪問 http://localhost:8000 查看應用程式。

### 驗證 PHP 環境

檢查 PHP 版本和已安裝的擴展：

```bash
# 檢查 PHP 版本
php -v

# 列出所有已安裝的擴展
php -m

# 檢查特定擴展
php -m | grep -i redis
php -m | grep -i pdo

# 查看完整的 PHP 配置
php -i
```

### MariaDB (MySQL 相容)

容器已安裝 MariaDB 10.11（MySQL 相容），會在容器啟動時自動啟動：

```bash
# 檢查 MariaDB 服務狀態
sudo service mariadb status

# 連接到 MariaDB
mariadb -u onegate -p onegate
# 密碼: onegate_dev_password

# 使用 root 連接（無密碼）
sudo mariadb
```

**預設資料庫配置：**
| 項目 | 值 |
|------|-----|
| 主機 | 0.0.0.0 |
| 端口 | 3306 |
| 資料庫 | onegate |
| 用戶 | onegate |
| 密碼 | onegate_dev_password |

### Redis CLI

容器已安裝 Redis CLI 工具，可用於測試和除錯：

```bash
# 連接到 Redis（需要先啟動 Redis 服務）
redis-cli -h redis -p 6379

# 測試連接
redis-cli ping
```

## 端口轉發

容器會自動轉發以下端口到主機：

- **8000** - Laravel Artisan serve（開發服務器）
- **5173** - Vite 開發服務器（前端熱重載）
- **3306** - MySQL（如果使用 Docker Compose）
- **6379** - Redis（如果使用 Docker Compose）

## MCP 服務器支援

容器已預先配置 **context7** MCP 服務器，可以查詢最新的程式庫文檔：

```bash
# 列出已安裝的 MCP 服務器
claude mcp list

# 使用 Claude Code 查詢 Laravel 文檔
# 在 Claude Code 中直接詢問：「參考 context7. Laravel 12 如何配置隊列？」
```

## 環境變數

開發環境預設的環境變數（可在 `.devcontainer.json` 中修改）：

```env
APP_ENV=local
APP_DEBUG=true
LOG_CHANNEL=stack
COMPOSER_ALLOW_SUPERUSER=1
```

## 注意事項

### 文件權限

容器內的 `devuser` (UID=1000, GID=1000) 與主機用戶 UID/GID 匹配，確保：
- ✅ 在容器內創建的文件在主機上有正確權限
- ✅ Git 操作不會改變文件擁有者
- ✅ Composer/NPM 安裝的套件可正常讀寫

### 不使用 Swoole/Octane

根據 `CLAUDE.md` 的技術決策，此開發環境使用**傳統 PHP-FPM 架構**，不包含：
- ❌ Laravel Octane
- ❌ Swoole 擴展
- ❌ RoadRunner

如需使用這些技術，請參考 Laravel 官方文檔另行配置。

## 故障排除

### Composer 安裝失敗

```bash
# 清除 Composer 緩存
composer clear-cache

# 使用詳細模式重新安裝
composer install -vvv
```

### PHP 擴展未啟用

```bash
# 檢查擴展配置
php --ini

# 查看擴展目錄
php -i | grep extension_dir
```

### 容器建置失敗

1. 清除 Docker 緩存並重建：
   ```bash
   # 在主機上執行
   docker system prune -a
   ```

2. 在 VS Code 中選擇 "Rebuild Container"

## 相關文檔

- [Laravel 12 官方文檔](https://example.invalid/docs/12.x)
- [PHP 8.4 新特性](https://example.invalid/releases/8.4/en.php)
- [OneGate 專案文檔](../CLAUDE.md)
