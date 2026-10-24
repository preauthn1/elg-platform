# TakaPay（GanyoPay）廠商規格

> **官方 API 文檔**：`GanyoPay_API_V1.6.pdf`（2025-10-13，存於 GitLab #3441）；`TakaPay_API_V1.5.pdf`（舊版，存於 #2748、#2749）
> **官方 API Base URL**：`https://example.invalid`（生產）
> **內部實作路徑**：`app/Services/Payment/Providers/TakaPay/`

TakaPay（舊名），現已更名為 **GanyoPay**（後台/前台顯示名稱於 2026-03 更新，config key `TAKAPAY` 及 codebase 路徑未異動）。面向孟加拉（BDT）市場，支援電子錢包（bKash、Nagad）代收與代付。採用 **Form-encoded（application/x-www-form-urlencoded）** 請求格式，回應為 JSON。簽名使用 **MD5**，無 OAuth/Bearer token 流程。

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [代收 API](#3-代收-api)
4. [代付 API](#4-代付-api)
5. [回調處理](#5-回調處理)
6. [簽名流程](#6-簽名流程)
7. [狀態碼對照（TakaPayStatusCodeEnum）](#7-狀態碼對照takapaystatuscodeenum)
8. [銀行代碼映射](#8-銀行代碼映射)
9. [限額與費率參考](#9-限額與費率參考)
10. [參考資料](#10-參考資料)

---

## 1. 環境資訊

| 項目 | 值 |
|---|---|
| 生產環境 API Base URL | `https://example.invalid` |
| 沙盒環境 API Base URL | `https://example.invalid/fund` |
| 舊版生產 API Base URL（歷史） | `https://example.invalid`（已遷移至 example.invalid） |
| 沙盒商戶後台 | （存於工單 #3441，不在此列） |
| 生產商戶後台 | （存於工單 #3441，不在此列） |
| 請求格式 | Form-encoded（`application/x-www-form-urlencoded`） |
| 響應格式 | JSON |
| 認證方式 | `securityToken`（MD5 簽名，見第 6 節） |

> `API_URL` 設定於 `example.invalid`，由 `TakaPayTrait::sendRequest()` 自動拼接 endpoint path。

---

## 2. 支援的支付方式與幣別

### BDT（孟加拉塔卡）

PDF 定義的 `paymentMethod` 為整數值：

| paymentMethod（整數） | 說明 | paymentCode 對應 |
|---|---|---|
| `1` | Ewallet | Ewallet Code（如 `bkash`、`nagad`） |
| `2` | Ewallet Direct | Ewallet Direct Code（如 `bkash`） |
| `3` | Ewallet Merchant | Ewallet Merchant Code（如 `bkash`）— V1.6 新增（2025-10-13） |

> **PDF vs Codebase 差異**：PDF 規格中 `paymentMethod` 為 Integer（1/2/3）。但 codebase 的 `parsePaymentMethodAndCode()` 從 `order_type.key` 或 `CashFlowBank.provider_bank_code`（格式 `{method}.{code}`）拆解後，`paymentMethod` 部分為字串（如 `ewallet`），傳送時轉型為 `(string)`。目前系統實際傳送的是**字串形式的方法名稱**，而非 PDF 規格的整數值。三方是否接受兩種格式尚待確認（現行環境已驗證可運作）。

**實際使用的 paymentMethod / paymentCode 組合（codebase 實作）**：

| paymentMethod（實際傳送） | paymentCode | 支付方式 |
|---|---|---|
| `ewallet` | `bkash` | bKash（Ewallet 模式） |
| `ewallet` | `nagad` | Nagad（Ewallet 模式） |

> `order_type.key` 與 `CashFlowBank.provider_bank_code` 格式均為 `ewallet.bkash` / `ewallet.nagad`，由 `parsePaymentMethodAndCode()` 拆分成 `paymentMethod` + `paymentCode`。
>
> 代收訂單也可透過 `order.extra_content`（JSON `{"payment_method": <integer>}`）覆寫 `paymentMethod`，此時傳整數值給三方（對應 PDF 規格的 1/2/3）。

---

## 3. 代收 API

### 代收發起 — `POST /deposit`

**描述**：送出代收請求，成功後三方回傳 `redirectUrl`，需導引玩家前往該 URL 完成支付。

**請求參數**

| 參數 | 必填 | 類型（PDF） | 說明 |
|---|---|---|---|
| `merchantCode` | ✅ | String | 商戶唯一代碼（`MERCHANT_CODE`） |
| `username` | ✅ | String | 會員帳號（`member.username`） |
| `ipAddress` | ✅ | String | 玩家 IP 地址 |
| `currencyCode` | ✅ | String | 幣別代碼（如 `BDT`） |
| `amount` | ✅ | Decimal | 代收金額 |
| `reference` | ✅ | String | 商戶訂單號（`order.order_no`，系統內唯一） |
| `transactionTime` | ✅ | Datetime | 請求時間，格式 `yyyy-MM-dd HH:mm:ss`（codebase 使用 UTC：`gmdate()`） |
| `returnUrl` | ✅ | String | 支付完成後跳轉 URL（成功後重導向玩家） |
| `notifyUrl` | ✅ | String | 代收回調 URL（三方主動推送支付結果） |
| `paymentMethod` | ✅ | Integer（PDF）/ String（實際） | 支付方式；PDF 規格為 1/2/3，codebase 實際傳送字串（如 `ewallet`），見第 2 節差異說明 |
| `paymentCode` | ✅ | String | 支付代碼；若 `paymentMethod=1` 填 Ewallet Code；`paymentMethod=2/3` 填 Ewallet Direct/Merchant Code（見 Section 4 of PDF） |
| `securityToken` | ✅ | String | MD5 簽名（見第 6.1 節） |

**請求範例**

```
merchantCode={MERCHANT_CODE}&username=player001&ipAddress=0.0.0.0
&currencyCode=BDT&amount=500.00&reference=ORD20260601001
&transactionTime=2026-06-01+10:00:00&returnUrl=https://example.invalid/return
&notifyUrl=https://example.invalid/callback/takapay/deposit
&paymentMethod=ewallet&paymentCode=bkash&securityToken=[REDACTED]
```

**成功響應**

> **PDF vs Codebase 差異**：PDF V1.6 的 Deposit 成功響應欄位為 `status`、`description`、`redirectUrl`，**未列出 `systemTransactionId`**。但 codebase（`Deposit.php` L104–106）額外處理了 `systemTransactionId` 欄位，若回應中存在則存入 `deposit_orders.third_party_order_no`。三方實際有無回傳此欄位以生產行為為準。

```json
{
    "status": "00000",
    "description": "Success",
    "redirectUrl": "https://example.invalid/checkout?token=[REDACTED]"
}
```

**失敗響應**

```json
{
    "status": "10004",
    "description": "Invalid parameters"
}
```

**成功判定**：`status === '00000'`（`TakaPayStatusCodeEnum::SUCCESS`）。其餘拋出 `ApiException`，訊息取自 `description` 欄位。

- `redirectUrl`：收銀台 URL，導向後由玩家在三方頁面完成支付
- `systemTransactionId`（如有）：三方訂單號，存入 `deposit_orders.third_party_order_no`

---

## 4. 代付 API

### 代付發起 — `POST /withdraw`

**請求參數**

| 參數 | 必填 | 類型（PDF） | 說明 |
|---|---|---|---|
| `merchantCode` | ✅ | String | 商戶代碼 |
| `currencyCode` | ✅ | String | 幣別代碼（如 `BDT`） |
| `amount` | ✅ | String | 代付金額（codebase 格式化為 `%.2f` 兩位小數） |
| `reference` | ✅ | String | 商戶代付流水號（`Common::genTransactionNo('TakaPay')` 生成） |
| `transactionTime` | ✅ | Datetime | 請求時間，格式 `yyyy-MM-dd HH:mm:ss`（UTC） |
| `paymentMethod` | ✅ | String | 支付方式（如 `ewallet`），從 `CashFlowBank.provider_bank_code` 拆解 |
| `paymentCode` | ✅ | String | 支付代碼（如 `bkash`、`nagad`），從 `CashFlowBank.provider_bank_code` 拆解 |
| `recipientName` | ✅ | String | 收款人姓名（`withdrawal_order.account_name`） |
| `recipientAccount` | ✅ | String | 收款帳號（`withdrawal_order.account_no`） |
| `notifyUrl` | ✅ | String | 代付回調 URL |
| `securityToken` | ✅ | String | MD5 簽名（見第 6.2 節） |

> `paymentMethod` / `paymentCode` 由 `CashFlowBank.provider_bank_code`（格式 `{method}.{code}`）透過 `parsePaymentMethodAndCode()` 拆解取得。

**請求範例**

```
merchantCode={MERCHANT_CODE}&currencyCode=BDT&amount=1000.00
&reference=TakaPay20260601XYZ&transactionTime=2026-06-01+10:05:00
&paymentMethod=ewallet&paymentCode=bkash
&recipientName=John+Doe&recipientAccount=01700000000
&notifyUrl=https://example.invalid/callback/takapay/withdrawal
&securityToken=[REDACTED]
```

**成功響應**

```json
{
    "status": "00000",
    "description": "Success"
}
```

**失敗響應**

```json
{
    "status": "11000",
    "description": "Merchant insufficient fund"
}
```

**成功判定**：`status === '00000'`（`TakaPayStatusCodeEnum::SUCCESS`）。其餘走失敗處理流程。

> TakaPay 代付成功響應**不回傳三方訂單號**，`thirdPartyOrderNo` 保持空字串，需等代付回調才能取得 `systemTransactionId`（存入 `payout_records.third_party_transaction_no` 及 `withdrawal_orders.third_party_order_no`）。

---

## 5. 回調處理

TakaPay 以 **POST Form-encoded** 格式推送代收與代付回調至 `notifyUrl`。代收與代付共用相同的欄位結構。

### 5.1 代收回調

**回調欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `status` | ✅ | String | 狀態碼（見第 7 節 Enum） |
| `currencyCode` | ✅ | String | 幣別 |
| `amount` | ✅ | Numeric | 實際支付金額 |
| `reference` | ✅ | String | 商戶訂單號（`order_no`，用於尋單） |
| `transactionTime` | ✅ | String | 支付時間（`yyyy-MM-dd HH:mm:ss`） |
| `systemTransactionId` | ✅ | String | GanyoPay 生成的唯一交易 ID |
| `securityToken` | ✅ | String | MD5 驗簽（見第 6.3 節） |

**成功判定**：`status === '00000'`（`TakaPayStatusCodeEnum::SUCCESS`）

**回調範例**

```json
{
    "status": "00000",
    "currencyCode": "BDT",
    "amount": "500.00",
    "reference": "ORD20260601001",
    "transactionTime": "2026-06-01 10:10:30",
    "systemTransactionId": "TK20260601ABCDEF",
    "securityToken": "{MD5_SIGNATURE}"
}
```

**我方回調響應**

| 情境 | 響應格式（JSON） | 說明 |
|---|---|---|
| 成功處理 | `{"status": "00000"}` | 三方收到後停止重送 |
| 訂單已處理（冪等攔截） | `{"status": "00000"}` | 回傳成功，告知三方停止重試 |
| 處理失敗 / 例外 | `{"status": "12000"}` | 三方可能重試回調 |

---

### 5.2 代付回調

**回調欄位**（與代收相同結構）

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `status` | ✅ | String | 狀態碼（見第 7 節 Enum） |
| `currencyCode` | ✅ | String | 幣別 |
| `amount` | ✅ | Numeric | 代付金額 |
| `reference` | ✅ | String | 商戶代付流水號（`payout_record` 尋單依據） |
| `transactionTime` | ✅ | String | 支付時間（`yyyy-MM-dd HH:mm:ss`） |
| `systemTransactionId` | ✅ | String | GanyoPay 生成的唯一交易 ID（存入 `payout_records.third_party_transaction_no` 及 `withdrawal_orders.third_party_order_no`） |
| `securityToken` | ✅ | String | MD5 驗簽（見第 6.3 節） |

**成功判定**：`status === '00000'`（`TakaPayStatusCodeEnum::SUCCESS`）

> 僅成功狀態下才驗證回調金額是否與訂單一致（`validateCallbackAmount()`）；失敗狀態下跳過金額驗證，因三方失敗時金額可能不準確。

**回調範例**

```json
{
    "status": "00000",
    "currencyCode": "BDT",
    "amount": "1000.00",
    "reference": "TakaPay20260601XYZ",
    "transactionTime": "2026-06-01 10:15:00",
    "systemTransactionId": "TK20260601PQRSTU",
    "securityToken": "{MD5_SIGNATURE}"
}
```

---

### 5.3 ⚠️ 已知問題：代付冪等反向（Idempotent Reverse）

**問題描述**：TakaPay 代付回調存在「已成功訂單再次觸發失敗回調」的情況。

**根因**：系統在判定 payout record 已處理完畢時，目前回傳的是**失敗響應** `{"status": "12000"}`，而非 `{"status": "00000"}`：

```php
// WithdrawalCallback.php（現行行為）
if ($this->isPayoutRecordProcessed($payoutRecord)) {
    DB::rollBack();
    return WithdrawalCallbackResponseData::from([
        'response' => $this->failResponse(),   // ← 回傳 12000（失敗）
        ...
    ]);
}
```

其他金流商（BigPayz 等）在相同情況下回傳**成功響應**以告知三方停止重送。TakaPay 回傳失敗碼可能導致三方持續重試，造成重複回調。

**影響**：已處理成功的代付訂單收到重複回調時，因冪等性防禦（`isPayoutRecordProcessed` 判定）不會被重複處理，但三方可能誤判為未送達而持續重送，增加系統噪音。

**緩解措施**：訂單已處理邏輯（`checkWithdrawalOrderStatus()`）在正常流程下仍可攔截重複回調，不會造成訂單狀態異變。

---

## 6. 簽名流程

TakaPay 使用 **MD5** 計算 `securityToken`，輸出為十六進位字串（小寫或大寫視情境而定）。

### 6.1 代收發起簽名

**PDF 規格（Section 2.1）**：

```
MD5(merchantCode + username + ipAddress + currencyCode + amount + reference +
    transactionTime + returnUrl + notifyUrl + paymentMethod + paymentCode + secretKey)
```

**Codebase 實作**（`Deposit.php`，`generateSecurityToken`，輸出小寫）：

```php
$signFields = [
    $credentials->merchantCode,
    $member->username,
    $clientIP,
    $currencyCode,
    $order->amount,
    $order->order_no,
    $transactionTime,
    $returnUrl,
    $callbackUrl,
    (string) $paymentMethod,
    $paymentCode,
];
$securityToken = [REDACTED]'', $signFields) . $credentials->secretKey);
```

> Codebase 與 PDF 欄位順序**一致**。`TakaPaySignature::generateSecurityToken()` 直接串接所有欄位值後加上 `secretKey` 再 MD5，輸出小寫（`md5()` 預設）。

### 6.2 代付發起簽名

**PDF 規格（Section 2.6）**：

```
MD5(merchantCode + currencyCode + amount + reference + transactionTime +
    paymentMethod + paymentCode + recipientName + recipientAccount + notifyUrl + secretKey)
```

**Codebase 實作**（`Withdrawal.php`，`generateSecurityToken`，輸出小寫）：

```php
$signFields = [
    $credentials->merchantCode,
    $currencyCode,
    sprintf('%.2f', $withdrawalOrder->amount),
    $transactionNo,
    $transactionTime,
    (string) $paymentMethod,
    $paymentCode,
    $withdrawalOrder->account_name,
    $withdrawalOrder->account_no,
    $notifyUrl,
];
$securityToken = [REDACTED]'', $signFields) . $credentials->secretKey);
```

> Codebase 與 PDF 欄位順序**一致**。`amount` 格式化為 `%.2f`（兩位小數）。

### 6.3 回調驗簽（代收 & 代付共用）

**Codebase 實作**（`TakaPaySignature::verifySecurityToken()`）：

固定欄位順序串接，與發起簽名相同算法（`generateSecurityToken`）：

```
MD5(merchantCode + status + currencyCode + amount + reference +
    transactionTime + systemTransactionId + secretKey)
```

```php
// TakaPaySignature::verifySecurityToken()
$signFields = [
    $merchantCode,
    $params['status'] ?? '',
    $params['currencyCode'] ?? '',
    $params['amount'] ?? '',
    $params['reference'] ?? '',
    $params['transactionTime'] ?? '',
    $params['systemTransactionId'] ?? '',
];
$expected = $this->generateSecurityToken($signFields, $secret);
return hash_equals(strtolower($expected), strtolower($token)); // 比對時大小寫不敏感
```

> 注意：`TakaPaySignature` 另有 `generateSign()` 通用方法（`ksort` + `key=value&...` 格式），但**回調驗簽實際使用 `verifySecurityToken()`（固定欄位順序）**，不使用 `generateSign()` 的通用排序方式。請以 `TakaPaySignature.php` 原始碼為準。

### 簽名算法對照總表

| 情境 | 欄位 key | 算法 | 大小寫 |
|---|---|---|---|
| 代收發起 | `securityToken` | `md5(固定欄位順序串接 + secretKey)` | 小寫 |
| 代付發起 | `securityToken` | `md5(固定欄位順序串接 + secretKey)` | 小寫 |
| 代收回調驗簽 | `securityToken` | `md5(merchantCode + status + currencyCode + amount + reference + transactionTime + systemTransactionId + secretKey)` | 比對時大小寫不敏感 |
| 代付回調驗簽 | `securityToken` | 同代收回調驗簽（相同欄位順序） | 比對時大小寫不敏感 |

---

## 7. 狀態碼對照（TakaPayStatusCodeEnum）

來源：`TakaPayStatusCodeEnum.php` + PDF Section 5（Status Code Definitions）。

| status | Enum Case | 說明 | 成功 |
|---|---|---|---|
| `00000` | `SUCCESS` | 成功 | ✅ |
| `10000` | `INVALID_MERCHANT_CODE` | 商戶代碼無效 | ❌ |
| `10001` | `DUPLICATE_MERCHANT_REFERENCE` | 商戶訂單號重複 | ❌ |
| `10002` | `TRANSACTION_NOT_FOUND` | 交易不存在 | ❌ |
| `10003` | `INVALID_IP_ADDRESS` | IP 地址無效 | ❌ |
| `10004` | `INVALID_PARAMETERS` | 參數無效 | ❌ |
| `10005` | `INVALID_MERCHANT_CODE_ALT` | 商戶代碼無效（另一形式） | ❌ |
| `10006` | `INVALID_EWALLET_CODE` | 電子錢包代碼無效 | ❌ |
| `10007` | `INVALID_REQUEST` | 請求無效 | ❌ |
| `10008` | `INVALID_CURRENCY_CODE` | 幣別代碼無效 | ❌ |
| `10009` | `AGENT_NOT_FOUND` | 代理不存在 | ❌ |
| `10010` | `AGENT_WALLET_NOT_FOUND` | 代理錢包不存在 | ❌ |
| `10011` | `INVALID_SECURITY_TOKEN` | 簽名驗證失敗 | ❌ |
| `10012` | `EWALLET_TEMPORARILY_NOT_SUPPORTED` | 電子錢包暫不支援 | ❌ |
| `10013` | `INACTIVE_EWALLET` | 電子錢包未啟用 | ❌ |
| `10014` | `PENDING_TRANSACTION` | 交易處理中 | ❌ |
| `10015` | `FAILED_TRANSACTION` | 交易失敗 | ❌ |
| `10016` | `TRANSACTION_TERMINATED_BY_USER` | 使用者取消交易 | ❌ |
| `10017` | `TRANSACTION_TIMED_OUT` | 交易逾時 | ❌ |
| `10018` | `BLACKLISTED_CUSTOMER` | 客戶已被列入黑名單 | ❌ |
| `10019` | `AMOUNT_OUT_OF_RANGE` | 金額超出限額範圍（V1.2 新增，2024-01-30） | ❌ |
| `11000` | `MERCHANT_INSUFFICIENT_FUND` | 商戶餘額不足（代付） | ❌ |
| `12000` | `FAILED_NOTIFY_CALLBACK` | 回調通知失敗（我方回傳給三方的失敗響應碼） | ❌ |
| `90001` | `EWALLET_SERVICE_OFFLINE` | 電子錢包服務離線 | ❌ |
| `90002` | `SYSTEM_ERROR` | 系統錯誤 | ❌ |
| `90003` | `MISSING_PARAMETERS` | 缺少必要參數 | ❌ |

> 成功判定為二分法：`status === '00000'` 為成功，其餘一律視為失敗。

---

## 8. 銀行代碼映射

### BDT — 代收 paymentCode（電子錢包）

| paymentMethod（傳送值） | paymentCode | 支付方式 | 模式 |
|---|---|---|---|
| `ewallet`（或整數 `1`） | `bkash` | bKash Ewallet | Ewallet |
| `ewallet`（或整數 `1`） | `nagad` | Nagad Ewallet | Ewallet |
| `2`（整數） | `bkash` | bKash Ewallet Direct（V1.4 新增，2024-06-25） | Ewallet Direct |
| `3`（整數） | `bkash` | bKash Ewallet Merchant（V1.6 新增，2025-10-13） | Ewallet Merchant |
| `upay`（或整數 `upay`） | — | Upay（V1.5 新增，2024-08-13） | Ewallet |

> `order_type.key` 與 `CashFlowBank.provider_bank_code` 格式為 `ewallet.bkash` / `ewallet.nagad`，由 `parsePaymentMethodAndCode()` 拆解。Ewallet Direct / Merchant 模式若啟用，`provider_bank_code` 格式可能為 `2.bkash` / `3.bkash`（需依實際三方後台設定確認）。

### BDT — 代付 paymentCode（電子錢包）

| paymentMethod（傳送值） | paymentCode | 支付方式 |
|---|---|---|
| `ewallet` | `bkash` | bKash |
| `ewallet` | `nagad` | Nagad |

> 早期工單（#2748/#2749）另列 `rocket`（Rocket Wallet），但 2026-02 bugfix commit（`71e9bf4`）已從代付選項中移除 Rocket，目前**不支援 Rocket 代付**。

---

## 9. 限額與費率參考

以下為工單記錄的典型值（早期 AllPay / TakaPay 版本，#2748/#2749），各商戶實際限額以後台設定及商戶協議為準。

### BDT

| 類型 | 最低 | 最高 |
|---|---|---|
| 代收 | 200 BDT | 25,000 BDT |
| 代付 | 500 BDT | 30,000 BDT |

> 費率以商戶協議為準；三方後台狀態碼 `10019`（`AMOUNT_OUT_OF_RANGE`）表示金額超出商戶設定的限額範圍。

---

## 10. 參考資料

### 官方文件

| 文件 | 版本 | 日期 | 來源 |
|---|---|---|---|
| `GanyoPay_API_V1.6.pdf` | V1.6 | 2025-10-13 | GitLab #3441（內部附件，存於 `tmp/payment-pdfs/`） |
| `TakaPay_API_V1.5.pdf` | V1.5 | — | GitLab #2748、#2749 |

**API V1.6 Revision History（完整）**：

| 版本日期 | 變更摘要 |
|---|---|
| 2024-01-01 | Initial release |
| 2024-01-08 | Added Section "Important Notes" |
| 2024-01-30 | Added status code 10019 "Amount Out of Range"；修改 Notify callback 響應格式 |
| 2024-03-19 | Added new API endpoint "Get Merchant Balance" |
| 2024-06-25 | Added new payment method "Ewallet Direct" for bkash |
| 2024-08-13 | New type "Upay" supported for ewallet |
| 2025-10-13 | Added new payment method "Ewallet Merchant" for bkash |

### 相關 GitLab 工單

| 工單 | 標題 | 說明 |
|---|---|---|
| #2748 | `[GFF][TP]Takapay BDT孟加拉支付商` | TPower 首次接入（舊 example.invalid API） |
| #2749 | `[GFF][Y2W]Takapay BDT 孟加拉支付商` | Yes2win 首次接入（舊 example.invalid API） |
| #3441 | `[GFF][APIs]TakaPay-BDT代收代付接入` | 遷移至 example.invalid / GanyoPay API V1.6 |
| #3518 | `[GFF][Yes2win]TakaPay-BDT代收代付配置` | Yes2win 正式商戶 GanyoPay 配置 |
| #3519 | `[GFF][TPower]TakaPay-BDT代收代付配置` | TPower 正式商戶 GanyoPay 配置 |

### 內部實作路徑

```
app/Services/Payment/Providers/TakaPay/
├── Driver.php                  # 組合代收/代付子類別，掛載 config key TAKAPAY
├── Deposit.php                 # 代收實作（POST /deposit）
├── DepositCallback.php         # 代收回調處理（securityToken 驗簽）
├── Withdrawal.php              # 代付實作（POST /withdraw）
├── WithdrawalCallback.php      # 代付回調處理（securityToken 驗簽）
├── TakaPayTrait.php            # 共用：sendRequest（Form-encoded）、生成/驗證簽名、parsePaymentMethodAndCode
├── TakaPaySignature.php        # 簽名邏輯類別（generateSecurityToken / generateSign / verifySecurityToken）
├── TakaPayCredentials.php      # 配置 readonly class（merchantCode / secretKey）
└── TakaPayStatusCodeEnum.php   # 狀態碼 Enum（00000 = SUCCESS，12000 = FAILED_NOTIFY_CALLBACK 等）
```

---

*文件版本：1.1 / 整理日期：2026-06-01*
*資料來源：`GanyoPay_API_V1.6.pdf`（存於 GitLab #3441，`tmp/payment-pdfs/`）、GitLab 工單 #2748 / #2749 / #3441 / #3518 / #3519、Codebase 實作（`app/Services/Payment/Providers/TakaPay/`）*
