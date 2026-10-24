# GMPay 廠商規格

> 官方 API 文檔：`GMPay_API_Document_V1.5.pdf`（GitLab project #492 uploads；工單 #3254 原附 V1.4、工單 #2435 更新為 V1.5）。⚠️ 2026-09-08 查證：`target/tickets#2435` 是另一案，V1.5 的工單出處待確認；V1.4 PDF 實際位於 GitLab project 365 uploads（`GMPay_API_Document_V1.4.pdf`），其 Payout Bank List 見下方。
> 內部實作路徑：`app/Services/Payment/Providers/GMPay/`（底層協議繼承自 `app/Services/Payment/Providers/MajesticPay/`）

## 廠商簡介

GMPay（config key：`GMPAY`）是面向新加坡市場的第三方支付平台，提供代收（Deposit）與代付（Withdrawal），主要支援 **SGD**（新加坡幣）。

### 與 MajesticPay 的關係

GMPay 是 **MajesticPay 協議的薄包裝**：

- `GMPay\Deposit`、`DepositCallback`、`Withdrawal`、`WithdrawalCallback` 均繼承自對應的 `MajesticPay\*` 類別
- 底層 HTTP 請求、參數結構、回調欄位規格與 MajesticPay 完全相同
- **唯一差異**：回調驗簽邏輯。`GMPayTrait` 覆蓋了 `checkHash()` 方法——使用 `transaction_reference + amount + currency` 拼接（MajesticPay 使用 `ref_no + amount + currency`）

MajesticPay（config key：`MAJESTIC`）主要面向馬來西亞市場（MYR），GMPay 則面向新加坡市場（SGD），兩者共用同一套 API 協議但商戶系統相互獨立。

---

## 環境資訊

| 項目 | 值 |
|---|---|
| Production Base URL | `https://example.invalid`（PDF 官方確認） |
| 商戶後台 | `https://example.invalid/`（需帳密登入，不記錄於此） |
| 請求格式 | Form URL-encoded（`application/x-www-form-urlencoded`） |
| 響應格式 | JSON |
| 認證方式 | API Key（請求參數內） + HMAC-SHA256 Hash 簽名 |
| 沙盒帳號 | 由商戶後台另行申請，`is_sandbox=1` 時回調直接標記成功 |

> **注意**：codebase 中 Base URL 由 `agent_cash_flows.info['API_URL']` 決定，可按商戶配置覆蓋。PDF 列出的 `https://example.invalid` 為官方正式環境 URL。

---

## 支援的支付方式與幣別

### SGD（新加坡幣）

| 支付類型 | `payment_method_id` | 說明 |
|---|---|---|
| Online Banking | `1` | 網銀轉帳（PDF 官方值） |
| PayNow | `2` | 新加坡 PayNow QR 掃碼支付（PDF 官方值） |

> ⚠️ **PDF 與 Codebase 差異**：PDF 官方文檔 `payment_method_id` 列 `Online Banking = 1`、`PayNow = 2`；但 codebase `MajesticPay/Deposit.php` 的建構注解列 `Online Banking = 3`、`DuitNow = 6`（這是 MajesticPay MYR 的值）。GMPay SGD 實際應以 PDF 值（1、2）為準，送出的值來自 `orderType.key`，需確認 `agent_cash_flows` 配置的 `key` 與 PDF 一致。

> 前台顯示名稱（多語系）：
> - GMPay (P2P) → `新币快速进分`
> - GMPay (PayNow) → `新币扫码支付`
> - 支付頁 icon 使用新加坡國旗

**代收限額**：20 – 3,000 SGD（每筆）

**代付限額**：Min 50 SGD / Max 10,000 SGD（每筆）

---

## API 接口

### 1. 代收發起 — `POST /api/create_deposit`

**Base URL**：`https://example.invalid`（或由商戶 `API_URL` 覆蓋）

**描述**：建立新訂單並返回支付連結。

