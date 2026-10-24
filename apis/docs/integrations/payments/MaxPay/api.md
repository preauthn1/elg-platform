# MaxPay（PayLoh）廠商規格

> 官方 API 文檔：`PAYLOH_API_DOC-v1.9.pdf`（GitLab project 492 uploads；原據 v1.7 撰寫，2026-09-08 已逐節對照 v1.9 實讀更新）
> 內部實作路徑：`app/Services/Payment/Providers/MaxPay/`

> ✅ **v1.7 → v1.9 差異（2026-09-08 逐節比對）**：**唯一實質變動是第 8 節代付銀行清單新增 `ICBC`**（22 → 23 個）。其餘全數一致並已覆核：Base URL、SHA-512 與 hash 拼接格式、五個端點、`payment_method_id` 五種、幣別、代收回調 `status_id` 0–4、代付回調 `status_id` 0–2、代付請求全部欄位與必填標記、代付僅支援 Production。`is_need_receipt` 必填、`callback_url`／`return_url` 在代收標 Optional 兩處與 codebase 的差異在 v1.9 依舊存在（見各節差異說明）。

MaxPay 是由 **PayLoh** 提供的第三方支付平台，支援新加坡（SGD）等多幣別代收（Deposit）與代付（Withdrawal）。API Base URL 為 `https://example.invalid`，所有請求以 **POST JSON** 發送，響應為 JSON。簽名採用 **SHA-512**。

> ⚠️ **安全性注意**：本商的代收與代付**回調均不驗簽**，系統僅依賴 IP 白名單驗證來源合法性。部署前務必確認 IP 白名單已正確配置。

