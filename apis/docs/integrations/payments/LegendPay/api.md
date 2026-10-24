# LegendPay 廠商規格

> 官方 API 文檔：https://example.invalid/LegendPay91（存取密碼見對應 GitLab 工單）
> 內部實作路徑：`app/Services/Payment/Providers/LegendPay/`

LegendPay 是面向印度市場的第三方支付平台，支援 INR（印度盧比）代收（Deposit）與代付（Withdrawal）。所有請求使用 **FORM-DATA（application/x-www-form-urlencoded）** 格式提交，簽名與 Token 以 **HTTP Header** 傳遞，響應為 JSON。代收成功後以 URL 跳轉方式導向付款頁面。

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [代收 API](#3-代收-api)
4. [代付 API](#4-代付-api)
5. [回調處理](#5-回調處理)
6. [簽名流程](#6-簽名流程)
7. [錯誤碼對照](#7-錯誤碼對照)
8. [線上文檔存在但未接入的端點](#8-線上文檔存在但未接入的端點)
9. [參考資料](#9-參考資料)

---

## 1. 環境資訊

| 項目 | 值 |
|---|---|
| 商戶後台 | `https://example.invalid` |
| API Base URL | 由 `example.invalid.API_URL` 動態配置 |
| 代收端點 | `POST {API_URL}/LegendPay/payin` |
| 代付端點 | `POST {API_URL}/LegendPay/payout` |
| 請求格式 | `application/x-www-form-urlencoded` |
| 響應格式 | JSON |
| 認證方式 | HTTP Header（`l-token` + `l-sign`） |
| IP 白名單 | 必須先在商戶後台錄入我方出口 IP，否則無法調用接口（三方側准入條件，非代碼邏輯） |

> `API_URL` 末尾斜線會自動去除後拼接路徑（`rtrim($url, '/')`）。

---

## 2. 支援的支付方式與幣別

### INR（印度盧比）

| 支付類型 | 說明 |
|---|---|
| 網銀 / UPI | 印度統一支付接口（UPI），收銀台選擇 |

**代收限額**：200 – 20,000 INR（原始 30,000，已於 2025-11-17 下調）

**代付限額**：100 – 50,000 INR

**匯率參考（工單記錄）**：
- 代收：1 INR ≈ 0.0466 MYR
- 代付：1 MYR ≈ 21.46 INR

> 實際限額與費率以商戶後台設定為準。

---

## 3. 代收 API

### 代收發起 — `POST /LegendPay/payin`

**請求頭（HTTP Headers）**

| Header | 必填 | 說明 |
|---|---|---|
| `Content-Type` | ✅ | `application/x-www-form-urlencoded` |
| `l-token` | ✅ | 商戶 API Token（`info.API_TOKEN`） |
| `l-sign` | ✅ | 請求簽名（見第 6 節） |

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `mode` | ✅ | string | 固定值 `"0"` |
| `random` | ✅ | string | 16 位隨機字串（`Str::random(16)`） |
| `orderAmount` | ✅ | decimal | 代收金額 |
| `orderId` | ✅ | string | 商戶訂單號（`order.order_no`） |
| `param` | ✅ | string | 商戶自定義回傳參數（傳入 `member.id`） |
| `callbackUrl` | ✅ | string | 代收回調 URL |
| `returnUrl` | ✅ | string | 支付完成後跳轉 URL |
| `ts` | ✅ | integer | 毫秒時間戳（`(int)(microtime(true) * 1000)`） |

**請求範例**

```
POST {API_URL}/LegendPay/payin
Content-Type: application/x-www-form-urlencoded
l-token: [REDACTED]
l-sign: {SIGNATURE}

mode=0&random=AbCdEfGhIjKlMnOp&orderAmount=500&orderId=ORD20260101001&param=12345&callbackUrl=https%3A%2F%example.invalid%2Fcallback&returnUrl=https%3A%2F%example.invalid%2Freturn&ts=1748745600000
```

**成功響應**

```json
{
    "code": 1000,
    "msg": "success",
    "data": {
        "payUrl": "https://example.invalid/checkout?token=[REDACTED]"
    }
}
```

**失敗響應**

```json
{
    "code": 1001,
    "msg": "Invalid parameter"
}
```

**成功判定**：`code === 1000`，且 `data.payUrl` 存在。`payUrl` 為收銀台 URL，直接導向使用者。

> `code !== 1000` 或 `data.payUrl` 缺失時拋出 `ApiException`。

---

## 4. 代付 API

### 代付發起 — `POST /LegendPay/payout`

**請求頭（HTTP Headers）**

| Header | 必填 | 說明 |
|---|---|---|
| `Content-Type` | ✅ | `application/x-www-form-urlencoded` |
| `l-token` | ✅ | 商戶 API Token（`info.API_TOKEN`） |
| `l-sign` | ✅ | 請求簽名（見第 6 節） |

**必要配置欄位**（`info` 中三個欄位若任一為空則直接返回失敗，不發送請求）：
- `API_URL`
- `API_TOKEN`
- `API_SECRET`

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `amount` | ✅ | string | 代付金額（`sprintf('%.2f', ...)` 保留兩位小數） |
| `account` | ✅ | string | 收款銀行帳號（`withdrawalOrder.account_no`） |
| `orderId` | ✅ | string | 商戶代付流水號（`Common::genTransactionNo('LEG')` 生成） |
| `callbackUrl` | ✅ | string | 代付回調 URL |
| `ts` | ✅ | integer | 毫秒時間戳（`(int)(microtime(true) * 1000)`） |
| `random` | ✅ | string | 隨機字串（`Str::random()`） |
| `param` | ✅ | string | 商戶自定義回傳參數（傳入 `member_id`） |
| `personName` | ✅ | string | 收款人姓名（`withdrawalOrder.account_name`） |
| `personPhone` | - | string | 收款人手機號（來自 `withdrawalOrder.memo.mobile`） |
| `ifsc` | - | string | 印度金融系統代碼（IFSC，來自 `withdrawalOrder.memo.ifsc`） |

> 代付訂單號前綴為 `LEG`（`protected string $orderPrefix = 'LEG'`）。

> `personPhone` 與 `ifsc` 從 `withdrawalOrder.memo` JSON 解析，INR 代付通常需要提供 `ifsc`。

**代付所需的 `example.invalid` 配置**（控制前端表單欄位）：

```json
{
    "country": {
        "type": "select",
        "display": "country",
        "placeholder": "country",
        "options": [{"text": "India", "value": 91}]
    },
    "mobile": {
        "type": "text",
        "display": "mobile",
        "regex": "XXX",
        "placeholder": "mobile"
    },
    "ifsc": {
        "type": "text",
        "display": "IFSC",
        "placeholder": "IFSC"
    }
}
```

**請求範例**

```
POST {API_URL}/LegendPay/payout
Content-Type: application/x-www-form-urlencoded
l-token: [REDACTED]
l-sign: {SIGNATURE}

amount=1000.00&account=123456789012&orderId=LEG20260101ABCDEF&callbackUrl=https%3A%2F%example.invalid%2Fcallback&ts=1748745600000&random=randomstring&param=12345&personName=John+Doe&personPhone=9876543210&ifsc=SBIN0000001
```

**成功響應**

```json
{
    "code": 1000,
    "msg": "success",
    "data": {
        "orderId": "LP_INTERNAL_ORDER_NO"
    }
}
```

**失敗響應**

```json
{
    "code": 1002,
    "msg": "Balance insufficient"
}
```

**成功判定**：`code === 1000`。成功時 `data.orderId`（三方訂單號）存入 `withdrawal_order.third_party_order_no`。

> 代付為非同步處理，最終結果透過回調通知。

---

## 5. 回調處理

### 5.1 代收回調

LegendPay 在付款完成後以 **HTTP POST FORM-DATA** 格式推送回調到 `callbackUrl`。

**必要欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `amount` | ✅ | string | 實際入款金額 |
| `commission` | ✅ | string | 手續費 |
| `orderAmount` | ✅ | string | 訂單原始金額 |
| `orderId` | ✅ | string | 商戶訂單號（用於查詢訂單） |
| `param` | ✅ | string | 商戶自定義回傳參數（`member.id`） |
| `sign` | ✅ | string | 回調簽名（見第 6 節） |
| `status` | ✅ | string | 訂單狀態碼（見下方三態說明） |

**代收回調狀態碼（三態）**

| status | 說明 | 系統處理 |
|---|---|---|
| `"0"` | 待支付（未完成） | 拋出 `ApiException`（未知狀態） |
| `"1"` | 支付成功（終態） | `updateDepositOrderSuccess(amount=input.amount)` |
| `"2"` | 支付失敗（終態） | `updateDepositOrderFailed()` |

> `status` 為 `"0"` 或其他未知值時，系統拋出 `ApiException('Unknown order status: ...')`——即代收為**三態設計**，不接受待支付回調。

**回調範例**

```json
{
    "amount": "500.00",
    "commission": "10.00",
    "orderAmount": "500.00",
    "orderId": "ORD20260101001",
    "param": "12345",
    "sign": "ABCD1234ABCD1234...",
    "status": "1"
}
```

**回調響應**（純文字）：

| 回傳值 | 說明 |
|---|---|
| `success` | 回調處理成功，LegendPay 不再重送 |
| `fail` | 回調處理失敗 |

---

### 5.2 代付回調

LegendPay 在代付完成後以 **HTTP POST FORM-DATA** 格式推送回調到 `callbackUrl`。

**必要欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `amount` | ✅ | string | 代付金額 |
| `commission` | ✅ | string | 手續費 |
| `message` | ✅ | string | 狀態描述訊息 |
| `orderId` | ✅ | string | 商戶代付流水號（用於查詢 payout_record） |
| `param` | - | string | 商戶自定義回傳參數（nullable） |
| `sign` | ✅ | string | 回調簽名（見第 6 節） |
| `utr` | ✅ | string | UTR（Unique Transaction Reference，印度金融交易追蹤碼） |
| `status` | ✅ | string | 代付狀態碼（見下方說明） |

**代付回調狀態碼**

| status | 說明 | 系統處理 |
|---|---|---|
| `"1"` | 代付成功（終態） | `updateWithdrawalOrderStatus(isSuccess=true)` + 驗證金額 |
| 其他值 | 代付失敗（終態） | `updateWithdrawalOrderStatus(isSuccess=false)` |

> 只有 `status === "1"` 時才驗證回調金額是否與訂單金額匹配（`validateCallbackAmount`）。

**回調範例**

```json
{
    "amount": "1000.00",
    "commission": "15.00",
    "message": "Payment Success",
    "orderId": "LEG20260101ABCDEF",
    "param": "12345",
    "sign": "ABCD1234ABCD1234...",
    "utr": "UTR123456789012",
    "status": "1"
}
```

**回調響應**（純文字）：

| 回傳值 | 說明 |
|---|---|
| `success` | 回調處理成功 |
| `fail` | 回調處理失敗 |

**代付冪等性**：系統透過 `findAndLockPayoutRecord(orderId)` 加鎖查詢 payout record，若 record 已處理（`isPayoutRecordProcessed`）則直接回傳成功，不重複更新訂單。

---

## 6. 簽名流程

LegendPay 使用 **MD5（大寫）** 作為簽名算法，適用於代收/代付**發起請求**與**回調驗簽**，流程完全一致。

### 簽名生成步驟

1. 過濾參數：移除值為 `null` 或空字串 `""` 的欄位（保留 `0` 與 `"0"`）
2. 排除 `sign` 欄位本身（若存在）
3. 對剩餘參數按 **key 字典序升冪排序**（`ksort`）
4. 使用 `http_build_query` 拼接，再 `urldecode` 還原（消除 `%xx` 編碼，且把 `+` 還原為空格）
5. 在末尾拼接 `&key={API_SECRET}`
6. 計算 `md5(...)` 並轉為**大寫（`strtoupper`）**

> ⚠️ **空格坑（2026-08-09 生產實證）**：三方以**解碼後的原始值**簽名（含空格）。`http_build_query` 會把空格編成 `+`，而 `rawurldecode` **不會**把 `+` 還原成空格——歷史上曾誤用 `rawurldecode`（`1818b31a1`，2025-11-24），凡值含空格（代付 `personName`、回調 `message="IFSC err"`）簽名必錯：發起被三方回 `1101 invalid sign`、合法回調被我方拒收。實證：TPower 代付回調 `message="IFSC err"` 的三方 `sign` 與空格形式 MD5 完全一致（`+` 形式不符）。必須使用 `urldecode`。

### 簽名公式

```
sign = strtoupper(md5(urldecode(http_build_query(ksort(filtered_params))) + "&key=" + API_SECRET))
```

### PHP 實作參考

```php
protected function generateSign(array $params, array $info): string
{
    // 過濾 null 與空字串（保留 0 與 '0'）
    $filtered = array_filter($params, function ($value) {
        return $value !== null && $value !== '';
    });

    // 排除 sign 欄位本身
    unset($filtered['sign']);

    // 字典序排序
    ksort($filtered);

    // 拼接簽名字串（urldecode：+ 還原為空格，含空格值才能簽對）
    $sign = urldecode(http_build_query($filtered));
    $sign = $sign . '&key=' . $info['API_SECRET'];

    return strtoupper(md5($sign));
}
```

### 簽名爭議的離線裁決法

要確認「三方到底用哪種編碼簽名」時，不必發請求、也不必把 secret 拿出資料庫：

1. 找一筆三方回調中**值含空格**的樣本（例如 `message="IFSC err"`），連同三方附的 `sign` 一起取出。
2. 對每種候選字串（空格形式／`+` 形式／完全編碼形式）各算一次，直接在 SQL 內取 secret：

   ```sql
   SELECT UPPER(MD5(CONCAT('<候選字串>', '&key=',
          JSON_UNQUOTE(JSON_EXTRACT(info, '$.API_SECRET')))))
   FROM agent_cash_flows WHERE id = <acf>;
   ```

3. 哪個結果等於三方的 `sign`，哪個就是三方的慣例。secret 全程不離開資料庫。

代付的完整嘗試紀錄（含請求、回應、回調 body）都在 `withdrawal_payout_records`，保留期比 log 長，適合翻歷史樣本。

🔴 **兩個會誤導方向的坑**：

- **手測時重放寫死的舊時間戳**，三方回 `Signature failed` 可能是**時效過期**而不是編碼錯——別據此改編碼方向，歷史上就是這樣誤判過一次。
- **驗收樣本的值不含空格時，`+` 與空格兩種形式的簽名完全相同**，蓋不出這類缺陷 ⇒ **測資必須含帶空格的值**。

### 回調驗簽

收到回調後，使用相同算法計算期望簽名，再與回調的 `sign` 欄位比對：

```php
protected function checkSign(array $params, array $info): bool
{
    $sign = $params['sign'] ?? '';
    $expected = $this->generateSign($params, $info);

    return hash_equals($expected, $sign);
}
```

> 驗簽失敗時拋出 `ApiException('Signature verification failed')`。

### 簽名範例（代收請求）

```
參數（過濾空值後，ksort 排序）：
  callbackUrl = https://example.invalid/callback
  mode        = 0
  orderAmount = 500
  orderId     = ORD20260101001
  param       = 12345
  random      = AbCdEfGhIjKlMnOp
  returnUrl   = https://example.invalid/return
  ts          = 1748745600000

拼接：callbackUrl=https://example.invalid/callback&mode=0&orderAmount=500&orderId=ORD20260101001&param=12345&random=AbCdEfGhIjKlMnOp&returnUrl=https://example.invalid/return&ts=1748745600000&key={API_SECRET}

sign = strtoupper(md5(拼接字串))
```

---

## 7. 錯誤碼對照

### 7.1 代收 / 代付發起響應碼

| code | msg | 說明 |
|---|---|---|
| `1000` | success | 請求成功 |
| `1101` | Signature failed | 簽名失敗 |
| `1102` | Signature failed | 簽名過期（`ts` 具時效性） |
| `1201` | Verification failed, please confirm merchant information | 無效的商戶 token |
| `1302` | Merchant status abnormal | 商戶狀態異常 |
| `1402` | Packet exception | 數據包解析異常（檢查請求參數與數據內容） |
| `1170` | unknow | 系統未知錯誤（需聯繫三方客服） |

> 系統不逐碼分支：統一嚴格判定 `code === 1000` 為成功，其餘一律失敗——讀取 `msg`（預設 `"Unknown error"`），代收拋出 `ApiException`、代付回傳失敗 `PayoutResult`。

### 7.2 代收回調狀態碼

| status | 說明 |
|---|---|
| `"0"` | 待支付 |
| `"1"` | 支付成功 |
| `"2"` | 支付失敗 |

### 7.3 代付回調狀態碼

| status | 說明 |
|---|---|
| `"1"` | 代付成功 |
| 其他 | 代付失敗 |

---

## 8. 線上文檔存在但未接入的端點

官方文檔目錄共 8 頁，本文件僅涵蓋已實作的代收／代付與回調。以下 5 個端點**線上文檔有、系統未實作**（`Driver.php` 僅組裝 Deposit / DepositCallback / Withdrawal / WithdrawalCallback）：

| 端點（線上文檔頁名） | 說明 | 未接入影響 |
|---|---|---|
| 代收查单 | 查詢代收訂單狀態 | 代收依賴回調與收銀台跳轉，影響低 |
| 代付查单 | 查詢代付訂單狀態 | ⚠️ 代付逾時或回調缺失時無主動查證管道，只能等回調或人工上商戶後台核對 |
| 余额查询 | 查詢商戶餘額 | 無法程式化監控商戶餘額 |
| UTR补单 | 以 UTR 補單 | 掉單需人工處理 |
| UTR查单 | 以 UTR 查詢 | 同上 |

> 是否補接屬功能決策；本節僅記錄缺口，避免誤以為官方無此能力。

---

## 9. 參考資料

| 資料來源 | 說明 |
|---|---|
| [官方 API 文檔](https://example.invalid/LegendPay91) | LegendPay 完整 API 規格（存取密碼見對應 GitLab 工單） |
| [商戶後台](https://example.invalid) | 登入查詢交易記錄（各站商戶帳密請查工單） |
| GitLab #2957 | [GFF][Yes2Win] LegendPay INR 代收代付接入工單 |
| GitLab #2958 | [GFF][TP] LegendPay INR 代收代付接入工單 |

### 內部實作路徑

```
app/Services/Payment/Providers/LegendPay/
├── Driver.php              # 組合代收/代付子類別，掛載到 LEGEND config key
├── Deposit.php             # 代收實作（POST /LegendPay/payin）
├── DepositCallback.php     # 代收回調處理（三態：0=拋出/1=成功/2=失敗）
├── Withdrawal.php          # 代付實作（POST /LegendPay/payout，前綴 LEG）
├── WithdrawalCallback.php  # 代付回調處理（含 UTR 欄位、金額驗證）
└── LegendPayTrait.php      # 共用：HTTP 請求（asForm）、generateSign、checkSign
```

---

*文件版本：1.1 / 整理日期：2026-06-01 / 更新：2026-08-09（對齊線上文檔「前言（必读）」頁：補錯誤碼明細、IP 白名單前置條件、未接入端點清單）*
*資料來源：官方 API 文檔（https://example.invalid/LegendPay91）、GitLab 工單 #2957、#2958、Codebase 實作*
