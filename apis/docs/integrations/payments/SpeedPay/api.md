# SpeedPay (SE PayCenter / G.B.P PayCenter) API 整合文檔

> 來源文件：`SE_PayCenter_API__v2026.001_.pdf`（PDF 內部標題「SE PayCenter API (v2026.001)」，
> 共 56 頁、中英雙語；系統代號 G.B.P PayCenter，商戶對外品牌名為 SpeedPay）。
> 原始附件見工單 [target/tickets#3557](http://example.invalid:1688/target/tickets/-/work_items/3557)。

## 基本信息

- **金流商名稱**: SpeedPay (SE PayCenter / G.B.P PayCenter)
- **文件版本**: v2026.001
- **開通幣別**: MYR（馬來西亞）
- **相關工單**: #3557（舊系統代收配置）、#4172（新 APIs 代收代付配置）
- **簽名方式**: MD5（欄位升冪排序，`name:value` 以 `&` 拼接，末端拼 `key:{MD5_KEY}`，UTF-8 MD5 轉大寫）
- **編碼方式**: 業務參數組 JSON 後 Base64 Encode，置於通用請求的 `request` 欄位

## 商戶基本資料

`agent_cash_flows.info` 欄位對照：

| info 鍵名 | 對應三方參數 | 說明 |
|---|---|---|
| `API_URL` | — | 三方 API base host（**不含端點路徑**，由 `PayMateTrait::sendRequestRaw` 以 `API_URL . $path` 拼接） |
| `MERCHANT_NO` | `merchantNo` | 商戶號 |
| `MD5_KEY` | `key` | 簽名密鑰（請求簽名、回應驗簽、Notify 驗簽三處同一把） |
| `ENDPOINT_BY_PAYMENT_CODE` | — | 本專案自訂：`paymentCode → 代收端點` 商戶專屬對照，見下方「與 PayMate 的差異」 |
| `available_banks` | `bankCode` | 代付銀行對照（`BankCode_ELG` → `BankCode_Third`） |

> **憑證值不落文檔**：`MERCHANT_NO` 與 `MD5_KEY` 的實值見工單 #3557 商戶資訊區，
> 以及 `Data/providers/SpeedPay.php` 同步進 DB 後於代理後台填入。版控內一律留空。

## 本商戶帳號實際開通資訊

商戶號 `SPMVW`（VWorld MYR 站）。

**已開通代收通道（依 #3557 商戶開通通知）**

| paymentCode | 說明 | 實際端點 |
|---|---|---|
| `DUITNOWP2P` | 通用碼掃碼 / eWalletQR | `/qrh5` |
| `P2PDEPOSIT` | 網銀直連 / 網銀直通車 | `/qrh5` |

> 🔴 上表為 #3557 的**通知內容**。2026-08-24 實測 `DUITNOWP2P` 三方回 `0901 No payment channels`
> ⇒ **實際只有 `P2PDEPOSIT` 可用**，落差詳見 [`integration.md`](integration.md) §1。

**代付**：#3557 未涵蓋代付開通資訊；本商戶在 VWorld 舊系統**無任何代付配置、無代付實績**。
上線前須向 SpeedPay 確認代付開通狀態與實際可用銀行清單（可用 §3.4 `/setting` 查
`payoutStatus` 與 `payments[].supportBankCode` 自行驗證）。

**IP 白名單**：#3557 提供 31 個三方來源 IP 需加白（回調來源）。清單見該工單，屬 ops 動作。

**商戶後台**：`https://example.invalid`（首次登入需綁 Google Authenticator；密鑰與密碼可於後台自行變更）。

## 三方文檔比對

> 我方接入時的踩坑、已驗證落差與維運注意事項一律寫在同目錄的
> [`integration.md`](integration.md)，**排查前請優先參閱該檔**——它比廠商原文更貼近真實行為
> （例：工單明列的 `DUITNOWP2P` 實測未開通、廠商未記載的業務碼 `0901`、付款連結僅 180 秒有效）。
> 本檔只保留廠商原文與版次比對。

### 與 PayMate（v2026.002）的版本差異

兩份文檔為同一份規格的不同版次，v2026.001 是 v2026.002 的**真子集**：

| 項目 | v2026.001（SpeedPay） | v2026.002（PayMate） |
|---|---|---|
| §1／§2 通用請求與簽名 | 相同 | 相同 |
| §3.1–3.4 API 列表 | 相同 | 相同 |
| §4 Notify | 相同 | 相同 |
| §5.1／§5.2 代碼表 | 相同 | 相同 |
| §5.3 PaymentCode 渠道代碼表 | 相同 | 相同 |
| §5.4 MY 區銀行 | **35 家** | 38 家（多 `BIGP`／`BOOB`／`BOOE`） |

前 35 家逐碼、逐名稱完全相同（含原文誤譯「CIMB Bank 泰國聯昌銀行」）。
故代付銀行配置取兩者交集 19 家（PayMate 已驗證可用者中，排除 v2026.001 未收錄的 `BOOB`）。

**上表的重驗方法**（2026-08-21 實測）：兩份 PDF 以 `pdftotext -layout -enc UTF-8` 抽文字後正規化
——去分頁標記行（`^\s*(CH|EN)\s*[0-9]+\s*$`）、壓縮連續空白為單一空格、刪空行——再 `diff`，
結果為 **1197 行 → 1201 行、僅 4 行純新增（1 行版本歷史 ＋ `BIGP`／`BOOB`／`BOOE` 三家），零刪除零修改**。
未正規化的 `diff -u` 會出現約 90 行變更，全為 `-layout` 的欄位對齊與分頁位移噪音，不是規格差異。

## 請求/回應流程

1. 會員選擇支付方式、我方建立訂單 → 呼叫 SpeedPay API（`/qrh5`、`/payout`、`/query`、`/setting`）
2. SpeedPay 回傳跳轉網址 `resultUrl` → 我方導轉會員至該網址完成支付
3. 訂單狀態變更時，SpeedPay 主動以 [§4 系統通用回調通知](#4-系統通用回調通知-notify) 通知，我方須回應純文字 `OK`

## 1. 通用 Request

每支 API 的請求格式固定，隨 API 改變的只有加密 `request` 的內容與請求網址。

| 參數名 | 參數示例 | 說明 |
|---|---|---|
| version | `1.0` | 系統版本號，目前最新為 `1.0` |
| merchantNo | `GBP111222` | 商戶號，註冊完畢後提供 |
| signMsg | — | MD5 驗簽，長度 32，方法見 §1.1 簽名說明 |
| request | — | 訂單內容，依 §3 API 列表組成 JSON 後 Base64 Encode |

### 1.1 簽名規則（Request）

參數依名稱字首升冪排序 A-Z（`merchantNo`、`request`、`version`），用 `&` 拼接，
參數名與參數值用 `:` 拼接，最後拼上商戶密鑰 `key`（**不含 `signMsg` 本身**），無任何空格：

```
merchantNo:{value}&request:{value}&version:{value}&key:{value}
```

再將拼接字串做 UTF-8 的 MD5 加密，**全部轉為大寫**。

### 1.2 請求組建範例（以 §3.1 存款為例）

Step 1. 組 request body JSON：

```json
{
    "paymentCode":"ALIPAYQR",
    "memberCode": "member123",
    "orderNo" : "ABC123456789",
    "orderTime" : "20101231010100",
    "amount" : "100.01",
    "returnUrl" : "http://example.invalid",
    "notifyUrl" : "http://example.invalid"
}
```

Step 2. 將 JSON 做 Base64 Encode。

Step 3. 以 `application/x-www-form-urlencoded` POST：`version`、`merchantNo`、`signMsg`、`request`。

## 2. 通用 Response

| 參數名 | 參數示例 | 說明 |
|---|---|---|
| version | `1.0` | 系統版本號 |
| merchantNo | `GBP111222` | 商戶號 |
| signMsg | — | MD5 驗簽，長度 32，方法見 §2.1 |
| resCode | `000` | 系統回應三位數代碼，見 §5.1 商戶檢驗回應代碼表 |
| resMsg | `成功` | 系統回應代碼說明 |
| response | — | 請求結果內容，依 §3 API 列表的回應 JSON，為 Base64 Decode |

> PDF §2.1 的 `resCode` 參數示例誤植為 `200`，但 §5.1 代碼表與拼接示例皆為 `000`＝成功。
> 實作以 `000` 為準（`PayMateTrait::sendRequest` 即判 `resCode !== '000'`）。

### 2.1 簽名規則（Response，驗簽用）

參數依名稱字首升冪排序 A-Z（`merchantNo`、`resCode`、`resMsg`、`response`、`version`），
拼接規則同 §1.1，最後拼上 `key`（不含 `signMsg`）：

```
merchantNo:{value}&resCode:{value}&resMsg:{value}&response:{value}&version:{value}&key:{value}
```

## 3. API 列表

### 3.1 存款 Deposit

建立充值訂單。

| | |
|---|---|
| API URL | `https://{{API-URL}}/qrh5` |
| HTTP Method | POST |

**API Request**（組 JSON 後 Base64 Encode 放入通用請求的 `request`）

| 參數名稱 | 必傳 | 參數值 | 說明 |
|---|---|---|---|
| bankCode | No | `CIMB` | 直接跳轉到對應的銀行（不輸入為正常選擇銀行畫面）。*緬甸通道不用填、澳洲通道必填 |
| amount | Yes | `100.00` | 金額，限額依據商戶設定，小數點兩位 |
| paymentCode | Yes | `GCASHORG` | 支付產品代碼，見 §5.3 渠道代碼表 |
| orderNo | Yes | `Order123456789` | 商戶訂單號，不能重複 |
| orderTime | Yes | `20101231032055` | 商戶系統發起訂單時間，格式 `yyyyMMddHHmmss` |
| returnUrl | Yes | — | 支付完畢後跳轉網址 |
| notifyUrl | Yes | — | 通知商戶系統訂單完成網址 |
| memberCode | Yes | `ken00123` | 提交訂單的會員帳號，可去識別化。*緬甸通道請提交空白 |
| note | No | `123456789` | 備註，字數 100 以內 |
| merchantName | No | — | 顯示於訂單內容的商戶名稱，字數 15 以內，限英文數字中文 |
| realName | No | — | 存款用戶真實姓名（僅支援 Australia／Thailand／Korea） |
| realAccount | No | — | 存款帳號（僅 Thailand／Korea） |

**API Response**（通用 Response 的 `response` 解出的 JSON）

| 參數名稱 | 參數值 | 說明 |
|---|---|---|
| code | `0000` | 四位數 API 處理代碼，見 §5.2；`0000` 代表成功，其他皆為失敗 |
| msg | `訂單建立成功` | API 處理代碼說明 |
| amount | `100.00` | 此筆訂單的交易金額 |
| RealOrderAmount | `99.99` | 實際交易金額 |
| orderNo | `Order123456789` | 商戶提交的訂單號 |
| gbpOrderNo | `190101185511101` | 三方系統訂單號，不會重複 |
| createTime | `20101231032055` | 三方系統建立訂單時間 |
| resultUrl | — | 支付網址，須提供給會員進行支付 |
| QrCode | — | QR 支付方式內容（`PROMPTPAY`、`TRUEMONEY`） |

### 3.2 代付 Payout

建立代付訂單。

| | |
|---|---|
| API URL | `https://{{API-URL}}/payout` |
| HTTP Method | POST |

**API Request**

| 參數名稱 | 必傳 | 參數值 | 說明 |
|---|---|---|---|
| amount | Yes | `100.00` | 金額，限額依據商戶設定，小數點兩位 |
| bankCode | Yes | `ICBK` | 會員提款銀行代碼，見 §5.4 銀行代碼表 |
| bankBranch | No | `北京北京分行` | 分行名稱 |
| bankAccountNo | Yes | — | 會員提款銀行帳號 |
| bankAccountName | Yes | — | 會員提款銀行戶名 |
| orderNo | Yes | `Order123456789` | 商戶訂單號，不能重複 |
| orderTime | Yes | `20101231032055` | 商戶系統發起訂單時間，格式 `yyyyMMddHHmmss` |
| notifyUrl | Yes | — | 通知商戶系統訂單完成網址 |
| memberCode | Yes | `ken00123` | 提交訂單的會員帳號，可去識別化 |

**API Response**

| 參數名稱 | 參數值 | 說明 |
|---|---|---|
| code | `0000` | 四位數 API 處理代碼，見 §5.2；`0000` 代表成功，其他皆為失敗 |
| msg | `訂單建立成功` | API 處理代碼說明 |
| amount | `100.00` | 此筆訂單的交易金額 |
| orderId | `Order123456789` | 商戶提交的訂單號（**注意：代付回應用 `orderId`，非 `orderNo`**） |
| gbpOrderNo | `190101185511101` | 三方系統訂單號，不會重複 |
| createTime | `20101231032055` | 三方系統建立訂單時間 |

> 注意：v2024.005 已刪除出款訂單 Response 的 `Fee` 欄位，本版次（v2026.001）同樣不含該欄位。

### 3.3 訂單查詢 Query

| | |
|---|---|
| API URL | `https://{{API-URL}}/query` |
| HTTP Method | POST |

**API Request**

| 參數名稱 | 必傳 | 參數值 | 說明 |
|---|---|---|---|
| service | Yes | `PAYOUT` | `QRH5`：存款訂單；`PAYOUT`：出款訂單 |
| orderNo | — | `Order123456789` | 商戶訂單號，與 `gbpOrderNo` 二擇一 |
| gbpOrderNo | — | `123456759` | 三方系統訂單號，與 `orderNo` 二擇一 |

**API Response**

| 參數名稱 | 參數值 | 說明 |
|---|---|---|
| code | `0000` | 四位數 API 處理代碼，見 §5.2 |
| msg | `訂單建立成功` | API 處理代碼說明 |
| service | `QRH5` | 服務名稱 |
| paymentCode | `UNIONPAYQR` | `service` 為 `QRH5` 時不為空 |
| bankCode | — | `service` 為 `QRH5` 時為空 |
| status | `SUCCESS` | `PENDING` 初始建立／`PROCESSING` 處理中／`PAYING` 處理中（客戶已開啟收銀台）／`SUCCESS` 成功／`FAIL` 失敗 |
| amount | `100.00` | 此筆訂單的交易金額 |
| orderNo | `Order123456789` | 商戶提交的訂單號 |
| gbpOrderNo | `190101185511101` | 三方系統訂單號 |
| createTime | `20101231032055` | 三方系統建立訂單時間 |
| lastUpdateTime | `20101231032056` | 三方系統最後更新訂單時間 |

### 3.4 商戶設定查詢 Setting

| | |
|---|---|
| API URL | `https://{{API-URL}}/setting` |
| HTTP Method | POST |

**API Request**

| 參數名稱 | 必傳 | 參數值 | 說明 |
|---|---|---|---|
| queryTime | Yes | `20101231032055` | 商戶系統發起查詢時間，格式 `yyyyMMddHHmmss` |

**API Response**（預設 Base64 Encode，可改為 RSA）

| 參數名稱 | 參數值 | 說明 |
|---|---|---|
| code | `0000` | 四位數 API 處理代碼，見 §5.2 |
| msg | `查詢成功` | API 處理代碼說明 |
| availableBalance | `1000.00` | 目前可代付金額（需三方後台開啟設定才顯示） |
| totalBalance | `1100.00` | 目前錢包總餘額（需三方後台開啟設定才顯示） |
| merchantStatus | `ON` | `ON` 啟用／`OFF` 停用 |
| depositStatus | `ON` | `ON` 啟用／`OFF` 停用 |
| payoutStatus | `ON` | `ON` 啟用／`OFF` 停用 |
| payments[].name | `PERBANK` | 服務名稱 |
| payments[].supportBankCode | `ICBK,ABC,CCB,CMBC` | 該條線路支援銀行列表以 `,` 分隔；掃碼支付類型則為空 |
| payments[].minAmount | `10.00` | 單筆最小限額 |
| payments[].maxAmount | `100.00` | 單筆最高限額 |
| payments[].todayLimit | `1000000.00` | 單日最高限額 |
| payments[].totalAmount | `18520.00` | 當日實時累積交易額 |
| payments[].status | `ON` | `ON` 啟用／`OFF` 停用 |

> 本專案已實作 `/setting`：`PayMate\Setting` ＋ `PayMate\Driver::merchantSetting()`，
> 由 `artisan cash-flow:third-party-query <acf id>` 呼叫（唯讀，另比對我方 `info` 的限額與
> `available_banks`）。此 API 是查證 **代付是否開通**（`payoutStatus`）與**實際可用銀行**
> （`supportBankCode`）的官方管道。

## 4. 系統通用回調通知 Notify

回調涵蓋支付與代付訂單，於訂單成功或失敗時通知我方變更訂單狀態，方式為 HTTP FORM POST。
**處理中或結果未確認的訂單不會通知。** 我方回應 `OK` 後不再通知；未回 `OK` 將持續通知 10 次，間隔為：

| 次數 | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 |
|---|---|---|---|---|---|---|---|---|---|---|
| 間隔 | 即時 | 30 秒 | 30 秒 | 1 分 | 1 分 30 秒 | 2 分 30 秒 | 4 分 | 6 分 30 秒 | 10 分 30 秒 | 16 分 30 秒 |

總歷時 44 分鐘，超過後放棄通知，須至商戶後台手動同步訂單或聯繫三方協助。
**三方通知來源 IP 須加白名單。**

### 4.1 Notify Request

| 參數名稱 | 參數值 | 說明 |
|---|---|---|
| signMsg | — | 簽名結果 |
| version | `1.0` | 版本號 |
| notifyTime | `20190101130125` | 通知時間 |
| content | — | JSON 內容，預設 Base64 Encode |

`content` 解出後的欄位：

| 參數名稱 | 參數值 | 說明 |
|---|---|---|
| service | `Nocard` | 服務名稱 |
| merchantNo | `GBP999999` | 商戶號 |
| paymentCode | — | `service` 為 `QRH5` 時不為空 |
| bankCode | `ABOC` | `service` 為 `QRH5` 時為空 |
| status | `SUCCESS` | `SUCCESS` 成功／`FAIL` 失敗（**僅此二態，無中間態**） |
| amount | `100.00` | 此筆訂單的交易金額 |
| orderNo | `Order123456789` | 商戶提交的訂單號 |
| gbpOrderNo | `NOCA1000123` | 三方系統訂單號，不會重複 |
| createTime | `20101231032055` | 三方系統建立訂單時間，**時區為 GMT+8** |
| iplocation | `TW` | 下單端 IP 國別代碼 |
| ipaddress | `0.0.0.0` | 下單端 IP |

### 4.2 Notify 簽名驗證

```
content:{{content}}&notifyTime:{{notifyTime}}&version:{{version}}&key:{{key}}
```

`key` 與通用請求簽名、通用回應驗簽用的 MD5 密鑰相同。驗簽不符即可能為偽造通知，**務必驗證**。

### 4.3 通知成功商戶回應字串

須回傳純文字 `OK`，系統才不會持續通知。

## 5. 附錄

### 5.1 商戶檢驗回應代碼表（通用回應 resCode）

| resCode | resMsg |
|---|---|
| 000 | 成功 |
| 101 | API 不存在 |
| 202 | 查無商戶號 |
| 203 | 商戶停用中 |
| 204 | 商戶鎖定中 |
| 205 | 商戶凍結中 |
| 206 | 商戶配置錯誤 |
| 301 | version 參數錯誤 |
| 302 | merchantNo 參數錯誤 |
| 303 | request 參數錯誤 |
| 304 | signMsg 錯誤 |
| 401 | 驗簽失敗 |
| 402 | 加解密失敗 |
| 403 | 密文格式錯誤 |
| 404 | 密文格式拼接錯誤 |
| 999 | 系統內部錯誤 |

> **代付例外分類**：`101`／`202`–`206`／`301`–`304`／`401`–`404` 屬「商戶／參數／密文檢驗」類，
> 可證明請求未進單，列入 `PayMate\Withdrawal::ENVELOPE_REJECT_CODES` 白名單判 `-2` 還原可重送。
> **`999`（系統內部錯誤）與所有未知碼不得入白名單**——發生在請求抵達之後，不能排除已建單。

### 5.2 訂單檢驗回應代碼表（各 API response code）

| code | 說明 |
|---|---|
| 0000 | 成功 |
| 01XX - 02XX | 訂單建立錯誤 |
| 20XX | 公對私錯誤 |
| 21XX | 快捷支付錯誤 |
| 22XX | 掃碼錯誤 |

### 5.3 PaymentCode 渠道代碼表（大寫英文）

**MY（馬來西亞）** — 本商戶僅開通 `P2PDEPOSIT`、`DUITNOWP2P`

| 代碼 | 說明 |
|---|---|
| P2PDEPOSIT | 網銀直連 / 網銀直通車 |
| DUITNOWP2P | 通用碼掃碼 / eWalletQR |
| DUITNOWQR | 原生掃碼通道 |
| FPX | FPX |
| REFQR | 水單匹配 |

其餘地區（TH／PH／SG／MM／AU）之渠道代碼見原始 PDF §5.3，本商戶未開通、未轉錄。

### 5.4 銀行代碼表（大寫英文）

**MY（馬來西亞）** — v2026.001 共 35 家

| 銀行代碼 | 銀行名稱 |
|---|---|
| MAYB | Maybank 馬來亞銀行 |
| CIMB | CIMB Bank 泰國聯昌銀行 |
| PBBB | Public Bank 大眾銀行 |
| RHBB | RHB Bank 興業銀行 |
| HOLB | HONG LEONG BANK 豐隆銀行 |
| AFIN | AFFIN ISLAMIC BANK 艾芬銀行 |
| SINA | BANK SIMPANAN NASIONAL 國民儲蓄銀行 |
| AAAA | bank of america 美國銀行 |
| AGRO | BANK PERTANIAN 農業銀行 |
| ALLI | ALLIANCE BANK 馬來西亞聯合銀行 |
| BAKO | Bangkok Bank Malaysia 泰國盤谷銀行 |
| BNPB | BNP PARIBAS MALAYSIA 法國巴黎銀行 |
| CCCC | bank of china 中國銀行 |
| CITI | CITIBANK BERHAD 花旗銀行 |
| DEUT | DEUTSCHE BANK 德意志銀行 |
| HSBC | HSBC BANK 匯豐銀行 |
| ICBC | INDUSTRIAL & COMMERCIAL BANK OF CHINA 工商銀行 |
| JPMB | J.P. MORGAN CHASE BANK 摩根大通銀行 |
| KFHB | KUWAIT FINANCE HOUSE 科威特金融銀行 |
| MBSB | MBSB Bank 馬屋銀行 |
| MCCB | CHINA CONST BK (M) BHD 建設銀行 |
| MIZU | MIZUHO BANK 瑞穗銀行 |
| MUAM | BANK MUAMALAT 回教教義銀行 |
| MUFG | MUFG BANK 三菱日聯銀行 |
| MYAB | AmBank 大馬銀行 |
| MYIS | Islam bank 馬來西亞伊斯蘭銀行 |
| OCBC | OCBC Bank 華僑銀行 |
| RAJH | AL-RAJHI BANK 拉吉哈銀行 |
| RAKY | RAKYAT BANK 馬來西亞人民銀行 |
| STCB | STANDARD CHARTERED BANK 渣打銀行 |
| SUMB | SUMITOMO MITSUI BANKING 三井住友銀行 |
| UNOB | UNITED OVERSEAS BANK 大華銀行 |
| TNGP | TOUCH N GO eWALLET |
| GXBK | GXBANK |
| RYTB | RYT BANK |

> **`BIGP`／`BOOB`／`BOOE` 不在 v2026.001**（為 v2026.002 新增），
> 故本商戶代付配置不含 Boost Bank。

其餘區域（TH／PH／SG／MM／VN／AU）之銀行代碼見原始 PDF §5.4，本商戶僅開通 MYR、未轉錄。

## 注意事項

1. **`code` 與 `resCode` 是兩層**：`resCode`（三位數）是信封層／商戶檢驗，`code`（四位數）是業務層。
   兩層都要判：`resCode='000'` 才代表信封有效，`code='0000'` 才代表業務成功。
2. **簽名密鑰三處共用**：請求簽名、回應驗簽、Notify 驗簽同一把 `MD5_KEY`。
3. **回調時區為 GMT+8**（`createTime`），我方 DB 存 UTC，對帳切口需換算。
4. **回調僅 `SUCCESS`／`FAIL` 二態**，無中間態，不需處理 pending 回調。
5. **金額小數兩位**：送單前以 `Common::numFloor($amount, 2)` 處理。
6. **代付回應欄位是 `orderId`**（非 `orderNo`），但三方單號一律取 `gbpOrderNo`。

## 實作檔案

SpeedPay 與 PayMate 為同協定的兩組商戶，**共用 PayMate 實作**，不另立 Provider 目錄：

| 檔案 | 說明 |
|---|---|
| `config/payment.php` | `'SPEEDPAY' => Providers\PayMate\Driver::class` |
| `app/Services/Payment/Providers/PayMate/` | Driver／Deposit／DepositCallback／Withdrawal／WithdrawalCallback／QueryOrder／PayMateTrait |
| `app/Console/Commands/CashFlow/Data/providers/SpeedPay.php` | 本商戶配置（憑證、端點對照、通道費率、代付銀行） |
| `app/Console/Commands/CashFlow/Data/cash_flows.php` | `cash_flows` 127（`D_SPEEDPAY`）／128（`W_SPEEDPAY`） |

同協定另一組商戶的文檔見 `docs/integrations/payments/PayMate/api.md`（v2026.002）。
