# AllPay 廠商規格

> 官方 API Base URL：`https://example.invalid`
> 內部實作路徑：`app/Services/Payment/Providers/AllPay/`

AllPay（example.invalid）是以東南亞市場為主的第三方支付平台，透過統一的封裝協議支援馬來西亞、越南、印尼、泰國、孟加拉等多幣別代收業務。平台本身也作為底層通道，被 SurePay、SafePay、TrustPay、TakaPay 等子品牌所使用。

**本系統僅實作代收（Deposit）與訂單查詢（QueryOrder），不支援代付（Withdrawal）。**

## 環境資訊

| 項目 | 值 |
|---|---|
| 生產 API Base URL | `https://example.invalid` |
| QRH5 / 掃碼端點 | `https://example.invalid/qrh5` |
| 自助收銀台端點（Selfplay） | `https://example.invalid/api/Deposit/manual-cashier` |
| 訂單查詢端點 | `{Base URL 主機}/query` |
| 請求格式 | `application/x-www-form-urlencoded`（asForm） |
| 響應格式 | JSON；業務資料以 Base64 編碼後置於 `response` 欄位 |
| SSL 注意 | 生產憑證缺少 SAN，需停用 SSL 驗證（codebase 已設 `verify: false`） |

> `APIUrl` 由各商戶配置決定，`sendRequest` 直接使用完整 URL，僅 QueryOrder 查詢時自動取 Host 拼接 `/query`。

---

## 支援的支付方式與幣別

AllPay 以 `paymentCode` 區分支付通道，以 `bankCode` 區分銀行（部分通道）。下表為 codebase 及 DB 中實際使用的通道配置。

### MYR（馬來西亞令吉）

| paymentCode | bankCode（代收通道） | 說明 |
|---|---|---|
| `FPX` | 各銀行代碼（見銀行代碼映射） | FPX 網銀轉帳 |
| `FPXC` | 各銀行代碼 | FPX 轉帳（C 型通道） |
| `EWALLET` | — | 電子錢包（Touch 'n Go、GrabPay 等） |
| `DUITNOWQR` | — | DuitNow QR 掃碼 |
| `TNG` | — | Touch 'n Go 專用通道 |
| `P2PDEPOSIT` | — | P2P 人工收款（自助收銀台） |

### VND（越南盾）

| paymentCode | bankCode | 說明 |
|---|---|---|
| `VNDBANK` | 各銀行代碼 | 越南銀行轉帳 |
| `QRH5`（hasPaymentCode=0） | — | QR 掃碼（通道使用 orderType key） |

### IDR（印尼盾）— 底層通道 SurePay

| paymentCode（orderType key） | 說明 |
|---|---|
| `MANDIRI` / `BRI` / `BCA` / `BNI` / `CIMB` / `MAYB` / `DANAMON` | 虛擬帳號（VA） |
| `LINKAJA` / `OVO` / `DANA` / `SHOPEEPAY` | 電子錢包 |

> IDR 限額（SurePay）：VA 10,000–50,000,000 IDR；EWallet 20,000–10,000,000 IDR

### THB（泰銖）— 底層通道 SurePay

| paymentCode（orderType key） | 說明 |
|---|---|
| `KBANK` / `KTB` / `BBL` / `SCB` / `CIMB` / `HSBC` / `TMB` / `GSB` / `DBBK` / `GHB` / `MHCB` / `TISCO` / `TCRB` | 泰國銀行轉帳 |

> THB 限額（SurePay）：300–200,000 THB

### BDT（孟加拉塔卡）— 底層通道 TakaPay

| paymentCode（orderType key） | 說明 |
|---|---|
| `BKASH` | bKash |
| `ROCKET` | Rocket |
| `NAGAD` | Nagad |

---

## API 接口

### 代收 — `POST {APIUrl}`

**格式說明**：所有請求以外層封裝（Wrapper）格式發送；業務參數先做 JSON 序列化後再 Base64 編碼，放入 `request` 欄位。外層簽名以 `signMsg` 欄位傳遞。

#### 外層請求結構

