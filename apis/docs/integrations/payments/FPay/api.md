# FPay 廠商規格

> 官方文檔：https://doc.fpay.support/#introduction
> 內部實作路徑：`app/Services/Payment/Providers/FPay/`

FPay 是支援馬來西亞、越南、印尼等東南亞市場的第三方支付平台，提供代收（Deposit）與代付（Withdrawal）。採用**兩步驟認證**流程：先呼叫 `/merchant/auth` 取得一次性 `auth` token，再以該 token 作為後續 API 請求的身份憑據。所有請求使用 **FORM-DATA（multipart/form-data）** 格式提交，響應為 JSON。

## 環境資訊

| 項目 | 值 |
|---|---|
| 生產環境 API Base URL | `https://liveapi.fpay.support` |
| 沙盒環境 API Base URL | `https://sandboxapi.fpay.asia` |
| 商戶後台（生產） | `https://portal.fpay.support/` |
| 商戶後台（沙盒） | `http://sandbox-merchant.fpay.asia` |
| 請求格式 | FORM-DATA（multipart/form-data） |
| 響應格式 | JSON |

## 支援的支付方式與幣別

### MYR（馬來西亞令吉）

| 支付類型 | bank_code | 說明 |
|---|---|---|
| Public Bank 網銀 | `PbeBank` | 馬來西亞網銀轉帳 |
| Maybank 網銀 | `MayBank` | 馬來西亞網銀轉帳 |
| CIMB Bank 網銀 | `CimbBank` | 馬來西亞網銀轉帳 |
| RHB Bank 網銀 | `RhbBank` | 馬來西亞網銀轉帳 |
| AmBank 網銀 | `AmBank` | 馬來西亞網銀轉帳 |
| Hong Leong Bank 網銀 | `HlbBank` | 馬來西亞網銀轉帳 |
| BSN 網銀 | `BSN` | Bank Simpanan Nasional |
| Alliance Bank 網銀 | `AllianceBank` | 馬來西亞網銀轉帳 |
| Affin Bank 網銀 | `AffinBank` | 馬來西亞網銀轉帳 |
| Touch 'n Go DuitNow QR | `Tng` | 電子錢包 QR |
| Maybank QR | `MayBankQR` | DuitNow QR |
| DuitNow QR | `DuitNowQR` | 通用 DuitNow QR |
| GrabPay | `GrabPay` | 電子錢包 |
| Boost | `Boost` | 電子錢包 |

> MYR 代收限額：網銀 Min 10 / Max 5,000；掃碼 Min 25 / Max 5,000

### IDR（印尼盾）

| 支付類型 | 說明 |
|---|---|
| VA（Virtual Account） | 虛擬帳號快速支付，`order_type` key = `FPay.VA` |
| QRIS | 印尼通用 QR 掃碼，`order_type` key = `FPay.EWallet` |

> IDR 代收限額：Deposit Min 10,000 / Max 10,000,000

### VND（越南盾）

| 支付類型 | 說明 |
|---|---|
| Bank QR | 銀行 QR 掃碼轉帳 |
| EWallet | 電子錢包（Momo 等） |

> VND 代收限額：Deposit Min 50,000 / Max 30,000,000

---

## API 接口

### Step 0：取得認證 Token — `POST /merchant/auth`

所有業務 API 呼叫前，必須先取得一次性 `auth` token。

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `username` | ✅ | string | 商戶帳號 |
| `api_key` | ✅ | string | 商戶 API Key |

**響應**

```json
{
    "status": true,
    "auth": "eyJxxxxxx"
}
```

- `status === true` 且 `auth` 存在才視為成功
- 取得的 `auth` 值作為後續請求的身份憑據，直接帶入 `auth` 欄位

---

### 代收 — `POST /merchant/generate_orders`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `username` | ✅ | string | 商戶帳號（會員 username） |
| `auth` | ✅ | string | 由 `/merchant/auth` 取得的 token |
| `amount` | ✅ | decimal | 訂單金額 |
| `currency` | ✅ | string | 幣別代碼（`MYR` / `IDR` / `VND` / `THB` 等） |
| `orderid` | ✅ | string | 商戶訂單號（系統內唯一） |
| `redirect_url` | - | string | 支付完成後跳轉 URL |
| `bank_code` | - | string | 銀行/錢包代碼（MYR 網銀/QR 必填，見支付方式表） |
| `customer_bank_holder_name` | - | string | 客戶持有人姓名（MYR 部分通道需要） |

