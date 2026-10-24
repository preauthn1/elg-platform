# DgPay 廠商規格 — V1（MYR / IDR）

> 本檔為 **V1 API**（MYR、IDR）的發起規格；共用段（環境、回調處理、回調驗簽、錯誤碼、IP 白名單、ACF info 欄位、參考資料）見索引頁 [`api.md`](./api.md)，SGD 走 [`api-v2.md`](./api-v2.md)。
>
> 版本由 `agent_cash_flows.info.VERSION` 決定：**MYR / IDR 用 `V1`**。所有請求 **FORM-DATA**、響應 **JSON**、認證 **MD5 簽名**（`securityToken`）。

---

## 1. 支援的支付方式與幣別（V1）

### 代收（Deposit）

現行官方 Provider Definition（MYR / IDR 文檔 §5，2026-07-02 經瀏覽器實抓比對）：

| 幣別 | 支付通道 | providerId | providerType | 說明 |
|---|---|---|---|---|
| **MYR** | 網銀轉帳（Route2） | `5` | `10` | 需帶 `fromBankCode` |
| **MYR** | QR（Route2） | `5` | `20` | 僅 `redirectUrl` |
| **MYR** | QR KYC（Route47） | `49` | `10` | 需帶 `language`、`username` |
| **MYR** | QR TNG（Route47） | `49` | `15` | 需帶 `language` |
| **MYR** | QR DuitNow（Route47） | `49` | `20` | 需帶 `language` |
| **MYR** | QR Decimal Amount（Route47） | `49` | `25` | 需帶 `language` |
| **MYR** | P2P（Route47） | `49` | `30` | 需帶 `username`、`bankCode`（§6 僅 `MBB`） |
| **IDR** | QRIS（Route49） | `55` | `10` | 需帶 `accName` |
| **IDR** | Virtual Account（Route49） | `55` | `15` | 需帶 `accName`、`bankCode` |
| **IDR** | OVO（Route49） | `55` | `20` | 發起成功後 App 直接彈通知 |
| **IDR** | Dana（Route49） | `55` | `21` | 需帶 `accName`、`phoneNum`、`redirectUrl` |
| **IDR** | ShopeePay（Route49） | `55` | `22` | 需帶 `accName`、`phoneNum`、`redirectUrl` |

> ⚠️ **舊版 Provider Definition（已從官方文檔移除，僅舊 DG 組既有配置仍在使用）**：
> MYR E-Wallet `17`/`11~16`（Duit Now / TnG / Boost / GrabPay / ShopeePay / Maybank QR，由 orderType key DUI/TNG/BOT/GPY/SPY/MAE 動態解析）、IDR OVO `26`/`20`、IDR 代付 `26`/`10`。
> 新 DGPAY 組（cash_flow 119）一律照上表配置；程式碼保留舊值路由僅為舊組相容。

### 代付（Withdrawal）

代付走 `POST /withdraw`，依 `info.PROVIDER_ID` / `PROVIDER_TYPE` 與 `Currency` 決定收款人欄位（見第 3 節）。
現行官方 Provider Definition 代付條目（MYR 文檔 §5 Withdrawal 段／IDR 文檔 §5 最後一條 Withdraw，2026-07-03 經瀏覽器實抓比對）：

| 幣別 | 通道 | providerId | providerType | data 收款人欄位 |
|---|---|---|---|---|
| **MYR** | Auto（Route2） | `5` | `10` | `toBankCode` / `toAccNo` / `toAccName` |
| **MYR** | Manual（Route7） | `10` | `10` | `bankName` / `accNo` / `accName` |
| **IDR** | 銀行/錢包（Route49 Withdraw） | `55` | `10` | `accNo` / `bankCode` |

> ⚠️ **綁定必須配實值**：`PROVIDER_ID`/`PROVIDER_TYPE` 留 `0/0` 時三方回 `-180 Invalid Payment`
> （DEV 實測 order 135）；「非 10/10 即 Auto」僅決定 data 收款人欄位格式，param 仍原樣送出。

