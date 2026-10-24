# BigPayz 廠商規格

> 官方 API 文檔：https://example.invalid/apidocv1.asp
> 內部實作路徑：`app/Services/Payment/Providers/BigPayz/`

BigPayz 是面向馬來西亞市場的第三方支付平台，支援代收（Deposit）與代付（Withdrawal）。代收提供兩種格式：**Payin V2**（銀行轉帳）與 **DuitNow**（DuitNow QR 掃碼），系統依訂單類型自動路由。所有請求使用 **JSON** 格式，驗證方式為 **HTTP Basic Auth**（API Key 作為帳號，密碼為空）+ **HMAC-SHA256** 簽名。

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [代收 API — Payin V2（銀行轉帳）](#3-代收-api--payin-v2銀行轉帳)
4. [代收 API — DuitNow（QR 掃碼）](#4-代收-api--duitnowqr-掃碼)
5. [代付 API](#5-代付-api)
6. [回調處理](#6-回調處理)
7. [簽名流程](#7-簽名流程)
8. [銀行代碼映射](#8-銀行代碼映射)
9. [錯誤碼對照](#9-錯誤碼對照)
10. [參考資料](#10-參考資料)

---

## 1. 環境資訊

| 項目 | 值 |
|---|---|
| 商戶後台 | `https://example.invalid/` |
| 官方 API 文檔 | `https://example.invalid/apidocv1.asp` |
| Payin V2 API Base URL | `https://example.invalid` |
| Payout API Base URL | `https://example.invalid` |
| DuitNow API Base URL | `https://example.invalid` |
| 請求格式 | JSON（`application/json`） |
| 響應格式 | JSON |
| 認證方式 | HTTP Basic Auth（API Key 為帳號，密碼留空）+ HMAC-SHA256 Hash |

> 配置中三個 URL 分別對應 `PAYIN_API_URL`、`PAYOUT_API_URL`、`DUITNOW_API_URL`，由 `BigPayzConfig` 依 context 路由。

---

## 2. 支援的支付方式與幣別

### MYR（馬來西亞令吉）

| 支付類型 | 代碼 | 說明 |
|---|---|---|
| 銀行轉帳（Payin V2） | 各銀行 BankCode（見第 8 節） | 直連銀行轉帳，訂單類型 key 非 `qr.duitnow` 時走此流程 |
| DuitNow QR 掃碼 | `qr.duitnow` | 訂單類型 key = `qr.duitnow` 時走 DuitNow 流程 |

> 路由邏輯：`Deposit.php` 依 `$request->order->orderType->key` 自動分派：key = `qr.duitnow` → `DuitNowDeposit`，其餘 → `PayinDeposit`。

---

## 3. 代收 API — Payin V2（銀行轉帳）

### 代收發起 — `POST /Payin/DepositV2`

**Base URL**：`PAYIN_API_URL`（如 `https://example.invalid`）

**請求參數**

| 參數 | 必填 | 類型 | 長度 | 說明 |
|---|---|---|---|---|
| `MerchantCode` | ✅ | string | 20 | 商戶代碼 |
| `ItemID` | ✅ | string | 50 | 商戶訂單號（系統內唯一，對應 `order.order_no`） |
| `Amount` | ✅ | string | - | 代收金額 |
| `Currency` | ✅ | string | 3 | ISO-4217 幣別（如 `MYR`） |
| `ReturnURL` | ✅ | string | 250 | 支付成功後跳轉 URL |
| `FailedReturnURL` | ✅ | string | 250 | 支付失敗後跳轉 URL（實作中與 `ReturnURL` 相同） |
| `HTTPPostURL` | ✅ | string | 250 | 代收回調 URL |
| `ItemDescription` | ✅ | string | 50 | 交易描述（格式：`Deposit {order_no}`） |
| `PlayerId` | ✅ | string | 50 | 會員帳號（`member.username`） |
| `Hash` | ✅ | string | - | HMAC-SHA256 簽名（見第 7 節） |
| `BankCode` | - | string | 4 | 指定銀行代碼（見第 8 節），不填則由玩家在收銀台選擇 |
| `SenderVerification` | - | integer | - | 付款人姓名驗證旗標（選填） |
| `ClientFullName` | - | string | 50 | 付款人真實姓名（選填） |

**請求範例**

```json
{
    "MerchantCode": "{MERCHANT_CODE}",
    "ItemID": "ORD20260301001",
    "Amount": "100.00",
    "Currency": "MYR",
    "ReturnURL": "https://example.invalid/return",
    "FailedReturnURL": "https://example.invalid/return",
    "HTTPPostURL": "https://example.invalid/callback/bigpayz/deposit",
    "ItemDescription": "Deposit ORD20260301001",
    "PlayerId": "player001",
    "Hash": "{HMAC_SHA256_SIGNATURE}",
    "BankCode": "MB2U"
}
```

**成功響應**

```json
{
    "status": 0,
    "redirect_to": "https://example.invalid/pay?token=[REDACTED]",
    "transaction": "TXN123456789",
    "token": "abc123token"
}
```

**失敗響應**

```json
{
    "error_code": 102,
    "message": "Invalid hash"
}
```

**成功判定**：`BigPayzStatusCodeEnum::fromResponse($result['status'])` 為 `INITIATED (0)` 或 `ACCEPTED (1)` 才視為受理；其餘拋出 `ApiException`。

> `redirect_to` 可能帶有 `redirectlink=https://...` 前綴，系統透過 `normalizeRedirectUrl()` 去除前綴後使用。

### 代收狀態查詢 — `GET/POST /payin/transinfo`

透過 `token` 查詢 Payin V2 交易狀態。

**請求參數**

| 參數 | 必填 | 說明 |
|---|---|---|
| `token` | ✅ | 代收發起響應中的 `token` |

**狀態碼（`status` 欄位）**

| status | Enum | 說明 |
|---|---|---|
| `0` | `INITIATED` | 已發起，等待付款 |
| `1` | `ACCEPTED` | 成功（終態） |
| `-1` | `REJECTED` | 已拒絕（終態） |
| `-2` | `ERROR` | 錯誤（終態） |
| `-3` | `PENDING` | 處理中 |

### 可用銀行查詢 — `POST /Payin/DepositSenderBank`

查詢目前可用的代收銀行清單（`BankCode` 清單）。

---

## 4. 代收 API — DuitNow（QR 掃碼）

### 代收發起 — `POST /DuitNow/Deposit`

**Base URL**：`DUITNOW_API_URL`（如 `https://example.invalid`）

**請求參數**（snake_case 格式，有別於 Payin V2）

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `merchant_code` | ✅ | string | 商戶代碼 |
| `ref_id` | ✅ | string | 商戶訂單號（`order.order_no`） |
| `player_username` | ✅ | string | 會員帳號（`member.username`） |
| `player_ip` | ✅ | string | 玩家 IP（無法取得時填 `0.0.0.0`） |
| `currency_code` | ✅ | string | ISO-4217 幣別（如 `MYR`） |
| `amount` | ✅ | string | 代收金額 |
| `client_url` | ✅ | string | 付款完成後跳轉 URL（`returnUrl`） |
| `hash` | ✅ | string | HMAC-SHA256 簽名（見第 7 節） |
| `lang` | - | string | 語言（固定帶 `"en"`） |

**請求範例**

```json
{
    "merchant_code": "{MERCHANT_CODE}",
    "ref_id": "ORD20260301001",
    "player_username": "player001",
    "player_ip": "0.0.0.0",
    "currency_code": "MYR",
    "amount": "50.00",
    "client_url": "https://example.invalid/return",
    "hash": "{HMAC_SHA256_SIGNATURE}",
    "lang": "en"
}
```

**成功響應**

```json
{
    "error_code": 0,
    "message": "Operation Success",
    "redirect_to": "https://example.invalid/qr?id=xxx",
    "invoice_number": "INV987654321",
    "token": "duitnowtoken"
}
```

**失敗響應**

```json
{
    "error_code": 201,
    "message": "Duplicate Reference Id"
}
```

**成功判定**：`error_code === 0` 才視為成功，其餘查 `BigPayzPayinErrorCodeEnum` 取得錯誤訊息後拋出。

### DuitNow 狀態查詢 — `POST /DuitNow/CheckStatus`

查詢 DuitNow QR 交易狀態。

---

## 5. 代付 API

### 代付發起 — `POST /Payout/Withdrawal`

**Base URL**：`PAYOUT_API_URL`（如 `https://example.invalid`）

**請求參數**（snake_case 格式）

| 參數 | 必填 | 類型 | 長度 | 說明 |
|---|---|---|---|---|
| `merchant_code` | ✅ | string | 20 | 商戶代碼 |
| `ref_id` | ✅ | string | 50 | 商戶代付流水號（`Common::genTransactionNo('BPZ')` 生成） |
| `player_username` | ✅ | string | 40 | 提款人帳號（`member.username`） |
| `player_ip` | ✅ | string | 50 | 提款人 IP（無法取得時填 `0.0.0.0`） |
| `currency_code` | ✅ | string | 3 | ISO-4217 幣別（如 `MYR`） |
| `amount` | ✅ | string | - | 代付金額（格式 `%.2f`，兩位小數） |
| `bank_code` | ✅ | string | - | 收款銀行代碼（見第 8 節，需透過 `convertPayoutBankCode()` 轉換） |
| `beneficiary_account` | ✅ | string | 50 | 收款銀行帳號 |
| `beneficiary_name` | ✅ | string | 100 | 收款人姓名（需與銀行帳號一致） |
| `hash` | ✅ | string | - | HMAC-SHA256 簽名（見第 7 節） |
| `ifsc` | - | string | 11 | IFSC 代碼（INR 才需要） |
| `account_type` | - | integer | - | 帳戶類型：1=Savings、2=Current |
| `address` | - | string | - | 收款人地址（選填） |
| `email` | - | string | - | 電子郵件（選填） |
| `mobile` | - | string | - | 手機號碼（選填） |
| `beneficiary_verification` | - | integer | - | 姓名驗證旗標（選填） |

**請求範例**

```json
{
    "merchant_code": "{MERCHANT_CODE}",
    "ref_id": "BPZ20260301ABC",
    "player_username": "player001",
    "player_ip": "0.0.0.0",
    "currency_code": "MYR",
    "amount": "200.00",
    "bank_code": "MB2U",
    "beneficiary_account": "1234567890",
    "beneficiary_name": "John Doe",
    "hash": "{HMAC_SHA256_SIGNATURE}"
}
```

**成功響應**

```json
{
    "error_code": 0,
    "message": "Operation Success",
    "invoice_number": "INV_PAYOUT_001"
}
```

**失敗響應**

```json
{
    "error_code": 401,
    "message": "Insufficient balance"
}
```

**成功判定**：`BigPayzErrorCodeEnum::fromValue($result['error_code'])->isSuccess()` 為 `true`（即 `error_code === 0`）。`invoice_number` 會存入 `withdrawal_order.third_party_order_no`。

### 代付餘額查詢 — `POST /Payout/GetBalance`

查詢商戶代付帳戶餘額。

### 代付狀態查詢 — `POST /Payout/CheckStatus`

**請求參數**

| 參數 | 必填 | 說明 |
|---|---|---|
| `merchant_code` | ✅ | 商戶代碼 |
| `ref_id` | ✅ | 原始代付流水號 |
| `hash` | ✅ | HMAC-SHA256（`merchant_code + ref_id`） |

---

## 6. 回調處理

### 6.1 代收回調路由邏輯

系統收到代收回調後，`DepositCallback.php` 依 payload 中是否含 `ItemID` 或 `RefId` 自動分派：

| Payload 特徵 | 路由目標 |
|---|---|
| 含 `ItemID` 鍵 | `PayinDepositCallback`（Payin V2） |
| 含 `RefId` 鍵 | `DuitNowDepositCallback`（DuitNow） |
| 兩者皆無 | 拋出 `ApiException`（格式未知） |

**回調響應格式（JSON）**：

```json
// 成功
{ "error_code": 0, "message": "Operation Success" }

// 失敗
{ "error_code": 1, "message": "Operation Failed" }
```

### 6.2 Payin V2 代收回調

**BigPayz 推送方式**：HTTP POST JSON

**必要欄位**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `ItemID` | string | 商戶訂單號（`order_no`） |
| `transaction` | string | BigPayz 交易號 |
| `status` | integer | 交易狀態（見下方 Enum） |
| `Amount` | string | 實際支付金額 |
| `Currency` | string | 幣別 |
| `signature2` | string | HMAC-SHA256 驗簽（見第 7 節） |
| `created_at` | string | 交易建立時間 |

**代收回調狀態碼（`BigPayzPayinCallbackStatusCodeEnum`）**

| status | Enum | isSuccess | 說明 |
|---|---|---|---|
| `1` | `SUCCESS` | ✅ | 支付成功 |
| `2` | `REJECTED` | ❌ | 支付被拒 |
| `3` | `APPROVED` | ✅ | 已批准（人工審核後成功） |

> `isSuccess()` 回傳 `true` 的 status：**1 和 3**；`tryFrom()` 回傳 `null` 時視為失敗。

**回調範例**

```json
{
    "ItemID": "ORD20260301001",
    "transaction": "TXN123456789",
    "status": 1,
    "Amount": "100.00",
    "Currency": "MYR",
    "signature2": "{HMAC_SHA256_SIGNATURE}",
    "created_at": "2026-03-01 10:00:00"
}
```

### 6.3 DuitNow 代收回調

**必要欄位**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `RefId` | string | 商戶訂單號（`order_no`，對應代收發起的 `ref_id`） |
| `Status` | integer | 交易狀態（同 `BigPayzPayinCallbackStatusCodeEnum`） |
| `Amount` | string | 實際支付金額 |
| `Currency` | string | 幣別 |
| `Signature` | string | HMAC-SHA256 驗簽（見第 7 節） |
| `PaymentDate` | string | 支付日期時間 |
| `InvoiceNo` | string | BigPayz 發票號（存為 `third_party_order_no`） |
| `ReceiverBank` | string | 收款銀行名稱 |
| `ReceiverAccount` | string | 收款帳號 |

**回調範例**

```json
{
    "RefId": "ORD20260301001",
    "Status": 1,
    "Amount": "50.00",
    "Currency": "MYR",
    "Signature": "{HMAC_SHA256_SIGNATURE}",
    "PaymentDate": "2026-03-01 10:05:00",
    "InvoiceNo": "INV987654321",
    "ReceiverBank": "Maybank",
    "ReceiverAccount": "5641234567"
}
```

### 6.4 代付回調

**BigPayz 推送方式**：HTTP POST（PascalCase 欄位）

**必要欄位**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `RefId` | string | 商戶代付流水號（`ref_id`，對應 `payout_record` 尋單依據） |
| `Signature` | string | HMAC-SHA256 驗簽（見第 7 節） |
| `Status` | integer | 代付狀態（見下方 Enum） |
| `Amount` | string | 代付金額 |
| `PaymentDate` | string | 支付日期時間 |
| `InvoiceNo` | string | BigPayz 代付發票號（存為 `third_party_order_no`） |
| `StatusMessage` | string | 狀態說明文字 |
| `Currency` | string | 幣別 |

**代付回調狀態碼**

| Status | 語意 | 判定 | 說明 |
|---|---|---|---|
| `0` | IN_PROCESS | ⏳ 中間態 | 處理中——訂單維持 `PAYMENT_PROCESSING(22)` |
| `1` | SUCCESS | ✅ 成功 | 代付成功（終態） |
| `2` | REJECTED | ❌ 失敗 | 代付被拒（終態） |
| `3` | MANUAL_APPROVE | ✅ 成功 | 人工審核批准（終態，視為成功） |

> 實際判定在 `WithdrawalCallback.php:126`：`resolveWithdrawalOutcome($status, ['1', '3'], ['2'])`——成功碼 `1`／`3`、失敗碼 `2`，**其餘（含 `0` 與未知值）一律落 `INDETERMINATE`，訂單保持 22 不動**（2026-06-02 代付回調三態重構）。

**回調範例**

```json
{
    "RefId": "BPZ20260301ABC",
    "Signature": "{HMAC_SHA256_SIGNATURE}",
    "Status": 1,
    "Amount": "200.00",
    "PaymentDate": "2026-03-01 11:00:00",
    "InvoiceNo": "INV_PAYOUT_001",
    "StatusMessage": "Payment Success",
    "Currency": "MYR"
}
```

**代付冪等性**：系統透過 `findAndLockPayoutRecord(RefId)` 查詢 payout record，若已處理則直接回傳成功，不重複更新訂單狀態。

---

## 7. 簽名流程

所有簽名均使用 **HMAC-SHA256**，密鑰為商戶的 `API_KEY`，輸出為**小寫十六進位字串**。

### 7.1 Payin V2 代收發起簽名（Hash）

**拼接規則**：`MerchantCode + ItemID + Currency + Amount`

```php
protected function generatePayinHash(
    string $merchantCode,
    string $itemId,
    string $currency,
    string $amount,
    string $apiKey
): string {
    $string = $merchantCode . $itemId . $currency . $amount;
    return hash_hmac('sha256', $string, $apiKey);
}
```

**範例**：

```
MerchantCode: {MERCHANT_CODE}
ItemID:       ORD20260301001
Currency:     MYR
Amount:       100.00

拼接：{MERCHANT_CODE}ORD20260301001MYR100.00
Hash：hash_hmac('sha256', 拼接字串, {API_KEY})
```

### 7.2 DuitNow 代收發起簽名（hash）

**拼接規則**：`merchant_code + ref_id + player_username + player_ip + currency_code + amount + client_url`

```php
protected function generateDuitNowHash(
    string $merchantCode,
    string $refId,
    string $playerUsername,
    string $playerIp,
    string $currencyCode,
    string $amount,
    string $clientUrl,
    string $apiKey
): string {
    $string = $merchantCode . $refId . $playerUsername . $playerIp . $currencyCode . $amount . $clientUrl;
    return hash_hmac('sha256', $string, $apiKey);
}
```

### 7.3 Payin V2 代收回調驗簽（signature2）

**拼接規則**：`transaction + status + Currency + Amount + created_at`

```php
protected function verifyPayinCallbackSignatureV2Doc(array $input, BigPayzConfig $config): bool
{
    $received = $input['signature2'];
    $string = (string) $input['transaction']
        . (string) $input['status']
        . (string) $input['Currency']
        . (string) $input['Amount']
        . (string) $input['created_at'];
    $expected = hash_hmac('sha256', $string, $config->apiKey);
    return hash_equals($expected, $received);
}
```

### 7.4 DuitNow 代收回調驗簽（Signature）

**拼接規則**：`InvoiceNo + ReceiverBank + ReceiverAccount + Currency + Amount + PaymentDate`

```php
protected function verifyPayinCallbackSignatureReal(array $input, BigPayzConfig $config): bool
{
    $received = $input['Signature'];
    $string = (string) $input['InvoiceNo']
        . (string) $input['ReceiverBank']
        . (string) $input['ReceiverAccount']
        . (string) $input['Currency']
        . (string) $input['Amount']
        . (string) $input['PaymentDate'];
    $expected = hash_hmac('sha256', $string, $config->apiKey);
    return hash_equals($expected, $received);
}
```

### 7.5 代付發起簽名（hash）

**拼接規則**：`merchant_code + ref_id + player_username + player_ip + currency_code + amount + bank_code + beneficiary_account + beneficiary_name`

```php
protected function generatePayoutHash(
    string $merchantCode,
    string $refId,
    string $playerUsername,
    string $playerIp,
    string $currencyCode,
    string $amount,
    string $bankCode,
    string $beneficiaryAccount,
    string $beneficiaryName,
    string $apiKey
): string {
    $string = $merchantCode . $refId . $playerUsername . $playerIp . $currencyCode
        . $amount . $bankCode . $beneficiaryAccount . $beneficiaryName;
    return hash_hmac('sha256', $string, $apiKey);
}
```

### 7.6 代付回調驗簽（Signature）

**拼接規則**：`InvoiceNo + Status + Currency + Amount + PaymentDate`

```php
protected function verifyPayoutCallbackSignature(array $input, BigPayzConfig $config): bool
{
    $received = $input['Signature'];
    $string = (string) $input['InvoiceNo']
        . (string) $input['Status']
        . (string) $input['Currency']
        . (string) $input['Amount']
        . (string) $input['PaymentDate'];
    $expected = hash_hmac('sha256', $string, $config->apiKey);
    return hash_equals($expected, $received);
}
```

### 簽名算法對照總表

| 情境 | 參數 Key | 拼接字串 |
|---|---|---|
| Payin V2 代收發起 | `Hash` | `MerchantCode + ItemID + Currency + Amount` |
| DuitNow 代收發起 | `hash` | `merchant_code + ref_id + player_username + player_ip + currency_code + amount + client_url` |
| 代付發起 | `hash` | `merchant_code + ref_id + player_username + player_ip + currency_code + amount + bank_code + beneficiary_account + beneficiary_name` |
| Payin V2 代收回調驗簽 | `signature2` | `transaction + status + Currency + Amount + created_at` |
| DuitNow 代收回調驗簽 | `Signature` | `InvoiceNo + ReceiverBank + ReceiverAccount + Currency + Amount + PaymentDate` |
| 代付回調驗簽 | `Signature` | `InvoiceNo + Status + Currency + Amount + PaymentDate` |

---

## 8. 銀行代碼映射

### MYR — 代收 BankCode（Payin V2）

官方文件列出的可用代收銀行代碼（以下為參考值，實際以 `/Payin/DepositSenderBank` API 回傳為準）：

| BankCode | 銀行名稱 |
|---|---|
| `MB2U` | Maybank |
| `CIMB` | CIMB Bank |
| `PUBB` | Public Bank |
| `RHBB` | RHB Bank |
| `HLBB` | Hong Leong Bank |
| `BSNB` | BSN（Bank Simpanan Nasional） |
| `BIMB` | Bank Islam |
| `AMBB` | AmBank |
| `AFBB` | Affin Bank |
| `ABMB` | Alliance Bank |
| `OCBC` | OCBC Bank |
| `BKRM` | Bank Rakyat |

### MYR — 代付 bank_code

| bank_code | 銀行名稱 |
|---|---|
| `MB2U` | Maybank |
| `CIMB` | CIMB Bank |
| `PUBB` | Public Bank |
| `RHBB` | RHB Bank |
| `HLBB` | Hong Leong Bank |
| `BSNB` | Bank Simpanan Nasional（BSN） |
| `BIMB` | Bank Islam |
| `AMBB` | AmBank |
| `AFBB` | Affin Bank |
| `ABMB` | Alliance Bank |
| `OCBC` | OCBC Bank |
| `BKRM` | Bank Rakyat |

> 代付 `bank_code` 需透過 `convertPayoutBankCode()` 方法從內部 bank_code 轉換後使用。

---

## 9. 錯誤碼對照

### 9.1 代收 Payin V2 / DuitNow 錯誤碼（`BigPayzPayinErrorCodeEnum`）

| error_code | 說明 |
|---|---|
| `0` | Operation Success |
| `1` | Invalid parameter(s) |
| `102` | Invalid hash |
| `103` | Invalid IP |
| `104` | Amount less than or equal to zero |
| `105` | Decimal point must be zero |
| `108` | Invalid Merchant provider |
| `201` | Duplicate Reference Id |
| `205` | Minimum deposit limit is x.xx |
| `206` | Bank is unavailable at the moment |
| `207` | Failed to Access Bank |
| `209` | Unavailable at the moment |
| `210` | Merchant's Bank Account Not Available |
| `211` | Maximum deposit limit is x.xx |
| `214` | Bank Is Under Maintenance |
| `215` | We're busy in another transaction. Please try it again later |
| `216` | Your account is blacklisted. Please contact administrator |
| `301` | Failed to Save Transaction |

### 9.2 代付通用錯誤碼（`BigPayzErrorCodeEnum`）

| error_code | 說明 | shouldRetry |
|---|---|---|
| `0` | Operation Success | - |
| `1` | Invalid/Incorrect Transaction | ❌ |
| `2` | Duplicate Transaction | ❌ |
| `3` | Invalid Parameter | ❌ |
| `4` | Transaction Is Process | ❌ |
| `5` | Agent Disable | ❌ |
| `401` | Insufficient balance | ❌ |
| `1001` | Operation Failed（Retry） | ✅ |
| `1002` | System Maintenance（Retry） | ✅ |

### 9.3 回調響應錯誤碼

系統回傳給 BigPayz 的 JSON 格式：

| error_code | message | 說明 |
|---|---|---|
| `0` | `Operation Success` | 成功處理，BigPayz 不再重送 |
| `1` | `Operation Failed` | 處理失敗，可能觸發 BigPayz 重試 |

---

## 10. 參考資料

| 資料來源 | 說明 |
|---|---|
| [官方 API 文檔](https://example.invalid/apidocv1.asp) | BigPayz 完整 API 規格 |
| [商戶後台](https://example.invalid/) | 登入查詢商戶代碼、API Key、交易記錄 |
| GitLab #3600 | [GFF][APIs] BigPayz MYR 代收代付接入工單（含測試商戶資訊） |
| GitLab #3628 | [GFF][VWorld] BigPayz MYR 代收代付正式配置工單 |
| GitLab #3716 | [GFF][VWMY] BigPayz 代付配置補充工單 |

### 內部實作路徑

```
app/Services/Payment/Providers/BigPayz/
├── Driver.php                        # 組合代收/代付子類別，掛載到 BIGPAYZ config key
├── Deposit.php                       # 代收路由：依 orderType.key 分派 Payin/DuitNow
├── PayinDeposit.php                  # Payin V2 代收實作（POST /Payin/DepositV2）
├── DuitNowDeposit.php                # DuitNow 代收實作（POST /DuitNow/Deposit）
├── DepositCallback.php               # 代收回調路由：依 ItemID/RefId 自動分派
├── PayinDepositCallback.php          # Payin V2 回調處理（signature2 驗簽）
├── DuitNowDepositCallback.php        # DuitNow 回調處理（Signature 驗簽）
├── Withdrawal.php                    # 代付實作（POST /Payout/Withdrawal）
├── WithdrawalCallback.php            # 代付回調處理（Signature 驗簽）
├── BigPayzTrait.php                  # 共用：HTTP 請求（Basic Auth）、各式 Hash 生成/驗證方法
├── BigPayzConfig.php                 # 配置 readonly class（PAYIN_API_URL/PAYOUT_API_URL/DUITNOW_API_URL/MERCHANT_CODE/API_KEY）
├── BigPayzStatusCodeEnum.php         # Payin V2 發起響應狀態（0/1/-1/-2/-3）
├── BigPayzPayinCallbackStatusCodeEnum.php    # 代收回調狀態（1=SUCCESS/2=REJECTED/3=APPROVED）
├── BigPayzPayinErrorCodeEnum.php     # 代收 error_code（0/1/102/103/201 等）
└── BigPayzErrorCodeEnum.php          # 代付通用 error_code（0/1/2/3/401/1001/1002 等）
```

---

*文件版本：1.0 / 整理日期：2026-06-01*
*資料來源：官方 API 文檔（`https://example.invalid/apidocv1.asp`）、GitLab 工單 #3600、#3628、#3716、Codebase 實作*
