# B3 Wallet 廠商規格

> 上線步驟、各站配置與止血回滾見同目錄 **`runbook.md`**；通用接入規範見 target-apis plugin 的 `payment-integration` skill。
> （同目錄尚無 `integration.md`；我方實作決策目前記在各 class 的 docblock 與 `runbook.md` 第 1 節。）

B3 Wallet（測試環境 Base URL：`https://www.judy88.xin`）提供代收（Deposit：掃碼 / 銀行轉帳 / 鏈上收款）與代付（Withdrawal：銀行轉帳 / PayNow / 鏈上出金）。

廠商原文兩份都收在本目錄，升版時拿來 diff：

| 檔案 | 版本 | 對應工單 |
|---|---|---|
| `merchant-api-guide-paynow.md` | 初版，僅 PayNow 收款、僅 SGD | target/tickets#4000（VWSG 首次接入） |
| `merchant-api-guide.md` | 2026-09 版，開放多收款方式與多幣種 | target/tickets#4300（TPower 接入） |

**我方已串接**：PayNow / 銀行轉帳 / USDT-TRC20 鏈上收款（代收），銀行轉帳 / PayNow（代付）。
**三方有、我方未串接**：代收 `AUTO`、代付 `CRYPTO`、SGD 以外的法幣（AUD/HKD/MYR/PHP/INR/THB）。

## 驗證方式

所有請求使用 `Authorization` header，格式為 `PaymentKey:PaymentPass`（無簽名機制，非 HMAC/MD5 等簽名串接）。

```
Authorization: PaymentKey:PaymentPass
```

- 商戶狀態須為 `ACTIVE`。
- 若有設定 IP 白名單，來源 IP 須在其中。
- 缺失或格式錯誤 `Authorization` → `401`。

## 通用請求頭

| 欄位 | 位置 | 必填 | 說明 |
|---|---|---|---|
| `Authorization` | Header | ✅ | `PaymentKey:PaymentPass` |
| `Content-Type` | Header | ✅ | `application/json` |
| `Idempotency-Key` | Header | - | 冪等請求鍵；相同 key + 相同請求體回傳首次回應，請求體不同會被拒絕 |

## API 接口

### 建立收款單（Bill）— `POST /api/v1/bills/create`

| 欄位 | 位置 | 必填 | 類型 | 說明 |
|---|---|---|---|---|
| `ShopName` | Body | ✅ | string | 商戶店鋪名，1-100 字元 |
| `ProductName` | Body | ✅ | string | 商品名，1-200 字元 |
| `Amount` | Body | ✅ | number | 訂單金額，須大於 0 且在幣別限額內 |
| `SenderId` | Body | - | string | 商戶側買家標識 |
| `PayerName` | Body | - | string | 付款人銀行登記姓名，1-100 字元；傳入後鎖定，付款頁跳過姓名填寫步驟；空白視為未傳 |
| `RedirectUrl` | Body | - | string(url) | 支付完成後跳轉地址 |
| `CallbackUrl` | Body | - | string(url) | 該訂單專用回調地址；為空時使用商戶預設收款回調 |
| `Param` | Body | - | string | 透傳字段，最長 255 字元 |
| `DepositInstruction` | Body | ✅ | `PAYNOW`\|`BANK_TRANSFER`\|`CRYPTO`\|`AUTO` | 收款方式，**新接入必傳**、大小寫敏感。掃碼一律傳 `PAYNOW`（非 SGD 專用）；`AUTO` 不保證走掃碼 |
| `Chain` | Body | CRYPTO 必填 | `TRON`\|`ETHEREUM`\|`BSC` | 區塊鏈網路，僅 `DepositInstruction=CRYPTO` 有效 |
| `Currency` | Body | - | string | `SGD`/`AUD`/`HKD`/`MYR`/`PHP`/`INR`/`THB` 多幣種錢包；`CRYPTO` 須傳 `USDT`；不填用商戶預設幣別 |