> ⚠️ **IDR 代付 = `55/10`（官方現行值，2026-07-03 更正）**：官方 IDR §5 Provider Definition 最後一條
> Withdraw 為 Route49 `55/10`（與 QRIS 代收同一組 pair，靠 `/withdraw` 端點區分方向）。
> 舊系統可運作綁定「DGPay代付(IDR) `26/10`」屬官方文檔已移除的舊 Provider（OVO `26` 時代），
> 僅舊 DG 組沿用；**新 DGPAY 組（cash_flow 120）一律配 `55/10`**，首筆 IDR 代付上線前仍須實測。

> ⚠️ **SGD 代付也走 V1 結構**（`/withdraw`＋`providerId=10`/`providerType=10`＋`data{bankName,accNo,accName}`），但銀行碼為 SGD 專屬——單列於 [`api-v2.md`§3/§5](./api-v2.md)（SGD 代收 V2、代付 V1，見該檔頂部巨坑說明）。

---

## 2. 代收 API — `POST /deposit`

**Base URL**：`https://example.invalid/ajax/api`（`agent_cash_flows.info.APIUrl`）

### 請求參數

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `opCode` | ✅ | string | 商戶代碼（`info.Merchant`，如 `dgpay_1568`） |
| `orderId` | ✅ | string | 商戶訂單號（系統 `order.order_no`） |
| `providerId` | ✅ | integer | 支付通道 Provider ID（見第 1 節表格） |
| `providerType` | ✅ | integer | 支付通道 Provider Type（見第 1 節表格） |
| `currency` | ✅ | string | ISO-4217 幣別（`MYR` / `IDR`） |
| `amount` | ✅ | numeric | 代收金額 |
| `callbackUrl` | ✅ | string | 代收回調 URL |
| `reqDateTime` | ✅ | string | 請求時間（格式：`Y-m-d H:i:s`，前端時間） |
| `data` | ✅ | string | 通道附加資料（JSON 字串，依幣別/通道而異，見下方） |
| `securityToken` | ✅ | string | MD5 簽名（見第 4 節） |

### `data` 欄位依幣別/通道

`data` 為 JSON 字串（`json_encode($data, JSON_UNESCAPED_SLASHES)`）：

| 幣別 + 通道 | data 內容 |
|---|---|
| MYR 網銀 Route2（5/10） | `{"fromBankCode": "{銀行代碼}", "redirectUrl": "{returnUrl}"}` |
| MYR QR Route2（5/20） | `{"redirectUrl": "{returnUrl}"}` |
| MYR QR KYC Route47（49/10） | `{"language": "EN", "username": "{member.username}", "redirectUrl": "{returnUrl}"}` |
| MYR QR TNG / DuitNow / Decimal Route47（49/15、20、25） | `{"language": "EN", "redirectUrl": "{returnUrl}"}` |
| MYR P2P Route47（49/30） | `{"username": "{member.username}", "redirectUrl": "{returnUrl}", "bankCode": "MBB"}` |
| IDR QRIS（55/10） | `{"accName": "{member.username}"}` |
| IDR VA（55/15） | `{"accName": "{member.username}", "bankCode": "{銀行數字碼}"}` |
| IDR OVO / Dana / ShopeePay（55/20、21、22） | `{"accName": "{member.username}", "redirectUrl": "{returnUrl}", "phoneNum": "{member.mobile}"}` |
| MYR E-Wallet（17，舊組 legacy） | `{"username": "{member.username}", "code": "{orderType.key}", "redirectUrl": "{returnUrl}"}` |
| THB QR（providerId=11, providerType=10，舊組 legacy，非官方文檔範圍） | `{"name": "{member.username}", "email": "{email}", "phone": "{member.mobile}", "language": "EN"}` |

### 請求範例（MYR 網銀）

```
POST /deposit
Content-Type: application/x-www-form-urlencoded

opCode=dgpay_1568
&orderId=ORD20260301001
&providerId=5
&providerType=10
&currency=MYR
&amount=100
&callbackUrl=https://example.invalid/callback/dg/deposit
&reqDateTime=2026-03-01+10%3A00%3A00
&data=%7B%22fromBankCode%22%3A%22MAYB%22%2C%22redirectUrl%22%3A%22https%3A%2F%example.invalid%2Freturn%22%7D
&securityToken=[REDACTED]
```

### 成功響應

