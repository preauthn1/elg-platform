# EpicPay 廠商規格

> 官方 API 文檔：`https://example.invalid`（需商戶帳號登入，外部無法直接存取）
> 官方後台：`https://example.invalid`（遮蔽，請向 EpicPay 索取）
> 內部實作路徑：`app/Services/Payment/Providers/EpicPay/`

EpicPay 是面向馬來西亞（MYR）市場的第三方支付平台，支援代收（Deposit）與代付（Withdrawal）。所有請求以 **application/x-www-form-urlencoded（Form POST）** 格式提交，響應為 JSON。簽名方式為 **MD5**，分為請求簽名（Token）與回調驗簽兩種格式。

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [代收 API](#3-代收-api)
4. [代付 API](#4-代付-api)
5. [輔助 API](#5-輔助-api)
6. [回調處理](#6-回調處理)
7. [簽名流程](#7-簽名流程)
8. [銀行代碼映射](#8-銀行代碼映射)
9. [限額與費率參考](#9-限額與費率參考)
10. [參考資料](#10-參考資料)

---

## 1. 環境資訊

| 項目 | 值 |
|---|---|
| 生產環境 API Base URL | `https://example.invalid`（由 `API_URL` config 欄位設定） |
| 商戶後台 | 請向 EpicPay 索取（已遮蔽） |
| 回調來源 IP | `0.0.0.0` |
| 請求格式 | `application/x-www-form-urlencoded`（Form POST） |
| 響應格式 | JSON |
| 簽名方式 | MD5 |
| 官方 API 文件版本 | v1.1（2025-09-19）|

> `API_URL` 末尾請勿帶斜線；實作中以 `rtrim($url, '/')` 去除。

> **文件修訂歷史**：v1.0（2025-04-04，初版）；v1.1（2025-09-19，新增 Malaysia Bank Code bCode 清單）

---

## 2. 支援的支付方式與幣別

### MYR（馬來西亞令吉）

| 支付類型 | 內部 key | bCode（代收） | 說明 |
|---|---|---|---|
| 銀行網銀（Banking） | `EpicPay.Banking` | 各銀行代碼（見第 8 節） | 直連網銀轉帳 |
| QR 掃碼 | `EpicPay.QR` | `TNGQR` / `DUITNOW` | TnG QR 或 Duitnow QR |
| FPX Internet Banking | `FPX` | — | FPX 閘道（第三方 FPX 渠道） |
| FPX DuitNow QR | `FPXDUITNOW` | — | FPX 閘道（DuitNow QR） |
| FPX E-Wallet | `FPXEWALLET` | — | FPX 閘道（電子錢包） |

---

## 3. 代收 API

### 代收發起 — `POST /a/getDPLink`

**Base URL**：`{API_URL}/a/getDPLink`

**請求格式**：`application/x-www-form-urlencoded`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `mCode` | ✅ | string | 商戶代碼（Merchant Code，由 EpicPay 配發，遮蔽） |
| `ts` | ✅ | integer | Unix 時間戳（秒） |
| `bCode` | ✅ | string | 銀行/支付方式代碼（見第 8 節） |
| `amt` | ✅ | numeric | 訂單金額 |
| `user` | ✅ | string | 會員帳號（username） |
| `userFn` | - | string | 會員姓名（first name；有實名驗證時帶入） |
| `trxid` | ✅ | string | 商戶訂單號（系統內唯一，即 `order_no`） |
| `notifyUrl` | ✅ | string | 代收回調 URL |
| `webUrl` | ✅ | string | 支付完成後跳轉 URL |
| `isverify` | ✅ | integer | 是否需要實名驗證：`1`（是）/ `0`（否） |
| `userIP` | ✅ | string | 使用者 IP |
| `channel` | ✅ | integer | 裝置渠道：`1`（PC）/ `2`（Mobile） |
| `token` | ✅ | string | 請求簽名（見第 7 節，請求簽名格式） |

> `isverify` 邏輯：`member->first_name` 有值且 `member->realname_validate` 為真時設為 `1`，否則為 `0`。

**成功響應**

```json
{
    "status": 0,
    "msg": "Success",
    "d": {
        "url": "https://example.invalid/checkout?token=[REDACTED]"
    }
}
```

**失敗響應**

```json
{
    "status": 1,
    "msg": "Invalid merchant"
}
```

**成功判定**：`status === 0`（整數嚴格比對），`d.url` 為收銀台 URL，直接導向使用者。

---

## 4. 代付 API

### 代付發起 — `POST /a/sPayout`

**Base URL**：`{API_URL}/a/sPayout`

**請求格式**：`application/x-www-form-urlencoded`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `mCode` | ✅ | string | 商戶代碼（Merchant Code，遮蔽） |
| `ts` | ✅ | integer | Unix 時間戳（秒） |
| `bCode` | ✅ | string | 銀行代碼（見第 8 節代付代碼欄） |
| `trxid` | ✅ | string | 商戶端代付訂單號（前綴 `Epic`，由 `Common::genTransactionNo('Epic')` 生成） |
| `user` | ✅ | string | 會員帳號（username） |
| `amt` | ✅ | string | 代付金額（保留兩位小數，如 `"100.00"`） |
| `bAccNo` | ✅ | string | 收款銀行帳號 |
| `bAccName` | ✅ | string | 收款人姓名 |
| `notifyUrl` | ✅ | string | 代付回調 URL |
| `token` | ✅ | string | 請求簽名（見第 7 節，請求簽名格式） |

> `trxid` 格式：`Epic` + 系統流水號（由 `Common::genTransactionNo('Epic')` 生成）。

**成功響應**

```json
{
    "status": 0,
    "msg": "Payout request submitted"
}
```

**失敗響應**

```json
{
    "status": 1,
    "msg": "Insufficient balance"
}
```

**成功判定**：`status === 0`（整數嚴格比對）。代付為非同步處理，最終結果透過回調通知。

---

## 5. 輔助 API

以下三個輔助 API 由官方文件（v1.0+）列出，目前 codebase **尚未實作**（僅呼叫代收/代付）。

### 5.1 查詢支援銀行 — `POST /a/getBankOptions`

**Base URL**：`{API_URL}/a/getBankOptions`

**用途**：取得當前可用銀行清單與狀態（包含是否在線、支援的 bCode 等）。

**已知資訊**：

- API Action Name：`getBankOptions`
- Endpoint pattern：`{API_URL}/a/getBankOptions`
- 請求參數詳情未納入已解析 PDF 頁面（PDF v1.1 第 2 頁僅顯示 Action Name 與 Endpoint）

> **⚠️ 缺口**：完整請求參數表、響應格式尚未從 PDF 讀取到（PDF 僅 2 頁，後續頁面未收錄）。如需整合此 API，請向 EpicPay 索取完整規格。

### 5.2 查詢訂單狀態 — `POST /a/checkTransaction`（推測路徑）

**用途**：主動查詢代收/代付訂單的當前狀態。

> **⚠️ 缺口**：官方文件列出此功能（"Check Transaction Status"），但 PDF 收錄頁面不足，確切 Endpoint、請求參數、響應欄位均未確認。

### 5.3 查詢商戶餘額 — `POST /a/getBalance`（推測路徑）

**用途**：查詢商戶帳戶當前可用餘額。

> **⚠️ 缺口**：官方文件列出此功能（"Get Balance"），但 PDF 收錄頁面不足，確切 Endpoint、請求參數、響應欄位均未確認。

---

## 6. 回調處理

EpicPay 在代收/代付完成後，以 **POST JSON** 格式推送至商戶設定的 `notifyUrl`。

### 代收回調

**回調欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `id` | ✅ | string | 商戶訂單號（即代收請求中的 `trxid`） |
| `status` | ✅ | string | 訂單狀態：`"1"`（成功）/ 其他（失敗） |
| `amount` | ✅ | string | 實際支付金額 |
| `fee` | ✅ | string | 手續費 |
| `message` | ✅ | string | 狀態說明（如 `"Success"`） |
| `bfname` | - | string | 付款人姓名（可為 null） |
| `token` | ✅ | string | 回調驗簽 token（見第 7 節，回調簽名格式） |
| `ts` | ✅ | string | 回調時間戳（秒） |

**成功判定**：`status === "1"`（字串嚴格比對）

**回調響應**：回傳純文字 `OK` 表示處理成功；`FAIL` 表示處理失敗。

**回調範例**

```json
{
    "id": "202601149042085136",
    "status": "1",
    "amount": "10.00",
    "fee": "0.11",
    "message": "Success",
    "bfname": null,
    "token": "{md5_hash}",
    "ts": "1768379714"
}
```

---

### 代付回調

**回調欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `id` | ✅ | string | 商戶端代付訂單號（即代付請求中的 `trxid`） |
| `status` | ✅ | string | 代付狀態：`"1"`（成功）/ 其他（失敗） |
| `amount` | ✅ | string | 代付金額 |
| `fee` | ✅ | string | 手續費 |
| `message` | ✅ | string | 狀態說明 |
| `token` | ✅ | string | 回調驗簽 token |
| `ts` | ✅ | string | 回調時間戳（秒） |

**成功判定**：`status === "1"`（字串嚴格比對）

> 代付失敗時，`amount` 可能不準確，僅成功時才驗證金額是否與原始訂單一致（實作：`validateCallbackAmount`）。

**回調響應**：同代收，`OK` 表示接收成功。

---

## 7. 簽名流程

EpicPay 使用 **MD5** 作為簽名機制，分為**請求簽名**與**回調驗簽**兩種格式。

### 請求簽名（Token）

用於代收發起（`getDPLink`）與代付發起（`sPayout`）的 `token` 欄位。

**簽名格式**（來源：官方 PDF + codebase 實測驗證）

```
token = [REDACTED]
```

| 位置 | 說明 |
|---|---|
| `{MERCHANT_CODE}` | 請求中的 `mCode` 欄位 |
| `{SECRET_KEY}` | 商戶密鑰（Secret Key，由 EpicPay 配發，遮蔽） |
| `{ACTION}` | API 動作名稱（代收：`getDPLink`；代付：`sPayout`） |
| `{TIMESTAMP}` | 請求中的 `ts` 欄位（Unix 秒） |

**PHP 實作（`EpicPayTrait::generateToken`）**

```php
// Format: md5(<MERCHANT CODE>-<SECRET KEY>-<ACTION NAME>-<TIMESTAMP TS>)
protected function generateToken(array $params, string $action, string $secret): string
{
    return md5($params['mCode'] . '-' . $secret . '-' . $action . '-' . $params['ts']);
}
```

---

### 回調驗簽（Callback Token）

用於驗證代收/代付回調的 `token` 欄位真實性。

**簽名格式**（來源：codebase 實測驗證，PDF 未明確列出）

```
token = [REDACTED]
```

| 位置 | 說明 |
|---|---|
| `{MERCHANT_CODE}` | 商戶代碼 |
| `{SECRET_KEY}` | 商戶密鑰（Secret Key） |
| `{TRANSACTION_ID}` | 回調中的 `id` 欄位 |
| `{AMOUNT}` | 回調中的 `amount` 欄位 |
| `{TIMESTAMP}` | 回調中的 `ts` 欄位 |

**PHP 實作（`EpicPayTrait::generateCallbackToken`）**

```php
// Format: md5(<MERCHANT CODE>-<SECRET KEY>-<TRANSACTION ID>-<AMOUNT>-<TIMESTAMP TS>)
protected function generateCallbackToken(array $params, string $merchant, string $secret): string
{
    return md5($merchant . '-' . $secret . '-' . $params['id'] . '-' . $params['amount'] . '-' . $params['ts']);
}

// 回調驗證
$expected = $this->generateCallbackToken($input, $this->info['MERCHANT_CODE'], $this->info['SECRET_KEY']);
if ($expected !== $input['token']) {
    throw new ApiException('Signature verification failed');
}
```

---

## 8. 銀行代碼映射

> PDF v1.1（2025-09-19）版本新增了完整 Malaysia Bank Code bCode 清單。以下代碼以 **codebase（EpicPay.php config）為準**，同時標註 StandardBankCodes.php 的差異。

### MYR — 代收 `bCode`（Banking 通道）

來源：`app/Console/Commands/CashFlow/Data/providers/EpicPay.php`

| EpicPay bCode | 銀行名稱 | 備註 |
|---|---|---|
| `MBB` | Maybank | |
| `CIMB` | CIMB Bank | |
| `RHB` | RHB Bank | |
| `PBB` | Public Bank | |
| `HLB` | Hong Leong Bank | |
| `ALLIANCE` | Alliance Bank | |
| `AMBANK` | AmBank | |
| `BSN` | Bank Simpanan Nasional | |
| `OCBCM` | OCBC Bank | ⚠️ config 用 `OCBCM`，StandardBankCodes 用 `OCBC` |
| `OCBCM` | United Overseas Bank | ⚠️ **config 疑似錯誤**：UOB 與 OCBC 同用 `OCBCM`；StandardBankCodes 的 EpicPay UOB 代碼為 `UOB` |
| `AGRO` | Agrobank | |
| `RAKYAT` | Bank Rakyat | ⚠️ config 用 `RAKYAT`，StandardBankCodes 用 `BRAKYAT` |

> **⚠️ UOB/OCBC 代碼疑似 config bug**：在 `EpicPay.php` 代收 options 中，`United Overseas Bank` 的 key 被設為 `OCBCM`（與 OCBC Bank 相同），而 `StandardBankCodes.php` 中 EpicPay 的 UOB 代碼為 `UOB`。建議向 EpicPay 確認正確代碼後修正。

### MYR — 代收 `bCode`（QR 通道）

| EpicPay bCode | 說明 |
|---|---|
| `TNGQR` | Touch 'n Go QR |
| `DUITNOW` | DuitNow QR |

> FPX 通道（`FPX` / `FPXDUITNOW` / `FPXEWALLET`）的 `bCode` 由 FPX 閘道決定，無需在此映射。

### MYR — 代付 `bCode`

來源：`app/Console/Commands/CashFlow/Data/providers/EpicPay.php`（代付 config banks 清單）

| 系統內部 code | EpicPay bCode | 銀行名稱 | 備註 |
|---|---|---|---|
| `MBB` | `MBB` | Maybank | |
| `CIMB` | `CIMB` | CIMB Bank | |
| `RHB` | `RHB` | RHB Bank | |
| `PBB` | `PBB` | Public Bank | |
| `HLB` | `HLB` | Hong Leong Bank | |
| `AFFIN` | `AFFIN` | Affin Bank | |
| `ALLIANCE` | `ALLIANCE` | Alliance Bank | |
| `AMBANK` | `AMBANK` | AmBank | |
| `ISLAM` | `ISLAM` | Bank Islam Malaysia | ⚠️ StandardBankCodes 的 EpicPay 代碼為 `BIMB` |
| `BSN` | `BSN` | Bank Simpanan Nasional | |
| `CITIM` | `CITIM` | Citibank | ⚠️ StandardBankCodes 的 EpicPay 代碼為 `CITI` |
| `HSBCM` | `HSBCM` | HSBC Bank | ⚠️ StandardBankCodes 的 EpicPay 代碼為 `HSBC` |
| `OCBCM` | `OCBCM` | OCBC Bank | ⚠️ StandardBankCodes 的 EpicPay 代碼為 `OCBC` |
| `UOBM` | `UOBM` | United Overseas Bank | ⚠️ StandardBankCodes 的 EpicPay 代碼為 `UOB` |
| `STCM` | `STCM` | Standard Chartered Bank | ⚠️ StandardBankCodes 的 EpicPay 代碼為 `SCB` |
| `AGRO` | `AGRO` | Agrobank | |
| `RAKYAT` | `RAKYAT` | Bank Rakyat | ⚠️ StandardBankCodes 的 EpicPay 代碼為 `BRAKYAT` |
| `AEON` | `AEON` | AEON Bank | |
| `BOOSTBANK` | `BOOSTBANK` | Boost Bank | |
| `GX` | `GX` | GX Bank | |
| `TNGQR` | `TNGQR` | Touch 'n Go | |

> **注意**：代付 config 中的 `bCode` 是系統端的內部代碼；`convertPayoutBankCode()` 將 `MYR_*` 標準代碼轉換為 EpicPay 代碼。`StandardBankCodes.php` 與 config 之間存在多處命名差異（如 `BRAKYAT` vs `RAKYAT`、`BIMB` vs `ISLAM` 等），實際生效路徑以 `convertPayoutBankCode()` + `StandardBankCodes.php` 為準。

### 標準銀行代碼 → EpicPay 代碼對照（StandardBankCodes.php 官方映射）

| 標準代碼 | EpicPay 代碼 | 銀行名稱 |
|---|---|---|
| `MYR_MAYBANK` | `MBB` | Maybank |
| `MYR_CIMB` | `CIMB` | CIMB Bank |
| `MYR_PUBLIC` | `PBB` | Public Bank |
| `MYR_RHB` | `RHB` | RHB Bank |
| `MYR_HONGLEONG` | `HLB` | Hong Leong Bank |
| `MYR_AMBANK` | `AMBANK` | AmBank |
| `MYR_UOB` | `UOB` | United Overseas Bank |
| `MYR_OCBC` | `OCBC` | OCBC Bank |
| `MYR_HSBC` | `HSBC` | HSBC Bank |
| `MYR_BANKISLAM` | `BIMB` | Bank Islam |
| `MYR_BANKRAKYAT` | `BRAKYAT` | Bank Rakyat |
| `MYR_BSN` | `BSN` | Bank Simpanan Nasional |
| `MYR_AFFIN` | `AFFIN` | Affin Bank |
| `MYR_ALLIANCE` | `ALLIANCE` | Alliance Bank |
| `MYR_AGROBANK` | `AGRO` | Agrobank |
| `MYR_MUAMALAT` | `MUAMALAT` | Bank Muamalat |
| `MYR_STANDARDCHARTERED` | `SCB` | Standard Chartered Bank |
| `MYR_CITIBANK` | `CITI` | Citibank |
| `MYR_TNGWALLET` | `TNG` | Touch 'n Go eWallet |
| `MYR_GRABPAY` | `GRABPAY` | GrabPay |
| `MYR_BOOST` | `BOOST` | Boost |
| `MYR_DUITNOW` | `DUITNOW` | DuitNow |

---

## 9. 限額與費率參考

以下數值取自 GitLab 工單 #3379 / #3380，各商戶實際限額以後台設定為準。

### MYR — 代收

| 通道 | 費率 | 最低金額 | 最高金額 |
|---|---|---|---|
| Internet Banking（直連） | 1.10% | MYR 10 | MYR 50,000 |
| TNG QR / DuitNow QR | 1.50% | MYR 30 | MYR 10,000 |
| FPX Internet Banking | 1.40%（最低收 RM1.50） | MYR 50 | MYR 30,000 |
| FPX DuitNow QR | 1.50%（最低收 RM1.50） | MYR 10 | MYR 30,000 |
| FPX E-Wallet | 1.80% | MYR 10 | MYR 30,000 |

### MYR — 代付

| 通道 | 費率 | 最低金額 | 最高金額 |
|---|---|---|---|
| Internet Banking Payout | 0.80% | MYR 100 | MYR 30,000 |
| TNG QR / DuitNow Payout | 0.80% | MYR 100 | MYR 30,000 |

### 清算資訊

| 項目 | 說明 |
|---|---|
| 到帳時間 | 代收/代付均於 10 分鐘內完成 |
| 清算頻率 | 每日最多 5 次 |
| 清算起點 | MYR 5,000 起，無上限 |
| 客服時間 | 24/7 |

---

## 10. 參考資料

- 官方 API 文檔：`https://example.invalid`（需商戶帳號，外部無法直接存取）
- 官方 API 整合文件：`EPICPAY_API_Integration_Document_New.pdf`（v1.1，2025-09-19，存於工單 #3379）
- GitLab 工單：
  - [#3388 — EpicPay-MYR 代收代付接入（TP）](http://example.invalid:1688/target/tickets/-/work_items/3388)
  - [#3380 — EpicPay-MYR 代收代付配置（YES2WIN）](http://example.invalid:1688/target/tickets/-/work_items/3380)
  - [#3379 — EpicPay-MYR 代收代付配置（TP）](http://example.invalid:1688/target/tickets/-/work_items/3379)
- 內部實作路徑：`app/Services/Payment/Providers/EpicPay/`
  - `Driver.php` — 組合代收/代付子類別
  - `EpicPayTrait.php` — 請求發送（`sendRequest`）、請求簽名（`generateToken`）、回調驗簽（`generateCallbackToken`）
  - `Deposit.php` — 代收實作，呼叫 `POST /a/getDPLink`
  - `DepositCallback.php` — 代收回調處理
  - `Withdrawal.php` — 代付實作，呼叫 `POST /a/sPayout`
  - `WithdrawalCallback.php` — 代付回調處理
- 通道配置：`app/Console/Commands/CashFlow/Data/providers/EpicPay.php`
- 銀行代碼映射：`app/Console/Commands/CashFlow/Data/banks/StandardBankCodes.php`

---

*文件版本：1.1 / 整理日期：2026-06-01*
*資料來源：官方 PDF（EPICPAY_API_Integration_Document_New.pdf v1.1）+ Codebase 實作驗證 + GitLab 工單 #3379、#3380、#3388*
