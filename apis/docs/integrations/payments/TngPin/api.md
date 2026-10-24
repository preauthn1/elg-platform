# TngPin 廠商規格

> 官方 API 文檔：https://example.invalid/docs/api-documentation （公開可達，無需登入；備用：https://example.invalid/view/639514/2s9YsNeqiJ）
> 內部實作路徑：`app/Services/Payment/Providers/TngPin/`

TngPin 是面向馬來西亞市場的 **Telco PIN（電信儲值卡 PIN 碼）** 代收平台，**僅支援代收，不支援代付**。玩家在充值時填入電信商預付儲值卡 PIN 碼，系統將 PIN 送至三方平台進行扣款，三方扣款成功後透過 callback 通知系統上分。

> **⚠️ 安全提示**：TngPin 繞過通用框架的金額保護機制，代收成功後直接從三方響應的 `amount` 欄位取值計算 `point` 與 `tax`，不使用訂單原始金額（詳見安全性說明章節）。

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援支付方式（電信商）](#2-支援支付方式電信商)
3. [代收 API](#3-代收-api)
4. [交易查詢 API](#4-交易查詢-api)
5. [回調處理](#5-回調處理)
6. [簽名流程](#6-簽名流程)
7. [PIN 碼特殊處理機制](#7-pin-碼特殊處理機制)
8. [安全性說明](#8-安全性說明)
9. [費率參考](#9-費率參考)
10. [參考資料](#10-參考資料)

---

## 1. 環境資訊

| 項目 | 值 |
|---|---|
| 商戶後台 | `https://example.invalid/` |
| 官方 API 文檔 | `https://example.invalid/docs/api-documentation`（公開可達） |
| API Base URL | 配置項 `APIUrl`（由後台商戶配置決定） |
| Production URL（官方） | `https://example.invalid` |
| Test / Sandbox URL（官方） | `https://example.invalid` |
| 代收 Endpoint | `POST {APIUrl}/submitPIN` |
| 交易查詢 Endpoint | `POST {APIUrl}/checkTransaction`（官方提供；目前 codebase 未實作，見第 4 節） |
| 請求格式 | JSON（`application/json`，**POST**） |
| 響應格式 | JSON |
| 支援功能 | **僅代收**，不支援代付 |

> **Sandbox 規則（官方）**：測試環境下 `payment_key` 必須以 `TEST-` 前綴開頭（例 `ABCDE123` → `TEST-ABCDE123`），且須打到 `example.invalid`；Production 會拒絕帶 `TEST-` 前綴的 key。測試交易為模擬（不會打真實電信商），成功與否為隨機結果，成功時 `amount` 亦為隨機模擬值。

> **後台設定（官方，影響 API 行為）**：
> - **Unique Merchant Reference**：開啟後會強制檢查 `merchant_ref`，空值或重複值將被拒絕（防重複送單）。
> - **API Concurrent Limit**：帳號允許的同時 pending 交易上限，超過回 `You have too many pending transaction`。
> - **Callback Delay**：每筆 callback 送出前的延遲（0–5 秒），用於節流。

---

## 2. 支援支付方式（電信商）

TngPin 支援馬來西亞主要電信商的預付儲值卡 PIN 碼代收。訂單建立時，前端會依玩家選擇的電信商決定 `order_type_key`（即 `telco` 參數值）。

> **⚠️ `telco` 值必須為小寫**：官方僅接受小寫值，否則回 `result: "error"`、`message: "Invalid Telco"`。本系統 `order_types.key` 已存為小寫（id 362–366，綁定 cash_flow `D_TNGPIN`），`Deposit.php` 直接以 `order.orderType.key` 原樣送出，與官方一致。

| 電信商 | `telco` 參數值 | PIN 長度（官方） | 說明 |
|---|---|---|---|
| Digi | `digi` | 15 / 16 碼 | Digi Telecommunications |
| Maxis | `maxis` | 14 / 16 碼 | Maxis Berhad |
| Celcom | `celcom` | 15 / 16 碼 | Celcom Axiata |
| U Mobile | `umobile` | 14 碼 | U Mobile |
| Touch 'n Go（TNG） | `tng` | 10 碼 | Touch 'n Go eWallet 點數 |

> `telco` 值來自訂單類型的 `key` 欄位（`order.orderType.key`）。PIN 長度不符會回 `PIN must be 14/16 digit`（依電信商而異）。

---

## 3. 代收 API

### 代收發起 — `POST {APIUrl}/submitPIN`

玩家提交 PIN 碼後，系統呼叫此接口向三方平台請求扣款。

**請求參數（JSON）**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `payment_key` | ✅ | string | 商戶 API Key（測試環境須帶 `TEST-` 前綴） |
| `payment_pass` | ✅ | string | 商戶 API Password |
| `telco` | ✅ | string | 電信商代碼（**小寫**，見第 2 節） |
| `pin` | ✅ | string | 玩家輸入的儲值卡 PIN 碼（純數字，長度依電信商而異） |
| `ip_address` | ✅ | string | 玩家 IP 位址（`order.ip`），須為合法 IP 格式 |
| `merchant_ref` | ✅ | string | 系統訂單號（`order.order_no`，每單唯一）。已對齊工單 #3865 改送訂單號（非商戶號），避免三方開啟 Unique Merchant Reference 時被當重複送單拒絕；亦用於簽名生成（見第 6 節） |
| `wait_result` | - | boolean | 選填。本系統已實作為 boolean `false`（對齊工單 #3865）：`true` = 同步等待結果（最長 30 秒，易超時）；`false` = 立即回傳 `status="3"`、背景處理完後送 callback。**必須為 boolean `false`，不可為字串 `"false"`** |

> **現行實作**：`Deposit.php` 已送 `wait_result: false`（非同步，靠 callback 回報最終狀態）與 `merchant_ref: order.order_no`，避免同步超時與重複送單拒絕。

**請求範例**

```json
{
    "payment_key": "{API_KEY}",
    "payment_pass": "{API_PASS}",
    "telco": "maxis",
    "pin": "{PIN_CODE}",
    "ip_address": "0.0.0.0",
    "merchant_ref": "ORD20260101001",
    "wait_result": false
}
```

**成功響應（完整欄位）**

```json
{
    "result": "success",
    "status": "1",
    "transaction_id": "4C00B61A6FDFCB6393F6",
    "telco": "maxis",
    "message": "Transaction Successful",
    "detail_message": "Reload Successful.",
    "raw_message": "pin reload success",
    "amount": "5.00",
    "pin": "{PIN_CODE}",
    "merchant_ref": "ORD20260101001",
    "signature": "cb5a2e14fe4dd91b158847981e35d791",
    "transaction_date": "2026-01-01 12:53:55",
    "completion_date": "2026-01-01 12:54:03"
}
```

**處理中響應（`wait_result: false` 非同步）**

```json
{
    "result": "success",
    "status": "3",
    "transaction_id": "4C00B61A6FDFCB6393F6",
    "message": "Processing",
    "detail_message": "PIN Processing",
    "amount": "0.00",
    "completion_date": null
}
```

**交易失敗響應**（注意：`result` 仍為 `success`，由 `status` 判定失敗）

```json
{
    "result": "success",
    "status": "2",
    "transaction_id": "4C00B61A6FDFCB6393F6",
    "message": "Transaction Failed",
    "detail_message": "Invalid PIN",
    "raw_message": "invalid pin reload.",
    "amount": "0.00"
}
```

**驗證 / 授權錯誤響應**（`result` 為 `error`，**無** `status` 欄位）

```json
{
    "result": "error",
    "message": "Invalid Telco"
}
```

**`result` 欄位判定**

| `result` | 說明 |
|---|---|
| `success` | API 受理成功，**依 `status` 判斷最終交易結果（含 `status="2"` 失敗）** |
| `error` | 驗證 / 授權失敗（如 `Invalid Telco`、`Invalid payment_key`），無 `status` 欄位，向玩家顯示 `message` |

**`status` 欄位（`result === "success"` 時有效）**

| `status` | 說明 | 系統行為（同步路徑，`Deposit::finalizeSyncResult`） |
|---|---|---|
| `"1"` | 扣款成功（觸發 callback） | **維持 `CREATED`** + 寫入 `transaction_id`（同步不入帳）；`point`/`tax` 由 callback `status=1` 計算入帳 |
| `"2"` | 扣款失敗（三方拒絕或平台保護檢查，觸發 callback） | **同步即標 `FAILED`** + 寫入 `transaction_id`（官方文檔證實 status=2 為終態）；遲到 callback 冪等擋下 |
| `"3"` | 處理中（官方標 No Callback Yet） | 維持 `CREATED`，等 callback 變 `1`/`2` 決定終態 |

> **特殊情況**：若 `status === "1"` 但響應中缺少 `transaction_id`，系統會保留訂單 `CREATED` 狀態等待 callback，不直接標成功，避免 `third_party_order_no` 為空導致後續 callback 無法命中。

**主要回應欄位說明**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `result` | string | `success` = API 受理；`error` = 驗證/授權錯誤 |
| `status` | string | `1` 成功 / `2` 失敗 / `3` 處理中（僅 `result=success` 時出現） |
| `transaction_id` | string | 三方交易流水號（20 碼 hex），用於 `/checkTransaction` 與訂單查找 |
| `telco` | string | 電信商代碼 |
| `message` | string | 高階狀態訊息 |
| `detail_message` | string | 面向使用者的詳細訊息 |
| `raw_message` | string | 電信商原始回應（供除錯，處理中可能為空） |
| `amount` | string | PIN 面額（before rate），失敗/處理中為 `"0.00"` |
| `signature` | string | MD5 簽名（見第 6 節） |
| `transaction_date` | string | 交易建立時間 `YYYY-MM-DD HH:mm:ss` |
| `completion_date` | string\|null | 完成時間；處理中為 `null` 或空字串 |

**常見錯誤訊息（官方）**

| 訊息 | 說明 |
|---|---|
| `Invalid payment_key` / `Invalid payment_pass` | 金鑰錯誤或格式不符 |
| `Your account is suspended` / `Your account API is disabled` | 帳號或 API 被停用 |
| `PIN must be 14/16 digit` | PIN 長度驗證失敗（依電信商） |
| `Invalid Telco` | `telco` 非小寫合法值 |
| `ip_address must be a valid IP` | IP 格式不合法 |
| `Service maintenance` | 電信商維護中，稍後再試 |
| `This PIN is currently processing` | 重複送單（僅 UMobile / TNG） |
| `You have too many pending transaction` | 達並發上限 |

---

## 4. 交易查詢 API

> **官方提供，目前 codebase 未實作**。`Driver.php` 僅組合代收與回調子類別，無主動輪詢查詢。本節為對齊官方文檔保留；若未來實作 #3865 的非同步模式，可搭配此接口主動查詢 `status="3"` 的交易。

### 交易查詢 — `POST {APIUrl}/checkTransaction`

依 `submitPIN` 回傳的 `transaction_id` 查詢交易狀態。

**請求參數（JSON）**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `payment_key` | ✅ | string | 同 `submitPIN`（含 sandbox key 格式規則） |
| `payment_pass` | ✅ | string | 同 `submitPIN` |
| `transaction_id` | ✅ | string | `submitPIN` 回傳的交易流水號 |

**響應**：欄位與 `submitPIN` 一致，`status` 同樣為 `1`/`2`/`3`。

> **raw pending（`status=0`）**：若交易仍在最原始 pending 狀態，此接口回 `result: "error"`、`message: "Transaction pending, please try again later."`（不帶 `status` 欄位）。官方建議：卡在 `status="3"` 超過 1 分鐘可重送或稍後再查；長時間未收 callback 應帶 `transaction_id` 聯繫客服。

---

## 5. 回調處理

三方在 PIN 扣款**完成（`status=1`）或失敗（`status=2`）時**，以 **POST JSON** 格式推送到商戶配置的 callback URL。官方文檔明示 **`status=3` 為 No Callback Yet**（callback 僅於狀態變為 1/2 時送），故正常流程不會收到 `status=3` callback；惟系統仍**防禦性保留**對 `status=3` callback 的處理（維持 `CREATED` 等下次 callback），以防三方節流／重送等非典型情況（見下方「狀態判定」）。

> **⚠️ Callback 來源 IP 白名單（兩層）**：
> 1. **網路層**：官方所有 callback 來自**單一固定 IP `0.0.0.0`**。新增白標或調整防火牆 / WAF 時務必放行此 IP，否則 callback 會被靜默丟棄。
> 2. **應用層（`guardCallbackIp`）**：`DepositCallback` 會檢查 `agent_cash_flows.info` 的 `CallbackAllowIps`（陣列或逗號分隔字串）；**未設定則略過**。因 TngPin 面額由回調決定、無法以訂單預期金額事前交叉驗證，故來源 IP 是簽章以外的關鍵防線，建議於 info 設定為官方固定 IP。

**回調欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `result` | ✅ | string | 固定值 `success`（交易類 callback；驗證錯誤不會送 callback） |
| `status` | ✅ | string | `"1"` = 成功；`"2"` = 失敗；`"3"` = 處理中（訂單保留 `CREATED`，等待後續 callback） |
| `message` | ✅ | string | 結果說明訊息 |
| `detail_message` | - | string | 詳細說明（如 `"Reload Successful."`） |
| `raw_message` | - | string | 電信商原始回應 |
| `amount` | ✅ | string | 實際扣款金額 / PIN 面額（用於計算 `point` 與 `tax`） |
| `transaction_id` | ✅ | string | 三方交易流水號（系統以此查找訂單） |
| `telco` | - | string | 電信商代碼 |
| `pin` | - | string | 原始 PIN 碼（用於 PIN fallback 查找訂單，見第 7 節） |
| `merchant_ref` | - | string | 原始商戶訂單號 |
| `signature` | ✅ | string | MD5 簽名（見第 6 節） |
| `transaction_date` | - | string | 交易發起時間（`YYYY-MM-DD HH:mm:ss`） |
| `completion_date` | - | string | 交易完成時間（`YYYY-MM-DD HH:mm:ss`） |

**狀態判定**：
- `status === "1"` → 成功，計算 `point`/`tax` 入帳、訂單轉 `SUCCESS`
- `status === "3"` → 處理中，**維持訂單 `CREATED`** 並記錄遮罩後 callback，回 `Processing`，等待後續最終狀態 callback（不可標 FAILED，否則最終成功 callback 會被冪等檢查擋下導致不上分）
- 其他（含 `"2"`）→ 失敗，訂單轉 `FAILED`

**訂單查找邏輯（Two-phase lookup）**：

1. **Phase 1（精準主鍵）**：以 `transaction_id` 比對 `third_party_order_no`（含已處理訂單，支援重發冪等）
2. **Phase 2（窄域 PIN fallback，僅 timeout 孤兒）**：若 Phase 1 未命中且 callback 帶 `pin`，反查符合**全部**條件者：同 `agent_cash_flow_id` + `status = CREATED` + **`third_party_order_no` 為空（`''`/`NULL`）** + **`extra_content` 內 `pin` 等於 callback `pin`**；**單一候選**才回填 `transaction_id` 並自動歸屬，多候選則拒絕、留人工。
   > 反查鍵已由舊版「`third_party_order_no=PIN` 占位」改為「`extra_content` PIN + 空 `third_party_order_no`」——`third_party_order_no` 語意收斂為「只存 transaction_id」，不再暫存 PIN。

**回調範例（成功）**

```json
{
    "result": "success",
    "status": "1",
    "message": "Transaction Successful",
    "detail_message": "Reload Successful.",
    "raw_message": "pin reload success",
    "amount": "5.00",
    "transaction_id": "4C00B61A6FDFCB6393F6",
    "telco": "maxis",
    "pin": "{PIN_CODE}",
    "merchant_ref": "ORD20260101001",
    "signature": "cb5a2e14fe4dd91b158847981e35d791",
    "transaction_date": "2026-01-01 12:53:55",
    "completion_date": "2026-01-01 12:54:03"
}
```

**回調響應**：系統回傳純文字或 JSON，成功時：

```json
{
    "status": "0",
    "message": "Success"
}
```

> 官方建議於 5 秒內回 200 OK，並以非同步方式處理；需具備重複 callback 的冪等處理（人工重送 / 恢復流程可能對同一交易送多次）。若處理失敗，系統拋出例外；三方端應重試直到收到成功響應為止。

---

## 6. 簽名流程

TngPin 使用 **MD5** 作為簽名機制，用於 callback 驗簽。

### 驗簽規則

**官方拼接方式**：`payment_key + transaction_id + merchant_ref`

```
signature = md5(payment_key + transaction_id + merchant_ref)
```

> MD5 結果為**小寫**；拼接順序為 `payment_key + transaction_id + merchant_ref`，不可有多餘空白。Sandbox 模式下若以 `TEST-` 前綴 key 送單，驗簽也須用同一把 `TEST-` key。

**PHP 實作參考（現行 codebase）**

```php
protected function checkSign(array $params, array $info): bool
{
    $sign = $params['signature'] ?? '';
    // 第三段取回傳的 merchant_ref（非寫死 info['Merchant']），三方回什麼簽什麼
    $expected = md5($info['ApiKey'] . $params['transaction_id'] . ($params['merchant_ref'] ?? ''));

    return hash_equals($expected, $sign);
}
```

| 欄位 | 來源 | 對應官方欄位 |
|---|---|---|
| `ApiKey` | 商戶配置 `info['ApiKey']` | `payment_key`（同值） |
| `transaction_id` | 回調 payload `params['transaction_id']` | `transaction_id` |
| `merchant_ref` | 回調 payload `params['merchant_ref']` | `merchant_ref`（三方原樣回傳送出的訂單號） |

> **✅ #3865 已實作**：`checkSign` 第三段取**回傳的 `merchant_ref`**（非寫死 `info['Merchant']`），且 `Deposit.php` 送出的 `merchant_ref` 已改為 `order.order_no`。因驗簽取回傳值、三方回什麼簽什麼，故同時相容在途舊單（不論舊單以訂單號或更早的商戶號簽章皆驗得過），毋須本地候選清單。**切勿改回寫死 `info['Merchant']`**——回歸測試 `TngPinTraitTest::test_check_sign_uses_returned_merchant_ref_not_static_merchant` 會擋下此退化。

> 代收請求本身（`/submitPIN`）不需要在 payload 中附帶簽名；`payment_key` 和 `payment_pass` 已作為身份驗證。

---

## 7. PIN 碼特殊處理機制

Telco PIN 屬於**不可逆扣款**：三方一旦收到 PIN 碼便立即消耗，失敗後禁止重試同一張 PIN。系統對此設計了以下保護機制：

### 7.1 in-flight 並發短鎖（取代舊版 PIN 占位）

> **變更歷史**：舊版於送 `/submitPIN` 前把 `order.third_party_order_no` 設為 PIN（永久占位），因孤兒訂單永不釋放，導致同 PIN 跨 telco 被永久封鎖（`Duplicate PIN reservation`）。現已移除占位，改用短鎖。

送出 `/submitPIN` 前，以 **`Cache::lock`** 取得短期鎖：

- **key**：`tngpin:inflight:{agent_cash_flow_id}:sha256(pin)`（PIN 雜湊化，不放原文進 cache key/log）
- **TTL**：`CacheTtl::DEFAULT`（300s，crash 安全網）
- **用途**：防止同 `agent_cash_flow_id` 下同一張 PIN 並發雙送到三方（Telco PIN 不可逆）
- **釋放時機**：定案（`result=error`/`status=2` FAILED、4xx 確定性拒絕、或有同步回應）即釋放；**timeout 模糊失敗不主動釋放**，留 TTL 擋住模糊期內同 PIN 重送雙扣
- 取鎖失敗 → 拋 `ApiException(HTTP_LOCKED)`（短暫、可重試，**非永久封鎖**）

> timeout 孤兒的 callback 對帳改由第 5 節窄域 Phase 2（`extra_content` PIN）負責，不再依賴 `third_party_order_no` 占位。
> 補充：三方對 **UMobile/TNG** 另有自身重複送單防線（`This PIN is currently processing`）；digi/maxis/celcom 無此 telco 端保護，故本地短鎖仍必要。

### 7.2 失敗分類處理

| 失敗類型 | HTTP 狀態碼 | 系統行為 |
|---|---|---|
| 確定性拒絕（4xx 非 408） | 400 | 訂單標 `FAILED` + **釋放短鎖**，玩家可換另一張 PIN 重試 |
| 模糊失敗（timeout / 5xx / ConnectionException） | 非 400 | 訂單保留 `CREATED`（`third_party_order_no` 維持空）+ **不釋放短鎖（留 TTL）** + BotNotify 告警 OP 人工確認；若三方稍後 callback，由窄域 Phase 2 以 `extra_content` PIN 自動對帳 |

> 此處的 HTTP 狀態碼分類為 codebase 對**傳輸層**錯誤的內部處理，與第 3 節官方以 `result`/`status` 表達的**業務結果**為不同層次。

### 7.3 PIN 遮罩

PIN 碼屬支付憑證，**禁止原文寫入 log、BotNotify 或 DB `order.remark`**。系統提供兩個工具：
- `maskPin()`：保留末 5 碼，用於 log / BotNotify 與對帳。
- `maskSensitive()`：寫入 `order.remark` 前套用，將 `payment_key` / `payment_pass` 全遮罩、`pin` 保留末 5 碼；`Deposit`（請求/響應）與 `DepositCallback`（callback payload）寫 remark 一律先經此遮罩。

```
1234567890123456 → ***********23456   (maskPin)
payment_key / payment_pass → ***       (maskSensitive)
```

---

## 8. 安全性說明

### ⚠️ 繞過共用框架金額保護

TngPin 的代收成功邏輯（`Deposit.php` 與 `DepositCallback.php`）**直接使用三方響應的 `amount` 欄位計算點數與手續費**，而非訂單原始申請金額：

```php
// 直接取三方回傳的 amount（不與訂單原始金額比較）；但仍須正規化後以 Common 數值運算，禁止原生浮點 *
$amount = Common::numAdd('0', (string) $result['amount'], 4);
$point  = Common::numMul($amount, $this->agentCashFlow->rate, 4);
```

這是 TngPin 業務特性導致：電信商儲值卡面額固定（如 RM 5、RM 10、RM 30 等），三方扣款成功後回傳的 `amount` 即為實際儲值面額，系統依此面額上分。此行為屬**已知設計決策**，開發人員修改此邏輯時需特別注意，勿誤解為 bug 而改回使用訂單原始金額。

> **⚠️ 金額精度**：「不與訂單原始金額比較」不代表可略過金額正規化。三方 `amount` 為字串（如 `"5.00"`），進入運算前必須 `Common::numAdd('0', (string) $amount, 4)` 正規化，且一律用 `Common::numMul/numSub` 取代原生浮點運算（CLAUDE.md 強制規範）。

> 官方註明 `amount` 為 PIN 面額（before rate）；sandbox 模式下成功交易的 `amount` 為隨機模擬值，測試環境勿據此校驗金額。

### PIN 為支付憑證

PIN 碼的安全等級等同金錢：

- Log、BotNotify、DB `order.remark` 均須遮罩（PIN 保留末 5 碼、API 憑證全遮罩）
- `order.third_party_order_no` **只存 `transaction_id`**（不再暫存 PIN 原文）；PIN 僅存於 `extra_content`，並作為 callback 窄域 Phase 2 的反查鍵
- in-flight 短鎖 key 以 `sha256(pin)` 雜湊，不含 PIN 原文

---

## 9. 費率參考

以下為工單記錄的典型費率（各商戶後台設定可能不同，以實際後台配置為準；官方 API 文檔不提供費率）：

| 電信商 | 費率（從充值金額扣除） |
|---|---|
| Digi | 26.5% |
| Maxis | 25.5% |
| Celcom | 26.0% |
| U Mobile | 26.0% |
| Touch 'n Go（TNG） | 7.5% |

> 費率直接從玩家充值金額裡扣除（非另收），前端需提示玩家實際到帳金額。

---

## 10. 參考資料

| 資料來源 | 說明 |
|---|---|
| [官方 API 文檔](https://example.invalid/docs/api-documentation) | 公開可達（含 submitPIN / checkTransaction / callback webhook 三段） |
| [Postman 文件](https://example.invalid/view/639514/2s9YsNeqiJ) | 工單 #2774 附帶的原始 API 說明連結 |
| GitLab #2774 | [GFF][TP] telco pin 代收首次接入工單（費率、Postman 文件連結） |
| GitLab #2907 | [GFF][yes2win] telco pin 配置工單（yes2win 費率資訊） |
| GitLab #2908 | [GFF][WTT] telco pin 配置工單（WTT 費率資訊） |
| GitLab #3215 | [GFF][APIs] 接入 TngPin MYR 代收（APIs 專案接入工單） |
| GitLab #3279 | [GFF] TNG PIN 測試商戶（測試環境配置） |
| GitLab #3865 | [GFF] TelcoPin 代收超時狀況優化（`merchant_ref` 改訂單號、`wait_result` 改 `false`） |

### 內部實作路徑

```
app/Services/Payment/Providers/TngPin/
├── Driver.php            # 僅組合代收子類別（不含代付、不含 checkTransaction）
├── Deposit.php           # 代收實作：in-flight 短鎖 → POST /submitPIN → finalizeSyncResult(error/status2→FAILED) → 處理響應
├── DepositCallback.php   # 代收回調：Phase1(txn)+窄域Phase2(extra_content PIN) → 簽名驗證 → 計算 point/tax → 更新訂單
└── TngPinTrait.php       # 共用：sendRequest（禁止 retry）、checkSign（MD5 驗簽）、maskPin（PIN 遮罩）
```

**Enum**：

```
app/Enums/TngPinResponseCode.php
├── STAY_ON_PAGE = 998    # 前端維持在原頁
└── RETURN_TO_PAYMENT = 999  # 前端返回支付頁
```

---

*文件版本：1.2 / 整理日期：2026-06-08*
*v1.2 變更：對齊「移除 PIN 占位」重構——§7.1 改 in-flight 短鎖、§7.2 失敗分類更新、§5 Phase 2 改 `extra_content` PIN 反查、§3 status=2 同步 FAILED + status=3 No Callback Yet、§8 `third_party_order_no` 只存 txn。*
*資料來源：官方 API 文檔（https://example.invalid/docs/api-documentation，2026-06-08 以 `curl -A "Mozilla/5.0"` 重新抓取逐欄比對一致）、Codebase 實作、DB order_types(362–366)/cash_flows(D_TNGPIN)、GitLab 工單 #2774、#2907、#2908、#3215、#3279、#3865*
