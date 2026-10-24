# TopPay 印尼 API 整合文件（Laravel 對接用）

> **文件用途**：本份文件由 TopPay 官方文檔（`https://example.invalid/zh/reference/`）整理而成，僅保留印尼業務相關內容，並針對 Laravel + AI 對接情境重新組織。所有端點、欄位、錯誤碼皆來自官方文檔。
>
> **適用對象**：本份文件設計給 LLM（Claude Code、Codex 等）與工程師雙方閱讀。所有 schema 採表格格式以利機器解析，所有範例使用 PHP 8.1+ 與 Laravel 風格命名。
>
> **官方原文**：本文件章節末尾皆附上官方文檔 URL 來源，若有歧義請以官方為準。官方頁面沒有標示更新日期，內容以「整理日期」當天抓到的版本為準（見文末）。

---

## 目錄

1. [服務概覽與架構](#1-服務概覽與架構)
2. [環境資訊](#2-環境資訊)
3. [簽章規則與 PHP 實作](#3-簽章規則與-php-實作)
4. [共通請求／回應結構](#4-共通請求回應結構)
5. [API 端點明細](#5-api-端點明細)
   - 5.1 [收銀台支付（Pay-In）](#51-收銀台支付pay-in)
   - 5.2 [代收訂單查詢](#52-代收訂單查詢)
   - 5.3 [代付（Pay-Out / Disbursement）](#53-代付pay-out--disbursement)
   - 5.4 [代付訂單查詢](#54-代付訂單查詢)
   - 5.5 [餘額查詢](#55-餘額查詢)
   - 5.6 [代收 API 模式（transOrder）](#56-代收-api-模式transorder)
   - 5.7 [帳號校驗（bankVerify）](#57-帳號校驗bankverify)
6. [Webhook 回調處理](#6-webhook-回調處理)
7. [交易狀態碼](#7-交易狀態碼)
8. [錯誤碼對照表](#8-錯誤碼對照表)
9. [印尼支付通道對照表](#9-印尼支付通道對照表)

---

## 1. 服務概覽與架構

TopPay 是針對東南亞市場的支付閘道。整體服務由三大類 API 組成，分別是收款（代收 / Pay-In）、出款（代付 / Pay-Out）與查詢類（訂單查詢、餘額查詢）。所有 API 都採用 HTTP POST + JSON Body 的形式呼叫，請求與回應都必須包含 RSA 簽章欄位 `sign`。

各國業務用路徑上的國家碼區分（印尼是 `/id/`）。官方推薦所有國家共用統一網域 `example.invalid`；原本每國一個的國別網域（印尼是 `example.invalid`）仍可使用。在你寫 Laravel config 時，**網域和 country code 應該都做成可配置項**，這樣未來要換網域或擴到其他國家時不需要改程式碼。

| 項目 | 印尼設定值 |
|---|---|
| 國家 / 地區 | 印尼 Indonesia |
| Country Code | `id` |
| 網域（推薦） | `example.invalid`（所有國家共用） |
| 網域（相容） | `example.invalid`（印尼專用，仍可使用） |
| 結算幣別 | `IDR`（印尼盾，ISO 4217） |
| 路徑前綴（沙箱） | `/sandbox/id/` |
| 路徑前綴（生產） | `/id/` |
| 收款金額單位 | 整數，官方明寫印尼不允許小數，例如 `100000` = Rp 100,000 |

---

## 2. 環境資訊

TopPay 提供沙箱（Sandbox）與生產（Production）兩套完全獨立的環境，主要差別在於商戶號的開頭與路徑前綴。

### 2.1 沙箱環境（Sandbox）

沙箱用於對接測試，所有金流都是模擬的，不會產生真實扣款或撥款。沙箱環境的識別方式有兩個：第一是商戶號 `mchNo` 一定以 `SD` 開頭（例如商戶號 `123456`，沙箱版本就是 `SD123456`）；第二是 URL 路徑上會多一段 `/sandbox/`。

沙箱不會打真實的上游通道，代收、代付都是模擬結果，並會把回調事件推送到你設定的 `downNotifyUrl`，方便你測試 webhook 接收邏輯。要手動模擬時，可以在商戶後台的訂單詳情頁按「更改訂單狀態」「發送通知」，改變訂單狀態並觸發回調。代付聯調如果有 IP 白名單限制，要先確認我方出口 IP 已加入。

### 2.2 生產環境（Production）

生產環境的商戶號不帶 `SD` 前綴，URL 路徑也不帶 `/sandbox/`。上線前必須完成這幾件事：商戶資料審核通過、生產 RSA 公鑰已上傳到 TopPay 商戶後台、TopPay 平台公鑰已從後台取得、簽章流程已測通、`downNotifyUrl` 在公網可訪問、代付的出口 IP 已加入白名單（如有限制）。

### 2.3 環境對照速查

下面用推薦的統一網域。換成相容的國別網域時，只要把 `example.invalid` 換成 `example.invalid`，路徑不變。

```
沙箱 Pay-In（收銀台）:  https://example.invalid/sandbox/id/pay/prePay
生產 Pay-In（收銀台）:  https://example.invalid/id/pay/prePay

沙箱 Pay-In（API 模式）: https://example.invalid/sandbox/id/pay/transOrder
生產 Pay-In（API 模式）: https://example.invalid/id/pay/transOrder

沙箱 Pay-Out:       https://example.invalid/sandbox/id/disbursement/cash
生產 Pay-Out:       https://example.invalid/id/disbursement/cash

沙箱 訂單查詢:       https://example.invalid/sandbox/id/pay/query
生產 訂單查詢:       https://example.invalid/id/pay/query

沙箱 代付查詢:       https://example.invalid/sandbox/id/disbursement/query
生產 代付查詢:       https://example.invalid/id/disbursement/query

生產 帳號校驗:       https://example.invalid/id/disbursement/bankVerify（只有生產環境）

沙箱 餘額查詢:       https://example.invalid/sandbox/id/balance/v1
生產 餘額查詢:       https://example.invalid/id/balance/v1
```

> 來源：[沙箱環境](https://example.invalid/zh/reference/sandbox.html) / [生產環境](https://example.invalid/zh/reference/prod.html)

---

## 3. 簽章規則與 PHP 實作

簽章是 TopPay API 安全機制的核心，請求送出前必須先計算 `sign` 欄位放進 body，TopPay 收到後會用你的公鑰驗章。回調（webhook）也是同樣的機制，只是方向相反——TopPay 用自己的私鑰簽，你用 TopPay 的公鑰驗。

### 3.1 演算法規格

| 項目 | 規格 |
|---|---|
| 演算法 | RSA 非對稱加密簽章 |
| 金鑰格式 | PKCS#8，推薦 2048 bit；1024 bit 只有歷史商戶可以繼續用 |
| 編碼 | Base64（簽章輸出） |
| 字元編碼 | UTF-8 |
| 請求方簽章 | 商戶私鑰加密 → TopPay 公鑰解密驗證 |
| 回調方簽章 | TopPay 私鑰加密 → 商戶用 TopPay 公鑰解密驗證 |

> ⚠️ **注意**：TopPay 採用的不是標準 RSA 簽章（不是 SHA + PKCS#1 v1.5/PSS），而是直接用 RSA 加密來產生簽章。實作時必須用 `openssl_private_encrypt` 而非 `openssl_sign`，這點跟一般 RSA 簽章流程不同，務必小心。

### 3.2 簽章產生流程

簽章的計算分成三個步驟，每個步驟都很容易踩雷，建議一邊讀一邊對照下方 PHP 程式碼：

**步驟 1：把所有非空參數按 Key 的 ASCII 升序排序**

注意是「Key 排序」不是「值排序」，而且要排除 `sign` 本身、以及任何 `null` 或空字串值的欄位。排序使用 PHP 的 `SORT_STRING` 旗標。

**步驟 2：拼接成字串 StrA（只取值，不取 Key，不加分隔符）**

把排序後的欄位值依序串接，**不包含 Key**、**不加任何分隔符**（沒有 `=`、沒有 `&`、沒有逗號）。例如三個欄位 `amount=10000`、`mchNo=PHOT000001`、`method=GCASH` 排序後拼接結果是 `10000PHOT000001GCASH`。

> ⚠️ 數字型別在序列化時保持原樣，不要加引號。例如 JSON body 裡的 `amount: 10000` 進入 StrA 就是 `10000`，不是 `"10000"`。

**步驟 3：用商戶私鑰對 StrA 做 RSA 加密，輸出 Base64**

如果明文長度超過 RSA 區塊大小，要分塊加密後再串接（PKCS#1 padding 下 1024-bit 金鑰每塊最多 117 bytes，2048-bit 每塊最多 245 bytes）。

### 3.3 PHP 簽章工具類別（可直接放入 Laravel 專案）

下面這個 class 是把官方 PHP 範例重寫成 PSR-12 風格、加上型別宣告與註解後的版本，可以直接放到 `app/Services/TopPay/Support/Signer.php`：

```php
<?php

declare(strict_types=1);

namespace App\Services\TopPay\Support;

use RuntimeException;

/**
 * TopPay RSA 簽章工具
 *
 * 簽章演算法說明：
 * 1. 排除 null/空值欄位以及 sign 本身，將剩餘欄位的 Key 按 ASCII 升序排序
 * 2. 依排序順序串接 value（不含 Key，不加分隔符）得到 StrA
 * 3. 用商戶私鑰對 StrA 做 RSA 加密（分塊加密 + Base64）
 *
 * 注意：這不是標準 RSA 簽章流程，是用「私鑰加密 / 公鑰解密」來模擬簽章。
 */
final class Signer
{
    public function __construct(
        private readonly string $merchantPrivateKey,  // 商戶 RSA 私鑰（純 base64，不含 PEM 頭尾）
        private readonly string $platformPublicKey,   // TopPay 平台公鑰
    ) {
    }

    /**
     * 產生請求簽章。
     *
     * @param  array<string, mixed>  $payload  尚未包含 sign 的請求 body
     * @return string  Base64 編碼的簽章
     */
    public function sign(array $payload): string
    {
        // 1. 排除 sign / null / 空字串，取得排序後的拼接字串
        $strA = $this->buildStringToSign($payload);

        // 2. 載入私鑰（自動補上 PEM 頭尾）
        $privateKey = openssl_pkey_get_private($this->wrapPem($this->merchantPrivateKey, 'PRIVATE KEY'));
        if ($privateKey === false) {
            throw new RuntimeException('商戶私鑰格式錯誤，請確認為 PKCS#8 純 base64 內容');
        }

        // 3. 分塊加密（RSA 一次最多只能加密 keySize/8 - 11 bytes）
        $details = openssl_pkey_get_details($privateKey);
        $blockSize = ($details['bits'] / 8) - 11;

        $encrypted = '';
        foreach (str_split($strA, $blockSize) as $chunk) {
            if (! openssl_private_encrypt($chunk, $chunkEncrypted, $privateKey)) {
                throw new RuntimeException('RSA 加密失敗');
            }
            $encrypted .= $chunkEncrypted;
        }

        return base64_encode($encrypted);
    }

    /**
     * 驗證 TopPay 回調簽章。
     *
     * @param  array<string, mixed>  $payload  含 sign 的回調 body（會自動排除 sign）
     */
    public function verify(array $payload): bool
    {
        $sign = $payload['sign'] ?? null;
        if (! is_string($sign) || $sign === '') {
            return false;
        }

        $strA = $this->buildStringToSign($payload);

        $publicKey = openssl_pkey_get_public($this->wrapPem($this->platformPublicKey, 'PUBLIC KEY'));
        if ($publicKey === false) {
            return false;
        }

        $details = openssl_pkey_get_details($publicKey);
        $blockSize = $details['bits'] / 8;

        $encrypted = base64_decode($sign, true);
        if ($encrypted === false) {
            return false;
        }

        // 分塊解密還原原文
        $decrypted = '';
        foreach (str_split($encrypted, $blockSize) as $chunk) {
            if (! openssl_public_decrypt($chunk, $chunkDecrypted, $publicKey)) {
                return false;
            }
            $decrypted .= $chunkDecrypted;
        }

        // 用 hash_equals 防時序攻擊
        return hash_equals($strA, $decrypted);
    }

    /**
     * 排序並拼接出待簽字串 StrA。
     */
    private function buildStringToSign(array $payload): string
    {
        // 排除 sign 本身與 null / 空字串欄位
        $filtered = array_filter(
            $payload,
            fn ($value, $key) => $key !== 'sign' && $value !== null && $value !== '',
            ARRAY_FILTER_USE_BOTH,
        );

        // Key 按 ASCII 升序排序
        ksort($filtered, SORT_STRING);

        // 串接 value（不含 Key、不加分隔符）
        return implode('', array_map(
            fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v,
            $filtered,
        ));
    }

    /**
     * 把純 base64 金鑰包成標準 PEM 格式。
     */
    private function wrapPem(string $rawKey, string $label): string
    {
        $rawKey = preg_replace('/\s+/', '', $rawKey); // 清掉換行 / 空白

        return "-----BEGIN {$label}-----\n"
            . wordwrap($rawKey, 64, "\n", true)
            . "\n-----END {$label}-----\n";
    }
}
```

### 3.4 簽章常見問題

依照官方文檔整理的踩雷清單：

- **簽章驗證失敗（錯誤碼 0002）**：絕大多數原因是 `StrA` 拼接錯誤。檢查 Key 是否正確排序、是否漏掉欄位、是否誤把 `null` 或空字串拼進去。
- **時間戳異常**：`timestamp` 必須是當前毫秒級時間戳（13 位數），與伺服器時間誤差過大會被拒絕。建議用 NTP 同步機器時鐘。
- **金鑰格式錯誤**：金鑰必須是 PKCS#8 格式的純 base64 內容（一般工具產出的 `.pem` 檔需要去掉 `-----BEGIN/END-----` 行才是純 base64）。
- **null 值處理**：拼接時必須跳過值為 `null` 或空字串的欄位，連 Key 都不要排進去，否則簽章會對不上。
- **數字加引號問題**：JSON 序列化時數字保持數字，不要 cast 成字串。

> 來源：[簽章規則](https://example.invalid/zh/reference/signature.html)

---

## 4. 共通請求／回應結構

所有 TopPay 印尼 API 的請求都是 `POST + application/json`，HTTP 標頭只需要設定 `Content-Type: application/json`。請求 body 一律是扁平的 JSON 物件（除了 prePay/payout 的回應 data 是巢狀）。

### 4.1 共通請求欄位

每個端點的 request body 都會包含以下幾個共通欄位，再加上端點各自的業務欄位：

| 欄位 | 必填 | 型別 | 說明 |
|---|---|---|---|
| `mchNo` | M | String(32) | 商戶編號（沙箱以 `SD` 開頭） |
| `timestamp` | M | String/Number(13) | 毫秒級時間戳，例如 `1749451858772` |
| `sign` | M | String | RSA 簽章（最後一步計算，不參與簽章本身） |

### 4.2 共通回應結構

所有 API 的回應都採用統一的信封（envelope）結構，業務資料放在 `data` 物件裡：

| 欄位 | 必填 | 型別 | 說明 |
|---|---|---|---|
| `success` | M | Boolean | `true` 表示成功，`false` 表示失敗 |
| `code` | M | String | 業務狀態碼，**`9999`** 代表成功，其他見 [錯誤碼對照表](#8-錯誤碼對照表) |
| `msg` | O | String | 失敗時的錯誤訊息，成功時為 `null` |
| `timeStamp` | M | Number | 回應時間（毫秒） |
| `data` | M | Object | 業務資料物件，內容依端點而異 |

> ⚠️ HTTP 狀態碼 `200` 不代表業務成功。即使是業務失敗，TopPay 也會回 200 + JSON body，必須檢查 `code === "9999"` 才算真正成功。

### 4.3 範例：回應信封

```json
{
  "success": true,
  "code": "9999",
  "msg": null,
  "timeStamp": 1767873172906,
  "data": {
    "...": "業務資料"
  }
}
```

---

## 5. API 端點明細

下面五個端點是印尼業務的全部 API。每個端點都列出請求路徑、請求欄位、回應欄位與完整 JSON 範例。

### 5.1 收銀台支付（Pay-In）

這是用戶端發起付款時呼叫的端點。商戶把訂單資訊送過來，TopPay 回傳一個收銀台 URL（`cashierUrl`），商戶把使用者導去那個 URL 完成付款。付款結果之後會透過 webhook（`downNotifyUrl`）非同步通知。

代收有兩種下單方式。這節的收銀台模式是預設開通的；另一種 API 模式（直接拿到 VA 帳號或 QRIS 碼，不經收銀台）要先請客服把商戶加入白名單，見 [5.6](#56-代收-api-模式transorder)。

**請求路徑**

| 環境 | URL |
|---|---|
| 沙箱 | `POST https://example.invalid/sandbox/id/pay/prePay` |
| 生產 | `POST https://example.invalid/id/pay/prePay` |

**請求欄位**

| 欄位 | 必填 | 型別 | 說明 |
|---|---|---|---|
| `mchNo` | M | String(32) | 商戶編號 |
| `orderNum` | M | String(64) | 商戶訂單號（**商戶系統內必須唯一**） |
| `amount` | M | Number(32,8) | 訂單金額，印尼不允許小數，請傳整數 |
| `productDetail` | M | String(100) | 商品描述（UTF-8） |
| `method` | O | String(16) | 支付方式編碼，例如 `DANA`、`QRIS`、`OVO`，見 [印尼支付通道對照表](#9-印尼支付通道對照表)。不指定時由用戶在收銀台選擇 |
| `timestamp` | M | String(13) | 毫秒級時間戳 |
| `customerName` | M | String(64) | 客戶姓名 |
| `customerEmail` | M | String(64) | 客戶 Email |
| `customerPhone` | M | String(32) | 客戶手機號 |
| `expiryPeriod` | O | Number(1-9999) | 訂單有效期（分鐘），例如 1440（24 小時） |
| `downNotifyUrl` | M | String(255) | 異步回調 webhook URL（HTTP/HTTPS） |
| `redirectUrl` | O | String(512) | 支付成功後瀏覽器跳回的網址，必須是 `http://` 或 `https://` 開頭的絕對網址，規則見下方「成功回跳」 |
| `notifyVersion` | O | String(8) | 回調版本，`v1` 或 `v2`（不分大小寫），不傳就是 `v1`。傳 `v2` 時，成功回調可能多帶實際付款人資訊，見 [6.1](#61-pay-in-回調-body) |
| `sign` | M | String | RSA 簽章 |

**成功回跳（`redirectUrl`）**

這個功能預設關閉，要透過 Telegram 請客服開通，並由客服在後台設定回跳網址白名單（可以設多條）。開通後的規則：

- 只在支付成功時回跳；支付失敗或沒付完不回跳。
- 傳了合法的 `redirectUrl`，而且它的來源（origin，即協定＋網域＋埠號）在白名單內：原樣跳回這個網址，不會自動加上 `orderNum`。
- 沒傳、傳空值或傳不合法的網址：跳回白名單第一條，並自動在網址後面加上 `orderNum` 參數，例如 `https://example.invalid/pay/result?orderNum=ID1234561`。
- 傳了 `redirectUrl` 但來源不在白名單內：不回跳，也不會改跳白名單第一條。

**回應欄位（data 物件）**

| 欄位 | 必填 | 型別 | 說明 |
|---|---|---|---|
| `orderNum` | M | String | 商戶訂單號（原樣回傳） |
| `platOrderNum` | M | String | TopPay 平台訂單號（**全平台唯一**，建議存入 DB 以便對帳） |
| `amount` | M | Number | 訂單金額 |
| `fee` | M | Number | 手續費（IDR） |
| `method` | M | String | 支付方式編碼 |
| `productDetail` | M | String | 商品描述 |
| `customerName` | M | String | 客戶姓名 |
| `customerEmail` | M | String | 客戶 Email |
| `customerPhone` | M | String | 客戶手機號 |
| `validTime` | M | Number | 訂單失效時間（毫秒時間戳） |
| `cashierUrl` | M | String | 收銀台 URL（**直接導使用者過去**） |

收銀台模式的回應不含 `payData`／`payDataType`，這兩個欄位只在 API 模式出現（見 [5.6](#56-代收-api-模式transorder)）。

**請求範例**

```json
{
  "mchNo": "SD123456",
  "orderNum": "ID20251030001",
  "amount": 100000,
  "productDetail": "商品名稱",
  "method": "DANA",
  "timestamp": "1749451858772",
  "customerName": "Budi",
  "customerEmail": "user@example.invalid",
  "customerPhone": "081234567890",
  "expiryPeriod": 1440,
  "downNotifyUrl": "https://example.invalid/api/toppay/payin/notify",
  "redirectUrl": "https://example.invalid/checkout/return",
  "notifyVersion": "v2",
  "sign": "<RSA Base64 簽章>"
}
```

**回應範例**

```json
{
  "success": true,
  "code": "9999",
  "msg": null,
  "timeStamp": 1767840272829,
  "data": {
    "orderNum": "ID20251030001",
    "platOrderNum": "PRE2009093829059153920",
    "amount": 100000,
    "fee": 2000.0,
    "method": "DANA",
    "productDetail": "商品名稱",
    "customerName": "Budi",
    "customerEmail": "user@example.invalid",
    "customerPhone": "081234567890",
    "validTime": 1767926672819,
    "cashierUrl": "https://example.cashier/pay"
  }
}
```

> 來源：[印尼代收](https://example.invalid/zh/reference/transaction/payin-id.html)

### 5.2 代收訂單查詢

主動查詢一筆代收訂單的當前狀態。一般情境是 webhook 沒收到、或要做對帳時拿來補單。

**請求路徑**

| 環境 | URL |
|---|---|
| 沙箱 | `POST https://example.invalid/sandbox/id/pay/query` |
| 生產 | `POST https://example.invalid/id/pay/query` |

**請求欄位**

| 欄位 | 必填 | 型別 | 說明 |
|---|---|---|---|
| `mchNo` | M | String(32) | 商戶編號 |
| `orderNum` | C | String(64) | 商戶訂單號（與 `platOrderNum` **至少提供一個**） |
| `platOrderNum` | C | String(64) | 平台訂單號（與 `orderNum` 至少提供一個） |
| `timestamp` | M | Number(13) | 毫秒級時間戳 |
| `sign` | M | String | RSA 簽章 |

**回應欄位（data 物件）**

| 欄位 | 必填 | 型別 | 說明 |
|---|---|---|---|
| `orderNum` | M | String | 商戶訂單號 |
| `platOrderNum` | M | String | 平台訂單號 |
| `amount` | M | Number | 訂單金額 |
| `fee` | M | Number | 手續費 |
| `validTime` | M | Number | 訂單失效時間（毫秒時間戳） |
| `productDetail` | M | String | 商品描述 |
| `customerName` | M | String | 客戶姓名 |
| `customerEmail` | M | String | 客戶 Email |
| `customerPhone` | M | String | 客戶手機號 |
| `status` | M | String | 訂單狀態，見 [Pay-In 狀態](#71-pay-in代收狀態字串型) |
| `payerName` | O | String | 付款人姓名（成功後才有值；平台可能回英文原文） |
| `payerAccountNo` | O | String | 付款人帳號（成功後才有值） |
| `payerAccountBank` | O | String | 付款人銀行（成功後才有值） |

**回應範例**

```json
{
  "success": true,
  "code": "9999",
  "msg": null,
  "timeStamp": 1767873183510,
  "data": {
    "orderNum": "ID20251030001",
    "platOrderNum": "PRE2008115605537816576",
    "amount": 200.00,
    "fee": 0.00,
    "validTime": 1767697044000,
    "productDetail": "azel",
    "customerName": "azel",
    "customerEmail": "user@example.invalid",
    "customerPhone": "18121153777",
    "status": "INIT_ORDER"
  }
}
```

> 來源：[代收訂單查詢](https://example.invalid/zh/reference/transaction/payinQuery.html)

### 5.3 代付（Pay-Out / Disbursement）

把錢撥出去到指定銀行帳戶或電子錢包。注意這裡用的是 `bankCode`（不是 Pay-In 的 `method`），印尼支援的銀行清單很長，完整編碼見 [印尼代付銀行 bankCode](#92-印尼代付銀行-bankcode)。電子錢包也是用 `bankCode` 欄位（OVO 是 `10001`、DANA 是 `10002` 等）。

**請求路徑**

| 環境 | URL |
|---|---|
| 沙箱 | `POST https://example.invalid/sandbox/id/disbursement/cash` |
| 生產 | `POST https://example.invalid/id/disbursement/cash` |

**請求欄位**

| 欄位 | 必填 | 型別 | 說明 |
|---|---|---|---|
| `mchNo` | M | String(32) | 商戶編號 |
| `orderNum` | M | String(50) | 商戶訂單號（**唯一**） |
| `amount` | M | Number(32,8) | 撥款金額，最低 100 |
| `bankCode` | M | String(32) | 銀行 / 錢包編碼，例如 BCA = `014`、Mandiri = `008`、OVO = `10001` |
| `bankCard` | M | String(50) | 收款銀行帳號 / 錢包號（純數字） |
| `accountName` | M | String(50) | 收款人姓名（UTF-8） |
| `description` | O | String(255) | 備註（UTF-8） |
| `feeType` | M | Number(0/1) | 手續費類型：`0` = 從代付金額內扣（實際到帳 = amount - fee）；`1` = 手續費另計（實際到帳 = amount） |
| `downNotifyUrl` | M | String(164) | 異步回調 webhook URL |
| `timestamp` | M | String(13) | 毫秒級時間戳 |
| `sign` | M | String | RSA 簽章 |

**回應欄位（data 物件）**

| 欄位 | 必填 | 型別 | 說明 |
|---|---|---|---|
| `orderNum` | M | String | 商戶訂單號 |
| `platOrderNum` | M | String | 平台訂單號 |
| `amount` | M | Number | 撥款金額 |
| `fee` | M | Number | 手續費 |
| `feeType` | M | Number | 手續費承擔方（同請求） |
| `status` | M | Number | 狀態碼，見 [Pay-Out 狀態](#72-pay-out代付狀態數字型) |
| `statusMsg` | M | String | 狀態說明文字（**不要拿來判斷狀態，請看 status**） |
| `bankCode` | M | String | 銀行編碼 |
| `bankCard` | M | String | 收款帳號 |
| `accountName` | M | String | 收款人姓名 |
| `description` | O | String | 備註 |

> ⚠️ **回應的 `code` 不是 `9999` 時，官方明寫「不能直接把訂單當失敗處理，以免造成資金損失」，要依訂單狀態判斷**（用 [5.4 代付訂單查詢](#54-代付訂單查詢) 或等回調）。我方目前的做法見文末「我方實作與本文件的差異」。

**請求範例（撥款到 BCA 銀行）**

```json
{
  "mchNo": "SD123456",
  "orderNum": "IDPAYOUT20251030001",
  "amount": 100000,
  "bankCode": "014",
  "bankCard": "1234567890",
  "accountName": "Budi Santoso",
  "description": "salary payment",
  "feeType": 1,
  "downNotifyUrl": "https://example.invalid/api/toppay/payout/notify",
  "timestamp": "1749451858772",
  "sign": "<RSA Base64 簽章>"
}
```

**回應範例**

```json
{
  "success": true,
  "code": "9999",
  "msg": "SUCCESS",
  "timeStamp": 1767772957433,
  "data": {
    "orderNum": "IDPAYOUT20251030001",
    "platOrderNum": "TRANS2008811496544403456",
    "amount": 100000,
    "fee": 2000,
    "feeType": 1,
    "status": 1,
    "statusMsg": "ORDER_RECEIVED_PROCESSING",
    "bankCode": "014",
    "bankCard": "1234567890",
    "accountName": "Budi Santoso",
    "description": "salary payment"
  }
}
```

> 來源：[印尼代付](https://example.invalid/zh/reference/transaction/payout-id.html)

### 5.4 代付訂單查詢

跟代收查詢類似，主動查詢一筆代付訂單的狀態。

**請求路徑**

| 環境 | URL |
|---|---|
| 沙箱 | `POST https://example.invalid/sandbox/id/disbursement/query` |
| 生產 | `POST https://example.invalid/id/disbursement/query` |

**請求欄位**

| 欄位 | 必填 | 型別 | 說明 |
|---|---|---|---|
| `mchNo` | M | String(32) | 商戶編號 |
| `orderNum` | C | String(64) | 商戶訂單號（與 `platOrderNum` 至少提供一個） |
| `platOrderNum` | C | String(64) | 平台訂單號（與 `orderNum` 至少提供一個） |
| `timestamp` | M | Number(13) | 毫秒級時間戳 |
| `sign` | M | String | RSA 簽章 |

**回應欄位（data 物件）**

| 欄位 | 必填 | 型別 | 說明 |
|---|---|---|---|
| `orderNum` | M | String | 商戶訂單號 |
| `platOrderNum` | M | String | 平台訂單號 |
| `amount` | M | Number | 撥款金額 |
| `fee` | M | Number | 手續費 |
| `feeType` | M | Number | 手續費類型（`0` = 內扣，`1` = 外扣，同請求） |
| `status` | M | Number | 狀態碼，見 [Pay-Out 狀態](#72-pay-out代付狀態數字型) |
| `statusMsg` | M | String | 狀態說明文字（英文原文，例如 `supplement success!` 表示補單成功） |
| `bankCode` | M | String | 銀行編碼 |
| `bankCard` | M | String | 收款帳號 |
| `accountName` | M | String | 收款人姓名 |
| `description` | O | String | 備註 |

**回應範例**

```json
{
  "success": true,
  "code": "9999",
  "msg": null,
  "timeStamp": 1774876011669,
  "data": {
    "orderNum": "IDPAYOUT20251030001",
    "platOrderNum": "TRANS2037041938602917888",
    "amount": 10000.00000000,
    "fee": 2200.00000000,
    "feeType": 1,
    "status": 2,
    "statusMsg": "supplement success!",
    "bankCode": "008",
    "bankCard": "12340995811",
    "accountName": "Ujang",
    "description": "test"
  }
}
```

> 來源：[代付訂單查詢](https://example.invalid/zh/reference/transaction/payoutQuery.html)

### 5.5 餘額查詢

查詢商戶在 TopPay 平台上的餘額。回傳的金額分成五個維度，建議全部存下來方便對帳：總額 = 可用餘額 + 凍結餘額 + 待結算 + 凍結待結算。

**請求路徑**

| 環境 | URL |
|---|---|
| 沙箱 | `POST https://example.invalid/sandbox/id/balance/v1` |
| 生產 | `POST https://example.invalid/id/balance/v1` |

**請求欄位**

| 欄位 | 必填 | 型別 | 說明 |
|---|---|---|---|
| `mchNo` | M | String(32) | 商戶編號 |
| `currency` | M | String(32) | ISO 4217 幣別代碼，印尼填 `IDR` |
| `timestamp` | M | Number(13) | 毫秒級時間戳 |
| `sign` | M | String | RSA 簽章 |

**回應欄位（data 物件）**

| 欄位 | 必填 | 型別 | 說明 |
|---|---|---|---|
| `mchNo` | M | String | 商戶編號 |
| `currency` | M | String | 幣別 |
| `totalAmount` | M | Number | 總額（= 下面四個欄位的合計） |
| `balance` | M | Number | 可用餘額 |
| `freeze` | M | Number | 凍結餘額 |
| `waitingSettleAmount` | M | Number | 待結算金額 |
| `freezeWaitingSettleAmount` | M | Number | 凍結待結算金額 |

**回應範例**

```json
{
  "success": true,
  "code": "9999",
  "msg": null,
  "timeStamp": 1767873172906,
  "data": {
    "mchNo": "G1",
    "currency": "IDR",
    "totalAmount": 1875716820.00000000,
    "balance": 1875689559.48000000,
    "freeze": 26149.52000000,
    "waitingSettleAmount": 980.00000000,
    "freezeWaitingSettleAmount": 131.00000000
  }
}
```

> 來源：[餘額查詢](https://example.invalid/zh/reference/inquiry/inquiryBalance.html)

### 5.6 代收 API 模式（transOrder）

商戶在請求裡指定支付方式，TopPay 直接向支付通道下單，回應裡給 `payData`／`payDataType`（VA 帳號、QRIS 碼、跳轉連結等），使用者不必經過 TopPay 的收銀台。這個模式要先聯絡客服把商戶加入白名單才能用。我方目前只用收銀台模式（5.1）。

**請求路徑**

| 環境 | URL |
|---|---|
| 沙箱 | `POST https://example.invalid/sandbox/id/pay/transOrder` |
| 生產 | `POST https://example.invalid/id/pay/transOrder` |

**請求欄位**：跟 5.1 相同（含 `notifyVersion`），只差 `method` 變成必填。

**回應欄位（data 物件）**：5.1 的共同欄位之外，重點看下面三個：

| 欄位 | 必填 | 型別 | 說明 |
|---|---|---|---|
| `payData` | M | String | 支付資料：VA 帳號、QRIS 字串、跳轉連結等，意思由 `payDataType` 決定 |
| `payDataType` | M | String | 支付資料類型：`VA`、`QR_CODE`、`QR_URL`、`CASHIER_URL` |
| `cashierUrl` | O | String | 部分通道會一起回，API 模式以 `payData` 為準 |

| `payDataType` | 意思 | 常見 `method` |
|---|---|---|
| `VA` | 虛擬帳號，引導使用者轉帳到這個帳號 | BNI、BCA、MANDIRI、BRI 等銀行 VA |
| `QR_CODE` | QRIS 二維碼的內容字串，要自己畫成二維碼 | QRIS |
| `QR_URL` | 二維碼圖片或掃碼頁連結 | 部分 QRIS／錢包 |
| `CASHIER_URL` | 上游支付頁的跳轉連結 | DANA、OVO 等錢包 |

> 來源：[印尼代收](https://example.invalid/zh/reference/transaction/payin-id.html)（「API 模式」節）

### 5.7 帳號校驗（bankVerify）

檢查一個銀行帳號或電子錢包帳號是否有效，有效時回傳戶名。這是同步介面，一次請求就拿到最終結果，沒有回調。我方目前沒有使用。

- 只有生產環境，要用生產網域和生產商戶號。
- 同一個 `mchNo`＋`orderNum` 重複送會回錯誤，不會再校驗一次。
- 簽章規則跟代付下單相同。

**請求路徑**：`POST https://example.invalid/id/disbursement/bankVerify`

**請求欄位**

| 欄位 | 必填 | 型別 | 說明 |
|---|---|---|---|
| `mchNo` | M | String(32) | 商戶編號 |
| `orderNum` | M | String(64) | 商戶訂單號，必須唯一 |
| `bankCode` | M | String(32) | 銀行或錢包編碼，跟代付的 `bankCode` 相同 |
| `bankCard` | M | String(64) | 銀行帳號或錢包帳號 |
| `timestamp` | M | String(13) | 毫秒級時間戳 |
| `sign` | M | String | RSA 簽章 |
| `countryCode` | O | String(16) | 國家碼 |

**回應欄位（data 物件，失敗時可能是 `null`）**

| 欄位 | 必填 | 型別 | 說明 |
|---|---|---|---|
| `orderNum` | M | String | 商戶訂單號 |
| `platOrderNum` | M | String | 平台訂單號 |
| `accountName` | O | String | 校驗成功時回傳戶名（不遮罩） |
| `status` | M | Integer | 訂單狀態：`0` 處理中、`1` 處理成功、`2` 處理失敗、`3` 請求失敗 |
| `verifyResult` | O | Integer | 校驗結果：`1` 帳號有效（status=1）、`2` 帳號無效或跟戶名不符（status=1）、`3` 請求失敗（status=3） |

注意 `status=1` 只代表這次校驗跑完了，帳號有沒有效要看 `verifyResult`。回傳的戶名是未遮罩的個資，寫進 log 前要先遮罩。

> 來源：[帳號校驗－印尼](https://example.invalid/zh/reference/transaction/bankVerify-id.html)

---

## 6. Webhook 回調處理

當訂單狀態有變動時（付款成功、付款失敗、撥款成功等），TopPay 會主動 POST JSON 到你註冊的 `downNotifyUrl`。回調處理是整個對接流程裡最容易出錯的地方，請務必嚴格遵守下面三個原則：第一是**先驗章再做任何事**；第二是**回應必須是純文字 `SUCCESS`**（不是 JSON）；第三是**做冪等處理**，因為 TopPay 可能重複推送。

> ⚠️ **新手必讀**：對接 callback 之前**先看 §6.5**——TopPay 簽章端與傳送端的數字格式不一致，直接用收到的原始 body 驗章一定 `0002` 失敗。

### 6.1 Pay-In 回調 body

| 欄位 | 必填 | 型別 | 說明 |
|---|---|---|---|
| `platOrderNum` | M | String | 平台訂單號 |
| `version` | M | String | 回調版本，跟下單時的 `notifyVersion` 一致（`v1` 或 `v2`） |
| `orderNum` | M | String | 商戶訂單號 |
| `amount` | M | Number | 訂單金額 |
| `fee` | M | Number | 手續費 |
| `customerName` | M | String | 客戶姓名（下單時填的，不是實際付款帳戶的戶名） |
| `customerEmail` | M | String | 客戶 Email |
| `customerPhone` | M | String | 客戶手機號 |
| `status` | M | String | Pay-In 狀態（`SUCCESS`/`PAY_ERROR`/...）|
| `sign` | M | String | RSA 簽章（用 TopPay 公鑰驗證） |

下單時傳 `notifyVersion=v2`，而且 `status` 是 `SUCCESS` 時，如果上游通道有回傳付款人資訊，回調可能多帶下面三個欄位。沒有資料時不帶或是空值，失敗回調的結構跟 v1 相同。

| 欄位 | 必填 | 型別 | 說明 |
|---|---|---|---|
| `payerName` | O | String | 實際付款帳戶的戶名（可能跟 `customerName` 不同） |
| `payerAccountNo` | O | String | 實際付款帳號 |
| `payerAccountBank` | O | String | 實際付款的銀行或通道名稱 |

### 6.2 Pay-Out 回調 body

| 欄位 | 必填 | 型別 | 說明 |
|---|---|---|---|
| `platOrderNum` | M | String | 平台訂單號 |
| `version` | M | String | 版本號 `v1` |
| `orderNum` | M | String | 商戶訂單號 |
| `amount` | M | Number | 撥款金額 |
| `fee` | M | Number | 手續費 |
| `feeType` | M | Number | 手續費類型（`0` = 內扣，`1` = 外扣） |
| `status` | M | Number | Pay-Out 狀態碼，見 [7.2](#72-pay-out代付狀態數字型) |
| `statusMsg` | M | String | 狀態說明 |
| `bankCode` | M | String | 銀行編碼 |
| `bankCard` | M | String | 收款帳號 |
| `accountName` | M | String | 收款人 |
| `description` | O | String | 備註 |
| `sign` | M | String | RSA 簽章 |

### 6.3 回應規則

收到 webhook 後，無論成功失敗，**HTTP 回應 body 必須是純文字 `SUCCESS`**（不是 `{"success":true}` 也不是其他 JSON）。否則 TopPay 會視為發送失敗並持續重試。

### 6.4 Laravel Controller 範例

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhook;

use App\Services\TopPay\Support\Signer;
use App\Services\TopPay\PayInWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class TopPayPayInWebhookController
{
    public function __construct(
        private readonly Signer $signer,
        private readonly PayInWebhookHandler $handler,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $payload = $request->json()->all();

        // 1. 驗章（驗章失敗也要回 SUCCESS 嗎？不，要回非 SUCCESS 讓對方重試）
        if (! $this->signer->verify($payload)) {
            Log::warning('TopPay webhook 驗章失敗', ['payload' => $payload]);
            return response('SIGN_INVALID', 400);
        }

        // 2. 冪等處理 + 業務邏輯（內部用 platOrderNum 做 unique key）
        try {
            $this->handler->handle($payload);
        } catch (Throwable $e) {
            Log::error('TopPay webhook 處理失敗', [
                'payload' => $payload,
                'error' => $e->getMessage(),
            ]);
            // 回非 SUCCESS 讓 TopPay 重試
            return response('INTERNAL_ERROR', 500);
        }

        // 3. 一律回純文字 SUCCESS
        return response('SUCCESS', 200);
    }
}
```

### 6.5 ⚠️ 已知坑：簽章端與傳送端的數字格式不一致

> 本節為**官方文檔未載明的實測結論**，由實際打通沙箱 callback 時拿平台公鑰解 sign 反推得出。對接前務必先讀。

**現象**：Pay-In / Pay-Out 回調的 `sign` **不是針對 JSON body 中的數值**簽的，而是針對「金額類欄位轉成 2 位小數」的版本簽的。直接用收到的原始資料驗章會**永遠 `0002` 失敗**。

**實測對照**（已用平台公鑰解密實際 callback 的 sign 還原 StrA 證實）：

| 欄位 | JSON body 收到 | 簽章端實際拼進 StrA |
|---|---|---|
| `amount` | `10000` | `"10000.00"` |
| `fee` | `100` | `"100.00"` |
| 其餘 String / 整數欄位（status / feeType / orderNum…） | 原樣 | 原樣 |

**驗章前的補正**（PHP）：

```php
foreach (['amount', 'fee'] as $field) {
    if (isset($payload[$field]) && is_numeric($payload[$field])) {
        // 推薦：bcmath，避免 float 精度問題
        $payload[$field] = Common::numAdd('0', (string) $payload[$field], 2);
        // 或：number_format((float) $payload[$field], 2, '.', '')
    }
}

// 補正後再丟給標準 verifySign 驗章
return $this->verifySign($payload);
```

**為什麼這不是「擅自修飾資料」**

嚴格來說，簽章驗證應該用「收到的 bytes」直接拼接 StrA——把 `1000` 改成 `1000.00` 才驗得過，等於在替對方擦屁股。會出現此補正只因 **TopPay 簽章端與傳送端格式不對齊**（屬其 API 設計缺陷）：

```
TopPay 簽章：over "10000.00"   ← 解 sign 解出來的真相
TopPay 傳送：send 10000        ← JSON 實際欄位
```

一旦對方修正成「簽什麼就送什麼」，本補正即可移除。

**踩雷症狀速查**

| 症狀 | 對應原因 |
|---|---|
| 我方 Pay-In 請求發出，TopPay 全部驗章 OK | 請求方向兩端格式一致（皆整數） |
| TopPay 打回的 callback 我方驗章一律 `0002` 失敗 | callback 方向兩端格式不一致，需補 `.00` |
| 解 sign 還原出的 StrA 比預期多了 `.00` 字串 | 鐵證——本節描述的格式錯位 |

> 來源：[印尼代收 - 通知](https://example.invalid/zh/reference/transaction/payin-id.html) / [印尼代付 - 通知](https://example.invalid/zh/reference/transaction/payout-id.html)
> 補充：本書 §3.2 步驟 2 號稱「拼接時保留原樣」，但回調方向實測與此規則不符。

---

## 7. 交易狀態碼

Pay-In 跟 Pay-Out 的狀態碼是**完全不同類型**，這是 TopPay API 設計上比較不一致的地方，實作時要特別小心：Pay-In 的 `status` 是字串（例如 `SUCCESS`），Pay-Out 的 `status` 是數字（例如 `2`）。建議在 PHP 端各自做成 Enum，避免拼錯字串或數字搞混。

### 7.1 Pay-In（代收）狀態（字串型）

| status 值 | 含義 | 終態？ |
|---|---|---|
| `INIT_ORDER` | 訂單初始化 | 否 |
| `NO_PAY` | 未支付 | 否 |
| `SUCCESS` | 支付成功 | **是** |
| `PAY_CANCEL` | 撤銷 | **是** |
| `PAY_ERROR` | 支付失敗 | **是** |

官方註明部分介面可能出現上表以外的字串狀態，以實際回傳為準。

### 7.2 Pay-Out（代付）狀態（數字型）

官方的重要提示：**只有 `2`、`3`、`4` 是終態，其他狀態一律是處理中，不可直接把訂單當失敗處理。**

| status 值 | 含義 | 終態？ |
|---|---|---|
| `99` | 已建立 | 否 |
| `0` | 待處理 | 否 |
| `1` | 已受理 | 否 |
| `2` | 代付成功 | **是**（成功） |
| `3` | 退單 | **是**（失敗） |
| `4` | 代付失敗 | **是**（失敗） |
| `5` | 銀行代付中 | 否 |

> ⚠️ `statusMsg` 欄位（例如 `ORDER_RECEIVED_PROCESSING`、`supplement success!`）僅供人類閱讀，**不要拿來做程式判斷**，永遠以 `status` 為準。
>
> ⚠️ 我方回調程式對 `4`、`5` 的判斷跟上表不同，見文末「我方實作與本文件的差異」。

> 來源：[交易狀態碼](https://example.invalid/zh/reference/transactionStatus.html)

---

## 8. 錯誤碼對照表

回應中的 `code` 欄位是業務狀態碼，`9999` 表示成功，其他都是錯誤。

### 8.1 通用

| 錯誤碼 | 英文訊息 | 中文說明 | 處理建議 |
|---|---|---|---|
| `9999` | SUCCESS | 成功 | 正常處理 |
| `0000` | unknown exception | 未知異常 | 記下請求與回應全文後重試；持續出現就聯絡技術支援 |

### 8.2 參數 / 業務校驗（0001–0010）

| 錯誤碼 | 英文訊息 | 中文說明 | 處理建議 |
|---|---|---|---|
| `0001` | Parameter exception! | 參數異常 | 檢查必填欄位、型別、長度 |
| `0002` | signature verification failed! | 簽章驗證失敗 | 檢查 `sign` 計算流程，重新驗證金鑰格式 |
| `0003` | mch no channel available! | 無可用通道 | 先檢查訂單金額是否合規，再請運營確認商戶是否已開通該通道 |
| `0004` | request duplicate! | 請求重複 | 用商戶訂單號去重，避免短時間內重複送出 |
| `0005` | order abnormality! | 訂單異常 | 先查訂單詳情與狀態，不要盲目重試 |
| `0006` | the order does not exist! | 訂單不存在 | 檢查 `orderNum` / `platOrderNum`，以及是否跨環境（沙箱查生產或反過來） |
| `0007` | Order payment method has been selected! | 已選擇支付方式 | 不能再換通道；照目前方式繼續，或關單後重新下單 |
| `0008` | Order status abnormal! | 訂單狀態異常 | 先查單確認狀態，只在允許的狀態下操作 |
| `0009` | There is no payment method available for the order! | 無可用支付方式 | 檢查商戶與訂單是否支援該支付方式與地區設定 |
| `0010` | the bank does not exist! | 銀行不存在 | 檢查 `bankCode` 是否在 [9.2](#92-印尼代付銀行-bankcode) 的清單中 |

### 8.3 風控與其他（1000–1003）

| 錯誤碼 | 英文訊息 | 中文說明 | 處理建議 |
|---|---|---|---|
| `1000` | other exception! | 其他異常 | 聯絡客服協助排查 |
| `1001` | ip whitelist exception! | IP 白名單異常 | 確認對接 IP 已加白名單 |
| `1002` | Insufficient balance! | 餘額不足 | 商戶帳戶餘額不足，需充值 |
| `1003` | amount exception | 金額異常 | 檢查金額單位、最低／最高限額、小數位與幣別規則 |

### 8.4 錯誤回應範例

```json
{
  "code": "0002",
  "message": "signature verification failed!",
  "data": null
}
```

> 來源：[異常碼](https://example.invalid/zh/reference/exceptionCode.html)

---

## 9. 印尼支付通道對照表

### 9.1 印尼代收 method 編碼（用於 Pay-In `prePay`）

這 19 個 `method` 涵蓋印尼主要的支付方式（電子錢包、銀行轉帳、超商、QR Code），實際可用清單依商戶開通狀態而定。

| method | 名稱 | 類型 |
|---|---|---|
| `ALFAMART` | Alfamart 超商付款 | 超商 |
| `BCA` | BCA 銀行 | 銀行 |
| `BNI` | BNI 銀行 | 銀行 |
| `BRI` | BRI 銀行 | 銀行 |
| `BSS` | BSS 銀行 | 銀行 |
| `CIMB` | CIMB 銀行 | 銀行 |
| `DANA` | DANA 錢包 | 電子錢包 |
| `DANA_2` | DANA 錢包（官方標「偽原生」） | 電子錢包 |
| `DANAMON` | Danamon 銀行 | 銀行 |
| `GOPAY_QRIS` | GoPay（透過 QRIS） | 電子錢包 |
| `LINKAJA` | LINKAJA 錢包 | 電子錢包 |
| `MANDIRI` | Mandiri 銀行 | 銀行 |
| `MAYBANK` | Maybank 銀行 | 銀行 |
| `OVO` | OVO 錢包 | 電子錢包 |
| `PERMATA` | Permata 銀行 | 銀行 |
| `QRIS` | QRIS 通用 QR | 通用 QR |
| `SHOPEEPAY` | ShopeePay | 電子錢包 |
| `TRANSFER_BCA` | BCA 轉帳 | 轉帳 |
| `TRANSFER_BRI` | BRI 轉帳 | 轉帳 |

### 9.2 印尼代付銀行 bankCode

代付（disbursement）使用 `bankCode` 而不是 `method`，帳號校驗（5.7）也用同一套編碼。下表照官方「代付銀行編碼」原樣轉錄，順序與名稱都不改（名稱的大小寫與底線是官方原文）。

官方共 234 個編碼。「我方碼表」欄標示 `app/Console/Commands/CashFlow/Data/providers/TopPay.php` 有沒有收這個碼：有 180 個、沒有 54 個。我方碼表的每個碼都在官方清單裡。

讀這張表要注意：

- **同一家銀行可能有好幾個碼。** 官方清單新舊碼並存、不下架，例如 CCB Indonesia 有 `9490`（BANK CHINA CONSTRUCTION）、`088`（CCB Indonesia）、`036`（BANK_CCB_INDONESIA）三個碼，它的前身 Multicor（`10006`）、Antardaerah（`1163`）、Windu Kentjana（`162`）也各有一個碼。哪個碼實際可用，官方沒寫，要問 TopPay。
- **名稱相近不代表同一家。** `949` CTBC Indonesia 是中國信託的印尼子行，`069` BANK OF CHINA LIMITED 是中國銀行，兩家都跟 CCB Indonesia 無關。
- **伊斯蘭業務單位（UUS）也有兩套碼**，例如 Kalbar UUS 有 `1230` 和 `727`、Kaltim UUS 有 `1240` 和 `729`。
- **`10011`～`10019` 是 VA 碼（虛擬帳號）**，不是會員自己的存款帳戶。
- 電子錢包的碼另列在 9.3。

| bankCode | 官方 bankName | 我方碼表 |
|---|---|---|
| `116` | Bank Aceh Syariah | 有 |
| `1160` | Bank Agris UUS | 有 |
| `1161` | BPD ISTIMEWA ACEH SYARIAH | 有 |
| `945` | Bank IBK Indonesia | 有 |
| `1162` | BANK AMAR INDONESIA | 有 |
| `494` | Bank Agroniaga | 有 |
| `466` | Bank Andara | 有 |
| `531` | Anglomas International Bank | 有 |
| `1163` | BANK ANTAR DAERAH | 有 |
| `061` | Bank ANZ Indonesia | 有 |
| `0610` | Bank ANZ PANIN | 有 |
| `987` | ARTAJASA PEMBAYARAN ELEKTRONIK | 有 |
| `020` | Bank Arta Niaga Kencana | 有 |
| `037` | Bank Artha Graha Internasional | 有 |
| `542` | Bank ARTOS/ Bank Jago | 有 |
| `129` | BPD Bali | 有 |
| `459` | Bank Bisnis Internasional | 有 |
| `040` | Bangkok Bank | 有 |
| `558` | BPD Banten | 有 |
| `525` | BANK BARCLAYS INDONESIA | 有 |
| `014` | Bank Central Asia (BCA) | 有 |
| `536` | Bank Central Asia (BCA) Syariah | 有 |
| `133` | Bank Bengkulu | 有 |
| `110` | Bank Jawa Barat(BJB) | 有 |
| `425` | Bank BJB Syariah | 有 |
| `009` | Bank Negara Indonesia(BNI) | 有 |
| `427` | Bank BNI Syariah | 有 |
| `069` | BANK OF CHINA LIMITED | 有 |
| `002` | Bank Rakyat Indonesia(BRI) | 有 |
| `422` | Bank BRI Syariah | 有 |
| `1450` | Bank BNP Paribas | 有 |
| `033` | BANK OF AMERICA NA | 有 |
| `88` | BPR KS | — |
| `4510` | Bank Syariah Indonesia(BSI) | 有 |
| `200` | Bank Tabungan Negara (BTN) | 有 |
| `2000` | Bank Tabungan Negara (BTN) UUS | 有 |
| `213` | Bank BTPN | 有 |
| `5470` | BTPN Syariah | — |
| `547` | Bank BTPN Syariah | 有 |
| `441` | Wokee/Bukopin | 有 |
| `521` | Bank Bukopin Syariah | 有 |
| `076` | Bank Bumi Arta | 有 |
| `4850` | BANK BUMIPUTERA | 有 |
| `054` | Bank Capital Indonesia | 有 |
| `5590` | BANK CENTRATAMA | 有 |
| `9490` | BANK CHINA CONSTRUCTION | 有 |
| `949` | CTBC Indonesia | 有 |
| `559` | Centratama Nasional Bank(CNB) | 有 |
| `022` | Bank CIMB Niaga | 有 |
| `0220` | Bank CIMB Niaga UUS | 有 |
| `0221` | Bank CIMB Niaga REKENING PONSEL | 有 |
| `031` | Citibank | 有 |
| `950` | Bank Commonwealth | 有 |
| `112` | BPD DIY | 有 |
| `1121` | BANK PEMBANGUNAN DAERAH DIY UNIT USAHA SYARIAH | 有 |
| `011` | Bank Danamon | 有 |
| `0110` | Bank Danamon UUS | 有 |
| `046` | Bank DBS Indonesia | 有 |
| `067` | Deutsche Bank | 有 |
| `526` | Bank Dinar Indonesia | 有 |
| `5230` | BANK DIPO INTERNATIONAL | 有 |
| `111` | Bank DKI | 有 |
| `778` | Bank DKI UUS | 有 |
| `699` | Bank EKA | 有 |
| `087` | BANK EKONOMI RAHARJA | 有 |
| `562` | Bank Fama International | 有 |
| `161` | Bank Ganesha | 有 |
| `484` | LINE Bank/KEB Hana | 有 |
| `567` | Allo Bank/Bank Harda Internasional | 有 |
| `2120` | Bank Himpunan Saudara 1906 | 有 |
| `041` | HSBC | 有 |
| `164` | Bank ICBC Indonesia | 有 |
| `513` | Bank Ina Perdana | 有 |
| `555` | Bank Index Selindo | 有 |
| `146` | Bank of India Indonesia | 有 |
| `5421` | BANK JAGO TBK | 有 |
| `115` | Bank Jambi | 有 |
| `472` | Bank Jasa Jakarta | 有 |
| `113` | Bank Jateng | 有 |
| `1130` | BPD JAWA TENGAH UNIT USAHA SYARIAH | 有 |
| `114` | Bank Jatim | 有 |
| `1140` | BPD Jawa Timur | 有 |
| `1141` | Bank Jatim UUS | 有 |
| `032` | JPMORGAN CHASE BANK | 有 |
| `095` | Bank JTrust Indonesia | 有 |
| `123` | BPD Kalimantan Barat/Kalbar | 有 |
| `1230` | BPD Kalimantan Barat UUS | 有 |
| `122` | BPD Kalimantan Selatan/Kalsel | 有 |
| `1220` | BPD Kalimantan Selatan UUS | 有 |
| `125` | BPD Kalimantan Tengah (Kalteng) | 有 |
| `124` | BPD Kalimantan Timur | 有 |
| `1240` | BPD Kalimantan Timur UUS | 有 |
| `535` | Seabank/Bank Kesejahteraan Ekonomi(BKE) | 有 |
| `121` | BPD Lampung | 有 |
| `131` | Bank Maluku | 有 |
| `008` | Bank Mandiri | 有 |
| `451` | Bank Syariah Mandiri | 有 |
| `5640` | Bank Mandiri Taspen Pos | 有 |
| `564` | Bank MANTAP | 有 |
| `548` | Bank Multi Arta Sentosa(MAS) | 有 |
| `157` | Bank Maspion Indonesia | 有 |
| `097` | Bank Mayapada | 有 |
| `016` | Bank Maybank | 有 |
| `947` | Bank Maybank Syariah Indonesia | 有 |
| `0160` | Bank Maybank Syariah Indonesia UUS | 有 |
| `553` | Bank Mayora Indonesia | 有 |
| `426` | Bank Mega | 有 |
| `506` | Bank Mega Syariah | 有 |
| `151` | Bank Mestika Dharma | 有 |
| `1520` | BANK METRO EXPRESS | 有 |
| `485` | Motion/Bank MNC Internasional | 有 |
| `147` | Bank Muamalat Indonesia | 有 |
| `491` | Bank Mitra Niaga | 有 |
| `048` | Bank Mizuho Indonesia | 有 |
| `10010` | Bank MUTIARA | 有 |
| `10006` | Bank MULTICOR | 有 |
| `503` | Bank National Nobu | 有 |
| `583` | BANK NIAGA TBK. SYARIAH | 有 |
| `128` | BPD Nusa Tenggara Barat(NTB) | 有 |
| `1280` | BPD Nusa Tenggara Barat (NTB) UUS | 有 |
| `130` | BPD Nusa Tenggara Timur(NTT) | 有 |
| `145` | Bank Nusantara Parahyangan | 有 |
| `028` | Bank OCBC NISP | 有 |
| `0280` | Bank OCBC NISP UUS | 有 |
| `019` | Bank Panin | 有 |
| `517` | Panin Dubai Syariah | 有 |
| `132` | Bank Papua | 有 |
| `013` | Bank Permata | 有 |
| `0130` | Bank Permata UUS | 有 |
| `520` | Bank Prima Master | 有 |
| `584` | BANK PUNDI INDONESIA | 有 |
| `167` | QNB KESAWAN | 有 |
| `1670` | QNB Indonesia | 有 |
| `5260` | Bank Oke Indonesia | 有 |
| `089` | Rabobank International Indonesia | 有 |
| `047` | Bank Resona Perdania | 有 |
| `119` | BPD Riau Dan Kepri | 有 |
| `1190` | BPD Riau Dan Kepri UUS | 有 |
| `5010` | Blu/BCA Digital | 有 |
| `5471` | BANK PURBA DANARTA | 有 |
| `523` | Bank Sahabat Sampoerna | 有 |
| `498` | Bank SBI Indonesia | 有 |
| `152` | Bank Shinhan Indonesia | 有 |
| `153` | Bank Sinarmas | 有 |
| `1530` | Bank Sinarmas UUS | 有 |
| `050` | Standard Chartered Bank | 有 |
| `134` | Bank Sulteng | 有 |
| `135` | Bank Sultra | 有 |
| `126` | Bank Sulselbar | 有 |
| `1260` | Bank Sulselbar UUS | 有 |
| `127` | BPD Sulawesi Utara(SulutGo) | 有 |
| `118` | BPD Sumatera Barat | 有 |
| `1180` | BPD Sumatera Barat UUS | 有 |
| `1181` | BANK NAGARI | 有 |
| `120` | BPD Sumsel Babel | 有 |
| `1200` | Bank Sumsel Babel | 有 |
| `1201` | Bank Sumsel Dan Babel UUS | 有 |
| `117` | Bank Sumut | 有 |
| `1170` | Bank Sumut UUS | 有 |
| `045` | Bank Sumitomo Mitsui Indonesia | 有 |
| `042` | Bank of Tokyo | 有 |
| `023` | TMRW/Bank UOB Indonesia | 有 |
| `566` | Bank Victoria International | 有 |
| `405` | Bank Victoria Syariah | 有 |
| `212` | Bank Woori Saudara | 有 |
| `490` | Neo Commerce/Bank Yudha Bhakti(BNC) | 有 |
| `1120` | BPD_Daerah_Istimewa_Yogyakarta_(DIY) | — |
| `088` | CCB Indonesia | 有 |
| `501` | Royal Bank of Scotland (RBS) | 有 |
| `10001` | OVO | 有 |
| `10002` | DANA | 有 |
| `10003` | GOPAY | 有 |
| `10008` | SHOPEEPAY | 有 |
| `10009` | LINKAJA | 有 |
| `003` | Bank_Ekspor_Indonesia | — |
| `026` | Bank_Lippo | — |
| `030` | American_Express_Bank_LTD | — |
| `034` | ING_Indonesia_Bank | — |
| `036` | BANK_CCB_INDONESIA | — |
| `039` | Bank_Credit_Agricole_Indosuez | — |
| `052` | Bank_ABN_Amro | — |
| `053` | Bank_Keppel_Tatlee_Buana | — |
| `057` | Bank_BNP_Paribas_Indonesia | — |
| `059` | Korea_Exchange_Bank_Danamon | — |
| `060` | Bank Rabobank International Indonesia | — |
| `068` | Bank_Woori_Indonesia | — |
| `093` | Bank_IFI | — |
| `137` | Bank_BANTEN | — |
| `139` | Permata Syariah | — |
| `159` | Bank_Hagakita | — |
| `162` | Bank_Windu_Kentjana | — |
| `166` | Bank_Harmoni_International | — |
| `220` | Bank CIMB Niaga UUS | — |
| `280` | Bank OCBC NISP UUS | — |
| `333` | Koperasi_Intidana | — |
| `387` | PT Bank Aladin Syariah Tbk | — |
| `600` | BPR/LSB | — |
| `688` | BPR_KS | 有 |
| `721` | BANK PERMATA UNIT USAHA SYARIAH | — |
| `722` | DANAMON UNIT USAHA SYARIAH | — |
| `723` | BANK TABUNGAN NEGARA UNIT USAHA SYARIAH | — |
| `724` | BANK DKI UUS | — |
| `725` | BPD JAWA TENGAH UUS | — |
| `727` | PT BANK PEMBANGUNAN DAERAH KALBAR - UUS | — |
| `728` | PT BANK PEMBANGUNAN DAERAH KAM SEL - UUS | — |
| `729` | BPD KALIMANTAN TIMUR DAN KALIMANTAN UTARA UUS | — |
| `733` | BANK SULSELBAR UUS | — |
| `734` | BANK SINARMAS - UUS | — |
| `736` | PT BANK BPD DIY - UUS | — |
| `770` | ALTO_Pay | — |
| `775` | SGDO_Pay | — |
| `777` | Finnet | — |
| `789` | Indosat_Dompetku | — |
| `808` | XL TUNAI | — |
| `867` | Bank_Eka_Bumi_Artha | — |
| `886` | ALTOPay | — |
| `888` | ISO | — |
| `898` | TELKOM | — |
| `899` | Doku | — |
| `911` | Link_Aja | — |
| `920` | ATMi | — |
| `921` | ATMI - EN | — |
| `946` | Bank_Merincorp | — |
| `948` | Bank_OCBC_–_Indonesia | — |
| `10007` | NATIONALNOBU | — |
| `10011` | VA_BRI | 有 |
| `10012` | VA_MANDIRI | 有 |
| `10013` | VA_CIMB | 有 |
| `10014` | VA_PERMATA | 有 |
| `10015` | VA_BNI | 有 |
| `10016` | VA_BCA | 有 |
| `10017` | VA_DANAMON | 有 |
| `10018` | VA_BSI | 有 |
| `10019` | VA_BNC | — |

### 9.3 電子錢包編碼（代付用）

電子錢包在 Pay-Out 時也是用 `bankCode` 欄位填入：

| bankCode | 錢包名稱 |
|---|---|
| `10001` | OVO |
| `10002` | DANA |
| `10003` | GOPAY |
| `10008` | SHOPEEPAY |
| `10009` | LINKAJA |

> 來源：[印尼支付通道](https://example.invalid/zh/reference/paymentMethodIndonesia.html)

## 我方實作與本文件的差異

這節列出 `app/Services/Payment/Providers/TopPay/` 現在的寫法跟官方文件對不上的地方。程式修正後，要把對應的條目刪掉。

| 項目 | 官方文件 | 我方現在 | 影響 |
|---|---|---|---|
| 代付回調的狀態判斷（`WithdrawalCallback.php`） | `2` 成功；`3`、`4` 失敗；`99`、`0`、`1`、`5` 處理中 | `2` 成功；`3`、`5` 失敗；其他當處理中 | 收到 `5`（銀行代付中）會被當成失敗，訂單轉第三方失敗（29）；收到 `4`（代付失敗）會一直停在處理中 |
| 代付下單回應不是 `9999`（`Withdrawal.php`） | 不能直接當失敗，要依訂單狀態判斷 | `code !== '9999'` 就當成下單失敗 | 對方實際已受理、卻回非 `9999` 時，訂單會被當成失敗 |
| 網域 | 推薦統一網域 `example.invalid` | 依商戶設定 `API_URL` 呼叫；Alfawin77 兩筆 TopPay 商戶設定都是 `https://example.invalid/id`（國別網域） | 官方說國別網域仍可用，暫時沒有影響 |

## 附錄：原始官方文檔來源

| 章節 | 官方 URL |
|---|---|
| 快速開始 | https://example.invalid/zh/reference/ |
| 沙箱環境 | https://example.invalid/zh/reference/sandbox.html |
| 生產環境 | https://example.invalid/zh/reference/prod.html |
| 簽章規則 | https://example.invalid/zh/reference/signature.html |
| 印尼支付通道 | https://example.invalid/zh/reference/paymentMethodIndonesia.html |
| 印尼代收 | https://example.invalid/zh/reference/transaction/payin-id.html |
| 印尼代付 | https://example.invalid/zh/reference/transaction/payout-id.html |
| 代收訂單查詢 | https://example.invalid/zh/reference/transaction/payinQuery.html |
| 代付訂單查詢 | https://example.invalid/zh/reference/transaction/payoutQuery.html |
| 餘額查詢 | https://example.invalid/zh/reference/inquiry/inquiryBalance.html |
| 交易狀態碼 | https://example.invalid/zh/reference/transactionStatus.html |
| 異常碼 | https://example.invalid/zh/reference/exceptionCode.html |
| 帳號校驗－印尼 | https://example.invalid/zh/reference/transaction/bankVerify-id.html |

---

*文件版本：1.1 / 整理日期：2026-10-16*