響應：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `BillId` | ✅ | string | 平台帳單號 |
| `BillUrl` | ✅ | string(url) | 支付頁面地址 |
| `BuyerId` | - | string\|null | 回放 `SenderId` |
| `Date` | ✅ | string(ISO8601) | 帳單建立時間 |
| `Paid` | ✅ | boolean | 建立時固定為 `false` |
| `Param` | - | string\|null | 回放 `Param` |
| `ProductName` / `ShopName` / `Currency` | ✅ | - | 回放建立時的欄位 |
| `Amount` | ✅ | number | **應付金額，展示與對帳一律以此為準**；部分通道（如 HKD）會微調，不保證等於請求金額 |
| `RequestedAmount` / `PayableAmount` | ✅ | number | 商戶請求金額 / 付款人實際應付金額 |
| `FeeRate` / `FeeAmount` / `NetAmount` | ✅ | number | 本單鎖定的有效費率 / 手續費 / 預計入帳淨值（`PayableAmount − FeeAmount`） |
| `ExpiresAt` | ✅ | string(ISO8601) | 帳單過期時間 |
| `BillAccessToken` | ✅ | string | 付款頁訪問令牌，嵌入 `BillUrl` 的 `#token=[REDACTED] `PayerName` 時用於前端提交姓名；外部收銀台連結不得附加 |
| `DepositInstruction` | ✅ | string\|null | **實際收款方式，與請求值不一定相同**：傳 `PAYNOW` 建立的新掃碼單回 `QR`，`AUTO` 回選定方式；歷史單另可能為 `PAYNOW_I1QR` / `AUTO` / `null` |
| `CryptoChain` / `CryptoAddress` / `CryptoAddressTag` | - | string\|null | 僅 `CRYPTO` 有值：鏈名、鏈上收款地址、地址 Tag/Memo |

約束：
- `Currency` 不填時使用商戶 `defaultCurrency`；顯式幣別須為平台已開通的錢包與收款方式。
- `Bill` 的 `CallbackUrl` 不填時，依序 fallback 至商戶收款回調預設、再 fallback 商戶通用回調預設；**不可傳空字串或 `null`**（回 400）。
- 傳入未支援的 `DepositInstruction` 列舉值回 `400 VALIDATION_ERROR`；方式或幣別未開通回 `422 NO_AVAILABLE_PAYMENT_ACCOUNT`。
- 低於最小額/高於最大額回傳 `AMOUNT_BELOW_MIN` / `AMOUNT_ABOVE_MAX`。
- 掃碼單建立時即產生收款戶與 QR，但**通道單常外跳收銀台，不保證 `GET /api/v1/bills/{billId}/qr` 有碼**，一律引導會員開完整 `BillUrl`。
- `INR` UPI：付款人須在託管付款頁提交 12 位 UTR（非商戶 API 欄位）。

請求範例：

```bash
curl -X POST https://www.judy88.xin/api/v1/bills/create \
  -H "Authorization: PaymentKey:PaymentPass" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: bill-20260327-001" \
  -d '{
    "ShopName": "Demo Shop",
    "ProductName": "Topup",
    "Amount": 120.5,
    "DepositInstruction": "PAYNOW",
    "PayerName": "TAN AH KAU",
    "CallbackUrl": "https://merchant.example/callback"
  }'
