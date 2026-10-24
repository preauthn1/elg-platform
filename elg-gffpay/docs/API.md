# API 文件

本文檔說明 GFFPay TON 支付系統的 API 介面。

## 基礎資訊

### Base URL

```
https://example.invalid/api
```

### 請求格式

- Content-Type: `application/json`
- 所有請求都需要進行簽名驗證

### 響應格式

所有 API 響應都遵循以下格式：

**成功響應：**
```json
{
  "code": 200,
  "message": "SUCCESS",
  "data": {
    // 響應數據
  }
}
```

**錯誤響應：**
```json
{
  "code": 40001,
  "message": "INVALID_SIGN"
}
```

### 簽名算法

所有 API 請求都需要包含 `sign` 參數進行驗證。簽名算法如下：

1. 移除參數中的 `sign` 欄位
2. 將剩餘參數按照鍵名進行字典序排序
3. 將參數拼接成 `key=value&key=value` 格式
4. 在拼接字串末尾加上商家的 `secret_key`
5. 對整個字串進行 MD5 加密
6. 將結果轉換為大寫

**範例：**

假設參數為：
```json
{
  "merchant_code": "TEST001",
  "merchant_order_no": "ORDER123",
  "currency_code": "TON/TON",
  "amount": "10.5",
  "sign": "需要計算的值"
}
```

`secret_key` 為 `your_secret_key`

計算步驟：
1. 移除 `sign`：`merchant_code=TEST001&merchant_order_no=ORDER123&currency_code=TON/TON&amount=10.5`
2. 加上 `secret_key`：`merchant_code=TEST001&merchant_order_no=ORDER123&currency_code=TON/TON&amount=10.5your_secret_key`
3. MD5 並轉大寫：`strtoupper(md5($signString))`

### 錯誤碼說明

| 錯誤碼 | 說明 |
|--------|------|
| 200 | 成功 |
| 40001 | 驗證簽名失敗 |
| 40002 | 沒有可用的錢包 |
| 40003 | 參數格式錯誤 |
| 40004 | 訂單號重複 |
| 40005 | 錢包地址無效 |
| 40006 | 代付錢包餘額不足 |
| 40007 | 訂單不存在 |
| 40008 | 商家不存在 |
| 40009 | 幣別不支援 |
| 40010 | 錢包部屬狀態錯誤 |
| 500 | 系統錯誤 |
| 50001 | 系統繁忙 |

### 支援的貨幣

- `TON/TON`
- `TON/USDT`

### 訂單狀態

| 狀態值 | 說明 |
|--------|------|
| 1 | 等待付款 (PENDING) |
| 2 | 成功 (SUCCESS) |
| 3 | 失敗 (FAILED) |

---

## API 介面

### 1. 建立代收訂單

建立一個代收訂單，用戶將轉帳到系統分配的子錢包。

**請求**

```
POST /api/deposit-order
```

**請求參數**

| 參數名 | 類型 | 必填 | 說明 | 範例 |
|--------|------|------|------|------|
| merchant_code | string | 是 | 商家代碼 | "TEST001" |
| merchant_order_no | string | 是 | 商家訂單號（最大長度 50） | "ORDER123456" |
| currency_code | string | 是 | 幣別代碼 | "TON/TON" |
| amount | string | 是 | 金額（格式：整數部分最多 11 位，小數部分最多 9 位） | "10.5" |
| callback_url | string | 是 | 回調網址（最大長度 191） | "https://example.invalid/callback" |
| return_url | string | 否 | 返回網址（最大長度 191） | "https://example.invalid/return" |
| sign | string | 是 | 簽名 | "A1B2C3D4E5F6..." |

**請求範例**

```json
{
  "merchant_code": "TEST001",
  "merchant_order_no": "ORDER123456",
  "currency_code": "TON/TON",
  "amount": "10.5",
  "callback_url": "https://example.invalid/callback",
  "return_url": "https://example.invalid/return",
  "sign": "A1B2C3D4E5F6G7H8I9J0K1L2M3N4O5P6"
}
```

**響應參數**

| 參數名 | 類型 | 說明 |
|--------|------|------|
| order_no | string | 訂單編號 |
| merchant_order_no | string | 商家訂單編號 |
| amount | string | 金額 |
| payment_url | string | 支付網址（用戶可通過此網址進行付款） |

**響應範例**

```json
{
  "code": 200,
  "message": "SUCCESS",
  "data": {
    "order_no": "D2025010112000101",
    "merchant_order_no": "ORDER123456",
    "amount": "10.5",
    "payment_url": "https://example.invalid/cashier/D2025010112000101"
  }
}
```

---

### 2. 查詢代收訂單

查詢代收訂單的狀態和資訊。

**請求**

```
GET /api/deposit-order
```

**請求參數**

| 參數名 | 類型 | 必填 | 說明 | 範例 |
|--------|------|------|------|------|
| merchant_code | string | 是 | 商家代碼 | "TEST001" |
| merchant_order_no | string | 是 | 商家訂單號 | "ORDER123456" |
| currency_code | string | 是 | 幣別代碼 | "TON/TON" |
| sign | string | 是 | 簽名 | "A1B2C3D4E5F6..." |

**請求範例**

```
GET /api/deposit-order?merchant_code=TEST001&merchant_order_no=ORDER123456&currency_code=TON/TON&sign=A1B2C3D4E5F6...
```

**響應參數**

| 參數名 | 類型 | 說明 |
|--------|------|------|
| order_no | string | 訂單編號 |
| merchant_order_no | string | 商家訂單編號 |
| amount | string | 金額 |
| status | int | 訂單狀態（1: 等待付款, 2: 成功, 3: 失敗） |
| confirmed_at | string\|null | 上鏈時間（ISO 8601 格式，成功時有值） |
| payment_url | string | 支付網址 |

