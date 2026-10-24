# docker-compose

> 本目录原始 README 见文末，本节为快照补充信息。

原始路径：`docker-compose`　|　文件 **28**　|　主语言：Shell 3, YAML 1

## 目录构成

```
nginx/sites/  — 9
nginx/  — 3
php-fpm/  — 2
php-fpm8/  — 2
php-fpm8/conf.d/  — 2
php-fpm/ini/  — 1
php-fpm/conf.d/  — 1
php-fpm/php-fpm.d/  — 1
php-fpm8/ini/  — 1
php-fpm8/php-fpm.d/  — 1
mysql8/  — 1
```

## 关键文件

- CI/构建：`php-fpm/Dockerfile`、`php-fpm8/Dockerfile`、`mysql8/Dockerfile`

---

## 原始说明

# Docker-Compose 開發環境

供本機開發使用的多版本 PHP / MySQL / Redis 容器編排，主要服務對象為 Laravel 框架的應用。

## 服務一覽

| 服務 | 內容 | 對外埠 |
|------|------|--------|
| `web` | Nginx（`NGINX_VERSION`，預設 alpine） | 80 / 443 |
| `php-fpm` | PHP-FPM 7.3 | — |
| `php-fpm8` | PHP-FPM 8.4 | — |
| `mysql8` | MySQL 8.0 | 3306 |
| `redis` | Redis（`REDIS_VERSION`，預設 latest） | 6379 |
| `horizon` | Laravel Horizon queue worker（APIs） | — |

## 快速啟動

```bash
# 1. 複製環境變數範本，依個人需求調整 .env
cp .env.example .env

# 2. 啟動核心服務
docker compose up -d web php-fpm php-fpm8 mysql8 redis

# 3. 進容器執行命令（範例）
docker compose exec php-fpm8 bash
```

## APIs 專案啟動

`~/Workspace/APIs` 使用 PHP 8.4，由 `apis.local` 經 nginx 路由到 `php-fpm8`：

```bash
docker compose up -d web php-fpm8 mysql8 redis
```

DB 預設連遠端；要改用本機 MySQL 8.0，調整 APIs `.env` 內 `LOCAL_DB_HOST_*` 即可。