```json
{
    "code": "0",
    "description": "Success",
    "refId": "202507289918578258@dgpay_1568",
    "providerRefId": "202507289918578258@dgpay_1568",
    "paymentUrl": "https://example.invalid/pay-auth?authToken=[REDACTED]",
    "merchantOrderId": "202507289918578258",
    "amount": 100,
    "nominalAmount": null,
    "amountToDeposit": 100,
    "adminFee": 1,
    "playerFee": 0,
    "agentFee": 1
}
```

**成功判定**：`code === '0'`；其餘取 `description`（或 `providerMsg`）拋出 `ApiException`。

> - 成功後系統使用 `paymentUrl`（經 `urldecode()` 解碼）導向使用者；官方 IDR §3.1 標 `paymentUrl` 為 Optional。
> - IDR Virtual Account（55/15）響應另帶 `accountNo`（虛擬帳號，官方 IDR §3.1 Optional 欄位）。
> - IDR OVO（55/20；舊組 26/20）成功時，App 直接彈出轉帳通知，回應類型為 `SUCCESS`（無 paymentUrl）。

---

## 3. 代付 API — `POST /withdraw`

**Base URL**：`https://example.invalid/ajax/api`（`agent_cash_flows.info.APIUrl`）

### 請求參數（FORM-DATA）

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `orderId` | ✅ | string | 商戶代付訂單號（我方 `transaction_no`，前綴 `DG`） |
| `providerId` | ✅ | integer | 代付通道 Provider ID（`info.PROVIDER_ID`） |
| `providerType` | ✅ | integer | 代付通道 Provider Type（`info.PROVIDER_TYPE`） |
| `currency` | ✅ | string | ISO-4217 幣別（`MYR` / `IDR`） |
| `amount` | ✅ | numeric | 代付金額 |
| `callbackUrl` | ✅ | string | 代付回調 URL |
| `reqDateTime` | ✅ | string | 請求時間（格式：`Y-m-d H:i:s`，前端時間） |
| `opCode` | ✅ | string | 商戶代碼（`info.Merchant`） |
| `data` | ✅ | string | 收款人資訊（JSON 字串，依幣別/通道而異，見下方） |
| `securityToken` | ✅ | string | MD5 簽名（見第 4 節） |

### `data` 欄位依幣別/通道

`data` 為 JSON 字串（`json_encode($data, JSON_UNESCAPED_SLASHES)`）：

| 幣別 + 通道 | data 內容 |
|---|---|
| MYR Manual（providerId=10, providerType=10） | `{"bankName": "{我方銀行全名}", "accNo": "{帳號}", "accName": "{戶名}"}` |
| MYR Auto（其他） | `{"toBankCode": "{三方銀行代碼}", "toAccNo": "{帳號}", "toAccName": "{戶名}"}` |
| IDR | `{"accNo": "{帳號}", "bankCode": "{三方銀行代碼}"}` |

> 三方銀行代碼由我方 `withdrawal_orders.bank_code` 經 `info.available_banks`（`BankCode_ELG` → `BankCode_Third`）映射取得。

> ⚠️ **MYR Manual 送的是我方銀行全名，不是三方銀行代碼**：程式取 `withdrawal_orders.bank_name`（`app/Services/Payment/Providers/DgPay/Withdrawal.php` 的 `buildChannelData()`）。生產實證（2026-09-16 查 TPower 的 TtPay，與 DgPay 共用同一套代付實作）：近 30 天送出的 `data.bankName` 都是「(MYR) - Ryt Bank」這類我方銀行全名，同期 6,672 筆成功、116 筆失敗、3 筆未定案。本文件原本寫「三方銀行代碼」，與實際行為相反，照著改程式會讓這條通道的代付全數失敗。

### 成功響應

```json
{
    "code": "0",
    "refId": "W0013",
    "amount": 100,
    "amountToWithdraw": 98.00,
    "adminFee": 2.00,
    "playerFee": 2.00,
    "agentFee": 0.00,
    "result": {}
}
```

