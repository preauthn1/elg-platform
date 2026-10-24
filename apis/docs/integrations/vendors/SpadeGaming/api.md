# SpadeGaming（vid 47）— 廠商原始 API 規格

> 原始來源：`Merchant_API_v4.8(CN).pdf`（共 74 頁，遊戲商提供之官方對接文件，簡體中文版）。
> 抓取日期：2026-06-03。
> 補充參考：同下載包另含 `Merchant_API_v3.8(CN).pdf`（舊版）、`E-Games_Merchant_Integration_Form_ZCH236.xlsx`（商戶申請表）、`my_info.txt` / `wintotoidn_info.txt`（Staging / 各白標 Merchant Code 與後台帳密）。
> 本檔為忠實的第三方 API 規格重述。我方實作分析、陷阱與落地對應見同目錄 `integration.md`。

SpadeGaming（內部代號 vid **47**，`VendorIdEnum::SG`）為提供 Slot / 桌牌 / 漁機等遊戲的遊戲商，本平台以 **Transfer Wallet（轉帳錢包）** 模式接入：玩家餘額由 SpadeGaming 端保管，進遊戲前須由本平台呼叫 `deposit` 把本地餘額轉入遊戲商；下注、派彩在遊戲商端結算，提款時呼叫 `withdraw` 轉回本地。

> 本平台另存在 `SpadegamingSW`（vid 81，單一錢包）driver，與本文件 vid 47 為不同接入模式；本文件僅涵蓋 **vid 47 轉帳錢包**。

---

## 通用約定

### 請求格式

- **HTTP Method**：所有 API 一律 `POST`。
- **Body**：JSON 或 XML（由 `DataType` header 指定）。字元編碼一律 `UTF-8`，body **不得含空格與換行符號**。
- **每支 request 必含**：`serialNo`（唯一序列號）與 `merchantCode`（商戶 ID）。
- **每支 response 必含**：`serialNo` / `code` / `msg` / `merchantCode`。
- 方向術語：「接口提供者 = Game Provider」表示由本平台呼叫 SpadeGaming；「接口提供者 = Merchant」表示由 SpadeGaming 反向呼叫本平台（如 `authorize`）。

### HTTP Header

| Header | 必須 | 範例 | 說明 |
|---|---|---|---|
| `API` | 是 | `authorize` | 服務介面名稱（如 `deposit` / `withdraw` / `getAcctInfo`） |
| `DataType` | 是 | `JSON` | `XML` 或 `JSON` |
| `Digest` | 視設定 | `MD5(Data + securityKey)` | 簽章（選用，視商戶設定）；`securityKey` 由 SpadeGaming 提供 |
| `Accept-Encoding` | 否 | `gzip, deflate` | 支援回傳 gzip |
| `Content-Encoding` | 否 | `gzip` | 提交資料已 gzip |
| `Accept-Language` | 否 | `en_US` | 回傳訊息語言 |

HTTP status 非 `200` 直接視為錯誤（`server return error! Status {code}`）。

### 簽名（Digest，選用）

SpadeGaming 不使用「把參數序列化成 signature 欄位」的做法，而是把**整包 body 連同 securityKey 做 MD5**，結果放入 HTTP Header `Digest`。簽章為**選用（視商戶設定開啟）**。

```
Digest = HexLower( MD5( UTF8(body) ++ UTF8(securityKey) ) )
```

- `body`：實際送出的 request body（JSON/XML 字串，去除空白與換行）的 UTF-8 bytes。
- `securityKey`：由 SpadeGaming 提供的商戶密鑰，接在 body bytes 之後串接（`++`）。
- 結果以 16 進位小寫字串呈現。

**回應驗證**：若 response header 帶 `Digest`，以同一演算法重算 response body 並比對，不符視為資料竄改／傳輸錯誤。

> 文件瑕疵註記：官方 PDF 帶 key 版 Java 範例最後一行寫成 `compute(input)`（只算 body 未算 buffer），對照語意應為 `compute(buffer)`，研判為官方 typo，以「body + key 串接後 MD5」語意為準。C# 與 Java 範例 header 命名不一致（C# `Disgest`/`hash`，Java `Digest`），實際以商戶開通文件為準。

### Base URL

由 SpadeGaming 依商戶開通提供（範例 `http://example.invalid/api`）；圖片相對路徑須補 `http://{API URL host}` 前綴。

