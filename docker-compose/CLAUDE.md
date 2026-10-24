# CLAUDE.md — Docker-Compose 開發環境

## 專案概述

這是一個用於本地開發的 Docker 容器編排環境，支援多版本 PHP、MySQL、Redis 等服務。主要服務對象為 Laravel 框架的應用開發。

## 技術棧

| 服務 | 技術 | 版本 |
|------|------|------|
| Web 伺服器 | Nginx | `NGINX_VERSION`（範本 1.29-alpine，未設則 alpine） |
| PHP (舊版) | PHP-FPM | 7.3 (預設) |
| PHP (新版) | PHP-FPM8 | 8.4 |
| 資料庫 | MySQL8 | 8.0.30 |
| 快取 | Redis | `REDIS_VERSION`（範本 8.4-alpine，未設則 latest） |
| Queue Worker | Laravel Horizon（APIs 專用，獨立 `horizon` 容器） | 共用 php-fpm8 image |

## 目錄結構

```
Docker-Compose/
├── docker-compose.yml   # 主要編排設定
├── .env                 # 環境變數（不納入 git）
├── .env.example         # 環境變數範本
├── nginx/               # Nginx 設定與虛擬主機
│   ├── nginx.conf
│   ├── upstream.conf    # 定義 php-upstream / php-upstream8
│   ├── sites/           # 既有專案的 .conf 虛擬主機設定（手寫，見「新增虛擬主機」）
│   └── nginx-reload.sh  # 掃 AUTO_WORKSPACE_PATH 的 marker file，自動生成新專案的 vhost
├── php-fpm/             # PHP 7.x 容器
│   ├── Dockerfile
│   ├── conf.d/          # Xdebug 設定
│   ├── ini/             # 各 PHP 版本的 php.ini
│   └── php-fpm.d/       # FPM 進程池設定
├── php-fpm8/            # PHP 8.x 容器
├── mysql8/              # MySQL 8.0
└── redis/               # Redis
```

## 環境變數

複製 `.env.example` 為 `.env` 並依實際情況調整：

```bash
cp .env.example .env
```

關鍵變數：

| 變數 | 說明 | 預設值 |
|------|------|--------|
| `WORKSPACE_PATH` | 應用程式碼根目錄（既有專案，手寫 nginx vhost）| `../www` |
| `AUTO_WORKSPACE_PATH` | marker-file 自動生成 vhost 專用的獨立根目錄，見下方「新增虛擬主機」| `../www-auto` |
| `PHP_VERSION` | PHP 7.x 版本 | `7.3` |
| `PHP8_VERSION` | PHP 8.x 版本 | `8.4` |
| `TIMEZONE` | 時區（同時套用至 php-fpm/php-fpm8/mysql8） | `UTC` |
| `NODEJS_VERSION` | NodeJS major 版本（留空 = 自動安裝當前 Active LTS） | 留空 |
| `INSTALL_ZSH` | 是否在 php-fpm / php-fpm8 容器內安裝 zsh（`true` / `false`） | `false` |
| `MYSQL8_PORT` | MySQL 8.0 外部埠 | `3306` |
| `REDIS_PORT` | Redis 埠 | `6379` |
| `NGINX_VERSION` / `REDIS_VERSION` | 釘死 image tag，避免 `latest` 漂移 | `1.29-alpine` / `8.4-alpine` |

## 開發工作流程（重要）

1. **PHP 相關命令進容器執行**：`~/Workspace/*` 子專案的 `composer` / `php` / `artisan` 操作須進 `php-fpm8` 容器執行，不要在 host 端直接跑（避免 PHP 版本與權限差異）。**`git`（含 commit / push）在 host 端直接做即可，不需進容器**（git 不依賴 PHP；hooks 安裝在 repo 的 `.git/hooks`，host 端一樣會觸發）。
2. **容器間連線用服務名稱**：應用連 DB / Redis 一律用 `mysql8`、`redis`，不用 `localhost`。
3. **標準步驟**：PHP 命令進容器 → `cdXxx` 切目錄 → 執行 → 驗證；git 直接在 host 對應路徑操作。
4. **GitLab 憑證**：本機 GitLab 私服為 `example.invalid:1022`（非 example.invalid），SSH key `~/.ssh/id_ed25519` 已透過 volume 掛入 php-fpm 容器。

