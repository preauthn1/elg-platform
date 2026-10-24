# PayMate (SE PayCenter / G.B.P PayCenter) API 整合文檔

> 來源文件：`PayMate API (v2026.002).pdf`（原文檔標題為「SE PayCenter API」，系統代號 G.B.P PayCenter，商戶對外品牌名為 PayMate）

## 基本信息

- **金流商名稱**: PayMate (SE PayCenter / G.B.P PayCenter)
- **文件版本**: v2026.002
- **加密方式**: 簽名 MD5（UTF-8、全大寫），內容加密預設 Base64 Encode（業務層可協商改為 RSA，代收/代付各自獨立設定）
- **商戶號格式**: `GBP` 開頭，如 `GBP111222`

## 商戶基本資料

開通成功後由 PayMate 提供：

| 資料名稱 | 說明 |
|---|---|
| 商戶號 (merchantNo) | 系統中唯一，`GBP` 開頭 |
| 商戶 MD5 密鑰 (key) | 全大寫、長度 32 的大寫英文數字，用於加簽/驗簽 |
| 商戶開通產品列表 | 記載申請的產品、費率、線路類型，可透過 [3.4 商戶設定查詢](#34-商戶設定查詢-setting) 動態取得 |
| 商戶後台網址 | 查詢訂單、結算申請、查看設定 |
| API 請求網址 (`{{API-URL}}`) | 提交交易請求與訂單查詢用 |
| 系統通知 IP | 需將 PayMate 提供的 4 組 IP 加入白名單，用於接收回調通知 |

**白名單**：須將 PayMate 回調服務器 IP 加白，才能正常接收存/提款訂單狀態更新通知。

## 本商戶帳號實際開通資訊

> 以下為 PayMate 針對本商戶帳號（`PM134126`）另行提供的實際數值，PDF 原文未涵蓋，故另立本節記錄（仍屬廠商提供的一手資料，非我方詮釋）。

- **API 請求網址**：`https://publicapi.paymate.solutions`
- **代收端點依 paymentCode 而異**（api.md 原文僅記載 `/qrh5`，以下為 PayMate 另行提供）：
  - `DUITNOWP2P` → `{API-URL}/qrh5`
  - `P2PDEPOSIT` → `{API-URL}/cashier`
- **已開通通道代碼**（見 [5.3 PaymentCode 渠道代碼表](#53-paymentcode-渠道代碼表-大寫英文)）：`DUITNOWP2P`、`P2PDEPOSIT`
- **回調服務器 IP 白名單**（需加白才能正常接收存/提款訂單狀態通知）：
  `0.0.0.0`、`0.0.0.0`、`0.0.0.0`、`0.0.0.0`、`0.0.0.0`、
  `0.0.0.0`、`0.0.0.0`、`0.0.0.0`、`0.0.0.0`、`0.0.0.0`、
  `0.0.0.0`、`0.0.0.0`、`0.0.0.0`、`0.0.0.0`、`0.0.0.0`、
  `0.0.0.0`、`0.0.0.0`、`0.0.0.0`、`0.0.0.0`、`0.0.0.0`、
  `0.0.0.0`、`0.0.0.0`、`0.0.0.0`
- **限額**：
  - `DUITNOWP2P`（QR/eWallet）：Min RM 5，Max 依客戶電子錢包容量而定，無固定值
  - `P2PDEPOSIT`：Min RM 10，Max RM 30,000
  - `Payout`（代付）：Min RM 50，Max RM 4,999

## 踩坑筆記

### 端點與 PDF 原文不符

PayMate PDF 原文僅記載 `/qrh5` 為代收端點，但商戶帳號 `PM134126` 實際的端點配置由 PayMate 另行提供，與 PDF 不同：

| paymentCode | 實際端點 | PDF 原文說法 |
|---|---|---|
| `DUITNOWP2P` | `/qrh5` | 未明確標示（易誤以為統一走 `/cashier`） |
| `P2PDEPOSIT` | `/cashier` | `/qrh5` |

> 實作時**不可直接依 PDF 端點列表配置**，須以 PayMate 針對本商戶另行提供的資料為準。

### §5.3 PaymentCode 渠道代碼表為廠商全局清單，非本商戶已開通清單

`DUITNOWQR`、`FPX`、`REFQR` 等代碼雖出現在 §5.3 MYR 欄位，但本商戶（`PM134126`）**未開通**，切勿自行加入通道配置。已開通通道須以 PayMate 帳號開通通知為準。

## 請求/回應流程

1. 商戶客戶選擇支付方式、商戶建立訂單 → 呼叫 PayMate API（`/qrh5`、`/perbank`、`/bizbank`、`/nocard`、`/payout` 等）
2. PayMate 回傳跳轉網址（`resultUrl`）給商戶 → 商戶導轉客戶至該網址完成支付
3. 訂單狀態變更時，PayMate 主動以 [4. 系統通用回調通知](#4-系統通用回調通知-notify) 通知商戶，商戶需回應 `OK`

## 1. 通用 Request

每次 API 請求格式固定，僅依 API 不同改變加密 `request` 內容與請求網址。

| 參數名稱 | 說明 |
|---|---|
| version | 系統版本號，目前為 `1.0` |
| merchantNo | 商戶號 |
| signMsg | MD5 驗簽，長度 32，詳見下方簽名說明 |
| request | 訂單內容組成 JSON 後 Base64 Encode（預設；可改 RSA，代收/代付獨立設定） |

### 1.1 簽名規則（Request）

參數依名稱字首升冪排序 A-Z（`merchantNo`、`request`、`version`），用 `&` 拼接，參數名與參數值用 `:` 拼接，最後拼上商戶密鑰 `key`（**不含 `signMsg` 本身**），格式如下（無任何空格）：

```
merchantNo:{value}&request:{value}&version:{value}&key:{value}
```

再將拼接字串做 **UTF-8 的 MD5** 加密，結果**全部轉為大寫**。

### 1.2 請求組建範例（以 3.1 存款為例）

Step 1. 組 request body JSON：

```json
{
  "paymentCode": "ALIPAYQR",
  "memberCode": "member123",
  "orderNo": "ABC123456789",
  "orderTime": "20101231010100",
  "amount": "100.01",
  "returnUrl": "http://example.invalid",
  "notifyUrl": "http://example.invalid"
}
```

Step 2. Base64 Encode 整個 JSON → 作為 `request` 值

Step 3. 以 `application/x-www-form-urlencoded` POST：`version`、`merchantNo`、`signMsg`、`request`

## 2. 通用 Response

| 參數名稱 | 說明 |
|---|---|
| version | 系統版本號 |
| merchantNo | 商戶號 |
| signMsg | MD5 驗簽，詳見下方驗簽說明 |
| resCode | 系統回應三位數代碼，詳見 [5.1 商戶檢驗回應代碼表](#51-商戶檢驗回應代碼表-通用回應-rescode) |
| resMsg | 回應代碼說明 |
| response | 請求結果內容 JSON，Base64 Decode（預設） |

### 2.1 簽名規則（Response，驗簽用）

參數依名稱字首升冪排序 A-Z（`merchantNo`、`resCode`、`resMsg`、`response`、`version`），拼接規則同上，最後拼上 `key`（不含 `signMsg`）：

```
merchantNo:{value}&resCode:{value}&resMsg:{value}&response:{value}&version:{value}&key:{value}
```

UTF-8 MD5、轉大寫，比對是否與回應中的 `signMsg` 相符；不符則可能是被竄改的回應。

## 3. API 列表

### 3.1 存款 Deposit

建立充值訂單。

| | |
|---|---|
| **API URL** | `https://{{API-URL}}/qrh5` |
| **HTTP Method** | POST |

**API Request**（JSON 組好後依 1. 通用 Request 加密送出）

| 參數名稱 | 必傳 | 參數值範例 | 說明 |
|---|---|---|---|
| bankCode | No | `CIMB` | 直接跳轉到對應銀行（不填為正常選擇銀行畫面）；緬甸通道不用填，澳洲通道必填 |
| amount | Yes | `100.00` | 金額，限額依商戶設定，小數點兩位 |
| paymentCode | Yes | `GCASHORG` | 支付產品代碼，見 [5.3 PaymentCode 渠道代碼表](#53-paymentcode-渠道代碼表-大寫英文) |
| orderNo | Yes | `Order123456789` | 商戶訂單號，不能重複 |
| orderTime | Yes | `20101231032055` | 商戶系統發起訂單時間，格式 `yyyyMMddHHmmss` |
| returnUrl | Yes | `https://example.invalid` | 支付完畢後跳轉網址 |
| notifyUrl | Yes | `https://example.invalid` | 通知商戶系統訂單完成網址 |
| memberCode | Yes | `ken00123` | 提交訂單的會員帳號，可去識別化；緬甸通道不用填，請提交空白即可 |
| note | No | `123456789` | 備注，字數 100 以內 |
| merchantName | No | - | 顯示於訂單內容的商戶名稱，字數 15 以內，限英文數字中文 |
| realName | No | - | 存款用戶真實姓名（僅支援 Australia、Thailand、Korea） |
| realAccount | No | - | 存款帳號（僅 Thailand、Korea） |

**API Response**（`response` Base64 Decode 後的 JSON）

| 參數名稱 | 參數值範例 | 說明 |
|---|---|---|
| code | `0000` | 四位數 API 處理代碼，見 [5.2 訂單檢驗回應代碼表](#52-訂單檢驗回應代碼表-各個-api-response-code)，`0000` 為成功 |
| msg | 訂單建立成功 | API 處理代碼說明 |
| amount | `100.00` | 此筆訂單交易金額 |
| RealOrderAmount | `99.99` | 實際交易金額 |
| orderNo | `Order123456789` | 商戶提交的訂單號 |
| gbpOrderNo | `190101185511101` | PayMate 系統訂單號，不會重複 |
| createTime | `20101231032055` | PayMate 系統建立訂單時間 |
| resultUrl | - | 支付網址，請提供給客戶進行支付 |
| QrCode | - | QR 支付方式內容（`PROMPTPAY`、`TRUEMONEY`） |

### 3.2 代付 Payout

建立代付訂單。

| | |
|---|---|
| **API URL** | `https://{{API-URL}}/payout` |
| **HTTP Method** | POST |

**API Request**

| 參數名稱 | 必傳 | 參數值範例 | 說明 |
|---|---|---|---|
| amount | Yes | `100.00` | 金額，限額依商戶設定，小數點兩位 |
| bankCode | Yes | `ICBK` | 商戶客戶提款銀行代碼，見 [5.4 銀行代碼表](#54-銀行代碼表-大寫英文) |
| bankBranch | No | 北京北京分行 | 分行名稱 |
| bankAccountNo | Yes | - | 商戶客戶提款銀行帳號 |
| bankAccountName | Yes | - | 商戶客戶提款銀行戶名 |
| orderNo | Yes | `Order123456789` | 商戶訂單號，不能重複 |
| orderTime | Yes | `20101231032055` | 商戶系統發起訂單時間，格式 `yyyyMMddHHmmss` |
| notifyUrl | Yes | `https://example.invalid` | 通知商戶系統訂單完成網址 |
| memberCode | Yes | `ken00123` | 提交訂單的會員帳號，可去識別化 |

**API Response**

| 參數名稱 | 參數值範例 | 說明 |
|---|---|---|
| code | `0000` | 四位數 API 處理代碼，`0000` 為成功 |
| msg | 訂單建立成功 | API 處理代碼說明 |
| amount | `100.00` | 此筆訂單交易金額 |
| orderId | `Order123456789` | 商戶提交的訂單號 |
| gbpOrderNo | `190101185511101` | PayMate 系統訂單號，不會重複 |
| createTime | `20101231032055` | PayMate 系統建立訂單時間 |

> 注意：v2024.005 已刪除出款訂單 Response 的 `Fee` 欄位。

### 3.3 訂單查詢 Query

查詢充值/代付訂單。

| | |
|---|---|
| **API URL** | `https://{{API-URL}}/query` |
| **HTTP Method** | POST |

**API Request**

| 參數名稱 | 必傳 | 參數值範例 | 說明 |
|---|---|---|---|
| service | Yes | `PAYOUT` | `QRH5`: 存款訂單；`PAYOUT`: 出款訂單 |
| orderNo | - | `Order123456789` | 商戶訂單號，與 `gbpOrderNo` 二擇一 |
| gbpOrderNo | - | `123456759` | PayMate 系統訂單號，與 `orderNo` 二擇一 |

**API Response**

| 參數名稱 | 參數值範例 | 說明 |
|---|---|---|
| code | `0000` | 四位數 API 處理代碼，`0000` 為成功 |
| msg | 訂單建立成功 | API 處理代碼說明 |
| service | `QRH5` | 服務名稱 |
| paymentCode | `UNIONPAYQR` | `service` 為 `QRH5` 時不為空 |
| bankCode | - | `service` 為 `QRH5` 時為空 |
| status | `SUCCESS` | `PENDING`: 初始建立；`PROCESSING`: 處理中；`PAYING`: 處理中（客戶已開啟收銀台）；`SUCCESS`: 成功；`FAIL`: 失敗 |
| amount | `100.00` | 此筆訂單交易金額 |
| orderNo | `Order123456789` | 商戶提交的訂單號 |
| gbpOrderNo | `190101185511101` | PayMate 系統訂單號，不會重複 |
| createTime | `20101231032055` | PayMate 系統建立訂單時間 |
| lastUpdateTime | `20101231032056` | PayMate 系統最後更新訂單時間 |

### 3.4 商戶設定查詢 Setting

| | |
|---|---|
| **API URL** | `https://{{API-URL}}/setting` |
| **HTTP Method** | POST |

**API Request**

| 參數名稱 | 必傳 | 參數值範例 | 說明 |
|---|---|---|---|
| queryTime | Yes | `20101231032055` | 商戶系統發起查詢時間，格式 `yyyyMMddHHmmss` |

**API Response**（預設 Base64 Encode，可改為 RSA）

| 參數名稱 | 參數值範例 | 說明 |
|---|---|---|
| code | `0000` | 四位數 API 處理代碼，`0000` 為成功 |
| msg | 查詢成功 | API 處理代碼說明 |
| availableBalance | `1000.00` | 目前可代付金額（需後台開啟設定才顯示） |
| totalBalance | `1100.00` | 目前錢包總餘額（需後台開啟設定才顯示） |
| merchantStatus | `ON` | `ON`: 啟用 `OFF`: 停用 |
| depositStatus | `ON` | 代收啟用狀態 |
| payoutStatus | `ON` | 代付啟用狀態 |
| payments[].name | `PERBANK` | 服務名稱 |
| payments[].supportBankCode | `ICBK,ABC,CCB,CMBC` | 該條線路支援銀行列表，以 `,` 分割；掃碼支付類型則為空 |
| payments[].minAmount | `10.00` | 單筆最小限額 |
| payments[].maxAmount | `100.00` | 單筆最高限額 |
| payments[].todayLimit | `1000000.00` | 單日最高限額 |
| payments[].totalAmount | `18520.00` | 當日實時累積交易額 |
| payments[].status | `ON` | `ON`: 啟用 `OFF`: 停用 |

## 4. 系統通用回調通知 Notify

系統回調通知包含存款、代付訂單，於訂單成功或失敗時通知商戶更改訂單狀態。通知方式為 **HTTP FORM POST**；處理中或結果未確認的訂單不會通知；商戶回應 `OK` 後不再通知，未回應則持續通知共 **10 次**，總歷時 **44 分鐘**：

| 次數 | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 |
|---|---|---|---|---|---|---|---|---|---|---|
| 間隔 | 實時 | 30秒 | 30秒 | 1分鐘 | 1分30秒 | 2分30秒 | 4分鐘 | 6分30秒 | 10分30秒 | 16分30秒 |

超過後放棄通知，須到商戶後台手動同步訂單，或通知 PayMate 商務協助處理。**請將 PayMate 通知服務器 IP 加白**。

### 4.1 Notify Request

| 參數名稱 | 參數值範例 | 說明 |
|---|---|---|
| signMsg | - | 簽名結果 |
| version | `1.0` | 版本號 |
| notifyTime | `20190101130125` | 通知時間 |
| content (JSON, Base64 Encode) | - | 見下表 |

`content` 內容：

| 欄位 | 參數值範例 | 說明 |
|---|---|---|
| service | `Nocard` | 服務名稱 |
| merchantNo | `GBP999999` | 商戶號 |
| paymentCode | - | `service` 為 `QRH5` 時不為空 |
| bankCode | `ABOC` | `service` 為 `QRH5` 時為空 |
| status | `SUCCESS` | `SUCCESS`: 成功 `FAIL`: 失敗 |
| amount | `100.00` | 此筆訂單交易金額 |
| orderNo | `Order123456789` | 商戶提交的訂單號 |
| gbpOrderNo | `NOCA1000123` | PayMate 系統訂單號，不會重複 |
| createTime | `20101231032055` | PayMate 系統建立訂單時間，**時區為 GMT+8** |
| iplocation | `TW` | 下單客戶 IP 所在地國碼 |
| ipaddress | `0.0.0.0` | 下單客戶 IP |

### 4.2 Notify 簽名驗證

驗證 `signMsg` 與通知參數組成的字串進行 MD5 加密結果是否相符，`key` 與通用請求/回應簽名相同的 MD5 密鑰。

```
content:{{content}}&notifyTime:{{notifyTime}}&version:{{version}}&key:{{key}}
```

UTF-8 MD5、轉大寫；不相符則可能是偽造的通知請求，商戶必須驗簽。

### 4.3 通知成功商戶回應字串

商戶收到通知後**必須回傳 `OK`**（純文字），系統才不會持續通知。

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

### 5.2 訂單檢驗回應代碼表（各個 API response code）

| code | 說明 |
|---|---|
| 0000 | 成功 |
| 01XX - 02XX | 訂單建立錯誤 |
| 20XX | 公對私錯誤 |
| 21XX | 快捷支付錯誤 |
| 22XX | 掃碼錯誤 |

### 5.3 PaymentCode 渠道代碼表（大寫英文）

**馬來西亞**

| 代碼 | 說明 |
|---|---|
| P2PDEPOSIT | 網銀直連 / 網銀直通車 |
| DUITNOWP2P | 通用碼掃碼 / eWalletQR |
| DUITNOWQR | 原生掃碼通道 |
| FPX | FPX |
| REFQR | 水單匹配 |

**泰國**

| 代碼 | 說明 |
|---|---|
| PROMPTPAY | PromptPay 掃碼 |
| TRUEMONEY | TrueMoney 掃碼 |
| P2PDEPOSIT | 網銀直連 / 網銀直通車 |
| PUREBANK | 銀行轉帳 |

**菲律賓**

| 代碼 | 說明 |
|---|---|
| GCASHORG | GCash 原生 |
| GCASHP2P | GCash 直連 / 仿原生 |
| GCASHQR | GCash 掃碼 |
| GCASHBT | GCash 轉帳 |
| GCASHRN | GCash 實名轉帳 / 掃碼 |

**新加坡**

| 代碼 | 說明 |
|---|---|
| P2PDEPOSIT | 網銀直連 / 網銀直通車 |

**緬甸**

| paymentCode | Description |
|---|---|
| KBZPAY | KBZPAY |
| WAVEPAY | WAVEPAY |
| KBZPAYQR | KBZPAYQR |
| WAVEPAYQR | WAVEPAYQR |

**澳洲**

| 代碼 | 說明 |
|---|---|
| P2PDEPOSIT | 網銀直連 / 網銀直通車 |
| REALNAMEBANK | 實名存款 |
| REALNAMEC2C | 實名存款 + 附言 |

### 5.4 銀行代碼表（大寫英文）

代付 `bankCode` 依收款銀行所在地區選用，區域代號對照：`MY`（馬來西亞）、`TH`（泰國）、`PH`（菲律賓）、`SG`（新加坡）、`MM`（緬甸）、`VN`（越南）、`AU`（澳洲）、`KR`（韓國）。

**MY（馬來西亞）**

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
| BIGP | BigPay Malaysia Sdn Bhd |
| BOOB | Boost Bank Berhad |
| BOOE | Boost eWallet |

**TH（泰國）**

| 銀行代碼 | 銀行名稱 |
|---|---|
| KBANK | KASIKORNBANK PCL |
| BBL | BANGKOK BANK PUBLIC COMPANY LTD. |
| KTB | KRUNG THAI BANK PUBLIC COMPANY LTD. |
| SCB | THE SIAM COMMERCIAL BANK PUBLIC COMPANY |
| BAY | BANK OF AYUDHAYA PUBLIC COMPANY LTD. |
| CHAS | JPMorgan Chase Bank Bangkok Branch |
| TMB | TMB BANK PUBLIC COMPANY LTD. |
| CITI | CITI BANK N.A. |
| CIMB | CIMB THAI BANK PUBLIC COMPANY LTD. |
| UOVBTHBK | UNITED OVERSEAS BANK (THAI) PUBLIC COMPANY LTD. |
| MEGA | MEGA INTERNATIONAL COMMERCIAL BANK |
| BOA | BANK OF AMERICA NT SA |
| GSB | THE GOVERNMENT SAVING BANK |
| HSBC | THE HONGKONG SHANGHAI CORPORATION LTD. |
| DBBK | Deutsche Bank AG |
| GHB | GOVERNMENT HOUSING BANK |
| MHCB | MIZUHO CORPORATE BANK |
| SMBC | THE SUMITOMO MITSU BANKING CORPORATION |
| TISCO | TISCO Bank PCL |
| TCRB | THE THAI CREDIT RETAIL BANK |
| ICBKTHBK | INDUSTRIAL AND COMMERCIAL BANK OF CHINA (THAI) PCL |
| TBNK | Thanachart Bank Public Company Limited |
| LHBA | Land and Houses Bank |
| BNPP | BNP PARIBAS BANGKOK BRANCH |
| TIBT | ISLAMIC BANK OF THAILAND |
| BAAC | BANK FOR AGRICULTURE AND AGRICULTURAL CO-OPERATIVES |
| KKB | KIATNAKIN BANK PCL |
| BOC | Bank of China (Thai) PCL |
| SCBT | STANDARD CHARTERED BANK THAI PCL. |
| SMTB | Sumitomo Mitsui Trust Bank (Thai) PCL. |
| ANZA | ANZ Bank (Thai) Public Company Limited |
| IOBA | INDIAN OVERSEAS BANK |

**PH（菲律賓）**

| 銀行代碼 | 銀行名稱 |
|---|---|
| GCASH | GCash |
| ALB | AllBank (A Thrift Bank), Inc. |
| AUB | Asia United Bank Corporation |
| BDO | BDO Unibank, Inc. |
| BGB | BanKo, A Subsidiary of BPI |
| BMB | Bangko Mabuhay |
| BOC | Bank of Commerce |
| BPI | BPI/ BPI Family Savings Bank |
| CBC | China Banking Corporation |
| CBS | China Bank Savings, Inc |
| CCB | CTBC Bank (Philippines) Corporation |
| CIS | Bayad |
| CLB | Cebuana Lhuillier Bank / Cebuana Xpress. |
| CMG | Camalig Bank |
| CPI | CIMB Philippines, Inc. |
| CRD | CARD Bank Inc. |
| DBI | Dungganon Bank (A Microfinance Rural Bank), Inc. |
| DBP | Development Bank of the Philippines |
| DCB | Dumaguete City Development Bank |
| DCP | Coins.ph (DCPay) |
| EQB | Equicom Savings Bank, Inc. |
| EWB | East West Banking Corporation |
| EWR | Komo / EastWest Rural Bank |
| GBY | GrabPay |
| ING | ING Bank N.V. |
| ISL | ISLA Bank (A Thrift Bank), Inc. |
| LBP | LANDBANK / OFBank |
| LSB | Legazpi Savings Bank |
| MCB | Mindanao Consolidated CoopBank. |
| MET | Metropolitan Bank and Trust Co. |
| MPI | Maybank Philippines, Inc. |
| MSB | Malayan Bank Savings and Mortgage Bank, Inc. |
| NBK | Netbank |
| ONB | BDO Network Bank |
| OPI | OmniPay, Inc. |
| PAR | Partner Rural Bank (Cotabato), Inc. |
| PBB | Philippine Business Bank, Inc., A Savings Bank |
| PBC | Philippine Bank of Communications |
| PNB | Philippine National Bank (PNB) |
| PPI | PayMaya Philippines, Inc. |
| PRB | Producers Bank |
| PSB | Philippine Savings Bank |
| PTC | Philippine Trust Company |
| PVB | Veterans Bank |
| QCB | Queenbank |
| QRB | Quezon Capital Rural Bank |
| RBG | Asenso |
| RBN | Robinsons Bank Corporation |
| RCI | RCBC/DiskarTech |
| SBA | Sterling Bank of Asia, Inc (A Savings Bank) |
| SCB | Standard Chartered Bank |
| SEA | Seabank |
| SEC | Security Bank Corporation |
| SPP | ShopeePay |
| SPY | Starpay |
| SSB | Sun Savings Bank, Inc. |
| TCI | TayoCash |
| UBP | Union Bank of the Philippines |
| UCP | United Coconut Planters Bank (UCPB) |
| UMS | USSC Money Services |
| USB | UCPB Savings Bank |
| WDB | Wealth Development Bank |
| ZTI | JuanCash (Zybi Tech Inc.) |

**SG（新加坡）**

| 銀行代碼 | 銀行名稱 |
|---|---|
| DBS | DBS Bank |
| UOB | UOB Bank |
| OCBC | OCBC Bank |

**MM（緬甸）**

| 銀行代碼 | 銀行名稱 |
|---|---|
| KBZPAY | KBZPAY |
| WAVEPAY | WAVEPAY |

**VN（越南）**

| 銀行代碼 | 銀行名稱 |
|---|---|
| ABBANK | ABBANK |
| ACB | Asia Commercial Bank |
| AGRIBANK | AGRIBANK |
| BAB | Bac A bank |
| BIDV | Bank for Investment and Development of Vietnam |
| BVB | Bao Viet Bank |
| DONGA | DongABank |
| EXB | Exim Bank |
| GPB | GPBank |
| HDB | HD Bank |
| KLB | Kien Long bank |
| MBBANK | MBBANK |
| MSB | MSB |
| NAMA | NamABank |
| NCB | National citizen bank |
| OCEAN | Oceanbank |
| OCB | Orient Commercial Joint Stock Bank |
| PGB | PG Bank |
| SAIGON | Saigon Commercial Bank |
| SEAB | SeABank |
| SCB | Sacom Bank |
| SHB | Saigon – Hanoi Commercial Joint Stock Bank |
| TECHB | Techcom Bank |
| TPB | Tien Phong Bank (TP Bank) |
| VAB | VietABank |
| VCB | Vietcombank |
| VIETINBANK | VietinBank |
| VIB | Vietnam International Bank |
| VPB | VP Bank |

**AU（澳洲）**

| 銀行代碼 | 銀行名稱 |
|---|---|
| ABNA | ABN AMRO Bank |
| ADEL | Adelaide Bank |
| AIB | Allied Irish Banks |
| AMP | AMP Limited |
| ABAL | Arab Bank Australia |
| ANZ | Australia and New Zealand Banking Group Limited |
| AUSW | Auswide Bank |
| AUST | Bank Australia |
| BOFA | Bank of America in Australia |
| BOC | Bank of China Australia |
| BOM | Bank of Melbourne |
| BOQ | Bank of Queensland |
| BOFS | Bank of Scotland |
| BOS | Bank of Sydney |
| BANKWEST | Bank of Western Australia |
| BSA | Bank SA |
| BVIC | Bank Vic |
| BARC | Barclays Bank PLC |
| BDB | Bendigo Bank |
| BBA | Beyond Bank Australia |
| BNP | BNP Paribas in Australia and New Zealand |
| CITI | Citi Bank |
| COMMBANK | Commonwealth Bank of Australia |
| DFB | Defence Bank |
| DPB | Delphi Bank |
| DEUT | Deutsche Bank Australia |
| GCMB | G&C Mutual Bank |
| HERB | Heritage Bank |
| HSBC | HSBC Bank Australia Limited |
| HUME | Hume Bank |
| IMB | IMB Bank |
| ING | ING Direct |
| IBL | Investec Bank Limited |
| JPMB | J.P. Morgan Australia & New Zealand |
| MBL | Macquarie Bank Limited |
| MEB | ME Bank |
| ICBC | Mega International Commercial Bank |
| MYST | MyState Bank |
| NAB | National Australia Bank |
| PNB | P&N Bank |
| PLB | Police Bank |
| RABO | Rabobank |
| RACQ | RACQ Bank |
| RBA | Reserve Bank of Australia |
| RRB | Rural Bank |
| STG | St.George Bank |
| SCB | Standard Chartered Australia Corporate Banking |
| SBI | STATE BANK OF INDIA |
| SUNCORP | Suncorp Bank |
| TMB | Teachers Mutual Bank |
| BNY | The Bank of New York Mellon |
| MUFG | The Bank of Tokyo-Mitsubishi UFJ, Ltd. |
| RBS | The Royal Bank of Scotland Group PLC |
| UOB | United Overseas Bank |
| WESTPAC | Westpac |

**KR（韓國）**

| 銀行代碼 | 銀行名稱 |
|---|---|
| KB | KB Kookmin Bank |
| SHB | Shinhan Bank |
| HNB | Hana Bank |
| WRB | Woori Bank |
| IBK | Industrial Bank of Korea |
| BSB | Busan Bank |
| DGB | Daegu Bank |
| GJB | Gwangju Bank |
| KNB | Kyongnam Bank |
| JBB | Jeonbuk Bank |
| CBK | Citibank Korea |
| SC | Standard Chartered Korea |
| HSBC | HSBC Korea |
| JPM | J.P. Morgan Korea |
| BOC | Bank of China Seoul Branch |

## 注意事項

1. 所有金額限額依商戶設定，皆為小數點兩位。
2. `signMsg` 簽名一律 UTF-8 MD5，結果全大寫；Request 與 Response 的簽名參數組成不同，勿混用。
3. `orderTime` / `queryTime` / `notifyTime` 格式皆為 `yyyyMMddHHmmss`；回調 `createTime` 時區為 **GMT+8**。
4. 緬甸通道：`bankCode` 與 `memberCode` 不用填（`memberCode` 需提交空白字串）。
5. 澳洲通道：`bankCode` 必填；`realName` 支援 Australia、Thailand、Korea；`realAccount` 僅 Thailand、Korea。
6. 回調通知需回應 `OK`（純文字）以停止重複通知，最多重試 10 次、總歷時 44 分鐘。
7. `request` / `response` / `content` 加解密預設 Base64 Encode，可依商戶設定改為 RSA（代收/代付獨立設定）。
8. `resCode`（通用回應層級）與 `code`（業務層級 API/Notify 內容）為兩套獨立代碼表，須分開判斷，見 5.1、5.2。

## 實作檔案

依專案慣例（`app/Services/Payment/Providers/PayMate/`）：

- `Driver.php`：驅動程式
- `PayMateTrait.php`：共用方法（簽名、Base64/RSA 加解密、HTTP 請求）
- `Deposit.php`：代收實作（`/qrh5`）
- `DepositCallback.php`：代收回調（Notify）
- `Withdrawal.php`：代付實作（`/payout`）
- `WithdrawalCallback.php`：代付回調（Notify）
