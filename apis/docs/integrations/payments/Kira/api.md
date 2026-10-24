# Kira 廠商規格

> 官方文檔：https://doc.kira.asia
> 內部實作路徑：`app/Services/Payment/Providers/Kira/`

Kira 是面向馬來西亞市場的第三方支付平台，支援馬幣（MYR）的代收（Deposit）與代付（Withdrawal）。代收支援 FPX 網路銀行、FPX 企業銀行及電子錢包；代付透過銀行帳號直連各家馬來西亞銀行。

**注意事項**：
- 請勿將 KIRA 支付頁面嵌入 iframe，否則將導致銀行支付頁面跳轉失敗
- 首次登入商戶後台時，需使用驗證器應用程式設定雙重驗證（2FA）
- API 憑證從商戶後台 > Development Settings 取得

**安全機制總覽**：
- 認證：RSA 非對稱加密（PKCS#1 Padding）取得 Access Token，Token 以 AES-256-CBC 加密回傳
- 業務請求：payload 先排序、Base64 編碼，再以 AES-256-CBC 加密後傳送，回應同樣加密
- 請求防偽：RSA SHA-256 簽名帶入 `X-Signature` header
- 回調驗證：回調 body 以 AES-256-CBC 加密的 `encryptedData`，不使用額外 HMAC/MD5

