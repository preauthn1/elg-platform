# Bigpayz (GlobePay365) API 整合文檔

## 基本信息

- **金流商名稱**: Bigpayz (GlobePay365)
- **API 版本**: 0
- **加密方式**: AES-256-CBC + RSA SHA-1 簽名

## 環境配置

### 正式環境
- API Domain: `https://example.invalid`
- 輸出 IP: `0.0.0.0`

### 測試環境
- API Domain: `https://example.invalid`
- 輸出 IP: `0.0.0.0`

## 密鑰配置

需要配置以下密鑰信息：

1. **MerchantCode** (商戶代碼)
2. **AES_KEY** (AES 加密密鑰，由 GlobePay365 提供)
3. **MerchantPrivateKey** (商戶 RSA 私鑰，用於簽名)
4. **GlobePay365PublicKey** (GlobePay365 RSA 公鑰，用於驗簽)
5. **Currency** (貨幣代碼，如 VND、MYR、THB 等)

## API 端點

### 1. 代收 (Deposit)

**端點**: `/api/Deposit/SubmitDeposit`

**請求方法**: POST (JSON)

**請求參數**:
```json
{
  "MerchantCode": "商戶代碼",
  "Data": "AES 加密後的資料 (Base64)",
  "Signature": "RSA SHA-1 簽名 (Base64)",
  "Version": 0
}
```

**Data 內容** (加密前):
```json
{
  "MerchantCode": "商戶代碼",
  "MerchantTransactionId": "商戶訂單號",
  "MerchantClientId": "會員用戶名",
  "CurrencyCode": "貨幣代碼",
  "Amount": 100.00,
  "SourceClientBankCode": "存款銀行代碼 (BMR 必填)",
  "NotifyUrl": "回調 URL",
  "ReturnUrl": "返回 URL",
  "IPAddress": "用戶 IP",
  "PaymentMethodCode": "支付方式代碼",
  "AccountHolderName": "存款人姓名 (可選)",
  "AccountNumber": "存款人帳號 (可選)"
}
```

**返回參數**:
```json
{
  "isSuccess": true,
  "successCode": 200,
  "errorMessage": "",
  "errorList": null,
  "data": {
    "transactionId": "D2023111111111111",
    "url": "https://example.invalid/...",
    "bankCode": "銀行代碼",
    "accountNumber": "收款帳號",
    "accountHolderName": "收款戶名",
    "referenceNo": "參考編號",
    "qrCode": "QR Code (base64 image)",
    "depositActualAmount": 500.00,
    "deepLink": "UPI 深度鏈接"
  }
}
```

### 2. 代收回調 (Deposit Callback)

**請求方法**: POST (JSON)

**請求參數**:
```json
{
  "TransactionId": "GlobePay 訂單號",
  "MerchantTransactionId": "商戶訂單號",
  "Data": "AES 加密後的資料",
  "AdditionalInformationData": "額外信息 (AES 加密)",
  "Signature": "RSA SHA-1 簽名",
  "Version": 0
}
```

**Data 內容** (解密後):
```json
{
  "MerchantCode": "商戶代碼",
  "CurrencyCode": "貨幣代碼",
  "MerchantTransactionId": "商戶訂單號",
  "Status": 6,
  "Amount": 100.00,
  "NetAmount": 98.50,
  "Remark": "備註",
  "PaymentMethodCode": "支付方式",
  "BankReferenceNo": "銀行參考號",
  "UniqueReferenceNo": "唯一參考號"
}
```

**回調狀態碼**:
- `6`: Success (成功)
- `7`: Failed (失敗)
- `8`: Cancelled (取消)

**返回值**: `success` (字符串)

### 3. 代付 (Withdrawal)

**端點**: `/api/Withdrawal/SubmitWithdrawal`

**請求方法**: POST (JSON)