| 欄位 | 必填 | 說明 |
|---|---|---|
| `version` | ✅ | 固定值 `"1.0"` |
| `merchantNo` | ✅ | 商戶號 |
| `request` | ✅ | `base64_encode(json_encode($innerParams))` — 業務參數 |
| `signMsg` | ✅ | MD5 簽名（見簽名流程） |

#### 代收業務參數（`request` 內層 JSON）

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `amount` | ✅ | numeric | 訂單金額 |
| `orderNo` | ✅ | string | 商戶訂單號（系統內唯一） |
| `orderTime` | ✅ | string | 訂單時間，格式 `YmdHis`（前端時區） |
| `returnUrl` | ✅ | string | 支付完成後跳轉 URL |
| `notifyUrl` | ✅ | string | 回調通知 URL |
| `memberCode` | - | string | 會員帳號（緬甸通道不需要，可由 `disabled_member_code=1` 停用） |
| `mobile` | - | string | 會員手機號 |
| `paymentCode` | - | string | 支付通道代碼（`hasPaymentCode=1` 時必填） |
| `bankCode` | - | string | 銀行代碼（`hasBankCode=1` 時必填，值取自 `orderType.key`） |

> **paymentCode / bankCode 邏輯**：
> - `hasPaymentCode=1, hasBankCode=0`：`paymentCode = orderType.key`（直接由訂單類型 key 決定通道）
> - `hasPaymentCode=1, hasBankCode=1`：`paymentCode` 取自商戶配置（固定值），`bankCode = orderType.key`（由訂單類型決定銀行）

#### 代收響應

代收成功時，三方回傳外層 JSON，`response` 欄位為 Base64 編碼的內層 JSON：

**外層格式**：

```json
{
    "resCode": "000",
    "resMsg": "success",
    "merchantNo": "{MerchantNo}",
    "response": "<base64_encoded_inner_json>"
}
```

**內層業務響應**：

```json
{
    "code": "0000",
    "msg": "success",
    "gbpOrderNo": "GBP2025120100001",
    "resultUrl": "https://example.invalid/pay/xxxx"
}
```

| 欄位 | 說明 |
|---|---|
| `code` | 業務結果代碼，`"0000"` 為成功 |
| `msg` | 結果描述 |
| `gbpOrderNo` | 三方訂單號，存入 `orders.third_party_order_no` |
| `resultUrl` | 收銀台 URL，導向使用者（`DepositResponseType::URL_REDIRECT`） |

**成功判定**：外層 `resCode === '000'` 且內層 `code === '0000'`

---

### 訂單查詢 — `POST {Host}/query`

QueryOrder 自動從配置的 `APIUrl` 中提取主機名，拼接 `/query` 路徑（例：`https://example.invalid/query`）。

同樣使用外層封裝格式（Wrapper），業務參數 Base64 編碼後放入 `request`。

#### 業務參數（內層 JSON）

| 欄位 | 必填 | 說明 |
|---|---|---|
| `service` | ✅ | `"QRH5"`（代收訂單）|
| `orderNo` | ✅ | 商戶訂單號（與 `gbpOrderNo` 二擇一） |
| `gbpOrderNo` | - | 三方訂單號（可替代 `orderNo`，目前 codebase 預設不傳） |

#### 查詢響應（內層 JSON）

```json
{
    "code": "0000",
    "msg": "success",
    "status": "SUCCESS",
    "orderNo": "ORDER20251201001",
    "gbpOrderNo": "GBP2025120100001",
    "amount": "100.00"
}
```

| `status` | 含義 | 內部映射 |
|---|---|---|
| `SUCCESS` | 支付成功（終態） | `ProviderOrderStatusEnum::SUCCESS` |
| `PROCESSING` | 處理中 | `ProviderOrderStatusEnum::PROCESSING` |
| `PAYING` | 支付中 | `ProviderOrderStatusEnum::PROCESSING` |
| `FAIL` | 支付失敗（終態） | `ProviderOrderStatusEnum::FAIL` |
| 其他 | 未知 | `ProviderOrderStatusEnum::ERROR` |