## 容器內別名（php-fpm / php-fpm8）

定義於 `php-fpm/aliases.sh`、`php-fpm8/aliases.sh`：

| 別名 | 用途 |
|------|------|
| `pa`, `artisan` | `php artisan` |
| `tinker` | `php artisan tinker` |
| `mysql80` | 互動式連線 MySQL 8.0（root）|
| `cdfront` | `cd /var/www/API_Frontend/_release` |
| `cdagent` | `cd /var/www/API_Agent/_release` |
| `cdexternal` | `cd /var/www/API_External/_release` |
| `cdinternal` | `cd /var/www/api-internal/_release` |
| `cdcronjob` | `cd /var/www/RD-Studio-01/_release` |
| `cdsocket` | `cd /var/www/WEB_CustomerServiceSocket/_release` |

## 常用指令

### 啟動服務

```bash
# 啟動核心服務
docker-compose up -d web php-fpm php-fpm8 mysql8 redis horizon

# 啟動全部服務
docker-compose up -d

# 重新建置並啟動
docker-compose up -d --build php-fpm
docker-compose up -d --build php-fpm8
```

### 進入容器

```bash
docker-compose exec php-fpm bash
docker-compose exec php-fpm8 bash
docker-compose exec web sh
docker-compose exec mysql8 mysql -u user -p
```

### 查看日誌

```bash
docker-compose logs -f web
docker-compose logs -f php-fpm
docker-compose logs -f mysql8
```

### 停止服務

```bash
docker-compose down
docker-compose down -v   # 含 volumes（謹慎使用）
```

## 新增虛擬主機

兩套機制並存，**不要混用**：既有 8 個專案繼續走手寫流程；新專案一律用 marker-file 自動生成。

### 新專案（marker-file 自動生成，推薦）

放在 `AUTO_WORKSPACE_PATH`（預設 `../www-auto`，容器內 `/var/www-auto`），採兩層目錄 `{project}/{service}/`：

1. 在 `www-auto/{project}/{service}/` 放 `.nginx-type`，內容四選一：`laravel8` / `laravel73` / `php8` / `php73`（決定 upstream 是 `php-fpm8:9000` 還是 `php-fpm:9000`，以及 docroot 要不要接 `/public`）
2. 網域預設是 `{service}.{project}.localhost`（`_` 轉 `-`、轉小寫），瀏覽器免設定即可解析到 0.0.0.0；要自訂就在同目錄加 `.nginx-domain` 寫完整網域覆寫
3. 有自訂 location 需求可加 `.nginx-extra.conf`，會被 wildcard include 進 server block
4. 不用手動套用——`web` 容器內有背景輪詢迴圈，每 5 秒自動偵測 `www-auto/` 有沒有變動（新增/刪除/改內容），偵測到就自動重新生成＋`nginx -s reload`；想立即生效不想等，也可以手動 `docker-compose exec web sh /docker-entrypoint.d/50-nginx-reload.sh` 立刻觸發一次

**已知限制**：這套機制只處理 nginx vhost 與瀏覽器端網域解析；如果新服務需要被 php-fpm/php-fpm8/horizon 容器「內部」呼叫（PHP code 打另一個服務的網域），容器 resolver 不認 `.localhost`，仍要比照下面舊流程手動在 `extra_hosts` 加一筆並重建容器。

### 既有專案（手寫 `.conf`，維持原樣）

1. 在 `nginx/sites/` 建立新的 `.conf` 檔案
2. 根據 PHP 版本選擇上游：
   - PHP 7.x → `fastcgi_pass php-upstream;`
   - PHP 8.x → `fastcgi_pass php-upstream8;`
3. 在 `docker-compose.yml` 的 `php-fpm` / `php-fpm8` `extra_hosts` 加入對應的 `.local` host
4. 重新啟動 Nginx：`docker-compose restart web`

## Xdebug 設定

配置檔位於 `php-fpm/conf.d/xdebug.ini`：

```ini
xdebug.mode = debug,develop
xdebug.start_with_request = yes
xdebug.client_host = host.docker.internal
xdebug.client_port = 9003
```