**請求格式**：Form URL-encoded

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ Mandatory | string | 商戶 API Key，用於認證 |
| `ref_no` | ✅ Mandatory | string | 商戶唯一訂單號（`order.order_no`） |
| `amount` | ✅ Mandatory | string | 代收金額，**必須含 2 位小數**（如 `100.00`） |
| `payment_method_id` | ✅ Mandatory | string | 支付方式 ID（`1`=Online Banking、`2`=PayNow） |
| `currency` | ✅ Mandatory | string | 幣別代碼（如 `SGD`） |
| `hash` | ✅ Mandatory | string | SHA256 簽名，格式：`ref_no + amount + currency`，金額**必須含 2 位小數** |
| `return_url` | ✅ Mandatory | string | 支付完成後跳轉 URL |
| `callback_url` | ✅ Mandatory | string | 代收結果伺服器回調 URL |
| `player_id` | Optional | string | 玩家唯一 ID |

**成功響應**

```json
{
    "status": true,
    "data": {
        "created_at": "2025-08-21T02:49:22.000000Z",
        "transaction_reference": "{三方交易號}",
        "ref_no": "{商戶訂單號}",
        "currency": "SGD",
        "payment_method": "Online Banking",
        "amount": "1.00",
        "platform_charge": 0,
        "final_amount": 0,
        "return_url": "{your_return_url}",
        "callback_url": "{your_callback_url}",
        "ip_address": "{your_ip_address}",
        "payment_link": "{payment_link_from_api}",
        "is_sandbox": true,
        "origin_amount": "1.00"
    }
}
```

> **V1.5 新增** `origin_amount`：原始下單金額。三方為避免交易金額重複，可能自動微調最終付款金額，`amount` 為實付金額、`origin_amount` 為原始下單金額。
>
> **金額關係約束（重要）**：
> - `order.amount`（我方下單金額）**一定 ≥ `origin_amount`**，且兩者差額**不會超過 1.00（SGD）**。
> - 實付 `amount` **≥ `origin_amount`**，差額同樣**不超過 1.00（SGD）**。
> - 回調上分一律以**實付 `amount`** 為準（見 `integration.md` 工單 #2435 入帳重算與防護）。

**失敗響應**

```json
{
    "status": false,
    "message": "Ref no already existed."
}
```

**成功判定**：`status === true`（嚴格比對，不接受 truthy 值）

**codebase 行為**：
- `data.transaction_reference` 存入 `orders.third_party_order_no`
- `data.payment_link` 為支付收銀台 URL，導向給玩家（`DepositResponseType::URL_REDIRECT`）

---

### 2. 代付發起 — `POST /api/create_payout`

**描述**：發起商戶代付到收款人銀行帳戶。

**請求格式**：Form URL-encoded

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ Mandatory | string | 商戶 API Key，用於認證 |
| `ref_no` | ✅ Mandatory | string | 商戶唯一代付流水號（`Common::genTransactionNo('MAJ')` 生成） |
| `amount` | ✅ Mandatory | string | 代付金額，**必須含 2 位小數** |
| `currency` | ✅ Mandatory | string | 幣別代碼（如 `SGD`） |
| `hash` | ✅ Mandatory | string | SHA256 簽名，格式：`ref_no + amount + currency`，金額**必須含 2 位小數** |
| `callback_url` | ✅ Mandatory | string | 代付結果伺服器回調 URL |
| `bank_name` | ✅ Mandatory | string | 收款銀行代碼（見代付銀行清單，傳 SWIFT BIC 格式） |
| `bank_holder_name` | ✅ Mandatory | string | 收款人姓名（`withdrawal_order.account_name`） |
| `bank_account` | ✅ Mandatory | string | 收款銀行帳號（`withdrawal_order.account_no`） |
| `remarks` | Optional | string | 代付備注 |
| `player_id` | Optional | string | 玩家唯一 ID |

> `ref_no` 使用前綴 `MAJ` 生成（`$orderPrefix = 'MAJ'`），格式如 `MAJ20260601XXXXXX`。沿用 MajesticPay 前綴為歷史慣例。