---

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [認證流程（Access Token）](#3-認證流程access-token)
4. [請求簽名算法](#4-請求簽名算法)
5. [AES 加解密規格](#5-aes-加解密規格)
6. [代收 API](#6-代收-api)
7. [代付 API](#7-代付-api)
8. [回調處理](#8-回調處理)
9. [銀行代碼映射（MYR 代付）](#9-銀行代碼映射myr-代付)
10. [錯誤碼與狀態碼](#10-錯誤碼與狀態碼)
11. [參考資料](#11-參考資料)

---

## 1. 環境資訊

| 項目 | 生產環境 | 測試環境 |
|---|---|---|
| API Base URL | `https://api.kira.asia` | `https://api-stag.kira.asia` |
| 商戶後台 | `https://bo.kira.asia` | `https://bo-stag.kira.asia` |
| 請求格式 | JSON | JSON |
| 響應格式 | JSON（body 內含 AES 加密 `encryptedData`） | 同左 |

**Kira 回調 IP 白名單**：

| 環境 | IP |
|---|---|
| 生產 & 測試（共用） | `0.0.0.0` |

> 需將上述 IP 加入系統白名單，以接收回調通知。

**必要憑證（由商戶後台 Development Settings 取得）**：

| 憑證欄位（config key） | 說明 |
|---|---|
| `API_URL` | API Base URL |
| `CLIENT_ID` | 商戶識別碼 |
| `CLIENT_SECRET` | 商戶金鑰 |
| `SERVER_PUBLIC_KEY` | Kira 伺服器 RSA 公鑰（用於加密 credentials） |
| `CLIENT_SYMMETRIC_KEY` | 商戶 AES 對稱金鑰（用於加解密 payload） |
| `CLIENT_PRIVATE_KEY` | 商戶 RSA 私鑰（用於請求簽名） |

---

## 2. 支援的支付方式與幣別

### 代收（Deposit）

| 幣別 | 支付方式代碼（`method`） | 說明 | 最低 | 最高 | 費率（參考） |
|---|---|---|---|---|---|
| MYR | `FPX` | FPX 網路銀行 | RM 30 | RM 30,000 | 1.0%～1.5% |
| MYR | `FPX_C` | FPX 企業銀行 | RM 30 | RM 30,000 | 2.0% |
| MYR | `E_WALLET_MY` | 電子錢包（Touch n Go、ShopeePay 等） | RM 2 | RM 9,999 | 1.6%～1.8% |

> 費率依各商戶合約而異，以上為工單記載之參考值。

### 代付（Withdrawal）

| 幣別 | 方法 | 說明 | 最低 | 最高 | 費率（參考） |
|---|---|---|---|---|---|
| MYR | 銀行帳號（SWIFT code） | `methodType` 填入銀行 SWIFT code，`methodValue` 填入帳號 | RM 30 | RM 10,000 | 0.8%～1.5% |

完整銀行代碼清單見 [第 9 節](#9-銀行代碼映射myr-代付)。

---

## 3. 認證流程（Access Token）

Access Token 為 JWT 格式，快取有效期約 **23 小時**（82800 秒）。每次業務 API 呼叫前需帶入 `Authorization` header。

**Endpoint**：`POST /gateway/v1/createToken`

### 步驟

#### Step 1 — 組合並 Base64 編碼 credentials

```
plainCredentials = "{CLIENT_ID}:{CLIENT_SECRET}"
credentials      = base64_encode(plainCredentials)
```

#### Step 2 — RSA 加密

使用 `SERVER_PUBLIC_KEY`（PKCS#1 Padding）對 `credentials` 進行 RSA 加密，再將加密結果轉成 hex 字串：

```
encryptedData = bin2hex(openssl_public_encrypt(credentials, SERVER_PUBLIC_KEY, PKCS1_PADDING))
```

#### Step 3 — 取得 Token

**請求**

```json
POST /gateway/v1/createToken
Content-Type: application/json

{
    "data": "{encryptedData}"
}
```

**響應**

```json
{
    "code": 200,
    "message": "Success",
    "encryptedToken": "a1b2c3d4e5f6..."
}
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `code` | integer | `200` 表示成功 |
| `message` | string | 狀態訊息 |
| `encryptedToken` | string | AES 加密後的 JWT Token（hex 格式） |

#### Step 4 — AES 解密取得 JWT

使用 `CLIENT_SYMMETRIC_KEY` 對 `encryptedToken` 進行 AES-256-CBC 解密（見 [第 5 節](#5-aes-加解密規格)），得到明文 JWT：

```
accessToken = [REDACTED], CLIENT_SYMMETRIC_KEY)
```

#### Step 5 — 帶入後續請求

```
Authorization: {accessToken}
```

> **401 自動重刷**：若業務 API 回傳 HTTP 401，需清除快取 Token 並重新走取 Token 流程，再重試原請求。Token 快取 key 為 `kira_token_{md5(CLIENT_ID + CLIENT_SECRET)}`。

---

## 4. 請求簽名算法

所有業務 API 請求的 `X-Signature` header 依以下步驟計算。

### 步驟

#### Step 1 — 排序 request payload

將 request payload（加密前的原始物件）的所有 key **遞迴按字母排序**（`ksort` recursive）。

#### Step 2 — 序列化並 Base64 編碼

```
compactJson = json_encode(sortedPayload)   // compact 格式，不含空白
base64Data  = base64_encode(compactJson)
```

#### Step 3 — 組合明文字串

依以下格式拼接（固定順序，各欄位以 `&` 分隔）：

```
plainText = "data={base64Data}&method=post&nonceStr={X-Nonce-Str}&requestUrl={endpoint}&signType=sha256&timestamp={X-Timestamp}"
```

- `requestUrl`：去掉開頭 `/` 的 endpoint path，例如 `gateway/v1/createPayment`
- `nonceStr`：隨機 hex 字串（16 位元組 `bin2hex(random_bytes(8))`）
- `timestamp`：Unix timestamp（秒）字串

#### Step 4 — RSA SHA-256 簽名

使用 `CLIENT_PRIVATE_KEY` 對明文進行 RSA 簽名（`OPENSSL_ALGO_SHA256`），再 Base64 編碼：

```
signature = base64_encode(openssl_sign(plainText, CLIENT_PRIVATE_KEY, SHA256))
```

#### Step 5 — 加入 Header

```
X-Signature: sha256 {signature}
```

### 完整請求 Headers（業務 API）

| Header | 說明 |
|---|---|
| `Authorization` | 從 createToken 解密取得的 JWT |
| `X-Nonce-Str` | 每次請求產生的隨機 hex 字串（16 位元組） |
| `X-Timestamp` | Unix timestamp（秒） |
| `X-Signature` | `sha256 {base64簽名}` |
| `Content-Type` | `application/json` |

---

## 5. AES 加解密規格

Kira 採用 **AES-256-CBC** 對稱加密，IV 由金鑰衍生：

| 項目 | 規格 |
|---|---|
| 演算法 | AES-256-CBC |
| 金鑰 | `CLIENT_SYMMETRIC_KEY`（32 位元組） |
| IV | `md5(CLIENT_SYMMETRIC_KEY, raw_output=true)`（16 位元組） |
| 加密輸出 | `bin2hex(encrypted_bytes)`（hex 字串） |
| 解密輸入 | `hex2bin(encryptedHex)` 轉回 bytes 再解密 |

### 加密（傳送 payload 前）

```
sortedPayload  = ksort_recursive(payload)
jsonData       = json_encode(sortedPayload)          // compact JSON
base64Data     = base64_encode(jsonData)
iv             = md5(CLIENT_SYMMETRIC_KEY, raw=true)
encryptedBytes = openssl_encrypt(base64Data, 'aes-256-cbc', CLIENT_SYMMETRIC_KEY, OPENSSL_RAW_DATA, iv)
encryptedHex   = bin2hex(encryptedBytes)
```

> 注意：加密的是 **Base64 後的 JSON**，而非原始 JSON 字串。

### 解密（接收響應或回調）

```
iv             = md5(CLIENT_SYMMETRIC_KEY, raw=true)
encryptedBytes = hex2bin(encryptedHex)
decrypted      = openssl_decrypt(encryptedBytes, 'aes-256-cbc', CLIENT_SYMMETRIC_KEY, OPENSSL_RAW_DATA, iv)
// decrypted 為 JSON 字串，直接 json_decode 取得資料物件
```

> Token 解密（createToken 回傳的 `encryptedToken`）直接解密得到 JWT 明文字串，不需 json_decode。

---

## 6. 代收 API

### Endpoint

`POST /gateway/v1/createPayment`

### 請求 Headers

見 [第 4 節](#4-請求簽名算法)，完整 5 個 headers 均必填。

### 請求 Body

```json
{
    "data": "{AES加密後的payload hex字串}"
}
```

**加密前 payload 結構**：

```json
{
    "order": {
        "id": "ORDER20251101001",
        "additionalData": "",
        "title": "Deposit",
        "amount": "100.00",
        "currencyType": "MYR",
        "logoUrl": "",
        "redirectUrl": "https://example.invalid/return",
        "callbackUrl": "https://example.invalid/callback/deposit/{cash_flow_id}"
    },
    "customer": {
        "name": "member_username",
        "phone": "0123456789",
        "email": "user@example.invalid"
    },
    "method": "FPX"
}
```

**order 物件欄位**：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `id` | ✅ | string | 商戶訂單號（對應 `orders.order_no`） |
| `title` | ✅ | string | 訂單標題，固定傳 `"Deposit"` |
| `amount` | ✅ | string | 金額，固定 2 位小數（`sprintf('%.2f', $amount)`） |
| `currencyType` | ✅ | string | 幣別，目前固定 `MYR` |
| `additionalData` | - | string | 訂單備註，傳空字串 |
| `logoUrl` | - | string | 商戶 Logo URL，傳空字串 |
| `redirectUrl` | - | string | 支付完成後跳轉 URL |
| `callbackUrl` | ✅ | string | 回調通知 URL |

**customer 物件欄位**：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `name` | ✅ | string | 會員帳號（`member.username`） |
| `phone` | ✅ | string | 手機號（`member.mobile`），無號碼傳空字串 |
| `email` | ✅ | string | 電子郵件（`member.email`），無郵件傳 `"user@example.invalid"` |

**method 欄位**：

| 值 | 說明 |
|---|---|
| `FPX` | FPX 網路銀行 |
| `FPX_C` | FPX 企業銀行 |
| `E_WALLET_MY` | 電子錢包 |

> `method` 值取自 `order_types.key`（即 AgentCashFlow 所綁定的渠道 key）。

### 響應

```json
{
    "code": 200,
    "message": "Success",
    "encryptedData": "a1b2c3d4..."
}
```

解密 `encryptedData` 後得：

```json
{
    "data": {
        "paymentUrl": "https://api.kira.asia/gateway/v1/createPayment/checkout/payment?id=...",
        "transactionId": "123456789"
    }
}
```

| 欄位 | 說明 |
|---|---|
| `data.paymentUrl` | 收銀台 URL，導向此 URL 讓使用者完成支付 |
| `data.transactionId` | Kira 交易 ID（存入 `orders.third_party_order_no`） |

**成功判定**：`code == 200` 且 `data.paymentUrl` 不為空。

**錯誤響應範例**：

```json
{
    "code": 400,
    "message": "Order ID already exists"
}
```

---

## 7. 代付 API

### Endpoint

`POST /gateway/v1/withdrawRequest`

### 請求 Body

```json
{
    "data": "{AES加密後的payload hex字串}"
}
```

**加密前 payload 結構**：

```json
{
    "order": {
        "id": "KIRA1234567890",
        "amount": "500.00",
        "currencyType": "MYR",
        "callbackUrl": "https://example.invalid/callback/withdrawal/{cash_flow_id}"
    },
    "recipient": {
        "name": "收款人姓名",
        "phone": "0123456789",
        "email": "user@example.invalid",
        "methodType": "MBBEMYKL",
        "methodValue": "1234567890",
        "methodRef": ""
    }
}
```

**order 物件欄位**：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `id` | ✅ | string | 商戶代付交易號（由 `Common::genTransactionNo('KIRA')` 產生，格式 `KIRA{timestamp...}`） |
| `amount` | ✅ | string | 金額，固定 2 位小數 |
| `currencyType` | ✅ | string | 幣別，目前固定 `MYR` |
| `callbackUrl` | ✅ | string | 代付結果回調 URL |

**recipient 物件欄位**：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `name` | ✅ | string | 收款人姓名（`withdrawal_orders.account_name`） |
| `phone` | ✅ | string | 收款人手機號（`owner.mobile`），無值傳空字串 |
| `email` | ✅ | string | 收款人電子郵件（`owner.email`），無值傳 `"user@example.invalid"` |
| `methodType` | ✅ | string | 銀行 SWIFT code（透過 `convertPayoutBankCode()` 由系統 bank_code 轉換） |
| `methodValue` | ✅ | string | 銀行帳號（`withdrawal_orders.account_no`） |
| `methodRef` | ✅ | string | 附加資訊，傳空字串 |

> `methodType` 透過 `convertPayoutBankCode()` 將系統內部 `bank_code` 轉換為 Kira 接受的 SWIFT code。不支援的 bank_code 會直接回傳失敗，不送出 API 請求。

### 響應

```json
{
    "code": 200,
    "message": "Success",
    "encryptedData": "a1b2c3d4..."
}
```

解密 `encryptedData` 後得：

```json
{
    "data": {
        "transactionId": "123456789"
    }
}
```

| 欄位 | 說明 |
|---|---|
| `data.transactionId` | Kira 代付交易 ID（存入 `withdrawal_payout_records.third_party_transaction_no`） |

**成功判定**：`code == 200` 且 `data.transactionId` 不為空。代付為**非同步處理**，最終結果透過回調通知。

---

## 8. 回調處理

回調由 Kira 以 **POST JSON** 推送至商戶設定的 `callbackUrl`。代收與代付均使用 AES 加密的 `encryptedData` 格式，但**狀態判定邏輯不對稱**。

### 8.1 代收回調

**接收的 request body**：

```json
{
    "encryptedData": "a1b2c3d4e5..."
}
```

**解密後資料結構**：

```json
{
    "message": "Success",
    "data": {
        "status": "SUCCESS",
        "transaction": {
            "id": 123456789,
            "amountPaid": 100.00,
            "confirmedAt": 1698825600,
            "paymentMethod": "FPX",
            "commissionFee": 1.50,
            "payerBank": {
                "swiftCode": "MBBEMYKL",
                "accountNo": "1234567890",
                "accountName": "JOHN DOE"
            }
        },
        "order": {
            "id": "ORDER20251101001",
            "amount": "100.00",
            "currencyType": "MYR"
        },
        "customer": {
            "name": "member_username",
            "phone": "0123456789",
            "email": "user@example.invalid"
        }
    }
}
```

**查單鍵值**：`data.order.id` 對應系統的 `orders.order_no`。

**狀態判定**（大小寫不敏感，先 `strtoupper()`）：

| `data.status` | 系統對應狀態 | 處理方式 |
|---|---|---|
| `SUCCESS` | `OrderStatusEnum::SUCCESS` | 以 `data.transaction.amountPaid` 更新訂單金額並結案 |
| `PENDING` | `OrderStatusEnum::CREATED` | **僅更新 status，不終結訂單**（等待後續回調） |
| `FAILED` | `OrderStatusEnum::FAILED` | 訂單失敗結案 |
| 其他 | `OrderStatusEnum::FAILED` | 訂單失敗結案 |

> `PENDING` 狀態：不觸發退款或失敗流程，僅更新 status 欄位及 remark，保留訂單等待後續回調。

### 8.2 代付回調

**接收的 request body**：

```json
{
    "encryptedData": "a1b2c3d4e5..."
}
```

**解密後資料結構**：

```json
{
    "message": "Success",
    "data": {
        "status": "SUCCESS",
        "transaction": {
            "id": 10025,
            "amountWithdraw": 500.00,
            "confirmedAt": 1698825600,
            "commissionFee": 5.00,
            "remarks": "Success"
        },
        "order": {
            "id": "KIRA1234567890",
            "amount": "500.00",
            "currencyType": "MYR"
        },
        "recipient": {
            "name": "收款人姓名",
            "phone": "0123456789",
            "email": "user@example.invalid",
            "methodType": "MBBEMYKL",
            "methodValue": "1234567890",
            "methodRef": ""
        }
    }
}
```

**查單鍵值**：`data.order.id` 對應系統的 `withdrawal_payout_records.transaction_no`（由系統產生的 `KIRA...` 編號）。

**狀態判定（與代收不對稱）**：

| `data.status` | 處理方式 |
|---|---|
| `SUCCESS` | 代付成功，驗證 `data.transaction.amountWithdraw` 後結案 |
| `FAILED` | 代付失敗，訂單標記失敗 |
| 其他（如 `PENDING` 等） | **直接回傳 `OK` 忽略**（不更新訂單狀態） |

> **代付不處理 PENDING**：代付回調收到非 `SUCCESS`/`FAILED` 的狀態時，直接回傳 `OK` 結束，不對訂單做任何變動。這與代收的 PENDING 保留邏輯不同。

### 8.3 商戶響應格式

| 情境 | 回傳內容 | HTTP 狀態碼 |
|---|---|---|
| 成功處理 | 純文字 `OK` | 200 |
| 處理失敗 | 純文字 `FAIL` | 200 |

### 8.4 冪等性保護

| 情境 | 處理方式 |
|---|---|
| 代收已終結訂單重複回調 | `checkDepositOrderStatus()` 拋出 `DepositOrderException(200)`，回傳 `OK` 不重複處理 |
| 代付 payout_record 不存在 | 回傳特定錯誤響應 |
| 代付 payout_record 已處理 | `isPayoutRecordProcessed()` 檢查後回傳 `OK` |
| 代付 payout_record 已被同步回應判明確拒絕（`THIRD_PARTY_REJECTED(-2)`），又收到回調 | 回調不是 `SUCCESS`（`FAILED`、`PENDING` 等）：回傳 `OK`、不動訂單、不告警；`FAILED` 另記下回調內容（`callback_request_body`／`callback_time`／`result_code`），其他狀態只記 log。回調是 `SUCCESS`：不論訂單目前狀態，一律發專用告警（拒絕判定可能有誤、訂單可能重複出款），再照常走訂單狀態檢查與定案 |
| 所有 DB 操作 | `lockForUpdate()` 防止競態條件 |

---

## 9. 銀行代碼映射（MYR 代付）

代付 `methodType` 使用 SWIFT code 格式。系統內的 `bank_code` 需透過 `convertPayoutBankCode()` 轉換為以下 SWIFT code 後傳入 API。

### 馬來西亞銀行

| SWIFT code | 銀行名稱 |
|---|---|
| `PHBMMYKL` | Affin Bank |
| `AGOBMYKL` | AGRO Bank（Agrobank） |
| `MFBBMYKL` | Alliance Bank |
| `RJHIMYKL` | Alrajhi Bank |
| `ARBKMYKL` | AmBank |
| `BIMBMYKL` | Bank Islam Malaysia |
| `BMMBMYKL` | Bank Muamalat |
| `BKRMMYKL` | Bank Rakyat |
| `BSNAMYK1` | Bank Simpanan Nasional |
| `CIBBMYKL` | CIMB Bank |
| `CITIMYKL` | Citibank |
| `HLBBMYKL` | Hong Leong Bank |
| `HBMBMYKL` | HSBC Bank |
| `KFHOMYKL` | Kuwait Finance House |
| `MBBEMYKL` | Maybank |
| `OCBCMYKL` | OCBC Bank |
| `PBBEMYKL` | Public Bank |
| `RHBBMYKL` | RHB Bank |
| `SCBLMYKX` | Standard Chartered Bank |
| `UOVBMYKL` | United Overseas Bank（UOB） |

> 完整且最新的銀行清單以 Kira 官方文檔 https://doc.kira.asia 為準。

---

## 10. 錯誤碼與狀態碼

### API 響應 code

| code | 說明 |
|---|---|
| `0` | 業務拒絕（官方文檔未載；實測 2026-08-04 TPower 訂單 2184127 回 `code:0`「Daily withdrawal limit reached」） |
| `200` | 成功 |
| `400` | 請求參數錯誤（如訂單號重複） |
| `401` | 認證失敗（Token 過期），系統自動清除快取並重新取 Token |
| `1200` | 業務拒絕（官方文檔未載；正式環境實測 message「Payment Gateway Status OFF」） |
| `3001` | 業務拒絕（官方文檔未載；正式環境實測 message「Payment Gateway Error」；事後可能再收到 `FAILED` 回調） |
| `4001` | 業務拒絕（官方文檔未載；正式環境實測 message「Merchant Payout Pool Insufficent Balance」） |

> **KIRAPAY 書面確認：業務碼只要不是 `200`，訂單都可視為失敗、可以重發，銀行端不會出款**（對話紀錄見 target/tickets#4393）。原文：「只要不是 200，都可以把订单视为失败，可以重发」「不会的，只要不是 200，就表示银行那里不会出款」。

### HTTP 狀態碼

| HTTP 狀態 | 說明 |
|---|---|
| `520`／`522` | Cloudflare 的錯誤碼（KIRA API 架在 Cloudflare 後面），不是業務碼。KIRAPAY 書面表示這兩種情況 KIRA 系統沒收到訂單（對話紀錄見 target/tickets#4091）。原文：「CODE 520 和 522 是 CLOUDFLARE 错误代码，我司系统没收到订单」。本系統不依此判拒絕，見下方說明 |

> **Cloudflare 官方文件對這兩碼的定義**：`522` 是 Cloudflare 19 秒內連不上 KIRA 主機（連上後 90 秒沒回應也算 `522`）；`520` 是 KIRA 主機回了空的、未知或不預期的回應，可能發生在請求已送進 KIRA 之後（例如 KIRA 處理到一半當掉）。
>
> **代付分流判準（codebase 行為）**：只依 KIRA 回應的業務碼判定明確拒絕。HTTP 2xx 且業務碼不是 `200`（含 `400` 與上表沒列到的碼）→ payout record 標 `THIRD_PARTY_REJECTED(-2)`、訂單轉 29（三方失敗）可重新發起。所有 HTTP 非 2xx（含 `520`、`522`；body 帶了業務碼也一樣）、連線逾時、回應解不出數字業務碼、`code=200` 但解不出 `transactionId` → 拋例外 → `REQUEST_FAILED(-1)`、訂單停留 21（代付請求中）待回調或人工定案。HTTP 狀態碼不判拒絕是需求範圍的決定：target/tickets#4393 只依業務碼判定。
>
> `400` 納入明確拒絕的理由：文檔說它含「訂單號重複」，但每次送出都用新產生的交易號、HTTP 請求不自動重試，同一個交易號只會送一次，回「重複」也不代表這次請求已在 KIRA 建單。

### 回調 status

| status | 代收 | 代付 |
|---|---|---|
| `SUCCESS` | 支付成功，結案 | 代付成功，結案 |
| `PENDING` | 保留訂單（不終結） | **忽略，回 OK** |
| `FAILED` | 支付失敗，結案 | 代付失敗，結案 |
| 其他 | 失敗，結案 | **忽略，回 OK** |

> 狀態比對前先 `strtoupper()`，容錯大小寫差異。

---

## 11. 參考資料

- **官方 API 文檔**：https://doc.kira.asia
  - 子頁面需商戶帳號登入（直連 URL 為 404）
- **GitLab 工單**：
  - #3435 [GFF][APIs] KiraPay-MYR 代收代付接入（2026-01 首次接入，closed）
  - #3494 [GFF][Yes2win] KiraPay-MYR 代收代付配置（2026-01，closed）
  - #3694 [GFF][TPower] KiraPay-MYR 代收代付配置（2026-04，closed）
  - #3695 [GFF][Wintoto] KiraPay-MYR 代收代付配置（2026-04，closed）
  - #4091 [GFF]KIRAPAY代付 判斷失敗邏輯處理（業務碼 0 與 HTTP 520／522 的書面說法）
  - #4393 [GFF]KIRAPAY代付 除了code200以外都判斷失敗（業務碼非 200 一律判失敗的書面依據）
- **內部實作路徑**：`app/Services/Payment/Providers/Kira/`
  - `Driver.php` — 組合代收/代付子類別
  - `KiraTrait.php` — Token 取得與快取、AES 加解密、RSA 操作、簽名生成、HTTP 請求發送（`sendRequest`）、回調解密（`decryptCallbackData`）
  - `Deposit.php` — 代收實作，呼叫 `/gateway/v1/createPayment`
  - `DepositCallback.php` — 代收回調處理（`OK` / `FAIL` 響應，PENDING 保留邏輯）
  - `Withdrawal.php` — 代付實作，呼叫 `/gateway/v1/withdrawRequest`
  - `WithdrawalCallback.php` — 代付回調處理（忽略非 SUCCESS/FAILED 狀態）
  - `app/Console/Commands/CashFlow/Data/providers/Kira.php` — 金流商初始化配置（幣別、渠道、銀行清單）

---

*文件版本：1.0 / 整理日期：2026-06-01*
*資料來源：Codebase 實作（最權威）、GitLab 工單 #3435、#3494、#3694、#3695、官方 API 文檔首頁（https://doc.kira.asia）*
