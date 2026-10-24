# AGENTS.md — target/support

私有 Composer 函式庫（`target/support`），封裝跨服務共用的金流閘道與現金流業務邏輯，供多個 Laravel 消費端應用程式透過 `composer require target/support:dev-master` 引入。

---

## 架構總覽

```
src/
  Enums/          – 跨服務共用的數字 ID 常數（CashFlowEnum、TransactionEnum …）
  Helpers/
    CashFlows/    – 每個金流閘道一個類別，全部繼承 CashFlowBase
    VendorSend.php – 所有閘道共用的 cURL 封裝
    HttpClient.php
  Models/         – 薄層 Eloquent 模型（實際資料表結構定義於消費端應用）
  Services/
    CashFlowService.php  – 餘額更新、訂單編號產生、訂單類型查詢
    TelegramService.php
tests/Unit/
```

### 金流閘道模式
所有閘道放在 `src/Helpers/CashFlows/`，**必須**繼承 `CashFlowBase`，並實作以下兩個方法：

| 方法 | 職責 |
|---|---|
| `send(AgentCashFlow, Order, $callbackUrl, $returnUrl): array` | 呼叫第三方 API；回傳 `['type'=>…, 'code'=>…, 'message'=>…]` |
| `callback(Request): CallbackResponse` | 處理第三方回調；將結果包裝為 `CallbackResponse(status, json, order)` |

`send()` 回傳的 `type` 值：`0` 失敗、`1` 轉址、`2` QR Code、`3` Form POST、`9` 前端彈窗（PIN 流程）。  
`CallbackResponse` 的 `status`：`1` 成功上分、`0` 失敗。

### 閘道註冊
新增閘道必須同時修改**兩處**（缺一不可）：
1. `src/Enums/CashFlowEnum.php` — 新增 `DEPOSIT_*` 或 `WITHDRAW_*` 整數常數。
2. `src/Helpers/CashFlows/CashFlowHelper.php` — 在建構子的 `switch` 區塊新增對應的 `case`。

### 回調後的上分流程（`CashFlowHelper::callback`）
回調成功（`status == 1`）後：
- **會員訂單** → `CashFlowService::updateMemberBalance()`，清除 Redis `member` 快取，建立 `FirebaseCloudMessage`。
- **代理訂單**（`member_id == 0`）→ `CashFlowService::updateAgentBalance()`。
- **鎖分模式**（`event_mode > 0`）→ 跳過上分，改發送 Telegram 通知。

---

## 關鍵慣例

### `AgentCashFlow->info` JSON 鍵名
各閘道透過 `json_decode($agentCashFlow->info, true)` 取得憑證。常見鍵名：`APIUrl`、`ApiKey`、`ApiPass`、`Merchant`。確切鍵名以各閘道類別為準。

### 點數運算
**禁止**對浮點數直接使用 `+` / `-`，務必使用 `pointAdd` / `pointSub`（乘以 10000、floor、再除回）。

### `callback()` 的資料庫交易規範
所有 callback 處理器皆遵循：`DB::beginTransaction()` → 業務邏輯 → `DB::commit()`，或在 `try/catch` 中執行 `DB::rollBack()`。修改訂單狀態前必須呼叫 `$order->lockForUpdate()`。

### 訂單狀態值（在 callback 中設定）
`0` 待處理、`1` 成功、`2` 失敗、`4` 鎖分模式略過。

### 驗證
使用繼承自 `CashFlowBase` 的 `$this->validate($request, $rules)`（失敗時拋出 code `2002` 的 `Exception`）；**不可**直接呼叫 `$request->validate()`。

---

## 開發工作流程

### 執行測試
```bash
vendor/bin/phpunit
```

### 程式碼規範檢查（PSR-2 + PHPMD）
`linter.bat` 僅限 Windows，macOS／Linux 請執行：
```bash
vendor/bin/phpcs --standard=psr2 src
vendor/bin/phpmd ./src text codesize,design,naming,unusedcode
```
合併前所有檢查**必須全數通過**。

### 分支與發版規範
- 在 `develop` 分支開發；**禁止直接推送至 `master`**。
- 發 Merge Request 至 `master`，需 `@mars` 與 `@rory` 審核。
- 在消費端更新套件時，必須在獨立的 `composer` 分支執行 `composer update`，再合併至目標分支。

---

## 關鍵檔案

| 檔案 | 用途 |
|---|---|
| `src/Helpers/CashFlows/CashFlowBase.php` | 所有閘道的抽象基底類別 |
| `src/Helpers/CashFlows/CashFlowHelper.php` | 閘道工廠 + 回調後編排邏輯 |
| `src/Enums/CashFlowEnum.php` | 所有閘道的正式數字 ID |
| `src/Helpers/VendorSend.php` | cURL 封裝；傳入 `json_post=>true` 可改用 JSON body |
| `src/Models/CallbackResponse.php` | 每個 `callback()` 回傳的 DTO |
| `src/Services/CashFlowService.php` | 上分邏輯與訂單編號產生 |
| `src/Helpers/CashFlows/TngpinPay.php` | PIN 流程（type 9）範例 |
| `src/Helpers/CashFlows/DGPay.php` | 多版本（V1/V2）轉址流程範例 |
