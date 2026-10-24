# PayPro 廠商規格

> 官方 API 文檔：https://docs.paypro.tech/guide/areas/indonesia/overview.html
> 內部實作路徑：`app/Services/Payment/Providers/PayPro/`

PayPro 是面向東南亞市場的第三方支付平台，目前系統整合**印尼（IDR）**通道，提供代收（Deposit）與代付（Withdrawal）。支援 QRIS 掃碼、Virtual Account（VA）及電子錢包（EWallet）等多種支付方式。所有請求使用 **JSON** 格式（`application/json`），身份驗證透過 HTTP Header 帶入商戶號，簽名採用 **MD5** 算法。

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [代收 API](#3-代收-api)
4. [代付 API](#4-代付-api)
5. [回調（Callback）](#5-回調callback)
6. [簽名流程](#6-簽名流程)
7. [銀行代碼映射](#7-銀行代碼映射)
8. [參考資料](#8-參考資料)

---

## 1. 環境資訊

| 項目 | 值 |
|---|---|
| 沙盒 API Base URL | `https://sandbox-api.paypro.tech/indonesia-pay` |
| 生產 API Base URL | `https://openapi.paypro.tech/indonesia-pay` |
| 商戶後台（生產） | `https://partner.paypro.tech` |
| 請求格式 | JSON（`application/json`） |
| 響應格式 | JSON |
| 認證方式 | HTTP Header `merchantNo` + MD5 簽名（`sign` 欄位） |

> `API_URL` 在 `example.invalid` 中設定，`PayProTrait::sendRequest()` 會自動拼接路徑。

---

## 2. 支援的支付方式與幣別

### IDR（印尼盾）

| 支付類型 | channelCode | wayCode | 說明 |
|---|---|---|---|
| QRIS 掃碼 | `QRIS` | `QRIS` | 通用 QR 掃碼（最常用） |
| Virtual Account | `VA` | 各銀行代碼 | 銀行虛擬帳號轉帳 |
| 電子錢包 DANA | `EWALLET` | `DANA` | DANA 錢包（需跳轉 URL） |
| 電子錢包 GoPay | `EWALLET` | `GOPAY` | GoPay 錢包 |
| 電子錢包 OVO | `EWALLET` | `OVO` | OVO 錢包 |
| 電子錢包 ShopeePay | `EWALLET` | `SHOPEEPAY` | ShopeePay 錢包 |
| 電子錢包 LinkAja | `EWALLET` | `LINKAJA` | LinkAja 錢包 |

> **代收限額（依工單記錄）**：
> - QRIS：單筆 10,000 – 10,000,000 IDR
> - VA：單筆 10,000 – 20,000,000 IDR
> - EWallet：單筆 10,000 – 10,000,000 IDR（LinkAja 上限較低）
>
> **代付限額（依工單記錄）**：
> - 銀行卡：單筆 10,000 – 50,000,000 IDR
> - EWallet（Dana/OVO/GoPay/ShopeePay）：單筆 10,000 – 20,000,000 IDR
> - EWallet（LinkAja）：單筆 10,000 – 10,000,000 IDR

---

## 3. 代收 API

### 代收發起 — `POST /v1/paypro/paymentLink`

> 注意：codebase 使用 `/v1/paypro/paymentLink`（收銀台連結模式），官方文檔另有 `/v1/paypro/payIn`（直連模式）。兩者參數結構相同，差異在回應：`paymentLink` 回傳收銀台 URL；`payIn` 回傳 qrString / vaNumber 等。

**請求 Header**

| Header | 必填 | 說明 |
|---|---|---|
| `merchantNo` | ✅ | 商戶號（10 位數字） |
| `Content-Type` | ✅ | `application/json` |

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `timestamp` | ✅ | string | 毫秒時間戳（如 `1732988442913`） |
| `tradeNo` | ✅ | string | 商戶訂單號（系統內唯一，對應 `order.order_no`） |
| `username` | ✅ | string | 會員帳號（`member.username`） |
| `mobile` | ✅ | string | 手機號（印尼格式）；⚠️ **codebase 寫死固定佔位 `62812345678`**，未取會員真實手機 |
| `email` | ✅ | string | 電子郵件；⚠️ **codebase 寫死固定佔位 `user@example.invalid`**，未取會員真實 email |
| `channelCode` | ✅ | string | 支付通道代碼（如 `QRIS`、`VA`、`EWALLET`） |
| `wayCode` | - | string | 支付方式代碼（如 `QRIS`、`DANA`，細分通道時填入） |
| `amount` | ✅ | float | 訂單金額（整數，不支援小數） |
| `notifyUrl` | ✅ | string | 代收回調 URL |
| `returnUrl` | - | string | 支付完成後跳轉 URL |
| `remark` | - | string | 備註（回應中會原樣回傳） |
| `sign` | ✅ | string | MD5 簽名（見第 6 節，由 `generateSign()` 產生，最後帶入） |

> `channelCode` 與 `wayCode` 的關係：`channelCode` 為父層通道，`wayCode` 為子層方式。codebase 中 `channelCode` 取自 `orderType.parentOrderType.key`（若有父類型），`wayCode` 取自 `orderType.key`。

**請求範例**

```json
{
    "timestamp": "1732988442913",
    "tradeNo": "ORDER20260201001",
    "username": "player001",
    "mobile": "6281234567890",
    "email": "user@example.invalid",
    "channelCode": "QRIS",
    "wayCode": "QRIS",
    "amount": 100000,
    "notifyUrl": "https://example.invalid/callback/paypro/deposit",
    "returnUrl": "https://example.invalid/return",
    "sign": "{MD5_SIGNATURE}"
}
```

**成功響應**

```json
{
    "msg": "success",
    "code": "0000",
    "timestamp": 1728609277476,
    "success": true,
    "data": {
        "tradeNo": "ORDER20260201001",
        "platFormTradeNo": "E000czqcfalkxyti0pz4_da",
        "status": "0015",
        "paymentUrl": "https://pay.paypro.tech/cashier?token=[REDACTED]",
        "desc": ""
    }
}
```

**失敗響應範例**

```json
{
    "msg": "Invalid sign",
    "code": "9999",
    "success": false,
    "data": null
}
```

**成功判定**：`code === '0000'` 才視為受理；若 `status === '00029'` 或 `paymentUrl` 為空則拋出例外。

**響應欄位說明**

| 欄位 | 說明 |
|---|---|
| `data.platFormTradeNo` | PayPro 平台訂單號，存入 `order.third_party_order_no` |
| `data.paymentUrl` | 收銀台 URL（DANA/LinkAja 等跳轉用） |
| `data.qrString` | QR 碼字串（QRIS 直連模式） |
| `data.vaNumber` | 虛擬帳號號碼（VA 直連模式） |
| `data.status` | 初始訂單狀態（通常為 `0015` 處理中） |

---

## 4. 代付 API

### 代付發起 — `POST /v1/paypro/payOut`

**請求 Header**

| Header | 必填 | 說明 |
|---|---|---|
| `merchantNo` | ✅ | 商戶號 |
| `Content-Type` | ✅ | `application/json` |

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `timestamp` | ✅ | string | 毫秒時間戳 |
| `tradeNo` | ✅ | string | 商戶代付流水號（由 `Common::genTransactionNo('PayPro')` 生成） |
| `amount` | ✅ | float | 代付金額（整數，不支援小數） |
| `customerName` | ✅ | string | 收款人姓名（需與銀行帳號一致） |
| `mobile` | ✅ | string | 收款人手機號（印尼格式）；⚠️ **codebase 寫死固定佔位 `62812345678`** |
| `email` | ✅ | string | 收款人電子郵件；⚠️ **codebase 寫死固定佔位 `user@example.invalid`** |
| `bankAccountNo` | ✅ | string | 收款銀行帳號 |
| `bankCode` | ✅ | string | 收款銀行代碼（見第 7 節，由 `convertPayoutBankCode()` 從內部代碼轉換） |
| `notifyUrl` | - | string | 代付結果回調 URL |
| `remark` | - | string | 備註 |
| `sign` | ✅ | string | MD5 簽名（見第 6 節） |

**請求範例**

```json
{
    "timestamp": "1732988500000",
    "tradeNo": "PayPro20260201ABC123",
    "amount": 50000,
    "customerName": "John Doe",
    "mobile": "6281234567890",
    "email": "user@example.invalid",
    "bankAccountNo": "1234567890",
    "bankCode": "0001",
    "notifyUrl": "https://example.invalid/callback/paypro/withdrawal",
    "sign": "{MD5_SIGNATURE}"
}
```

**成功響應**

```json
{
    "msg": "success",
    "code": "0000",
    "success": true,
    "timestamp": 1728609300000,
    "data": {
        "tradeNo": "PayPro20260201ABC123",
        "platFormTradeNo": "Y000ewzvrwsyivl7rgjx_da",
        "status": "0015",
        "desc": ""
    }
}
```

**失敗響應範例**

```json
{
    "msg": "Insufficient balance",
    "code": "1001",
    "success": false,
    "data": null
}
```

**成功判定**：`code === '0000'` 才視為受理成功；`data.platFormTradeNo` 存入 `withdrawal_order.third_party_order_no`。代付為**非同步**處理，最終結果透過回調通知。

---

## 5. 回調（Callback）

PayPro 在支付完成後以 **HTTP POST JSON** 格式推送通知，merchant 必須在 **24 小時內**正確響應，否則平台每隔一段時間重試（重試排程：5s / 10s / 1m / 10m / 30m / 1h / 3h / 3h，共 8 次）。

### 5.1 代收回調（Deposit Notify）

**回調欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `platFormTradeNo` | ✅ | string | PayPro 平台訂單號 |
| `tradeNo` | ✅ | string | 商戶訂單號（用於查單） |
| `amount` | ✅ | numeric | 實際支付金額（可能為部分支付） |
| `status` | ✅ | string | 訂單狀態碼（見下方狀態表） |
| `message` | - | string | 狀態描述 |
| `timestamp` | ✅ | string | 毫秒時間戳 |
| `channelCode` | - | string | 支付通道代碼（僅代收回調存在） |
| `wayCode` | - | string | 支付方式代碼（僅代收回調存在） |
| `successTime` | - | string | 成功時間（失敗時不存在，格式 `YYYY-MM-DD HH:mm:ss`） |
| `rrn` | - | string | 外部參考號 |
| `sign` | ✅ | string | MD5 簽名（需驗簽） |

**代收狀態碼（多態邏輯）**

| status | 說明 | 內部處理 |
|---|---|---|
| `0000` | 支付成功（終態） | 更新訂單為**成功** |
| `0001` | 部分支付（終態，視為成功） | 使用 `amount` 實際金額，更新訂單為**成功** |
| `0015` | 處理中（非終態） | **不更新**訂單狀態，回傳 `OK` 等待下一次回調 |
| 其他 | 支付失敗（終態） | 更新訂單為**失敗** |

> `0015` 為保留狀態（Processing），收到時僅記錄日誌，不改變訂單狀態，仍回傳 `OK`。

**回調範例**

```json
{
    "tradeNo": "ORDER20260201001",
    "message": "Mock success status",
    "wayCode": "QRIS",
    "status": "0000",
    "channelCode": "QRIS",
    "amount": 100000,
    "timestamp": "1770101776756",
    "platFormTradeNo": "E000czqcfalkxyti0pz4_da",
    "sign": "05e162af807f112e4ee1dc5ba05e8c78",
    "successTime": "2026-02-03 14:56:16"
}
```

**回調響應**：回傳純文字 `OK` 表示已成功接收；`FAIL` 表示處理失敗（可能觸發重試）。

---

### 5.2 代付回調（Withdrawal Notify）

**回調欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `platFormTradeNo` | ✅ | string | PayPro 平台代付訂單號 |
| `tradeNo` | ✅ | string | 商戶代付流水號（對應 payout_record 查單依據） |
| `amount` | ✅ | numeric | 代付金額（失敗時金額可能不準確，不建議用於失敗校驗） |
| `status` | ✅ | string | 代付狀態碼（見下方狀態表） |
| `message` | - | string | 狀態描述 |
| `timestamp` | ✅ | string | 毫秒時間戳 |
| `successTime` | - | string | 成功時間（失敗時不存在） |
| `sign` | ✅ | string | MD5 簽名（需驗簽） |

**代付狀態碼（二分邏輯）**

| status | 說明 | 內部處理 |
|---|---|---|
| `0000` | 代付成功（終態） | 更新訂單為**成功** |
| `0015` | 處理中（非終態） | 不在 codebase 代付回調中特別處理（視為失敗） |
| 其他 | 代付失敗（終態） | 更新訂單為**失敗** |

> 代付回調：`isSuccessPayout = (status === '0000')`；只有成功時才驗證金額是否與訂單一致。`successTime` 儲存前會換算時區（CST `Asia/Taipei` → 系統時區）。

**回調範例**

```json
{
    "sign": "4b51dc9b09d17c8f23244f95b50c5e1f",
    "timestamp": "1770108032294",
    "status": "0000",
    "tradeNo": "PayPro2026020308342212734",
    "message": "Mock success status",
    "platFormTradeNo": "Y000ewzvrwsyivl7rgjx_da",
    "amount": 10000,
    "successTime": "2026-02-03 16:40:32"
}
```

**回調響應**：回傳純文字 `OK`；`FAIL` 表示失敗。

---

### 5.3 回調冪等性

代收與代付回調均有冪等性保護：
- 代收：`findAndLockDepositOrder()` + `checkDepositOrderStatus()`，已處理訂單直接回傳成功。
- 代付：`findAndLockPayoutRecord()` + `isPayoutRecordProcessed()`，已處理記錄直接回傳成功。

---

## 6. 簽名流程

PayPro 使用 **MD5** 算法對所有請求參數進行簽名，驗簽時亦使用相同算法。

### 6.1 簽名生成步驟

1. **排除** `sign` 欄位，**過濾**空值（`null` 和 `''`，但保留 `0` 和 `'0'`）
2. 將剩餘參數按 **key 字母升冪**（`a-z`）排序（`ksort`）
3. 拼接成 `key1=value1key2=value2...` 格式（無分隔符）
4. 在字串尾部附加商戶密鑰（`MERCHANT_KEY`）作為 salt
5. 對整個字串進行 **MD5 加密**，輸出**小寫**十六進位字串

### 6.2 PHP 實作參考

```php
protected function generateSign(array $params): string
{
    // 排除 sign 欄位
    unset($params['sign']);

    // 過濾空值（保留 0 和 '0'）
    $filtered = array_filter($params, function ($value) {
        return $value !== null && $value !== '';
    });

    // 按 key 字母升冪排序
    ksort($filtered);

    // 拼接 key=value
    $signString = '';
    foreach ($filtered as $key => $value) {
        $signString .= $key . '=' . $value;
    }

    // 附加 MERCHANT_KEY 作為 salt
    $signString .= $this->info['MERCHANT_KEY'];

    // MD5 加密並轉小寫
    return strtolower(md5($signString));
}
```

### 6.3 簽名範例

假設請求參數為：

```
amount=100000
channelCode=QRIS
email=user@example.invalid
mobile=6281234567890
notifyUrl=https://example.invalid/callback
timestamp=1732988442913
tradeNo=ORDER20260201001
username=player001
wayCode=QRIS
```

拼接字串：
```
amount=100000channelCode=QRISemail=user@example.invalid=6281234567890notifyUrl=https://example.invalid/callbacktimestamp=1732988442913tradeNo=ORDER20260201001username=player001wayCode=QRIS{MERCHANT_KEY}
```

Sign = `strtolower(md5(拼接字串))`

### 6.4 回調驗簽

回調接收後使用 `verifySign()` 驗證：以回調全部參數（含 `sign`）重新計算，與回調中的 `sign` 比對（使用 `hash_equals` 防時序攻擊）。

---

## 7. 銀行代碼映射

> **代付銀行代碼（`bankCode`）使用 PayPro 官方數字碼**，由 `convertPayoutBankCode()` 從系統內部代碼（`BankCode_ELG`）轉換後帶入請求。以下列出常用銀行及電子錢包代碼。

### IDR — 主要銀行（代付 `bankCode`）

| bankCode | 銀行名稱 | 英文全名 |
|---|---|---|
| `0001` | Bank BRI | Bank Rakyat Indonesia |
| `0002` | Bank Mandiri | Bank Mandiri (Persero) Tbk |
| `0003` | Bank BNI | Bank Negara Indonesia |
| `0004` | Bank Danamon | Bank Danamon Indonesia Tbk |
| `0005` | Bank Permata | PT Bank Permata Tbk |
| `0007` | Bank BCA | Bank Central Asia Tbk |
| `0008` | BII Maybank | PT Bank Internasional Indonesia Tbk |
| `0010` | CIMB Niaga | PT Bank CIMB Niaga Tbk |
| `0011` | Bank UOB Indonesia | PT Bank UOB Buana Tbk |
| `0012` | Bank OCBC NISP | PT Bank OCBC NISP Tbk |
| `0013` | Citibank | Citibank N.A. |
| `0018` | Bank DBS | DBS Bank Ltd. |
| `0020` | Bank Mizuho Indonesia | Bank Mizuho Indonesia |
| `0021` | Standard Chartered | Standard Chartered Bank |
| `0023` | Bank BNP Paribas | Bank BNP Paribas Indonesia |
| `0024` | ANZ Indonesia | PT ANZ Panin Bank |
| `0025` | Bank of China | Bank of China Limited |
| `0027` | Bank HSBC | HSBC Bank (Indonesia) |
| `0072` | Bank ICBC | Industrial and Commercial Bank of China (ICBC) |
| `0074` | Bank BTN | Bank Tabungan Negara |
| `0079` | Bank Mega | Bank Mega Tbk |
| `0086` | Bank BRI Agroniaga | Bank Rakyat Indonesia (Agroniaga) |
| `0105` | CNB | Central Nasional Bank |
| `0113` | Bank Commonwealth | Commonwealth Bank of Australia |

> 完整 113 筆銀行清單請參閱官方 API 文檔 [Bank List](https://docs.paypro.tech/guide/areas/indonesia/bankList.html)。

### IDR — 電子錢包（代付 `bankCode`）

| bankCode | 錢包名稱 |
|---|---|
| `dana` | Dana |
| `gopay` | GoPay |
| `linkaja` | LinkAja |
| `ovo` | OVO |
| `shopeepay` | ShopeePay |

> 工單（#2663）中另有一套舊版英文大寫代碼格式（`BRI`、`MANDIRI`、`BNI` 等），此為早期版本，官方文檔數字碼為當前標準，以官方文檔數字碼為準。

### 代收 channelCode / wayCode 參考

| channelCode | wayCode | 說明 |
|---|---|---|
| `QRIS` | `QRIS` | QRIS 掃碼代收 |
| `VA` | 銀行 VA 代碼 | Virtual Account |
| `EWALLET` | `DANA` | DANA 電子錢包 |
| `EWALLET` | `GOPAY` | GoPay 電子錢包 |
| `EWALLET` | `OVO` | OVO 電子錢包 |
| `EWALLET` | `SHOPEEPAY` | ShopeePay 電子錢包 |
| `EWALLET` | `LINKAJA` | LinkAja 電子錢包 |

---

## 8. 費率參考（工單記錄值）

以下費率為工單記錄的典型值，各商戶實際費率以後台設定為準。

### IDR

| 類型 | 費率 | 說明 |
|---|---|---|
| 代收 QRIS | 1.70% | 見工單 #2663 / #2968 / #2969 |
| 代收 VA | 1.10% + 5,000 IDR | 見工單 #2968 / #2969 |
| 代付 | 0.60% + 5,000 IDR | 見工單 #2968 / #2969 |

---

## 回調 IP 白名單

| 環境 | Callback IP |
|---|---|
| 生產環境 | `0.0.0.0` |

> 來源：工單 #2968、#2969。IP 白名單需向 PayPro 申請設定，部署時須確認伺服器對外 IP 已加入白名單。

---

## 參考資料

| 資料來源 | 說明 |
|---|---|
| [官方 API 文檔（Indonesia）](https://docs.paypro.tech/guide/areas/indonesia/overview.html) | PayPro 印尼完整 API 規格 |
| [代收接口](https://docs.paypro.tech/guide/areas/indonesia/payment.html) | Payment / PayIn 詳細規格 |
| [代付接口](https://docs.paypro.tech/guide/areas/indonesia/payout.html) | PayOut 詳細規格 |
| [回調通知](https://docs.paypro.tech/guide/areas/indonesia/paymentNotify.html) | Notify 欄位與重試策略 |
| [銀行代碼列表](https://docs.paypro.tech/guide/areas/indonesia/bankList.html) | 完整 113 筆銀行 + 電子錢包代碼 |
| GitLab #3446 | [GFF][APIs] PayPro IDR 代收代付接入（開發工單，含沙盒資訊） |
| GitLab #3502 | [GFF][Alfawin77] PayPro IDR 代收代付配置 |
| GitLab #3385 | [GFF][TP][APIs] PayPro IDR 代收代付接入 |
| GitLab #2968 | [GFF][TP] PayPro IDR 代收代付配置（含費率、限額、回調 IP） |
| GitLab #2969 | [GFF][YES2WIN] PayPro IDR 代收代付配置（含費率、限額、回調 IP） |
| GitLab #2663 | [GFF][AW77] PayPro 代收代付（含早期銀行代碼列表） |

### 內部實作路徑

```
app/Services/Payment/Providers/PayPro/
├── Driver.php              # 組合代收/代付子類別，掛載到 PAYPRO config key
├── Deposit.php             # 代收實作（POST /v1/paypro/paymentLink）
├── DepositCallback.php     # 代收回調處理（多態狀態：0000/0001=成功，0015=保留，其他=失敗）
├── Withdrawal.php          # 代付實作（POST /v1/paypro/payOut）
├── WithdrawalCallback.php  # 代付回調處理（二分狀態：0000=成功，其他=失敗）
└── PayProTrait.php         # 共用：HTTP 請求（Header merchantNo）、MD5 簽名生成/驗證、時間戳
```

**example.invalid 設定格式**

```json
{
    "API_URL": "https://openapi.paypro.tech/indonesia-pay",
    "MERCHANT_NO": "{Merchant_No}",
    "MERCHANT_KEY": "{Merchant_Key}"
}
```

---

*文件版本：1.0 / 整理日期：2026-06-01*
*資料來源：官方 API 文檔、GitLab 工單 #2663、#2968、#2969、#3385、#3446、#3502、Codebase 實作*
