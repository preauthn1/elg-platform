# VaderPay 廠商規格

> 官方 API 文檔：https://example.invalid/
> 內部實作路徑：`app/Services/Payment/Providers/VaderPay/`

VaderPay 是支援印尼（IDR）、越南（VND）等東南亞市場的第三方支付平台，提供代收（Deposit）與代付（Withdrawal）。代收依通道分三條路徑：**銀行轉帳**（`/Payin/DepositV2`）、**QR 掃碼**（`/BankBot/QRScanDeposit/`）、**電子錢包**（`/Payin/Ewallet`），系統依訂單類型 `orderType.type`（2/3/1）自動路由。所有請求使用 **JSON（application/json）** 格式，驗簽方式為 **HMAC-SHA256**。

---

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [代收 API](#3-代收-api)
4. [代付 API](#4-代付-api)
5. [回調處理](#5-回調處理)
6. [簽名流程](#6-簽名流程)
7. [狀態碼對照（VaderPayStatusCodeEnum）](#7-狀態碼對照vaderpaystatuscodeenum)
8. [銀行代碼映射](#8-銀行代碼映射)
9. [限額與費率參考](#9-限額與費率參考)
10. [參考資料](#10-參考資料)

---

## 1. 環境資訊

| 項目 | 值 |
|---|---|
| 生產環境 API Base URL | `https://example.invalid/` |
| 測試環境 API Base URL | `https://example.invalid/aggregator` |
| 商戶後台（生產） | `https://example.invalid/Home/Index` |
| 商戶後台（測試） | `https://example.invalid/Home/Index` |
| 請求格式 | JSON（`application/json`） |
| 響應格式 | JSON |
| 認證方式 | HMAC-SHA256（`API_KEY` 作為 HMAC secret） |

> 配置 key：`VADERPAY`；config 欄位：`API_URL`、`MERCHANT_CODE`、`API_KEY`

---

## 2. 支援的支付方式與幣別

### IDR（印尼盾）

| 支付類型 | orderType.type | 說明 |
|---|---|---|
| 銀行轉帳（Bank Transfer） | `2` | 走 `/Payin/DepositV2`，需帶 `BankCode` |
| QR 掃碼（QRIS） | `3` | 走 `/BankBot/QRScanDeposit/`，金額**必須為整數** |
| 電子錢包（Dana / OVO） | `1` | 走 `/Payin/Ewallet`，金額**必須為整數** |

> 工單 #2665 費率：銀行轉帳 2.00%+6,000 IDR、QRIS 3.30%、Dana/OVO 4.50%；代付 0.80%+5,000 IDR

### VND（越南盾）

| 支付類型 | orderType.type | 說明 |
|---|---|---|
| 銀行轉帳 | `2` | 走 `/Payin/DepositV2` |

### 其他支援幣別（依官方文件）

Payin V2（銀行轉帳）支援：AUD、HKD、IDR、JPY、MXN、MYR、PHP、SGD、VND  
Payout（代付）支援：AUD、BRL、HKD、KHR、IDR、INR、MYR、PHP、SGD、THB、USD、VND、ZAR

---

## 3. 代收 API

### 3.1 銀行轉帳代收 — `POST /Payin/DepositV2`

**適用時機**：`orderType.type == 2`

**請求參數**

| 參數 | 必填 | 類型 | 長度 | 說明 |
|---|---|---|---|---|
| `MerchantCode` | ✅ | string | 20 | 商戶代碼 |
| `ReturnURL` | ✅ | string | 250 | 支付完成後跳轉 URL |
| `Amount` | ✅ | string | - | 代收金額（格式：`%.2f`，兩位小數） |
| `Currency` | ✅ | string | 3 | ISO-4217 幣別（如 `IDR`、`VND`） |
| `ItemID` | ✅ | string | 50 | 商戶訂單號（`order.order_no`） |
| `ItemDescription` | - | string | 50 | 交易描述（填 `order.order_no`） |
| `PlayerId` | ✅ | string | 50 | 會員帳號（`member.username`） |
| `Hash` | ✅ | string | - | HMAC-SHA256 簽名（見第 6 節） |
| `BankCode` | ✅ | string | - | 銀行代碼（見第 8 節，從 `orderType.key` 取得） |
| `Email` | - | string | - | 會員 Email |
| `Phone` | - | string | - | 會員手機號 |
| `ClientFullName` | - | string | 50 | 付款人姓名（`first_name + last_name`，空時用 `username`） |

**請求範例**

```json
{
    "MerchantCode": "{MERCHANT_CODE}",
    "ReturnURL": "https://example.invalid/return",
    "Amount": "500000.00",
    "Currency": "IDR",
    "ItemID": "ORD20260101001",
    "ItemDescription": "ORD20260101001",
    "PlayerId": "player001",
    "Hash": "{HMAC_SHA256_SIGNATURE}",
    "BankCode": "BCAB",
    "Email": "user@example.invalid",
    "Phone": "081234567890",
    "ClientFullName": "John Doe"
}
```

**成功響應**

```json
{
    "transaction": "TXN123456789",
    "status": 0,
    "redirect_to": "https://example.invalid/pay?token=[REDACTED]",
    "Amount": 500000.00,
    "Currency": "IDR"
}
```

**錯誤響應**

```json
{
    "error_code": 202,
    "error_message": "Invalid Hash"
}
```

**成功判定**：響應中 `redirect_to` 欄位必須存在；若 `error_code` 欄位存在且非 `0`，則視為失敗。成功後 `transaction` 值存入 `order.third_party_order_no`。

---

### 3.2 QR 掃碼代收 — `POST /BankBot/QRScanDeposit/`

**適用時機**：`orderType.type == 3`

> **注意**：金額必須為整數（無小數位），否則拋出 `ApiException`。

**請求參數**（snake_case 格式）

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `merchant_code` | ✅ | string | 商戶代碼 |
| `ref_id` | ✅ | string | 商戶訂單號（`order.order_no`） |
| `player_username` | ✅ | string | 會員帳號（`member.username`） |
| `player_ip` | ✅ | string | 玩家 IP |
| `currency_code` | ✅ | string | 幣別（如 `IDR`） |
| `amount` | ✅ | string | 代收金額（整數，格式 `%.2f`） |
| `client_url` | ✅ | string | 支付完成後跳轉 URL |
| `hash` | ✅ | string | HMAC-SHA256 簽名（見第 6 節） |
| `email` | - | string | 會員 Email |
| `phone_number` | - | string | 會員手機號 |
| `cpf_no` | - | string | CPF 號（BRL 專用） |

**請求範例**

```json
{
    "merchant_code": "{MERCHANT_CODE}",
    "ref_id": "ORD20260101002",
    "player_username": "player001",
    "player_ip": "0.0.0.0",
    "currency_code": "IDR",
    "amount": "500000.00",
    "client_url": "https://example.invalid/return",
    "hash": "{HMAC_SHA256_SIGNATURE}",
    "email": "user@example.invalid",
    "phone_number": "081234567890"
}
```

**成功響應**

```json
{
    "error_code": 0,
    "error_message": "Operation Success",
    "invoice_number": "INV987654321",
    "currency_code": "IDR",
    "amount": 500000.00,
    "redirect_to": "https://example.invalid/qr?id=xxx"
}
```

**成功判定**：`redirect_to` 欄位必須存在；`error_code` 存在且非 `0` 則視為失敗。

---

### 3.3 電子錢包代收 — `POST /Payin/Ewallet`

**適用時機**：`orderType.type == 1`

> **注意**：金額必須為整數（無小數位），否則拋出 `ApiException`。

**請求參數**（snake_case 格式）

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `merchant_code` | ✅ | string | 商戶代碼 |
| `ref_id` | ✅ | string | 商戶訂單號（`order.order_no`） |
| `player_username` | ✅ | string | 會員帳號（`member.username`） |
| `player_ip` | ✅ | string | 玩家 IP |
| `currency_code` | ✅ | string | 幣別 |
| `amount` | ✅ | string | 代收金額（整數，格式 `%.2f`） |
| `account_no` | - | string | 電子錢包帳號（`member.ewallet_account_no`） |
| `ewallet_type` | ✅ | string | 錢包類型代碼（`orderType.key`，如 `DANA`、`OVO`） |
| `client_url` | ✅ | string | 支付完成後跳轉 URL |
| `hash` | ✅ | string | HMAC-SHA256 簽名（與 QR 格式相同，見第 6 節） |
| `email` | - | string | 會員 Email |
| `phone_number` | - | string | 會員手機號 |
| `client_name` | - | string | 帳號名稱（填 `member.username`） |

**請求範例**

```json
{
    "merchant_code": "{MERCHANT_CODE}",
    "ref_id": "ORD20260101003",
    "player_username": "player001",
    "player_ip": "0.0.0.0",
    "currency_code": "IDR",
    "amount": "100000.00",
    "ewallet_type": "DANA",
    "client_url": "https://example.invalid/return",
    "hash": "{HMAC_SHA256_SIGNATURE}",
    "email": "user@example.invalid",
    "phone_number": "081234567890",
    "client_name": "player001"
}
```

**成功響應**（與 QR 掃碼相同格式）

```json
{
    "error_code": 0,
    "error_message": "Operation Success",
    "invoice_number": "INV111222333",
    "currency_code": "IDR",
    "amount": 100000.00,
    "redirect_to": "https://example.invalid/ewallet?id=xxx"
}
```

---

## 4. 代付 API

### 代付發起 — `POST /Payout/Withdrawal`

**請求參數**（snake_case 格式）

| 參數 | 必填 | 類型 | 長度 | 說明 |
|---|---|---|---|---|
| `merchant_code` | ✅ | string | 20 | 商戶代碼 |
| `ref_id` | ✅ | string | 50 | 商戶代付流水號（`Common::genTransactionNo('VaderPay')` 生成） |
| `player_username` | ✅ | string | 40 | 提款人帳號（`member.username` 或 `withdrawalOrder.account_name`） |
| `player_ip` | ✅ | string | 50 | 提款人 IP（`Common::getIp()`） |
| `currency_code` | ✅ | string | 3 | ISO-4217 幣別 |
| `amount` | ✅ | string | - | 代付金額（格式：`%.2f`，兩位小數） |
| `bank_code` | ✅ | string | - | 收款銀行代碼（由 `cash_flow_banks.provider_bank_code` 取得） |
| `beneficiary_account` | ✅ | string | 50 | 收款銀行帳號 |
| `beneficiary_name` | ✅ | string | 100 | 收款人姓名（必須與銀行帳號一致） |
| `hash` | ✅ | string | - | HMAC-SHA256 簽名（見第 6 節） |
| `email` | - | string | - | 會員 Email |
| `mobile` | - | string | - | 會員手機號 |
| `account_type` | - | integer | - | 帳戶類型：1=Savings、2=Current |
| `ifsc` | - | string | 11 | IFSC 代碼（INR 專用） |
| `address` | - | string | 200 | 收款人地址（選填） |

**請求範例**

```json
{
    "merchant_code": "{MERCHANT_CODE}",
    "ref_id": "VaderPay20260101ABC",
    "player_username": "player001",
    "player_ip": "0.0.0.0",
    "currency_code": "IDR",
    "amount": "500000.00",
    "bank_code": "BCA",
    "beneficiary_account": "1234567890",
    "beneficiary_name": "John Doe",
    "hash": "{HMAC_SHA256_SIGNATURE}",
    "email": "user@example.invalid",
    "mobile": "081234567890"
}
```

**成功響應**

```json
{
    "error_code": 0,
    "error_message": "Operation Success",
    "invoice_number": "INV_PAYOUT_001",
    "currency_code": "IDR",
    "merchant_balance": 9500000.00
}
```

**錯誤響應**

```json
{
    "error_code": 401,
    "error_message": "Insufficient balance"
}
```

**成功判定**：`error_code` 欄位不存在或為 `0`，且無例外拋出。`invoice_number` 存入 `withdrawal_order.third_party_order_no`。

> **銀行代碼查詢**：代付所需 `bank_code` 透過查詢 `cash_flow_banks` 表的 `provider_bank_code` 欄位取得，而非寫死。若找不到配置則直接返回失敗，不送出請求。

### 代付狀態查詢 — `POST /Payout/CheckStatus`

| 參數 | 必填 | 說明 |
|---|---|---|
| `merchant_code` | ✅ | 商戶代碼 |
| `ref_id` | ✅ | 代付流水號 |
| `hash` | ✅ | HMAC-SHA256（`merchant_code + ref_id`） |

---

## 5. 回調處理

VaderPay 回調以 **HTTP POST JSON** 格式推送，欄位使用 **PascalCase**。

**系統回應格式（JSON）**：

```json
// 成功
{ "error_code": 0, "message": "Operation Success" }

// 失敗
{ "error_code": -1, "message": "Operation Failed" }
```

> `notifySuccessText` / `notifyFailText` 屬性在代碼中雖有定義為 `'Operation Success'`／`'Operation Failed'` 字串，但實際響應由 `successResponse()` / `failResponse()` 方法構建為 JSON，屬性本身為死碼（未被讀取）。

---

### 5.1 代收回調

**推送格式**：HTTP POST JSON

**回調欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `InvoiceNo` | ✅ | string | VaderPay 發票號（存入 `order.third_party_order_no`） |
| `ReceiverBank` | - | string | 收款銀行名稱 |
| `ReceiverAccount` | - | string | 收款帳號 |
| `Currency` | ✅ | string | 幣別 |
| `Amount` | ✅ | numeric | 實際付款金額（用於更新訂單） |
| `OriginalAmount` | ✅ | numeric | 原始請求金額 |
| `PaymentDate` | ✅ | string | 支付日期時間（格式：`Y-m-d H:i:s`） |
| `MerchantCode` | ✅ | string | 商戶代碼（必須與本地配置一致） |
| `RefId` | ✅ | string | 商戶訂單號（對應 `order.order_no`，尋單依據） |
| `Status` | ✅ | integer | 訂單狀態（見第 7 節 Enum） |
| `Signature` | ✅ | string | HMAC-SHA256 驗簽（見第 6 節） |
| `StatusMessage` | ✅ | string | 狀態說明文字 |
| `DeclineReason` | - | string | 拒絕原因（失敗時） |

**代收成功判定**：`Status == 1`（`VaderPayStatusCodeEnum::SUCCESS`）

**代收各狀態處理**：
- `Status == 1`（SUCCESS）→ 更新訂單成功，紀錄 `InvoiceNo` 與實際 `Amount`
- `Status == 2`（REJECTED）→ 更新訂單失敗
- `Status == 0`（PENDING）或 `Status == 3`（APPROVED）→ 回傳成功但不更新訂單狀態（保留/待確認）

> 代收**不認可** `APPROVED`（3）為成功，與代付不同；`APPROVED` 在代收中視為待確認狀態，直接回傳成功 response 但訂單保持原狀。

**回調範例**

```json
{
    "InvoiceNo": "INV987654321",
    "ReceiverBank": "BCA",
    "ReceiverAccount": "1234567890",
    "Currency": "IDR",
    "Amount": 500000,
    "OriginalAmount": 500000,
    "PaymentDate": "2026-01-01 10:05:00",
    "MerchantCode": "{MERCHANT_CODE}",
    "RefId": "ORD20260101001",
    "Status": 1,
    "Signature": "{HMAC_SHA256_SIGNATURE}",
    "StatusMessage": "Payment Success",
    "DeclineReason": null
}
```

---

### 5.2 代付回調

**推送格式**：HTTP POST JSON（或 raw body JSON，系統會優先解析 raw body）

**回調欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `InvoiceNo` | ✅ | string | VaderPay 代付發票號（存入 `third_party_transaction_no` 及 `order.third_party_order_no`） |
| `BeneficiaryAccount` | ✅ | string | 收款帳號 |
| `BeneficiaryBankCode` | ✅ | string | 收款銀行代碼 |
| `BeneficiaryName` | ✅ | string | 收款人姓名 |
| `SubmissionDate` | ✅ | string | 提交日期時間（存入 `payout_record.transaction_time`） |
| `Status` | ✅ | integer | 代付狀態（見第 7 節 Enum） |
| `StatusMessage` | ✅ | string | 狀態說明文字 |
| `MerchantCode` | ✅ | string | 商戶代碼（必須與本地配置一致） |
| `RefId` | ✅ | string | 商戶代付流水號（對應 `payout_record`，尋單依據） |
| `Currency` | ✅ | string | 幣別 |
| `Signature` | ✅ | string | HMAC-SHA256 驗簽（見第 6 節） |
| `Amount` | ✅ | numeric | 代付金額（用於金額驗證） |
| `PaymentDate` | ✅ | string | 支付日期（存入 `payout_record.info_2`） |

**代付成功判定**：`Status == 1`（SUCCESS）或 `Status == 3`（APPROVED），兩者均視為成功終態。

**代付各狀態處理**：
- `Status == 0`（PENDING）→ 回傳成功但不更新訂單（等待下次回調）
- `Status == 1`（SUCCESS）→ 代付成功，驗證金額後更新訂單
- `Status == 2`（REJECTED）→ 代付失敗，更新訂單
- `Status == 3`（APPROVED）→ 代付成功（與 SUCCESS 同等），驗證金額後更新訂單

**回調範例**

```json
{
    "InvoiceNo": "INV_PAYOUT_001",
    "BeneficiaryAccount": "1234567890",
    "BeneficiaryBankCode": "BCA",
    "BeneficiaryName": "John Doe",
    "SubmissionDate": "2026-01-01 11:00:00",
    "Status": 1,
    "StatusMessage": "Payment Success",
    "MerchantCode": "{MERCHANT_CODE}",
    "RefId": "VaderPay20260101ABC",
    "Currency": "IDR",
    "Signature": "{HMAC_SHA256_SIGNATURE}",
    "Amount": 500000,
    "PaymentDate": "2026-01-01 11:05:00"
}
```

**代付冪等性**：系統透過 `findAndLockPayoutRecord(RefId)` 查詢 payout record，若已處理則直接回傳成功，不重複更新訂單狀態。

---

### 代收 vs 代付狀態判定差異對照

| Status 值 | Enum | 代收判定 | 代付判定 |
|---|---|---|---|
| `0` | `PENDING` | 保留（不更新訂單） | 保留（等待回調） |
| `1` | `SUCCESS` | **成功** | **成功** |
| `2` | `REJECTED` | 失敗 | 失敗 |
| `3` | `APPROVED` | 保留（不視為成功，不更新） | **成功**（與 SUCCESS 同等） |

---

## 6. 簽名流程

所有簽名均使用 **HMAC-SHA256**，密鑰為商戶的 `API_KEY`，輸出為**小寫十六進位字串**。  
回調驗簽比對時使用 `hash_equals()` 進行常數時間比對（大小寫不敏感），防止時序攻擊。

### 6.1 銀行轉帳代收發起簽名（Hash）

**拼接規則**：`MerchantCode + ItemID + Currency + Amount`

```php
$payload = $merchantCode . $itemId . $currency . $amount;
$hash = hash_hmac('sha256', $payload, $apiKey);
```

**範例**：

```
MerchantCode: {MERCHANT_CODE}
ItemID:       ORD20260101001
Currency:     IDR
Amount:       500000.00

拼接：{MERCHANT_CODE}ORD20260101001IDR500000.00
Hash：hash_hmac('sha256', 拼接字串, {API_KEY})
```

### 6.2 QR 掃碼 / 電子錢包代收發起簽名（hash）

**拼接規則**：`merchant_code + ref_id + player_username + player_ip + currency_code + amount + client_url`

```php
$payload = $merchantCode . $refId . $playerUsername . $playerIp
         . $currencyCode . $amount . $clientUrl;
$hash = hash_hmac('sha256', $payload, $apiKey);
```

### 6.3 代付發起簽名（hash）

**拼接規則**：`merchant_code + ref_id + player_username + player_ip + currency_code + amount + bank_code + beneficiary_account + beneficiary_name`

```php
$payload = $merchantCode . $refId . $playerUsername . $playerIp
         . $currencyCode . $amount . $bankCode
         . $beneficiaryAccount . $beneficiaryName;
$hash = hash_hmac('sha256', $payload, $apiKey);
```

### 6.4 代收回調驗簽（Signature）

**拼接規則**：`InvoiceNo + ReceiverBank + ReceiverAccount + Currency + Amount + PaymentDate`

```php
$payload = $invoiceNo . $receiverBank . $receiverAccount
         . $currency . $amount . $paymentDate;
$expected = hash_hmac('sha256', $payload, $apiKey);
// Amount 正規化為兩位小數：bcadd((string)$amount, '0', 2)
return hash_equals(strtolower($expected), strtolower($input['Signature']));
```

> `Amount` 在拼接前透過 `bcadd((string)$amount, '0', 2)` 正規化為兩位小數字串。

### 6.5 代付回調驗簽（Signature）

**拼接規則**：`InvoiceNo + Status + Currency + Amount + PaymentDate`

```php
$payload = $invoiceNo . $status . $currency . $amount . $paymentDate;
$expected = hash_hmac('sha256', $payload, $apiKey);
// Amount 同樣正規化為兩位小數
return hash_equals(strtolower($expected), strtolower($input['Signature']));
```

> 代付回調的 raw body 與 Laravel validated input 可能因型別轉換導致 `Amount`、`Status` 等欄位值不同，系統會優先使用 raw JSON body 中的原始字串值來計算驗簽，以確保驗簽準確。

### 6.6 狀態查詢簽名（hash）

**拼接規則**：`merchant_code + ref_id`

```php
$payload = $merchantCode . $refId;
$hash = hash_hmac('sha256', $payload, $apiKey);
```

### 簽名算法總覽

| 情境 | 參數 Key | 拼接字串 |
|---|---|---|
| 銀行轉帳代收發起 | `Hash` | `MerchantCode + ItemID + Currency + Amount` |
| QR / 電子錢包代收發起 | `hash` | `merchant_code + ref_id + player_username + player_ip + currency_code + amount + client_url` |
| 代付發起 | `hash` | `merchant_code + ref_id + player_username + player_ip + currency_code + amount + bank_code + beneficiary_account + beneficiary_name` |
| 代收回調驗簽 | `Signature` | `InvoiceNo + ReceiverBank + ReceiverAccount + Currency + Amount + PaymentDate` |
| 代付回調驗簽 | `Signature` | `InvoiceNo + Status + Currency + Amount + PaymentDate` |
| 狀態查詢 | `hash` | `merchant_code + ref_id` |

---

## 7. 狀態碼對照（VaderPayStatusCodeEnum）

| 整數值 | Enum 名稱 | 說明 | 代收語意 | 代付語意 |
|---|---|---|---|---|
| `0` | `PENDING` | 待處理 | 保留（不更新） | 保留（等待） |
| `1` | `SUCCESS` | 成功 | **成功終態** | **成功終態** |
| `2` | `REJECTED` | 已拒絕 | 失敗終態 | 失敗終態 |
| `3` | `APPROVED` | 已核准 | 保留（不更新） | **成功終態** |

**代收成功條件**：`Status === 1`（`isPayinSuccess()`）  
**代付成功條件**：`Status === 1` 或 `Status === 3`（`isPayoutSuccess()`）  
**未知狀態碼**：拋出 `ApiException('VaderPay unknown status code: X')`

---

## 8. 銀行代碼映射

### IDR — 代收 BankCode（銀行轉帳 `/Payin/DepositV2`）

工單 #2665 記錄的可用代收銀行代碼：

| BankCode | 銀行名稱 |
|---|---|
| `BCAB` | BCA Bank |
| `BRIB` | Bank Rakyat Indonesia |
| `BNIB` | Bank Negara Indonesia |
| `CIMN` | CIMB Niaga |
| `PMTB` | Permata Bank |
| `DNMB` | Danamon Bank |
| `MDRB` | Mandiri Bank |
| `MBID` | Maybank Indonesia |

官方文件額外列出（完整清單）：`PNBB`、`UOBI`、`OCBC`、`BAGI`、`DBSI`、`SNMB`、`BTNB`、`MEGA`、`BSIB`、`BYBT`、`NOBU`、`SEAB`、`JAGO`、`ALLO`

### IDR — 代付 bank_code

| bank_code | 銀行名稱 |
|---|---|
| `BCA` | BCA Bank |
| `BRI` | Bank Rakyat Indonesia |
| `BNI` | Bank Negara Indonesia |
| `CIMB` | CIMB Niaga |
| `PMTB` | Permata Bank |
| `DNMB` | Danamon Bank |
| `MDRB` | Mandiri Bank |
| `MAYB` | Maybank Indonesia |

### IDR — 電子錢包代收 ewallet_type（`/Payin/Ewallet`）

| ewallet_type | 錢包名稱 |
|---|---|
| `DANA` | Dana |
| `OVO` | OVO |
| `LINKAJA` | LinkAja |
| `SHOPEEPAY` | ShopeePay |

### VND — 代收 BankCode（`/Payin/DepositV2`）

工單 #2642 記錄：

| BankCode | 銀行名稱 |
|---|---|
| `BIDV` | Bank for Investment and Development of Vietnam |
| `EXMB` | Eximbank |
| `ACBB` | Asia Commercial Bank |
| `VCBB` | Vietcombank |
| `VTBB` | VietinBank |

### VND — 代付 bank_code

工單 #2642 記錄：

| bank_code | 銀行名稱 |
|---|---|
| `AGRIBANK` | Agribank |
| `ACB` | Asia Commercial Bank |
| `BIDV` | Bank for Investment and Development of Vietnam |
| `DONGA` | DongA Bank |
| `EXB` | Eximbank |
| `HDB` | Ho Chi Minh City Development JSC Bank |
| `NCB` | National Citizen Commercial JSC Bank |
| `OCEAN` | Ocean Commercial Bank |
| `SCB` | SacomBank |
| `SHB` | Saigon Hanoi Commercial JSC Bank |
| `TECHB` | TechcomBank |
| `TPB` | Tien Phong Commercial JSC Bank |
| `VCB` | VietcomBank |
| `VIETINBANK` | VietinBank |
| `VIB` | Vietnam International Commercial JSC Bank |
| `MSB` | Vietnam Maritime Commercial JSC Bank |

> **注意**：代收與代付的銀行代碼格式不同（代收用 `VCBB`，代付用 `VCB`），請勿混用。

---

## 9. 限額與費率參考

以下為工單記錄值，各商戶實際開通的限額以後台設定為準。

### IDR（AW77 站，工單 #2665）

| 類型 | 費率 | 最低 | 最高 |
|---|---|---|---|
| 代收（銀行轉帳） | 2.00% + 6,000 IDR | 15,000 IDR | 50,000,000 IDR |
| 代收（QRIS） | 3.30% | 10,000 IDR | 10,000,000 IDR |
| 代收（Dana/OVO） | 4.50% | 20,000 IDR | 10,000,000 IDR |
| 代付（銀行轉帳） | 0.80% + 5,000 IDR | 10,000 IDR | 50,000,000 IDR |

### VND（VT88 站，工單 #2642）

| 類型 | 費率 | 最低 | 最高 |
|---|---|---|---|
| 代收 | - | 50,000 VND | 300,000,000 VND |
| 代付 | - | 50,000 VND | 200,000,000 VND |

---

## 10. 參考資料

| 資料來源 | 說明 |
|---|---|
| [官方 API 文檔](https://example.invalid/) | VaderPay 完整 API 規格（含所有幣別、通道） |
| [商戶後台（生產）](https://example.invalid/Home/Index) | 商戶代碼查詢、交易記錄、Callback URL 設定 |
| GitLab #3449 | [GFF][APIs] VaderPay IDR 代收代付接入工單（含測試環境資訊） |
| GitLab #3522 | [GFF][Alfawin77] VaderPay IDR 代收代付配置工單（正式商戶） |
| GitLab #2665 | [GFF][AW77] VaderPay IDR 代收代付工單（含費率/限額） |
| GitLab #2642 | [GFF][VT88] VaderPay VND 代收代付工單（含銀行代碼） |

### 內部實作路徑

```
app/Services/Payment/Providers/VaderPay/
├── Driver.php                    # 組合代收/代付子類別，掛載到 VADERPAY config key
├── Deposit.php                   # 代收路由：依 orderType.type (1/2/3) 分派三條路徑
├── DepositCallback.php           # 代收回調處理（MerchantCode 驗證 + 簽名驗證）
├── Withdrawal.php                # 代付實作（POST /Payout/Withdrawal）
├── WithdrawalCallback.php        # 代付回調處理（含 raw body 解析 + 簽名驗證）
├── VaderPayTrait.php             # 共用：HTTP 請求、各式 Hash 生成/驗證方法、回應格式
├── VaderPaySignature.php         # 純函數式簽名計算（Deposit/Payout/Callback 各式 Hash）
├── VaderPayStatusCodeEnum.php    # 狀態碼 Enum（0/1/2/3，代收/代付判定方法不對稱）
└── VaderPayCredentials.php       # readonly class，封裝 merchantCode + apiKey
```

### 已知實作注意事項

- **代收 `Amount` 格式**：`sprintf('%.2f', (float) $order->amount)` — 使用原生 float 轉換，未走 `Common::numAdd`（規範上屬違規，實際行為等同兩位小數字串）。
- **代付 `Amount` 格式**：同上，`sprintf('%.2f', (float) $withdrawalOrder->amount)`。
- **簽名 `Amount` 正規化**：回調驗簽中的 Amount 走 `bcadd((string)$amount, '0', 2)` 正規化（直接呼叫 `bcadd`，未透過 `Common::`），確保比對精度正確。
- **`notifySuccessText` / `notifyFailText`**：`DepositCallback` 與 `WithdrawalCallback` 中有宣告，但實際響應由 `successResponse()` / `failResponse()` 方法構建為 JSON array，屬性本身為死碼。

---

*文件版本：1.0 / 整理日期：2026-06-01*
*資料來源：官方 API 文檔（https://example.invalid/）、GitLab 工單 #3449、#3522、#2665、#2642、Codebase 實作*