---

## 介面總覽

| § | 接口名（API header） | 方向 | 用途 |
|---|---|---|---|
| 4.1 | Redirect To Game Lobby | 跳轉（URL） | 玩家進遊戲大廳/遊戲（URL 參數說明，非 API 呼叫） |
| 4.2 | `authorize` | SG → 本平台 | 玩家授權，驗證 token 是否合法 |
| 4.3 | `getAcctInfo` | 本平台 → SG | 查詢玩家資訊（含餘額）|
| 4.4 | `deposit` | 本平台 → SG | 存款（本地餘額轉入遊戲商）|
| 4.5 | `withdraw` | 本平台 → SG | 取款（遊戲商餘額轉回本地）|
| 4.6 | `getBetHistory` | 本平台 → SG | 查詢玩家下注記錄（注單）|
| 4.7 | `jackpotPool` | 本平台 → SG | 查詢 Jackpot 彩池（單一幣別）|
| 4.8 | `jackpotPoolAll` | 本平台 → SG | 查詢商號 Jackpot 彩池（以幣別為準）|
| 4.9 | `checkStatus` | 本平台 → SG | 查詢某 serialNo 的調用狀態 |
| 4.10 | `kickAcct` | 本平台 → SG | 強制踢出在線玩家 |
| 4.11 | `pendingAcct` | 本平台 → SG | 查詢不在線但仍有餘額的玩家 |
| 4.12 | `getGames` | 本平台 → SG | 查詢遊戲列表 |
| 4.13 | `createToken` | 本平台 → SG | 取得 token（用於單號詳細頁面跳轉）|
| 4.14 | Redirect To Ticket Details Page | 跳轉（URL） | 玩家檢視單號詳細頁 |
| 4.15 | `fundInOut` | 本平台 → SG | 查詢存提轉帳記錄 |
| 4.16 | `checkAcct` | 本平台 → SG | 查詢玩家帳號是否存在 |
| 4.17 | `playerDailySumByGame` | 本平台 → SG | 查詢玩家每日依遊戲彙總下注 |
| 4.18 | `setYoutuber` | 本平台 → SG | 設定玩家為 youtuber 模式 |

---

## 4.1 Redirect To Game Lobby（遊戲大廳跳轉，URL 參數）

非 API 呼叫，而是進遊戲連結的參數說明。

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `acctId` | Varchar(50) | 是 | 玩家 ID |
| `token` | Varchar(80) | 是 | 由 Merchant 端產生 |
| `language` | Varchar(10) | 否 | 語言（附錄 2）|
| `game` | Varchar(10) | 是 | 遊戲代碼，例 `S-DG02` |
| `fun` | Varchar(10) | 否 | 試玩，`fun=true` 需仍帶 acctId & token |
| `mobile` | Varchar(10) | 否 | 手機 `true/false` |
| `menumode` | Varchar(10) | 否 | 目錄開關 `on/off` |
| `exitUrl` | Varchar(150) | 否 | 退出按鈕導向網址 |
| `fullScreen` | Varchar(10) | 否 | 全螢幕 `on/off` |
| `lobby` | — | 否 | 只進大廳時帶 `lobby=SG`（不帶 game）|