```

### 查詢收款單 — `GET /api/v1/bills/{billId}`

回傳欄位在建立回應基礎上另含：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `Status` | ✅ | `PENDING\|PAID\|SETTLED\|EXPIRED\|CANCELLED` | 帳單狀態 |
| `PaidAt` | - | string(ISO8601)\|null | 支付完成時間，未支付為 null |

約束：僅可查詢自己商戶名下 `billId`；`404` 表示不存在或不屬於該商戶。

### 取消收款單 — `POST /api/v1/bills/{billId}/cancel`

- `PAID` 狀態不可取消（回 422）；`CANCELLED` / `EXPIRED` 重複取消回 422。
- 成功後狀態固定為 `CANCELLED`。

### 取得二維碼 — `GET /api/v1/bills/{billId}/qr`

回傳 `QrString`（原始字串，需自行生成 QR 圖，非圖片 URL）。帳單須存在 `qrString` 才會回傳，否則 404（`QR code is not available for this bill`）。PayNow 收款單建立時即會生成 `qrString`，建立完成即可呼叫。

## 代付（Payout）

### 取得可用收款銀行清單 — `GET /api/v1/payout-recipient-banks`

回傳 `Banks: string[]`，為目前商戶已啟用可用於 `RecipientBank` 的銀行代碼（如 `DBS`、`UOB`）。**串接前務必先呼叫此接口取得實際代碼清單**，用於對照 `banks` 表 mapping。

### 建立代付 — `POST /api/v1/payouts/create`

透過 `PayoutMethod` 區分方式，不傳時預設 `BANK_TRANSFER`。建立成功後金額（本金 + 代付手續費 `withdrawRate`）會即時凍結（凍結出商戶可用餘額，結算完成才實際扣除/解凍）。

#### 銀行轉帳（`PayoutMethod=BANK_TRANSFER`，預設）

| 欄位 | 位置 | 必填 | 類型 | 說明 |
|---|---|---|---|---|
| `RecipientAccount` | Body | ✅ | string | 收款帳號，1-50 字元 |
| `RecipientName` | Body | ✅ | string | 收款人姓名，1-100 字元 |
| `RecipientBank` | Body | ✅ | string | 收款銀行代碼，須為已啟用代碼（見上方銀行清單接口） |
| `Amount` | Body | ✅ | number | 代付金額（本金），須大於 0 且在額度內 |
| `CallbackUrl` | Body | - | string(url) | 本筆代付專用回調地址 |
| `Param` | Body | - | string | 透傳字段，最長 255 字元 |
| `Currency` | Body | - | string | 目前僅支援 `SGD` |

#### PayNow（`PayoutMethod=PAYNOW`）

| 欄位 | 位置 | 必填 | 類型 | 說明 |
|---|---|---|---|---|
| `PayNowProxyType` | Body | ✅ | `UEN`\|`MOBILE` | PayNow 代收類型 |
| `PayNowProxy` | Body | ✅ | string | UEN 或手機號，服務端會正規化，須符合 PayNow 常見格式 |
| `RecipientName` | Body | ✅ | string | 收款人 PayNow 登記名/顯示名，1-100 字元 |
| `Amount` | Body | ✅ | number | 代付本金 |
| `RecipientBank` / `RecipientAccount` | - | **禁止傳** | - | 傳了回 400 |

平台在建立時即用 SGQR 產生 QR 供收款人掃碼收款；**商戶 API 不回傳 QR 字符串**（此點與 PayNow 收款單不同）。

響應（兩種模式共用）：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `PayoutId` | ✅ | string | 平台代付單號 |
| `PayoutMethod` | ✅ | `BANK_TRANSFER`\|`PAYNOW` | 代付方式 |
| `PayNowProxyType` / `PayNowProxy` | - | -\|null | 銀行轉帳時為 null |
| `Amount` | ✅ | number | 本金 |
| `FeeAmount` | ✅ | number | 代付手續費 |
| `TotalDeductAmount` | ✅ | number | 凍結/扣款總額（本金+手續費） |
| `Date` | ✅ | string(ISO8601) | 建立時間 |
| `Error` | - | string\|null | 建立成功時為 null |
| `Paid` | ✅ | boolean | 建立時固定為 `false` |
| `RecipientAccount` | ✅ | string | 銀行轉帳為帳號，PayNow 為規範化代理值 |
| `RecipientBank` | ✅ | string | 銀行 code，PayNow 固定為 `PAYNOW` |
| `Currency` | ✅ | string | 實際幣別 |

約束：
- 銀行轉帳：`RecipientBank` 須為已啟用代碼；誤傳 PayNow 字段回 400。
- PayNow：須同傳 `PayNowProxyType` + `PayNowProxy`；誤傳 `RecipientBank`/`RecipientAccount` 回 400；代理格式無效回 400；QR 生成失敗回 422 `PAYNOW_QR_GENERATION_FAILED`。
- 商戶在額度組合或可用 ACTIVE 收款戶不存在時回 422 `PAYMENT_ACCOUNT_NOT_FOUND`。
- 可用餘額不足回 422 `INSUFFICIENT_BALANCE`。
- 建立成功即凍結 `TotalDeductAmount`，後續結算完成才實際扣款或解凍釋放。

**業務拒絕（4xx）實際響應格式（實測，非文件記載）**：與上方成功結構的扁平 `Error`（string|null）不同，
`INSUFFICIENT_BALANCE` 等 422 業務拒絕實測回傳巢狀小寫 `error` 物件：

```json
{ "error": { "code": "INSUFFICIENT_BALANCE", "message": "Insufficient merchant wallet balance" } }
```

串接時兩種格式（扁平 `Error` 與巢狀 `error.code`/`error.message`）都需解析，見 `Withdrawal.php` 的 `interpretResult`。

請求範例（銀行轉帳）：

```bash
curl -X POST https://www.judy88.xin/api/v1/payouts/create \
  -H "Authorization: PaymentKey:PaymentPass" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: payout-20260420-001" \
  -d '{
    "RecipientAccount": "12345678",
    "RecipientName": "Alice",
    "RecipientBank": "DBS",
    "Amount": 88.8,
    "Param": "order-001"
  }'
