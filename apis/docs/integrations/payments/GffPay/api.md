# GffPay 廠商規格

> 官方 API 文檔：未公開（登入後台取得；Prod 入口 `https://example.invalid/admin`）
> 內部實作路徑：`app/Services/Payment/Providers/GffPay/`

GffPay 是以 **TON 區塊鏈**為基礎的第三方支付平台，支援代收（Deposit）與代付（Withdrawal）。與傳統法幣通道不同，GffPay 採用 **TON 加密貨幣**作為清算媒介：代收時分配 TON 錢包地址給玩家，代付時向指定錢包地址發送 TON。所有請求使用 **JSON** 格式，認證方式為**商戶代碼 + MD5 簽名**，回應格式統一為 `{ code, message, data }`。

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [代收 API](#3-代收-api)
4. [代付 API](#4-代付-api)
5. [回調處理](#5-回調處理)
6. [簽名流程](#6-簽名流程)
7. [銀行（通道）代碼映射](#7-銀行通道代碼映射)
8. [錯誤碼對照](#8-錯誤碼對照)
9. [參考資料](#9-參考資料)

---

## 1. 環境資訊

| 項目 | 值 |
|---|---|
| 生產環境 API Base URL | `https://example.invalid/api` |
| 生產環境商戶後台 | `https://example.invalid/admin` |
| 生產環境收銀台 | `https://example.invalid/cashier/preview` |
| 測試環境 API Base URL | `https://example.invalid/api` |
| 測試環境商戶後台 | `https://example.invalid/admin` |
| 測試環境收銀台 | `https://example.invalid/cashier/preview` |
| 請求格式 | JSON（`application/json`） |
| 響應格式 | JSON `{ code, message, data }` |
| 認證方式 | 商戶代碼（`merchant_code`）+ MD5 簽名（`sign`） |

> 測試環境使用 TON **測試網**，不可用正式網 TON 錢包轉帳。測試代付金額建議 2 TON 以下。

---

## 2. 支援的支付方式與幣別

### TON（Toncoin 區塊鏈）

| 支付類型 | `currency_code`（代收） | `currency_code`（代付） | 說明 |
|---|---|---|---|
| TON 錢包轉帳 | 對應 `order_type.key`（由 AgentCashFlow config 決定） | 透過 `convertPayoutBankCode()` 轉換後的代碼 | 鏈上轉帳，收銀台顯示 TON 地址 + QR Code |

> GffPay 本質為 TON 鏈上支付，代收採用 **1,000 個收款錢包池**輪替（有效期 1 小時、冷卻期 3 小時）；代付由主錢包統一撥款。`currency_code` 的具體值由商戶後台通道配置決定，透過 `example.invalid` 的 `available_banks` 映射取得。

---

## 3. 代收 API

### 代收發起 — `POST /deposit-order`

**Full URL**：`{APIUrl}/deposit-order`（APIUrl 由 `example.invalid['APIUrl']` 取得）

**請求格式**：JSON（`application/json`）

#### 請求參數

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `currency_code` | ✅ | string | 支付通道代碼（對應 `order_type.key`） |
| `merchant_code` | ✅ | string | 商戶代碼（由 `{MerchantNo}` 配置） |
| `merchant_order_no` | ✅ | string | 商戶訂單號（系統內唯一，`order.order_no`） |
| `amount` | ✅ | decimal | 代收金額（TON） |
| `callback_url` | ✅ | string | 代收結果回調 URL |
| `return_url` | ✅ | string | 收銀台支付完成後跳轉 URL |
| `sign` | ✅ | string | MD5 簽名（見第 6 節） |

**請求範例**

```json
{
    "currency_code": "TON",
    "merchant_code": "{MerchantNo}",
    "merchant_order_no": "ORD20260601001",
    "amount": "10.5",
    "callback_url": "https://example.invalid/callback/gffpay/deposit",
    "return_url": "https://example.invalid/return",
    "sign": "A1B2C3D4E5F6..."
}
```

#### 成功響應

```json
{
    "code": 200,
    "message": "success",
    "data": {
        "payment_url": "https://example.invalid/cashier/preview?token=[REDACTED]",
        "order_no": "GFF20260601001"
    }
}
```

| 欄位 | 說明 |
|---|---|
| `code` | `200` 為成功，其他為失敗 |
| `data.payment_url` | 收銀台 URL，導向玩家完成支付（顯示 TON 錢包地址 + QR Code） |
| `data.order_no` | GffPay 平台訂單號（存為 `third_party_order_no`） |

**成功判定**：`code === 200`（嚴格整數比較）

**失敗響應範例**

```json
{
    "code": 400,
    "message": "Invalid sign"
}
```

---

## 4. 代付 API

### 代付發起 — `POST /withdraw-order`

**Full URL**：`{APIUrl}/withdraw-order`

**請求格式**：JSON（`Content-Type: application/json`，使用 `->asJson()`）

#### 請求參數

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `currency_code` | ✅ | string | 代付通道代碼（透過 `convertPayoutBankCode()` 由內部 bank_code 轉換） |
| `merchant_code` | ✅ | string | 商戶代碼（`{MerchantNo}`） |
| `merchant_order_no` | ✅ | string | 商戶代付流水號（`Common::genTransactionNo('GFF')` 生成，前綴 `GFF`） |
| `amount` | ✅ | decimal | 代付金額（TON） |
| `callback_url` | ✅ | string | 代付結果回調 URL |
| `address` | ✅ | string | 收款 TON 錢包地址（`withdrawal_order.account_no`） |
| `sign` | ✅ | string | MD5 簽名（見第 6 節） |

> 注意：代付不傳收款人姓名，`address` 為 TON 鏈上錢包地址（非銀行帳號）。

**請求範例**

```json
{
    "currency_code": "TON",
    "merchant_code": "{MerchantNo}",
    "merchant_order_no": "GFF20260601ABC",
    "amount": "5.0",
    "callback_url": "https://example.invalid/callback/gffpay/withdraw",
    "address": "EQD_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
    "sign": "A1B2C3D4E5F6..."
}
```

#### 成功響應

```json
{
    "code": 200,
    "message": "success",
    "data": {
        "order_no": "GFF_OUT_20260601001"
    }
}
```

| 欄位 | 說明 |
|---|---|
| `code` | `200` 為成功，其他為失敗 |
| `data.order_no` | GffPay 平台代付訂單號（存為 `withdrawal_order.third_party_order_no`） |

**成功判定**：`code === 200`（嚴格整數比較）

**失敗響應範例**

```json
{
    "code": 400,
    "message": "Insufficient balance"
}
```

> 代付為非同步處理，最終結果透過回調通知。GffPay 每分鐘查詢 TON 交易紀錄，確認上鏈後才標記成功並回調。

---

## 5. 回調處理

GffPay 在代收或代付訂單狀態變更後，以 **HTTP POST JSON** 格式推送到商戶配置的 `callback_url`。回調遵循重試策略直到商戶正確響應（最長 7 天）。

### 5.1 代收回調

**推送方式**：HTTP POST JSON

#### 回調欄位

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `currency_code` | ✅ | string | 支付通道代碼 |
| `merchant_code` | ✅ | string | 商戶代碼 |
| `order_no` | ✅ | string | GffPay 平台訂單號 |
| `merchant_order_no` | ✅ | string | 商戶訂單號（`order_no`，用於查單） |
| `status` | ✅ | integer | 訂單狀態（見下方說明） |
| `amount` | ✅ | decimal | 實際支付金額 |
| `sign` | ✅ | string | MD5 簽名（驗簽用） |

**訂單狀態（`status`）**

| status | 說明 | 終態 |
|---|---|---|
| `1` | 等待付款 | - |
| `2` | 支付成功 | ✅ |
| `3` | 支付失敗 / 超時 | ✅ |

**成功判定**：`status == 2`（弱比較，二分判斷：等於 2 為成功，其餘為失敗）

**回調響應格式**（商戶回傳給 GffPay）

```json
// 成功接收
{ "code": 200, "message": "Success" }

// 已處理（冪等）
{ "code": 200, "message": "Order already processed" }

// 簽名錯誤
{ "code": 400, "message": "Invalid signature" }
```

**代收回調範例**

```json
{
    "currency_code": "TON",
    "merchant_code": "{MerchantNo}",
    "order_no": "GFF20260601001",
    "merchant_order_no": "ORD20260601001",
    "status": 2,
    "amount": "10.5",
    "sign": "A1B2C3D4E5F6..."
}
```

---

### 5.2 代付回調

**推送方式**：HTTP POST JSON

#### 回調欄位

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `currency_code` | ✅ | string | 代付通道代碼 |
| `merchant_code` | ✅ | string | 商戶代碼 |
| `order_no` | ✅ | string | GffPay 平台代付訂單號 |
| `merchant_order_no` | ✅ | string | 商戶代付流水號（用於查 payout_record） |
| `status` | ✅ | integer | 代付狀態（見下方說明） |
| `amount` | ✅ | decimal | 代付金額 |
| `sign` | ✅ | string | MD5 簽名（驗簽用） |

**代付狀態（`status`）**

| status | 說明 | 終態 |
|---|---|---|
| `1` | 等待付款（待處理） | - |
| `2` | 代付成功 | ✅ |
| `3` | 代付失敗 | ✅ |

**成功判定**：`status == 2`（弱比較）

**金額驗證**：代付成功時系統驗證回調金額與原始訂單金額是否一致（`validateCallbackAmount()`）；失敗時跳過金額驗證（因失敗時 `amount` 可能不準確）。

**回調響應格式**（商戶回傳給 GffPay）

```json
// 成功接收
{ "code": 200, "message": "Success" }

// 已處理（冪等）
{ "code": 200, "message": "Order already processed" }

// 訂單不存在
{ "code": 400, "message": "Order not exist" }

// 簽名錯誤
{ "code": 400, "message": "Invalid signature" }
```

**代付回調範例**

```json
{
    "currency_code": "TON",
    "merchant_code": "{MerchantNo}",
    "order_no": "GFF_OUT_20260601001",
    "merchant_order_no": "GFF20260601ABC",
    "status": 2,
    "amount": "5.0",
    "sign": "A1B2C3D4E5F6..."
}
```

---

### 5.3 回調冪等性

- **代收**：透過 `findAndLockDepositOrder()` + `checkDepositOrderStatus()` 防重。訂單已處理時回傳 `{ code: 200, message: "Order already processed" }`。
- **代付**：透過 `findAndLockPayoutRecordByThirdPartyNo(order_no, merchant_order_no)` 雙欄位查單，`isPayoutRecordProcessed()` 判斷是否重複。

---

### 5.4 GffPay 回調重試策略

GffPay 在商戶未正確響應時，按以下頻率持續重試，最長 7 天：

| 次數 | 頻率 |
|---|---|
| 第 1 次 | 立即執行 |
| 第 2～10 次 | 每 5 分鐘 |
| 第 11～20 次 | 每小時 |
| 第 21～30 次 | 每 3 小時 |
| 第 31 次以上 | 每天 |
| 超過 7 天 | 停止回調 |

---

## 6. 簽名流程

GffPay 使用 **MD5** 作為簽名算法，輸出為**大寫十六進位字串**。代收發起、代付發起、代收回調驗簽、代付回調驗簽均使用**相同算法**。

### 算法步驟

1. 取出所有請求/回調參數（排除 `sign` 欄位）
2. 將參數按 **Key 字典序（ksort）升冪排列**
3. 拼接格式：`key1=value1&key2=value2&...&keyN=valueN`（不含尾端 `&`）
4. 在末尾直接附加商戶 Secret Key（不加 `&`）：`...&keyN=valueN{MerchantKey}`
5. 對整個字串執行 `md5()`，結果轉**大寫**（`strtoupper`）

### PHP 實作參考

```php
private function sign(array $input, string $secretKey): string
{
    unset($input['sign']);       // 排除 sign 欄位
    ksort($input);               // 字典序排序

    $signString = '';
    foreach ($input as $k => $v) {
        $signString .= $k . '=' . $v . '&';
    }
    $signString = rtrim($signString, '&');  // 去除尾端 &
    $signString .= $secretKey;              // 附加 Secret Key

    return strtoupper(md5($signString));    // MD5 大寫
}
```

### 簽名範例

**代收發起參數**（排除 `sign`，ksort 後）：

```
amount=10.5
callback_url=https://example.invalid/callback/gffpay/deposit
currency_code=TON
merchant_code={MerchantNo}
merchant_order_no=ORD20260601001
return_url=https://example.invalid/return
```

拼接後附加 Secret Key：

```
amount=10.5&callback_url=https://example.invalid/callback/gffpay/deposit&currency_code=TON&merchant_code={MerchantNo}&merchant_order_no=ORD20260601001&return_url=https://example.invalid/return{MerchantKey}
```

最終 sign：`strtoupper(md5(上方字串))`

> 代收回調與代付回調的驗簽方式相同：對回調參數（排除 `sign`）執行相同計算，比對結果與回調中的 `sign` 是否一致。

---

## 7. 銀行（通道）代碼映射

GffPay 以 **TON 錢包/通道代碼** 作為 `currency_code`（而非傳統銀行代碼）。代收時，通道由 `order_type.key` 直接帶入；代付時，需透過 `convertPayoutBankCode()` 將內部 `bank_code` 映射至 GffPay 的三方代碼。

映射關係存在 `example.invalid['available_banks']` 中，格式如下：

```json
{
    "available_banks": [
        {
            "BankCode_ELG": "內部銀行代碼",
            "BankCode_Third": "GffPay 代碼（填入 currency_code）"
        }
    ]
}
```

> 由於 GffPay 為 TON 鏈上支付，不存在傳統意義的銀行代碼清單。具體通道代碼以各商戶後台開通的通道為準（後台路徑：`https://example.invalid/admin` → 商戶管理 → 通道設定）。

---

## 8. 錯誤碼對照

### 8.1 API 響應碼（`code` 欄位）

| code | 說明 |
|---|---|
| `200` | 成功 |
| `400` | 請求失敗（詳見 `message`） |
| 其他 | 系統錯誤 |

常見錯誤 `message`：

| message | 原因 |
|---|---|
| `Invalid sign` | 簽名驗證失敗 |
| `Merchant not found` | 商戶代碼無效 |
| `Duplicate order` | 訂單號重複 |
| `Insufficient balance` | 代付餘額不足 |
| `Bank code not found` | 代付通道代碼不存在 |
| `Amount invalid` | 金額無效或超出限額 |

### 8.2 回調響應碼（商戶回傳給 GffPay）

| code | message | 說明 |
|---|---|---|
| `200` | `Success` | 正常處理完成，GffPay 停止重試 |
| `200` | `Order already processed` | 冪等響應，GffPay 停止重試 |
| `400` | `Invalid signature` | 驗簽失敗，GffPay 視為未收到並繼續重試 |
| `400` | `Order not exist` | 訂單不存在，GffPay 視為異常 |

---

## 9. 參考資料

| 資料來源 | 說明 |
|---|---|
| GitLab #2881 | [GFF][TGGame] GffPay TON 貨幣代收 / 代付（接入工單，含系統架構與排程邏輯） |
| 官方 API 文檔 | 未公開，需登入商戶後台取得；Prod 入口：`https://example.invalid/admin` |

### 內部實作路徑

```
app/Services/Payment/Providers/GffPay/
├── Driver.php              # 組合代收/代付子類別，config key = GFF
├── Deposit.php             # 代收實作（POST /deposit-order），響應取 data.payment_url
├── DepositCallback.php     # 代收回調處理，status==2 成功，回傳 JSON {code,message}
├── Withdrawal.php          # 代付實作（POST /withdraw-order），前綴 GFF，asJson() 發送
└── WithdrawalCallback.php  # 代付回調處理，status==2 成功，雙欄位查 payout_record
```

> 無獨立 Trait 檔，HTTP 請求與 MD5 簽名邏輯各自內嵌於 `Deposit.php` 和 `Withdrawal.php`（程式完全對稱）。

---

*文件版本：1.0 / 整理日期：2026-06-01*
*資料來源：GitLab 工單 #2881、Codebase 實作（`app/Services/Payment/Providers/GffPay/`）*