> **THB 額外參數**：`currency_amount`、`customer_bank_account`、`bank_pid`、`pay_method`（`"thaiqr"` 或 `"truemoney"`）

**響應**

```json
{
    "status": true,
    "p_url": "https://pay.fpay.support/checkout?token=[REDACTED]"
}
```

- `status === true` 才視為成功
- `p_url` 為收銀台 URL，需 `urldecode()` 後導向使用者

**錯誤響應範例**

```json
{
    "status": false,
    "message": "Order ID Exist."
}
```

常見錯誤訊息（官方文檔四個幣別段落一致）：

| 訊息 | 說明 |
|---|---|
| `Params incorrect.` | 參數驗證失敗（含 auth 過期或錯誤） |
| `Params missing.` | 缺少必需參數 |
| `Currency invalid.` | 幣別代碼無效 |
| `Order ID Exist.` | 訂單號重複，需重新生成 |
| `Invalid Amount.` | 金額超出限額範圍 |
| `email incorrect.` | Email 不正確 |

---

### 代付 — `POST /merchant/withdraw_orders`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `auth` | ✅ | string | 由 `/merchant/auth` 取得的 token |
| `amount` | ✅ | decimal | 提款金額 |
| `currency` | ✅ | string | 幣別代碼 |
| `orderid` | ✅ | string | 商戶訂單號（系統內唯一） |
| `bank_id` | ✅ | string | 銀行代碼 ID（從銀行列表 API 取得） |
| `holder_name` | ✅ | string | 收款人姓名（必須與銀行帳號一致） |
| `account_no` | ✅ | string | 收款銀行帳號 |

> IDR 代付需額外帶 `IFSC_id` 欄位。`bank_id` 必須透過 `/wallet/withdraw_bank_list` API 動態查詢，不可寫死。

**響應**

```json
{
    "status": true,
    "message": "Success"
}
```

- `status === true` 才視為成功
- 代付為非同步處理，最終結果透過回調通知

**常見錯誤訊息**（官方文檔四個幣別段落一致）：

| 訊息 | 說明 |
|---|---|
| `Params incorrect` | 參數驗證失敗（含 auth 過期或錯誤） |
| `Params missing.` | 缺少必需參數 |
| `bank_id invalid.` | 銀行 ID 無效 |
| `Balance not enough!` | 商戶餘額不足 |
| `Amount Invalid!` | 金額超出限額範圍 |
| `Order id exist!` | 訂單號重複 |

---

### 銀行列表查詢 — `POST /wallet/withdraw_bank_list`

查詢特定幣別的可用提款銀行清單，`bank_id` 用於代付請求。

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `username` | ✅ | string | 商戶帳號 |
| `currency` | ✅ | string | 幣別代碼 |

**響應**

```json
{
    "status": true,
    "data": [
        { "currency": "MYR", "bank_name": "MAYBANK", "id": "246" },
        { "currency": "MYR", "bank_name": "CIMB", "id": "261" }
    ]
}
```

---

### 代收訂單查詢 — `POST /merchant/check_status`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `username` | ✅ | string | 商戶帳號 |
| `id` | ✅ | string | 商戶訂單號 |

**響應**

```json
{
    "status": true,
    "order_status": "completed",
    "order_datetime": "2021-11-18 15:00:00",
    "amount": 300.0000,
    "currency": "THB"
}
```

| `order_status` | 說明 |
|---|---|
| `completed` | 支付成功（終態） |
| `fail` | 支付失敗（終態） |
| `pending` | 處理中 |

---

### 代付訂單查詢 — `POST /merchant/check_withdraw_status`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `username` | ✅ | string | 商戶帳號 |
| `id` | ✅ | string | 商戶訂單號 |

**響應**

```json
{
    "status": true,
    "order_status": "completed",
    "order_datetime": "2021-11-18 15:00:00",
    "amount": 300.0000,
    "currency": "THB"
}
```