> QueryOrder 以 `code === '0000'` 為成功前提才比對 status；業務層失敗（`code` 非 `'0000'`）時 status 不可信，一律回 `ERROR` 並由 `errorMessage` 帶出 `msg`。（歷史：2026-08-25 前此判斷曾寫反為 `!==`，成功訂單會被判成 ERROR——當時零 consumer 故未爆發，已修正並補回歸測試。）

---

## 代收回調

AllPay 在支付完成後以 **POST FORM** 格式推送至商戶設定的 `notifyUrl`。

### 回調請求參數

| 欄位 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `content` | ✅ | string | Base64 編碼的回調業務 JSON |
| `notifyTime` | ✅ | string | 通知時間，格式 `YmdHis` |
| `signMsg` | ✅ | string | MD5 簽名（見簽名流程） |
| `version` | ✅ | string | 版本號（通常為 `"1.0"`） |

### `content` 解碼後的業務 JSON

| 欄位 | 必填 | 說明 |
|---|---|---|
| `service` | ✅ | 服務類型（如 `"QRH5"`） |
| `merchantNo` | ✅ | 商戶號（需與配置比對） |
| `paymentCode` | ✅ | 支付通道代碼 |
| `status` | ✅ | 訂單狀態：`"SUCCESS"` 或其他（失敗） |
| `amount` | ✅ | 實際支付金額 |
| `orderNo` | ✅ | 商戶訂單號 |
| `gbpOrderNo` | - | 三方訂單號 |
| `extraContent` | - | 附加資訊（Base64 編碼的 JSON，解碼後存入 `orders.extra_content`） |

### 成功判定

`content.status === 'SUCCESS'`

### 回調響應

| 情境 | 回傳 |
|---|---|
| 處理成功 | 純文字 `OK` |
| 處理失敗（簽名錯誤、商戶號不符等） | 純文字 `Failure` |

### 回調處理流程

```
1. 驗證 signMsg（外層簽名）→ 失敗回 Failure
2. Base64 解碼 content
3. 驗證 content 欄位格式（service / merchantNo / paymentCode / status / amount / orderNo）
4. 驗證 merchantNo 是否與配置吻合 → 不符回 Failure
5. lockForUpdate 鎖定並查詢訂單
6. checkDepositOrderStatus（訂單已處理則依狀態回應）
7. 解析 extraContent（若存在）
8. content.status === 'SUCCESS' → updateDepositOrderSuccess（含金額比對）
   否則 → updateDepositOrderFailed
9. DB::commit()，回傳 OK
```

---

## 簽名流程

AllPay 使用 **MD5（大寫）** 作為簽名演算法，有兩套簽名情境：

### 代收請求簽名（sendRequest 外層）

1. 組裝外層 data（`version`、`merchantNo`、`request`）
2. 對 key **按字母序排列（ksort）**
3. 逐 key 拼接為 `key:value&` 格式
4. 末尾追加 `key:{MerchantKey}`
5. `signMsg = strtoupper(md5($signMsg))`

**拼接範例**：

```
merchantNo:{MerchantNo}&request:{base64_request}&version:1.0&key:{MerchantKey}
```

```php
// codebase 實作
ksort($data);
$signMsg = '';
foreach ($data as $k => $v) {
    $signMsg .= $k . ':' . $v . '&';
}
$signMsg .= 'key:' . $this->info['MerchantKey'];

$data['signMsg'] = strtoupper(md5($signMsg));
```

### 回調簽名驗證（makeSignature）

回調通知的外層簽名字段固定為 `content`、`notifyTime`、`version`（按字母序），末尾追加 `key:{MerchantKey}`：

**拼接規則**：

```
content:{content}&notifyTime:{notifyTime}&version:{version}&key:{MerchantKey}
```

```php
// codebase 實作
protected function makeSignature(array $input): string
{
    $fields = [
        'content'    => $input['content'] ?? '',
        'notifyTime' => $input['notifyTime'] ?? '',
        'version'    => $input['version'] ?? '',
    ];

    $tmpSignature = '';
    foreach ($fields as $k => $v) {
        $tmpSignature .= $k . ':' . $v . '&';
    }
    $tmpSignature .= 'key:' . $this->info['MerchantKey'];

    return strtoupper(md5($tmpSignature));
}
```