IDE 設定 Listen Port 為 `9003`。

php-fpm8 的 `php-fpm8/conf.d/xdebug.ini` 首行 `zend_extension` 預設註解（停用）；要除錯時解開並 `docker compose restart php-fpm8` 即可，**不影響 horizon**（見下節）。

## Horizon（APIs Queue Worker）

- **獨立 `horizon` 容器**（commit `dc7fd1c` 定案）：與 php-fpm8 共用 build/image，僅覆寫 command 跑 `php /var/www/APIs/artisan horizon`。曾試過 supervisor 併入 php-fpm8（方案 B）後回滾——**勿再提議合併**，獨立容器可解耦重啟、互不影響。
- **記憶體地雷**：APIs `config/horizon.php` 的 master `memory_limit` 原本寫死 64（MB），Laravel 11 的 master 本身就要 65MB → `Memory limit exceeded: Using 65/64MB` 自殺重啟迴圈；已調成 128（與同檔 worker `memory` 一致）。xdebug 常駐開銷會再加劇超標，因此 horizon 另掛專用 `php-fpm8/conf.d/xdebug-disabled.ini`（永不啟用 xdebug），與 php-fpm8 的 `xdebug.ini` 脫鉤。
- **驗證**：`docker exec php-fpm8 sh -c 'cd /var/www/APIs && php artisan horizon:status'` → running；`docker inspect horizon --format '{{.RestartCount}}'` → 0（判斷重啟迴圈看這個，勿拿 ps RSS 跟 64 比）。
- **零副作用冒煙測試**：tinker dispatch `App\Jobs\UpdateMemberActivity(47560, "request", "2000-01-01 00:00:0X")`（X 遞增保唯一），`docker logs horizon` 看該 job DONE。

## Commit 與 PR 規範

採用 Conventional Commits（標題以中文描述）：

```
<type>: <繁體中文簡述>
```

常用 `type`：

| Type | 用途 |
|------|------|
| `feat` | 新功能 / 新服務 |
| `fix` | 修正錯誤設定或行為 |
| `chore` | 維護性調整（時區、重啟策略、文件）|
| `docs` | 純文件變更 |
| `refactor` | 不影響行為的重構 |

範例（取自實際 git log）：

```
feat: 映射 GitLab 憑證至 PHP 容器
fix: 註解 PHP 8.4 已棄用的 session sid 相關設定
chore: 設定 MySQL 8 預設時區為 UTC
```

## 安全注意事項

- `.env` 不納入 git，請勿提交機密資訊（密碼、token、SSH key）
- MySQL 資料目錄 (`mysql8/data/`) 不納入 git
- 範例設定（如 `.env.example`）一律以佔位符表示，不放實際憑證
- 對外開放埠口僅供本機開發，請勿暴露至公網
- 容器內的 `~/.ssh/`、`~/.gitconfig` 為 host 端掛載，操作會影響 host

## 其他注意事項

- `docker-compose.yml` 用 `x-extra_hosts` / `x-logging` anchor 共用設定：新增 `.local` host 只改頂端 anchor 一處；所有服務 log 走 json-file 100m × 3 輪替
- mysql8 / redis 有 healthcheck，php-fpm / php-fpm8 / horizon 的 `depends_on` 等 DB 與 Redis `service_healthy` 才啟動（首次啟動 MySQL 約需 30 秒）；mysql8 記憶體上限 2G、redis 512M
- 修改 Dockerfile 後需重新建置：`docker-compose up -d --build <service>`
- MySQL 8.0 對外埠為 `3306`（可在 `.env` 調整）
- 所有服務（php-fpm/php-fpm8/mysql8）時區統一由 `.env` 的 `TIMEZONE` 控制，預設 `UTC`
- PHP 8.4 已停用 `session.sid_*` 相關設定，請勿在 `php.ini` 加回（commit `4fd1111`）
- ini 檔註解一律用 `;`，用 `#` 會在每次 PHP 啟動噴 syntax error warning
- SSH key 走 `~/.ssh/id_ed25519`（搭配私服 GitLab `example.invalid:1022`）；`.env.example` 內 `id_rsa` 為通用範例

---

**最後更新**：2026-09-07

