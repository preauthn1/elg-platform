# VtPay 廠商規格

> 我方實作心得、疑難排解、金額/狀態處理見同目錄 **`integration.md`（對接心得）**；通用接入規範見 target-apis plugin 的 `payment-integration` skill。

VtPay 支援多種支付方式的第三方支付平台，提供代收（Deposit）與代付（Withdrawal），所有 API 請求與回調均使用 AES-128-CBC 加密。

## 支援的支付方式

| 支付類型 | order_type | 適用地區 | 說明 |
|---|---|---|---|
| 網銀充值 | 100 | - | 銀行轉帳 |
| QR Code 掃碼 | QRPay | - | 通用 QR 碼支付 |
| Zalo Pay | QRZaloPay | 越南 | Zalo 錢包 |
| Momo Pay | QRMonopay | 越南 | Momo 錢包 |
| Viettelpay | QRViettelpay | 越南 | Viettelpay 錢包 |
| LBT 充值 | lbt | 新加坡 | LBT 支付 |
| FPX | FPX | 馬來西亞 | FPX 銀行轉帳 |
| Touch 'n Go | TNG | 馬來西亞 | TNG 電子錢包 |
| Cards | Cards | 馬來西亞 | 信用卡 / 簽帳卡 |

## API 接口

### 代收 — `POST /SH/sendPay`

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| sh_order_no | ✅ | string | 商戶訂單號 |
| order_amount | ✅ | decimal | 訂單金額 |
| order_type | ✅ | string | 支付類型 |
| bank_code | - | string | 銀行代碼（網銀 / FPX 必填） |
| notify_url | ✅ | string | 異步通知地址 |
| customer_name | - | string | 客戶名（FPX / TNG / Cards 必填） |
| customer_email | - | string | 客戶郵箱（FPX / TNG / Cards 必填） |
| customer_phone | - | string | 客戶電話（FPX / TNG / Cards 必填） |

響應：

```json
{
    "error_code": 200,
    "data": {
        "order_id": "D-0103201000001",
        "order_amount": 10.01,
        "request_amount": 10.01,
        "url": [
            "https://example.invalid?token=[REDACTED]",
            "https://example.invalid?token=[REDACTED]"
        ],
        "token": "1AYo99S4vymMeAWHsvWeWGH7XKblhL8tuDYlfhAN"
    }
}
```

### 代付 — `POST /SH/sendWithdrawal`

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| sh_order_no | ✅ | string | 商戶訂單號 |
| withdraw_amount | ✅ | decimal | 提現金額 |
| bank_code | ✅ | string | 銀行代碼 |
| bank_account | ✅ | string | 銀行帳號 |
| bank_account_name | ✅ | string | 帳戶名稱 |
| notify_url | ✅ | string | 異步通知地址 |

響應：

```json
{
    "error_code": 200,
    "data": {
        "order_id": "W-0103201000001",
        "withdraw_amount": 100.00
    }
}
```

### 回調

格式：`POST {notify_url}?data={encrypted_data}`

解密後：

```json
{
    "sh_order_no": "TEST123456",
    "order_id": "D-0103201000001",
    "order_status": 3,
    "order_amount": 100.00,
    "paid_amount": 100.00
}
```

- `order_status = 3`：成功
- `order_status = 5`：失敗

回調回應（需加密）：

```json
{
    "result": "success"
}
```

## AES 加解密流程

### 加密

1. 將請求參數轉為 JSON 字串
2. 生成隨機 IV（16 bytes）
3. 使用 AES-128-CBC 加密（密鑰 = `base64_decode(API_SECRET)`）
4. 構造 `{"iv": base64(iv), "value": encrypted}`
5. 整體 Base64 編碼後發送

### 解密

1. Base64 解碼接收到的數據
2. JSON 解析得 `{"iv": "...", "value": "..."}`
3. Base64 解碼 IV
4. 使用 AES-128-CBC 解密 value
5. JSON 解析

## 銀行代碼映射（馬來西亞）

| 系統代碼 | VtPay 代碼 | 銀行名稱 |
|---|---|---|
| CIMB | CIMB | CIMB Bank |
| MAYBANK | MAYBANK | Maybank |
| PUBLIC_BANK | PBB | Public Bank |
| RHB | RHB | RHB Bank |
| HONG_LEONG | HLB | Hong Leong Bank |
| AMBANK | AMBANK | AmBank |
| UOB | UOB | UOB Bank |
| BANK_ISLAM | BIMB | Bank Islam |
| AFFIN | AFFIN | Affin Bank |
| ALLIANCE | ALLIANCE | Alliance Bank |

> 實際使用時以 VtPay 官方最新清單為準。

## 參考資料

- 官方文檔：https://example.invalid/9c4f18e7-0304-4357-83f1-cbc07b37ea59
- PHP 加解密示例：https://example.invalid/devkhgy/top/blob/doc/code/PHP_example_encrypt_decrypt.php
- 內部 OpenAPI：`app/Services/Payment/Providers/VtPay/VtPay.openapi.json`
