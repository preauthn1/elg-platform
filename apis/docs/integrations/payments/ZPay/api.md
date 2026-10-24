# ZPay 廠商規格

> 官方 API 文檔：PDF 版本（`zpay-api-3.pdf`，v1.0.32，24 Jun 2025）— 無公開網址，透過 ZPay 技術群取得
> 商戶後台：`https://admin.zpay.mom/`
> 內部實作路徑：`app/Services/Payment/Providers/ZPay/`

ZPay 是面向馬來西亞市場（MYR）的第三方支付平台，提供代收（Deposit）與代付（Withdrawal）。代收採用 **JSON POST** 格式呼叫 `/ApiRobot/ApiAllocateServer.aspx`，收銀台 URL 以 **Base64** 編碼回傳。代付（Fully Auto）呼叫 `/ApiPBO/ApiCreateAutoPayout.aspx`，亦為 **JSON POST**。回調由 ZPay 以 **FORM POST** 格式推送，代收與代付使用同一 Callback 結構但簽名驗證機制不同。

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [代收 API — Launch](#3-代收-api--launch)
4. [代付 API — Fully Auto Payout](#4-代付-api--fully-auto-payout)
5. [訂單查詢 API](#5-訂單查詢-api)
6. [回調處理](#6-回調處理)
7. [簽名流程](#7-簽名流程)
8. [銀行代碼映射](#8-銀行代碼映射)
9. [參考資料](#9-參考資料)

---

## 1. 環境資訊

| 項目 | 值 |
|---|---|
| 生產環境 API Base URL | `https://s.zpay.mom` |
| 商戶後台 | `https://admin.zpay.mom/` |
| 請求格式（代收） | JSON（`application/json`） |
| 請求格式（代付） | JSON（`application/json`） |
| 回調格式 | FORM POST |
| 響應格式 | JSON |
| 認證方式 | Token（由 ZPay 技術團隊提供）+ SecretKey 簽名 |
| 憑證欄位 | `TOKEN`、`SECRET_KEY`（儲存於 `agent_cash_flows.info`） |

> 代收 API 有兩種呼叫方式：Method 1（FORM POST `/Launch.aspx`，舊式）與 Method 2（JSON POST `/ApiRobot/ApiAllocateServer.aspx`）。本系統實作採用 **Method 2（JSON POST）**。

---

## 2. 支援的支付方式與幣別

### MYR（馬來西亞令吉）

| 支付類型 | BankCode | 說明 |
|---|---|---|
| Public Bank FPX 網銀 | `PBE` | 直連 P2P 銀行轉帳 |
| Maybank FPX 網銀 | `MBB` | 直連 P2P 銀行轉帳 |
| CIMB Bank FPX 網銀 | `CIMB` | 直連 P2P 銀行轉帳 |
| Hong Leong Bank FPX 網銀 | `HLB` | 直連 P2P 銀行轉帳 |
| RHB Bank FPX 網銀 | `RHB` | 直連 P2P 銀行轉帳 |
| AmBank FPX 網銀 | `AMB` | 直連 P2P 銀行轉帳 |
| Bank Simpanan Nasional | `BSN` | 直連 P2P 銀行轉帳 |
| Bank Islam | `BISL` | 直連 P2P 銀行轉帳 |
| DuitNow QR | `DUI` | DuitNow QR 掃碼 |
| Touch 'n Go | `TNG` | 電子錢包 |
| Boost | `BOT` | 電子錢包 |
| GrabPay | `GPY` | 電子錢包 |
| ShopeePay | `SPY` | 電子錢包 |
| Maybank QR | `MAE` | Maybank QR |
| （收銀台自選，不指定銀行） | （不帶 `BankCode`） | 玩家進入收銀台後自行選擇通道 |

> **Dedicated QR 模式**：在 `BankCode` 後加 `@` 符號（如 `TNG@`），可強制指定 dedicated 電子錢包通道，僅對 e-Wallet BankCode 有效。
>
> 代收限額（工單記錄）：Min 1 MYR / Max 30,000 MYR（依商戶協議為準）

---

## 3. 代收 API — Launch

### 代收發起 — `POST /ApiRobot/ApiAllocateServer.aspx`

**請求格式**：JSON POST

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `Token` | ✅ | string | 商戶 Token（由平台提供商提供） |
| `ApiUserCode` | ✅ | string | 玩家帳號（`member.username`） |
| `ReferenceId` | ✅ | string | 商戶訂單號（系統內唯一） |
| `Amount` | ✅ | float | 代收金額 |
| `CallBackUrl` | ✅ | string | 回調 URL（可於後台設定後留空） |
| `LinkBackUrl` | - | string | 支付完成後跳轉 URL（必須含 `?` 符號，見下方說明） |
| `BankCode` | - | string | 銀行/錢包代碼（見第 8 節）；不帶則玩家在收銀台自選 |
| `ItemDesc` | - | string | 簽名（代收簽名，見第 7 節） |
| `RealName` | - | string | 付款人銀行帳戶姓名（防詐驗證用，P2P 通道） |
| `ForceRealName` | - | boolean | 強制玩家輸入真實姓名；QR 通道需搭配 `BankCode`=`TNG` |
| `AllowQR` | - | boolean | 是否顯示 e-Wallet/QR 選項，預設 `false` |
| `AllowFPX` | - | boolean | 是否顯示 FPX P2P 銀行選項，預設 `true` |
| `ChannelName` | - | string | 預設通道名稱，預設 `FPX` |
| `TemplateCode` | - | int/string | UI 範本：`0`/空=標準 FPX、`1`=UI Template 1、`2`=UI Template 2（FPX）、`998`=Repush Topup |
| `EnableAmountInput` | - | boolean | 啟用金額輸入，搭配 `TemplateCode=998` 使用，預設 `false` |
| `Demo` | - | string | 測試模式，帶 `"on"` 可直接觸發成功/失敗回調；**生產環境必須移除此參數** |

> **LinkBackUrl 注意事項**：ZPay 的 HTTP GET linkback 要求 URL 必須含 `?` 符號。系統實作中，若 URL 不含 `?`，會自動在末尾補上 `?`（`#` 之前插入）。

**請求範例**

```json
{
    "Token": "{TOKEN}",
    "ApiUserCode": "player001",
    "BankCode": "MBB",
    "CallBackUrl": "https://example.invalid/callback/zpay/deposit/{acf_id}",
    "LinkBackUrl": "https://example.invalid/return?",
    "ReferenceId": "ORD20260301001",
    "Amount": 100.00,
    "ItemDesc": "{MD5_SIGN}"
}
```

**成功響應**

```json
{
    "Code": 1,
    "Message": "Success",
    "Data": {
        "Status": 1,
        "ErrorCode": "",
        "Message": "",
        "Domain": "aHR0cHM6Ly9wYXkueHh4LmNvbS9jaGVja291dD90b2tlbj14eHg=",
        "IP": "",
        "TransactionId": 123456
    }
}
```

- `Code === 1` 才視為請求成功
- `Data.Domain` 為 **Base64 編碼**的收銀台 URL，需 `base64_decode()` 後驗證為合法 URL 再導向玩家
- `Data.TransactionId` 為 ZPay 三方訂單號，儲存至 `cash_flow_orders.third_party_order_no`

**失敗響應**

```json
{
    "Code": 0,
    "Message": "Invalid Token"
}
```

> `Code !== 1` 時從 `Message` 取錯誤訊息拋出 `ApiException`。

**LinkBack（玩家端跳轉）回傳參數**

玩家完成支付後，ZPay 以 GET querystring（URL 含 `?`）或 FORM POST 方式跳回 `LinkBackUrl`，附帶以下資料：

| 參數 | 說明 |
|---|---|
| `Success` | 交易狀態：`0`=失敗，`1`=成功 |
| `Message` | 狀態描述 |
| `ResitUrl` | 收據照片 URL |
| `PaymentFail` | 付款狀態：`true` 或 `false` |
| `MsgFromBank` | 銀行回傳訊息 |
| `ReferenceId` | 商戶訂單號 |

---

## 4. 代付 API — Fully Auto Payout

本系統採用 **Fully Auto Payout**（`/ApiPBO/ApiCreateAutoPayout.aspx`），無需人工審核即可自動出款。

> 另有 Manual/Semi-Auto 版本（`/ApiPBO/ApiCreatePayment.aspx`），需人工介入，本系統未使用。

### 代付發起 — `POST /ApiPBO/ApiCreateAutoPayout.aspx`

**請求格式**：JSON POST

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `Token` | ✅ | string | 商戶 Token |
| `SecretParam` | ✅ | string | 代付簽名（見第 7 節）|
| `BankId0` | ✅ | string | 銀行 ID（見第 8 節，數字字串） |
| `AccountName0` | ✅ | string | 收款人帳戶姓名 |
| `AccountNo0` | ✅ | string | 收款人帳戶號碼 |
| `ToCurrency` | ✅ | string | 幣別，固定為 `MYR` |
| `RequestAmount` | ✅ | string | 代付金額（格式：`"%.2f"`，保留兩位小數） |
| `UserReference` | ✅ | string | 商戶內部參考號（系統生成，前綴 `ZPAY`） |
| `Remark` | - | string | 備註說明 |
| `CallBackUrl` | - | string | 代付回調 URL |
| `Demo` | - | string | 測試模式（`"on"`），**生產環境須移除** |

> `SecretParam` = MD5(`UserReference` + `datetime` + `amount` + `SecretKey`)，`datetime` 格式為 `YmdHis`（如 `20260301143000`）。

**請求範例**

```json
{
    "Token": "{TOKEN}",
    "SecretParam": "{MD5_SIGN}",
    "BankId0": "2",
    "AccountName0": "Ahmad Bin Ali",
    "AccountNo0": "1234567890",
    "ToCurrency": "MYR",
    "RequestAmount": "500.00",
    "UserReference": "ZPAY20260301143000001",
    "Remark": "Withdrawal for order WD20260301001",
    "CallBackUrl": "https://example.invalid/callback/zpay/withdrawal/{acf_id}"
}
```

**成功響應**

```json
{
    "Status": 0,
    "ErrorCode": "",
    "Message": ""
}
```

- **`Status === 0`** 才視為代付請求成功（注意：代收響應成功條件是 `Code === 1`，代付是 `Status === 0`，兩者不同）
- 代付為非同步處理，最終結果透過回調通知

**失敗響應**

```json
{
    "Status": 1,
    "ErrorCode": "INSUFFICIENT_BALANCE",
    "Message": "Insufficient balance"
}
```

---

## 5. 訂單查詢 API

### 交易狀態查詢 — `POST /ApiPBO/ApiCheckTransaction.aspx`

**請求格式**：JSON POST

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `Token` | ✅ | string | 商戶 Token |
| `ReferenceId` | ✅ | string | 商戶訂單號 |
| `TransactionId` | ✅ | long | ZPay 三方訂單號 |

**響應**

```json
{
    "Status": 1,
    "ErrorCode": "",
    "Message": "",
    "TransactionStatus": 1,
    "TransactionDescription": "Transaction success"
}
```

| 響應欄位 | 說明 |
|---|---|
| `Status` | `0`=查無記錄，`1`=找到記錄 |
| `TransactionStatus` | 交易狀態碼（1-8，見第 6 節） |
| `TransactionDescription` | 狀態描述 |

---

## 6. 回調處理

ZPay 代收與代付使用**相同的 Callback 結構**（FORM POST），由 `status` 欄位判斷結果。

### 代收 / 代付共用回調欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `referenceId` | string | 商戶訂單號（代收）或 `UserReference`（代付） |
| `itemDesc` | string | 簽名（驗證方式代收/代付不同，見第 7 節） |
| `status` | int | 交易狀態碼（1-8，見狀態 Enum 表） |
| `transactionId` | long | ZPay 三方交易 ID |
| `amount` | decimal | 請求金額 |
| `transactionAmount` | decimal | 實際成交金額 |
| `currency` | string | 幣別 |
| `statusDesc` | string | 狀態描述 |
| `declineReason` | string | 拒絕原因（失敗時） |
| `transactionDesc` | string | 交易類型描述 |
| `sourceBank` | string | 付款方銀行 |
| `sourceAccountNo` | string | 付款方帳號 |
| `sourceAccountName` | string | 付款方帳戶姓名 |
| `designatedBank` | string | 收款方銀行 |
| `designatedAccountNo` | string | 收款方帳號 |
| `designatedAccountName` | string | 收款方帳戶姓名 |
| `bankRef` | string | 銀行交易參考號 |
| `requestDate` | datetime | 交易請求時間 |
| `transactionDate` | datetime | 交易完成時間 |
| `dateTime` | string | 代付回調專用時間戳（格式 `YmdHis`，用於簽名驗證） |

> 代收回調含 `transactionDate`；代付回調含 `dateTime`（兩者欄位名不同，簽名驗證邏輯亦不同）。

### 狀態 Enum（ZPayStatusCodeEnum，1-8）

| 值 | Enum 名稱 | 說明 | 代收判定 | 代付判定 |
|---|---|---|---|---|
| `1` | `ON` | 成功 | 訂單成功 | 代付成功 |
| `2` | `VOIDED` | 已作廢 | 訂單失敗 | 代付失敗 |
| `3` | `FAILED` | 失敗 | 訂單失敗 | 代付失敗 |
| `4` | `REJECTED` | 已拒絕 | 訂單失敗 | 代付失敗 |
| `5` | `PROCESSING` | 處理中 | **保留**（回傳 OK 但不更新訂單） | **保留**（回傳 OK 但不更新訂單） |
| `6` | `PENDING` | 待處理 | **保留**（回傳 OK 但不更新訂單） | **保留**（回傳 OK 但不更新訂單） |
| `7` | `FORFEIT` | 沒收 | 訂單失敗 | 代付失敗 |
| `8` | `REFUNDED` | 已退款 | 訂單失敗 | 代付失敗 |

**成功條件**：`status === 1`（`ON`）

**代收/代付判定不對稱說明**：

- 代收（`DepositCallback`）：`isSuccess()` → 狀態 1 執行 `updateDepositOrderSuccess`；`isFailed()`（2/3/4/7/8）→ 執行 `updateDepositOrderFailed`；其餘（5/6）→ 直接回傳 `successResponse()` 但**不更新訂單**（靜待下次回調）。
- 代付（`WithdrawalCallback`）：邏輯相同，但簽名驗證方式不同（見第 7 節）；`isSuccess()` → `updateWithdrawalOrderStatus(true)`；`isFailed()` → `updateWithdrawalOrderStatus(false)`；5/6 → 保留不處理。
- **關鍵差異**：代收與代付使用同一 `ZPayStatusCodeEnum` 判斷邏輯，但簽名驗證完全不同（代收驗 `storedItemDesc + secret`；代付驗 `referenceId + dateTime + amount + secret`）。

### 回調響應

| 情形 | 回傳 |
|---|---|
| 成功接收並處理 | `{"status": "OK", "message": "Success"}` |
| 處理失敗 | `{"status": "FAIL", "message": "Failed"}` |

---

## 7. 簽名流程

ZPay 使用 **MD5** 雜湊作為簽名機制，但代收與代付的簽名計算方式不同。

### 代收簽名（ItemDesc）

#### 步驟

1. **代收發起時**：
   ```
   ItemDesc（ME）= MD5(order_no + amount + SecretKey)
   ```
   將此值存入 `cash_flow_orders.extra_content['zpay_item_desc']`。

2. **代收回調時**：ZPay 以下列公式計算並回傳 `itemDesc`（GE）：
   ```
   itemDesc（GE）= MD5(stored_ItemDesc + SecretKey)
   ```

3. **驗證**：比對本地計算的 `MD5(storedItemDesc + SecretKey)` 與回調 `itemDesc` 是否一致（大小寫不敏感比對）。

```php
// 代收發起時計算並儲存
$itemDescSign = md5($orderNo . $amount . $secretKey);

// 代收回調驗證
$expected = md5($storedItemDesc . $secretKey);
return hash_equals(strtolower($expected), strtolower($receivedItemDesc));
```

### 代付簽名（SecretParam）

代付請求的簽名與回調驗證使用同一公式：

```
SecretParam = MD5(UserReference + datetime + amount + SecretKey)
```

- `datetime` 格式：`YmdHis`（如 `20260301143000`）
- `amount` 為 float 型別

#### 發起時

```php
$dateTime = date('YmdHis');
$secretParam = md5($transactionNo . $dateTime . (float) $amount . $secretKey);
```

#### 回調驗證時

```php
// 回調帶有 dateTime 欄位，用回調中的 dateTime，而非系統當前時間
$expected = md5($referenceId . $callbackDateTime . (float) $amount . $secretKey);
return hash_equals(strtolower($expected), strtolower($receivedItemDesc));
```

### 簽名差異總覽

| 場景 | 公式 | 備註 |
|---|---|---|
| 代收發起 ItemDesc | `MD5(order_no + amount + SecretKey)` | 儲存備用 |
| 代收回調驗證 | `MD5(storedItemDesc + SecretKey)` | 兩層 MD5 |
| 代付發起 SecretParam | `MD5(UserReference + datetime + amount + SecretKey)` | datetime=`YmdHis` |
| 代付回調驗證 | `MD5(referenceId + callbackDateTime + amount + SecretKey)` | 使用回調中的 `dateTime` 欄位 |

---

## 8. 銀行代碼映射

### MYR — 代收 BankCode（Launch API）

| BankCode | 銀行/錢包名稱 | 類型 |
|---|---|---|
| `PBE` | Public Bank | FPX 網銀 |
| `MBB` | Maybank | FPX 網銀 |
| `CIMB` | CIMB Bank | FPX 網銀 |
| `HLB` | Hong Leong Bank | FPX 網銀 |
| `RHB` | RHB Bank | FPX 網銀 |
| `AMB` | AmBank | FPX 網銀 |
| `BSN` | Bank Simpanan Nasional | FPX 網銀 |
| `BISL` | Bank Islam | FPX 網銀 |
| `DUI` | DuitNow | DuitNow QR |
| `TNG` | Touch 'n Go | 電子錢包 |
| `BOT` | Boost | 電子錢包 |
| `GPY` | GrabPay | 電子錢包 |
| `SPY` | ShopeePay | 電子錢包 |
| `MAE` | Maybank QR | DuitNow QR |

> Dedicated QR：`TNG@`、`BOT@`、`GPY@`、`SPY@`、`MAE@` — 加 `@` 符號強制指定 dedicated 電子錢包通道。

### MYR — 代付 BankId0（Payout API）

代付使用整數 `BankId0`，非 BankCode 字串。

| BankId0 | 銀行名稱 |
|---|---|
| `1` | Public Bank |
| `2` | Maybank Berhad |
| `3` | CIMB Bank Berhad |
| `4` | Hong Leong Bank Berhad |
| `5` | RHB Bank Berhad |
| `6` | United Overseas Bank (Malaysia) Berhad |
| `7` | AmBank (M) Berhad |
| `8` | Bank Kerjasama Rakyat Malaysia Berhad |
| `9` | Bank Simpanan Nasional Berhad |
| `10` | Bank Pertanian Malaysia Berhad（Agrobank） |
| `11` | Bank Islam Malaysia Berhad |
| `12` | Alliance Bank Malaysia Berhad |
| `13` | Affin Bank Berhad |
| `14` | OCBC Bank (Malaysia) Berhad |
| `15` | MBSB Bank Berhad |
| `16` | GXBANK |
| `17` | TNG（Touch 'n Go） |
| `18` | HSBC Bank Berhad |
| `19` | ALRAJHI Bank Berhad |
| `20` | Bank Muamalat Berhad |
| `21` | DuitNow（Fully Auto v 中含）|
| `22` | Boost Bank |
| `23` | Shopee Pay |

> `BankId0` 為 ZPay 平台內部編號，非 SWIFT code。Fully Auto（`/ApiCreateAutoPayout.aspx`）支援至 `BankId0=20`；Manual/Semi-Auto（`/ApiCreatePayment.aspx`）支援至 `23`。

---

## 9. 參考資料

- 官方 API 文檔：PDF 版本 v1.0.32（最後更新 24 Jun 2025）— 透過 ZPay 技術支援群取得，無公開網址
- 商戶後台：`https://admin.zpay.mom/`
- 內部實作路徑：`app/Services/Payment/Providers/ZPay/`
  - `Driver.php` — 組合代收/代付子類別
  - `ZPayTrait.php` — 請求發送（`sendJsonRequest`）、代收簽名（`generateItemDescSign`）、代付簽名（`generateSecretParamSign`）、回調驗證（`verifyCallbackItemDesc` / `verifyCallbackSign`）
  - `ZPayCredentials.php` — Token + SecretKey DTO
  - `ZPayStatusCodeEnum.php` — 狀態碼 1-8（`ON`/`VOIDED`/`FAILED`/`REJECTED`/`PROCESSING`/`PENDING`/`FORFEIT`/`REFUNDED`）
  - `Deposit.php` — 代收發起，呼叫 `/ApiRobot/ApiAllocateServer.aspx`，Base64 解碼 Domain URL
  - `DepositCallback.php` — 代收回調，驗證 `storedItemDesc` 二層 MD5
  - `Withdrawal.php` — 代付發起，呼叫 `/ApiPBO/ApiCreateAutoPayout.aspx`，成功條件 `Status === 0`
  - `WithdrawalCallback.php` — 代付回調，驗證 `referenceId + dateTime + amount` 簽名
- 相關 GitLab 工單：#3430（APIs 代收接入）、#3505（VPlus33 配置）、#3506（ME77 配置）、#3507（VWorld 配置）、#2716（VPlus33 舊版 AllPay）、#2397（VWMY 舊版 AllPay）、#2934（ME77 舊版 AllPay）

---

*文件版本：1.0 / 整理日期：2026-06-01*
*資料來源：官方 API PDF（v1.0.32）、GitLab 工單 #3430、#3505、#3506、#3507、Codebase 實作*