```

請求範例（PayNow）：

```bash
curl -X POST https://www.judy88.xin/api/v1/payouts/create \
  -H "Authorization: PaymentKey:PaymentPass" \
  -H "Content-Type: application/json" \
  -d '{
    "PayoutMethod": "PAYNOW",
    "PayNowProxyType": "MOBILE",
    "PayNowProxy": "+65 9123 4567",
    "RecipientName": "Alice",
    "Amount": 88.8,
    "Param": "wd-001"
  }'
```

### 查詢代付 — `GET /api/v1/payouts/{payoutId}`

回傳欄位在建立回應基礎上另含：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `Status` | ✅ | `PENDING\|APPROVED\|PROCESSING\|PAID\|FAILED\|CANCELLED` | 代付狀態 |
| `PaidAt` | - | string(ISO8601)\|null | 完成支付時間，未完成為 null |

約束：僅可查詢自己商戶名下 `payoutId`；`404` 表示不存在或不屬於該商戶。

## 回調通知

平台主動 callback 到商戶配置的回調地址，不需要回傳簽名；商戶只需回 2xx，並用查詢接口做二次確認。

**回調重試策略（分鐘級間隔）**：0, 1, 2, 5, 10, 30, 60, 120, 240。

### Bill 支付回調

支付成功或失敗平台皆會 POST 回調到商戶地址。

Payload：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `BillId` | ✅ | string | 帳單號 |
| `Amount` | ✅ | number | 帳單金額（買家支付毛額） |
| `FeeRate` / `FeeAmount` | - / ✅ | number\|null | 本筆鎖定的有效費率 / 手續費金額 |
| `NetAmount` | - | number\|null | 商戶錢包入帳淨值（`Amount − FeeAmount`）；手續費未確定時為 `null` |
| `BuyerId` | - | string\|null | 商戶側買家標識 |
| `Paid` | ✅ | boolean | 是否支付成功 |
| `Param` | - | string\|null | 透傳字段 |
| `ProductName` / `ShopName` | ✅ | string | 回放建立時欄位 |

```json
{
  "BillId": "550e8400-e29b-41d4-a716-446655440000",
  "Amount": 120.5,
  "BuyerId": "buyer-1",
  "Paid": true,
  "Param": "order-001",
  "ProductName": "Topup",
  "ShopName": "Demo Shop"
}
```

### Payout 回調

- `POST /api/v1/payouts/create` **僅回傳同步 JSON**（`Paid` 固定為 `false`），**不會在建立當下立即**依你傳的 `CallbackUrl` 發一筆 HTTP POST。
- 當運營側產生需要通知的結果（標記已付/終結/失敗/取消等）時，該筆代付若當時能解析到回調 URL，平台才會將一筆代付回調**入隊**並 POST 到你配置的地址（非建立當下、單次入隊回調，同筆有可能重試多次 POST）。
- 回調 URL 優先序：建立請求體 `CallbackUrl` → 商戶後台配置的預設代付回調 → 兼容的「預設回調」。**皆未配置則不會發出代付 HTTP 回調。**
- 請求約定：`POST`、`Content-Type: application/json`、UTF-8 JSON，欄位名 **PascalCase**。**沒有** `Status` 或 `Currency` 欄位，業務結果請用 `Paid`（布林）與 `Error`（字符串或 null）判斷，幣別請以 `GET /api/v1/payouts/{payoutId}` 為準。

Payload：

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `PayoutId` | ✅ | string | 平台代付 ID（UUID），建議以此做冪等（同一筆可能在重試時收到多次 POST） |
| `Amount` | ✅ | number | 代付金額 |
| `Paid` | ✅ | boolean | 平台側狀態為 `PAID` 時為 `true`；其餘情況（含失敗、取消、處理中快照等）皆為 `false` |
| `PayoutMethod` | ✅ | `BANK_TRANSFER`\|`PAYNOW` | 代付方式 |
| `PayNowProxyType` | - | `UEN`\|`MOBILE`\|null | 銀行轉帳時為 null |
| `RecipientAccount` | ✅ | string | 銀行轉帳為帳號，PayNow 為規範化代理值 |
| `RecipientBank` | ✅ | string | 銀行 code，PayNow 為 `PAYNOW` |
| `Param` | ✅ | string\|null | 建立時 `Param` 回放，未傳為 `null` |
| `Error` | ✅ | string\|null | `Paid=true` 時為 `null`；`Paid=false` 時多為失敗/終結/取消原因，也可能為 `null`（例如仍在處理中），此時須以查詢接口為準 |

語意小結：
- `Paid: true` 且 `Error: null` → 成功已處理。
- `Paid: false` 且 `Error` 非空 → 未成功，原因見 `Error`。
- `Paid: false` 且 `Error: null` → 未確定成敗，可能仍在處理，須呼叫 `GET /api/v1/payouts/{payoutId}` 確認。

回調範例（成功）：

```json
{
  "PayoutId": "550e8400-e29b-41d4-a716-446655440000",
  "Amount": 88.8,
  "Paid": true,
  "RecipientAccount": "12345678",
  "RecipientBank": "DBS",
  "Param": "order-001",
  "Error": null
}
```

回調範例（失敗/終結/取消）：

```json
{
  "PayoutId": "550e8400-e29b-41d4-a716-446655440000",
  "Amount": 88.8,
  "Paid": false,
  "RecipientAccount": "12345678",
  "RecipientBank": "DBS",
  "Param": "order-001",
  "Error": "Rejected by operator"
}
```

## 狀態流轉

### Bill 生命週期

```
PENDING -> PAID -> SETTLED
PENDING -> EXPIRED
PENDING -> CANCELLED
```

### Payout 生命週期

```
PENDING -> APPROVED -> PROCESSING -> PAID
                     -> FAILED（終結/失敗）
                     -> CANCELLED（取消）
