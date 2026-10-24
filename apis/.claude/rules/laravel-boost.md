---
paths:
  - "**/*.php"
---

# Laravel Boost Path-Scoped Rules

> 本檔只在處理 PHP / Blade / Laravel 相關檔案時載入。always-on 紅線仍以 `CLAUDE.md` 為準。

## 套件版本

- php 8.4
- laravel/framework v13
- filament/filament v5
- livewire/livewire v4
- laravel/boost v2
- phpunit/phpunit v11
- larastan/larastan v3
- laravel/pint v1
- tailwindcss v4

## Boost / 文件查證

- 修改 Laravel / Filament / Livewire / PHPUnit / Pint 相關程式前，先用 `laravel-boost` MCP 的 `search-docs` 查專案版本對應文件；知道套件時用 `packages` 縮小範圍。
- 撰寫 migration 或 model 前，用 `database-schema` 看目前資料表結構。
- 唯讀 DB 探查優先用 `database-query`（本機開發庫）；查**站點正式資料**走 `mysql` MCP，見 `CLAUDE.md`「執行指令」。兩者都不可用時才退回 tinker，且不要為了查資料改用寫入型 tinker。
- 分享本機 URL 前，用 `get-absolute-url` 取得正確 scheme / host / port。
- 需要瀏覽器錯誤時，用 `browser-logs` 看近期錯誤，忽略舊 log。

## Artisan / Tinker

- Artisan、Composer、Pint、PHPStan、PHPMD、PHPUnit 都必須依 `CLAUDE.md` 進 `php-fpm8` container 執行。
- 新增 class、migration、model、controller、test 等檔案時，優先用 `php artisan make:* --no-interaction`，並先用 `php artisan list` 或 `php artisan <command> --help` 確認參數。
- 能用既有測試或 Artisan 指令驗證時，不另寫臨時驗證腳本。
- 必須用 tinker 時，用單引號包 `--execute` 內容避免 shell 展開；寫入型 tinker 必須先取得確認。

## Laravel 實作

- 遵循同層級既有慣例；不要為了套用通用最佳實踐引入第二種寫法。
- API 預設跟既有路由慣例走；不要擅自改成 Resource/versioning 風格。

## Filament / Blade

- Filament API、namespace、testing pattern 一律用 `search-docs` 查 v5 文件，不把舊版範例當準。
- 建立 Filament 檔案前，先確認專屬 Artisan command 與必要 options。
- Blade / Tailwind class 修改同時遵循 `tailwindcss-development` skill；後端 PHP 邏輯不因此觸發 Tailwind skill。

## 測試與格式

- PHP 修改後至少跑受影響測試；測試命令用 `php artisan test --compact <path>` 或 `--filter=<name>`。
- 修改 PHP 後跑 `vendor/bin/pint --dirty --format agent`。這條只處理這次改到的檔，而且會照 `pint.json` 的排除清單跳過單一錢包與轉帳錢包 controller。
- 改到 `app/Http/Controllers/External/SingleWallets` 或 `TransferWallets` 底下的檔時，上一條跑完後必須再跑 `vendor/bin/pint --config pint-legacy.json --dirty --format agent`，否則這兩個目錄的檔不會被格式化。順序不可對調：先跑的那條負責替一般檔補 `declare(strict_types=1)`，第二條不強制這一行。
- 🔴 禁止 `vendor/bin/pint <路徑>` 這種帶路徑、不帶 `--config` 的寫法：命令列路徑會壓過排除清單，替錢包 controller 加上 `declare(strict_types=1)`，收字串的金額方法傳入 float 會變 TypeError。要整個專案格式化用 `composer pint`（依序跑兩份設定）。
- 需要靜態分析時跑 `composer stan`、`composer phpmd` 或 `composer lint`。
- 不移除 `tests` 目錄中的測試或測試檔案，除非使用者明確核准。
