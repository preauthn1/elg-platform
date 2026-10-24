# PayEssence API 整合文件

> **文件用途**：本份文件由 PayEssence 官方文檔（`https://example.invalid/`）與 codebase 實作整理而成，涵蓋代收（Deposit）、代付（Withdrawal）、回調（Callback）、雙簽名驗證（CheckString / CheckString2）全流程。
>
> **最後更新**：官方文檔標示 2026-05-18。

---

## 目錄

1. [廠商簡介](#1-廠商簡介)
2. [支援的支付方式](#2-支援的支付方式)
3. [環境資訊](#3-環境資訊)
4. [代收 API](#4-代收-api)
5. [代付 API](#5-代付-api)
6. [回調處理](#6-回調處理)
7. [簽名與雙 CheckString 流程](#7-簽名與雙-checkstring-流程)
8. [銀行代碼映射](#8-銀行代碼映射)
9. [貨幣代碼對照](#9-貨幣代碼對照)
10. [錯誤碼對照](#10-錯誤碼對照)
11. [回調 IP 白名單](#11-回調-ip-白名單)
12. [參考資料](#12-參考資料)

---

## 1. 廠商簡介

PayEssence（系統域名 `mxpay.asia`）是面向東南亞與南亞市場的支付閘道，支援馬來西亞、越南、印度、泰國、孟加拉、汶萊、新加坡及加密貨幣等多幣別業務。

- **代收模式**：HTML Form POST 自動提交至支付閘道（`/WebForm/Deposit.aspx`）
- **代付模式**：B2B JSON API（`/WebForm/Deposit_P2pPayout2_B2b.aspx`）
- **加密通道**：ERC-20 USDT（EUSDT）、TRC-20 USDT（TUSDT）、BTC

---

## 2. 支援的支付方式

### 代收 TransProvider 代碼

| TransProvider | 支付方式 | 適用地區 |
|---|---|---|
| 2 | Maybank | 馬來西亞 |
| 3 | CIMB | 馬來西亞 |
| 4 | BSN | 馬來西亞 |
| 5 | Public Bank | 馬來西亞 |
| 6 | RHB | 馬來西亞 |
| 7 | Alliance Bank | 馬來西亞 |
| 11 | Hong Leong Bank | 馬來西亞 |
| 15 | Bank Islam | 馬來西亞 |
| 19 | Affin Bank | 馬來西亞 |
| 101 | Touch N Go eWallet | 馬來西亞 |
| 102 | GrabPay | 馬來西亞 |
| 103 | ShopeePay | 馬來西亞 |
| 104 | Boost | 馬來西亞 |
| 105 | DuitNow | 馬來西亞 |
| 451 | UPI | 印度 |
| 550 | MomoPay | 越南 |
| 551 | ZaloPay | 越南 |
| 552 | ViettelPay | 越南 |
| 553 | 銀行轉帳（VND） | 越南 |
| 554 | 銀行轉帳（VND） | 越南 |
| 601 | Baiduri Bank | 汶萊 |
| 801 | EUSDT（ERC-20） | 加密貨幣 |
| 802 | TUSDT（TRC-20） | 加密貨幣 |
| 803 | BTC | 加密貨幣 |
| 901 | DBS | 新加坡 |
| 902 | OCBC | 新加坡 |
| 903 | UOB | 新加坡 |
| 1001 | 所有信用卡 | 通用 |
| 2101 | Digi（電信代收） | 馬來西亞 |
| 2102 | Maxis | 馬來西亞 |
| 2103 | Celcom | 馬來西亞 |
| 2104 | UMobile | 馬來西亞 |
| 2105 | Touch N Go PIN | 馬來西亞 |
| 4001 | PromptPay | 泰國 |
| 9051 | bKash | 孟加拉 |
| 9052 | Nagad | 孟加拉 |
| 9053 | Upay | 孟加拉 |
| 9054 | Rocket | 孟加拉 |

> 未傳 `TransProvider` 時，使用者在收銀台自行選擇支付方式。

---

## 3. 環境資訊

| 環境 | 代收 Endpoint | 代付 B2B Endpoint |
|---|---|---|
| **測試環境** | `https://api-demo.mxpay.asia/WebForm/Deposit.aspx` | `https://job1-demo.mxpay.asia/WebForm/Deposit_P2pPayout2_B2b.aspx` |
| **正式環境** | `https://api.mxpay.asia/WebForm/Deposit.aspx` | `https://job1.mxpay.asia/WebForm/Deposit_P2pPayout2_B2b.aspx` |

### 其他 Endpoint

| 用途 | 測試環境 | 正式環境 |
|---|---|---|
| 代收狀態查詢（Redirect） | `https://job1-demo.mxpay.asia/WebForm/Deposit_StatusCheck.aspx` | `https://job1.mxpay.asia/WebForm/Deposit_StatusCheck.aspx` |
| 代收狀態查詢（B2B JSON） | `https://job1-demo.mxpay.asia/WebForm/Deposit_StatusCheck_Json.aspx` | `https://job1.mxpay.asia/WebForm/Deposit_StatusCheck_Json.aspx` |
| 代付 Redirect 模式 | `https://job1-demo.mxpay.asia/WebForm/Deposit_P2pPayout2.aspx` | `https://job1.mxpay.asia/WebForm/Deposit_P2pPayout2.aspx` |
| 代付狀態查詢（B2B） | `https://job1-demo.mxpay.asia/WebForm/Deposit_P2pPayout2_Check_B2b.aspx` | `https://job1.mxpay.asia/WebForm/Deposit_P2pPayout2_Check_B2b.aspx` |
| 加密代收 | 無 | `https://job1.mxpay.asia/PublicApi/Deposit_Crypto` |

---

## 4. 代收 API

### 4.1 代收請求

**方法**：`POST`（HTML Form 自動提交）

**Endpoint**（正式）：`https://api.mxpay.asia/WebForm/Deposit.aspx`

#### 請求參數

| 參數 | 必填 | 類型 | 最大長度 | 說明 |
|---|---|---|---|---|
| `MerchantCode` | ✅ | String | - | 商戶代碼（大小寫敏感） |
| `TransNum` | ✅ | String | 50 | 商戶唯一訂單號 |
| `Currency` | ✅ | String | 5 | 幣別代碼（見第 9 節） |
| `Amount` | ✅ | Numeric | - | 金額，一般保留 2 位小數；EUSDT/TUSDT/BTC 最多 6 位 |
| `FirstName` | ✅ | String | 50 | 使用者識別（IDR 必填已登記銀行帳戶名稱） |
| `Country` | ✅ | String | 3 | 3 字母 ISO 國家碼（如 MYS、VNM、IND） |
| `Signature` | ✅ | String | - | SHA256 簽名（見第 7 節） |
| `TransProvider` | - | String | - | 跳過付款頁直連特定通道（見第 2 節） |
| `RedirectUrl` | - | String | - | 付款完成後導向 URL（僅供商戶參考） |
| `LastName` | - | String | 50 | 選填 |
| `EmailAddress` | - | String | 100 | 選填 |
| `PhoneNum` | - | String | 50 | 選填 |
| `Address` | - | String | 300 | 選填 |
| `City` | - | String | 50 | 選填 |
| `State` | - | String | 50 | 選填 |
| `Postcode` | - | String | 50 | 選填 |
| `PaymentDesc` | - | String | 300 | 付款描述（選填） |
| `MerchantRemark` | - | String | 300 | 商戶備註（選填） |

#### codebase 實際組裝

```php
$depositParams = [
    'MerchantCode'  => $merchantCode,
    'TransNum'      => $order->order_no,
    'Currency'      => $currency,          // 'MYR' / 'VND' / 'EUSDT' / 'TUSDT'
    'Amount'        => (string) $order->amount,
    'FirstName'     => $member->username,
    'Country'       => $country,
    'TransProvider' => $transProvider,     // 801=EUSDT, 802=TUSDT（選填）
    'Signature'     => $signature,
    'RedirectUrl'   => $params->returnUrl,
];
```

### 4.2 代收回調欄位

PayEssence 以 HTTP GET/POST 推送回調至商戶 RedirectUrl 或商戶後台設定的 PostbackUrl。

| 欄位 | 說明 |
|---|---|
| `MerchantCode` | 商戶代碼 |
| `TransNum` | 商戶訂單號 |
| `DepositId` | PayEssence 唯一存款 ID |
| `Amount` | 存款金額 |
| `Result` | 結果代碼（見下表） |
| `CheckString` | SHA256 驗簽字串 1 |
| `CheckString2` | SHA256 驗簽字串 2（僅成功時存在） |
| `FromBank` | 付款銀行（成功時） |
| `ToBank` | 收款銀行（成功時） |
| `BankAccountNum` | 收款帳號（成功時） |
| `Currency` | 幣別 |
| `MerchantFeePercentage` | 手續費百分比 |
| `MerchantFee` | 手續費金額 |

#### 代收結果代碼

| Result | 含義 | 內部處理 |
|---|---|---|
| `10001` | 成功 | `OrderStatusEnum::SUCCESS` |
| `10002` | 失敗 | `OrderStatusEnum::FAILED` |
| `10004` | 待處理（Pending） | 保持 `OrderStatusEnum::CREATED`，等待後續回調 |

> **重要**：Result=`10004` 時，系統不更新訂單狀態，回應 `{"Status":"OK"}` 等待下一次回調。

#### 代收回調驗簽流程

1. 收到回調後，先依 `TransNum` 查詢並鎖定訂單（`lockForUpdate`）
2. 驗證 `MerchantCode` 是否吻合
3. 解析 `Result`：若為 `10004` 直接回 `{"Status":"OK"}` 跳出
4. 驗證 `CheckString`（所有非 Pending 情況必驗）
5. 若 Result=`10001`（成功），**額外驗證** `CheckString2`
6. 驗證金額是否與訂單一致
7. 回應 `{"Status":"OK"}`

---

## 5. 代付 API

### 5.1 代付請求（B2B）

**方法**：`POST`（`application/x-www-form-urlencoded`）

**Endpoint**（正式）：`https://job1.mxpay.asia/WebForm/Deposit_P2pPayout2_B2b.aspx`

#### 請求參數

| 參數 | 必填 | 類型 | 最大長度 | 說明 |
|---|---|---|---|---|
| `MerchantCode` | ✅ | String | - | 商戶代碼（大小寫敏感） |
| `MerchantTransNum` | ✅ | String | 50 | 代付唯一交易號 |
| `CurrencyTypeId` | ✅ | Numeric | - | 幣別 ID（見下表） |
| `ToBankId` | ✅ | String | 2 | 目標銀行/錢包 ID（見第 8 節） |
| `ToBankAccountName` | ✅ | String | 100 | 收款人姓名（加密幣種填空字串） |
| `ToBankAccountNum` | ✅ | String | 50 | 收款帳號或錢包地址 |
| `Amount` | ✅ | Numeric | - | 金額，一般 2 位小數；加密最多 6 位 |
| `Signature` | ✅ | String | - | SHA256 簽名（見第 7 節） |
| `BankIdentifierCode` | - | String | 50 | IFSC 代碼（INR 才需要） |

#### CurrencyTypeId 對照

| CurrencyTypeId | 幣別 |
|---|---|
| `1` | MYR（馬來西亞令吉） |
| `8` | VND（越南盾） |
| `1001` | EUSDT（ERC-20 USDT） |
| `1002` | TUSDT（TRC-20 USDT） |

> 加密貨幣代付：`ToBankId` 固定填 `"0"`，`ToBankAccountName` 填空字串。

#### codebase 實際組裝

```php
$params = [
    'MerchantCode'       => $merchantCode,
    'MerchantTransNum'   => $transactionNo,        // Common::genTransactionNo('PE') 產生
    'CurrencyTypeId'     => $currencyTypeId,        // 1=MYR, 8=VND, 1001=EUSDT, 1002=TUSDT
    'ToBankId'           => $toBankId,              // 轉換自 bank_code
    'ToBankAccountName'  => $accountName,           // 加密貨幣為空字串
    'ToBankAccountNum'   => $withdrawalOrder->account_no,
    'Amount'             => sprintf('%.2f', $withdrawalOrder->amount),
    'BankIdentifierCode' => $bankIdentifierCode,    // 選填，INR 用
    'Signature'          => $signature,
];
```

### 5.2 代付響應

響應為 JSON 格式。

| 欄位 | 說明 |
|---|---|
| `Result` | 結果代碼（見下表） |
| `PayoutId` | PayEssence 代付唯一 ID |
| `MerchantCode` | 商戶代碼 |
| `MerchantTransNum` | 商戶交易號 |
| `CurrencyTypeId` | 幣別 ID |
| `ToBankId` | 目標銀行 ID |
| `ToBankAccountNum` | 收款帳號 |
| `Amount` | 代付金額 |
| `Remarks` | 備註 |
| `CheckString2` | 驗簽字串 |

#### 代付結果代碼

| Result | 含義 | 終態 |
|---|---|---|
| `35001` | Pending（待處理） | 否 |
| `35002` | Success（成功） | **是** |
| `35003` | Rejected（拒絕） | **是** |
| `35004` | Processing（處理中） | 否 |

> codebase 判斷：`in_array($resultCode, ['35001', '35002', '35004'])` 視為 API 受理成功；`35003` 為失敗。

### 5.3 代付回調欄位

| 欄位 | 必填 | 說明 |
|---|---|---|
| `MerchantCode` | ✅ | 商戶代碼 |
| `TransNum` | ✅ | 商戶代付交易號（`MerchantTransNum`） |
| `PayoutId` | ✅ | PayEssence 代付 ID |
| `Amount` | ✅ | 代付金額 |
| `Result` | ✅ | 結果代碼（35001–35004） |
| `CheckString2` | ✅ | 雙重驗簽字串 |
| `CurrencyTypeId` | - | 幣別 ID |
| `ToBankId` | - | 目標銀行 ID |
| `ToBankAccountNum` | - | 收款帳號 |
| `Remarks` | - | 備註 |

> **只有 `Result=35002`（成功）時才驗證 CheckString2**；其他狀態不驗（對應 codebase 邏輯）。

---

## 6. 回調處理

### 6.1 共通原則

- 回調只接受來自 IP 白名單的 HTTP POST（見第 11 節）
- 必須先驗 `MerchantCode`，再驗 `CheckString` / `CheckString2`
- 必須驗證金額是否與原訂單吻合（成功狀態才驗）
- 回應成功：`{"Status":"OK"}`；失敗/拒絕：`fail`

### 6.2 代付狀態查詢（B2B）

**Endpoint**（正式）：`https://job1.mxpay.asia/WebForm/Deposit_P2pPayout2_Check_B2b.aspx`

| 請求參數 | 說明 |
|---|---|
| `MerchantCode` | 商戶代碼 |
| `MerchantTransNum` | 原代付交易號 |
| `CheckString` | SHA256(MerchantCode + SecretKey + MerchantTransNum) |

---

## 7. 簽名與雙 CheckString 流程

### 7.1 代收請求簽名（Signature）

**算法**：SHA256

**拼接規則**：`MerchantCode + SecretKey + TransNum + Currency + Amount`

```php
protected function generateDepositSignature(
    string $merchantCode,
    string $secretKey,
    string $transNum,
    string $currency,
    string $amount
): string {
    $concatString = $merchantCode . $secretKey . $transNum . $currency . $amount;
    return hash('sha256', $concatString);
}
```

**範例**：
```
MerchantCode: {MerchantCode}
SecretKey:    {SecretKey}        # 範例佔位，實際值見各白標商戶配置
TransNum:     T1234
Currency:     MYR
Amount:       10.00

拼接：{MerchantCode}{SecretKey}T1234MYR10.00
SHA256：<hash>
```

### 7.2 代付請求簽名（Signature）

**拼接規則**：`MerchantCode + SecretKey + MerchantTransNum + ToBankId + ToBankAccountNum + CurrencyTypeId + Amount`

```php
protected function generateWithdrawalSignature(
    string $merchantCode,
    string $secretKey,
    string $transNum,
    string $toBankId,
    string $toBankAccountNum,
    string $currencyTypeId,
    string $amount
): string {
    $concatString = $merchantCode . $secretKey . $transNum . $toBankId . $toBankAccountNum . $currencyTypeId . $amount;
    return hash('sha256', $concatString);
}
```

### 7.3 代收回調 CheckString（第一層驗簽）

**算法**：SHA256

**拼接規則**：`MerchantCode + SecretKey + TransNum`

```php
protected function verifyDepositCheckString(
    string $merchantCode,
    string $secretKey,
    string $transNum,
    string $checkString
): bool {
    $expected = hash('sha256', $merchantCode . $secretKey . $transNum);
    return hash_equals($expected, $checkString);
}
```

### 7.4 代收回調 CheckString2（第二層驗簽，僅成功時）

**算法**：SHA256，含字串反轉（Reverse）

**拼接規則**：`Reversed(MerchantCode + TransNum) + SecretKey`

> 注意：先將 `MerchantCode` 與 `TransNum` **各自反轉**後拼接，再加上 `SecretKey`，最後做 SHA256。

```php
protected function verifyDepositCheckString2(
    string $merchantCode,
    string $secretKey,
    string $transNum,
    string $checkString2
): bool {
    $reversedMerchantCode = strrev($merchantCode);
    $reversedTransNum     = strrev($transNum);
    $concatReversed       = $reversedMerchantCode . $reversedTransNum;
    $expected             = hash('sha256', $concatReversed . $secretKey);
    return hash_equals($expected, $checkString2);
}
```

**範例**：
```
MerchantCode 反轉：7654321M       # 設 MerchantCode = M1234567
TransNum 反轉：    4321T          # 設 TransNum = T1234
拼接：            7654321M4321T
加 SecretKey：    7654321M4321T{SecretKey}
SHA256：          <hash>
```

### 7.5 代付回調 CheckString2（成功時才驗）

**拼接規則**：`MerchantCode + SecretKey + TransNum + PayoutId + ToBankId + ToBankAccountNum + CurrencyTypeId + Amount`

```php
protected function verifyWithdrawalCheckString2(
    string $merchantCode,
    string $secretKey,
    string $transNum,
    string $payoutId,
    string $toBankId,
    string $toBankAccountNum,
    string $currencyTypeId,
    string $amount,
    string $checkString2
): bool {
    $concatString = $merchantCode . $secretKey . $transNum . $payoutId
                  . $toBankId . $toBankAccountNum . $currencyTypeId . $amount;
    $expected = hash('sha256', $concatString);
    return hash_equals($expected, $checkString2);
}
```

### 7.6 雙簽名驗證流程圖

```
代收回調
│
├─ Result = 10004 → 回 OK，不驗簽
│
├─ 驗 CheckString（所有非 Pending 狀態）
│   └─ 失敗 → 拋 ApiException
│
└─ Result = 10001（成功）→ 額外驗 CheckString2
    └─ 失敗 → 拋 ApiException

代付回調
│
└─ Result = 35002（成功）→ 驗 CheckString2
    └─ 失敗 → 拋 ApiException
```

---

## 8. 銀行代碼映射

### 8.1 馬來西亞代付 ToBankId

| ToBankId | 銀行名稱 |
|---|---|
| 1 | Maybank |
| 2 | CIMB Bank |
| 3 | RHB Bank |
| 4 | Public Bank |
| 5 | Hong Leong Bank |
| 6 | Alliance Bank |
| 7 | Bank Islam |
| 8 | Affin Bank |
| 9 | AmBank |
| 10 | BSN |
| 11 | Touch N Go eWallet |
| 12 | GX Bank |
| 13 | Bank Rakyat |
| 15 | United Overseas Bank (UOB) |
| 16 | OCBC Bank |
| 17 | Bank Muamalat |
| 18 | Standard Chartered Bank |
| 24 | CitiBank Berhad |
| 26 | HSBC Bank |

### 8.2 越南代付 ToBankId

| ToBankId | 銀行名稱 |
|---|---|
| 101 | Asia Commercial Bank (ACB) |
| 102 | BIDV |
| 103 | Military Commercial Bank (MB) |
| 104 | Dong A Bank |
| 105 | EXIMBANK |
| 106 | SHB（Saigon-Hanoi Bank） |
| 107 | HD Bank |
| 108 | Sacombank |
| 109 | AGRIBANK |
| 110 | TECHCOMBANK |
| 111 | VietinBank |
| 112 | Vietcombank |

### 8.3 加密貨幣代付

| ToBankId | 說明 |
|---|---|
| 0 | 加密貨幣（固定值，ToBankAccountName 填空字串） |

> `ToBankAccountNum` 填入錢包地址。

---

## 9. 貨幣代碼對照

| Currency | 說明 |
|---|---|
| `MYR` | 馬來西亞令吉 |
| `VND` | 越南盾 |
| `INR` | 印度盧比 |
| `THB` | 泰銖 |
| `BDT` | 孟加拉塔卡 |
| `IDR` | 印尼盾 |
| `SGD` | 新加坡元 |
| `BND` | 汶萊元 |
| `USD` | 美元 |
| `EUR` | 歐元 |
| `CNY` | 人民幣 |
| `MMK` | 緬甸元 |
| `EUSDT` | ERC-20 USDT（以太坊鏈） |
| `TUSDT` | TRC-20 USDT（Tron 鏈） |
| `BTC` | 比特幣 |

---

## 10. 錯誤碼對照

### 10.1 代收狀態查詢回應碼

| 碼 | 說明 |
|---|---|
| 2001 | 已登記（等待付款） |
| 2002 | 處理中 |
| 2101 | 成功 |
| 2102 | 失敗 |
| 2201–2203 | 申訴相關狀態 |
| 1201–1204 | 商戶交易號錯誤 |
| 1301–1302 | CheckString 錯誤 |

### 10.2 代付錯誤碼

| 碼 | 說明 |
|---|---|
| `1101` | Merchant Code Not Defined |
| `1102` | Merchant Code Invalid |
| `1201` | Merchant Payout No. is not defined |
| `1202` | Invalid Merchant Payout No. |
| `1301` | Currency Code is not defined |
| `1302` | Invalid Currency Code |
| `1401` | Amount is not defined |
| `1402` | Invalid Amount |
| `1501` | Bank Name is not defined |
| `1502` | Invalid Bank Name |
| `1503` | Bank Name not matched |
| `1601` | Account Name is not defined |
| `1602` | Invalid Account Name |
| `1701` | Account Number is not defined |
| `1702` | Invalid Account Number |
| `1802` | Invalid Signature |
| `2301` | Bank ID is not defined |
| `2302` | Bank ID is invalid |
| `2401` | Account Name is not defined |
| `2402` | Account Name is invalid |
| `2501` | Account Number is not defined |
| `2502` | Account Number is invalid |
| `2701` | Merchant Payout Account is inactive |
| `2801` | Merchant IP Address Invalid |

---

## 11. 回調 IP 白名單

PayEssence 要求只接受以下 IP 的 HTTP POST 回調，商戶側需設白名單或防火牆規則：

```
0.0.0.0
0.0.0.0
0.0.0.0
0.0.0.0
0.0.0.0
0.0.0.0
0.0.0.0
0.0.0.0
0.0.0.0
0.0.0.0
0.0.0.0
0.0.0.0
0.0.0.0
```

> 歷史 IP（已停用，無需保留）：`0.0.0.0`（2021-06 停用）

---

## 12. 參考資料

| 資料來源 | 說明 |
|---|---|
| [官方 API 文檔](https://example.invalid/) | PayEssence 官方完整規格（含所有語言/地區） |
| GitLab #3397 | [APIs] PayEssence USDT/MYR 代收代付接入工單（含測試環境帳號說明） |
| GitLab #3500 | [TPower] PayEssence USDT/MYR 代收代付配置 |
| GitLab #3495–#3501 | 各白標站點配置工單 |
| GitLab #872 | IP 更新工單（2021-06-30，新增 `0.0.0.0`、`0.0.0.0`） |
| GitLab #868 | IP 更新工單（2021-06-25，新增 `0.0.0.0`） |

### 內部實作路徑

```
app/Services/Payment/Providers/PayEssence/
├── Driver.php              # 組合子類別
├── Deposit.php             # 代收（HTML Form POST）
├── DepositCallback.php     # 代收回調（雙 CheckString 驗簽）
├── Withdrawal.php          # 代付（B2B JSON）
├── WithdrawalCallback.php  # 代付回調
└── PayEssenceTrait.php     # 共用 HTTP / 簽名方法
```

---

*文件版本：1.0 / 整理日期：2026-06-01*
