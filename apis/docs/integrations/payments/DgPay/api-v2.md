# DgPay 廠商規格 — SGD（代收 V2 / 代付 V1）

> 本檔為 **SGD** 的發起規格；共用段（環境、回調處理、回調驗簽、錯誤碼、IP 白名單、ACF info 欄位、參考資料）見索引頁 [`api.md`](./api.md)，MYR / IDR 走 [`api-v1.md`](./api-v1.md)。
> 所有請求 **FORM-DATA**、響應 **JSON**、認證 **MD5 簽名**（`securityToken`）。
>
> ## ⚠️⚠️ SGD 代收與代付走「不同 API 版本」——巨坑，務必分清
>
> DgPay 對 SGD 的代收、代付使用**不同版本**（與「一幣別一 VERSION」直覺相反）。這是**兩筆獨立 binding**（代收 cash_flow 119、代付 cash_flow 120），`info.VERSION` 各自獨立，故可共存：
>
> | 金流類型 | 版本 | 官方文檔 | 端點 | 關鍵結構 | binding VERSION |
> |---|---|---|---|---|---|
> | **SGD 代收** | **V2** | [SGD V2](https://example.invalid/Power-PG-API-Document-SGD-V2-1653a5833ed8801b8360ee6f5e568168) | `/v2/deposit` | `paymentType`＋`sender`，V2 簽名 | `V2` |
> | **SGD 代付** | **V1** | [SGD（舊，未重導）](https://example.invalid/Power-PG-API-Document-SGD-6fd128f7c2c64791a2bd1f3b249a662c) | `/withdraw` | `providerId=10`/`providerType=10`＋`data`，V1 簽名 | `V1` |
>
> ❗ **先前記述全錯（2026-07-03 更正）**：舊「SGD（代付）」文檔連結**並未 302 重導至 V2**——它是一份**有效的 V1 結構文檔**，正是 SGD 代付權威來源。先前把 SGD 代付寫成「V2 `/v2/withdraw`＋`recipientInfo`」全屬誤解，`Withdrawal.php` 的 V2 代付分支對 SGD **不該觸發**（詳見 [`integration.md` §5](./integration.md)）。
>
> - **代收（V2）**：見第 2、4.1、5 節。
> - **代付（V1）**：見第 3、4.2 節；結構與 [`api-v1.md`§3/§4](./api-v1.md) 的 V1 代付一致，僅銀行碼為 SGD 專屬。

---

## 1. 支援的支付方式與幣別（SGD V2）

### 代收（Deposit）

官方 §7 Payment Type Definition（SGD）共三個 paymentType，皆為 V2 代收通道（無 providerId/providerType，一律帶 `sender`）：

| paymentType | 官方名稱 | 通道類型 | 代收支援銀行（見 §5） | `sender` 額外必填 |
|---|---|---|---|---|
| `P2P` | Direct transfer | 銀行轉帳 | `DBS` / `UOB` / `OCBC` | `bankCode`（付款銀行） |
| `QR` | Qr code transfer | 掃碼 | 僅 `MARB`（Mari Bank） | 無（純掃碼、不選銀行） |
| `QRKYC` | Qr code transfer | 掃碼 + KYC | 上述所有銀行碼 | `username`（商戶會員帳號） |

> **正確碼以官方 §7 為準：`P2P` / `QR` / `QRKYC`——無 `PAYNOWQR`（舊記述有誤，已更正）。**
> paymentType 為「定義代收通道的變數」，語意等同 V1 的 Provider Definition（`providerId`/`providerType`）；動態解析設計見 §5。各通道代收支援銀行與 `sender` 欄位差異見 §5。

### 代付（Withdrawal）— ⚠️ 走 V1 結構（舊 SGD 文檔 §5）

| 幣別 | 通道 | providerId | providerType | 端點 | data 收款人欄位 |
|---|---|---|---|---|---|
| **SGD** | Route3 | `10` | `10` | `POST /withdraw` | `data`：`bankName` / `accNo` / `accName` |

> **SGD 代付不走 V2**：無 `/v2/withdraw`、無 `recipientInfo`、無 `paymentType`。
> 結構完全比照 V1 代付（[`api-v1.md`§3](./api-v1.md)），`data` 為 `{bankName, accNo, accName}`（同 MYR Manual）；
> 代付 binding 須配 `PROVIDER_ID=10` / `PROVIDER_TYPE=10`、`VERSION=V1`。
> 代付收款銀行碼＝**舊 SGD 文檔 §6 Route2（Withdraw）11 碼**（見第 5 節，含 `PNSG`），非 V2 文檔那套。

---

## 2. 代收 API — `POST /v2/deposit`

V2 通道用於 SGD PayNow / P2P 等新版通道。

### 請求參數

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `opCode` | ✅ | string | 商戶代碼（`info.Merchant`） |
| `orderId` | ✅ | string | 商戶訂單號 |
| `paymentType` | ✅ | string | 支付類型代碼（官方：「Use P2P」，§7 Payment Type Definition）。新組 binding `PAYMENT_CODE` 留空、由 channel 父層 key（`P2P`/`QR`/`QRKYC`）逐請求動態解析 |
| `currency` | ✅ | string | `SGD` |
| `amount` | ✅ | numeric | 代收金額 |
| `callbackUrl` | ✅ | string | 代收回調 URL |
| `redirectUrl` | ✅ | string | 支付完成跳轉 URL |
| `reqDateTime` | ✅ | string | 請求時間（`Y-m-d H:i:s`，SGT +8 時區） |
| `sender` | ✅ | string | 付款人資訊（JSON 字串，見下方） |
| `securityToken` | ✅ | string | MD5 簽名（見第 4 節） |

### `sender` 欄位（V2 SGD PayNow）

```json
{
    "senderFirstName": "{extra_content.first_name}",
    "senderLastName": "{extra_content.last_name}",
    "senderNo": "{extra_content.bank_number}",
    "bankCode": "{orderType.key}",
    "username": "{member.username}",
    "phone": "{member.mobile}"
}
```

> 官方 §5 Sender Info：各欄位「With different currencies, some params may be mandatory, please contact CS to confirm」——即範本非強制，依實際商戶需求填。`bankCode` 於 P2P 為必填。

### 成功響應

```json
{
    "code": "0",
    "description": "Success",
    "refId": "refid01",
    "providerRefId": "refid01",
    "paymentUrl": "https://example.invalid/payment.html",
    "merchantOrderId": "20260301001",
    "amount": 200
}
```

**成功判定**：`code === '0'`（同 V1）。成功後以 `paymentUrl` 導向。

> 官方 §3.1 響應表僅列 `code` / `description` / `refId` / `paymentUrl`；實際回應另帶 `providerRefId`、`merchantOrderId`、`amount` 等欄位（上例為實抓樣本）。

---

## 3. 代付 API — `POST /withdraw`（⚠️ V1 結構，非 `/v2/withdraw`）

> SGD 代付權威來源為**舊 SGD 文檔**（`6fd128f7…`，未重導）§3.2 Withdraw / §5 Provider Definition。結構與 [`api-v1.md`§3](./api-v1.md) 完全一致，此處只列 SGD 特有值。

**Base URL**：`https://example.invalid/ajax/api`（`agent_cash_flows.info.APIUrl`，含 `/ajax/api` 前綴——官方完整路徑即 `/ajax/api/withdraw`）。

### 請求參數（FORM-DATA）

| 參數 | 必填 | 類型 | 說明 |
|---|---|---|---|
| `orderId` | ✅ | string | 商戶代付訂單號（我方 `transaction_no`，前綴 `DG`） |
| `providerId` | ✅ | integer | `10`（Route3，`info.PROVIDER_ID`） |
| `providerType` | ✅ | integer | `10`（`info.PROVIDER_TYPE`） |
| `currency` | ✅ | string | `SGD` |
| `amount` | ✅ | numeric | 代付金額 |
| `callbackUrl` | ✅ | string | 代付回調 URL |
| `reqDateTime` | ✅ | string | 請求時間（`Y-m-d H:i:s`） |
| `opCode` | ✅ | string | 商戶代碼（`info.Merchant`） |
| `data` | ✅ | string | 收款人資訊（JSON 字串，見下方） |
| `securityToken` | ✅ | string | MD5 簽名（V1 規則，見第 4.2 節） |

> **注意**：SGD 代付**無** `recipientInfo` / `paymentType` / `redirectUrl`（那些是 V2 代收或誤解的欄位），用 V1 的 `providerId` / `providerType` / `data`。

### `data` 欄位（官方舊 SGD §5 Route3）

`data` 為 JSON 字串（`json_encode($data, JSON_UNESCAPED_SLASHES)`）。程式由 `Withdrawal::buildChannelData()` 的 `default` case 產生（SGD 未落 MYR/IDR 特判，即走此形）：

| data 欄位 | 來源 | 備註 |
|---|---|---|
| `bankName` | 三方碼（`available_banks` 的 `BankCode_Third`，如 `PNSG` / `DBS`） | 由 `withdrawal_orders.bank_code` 映射，見第 5 節 |
| `accNo` | `withdrawal_orders.account_no` | 收款帳號 |
| `accName` | `withdrawal_orders.account_name` | 收款戶名（全名） |

```json
{
    "bankName": "{三方銀行代碼}",
    "accNo": "{account_no}",
    "accName": "{account_name}"
}
```

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

**成功判定**：`code === '0'`；`refId` 為三方代付訂單號。錯誤碼見 [`api.md`](./api.md#錯誤碼)。**首筆 SGD 代付上線務必實測驗簽通過（端點 `/withdraw`、`data` 結構、V1 簽名）。**

---

## 4. 簽名流程

`securityToken` 使用 **MD5**、輸出**小寫十六進位字串**。SGD **代收（V2）與代付（V1）簽名規則不同**。

### 4.1 SGD 代收發起簽名（V2）

**拼接規則**：`opCode + orderId + paymentType + currency + amount + callbackUrl + redirectUrl + reqDateTime + sender + merchantKey`

```php
$string = $params['opCode']
    . $params['orderId']
    . $params['paymentType']
    . $params['currency']
    . $params['amount']
    . $params['callbackUrl']
    . $params['redirectUrl']
    . $params['reqDateTime']
    . $params['sender']    // JSON 字串（非物件）
    . $this->infoData->merchantKey;

return strtolower(md5($string));
```

### 4.2 SGD 代付發起簽名（V1，與 MYR/IDR 代付共用）

**拼接規則**：`orderId + providerId + providerType + currency + amount + reqDateTime + opCode + merchantKey`（官方舊 SGD §2.1.1 Deposit / Withdraw 共用 V1 拼接）

```php
$string = $params['orderId']
    . $params['providerId']
    . $params['providerType']
    . $params['currency']
    . $params['amount']
    . $params['reqDateTime']
    . $params['opCode']
    . $this->infoData->merchantKey;

return strtolower(md5($string));
```

> **注意**：SGD 代付簽名**與 V2 代收完全不同**（無 `sender`/`recipientInfo`、含 `providerId`/`providerType`），與 [`api-v1.md`§4](./api-v1.md) 的 V1 代付簽名**一致**。回調驗簽（V1/V2 共用 `md5(opCode + orderId + amount + status + secretKey)`）見 [`api.md`](./api.md#回調驗簽)。

---

## 5. 銀行代碼映射（SGD）

> ⚠️ **代收與代付銀行碼來自不同文檔**：代收（V2）用 SGD V2 文檔 §6；代付（V1）用**舊 SGD 文檔 §6 Route2（Withdraw）**。兩者清單不完全一致（見末段落差表）。

### 5.1 代收 `sender.bankCode`（V2，依 paymentType 分段）

官方 SGD V2 §6 依 **paymentType 分段**列銀行；**同一銀行碼在不同 paymentType 下代收支援可能相反**（如 `MARB`：P2P 下 ❌代收、QR 下 ✅代收）。

**SGD P2P（`sender.bankCode` 必填）**：僅 `DBS`/`UOB`/`OCBC` 支援代收。

| 代碼 | 銀行名稱 | 代收 |
|---|---|---|
| `DBS` | The Development Bank of Singapore | ✅ |
| `UOB` | United Overseas Bank | ✅ |
| `OCBC` | Oversea-Chinese Banking Corporation | ✅ |

**SGD QR（純掃碼，`sender` 不帶 `bankCode`）**：僅 `MARB`（Mari Bank）代收 ✅。

**SGD QRKYC（`sender.username` 必填）**：官方 §6 列為 **All Bank Code above**（＝ V2 §6「SGD P2P」段全部銀行碼），代收 ✅。

### 5.2 代付 `data.bankName`（V1，舊 SGD 文檔 §6 Route2 Withdraw）

官方舊 SGD 文檔 §6「Route2（Withdraw）」的 **11 個代付碼**（此為 SGD 代付的權威清單）：

| 代碼 | 銀行名稱 |
|---|---|
| `PNSG` | Paynow Singapore |
| `DBS` | DBS Bank Limited |
| `UOB` | United Overseas Bank |
| `OCBC` | Oversea-Chinese Banking Corporation |
| `SCIMB` | CIMB Bank Berhad |
| `SHSBC` | HSBC Singapore |
| `SSCB` | Standard Chartered Bank |
| `SMBB` | Maybank |
| `RHBSG` | RHB Bank Berhad |
| `CITISG` | Citibank Berhad |
| `ICBCSG` | Industrial and Commercial Bank of China |

> 三方碼由我方 `withdrawal_orders.bank_code` 經 `available_banks`（`BankCode_ELG` → `BankCode_Third`）映射取得。

### 5.3 ⚠️ config 代付銀行清單落差（待商戶確認）

現行 `Data/providers/DgPay.php` 的 `withdrawal` SGD `banks` 取自 **SGD V2 文檔** §6「SGD P2P」Withdraw ✅ 那套（14 碼），與上表（舊 SGD 文檔 11 碼）**不一致**：

| 差異 | 碼 | 處置建議 |
|---|---|---|
| config 有、舊 SGD 代付文檔**無** | `GXSB`（GXS Bank）、`MARB`（Mari Bank）、`ANBK`（ANEXT）、`MPP`（Match Move Pay） | 代付走 V1，這 4 碼可能不被 `/withdraw` 接受——上線前與 DgPay CS 確認；未確認前送出恐 `-175`/`-180` |
| 舊 SGD 代付文檔有、config **無** | `PNSG`（Paynow Singapore） | 若商戶支援 PayNow 代付，需補進 config |

> 銀行清單變更影響會員可選代付銀行（`available_banks` 生成），屬營運決策——**本次僅記錄落差，未擅改 config `banks`**。待商戶/PM 確認實際支援碼後再調整。

### 5.4 代收 `sender` 欄位依 paymentType 之差異（官方 SGD V2 §5 Sender Info）

> 官方 §5 註明「With different currencies, some params may be mandatory, please contact CS to confirm」——下表為文檔標示，實際以商戶協議為準。（此為**代收** V2 的 `sender`；代付走 V1 用 `data`，見 §3。）

| `sender` 欄位 | 類型 | P2P | QR | QRKYC | 說明 |
|---|---|:---:|:---:|:---:|---|
| `senderFirstName` | string | ⚪ | ⚪ | ⚪ | Sender bank/wallet name |
| `senderLastName` | string | ⚪ | ⚪ | ⚪ | Sender bank/wallet name |
| `senderNo` | string | ⚪ | ⚪ | ⚪ | Sender bank/wallet number |
| `bankCode` | string | ✅ 必填 | ❌ | ⚪ | Sender bank code（§6）；官方標「required for P2P」 |
| `username` | string | ❌ | ❌ | ✅ 必填 | 商戶會員帳號；官方標「only for QRKYC」 |
| `phone` | string | ⚪ | ⚪ | ⚪ | Sender phone number |

（⚪＝官方標非必填／視商戶而定；✅＝該 paymentType 必填；❌＝該 paymentType 不適用）

---

## 6. 限額與費率參考（SGD）

各商戶實際開通的限額以後台設定為準；以下為工單記錄的典型值。

| 通道 | 費率 | 最低 | 最高 |
|---|---|---|---|
| 代收（V2 P2P） | 依商戶協議 | SGD 50 | SGD 5,000 |
