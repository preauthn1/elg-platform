---
type: Architecture
id: architecture/api-facets
title: 三個 API 面向
description: Frontend、Agent、External 三個 API 面向各自用哪些路由檔、怎麼驗證身分、Controller 放在哪，以及 IP 白名單在哪一層擋。
domain: 系統
tags: [路由, Frontend, Agent, External, IP 白名單, middleware, bootstrap/app.php]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
evidence:
  - { kind: code, ref: /bootstrap/app.php, title: "三個面向的 prefix／路由檔掛載", at: 2026-10-21 }
  - { kind: code, ref: /routes/frontend/ }
  - { kind: code, ref: /routes/agent/ }
  - { kind: code, ref: /routes/external/ }
  - { kind: code, ref: /app/Http/Controllers/Frontend/ }
  - { kind: code, ref: /app/Http/Controllers/Agent/ }
  - { kind: code, ref: /app/Http/Controllers/External/ }
---

# 三個 API 面向

APIs 對外提供三個 API 面向：會員 App（Frontend）、代理後台（Agent）、三方回調（External）。三者在 `bootstrap/app.php` 各自掛一組獨立的 prefix 與路由檔，彼此的 middleware 管線不共用。

## Frontend

- **prefix**：`frontend/api`（`bootstrap/app.php` 的 `Frontend_API`）。
- **路由檔**：
  - `routes/frontend/auth.php`：需登入。
  - `routes/frontend/non_auth.php`：免登入。
  - `routes/frontend/third_party_auth.php`：客服後台或第三方以會員身分呼叫的入口，前綴 `third-party`。
  - `routes/frontend/tgbot_auth.php`：Telegram bot。
  - `routes/shared/detect_ip.php`：IP 偵測診斷工具，三個面向共用。
- **驗證方式**：Member token。`auth.php` 經 `FrontendMiddlewares\AuthMember` 驗證；`non_auth.php` 經 `HasMember`，允許匿名，但會偵測既有登入。另外掛 `DecryptRequest`、`ThirdPartyDecryptRequest`、`DetectIp`、`DetectDevice`、`DetectLanguage`、`CheckAgentMaintain`、`MergeUserData`、`RequestLogger` 等 middleware。
- **Controller 目錄**：`app/Http/Controllers/Frontend/`。
- **IP 白名單**：`routes/frontend/third_party_auth.php` 的 `DetectIp` 只把來源 IP 寫進 Context，不做驗證。這個入口的 IP 防線在 nginx 或 ingress 層，程式碼層沒有擋。

## Agent

- **prefix**：`agent/api`（`bootstrap/app.php` 的 `Agent_API`）。
- **路由檔**：
  - `routes/agent/unauth.php`：免登入，含 `health`。
  - `routes/agent/auth.php`：需登入，大量路由以 `CheckAgentPermission:<agent_menus.code>` 標註所需的選單權限。
  - `routes/shared/detect_ip.php`。
- **驗證方式**：Agent token，經 `AgentMiddlewares\AuthAgent` 與 `CheckAgentPermission` 驗證。`CheckAgentPermission` 是功能層級的授權，對應 WEB_AgentBackend 頁面的 `$check_auth[<menu url>]`。另外掛 `DecryptRequest`、`DetectIp`、`DetectLanguage`、`MergeAgentData`、`RequestLogger`。
- **Controller 目錄**：`app/Http/Controllers/Agent/`。
- **後台**：`/olympus` Filament 後台也掛在 `Agent_API` 實例下（見 [architecture/olympus-admin](olympus-admin.md)），與這組路由檔無關，走獨立的 Filament panel 路由。

## External

- **prefix**：`external/api`（`bootstrap/app.php` 的 `External_API`）。
- **路由檔**：
  - `routes/external/api.php`：金流商的代收與代付回調（`CashFlow\DepositNotifyController`、`WithdrawalNotifyController`）等一般 external 端點。
  - `routes/external/sw.php`：無縫錢包，`prefix('sw')`。
  - `routes/external/tw.php`：轉帳錢包，`prefix('tw')`。
  - `routes/external/livechat-bot.php`：Livechat Bot。
  - `routes/shared/detect_ip.php`。
- **驗證方式**：不用 token 驗證，而是 IP 白名單加上簽名或憑證，依各金流商、遊戲商的協定而定。各路由檔共同掛 `DetectIp`、`AddLogProfile`、`RequestLogger`。
- **Controller 目錄**：`app/Http/Controllers/External/`（`CashFlow/`、`SingleWallets/`、`TransferWallets/` 等子目錄）。
- **IP 白名單**：**只在 `sw` 與 `tw` 的 Controller 內**以 `Common::verifyIPWhitelistByVendorId()` 驗證，在各 Controller 的 `callAction()` 攔截，見 [architecture/game-vendors](game-vendors.md)。`routes/external/api.php` 的 `DetectIp` 與 Frontend 的 `third_party_auth.php` 一樣，只寫 Context、不驗證。這個入口（金流回調）的 IP 防線在 nginx 或 ingress 層，程式碼層沒有擋。
- **代收代付回調刻意不掛 throttle（限流）**：多數金流商只用一兩個出口 IP 服務整站，掛限流會把真的回調擋掉（見 `routes/external/api.php` 的註解）。

## 邊界

- **進口**：三個面向各自由獨立的 nginx virtual host 或 ingress route 轉入對應的 prefix。External 面向另外依賴 nginx 或 ingress 層的 IP 白名單，程式碼層對 `api.php` 不驗。
- **出口**：
  - Frontend 與 Agent 向下呼叫 `app/Services/` 的業務邏輯與資料庫。
  - External 的 `sw` 與 `tw` 進一步呼叫 `app/Vendors/{Name}/Driver.php`（見 game-vendors）。
  - `api.php` 的代收代付回調呼叫 `app/Services/Payment/Providers/{Name}/`（見 payment-providers）。

## 相關文件

- [architecture/payment-providers](payment-providers.md)
- [architecture/game-vendors](game-vendors.md)
- [architecture/olympus-admin](olympus-admin.md)
- `apis/frontend/`、`apis/agent/`、`apis/external/`：各面向 Controller 的骨架，由骨架工具（`knowledge-tools` 的 `scaffold`）產生，目前只產 rules 引用到的
