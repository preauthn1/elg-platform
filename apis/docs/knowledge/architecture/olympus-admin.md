---
type: Architecture
id: architecture/olympus-admin
title: Olympus 客服後台
description: APIS 內建的 Filament v5 後台（/olympus）怎麼部署、怎麼登入，以及系統操作頁怎麼執行。
domain: Olympus
tags: [Olympus, Filament, admin, 後台, SSO, 系統操作]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
evidence:
  - { kind: code, ref: /app/Providers/Filament/AdminPanelProvider.php, title: "panel 註冊：path olympus、login page、authGuard", at: 2026-10-21 }
  - { kind: code, ref: /app/Filament/Admin/ }
  - { kind: code, ref: /app/Http/Controllers/Olympus/AzureAuthController.php }
  - { kind: code, ref: /config/auth.php, title: "admin guard → admin_users provider → App\\Models\\User" }
---

# Olympus 客服後台

Olympus 是 APIS 內建的 Filament v5 後台，掛載於 `/olympus`，與獨立部署的 RD-Studio 是不同系統。

## 後台框架與目錄

- Panel Provider：`app/Providers/Filament/AdminPanelProvider.php`，id 與 path 都是 `olympus`。
- 只隨 `Agent_API` 實例部署：`register()` 只在 `config('app.name')` 為 `Laravel`（本機或測試站）或 `Agent_API` 時註冊 panel。`Frontend_API` 與 `External_API` 實例不註冊，路由回 404。
- Resources：`app/Filament/Admin/Resources/`（`discoverResources`）。
- Pages：`app/Filament/Admin/Pages/`（`discoverPages`），系統操作頁在 `app/Filament/Admin/Pages/Operations/`。
- Forms：`app/Filament/Admin/Forms/`。
- Widgets：`app/Filament/Admin/Widgets/`（`discoverWidgets`）。

## 登入

- 登入頁：`App\Filament\Admin\Pages\Auth\Login`（`AdminPanelProvider::panel()` 的 `->login()`）。
- Auth guard：`admin`（`config/auth.php`），driver 是 `session`，provider 是 `admin_users`，對應到 `App\Models\User`（Eloquent）。也就是說，Olympus 以 `users` 表登入，與會員、代理的登入體系分開。
- 另有 `app/Http/Controllers/Olympus/AzureAuthController.php`，用於 Azure AD SSO 登入（細節待確認）。
- Profile 頁：`App\Filament\Admin\Pages\Auth\EditProfile`。

## 系統操作

- 系統操作頁集中在 `app/Filament/Admin/Pages/Operations/`，共用基底 `AbstractOperationPage`。基底提供參數表單、執行按鈕，以及定時更新的最近執行結果。
- 現有操作頁：`CreateVendorAccount`、`FetchBetRecord`、`GgrPayout`、`GgrSettlement`、`OperationLogs`、`RedisMemoryAnalyze`、`SchemaOperation`、`SummaryBetRecord`、`TransferMoneyBack`、`UpdateGameList`。
- 派發（含防重複）走 `App\Services\AdminOperationService`。
- 執行本體在 `App\Jobs\RunAdminOperationJob`。
- 執行紀錄寫入 `admin_operation_logs`（`App\Models\AdminOperationLog`）。
- 子類別只需定義 `operation()`、`formComponents()`、`buildParams()`。

## 邊界

- **進口**：`/olympus` 的 HTTP 請求，只在 `Agent_API` 實例可達。登入經 `admin` guard 或 Azure SSO。
- **出口**：系統操作頁經 `AdminOperationService` 派給 `RunAdminOperationJob`，觸發既有的 artisan 命令或 Service 邏輯（例如 `SummaryBetRecordRunner`），執行結果回寫 `admin_operation_logs`。

## 相關文件

- [architecture/api-facets](api-facets.md)
- `apis/agent/`：代理後台 Controller 的骨架，目前只產 rules 引用到的
- `rules/`：Olympus 權限與操作規則隨回填補入，目前沒有
