# AkPay 廠商規格

> 官方 API 文檔：https://example.invalid/view/22077629/2s9Ykt4ycb
> 內部實作路徑：`app/Services/Payment/Providers/AkPay/`

AkPay（後台域名 `example.invalid`）是面向馬來西亞市場的第三方支付平台，支援 MYR 幣別的代收（Deposit）與代付（Withdrawal）。採用 **Bearer Token** 認證方式，所有請求以 **JSON** 格式提交，響應亦為 JSON。回調簽名採 **MD5** 雜湊驗證（`secret_key` 欄位）。

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [認證方式](#3-認證方式)
4. [代收 API](#4-代收-api)
5. [代付 API](#5-代付-api)
6. [回調處理](#6-回調處理)
7. [簽名（secret\_key）驗證流程](#7-簽名secret_key驗證流程)
8. [銀行代碼映射](#8-銀行代碼映射)
9. [限額參考](#9-限額參考)
10. [參考資料](#10-參考資料)

---

## 1. 環境資訊

| 項目 | 值 |
|---|---|
| 生產環境 API Base URL | `https://example.invalid` |
| 商戶後台（生產） | `https://example.invalid/merchant` |
| 請求格式 | JSON（`Content-Type: application/json`） |
| 響應格式 | JSON |
| 認證方式 | Bearer Token（`Authorization: Bearer {MERCHANT_TOKEN}`） |

---

## 2. 支援的支付方式與幣別

### MYR（馬來西亞令吉）

#### 代收渠道

| 渠道名稱 | `payment_method` | 類型 | 銀行/選項（`bank_code`） |
|---|---|---|---|
| Online Banking（網銀） | `Bank` | 銀行轉帳 | HLB、MBB、PBB、RHB、CIMB、BSN、BMMB、AFB |
| QR Code | `QR` | 掃碼 | TNGQR、DUITNOW、DUITNOWEWALLET |

> `payment_method` 為父層渠道代碼（`Bank` / `QR`）；子層銀行/QR 選項以 `bank_code` 傳入。

#### 代付渠道

| 渠道類型 | `payment_method` |
|---|---|
| 銀行轉帳 | `Bank` |

---

## 3. 認證方式

所有 API 請求需在 HTTP Header 帶入 `Authorization: Bearer {MERCHANT_TOKEN}`，Token 由 AkPay 後台建立商戶帳號後取得，對應 codebase 設定項目 `MERCHANT_TOKEN`。

```
Authorization: Bearer {MERCHANT_TOKEN}
Content-Type: application/json
```

> 注意：AkPay 無需像部分平台先呼叫 `/auth` 接口換 token，直接以固定 Token 發請求即可。

---

## 4. 代收 API

### 4.1 建立代收訂單 — `POST /api/order/fundin`

**完整 URL（生產）**：`https://example.invalid/api/order/fundin`

#### 請求參數

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `merchant_code` | ✅ | string | 商戶代碼（`{MerchantCode}`） |
| `source_id` | ✅ | string | 商戶訂單號（系統內唯一，直接使用 `order_no`，**無商戶前綴**） |
| `full_name` | ✅ | string | 使用者顯示名稱（實作使用 member.username） |
| `customer` | ✅ | integer | 會員 ID |
| `payment_method` | ✅ | string | 支付渠道代碼（`Bank` / `QR`） |
| `amount` | ✅ | decimal | 訂單金額 |
| `currency` | ✅ | string | 幣別代碼（目前僅 `MYR`） |
| `callback_url` | ✅ | string | 回調通知 URL |
| `redirect_url` | - | string | 支付完成後跳轉 URL |
| `bank_code` | - | string | 子渠道銀行/QR 代碼（父層有 `bank_code` 時必填，見銀行代碼映射） |

> `source_id` 實際送出的就是 `order_no`（**無 `{MERCHANT_CODE}_` 前綴**）；回調回傳同一值，直接作為內部訂單號查單。

#### codebase 實際組裝

```php
$params = [
    'merchant_code'  => $this->info['MERCHANT_CODE'],
    'source_id'      => $order->order_no,       // 訂單號（純 order_no，無前綴）
    'full_name'      => $member->username,
    'customer'       => $member->id,
    'payment_method' => $paymentMethod,          // 父層 OrderType key（'Bank' 或 'QR'）
    'amount'         => $order->amount,
    'currency'       => $this->info['CURRENCY'] ?? 'MYR',
    'callback_url'   => $callbackUrl,
    'redirect_url'   => $returnUrl,
];

// 若有子渠道（銀行選項）則加入 bank_code
if ($parentOrderType) {
    $params['bank_code'] = $order->orderType->key;  // 子層 OrderType key（如 'HLB'、'DUITNOW'）
}
```

#### 成功響應

```json
{
    "success": true,
    "data": {
        "tx_id": "123456",
        "url": "https://example.invalid/pay/xxxxxx"
    }
}
```

| 欄位 | 說明 |
|---|---|
| `success` | `true` 表示 API 受理成功 |
| `data.tx_id` | AkPay 三方訂單號，儲存至 `third_party_order_no` |
| `data.url` | 收銀台 URL，導向此 URL 讓使用者完成付款 |

#### 失敗響應

```json
{
    "success": false,
    "message": "Invalid merchant code",
    "data": {
        "field_name": ["錯誤訊息"]
    }
}
```

> 失敗時，`data` 可能包含逐欄位的驗證錯誤陣列；`message` 為通用錯誤訊息。codebase 取 `data` 第一個欄位的第一條錯誤，再 fallback 至 `message`。

---

## 5. 代付 API

### 5.1 建立代付訂單 — `POST /api/withdrawal/create`

**完整 URL（生產）**：`https://example.invalid/api/withdrawal/create`

#### 請求參數

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `merchant_code` | ✅ | string | 商戶代碼（`{MerchantCode}`） |
| `source_id` | ✅ | string | 商戶代付交易號（系統產生，前綴 `AK_`） |
| `payment_method` | ✅ | string | 固定值 `Bank` |
| `bank_code` | ✅ | string | 目標銀行代碼（見銀行代碼映射） |
| `account_holder_name` | ✅ | string | 收款人姓名 |
| `account_number` | ✅ | string | 收款銀行帳號 |
| `currency` | ✅ | string | 幣別代碼（目前僅 `MYR`） |
| `amount` | ✅ | string | 代付金額（格式 `sprintf('%.2f', $amount)`，保留 2 位小數） |
| `callback_url` | ✅ | string | 回調通知 URL |

> `source_id` 由 `Common::genTransactionNo('AK_')` 產生，格式為 `AK_{隨機字串}`。

#### codebase 實際組裝

```php
$params = [
    'merchant_code'       => $this->info['MERCHANT_CODE'],
    'source_id'           => $transactionNo,                       // AK_ 前綴交易號
    'payment_method'      => 'Bank',
    'bank_code'           => $this->convertPayoutBankCode($withdrawalOrder->bank_code),
    'account_holder_name' => $withdrawalOrder->account_name,
    'account_number'      => $withdrawalOrder->account_no,
    'currency'            => $this->info['CURRENCY'] ?? 'MYR',
    'amount'              => sprintf('%.2f', $withdrawalOrder->amount),
    'callback_url'        => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
];
```

#### 成功響應

```json
{
    "success": true,
    "data": {
        "tx_id": "789012"
    }
}
```

| 欄位 | 說明 |
|---|---|
| `success` | `true` 表示代付請求已受理（非同步處理） |
| `data.tx_id` | AkPay 三方代付交易號 |

#### 失敗響應

```json
{
    "success": false,
    "message": "Insufficient balance",
    "data": {
        "field_name": ["錯誤訊息"]
    }
}
```

> 代付為**非同步**處理，API 受理成功後最終結果透過回調通知。

---

### 5.2 商戶餘額查詢（參考）— `GET /api/merchant/balance`

**完整 URL（生產）**：`https://example.invalid/api/merchant/balance`

> 此接口由工單 #3592 列出，codebase 未整合主動查詢邏輯。帶入 Bearer Token 即可查詢商戶可用餘額，供維運監控使用。

---

## 6. 回調處理

### 6.1 代收回調

AkPay 在支付完成後，以 **HTTP POST JSON** 格式推送至建立訂單時傳入的 `callback_url`。

#### 回調欄位

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `source_id` | ✅ | string | 商戶訂單號（格式含商戶前綴） |
| `tx_id` | ✅ | integer | AkPay 三方訂單號 |
| `status` | ✅ | string | 訂單狀態（見狀態碼說明） |
| `bank_code` | ✅ | string | 實際使用的銀行/QR 代碼 |
| `amount` | ✅ | string | 訂單金額（商戶請求金額） |
| `actual_amount` | ✅ | string | 實際到帳金額 |
| `currency` | ✅ | string | 幣別 |
| `secret_key` | ✅ | string | MD5 簽名（見簽名驗證流程） |

#### 代收狀態碼

| `status` | 說明 | 終態 | 內部處理 |
|---|---|---|---|
| `Completed` | 支付成功 | ✅ | 更新訂單為成功 |
| 其他 | 支付失敗/拒絕 | ✅ | 更新訂單為失敗 |

> 成功判定：`status === 'Completed'`

#### 回調響應

商戶需回傳 JSON：

```json
{ "success": true }
```

> 失敗或異常時回傳：`{ "success": false }`

#### 代收回調範例

```json
{
    "source_id": "TPOWERMYR_DP20260301001",
    "tx_id": 123456,
    "status": "Completed",
    "bank_code": "DUITNOW",
    "amount": "100.00",
    "actual_amount": "100.00",
    "currency": "MYR",
    "secret_key": "d41d8cd98f00b204e9800998ecf8427e"
}
```

---

### 6.2 代付回調

AkPay 在代付完成後，以 **HTTP POST JSON** 格式推送至建立代付時傳入的 `callback_url`。

#### 回調欄位

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `source_id` | ✅ | string | 商戶代付交易號（`AK_` 前綴） |
| `tx_id` | ✅ | string | AkPay 三方代付交易號 |
| `status` | ✅ | string | 代付狀態（見狀態碼說明） |
| `bank_code` | ✅ | string | 目標銀行代碼 |
| `amount` | ✅ | string | 代付金額 |
| `currency` | ✅ | string | 幣別 |
| `secret_key` | ✅ | string | MD5 簽名（見簽名驗證流程） |

> 注意：代付回調的 `tx_id` 類型為 **string**（代收為 integer），驗證規則略有不同。

#### 代付狀態碼

| `status` | 說明 | 終態 | 內部處理 |
|---|---|---|---|
| `Approved` | 代付成功 | ✅ | 更新訂單為成功，驗證金額 |
| 其他 | 代付失敗/拒絕 | ✅ | 更新訂單為失敗（失敗時不驗金額） |

> 成功判定：`status === 'Approved'`

#### 代付回調響應

```json
{ "success": true }
```

> 代付成功時才驗證金額是否與訂單一致；失敗狀態下金額可能不準確，跳過金額驗證。

---

### 6.3 回調冪等性保護

- 代收回調：依 `source_id` 去除商戶前綴後查詢訂單，`lockForUpdate()` 加鎖，呼叫 `checkDepositOrderStatus()` 防止重複處理。
- 代付回調：依 `source_id`（`AK_` 交易號）查詢 PayoutRecord，`lockForUpdate()` 加鎖，若已處理直接回傳 `{ "success": true }`。

---

## 7. 簽名（secret\_key）驗證流程

AkPay 使用 **MD5** 雜湊作為回調簽名機制，代收與代付使用相同算法。

### 簽名算法

**拼接欄位**（順序固定）：

```
source_id + tx_id + amount + currency + {MERCHANT_TOKEN}
```

**算法**：

```
secret_key = [REDACTED]
    'source_id' => {source_id},
    'tx_id'     => {tx_id},
    'amount'    => {amount},
    'currency'  => {currency},
    'token'     => {MERCHANT_TOKEN},
])))
```

> 使用 PHP `http_build_query()` 將 key-value 陣列轉為 URL 編碼字串，再 `urldecode()` 解碼，最後取 MD5。`token` 欄位填入商戶 Bearer Token（`MERCHANT_TOKEN`）。

### PHP 實作參考

```php
protected function generateSecretKey(array $params, string $token): string
{
    $encrypt = [
        'source_id' => $params['source_id'],
        'tx_id'     => $params['tx_id'],
        'amount'    => $params['amount'],
        'currency'  => $params['currency'],
        'token'     => $token,
    ];

    return md5(urldecode(http_build_query($encrypt)));
}

// 回調驗證
protected function checkSecretKey(array $params, string $token): bool
{
    $expected = $this->generateSecretKey($params, $token);
    return hash_equals($params['secret_key'], $expected);
}
```

### 驗證步驟

1. 從回調請求取得 `source_id`、`tx_id`、`amount`、`currency`、`secret_key`
2. 使用 `MERCHANT_TOKEN` 與上述欄位計算 `expected_secret_key`
3. 以 `hash_equals()` 比對回調中的 `secret_key` 與 `expected_secret_key`
4. 比對通過才繼續處理訂單；不符合則拋出 `ApiException('Signature verification failed')`

> 代收與代付回調的簽名計算方式完全相同，差別僅在欄位中的 `tx_id` 類型（代收為 integer，代付為 string）。

---

## 8. 銀行代碼映射

### 8.1 MYR — 代收 bank\_code（Online Banking）

| bank\_code | 銀行名稱 |
|---|---|
| `HLB` | Hong Leong Bank |
| `MBB` | Maybank Berhad |
| `PBB` | Public Bank |
| `RHB` | RHB Bank |
| `CIMB` | CIMB Bank |
| `BSN` | Bank Simpanan Nasional |
| `BMMB` | Bank Muamalat |
| `AFB` | Affin Bank |

### 8.2 MYR — 代收 bank\_code（QR Code）

| bank\_code | 說明 |
|---|---|
| `TNGQR` | Touch n Go QR |
| `DUITNOW` | DuitNow QR（線上主要開放渠道） |
| `DUITNOWEWALLET` | DuitNow eWallet |

### 8.3 MYR — 代付 bank\_code

工單 #3124 / #2740 列出的官方代付銀行代碼：

| bank\_code | 銀行名稱 |
|---|---|
| `HLB` | Hong Leong Bank |
| `MBB` | Maybank |
| `PBB` | Public Bank |
| `RHB` | RHB Bank |
| `CIMB` | CIMB Bank |
| `AFB` | Affin Bank |
| `HSBC` | HSBC Bank |
| `OCBC` | OCBC Bank |
| `SCBMY` | Standard Chartered Bank |
| `BSN` | Bank Simpanan Nasional |
| `AMBB` | AmBank |
| `BIMB` | Bank Islam |
| `ABMB` | Alliance Bank |
| `TNG` | Touch n Go |
| `GX` | GX Bank |
| `AEON` | AEON Bank |
| `BOOSTBANK` | Boost Bank |
| `MERCHANTRADE` | Merchantrade |
| `BIGPAY` | BigPay |
| `BMMB` | Bank Muamalat |
| `MBSB` | MBSB Bank |
| `AGRO` | AGRO Bank |
| `RYT` | Ryt Bank |

> 以下為工單 #3124 / #2740 記錄的舊版代付代碼（可能對應不同接入版本）：
>
> | 舊代碼 | 銀行名稱 |
> |---|---|
> | `AFIN` | Affin Islamic Bank |
> | `ALLI` | Alliance Bank |
> | `HOLB` | Hong Leong Bank |
> | `MAYB` | Maybank |
> | `MUAM` | Bank Muamalat |
> | `MYAB` | AmBank |
> | `MYIS` | Bank Islam |
> | `PBBB` | Public Bank |
> | `RHBB` | RHB Bank |
> | `SINA` | Bank Simpanan Nasional |
> | `SCB` | Standard Chartered Bank |
> | `TNGP` | Touch 'n Go |
> | `GXBK` | GX Bank |

---

## 9. 限額參考

以下為工單記錄的典型值（#2740），實際限額以 AkPay 後台商戶設定為準：

| 類型 | 幣別 | 最低 | 最高 |
|---|---|---|---|
| 代收 | MYR | 50 | 10,000 |
| 代付 | MYR | 50 | 10,000 |

---

## 10. 參考資料

| 資料來源 | 說明 |
|---|---|
| [官方 API 文檔（Postman）](https://example.invalid/view/22077629/2s9Ykt4ycb) | AkPay 官方完整規格（需瀏覽器開啟，WebFetch 無法渲染 JS） |
| GitLab #3592 | [APIs] AKPay-MYR 代收接入工單（含官方 API 文檔 URL、各端點列表） |
| GitLab #3593 | [TPower] AkPay-MYR 代收配置工單 |
| GitLab #3596 | [Yes2win] AkPay-MYR 代收配置工單 |
| GitLab #3124 | [TPower] AKPay MYR 代付配置工單（含代付銀行代碼） |
| GitLab #2740 | [Yes2Win] AKPay MYR 對接工單（含代收/代付銀行代碼、限額） |

### 內部實作路徑

```
app/Services/Payment/Providers/AkPay/
├── Driver.php              # 組合代收/代付子類別
├── Deposit.php             # 代收實作，呼叫 /api/order/fundin
├── DepositCallback.php     # 代收回調處理，含 secret_key 簽名驗證
├── Withdrawal.php          # 代付實作，呼叫 /api/withdrawal/create（前綴 AK_）
├── WithdrawalCallback.php  # 代付回調處理，Approved 判定，含金額驗證
└── AkPayTrait.php          # 共用 HTTP 發送（Bearer Token）、MD5 簽名生成/驗證
```

### config/payment.php 設定 key

```
AKPAY => App\Services\Payment\Providers\AkPay\Driver::class
```

### 商戶配置項目（agent\_cash\_flows.info）

| Key | 說明 |
|---|---|
| `API_URL` | API Base URL（`https://example.invalid`） |
| `MERCHANT_CODE` | 商戶代碼（如 `TPOWERMYR`） |
| `MERCHANT_TOKEN` | Bearer Token（簽名驗證亦使用此值作為 `token` 欄位） |
| `CURRENCY` | 幣別（預設 `MYR`） |

---

*文件版本：1.0 / 整理日期：2026-06-01*
*資料來源：GitLab 工單 #3592、#3593、#3596、#3124、#2740、Codebase 實作（app/Services/Payment/Providers/AkPay/）*
*官方 API 文檔（Postman Documenter）WebFetch 因 JavaScript 渲染限制無法抓取，規格細節以 codebase 實作為準*