**成功判定**：`code === '0'`；`refId` 為三方代付訂單號（寫入 `withdrawal_payout_records.third_party_transaction_no`）。失敗時取 `description` 拋出錯誤。錯誤碼見 [`api.md`](./api.md#錯誤碼)（V1/V2 共用）。

---

## 4. 簽名流程（V1）

`securityToken` 使用 **MD5**、輸出**小寫十六進位字串**。V1 代收發起與代付發起**共用同一拼接規則**。

**拼接規則**：`orderId + providerId + providerType + currency + amount + reqDateTime + opCode + merchantKey`

```php
protected function generateSignature(array $params): string
{
    $string = $params['orderId']
        . $params['providerId']
        . $params['providerType']
        . $params['currency']
        . $params['amount']
        . $params['reqDateTime']
        . $params['opCode']
        . $this->infoData->merchantKey;  // info.MerchantKey = secretKey

    return strtolower(md5($string));
}
```

**範例**：

```
orderId:      ORD20260301001
providerId:   5
providerType: 10
currency:     MYR
amount:       100
reqDateTime:  2026-03-01 10:00:00
opCode:       dgpay_1568
merchantKey:  {MerchantKey}

拼接：ORD202603010015 10MYR1002026-03-01 10:00:00dgpay_1568{MerchantKey}
securityToken = [REDACTED]
```

> 回調驗簽（V1/V2 共用公式 `md5(opCode + orderId + amount + status + secretKey)`）見 [`api.md`](./api.md#回調驗簽)。

---

## 5. 銀行代碼映射（V1）

### MYR — Route2 銀行碼（代收 `fromBankCode` ＋ 代付 `toBankCode` 共用）

官方 §6 Bank Code Definition「Route2」全表（37 筆，2026-07-03 實抓）。欄位語意：
**Transfer Supported＝可作代收付款行（`fromBankCode` 可選）；Payment Receiving Supported＝可作代付收款行（`toBankCode` 可用）**。
代收發起由 `orderType.key` 直接帶入 `fromBankCode`——僅限 Transfer=✅ 的 9 家個人戶。

| 代碼 | 銀行名稱 | 代收付款（Transfer） | 代付收款（Receiving） |
|---|---|:---:|:---:|
| `AFIN` | Affin Islamic Bank | ✅ | ✅ |
| `ALLI` | Alliance Bank | ✅ | ✅ |
| `HOLB` | Hong Leong Bank | ✅ | ✅ |
| `CIMB` | CIMB Bank | ✅ | ✅ |
| `MAYB` | Maybank | ✅ | ✅ |
| `PBBB` | Public Bank | ✅ | ✅ |
| `RHBB` | RHB Bank | ✅ | ✅ |
| `SINA` | Bank Simpanan Nasional | ✅ | ✅ |
| `MYIS` | Bank Islam | ✅ | ✅ |
| `CIMB_BIZ` | CIMB Bank（Corporate Account） | Payout Only | ✅ |
| `MAYB_BIZ` | Maybank（Corporate Account） | Payout Only | ✅ |
| `AAAA` | Bank of America | ❌ | ✅ |
| `AGRO` | Bank Pertanian（AGRO Bank） | ❌ | ✅ |
| `BAKO` | Bangkok Bank Malaysia | ❌ | ✅ |
| `BNPB` | BNP Paribas Malaysia | ❌ | ✅ |
| `CCCC` | Bank of China | ❌ | ✅ |
| `CITI` | Citibank Berhad | ❌ | ✅ |
| `DEUT` | Deutsche Bank | ❌ | ✅ |
| `HSBC` | HSBC Bank | ❌ | ✅ |
| `ICBC` | Industrial & Commercial Bank of China | ❌ | ✅ |
| `JPMB` | J.P. Morgan Chase Bank | ❌ | ✅ |
| `KFHB` | Kuwait Finance House | ❌ | ✅ |
| `MBSB` | MBSB Bank | ❌ | ✅ |
| `MCCB` | China Construction Bank (M) Bhd | ❌ | ✅ |
| `MIZU` | Mizuho Bank | ❌ | ✅ |
| `MUAM` | Bank Muamalat | ❌ | ✅ |
| `MUFG` | MUFG Bank | ❌ | ✅ |
| `MYAB` | AmBank | ❌ | ✅ |
| `OCBC` | OCBC Bank | ❌ | ✅ |
| `RAJH` | Al-Rajhi Bank | ❌ | ✅ |
| `RAKY` | Bank Rakyat | ❌ | ✅ |
| `RYTB` | RYT Bank | ❌ | ✅ |
| `STCB` | Standard Chartered Bank | ❌ | ✅ |
| `SUMB` | Sumitomo Mitsui Banking | ❌ | ✅ |
| `TNGP` | Touch N Go eWallet | ❌ | ✅ |
| `GXBK` | GXBank | ❌ | ✅ |
| `UNOB` | United Overseas Bank | ❌ | ✅ |

> ⚠️ **2026-07-03 更正**：官方 Route2 **無 `UOB` 碼**——United Overseas Bank 用 `UNOB`（僅代付收款；`UOB` 是 SGD V2 的碼）。
> 舊記述曾把 HSBC / OCBC / CITI / STCB / MYAB / RAKY / GXBK / TNGP / UOB 列為代收付款行，
> 官方 §6 標示這些皆 Transfer=❌（僅代付收款），代收銀行選單不應出現。
> 官方文檔另無「Route 1」一詞（舊記述誤植），MYR 網銀代收即 Route2 `5/10`。

### MYR — Route47 銀行碼（P2P 代收 `bankCode`）

官方 §6「Route47」段（欄位語意同上表）：

| 代碼 | 銀行名稱 | 代收付款（Transfer） | 代付收款（Receiving） |
|---|---|:---:|:---:|
| `MBB` | Maybank | ✅ | ✅ |
| `PBB` | Public Bank | ❌ | ✅ |
| `RHBMY` | RHB Bank | ❌ | ✅ |

> P2P 代收（49/30）`data.bankCode` 僅 `MBB` 可用（Transfer=✅）。Route47 不在代付 Provider 表——代付走 Route2（5/10）或 Route7（10/10）。

### MYR — E-Wallet code（providerId=17，代收動態路由）

由 `orderType.key` 決定 `providerId=17` 與 `providerType`，再帶入 `code`：

| orderType.key | providerType | 說明 |
|---|---|---|
| `DUI` | `11` | Duit Now QR |
| `TNG` | `12` | Touch 'n Go |
| `BOT` | `13` | Boost |
| `GPY` | `14` | GrabPay |
| `SPY` | `15` | ShopeePay |
| `MAE` | `16` | Maybank QR |

### IDR — bankCode（代收 VA ＋ 代付共用）

> 官方 IDR 文檔 §6 的 `bankCode` 一律為**三位數字碼**（非英文簡稱），代收 VA 與代付共用同一組。以下為代表性節錄，**完整清單以官方 IDR 文檔 §6 與 `Data/providers/DgPay.php` IDR 區塊為準**。

| 代碼 | 銀行名稱 |
|---|---|
| `002` | Bank BRI |
| `008` | Bank Mandiri |
| `009` | Bank BNI |
| `011` | Bank Danamon |
| `013` | Bank Permata |
| `014` | Bank BCA |
| `022` | CIMB Niaga |
| `200` | Bank BTN |
| `426` | Bank Mega |
| `451` | Bank Syariah Indonesia |
| … | （官方 §6 共 100+ 筆數字碼） |

電子錢包代碼（小寫字串）：`ovo`、`linkaja`、`dana`、`gopay`、`shopeepay`。

---

## 6. 限額與費率參考（V1）

各商戶實際開通的限額以後台設定為準；以下為工單記錄的典型值。

### MYR（馬來西亞令吉）

| 通道 | 費率 | 最低 | 最高 |
|---|---|---|---|
| 網銀轉帳（Route2） | 依商戶協議 | MYR 10 | MYR 50,000 |
| E-Wallet（掃碼） | 依商戶協議 | MYR 30 | MYR 10,000 |

### IDR（印尼盾）

| 通道 | 費率 | 最低 | 最高 |
|---|---|---|---|
| QRIS | 1.7% | IDR 10,000 | IDR 10,000,000 |
| Virtual Account（VA） | IDR 7,500（固定） | IDR 10,000 | IDR 10,000,000 |
| E-Wallet（Dana 等） | 3.6% | IDR 10,000 | IDR 10,000,000 |
| OVO | 超出 IDR 2,000,000 需實名認證 | IDR 10,000 | IDR 10,000,000 |