| `order_status` | 說明 |
|---|---|
| `completed` | 代付成功（終態） |
| `fail` | 代付失敗（終態） |
| `proccess` | 處理中 |
| `rejected` | 已拒絕（終態） |

---

### 幣別與限額查詢 — `POST /merchant/currency`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `username` | ✅ | string | 商戶帳號 |

**響應**

```json
{
    "status": true,
    "rate": [
        { "currency": "MYR", "min": "10.000000", "max": "5000.000000" }
    ]
}
```

---

## 回調（Callback）

### 代收回調

FPay 在支付成功或失敗後，以 **POST JSON** 格式推送到商戶設定的 `redirect_url`（或後台設定的 callback URL）。

**回調欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `order_id` | ✅ | string | 商戶訂單號 |
| `amount` | ✅ | string | 實際支付金額 |
| `currency` | ✅ | string | 幣別 |
| `order_status` | ✅ | string | 訂單狀態：`completed` / `fail` |
| `status` | ✅ | boolean | 總體狀態 |
| `charge` | ✅ | string | 手續費 |
| `token` | ✅ | string | 安全驗證 token（見簽名流程） |
| `name` | - | string | 付款人姓名 |
| `type` | ✅ | string | 固定值 `deposit` |
| `ccno` | - | string | 部分通道的卡號資訊 |
| `mode` | - | string | 支付模式 |
| `payment_type` | - | string | 支付方式類型（`EWallet` / `VA` 等，FPay 特有） |
| `username` | - | string | 會員帳號 |

**成功判定**：`order_status === "completed"`

**FPay 特殊邏輯**：回調帶有 `payment_type` 欄位，用於動態更新訂單的支付方式類型：
- `payment_type === "EWallet"` → 訂單類型更新為 `FPay.EWallet`
- 其他（`"VA"` 或空值） → 訂單類型更新為 `FPay.VA`

> 收銀台模式下，訂單建立時尚無支付方式類型，回調後才能確認實際使用的通道。

**回調響應**：回傳純文字 `success` 表示已接收；`fail` 表示處理失敗。

```
success
```

**回調範例**

```json
{
    "order_id": "ORDER20251030001",
    "amount": "100.00",
    "currency": "MYR",
    "order_status": "completed",
    "status": true,
    "charge": "2.60",
    "token": "d41d8cd98f00b204e9800998ecf8427e",
    "name": "John Doe",
    "type": "deposit",
    "payment_type": "EWallet"
}
```

---

### 代付回調

**回調欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `order_id` | ✅ | string | 商戶訂單號 |
| `amount` | ✅ | string | 代付金額 |
| `currency` | ✅ | string | 幣別 |
| `order_status` | ✅ | string | 狀態：`completed` / `fail` |
| `status` | ✅ | boolean | 總體狀態 |
| `charge` | ✅ | string | 手續費 |
| `token` | ✅ | string | 安全驗證 token |
| `name` | - | string | 收款人姓名 |
| `type` | ✅ | string | 固定值 `withdrawal` |
| `remarks` | ✅ | string | 處理結果說明：成功為 `Success`，失敗載明原因（例：`Account Holder Name Mismatch (MR SUK THA != SUK THAWAR)`）。**代收回調沒有這個欄位**，它是排查代付失敗原因的主要線索 |

**成功判定**：`order_status === "completed"`

---

## 簽名（Token）驗證流程

FPay 使用 **MD5 Hash** 作為回調簽名機制，無需 RSA 或 AES 加密。

### 驗證步驟

1. 取得回調中的 `order_id` 與 `token`
2. 使用商戶的 `SECRET_KEY` 與 `order_id` 計算：
   ```
   expected_token = [REDACTED] + order_id)
   ```
3. 比對 `expected_token` 與回調中的 `token` 是否一致
4. 一致則通過驗證，不一致則拒絕（回傳 `fail`）

### PHP 實作參考

```php
protected function generateToken(string $secret, string $orderId): string
{
    return md5($secret . $orderId);
}

// 回調驗證
if ($this->generateToken($this->info['APP_SECRET'], $input['order_id']) !== $input['token']) {
    throw new ApiException('Token verification failed');
}
```

