# A3Pay 廠商規格（SGD）

> 廠商原文整理，不含我方詮釋。來源：A3pay Merchant Web Integration Handbook v3.3.5
> （<https://example.invalid/API/a3pay_api_doc.html>，2026-09-22 抓取）。工單：target/tickets#4394。
> 我方實作：`app/Services/Payment/Providers/A3Pay/`。

## 基本資訊

| 項目 | 值 |
|---|---|
| 幣別 | SGD only |
| 正式 API domain | `https://example.invalid` |
| 收銀台 domain | `https://example.invalid` |
| A3Pay 出口 IP（回調來源） | `0.0.0.0` |
| 商戶端出口 IP | 須事先提供給 A3Pay 加白名單 |
| 請求格式 | JSON POST（`Content-Type: application/json`） |
| API Version | `0` |
| 加密 | AES-256-CBC（PBKDF2-SHA1 衍生密鑰）加密 Data；RSA SHA-1 簽名 |

### 金鑰

- 兩組獨立 RSA key pair：商戶自建一組，公鑰交 A3Pay 登錄 BO；A3Pay 另建一組，公鑰交商戶。私鑰不離開建立方。
- AES key 由 A3Pay 產生後交商戶。
- 商戶送出的請求：以**商戶私鑰**簽名。A3Pay 送來的訊息（回調／Payout Verification）：以 **A3Pay 公鑰**驗簽。

## 通用信封（所有商戶主動呼叫的接口）

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| MerchantCode | string | ✓ | A3Pay 核發的商戶代碼，如 `SGD00001` |
| Data | string | ✓ | Data Parameter 轉 JSON 後 AES 加密（見「AES」節） |
| Signature | string | ✓ | Data Parameter 的 JSON **明文**以商戶私鑰 RSA SHA-1 簽名後 base64（見「RSA」節） |
| Version | int | ✓ | `0` |

範例（Payin）：

```json
{
  "merchantCode": "SGD00001",
  "data": "P4xgJ8G6yilvAjKI7zZzs18xrqQT9cq13p11+RCyQxTG207gj/...",
  "signature": "V49YGdaJp+rpWV5yoAZjWXPr1mgf917f15m7iXFfwOPVO0WXMOM/...",
  "version": 0
}
```

> 廠商範例信封 key 大小寫不一致（Payin 範例小寫 `merchantCode`，其餘範例大寫 `MerchantCode`）。

### 通用回應

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| isSuccess | bool | ✓ | 請求是否受理 |
| successCode | int | ✓ | 回應狀態碼，如 `200` |
| errorMessage | string | 條件 | 無法處理時的錯誤訊息 |
| errorList | List of `{ErrorCode, ErrorDescription}` | 條件 | 驗證錯誤清單 |
| data | Result Model | ✓ | 各接口結果 payload |

Payin 章節將此回應標題寫為「Bad Request Response (400)」。廠商未提供 ErrorCode 清單。

## Payin（代收）

### Submit Payin

`POST https://example.invalid/api/Payin/CreatePayin`

Data Parameter：

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| MID | string | ✓ | 商戶代碼 |
| MTransactionId | string | ✓ | 商戶唯一交易號 |
| MCustId | string | 選填 | 商戶端會員識別 |
| PayinAmount | decimal | ✓ | 代收金額，2 位小數 |
| CurrCode | string | ✓ | `SGD` |
| RequestIPAddress | string | ✓ | 會員裝置 IP |
| FromCustomerBankCode | string | 條件 | 會員付款銀行；僅部分支付方式需要，其餘可省略。見 Bank Appendix |
| CallbackURL | string | 選填 | 狀態回調網址 |
| ReturnClientURL | string | 選填 | 會員付款後返回頁 |
| PaymentMethodId | string | ✓ | 支付方式，如 `OB`。見 Payin Method |
| FromCustomerAccountHolderName | string | 條件 | 付款帳戶戶名；**幣別為 SGD 時必填** |
| FromCustomerAccountNumber | string | 選填 | 付款帳號 |