> ⚠️ **代付限制**：代付 API（`/api/create_payout`）**僅支援正式（Production）環境**，測試環境無法使用。

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [代收 API](#3-代收-api)
4. [代付 API](#4-代付-api)
5. [查詢 API](#5-查詢-api)
6. [回調處理](#6-回調處理)
7. [簽名流程](#7-簽名流程)
8. [銀行代碼映射](#8-銀行代碼映射)
9. [錯誤碼說明](#9-錯誤碼說明)
10. [參考資料](#10-參考資料)

---

## 1. 環境資訊

| 項目 | 值 |
|---|---|
| Production Base URL | `https://example.invalid` |
| 請求格式 | JSON（`application/json`） |
| 響應格式 | JSON |
| 簽名算法 | SHA-512（非 HMAC，直接 hash） |
| 回調驗簽 | **無**（僅靠 IP 白名單） |
| 代付環境限制 | **僅支援 Production**，不支援沙箱 |

---

## 2. 支援的支付方式與幣別

### 幣別支援（PDF 記載）

| 幣別代碼 | 國家 |
|---|---|
| `SGD` | 新加坡 |
| `MYR` | 馬來西亞 |
| `THB` | 泰國 |
| `AUD` | 澳洲 |
| 其他 | 依商戶帳號設定 |

### 代收支付方式（`payment_method_id`）

| `payment_method_id` | 支付類型 | 備註 |
|---|---|---|
| `1` | Manual Bank Transfer | 手動銀行轉帳 |
| `2` | Cryptocurrency | 加密貨幣 |
| `3` | Online Banking | 網銀直連 |
| `4` | PayNow QR | 新加坡 PayNow 掃碼（For Singapore） |
| `6` | DuitNow | 馬來西亞 DuitNow（For Malaysia） |

> 代收 `payment_method_id` 直接傳入訂單類型的 `key` 值（如 `"4"` 或 `"3"`）。

### 系統內 OrderType 對應

| 支付類型 | OrderType 名稱 |
|---|---|
| PayNow QR（`4`） | MAXPAY - PayNow QR |
| Online Banking（`3`） | MAXPAY - Online Banking |
| 代付（銀行轉帳） | MAXPAY - W |

---

## 3. 代收 API

### 代收發起 — `POST /api/create_order`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ | string | 商戶 API Key |
| `ref_no` | ✅ | string | 商戶訂單號（對應 `order.order_no`，系統內唯一） |
| `amount` | ✅ | string | 代收金額（傳入 `order->amount` 原始值） |
| `payment_method_id` | ✅ | string | 支付方式 ID（訂單類型 `key` 值，如 `"4"`/`"3"`） |
| `currency` | ✅ | string | 幣別代碼（如 `SGD`） |
| `hash` | ✅ | string | SHA-512 簽名（見第 7 節） |
| `is_need_receipt` | ✅ | string | 是否需要上傳憑證（`0`=否 / `1`=是） |
| `callback_url` | optional | string | 代收回調 URL |
| `return_url` | optional | string | 支付完成後跳轉 URL |
| `customer_name` | optional | string | 客戶名稱 |
| `unit_price` | optional | string | 商品單價 |
| `quantity` | optional | string | 商品數量 |
| `description` | optional | string | 付款描述 |
| `discount_price` | optional | string | 折扣金額 |
| `delivery_fee` | optional | string | 運費 |
| `email` | optional | string | 客戶 Email |
| `contact` | optional | string | 客戶聯絡電話 |
| `delivery_address` | optional | string | 收件地址 |
| `address_city` | optional | string | 城市 |
| `address_state` | optional | string | 州/省 |
| `address_country` | optional | string | 國家 |
| `address_poscode` | optional | string | 郵遞區號 |

> **差異說明**：PDF 將 `is_need_receipt` 標為必填（Required），但 codebase（`Deposit.php`）實作未帶此欄位。建議確認與 PayLoh 的實際需求，必要時補入。

> **差異說明**：PDF 將 `callback_url`、`return_url` 標為 Optional，但 codebase 實作為必傳（代收流程強制帶入）。

**請求範例**

```json
{
    "api_key": "{API_KEY}",
    "ref_no": "ORD20260601001",
    "amount": "100",
    "payment_method_id": "4",
    "currency": "SGD",
    "hash": "{SHA512_SIGNATURE}",
    "is_need_receipt": "0",
    "callback_url": "https://example.invalid/callback/maxpay/deposit",
    "return_url": "https://example.invalid/return"
}
```

**成功響應**

```json
{
    "status": true,
    "data": {
        "created_at": "2024-11-05T04:11:27.000000Z",
        "amount": "1.00",
        "ref_no": "1170",
        "currency": "SGD",
        "payment_method": "Online Banking",
        "customer_name": "",
        "description": "",
        "unit_price": "0.00",
        "quantity": 0,
        "discount_price": "0.00",
        "delivery_fee": "0.00",
        "grand_total": "0.00",
        "return_url": "https://...",
        "callback_url": "https://...",
        "delivery_address": "",
        "address_city": "",
        "address_state": "",
        "address_country": "",
        "address_postcode": "",
        "ip_address": "...",
        "email": "",
        "contact": "",
        "payment_link": "https://example.invalid/{transaction_reference}",
        "tax": "0.00",
        "transaction_reference": "{transaction_reference}",
        "platform_charge": 0,
        "final_receive_amount": "0.00"
    }
}
```

**失敗響應**

```json
{
    "status": false,
    "message": "Invalid hash"
}
```

**成功判定（codebase 行為）**：

- `status === true`（布林值）才視為成功
- `data.payment_link` 為收銀台 URL，導向使用者完成支付（空值則拋出 `ApiException`）
- `data.transaction_reference` 存入 `order.third_party_order_no`

---

## 4. 代付 API

### 代付發起 — `POST /api/create_payout`

> ⚠️ **僅支援 Production 環境。**

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ | string | 商戶 API Key |
| `ref_no` | ✅ | string | 商戶代付流水號（`Common::genTransactionNo('MAX')` 生成，格式 `MAX{timestamp}{random}`） |
| `amount` | ✅ | string | 代付金額（`sprintf('%.2f', amount)`，兩位小數） |
| `currency` | ✅ | string | 幣別代碼（如 `SGD`） |
| `hash` | ✅ | string | SHA-512 簽名（見第 7 節） |
| `bank_name` | ✅ | string | 收款銀行代碼（三方銀行代碼，見第 8 節） |
| `bank_holder_name` | ✅ | string | 收款人姓名（需與銀行帳號一致） |
| `bank_account` | ✅ | string | 收款銀行帳號 |
| `callback_url` | ✅ | string | 代付回調 URL |
| `remarks` | optional | string | 備註 |

> `bank_name` 欄位傳入三方銀行代碼（如 `UOB`、`DBS`、`CIMB`），透過 `available_banks` 配置將內部 `BankCode_ELG` 轉換為 `BankCode_Third`。

**請求範例**

```json
{
    "api_key": "{API_KEY}",
    "ref_no": "MAX202606010001",
    "amount": "500.00",
    "currency": "SGD",
    "hash": "{SHA512_SIGNATURE}",
    "bank_name": "DBS",
    "bank_holder_name": "John Doe",
    "bank_account": "1234567890",
    "callback_url": "https://example.invalid/callback/maxpay/withdrawal"
}
```

**成功響應（PDF 定義）**

> ⚠️ **差異說明**：PDF 顯示代付成功響應的交易參考號在 `result.transaction_reference` 底下；codebase 既有讀取為 `data.transaction_reference`。實際欄位路徑以連線測試為準；定案前 `Withdrawal.php` 採雙路徑容錯讀取（`data.transaction_reference ?? result.transaction_reference`）。

```json
{
    "status": true,
    "result": {
        "status_id": 0,
        "status": "Processing",
        "ref_no": "{ref_no}",
        "currency": "SGD",
        "amount": "2",
        "platform_charge": 0.03,
        "final_amount": 2.03,
        "bank_name": "UOB",
        "bank_holder_name": "TEST",
        "bank_account": "TEST",
        "transaction_reference": "{transaction_reference}",
        "callback_url": "https://...",
        "remarks": "TEST"
    }
}
```

**失敗響應**

```json
{
    "status": false,
    "message": "Insufficient balance"
}
```

**成功判定（codebase 行為）**：

- `status === true` 且 `transaction_reference` 非空（`data.*` 或 `result.*` 雙路徑容錯）才視為受理
- `transaction_reference` 存入 `withdrawal_payout_records.third_party_transaction_no`
- `status === true` 但缺 `transaction_reference`＝結構異常不能排除已進單：拋例外 → payout record 標 `REQUEST_FAILED(-1)`、訂單停留 21（代付請求中）待回調或人工定案
- `status === false`＝同步明確拒絕：payout record 標 `THIRD_PARTY_REJECTED(-2)`、訂單轉 29（代付回應失敗）可重新發起

---

## 5. 查詢 API

> 以下 API 為 PDF 記載，codebase 目前未實作。

### 5.1 查詢代收訂單狀態 — `POST /api/check_order`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ | string | 商戶 API Key |
| `transaction_reference` | ✅ | string | 代收交易參考號 |

**成功響應**

```json
{
    "status": true,
    "data": {
        "status_id": 3,
        "currency": "AUD",
        "amount": "1.0000",
        "ref_no": "1775",
        "platform_charge": "0.0000",
        "transaction_reference": "{transaction_reference}",
        "updated_at": "2025-03-05T08:31:16.000000Z",
        "is_sandbox": 0,
        "created_at": "2025-03-05T08:21:16.000000Z",
        "receipt": null
    }
}
```

---

### 5.2 查詢代付訂單狀態 — `POST /api/check_payout`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ | string | 商戶 API Key |
| `transaction_reference` | ✅ | string | 代付交易參考號 |

**成功響應**

```json
{
    "status": true,
    "data": {
        "status_id": 0,
        "currency": "SGD",
        "amount": "1.0000",
        "ref_no": "4005",
        "platform_charge": null,
        "transaction_reference": "{transaction_reference}",
        "updated_at": "2026-04-02 01:08:51",
        "created_at": "2026-04-02 01:08:51",
        "receipt": null
    }
}
```

---

### 5.3 查詢商戶餘額 — `POST /api/check_balance`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ | string | 商戶 API Key |
| `currency` | ✅ | string | 查詢幣別（如 `SGD`） |

**成功響應**

```json
{
    "status": true,
    "balance": "55.9740"
}
```

---

## 6. 回調處理

> ⚠️ **重要安全警示**：MaxPay（PayLoh）的代收與代付回調**均不包含簽名驗證欄位**，系統**無法透過簽名確認回調來源合法性**。安全保障完全依賴部署層的 **IP 白名單**過濾，請務必在防火牆或反向代理層確認僅允許 PayLoh 官方 IP 存取回調路由。

### 6.1 代收回調

**MaxPay 推送方式**：HTTP POST JSON

**回調欄位（PDF 定義）**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status_id` | string | 回調狀態碼（見下方） |
| `currency` | string | 支付幣別（如 `SG`=Singapore, `MY`=Malaysia, `TH`=Thailand） |
| `amount` | string | 實際支付金額 |
| `ref_no` | string | 商戶訂單號（對應 `order.order_no`，用於查單） |
| `transaction_reference` | string | PayLoh 交易參考號（存入 `order.third_party_order_no`） |
| `hash` | string | Hash 值（PDF 有記載，但 codebase 未驗簽） |
| `payment_method` | string | 支付方式（如 `PayNow/Online Banking`） |

> codebase（`DepositCallback.php`）實際 validate 欄位：`status_id`、`amount`、`ref_no`、`transaction_reference`。

**代收回調狀態碼（`status_id`）**

| status_id | PDF 定義 | 系統處理（codebase 現狀） |
|---|---|---|
| `0` | Pending（處理中） | ⚠️ **視為 FAILED**（match default 分支）；工單 #3739 指出應「狀態不變」 |
| `1` | Success（成功） | 更新訂單為成功（終態） |
| `2` | Failed（失敗） | 更新訂單為失敗（終態） |
| `3` | Time out（逾時） | ⚠️ **視為 FAILED**（match default 分支）；工單 #3739 指出應「狀態不變」 |
| `4` | Hold（保留） | ⚠️ **視為 FAILED**（match default 分支）；工單 #3739 指出應「狀態不變」 |

> **差異警示**：`DepositCallback.php` 的 `match` 表達式：`1 => SUCCESS, 2 => FAILED, default => FAILED`，導致 `status_id` 為 `0/3/4` 時一律設為 FAILED。工單 #3739 要求 `0/3/4` 應「狀態不變」（忽略）。如需修正，需將 match 改為僅處理 1/2，其餘 early return。

**回調響應**：純文字 `OK`（成功）/ `FAIL`（失敗）

**回調範例**

```json
{
    "status_id": "1",
    "currency": "SG",
    "amount": "100.00",
    "ref_no": "ORD20260601001",
    "transaction_reference": "{transaction_reference}",
    "hash": "{hash_value}",
    "payment_method": "PayNow"
}
```

---

### 6.2 代付回調

> ⚠️ **同上**：代付回調亦不驗簽，僅靠 IP 白名單。

**MaxPay 推送方式**：HTTP POST JSON

**回調欄位**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status_id` | integer | 回調狀態碼（見下方） |
| `ref_no` | string | 商戶代付流水號（對應 `withdrawal_payout_records.transaction_no`，用於查單） |
| `amount` | string | 代付金額 |
| `status` | string | 狀態文字（如 `Processing`, `Completed`） |
| `currency` | string | 幣別 |
| `bank_name` | string | 銀行名稱 |
| `bank_holder_name` | string | 收款人姓名 |
| `bank_account` | string | 收款帳號 |
| `platform_charge` | string | 平台手續費 |
| `final_amount` | string | 含手續費最終金額 |
| `transaction_reference` | string | PayLoh 交易參考號 |
| `callback_url` | string | 回調 URL |
| `remarks` | string\|null | 備註 |

> codebase（`WithdrawalCallback.php`）實際 validate 欄位：`status_id`、`amount`、`ref_no`。

**代付回調狀態碼（`status_id`）**

| status_id | 說明（工單 #3739） | 系統處理 |
|---|---|---|
| `0` | Pending（處理中） | 視為失敗（非成功態，`$isSuccessPayout = $status == 1`） |
| `1` | Completed（成功） | 更新代付訂單為成功（終態） |
| `2` | Failed（失敗） | 更新代付訂單為失敗（終態） |

**回調響應**：純文字 `OK`（成功）/ `FAIL`（失敗）

**回調範例**

```json
{
    "status_id": 1,
    "status": "Completed",
    "ref_no": "MAX202606010001",
    "currency": "SGD",
    "amount": "500.00",
    "platform_charge": "7.00",
    "final_amount": "507.00",
    "bank_name": "DBS",
    "bank_holder_name": "John Doe",
    "bank_account": "1234567890",
    "transaction_reference": "{transaction_reference}",
    "callback_url": "https://...",
    "remarks": null
}
```

**代付冪等性**：系統透過 `withdrawal_payout_records.transaction_no`（即 `ref_no`）查詢並 `lockForUpdate()`；`isPayoutRecordProcessed()` 判斷已處理記錄直接回傳成功，不重複更新訂單狀態。

---

## 7. 簽名流程

MaxPay 使用 **SHA-512** 作為請求簽名機制（非 HMAC，直接對拼接字串做 hash）。

### 簽名公式

```
hash_value = SHA512(api_key + ref_no + amount + secret_key)
```

**PDF 原文**：`hash-key-format = api_key + ref_no + amount + secret_key`

### 實作（`MaxPayTrait.php`）

```php
protected function generateHash(string $apiKey, string $refNo, string|float $amount, string $secretKey): string
{
    return hash('sha512', $apiKey . $refNo . $amount . $secretKey);
}
```

### 代收 vs 代付 amount 格式差異

| 情境 | amount 取值 | 格式說明 |
|---|---|---|
| 代收發起 | `$order->amount` | 原始值，不格式化（如 `100`） |
| 代付發起 | `sprintf('%.2f', $withdrawalOrder->amount)` | 兩位小數字串（如 `500.00`） |

> Hash 計算時 amount 的格式必須與請求中傳送的 amount **完全一致**，否則驗簽失敗（`Invalid hash`）。

### 簽名對照總表

| 情境 | 參數 Key | 拼接字串 |
|---|---|---|
| 代收發起 | `hash` | `api_key + ref_no + amount + secret_key` |
| 代付發起 | `hash` | `api_key + ref_no + amount + secret_key` |
| 代收回調 | — | **無驗簽**（回調含 `hash` 欄位但 codebase 不驗） |
| 代付回調 | — | **無驗簽** |

---

## 8. 銀行代碼映射

### 代付銀行清單（PDF `Payout Bank List`）

> 以下為 PayLoh 官方支援的代付銀行代碼，`bank_name` 欄位傳入 `BankCode_Third`。**2026-09-08 依 v1.9 PDF 實讀更新（共 23 個）**。

| BankCode_Third | 銀行名稱 | BankCode_Third | 銀行名稱 |
|---|---|---|---|
| `UOB` | UOB | `RHB` | RHB |
| `CIMB` | CIMB | `TrustBank` | TrustBank |
| `SCB` | SCB（渣打） | `Wise` | Wise |
| `OCBC` | OCBC | `ABMB` | ABMB |
| `DBS` | DBS | `GXS` | GXS |
| `Bank Of China` | Bank Of China | `Maribank` | Maribank |
| `Citibank` | Citibank | `Liquid Pay` | Liquid Pay |
| `Hong Leong` | Hong Leong | `Dash` | Dash |
| `HSBC` | HSBC | `PayNow` | PayNow |
| `Maybank` | Maybank | `POSB` | POSB |
| `paynow vpa` | PayNow VPA | `paynow uen` | PayNow UEN |
| `ICBC` | ICBC（**v1.9 新增**） | | |

> 🔴 **本商碼系是「全名」，與同為新幣代付的 GMPay「短名」相反，兩者逐項對應但寫法不同**（`Bank Of China`↔`BOC`、`Liquid Pay`↔`LiquidPay`、`Citibank`↔`Citi`、`Maybank`↔`MBB`、`Hong Leong`↔`HLB`、`TrustBank`↔`TBL`）。兩家銀行覆蓋高度重疊，極易誤判成同一碼系 ⇒ **禁止把本商 `available_banks` 的全名「順手收斂成短碼」**，那會直接斷付；反之亦然。

> ⭐ **`ICBC` 是 v1.9 才出現的碼**：VWorld 代理群組 70 有 5 名會員綁定 ICBC 而 GMPay 官方清單無此行，本商可作為該行的出款路徑（需在對應 `agent_cash_flows.info.available_banks` 補 `ICBKSG` → `ICBC` 映射）。

### 系統內已配置映射（`available_banks`）

代付請求透過 `available_banks` 配置將內部銀行代碼（`BankCode_ELG`）轉換為上方三方代碼。以下為目前已配置的映射（非完整清單）：

| 內部代碼（BankCode_ELG） | 三方代碼（BankCode_Third） | 銀行名稱 |
|---|---|---|
| `TPSG014` | `UOB` | United Overseas Bank |
| `SGD0059` | `CIMB` | CIMB Bank Singapore |
| `FRSG01` | `DBS` | DBS Bank |

> 新增銀行需在 `agent_cash_flows.info.available_banks` 加入對應 mapping。

---

## 9. 錯誤碼說明

以下為 PDF 記載的常見 API 錯誤（`status: false` 時的 `message` 值）：

| 錯誤訊息 | 說明 | 常見原因與排查 |
|---|---|---|
| `{'api_key': ['The api key field is required.']}` | 缺少必填參數 | 請求未帶 `api_key` 或欄位名稱拼錯 |
| `Invalid credential` | 憑證錯誤 | `api_key` 或 `secret_key` 不正確；新建憑證尚未啟用 |
| `IP address not whitelisted` | IP 未加入白名單 | 伺服器 IP 未在 PayLoh 後台設定；需聯繫 PayLoh 支援添加 |
| `Invalid currency` | 幣別不支援 | 傳入的幣別代碼不在商戶帳號設定的支援範圍內 |
| `Invalid payment method` | 支付方式不支援 | `payment_method_id` 不在商戶帳號啟用的方式清單內 |
| `Invalid hash` | 簽名錯誤 | hash 拼接字串有誤（欄位順序、amount 格式、多餘空格等） |

---

## 10. 參考資料

| 資料來源 | 說明 |
|---|---|
| 官方 API 文檔 | `PAYLOH_API_DOC-v1.9.pdf`（GitLab project 492 uploads，需登入下載）；前版 `PAYLOH_API_DOC-v1.7.pdf` 為工單 #3739 附件 |
| GitLab #3739 | [GFF][Yes2win] MAXPAY-SGD 代收代付接入（主接入工單，含 callback 格式說明） |
| GitLab #3740 | [GFF][Yes2win] MAXPAY-SGD 代收代付配置 |
| GitLab #3741 | [GFF][Wintoto] MAXPAY-SGD 代收代付配置 |
| GitLab #3745 | [GFF][TPower] MAXPAY-SGD 代收代付配置 |

### 內部實作路徑

```
app/Services/Payment/Providers/MaxPay/
├── Driver.php              # 組合代收/代付子類別，掛載到 MAXPAY config key
├── Deposit.php             # 代收實作（POST /api/create_order）
├── DepositCallback.php     # 代收回調處理（不驗簽，status_id 判定）
├── Withdrawal.php          # 代付實作（POST /api/create_payout）
├── WithdrawalCallback.php  # 代付回調處理（不驗簽，status_id 判定）
└── MaxPayTrait.php         # 共用：sendRequest、generateHash（SHA-512）
```

**config/payment.php 對應 key**：`MAXPAY`

---

*文件版本：1.1 / 整理日期：2026-06-01*
*資料來源：PAYLOH_API_DOC-v1.7.pdf（工單 #3739）、GitLab 工單 #3739/3740/3741/3745、Codebase 實作驗證*