> 驗證方式：`$signature !== $input['signMsg']` 則回傳 `Failure`。

---

## 回調 IP 白名單

根據 GitLab 工單 #3401，申請商戶時須提供以下 IP 列入白名單（商戶側 IP）：

| 用途 | IP |
|---|---|
| 商戶出口 IP 1 | `0.0.0.0` |
| 商戶出口 IP 2 | `0.0.0.0` |
| 商戶出口 IP 3 | `0.0.0.0` |
| 商戶出口 IP 4（GCP） | `0.0.0.0` |
| 商戶出口 IP 5（GCP） | `0.0.0.0` |
| 商戶出口 IP 6（GCP） | `0.0.0.0` |

> 以上為本平台向 AllPay 申請商戶時所填寫的 IP 白名單，即 AllPay 允許發起 API 請求的出口 IP。AllPay 本身推送回調的 IP 範圍尚無工單記錄，建議向對方確認。

---

## 銀行代碼映射

### MYR — FPX 網銀 bankCode

以下 `bankCode` 對應 MYR FPX 通道（`paymentCode = FPX` 或 `FPXC`），值即 `orderType.key`：

| bankCode | 銀行名稱 |
|---|---|
| `PBBB` | Public Bank |
| `HOLB` | Hong Leong Bank |
| `CIMB` | CIMB Bank |
| `RHBB` | RHB Bank |
| `MYAB` | AmBank |
| `MYIS` | Bank Islam |
| `AFIN` | Affin Bank |
| `ALLI` | Alliance Bank |
| `SINA` | BSN（Bank Simpanan Nasional） |
| `OCBC` | OCBC Bank |
| `STCB` | Standard Chartered Bank |
| `UNOB` | United Overseas Bank (UOB) |
| `HSBC` | HSBC Bank |
| `AGRO` | AgroBank |
| `RAKY` | Bank Rakyat |
| `RAJH` | Al-Rajhi Bank |
| `MUAM` | Bank Muamalat |
| `CITI` | Citibank |
| `MBSB` | MBSB Bank |
| `GXBK` | GX Bank |

### VND — 越南銀行 bankCode

以下 `bankCode` 對應 VND 通道（`paymentCode = VNDBANK`）：

（具體 bankCode 值依三方文件或 orderType 設定，DB 記錄以 `VNDBANK` 為通道 key，bankCode 由 `orderType.key` 決定；具體值需查三方最新清單。）

### IDR — 印尼 virtual account / 電子錢包（SurePay 底層）

| orderType key（bankCode） | 類型 | 銀行/錢包名稱 |
|---|---|---|
| `MANDIRI` | VA | Bank Mandiri |
| `BRI` | VA | Bank BRI |
| `BCA` | VA | Bank BCA |
| `BNI` | VA | Bank BNI |
| `CIMB` | VA | CIMB Niaga |
| `MAYB` | VA | Maybank Indonesia |
| `DANAMON` | VA | Bank Danamon |
| `LINKAJA` | EWallet | LinkAja |
| `OVO` | EWallet | OVO |
| `DANA` | EWallet | DANA |
| `SHOPEEPAY` | EWallet | ShopeePay |

### THB — 泰國銀行（SurePay 底層）

| orderType key（bankCode） | 銀行名稱 |
|---|---|
| `BBL` | Bangkok Bank |
| `KBANK` | KasikornBank |
| `KTB` | Krungthai Bank |
| `SCB` | Siam Commercial Bank |
| `CIMB` | CIMB Thai |
| `HSBC` | HSBC Thailand |
| `TMB` | TMB Bank |
| `GSB` | Government Savings Bank |
| `DBBK` | DBS Bank |
| `GHB` | Government Housing Bank |
| `MHCB` | Mizuho Bank |
| `TISCO` | Tisco Bank |
| `TCRB` | Thai Credit Retail Bank |