> 注意：代收回調與代付回調使用相同的 token 計算方式，差別在於 `type` 欄位（`deposit` / `withdrawal`）。

---

## 銀行代碼映射

### MYR — 代收 bank_code（網銀 / DuitNow）

| 支付類型 | bank_code | 銀行/錢包名稱 |
|---|---|---|
| 網銀 | `PbeBank` | Public Bank |
| 網銀 | `MayBank` | Maybank |
| 網銀 | `CimbBank` | CIMB Bank |
| 網銀 | `RhbBank` | RHB Bank |
| 網銀 | `AmBank` | AmBank |
| 網銀 | `HlbBank` | Hong Leong Bank |
| 網銀 | `BSN` | Bank Simpanan Nasional |
| 網銀 | `AllianceBank` | Alliance Bank |
| 網銀 | `AffinBank` | Affin Bank |
| DuitNow QR | `Tng` | Touch 'n Go |
| DuitNow QR | `MayBankQR` | Maybank QR |
| DuitNow QR | `DuitNowQR` | DuitNow QR |
| 電子錢包 | `GrabPay` | GrabPay |
| 電子錢包 | `Boost` | Boost |

### MYR — 代付 bank_id（動態查詢，以下為參考值）

| bank_id | 銀行名稱 |
|---|---|
| 246 | MAYBANK |
| 261 | CIMB |
| 264 | HLB（Hong Leong Bank） |
| 273 | PUBLIC BANK |
| 274 | RHB |
| 250 | AMBANK |
| 275 | Standard Chartered Bank |
| 251 | BANK ISLAM |
| 257 | BSN |
| 248 | AL-RAHJI BANK |
| 277 | UOB |
| 265 | HSBC |
| 249 | ALLIANCE BANK |
| 262 | CITI BANK |
| 247 | AFFIN BANK |
| 252 | Bank Rakyat |
| 278 | TOUCH N GO |
| 280 | MBSB |
| 281 | BANK MUAMALAT |
| 282 | OCBC |
| 283 | AGRO BANK |

> ⚠️ MYR `bank_id` 為後台內部 ID，非 SWIFT code；請以 `/wallet/withdraw_bank_list` API 的實際回傳為準，不可寫死。

### MYR — 代付 bank_code（另一格式，早期商戶配置）

部分早期商戶配置使用 code 字串格式：

| bank_code | 銀行名稱 |
|---|---|
| `MAYB` | MAYBANK |
| `CIMB` | CIMB BANK |
| `HOLB` | HONG LEONG BANK |
| `PBBB` | PUBLIC BANK |
| `RHBB` | RHB BANK |
| `MYAB` | AMBANK |
| `STCB` | STANDARD CHARTERED BANK |
| `MYIS` | BANK ISLAM |
| `SINA` | BSN |
| `UOB` | UOB |
| `HSBC` | HSBC |
| `CITI` | CITI BANK |
| `AFIN` | AFFIN BANK |
| `RAKY` | BANK RAKYAT |
| `TNGP` | TOUCH N GO |
| `GXBK` | GXBANK |

### IDR — 代付 bank_code

| bank_code | 銀行/錢包名稱 |
|---|---|
| `BRI` | Bank BRI |
| `MANDIRI` | Bank Mandiri |
| `BNI` | Bank BNI |
| `DANAMON` | Bank Danamon |
| `BCA` | Bank BCA |
| `MAYB` | BII Maybank |
| `CIMB` | CIMB Niaga |
| `UOB` | Bank UOB Indonesia |
| `OCBC` | Bank OCBC NISP |
| `CITI` | Citibank |
| `HSBC` | HSBC |
| `MEGA` | Bank Mega |
| `AGRO` | Bank Rakyat Indonesia AGRONIAGA |
| `COMM` | Bank Commonwealth |
| `DANA` | Dana（電子錢包） |
| `Gopay` | GoPay（電子錢包） |
| `LINKAJA` | LinkAja（電子錢包） |
| `OVO` | OVO（電子錢包） |
| `SHOPEEPAY` | ShopeePay（電子錢包） |