回應 `data`：

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| paymentOrderId | string | ✓ | A3Pay 代收單號 |
| paymentURL | string | ✓ | A3Pay 收銀台連結。廠商備註：即使驗證失敗仍可導向 paymentURL，收銀台會向會員顯示錯誤 |
| paymentBankCode | string | 條件 | 收款銀行代碼 |
| paymentAccountNumber | string | 條件 | 收款帳號 |
| paymentAccountHolderName | string | 條件 | 收款戶名 |
| paymentReferenceNo | string | 條件 | 會員轉帳須填的參考號（銀行／電子錢包轉帳時會員必須輸入） |
| paymentQRCode | string | 條件 | 付款 QR code，base64 圖片 |
| payinActualAmount | decimal | ✓ | 會員實際須轉的金額。**SGD：同收款帳戶同金額的兩筆交易，系統會微幅上調金額以唯一化（如 100 → 100.01）** |
| paymentDeepLink | string | 條件 | 開啟會員銀行／電子錢包 App 的 deep link |
| bankBranchCode | string | 條件 | 收款帳戶分行代碼 |

廠商備註：當 paymentBankCode／paymentAccountNumber／paymentAccountHolderName／paymentReferenceNo／paymentQRCode 為 null 或空時，改導向 paymentURL。

範例回應：

```json
{
  "isSuccess": true, "successCode": 200, "errorCode": "", "errorMessage": "", "errorList": null,
  "data": {
    "paymentOrderId": "D2023111111111111",
    "paymentURL": "https://example.invalid/Deposit/DepositCashier/?a=PeJ9TCBRWTGapY8QDQg7UMh69",
    "paymentBankCode": null, "paymentAccountNumber": "01111111", "paymentAccountHolderName": "TEST",
    "paymentReferenceNo": "920111", "paymentQRCode": "data:ImageXXXX", "payinActualAmount": 500.00,
    "paymentDeepLink": null, "bankBranchCode": null
  }
}
```

### Payin Callback

A3Pay 以 JSON POST 打商戶 CallbackURL。商戶回 `success`（純文字）A3Pay 即停止重送。

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| TransactionId | string | ✓ | A3Pay 代收單號，如 `D2023042600000093` |
| MerchantTransactionId | string | ✓ | 商戶交易號 |
| Data | string | ✓ | AES 加密的 Payin payload |
| AdditionalInformationData | string | ✓ | AES 加密的補充資料 |
| Signature | string | ✓ | RSA 簽名，以 A3Pay 公鑰驗 |
| Version | int | ✓ | `0` |

Data 解密後：

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| MerchantCode | string | ✓ | 商戶代碼 |
| CurrencyCode | string | ✓ | `SGD` |
| MerchantTransactionId | string | ✓ | 商戶交易號 |
| Status | int | ✓ | 代收狀態，見 Payin Status |
| Amount | decimal | ✓ | 代收金額（Payin amount requested），2 位小數 |
| NetAmount | decimal | ✓ | 扣手續費後金額 |
| Remark | string | 選填 | 備註 |
| PaymentMethodCode | string | ✓ | 支付方式 |

AdditionalInformationData 解密後：`BankAccountNumber`、`BankAccountName`、`BankCode`（收款帳戶資訊，皆選填）。

### Payin Requery

`POST https://example.invalid/api/Payin/GetPayinDetail`，信封同上。

Data：`MID`、`MTransactionId`、`CurrCode`。

回應 `data`：`paymentOrderId`、`mTransactionId`、`statusId`（int）、`status`（string，如 `Success`）、`payinAmount`、`payinNetAmount`、`paymentMethodId`。

## Payout（代付）

### Submit Payout

`POST https://example.invalid/api/Payout/SubmitPayout`

