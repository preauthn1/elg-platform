# SpadegamingSW（Spadegaming 單一錢包，vid 81）— 廠商原始 API 規格

> 原始來源：`SG_Merchant_API_CN_v1.7.pdf`（Spadegaming API Specifications Document，中文版 Version 1.7）。
> 補充：`SG_Game List_V1.17_超特霸.xlsx`、`GameDenomination V1.72-SG.xlsx` 等（遊戲清單與面額表）。抓取日期：2026-06-03。
> 本檔聚焦**單一錢包（Single Wallet）**模式，對應 `vid 81 / VendorIdEnum::SPADEGAMINGSW`。轉帳錢包模式（`VendorIdEnum::SG`）見 `docs/integrations/vendors/SpadeGaming/api.md`。
> 本檔為忠實的第三方 API 規格重述。我方實作分析、陷阱與落地對應見同目錄 `integration.md`。

Spadegaming 為電子遊戲（slot / 棋牌 / 捕魚 / 街機）供應商。單一錢包模式下**玩家餘額由 Merchant（我方）保管**，SG 在玩家下注 / 派彩時透過回調通知我方扣加款。

---

## 通用約定

### 請求格式

- **HTTP Method**：所有 API 一律 `POST`。
- **資料格式**：支援 `XML` 與 `JSON`（本平台採 JSON）。
- **字元編碼**：text ↔ byte[] 必須明確 **UTF-8**，且**不能有空格與換行符**（影響 Digest 計算）。

### 角色定義（單一錢包方向）

| 角色 | 說明 | 單一錢包身分 |
|---|---|---|
| Game Provider | Spadegaming（SPADE） | API Requestor（主動發起回調） |
| Merchant | 商戶（我方平台） | API Provider（提供 `getBalance` / `transfer` 端點） |

### HTTP Header

| Header | 必須 | 範例 | 說明 |
|---|---|---|---|
| `API` | 是 | `getBalance` / `transfer` / `getAuthorize` | 服務接口名稱 |
| `DataType` | 是 | `JSON` | `XML` 或 `JSON` |
| `Digest` | 是 | `MD5(body + securityKey)` | 簽章（見下「簽名」節） |
| `Accept-Encoding` | 否 | `gzip, deflate` | 回應是否 zip 壓縮 |
| `Content-Encoding` | 否 | `gzip` | 提交是否已 zip |
| `Accept-Language` | 否 | `en_US` | 回傳訊息語言 |

### 通用請求 / 回應欄位

- **每支 request 必含**：`serialNo`（Varchar(50)，唯一，建議 GUID 或「日期時間+隨機數」）、`merchantCode`（Varchar(10)，例 `SPADE`）。
- **每支 response 必含**：`serialNo`（回填）、`code`（Integer，見回應代碼）、`msg`（Varchar(128)）、`merchantCode`。成功以 `code === 0` 為準。

### Base URL

| 方向 | URL |
|---|---|
| Merchant → SG（`getAuthorize` 等） | 由 SG 提供，存 `vendor_infos.info.API_URL`（文件僅範例 `http://example.invalid/api`）|
| SG → Merchant（`getBalance` / `transfer` 回調） | 我方提供，本平台固定 `routes/external/sw.php` 的 `POST /spadegaming` |

> 正式 host、`securityKey`、`merchantCode` 等接入參數由 SG 客服開戶時提供（文件未列），須向 SG 索取。

### 簽名（Digest）

SG 採 **MD5 摘要**：請求 body 與 `securityKey` 串接後 MD5，輸出 hex 字串，放 HTTP Header `Digest`（**簽章不放 body 內**）。

```
Digest = HEX_lower( MD5( UTF8(body) ++ UTF8(securityKey) ) )
```

