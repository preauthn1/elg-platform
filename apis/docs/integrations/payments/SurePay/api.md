# SurePay 廠商規格

> 官方 API 文檔：`Surepay_api_specification_v2.22_New.pdf`（v2.20，2024-10-05，存於 GitLab 工單 #3438）
> 內部實作路徑：`app/Services/Payment/Providers/SurePay/`

SurePay 是支援馬來西亞（MYR）、新加坡（SGD）、泰國（THB）、越南（VND）、印尼（IDR）、柬埔寨（KHR）等市場的第三方支付平台，提供代收（Deposit）、代付（Withdrawal）與餘額查詢（Balance）。所有業務 API 請求使用 **JSON** 格式（`application/json`），響應同為 JSON。驗證採用 **MD5 簽名（token）** 機制，請求簽名與回調驗證使用不同的 Key。

> **重要**：SurePay 的每個商戶、每條支付通道（bankcode）都是後台手動建立的數字 ID（如 `10022xxx`），不同商戶、不同站台的 bankcode 一定不同，無法跨商戶共用。

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [代收 API](#3-代收-api)
4. [代付 API](#4-代付-api)
5. [餘額查詢 API](#5-餘額查詢-api)
6. [餘額轉移 API](#6-餘額轉移-api)
7. [交易查詢 API](#7-交易查詢-api)
8. [銀行帳號查詢 API（IBAN）](#8-銀行帳號查詢-api-iban)
9. [馬來西亞電話儲值 PIN](#9-馬來西亞電話儲值-pin)
10. [回調處理](#10-回調處理)
11. [簽名流程](#11-簽名流程)
12. [銀行代碼說明](#12-銀行代碼說明)
13. [錯誤碼對照](#13-錯誤碼對照)
14. [限額與費率參考](#14-限額與費率參考)
15. [參考資料](#15-參考資料)

---

## 1. 環境資訊

| 項目 | 正式環境 | 沙盒環境 |
|---|---|---|
| 代收端點 | `https://example.invalid/credittransfer`（JSON 方式：`/api/request`） | `https://example.invalid/credittransfer` |
| 代付端點 | `https://example.invalid/payout` | `https://example.invalid/payout` |
| 餘額查詢 | `https://example.invalid/merchant/balance` | — |
| 餘額轉移 | `https://example.invalid/merchant/baltransfer` | — |
| 交易狀態 | `https://example.invalid/transaction/status` | — |
| 銀行查詢（IBAN） | `https://example.invalid/v1/bankquery` | — |
| 電話儲值 PIN | `https://example.invalid/telco/reloadpin` | — |
| 馬來西亞另一代收端點 | `https://example.invalid/credittransfer` | — |
| 請求格式 | JSON（`application/json`） | — |

> **PDF 說明**：代收有兩種整合方式——HTML Form POST（§4，直跳銀行）與 JSON POST（§5，取得 `redirecturl` 後由商戶 redirect）。codebase 使用 **JSON POST `/api/request`** 方式。

**必要配置欄位**（寫入 `agent_cash_flows.info`）：

| 欄位 | 說明 |
|---|---|
| `API_URL` | API Base URL（如 `https://example.invalid`） |
| `MERCHANT_ID` | 商戶帳號 ID（Merchant code） |
| `CONTRACT_NAME` | 合約名稱（對應特定通道，如 OB / QR 各有不同） |
| `API_KEY` | 發送請求用的 API Key（用於請求簽名） |
| `CALLBACK_API_KEY` | 回調驗證用的 API Key（用於驗證回調 token） |
| `CURRENCY` | 幣別（`MYR` / `IDR` / `THB` 等） |
| `WITHDRAWAL_IBAN` | 代付通道的 bankcode（後台建立的數字 ID） |
| `LANGUAGE` | （選填）語言代碼，預設 `EN`；可選 `en`/`my`/`th`/`id`/`vn` |

> `API_KEY` 與 `CALLBACK_API_KEY` 是兩把不同的 Key，分別用於請求簽名和回調驗證，不可混用。

> **Balance.php config key 命名差異（codebase 特有）**：`Balance.php` 直接讀取 `info['MerchantID']`、`info['ApiKey']`、`info['APIUrl']`，而其他模組（Deposit/Withdrawal 等）讀取 `info['MERCHANT_ID']`、`info['API_KEY']`、`info['API_URL']`。兩套 key 名稱不同但指向相同的配置值，這是 Balance.php 的歷史命名問題。若餘額查詢功能未正常啟用，需確認對應 `agent_cash_flows.info` 中是否同時存在兩套 key 名稱，或 Balance 是否使用獨立 cashflow entry。

---

## 2. 支援的支付方式與幣別

PDF 記錄 SurePay 支援 6 個國家，codebase 目前主要實作 MYR、IDR、THB。

### MYR（馬來西亞令吉）

| 支付類型 | 資料檔規劃 key | 說明 |
|---|---|---|
| 網路銀行（Online Banking） | `OB` | 直連銀行轉帳頁面 |
| DuitNow QR | `QR` | DuitNow QR 掃碼 |
| 電子錢包（Ewallet） | `Ewallet` | 電子錢包（如 TNG），走 QR 掃碼流程 |
| DuitNow Dynamic QR | `DBDQR` | DuitNow 動態 QR（僅代收，Type=D） |

> 這一欄的值不是 `bankcode`。它們是非數字字串，因此代收會改帶 `contractname`、由三方顯示收銀台選擇頁（見 §3 codebase 行為補充）。要直跳單一通道才需要三方後台的通道數字 ID。

> 代收限額（MYR）：網銀 Min 10 / Max 9,999；QR / Ewallet Min 30 / Max 1,000

### SGD（新加坡元）

| 支付類型 | 代碼 | 說明 |
|---|---|---|
| 網路銀行 DBS / UOB / OCBC | `DBS` / `UOB` / `OCBC` | 代收代付（DP） |
| POSB | `POSB` | 僅代收（D） |
| PayNow | `PAYNOW` | 僅代收（D） |

### THB（泰銖）

| 支付類型 | 代碼 | 說明 |
|---|---|---|
| Thai PromptPay | `THAIPP` | 僅代收（D） |
| 各銀行 PromptPay | `KBANKPROMPTPAY` / `SCBPROMPTPAY` / `BBLPROMPTPAY` / `KTBPROMPTPAY` | 僅代收（D） |
| PromptPay 代付 | `PROMPTPAY` | 僅代付（P），`destbankaccno` 填 Thai 手機號 |

> 代收限額（THB）：Min 10 / Max 150,000（參考工單 #2838）

### VND（越南盾）

| 支付類型 | 代碼 | 說明 |
|---|---|---|
| Vietcom / Sacom / Techcom / Vietin / BIDV | DP | 代收代付 |
| Momo Pay | `MOMO` | 僅代收（D） |
| VietQR | `VietQR` | 僅代收（D），新版 |

> 注意：越南銀行**不支援小數金額**，amount 必須為整數或 `.00`

### IDR（印尼盾）

| 支付類型 | 代碼 | 說明 |
|---|---|---|
| QRIS eWallet | `QRIS` | 僅代收（D） |
| DANA eWallet | `DANA` | 僅代收（D） |
| Shopeepay | `SHOPEEPAY` | 僅代收（D） |
| Alfamart eWallet | `ALFAMART` | 僅代收（D） |
| 各主要銀行 | `MANDIRI` / `BRI` / `BCA` / `BNI` 等 | 僅代收（D） |

> 代收限額（IDR）：Min 20,000 / Max 20,000,000

---

## 3. 代收 API

### 方式一：HTML Form POST（舊介面 v1）

**Endpoint**：`POST https://example.invalid/fundtransfer/`（或 `https://example.invalid/fundtransfer`）

以 HTML hidden input 方式提交參數，直接跳轉到銀行頁面。

### 方式二：JSON POST（新介面 v2，codebase 使用）

**Endpoint**：`POST https://example.invalid/api/request`

Header：`Content-Type: application/json`

響應包含 `redirecturl`，商戶自行 redirect 用戶。

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `merchant` | 是 | String | 商戶帳號（`MERCHANT_ID`） |
| `refid` | 是 | String(30) | 商戶訂單號，唯一（對應 `order.order_no`） |
| `amount` | 是 | Float | 代收金額（如 `"10.00"`）；越南銀行不支援小數 |
| `customer` | 是 | String(30) | 會員帳號（最大 30 字元） |
| `currency` | 是 | String | ISO-4217 幣別（如 `MYR`、`THB`） |
| `token` | 是 | String | 請求簽名（見簽名流程 §11.1） |
| `clientip` | 是 | String | 會員 IP（如 `0.0.0.0`） |
| `post_url` | 是 | String | 代收回調 URL（三方 POST 通知此 URL） |
| `failed_return_url` | 是 | String | 支付失敗後跳轉 URL |
| `return_url` | 是 | String | 支付成功後跳轉 URL |
| `language` | 否 | String | 語言（`en`/`my`/`th`/`id`/`vn`），預設 `en` |
| `bankcode` | 否 | String | 指定支付通道的後台數字 ID；不指定則顯示銀行選擇頁 |
| `contractname` | 否 | String | 當 `bankcode` 未指定時需提供 |
| `srcbankcode` | 否 | String | 定義來源銀行代碼（PromptPay 用） |
| `srcbankaccname` | 否 | String | 定義來源銀行帳戶名稱（PromptPay 用） |
| `srcbankaccno` | 否 | String | 定義來源銀行帳號（PromptPay 用） |
| `destbankcode` | 否 | String | 定義目標銀行代碼 |
| `destbankaccname` | 否 | String | 定義目標銀行帳戶名稱 |
| `destbankaccno` | 否 | String | 定義目標銀行帳號 |

> `srcbankcode` / `srcbankaccname` / `srcbankaccno` 三個欄位（PDF 黃色標示）：用於 PromptPay（泰國）指定付款方銀行帳戶詳情。

**成功響應（HTTP 200）**

```json
{
    "status": 1,
    "message": "success",
    "trxid": 2514,
    "redirecturl": "https://example.invalid/api/splink/{token}"
}
```

| 欄位 | 說明 |
|---|---|
| `status` | `1` = 成功（整數） |
| `message` | `"success"` |
| `trxid` | 三方交易 ID |
| `redirecturl` | 收銀台 URL，導向此 URL 讓用戶完成支付 |

> `status === 1`（整數）才視為成功。codebase 以 `$result['status'] !== 1` 判定失敗。

**失敗響應範例**

```json
{
    "status": 0,
    "message": "Invalid token"
}
```

**codebase 行為補充**：

`bankcode` 與 `contractname` 是二選一（見上方參數表）。`Deposit::buildChannelParams()` 依 `order_types.key` 是否為純數字決定走哪一條：

| `order_types.key` | 送出的欄位 | 三方行為 |
|---|---|---|
| 純數字（如 `10022198`） | `bankcode` | 直跳該通道 |
| 非數字（如 `OB` / `QR` / `Ewallet`） | `contractname`（取 `info.CONTRACT_NAME`） | 依合約通道顯示收銀台選擇頁 |

判準取「是否為純數字」，因為 `bankcode` 依規格是三方後台建立的通道數字 ID，非數字字串不是合法值。

**bankcode 路徑**已在正式環境驗證。2026-08-26 實查四站：VWorld `10022198`（DuitNowQR）、`10022199`（Tng PayLink）；TPower `10022201`（QRIS）；Yes2Win `10022204`（QRIS）；Alfawin77 未建。其中 VWorld `10022198` 累積 12,439 筆訂單。

**contractname 路徑**沿用舊版 Sure 的分流方式：一條通道一個 `agent_cash_flows`、各帶自己的 `CONTRACT_NAME`。Yes2Win 舊版 Sure（`cash_flow_id 26`）的兩條 MYR 通道即為此結構（`acf 3` 與 `acf 5` 的 `CONTRACT_NAME` 不同、`MERCHANT_ID` 相同）。因此資料檔 `providers/SurePay.php` 的 MYR 泛用 key（`OB` / `QR`）可直接建通道，**不需要先向三方索取數字 ID**。

#### 回應 URL 前綴會揭露走了哪條路

| 前綴 | 對應路徑 |
|---|---|
| `/api/spshow/{token}` | contractname（收銀台銀行選擇頁） |
| `/api/splink/{token}` | bankcode（直跳指定通道） |

排查時看 `redirecturl` 前綴即可反推送出的是哪個欄位，不必翻請求 payload。

#### 兩條路徑的實打結果（2026-08-26，Dev 測試商戶）

以工單 `target/tickets#3438` 的測試商戶在 Dev 實打，四條路徑全部回 `status:1`：

| 路徑 | ACF | `order_types.key` | 回應 |
|---|---|---|---|
| contractname | 477 | `OB` | `/api/spshow/…` |
| contractname | 479 | `QR` | `/api/spshow/…` |
| bankcode | 477 | `10022095`（AmBank） | `/api/splink/…` |
| bankcode | 479 | `10022099`（DuitNowQR） | `/api/splink/…` |

**反向驗證**：手動重現修正前的行為（對 `OB` 通道送 `bankcode=OB`），三方回 `{"status":-103,"message":"error contract"}`。⇒ 泛用 key 送進 `bankcode` 是不被接受的，該修正是修 bug 而非防禦性調整。

#### ⚠️ 每條通道是獨立的憑證組，不只 CONTRACT_NAME 不同

Dev `acf 477`（OB）與 `acf 479`（QR）實測：`CONTRACT_NAME`、`API_KEY`、`CALLBACK_API_KEY` **三者皆不同**，只有 `MERCHANT_ID` 相同（工單 #3438 亦分別列出兩組 ApiKey / CallBackApiKey）。

因此走 contractname 分流時，**每條通道必須是獨立一筆 `agent_cash_flows`**。若把兩條通道併進同一筆 ACF，會同時壞掉兩件事：

1. **代收分流失效** —— 兩條通道都帶到同一個 `CONTRACT_NAME`。
2. **回調驗簽必定有一條失敗** —— 原因見下。

##### 回調憑證是綁在 ACF 上的，不是綁在訂單或通道上

下單時 `post_url` 由 `Common::depositCallbackUrl($agentCashFlow->id)` 產生（`app/Helpers/Traits/PaymentTrait.php:14`），路徑尾端就是**發起該筆訂單的 ACF id**：

```
{api_external_url}external/api/deposit/notify/{agentCashFlowId}
```

回調進來時 `DepositNotifyController::__invoke()` 直接用該路徑參數 `AgentCashFlow::find($agentCashFlowId)` 取 ACF，再交給對應 provider 驗簽（`app/Http/Controllers/External/CashFlow/DepositNotifyController.php:39`）。也就是說**驗簽用的 `CALLBACK_API_KEY` 完全由回調 URL 裡的 ACF id 決定**。

而三方是用「該通道所屬合約」的 CallBackApiKey 簽名。兩者必須對得上。

**負向實測**（2026-08-26 Dev，以 reflection 直接呼叫 `checkToken()`，未觸發訂單流程）：

| 情境 | 端點 ACF | 三方簽名用的 key | 驗簽結果 |
|---|---|---|---|
| A：兩通道各自一筆 ACF（正確配置） | 477 | OB 合約 | **通過** |
| B：兩通道併成一筆 ACF 時 QR 那條的下場 | 477 | QR 合約 | **失敗** |
| C：對照組 | 479 | QR 合約 | **通過** |

情境 B 的後果：回調在 `checkToken()` 就被擋下，回 `Token verification failed`，**訂單不會更新、玩家不會上分** —— 玩家已付款但點數沒進，只能人工補單。且建置階段完全不會報錯，要等真實付款才會發現。

> **2026-08-26 兩次更正**：
> 1. 原文寫「`bankcode` 帶入 `OB` / `QR` / `Ewallet` 等數字 ID」自相矛盾（`OB` 不是數字 ID），且稱泛用 key 時三方會顯示選擇頁 —— 當時的實作恆送 `bankcode`、從不送 `contractname`，該行為不存在。
> 2. 本節一度寫成「建 MYR 通道前必須先取得通道數字 ID」，這是錯的：依 PDF v2.22 §4／§5，未指定 `bankcode` 時改帶 `contractname` 即可，舊版 Sure 一直是這樣運作。`Deposit.php` 已補上該分支。

### 建置通道時的四個陷阱

以下四點都不會在建置階段報錯，全部要等上線或真實付款才會發現。

#### 一、通道名稱不能靠改資料檔對齊

`SyncCashFlowsCommand::detectChanges()` 明文只比對 `info`，**既有 `order_types` 的 `name` 與 `image_path` 一律不覆寫**（兩者是營運可在後台調整的顯示資產，由 sync 回寫會把人工調整靜默蓋掉）。`name` 只在**建立時**取用資料檔的值。

而資料檔是全站共用單一值，各站舊通道名稱卻不同：

| 站 | 對應 `OB` 的舊名 | 對應 `QR` 的舊名 |
|---|---|---|
| Yes2Win | `Instant TopUp (Channel4)` | `DuitNow (Channel 4)` |
| VWorld | `Instant TopUp (S)` | `Ewallet (Channel2)` |

⇒ 改資料檔最多只能滿足一站。要達到「配置等價」（代理商在後台感覺不出換了金流商），只能**建完通道後逐站改名**，不要試圖用資料檔或重跑 sync 解決。

#### 二、`type` 不符會多建一列，而不是更新既有列

`findExistingOrderType()` 的查找鍵是 `(cash_flow_id, currency_id, key, type, parent)` 五欄全等，**`type` 也在其中**。

已知分歧：Yes2Win 舊 `DuitNow (Channel 4)` 是 `type=1`（電子支付），資料檔 `SurePay (QRCode)` 是 `type=3`（QR 掃碼）。若沿用資料檔預設值去 sync，會**新增一列 order_type** 而非接上舊列，前台會同時出現新舊兩條 QR 通道。

#### 三、ACFOT 開著、order_type 關著＝備用配置，不要當孤兒清掉

`check:cashflow-orphans` 會把這種列報成 `[L2] 類型停用`，而 `fix:cashflow-orphans` 的處置是把 ACFOT 一併下架（`status=0`）。

但對 SurePay 而言這是**刻意的備用配置**：各家銀行的直跳通道（`10022xxx`）先把 ACFOT 建好、order_type 保持停用，日後要開哪家銀行只需打開該 order_type。2026-08-26 Dev 實測 `check:cashflow-orphans --cashflow=97` 就報出 11 筆這種列（`acfot 850–858`、`860–861`）。

⇒ **不要對 SurePay 跑 `fix:cashflow-orphans`**，否則備用配置會被清掉一半。

#### 四、憑證錯配是既有工具的偵測盲點

若某條通道的 ACFOT 掛到了「憑證不屬於該通道」的 ACF 下（例如把 QR 的 order_type 掛在 OB 憑證的 ACF 上），沒有任何現成檢查抓得到：

- `check:cashflow-orphans` 不會報（該 order_type 是啟用的，不符任何 orphan_reason）
- 「跨 ACF 掛同一 order_type」本身是**合法配置**（同金流商多商戶號），見 `AgentCashFlowOrderTypeObserver` 的 invariant 說明，無法據此判定異常
- 判準需要「哪個 `CONTRACT_NAME` 對應哪條通道」的三方合約知識，無法一般化成 SQL 或命令

前台會取同一 order_type 中**最小 id** 的 ACFOT（`OrderTypeService::getOrderTypes()` 的 `orderBy('id')` ＋ `unique('order_type_id')`），所以錯配列的 id 大小決定它會不會實際生效 —— 這是巧合而非保護。

**實例（Dev，已處置）**：`acfot 859` 掛在 `acf 477`（OB 憑證）下卻是 order_type 1092（QR），與 850–861 同批於 2026-02-26 誤建（限額 10/50000 沿用銀行子通道預設，非 QR 的 30/1000），2026-03-01 被停用。因 `849 < 859`，前台恰好仍取到正確的 `849`；但一旦 `849` 被停用，QR 通道就會落到 `859`，用 OB 的 `CONTRACT_NAME` 下單、OB 的 `CALLBACK_API_KEY` 驗簽 ⇒ 即上節情境 B。已於 2026-08-26 軟刪（`deleted_at` 寫入、`is_live` 轉 NULL），前台曝露不變。

⇒ 建通道後應人工核對一次：**每筆 ACFOT 的 order_type 所屬通道，與其 ACF 的 `CONTRACT_NAME` 是否相符**。

#### 五、對已拆分的結構不要重跑 `agent:add-agent-cash-flow`

2026-08-26 在 Dev（`acf 477` OB／`acf 479` QR 已是拆分結構）以 `--mode=split --dry-run` 乾跑，輸出：

```
✓ 已 rename 單筆 AgentCashFlow id=477 → "(MYR) SurePay (Online Banking)"
✓ 已建立 AgentCashFlow id=7669 → "(MYR) SurePay (QRCode)"
...
✓ 沿用遷移後 AgentCashFlow (ID: 479)
```

命令把現況誤判為「單筆模式」而走了 split 遷移路徑：改名 `477`、另建一筆 QR 的 ACF，而 `479` 本來就是 QR 的專用 ACF。新建那筆的憑證來自 `477`（OB 憑證）⇒ 若真的寫入，等於**由命令自己製造出上一節的憑證錯配**，而且是 live 狀態。

⚠️ **此結論未經實跑確認**：同一次乾跑的統計表顯示 `AgentCashFlow (新增) 0`、`OrderType 關聯 0`，與上方訊息矛盾（可能是事務內已 INSERT 取得 id 後回滾、而統計計數器走另一條路徑）。實跑會真的寫資料，因此沒有進一步驗證。

⇒ 要補通道請只用針對性操作（`cash-flow:sync` 補 order_type、`cash-flow:channel-status` 改上下架），**別對已拆分的金流商重跑建置命令**。真要跑，先 `--dry-run` 逐行讀輸出，不要只看統計表。

**順帶確認**：同一次乾跑證實軟刪 `859` 後**不會**被重建 —— 各 channel 只補自己那條 ACFOT（輸出為「既有，保留原配置」），不會為 `acf 477` 補 QR 的 ACFOT。

---

## 4. 代付 API

### 代付發起 — `POST https://example.invalid/payout`

Header：`Content-Type: application/x-www-form-urlencoded`（PDF 規定），codebase 以 JSON 方式傳送（`asJson()`）。

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `merchant` | 是 | String | 商戶帳號（`MERCHANT_ID`） |
| `amount` | 是 | Float | 代付金額（如 `12.99`） |
| `refid` | 是 | String | 商戶代付訂單號，唯一 |
| `token` | 是 | String | 請求簽名（見簽名流程 §11.1） |
| `customer` | 是 | String | 會員帳號 |
| `currency` | 是 | String | ISO-4217 幣別（如 `MYR`、`THB`） |
| `bankcode` | 是 | String | 代付通道 bankcode（即 IBAN，`WITHDRAWAL_IBAN` 配置值） |
| `destbankaccname` | 是 | String | 收款人姓名 |
| `destbankcode` | 是 | String | 收款銀行短代碼（見 §12 銀行代碼）；泰國 PromptPay 可填 `PROMPTPAY` |
| `destbankaccno` | 是 | String | 收款銀行帳號；泰國 PromptPay 填收款人已在 PromptPay 登記的手機號 |
| `clientip` | 是 | String | 請求 IP（如 `0.0.0.0`） |
| `post_url` | 是 | String | 代付回調 URL |

> `bankcode`（代付通道 IBAN 數字 ID）與 `destbankcode`（目標銀行短代碼如 `MAYBANK`）是兩個不同欄位，不可混淆。

**代付訂單號前綴**：系統生成訂單號固定前綴為 `Sure`（如 `Sure2026022609275754926`）。

**成功響應（HTTP 200）**

```json
{
    "merchant": "{MERCHANT_ID}",
    "amount": "1.00",
    "refid": "{訂單號}",
    "customer": "{會員帳號}",
    "currency": "MYR",
    "clientIP": "0.0.0.0",
    "statdesc": "In-Progress",
    "status": "1"
}
```

> 代付響應的 `status` 在 PDF 範例是**字串** `"1"`，但 codebase `Withdrawal.php` 以整數判定（`($result['status'] ?? null) === 1`），兩者有出入，實際以三方真實響應為準，建議以 `(int)$result['status'] === 1` 進行判定。
>
> 實測補充（2026-09-14 查 TPower／Yes2Win／VWorld 三個站點全部的 SurePay 代付請求紀錄 `withdrawal_payout_records`）：
>
> - 共 18 筆，全部被拒：TPower 15 筆（`-101` ×1、`-201` ×7、`-110` ×7）、Yes2Win 2 筆（`-101`）、VWorld 1 筆（`-101`）。`status` 全是 JSON **整數**。
> - **沒有一筆成功**，所以成功時 `status` 是整數還是字串，仍未驗證。

| status 值 | statdesc | 說明 |
|---|---|---|
| `1` | In-Progress | 處理中（視為成功提交） |
| `-101` | Insufficient Balance | 商戶餘額不足 |
| `-102` | Withdrawal Bank Not Found | 銀行代碼不存在 |
| `-103` | Invalid Request | 請求參數無效 |
| `-104` | Request Rejected | 請求被拒絕 |
| `-106` | Dest Bank Blacklisted | 目標銀行已列入黑名單 |
| `-107` | Invalid Merchant | 商戶帳號無效 |
| `-108` | Invalid Token | 簽名驗證失敗 |
| `-110` | Invalid Credential | 憑證無效（官方文檔未載；成因待 SurePay 確認，見下方說明） |
| `-201` | Unauthorised access detected | 偵測到未授權存取（官方文檔未載；成因待 SurePay 確認，見下方說明） |

> **`-110`、`-201` 是實測回傳、官方文檔沒有列的碼**。statdesc 欄是 SurePay 回傳的原文，說明欄的意思照原文翻譯；**成因還沒確認**，等 SurePay 回覆（target/op_tickets#3173）。
>
> - `-110`：2026-07～09 回傳 7 次，都在 TPower 的 `agent_cash_flows` id 348（印尼幣 SurePay 代付），例：提款單 2254834。
> - `-201`：2026-06 回傳 7 次，同樣在 `agent_cash_flows` id 348，例：提款單 2082904。

---

## 5. 餘額查詢 API

### 餘額查詢 — `POST https://example.invalid/merchant/balance`

> **Balance.php config key 命名差異**：`Balance.php` 使用 `info['MerchantID']`、`info['ApiKey']`、`info['APIUrl']`，而非其他模組的 `MERCHANT_ID`、`API_KEY`、`API_URL`。這是 codebase 內的歷史命名不一致，PDF 本身無此區別。

**請求 Headers**

```
Content-Type: application/json
Authorization: Bearer {API_KEY}
```

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `merchant` | 是 | String | 商戶帳號（Merchant code） |

**成功響應（HTTP 200）**

```json
{
    "contractname": "{合約名稱}",
    "domain": "{商戶域名}",
    "inbal": "1005.00",
    "outbal": "0.00",
    "statdesc": "Success",
    "status": 1
}
```

| 欄位 | 說明 |
|---|---|
| `inbal` | 代收可用餘額（`depositBalance`） |
| `outbal` | 代付可用餘額（`withdrawBalance`） |
| `statdesc` | 狀態描述 |
| `status` | `1` = 成功 |

---

## 6. 餘額轉移 API

> PDF §12 新增功能（v2.10，2024-07-23 引入）。codebase 目前尚未實作此功能。

### 餘額轉移 — `POST https://example.invalid/merchant/baltransfer`

用於將代收餘額轉移至代付（deposit → payout）。

Header：`Content-Type: application/json`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `merchant` | 是 | String | 商戶帳號 |
| `contractname` | 是 | String | 合約名稱（指定從哪個 deposit 合約轉） |
| `amount` | 是 | Float | 轉移金額；越南銀行不支援小數 |
| `refid` | 是 | String(30) | 商戶交易 ID，唯一 |
| `token` | 是 | String | 請求簽名（見 §11.3） |
| `currency` | 是 | String | ISO-4217 幣別 |
| `clientip` | 是 | String | 授權來源 IP |

**成功響應**

```json
{
    "merchant": "{MERCHANT_ID}",
    "amount": "100",
    "refid": "{訂單號}",
    "contractname": "{合約名稱}",
    "currency": "MYR",
    "clientIP": "0.0.0.0",
    "statdesc": "success",
    "status": 1
}
```

---

## 7. 交易查詢 API

> PDF §13。codebase 目前尚未實作此功能。

### 交易狀態查詢 — `POST https://example.invalid/transaction/status`

Header：`Content-Type: application/json`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `token` | 是 | String | 代收請求簽名（請求簽名 token，非 callback token） |

**成功響應（HTTP 200）**

```json
{
    "merchant": "{MERCHANT_ID}",
    "amount": "100.00",
    "refid": "{訂單號}",
    "customer": "{會員帳號}",
    "trxno": "{三方交易號}",
    "status": 1,
    "status_message": "success"
}
```

---

## 8. 銀行帳號查詢 API（IBAN）

> PDF §7。實作：`app/Services/Payment/Providers/SurePay/BankQuery.php`（`cash-flow:third-party-query` 消費）。回應含 accname／bankaccno（PII），入 log 前必過遮罩。

### 銀行帳號查詢 — `POST https://example.invalid/v1/bankquery`

查詢商戶合約下所有可用的 IBAN（bankcode 數字 ID）清單。

**Headers**

```
Authorization: Bearer {API_KEY}
Content-Type: application/json
```

**響應欄位**

| 欄位 | 說明 |
|---|---|
| `bankname` | 銀行短代碼 |
| `accname` | 銀行帳戶名稱 |
| `bankaccno` | 銀行帳號 |
| `iban` | 指派給此帳戶的 bankcode 數字 ID |
| `banktype` | `D` = 代收，`W` = 代付 |
| `bankminamout` | 最低金額 |
| `bankmaxamout` | 最高金額 |
| `created` | 記錄建立時間 |

> 透過此 API 可取得 `iban`（即 `bankcode` 數字 ID），用於代收 `bankcode` 欄位或代付 `WITHDRAWAL_IBAN` 配置。

#### 實測補充（2026-08-28，Dev 測試商戶實打）

以 `artisan cash-flow:third-party-query <acf> --raw` 對 Dev 的 SurePay 測試商戶實打（MYR 主合約／MYR QR 合約／IDR 合約三份，共 5 個 ACF）。**實際回應與上表有三處出入，以實測為準**（三份合約的欄位形狀完全一致）：

| 項目 | 上表（PDF） | 實際回應 |
|---|---|---|
| 限額欄位名 | `bankminamout` / `bankmaxamout` | **`bankminamt` / `bankmaxamt`**（少 `ou`） |
| `banktype` 值 | 大寫 `D` / `W` | **小寫 `d` / `w`** |
| 頂層結構 | 未載明 | **JSON 陣列，無外層 envelope**（無 code／msg 包裹） |

`BankQuery` 的映射兩套拼法與大小寫都吃，三方改回文件寫法時不會靜默退化成「限額未回傳」。錯誤格式與空清單的形狀仍未實測——目前採 fail-closed：頂層非陣列一律拋 `ApiException`，不靜默回空快照。

**⚠️ `bankname` 的語意是「該通道自己的收付款帳戶所屬銀行」，不是「這條通道支援哪些銀行」**：每列都帶 `accname`／`bankaccno`，整列描述的是 SurePay 端的一個銀行帳戶。代收通道因為是「玩家轉入這個帳戶」，一條通道對一家銀行成立；**代付通道的 `bankname` 只是出款帳戶的開戶行，與「可出款到哪些銀行」無關**，不可拿來比對我方 `available_banks`。掃碼類通道的 `bankname` 甚至不是銀行名而是通道標記（實測見過 `CENTURY_QRIS`、`Duitnow DQR`、`TNG`），更不能當銀行代碼用。

**⚠️ 回應範圍由 `API_KEY` 綁定的「合約」決定，不是商戶**：同一 `MERCHANT_ID` 底下每個 `CONTRACT_NAME` 有各自的 `API_KEY` 與通道集。實測同商戶三份合約：

| 合約 | 通道數 | 通道 |
|---|---|---|
| MYR 主合約 | 10 | 9 條代收（各對一家銀行）＋1 條代付 |
| MYR QR 合約 | 2 | `Duitnow DQR`、`TNG`（皆代收） |
| IDR 合約 | 2 | `CENTURY_QRIS`（代收）＋`BRI`（代付） |

⇒ **要盤完一個商戶的全部通道，必須逐合約（逐 ACF）查**，查任一支不會回全部。同合約下的代收 ACF 與代付 ACF 則回完全相同的清單（與該 ACF 自身方向無關），故同合約只需查一次。

**排查用途**：走 `contractname` 路徑的 ACF（`order_types.key` 非數字，如 `OB`／`QR`）可用本 API 反查該合約實際有哪些 `iban` 通道碼與三方端真實限額——這是把該類配置轉成 `bankcode` 直跳路徑的素材來源。

---

## 9. 馬來西亞電話儲值 PIN

> PDF §14。codebase 目前尚未實作此功能。

### 提交請求 — `POST https://example.invalid/telco/reloadpin`

Header：`Content-Type: application/json`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `merchant` | 是 | String | 商戶帳號 |
| `refid` | 是 | String | 商戶訂單號，唯一 |
| `token` | 是 | String | 請求簽名 `md5(merchant + refid + customer + apikey + ReloadPIN)` |
| `customer` | 是 | String(30) | 會員帳號，最大 30 字元 |
| `bankcode` | 是 | String | 後台 bankcode；不指定則顯示選擇頁 |
| `clientip` | 是 | String | 會員 IP |
| `ReloadPIN` | 是 | String | 儲值 PIN，14-16 位數字 |

> 此 API 的 token 公式與代收/代付不同，為 `md5(merchant + refid + customer + apikey + ReloadPIN)`。

**響應狀態碼（Telco PIN 特有）**

| status | statdesc |
|---|---|
| `1` | success |
| `-1` | Card is used / Invalid PIN / Can not find PIN status |
| `-3` | TIMEOUT |
| `-103` | Error merchant/contract |
| `-106` | Error result not found |
| `-107` | Error invalid response from provider |
| `-108` | Unregonised telco |

---

## 10. 回調處理

### 10.1 代收回調

SurePay 在支付完成（成功或失敗）後，以 **HTTP POST** 格式推送到代收請求的 `post_url`。

**重要**：可能發生二次回調（Back Post）——初次回調因 session TIMEOUT 導致失敗時，銀行會在 5 分鐘內以相同 `trxno` 和 `token` 重新推送並修正狀態，系統必須接受此次後補回調（status 可能從失敗改為成功）。

**回調欄位**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `merchant` | String | 商戶帳號 |
| `amount` | String | 實際支付金額 |
| `refid` | String | 商戶訂單號（我方訂單號） |
| `customer` | String | 會員帳號 |
| `clientip` | String | 用戶 IP |
| `token` | String | 回調驗證簽名（見簽名流程 §11.2） |
| `trxno` | String | 三方交易號（支付閘道唯一編號） |
| `srcbankcode` | String | 來源銀行代碼（如 `DNDQR`、`MAYBANK`） |
| `destbankcode` | String | 目標銀行代碼（僅適用自有銀行商戶，選填） |
| `destbankacc` | String | 目標銀行帳號（選填） |
| `destbankname` | String | 目標銀行帳戶名稱（選填） |
| `status` | String | 訂單狀態：`"1"` = 成功，`"-1"` = 失敗 |
| `status_message` | String | 狀態描述（見 §10.3 狀態訊息列表） |

> `status` 為**字串**（`"1"` / `"-1"`），非整數。codebase `DepositCallback.php` 以 `$input['status'] === '1'` 比對。

**成功判定**：`status === "1"`（字串比對）

**回調範例（成功）**

```json
{
    "status_message": "success",
    "token": "{MD5_CALLBACK_TOKEN}",
    "currency": "MYR",
    "amount": "52.00",
    "fee": "0.91",
    "merchant": "{MERCHANT_ID}",
    "customer": "{會員帳號}",
    "refid": "{訂單號}",
    "status": "1",
    "srcbankcode": "DNDQR",
    "trxno": "{三方交易號}",
    "clientip": "{用戶IP}"
}
```

**回調響應**：回傳純文字表示接收狀態：

| 響應 | 含義 |
|---|---|
| `success` | 成功接收並處理 |
| `fail` | 處理失敗（token 驗證失敗或例外） |

---

### 10.2 代付回調

**回調欄位**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `merchant` | String | 商戶帳號 |
| `amount` | String | 代付金額 |
| `refid` | String | 商戶代付訂單號（`Sure...` 前綴） |
| `customer` | String | 會員帳號 |
| `clientip` | String | 請求 IP |
| `token` | String | 回調驗證簽名（見簽名流程 §11.2） |
| `trxno` | String | 三方交易號 |
| `currency` | String | 幣別 |
| `status` | String | `"1"` = 成功，`"-1"` = 失敗 |
| `status_message` | String | 狀態描述 |

**成功判定**：`status === "1"`（字串比對）

**代付失敗 status_message 範例**

| status_message |
|---|
| `System Unavailable` |
| `Aborted` |
| `Unable to process transaction. (16338)` |
| `Sorry, we are unable to process your request at this time. Please try again later. (2185)` |
| `Your account available balance is not sufficient for this transaction. (2134)` |
| `You will receive a notification on your phone to authorise this transaction on the new Maybank app.` |

**回調響應**：同代收，回傳 `success` 或 `fail`。

---

### 10.3 代收失敗 status_message 列表

當 `status === "-1"` 時，`status_message` 可能為以下任一值：

1. Payment Timeout
2. Your account available balance is not sufficient
3. System unavailable, please try again later.
4. Sorry, service is temporarily unavailable.
5. Unable to login at the moment due to some connection error
6. 0039: Your transaction is not successful. Try again
7. Unsuccessful This service is temporarily unavailable
8. TAC entered is incorrect. Please try again.
9. Unsuccessful - This service is temporarily unavailable
10. The User IDPassword entered is invalid. Please try again
11. The User is already Logged In
12. DBS service is currently unavailable. Please try again
13. Your User ID or PIN is incorrect. Please try again....
15. Sorry, we are unable to process your request at the moment
16. Sorry, you have entered an invalid User ID.
17. Digi system maintenance from 12:00 to 12:10.
18. Invalid Login. Please try again
19. Unsuccessful Rejected 112041117
20. Unable to perform this action.
21. Invalid Password. Login Failed.
22. U128: Your transaction is not successful. Try again
23. INSTANT_TRANSFER_UNAVAILABLE
24. INSTANTTRANSFERUNAVAILABLE

---

## 11. 簽名流程

SurePay 使用兩套 MD5 簽名：**請求簽名**（發送 API 時帶入 `token`）與**回調驗證簽名**（驗證三方推送的回調）。另有**餘額轉移**和**Telco PIN** 各自的獨立公式。

### 11.1 請求簽名（代收/代付/餘額轉移共用）

用於代收 `/api/request`、代付 `/payout` 請求中的 `token` 欄位。

**公式**：

```
token = [REDACTED]
```

**欄位順序**（直接拼接，無分隔符）：

```
merchant → amount → refid → customer → API_KEY → currency → clientip
```

**計算範例**：

- merchant = `M00001`
- amount = `100.00`
- refid = `INV1000001`
- customer = `CUST001`
- apikey = [REDACTED]
- currency = `MYR`
- clientIP = `0.0.0.0`

字串：`M00001100.00INV1000001CUST001{API_KEY}MYR111.222.333.444`

**PHP 實作（SurePayTrait.php）**：

```php
protected function generateToken(array $params): string
{
    $string = $params['merchant']
        . $params['amount']
        . $params['refid']
        . $params['customer']
        . $this->info['API_KEY']
        . $params['currency']
        . $params['clientip'];

    return md5($string);
}
```

---

### 11.2 回調驗證簽名（代收/代付回調）

用於驗證代收/代付回調中的 `token` 欄位。使用 **`CALLBACK_API_KEY`**（非請求用的 `API_KEY`）。

**公式**：

```
token = [REDACTED]
```

**欄位順序**（直接拼接，無分隔符）：

```
merchant → amount → status → CALLBACK_API_KEY → trxno
```

**計算範例**：

- merchant = `M00001`
- amount = `100.00`
- status = `1`
- callback_apikey = [REDACTED]
- trxno = `10188228`

字串：`M00001100.001{CALLBACK_API_KEY}10188228`

**PHP 實作（SurePayTrait.php）**：

```php
protected function generateCallbackToken(array $params): string
{
    $string = $params['merchant']
        . $params['amount']
        . $params['status']
        . $this->info['CALLBACK_API_KEY']
        . $params['trxno'];

    return md5($string);
}
```

---

### 11.3 餘額轉移簽名

**公式**：

```
token = [REDACTED]
```

> 注意：餘額轉移使用的 `apikey` 是**代付（payout）用的 API_KEY**，且欄位順序中插入了 `contractname`，與代收/代付請求簽名的公式不同。

---

### 11.4 Telco PIN 簽名

**公式**：

```
token = [REDACTED]
```

> 與代收/代付公式完全不同，沒有 `amount`、`currency`、`clientip`，增加了 `ReloadPIN`。

---

### 簽名總覽

| 用途 | 公式 | 使用的 Key |
|---|---|---|
| 代收請求 | `merchant + amount + refid + customer + key + currency + clientip` | `API_KEY` |
| 代付請求 | `merchant + amount + refid + customer + key + currency + clientip` | `API_KEY` |
| 代收回調驗證 | `merchant + amount + status + key + trxno` | `CALLBACK_API_KEY` |
| 代付回調驗證 | `merchant + amount + status + key + trxno` | `CALLBACK_API_KEY` |
| 餘額轉移 | `merchant + amount + refid + contractname + key + currency + clientip` | `API_KEY（payout）` |
| Telco PIN | `merchant + refid + customer + key + ReloadPIN` | `API_KEY` |

---

## 12. 銀行代碼說明

SurePay 的銀行代碼（bankcode）分兩種：

1. **後台數字 ID（IBAN）**：如 `{bankcode}`（8 位數字，例 `1002xxxx`），用於代收請求的 `bankcode` 欄位和代付請求的 `bankcode`（WITHDRAWAL_IBAN）欄位。每個商戶獨立分配，不同商戶的同一銀行有不同 ID，不可跨商戶共用。
2. **銀行短代碼**：如 `MAYBANK`、`CIMB`，用於代付請求的 `destbankcode` 欄位（收款目標銀行）。

### MYR 存提款銀行代碼（`destbankcode`，代付目標銀行）

| 代碼 | 類型 | 銀行名稱 |
|---|---|---|
| `MAYBANK` | DP | Maybank |
| `CIMB` | DP | CIMB Group Holdings |
| `PBE` | DP | Public Bank Berhad |
| `RHB` | DP | RHB Bank |
| `HLB` | DP | Hong Leong Bank |
| `BSN` | DP | Bank Simpanan Nasional |
| `AMBANK` | DP | Ambank Berhad |
| `BKRM` | DP | Bank Rakyat Malaysia |
| `BIMB` | DP | Bank Islam Berhad |
| `TNG` | DP | Touch n Go |
| `DBDQR` | D | DuitNow Dynamic QR |
| `BMMB` | P | Bank Muamalat |
| `AGRO` | P | Bank of Agro |
| `HSBC` | P | Hong Kong And Shanghai Bank |
| `UOBMY` | P | United Overseas Bank |
| `OCBC` | P | Oversea-Chinese Banking Corporation |
| `SCBM` | P | Standard Chartered Malaysia |
| `GXB` | P | GX Bank Berhad |
| `ALLIANCE` | P | Alliance Bank |
| `AFFIN` | P | Affin Bank |

### SGD 存提款銀行代碼

| 代碼 | 類型 | 銀行名稱 |
|---|---|---|
| `DBS` | DP | DBS Bank Limited |
| `UOB` | DP | United Overseas Bank Limited |
| `OCBC` | DP | OCBC Bank |
| `MBBE` | DP | Maybank Singapore |
| `SCBLSG` | DP | Standard Chartered Bank Singapore |
| `POSB` | D | POSB |
| `PAYNOW` | D | Singapore PayNow |
| `ANZ` | P | Australia And New Zealand Bank |
| `BKCH` | P | Bank Of China |
| `BNPASG` | P | BNP Paribas |
| `CIMBSG` | P | CIMB |
| `CITISG` | P | Citibank Singapore |
| `DBK` | P | Deutsche Bank AG |
| `GPAY` | P | GPAY |
| `HLBBSG` | P | HLB Singapore |
| `HSBCCORP` | P | HSBC Coorperation |
| `HSBC` | P | HSBC Singapore - Personal |
| `ICICIB` | P | ICICI Bank |
| `ICBKSG` | P | Industrial and Commercial Bank |
| `JPM` | P | Jpmorgan Chase Bank |
| `LIQPAY` | P | Liquid Pay |
| `MBBGB` | P | Malayan Banking Berhad, Singapore Branch |
| `MMOVEPAY` | P | Match Move Pay |
| `MHCBSG` | P | Mizuho Bank Singapore |
| `SIVFSG` | P | Singapore Investments & Finance Limited |
| `SINGCASH` | P | Sing Cash |
| `SMBCSG` | P | Sumitomo Mitsui |
| `BOTKSG` | P | The Bank Of Tokyo-Mitsubishi |
| `WISE` | P | Wise Asia Pacific |

### THB 存提款銀行代碼

| 代碼 | 類型 | 銀行名稱 |
|---|---|---|
| `THAIPP` | D | Thai PromptPay |
| `KBANKPROMPTPAY` | D | Kasikornbank |
| `SCBPROMPTPAY` | D | Siam Commercial Bank |
| `BBLPROMPTPAY` | D | Bangkok Bank |
| `KTBPROMPTPAY` | D | Krung Thai Bank |
| `PROMPTPAY` | P | PromptPay（destbankaccno 填 Thai 手機號） |
| `KBANK` | P | KASIKORNBANK |
| `KTB` | P | KRUNG THAI BANK PUBLIC COMPANY LTD. |
| `BBL` | P | BANGKOK BANK PUBLIC COMPANY LTD. |
| `SCB` | P | Siam Commercial Bank |
| `BAAC` | P | Bank for Agriculture and Agricultural Co-operatives |
| `BOC` | P | Bank of China (Thai) |
| `CITI` | P | CITIBANK N.A. |
| `CIMBTH` | P | CIMB THAI BANK PUBLIC COMPANY LIMITED |
| `GHB` | P | GOVERNMENT HOUSING BANK |
| `MHCB` | P | MIZUHO CORPORATE BANK, LTD. |
| `KKB` | P | KIATNAKIN BANK PUBLIC COMPANY LIMITED |
| `TISCO` | P | TISCO BANK PUBLIC COMPANY LIMITED |
| `GSB` | P | GOVERNMENT SAVING BANK |
| `TMB` | P | TMBThanachart Bank Public Company Limited |
| `SCBT` | P | STANDARD CHARTERED BANK (THAI) PCL. |
| `TCRB` | P | THE THAI CREDIT RETAIL BANK PUBLIC COMPANY LIMITED |
| `SMBC` | P | SUMITOMO MITSUI BANGKING CORPORATION |
| `LHBA` | P | Land and Houses Bank Public Company Limited |
| `TIBT` | P | ISLAMIC BANK OF THAILAND |
| `HSBCTH` | P | HONGKONG AND SHANGHAI CORPORATION LTD. |
| `DBTH` | P | DEUTSCHE BANK AG, BANGKOK BRANCH |
| `ICBC` | P | Industrial and Commercial Bank of China (Thai) |
| `UOBTH` | P | UNITED OVERSEAS BANK (THAI) PCL. |
| `BAY` | P | BANK OF AYUDHAYA PUBLIC COMPANY LTD. |

### VND 存提款銀行代碼

| 代碼 | 類型 | 銀行名稱 |
|---|---|---|
| `VIETCOM` | DP | Vietcom Bank |
| `SACOM` | DP | Sacom Bank |
| `TECHCOM` | DP | Techcom Bank |
| `VIETIN` | DP | Vietin Bank |
| `BIDV` | DP | Bank for Investment and Development of Vietnam |
| `MOMO` | D | Momo Pay |
| `VietQR` | D | VietQR（新版） |

### IDR 存款銀行代碼

| 代碼 | 類型 | 銀行名稱 |
|---|---|---|
| `MANDIRI` | D | Bank Mandiri |
| `BRI` | D | Bank Rakyat Indonesia |
| `BCA` | D | Bank Central Asia |
| `BNI` | D | Bank Negara Indonesia |
| `CIMB` | D | CIMB Bank Indonesia |
| `MAYBANK` | D | Maybank Indonesia |
| `LINKAJA` | D | Link Aja App wallet |
| `OVO` | D | Ovo Payment |
| `QRIS` | D | QRIS eWallet |
| `DANA` | D | DANA eWallet |
| `DANAMON` | D | Bank Danamon |
| `SHOPEEPAY` | D | Shopeepay Indonesia |
| `ALFAMART` | D | Alfamart eWallet |

### MYR 代收通道參考（後台數字 IBAN，各站不同）

> 以下為工單記錄的範例值，**不可硬編碼**，實際值以各站商戶配置為準。

以下為 VWorld 站（工單 #3517）的範例值：

| 銀行 | bankcode 範例（僅供參考） |
|---|---|
| MAYBANK | `{bankcode}` |
| CIMB | `{bankcode}` |
| PUBLIC BANK | `{bankcode}` |
| RHB | `{bankcode}` |
| HONG LEONG | `{bankcode}` |
| BSN | `{bankcode}` |
| AMBANK | `{bankcode}` |
| BANK RAKYAT | `{bankcode}` |
| BANK ISLAM | `{bankcode}` |
| TNG（電子錢包） | `{bankcode}` |
| DuitNow QR | `{bankcode}` |

> 代付通道（Payout IBAN）同樣為後台建立的數字 ID，寫入 `WITHDRAWAL_IBAN` 配置欄位。

---

## 13. 錯誤碼對照

### 代付響應 status 碼（整數）

| status | 說明 |
|---|---|
| `1` | In-Progress（處理中，視為成功提交） |
| `-101` | Insufficient Balance（商戶餘額不足） |
| `-102` | Withdrawal Bank Not Found（銀行代碼不存在） |
| `-103` | Invalid Request（請求參數無效） |
| `-104` | Request Rejected（請求被拒絕） |
| `-106` | Dest Bank Blacklisted（目標銀行已列入黑名單） |
| `-107` | Invalid Merchant（商戶帳號無效） |
| `-108` | Invalid Token（簽名驗證失敗） |
| `-110` | Invalid Credential（憑證無效；官方文檔未載、實測回傳，成因待 SurePay 確認，見「代付 API」一節的表下說明） |
| `-201` | Unauthorised access detected（偵測到未授權存取；官方文檔未載、實測回傳，成因待 SurePay 確認，見「代付 API」一節的表下說明） |
| `-999` | Unknown Error（codebase 內部定義） |

### 代收/代付回調 status 碼（字串）

| status | 說明 |
|---|---|
| `"1"` | 成功 |
| `"-1"` | 失敗（reason 見 `status_message`） |

### Telco PIN 響應 status 碼

| status | statdesc |
|---|---|
| `1` | success |
| `-1` | Card is used / Invalid PIN / Can not find PIN status |
| `-3` | TIMEOUT |
| `-103` | Error merchant/contract |
| `-106` | Error result not found |
| `-107` | Error invalid response from provider |
| `-108` | Unregonised telco |

---

## 14. 限額與費率參考

以下為工單記錄的典型值，各商戶實際開通的限額以後台設定為準。

### MYR

| 類型 | 費率 | 最低 | 最高 |
|---|---|---|---|
| 代收（網銀 OB） | 依商戶協議 | 10 MYR | 9,999 MYR |
| 代收（QR / Ewallet） | 依商戶協議 | 30 MYR | 1,000 MYR |
| 代付 | 依商戶協議 | 50 MYR | 49,999 MYR |

### IDR

| 類型 | 費率 | 最低 | 最高 |
|---|---|---|---|
| 代收 QRIS | 3.0% | 20,000 IDR | 20,000,000 IDR |
| 代收 DANA | 3.7% | 20,000 IDR | 20,000,000 IDR |
| 代付 | 1.7% | — | — |

> IDR 費率來源：工單 #3514（TPower IDR）、#3515（Yes2Win IDR）

### THB

| 類型 | 費率 | 最低 | 最高 |
|---|---|---|---|
| 代收 | 2.6% | 10 THB | 150,000 THB |
| 代付 | 1.0% | 500 THB | 49,999 THB |

> THB 費率來源：工單 #2838（Yes2Win THB）

---

## 15. 參考資料

- 官方 API 文檔：`Surepay_api_specification_v2.22_New.pdf`（v2.20，2024-10-05，內部 GitLab 附件，見工單 #3438）
  - 文檔涵蓋完整端點規格、6 國銀行代碼、Sandbox 測試參數
  - 無公開網頁版文檔；PDF 需登入 GitLab 下載
- 內部實作路徑：`app/Services/Payment/Providers/SurePay/`
  - `Driver.php` — 組合代收/代付/餘額查詢子類別
  - `SurePayTrait.php` — 共用 HTTP 請求邏輯、請求簽名（`generateToken`）、回調驗證（`generateCallbackToken` / `checkToken`）
  - `Deposit.php` — 代收實作，呼叫 `POST /api/request`
  - `DepositCallback.php` — 代收回調處理
  - `Withdrawal.php` — 代付實作，呼叫 `POST /payout`；含錯誤碼對照表
  - `WithdrawalCallback.php` — 代付回調處理
  - `Balance.php` — 餘額查詢，呼叫 `POST /merchant/balance`（Bearer Token 認證；config key 命名與其他模組不同）
- Providers 配置：`app/Console/Commands/CashFlow/Data/providers/SurePay.php`
- 相關工單：GitLab `target/tickets`
  - #3623 — SUREPAY 新 API 對接（v2.22 文件）
  - #3517 — VWorld MYR 代收配置
  - #3515 — Yes2Win IDR 代收代付配置
  - #3514 — TPower IDR 代收代付配置
  - #3438 — MYR 代收代付接入（含 v2.22 PDF 附件）
  - #3437 — IDR 代收代付接入
  - #2838 — Y2W THB 代收代付配置
  - #2837 — Y2W IDR 代收代付配置
  - #2711 — Yes2Win 首次對接（MYR）

---

*文件版本：2.0 / 整理日期：2026-06-01*
*資料來源：官方 PDF v2.20（工單 #3438）、GitLab 工單 #3623、#3517、#3515、#3514、#3438、#3437、#2838、#2837、#2711、Codebase 實作*