Data Parameter：

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| MID | string | ✓ | 商戶代碼 |
| MTransactionId | string | 選填 | 商戶唯一交易號 |
| MCustId | string | 選填 | 商戶端會員識別 |
| PayoutAmount | decimal | ✓ | 代付金額，2 位小數 |
| CurrCode | string | ✓ | `SGD` |
| RequestIPAddress | string | ✓ | 會員裝置 IP |
| ToClientBankCode | string | ✓ | 收款銀行代碼，見 Bank Appendix |
| ToClientAccountNumber | string | ✓ | 收款帳號 |
| ToClientAccountHolderName | string | ✓ | 收款戶名 |
| ToClientBranchCode | string | 選填 | 分行代碼 |
| ToClientProvince | string | 選填 | 省 |
| ToClientCity | string | 選填 | 市 |
| ToClientBranch | string | 選填 | 分行 |
| CallBackURL | string | 選填 | 狀態回調網址（注意大小寫：Payout 是 `CallBackURL`，Payin 是 `CallbackURL`） |
| ReturnClientURL | string | 選填 | **Payout Verification 網址**：A3Pay 處理前打此網址向商戶確認 |
| PaymentMethodId | string | ✓ | `WD`，見 Payout Method |

回應 `data`：`paymentOrderId`（A3Pay 代付單號，如 `W2023111111111111`）。

### Payout Callback

JSON POST 打商戶 CallBackURL，回 `success` 停止重送。信封：`TransactionId`、`MerchantTransactionId`、`Data`、`Signature`、`Version`（**無** AdditionalInformationData）。

Data 解密後：`MerchantCode`、`CurrencyCode`、`MerchantTransactionId`、`Status`（見 Payout Status）、`Amount`、`NetAmount`、`Remark`、`PaymentMethodCode`。

### Payout Verification

A3Pay 處理代付前以 JSON POST 打 Submit Payout 的 `ReturnClientURL`。商戶回 `success` 代付才繼續；**回其他任何內容代付即被拒絕**。

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| MerchantCode | string | ✓ | 商戶代碼 |
| Data | string | ✓ | AES 加密 |
| AdditionalInformationData | string | ✓ | AES 加密 |
| Signature | string | ✓ | RSA 簽名，以 A3Pay 公鑰驗 |

Data 解密後：`MerchantTransactionId`、`Amount`（2 位小數）、`CurrencyCode`。
AdditionalInformationData 解密後：`DestinationClientBankCode`、`DestinationClientAccountNumber`、`DestinationClientAccountHolderName`。

### Payout Requery

`POST https://example.invalid/api/Payout/GetPayoutDetail`，Data：`MID`、`MTransactionId`、`CurrCode`。

回應 `data`：`paymentOrderId`（條件）、`mTransactionId`、`statusId`、`status`、`payoutAmount`、`payoutNetAmount`、`paymentMethodId`。

## Merchant API

### CheckBalance

`POST https://example.invalid/api/Merchant/CheckAccBalance`，Data：`MID`、`CurrCode`。

回應 `data`：`mID`、`currCode`、`currentBalanceAmount`、`availableBalanceAmount`。

## Bank API

### GetSupportedBank

`GET https://example.invalid/api/Bank/GetSupportedBankList?MID=SGD00001&PaymentMethodId=WD&currCode=SGD`

無加密信封。`PaymentMethodId` 目前只支援 `WD`。廠商建議以此接口取代硬編碼銀行清單。

回應 `data[]`：`bankName`、`bankNameOrigin`、`bankCode`、`currCode`、`mID`。

## 加解密

### AES

- 密鑰：`hash_pbkdf2('sha1', $aesKey, $aesKey, 1000, 32, true)`（password 與 salt 都是 AES key，1000 次，32 bytes）
- 模式：AES-256-CBC，PKCS7 padding
- IV：隨機 16 bytes，**前置於密文之前**，整體 base64

廠商 PHP 範例：

```php
function AESEncryption($plainText, $aesKey)
{
    $key = hash_pbkdf2("sha1", $aesKey, $aesKey, 1000, 32, true);
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted_data = openssl_encrypt($plainText, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $encrypted_data);
}

function AESDecryption($encryptedStringBase64, $aesKey)
{
    $raw = base64_decode($encryptedStringBase64);
    $key = hash_pbkdf2("sha1", $aesKey, $aesKey, 1000, 32, true);
    $iv = substr($raw, 0, 16);
    return openssl_decrypt(substr($raw, 16), "aes-256-cbc", $key, OPENSSL_RAW_DATA, $iv);
}
```

### RSA SHA-1