- `securityKey` 由 SG 開戶提供，body 在前、key 在後串接。
- body 必須 UTF-8 且**去除空格與換行**，否則 hash 不一致。
- 兩個方向：Merchant → SG（`getAuthorize`）用 `md5(json_encode(postData) . securityKey)`；SG → Merchant（回調驗簽）先 `json_decode` 再 `json_encode` 正規化 body 後 `md5(body . securityKey)`，比對 Header `Digest`，不符回 `code 2`。
- 註：官方 C# 範例回應 header 用 `hash`、Java 範例用 `Digest`，實際以雙方約定為準；C# 輸出可能大寫 hex，PHP `md5()` 為小寫，須與 SG 確認是否區分大小寫。

---

## 啟動遊戲（getAuthorize，Merchant → SG）

`getAuthorize` 由 **Merchant 主動呼叫 SG** 取得 game URL：

```
Player 點「Go to Game」
  → Merchant 生成 token + 呼叫 getAuthorize（送 acctInfo / game / token / digest）
  → SG 驗證 Token、Digest、AcctInfo
  → SG 回傳 gameUrl + token（Merchant 端再驗 token 一致）
  → Merchant 回 gameUrl 給 Player → Play Game
```

單一錢包模式下 `AcctInfo` 可附帶 `balance` 欄位（僅 Youtuber 模式適用）。

---

## 回調 API（SG → Merchant）

本平台統一以單一入口 `POST /spadegaming` 接收，由 HTTP Header `API` 區分 `getBalance` / `transfer`。

### 1. getBalance — 查詢用戶餘額（4.3.1）

**Request Body**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `acctId` | Varchar(50) | 是 | 玩家 ID |
| `gameCode` | Varchar(10) | 否 | 遊戲代碼（例 `S-LK03`） |
| `serialNo` | Varchar(50) | 是 | 訊息序列號 |
| `merchantCode` | Varchar(10) | 是 | 商戶 ID |

**Success Response**：回填通用欄位 + `acctInfo`：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `acctInfo.acctId` | Varchar(50) | 用戶 ID |
| `acctInfo.userName` | Varchar(15) | 用戶名稱 |
| `acctInfo.currency` | Char(3) | 幣別 ISO |
| `acctInfo.balance` | Decimal(20,4) | 用戶當前餘額（已乘 `rate`） |
| `acctInfo.siteId` | Varchar(10) | 商戶站點（文件有，本平台未回） |

### 2. transfer — 轉帳（下注 / 取消 / 派彩 / Bonus）（4.3.2 / 4.3.3）

所有資金異動透過此 API，由 body `type` 區分。

**Request Body**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `transferId` | Varchar(50) | 是 | 轉帳流水（**唯一**，冪等鍵） |
| `acctId` | Varchar(50) | 是 | 用戶 ID |
| `currency` | Char(3) | 是 | 幣別 ISO |
| `amount` | Decimal(20,9) | 是 | 轉帳金額；**無論何種操作皆 ≥ 0** |
| `type` | Int | 是 | `1` 下注 / `2` 取消下注 / `4` 派彩 / `7` Bonus（紅包） |
| `channel` | Varchar(10) | 是 | `Mobile` / `Web` / `APP-i` / `APP-A` / `PC` |
| `gameCode` | Varchar(10) | 是 | 遊戲代碼 |
| `ticketId` | Varchar(20) | 否 | 注單號 |
| `referenceId` | Varchar(50) | 否 | 參考 ID（**type=2/4 必帶**，指向對應 placeBet 的 transferId） |
| `specialGame` | SpecialGame | 否 | 僅 type=4 派彩且觸發 bonus/free/bonusfree/freebonus 時傳 |
| `refTicketIds` | Varchar(300) | 否 | 關聯票號（僅 type=4 派彩傳），例 `[120001, 120002]` |
| `playerId` | Varchar(50) | 否 | 用戶 IP |
| `gameFeature` | Varchar(50) | 否 | 玩家觸發 Buy Feature 時帶（例 `BUY-8`） |
| `transferTime` | Char(15) | 否 | 格式 `yyyy-mm-dd hh:mm:ss.ms`（**UTC+8**） |