### BDT — 孟加拉行動支付（TakaPay 底層）

| orderType key（bankCode） | 服務名稱 |
|---|---|
| `BKASH` | bKash |
| `ROCKET` | Rocket |
| `NAGAD` | Nagad |

---

## 系統錯誤代碼

外層響應 `resCode` 錯誤碼對照（`sendRequest` 驗證層）：

| resCode | 說明 |
|---|---|
| `000` | 成功 |
| `101` | API 不存在 |
| `202` | 商戶帳號不存在 |
| `203` | 帳號暫停 |
| `204` | 帳號鎖定 |
| `205` | 帳號凍結 |
| `206` | 帳號配置錯誤 |
| `301` | 版本參數錯誤 |
| `302` | merchantNo 參數錯誤 |
| `303` | 請求參數錯誤 |
| `304` | signMsg 簽名錯誤 |
| `401` | 驗證失敗 |
| `402` | 加解密失敗 |
| `403` | 密文格式錯誤（Malformed） |
| `404` | 密文格式錯誤（Format） |
| `999` | 系統內部錯誤 |

> 外層 `resCode !== '000'` 時，系統會 `Log::error(mapResCodeToMessage($resCode))` 並拋出 `ApiException($result['resMsg'])`。

---

## 商戶配置格式（`agent_cash_flows.info`）

```json
{
    "APIUrl": "https://example.invalid/qrh5",
    "MerchantNo": "{商戶號}",
    "MerchantKey": "{MD5 密鑰}",
    "hasPaymentCode": 1,
    "paymentCode": "FPX",
    "hasBankCode": 1,
    "disabled_member_code": 0
}
```

| 欄位 | 說明 |
|---|---|
| `APIUrl` | 完整的收款 URL（含通道路徑，如 `/qrh5`）；QueryOrder 自動提取主機 |
| `MerchantNo` | 商戶號（商戶申請時由 AllPay 分配） |
| `MerchantKey` | MD5 簽名密鑰（商戶申請時由 AllPay 分配） |
| `hasPaymentCode` | `1` = 代收時需傳 `paymentCode`；`0` = 不傳 |
| `paymentCode` | 固定通道代碼（`hasBankCode=1` 時使用），如 `FPX`、`EWALLET`、`P2PDEPOSIT` |
| `hasBankCode` | `1` = 代收時需傳 `bankCode`（值取自 `orderType.key`）；`0` = 不傳 |
| `disabled_member_code` | `1` = 不傳 `memberCode`（如緬甸通道） |

---

## 參考資料

| 來源 | 說明 |
|---|---|
| GitLab #3401 | [GFF] AllPay 轉直線申請商戶（含商戶申請白名單 IP、callback URL、跳轉頁資訊） |
| GitLab #2743 | [GFF] AllPay 盜號出款紀錄（安全事件，與 API 規格無關） |
| 官方 API 文件 | 域名 `https://example.invalid`，WebFetch 因 SSL 憑證缺少 SAN 無法取得；`example.invalid` 主域 ECONNREFUSED。如需查閱，建議透過商戶後台取得 PDF 文件或直接聯絡 AllPay 技術支援。 |

### 內部實作路徑

```
app/Services/Payment/Providers/AllPay/
├── Driver.php            # 組合代收 / QueryOrder 子類別（無 Withdrawal）
├── AllPayTrait.php       # 共用邏輯：sendRequest（外層封裝）、makeRequest（簽名組裝）、makeSignature（回調驗簽）、mapResCodeToMessage（錯誤碼映射）
├── Deposit.php           # 代收實作：buildDepositParams 組裝業務參數，回傳 URL_REDIRECT
├── DepositCallback.php   # 回調處理：外層簽名驗證 → Base64 解碼 content → 訂單狀態更新
└── QueryOrder.php        # 訂單狀態查詢：拼接 /query 路徑，映射 status 至 ProviderOrderStatusEnum
```

---

*文件版本：1.0 / 整理日期：2026-06-01*
*資料來源：Codebase 實作、GitLab 工單 #3401、DB 商戶配置萃取（agent_cash_flows）*