**Data 內容** (加密前):
```json
{
  "MerchantCode": "商戶代碼",
  "MerchantTransactionId": "商戶訂單號",
  "MerchantClientId": "會員用戶名",
  "CurrencyCode": "貨幣代碼",
  "Amount": 100.00,
  "DestinationClientBankCode": "收款銀行代碼",
  "DestinationClientAccountNumber": "收款帳號",
  "DestinationClientAccountHolderName": "收款人姓名",
  "DestinationClientProvince": "省份 (可選)",
  "DestinationClientCity": "城市 (可選)",
  "DestinationClientBranch": "支行 (可選)",
  "NotifyUrl": "回調 URL",
  "ReturnUrl": "反查 URL",
  "IPAddress": "IP 地址",
  "PaymentMethodCode": "WD",
  "DestinationClientBranchCode": "IFSCode (INR 必填)"
}
```

**返回參數**:
```json
{
  "isSuccess": true,
  "successCode": 200,
  "errorMessage": "",
  "errorList": null,
  "data": {
    "transactionId": "W2023111111111111"
  }
}
```

### 4. 代付回調 (Withdrawal Callback)

**Data 內容** (解密後):
```json
{
  "MerchantCode": "商戶代碼",
  "CurrencyCode": "貨幣代碼",
  "MerchantTransactionId": "商戶訂單號",
  "Status": 6,
  "Amount": 100.00,
  "NetAmount": 98.50,
  "Remark": "備註",
  "PaymentMethodCode": "WD",
  "BankReferenceNo": "銀行參考號",
  "UniqueReferenceNo": "唯一參考號"
}
```

**回調狀態碼**:
- `6`: Success (成功)
- `7`: Failed (失敗)
- `8`: Rejected (拒絕)

## 加密方式

### AES 加密 (AES-256-CBC)

1. 使用 PBKDF2 從 AES Key 生成 256 位密鑰
2. 生成隨機 16 bytes IV
3. 使用 AES-256-CBC 加密明文
4. 組合 IV + 密文
5. Base64 編碼

**PHP 實作**:
```php
$key = hash_pbkdf2('sha1', $aesKey, $aesKey, 1000, 32, true);
$iv = openssl_random_pseudo_bytes(16);
$encrypted = openssl_encrypt($plainText, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
$combined = $iv . $encrypted;
return base64_encode($combined);
```

### AES 解密

1. Base64 解碼
2. 提取前 16 bytes 作為 IV
3. 剩餘部分為密文
4. 使用 AES-256-CBC 解密

### RSA SHA-1 簽名

1. 使用商戶私鑰對 Data (加密後的字符串) 進行 RSA SHA-1 簽名
2. Base64 編碼簽名

**PHP 實作**:
```php
$key = openssl_pkey_get_private($privateKey);
openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA1);
return base64_encode($signature);
```

### RSA SHA-1 驗簽

1. Base64 解碼簽名
2. 使用 GlobePay365 公鑰驗證

## 支付方式代碼

### 代收方式
- `UPI`: UPI 支付 (印度)
- `BMR`: 網銀支付
- `PAYTM`: Paytm 電子錢包
- `PHONEPE`: PhonePe 電子錢包
- 等其他方式...

### 代付方式
- `WD`: Withdrawal (代付)

## 貨幣代碼

- `INR`: 印度盧比
- `VND`: 越南盾
- `MYR`: 馬來西亞令吉
- `THB`: 泰銖
- `BDT`: 孟加拉塔卡

## 錯誤處理

當 `isSuccess` 為 `false` 時：
- 檢查 `errorMessage` 獲取錯誤信息
- 檢查 `errorList` 獲取詳細錯誤列表

## 注意事項

1. 所有金額保留 2 位小數
2. 回調需要返回 `"success"` 字符串以停止重複回調
3. BMR (網銀) 支付方式需要提供 `SourceClientBankCode`
4. INR 貨幣代付需要提供 `DestinationClientBranchCode` (IFSCode)
5. 簽名驗證失敗會導致請求被拒絕
6. 金額不匹配會導致訂單處理失敗

## 實作檔案

- `Driver.php`: 驅動程式
- `BigpayzTrait.php`: 共用方法 (加密、簽名)
- `Deposit.php`: 代收實作
- `DepositCallback.php`: 代收回調
- `Withdrawal.php`: 代付實作
- `WithdrawalCallback.php`: 代付回調