```

代付 HTTP 回調在 **PAID / FAILED / CANCELLED** 等需通知場景才入隊（非建立時），一律用 `Paid` / `Error` 表達結果，無獨立 `Status` 欄位。

## 關鍵規則與常見誤區

1. Create Bill 與 Create Payout **必須**使用 `Idempotency-Key`，避免網絡重試導致重複提交。
2. `BillAccessToken` 僅在建立回應返回一次，需由 `BillUrl` 交給終端用戶；未傳 `PayerName` 時用於付款頁提交姓名。
3. **不要只依賴 Callback 判斷成敗**，必須呼叫查詢接口再次確認。
4. 鑑權失敗時應排查 `PaymentKey` / `PaymentPass`、商戶狀態、IP 白名單。
5. Payout 建立會凍結整體餘額（凍結出結算完成才實際釋放），非在交割側即刻扣減可用餘額。
6. PayNow 帳單在建立時立即發送收款戶並生成 QR，可透過 `GET /api/v1/bills/{billId}/qr` 取得二維碼字符串；但**通道單會外跳收銀台，不保證有碼**，引導會員開完整 `BillUrl` 才是通則。
7. **收款方式須先由平台為該商戶開通**，未開通的方式建單回 `422 NO_AVAILABLE_PAYMENT_ACCOUNT`；新增支付通道前先跟廠商確認。
8. **回應的 `DepositInstruction` 不等於請求值**（傳 `PAYNOW` 回 `QR`），不要拿它跟請求值做相等比對。
9. 代付的 `AUTO` 與代收的 `AUTO` 語意不同：代付 `AUTO` 一律按銀行轉帳處理。
