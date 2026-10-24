# Sure 廠商規格

> 官方 API Base URL（沙盒）：`https://example.invalid`
> 內部實作路徑：`app/Services/Payment/Providers/Sure/`

Sure（config key `SURE`）是一個支援馬來西亞市場的第三方支付平台，**僅提供代收（Deposit）**，不支援代付（Withdrawal）。代收採用**自動提交 HTML Form（POST FORM）**方式，將玩家導向收銀台頁面完成付款；回調採用 HTTP POST。另外提供**餘額查詢（Balance）** API 以供監控商戶帳戶狀態。

> ⚠️ **注意**：Sure（`SURE`）與 SurePay（`SUREPAY`）是**不同的金流商**，請勿混淆。

## 目錄

1. [環境資訊](#1-環境資訊)
2. [支援的支付方式與幣別](#2-支援的支付方式與幣別)
3. [代收 API](#3-代收-api)
4. [餘額查詢 API](#4-餘額查詢-api)
5. [不支援代付](#5-不支援代付)
6. [回調處理](#6-回調處理)
7. [簽名流程](#7-簽名流程)
8. [銀行代碼參考](#8-銀行代碼參考)
9. [參考資料](#9-參考資料)

---

## 1. 環境資訊

| 項目 | 值 |
|---|---|
| API Base URL（沙盒） | `https://example.invalid` |
| API Base URL（生產） | 由 `example.invalid.APIUrl` 配置 |
| 代收發起方式 | HTML Auto-submit Form（POST FORM） |
| 回調方式 | HTTP POST（application/x-www-form-urlencoded） |
| 餘額查詢方式 | HTTP POST JSON，`Authorization: Bearer {ApiKey}` |
| 響應格式 | JSON（餘額查詢）/ 純文字（回調響應） |

### 商戶配置結構（`example.invalid`）

```json
{
    "APIUrl": "https://example.invalid",
    "ApiKey": "{API_KEY}",
    "MerchantID": "{MERCHANT_ID}",
    "ContractName": "{CONTRACT_NAME}",
    "ReturnUrl": "https://example.invalid/return",
    "CallBackApiKey": "{CALLBACK_API_KEY}",
    "Currency": "MYR"
}
```

> `ApiKey` 用於代收請求簽名與餘額查詢 Bearer Token；`CallBackApiKey` 專用於回調驗簽（兩者為不同金鑰）。

---

## 2. 支援的支付方式與幣別

### MYR（馬來西亞令吉）

| 支付類型 | 銀行代碼（srcbankcode） | 說明 |
|---|---|---|
| DuitNow QR | `DNDQR` | DuitNow QR 掃碼（回調中觀測到） |
| 其他通道 | 視收銀台選擇 | 代收發起時不指定 bank_code，由玩家在收銀台選擇 |

> 代收請求不需傳入 bank_code；通道選擇由收銀台頁面決定，三方回調中的 `srcbankcode` 欄位會揭露實際使用通道。

---

## 3. 代收 API

### 代收發起

發起代收採用 **HTML Auto-submit Form** 方式：系統生成一個帶有隱藏欄位的 HTML 表單，自動 POST 到三方收銀台 URL，將玩家導向付款頁面。

**提交端點**：`POST {APIUrl}`（即 `example.invalid.APIUrl`，不另加路徑）

**表單欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `merchant` | ✅ | string | 商戶 ID（`info.MerchantID`） |
| `contractname` | ✅ | string | 合約名稱（`info.ContractName`） |
| `refid` | ✅ | string | 商戶訂單號（`order.order_no`，系統內唯一） |
| `amount` | ✅ | decimal | 訂單金額 |
| `customer` | ✅ | string | 玩家帳號（`member.username`） |
| `currency` | ✅ | string | 幣別代碼（`info.Currency`，如 `MYR`） |
| `clientip` | ✅ | string | 玩家 IP |
| `failed_return_url` | ✅ | string | 付款失敗後跳轉 URL |
| `return_url` | ✅ | string | 付款成功後跳轉 URL（與 `failed_return_url` 相同） |
| `post_url` | ✅ | string | 回調通知 URL |
| `token` | ✅ | string | MD5 簽名（見簽名流程，最後加入） |

**表單提交範例**

```html
<form id="autoForm" method="post" action="https://example.invalid">
    <input type="hidden" name="merchant" value="{MERCHANT_ID}" />
    <input type="hidden" name="contractname" value="{CONTRACT_NAME}" />
    <input type="hidden" name="refid" value="ORD20260101001" />
    <input type="hidden" name="amount" value="100.00" />
    <input type="hidden" name="customer" value="player001" />
    <input type="hidden" name="currency" value="MYR" />
    <input type="hidden" name="clientip" value="0.0.0.0" />
    <input type="hidden" name="failed_return_url" value="https://example.invalid/return" />
    <input type="hidden" name="return_url" value="https://example.invalid/return" />
    <input type="hidden" name="post_url" value="https://example.invalid/callback/sure/deposit" />
    <input type="hidden" name="token" value="{MD5_TOKEN}" />
</form>
<script>document.getElementById("autoForm").submit();</script>
```

> 系統回傳 `DepositResponseType::HTML_FORM`，由前端渲染此 HTML 自動提交。`providerOrderNo` 此時為空，待回調後從 `trxno` 補入。

---

## 4. 餘額查詢 API

### Balance — `POST /merchant/balance`

查詢商戶帳戶的入款餘額（`inbal`）與出款餘額（`outbal`）。

**請求方式**：HTTP POST JSON，帶 Bearer Token 認證

**Headers**

| Header | 值 |
|---|---|
| `Content-Type` | `application/json` |
| `Authorization` | `Bearer {ApiKey}` |

**請求參數**

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `merchant` | ✅ | string | 商戶 ID（`info.MerchantID`） |

**請求範例**

```json
{
    "merchant": "{MERCHANT_ID}"
}
```

**響應範例（成功）**

```json
{
    "contractname": "{CONTRACT_NAME}",
    "domain": "{MERCHANT_DOMAIN}",
    "inbal": "737928394.09",
    "outbal": "100990.14",
    "status": 1,
    "statdesc": "success"
}
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `contractname` | string | 合約名稱 |
| `domain` | string | 商戶網域 |
| `inbal` | string | 入款餘額（代收可用餘額） |
| `outbal` | string | 出款餘額（代付可用餘額，當前未啟用） |
| `status` | integer | `1` = 成功 |
| `statdesc` | string | 狀態描述 |

**欄位對應（`BalanceResponseData`）**

| 欄位 | 對應 |
|---|---|
| `merchantStatus` | `result.status` |
| `depositBalance` | `result.inbal` |
| `withdrawBalance` | `result.outbal` |

---

## 5. 不支援代付

Sure 目前**僅支援代收（Deposit）**，`Driver.php` 中未實作 `Withdrawal` 與 `WithdrawalCallback` 子類別。若嘗試發起代付請求，系統將回傳不支援錯誤。

---

## 6. 回調處理

### 代收回調

Sure 在付款完成後，以 **HTTP POST** 方式推送回調到代收發起時指定的 `post_url`。

**推送欄位**

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `token` | ✅ | string | MD5 回調驗簽（見簽名流程） |
| `merchant` | ✅ | string | 商戶 ID |
| `amount` | ✅ | string | 實際支付金額 |
| `refid` | ✅ | string | 商戶訂單號（用於查詢訂單） |
| `trxno` | ✅ | string | 三方交易號（存為 `third_party_order_no`） |
| `status` | ✅ | string | 訂單狀態：`"1"` = 成功，其餘 = 失敗 |
| `status_message` | - | string | 狀態描述文字（如 `"success"`） |
| `currency` | - | string | 幣別 |
| `fee` | - | string | 手續費 |
| `customer` | - | string | 玩家帳號 |
| `srcbankcode` | - | string | 付款銀行代碼（如 `"DNDQR"`） |
| `clientip` | - | string | 玩家 IP |

**成功判定**：`status === "1"`（字串比對）

**回調響應**

| 情況 | 回傳純文字 |
|---|---|
| 處理成功 | `success` |
| 處理失敗 | `fail` |
| Token 驗簽失敗 | `fail`（並 rollback） |

**回調範例**

```json
{
    "status_message": "success",
    "token": "5e965948a66b715c6e761b2671a16513",
    "currency": "MYR",
    "amount": "52.00",
    "fee": "0.91",
    "merchant": "{MERCHANT_ID}",
    "customer": "player001",
    "refid": "202511271794992494",
    "status": "1",
    "srcbankcode": "DNDQR",
    "trxno": "89072921",
    "clientip": "0.0.0.0"
}
```

**冪等性防護**

系統透過 `findAndLockDepositOrder($input['refid'])` 加鎖查詢訂單，再呼叫 `checkDepositOrderStatus($order)` 確認是否已處理，防止重複回調。

---

## 7. 簽名流程

Sure 使用 **MD5** 作為簽名機制，代收發起與回調驗簽使用**不同的金鑰與拼接規則**。

### 7.1 代收發起簽名（`token`）

**拼接規則**：`merchant + amount + refid + customer + ApiKey + currency + clientip`

```php
private function generateToken(array $params): string
{
    $string = $params['merchant']
        . $params['amount']
        . $params['refid']
        . $params['customer']
        . $this->info['ApiKey']     // 代收簽名用 ApiKey
        . $params['currency']
        . $params['clientip'];

    return md5($string);
}
```

**範例**：

```
merchant:   {MERCHANT_ID}
amount:     100.00
refid:      ORD20260101001
customer:   player001
ApiKey:     [REDACTED]
currency:   MYR
clientip:   0.0.0.0

拼接：{MERCHANT_ID}100.00ORD20260101001player001{API_KEY}MYR203.0.113.1
token：md5(拼接字串)
```

### 7.2 回調驗簽（`token`）

**拼接規則**：`MerchantID + amount + status + CallBackApiKey + trxno`

```php
private function checkToken(array $params, array $info): bool
{
    $token_msg = $params['token'];
    $msg = $info['MerchantID']
        . $params['amount']
        . $params['status']
        . $info['CallBackApiKey']   // 回調驗簽用 CallBackApiKey（不同於 ApiKey）
        . $params['trxno'];
    $token = [REDACTED];

    return $token_msg === $token;
}
```

> ⚠️ 代收發起用 `ApiKey`，回調驗簽用 `CallBackApiKey`，兩者為**不同金鑰**，不可互換。

### 簽名算法對照總表

| 情境 | 欄位名稱 | 金鑰 | 拼接字串 | 備註 |
|---|---|---|---|---|
| 代收發起 | `token` | `ApiKey` | `merchant + amount + refid + customer + ApiKey + currency + clientip` | md5，無大小寫限制 |
| 回調驗簽 | `token` | `CallBackApiKey` | `MerchantID + amount + status + CallBackApiKey + trxno` | `strtolower(md5(...))` |

---

## 8. 銀行代碼參考

以下為目前代碼中觀測到的 `srcbankcode` 值（由回調欄位揭露，代收發起時不需傳入）：

| srcbankcode | 支付通道 |
|---|---|
| `DNDQR` | DuitNow QR 掃碼 |

> 完整銀行代碼清單以三方後台實際開通通道為準。代收發起不傳 bank_code，通道由玩家在收銀台自行選擇。

---

## 9. 參考資料

| 資料來源 | 說明 |
|---|---|
| 官方 API Base URL（沙盒） | `https://example.invalid`（首頁可訪問，文件未公開） |
| GitLab 工單 | 未找到 Sure（非 SurePay）專屬接入工單 |
| 內部實作路徑 | `app/Services/Payment/Providers/Sure/` |

### 內部實作路徑

```
app/Services/Payment/Providers/Sure/
├── Driver.php           # 組合代收 + Balance，掛載到 SURE config key（無代付）
├── Deposit.php          # 代收實作：生成 HTML Auto-submit Form，提交至 APIUrl
├── DepositCallback.php  # 代收回調處理：驗簽（CallBackApiKey）、狀態判斷（status==="1"）、冪等性防護
└── Balance.php          # 餘額查詢：POST /merchant/balance（Bearer Token 認證）
```

---

*文件版本：1.0 / 整理日期：2026-06-01*
*資料來源：Codebase 實作（`app/Services/Payment/Providers/Sure/`）、config/payment.php*
