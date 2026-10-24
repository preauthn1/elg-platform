# FlashPay SGD — API 整合文件

> 原始規格版本：API Version 3.0
> 原始文件：`API Documentation.html`
> 幣別：SGD（新加坡元）
> 白標：VWorld

---

## 目錄

1. [環境資訊與憑證](#1-環境資訊與憑證)
2. [簽名規則](#2-簽名規則)
3. [全域參數](#3-全域參數)
4. [代收 — POST /v3/payin](#4-代收--post-v3payin)
5. [代收回調 — CALLBACK POST {tran_callback_url}](#5-代收回調--callback-post-tran_callback_url)
6. [代付 — POST /v3/payout](#6-代付--post-v3payout)
7. [代付回調 — CALLBACK POST {tran_callback_url}](#7-代付回調--callback-post-tran_callback_url)
8. [銀行列表 — POST /v3/banklist](#8-銀行列表--post-v3banklist)
9. [餘額查詢 — POST /v3/balance](#9-餘額查詢--post-v3balance)
10. [代收訂單查詢 — POST /v3/payinQuery](#10-代收訂單查詢--post-v3payinquery)
11. [代付訂單查詢 — POST /v3/payoutQuery](#11-代付訂單查詢--post-v3payoutquery)
12. [支付方式與交易限額](#12-支付方式與交易限額)

---

## 1. 環境資訊與憑證

| 項目 | 值 |
|---|---|
| API Base URL | `https://example.invalid/v3/` |
| API 文件 | `https://example.invalid/v3/doc` |
| 後台 | `https://example.invalid/` |
| 後台帳號 | `Demo_Vworld` |
| 後台密碼 | `Vworld1234` |
| Client ID | `8e363c65-6dec-11f1-8a6d-0200009ee8a2` |
| Secret Key | `5c378d3e9e1fcacf83e4ad94df944a538102905a116af5ae6ed6f1bdff13ca97` |
| Callback IP（白名單） | `0.0.0.0` |
| 請求格式 | `application/json` |
| 響應格式 | JSON |
| 簽名算法 | SHA256 |

> 以上為測試環境憑證，正式環境另行提供。

---

## 2. 簽名規則

所有非空參數（排除 `signature` / `tran_signature` 欄位本身），按 **ASCII 排序**拼接為 `key=value&...` 格式，末尾接上 `{SecretKey}`（無 `&`），取 **SHA256** hash。

### 範例

```
SecretKey: 09894367dbe822244d6297271c704d79b0fdfe4e120ea94e8a8c9cd36d0e6b1d

參數:
{
    "client_id": "93f3ab46-6745-11ee-9bb7-005056032122",
    "cont_code": "THB_PROMPTPAY",
    "tran_customer": "username",
    "tran_customer_bank_code": "SCB",
    "tran_customer_bank_holder_name": "Alex Tan Kah Liang",
    "tran_customer_bank_account": "10123456789",
    "tran_amount": 100,
    "tran_unique": "WX001"
}

Hash String:
client_id=93f3ab46-6745-11ee-9bb7-005056032122&cont_code=THB_PROMPTPAY&tran_amount=100&tran_customer=username&tran_customer_bank_account=10123456789&tran_customer_bank_code=SCB&tran_customer_bank_holder_name=Alex Tan Kah Liang&tran_unique=WX00109894367dbe822244d6297271c704d79b0fdfe4e120ea94e8a8c9cd36d0e6b1d

Hash Value: d85c15754f3bd7a22a394ecc9849461f2e7acc874dce942d3e31c5f0f916210c
```

---

## 3. 全域參數

| 參數 | 說明 |
|---|---|
| `status_code` | `0`: Success, `-1`: Error |
| `status_msg` | 詳細訊息 |

---

## 4. 代收 — POST /v3/payin

建立代收（存款）交易。

### 請求參數

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `client_id` | String | Yes | 商戶 ID |
| `currency` | String | Yes | 幣別（SGD） |
| `cont_code` | String | Yes | 合約代碼（見支付方式） |
| `tran_customer` | String | Yes | 會員帳號 |
| `tran_customer_email` | String | No | 會員 email |
| `tran_customer_phone` | String | No | 會員手機號碼 |
| `tran_customer_bank_code` | String | No | 會員銀行代碼 |
| `tran_customer_bank_holder_name` | String | No | 會員銀行戶名 |
| `tran_customer_bank_account` | String | No | 會員銀行帳號 |
| `tran_amount` | Integer | Yes | 代收金額 |
| `tran_unique` | String | Yes | 商戶訂單號（不可重複） |
| `tran_remark` | String | No | 交易備註 |
| `tran_return_url_success` | String | No | 成功跳轉 URL |
| `tran_return_url_fail` | String | No | 失敗跳轉 URL |
| `tran_callback_url` | String | No | 回調 URL |
| `tran_signature` | String | Yes | 簽名 |

### 請求範例

```json
POST /v3/payin
Content-Type: application/json

{
    "client_id": "93f3ab46-6745-11ee-9bb7-005056032122",
    "currency": "THB",
    "cont_code": "THB_PROMPTPAY",
    "tran_customer": "username",
    "tran_customer_email": "user@example.invalid",
    "tran_customer_phone": "0812389879",
    "tran_customer_bank_code": "SCB",
    "tran_customer_bank_holder_name": "Alex Tan Kah Liang",
    "tran_customer_bank_account": "10123456789",
    "tran_amount": 100,
    "tran_unique": "WX001",
    "tran_signature": "d85c15754f3bd7a22a394ecc9849461f2e7acc874dce942d3e31c5f0f916210c"
}
```

### 回應範例

```json
{
    "status_code": 0,
    "status_msg": "NO_ERROR",
    "tran_token": "7c711a99-8b42-11ee-b53e-020000557396",
    "tran_customer": "username",
    "tran_amount": 100,
    "tran_unique": "WX001",
    "tran_remark": "for WX001",
    "tran_fee": 1.11,
    "redirect_to": "https://api.{domain}.com/v3/?token=[REDACTED]",
    "qrcode": "00020101021229377816A000000677..." ,
    "bank_name": "VIB",
    "bank_account_number": "029111999",
    "bank_account_name": "KENNY",
    "bank_reference": "QQQQ945QP"
}
```

> `qrcode`、`bank_name`、`bank_account_number`、`bank_account_name`、`bank_reference` 為 **By request only** 欄位。

---

## 5. 代收回調 — CALLBACK POST {tran_callback_url}

金流商主動通知代收結果。

### 回調參數

| 參數 | 類型 | 說明 |
|---|---|---|
| `status_code` | Integer | 全域狀態碼 |
| `status_msg` | String | 詳細訊息 |
| `tran_token` | String | 金流商交易 token |
| `tran_reference` | String | 金流商交易參考號 |
| `tran_flag` | String | 金流商交易標記 |
| `tran_customer` | String | 會員帳號 |
| `tran_amount` | Integer | 代收金額 |
| `tran_unique` | String | 商戶訂單號 |
| `tran_remark` | String | 交易備註 |
| `tran_fee` | Integer | 手續費 |
| `tran_status` | String | `"initiated"` / `"expired"` / `"completed"` |
| `tran_createtime` | Datetime | 建立時間（`2023-12-31 23:59:59`） |
| `tran_lastupdated` | Datetime | 最後更新時間 |
| `tran_signature` | String | 簽名 |

### 回調範例

```json
POST {tran_callback_url}
Content-Type: application/json

{
    "status_code": 0,
    "status_msg": "NO_ERROR",
    "tran_token": "7c711a99-8b42-11ee-b53e-020000557396",
    "tran_reference": "XZXCFQEWE1234245",
    "tran_flag": "20251129112300",
    "tran_customer": "username",
    "tran_amount": 100,
    "tran_unique": "WX001",
    "tran_remark": "for WX001",
    "tran_fee": 1.11,
    "tran_status": "completed",
    "tran_createtime": "2023-12-31 23:59:59",
    "tran_lastupdated": "2023-12-31 23:59:59",
    "tran_signature": "e27f6fee0082a1c8140c061f19820cf13e8f068c66a1ad49d6bda93733218c68"
}
```

### 預期回應

```
OK
```

---

## 6. 代付 — POST /v3/payout

建立代付（提款）交易。

### 請求參數

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `client_id` | String | Yes | 商戶 ID |
| `currency` | String | Yes | 幣別（SGD） |
| `tran_customer` | String | Yes | 會員帳號 |
| `tran_customer_email` | String | No | 會員 email |
| `tran_customer_phone` | String | No | 會員手機號碼 |
| `bank_code` | String | Yes | 會員銀行代碼 |
| `bank_account_name` | String | Yes | 會員銀行戶名 |
| `bank_account_number` | String | Yes | 會員銀行帳號 |
| `tran_amount` | Integer | Yes | 代付金額 |
| `tran_unique` | String | Yes | 商戶訂單號（不可重複） |
| `tran_remark` | String | No | 交易備註 |
| `tran_callback_url` | String | No | 回調 URL |
| `tran_signature` | String | Yes | 簽名 |

### 請求範例

```json
POST /v3/payout
Content-Type: application/json

{
    "client_id": "93f3ab46-6745-11ee-9bb7-005056032122",
    "currency": "THB",
    "tran_customer": "username",
    "tran_customer_email": "user@example.invalid",
    "tran_customer_phone": "0812389879",
    "bank_code": "SCB",
    "bank_account_name": "Alex",
    "bank_account_number": "1122334455667788",
    "tran_amount": 100,
    "tran_unique": "WX001",
    "tran_signature": "e27f6fee0082a1c8140c061f19820cf13e8f068c66a1ad49d6bda93733218c68"
}
```

### 回應範例

```json
{
    "status_code": 0,
    "status_msg": "NO_ERROR",
    "tran_token": "7c711a99-8b42-11ee-b53e-020000557396",
    "tran_customer": "username",
    "bank_code": "SCB",
    "bank_account_name": "Alex",
    "bank_account_number": "1122334455667788",
    "tran_amount": 100,
    "tran_unique": "WX001",
    "tran_remark": "for WX001",
    "tran_fee": 1.11
}
```

---

## 7. 代付回調 — CALLBACK POST {tran_callback_url}

金流商主動通知代付結果。

### 回調參數

| 參數 | 類型 | 說明 |
|---|---|---|
| `status_code` | Integer | 全域狀態碼 |
| `status_msg` | String | 詳細訊息 |
| `tran_token` | String | 金流商交易 token |
| `tran_customer` | String | 會員帳號 |
| `bank_code` | String | 會員銀行代碼 |
| `bank_account_name` | String | 會員銀行戶名 |
| `bank_account_number` | String | 會員銀行帳號 |
| `tran_amount` | Integer | 代付金額 |
| `tran_unique` | String | 商戶訂單號 |
| `tran_remark` | String | 交易備註 |
| `tran_fee` | Integer | 手續費 |
| `tran_status` | String | `"initiated"` / `"rejected"` / `"completed"` / `"refund"`（`refund` 未見於官方文檔，由 FlashPay 客服 Leah 口頭確認：銀行端撥款後又退款時會送出，可能晚於 `completed` 回調再次針對同一筆交易送達） |
| `tran_reject_reason` | String | 拒絕原因（僅 rejected 時出現） |
| `tran_createtime` | Datetime | 建立時間 |
| `tran_lastupdated` | Datetime | 最後更新時間 |
| `tran_signature` | String | 簽名 |

### 回調範例

```json
POST {tran_callback_url}
Content-Type: application/json

{
    "status_code": 0,
    "status_msg": "NO_ERROR",
    "tran_token": "7c711a99-8b42-11ee-b53e-020000557396",
    "tran_customer": "username",
    "bank_code": "SCB",
    "bank_account_name": "Alex",
    "bank_account_number": "1122334455667788",
    "tran_amount": 100,
    "tran_unique": "WX001",
    "tran_remark": "for WX001",
    "tran_fee": 1.11,
    "tran_status": "completed",
    "tran_createtime": "2023-12-31 23:59:59",
    "tran_lastupdated": "2023-12-31 23:59:59",
    "tran_signature": "bf93595fe5a418e7259585821dfb93dca21a8d5064fbdb14fd5a146517f731a7"
}
```

### 預期回應

```
OK
```

---

## 8. 銀行列表 — POST /v3/banklist

查詢支援的銀行代碼。

### 請求參數

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `client_id` | String | Yes | 商戶 ID |
| `currency` | String | Yes | 幣別 |
| `tran_signature` | String | Yes | 簽名 |

### 回應範例

```json
{
    "status_code": 0,
    "status_msg": "NO_ERROR",
    "bank_list": "[{\"code\":\"ABPB\",\"name\":\"Aditya Birla Payments Bank\"},{\"code\":\"AIRP\",\"name\":\"Airtel Payments Bank\"}]"
}
```

> 注意：`bank_list` 是 JSON 字串，需再次 `json_decode`。

---

## 9. 餘額查詢 — POST /v3/balance

查詢商戶帳戶餘額。

### 請求參數

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `client_id` | String | Yes | 商戶 ID |
| `currency` | String | Yes | 幣別 |
| `tran_signature` | String | Yes | 簽名 |

### 回應範例

```json
{
    "status_code": 0,
    "status_msg": "NO_ERROR",
    "balance": 100.00,
    "available_balance": 95.00
}
```

---

## 10. 代收訂單查詢 — POST /v3/payinQuery

查詢代收交易狀態。

### 請求參數

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `client_id` | String | Yes | 商戶 ID |
| `tran_token` | String | Yes | 金流商交易 token |
| `tran_signature` | String | Yes | 簽名 |

### 回應範例

```json
{
    "status_code": 0,
    "status_msg": "NO_ERROR",
    "tran_token": "7c711a99-8b42-11ee-b53e-020000557396",
    "tran_customer": "username",
    "tran_amount": 100,
    "tran_unique": "WX001",
    "tran_remark": "for WX001",
    "tran_fee": 1.11,
    "tran_status": "completed",
    "tran_createtime": "2023-12-31 23:59:59",
    "tran_lastupdated": "2023-12-31 23:59:59"
}
```

---

## 11. 代付訂單查詢 — POST /v3/payoutQuery

查詢代付交易狀態。

### 請求參數

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `client_id` | String | Yes | 商戶 ID |
| `tran_token` | String | Yes | 金流商交易 token |
| `tran_signature` | String | Yes | 簽名 |

### 回應範例

```json
{
    "status_code": 0,
    "status_msg": "NO_ERROR",
    "tran_token": "7c711a99-8b42-11ee-b53e-020000557396",
    "tran_customer": "username",
    "bank_account_name": "Alex",
    "bank_account_number": "1122334455667788",
    "tran_amount": 100,
    "tran_unique": "WX001",
    "tran_remark": "for WX001",
    "tran_fee": 1.11,
    "tran_status": "completed",
    "tran_createtime": "2023-12-31 23:59:59",
    "tran_lastupdated": "2023-12-31 23:59:59"
}
```

---

## 12. 支付方式與交易限額

### 支付方式（SGD）

| 支付方式 | cont_code | 必填欄位 |
|---|---|---|
| PayNow | `SGD_PAYNOW` | 基本欄位即可 |
| Bank Transfer | `SGD_BANKTRANSFER` | `tran_customer_bank_code`, `tran_customer_bank_holder_name`, `tran_customer_bank_account` |
| Online Banking | `SGD_ONLINEBANKING` | `tran_customer_bank_code`, `tran_customer_bank_holder_name`, `tran_customer_bank_account` |

### 交易限額

| 方向 | 最低 | 最高 |
|---|---|---|
| 代收（Deposit） | 30 SGD | 10,000 SGD |
| 代付（Withdrawal） | 100 SGD | 10,000 SGD |

### 代收回調狀態

| tran_status | 說明 |
|---|---|
| `initiated` | 已建立 |
| `expired` | 已過期 |
| `completed` | 已完成 |

### 代付回調狀態

| tran_status | 說明 |
|---|---|
| `initiated` | 已建立 |
| `rejected` | 已拒絕（附 `tran_reject_reason`） |
| `completed` | 已完成 |
| `refund` | 銀行端撥款後又退款（未見於官方文檔，由 FlashPay 客服 Leah 口頭確認）。可能晚於 `completed` 針對同一筆交易再次送達——我方目前對「`completed` 之後才收到 `refund`」的情境，因冪等機制（`isPayoutRecordProcessed()` 視 `THIRD_PARTY_SUCCESS` 為不可逆終態）會直接忽略此次回調，訂單狀態不會改變，需人工核對；若 `refund` 在訂單仍是 `PAYMENT_PROCESSING` 時收到，我方僅記錄 log、不轉移訂單狀態（見 `FlashPay/WithdrawalCallback.php`） |