- 簽名：Data Parameter 的 JSON 明文，以商戶私鑰（PKCS#8 base64，補 `-----BEGIN PRIVATE KEY-----` 頭尾）`openssl_sign(..., OPENSSL_ALGO_SHA1)` 後 base64。
- 驗簽：先 AES 解密 Data 取得 JSON 明文，再以 A3Pay 公鑰（X.509 base64，補 `-----BEGIN PUBLIC KEY-----` 頭尾）`openssl_verify(明文, base64_decode(Signature), 公鑰, OPENSSL_ALGO_SHA1)`。

> 廠商「Form Encrypted Data」與「Form Signature」章節的 JSON 範例欄位為 `MerchantCode / MerchantTransactionId / MerchantClientId / CurrencyCode / Amount / NotifyUrl / ReturnUrl / IPAddress / PaymentMethodCode`，與 Payin／Payout 章節的 Data Parameter 欄位名（`MID / MTransactionId / …`）不一致。照抄原文，未修正。

## 錯誤碼（文件未列，實測補記）

廠商文件沒有 `errorCode` 清單。下表為 2026-09-24 以測試商戶實打得到的回應（皆 HTTP 400、JSON 信封、`data.transactionId`／`paymentOrderId` 為 null）：

| errorCode | errorMessage | 觸發情境 | 接口 |
|---|---|---|---|
| PMT10001 | Invalid Bank Code. | `ToClientBankCode` 不在支援清單 | SubmitPayout |
| PMT10005 | Invalid Transaction Amount. | 代付金額低於下限 50；代收通道未開通時任何金額也回此碼 | SubmitPayout、CreatePayin |
| PMT10006 | Invalid Payment Method. | `PaymentMethodId` 不是 OB／BQR | CreatePayin |
| PMT10013 | Insufficient Balance. | 商戶餘額不足 | SubmitPayout |

另：`GetPayoutDetail` 查不存在的交易號回 HTTP 400 **純文字** `Not found`（廠商 2026-09-24 確認）；CreatePayin 用錯欄位名回 HTTP 400 純文字 `Invalid Request`。

## 附錄

### Currency

| Code | Name |
|---|---|
| SGD | Singapore Dollar |

### Payin Method

| Code | Name | Currency |
|---|---|---|
| OB | Online banking（网银支付） | SGD |
| BQR | QR code（扫码支付） | SGD |

### Payout Method

| Code | Name | Currency |
|---|---|---|
| WD | Payout（代付） | SGD |

### Bank Appendix（SGD，以 GetSupportedBank 為準）

| Bank Code | English Bank Name | Origin Bank Name |
|---|---|---|
| DASH | DASH | DASH |
| LiquidPay | LiquidPay | LiquidPay |
| ABMB | Alliance Bank | Alliance Bank |
| Wise | Wise | Wise |
| TBL | Trust Bank | Trust Bank |
| RHB | RHB Bank | RHB Bank |
| MBBS | MayBank Singapore | MayBank Singapore |
| HSBCS | HSBC Bank Singapore | HongKong and Shanghai Banking Singapore |
| HLB | HongLeong Bank | Hong Leong Bank |
| CITIS | CitiBank Singapore | CitiBank Singapore |
| BOC | Bank of China | Bank of China |
| PayNow | PayNow | PayNow |
| POSB | The Development Bank of Singapore | The Development Bank of Singapore |
| Maribank | Maribank | Maribank |
| GXS | GXS Bank | GXS Bank |
| SCBS | Standard Chartered Bank Singapore | Standard Chartered Bank Singapore |
| CIMB | CIMB Bank | CIMB Bank |
| UOB | United Overseas Bank | United Overseas Bank |
| SDBS | DBS Bank Singapore | Development Bank of Singapore |
| OCBC | OCBC Bank | Oversea-Chinese Banking Corporation Limited |

### Payin Status

| Status | Id |
|---|---|
| Verify Fail | 4（**非終態**） |
| Success | 6 |
| Fail | 7 |
| Processing | 6、7 以外任何值 |

### Payout Status

| Status | Id |
|---|---|
| Success | 4 |
| Fail | 5 |
| Processing | 4、5 以外任何值（實測 GetPayoutDetail 對剛受理的單回 `statusId 6`、`status "Unknown"`） |