**代付初始狀態碼**

| status_id | 說明 |
|---|---|
| `0` | Pending（待處理） |
| `1` | Completed（完成） |
| `2` | Failed（失敗） |

**成功響應**

```json
{
    "status": true,
    "result": {
        "status_id": 0,
        "status": "Pending",
        "ref_no": "{商戶流水號}",
        "currency": null,
        "amount": 1,
        "platform_charge": 0,
        "final_amount": 0,
        "bank_name": "MBB",
        "bank_holder_name": "{bank_holder_name}",
        "bank_account": "1234567890",
        "callback_url": "{your_payout_callback_url}",
        "remarks": null,
        "transaction_reference": "{三方代付交易號}"
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

**成功判定**：`status === true`（嚴格比對）

**codebase 行為**：
- `result.transaction_reference` 存入代付紀錄的 `third_party_transaction_no`
- 代付為非同步處理，最終結果透過回調通知

---

### 3. 查詢代收狀態 — `POST /api/check_deposit`

> ⚠️ **此 API 現有文檔先前缺失，由 PDF 補充。**

**描述**：查詢代收交易的當前處理狀態。

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ Mandatory | string | 商戶 API Key |
| `transaction_reference` | ✅ Mandatory | string | 三方系統交易號（由 `/api/create_deposit` 返回） |

**狀態碼**

| status_id | 說明 |
|---|---|
| `0` | Pending（待處理） |
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
        "ref_no": "{商戶訂單號}",
        "platform_charge": "0.00",
        "transaction_reference": "{三方交易號}",
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

> **注意**：codebase 目前未實作此查詢 API，僅有被動回調處理。

---

### 4. 代收回調 — `POST {callback_url}`（由三方主動推送）

**描述**：GMPay 在支付完成後以 **HTTP POST（Form URL-encoded）** 格式推送到商戶的 `callback_url`。

**回調欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `status` | ✅ | string | 交易狀態（`completed`=成功、`failed`=失敗） |
| `currency` | ✅ | string | 幣別 |
| `amount` | ✅ | string | **實付金額**（如 `"500.00"`）；三方可能微調，以此為準 |
| `origin_amount` | ✅ | string | **V1.5 新增**：原始下單金額（如 `"500.00"`）；未納入回調驗簽 |
| `platform_charge` | ✅ | string | 手續費（如 `"0.00"`） |
| `ref_no` | ✅ | string | 商戶訂單號（查單依據） |
| `transaction_reference` | ✅ | string | GMPay 三方交易號 |
| `updated_at` | ✅ | string | 交易更新時間（ISO 8601，如 `"2025-08-14T09:15:17.000000Z"`） |
| `is_sandbox` | ✅ | integer | 沙盒旗標：`1`=沙盒、`0`=正式 |
| `hash` | - | string | HMAC-SHA256 驗簽（**GMPay 特有**，拼接 `transaction_reference + amount + currency`） |
| `is_repush` | - | integer | 補推旗標：`1`=補推金額不符需人工確認 |
| `repush_amount` | - | string | 補推金額（`is_repush=1` 時存在） |
| `telco` | - | string | 電信通道（選填） |

**代收回調狀態**

| status | 說明 |
|---|---|
| `completed` | 支付成功 |
| `failed` | 支付失敗 |

**成功判定**：`status === "completed"`

**回調響應**（純文字）：

| 情況 | 回傳值 |
|---|---|
| 處理成功 | `OK` |
| 處理失敗 | `Failed` |

**成功範例**

```json
{
    "status": "completed",
    "currency": "SGD",
    "amount": "500.00",
    "platform_charge": "0.00",
    "ref_no": "{商戶訂單號}",
    "transaction_reference": "{三方交易號}",
    "updated_at": "2025-08-14T09:15:17.000000Z",
    "is_sandbox": 0,
    "hash": "{hash_for_this_transaction}",
    "origin_amount": "500.00"
}
```

**特殊邏輯（codebase 驗證）**：

1. **額外驗證**：驗簽通過後，比對 `orders.third_party_order_no` 與回調的 `transaction_reference` 是否一致，不一致則拒絕（transaction_reference mismatch）
2. **沙盒強制成功**：`is_sandbox === 1` 時，不論 `status` 為何，直接標記訂單成功（⚠️ 安全警示，見下方）
3. **補推轉人工**：`is_repush !== 0` 時，訂單轉入 `WAITING_MANUAL_DEPOSIT` 等待人工確認
4. **V1.5 實付金額重算（工單 #2435）**：`completed` 且帶 `origin_amount` 時，以實付 `amount` 重算 `orders.amount` / `orders.point`（`point = amount × rate`）後才上分；含合理範圍防護。詳見 `integration.md`。

---

### 5. 查詢代付狀態 — `POST /api/check_payout`

> ⚠️ **此 API 現有文檔先前缺失，由 PDF 補充。**

**描述**：查詢代付交易的當前處理狀態。

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ Mandatory | string | 商戶 API Key |
| `transaction_reference` | ✅ Mandatory | string | 三方系統代付交易號（由 `/api/create_payout` 返回） |

**狀態碼**

| status_id | 說明 |
|---|---|
| `0` | Pending（待處理） |
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
        "ref_no": "{商戶流水號}",
        "platform_charge": "0.00",
        "transaction_reference": "{三方代付交易號}",
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

> **注意**：codebase 目前未實作此查詢 API，僅有被動回調處理。

---

### 6. 代付回調 — `POST {callback_url}`（由三方主動推送）

**描述**：GMPay 代付完成後以 **HTTP POST（Form URL-encoded）** 格式推送到商戶的 `callback_url`。

**回調欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `status` | ✅ | string | 代付狀態（`completed`=成功、`failed`=失敗） |
| `currency` | ✅ | string | 幣別 |
| `amount` | ✅ | string | 代付金額 |
| `platform_charge` | ✅ | string | 手續費 |
| `ref_no` | ✅ | string | 商戶代付流水號（查單依據） |
| `transaction_reference` | ✅ | string | GMPay 三方代付交易號 |
| `updated_at` | ✅ | string | 交易更新時間（ISO 8601） |
| `is_sandbox` | ✅ | integer | 沙盒旗標：`1`=沙盒、`0`=正式 |
| `hash` | - | string | HMAC-SHA256 驗簽（**GMPay 特有**，拼接 `transaction_reference + amount + currency`） |

**代付回調狀態**

| status | 說明 |
|---|---|
| `completed` | 代付成功 |
| `failed` | 代付失敗 |

**成功判定**：`status === "completed"`

**回調響應**（純文字）：

| 情況 | 回傳值 |
|---|---|
| 處理成功 | `OK` |
| 處理失敗 | `Failed` |

**codebase 行為**：以 `transaction_reference`（三方交易號）與 `ref_no`（商戶流水號）雙重比對 `payout_records` 表（`findAndLockPayoutRecordByThirdPartyNo`）。

---

### 7. 查詢商戶餘額 — `POST /api/check_balance`

> ⚠️ **此 API 現有文檔先前缺失，由 PDF 補充。**

**描述**：查詢商戶帳戶餘額。

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `api_key` | ✅ Mandatory | string | 商戶 API Key |
| `currency` | ✅ Mandatory | string | 查詢幣別（`SGD`） |

**成功響應**

```json
{
    "status": true,
    "data": {
        "balance": "3100.00",
        "currency": "SGD",
        "merchant": "{merchant_name}"
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

> **注意**：codebase 目前未實作此查詢 API。

---

## 簽名流程

所有 HMAC-SHA256 簽名均以商戶的 `SECRET_KEY` 為密鑰，輸出**小寫十六進位字串**。

### 代收發起簽名（`hash`）

**PDF 官方格式**：`SHA256(ref_no + amount + currency)`，金額必須含 2 位小數

**codebase 實作**（`MajesticPayTrait::generateHash()`）：

```php
protected function generateHash(array $params, string $secretKey = ''): string
{
    $stringToSign = $params['ref_no']
        . number_format((float) $params['amount'], 2, '.', '')
        . $params['currency'];

    return hash_hmac('sha256', $stringToSign, $secretKey);
}
```

**範例**：

```
ref_no:   ORD20260601001
amount:   100.00（number_format 強制兩位小數）
currency: SGD

拼接：ORD20260601001100.00SGD
Hash：hash_hmac('sha256', 拼接字串, {SECRET_KEY})
```

### 代付發起簽名（`hash`）

代付使用相同的 `generateHash()`，拼接規則相同：

```
ref_no:   MAJ20260601ABC
amount:   200.00
currency: SGD

拼接：MAJ20260601ABC200.00SGD
```

### 回調驗簽（`hash`）— GMPay 特有

> ⚠️ GMPay 的回調驗簽與 MajesticPay **不同**。

**PDF 官方格式（代收與代付回調均相同）**：`SHA256(transaction_reference + amount + currency)`

**codebase 實作**（`GMPayTrait::checkHash()`，覆蓋 `MajesticPayTrait::checkHash()`）：

```php
protected function checkHash(array $input, string $secretKey = ''): bool
{
    $stringToSign = $input['transaction_reference'] . $input['amount'] . $input['currency'];
    $calculatedSign = hash_hmac('sha256', $stringToSign, $secretKey);

    return hash_equals($calculatedSign, $input[$this->signField]); // $signField = 'hash'
}
```

| 商戶 | 發起簽名拼接 | 回調驗簽拼接 |
|---|---|---|
| **GMPay** | `ref_no + amount + currency` | `transaction_reference + amount + currency` |
| MajesticPay | `ref_no + amount + currency` | `ref_no + amount + currency` |

---

## ⚠️ 安全性警示

### 1. 沙盒旗標繞過金額保護

代收回調中，當 `is_sandbox === 1` 時，系統**直接標記訂單成功，不驗證 `status` 欄位也不驗證金額**：

```php
// MajesticPay/DepositCallback.php::determineOrderStatus()
if ($input['is_sandbox'] === 1) {
    return OrderStatusEnum::SUCCESS->value;  // 不管 status 是什麼都成功
}
```

**風險**：若正式環境的回調被偽造並帶入 `is_sandbox=1`，可能導致訂單被錯誤標記成功。應確保：
- 生產部署時嚴格驗簽（`checkHash` 必須通過）
- 不要在生產環境暴露沙盒配置

### 2. 直接 update 不轉 WAITING_MANUAL_DEPOSIT

代收成功路徑中，訂單以 `$order->update([...])` **直接更新**狀態，繞過了部分金流商共用框架的 `WAITING_MANUAL_DEPOSIT` 中間狀態（僅 `is_repush !== 0` 時才進人工確認）。

### 3. 代收額外驗證：transaction_reference 比對

系統在驗簽後，還會比對 `orders.third_party_order_no === $input['transaction_reference']`；若不符則拒絕回調並記錄 warning log，不影響訂單狀態。

---

## 錯誤碼清單

> **由 PDF 補充，現有文檔先前缺失。**

| 錯誤訊息 | 說明 |
|---|---|
| `Validation Error` | 必填欄位缺失或包含無效值 |
| `System under maintenance` | 系統暫時停止服務，稍後再試 |
| `Invalid credentials` | 提供的 API Key 無效 |
| `Invalid hash` | 請求 hash 格式錯誤，請確認雜湊演算法與參數 |
| `Invalid IP address` | 請求來源 IP 不在商戶白名單內 |
| `Ref no already exists` | 提供的訂單號已在先前交易中使用 |
| `Invalid payment method` | 選擇的支付方式對此商戶帳戶未啟用 |
| `No available channel for this payment method` | 無可用平台或銀行通道處理此支付方式 |
| `Below minimum amount` | 交易金額低於最低限額 |
| `Below maximum amount` | 交易金額超過最高限額 |
| `Invalid Merchant` | 商戶帳戶已停用或被禁用 |
| `Transaction not found` | 指定的交易號在系統中找不到 |
| `Payout not found` | 指定的代付號在系統中找不到 |

---

## 銀行代碼映射

### SGD — 代付銀行代碼（SWIFT BIC 格式）

以下為工單 #3255（2026/03/25 更新）記錄的可用代付銀行清單：

| 銀行名稱 | SWIFT BIC（`bank_name` 欄位） |
|---|---|
| UOB | `UOVBSGSGXXX` |
| CIMB | `CIBBSGSGXXX` |
| Standard Chartered Bank（SCB SG） | `SCBLSG22XXX` |
| ~~Standard Chartered Bank（SCB）~~ | ~~`SCBLSGSGXXX`~~ 🔴 **已退役** |
| OCBC | `OCBCSGSGXXX` |
| DBS | `DBSSSGSGXXX` |
| Bank of China | `BKCHSGSGXXX` |
| Citibank SG | `CITISGSLXXX` |
| Citibank NA | `CITISGSGXXX` |
| HL Bank Singapore | `HLBBSGSGXXX` |
| HSBC SG | `HSBCSGS2XXX` |
| HSBC | `HSBCSGSGXXX` |
| Maybank | `MBBESGS2XXX` |
| RHB | `RHBBSGSGXXX` |
| Trust Bank | `TRBUSGSGXXX` |
| Wise | `TRWISGSGXXX` |
| GXS Bank | `GXSPSGSGXXX` |
| Maribank | `SSPISGSGXXX` |
| Liquid | `LIGPSGSGXXX` |

> 代付 `bank_name` 欄位的值由 `convertPayoutBankCode()` 從 `agent_cash_flows.info.available_banks[].BankCode_Third` 取出（查表路徑則取 `cash_flow_banks.provider_bank_code`），**送出的是碼表填的字串，本身不限定格式**。上表 SWIFT BIC 為工單 #3255 所載；⚠️ **2026-09-07 查證：VWorld 生產現行填的是 V1.5 短名而非 BIC**，見下方 V1.5 段的更正。

> 🔴 **`SCBLSGSG` 已於 2026-05-23 退役，不可再用**（2026-08-27 查證官網）。渣打官方公告原文：
> 「from 23 May 2026, all inward telegraphic transfers (ITT) sent using BIC `SCBLSGSG` will be **rejected**」，
> 並會自動移除客戶端已存的舊識別碼收款人。**Standard Chartered Bank (Singapore) Limited 一律用 `SCBLSG22`**
> （自 2019-07-08 起搭配 Bank Code 9496 用於 GIRO／FAST／PayNow／MEPS／電匯）。
> 來源：[example.invalid/sg 官方公告](https://example.invalid/sg/important-information/bic-scblsg/)。
> ✅ 已確認**專案內無任何代碼或資料庫使用該碼**，僅本文檔曾列出，故無生產影響。

> ⚠️ **名稱更正（2026-08-27）**：`HLBBSGSG` 原記為「Hong Leong Finance」，實為 **HL Bank Singapore**
> ——Hong Leong Bank Berhad 的新加坡分行、持 Full Bank 執照。Hong Leong Finance 是**另一家公司**，勿混用。
> 來源：[HL Bank Singapore 官網](https://example.invalid.sg/en/personal-banking/about-us.html)。

> ⚠️ 同名多碼須依**法律實體**選擇，不可任意取一（8 碼 BIC 的第 7–8 碼是 business party suffix，
> 同一集團在同一國可註冊多個）：Citibank 有 `CITISGSL`（Citibank Singapore Limited，零售 SGD 轉帳用，
> 2019-04-15 起）與 `CITISGSG`（Citibank N.A. 新加坡分行）；HSBC 有 `HSBCSGS2`（2016-05 起，個人帳戶）
> 與 `HSBCSGSG`（法人銀行）。**給會員出款一律取零售／個人實體那一碼。**

> ✅ **官方 Payout Bank List（2026-09-08 依 V1.4 PDF 第 12 頁實讀更正）**：本段原記「V1.4 第 12 頁因字型嵌入問題亂碼、V1.5 才可讀」與實讀不符——`GMPay_API_Document_V1.4.pdf`（GitLab project 365 uploads）第 12 頁「Payout Bank List」完全可讀，列 20 個**銀行短名**（非 SWIFT BIC），也是 `app/Console/Commands/CashFlow/Data/providers/GMPay.php` 於 2026-03-26 調整六碼（Citi／MBB／HLB／BOC／TBL／LiquidPay）的依據：
>
> `Dash`、`BOC`、`LiquidPay`、`PayNow`、`ABMB`、`POSB`、`Wise`、`Maribank`、`TBL`、`GXS`、`RHB`、`SCB`、`MBB`、`CIMB`、`HSBC`、`UOB`、`HLB`、`DBS`、`Citi`、`OCBC`
>
> 對照上表（來源工單 #3255 的 SWIFT BIC）：多出 **`ABMB`（Alliance Bank）、`POSB`、`PayNow`、`TBL`（Trust Bank）、`Dash`** 等短名；**ICBC 不在清單內**（VWorld 群組 70 綁 ICBC 的會員經本通道無法出款）。
> ⚠️ 本段先前轉錄的「V1.5 清單」寫 `Liquid`／`MariBank` 且缺 `Dash`，與 V1.4 實讀的 `LiquidPay`／`Maribank` 不符；V1.5 PDF 未重讀，該轉錄視為**未驗證**，以本段 V1.4 實讀為準。
>
> ✅ **更正（2026-09-07，實跑查證）**：本段原記「勿直接以短名當 `bank_name` 送出」**已推翻**——VWorld 生產（`cash_flows` 74）回調確認撥款成功（`withdrawal_payout_records.is_success_payout=1`）的代付單，`bank_name` 送的全是官方短名：`DBS` 125 筆、`PayNow` 77、`OCBC` 36、`POSB` 4（最近 2026-09-06）；成功單裡沒有任何 BIC 或全名。`cash_flow_banks` 對 `cash_flows` 74 服務中的 20 列亦全為短名（`BOC`／`Citi`／`HLB`／`MBB`／`TBL`／`Wise`／`Maribank`／`Dash`…）。⇒ **`available_banks[].BankCode_Third` 與 `provider_bank_code` 一律填上方官方短名**。查證細節見 `docs/未完成計劃/RB/工作紀錄/銀行收斂-2026-09.md` 的 BL 列「名稱與短碼並存型真配錯 31 筆逐家判定」（2026-09-08 由 TODO.md 搬到工作紀錄）。
> ℹ️ Liquid Pay 的三種拼法（`LiquidPay`／`Liquid Pay`／`Liquid`）已以 V1.4 實讀定案為 **`LiquidPay`**（2026-09-08）：`cash_flow_banks` 226 與 ACF 235／239 已一致，ACF 294／296 殘留改碼前的 `Liquid Pay` 待修正（見 `docs/未完成計劃/RB/TODO.md` §2.13-尾「群組 70 的中國銀行與 Liquid Pay 兩筆刻意保留」；ACF 296 即代理群組 70，該處以群組編號記載、不以 ACF 編號記載）。該碼至今零實跑紀錄。

---

## 費率與限額參考

以下為工單記錄的典型值，各商戶實際費率以後台設定為準。

| 類型 | 費率 | 最低 | 最高 |
|---|---|---|---|
| 代收 P2P（Online Banking） | 3.4% – 3.5% | 20 SGD | 3,000 SGD |
| 代收 PayNow QR | 同上或略高 | 20 SGD | 3,000 SGD |
| 代付 | 1.8% | 50 SGD | 10,000 SGD |

---

## API 接口一覽

| 序號 | 接口名稱 | 方法 | 路徑 | codebase 實作 |
|---|---|---|---|---|
| 1 | 代收發起 | POST | `/api/create_deposit` | ✅ `MajesticPay/Deposit.php` |
| 2 | 代收回調 | POST | `{callback_url}`（三方推送） | ✅ `MajesticPay/DepositCallback.php` + `GMPayTrait` |
| 3 | 代付發起 | POST | `/api/create_payout` | ✅ `MajesticPay/Withdrawal.php` |
| 4 | 代付回調 | POST | `{callback_url}`（三方推送） | ✅ `MajesticPay/WithdrawalCallback.php` + `GMPayTrait` |
| 5 | 查詢代收狀態 | POST | `/api/check_deposit` | ❌ 未實作 |
| 6 | 查詢代付狀態 | POST | `/api/check_payout` | ❌ 未實作 |
| 7 | 查詢商戶餘額 | POST | `/api/check_balance` | ❌ 未實作 |

---

## 參考資料

| 資料來源 | 說明 |
|---|---|
| 官方 API 文檔 | `GMPay_API_Document_V1.5.pdf`（GitLab project #492 uploads，共 12 頁；工單 #2435 更新） |
| 商戶後台 | `https://example.invalid/`（需帳密登入） |
| GitLab #3254 | `[GFF][TP][APIs] GmPay-SGD 代收代付接入`（最初接入工單，rb 負責） |
| GitLab #3252 | `[GFF][TP] GmPay-SGD 代收代付配置`（TPower 商戶配置，含費率） |
| GitLab #3253 | `[GFF][YES2WIN] GmPay-SGD 代收代付配置`（Yes2Win 商戶配置） |
| GitLab #3255 | `[GFF][WTT] GmPay-SGD 代收代付配置`（WTT 商戶配置，含最新銀行清單） |
| GitLab #3391 | `[GFF][VWSG] GMPay-SGD 代收代付配置`（VWorld SG 配置） |
| GitLab #3776 | `[GFF][TPower] GmPay-SGD 代收代付 新商戶配置` |
| GitLab #3777 | `[GFF][Wintoto] GmPay-SGD 代收代付 新商戶配置` |
| GitLab #3778 | `[GFF][Yes2win] GmPay-SGD 代收代付 新商戶配置` |

### 內部實作路徑

```
app/Services/Payment/Providers/GMPay/
├── Driver.php              # 組合代收/代付子類別，掛載到 GMPAY config key
├── Deposit.php             # 繼承 MajesticPay\Deposit，使用 GMPayTrait
├── DepositCallback.php     # 繼承 MajesticPay\DepositCallback，使用 GMPayTrait
├── Withdrawal.php          # 繼承 MajesticPay\Withdrawal，使用 GMPayTrait
├── WithdrawalCallback.php  # 繼承 MajesticPay\WithdrawalCallback，使用 GMPayTrait
└── GMPayTrait.php          # 繼承 MajesticPayTrait，覆蓋 checkHash()（回調驗簽差異）

app/Services/Payment/Providers/MajesticPay/
├── Driver.php              # MajesticPay（MAJESTIC config key）主驅動
├── Deposit.php             # 代收實作：POST /api/create_deposit
├── DepositCallback.php     # 代收回調處理（GMPay 透過 GMPayTrait 覆蓋 checkHash）
├── Withdrawal.php          # 代付實作：POST /api/create_payout
├── WithdrawalCallback.php  # 代付回調處理（GMPay 透過 GMPayTrait 覆蓋 checkHash）
└── MajesticPayTrait.php    # 共用：sendRequest()、generateHash()、checkHash()（MYR 版）
```

> config key：`GMPAY`（GMPay，SGD）、`MAJESTIC`（MajesticPay，MYR）

---

*文件版本：1.2 / 整理日期：2026-07-09（對齊官方 V1.5：新增 `origin_amount`、Payout Bank List 可讀化、金額關係約束）*
*資料來源：官方 PDF `GMPay_API_Document_V1.5.pdf`（工單 #2435，12 頁）、Codebase 實作（`GMPay/`、`MajesticPay/`）、GitLab 工單 #2435、#3252、#3253、#3254、#3255、#3391、#3776、#3777、#3778*
