# EasyPay 廠商規格

> 官方 API 文檔：`https://example.invalid/api/documentation`（需登入商戶後台後才可存取）
> 內部實作路徑：`app/Services/Payment/Providers/EasyPay/`

EasyPay 是面向香港（HKD）與新加坡（SGD）市場的第三方支付平台，支援代收（Deposit）與代付（Withdrawal）。認證方式採用 **HTTP Basic Auth**（商戶代碼為帳號，API 密碼為密碼），並在 Header 額外帶入 `merchant` 欄位。所有請求以 **JSON** 格式提交，響應亦為 JSON。

> ⚠️ **安全性重要警示**：EasyPay 的代收與代付回調**完全不進行簽名驗證**。系統僅靠 IP 白名單防護，未實作任何 HMAC / MD5 / RSA 等回調簽章核實機制。部署時必須確保嚴格的 IP 白名單設定，否則任何人皆可偽造回調。

---

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [認證方式](#3-認證方式)
4. [代收 API](#4-代收-api)
5. [代付 API](#5-代付-api)
6. [餘額查詢 API](#6-餘額查詢-api)
7. [銀行列表查詢 API](#7-銀行列表查詢-api)
8. [回調處理](#8-回調處理)
9. [銀行代碼映射](#9-銀行代碼映射)
10. [費率與限額參考](#10-費率與限額參考)
11. [參考資料](#11-參考資料)

---

## 1. 環境資訊

| 項目 | 值 |
|---|---|
| 生產環境 API Base URL（HKD） | `https://example.invalid/api` |
| 生產環境 API Base URL（SGD） | `https://example.invalid/api` |
| 更新後域名（2026-05 起） | `https://yizypayonline.online/api` |
| 商戶後台（HKD） | `https://example.invalid/web` |
| 商戶後台（SGD） | `https://example.invalid/web` |
| 請求格式 | JSON（`application/json`） |
| 響應格式 | JSON |
| 認證方式 | HTTP Basic Auth + `merchant` Header |

> 配置 key `API_URL` 指向對應環境的 Base URL，`MERCHANT_NAME` 為商戶代碼，`MERCHANT_PASSWORD` 為 API 密碼。

---

## 2. 支援的支付方式與幣別

### SGD（新加坡元）

| 支付類型 | 訂單類型 key | 端點路徑 | 說明 |
|---|---|---|---|
| PayNow（代收） | `paynow` | `POST /deposit/paynow` | 新加坡即時轉帳 |
| P2P 銀行轉帳（代收） | `bank_order` | `POST /deposit/bank_order` | 銀行對銀行轉帳 |
| 代付（PayNow） | — | `POST /withdraw/paynow` | 收款方用 PayNow 接收 |
| 代付（銀行轉帳） | — | `POST /withdraw/bank_order` | 收款方為銀行帳號 |

### HKD（港元）

| 支付類型 | 訂單類型 key | 端點路徑 | 說明 |
|---|---|---|---|
| 銀行轉帳（代收） | `bank_order` | `POST /deposit/bank_order` | 香港銀行轉帳 |
| 轉數快 FPS（代收） | `paynow` | `POST /deposit/paynow` | 轉數快（FPS）即時支付 |
| 代付（銀行轉帳） | — | `POST /withdraw/bank_order` | 收款方為銀行帳號 |

> 路由邏輯：`Deposit.php` 依 `$order->orderType->key` 自動組成端點路徑 `/deposit/{key}`。

---

## 3. 認證方式

所有 API 請求均需同時帶入以下認證資訊：

**HTTP Headers**

| Header | 值 | 說明 |
|---|---|---|
| `Authorization` | `Basic base64({MERCHANT_NAME}:{MERCHANT_PASSWORD})` | HTTP Basic Auth |
| `merchant` | `{MERCHANT_NAME}` | 商戶代碼（額外帶入 Header） |
| `Content-Type` | `application/json` | 請求格式 |

**Basic Auth 組成**

```
Authorization = "Basic " + base64_encode("{MERCHANT_NAME}:{MERCHANT_PASSWORD}")
```

> 工單 #3418 說明：API 密鑰重置時，`MERCHANT_NAME`（商戶代碼）保持不變，只需更新 `MERCHANT_PASSWORD`，並重新計算 `Authorization` Header。

---

## 4. 代收 API

### 代收發起 — `POST /deposit/{channel}`

`{channel}` 依訂單類型 key 填入，例如 `/deposit/paynow` 或 `/deposit/bank_order`。

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `amount` | ✅ | decimal | 代收金額（保留 2 位小數，例如 `100.00`） |
| `tx_id` | ✅ | string | 商戶訂單號（對應 `order.order_no`，系統內唯一） |
| `transferror_name` | ✅ | string | 付款人帳號（填 `member.username`） |
| `callback_url` | ✅ | string | 代收回調 URL |
| `redirect_url` | ✅ | string | 支付完成後跳轉 URL |

**請求範例**

```json
{
    "amount": "100.00",
    "tx_id": "ORD20260601001",
    "transferror_name": "player001",
    "callback_url": "https://example.invalid/callback/easypay/deposit",
    "redirect_url": "https://example.invalid/return"
}
```

**成功響應**

```json
{
    "status": 1,
    "data": {
        "order_id": "EP20260601001",
        "payment_details": {
            "redirect_url": "https%3A%2F%example.invalid%2Fpay%3Ftoken%3Dxxx"
        }
    }
}
```

- `status === 1` 才視為成功（**嚴格整數比對**，非布林值）
- `data.order_id` 為三方訂單號，系統需記錄
- `data.payment_details.redirect_url` 為收銀台 URL，需 `urldecode()` 後導向使用者

**失敗響應**

```json
{
    "status": 0,
    "code": "INVALID_AMOUNT"
}
```

- 失敗時取 `result['code']` 作為錯誤訊息記錄

---

## 5. 代付 API

代付根據銀行代碼自動路由至不同端點：
- 銀行代碼為 `PAYNOW` → `POST /withdraw/paynow`（不帶 `bank_code`）
- 其他銀行代碼 → `POST /withdraw/bank_order`（帶 `bank_code`）

### 代付發起 — `POST /withdraw/bank_order` / `POST /withdraw/paynow`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `amount` | ✅ | string | 代付金額（保留 2 位小數，例如 `"100.00"`） |
| `tx_id` | ✅ | string | 商戶代付訂單號（前綴 `EASY`，系統內唯一） |
| `callback_url` | ✅ | string | 代付回調 URL |
| `bank_code` | ✅（bank_order） | string | 銀行代碼（走 `/withdraw/paynow` 時不帶此欄） |
| `account_number` | ✅ | string | 收款帳號 |
| `account_name` | ✅ | string | 收款人姓名 |

**請求範例（bank_order）**

```json
{
    "amount": "500.00",
    "tx_id": "EASY20260601001",
    "callback_url": "https://example.invalid/callback/easypay/withdrawal",
    "bank_code": "7171",
    "account_number": "0123456789",
    "account_name": "John Doe"
}
```

**請求範例（paynow）**

```json
{
    "amount": "500.00",
    "tx_id": "EASY20260601002",
    "callback_url": "https://example.invalid/callback/easypay/withdrawal",
    "account_number": "87654321",
    "account_name": "Jane Smith"
}
```

**成功響應**

```json
{
    "status": 1,
    "data": {
        "order_id": "EP-WD20260601001"
    }
}
```

- `status === 1` 才視為成功（**嚴格整數比對**）
- `data.order_id` 為三方代付訂單號
- 代付為非同步處理，最終結果透過回調通知

**失敗響應**

```json
{
    "status": 0,
    "message": "Balance not enough"
}
```

- 失敗時取 `result['message']` 作為錯誤訊息

---

## 6. 餘額查詢 API

### 商戶餘額查詢 — `POST /merchant/check_balance`

**請求參數**

無需請求 body，僅需帶認證 Header。

**成功響應**

```json
{
    "status": 1,
    "data": {
        "balance": "9850.00"
    }
}
```

- 系統取 `data.balance` 作為 `depositBalance`
- `withdrawBalance` 不提供（回傳 `null`）

---

## 7. 銀行列表查詢 API

### 代付銀行列表 — `POST /withdraw/get_bank_list`

查詢可用的代付銀行清單。請求 body 為空，僅需帶認證 Header。

**成功響應**

```json
{
    "status": 1,
    "data": [
        { "bank_name": "DBS Bank Limited", "bank_code": "7171" },
        { "bank_name": "OCBC Bank", "bank_code": "7339" }
    ]
}
```

---

## 8. 回調處理

> ⚠️ **安全性警示：EasyPay 回調不驗簽**
>
> EasyPay 的代收與代付回調**沒有任何簽名驗證機制**（無 token、無 HMAC、無 RSA）。系統僅依賴 IP 白名單作為唯一的安全保護。務必在防火牆層確保只允許 EasyPay 官方 IP 發送回調請求，否則惡意第三方可偽造回調，造成虛假入帳或出帳。

### 代收回調

EasyPay 在交易完成後，以 **POST JSON** 格式推送至代收請求中的 `callback_url`。

**回調欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `tx_id` | ✅ | string | 商戶訂單號（對應 `order.order_no`） |
| `transferror_name` | ✅ | string | 付款人帳號 |
| `status` | ✅ | string | 訂單狀態（見下表） |
| `order_id` | ✅ | string | 三方訂單號 |
| `final_amount` | ✅ | string | 實際到帳金額 |
| `order_amount` | ✅ | string | 訂單金額（用於金額核驗） |
| `service_change` | ✅ | string | 手續費 |

**代收 status 狀態值**

| status | 說明 |
|---|---|
| `COMPLETE` | 代收成功（終態，觸發入帳） |
| 其他 | 代收失敗（終態） |

> 成功判定：`status === 'COMPLETE'`（二分判定，非 COMPLETE 即視為失敗）

**回調響應**

系統回傳純文字表示接收結果：

| 情況 | 回應 |
|---|---|
| 處理成功 | `success` |
| 處理失敗 | `fail` |

**代收回調範例**

```json
{
    "tx_id": "ORD20260601001",
    "transferror_name": "player001",
    "status": "COMPLETE",
    "order_id": "EP20260601001",
    "final_amount": "97.10",
    "order_amount": "100.00",
    "service_change": "2.90"
}
```

---

### 代付回調

EasyPay 在代付完成後，以 **POST JSON** 格式推送至代付請求中的 `callback_url`。

**回調欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `tx_id` | ✅ | string | 商戶代付訂單號 |
| `status` | ✅ | string | 代付狀態（見下表） |
| `order_id` | ✅ | string | 三方代付訂單號 |
| `final_amount` | ✅ | string | 實際代付金額 |
| `order_amount` | ✅ | string | 訂單金額（成功時用於金額核驗） |
| `service_change` | ✅ | string | 手續費 |

**代付 status 狀態值**

| status | 說明 |
|---|---|
| `COMPLETE` | 代付成功（終態，觸發出帳） |
| 其他 | 代付失敗（終態） |

> 成功判定：`status === 'COMPLETE'`（二分判定）
> 金額核驗：**僅在代付成功時**核驗 `order_amount` 是否符合訂單金額；失敗時金額可能不準確，跳過核驗。

**代付回調範例**

```json
{
    "tx_id": "EASY20260601001",
    "status": "COMPLETE",
    "order_id": "EP-WD20260601001",
    "final_amount": "490.50",
    "order_amount": "500.00",
    "service_change": "9.50"
}
```

---

## 9. 銀行代碼映射

### SGD — 代收

| 支付類型 | 訂單 key | 說明 |
|---|---|---|
| PayNow | `paynow` | 新加坡 PayNow 即時轉帳 |
| P2P 銀行轉帳 | `bank_order` | 銀行對銀行轉帳 |

### SGD — 代付 bank_code

| bank_code | 銀行名稱 |
|---|---|
| `PAYNOW` | PayNow（走 `/withdraw/paynow` 端點，不帶 bank_code） |
| `POSB` | POSB Bank |
| `7171` | DBS Bank Limited |
| `7339` | OCBC Bank |
| `7375` | United Overseas Bank（UOB） |
| `7302` | Maybank |
| `7232` | HSBC Bank |
| `7214` | Citibank |
| `9496` | Standard Chartered Bank |
| `7083` | Bank of China |
| `8712` | ICBC Bank |
| `7986` | CIMB Bank |
| `9742` | Australia And New Zealand Bank |
| `7052` | BNP Paribas |
| `7463` | Deutsche Bank |
| `7065` | Bank of America |
| `7092` | Bank of East Asia |
| `7108` | Bank of India |
| `7250` | Indian Bank / Indian Overseas Bank |
| `7287` | Hong Leong Bank |
| `7314` | Singapura Finance Ltd |
| `7366` | RHB Bank |
| `7472` | Sumitomo Mitsui Banking Corporation |
| `7621` | Mizuho Bank |
| `7685` | UBS AG |
| `7764` | First Commercial Bank |
| `7791` | State Bank of India |
| `7852` | Societe Generale |
| `8505` | UniCredit Bank AG |
| `8502` | Svenska Handelsbanken AB |
| `8606` | Commerzbank Aktiengesellschaft |
| `9171` | GXS Bank |
| `9172` | Maribank |
| `9173` | ANEXT Bank |
| `9186` | Singapore Automated Clearing House |
| `9187` | Sing Investments & Finance Limited |
| `9326` | Qatar National Bank |
| `9353` | CTBC Bank |
| `9733` | Trust Bank |
| `7047` | Bangkok Bank Public Company Limited |
| `7091` | ING Bank |
| `7135` | Credit Agricole CIB |
| `7143` | Far Eastern Int'l Bank |
| `7155` | Siam Commercial Bank |
| `7056` | Bank Negara Indonesia |
| `7194` | Royal Bank of Scotland |

### HKD — 代收

| 支付類型 | 訂單 key | 說明 |
|---|---|---|
| 銀行轉帳 | `bank_order` | 香港銀行轉帳 |
| 轉數快 FPS | `paynow` | 香港即時支付（Faster Payment System） |

### HKD — 代付 bank_code（香港銀行代碼）

EasyPay HKD 代付使用香港銀行清算代碼（3 位數字）：

| bank_code | 銀行名稱 |
|---|---|
| `003` | Standard Chartered Bank（渣打銀行） |
| `004` | HSBC（匯豐銀行） |
| `009` | China Construction Bank（中國建設銀行） |
| `012` | Bank of China（中國銀行） |
| `015` | The Bank of East Asia（東亞銀行） |
| `016` | DBS Bank（星展銀行） |
| `018` | China CITIC Bank（中信銀行） |
| `020` | CMB Wing Lung Bank（招商永隆銀行） |
| `024` | Hang Seng Bank（恒生銀行） |
| `025` | Shanghai Commercial Bank（上海商業銀行） |
| `027` | Bank of Communications（交通銀行） |
| `028` | Public Bank（大眾銀行） |
| `035` | OCBC Wing Hang Bank（華僑永亨銀行） |
| `038` | Tai Yau Bank（大有銀行） |
| `039` | Chiyu Banking Corporation（集友銀行） |
| `040` | Dah Sing Bank（大新銀行） |
| `041` | Chong Hing Bank（創興銀行） |
| `043` | Nanyang Commercial Bank（南洋商業銀行） |
| `072` | Industrial and Commercial Bank of China（工商銀行） |
| `128` | Fubon Bank（富邦銀行） |
| `250` | Citibank（花旗銀行） |
| `387` | ZA Bank Limited（眾安銀行） |
| `388` | Livi Bank Limited（理慧銀行） |
| `389` | Mox Bank |
| `390` | Welab Bank（匯立銀行） |
| `391` | Fusion Bank（富融銀行） |
| `392` | Ping An OneConnect Bank（平安壹賬通銀行） |
| `393` | Ant Bank（螞蟻銀行） |
| `395` | Airstar Bank（天星銀行） |

> ⚠️ FPS（轉數快）`FPS` 代碼及 `930`（Global Cash 全球付）均為特殊轉帳通道，非銀行機構，已從代付銀行清單中排除。

---

## 10. 費率與限額參考

以下費率為工單記錄的典型值，實際費率以商戶協議與後台設定為準。

### HKD

| 類型 | 費率 | 限額 |
|---|---|---|
| 代收（網銀/轉數快）— 小額 | 2.9%～3.1% | 100～300 HKD |
| 代收（網銀/轉數快）— 大額 | 2.9% | 301～100,000 HKD |
| 代收（二維碼） | 3.5% | 100～10,000 HKD |
| 代付 | 1.9% | 100～1,000,000 HKD |

> HKD 小額定義：100～300；大額定義：301 以上。新商戶（2026 起）不再分大小額，統一費率（代收銀行/快轉 2.9%，代付 1.9%）。

### SGD

| 類型 | 費率 | 說明 |
|---|---|---|
| 代收 | 依商戶協議 | PayNow / P2P |
| 代付 | 依商戶協議 | PayNow / 銀行轉帳 |

---

## 11. 參考資料

- 官方 API 文檔（HKD）：`https://example.invalid/api/documentation`（需登入商戶後台）
- 官方 API 文檔（SGD）：`https://example.invalid/api/documentation`（需登入商戶後台）
- 更新後域名（2026-05）：`https://yizypayonline.online`
- 內部實作路徑：`app/Services/Payment/Providers/EasyPay/`
  - `Driver.php` — 組合代收/代付/餘額子類別
  - `EasyPayTrait.php` — HTTP 請求發送（Basic Auth + `merchant` Header）
  - `Deposit.php` — 代收實作，依 `orderType.key` 路由至 `/deposit/{channel}`
  - `DepositCallback.php` — 代收回調處理（**無驗簽**，依 IP 白名單）
  - `Withdrawal.php` — 代付實作，`bank_code=PAYNOW` 走 `/withdraw/paynow`，其他走 `/withdraw/bank_order`
  - `WithdrawalCallback.php` — 代付回調處理（**無驗簽**，依 IP 白名單）
  - `Balance.php` — 餘額查詢，呼叫 `/merchant/check_balance`
- GitLab 工單參考：#3434（HKD 接入）、#3431（DG-HKD 接入）、#3490（TPower HKD 配置）、#3491（Yes2Win HKD 配置）、#3418（API 密鑰更換說明）、#3395（SGD 接入）、#3817（域名更新）、#2755（TP HKD 費率）、#2797（Yes2Win HKD 配置）

---

*文件版本：1.0 / 整理日期：2026-06-01*
*資料來源：Codebase 實作（最權威）、GitLab 工單 #3434 / #3431 / #3490 / #3491 / #3418 / #3395 / #3817 / #2755 / #2797；官方 API 文檔需登入無法直接擷取*
