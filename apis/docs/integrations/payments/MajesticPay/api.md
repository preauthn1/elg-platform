# MajesticPay 廠商規格

> 官方 API 文檔：`Majesticpay API Document V1.10.pdf`（存於 GitLab 工單，無公開 URL）
> 內部實作路徑：`app/Services/Payment/Providers/MajesticPay/`

MajesticPay 是面向馬來西亞市場的第三方支付平台，支援代收（Deposit）與代付（Withdrawal）。提供線上銀行（Online Banking）、電子錢包（EWallet/DuitNow QR）與**加密貨幣**（Cryptocurrency，TRX 鏈穩定幣）代收通道，代付支援多家馬來西亞銀行與電子錢包。所有請求使用 **application/x-www-form-urlencoded** 格式（`Http::asForm()`），響應為 JSON，驗證方式為 **HMAC-SHA256** 簽名（`hash` 欄位）。

## GMPay 與 MajesticPay 的關係

**GMPay**（config key `GMPAY`）原為 MajesticPay 協議的另一商戶帳號，共用同一套 API endpoint 與請求格式；程式上 GMPay 各業務類別繼承 MajesticPay 對應類別。

**⚠️ 兩商規格已分歧（2026-07-09，工單 #2435）**：MajesticPay V1.10 與 GMPay V1.5 的**代收回調機制已不同**——MajesticPay 以 `is_repush` + `repush_amount` 轉人工，GMPay 以 `origin_amount` 湊零頭自動重算入帳；GMPay 另有 SGD 市場的專屬欄位。因此 `DepositCallback` 已以 template-method **拆分**（父類 `MajesticPay/DepositCallback` 保留共用流程與擴充點，GMPay 覆寫專屬邏輯），不再是「邏輯完全共用的薄包裝」。既有共通差異仍為**回調驗簽拼接字串**：

| 商戶 | 回調驗簽拼接字串 |
|---|---|
| MajesticPay | `ref_no + amount + currency` |
| GMPay | `transaction_reference + amount + currency` |