**響應範例**

```json
{
  "code": 200,
  "message": "SUCCESS",
  "data": {
    "order_no": "D2025010112000101",
    "merchant_order_no": "ORDER123456",
    "amount": "10.5",
    "status": 2,
    "confirmed_at": "2025-01-01T12:05:30.000Z",
    "payment_url": "https://example.invalid/cashier/D2025010112000101"
  }
}
```

---

### 3. 建立代付訂單

建立一個代付訂單，系統將從主錢包轉帳到指定地址。

**請求**

```
POST /api/withdraw-order
```

**請求參數**

| 參數名 | 類型 | 必填 | 說明 | 範例 |
|--------|------|------|------|------|
| merchant_code | string | 是 | 商家代碼 | "TEST001" |
| merchant_order_no | string | 是 | 商家訂單號（最大長度 50） | "ORDER123456" |
| currency_code | string | 是 | 幣別代碼 | "TON/TON" |
| address | string | 是 | 收款地址（最大長度 191） | "EQD...xxx" |
| amount | string | 是 | 金額（格式：整數部分最多 11 位，小數部分最多 9 位） | "10.5" |
| callback_url | string | 是 | 回調網址（最大長度 191） | "https://example.invalid/callback" |
| sign | string | 是 | 簽名 | "A1B2C3D4E5F6..." |

**請求範例**

```json
{
  "merchant_code": "TEST001",
  "merchant_order_no": "ORDER123456",
  "currency_code": "TON/TON",
  "address": "EQD0vdSA_NedR9uvbgN9EikRX-suesDxGeFgYx2wf5uqbggk",
  "amount": "10.5",
  "callback_url": "https://example.invalid/callback",
  "sign": "A1B2C3D4E5F6G7H8I9J0K1L2M3N4O5P6"
}
```

**響應參數**

| 參數名 | 類型 | 說明 |
|--------|------|------|
| order_no | string | 訂單編號 |
| merchant_order_no | string | 商家訂單編號 |
| amount | string | 金額 |

**響應範例**

```json
{
  "code": 200,
  "message": "SUCCESS",
  "data": {
    "order_no": "W2025010112000101",
    "merchant_order_no": "ORDER123456",
    "amount": "10.5"
  }
}
```

---

### 4. 查詢代付訂單

查詢代付訂單的狀態和資訊。

**請求**

```
GET /api/withdraw-order
```

**請求參數**

| 參數名 | 類型 | 必填 | 說明 | 範例 |
|--------|------|------|------|------|
| merchant_code | string | 是 | 商家代碼 | "TEST001" |
| merchant_order_no | string | 是 | 商家訂單號 | "ORDER123456" |
| currency_code | string | 是 | 幣別代碼 | "TON/TON" |
| sign | string | 是 | 簽名 | "A1B2C3D4E5F6..." |

**請求範例**

```
GET /api/withdraw-order?merchant_code=TEST001&merchant_order_no=ORDER123456&currency_code=TON/TON&sign=A1B2C3D4E5F6...
```

**響應參數**

| 參數名 | 類型 | 說明 |
|--------|------|------|
| order_no | string | 訂單編號 |
| merchant_order_no | string | 商家訂單編號 |
| amount | string | 金額 |
| status | int | 訂單狀態（1: 等待付款, 2: 成功, 3: 失敗） |
| confirmed_at | string\|null | 上鏈時間（ISO 8601 格式，成功時有值） |

**響應範例**

```json
{
  "code": 200,
  "message": "SUCCESS",
  "data": {
    "order_no": "W2025010112000101",
    "merchant_order_no": "ORDER123456",
    "amount": "10.5",
    "status": 2,
    "confirmed_at": "2025-01-01T12:05:30.000Z"
  }
}
```

---

## 回調通知

當訂單狀態發生變化時，系統會向商家設定的 `callback_url` 發送 POST 請求進行通知。

### 回調參數

| 參數名 | 類型 | 說明 |
|--------|------|------|
| order_no | string | 訂單編號 |
| merchant_order_no | string | 商家訂單編號 |
| status | int | 訂單狀態（1: 等待付款, 2: 成功, 3: 失敗） |
| amount | string | 金額 |
| confirmed_at | string\|null | 上鏈時間（ISO 8601 格式，成功時有值） |
| sign | string | 簽名 |

### 回調範例

```json
{
  "order_no": "D2025010112000101",
  "merchant_order_no": "ORDER123456",
  "status": 2,
  "amount": "10.5",
  "confirmed_at": "2025-01-01T12:05:30.000Z",
  "sign": "A1B2C3D4E5F6G7H8I9J0K1L2M3N4O5P6"
}
```

### 回調處理建議

1. 收到回調後，請先驗證簽名
2. 驗證訂單號是否為您的訂單
3. 處理業務邏輯
4. 返回 `{"code": 200}` 表示接收成功
5. 如果處理失敗，請返回錯誤響應，系統會進行重試

---

## 注意事項

1. **訂單號唯一性**：每個商家的 `merchant_order_no` 在同一幣別下必須唯一
2. **金額格式**：金額必須為數字字串，整數部分最多 11 位，小數部分最多 9 位
3. **地址格式**：代付訂單的 `address` 必須為有效的 TON 地址
4. **簽名驗證**：所有請求都必須包含有效的簽名，否則會返回 `40001` 錯誤
5. **回調重試**：如果回調失敗，系統會自動重試，請確保回調介面具有冪等性
6. **訂單查詢**：商家只能查詢自己的訂單，無法查詢其他商家的訂單