`SpecialGame`：`type`（Varchar(20)，`bonus`/`free`/`bonusfree`/`freebonus`）、`count`（Int，特別遊戲總數）、`sequence`（Int，已完成數）。

**Success Response**：回填通用欄位 + `transferId` / `merchantTxId`（Varchar(20)，Merchant 交易流水）/ `acctId` / `balance`（已乘 `rate`）。

**Type 對照**

| `type` | 含義 | 處理重點 |
|---|---|---|
| `1` | 下注 place bet | 餘額不足回 `50110`；`transferId` 重複回原餘額（冪等） |
| `2` | 取消下注 cancel | 須帶 `referenceId`；找不到對應 placeBet 回 `109`（SG 會繼續重送） |
| `4` | 派彩 payout | 須帶 `referenceId`；找不到回 `109` |
| `7` | Bonus（紅包 / promotion） | **不帶 `referenceId`**，直接加款 |

**文件規則**：
- Type 4 派彩：若該 `transferId` 已處理成功，**直接回 `code 0`，不需再調整餘額**（冪等）。
- Type 2 取消：找不到 / 重複的 referenceId 回 `0` 或 `109`（回 `109` 時 SG 會重送）；SG 未收到 placeBet 回應（timeout）或玩家進魚機未下注就離開，皆會發 cancel bet。

### 3. Bonus 轉帳（Type 7，4.3.3）

`type=7`，**不傳 `referenceId`**；`roundId` / `siteId` 依商戶需求傳（Promotion ID / site Id）。商戶收到須**增加**玩家錢包餘額。

---

## 取單對帳（getBetHistory，4.4.1）

`getBetHistory` 由 **Merchant 主動呼叫 SG**，對帳補單用。

文件取單規則（V1.7 新增）：
1. 注單以遊戲派獎時間 `ticket_date` 為準，但查詢以 `save_date` 進行，依時間順序持續往前取，不需回頭重取。
2. 請求時間建議「當前時間 3 小時前 ~ 3 分鐘前」，區間 1–5 分鐘為宜，**最大不超過 2 小時**。
3. 前後請求區間不重疊，首尾連續即可。
4. **每次只能單一請求**，否則回 `101 USER_CALL_LIMITED`。

`BetInfo` 重要欄位：`ticketId` / `acctId` / `ticketTime` / `categoryId`（`SM`=老虎機 / `BC`=桌面 / `AD`=街頭 / `BN`=活動 / `FH`=捕魚機）/ `gameCode` / `betAmount` / `winLoss` / `roundId` / `referenceId` / `gameFeature`。

---

## 回應代碼（附錄 1，完整）

| Code | 中文 | English | 說明 |
|---|---|---|---|
| `0` | 成功 | Success | 接口調用成功 |
| `1` | 系統錯誤 | System Error | 系統內部出錯 / bug / DB 連線失敗 |
| `2` | 非法請求 | Invalid Request | 不是合法的請求 |
| `3` | 服務暫時不可用 | Service Inaccessible | 服務不可用 |
| `100` | 請求超時 | Request Timeout | — |
| `101` | 用戶調用次數超限 | Call Limited | — |
| `104` | 請求被禁止 | Request Forbidden | — |
| `105` | 缺少必要參數 | Missing Parameters | — |
| `106` | 非法的參數 | Invalid Parameters | — |
| `107` | 批次號重複 | Duplicated Serial NO. | — |
| `109` | 關聯 id 找不到 | Related id not found | **常用於單一錢包 Transfer**（cancel/payout 找不到 placeBet） |
| `110` | 批次號不存在 | Record ID Not Found | — |
| `111` | 重複請求 | Duplicated request | — |
| `112` | API 調用次數超限 | API Call Limited | — |
| `113` | Acct ID 不正確 | Invalid Acct ID | 格式不正確 |
| `118` | 格式不正確 | Invalid Format | Parse JSON Data Failed |
| `120` | IP 不在白名單內 | IP no whitelisted | — |
| `5003` | 遊戲正在維護中 | System Maintenance | — |
| `10113` | Merchant 不存在 | Merchant Not Found | — |
| `10116` | Merchant 被暫停 | merchant suspend | — |
| `50099` | 帳號已存在 | Acct Exist | — |
| `50100` | 帳號不存在 | Acct Not Found | — |
| `50101` | 帳號未激活 | Acct Inactive | — |
| `50102` | 帳號已鎖 | Acct Locked | — |
| `50103` | 帳號 suspend | Acct Suspend | — |
| `50104` | Token 驗證失敗 | Token Validation Failed | — |
| `50110` | 帳號餘額不足 | Insufficient Balance | — |
| `50111` | 超過帳號交易限制 | Exceed Max Amount | — |
| `50112` | 幣種不支援 | Currency Invalid | 幣種與用戶不一致 / merchant 無相應幣種 |
| `50113` | 金額不合法 | Amount Invalid | 金額必須 > 0 |
| `50115` | 日期格式錯誤 | Date Format Invalid | — |