範例：`http://example.invalid/auth/?acctId=TESTPLAYER1&language=en_US&token=[REDACTED]

---

## 4.2 authorize（玩家授權，SpadeGaming → 本平台）

玩家跳轉進遊戲商後，SpadeGaming 以此 API 驗證 request 是否合法。**本平台為接口提供者。**

**AuthorizeRequest**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `acctId` | Varchar(50) | 是 | 玩家 ID |
| `token` | Varchar(80) | 是 | Merchant 端產生 |
| `language` | Varchar(10) | 否 | 語言 |
| `merchantCode` | Varchar(10) | 是 | 商戶 ID |
| `serialNo` | Varchar(50) | 是 | 序列號 |

**AuthorizeResponse**：含 `acctInfo`（AcctInfo 物件）。

**AcctInfo**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `acctId` | Varchar(50) | 是 | 玩家 ID |
| `userName` | Varchar(15) | 否 | 用戶名稱 |
| `currency` | Char(3) | 是 | 幣別 ISO 代碼 |
| `balance` | Decimal(20,4) | 是 | 當前餘額（**轉帳錢包可忽略，設 0 即可**）|
| `siteId` | Varchar(10) | 否 | 商戶站點 |

特別注意：`AuthorizeResponse` 的 `balance` 可忽略（設 0）。Acct ID 格式：`[a-zA-Z0-9_-@]{1,50}`，例 `TestPlayer_1`。

JSON Response 範例：
```json
{
  "acctInfo": { "acctId": "TESTPLAYER1", "balance": 0, "userName": "TestPlayer", "currency": "USD", "siteId": "SITE_USD1" },
  "merchantCode": "SPADE", "msg": "success", "code": 0, "serialNo": "20120722224255982841"
}
```

---

## 4.3 getAcctInfo（查詢玩家資訊）

| 欄位（Request） | 類型 | 必填 | 說明 |
|---|---|---|---|
| `acctId` | Varchar(50) | 否 | 玩家 ID，空則查全部 |
| `pageIndex` | Integer | 是 | 頁碼 |
| `merchantCode` | Varchar(10) | 是 | 商戶 ID |
| `serialNo` | Varchar(50) | 是 | 序列號 |

Response：`resultCount` / `pageCount` / `list`（AcctInfo[]）。AcctInfo：`acctId` / `userName` / `currency` / `balance`。

---

## 4.4 deposit（存款，本地 → 遊戲商）

| 欄位（Request） | 類型 | 必填 | 說明 |
|---|---|---|---|
| `acctId` | Varchar(50) | 是 | 玩家 ID |
| `currency` | Char(3) | 是 | 幣別 ISO |
| `amount` | Decimal(20,4) | 是 | 存款金額 |
| `serialNo` | Varchar(50) | 是 | 序列號（唯一）|
| `merchantCode` | Varchar(10) | 是 | 商戶 ID |

**DepositResponse**：`transactionId`（Varchar(20)，交易流水號）、`afterBalance`（Decimal(20,4)，玩家最新餘額）+ 通用欄位。

---

## 4.5 withdraw（取款，遊戲商 → 本地）

Request 欄位同 deposit（`acctId` / `currency` / `amount` / `serialNo` / `merchantCode`）。
**WithdrawResponse**：`transactionId`、`afterBalance` + 通用欄位。

---

## 4.6 getBetHistory（查詢下注記錄）

| 欄位（Request） | 類型 | 必填 | 說明 |
|---|---|---|---|
| `beginDate` | Char(15) | 是 | 開始時間，格式 `yyyymmdd'T'24hhmmss`，例 `20120720T230043` |
| `endDate` | Char(15) | 是 | 截止時間，同格式 |
| `pageIndex` | Integer | 是 | 頁碼 |
| `merchantCode` | Varchar(10) | 是 | 商戶 ID |
| `serialNo` | Varchar(50) | 是 | 序列號 |

Response：`resultCount` / `pageCount` / `list`（BetInfo[]）。

**BetInfo**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `ticketId` | Varchar(20) | 是 | 下注單號 |
| `acctId` | Varchar(50) | 是 | 玩家 ID |
| `ticketTime` | Char(15) | 是 | 下注時間 |
| `categoryId` | Varchar(10) | 是 | 遊戲種類（SM / TB / AD / BN / FH）|
| `gameCode` | Varchar(10) | 是 | 遊戲代碼 |
| `currency` | Char(3) | 是 | 幣別 |
| `betAmount` | Decimal(18,6) | 是 | 下注金額 |
| `result` | Varchar(1024) | 否 | 結果 |
| `winLoss` | Decimal(18,6) | 是 | 用戶輸贏 |
| `jackpotAmount` | Decimal(18,6) | 是 | jackpot 金額 |
| `betIp` | Varchar(50) | 是 | 下注 IP |
| `luckyDrawId` | Long | 否 | — |
| `completed` | boolean | 是 | 是否已結束 |
| `roundId` | Integer | 是 | 遊戲 log ID |
| `sequence` | Integer | 是 | `0` = 沒贏 jackpot；0,1,2,3,4 |
| `channel` | Varchar(10) | 是 | `Mobile` / `Web` |
| `balance` | Decimal(18,6) | 是 | 上輪餘額 |
| `jpWin` | Decimal(18,6) | 是 | 積寶贏額 |
| `referenceId` | Varchar(50) | 否 | 相關聯的轉帳流水 |
| `gameFeature` | Varchar(50) | 否 | 僅玩家觸發「購買功能」時回，例 `BUY-20` |

**特別注意（重要拉單限制）**：
- 注單以**遊戲派獎時間**為準；建議拉取**當前時間 3 分鐘之前**的資料。
- 建議拉取區間 **1–5 分鐘**，**最大不能超過 2 小時**。
- 此 API 有拉單限制：**每次只能單一請求，前一請求完成後才能拉下一筆**，否則回 `101 USER_CALL_LIMITED`。

---

## 4.7 jackpotPool / 4.8 jackpotPoolAll（彩池查詢）

- `jackpotPool`：Request `currency`（否，空則回所有幣別）/ `merchantCode` / `serialNo`；Response `list`（JackpotPoolInfo：`code` / `name` / `amount`）。
- `jackpotPoolAll`：Request `merchantCode` / `serialNo`；Response `list`（JackpotPoolInfo：`code` / `amoutMap`，例 `{"CNY":103418.588499,"IDR":...,"HKD":...}`）。

---

## 4.9 checkStatus（查詢調用狀態）

| 欄位（Request） | 類型 | 必填 | 說明 |
|---|---|---|---|
| `lastSerialNo` | Varchar(50) | 是 | 要查詢狀態的流水號 |
| `merchantCode` | Varchar(10) | 是 | 商戶 ID |
| `serialNo` | Varchar(50) | 是 | 本次請求序列號 |

Response：`status`（Integer，**0=成功；1=失敗**，指 `lastSerialNo` 那筆的狀態）、`code`（本次調用 0=成功/1=失敗）+ 通用欄位。

> 用途：deposit/withdraw 逾時後，用 checkStatus 帶原 serialNo 查該筆是否實際成功，避免重複轉帳。

---

## 4.10 kickAcct（踢出在線玩家）

Request：`acctId`（否，空則全部）/ `merchantCode` / `serialNo`。Response：`list`（String[]，被踢出的玩家列表）。用於 merchant 維護時強制下線。

## 4.11 pendingAcct（查詢不在線但有餘額的玩家）

Request：`merchantCode` / `serialNo`。Response：`list`（AcctInfo[]：`acctId` / `currency` / `balance`）。用於找出仍有遊戲商餘額未轉回的玩家（轉帳錢包對帳/回收餘額）。

---

## 4.12 getGames（查詢遊戲列表）

Request：`serialNo` / `merchantCode` / `currency`（否）/ `language`（否，附錄 2）。
Response：`games`（GameInfo[]）。

**GameInfo**：`gameCode` / `gameName` / `jackpot`(boolean) / `thumbnail` / `screenshot` / `mthumbnail`(手機圖) / `jackpotCode`(否) / `jackpotName`(否)。

特別注意：
- 圖片完整路徑 = `http://{API URL 的 host}` + 圖片相對路徑（例 API URL `http://example.invalid/api` → 圖 `http://example.invalid/images/aa.jpg`）。
- 建議定時任務拉取後**把圖片下載到自己伺服器**再顯示，因 API 為 server-to-server 訪問，非 public IP 不能直接在網站引用。

