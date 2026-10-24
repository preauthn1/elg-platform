# Ap8Pay 廠商規格

> 官方 API 文檔：https://developer.autopay.dev/membership/v1/
> 商戶後台：https://example.invalid/
> 內部實作路徑：`app/Services/Payment/Providers/Ap8Pay/`

Ap8Pay（系統域名 `example.invalid`，API 域名 `developer.autopay.dev`）是面向馬來西亞市場的第三方支付平台，本平台**僅整合代收（Deposit）功能，不支援代付（Withdrawal）**。

請求採用 **JSON POST** 格式，API 金鑰以雙重方式傳遞：請求 Body 帶 `apiKey`，HTTP Header 帶 `api-key`（`HeaderApiKey`）。響應成功時 HTTP 回傳 `200`，失敗時拋出例外訊息（非固定 JSON 格式）。

> ⚠️ **安全性特別說明**：Ap8Pay 的代收回調**不提供簽名驗簽機制**，回調 body 的每個欄位都不可信。
> 正式環境入口未限制來源 IP（維運已確認），防線全在應用層：來源 IP 白名單、訂單歸屬檢查、
> 狀態與金額一律以查單 API 反查為準，三道都在 `DepositCallback`（工單 #4446）。

---

## 目錄

1. [廠商簡介](#1-廠商簡介)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [環境資訊](#3-環境資訊)
4. [代收 API](#4-代收-api)
5. [回調處理](#5-回調處理)
6. [簽名與安全性](#6-簽名與安全性)
7. [限額資訊](#7-限額資訊)
8. [參考資料](#8-參考資料)

---

## 1. 廠商簡介

| 項目 | 說明 |
|---|---|
| 廠商名稱 | Ap8Pay |
| config key | `AP8` |
| 支援功能 | 代收（Deposit）**僅限代收** |
| 主要市場 | 馬來西亞（MYR） |
| 請求格式 | `application/json`（JSON POST） |
| 響應格式 | JSON |
| 簽名機制 | **無**（雙 API Key 驗身，回調無驗簽，改以查單反查把關） |

---

## 2. 支援的支付方式與幣別

### MYR（馬來西亞令吉）

| 支付類型 | 說明 |
|---|---|
| 網銀轉帳 / QR 碼 | 馬來西亞本地銀行線上轉帳或掃碼支付（具體通道由三方後台控制） |

> 代收限額：最低 RM 10，最高無上限（依工單 #2989 記載）。

**不支援代付**：本平台不整合 Ap8Pay 代付功能。

---

## 3. 環境資訊

| 項目 | 值 |
|---|---|
| 商戶後台 | `https://example.invalid/` |
| API Base URL | 由商戶後台配置（存放於 `example.invalid['APIUrl']`） |
| 代收 Endpoint | `{APIUrl}/merchants/deposits` |
| 查單 Endpoint | `{APIUrl}/merchants/deposits/{taskCode}` |
| 請求格式 | `application/json` |
| 響應格式 | JSON |
| 認證方式 | Body 帶 `apiKey`，Header 帶 `api-key` |

> 實際生產環境的 API Base URL 以各白標商戶的 `example.invalid['APIUrl']` 設定為準。

---

## 4. 代收 API

### 代收請求 — `POST {APIUrl}/merchants/deposits`

**請求 Header**

| Header 名稱 | 必填 | 說明 |
|---|---|---|
| `api-key` | ✅ | 商戶 Header API Key（`example.invalid['HeaderApiKey']`） |
| `Content-Type` | ✅ | `application/json` |

**請求 Body 參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `apiKey` | ✅ | string | 商戶 API Key（`example.invalid['MerchantApiKey']`） |
| `uniqueCode` | ✅ | string | 商戶唯一訂單號（系統內不重複） |
| `senderName` | ✅ | string | 付款人識別名稱（使用會員 `username`） |
| `senderCode` | ✅ | string | 付款人唯一代碼（使用會員 `id`，轉字串） |
| `callbackUrl` | ✅ | string | 三方回調到本系統的 URL |
| `redirectUrl` | ✅ | string | 付款完成後導向會員的 URL |

> 🔴 **刻意不送 `amount`**：spec 有這個選填欄位，2026-10-05 在 Dev 以測試商戶實測帶了之後，
> 三方收銀台（`aaimy.ipay21.online`）的金額輸入框**仍然是空的、連預填都沒有**，玩家照樣輸入任意金額。
> 出站請求確實帶了 `"amount": "10.00"`、三方也回了 200（GCP log `Ap8Pay_Deposit`，
> 訂單 `202610059431218273`），所以不是沒送成功，是這個參數對 AP8 沒有約束力。
>
> 連帶結論：**本渠道不存在「下單金額」這個概念**，金額一律由玩家在三方頁面決定、回調帶回來。
> 要防大額只能在收到金額之後把關，不能在建單時把關。各白標的 `order_types.info` 也因此
> 不帶金額輸入欄位（VPlus33 #98、Dev #540 皆為 `{"data":[]}`）——問了玩家金額卻約束不了，
> 只會讓人誤以為對得起來。

**請求範例（佔位符版本）**

```json
{
    "apiKey": "{MerchantApiKey}",
    "uniqueCode": "AP8_20251010_001",
    "senderName": "member_username",
    "senderCode": "12345",
    "callbackUrl": "https://example.invalid/api/external/callback/ap8pay/deposit",
    "redirectUrl": "https://example.invalid/deposit/return"
}
```

**成功響應**

```json
{
    "data": {
        "url": "https://example.invalid/checkout?token=[REDACTED]",
        "code": "xdcXy6FML"
    }
}
```

- 成功判定：響應 JSON 中存在 `data.url`（HTTP 200）
- 取得 `data.url` 後，導向會員至該 URL 進行付款
- `data.code` 是**三方 taskCode**，查單 API 唯一的查詢鍵；建單當下寫入 `orders.third_party_order_no`，
  回調時用它向三方反查真實狀態與金額（§4.1）

**失敗響應**

- HTTP 非 200，或響應 JSON 缺少 `data.url`
- 三方失敗時回傳例外訊息（非固定 JSON 格式），系統以 `ApiException` 拋出

**Codebase 實際組裝**

```php
$params = [
    'apiKey'      => $this->info['MerchantApiKey'],
    'uniqueCode'  => $order->order_no,
    'senderName'  => $member->username,
    'senderCode'  => (string) $member->id,
    'callbackUrl' => $callbackUrl,
    'redirectUrl' => $returnUrl,
];

$headers = [
    'api-key' => $this->info['HeaderApiKey'],
];
```

### 4.1 查單 API — `GET {APIUrl}/merchants/deposits/{taskCode}`

**請求**

| 項目 | 說明 |
|---|---|
| Header `api-key` | 商戶 Header API Key（`example.invalid['HeaderApiKey']`） |
| Path `taskCode` | 建單響應的 `data.code`，落庫於 `orders.third_party_order_no` |

> **沒有以商戶訂單號（`uniqueCode`）查單的端點**，taskCode 遺失即無法查單。

**成功響應**

```json
{
    "data": {
        "uniqueCode": "AP8_20251010_001",
        "taskCode": "xdcXy6FML",
        "amount": "30.00",
        "status": "SUCCESS"
    }
}
```

| 欄位 | 必填 | 說明 |
|---|---|---|
| `uniqueCode` | ✅ | 三方記載的商戶訂單號；回調處理時必須與我方 `order_no` 相符才採信 |
| `taskCode` | ✅ | 三方訂單號 |
| `amount` | - | 金額（可能缺漏，缺漏時不可當 0） |
| `status` | ✅ | `PENDING` / `PROCESSING` / `SUCCESS` / `FAILED` |

**內部實作**：`app/Services/Payment/Providers/Ap8Pay/QueryOrder.php`，
Driver 實作 `SupportsDepositQuery`，故 `php artisan cash-flow:third-party-query <acf> --order=<order_no>` 可直接查。

#### 實測結果（2026-10-05，以測試商戶 `ac6***c9c8` 建一筆未付款的單驗證）

| 驗證項 | 結果 |
|---|---|
| 建單回的 `data.code` 是不是查單的 taskCode | **是**。建單拿到 `8Sox7ka3B`，立刻查單回的 `taskCode` 與 `uniqueCode` 都對得上 |
| 未付款的單查單回什麼 | `status` = `PENDING`，**沒有 `amount` 欄位** |
| 舊單查得到嗎 | **查不到**。線上 14 筆（最新 2026-05-08）與 Dev 8 筆（2026-01-05）全回 404 `Deposit not found`，與亂編的 taskCode 同一個回應。推定是三方的保留期限，非接口故障 |
| 憑證是否有效 | `GET /me` 回 200、`status` = `ACTIVE`；故意帶錯 api-key 回 400 `APIValidationError`，證明 key 有被驗 |

> 🔴 **`amount` 在 SUCCESS 時會不會回傳，目前仍未驗證**——未付款時確定不回，而官方 spec 的範例
> 卻把 `amount` 與 `PENDING` 並列，該欄位的 spec 不可信。`DepositCallback` 對「SUCCESS 但無金額」
> 是 fail-closed（不上分）**並發 TG 告警**，因為那會讓每一筆代收都卡住。要拔掉這個不確定性，
> 只能照母單需求 4 的測試步驟真的付一筆。

> 🔴 **Dev 不是沙箱**：`agent_cash_flows` #183（Dev）與線上 VPlus33 #41/#42 的 `APIUrl` 與
> `HeaderApiKey`（系統金鑰）**完全相同**，只有 `MerchantApiKey`（商戶金鑰）不同。
> 測試會打進 AP8 正式平台，只是記在測試商戶底下。

---

## 5. 回調處理

### 5.1 代收回調

Ap8Pay 於付款成功或失敗後，以 **POST JSON** 格式推送至商戶設定的 `callbackUrl`。

> ⚠️ **無驗簽機制**：Ap8Pay 代收回調**不含任何簽名欄位**，無法進行密碼學層面的防偽驗證。
> 系統改以三道防線把關（§5.3），回調 body 的 `status` / `amount` **只進 `remark` 存證，不參與任何判斷**。

**回調欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `code` | ✅ | string | 三方 taskCode；**舊單**（建單未落庫 taskCode）才拿它來查單 |
| `status` | ✅ | string | 訂單狀態（**僅供存證，不採信**） |
| `uniqueCode` | ✅ | string | 商戶訂單號（對應 `order_no`） |
| `amount` | ✅ | string | 金額（**僅供存證，不採信**） |
| `details` | - | string | 附加說明（選填） |

**成功判定**：查單 API 回報 `status === 'SUCCESS'`，與回調 body 的 `status` 無關。

**Ap8Pay 特殊邏輯**

1. **金額以查單結果為準**：下單時未傳 `amount` 給三方，玩家實付金額由三方認定，故不與原始下單金額比對；
   採用的是**查單 API 回報的金額**，不是回調帶來的金額。
2. **金額與點數無論成功或失敗都更新**：更新訂單狀態後，一律以查單金額和匯率（`agentCashFlow.rate`）
   重新計算並更新 `point`。
3. **失敗也回 `OK`**：三方回報終態（SUCCESS / FAILED）並完成處理後統一回傳純文字 `OK`；
   訂單已處理回 `Order already deposit finished`；被防線擋下或三方尚未終態回 `FAIL`（讓三方依重試排程再送）。

**回調響應**

| 情境 | 回傳內容 |
|---|---|
| 訂單處理成功（`SUCCESS`） | `OK` |
| 訂單處理失敗（非 `SUCCESS`） | `OK` |
| 訂單已處理完畢（冪等保護） | `Order already deposit finished` |

**回調範例**

```json
{
    "code": "AP8_THIRD_PARTY_ORDER_NO_001",
    "status": "SUCCESS",
    "uniqueCode": "AP8_20251010_001",
    "amount": "100.00",
    "details": ""
}
```

### 5.2 回調處理流程

```
接收 POST 回調
│
├─ 驗證欄位格式（code / status / uniqueCode / amount 必填）
│
├─ 防線 1：來源 IP 在白名單內？ 否 → 告警 + 回 "FAIL"
│
├─ 依 uniqueCode 取訂單（不鎖）
│   └─ 查無 → 回 "FAIL"
│
├─ 防線 2：訂單 ACF 的 cash_flow_id 等於回調網址上 ACF 的 cash_flow_id？ 否 → 告警 + 回 "FAIL"
│
├─ 防線 3：以 taskCode（優先取 order.third_party_order_no）向三方查單
│   ├─ 查單失敗 → 回 "FAIL"（三方會重送）
│   ├─ 查回的 uniqueCode 不是這筆單 → 告警 + 回 "FAIL"
│   ├─ 狀態非 SUCCESS / FAILED（尚未終態）→ 回 "FAIL"，訂單不動
│   └─ SUCCESS 但查單沒帶金額 → 回 "FAIL"，訂單不動
│
├─ 交易：鎖定訂單 → 冪等檢查（已處理 → "Order already deposit finished"）
│   ├─ 查單 SUCCESS → updateDepositOrderSuccess
│   └─ 查單 FAILED  → updateDepositOrderFailed
│
├─ 更新 order.amount = 查單金額，order.point = 查單金額 × rate
│
└─ 回傳 "OK"
```

### 5.3 回調三道防線（工單 #4446）

| # | 防線 | 做法 | 缺了會怎樣 |
|---|---|---|---|
| 1 | 來源 IP | `Common::getIp()` 比對 `cash_flows.whitelist`，**fail-closed**：白名單為空一律拒絕 | 任何人都能送偽造回調上分 |
| 2 | 訂單歸屬 | `order->agentCashFlow->cash_flow_id` 必須等於回調網址上 ACF 的 `cash_flow_id` | Ap8Pay 的回調網址可結掉其他金流商的訂單 |
| 3 | 反查為準 | 向查單 API 取狀態與金額，並驗 `uniqueCode` 相符 | 回調 body 可謊報狀態與金額 |

**防線 2 綁的是支付商不是商戶**：回調網址常由支付商固定寫在商戶後台，我方換費率、換商戶號
新增一筆 ACF 後後台網址沒跟著改，回調就會打到舊 ACF；比商戶的話這些真回調會全數被擋。
比對經 `orders.agent_cash_flow_id → agent_cash_flows.cash_flow_id` 取得；
⚠️ **禁止直接讀 `$order->cash_flow_id`**，該欄位代收建單路徑從沒寫過（見 `OrderService::createOrder`），
永遠是 NULL，比了等於全部放行。

**白名單存在哪**：`cash_flows.whitelist`（逗號分隔的 IP 字串，格式與語意比照 `vendors.whitelist`）。
D_AP8 那一列的初始值由 migration `2026_10_05_000001` 預填，新站台另由 `Data/cash_flows.php` 的
同步模板帶入；兩者都只在「欄位還是空的」時才寫，不會覆蓋營運後來的調整。

**廠商換 IP 怎麼改**：Olympus 後台「金流商 → 金流商管理」編輯該列的白名單
（`App\Filament\Admin\Resources\PaymentManagement\CashFlowResource`）。那頁只開放改白名單，
其餘欄位唯讀，每次修改都留操作紀錄。

放支付商層（`cash_flows`）而不是商戶層（`agent_cash_flows.info`）的理由：廠商出口 IP 對該支付商
底下所有商戶、所有代理群組都是同一份，存進 per 商戶的設定會複製 N 份各自漂移。

> ⚠️ 兩個刻意不做的事：
> - 不走 `Common::verifyIPWhitelist()`——它在非 production 一律放行、白名單為空也放行，與這裡要的
>   fail-closed 相反。QA 在測試環境送的假回調必須真的被擋，用那支就擋不到。
> - 白名單為空時**不可**改成放行。這是無驗簽金流商唯一的來源驗證，放行等於門戶洞開。

---

## 6. 簽名與安全性

### 6.1 代收請求認證

Ap8Pay 使用**雙層 API Key** 而非傳統簽名方式：

| 層次 | 傳遞方式 | 欄位名稱 | 說明 |
|---|---|---|---|
| 請求 Body | JSON 欄位 | `apiKey` | 商戶 API Key（`MerchantApiKey`） |
| HTTP Header | 請求標頭 | `api-key` | Header API Key（`HeaderApiKey`） |

兩組 Key 獨立設定，分別存放於 `example.invalid`。

### 6.2 回調安全性

> ⚠️ **Ap8Pay 回調無簽名驗證**

回調請求不含任何 HMAC / MD5 / SHA256 簽名欄位：

- **部署層**：正式環境入口**未**限制來源 IP（維運 2026-10 確認），不可假設 nginx / ingress 會擋
- **應用層**：以 §5.3 的三道防線取代驗簽；其中防線 3（查單反查）是唯一有「三方背書」的依據，
  其餘兩道只是降低被打到的機率
- 回調內容本身**永遠不可**直接用來判斷真實性

---

## 7. 限額資訊

以下資訊來自工單 #2989（VPlus33 接入紀錄）：

| 幣別 | 類型 | 最低 | 最高 |
|---|---|---|---|
| MYR | 代收 | RM 10 | 無上限 |

> 實際限額以各白標商戶的三方後台設定為準，不同商戶可能有差異。

---

## 8. 參考資料

| 資料來源 | 說明 |
|---|---|
| [官方 API 文檔](https://developer.autopay.dev/membership/v1/) | Ap8Pay 官方完整 API 規格（RapiDoc 前端） |
| [OpenAPI 原始規格](https://developer.autopay.dev/membership/v1/openapi) | 上面那頁實際載入的 YAML，免登入可直接取得 |
| [商戶後台](https://example.invalid/) | Ap8Pay 商戶管理後台 |
| GitLab #2989 | [GFF][VPLUS33] AP8PAY MYR 代收接入工單，含限額與帳號資訊 |
| GitLab #4446 | [GFF] AP8PAY 代收回調 - 補來源驗證與金額檢查修復（本文件 §4.1、§5.3 的出處） |

### 內部實作路徑

```
app/Services/Payment/Providers/Ap8Pay/
├── Driver.php            # 組合代收子類別（僅代收，無代付）
├── Deposit.php           # 代收實作：POST {APIUrl}/merchants/deposits，雙 API Key 認證，落庫 taskCode
├── QueryOrder.php        # 代收查單：GET {APIUrl}/merchants/deposits/{taskCode}
└── DepositCallback.php   # 代收回調處理：三道防線，狀態與金額以查單反查為準
```

---

*文件版本：1.1 / 整理日期：2026-10-05（工單 #4446：補查單 API 與回調三道防線）*
*資料來源：官方 API 文檔（URL 可存取但內容需登入）、GitLab 工單 #2989、Codebase 實作*