---

## 語言代碼（附錄 2）

`en_US` / `zh_CN` / `th_TH` / `id_ID` / `vi_VN` / `ko_KR` / `ja_JP` / `ru_RU` / `tr_TR` / `es_ES` / `fr_FR` / `pt_PT` / `bn_BN`（孟加拉語）/ `hi_HI`（印地語）。

## 貨幣代碼（附錄 3 重點）

支援 USD / CNY / EUR / EPV / GBP / HKD / SGD / KRW / JPY / THB / IDR / ID2 / INR / VND / VN2 / MYR / MMK / MMV / AUD / BDT / BRL / KZT … 等。

> **匯率規則**：`IRR, COP, VND, IDR, MMV, LAK, KHR` 比率 **1:1000**；`CO2, VN2, ID2, MMK` 比率 **1:1**。對應 `vendor_infos.rate`，金額進出以 `Common::numMul/numDiv(..., rate, 4)` 換算。

---

## Change Log（官方 Revision History 摘要）

| 版本 | 變更 |
|---|---|
| V1.0 | 結合單一錢包與轉帳錢包；移除 `authorize` 新增 `getAuthorize`；新增 `getTicketLog` |
| V1.1 | `getAuthorize` 新增 `balance` 參數；`kickAcct` 增 `gameCode`、移除 `list[]` |
| V1.2 | 附錄 6 新增 spinAll 範例；`refTicketIds` 優化；`getBetHistory` 的 `categoryId` 優化 |
| V1.3 | `getTicketLog` 新增 `allTransferTicketLog`、移除 `currTransferTicketLog` |
| V1.4 | 新增 `getMemberList`；新增 Portuguese（`pt_PT`）；`transfer.transferTime` 加毫秒 |
| V1.5 | 新增 `getTotalPL` |
| V1.6 | 新增 孟加拉語（Bengali）、印地語（Hindi） |
| V1.7 | `getBetHistory` 新增 API 取單時間規則說明 |

---

## Sources

- `SG_Merchant_API_CN_v1.7.pdf`（Spadegaming API Specifications，§4 API / 附錄 1 回應碼 / 附錄 2 語言 / 附錄 3 貨幣 / 附錄 4 Java / 附錄 5 C#），`/Users/rb/Workspace/遊戲商/` SpadegamingSW 下載包，2026-06-03 讀取。
- 交叉驗證：`app/Vendors/SpadegamingSW/Driver.php` / `TransferDataDTO.php` / `Enums/ReturnStatusEnum.php`；`app/Http/Controllers/External/SingleWallets/SpadegamingSWController.php`（digest 驗證在其 `isRequestSignatureValid()`，原 `SpadegamingDigestCheck` middleware 已併入）；`routes/external/sw.php`。