---

## 4.13 createToken（建立 Token）

Request：`acctId` / `merchantCode` / `action`（客戶行徑，例 `ticketLog`）/ `serialNo`。
Response：`token`（Varchar(80)）+ `merchantCode` / `serialNo`。用途：搭配 §4.14 單號詳細頁面跳轉。

## 4.14 Redirect To Ticket Details Page（單號詳細頁跳轉，URL）

URL 參數：`ticketId` / `acctId` / `action`（`ticketLog`）/ `token` / `language`（否）。
範例：`http://example.invalid/{merchantCode}/createToken/?ticketId=...&acctId=...&action=ticketLog&token=[REDACTED]

---

## 4.15 fundInOut（查詢存提轉帳記錄）

| 欄位（Request） | 類型 | 必填 | 說明 |
|---|---|---|---|
| `beginDate` / `endDate` | Char(15) | 是 | 格式 `yyyymmdd'T'24hhmmss` |
| `acctId` | Varchar(50) | 否 | 玩家 ID |
| `currency` | Char(3) | 否 | 幣別 |
| `lastSerialNo` | Varchar(50) | 否 | 要查詢狀態的流水號 |
| `type` | Varchar | 否 | `In`=存入 / `Out`=提取 |
| `pageIndex` | Integer | 是 | 頁碼 |
| `merchantCode` | Varchar(10) | 是 | 商戶 ID |
| `serialNo` | Varchar(50) | 是 | 序列號 |

Response：`resultCount` / `pageCount` / `list`（FundInOutInfo[]：`transferId` / `acctId` / `transactionTime` / `type` / `amount` / `lastSerialNo`）。

---

## 4.16 checkAcct（查詢玩家帳號是否存在）

| 欄位（Request） | 類型 | 必填 | 說明 |
|---|---|---|---|
| `acctId` | Varchar(50) | 是 | 玩家帳號 |
| `includeType` | Boolean | 否 | 是否確認為 youtuber 玩家 |
| `merchantCode` | Varchar(50) | 是 | 商戶 ID |
| `serialNo` | Varchar(50) | 是 | 序列號 |

Response：`code`（0 存在）、`acctType`（否，Integer，**0=normal / 1=youtuber**）+ 通用欄位。

---

## 4.17 playerDailySumByGame（玩家每日依遊戲彙總下注）

| 欄位（Request） | 類型 | 必填 | 說明 |
|---|---|---|---|
| `beginDate` / `endDate` | Char(15) | 是 | 格式 `yyyymmdd'T'24hhmmss` |
| `pageIndex` | Integer | 是 | 頁碼 |
| `merchantCode` | Varchar(10) | 是 | 商戶 ID |
| `acctId` | Varchar(50) | 否 | 玩家 ID |

Response：`resultCount` / `pageCount` / `list`（PlayerBetInfo[]）。
**PlayerBetInfo**：`acctId` + `list`（BetInfo[]）。
此處 **BetInfo**（彙總版）：`categoryId`（SM/TB/AD/BN）/ `gameCode` / `bets`(下注次數) / `betAmount` / `jackpotAmount` / `jpWin` / `winLoss`。

**特別注意（拉取限制）**：
1. 拉取區間**最大不能超過 1 天**。
2. 拉取資料時間必須是**當前時間 12 小時前**。
3. 資料以**小時**為查詢單位。

---

## 4.18 setYoutuber（設定玩家為 youtuber 模式）

Request：`acctId` / `currency`(Char(3)) / `youtuber`(Boolean，**True=Youtuber 模式 / False=一般模式**) / `merchantCode` / `serialNo`。
Response：通用欄位（`code` / `msg` / `merchantCode` / `serialNo`）。

---

## 附錄 1 — 回應碼定義（完整）

| code | 中文 | English | 說明 |
|---|---|---|---|
| 0 | 成功 | Success | 接口調用成功 |
| 1 | 系統錯誤 | System Error | 系統內部出錯 / 程式 bug / DB 連不上 |
| 2 | 非法請求 | Invalid Request | 不是合法的請求 |
| 3 | 服務暫時不可用 | Service Inaccessible | 服務不可用 |
| 100 | 請求超時 | Request Timeout | — |
| 101 | 用戶調用次數超限 | Call Limited | `getBetHistory` 等需序列化拉取 |
| 104 | 請求被禁止 | Request Forbidden | — |
| 105 | 缺少必要參數 | Missing Parameters | — |
| 106 | 非法的參數 | Invalid Parameters | — |
| 107 | 批次號重複 | Duplicated Serial NO. | — |
| 109 | 關聯 id 找不到 | Related id not found | 通常用於單一錢包 Transfer API |
| 110 | 批次號不存在 | Record ID Not Found | — |
| 111 | 重複請求 | Duplicated request | — |
| 112 | API 調用次數超限 | API Call Limited | — |
| 113 | Acct ID 不正確 | Invalid Acct ID | Acct ID 格式不正確 |
| 118 | 格式不正確 | Invalid Format | Parse Json Data Failed |
| 120 | IP 不在白名單內 | IP no whitelisted | — |
| 5003 | 遊戲正在維護中 | System Maintenance | — |
| 10113 | Merchant 不存在 | Merchant Not Found | — |
| 10116 | Merchant 被暫停 | merchant suspend | — |
| 50099 | 帳號已存在 | Acct Exist | — |
| 50100 | 帳號不存在 | Acct Not Found | — |
| 50101 | 帳號未激活 | Acct Inactive | — |
| 50102 | 帳號已鎖 | Acct Locked | — |
| 50103 | 帳號 suspend | Acct Suspend | — |
| 50104 | Token 驗證失敗 | Token Validation Failed | — |
| 50110 | 帳號餘額不足 | Insufficient Balance | — |
| 50111 | 超過帳號交易限制 | Exceed Max Amount | — |
| 50112 | 幣別不支援 | Currency Invalid | 與用戶幣別不一致 / merchant 沒有相應幣別 |
| 50113 | 金額不合法 | Amount Invalid | Deposit/withdraw 金額必須 > 0 |
| 50115 | 日期格式錯誤 | Date Format Invalid | — |

---

## 附錄 2 — 語言代碼

| ISO Language Code | 說明 |
|---|---|
| `en_US` | 英文（default fallback）|
| `zh_CN` | 簡體中文 |
| `zh_TW` | 繁體中文（**官方附錄 2 未列，但本平台實作有送**）|
| `th_TH` | 泰文 |
| `id_ID` | 印尼文 |
| `vi_VN` | 越南文 |
| `ko_KR` | 韓文 |
| `ja_JP` | 日文 |
| `ru_RU` | 俄文 |
| `tr_TR` | 土耳其文 |
| `es_ES` | 西班牙文 |
| `fr_FR` | 法文 |
| `my_MM` | 緬甸文（**官方附錄 2 未列，本平台實作有送**）|
| `hi_IN` | 印地文（**官方附錄 2 未列，本平台實作有送**）|

> 註記：`zh_TW` / `my_MM` / `hi_IN` 未出現在 v4.8 附錄 2 清單；SpadeGaming 對未支援語言行為（fallback 或報錯）官方未明述，屬已知差異。

---

## 附錄 3 — 貨幣代碼（節錄 + 重要換算備註）

涵蓋 BDT/CNY/USD/EUR/EPV/GBP/HKD/SGD/KRW/JPY/THB/IDR/ID2/INR/VND/VN2/MYR/MMK/MMV/AUD … 及 MXN/NZD/PEN/PLN/PYG/RSD/UYU/ZAR/UAH/TND/RUB/TRY/SEK/NOK/AED/AMD/CAD/CHF/CLP/CZK/DKK/ARS/COP/CO2/PKR/HUF/XAF/XOF/AZN/NGN/ILS/LBP/MDL/ETB/TJS/BAM/KGS/TMT/BND/DEM/KSH/RON/BYN/NPR/GEL/KHR/LAK/BIF 等（完整對照見官方 PDF 第 65–66 頁）。

**換算備註（重要）**：
1. `IRR, COP, VND, IDR, MMV, LAK, KHR` 比率 **1:1000**。
2. `IR2, CO2, VN2, ID2, MMK` 用比率 **1:1**。

> 「同幣不同比率」的代碼（如 VND 1:1000 vs VN2 1:1）若選錯，金額對帳會差千倍，屬高風險設定點。

---

## Gaps

- 各 API 的回應碼適用集合，官方未逐 API 列出對應碼子集（僅給全域回應碼表）。
- `gameCode` / `categoryId` 完整對照表官方未隨此 PDF 提供（`gameCode` 須由 `getGames` 動態取得；`categoryId` 僅見 SM/TB/AD/BN/FH 等縮寫，全名未列）。
- `result` 欄位（getBetHistory，Varchar(1024)）的內容結構（如何解析遊戲結果）官方未說明。
- `securityKey` 取得與輪換流程官方文件未述（隨商戶開通提供）。
- 附錄 7 提供 RSA 解密範例（BouncyCastle，PKCS8 私鑰，解密後字串再 reverse），但 Merchant API 主流程未說明 RSA 使用時機，與 MD5 Digest 為不同機制，本文件不臆測其用途。

---

## Sources

- `Merchant_API_v4.8(CN).pdf`（74 頁，§1 協議 / §4 API 接口定義 / 附錄 1–7），`/Users/rb/Workspace/遊戲商/` SpadeGaming 下載包，2026-06-03 讀取。
- 補充：`Merchant_API_v3.8(CN).pdf`（舊版對照）、`my_info.txt` / `wintotoidn_info.txt`（各白標 Merchant Code）。
- 交叉驗證：`app/Vendors/SpadeGaming/Driver.php`（薄套 `app/Vendors/NextSpin/Driver.php`）、`config/vendor.php`、`app/Vendors/Models/SpadegamingAccount.php` / `SpadegamingBetRecord.php`。