### VND — 代付 bank_code

| bank_code | 銀行名稱 |
|---|---|
| `TECHB` | Techcombank |
| `SCB` | Sacombank |
| `VCB` | Vietcombank |
| `ACB` | Asia Commercial Bank |
| `DONGA` | DongA Bank |
| `VIETINBANK` | Vietin Bank |
| `BIDV` | Bank for Investment and Development of Vietnam |
| `EXB` | Eximbank Vietnam |
| `663` | Vietnam Prosperity Joint-Stock Commercial Bank（VPBank） |
| `664` | Military Commercial Joint Stock Bank（MBBank） |
| `TPB` | Tien Phong Commercial Bank |
| `AGRIBANK` | Agribank |
| `SHB` | Saigon-Hanoi Commercial Joint Stock Bank |
| `SAIGON` | Saigon Bank |
| `BAB` | Bac A Bank |
| `KLB` | Kien Long Commercial Joint Stock Bank |
| `NAMA` | Nam A Commercial Joint Stock Bank |
| `NCB` | National Citizen Commercial Joint Stock Bank |
| `HDB` | HD Bank |
| `OCB` | Orient Commercial Joint Stock Bank |
| `VIB` | Vietnam International Commercial Joint Stock Bank |
| `SGB` | Sai Gon Joint Stock Commercial Bank |
| `VAB` | VietABank |
| `BVB` | BAOVIET Bank |

---

## 限額與費率參考

以下為工單記錄的典型值，各商戶實際開通的限額以後台設定為準。

### IDR

| 類型 | 費率 | 最低 | 最高 |
|---|---|---|---|
| 代收 VA | 1.50% | 10,000 IDR | 10,000,000 IDR |
| 代收 QRIS | 3.00% | 10,000 IDR | 10,000,000 IDR |
| 代付 | 1.30% | 10,000 IDR | 20,000,000 IDR |

### VND

| 類型 | 費率 | 最低 | 最高 |
|---|---|---|---|
| 代收 Bank QR | 2.20% | 50,000 VND | 30,000,000 VND |
| 代收 EWallet | 3.20% | 50,000 VND | 30,000,000 VND |
| 代付 | 0.80% | 100,000 VND | 30,000,000 VND |

### MYR

| 類型 | 費率 | 最低 | 最高 |
|---|---|---|---|
| 代收（網銀） | 依商戶協議 | 10 MYR | 5,000 MYR |
| 代收（掃碼） | 依商戶協議 | 25 MYR | 5,000 MYR |
| 代付 | 依商戶協議 | 10 MYR | 9,999 MYR |

---

## 回調 IP 白名單

FPay 要求商戶將以下伺服器 IP 列入白名單，以正常接收回調：

| 環境 | Callback IP |
|---|---|
| 生產環境 | `0.0.0.0` |

---

## 參考資料

- 官方 API 文檔：https://doc.fpay.support/#introduction
- 官方文檔（簡中）：https://doc.fpay.support/cn/
- 商戶後台（生產）：https://portal.fpay.support/
- 商戶後台（沙盒）：http://sandbox-merchant.fpay.asia
- 內部實作路徑：`app/Services/Payment/Providers/FPay/`
  - `Driver.php` — 組合代收/代付子類別
  - `FPayTrait.php` — 認證（`getAuthCode`）、請求發送（`sendRequest`）、Token 生成（`generateToken`）
  - `Deposit.php` — 代收實作，呼叫 `/merchant/generate_orders`
  - `DepositCallback.php` — 代收回調處理，含 `payment_type` 動態更新邏輯
  - `Withdrawal.php` — 代付實作，呼叫 `/merchant/withdraw_orders`
  - `WithdrawalCallback.php` — 代付回調處理

---

*文件版本：1.1 / 整理日期：2026-06-01 / 最後校對：2026-09-16（依官方線上文檔逐段核對 MYR／VND／THB／IDR 四個幣別段落）*
*資料來源：官方 API 文檔、GitLab 工單 #2661、#2715、#2641、#3009、#3010、#3011、#3012、#3210、#3408、#3492、#3493、Codebase 實作*
