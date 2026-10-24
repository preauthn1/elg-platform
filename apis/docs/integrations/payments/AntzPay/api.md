# AntzPay 廠商規格

> 官方文檔：https://example.invalid/
> 內部實作路徑：`app/Services/Payment/Providers/AntzPay/`

AntzPay 是面向泰國市場的第三方支付平台，目前支援泰銖（THB）的代收（Deposit）與代付（Withdrawal）。代收採用 **PromptPay** 收銀台模式，代付透過 Bank API 直連各家泰國銀行。

**安全機制總覽**：
- 認證：RSA 非對稱加密取得 Access Token，Token 以 AES-256-CBC 加密回傳
- 業務請求：所有 payload 以 AES-256-CBC 加密後傳送，回應同樣加密
- 請求防偽：RSA SHA-256 簽名帶入 `X-Signature` header
- 回調驗證：回調 body 同樣是 AES-256-CBC 加密的 `encryptedData`，不使用額外 HMAC/MD5

---

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [認證流程（Access Token）](#3-認證流程access-token)
4. [請求簽名算法](#4-請求簽名算法)
5. [AES 加解密規格](#5-aes-加解密規格)
6. [代收 API](#6-代收-api)
7. [代付 API](#7-代付-api)
8. [訂單狀態查詢 API](#8-訂單狀態查詢-api)
9. [回調處理](#9-回調處理)
10. [銀行代碼映射（THB 代付）](#10-銀行代碼映射thb-代付)
11. [錯誤碼與狀態碼](#11-錯誤碼與狀態碼)
12. [參考資料](#12-參考資料)

---

## 1. 環境資訊

| 項目 | 生產環境 | 測試環境 |
|---|---|---|
| API Base URL | `https://example.invalid` | `https://example.invalid` |
| 商戶後台 | `https://example.invalid` | `https://example.invalid` |
| 請求格式 | JSON | JSON |
| 響應格式 | JSON（body 內含 AES 加密 `encryptedData`） | 同左 |

**必要憑證（由三方後台取得）**：

| 憑證欄位 | 說明 |
|---|---|
| `CLIENT_ID` | 商戶識別碼 |
| `CLIENT_SECRET` | 商戶金鑰 |
| `SERVER_PUBLIC_KEY` | 三方伺服器 RSA 公鑰（用於加密 credentials） |
| `CLIENT_SYMMETRIC_KEY` | 商戶 AES 對稱金鑰（用於加解密 payload） |
| `CLIENT_PRIVATE_KEY` | 商戶 RSA 私鑰（用於請求簽名） |

> 商戶 RSA 公鑰需事先上傳至 AntzPay 後台，三方以此驗證 `X-Signature`。

---

## 2. 支援的支付方式與幣別

### 代收（Deposit）

| 幣別 | 支付方式代碼 | 說明 |
|---|---|---|
| THB | `PROMPTPAY_TH` | PromptPay 收銀台（泰國 QR / 二維碼轉帳）。`method` 欄位傳入此值；留空則進入選擇頁 |

> 目前僅支援 THB；`method` 欄位可為空，讓使用者在收銀台自行選擇（若平台有多種支付方式時）。

### 代付（Withdrawal）

| 幣別 | 方法 | 說明 |
|---|---|---|
| THB | 銀行帳號（SWIFT code） | `methodType` 填入銀行 SWIFT code，`methodValue` 填入帳號 |
| THB | `NATID` | 泰國國民身份證號 |
| THB | `MSISDN` | 泰國手機號碼 |

完整銀行代碼清單見 [第 10 節](#10-銀行代碼映射thb-代付)。

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

範例：
```
plainCredentials = "10020:{CLIENT_SECRET}"
credentials      = "MTAwMjA6e0NMSUVOVF9TRUNSRVR9"
```

#### Step 2 — RSA 加密

使用 `SERVER_PUBLIC_KEY`（PKCS#1 padding）對 `credentials` 進行 RSA 加密，再將加密結果轉成 hex 字串：

```
encryptedCredentials = bin2hex(rsa_public_encrypt(credentials, SERVER_PUBLIC_KEY))
```

#### Step 3 — 取得 Token

**請求**

```json
POST /gateway/v1/createToken
Content-Type: application/json

{
    "data": "{encryptedCredentials}"
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

> **401 自動重刷**：若業務 API 回傳 HTTP 401，需清除快取 Token 並重新走取 Token 流程，再重試原請求。

---

## 4. 請求簽名算法

所有業務 API 請求的 `X-Signature` header 依以下步驟計算。

### 步驟

#### Step 1 — 排序並壓縮 request payload

將 request payload（加密前的 JSON）的所有 key **遞迴按字母排序**（`ksort` recursive），再序列化為 compact JSON（不含空白）。

#### Step 2 — Base64 編碼

```
base64Data = base64_encode(compactJson)
```

#### Step 3 — 組合明文字串

依以下格式拼接（固定順序，各欄位以 `&` 分隔）：

```
plainText = "data={base64Data}&method=post&nonceStr={X-Nonce-Str}&requestUrl={endpoint}&signType=sha256&timestamp={X-Timestamp}"
```

- `requestUrl`：去掉開頭 `/` 的 endpoint path，例如 `gateway/v1/createPayment`
- `nonceStr`：隨機 hex 字串（16 位元組 `bin2hex(random_bytes(8))`）
- `timestamp`：Unix timestamp（秒）字串

**範例明文**：
```
data=eyJjdXN0b21lcm...&method=post&nonceStr=VYNknZohxwicZMaWbNdBKUrnrxDtaRhN&requestUrl=gateway/v1/createPayment&signType=sha256&timestamp=1527407052
```

#### Step 4 — RSA SHA-256 簽名

使用 `CLIENT_PRIVATE_KEY` 對明文進行 RSA 簽名（`OPENSSL_ALGO_SHA256`），再 Base64 編碼：

```
signature = base64_encode(openssl_sign(plainText, CLIENT_PRIVATE_KEY, SHA256))
```

#### Step 5 — 加入 Header

```
X-Signature: sha256 {signature}
```

### 完整請求 Headers

| Header | 說明 |
|---|---|
| `Authorization` | 從 createToken 解密取得的 JWT |
| `X-Nonce-Str` | 每次請求產生的隨機 hex 字串 |
| `X-Timestamp` | Unix timestamp（秒） |
| `X-Signature` | `sha256 {base64簽名}` |
| `Content-Type` | `application/json` |

---

## 5. AES 加解密規格

AntzPay 採用 **AES-256-CBC** 對稱加密，IV 由金鑰衍生：

| 項目 | 規格 |
|---|---|
| 演算法 | AES-256-CBC |
| 金鑰 | `CLIENT_SYMMETRIC_KEY`（32 位元組） |
| IV | `md5(CLIENT_SYMMETRIC_KEY, raw_output=true)`（16 位元組） |
| 加密輸出 | `bin2hex(encrypted_bytes)`（hex 字串） |
| 解密輸入 | `hex2bin(encryptedHex)` 轉回 bytes 再解密 |

### 加密（傳送 payload）

```
sortedJson     = json_encode(ksort_recursive(payload))
base64Data     = base64_encode(sortedJson)
iv             = md5(CLIENT_SYMMETRIC_KEY, raw=true)
encryptedBytes = openssl_encrypt(base64Data, 'aes-256-cbc', CLIENT_SYMMETRIC_KEY, OPENSSL_RAW_DATA, iv)
encryptedHex   = bin2hex(encryptedBytes)
```

> 注意：加密的是 **Base64 後的 JSON**，不是原始 JSON。

### 解密（接收響應或回調）

```
iv             = md5(CLIENT_SYMMETRIC_KEY, raw=true)
encryptedBytes = hex2bin(encryptedHex)
decrypted      = openssl_decrypt(encryptedBytes, 'aes-256-cbc', CLIENT_SYMMETRIC_KEY, OPENSSL_RAW_DATA, iv)
```

解密結果為 JSON 字串，再 `json_decode` 取得資料物件。

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
        "title": "Deposit",
        "amount": "100.00",
        "currencyType": "THB",
        "additionalData": "",
        "logoUrl": "",
        "redirectUrl": "https://example.invalid/return",
        "callbackUrl": "https://example.invalid/callback/deposit/{cash_flow_id}"
    },
    "customer": {
        "name": "member_username",
        "phone": "0123456789",
        "email": "user@example.invalid"
    },
    "method": "PROMPTPAY_TH"
}
```

**order 物件欄位**：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `id` | ✅ | string | 商戶訂單號（系統唯一） |
| `title` | ✅ | string | 訂單標題，最長 32 字元 |
| `amount` | ✅ | string | 金額，固定 2 位小數（`sprintf('%.2f', $amount)`） |
| `currencyType` | ✅ | string | 幣別，目前固定 `THB` |
| `additionalData` | - | string | 訂單備註，可為空字串 |
| `logoUrl` | - | string | 商戶 Logo URL，可為空字串 |
| `redirectUrl` | - | string | 支付完成後跳轉 URL |
| `callbackUrl` | ✅ | string | 回調通知 URL |

**customer 物件欄位**：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `name` | ✅ | string | 會員帳號或姓名 |
| `phone` | ✅ | string | 手機號，無號碼可傳空字串 |
| `email` | ✅ | string | 電子郵件，無郵件可傳佔位符（`user@example.invalid`） |

**method 欄位**：

| 值 | 說明 |
|---|---|
| `PROMPTPAY_TH` | 指定 PromptPay 通道 |
| `""` 或省略 | 讓使用者在收銀台自行選擇 |

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
        "paymentUrl": "https://example.invalid/gateway/v1/createPayment/checkout/payment?id=...",
        "transactionId": "123456789"
    }
}
```

| 欄位 | 說明 |
|---|---|
| `data.paymentUrl` | 收銀台 URL，導向此 URL 讓使用者完成支付 |
| `data.transactionId` | 三方交易 ID（存入 `third_party_order_no`） |

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
        "id": "ANTZ20251101001",
        "amount": "500.00",
        "currencyType": "THB",
        "callbackUrl": "https://example.invalid/callback/withdrawal/{cash_flow_id}"
    },
    "recipient": {
        "name": "收款人姓名",
        "phone": "0123456789",
        "email": "user@example.invalid",
        "methodType": "KASITHBK",
        "methodValue": "1234567890",
        "methodRef": ""
    }
}
```

**order 物件欄位**：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `id` | ✅ | string | 商戶代付訂單號（由系統產生，格式 `ANTZ{timestamp...}`） |
| `amount` | ✅ | string | 金額，固定 2 位小數 |
| `currencyType` | ✅ | string | 幣別，目前固定 `THB` |
| `callbackUrl` | - | string | 代付結果回調 URL |

**recipient 物件欄位**：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `name` | ✅ | string | 收款人姓名（需與銀行帳戶一致） |
| `phone` | ✅ | string | 收款人手機號 |
| `email` | ✅ | string | 收款人電子郵件，無值傳佔位符 |
| `methodType` | ✅ | string | 銀行 SWIFT code 或特殊代碼（`NATID`/`MSISDN`） |
| `methodValue` | ✅ | string | 銀行帳號或對應號碼 |
| `methodRef` | ✅ | string | 附加資訊（如國碼），一般傳空字串 |
| `nameCheck` | - | string | `"1"` 表示啟用收款人姓名驗證 |

> `methodType` 對應系統內 `bank_code`，透過 `convertPayoutBankCode()` 轉換。支援的 SWIFT code 清單見 [第 10 節](#10-銀行代碼映射thb-代付)。

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
| `data.transactionId` | 三方代付交易 ID（存入 `payout_records.third_party_order_no`） |

**成功判定**：`code == 200` 且 `data.transactionId` 不為空。代付為**非同步處理**，最終結果透過回調通知（處理時間約 9 秒）。

---

## 8. 訂單狀態查詢 API

適用於主動查詢訂單狀態（非回調觸發的補單場景）。

### Endpoint

`POST /gateway/v1/checkStatus`（依官方文檔）

### 加密前 payload

```json
{
    "transactionId": "123456789",
    "orderId": "ORDER20251101001",
    "type": "1"
}
```

| 欄位 | 必填 | 說明 |
|---|---|---|
| `transactionId` | 二擇一 | 三方交易 ID |
| `orderId` | 二擇一 | 商戶訂單號 |
| `type` | ✅ | `"1"` 代收 / `"2"` 代付 |

### 響應（解密後）

| 欄位 | 說明 |
|---|---|
| `data.status` | 訂單狀態（`SUCCESS` / `PENDING` / `FAILED`） |
| `data.transaction` | 交易詳情（id, amountPaid, confirmedAt, paymentMethod 等） |
| `data.order` | 訂單資訊（id, amount, currencyType） |
| `data.customer` | 客戶資訊（代收）/ `data.recipient`（代付） |

---

## 9. 回調處理

回調由 AntzPay 以 **POST JSON** 推送至商戶設定的 `callbackUrl`。

### 9.1 代收回調

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
            "paymentMethod": "PROMPTPAY_TH",
            "commissionFee": 1.50,
            "payerBank": {
                "swiftCode": "KASITHBK",
                "accountNo": "1234567890",
                "accountName": "JOHN DOE"
            }
        },
        "order": {
            "id": "ORDER20251101001",
            "amount": "100.00",
            "currencyType": "THB"
        },
        "customer": {
            "name": "member_username",
            "phone": "0123456789",
            "email": "user@example.invalid"
        }
    }
}
```

**狀態判定**：

| `data.status` | 系統對應狀態 | 處理方式 |
|---|---|---|
| `SUCCESS` | `OrderStatusEnum::SUCCESS` | 以 `data.transaction.amountPaid` 更新訂單金額並結案 |
| `PENDING` | `OrderStatusEnum::CREATED` | 保留訂單，不終結（等待後續回調） |
| `FAILED` | `OrderStatusEnum::FAILED` | 訂單失敗結案 |
| 其他 | `OrderStatusEnum::FAILED` | 訂單失敗結案 |

> `PENDING` 狀態僅更新 `status` 欄位，不觸發退款或失敗流程。

**商戶響應**：
- 成功處理 → 回傳純文字 `OK`（HTTP 200）
- 處理失敗 → 回傳純文字 `FAIL`

### 9.2 代付回調

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
            "id": "ANTZ20251101001",
            "amount": "500.00",
            "currencyType": "THB"
        },
        "recipient": {
            "name": "收款人姓名",
            "phone": "0123456789",
            "email": "user@example.invalid",
            "methodType": "KASITHBK",
            "methodValue": "1234567890",
            "methodRef": ""
        }
    }
}
```

**查單鍵值**：`data.order.id` 對應系統的 `payout_records.transaction_no`（由系統產生的 `ANTZ...` 編號）。

**狀態判定**：

| `data.status` | 說明 |
|---|---|
| `SUCCESS` | 代付成功，以 `data.transaction.amountWithdraw` 驗證金額後結案 |
| 其他 | 代付失敗 |

**商戶響應**：
- 成功處理 → 回傳純文字 `OK`（HTTP 200）
- 處理失敗 → 回傳純文字 `FAIL`

### 9.3 冪等性保護

| 情境 | 處理方式 |
|---|---|
| 代收已終結訂單重複回調 | `checkDepositOrderStatus()` 拋出 `DepositOrderException(200)`，回傳 `OK` 不重複處理 |
| 代付 payout_record 不存在 | 回傳特定錯誤響應 |
| 代付已處理的 payout_record | `isPayoutRecordProcessed()` 檢查後回傳 `OK` |
| 代付 payout_record 已被同步回應判明確拒絕（`THIRD_PARTY_REJECTED(-2)`），又收到回調 | 與 Kira 相同（回調沿用 Kira 實作）：回調不是 `SUCCESS`：回傳 `OK`、不動訂單、不告警，`FAILED` 另記下回調內容；回調是 `SUCCESS`：一律發專用告警，再照常走訂單狀態檢查與定案 |
| DB 操作 | `lockForUpdate()` 防止競態條件 |

---

## 10. 銀行代碼映射（THB 代付）

代付 `methodType` 使用 SWIFT code 格式。系統內的 `bank_code` 需透過 `convertPayoutBankCode()` 轉換為以下 SWIFT code 後傳入 API。

### 主要泰國銀行

| SWIFT code | 銀行名稱 |
|---|---|
| `BKKBTHBK` | Bangkok Bank |
| `KASITHBK` | Kasikornbank（K-Bank） |
| `KRTHTHBK` | Krung Thai Bank |
| `TMBKTHBK` | TMBThanachart Bank |
| `SICOTHBK` | Siam Commercial Bank（SCB） |
| `AYUDTHBK` | Bank of Ayudhya（Krungsri） |
| `GSBATHBK` | Government Savings Bank |
| `GHBNTHBK` | Government Housing Bank |
| `BAABTHBK` | BAAC（Bank for Agriculture and Agricultural Cooperatives） |
| `TISCOBK` | TISCO Bank |
| `KIATTHBK` | Kiatnakin Bank |
| `TCRBTHBK` | Thai Credit Bank |
| `LHBATHBK` | Land and Houses Bank |
| `ISBCTHBK` | Islamic Bank of Thailand |

### 國際銀行（泰國分行）

| SWIFT code | 銀行名稱 |
|---|---|
| `CITITHBK` | Citibank |
| `HSBCTHBK` | HSBC |
| `SCBLTHBX` | Standard Chartered Bank |
| `CIMBTHBK` | CIMB Bank |
| `UOVBTHBK` | United Overseas Bank（UOB） |
| `SMBCTHBK` | Sumitomo Mitsui Banking Corporation |
| `ICBCTHBK` | ICBC（Industrial and Commercial Bank of China） |
| `ICBKTHBK` | Mega International Commercial Bank |
| `DEUTTHBK` | Deutsche Bank |
| `HSBCTHBK` | HSBC |
| `MHCBTHBK` | Mizuho Bank |
| `BNPATHBK` | BNP Paribas |
| `BOFATH2X` | Bank of America |
| `CRLYTHBK` | Crédit Agricole CIB |
| `BOTHTHBK` | Bank of Thailand |
| `CHASTHBX` | JPMorgan Chase Bank |
| `ABNATHBK` | ABN AMRO（Royal Bank of Scotland） |

### 非帳號類支付方式

| code | 說明 |
|---|---|
| `NATID` | 泰國國民身份證號（`methodValue` 填身份證號） |
| `MSISDN` | 泰國手機號碼（`methodValue` 填手機號） |

> 完整且最新的銀行清單以 AntzPay 官方文檔 https://example.invalid/docs/appendix/payment-method 為準。

---

## 11. 錯誤碼與狀態碼

### API 響應 code

| code | 說明 |
|---|---|
| `0` | 業務拒絕（官方文檔未載；比照同源協定 Kira 實測 `code:0`＝業務拒絕） |
| `200` | 成功 |
| `400` | 請求參數錯誤（如訂單號重複） |
| `401` | 認證失敗（Token 過期），需清除快取重新取 Token |

> **代付分流判準（codebase 行為）**：`0`／`401` 為「明確拒絕白名單」（可證未建單）→ payout record 標 `THIRD_PARTY_REJECTED(-2)`、訂單轉 29（三方失敗）可重新發起；`400` 與其他碼（AntzPay 未書面確認不會出款）、以及所有 HTTP 非 2xx → 拋例外 → `REQUEST_FAILED(-1)`、訂單停留 21（代付請求中）待回調或人工定案。
>
> **不沿用 Kira 的明確拒絕政策**：同源的 Kira 把「業務碼不是 `200`」都判為明確拒絕，依據是 KIRAPAY 的書面確認（target/tickets#4393），僅對 KIRAPAY 有效；AntzPay 沒有同等依據，白名單外的碼維持結果未知（訂單停留 21）。若日後取得 AntzPay 官方確認，於 `Providers/AntzPay/Withdrawal.php` 的 `REJECT_CODES` 補入並更新本節。HTTP 非 2xx 兩家相同，一律視為結果未知。

### 回調 status

| status | 代收 | 代付 |
|---|---|---|
| `SUCCESS` | 支付成功 | 代付成功 |
| `PENDING` | 待處理（不終結訂單） | — |
| `FAILED` | 支付失敗 | 代付失敗 |

> 狀態比對前先 `strtoupper(trim())`，容錯大小寫與前後空白差異（薄繼承 Kira 實作後與 Kira 一致）。

---

## 12. 參考資料

- **官方 API 文檔**：https://example.invalid/
  - 認證流程：https://example.invalid/docs/credential/access-token
  - 簽名算法：https://example.invalid/docs/credential/signature-algorithm
  - 代收 API：https://example.invalid/docs/transaction-api/payment/create-payment-url
  - 代付 API：https://example.invalid/docs/transaction-api/payout/create-payout-request
  - 代收回調：https://example.invalid/docs/transaction-api/payment/callback-query
  - 代付回調：https://example.invalid/docs/transaction-api/payout/callback-query
  - 支付方式：https://example.invalid/docs/appendix/payment-method
- **GitLab 工單**：
  - #2959 [GFF][TP] AntzPay-THB 代收代付配置（TPower）
  - #2960 [GFF][YES2WIN] AntzPay-THB 代收代付配置（Yes2Win）
  - #3387 [GFF][TP][APIs] AntzPay-THB 代收代付接入（closed）
- **內部實作路徑**：`app/Services/Payment/Providers/AntzPay/`
  - AntzPay 與 Kira 為 Gateway v1 同源協定，五個類別均為**薄繼承殼**，實作沿用 `app/Services/Payment/Providers/Kira/`（Token 取得與快取、AES 加解密、RSA 簽名、HTTP 請求、回調解密皆由 `KiraTrait` 提供；token 快取 key 依 driver 目錄名導出為 `antzpay_token_*`，與 Kira 隔離）
  - `Driver.php` — 繼承 Kira Driver，改組裝 AntzPay 命名空間的子類別
  - `Deposit.php` / `DepositCallback.php` / `WithdrawalCallback.php` — 空殼繼承，行為同 Kira 對應類別
  - `Withdrawal.php` — 僅覆寫明確拒絕判定（`isRejectedBusinessCode()` 只認 `REJECT_CODES = [0, 401]`，見 §11 分流判準）與交易號前綴 `orderPrefix = 'ANTZ'`
  - `app/Console/Commands/CashFlow/Data/providers/AntzPay.php` — 金流商初始化配置（幣別、銀行清單）

---

*文件版本：1.0 / 整理日期：2026-06-01*
*資料來源：官方 API 文檔（https://example.invalid/）、GitLab 工單 #2959、#2960、#3387、Codebase 實作*
