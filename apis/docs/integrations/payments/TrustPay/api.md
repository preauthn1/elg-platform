# TrustPay 廠商規格

> 官方 API 文檔：`TrustPay_API_Document_V1.0.pdf`（V2，附於 GitLab #3679，需登入存取）
> 內部實作路徑：`app/Services/Payment/Providers/TrustPay/`

TrustPay 是面向新加坡市場的第三方支付平台，支援代收（Deposit）與代付（Withdrawal），幣別為 **SGD**。支援渠道包含 PayNow（含 PayNow Premium）、Online Banking 直連銀行轉帳（DBS、UOB、OCBC 等本地銀行）。系統目前對接的為 **V2 API**（2026-04 升版，V1.x 已停用）。

所有請求採用 **application/x-www-form-urlencoded** 格式，響應為 JSON。簽名方式為 **HMAC-SHA256**。

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [代收 API](#3-代收-api)
4. [代付 API](#4-代付-api)
5. [回調處理](#5-回調處理)
6. [查詢 API](#6-查詢-api)
7. [商戶餘額查詢](#7-商戶餘額查詢)
8. [簽名流程](#8-簽名流程)
9. [銀行代碼映射](#9-銀行代碼映射)
10. [錯誤碼清單](#10-錯誤碼清單)
11. [限額與費率參考](#11-限額與費率參考)
12. [回調 IP 白名單](#12-回調-ip-白名單)
13. [參考資料](#13-參考資料)

---

## 1. 環境資訊

| 項目 | 值 |
|---|---|
| Production Base URL | `https://api.trustpaysg.click` |
| API Base URL（系統配置） | 由 `agent_cash_flows.info.API_URL` 配置（不同商戶可能不同） |
| 商戶後台（V2） | `https://example.invalid/`（排除登入 URL，僅作參考） |
| 請求格式 | `application/x-www-form-urlencoded` |
| 響應格式 | JSON |
| 簽名算法 | HMAC-SHA256 |

> **注意**：2026-04 TrustPay 正式升版至 V2 API，所有商戶在重新上線前必須完成 V2 對接。V2 Production Base URL 為 `https://api.trustpaysg.click`，V1.x 已停用。

---

## 2. 支援的支付方式與幣別

### SGD（新加坡元）

| 支付類型 | `payment_method_id` | 說明 |
|---|---|---|
| Online Banking | `1` | 直連銀行轉帳（DBS、UOB、OCBC 等） |
| PayNow | `2` | 新加坡 PayNow QR 掃碼 |
| PayNow Premium | `3` | PayNow 升級版 |

> PDF 明確列出上述三個 `payment_method_id` 定值。`payment_method_id` 由 `order.orderType.key` 決定，系統直接將訂單類型的 key 值帶入請求參數，不做額外轉換。

---

## 3. 代收 API

### 代收發起 — `POST /api/create_deposit`

**Endpoint**：`POST {base_url}/api/create_deposit`

**請求格式**：`application/x-www-form-urlencoded`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ 必填 | string | 商戶 API Key |
| `ref_no` | ✅ 必填 | string | 商戶訂單號（系統內唯一，對應 `order.order_no`） |
| `amount` | ✅ 必填 | string | 代收金額，**必須帶 2 位小數**（如 `100.00`） |
| `payment_method_id` | ✅ 必填 | string | 支付方式 ID（Online Banking=1, PayNow=2, PayNow Premium=3；由 `orderType.key` 提供） |
| `currency` | ✅ 必填 | string | 幣別代碼（如 `SGD`） |
| `hash` | ✅ 必填 | string | HMAC-SHA256 簽名（見第 8 節；**amount 必須帶 2 位小數**） |
| `return_url` | ✅ 必填 | string | 支付完成後跳轉 URL（玩家端） |
| `callback_url` | ✅ 必填 | string | 代收回調 URL（server-to-server） |
| `player_id` | ⚪ 選填 | string | 玩家唯一 ID |

> **PDF vs 實作差異**：PDF 將 `player_id` 標注為 Optional（選填）。實作（`Deposit.php`）固定帶入 `(string) $params->member->id`，實際上一律傳送。

**請求範例**

```
POST https://api.trustpaysg.click/api/create_deposit
Content-Type: application/x-www-form-urlencoded

api_key=[REDACTED]
```

**成功響應**

```json
{
    "status": true,
    "data": {
        "created_at": "2025-08-21T02:49:22.000000Z",
        "transaction_reference": "{TRANSACTION_REFERENCE}",
        "ref_no": "{REF_NO}",
        "currency": "SGD",
        "payment_method": "Online Banking",
        "amount": "1.00",
        "platform_charge": 0,
        "final_amount": 0,
        "return_url": "{YOUR_RETURN_URL}",
        "callback_url": "{YOUR_CALLBACK_URL}",
        "ip_address": "{YOUR_IP_ADDRESS}",
        "payment_link": "{PAYMENT_LINK_FROM_API}",
        "is_sandbox": true
    }
}
```

**失敗響應**

```json
{
    "status": false,
    "message": "Ref no already existed."
}
```

**成功判定**：`status === true`，否則拋出 `ApiException`。

| 響應欄位 | 說明 |
|---|---|
| `status` | `true` 表示受理成功 |
| `data.transaction_reference` | TrustPay 交易參考號，存為 `order.third_party_order_no` |
| `data.payment_link` | 導向玩家的收銀台 URL |
| `data.platform_charge` | 平台手續費 |
| `data.final_amount` | 最終金額 |
| `data.is_sandbox` | 是否為沙盒環境 |

---

## 4. 代付 API

### 代付發起 — `POST /api/create_payout`

**Endpoint**：`POST {base_url}/api/create_payout`

**請求格式**：`application/x-www-form-urlencoded`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ 必填 | string | 商戶 API Key |
| `ref_no` | ✅ 必填 | string | 商戶代付流水號（`Common::genTransactionNo('TrustPay')` 生成） |
| `amount` | ✅ 必填 | string | 代付金額，**必須帶 2 位小數** |
| `currency` | ✅ 必填 | string | 幣別代碼（如 `SGD`） |
| `hash` | ✅ 必填 | string | HMAC-SHA256 簽名（見第 8 節） |
| `callback_url` | ✅ 必填 | string | 代付回調 URL（server-to-server） |
| `bank_name` | ✅ 必填 | string | 收款銀行代碼（見第 9 節 Payout Bank List） |
| `bank_holder_name` | ✅ 必填 | string | 收款人姓名（需與銀行帳號一致） |
| `bank_account` | ✅ 必填 | string | 收款銀行帳號 |
| `remarks` | ⚪ 選填 | string | 代付備註 |
| `player_id` | ⚪ 選填 | string | 玩家唯一 ID |

> **PDF vs 實作差異（1）**：PDF 將 `player_id` 標注為 Optional（選填）。實作（`Withdrawal.php`）固定帶入 `(string) $withdrawalOrder->owner->id`，實際上一律傳送。
>
> **PDF vs 實作差異（2）**：PDF 有 `remarks` 選填欄位（代付備註），實作中未帶入此欄位。

**請求範例**

```
POST https://api.trustpaysg.click/api/create_payout
Content-Type: application/x-www-form-urlencoded

api_key=[REDACTED]
```

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
        "callback_url": "{YOUR_PAYOUT_CALLBACK_URL}",
        "remarks": null,
        "transaction_reference": "{TRANSACTION_REFERENCE}"
    }
}
```

**失敗響應**

```json
{
    "status": false,
    "message": "Ref no already existed."
}
```

**成功判定**：`status === true` **且** `result.status_id === 0`（兩個條件缺一不可）。

| 響應欄位 | 說明 |
|---|---|
| `status` | `true` 表示請求受理 |
| `result.status_id` | `0` = Pending（成功受理）；非 `0` 視為失敗 |
| `result.status` | 文字狀態（`Pending` / `Completed` / `Failed`） |
| `result.transaction_reference` | TrustPay 代付交易參考號，存為 `payout_record.third_party_order_no` |
| `result.platform_charge` | 平台手續費 |
| `result.final_amount` | 最終金額 |

**代付流水號前綴**：`TrustPay`（由 `Withdrawal.php` 的 `$orderPrefix` 屬性定義）。

**代付狀態碼**（`result.status_id`）

| status_id | 說明 |
|---|---|
| `0` | Pending（處理中） |
| `1` | Completed（完成） |
| `2` | Failed（失敗） |

---

## 5. 回調處理

TrustPay 以 **HTTP POST（application/x-www-form-urlencoded）** 格式推送回調。代收與代付使用相同的欄位結構，以 `ref_no` 對應商戶訂單號 / 代付流水號。

### 5.1 代收回調（Payment Result Callback）

**描述**：代收結果通知，server-to-server POST 推送。

**回調欄位**

| 欄位 | 說明 |
|---|---|
| `status` | 訂單狀態（`completed` = 成功；`failed` = 失敗） |
| `currency` | 幣別（如 `SGD`） |
| `amount` | 實際到帳金額（兩位小數字串，如 `500.00`） |
| `platform_charge` | 平台手續費（如 `0.00`） |
| `ref_no` | 商戶訂單號（用於查單） |
| `transaction_reference` | TrustPay 交易參考號 |
| `updated_at` | 更新時間（UTC，如 `2025-08-14T09:15:17.000000Z`） |
| `is_sandbox` | 是否沙盒（整數 `0`/`1`） |
| `hash` | HMAC-SHA256 驗簽（見第 8 節） |

**狀態判定**

| `status` 值 | 正規化（`Str::lower`） | 說明 |
|---|---|---|
| `completed` | `completed` | 支付成功（終態） |
| `failed` | `failed` | 支付失敗 |

> **成功判定**：`Str::lower($input['status']) === 'completed'`（codebase `DepositCallback.php` 實作）

**回調範例（成功）**

```json
{
    "status": "completed",
    "currency": "SGD",
    "amount": "500.00",
    "platform_charge": "0.00",
    "ref_no": "{REF_NO}",
    "transaction_reference": "{TRANSACTION_REFERENCE}",
    "updated_at": "2025-08-14T09:15:17.000000Z",
    "is_sandbox": 0,
    "hash": "{HASH_FOR_THIS_TRANSACTION}"
}
```

**回調範例（失敗）**

```json
{
    "status": "failed",
    "currency": "SGD",
    "amount": "50.00",
    "platform_charge": "0.00",
    "ref_no": "{REF_NO}",
    "transaction_reference": "{TRANSACTION_REFERENCE}",
    "updated_at": "2025-08-14T09:17:45.000000Z",
    "is_sandbox": 0,
    "hash": "{HASH_FOR_THIS_TRANSACTION}"
}
```

**回調回應**：回傳純文字 `success`（成功）或 `fail`（失敗）（**小寫**）。

```
success
```

### 5.2 代付回調（Payout Result Callback）

**描述**：代付結果通知，server-to-server POST 推送。

**回調欄位**（與代收結構相同）

| 欄位 | 說明 |
|---|---|
| `status` | 代付狀態（`completed` = 成功；`failed` = 失敗） |
| `currency` | 幣別（如 `SGD`） |
| `amount` | 代付金額（兩位小數字串） |
| `platform_charge` | 平台手續費（如 `0.00`） |
| `ref_no` | 商戶代付流水號（`payout_record` 尋單依據） |
| `transaction_reference` | TrustPay 代付交易參考號 |
| `updated_at` | 更新時間（UTC） |
| `is_sandbox` | 是否沙盒（整數 `0`/`1`） |
| `hash` | HMAC-SHA256 驗簽（見第 8 節） |

**狀態判定**：`Str::lower($input['status']) === 'completed'`（codebase `WithdrawalCallback.php` 實作）

**回調回應**：代付成功回傳 `SUCCESS`，失敗回傳 `FAIL`（**大寫**）。

```
SUCCESS
```

> **大小寫差異**：代收回調回應為小寫 `success`/`fail`；代付回調回應為大寫 `SUCCESS`/`FAIL`。兩者分別由 `DepositCallback::$notifySuccessText` 與 `WithdrawalCallback::$notifySuccessText` 定義，不可混用。

**代付冪等性**：
- 透過 `findAndLockPayoutRecord($orderNo)` 以 `lockForUpdate()` 鎖行查詢 payout record。
- 若 `isPayoutRecordProcessed()` 為真則直接回傳，不重複更新訂單狀態。
- 成功時另呼叫 `validateCallbackAmount` 校驗金額一致性。

---

## 6. 查詢 API

> **注意**：以下兩個查詢 API 為 PDF 新增內容，現有實作（`Driver.php`）**尚未接入**，僅作規格記錄備用。

### 6.1 查詢代收狀態 — `POST /api/check_deposit`

**Endpoint**：`POST {base_url}/api/check_deposit`

**描述**：查詢代收交易的當前處理狀態。

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ 必填 | string | 商戶 API Key |
| `transaction_reference` | ✅ 必填 | string | TrustPay 系統交易參考號 |

**狀態碼**

| status_id | 說明 |
|---|---|
| `0` | Pending（處理中） |
| `1` | Completed（完成） |
| `2` | Failed（失敗） |

**成功響應**

```json
{
    "status": true,
    "data": {
        "status_id": 0,
        "status": "pending",
        "currency": "SGD",
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

### 6.2 查詢代付狀態 — `POST /api/check_payout`

**Endpoint**：`POST {base_url}/api/check_payout`

**描述**：查詢代付交易的當前處理狀態。

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ 必填 | string | 商戶 API Key |
| `transaction_reference` | ✅ 必填 | string | TrustPay 系統交易參考號 |

**狀態碼**（同代收）

| status_id | 說明 |
|---|---|
| `0` | Pending（處理中） |
| `1` | Completed（完成） |
| `2` | Failed（失敗） |

**成功響應**

```json
{
    "status": true,
    "data": {
        "status_id": 0,
        "status": "pending",
        "currency": "SGD",
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

---

## 7. 商戶餘額查詢

> **注意**：此 API 為 PDF 新增內容，現有實作**尚未接入**，僅作規格記錄備用。

### 查詢商戶餘額 — `POST /api/check_balance`

**Endpoint**：`POST {base_url}/api/check_balance`

**描述**：查詢商戶帳戶餘額。

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ 必填 | string | 商戶 API Key |
| `currency` | ✅ 必填 | string | 幣別（如 `SGD`） |

**成功響應**

```json
{
    "status": true,
    "data": {
        "balance": "{BALANCE}",
        "currency": "SGD",
        "merchant": "{MERCHANT_NAME}"
    }
}
```

**失敗響應**

```json
{
    "status": false,
    "message": "Invalid merchant."
}
```

---

## 8. 簽名流程

TrustPay 使用 **HMAC-SHA256** 作為簽名機制，密鑰為商戶的 `SECRET_KEY`，輸出為**小寫十六進位字串**。

### 8.1 代收 / 代付發起請求簽名（`hash`）

**拼接規則**：`ref_no + amount + currency`，以 `SECRET_KEY` 作為 HMAC-SHA256 密鑰

```
拼接字串：{ref_no}{amount}{currency}
hash = hash_hmac('sha256', 拼接字串, SECRET_KEY)
```

**範例**：

```
ref_no:   ORD20260401001
amount:   100.00          ← 必須帶 2 位小數
currency: SGD

拼接字串：ORD20260401001100.00SGD
簽名：    hash_hmac('sha256', 'ORD20260401001100.00SGD', '{SECRET_KEY}')
```

**實作**（`TrustPayTrait::generateRequestSignature`）：

```php
$signString = $refNo . $amount . $currency;
return hash_hmac('sha256', $signString, $secretKey);
```

> **Trait PHPDoc 備註問題**：`generateRequestSignature` 的 PHPDoc 寫「商戶密鑰由後台提供，慣例上附加於末段參與雜湊」，此說法有誤導性——密鑰是作為 HMAC 的 key 參數，**不是**拼接到字串末尾。實際邏輯正確，文字說明有歧義。

### 8.2 回調驗簽（`hash`）

代收與代付回調均使用 `transaction_reference`（而非 `ref_no`）作為拼接來源。

**拼接規則**：`transaction_reference + amount + currency`，以 `SECRET_KEY` 作為 HMAC-SHA256 密鑰

```
hash = hash_hmac('sha256', transaction_reference + amount + currency, SECRET_KEY)
```

**實作**（`TrustPayTrait::generateCallbackSignature`）：

```php
$signString = $transactionReference . $amount . $currency;
return hash_hmac('sha256', $signString, $secretKey);
```

> **重要**：請求簽名的第一個拼接欄位是 `ref_no`（商戶自訂單號），回調驗簽的第一個欄位是 `transaction_reference`（TrustPay 回傳的交易參考號）。兩者不同，注意區分。

### 簽名算法對照總表

| 情境 | 參數 Key | 拼接字串 | HMAC 密鑰 |
|---|---|---|---|
| 代收發起 | `hash` | `ref_no + amount + currency` | `SECRET_KEY` |
| 代付發起 | `hash` | `ref_no + amount + currency` | `SECRET_KEY` |
| 代收回調驗簽 | `hash` | `transaction_reference + amount + currency` | `SECRET_KEY` |
| 代付回調驗簽 | `hash` | `transaction_reference + amount + currency` | `SECRET_KEY` |

---

## 9. 銀行代碼映射

### SGD — 代付 `bank_name`（透過 `convertPayoutBankCode()` 轉換）

> **注意**：PDF 第 12 頁的 Payout Bank List 因字元編碼損壞無法讀取，以下清單來源為工單記錄（#2494、#2697、#3442、#3679）。實際可用銀行以商戶後台開通的清單為準。

| bank_name | 銀行 / 通道名稱 |
|---|---|
| `DBS` | DBS Bank |
| `UOB` | UOB Bank |
| `OCBC` | OCBC Bank |
| `SCB` | Standard Chartered Bank |
| `CIMB` | CIMB Bank |
| `MBB` | Maybank |
| `ALLI` | Alliance Bank |
| `CCCC` | Bank of China |
| `CITI` | Citibank |
| `GXBK` | GXBank |
| `HOLB` | Hong Leong Bank |
| `HSBC` | HSBC |
| `PBBBB` | Public Bank |
| `RHBB` | RHB Bank |
| `Dash` | Dash（電子錢包） |
| `LiquidPay` | LiquidPay（電子錢包） |
| `Maribank` | Maribank |
| `PayNow` | PayNow |
| `TrustBank` | Trust Bank |
| `Wise` | Wise |

> `bank_name` 值必須透過 `AbstractMethod::convertPayoutBankCode()` 從系統內部 `bank_code` 轉換後使用。

---

## 10. 錯誤碼清單

以下為 PDF 官方文件記載的錯誤訊息清單（`message` 欄位值）：

| Error Message | 說明 |
|---|---|
| `Validation Error` | 一個或多個必填欄位缺失或值不合法 |
| `System under maintenance` | 系統暫時不可用（維護中），稍後重試 |
| `Invalid credentials` | 提供的 API Key 無效 |
| `Invalid hash` | 請求的 hash 格式不正確，請確認雜湊演算法與參數 |
| `Invalid IP address` | 請求來源 IP 不在商戶白名單內 |
| `Ref no already exists` | 提供的 ref_no 已在先前交易中使用過 |
| `Invalid payment method` | 選擇的支付方式在此商戶帳戶中未啟用 |
| `No available channel for this payment method` | 無可用平台或銀行渠道處理此支付方式 |
| `Below minimum amount` | 交易金額低於最低限額 |
| `Below maximum amount` | 交易金額超過最高限額 |
| `Invalid Merchant` | 商戶帳戶已停用或不存在 |
| `Transaction not found` | 指定的 transaction_reference 在系統中找不到 |
| `Payout not found` | 指定的代付參考號在系統中找不到 |

---

## 11. 限額與費率參考

以下為工單記錄的典型值，各商戶實際開通的限額與費率以後台設定為準。

### SGD

#### V2 商戶（2026-04 起，`example.invalid`）

| 類型 | 費率 | 最低 | 最高 |
|---|---|---|---|
| 代收（Banking） | 3.20% Flat: 0.00 | SGD 100 | SGD 20,000 |
| 代付（Payout） | 2.30% Flat: 0.00 | SGD 100 | 無上限 |
| 結算（Settlement） | 0.00% Flat: SGD 10 | — | — |

#### V1 商戶舊費率參考（`merchant.trustpay.asia`，已升版）

| 類型 | 費率 | 最低 | 最高 |
|---|---|---|---|
| 代收 | 3.40% ~ 3.60% | SGD 50 | SGD 10,000 |
| 代付 | 1.40% ~ 1.50% | SGD 100 | SGD 10,000 |
| 結算 | Flat: SGD 10 | — | — |

> 結算支援幣別：SGD / USDT / MYR；SGD 本地銀行結算 T+0，24/7 可申請；USDT 結算限工作日 12:00–17:00。

---

## 12. 回調 IP 白名單

工單 #3679 記錄的 V2 測試環境白名單 IP：

| 環境 | IP |
|---|---|
| V2 測試環境 | `0.0.0.0` |

> 生產環境回調 IP 以商戶後台配置為準，開通時由 TrustPay 運營團隊提供。請確保伺服器防火牆已放行上述 IP。

---

## 13. 參考資料

| 資料來源 | 說明 |
|---|---|
| 官方文檔 V2 | `TrustPay_API_Document_V1.0.pdf`（V2，附於 GitLab #3679，需登入存取） |
| 官方文檔 V1 | `TrustPay_API_Document-v1.4.pdf`（附於 GitLab #3442 / #3521，已棄用） |
| GitLab #3442 | [GFF][APIs] TrustPay SGD 代收代付接入工單（含 v1.4 PDF 文件） |
| GitLab #3521 | [GFF][TPower] TrustPay SGD 代收代付配置工單 |
| GitLab #3679 | [GFF][TPower] TrustPay SGD 更換 V2 API 工單（含 V1.0 V2 PDF 文件、費率、IP 白名單） |
| GitLab #2697 | [GFF][VWSG] TrustPay SGD 配置工單（含銀行代碼清單、費率） |
| GitLab #2494 | [GFF][TP] Trust Pay 支付商對接工單（最早接入，含銀行代碼清單） |

### 內部實作路徑

```
app/Services/Payment/Providers/TrustPay/
├── Driver.php              # 組合代收/代付子類別
├── Deposit.php             # 代收實作（POST /api/create_deposit）
├── DepositCallback.php     # 代收回調處理（hash 驗簽，回傳純文字 success/fail）
├── Withdrawal.php          # 代付實作（POST /api/create_payout，前綴 'TrustPay'）
├── WithdrawalCallback.php  # 代付回調處理（hash 驗簽，回傳純文字 SUCCESS/FAIL）
└── TrustPayTrait.php       # 共用：sendRequest（form-urlencoded）、generateRequestSignature、generateCallbackSignature、getCurrencyCode
```

---

*文件版本：2.0 / 整理日期：2026-06-01*
*資料來源：官方 PDF（TrustPay_API_Document_V1.0.pdf，V2）、Codebase 實作、GitLab 工單 #2494、#2697、#3442、#3521、#3679*