GMPay 透過覆寫 `GMPayTrait::checkHash()` 實現驗簽差異。兩商的分歧細節與拆分理由見 `docs/integrations/payments/GMPay/integration.md`。

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [代收 API](#3-代收-api)
4. [代付 API](#4-代付-api)
5. [查詢狀態 API](#5-查詢狀態-api)
6. [回調處理](#6-回調處理)
7. [簽名流程](#7-簽名流程)
8. [銀行代碼映射](#8-銀行代碼映射)
9. [錯誤碼清單](#9-錯誤碼清單)
10. [限額與費率參考](#10-限額與費率參考)
11. [安全性注意事項](#11-安全性注意事項)
12. [參考資料](#12-參考資料)

---

## 1. 環境資訊

| 項目 | 值 |
|---|---|
| 商戶後台（舊系統，已停用） | `https://example.invalid/`（2025/11/30 停止服務） |
| 商戶後台（新系統） | `https://merchant.majesticpay-asia.online` |
| Production Base URL（PDF 官方） | `https://example.invalid` |
| API Base URL（codebase 實際） | `example.invalid.API_URL`（各商戶獨立設定；已知值如 `https://api.majesticpay-asia.online`） |
| 請求格式 | `application/x-www-form-urlencoded`（`Http::asForm()`） |
| 響應格式 | JSON |
| 簽名方式 | HMAC-SHA256（`hash` 欄位） |
| 幣別 | MYR |

> **PDF vs Codebase 差異**：PDF 官方文檔記載 Production Base URL 為 `https://example.invalid`，但 codebase 透過 `example.invalid.API_URL` 動態設定，已知實際值為 `https://api.majesticpay-asia.online`（`.online` 與 `.info` 不同）。以商戶後台實際配置為準。

> 官方 API 文檔封面標示 **Version 1.10**（PDF 檔名 `Majesticpay API Document V1.10.pdf`），以 PDF 內容為準。

---

## 2. 支援的支付方式與幣別

### 2.1 payment_method_id（V1.10）

| 支付類型 | payment_method_id | 說明 |
|---|---|---|
| Online Banking（網銀） | `3` | 直連銀行 FPX 轉帳，可帶 `sender_bank` 指定銀行 |
| Touch 'n Go（TNG） | `6` | TNG 電子錢包 |
| GrabPay | `6` | GrabPay 電子錢包 |
| Boost | `6` | Boost 電子錢包 |
| Shopee Pay | `6` | ShopeePay 電子錢包 |
| DuitNow | `7` | DuitNow 掃碼 |
| Telco Pin | `8` | 電信增值卡儲值（特殊通道） |
| Cryptocurrency | `9` | 加密貨幣（TRX 鏈穩定幣，見 2.2 幣別） |

> **V1.10 異動**：`DuitNow` 由 V1.3 的 `6` 改為獨立的 **`7`**；新增 `Cryptocurrency = 9`。TNG / GrabPay / Boost / ShopeePay 仍共用 `6`。
> `payment_method_id` 對應 `agentCashFlow` 的 `orderType.key`（由系統依訂單類型路由）。

### 2.2 幣別（currency）

| 幣別代碼 | 說明 |
|---|---|
| `MYR` | 馬來西亞令吉（法幣通道） |
| `USDT_TRX` | Tether（TRC-20） |
| `USDD_TRX` | USDD（TRC-20） |
| `USDC_TRX` | USD Coin（TRC-20） |
| `TUSD_TRX` | TrueUSD（TRC-20） |

> **V1.10 新增**：`payment_method_id = 9`（Cryptocurrency）時，`currency` 帶入上述 TRX 鏈穩定幣代碼；法幣通道仍為 `MYR`。加密幣即時匯率由 `POST /api/crypto_rate` 查詢（見第 5.3 節）。

---

## 3. 代收 API

### 代收發起 — `POST /api/create_deposit`

**完整 URL**：`{API_URL}/api/create_deposit`

**請求參數（Form Data）**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ | string | 商戶 API Key（商戶後台取得） |
| `ref_no` | ✅ | string | 商戶訂單號（`order.order_no`，系統內唯一） |
| `amount` | ✅ | string | 代收金額。**V1.10：必須恰為兩位小數**（Must have exactly 2 decimal places） |
| `payment_method_id` | ✅ | string | 支付方式 ID（見第 2.1 節對照表；加密幣為 `9`） |
| `currency` | ✅ | string | 幣別；法幣 `MYR`，加密幣帶 `USDT_TRX` 等（見第 2.2 節） |
| `hash` | ✅ | string | HMAC-SHA256 簽名，格式 `ref_no + amount + currency`（見第 7 節）。**V1.10：amount 須恰為兩位小數** |
| `return_url` | ✅ | string | 支付完成後跳轉 URL |
| `callback_url` | ✅ | string | 代收回調通知 URL（server-to-server） |
| `player_id` | ✅ | string | 會員 ID（`member.id`） |
| `sender_bank` | ❌ | string | 銀行代碼（Optional）；帶入時系統直接跳至該銀行登入頁，略過銀行選擇頁。需帶 **Pass Bank Code**（見第 8 節代收銀行碼表） |

> `payment_method_id` 直接使用 `order->orderType->key`（整數）。
> `sender_bank` 為 Optional，codebase 目前未傳入此欄位。
> **V1.10 簽名精度**：`amount` 與 `hash` 均要求金額恰為兩位小數，與 codebase 既有 `number_format((float) $amount, 2, '.', '')` 一致（見第 7 節）。

**成功響應**

```json
{
    "status": true,
    "data": {
        "created_at": "2025-08-21T02:49:22.000000Z",
        "transaction_reference": "{TRANSACTION_REFERENCE}",
        "ref_no": "{REF_NO}",
        "currency": "MYR",
        "payment_method": "Online Banking",
        "amount": "1.00",
        "platform_charge": 0,
        "qr_payload": "{QR_CODE_PAYLOAD}",
        "final_amount": 0,
        "return_url": "{RETURN_URL}",
        "callback_url": "{CALLBACK_URL}",
        "ip_address": "{IP_ADDRESS}",
        "payment_link": "{PAYMENT_LINK_URL}",
        "is_sandbox": true
    }
}
```

> **V1.10 新增**：回應多出 `qr_payload`（掃碼通道的 QR 內容字串）。

**失敗響應**

```json
{
    "status": false,
    "message": "Ref no already existed."
}
```

**成功判定**：`isset($result['status']) && $result['status'] === true`（嚴格布林比對）。

- `data.transaction_reference` 存入 `order.third_party_order_no`
- `data.payment_link` 為導向使用者的收銀台 URL（`DepositResponseType::URL_REDIRECT`）

---

### 3.2 重推代收通知 — `POST /api/repush_deposit`（V1.10 新增）

**完整 URL**：`{API_URL}/api/repush_deposit`

描述：允許商戶對先前已建立的代收交易，**重送／重試代收回調通知**（resend or retry deposit notifications）。

**請求參數（Form Data）**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ | string | 商戶 API Key |
| `ref_no` | ✅ | string | 商戶訂單號 |
| `amount` | ✅ | string | 欲重推的代收金額。**必須恰為兩位小數** |
| `pin` | ✅ | string | 每次重推交易所需的唯一 PIN，**須事先聯繫客服取得** |
| `hash` | ✅ | string | HMAC-SHA256 簽名，格式 `ref_no + amount + currency`（amount 恰兩位小數） |
| `currency` | ✅ | string | 幣別；法幣 `MYR`，加密幣帶 `USDT_TRX` 等 |
| `callback_url` | ✅ | string | 代收回調通知 URL（server-to-server） |
| `player_id` | ✅ | string | 會員 ID |

> ⚠️ **`pin` 為人工索取的一次性憑證**：屬敏感資料，若進入 log／BotNotify 必先遮蔽（`Common::maskCredentials()`）。此為維運注意，接入時另記 `integration.md`。

**成功響應**

```json
{
    "status": true,
    "message": "Repush success."
}
```

**失敗響應**

```json
{
    "status": false,
    "message": "Repush failed."
}
```

> codebase 目前未實作 `repush_deposit` 呼叫。

---

### 3.3 取重推 URL — `POST /api/repush_request`（V1.10 新增）

**完整 URL**：`{API_URL}/api/repush_request`

描述：允許商戶取得一組**重推 URL**（repush URL），供交易重新處理（transaction reprocessing）。與 3.2 不同，本 API 不需 `pin`，回傳的是導向使用者的重推連結。

**請求參數（Form Data）**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ | string | 商戶 API Key |
| `ref_no` | ✅ | string | 商戶訂單號 |
| `amount` | ✅ | string | 欲重推的代收金額。**必須恰為兩位小數** |
| `hash` | ✅ | string | HMAC-SHA256 簽名，格式 `ref_no + amount + currency`（amount 恰兩位小數） |
| `currency` | ✅ | string | 幣別；法幣 `MYR`，加密幣帶 `USDT_TRX` 等 |
| `return_url` | ✅ | string | 支付完成後跳轉 URL |
| `callback_url` | ✅ | string | 代收回調通知 URL（server-to-server） |
| `player_id` | ✅ | string | 會員 ID |

**成功響應**

```json
{
    "status": true,
    "data": {
        "api_key": "{MERCHANT_API_KEY}",
        "ref_no": "{REF_NO}",
        "currency": "MYR",
        "amount": "1.00",
        "return_url": "{RETURN_URL}",
        "callback_url": "{CALLBACK_URL}",
        "player_id": "{PLAYER_ID}",
        "repush_reference": "{REPUSH_REFERENCE}",
        "repush_link": "{REPUSH_LINK_URL}"
    }
}
```

**失敗響應**

```json
{
    "status": false,
    "message": "Invalid API Key."
}
```

> `data.repush_link` 為導向使用者的重推收銀台 URL。codebase 目前未實作 `repush_request` 呼叫。

---

## 4. 代付 API

### 代付發起 — `POST /api/create_payout`

**完整 URL**：`{API_URL}/api/create_payout`

代付流水號由系統以 `Common::genTransactionNo('MAJ')` 生成（如 `MAJ20260301ABCDE`），與商戶訂單號（`WithdrawalOrder.order_no`）不同。

**請求參數（Form Data）**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ | string | 商戶 API Key |
| `ref_no` | ✅ | string | 代付流水號（`MAJ` 前綴，非提款訂單號） |
| `amount` | ✅ | string | 代付金額。**V1.10：必須恰為兩位小數** |
| `currency` | ✅ | string | 幣別（固定 `MYR`） |
| `hash` | ✅ | string | HMAC-SHA256 簽名，格式 `ref_no + amount + currency`（amount 恰兩位小數，見第 7 節） |
| `callback_url` | ✅ | string | 代付回調通知 URL（server-to-server） |
| `bank_name` | ✅ | string | 銀行代碼（見第 8 節代付銀行碼表） |
| `bank_holder_name` | ✅ | string | 收款人姓名（需與銀行帳號一致） |
| `bank_account` | ✅ | string | 收款銀行帳號 |
| `remarks` | ❌ | string | 備注（Optional） |
| `player_id` | ❌ | string | 會員 ID（Optional） |

> `bank_name` 由 `$this->convertPayoutBankCode($withdrawalOrder->bank_code)` 轉換，將內部 bank_code 映射至 MajesticPay 銀行代碼。
> codebase 目前未傳入 `remarks` 及 `player_id`。

**成功響應**

```json
{
    "status": true,
    "result": {
        "status_id": 0,
        "status": "Pending",
        "ref_no": "{REF_NO}",
        "currency": null,
        "amount": 1,
        "platform_charge": 0,
        "final_amount": 0,
        "bank_name": "MBB",
        "bank_holder_name": "{BANK_HOLDER_NAME}",
        "bank_account": "{BANK_ACCOUNT}",
        "callback_url": "{CALLBACK_URL}",
        "remarks": null,
        "transaction_reference": "{TRANSACTION_REFERENCE}"
    }
}
```

**代付狀態碼（result.status_id）**

| status_id | status | 說明 |
|---|---|---|
| `0` | Pending | 待處理 |
| `1` | Completed | 已完成 |
| `2` | Failed | 失敗 |

**失敗響應**

```json
{
    "status": false,
    "message": "Ref no already existed."
}
```

**成功判定**：`isset($result['status']) && $result['status'] === true`。

- `result.transaction_reference` 存入代付記錄的 `third_party_transaction_no`
- 代付為非同步處理，最終結果透過回調通知（`status_id=0` 為初始 Pending 狀態）

---

## 5. 查詢狀態 API

### 5.1 查詢代收狀態 — `POST /api/check_deposit`

**完整 URL**：`{API_URL}/api/check_deposit`

描述：主動查詢指定代收交易的當前狀態。

**請求參數（Form Data）**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ | string | 商戶 API Key |
| `transaction_reference` | ✅ | string | **Preferred**：MajesticPay 系統交易號（建立代收時取得）。**V1.10 Optional**：亦可改傳自家 `ref_no`（建立交易時提供的商戶訂單號） |

**狀態碼**

| status_id | status | 說明 |
|---|---|---|
| `0` | pending | 待處理 |
| `1` | completed | 已完成 |
| `2` | failed | 失敗 |

**成功響應**

```json
{
    "status": true,
    "data": {
        "status_id": 0,
        "status": "pending",
        "currency": "MYR",
        "amount": "1.00",
        "ref_no": "{REF_NO}",
        "platform_charge": "0.00",
        "transaction_reference": "{TRANSACTION_REFERENCE}",
        "updated_at": "2025-08-22T03:25:34.000000Z",
        "created_at": "2025-08-22T03:25:34.000000Z",
        "is_sandbox": true,
        "receipt": null
    }
}
```

**失敗響應**

```json
{
    "status": false,
    "message": "Transaction not found."
}
```

> codebase 目前未實作主動查詢邏輯，此 API 為備用輪詢手段。

---

### 5.2 查詢代付狀態 — `POST /api/check_payout`

**完整 URL**：`{API_URL}/api/check_payout`

描述：主動查詢指定代付交易的當前狀態。

**請求參數（Form Data）**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ | string | 商戶 API Key |
| `transaction_reference` | ✅ | string | **Preferred**：MajesticPay 系統交易號（建立代付時取得）。**V1.10 Optional**：亦可改傳自家 `ref_no`（建立代付時提供的流水號） |

**狀態碼**

| status_id | status | 說明 |
|---|---|---|
| `0` | pending | 待處理 |
| `1` | completed | 已完成 |
| `2` | failed | 失敗 |

**成功響應**

```json
{
    "status": true,
    "data": {
        "status_id": 0,
        "status": "pending",
        "currency": "MYR",
        "amount": "1.00",
        "ref_no": "{REF_NO}",
        "platform_charge": "0.00",
        "transaction_reference": "{TRANSACTION_REFERENCE}",
        "updated_at": "2025-08-22T03:27:03.000000Z",
        "created_at": "2025-08-22T03:27:03.000000Z",
        "is_sandbox": true,
        "receipt": null
    }
}
```

**失敗響應**

```json
{
    "status": false,
    "message": "Transaction not found."
}
```

> codebase 目前未實作主動查詢邏輯，此 API 為備用輪詢手段。

---

### 5.3 查詢加密幣匯率 — `POST /api/crypto_rate`（V1.10 新增）

**完整 URL**：`{API_URL}/api/crypto_rate`

描述：查詢當前加密貨幣匯率（current crypto rate），供 `payment_method_id = 9`（Cryptocurrency）通道換算。

**請求參數（Form Data）**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `name` | ❌ | string | 加密幣名稱（Optional）。不帶時回傳所有支援幣別的匯率；帶入時只回該幣別。範例：`USDT_TRX`、`USDD_TRX`、`USDC_TRX`、`TUSD_TRX` |

**成功響應**

```json
{
    "status": true,
    "data": [
        {
            "name": "USDT_TRX",
            "exchange_rate": "0.24"
        }
    ]
}
```

> `data` 為陣列；未帶 `name` 時列出所有支援幣別。codebase 目前未實作 `crypto_rate` 呼叫。

---

## 6. 回調處理

MajesticPay 使用 HTTP POST 推送回調，代收與代付以 `ref_no` 前綴區分來源（代收為 `order_no`；代付為 `MAJ` 前綴流水號）。

### 6.1 代收回調

**MajesticPay 推送方式**：HTTP POST（server-to-server）

**回調欄位**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `status` | string | ✅ | 訂單狀態：`completed`（成功）/ `failed`（失敗） |
| `currency` | string | ✅ | 幣別（`MYR`） |
| `amount` | string | ✅ | 實際支付金額 |
| `platform_charge` | string | ✅ | 平台手續費 |
| `ref_no` | string | ✅ | 商戶訂單號（`order.order_no`） |
| `transaction_reference` | string | ✅ | MajesticPay 交易號（需與 `order.third_party_order_no` 比對） |
| `updated_at` | string | ✅ | 交易更新時間（ISO 8601） |
| `is_sandbox` | integer | ✅ | 沙盒旗標：`0` = 正式，`1` = 沙盒 |
| `hash` | string | nullable | HMAC-SHA256 簽名（見第 7 節） |
| `is_repush` | integer | nullable | 補推旗標：`1` = 金額不符需人工確認（DuitNow 通道特有） |
| `repush_amount` | string | nullable | 客戶實際支付金額（`is_repush=1` 時帶入） |
| `telco` | string | nullable | 電信商資訊（Telco Pin 通道）。**PDF V1.3 未列此欄位**，源自 codebase 接收規則 `DepositCallback.php` `'telco' => 'nullable|string'` |

> **is_repush 說明（DuitNow 特有場景）**：當客戶建立金額 100 的訂單，但實際付款金額不符時，系統會設 `is_repush=1`，`repush_amount` 為實際付款金額，需商戶後台人工處理。

**代收成功判定邏輯（三層，依序執行）**

1. **驗簽**：`checkHash($input, $secretKey)` 失敗 → 直接回傳 `Failed`（400）
2. **Sandbox 模式**：`is_sandbox === 1` → 直接標記 `SUCCESS`（繞過 `status` 欄位判斷）
3. **補推金額**：`is_repush !== 0` → 標記 `WAITING_MANUAL_DEPOSIT`（需人工確認）
4. **正式回調**：`status === 'completed'` → `SUCCESS`；其他 → `FAILED`

**額外驗證**：系統比對 `transaction_reference` 與 `order.third_party_order_no` 是否一致，不符則拒絕並回傳 `Failed`（400）。

**回調響應**

| 響應文字 | HTTP 狀態碼 | 說明 |
|---|---|---|
| `OK` | 200 | 成功處理（含已處理的重複回調） |
| `Failed` | 400 | 驗簽失敗、訂單不存在、transaction_reference 不符 |

---

### 6.2 代付回調

**回調欄位**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `status` | string | ✅ | 代付狀態：`completed`（成功）/ `failed`（失敗） |
| `currency` | string | ✅ | 幣別（`MYR`） |
| `amount` | string | ✅ | 代付金額 |
| `platform_charge` | string | ✅ | 平台手續費 |
| `ref_no` | string | ✅ | 代付流水號（`MAJ` 前綴，對應 payout record） |
| `transaction_reference` | string | ✅ | MajesticPay 代付交易號 |
| `updated_at` | string | ✅ | 交易更新時間（ISO 8601） |
| `is_sandbox` | integer | ✅ | 沙盒旗標：`0` = 正式，`1` = 沙盒 |
| `hash` | string | nullable | HMAC-SHA256 簽名 |

**代付成功判定**：`status === 'completed'`

**尋單機制**：系統以 `transaction_reference`（`third_party_transaction_no`）與 `ref_no`（`transaction_no`）雙重匹配 payout record（`findAndLockPayoutRecordByThirdPartyNo`）。成功時另執行金額比對（`validateCallbackAmount`）；失敗時略過金額比對（金額可能不準確）。

**回調成功響應範例**

```json
{
    "status": "completed",
    "currency": "MYR",
    "amount": "500.00",
    "platform_charge": "0.00",
    "ref_no": "{REF_NO}",
    "transaction_reference": "{TRANSACTION_REFERENCE}",
    "updated_at": "2025-08-14T09:15:17.000000Z",
    "is_sandbox": 0,
    "hash": "{HASH}"
}
```

**回調響應**：同代收，`OK`（200）/ `Failed`（400）。

---

## 7. 簽名流程

所有簽名均使用 **HMAC-SHA256**，密鑰為商戶的 `SECRET_KEY`，輸出為**小寫十六進位字串**。

### 7.1 代收/代付發起簽名（`hash`）

**PDF 官方格式**：`(SHA 256) Hash Format: ref_no + amount + currency`

**Codebase 實作**：金額使用 `number_format((float) $amount, 2, '.', '')` 格式化

```php
protected function generateHash(array $params, string $secretKey = ''): string
{
    $stringToSign = $params['ref_no']
        . number_format((float) $params['amount'], 2, '.', '')
        . $params['currency'];

    return hash_hmac('sha256', $stringToSign, $secretKey);
}
```

> 注意：金額使用 `number_format(..., 2, '.', '')` 格式化為兩位小數，確保精度一致。

**範例**：

```
ref_no:   ORD20260301001
amount:   100.00
currency: MYR

拼接字串：ORD20260301001100.00MYR
Hash：    hash_hmac('sha256', 'ORD20260301001100.00MYR', '{SECRET_KEY}')
```

### 7.2 代收回調驗簽（MajesticPay）

**拼接規則**：`ref_no + amount + currency`（直接取回調中的原始字串，不重新格式化金額）

```php
// MajesticPayTrait::checkHash()
protected function checkHash(array $input, string $secretKey = ''): bool
{
    $stringToSign = $input['ref_no'] . $input['amount'] . $input['currency'];
    $calculatedSign = hash_hmac('sha256', $stringToSign, $secretKey);

    return hash_equals($calculatedSign, $input[$this->signField]);
}
```

### 7.3 代收回調驗簽（GMPay，覆寫版本）

**拼接規則**：`transaction_reference + amount + currency`

```php
// GMPayTrait::checkHash()（覆寫 MajesticPayTrait）
protected function checkHash(array $input, string $secretKey = ''): bool
{
    $stringToSign = $input['transaction_reference'] . $input['amount'] . $input['currency'];
    $calculatedSign = hash_hmac('sha256', $stringToSign, $secretKey);

    return hash_equals($calculatedSign, $input['hash']);
}
```

### 簽名算法對照總表

| 情境 | 拼接字串 | 備注 |
|---|---|---|
| 代收發起 | `ref_no + number_format(amount,2) + currency` | 金額格式化 |
| 代付發起 | `ref_no + number_format(amount,2) + currency` | 金額格式化 |
| 重推代收 `repush_deposit`（V1.10） | `ref_no + amount + currency` | amount 恰兩位小數 |
| 取重推 URL `repush_request`（V1.10） | `ref_no + amount + currency` | amount 恰兩位小數 |
| 代收回調驗簽（MajesticPay） | `ref_no + amount + currency` | 直接取原始字串 |
| 代收回調驗簽（GMPay） | `transaction_reference + amount + currency` | 直接取原始字串 |
| 代付回調驗簽（MajesticPay） | `ref_no + amount + currency` | 直接取原始字串 |
| 代付回調驗簽（GMPay） | `transaction_reference + amount + currency` | GMPay 代收/代付回調一致（`GMPayTrait::checkHash` 全域覆寫） |

> 發起簽名與回調驗簽的 `amount` 處理不同：發起時用 `number_format(..., 2)` 格式化，驗簽時直接取回調中的原始字串。

---

## 8. 銀行代碼映射

### 8.1 代收 — sender_bank（Online Banking P2P，payment_method_id = 3）

以下為 PDF V1.10 官方銀行代碼表，`sender_bank` 為 Optional 欄位（不帶時顯示選銀行頁面）：

| bank_code | 銀行名稱 |
|---|---|
| `HLB` | HONG LEONG BANK |
| `PBB` | PUBLIC BANK |
| `CIMB` | CIMB BANK |
| `MBB` | MAYBANK |
| `RHB` | RHB BANK |
| `AMMB` | AMBANK |
| `ABMB` | ALLIANCE BANK |
| `BSN` | BANK SIMPANAN NASIONAL |

> **V1.10 異動**：代收銀行碼表新增 `BSN`（Bank Simpanan Nasional），共 8 間。BSN 出現在代收（sender_bank）而**不在**第 8.2 節代付清單中。
> **PDF 銀行碼說明**：代收銀行碼與第 8.2 節代付清單重疊部分代碼完全一致（例如 Public Bank 代收／代付皆為 `PBB`，AmBank 皆為 `AMMB`）。codebase 目前未傳入 `sender_bank` 欄位。

### 8.2 代付 — bank_name（Payout Bank List）

以下為 PDF V1.10 官方代付銀行清單，`bank_name` 欄位帶入對應銀行代碼：

| bank_name | 銀行名稱 |
|---|---|
| `AMMB` | AmBank (AmOnline) |
| `ABMB` | Alliance Bank |
| `CIMB` | CIMB Bank |
| `HLB` | Hong Leong Bank |
| `MBB` | Maybank |
| `PBB` | Public Bank |
| `RHB` | RHB Bank |
| `ABB` | Affin Bank |
| `BIMB` | Bank Islam |
| `BMMB` | Bank Muamalat |
| `HSBC` | HSBC Bank Malaysia |
| `OCBC` | OCBC Bank Malaysia |
| `UOB` | United Overseas Bank (Malaysia) |
| `GRAB` | Grab |
| `TNG` | Touch N Go |

> `bank_name` 由 `convertPayoutBankCode()` 將內部 bank_code 轉換後帶入。
> **V1.10 異動**：代付清單新增電子錢包代付通道 `GRAB`（Grab）、`TNG`（Touch N Go），共 15 項。`BSN` 仍未列入代付清單（僅在代收 sender_bank，見 8.1），以商戶後台實際開通為準。

### 8.3 EWallet / DuitNow QR（payment_method_id = 6）

以下為 codebase 已知的 EWallet 路由代碼，供前端 `sender_bank` 對應（非 PDF 官方內容）：

| 代碼 | 電子錢包/QR 名稱 |
|---|---|
| `DUI` | DuitNow QR |
| `TNG` | Touch 'n Go |
| `BOT` | Boost |
| `GPY` | GrabPay |
| `SPY` | ShopeePay |
| `MAE` | Maybank QR（MAE） |

---

## 9. 錯誤碼清單

以下為 PDF V1.3 官方錯誤訊息清單（`status: false` 時 `message` 欄位的值）：

| 錯誤訊息（message） | 說明 |
|---|---|
| `Validation Error` | 一個或多個必填欄位缺失或格式無效 |
| `System under maintenance` | 系統暫時維護中，請稍後再試 |
| `Invalid credentials` | 提供的 API Key 無效 |
| `Invalid hash` | 簽名格式錯誤，請核查 Hash 算法與參數 |
| `Invalid IP address` | 請求來源 IP 不在商戶白名單內 |
| `Ref no already exists` | 該 ref_no 已在先前交易中使用過 |
| `Invalid payment method` | 所選支付方式在此商戶帳戶未啟用 |
| `No available channel for this payment method` | 無可用的平台或銀行通道處理此支付方式 |
| `Below minimum amount` | 交易金額低於最低限額 |
| `Below maximum amount` | 交易金額超過最高限額 |
| `Invalid Merchant` | 商戶帳戶未啟用或已被停用 |
| `Transaction not found` | 指定的交易號在系統中找不到（查詢 API 使用） |
| `Payout not found` | 指定的代付號在系統中找不到（查詢 API 使用） |

---

## 10. 限額與費率參考

以下為工單記錄的商戶典型值，各商戶實際開通限額以後台設定為準。

### MYR

| 類型 | 費率 | 最低 | 最高 | 備註 |
|---|---|---|---|---|
| 代收 P2P（Online Banking） | 1.4% | 1 MYR | 20,000 MYR | 工單 #2717 / #3121 |
| 代收 QR（EWallet/DuitNow） | 1.6% | 1 MYR | 20,000 MYR | 工單 #2717 / #3121 |
| 代付 | 1.0% | 500 MYR | 4,999 MYR | 工單 #2717 |
| 下發（Settlement） | — | 5,000 MYR | 無限制 | 工單 #2717 |

| 類型 | 交易時間 | 下發時間 | 客服時間 |
|---|---|---|---|
| 全部 | 24HR | 24HR | 24HR |

---

## 11. 安全性注意事項

以下為實作中觀察到的安全性設計特點，整合時需留意：

### 11.1 沙盒旗標強制成功（高風險）

代收回調中 `is_sandbox === 1` 時，系統**繞過 `status` 欄位判斷**，直接將訂單標記為 `SUCCESS`：

```php
// DepositCallback.php::determineOrderStatus()
if ($input['is_sandbox'] === 1) {
    return OrderStatusEnum::SUCCESS->value;  // 不驗 status 欄位
}
```

**風險**：若攻擊者可在正式環境偽造 `is_sandbox=1` 的回調並通過簽名驗證，可強制任何訂單成功。部署時需確保：
- 正式環境 `SECRET_KEY` 無外洩（簽名保護為第一道防線）
- 若 MajesticPay 承諾正式環境絕不推送 `is_sandbox=1`，需在商戶後台或白名單 IP 層額外確認

### 11.2 繞過共用框架金額保護

本實作**未使用** `WAITING_MANUAL_DEPOSIT` 狀態作為代收標準流程，直接以 `order->update()` 更新訂單狀態，繞過部分共用框架的金額保護機制。例外：`is_repush !== 0` 時才會進入 `WAITING_MANUAL_DEPOSIT`。

### 11.3 transaction_reference 雙重驗證

代收回調中額外比對 `transaction_reference` 與 `order.third_party_order_no`，提供第二道訂單綁定保護，可防止回調 `ref_no` 被替換的情境。

### 11.4 代付雙重尋單

代付回調以 `transaction_reference`（三方號）+ `ref_no`（流水號）雙重匹配 payout record，降低誤配風險。

### 11.5 hash 欄位為 nullable

回調驗證欄位 `hash` 標記為 `nullable`（`'hash' => 'nullable|string'`）。系統仍執行 `checkHash()` 驗證，但若 MajesticPay 不送 `hash`，`hash_equals()` 比對空字串將失敗並拒絕回調——此為正常保護行為，但需留意三方是否有不帶簽名的場景。

---

## 12. 參考資料

| 資料來源 | 說明 |
|---|---|
| `Majesticpay API Document V1.10.pdf` | 官方 API 文檔（V1.10，本次對齊來源，無公開 URL） |
| `Majesticpay_API_Document_V1.3.pdf` | 前版官方文檔（V1.3，存於 GitLab 工單 #3121） |
| GitLab #3121 | [GFF][VPlus33] MajesticPay 新系統對接（API V1.3 PDF 附件） |
| GitLab #2717 | [GFF][VPLUS33] MajesticPay MYR 代收配置（銀行碼、費率、限額） |
| GitLab #2893 | [GFF][VPlus33][APIs] 支付頁面展示方式（MajesticPay 分類確認） |
| 商戶後台（新） | `https://merchant.majesticpay-asia.online`（需登入） |

### 內部實作路徑

```
app/Services/Payment/Providers/MajesticPay/
├── Driver.php               # 組合代收/代付子類別，掛載到 MAJESTIC config key
├── Deposit.php              # 代收實作（POST /api/create_deposit）
├── DepositCallback.php      # 代收回調處理（含沙盒旗標、is_repush 邏輯）
├── Withdrawal.php           # 代付實作（POST /api/create_payout，MAJ 前綴流水號）
├── WithdrawalCallback.php   # 代付回調處理（雙重尋單：transaction_reference + ref_no）
└── MajesticPayTrait.php     # 共用：HTTP 發送（asForm）、generateHash、checkHash

app/Services/Payment/Providers/GMPay/
├── Driver.php               # 結構同 MajesticPay，掛載到 GMPAY config key
├── Deposit.php              # 繼承 MajesticPay\Deposit，加掛 GMPayTrait
├── DepositCallback.php      # 繼承 MajesticPay\DepositCallback，加掛 GMPayTrait
├── Withdrawal.php           # 繼承 MajesticPay\Withdrawal，加掛 GMPayTrait
├── WithdrawalCallback.php   # 繼承 MajesticPay\WithdrawalCallback，加掛 GMPayTrait
└── GMPayTrait.php           # 覆寫 checkHash()：改用 transaction_reference 拼接
```

---

*文件版本：1.2 / 整理日期：2026-06-01 / 對齊 V1.10：2026-07-09*
*資料來源：官方文檔 `Majesticpay API Document V1.10.pdf`（本次對齊）、前版 `Majesticpay_API_Document_V1.3.pdf`（工單 #3121）、GitLab 工單 #2717 / #2893、Codebase 實作*

## 附錄：V1.3 → V1.10 異動摘要

| 類別 | 異動 |
|---|---|
| 新 endpoint | `POST /api/crypto_rate`（§5.3）、`POST /api/repush_deposit`（§3.2，需 PIN）、`POST /api/repush_request`（§3.3） |
| payment_method_id | `DuitNow` 由 `6` 改為獨立 `7`；新增 `Cryptocurrency = 9` |
| 幣別 | 新增加密幣 `USDT_TRX` / `USDD_TRX` / `USDC_TRX` / `TUSD_TRX`（TRC-20） |
| 金額精度 | `create_deposit` / `create_payout` / repush 的 `amount` 與 `hash` 強調「恰為兩位小數」 |
| 代收回應 | 新增 `qr_payload` 欄位 |
| 代收銀行碼（sender_bank） | 新增 `BSN`（8 間） |
| 代付銀行清單 | 新增電子錢包代付 `GRAB`、`TNG`（15 項） |
| 查詢 API | `check_deposit` / `check_payout` 的 `transaction_reference` 可改傳自家 `ref_no`（Optional） |
| 兩商關係 | GMPay 與 MajesticPay 代收回調機制已分歧，`DepositCallback` 已拆分（工單 #2435，見開頭說明） |
