# BillionPay 廠商規格

> 官方 API 文檔（測試）：https://example.invalid
> 內部實作路徑：`app/Services/Payment/Providers/BillionPay/`

BillionPay 是面向新加坡市場的第三方支付平台，支援代收（Deposit）與代付（Withdrawal），幣別為 **SGD**。採用**兩步驟認證**流程：先呼叫 `/api/login` 取得 Bearer Token，再以 `Authorization: Bearer {token}` Header 作為後續 API 的身份憑據。所有請求使用 **FORM（application/x-www-form-urlencoded）** 格式提交，響應為 JSON。代收回傳 **QR Code Image Path**（`qr` 欄位），不是跳轉 URL。

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [登入認證 API](#3-登入認證-api)
4. [代收 API](#4-代收-api)
5. [代付 API](#5-代付-api)
6. [查詢 API](#6-查詢-api)
7. [回調處理](#7-回調處理)
8. [簽名流程](#8-簽名流程)
9. [銀行代碼映射](#9-銀行代碼映射)
10. [限額與費率參考](#10-限額與費率參考)
11. [參考資料](#11-參考資料)

---

## 1. 環境資訊

| 項目 | 值 |
|---|---|
| 生產環境 API Base URL | `https://example.invalid` |
| 測試環境 API Base URL | `https://example.invalid` |
| 商戶後台（生產） | `https://example.invalid` |
| 商戶後台（測試） | `https://example.invalid/` |
| 官方 API 文檔（測試） | `https://example.invalid` |
| 請求格式 | FORM（`application/x-www-form-urlencoded`） |
| 響應格式 | JSON |
| 認證方式 | Bearer Token（由 `/api/login` 取得） |
| 代收回傳類型 | QR Code Image Path（`DepositResponseType::QR_CODE_IMAGE_PATH`） |

> config key：`BILLIONPAY`；`LOGIN_URL`、`DEPOSIT_URL`、`WITHDRAWAL_URL` 分別對應三個 endpoint，由各子類別依 context 路由。

---

## 2. 支援的支付方式與幣別

### SGD（新加坡元）

| 支付類型 | method 值 | 說明 |
|---|---|---|
| 銀行轉帳（代收） | `card` | QR Code 掃碼入款 |
| 銀行轉帳（代付） | `card` | 直接打到指定銀行帳號 |

> BillionPay 目前僅支援 SGD；幣別固定寫死於代付實作（`'currency' => 'SGD'`）。

---

## 3. 登入認證 API

### `POST /api/login`

所有業務 API 呼叫前，必須先取得 Bearer Token。Token 取得後由 `BillionPayTrait::$token` 保存，後續請求自動帶入 `Authorization: Bearer {token}` Header。

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `username` | ✅ | string | 商戶帳號（`info['USERNAME']`） |
| `password` | ✅ | string | 商戶密碼（`info['PASSWORD']`） |

> 注意：登入請求不帶 `Authorization` Header（`isLoggingIn()` 判斷豁免）。

**成功響應**

```json
{
    "bearer_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
}
```

**成功判定**：響應中 `bearer_token` 非空才視為成功，否則拋出 `ApiException('BillionPay Login failed!!')`。

**失敗響應**

```json
{
    "message": "Invalid credentials"
}
```

---

## 4. 代收 API

### `POST /api/deposit`

**Base URL**：`info['DEPOSIT_URL']`

代收不帶簽名到請求 URL（簽名已包含在 `params` 內的 `signature` 欄位）。

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `amount` | ✅ | string | 代收金額（`(string) $order->amount`） |
| `callback_url` | ✅ | string | 代收回調 URL |
| `currency` | ✅ | string | 幣別（`info['CURRENCY']`，通常為 `SGD`） |
| `customer_id` | ✅ | string | 會員帳號（`member->username`） |
| `full_name` | ✅ | string | 會員帳號（同 `customer_id`） |
| `method` | ✅ | string | 固定值 `card` |
| `transaction_id` | ✅ | string | 商戶訂單號（`order->order_no`） |
| `signature` | ✅ | string | MD5 簽名（見第 8 節） |

**請求範例**

```json
{
    "amount": "100",
    "callback_url": "https://example.invalid/callback/billionpay/deposit",
    "currency": "SGD",
    "customer_id": "player001",
    "full_name": "player001",
    "method": "card",
    "transaction_id": "ORD20260521001",
    "signature": "a3f5b2c1d8e4f0..."
}
```

**成功響應**

```json
{
    "internal_id": "BP_INTERNAL_123456",
    "qr": "https://example.invalid/qr/xxxxxx.png"
}
```

**成功判定**：響應中同時含有 `internal_id` 與 `qr` 欄位才視為成功（`Arr::has($result, ['internal_id', 'qr'])`）。

- `internal_id` → 存入 `deposit_order.third_party_order_no`
- `qr` → 以 `DepositResponseType::QR_CODE_IMAGE_PATH` 類型回傳給前端，作為 QR Code 圖片路徑

**失敗響應**

```json
{
    "message": "Invalid signature"
}
```

---

## 5. 代付 API

### `POST /api/withdrawal`

**Base URL**：`info['WITHDRAWAL_URL']`

代付訂單號以 `Common::genTransactionNo('Billion')` 生成（前綴 `Billion`）。

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `transaction_id` | ✅ | string | 商戶代付流水號（`Common::genTransactionNo('Billion')`） |
| `callback_url` | ✅ | string | 代付回調 URL（`Common::withdrawalCallbackUrl($agentCashFlow->id)`） |
| `currency` | ✅ | string | 固定值 `SGD` |
| `customer_id` | ✅ | string | 提款人帳號（`withdrawalOrder->owner->username`） |
| `amount` | ✅ | integer/string | 代付金額（⚠️ **不能有小數點**） |
| `method` | ✅ | string | 固定值 `card` |
| `bank` | ✅ | string | 三方銀行代碼（透過 `convertPayoutBankCode()` 從內部 bank_code 轉換） |
| `bank_account_name` | ✅ | string | 收款人姓名（`withdrawalOrder->account_name`） |
| `bank_account_number` | ✅ | string | 收款銀行帳號（`withdrawalOrder->account_no`） |
| `signature` | ✅ | string | MD5 簽名（見第 8 節） |

> ⚠️ `amount` 欄位**不可有小數點**，需傳整數（代碼中直接使用 `$withdrawalOrder->amount`，請確認 DB 值為整數）。

**請求範例**

```json
{
    "transaction_id": "Billion20260521ABCD",
    "callback_url": "https://example.invalid/callback/billionpay/withdrawal",
    "currency": "SGD",
    "customer_id": "player001",
    "amount": "500",
    "method": "card",
    "bank": "DBS",
    "bank_account_name": "John Doe",
    "bank_account_number": "0123456789",
    "signature": "b7e9a2c4d1f5..."
}
```

**成功響應**

```json
{
    "internal_id": "BP_WD_INTERNAL_789"
}
```

**成功判定**：響應中含有 `internal_id` 欄位才視為成功（`Arr::has($result, ['internal_id'])`）。

- `internal_id` → 存入 `withdrawal_order.third_party_order_no`

**失敗響應**

```json
{
    "message": "Insufficient balance"
}
```

---

## 6. 查詢 API

### 帳戶餘額查詢 — `POST /api/balance`

查詢商戶目前帳戶餘額。

**請求參數**：無（Bearer Token 認證即可）

**響應**

```json
{
    "balance": "12345.00"
}
```

### 單筆交易狀態查詢 — `POST /api/transaction_status`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `transaction_id` | ✅ | string | 商戶訂單號 |
| `signature` | ✅ | string | MD5 簽名 |

**狀態值（`status`）**

| status | 說明 |
|---|---|
| `completed` | 交易成功（終態） |
| `pending` | 處理中 |
| `rejected` | 已拒絕（終態） |

### 時間範圍交易查詢 — `POST /api/transaction_statuses`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `start_date` | ✅ | string | 開始時間（格式：`YYYY-MM-DD HH:mm:ss`） |
| `end_date` | ✅ | string | 結束時間（格式：`YYYY-MM-DD HH:mm:ss`） |
| `type` | - | string | 交易類型篩選 |
| `signature` | ✅ | string | MD5 簽名 |

---

## 7. 回調處理

BillionPay 在交易狀態變更後，以 **HTTP POST** 推送到代收/代付發起時帶入的 `callback_url`。

### 7.1 代收回調

**回調欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `transaction_reference_id` | ✅ | string | 商戶訂單號（對應 `transaction_id`，用於查單） |
| `bank_reference_id` | - | string/null | 三方銀行參考號（存為 `third_party_order_no`） |
| `customer_id` | ✅ | string | 會員帳號 |
| `method` | ✅ | string | 支付方式（`card`） |
| `currency` | ✅ | string | 幣別 |
| `amount` | ✅ | string | 訂單金額 |
| `actual_amount` | ✅ | string | **實際入帳金額**（成功時以此更新訂單金額） |
| `fee` | ✅ | string | 手續費 |
| `status` | ✅ | string | 狀態（見下方三態說明） |
| `bank` | ✅ | string | 銀行代碼 |
| `bank_account_name` | ✅ | string | 付款人姓名 |
| `bank_account_number` | ✅ | string | 付款人帳號 |
| `full_name` | ✅ | string | 付款人全名 |
| `signature` | ✅ | string | MD5 簽名（需驗證） |

**代收三態處理邏輯**

| status | 處理動作 |
|---|---|
| `completed` | 以 `actual_amount` 更新訂單為成功；存 `bank_reference_id` 為三方訂單號 |
| `pending` | 不更新訂單狀態，記錄 log，回傳 `true`（保留，等待下次推送） |
| 其他（含 `rejected`） | 更新訂單為失敗 |

> ⚠️ `pending` 狀態屬中間態，系統保留訂單不做最終結算，等待 BillionPay 後續推送 `completed` 或最終失敗狀態。

**回調範例**

```json
{
    "transaction_reference_id": "ORD20260521001",
    "bank_reference_id": "BP_BANK_REF_001",
    "customer_id": "player001",
    "method": "card",
    "currency": "SGD",
    "amount": "100",
    "actual_amount": "100",
    "fee": "2.60",
    "status": "completed",
    "bank": "DBS",
    "bank_account_name": "John Doe",
    "bank_account_number": "0123456789",
    "full_name": "John Doe",
    "signature": "a3f5b2c1d8e4f0..."
}
```

**回調響應**：回傳純文字 `true` 表示已接收（HTTP 200）；`fail` 表示處理失敗。

```
true
```

---

### 7.2 代付回調

**回調欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `transaction_reference_id` | ✅ | string | 商戶代付流水號（`transaction_id`，用於查 payout_record） |
| `bank_reference_id` | - | string/null | 三方銀行參考號 |
| `customer_id` | ✅ | string | 提款人帳號 |
| `currency` | ✅ | string | 幣別 |
| `amount` | ✅ | string | 代付金額（成功時驗證金額匹配） |
| `fee` | ✅ | string | 手續費 |
| `status` | ✅ | string | 狀態（`completed` / 其他） |
| `bank` | ✅ | string | 銀行代碼 |
| `bank_account_name` | ✅ | string | 收款人姓名 |
| `bank_account_number` | ✅ | string | 收款帳號 |
| `receipt_image` | - | string/null | 收據圖片（base64；系統收到後**自動刪除**，不記錄） |
| `receipt_mime_type` | - | string/null | 收據圖片 MIME type |
| `receipt_url` | - | string/null | 收據圖片 URL |
| `signature` | ✅ | string | MD5 簽名（需驗證） |

**代付狀態處理邏輯**

| status | isSuccess | 處理動作 |
|---|---|---|
| `completed` | ✅ | 驗證金額後更新訂單為成功（狀態碼 24） |
| 其他 | ❌ | 更新訂單為失敗（狀態碼 29） |

> ⚠️ 僅成功時才驗證金額是否匹配；失敗時金額可能不準確，跳過金額驗證。

> ⚠️ `receipt_image` 欄位含圖片二進位資料，系統在記錄 callback data 前統一 `unset($input['receipt_image'])` 避免污染 log。

**回調範例**

```json
{
    "transaction_reference_id": "Billion20260521ABCD",
    "bank_reference_id": "BP_BANK_WD_REF_001",
    "customer_id": "player001",
    "currency": "SGD",
    "amount": "500",
    "fee": "8.50",
    "status": "completed",
    "bank": "DBS",
    "bank_account_name": "John Doe",
    "bank_account_number": "0123456789",
    "receipt_image": null,
    "receipt_mime_type": null,
    "receipt_url": "https://example.invalid/receipts/xxx.pdf",
    "signature": "b7e9a2c4d1f5..."
}
```

**回調響應**：同代收，回傳純文字 `true`（成功）或 `fail`（失敗）。

**代付冪等性**：系統透過 `findAndLockPayoutRecord($transactionReferenceId)` 查詢 payout record；若已處理（`isPayoutRecordProcessed()`）則直接回傳成功，不重複更新訂單狀態。

---

## 8. 簽名流程

BillionPay 使用 **MD5** 作為請求與回調的簽名機制，密鑰為 `info['SECRET_KEY']`，簽名欄位名為 `signature`。

### 8.1 生成簽名（發起請求時）

**步驟說明**

1. 排除 `signature` 欄位本身
2. 排除值為 `null`、`''`、`0`、`'0'` 的參數
3. 對剩餘參數鍵名進行 **ASCII 升序排序**（`ksort`）
4. 對排序後的陣列進行 `json_encode`
5. `json_encode` 結果末尾**直接拼接** Secret Key（無分隔符）
6. 對拼接後的字串進行 **MD5 加密**（小寫十六進位）

> 特殊處理：`json_encode` 結果中的 `//` 會被替換為 `\\/\\/`（見 PHP 實作）

**PHP 實作參考**

```php
protected function generateSign(array $params, string $secret): string
{
    // 步驟 1：排除 signature 欄位
    unset($params['signature']);

    // 步驟 2：排除空值、0、null
    $filtered = array_filter($params, fn ($value) => !in_array($value, [null, '', 0, '0'], true));

    // 步驟 3：ASCII 升序排序
    ksort($filtered);

    // 步驟 4-5：json_encode 後拼接 Secret Key（// 替換為 \/\/）
    $signString = str_replace('//', '\\/\\/', json_encode($filtered) . $secret);

    // 步驟 6：MD5 加密
    return md5($signString);
}
```

**範例（代收）**

```
原始參數（排除 signature）：
{
    "amount": "100",
    "callback_url": "https://example.invalid/callback/billionpay/deposit",
    "currency": "SGD",
    "customer_id": "player001",
    "full_name": "player001",
    "method": "card",
    "transaction_id": "ORD20260521001"
}

ASCII 排序後：
amount → callback_url → currency → customer_id → full_name → method → transaction_id

json_encode 結果（示意）：
{"amount":"100","callback_url":"https:\/\/example.invalid\/...","currency":"SGD",...}

拼接 Secret Key：
{json_encode 結果}{SECRET_KEY}

MD5：
md5(拼接字串) = a3f5b2c1d8e4f0...
```

### 8.2 驗證簽名（回調驗證時）

回調接收後，以同一套規則重新計算 `signature`，與請求中的 `signature` 欄位比對：

```php
protected function checkSign(array $params, string $secret): bool
{
    $sign = $params['signature'] ?? '';

    if (empty($secret)) {
        throw new ApiException('Secret key not found in info', 500);
    }

    $expected = $this->generateSign($params, $secret);

    return $expected === $sign;
}
```

- 代收回調驗簽失敗 → 拋出 `ApiException('Signature verification failed')`
- 代付回調驗簽失敗 → 記錄 `Log::error` 後拋出 `ApiException('Signature verification failed')`

### 8.3 簽名注意事項

| 要點 | 說明 |
|---|---|
| 排除欄位 | `signature` 本身不參與計算 |
| 排除空值 | `null`、`''`、`0`、`'0'` 皆排除在外 |
| 排序方式 | 鍵名 ASCII 升序（`ksort`） |
| 序列化 | `json_encode` 後拼接 Secret Key |
| URL 特殊字元 | `//` 替換為 `\/\/` |
| 加密算法 | MD5 小寫十六進位 |

---

## 9. 銀行代碼映射

### SGD — 代付 bank 代碼

代付請求中的 `bank` 欄位需透過 `convertPayoutBankCode()` 方法，從內部 target `bank_code` 轉換為三方 BillionPay 代碼。映射表定義於 `agent_cash_flow.info['available_banks']`。

以下為新加坡常見銀行的三方代碼（以工單及代碼命名慣例推斷，實際以後台設定的 `available_banks` 映射為準）：

| 三方 bank 代碼 | 銀行名稱 |
|---|---|
| `DBS` | DBS Bank |
| `POSB` | POSB（DBS 旗下） |
| `OCBC` | OCBC Bank |
| `UOB` | United Overseas Bank |
| `SCB` | Standard Chartered Bank |
| `HSBC` | HSBC Singapore |
| `CITI` | Citibank Singapore |
| `MBB` | Maybank Singapore |
| `BOC` | Bank of China Singapore |
| `ICBC` | ICBC Singapore |

> ⚠️ 以上三方代碼為參考值，**實際代碼以 BillionPay 後台設定及 `available_banks` 對照表為準**，不可寫死。

---

## 10. 限額與費率參考

以下為工單 #3709（VWSG 正式配置）記錄的數值，各商戶實際費率以後台設定為準。

### SGD

| 類型 | 費率 | 最低 | 最高 |
|---|---|---|---|
| 代收 | 2.6% | 10 SGD | 5,000 SGD |
| 代付 | 1.7% | 50 SGD | 30,000 SGD |

---

## 11. 參考資料

| 資料來源 | 說明 |
|---|---|
| [官方 API 文檔（測試）](https://example.invalid) | BillionPay 完整 API 規格（測試環境） |
| [商戶後台（生產）](https://example.invalid) | 登入查詢交易記錄 |
| [商戶後台（測試）](https://example.invalid/) | 測試環境後台 |
| GitLab #3417 | [GFF][VWSG] BillionPay SGD 代收代付接入（首次）— 已關閉 |
| GitLab #3709 | [GFF][VWSG] BillionPay SGD 代收代付配置（正式商戶費率限額） |
| GitLab #3839 | [GFF][VWSG] BillionPay SGD 代收代付接入（API 更新）— 進行中 |

### 內部實作路徑

```
app/Services/Payment/Providers/BillionPay/
├── Driver.php              # 組合代收/代付子類別，掛載到 BILLIONPAY config key
├── BillionPayTrait.php     # 共用：login()、sendRequest()、generateSign()、checkSign()
├── Deposit.php             # 代收實作（POST /api/deposit，回傳 QR_CODE_IMAGE_PATH）
├── DepositCallback.php     # 代收回調（三態：completed/pending/其他；以 actual_amount 入帳）
├── Withdrawal.php          # 代付實作（POST /api/withdrawal，amount 不可有小數）
└── WithdrawalCallback.php  # 代付回調（success/fail；自動移除 receipt_image 大欄位）
```

---

*文件版本：1.0 / 整理日期：2026-06-01*
*資料來源：官方 API 文檔（https://example.invalid）、GitLab 工單 #3417、#3709、#3839、Codebase 實作*
