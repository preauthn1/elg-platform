# Play'n GO（PG）— 廠商原始 API 規格

> 來源檔案：
> - `MAL0155-1-Separate Account Manual.pdf`（文件日期 2019-11-14，Document id MAL0155）— 錢包 / 帳號 SOAP API **主要事實來源**
> - `MAL0096-1-Play'n GO ENGAGE API.pdf`（文件日期 2019-05-08）— 遊戲前端 JS / postMessage API（非後端）
> - `MAL0153-1-Game Launch Manual.pdf`（字型嚴重缺失，§2.1.4 參數表幾乎不可讀）— 遊戲啟動 URL（以實作回填）
> - `MAL0162-1-Live Data Feed.pdf` / `MAL0162-1-Live Data Feed_CN.pdf`（欄位名稱欄字型缺失）— 注單 / 對帳事件推送
> - `MAL0146-1-Currency, Country & Language Appendix - updated.pdf`（幣別 / 國家 / 語言附錄）
> - `info.txt`（測試站 endpoint 與帳密）、`Project ID (PID).docx`（PID = `QCK 8849`）
>
> 來源路徑：`遊戲商/20.PG(Play_n GO)/`
> **抓取 / 整理日期**：2026-06-03
>
> 我方實作分析、陷阱與落地對應見同目錄 `integration.md`。

Play'n GO 為歐系老虎機（VideoSlot）/ VideoBingo 遊戲商，以 **Separate Account（獨立帳號）** 方案接入：玩家資金平時保留在站方（Player Account），進入真錢遊戲前透過 `CreditAccount` 轉入 Play'n GO 的 Player Gaming Account，結束後以 `DebitAccount` 轉回——Transfer Wallet 模式。

---

## 通用約定

### 角色 / 方向

| 角色 | 說明 |
|---|---|
| 代理（站方） | 持有玩家 Player Account，主動呼叫 SOAP API |
| Play'n GO（遊戲商） | 持有 Player Gaming Account，回應 SOAP 請求；另推送 Live Data Feed |

所有錢包 / 帳號操作皆為**代理 → Play'n GO**（主動呼叫）。

### 請求格式

| 項目 | 值 | 來源 |
|---|---|---|
| 協定 | **SOAP**（XML over HTTP）over **SSL/TLS** | MAL0155 §1, §3.2 |
| SOAP namespace | `xmlns:v1="http://example.invalid/v1"` | MAL0155 各範例 |
| Endpoint 尾綴 | 必須以 `/{0}` 結尾，否則不回應 | MAL0155 §3.2 |
| 參數大小寫 | 大小寫敏感 | MAL0155 §3.2 |
| 參數順序 | **不可變動**，須與文件一致 | MAL0155 §3.2 |
| 必填規則 | 未標 `Optional` 者皆為必填 | MAL0155 §3.2 |
| 向後相容 | Play'n GO 只做「被動變更」：新增非必填參數、新增可為 null 的回應屬性 | MAL0155 §3.2 |

共通 SOAP 信封：

```xml
<soapenv:Envelope
    xmlns:soapenv="http://example.invalid/soap/envelope/"
    xmlns:v1="http://example.invalid/v1">
  <soapenv:Header/>
  <soapenv:Body>
    <v1:{Method}> ... </v1:{Method}>
  </soapenv:Body>
</soapenv:Envelope>
```

### 驗證方式

SOAP 連線以 **WS-Security UsernameToken**（即帳號密碼）認證，對應**API Service 帳號**（非後台 GMT 帳號），透過 HTTP `login` / `password` SoapClient option 傳入。**無獨立簽章演算法**；憑證即帳密。

### 通用回應格式

成功時 SOAP 回傳 HTTP 200，各 endpoint 自有回應欄位（見下方各節）。SOAP 錯誤時回傳 SOAP Fault；MAL0155 未定義業務錯誤碼——轉帳類 API 無明確失敗碼可參照。

### Base URL

| 環境 | URL |
|---|---|
| 測試（info.txt） | `https://example.invalid:48849/casinogameservice` |
| 正式 | 由 Play'n GO 提供（endpoint 需加 `/{0}` 尾綴） |

遊戲啟動 URL 使用獨立 domain（見 [GetTicket](#7-getticket--遊戲啟動)）。

### 時區

文件未明載時區規範；Live Data Feed 的時間欄位（`GamesessionStarted`/`GamesessionFinished`/`MessageTimestamp`）格式與時區需以實際接收值確認。

### PID（Product / Project Id）

Play'n GO 指派給站方的唯一識別，用於遊戲啟動 URL 的 `pid` 參數。測試環境 PID = `QCK 8849`（`Project ID (PID).docx`）。

---

## 1. RegisterUser

建立 Play'n GO 遊戲系統內的新玩家（首次登入時以最小資料建立）。（MAL0155 §4.1）

### 請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `ExternalUserId` | string(64) | ✅ | 玩家識別，**必須唯一** |
| `Username` | string(64) | ❌ | 玩家登入名 |
| `Nickname` | string(32) | ❌ | 暱稱（2–12 字元，`a-z A-Z 0-9 - . _` 與空格） |
| `Currency` | string(3) | ❌ | 玩家偏好幣別（見 MAL0146 Appendix） |
| `Country` | string(2) | ❌ | 國家代碼（見 MAL0146 Appendix） |
| `Birthdate` | DateTime | ❌ | 出生日（僅 Community 功能用） |
| `Registration` | DateTime | ❌ | 玩家在站方的註冊日 |
| `BrandId` | string(32) | ❌ | 多品牌 / 聯盟用；可設定為強制（由 Play'n GO 設定） |
| `Language` | string(2) | ❌ | 偏好語言（見 MAL0146 Appendix） |
| `IP` | string(32) | ❌ | 玩家裝置 IP |
| `Locked` | bool | ❌ | 玩家鎖定狀態 |
| `Gender` | string(1) | ❌ | `m` 或 `f` |

> `BrandId`、`IP`、`Locked`、`Gender` 文件明列 Optional；其餘依「未標 Optional 即必填」原則。

### 回應

成功時回 HTTP 200（無額外回傳體）。

### SOAP 範例

```xml
<v1:RegisterUser>
  <v1:UserInfo>
    <v1:ExternalUserId>1234</v1:ExternalUserId>
    <v1:Username>TestUser</v1:Username>
    <v1:Nickname>TestUser123</v1:Nickname>
    <v1:Currency>EUR</v1:Currency>
    <v1:Country>SE</v1:Country>
    <v1:Birthdate>1977-07-07</v1:Birthdate>
    <v1:Registration>1978-08-08</v1:Registration>
    <v1:BrandId>TestBrand</v1:BrandId>
    <v1:Language>sv_SE</v1:Language>
    <v1:IP>1253456</v1:IP>
    <v1:Locked>false</v1:Locked>
    <v1:Gender>m</v1:Gender>
  </v1:UserInfo>
</v1:RegisterUser>
```

---

## 2. Credit

存款 Player Account → Player Gaming Account（僅回交易序號）。（MAL0155 §4.2）

### 請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `ExternalUserId` | string(64) | ✅ | 玩家識別 |
| `Amount` | decimal | ✅ | 轉入金額 |
| `Currency` | string(3) | ❌ | 幣別 |
| `ExternalTransactionId` | string(32) | ❌ | 站方交易識別（用於對帳 / 去重） |
| `Game` | string(64) | ❌ | 限定資金只用於單一遊戲（gameName 或 gameId） |
| `ExternalGameSession` | varchar(32) | ❌ | 外部 game session 追蹤 id |

### 回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `TransactionId` | long | Play'n GO 交易識別 |

> 文件 §4.2 / §4.3 SOAP 範例存在 copy-paste typo：`<v1:Credit>` 開頭但以 `</v1:Debit>` 收尾（§4.3 範例甚至帶 `Real`/`TransactionId` 卻仍標 Credit）。屬官方文件瑕疵，不影響實作。

---

## 3. CreditAccount

存款 Player Account → Player Gaming Account，**並回傳當前餘額**。（MAL0155 §4.3）

### 請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `ExternalUserId` | string(64) | ✅ | 玩家識別 |
| `Amount` | decimal | ✅ | 轉入金額 |
| `Currency` | string(3) | ❌ | 幣別 |
| `ExternalTransactionId` | string(32) | ❌ | 站方交易識別 |
| `Game` | string(64) | ❌ | 限定單一遊戲 |
| `ExternalGameSession` | varchar(32) | ❌ | 外部 game session 追蹤 id |

### 回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Currency` | string(3) | 玩家餘額幣別 |
| `Real` | decimal | 玩家當前餘額 |
| `TransactionId` | long | 交易識別 |

---

## 4. Debit

提款 Player Gaming Account → Player Account（僅回交易序號）。（MAL0155 §4.4）

### 請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `ExternalUserId` | string(64) | ✅ | 玩家識別 |
| `Amount` | decimal | ✅ | 轉回金額 |
| `Currency` | string(3) | ❌ | 幣別 |
| `ExternalTransactionId` | string(32) | ❌ | 站方交易識別 |

### 回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `TransactionId` | long | 交易識別 |

### SOAP 範例

```xml
<v1:Debit>
  <v1:ExternalUserId>1234</v1:ExternalUserId>
  <v1:Amount>400</v1:Amount>
  <v1:Currency>EUR</v1:Currency>
  <v1:ExternalTransactionId>999999</v1:ExternalTransactionId>
</v1:Debit>
```

---

## 5. DebitAccount

提款 Player Gaming Account → Player Account，**並回傳當前餘額**。（MAL0155 §4.5）

### 請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `ExternalUserId` | string(64) | ✅ | 玩家識別 |
| `Amount` | decimal | ✅ | 轉回金額 |
| `Currency` | string(3) | ❌ | 幣別 |
| `ExternalTransactionId` | string(32) | ❌ | 站方交易識別 |

### 回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Currency` | string(3) | 玩家餘額幣別 |
| `Real` | decimal | 玩家當前餘額 |
| `TransactionId` | long | 交易識別 |

---

## 6. Balance

查詢 Player Gaming Account 餘額。可由遊戲在不特定時點觸發（如遊戲載入、餘額偏低時）。（MAL0155 §4.6）

### 請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `ExternalUserId` | string(64) | ✅ | 玩家識別 |

### 回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Currency` | string(3) | 餘額幣別 |
| `Real` | decimal | 玩家當前餘額 |

> 文件 §4.6 回傳表列為頂層 `Currency` / `Real`，但實作讀取路徑為 `$result->UserBalance->Real`（巢狀）。實際 SOAP 回應結構以三方為準，見 `integration.md`。

---

## 7. GetTicket（遊戲啟動）

取得遊戲啟動用的一次性 session ticket。（MAL0155 §4.7）

### 請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `ExternalUserId` | string(64) | ✅ | 玩家識別 |

### 回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Ticket` | string(32) | session token，**效期 1 小時** |

### 遊戲啟動 URL（MAL0153）

取得 Ticket 後，組裝遊戲啟動 URL：

```
{GAME_URL}?pid={API_PID}&game_id={gid}&channel={channel}&lang={lang}&ticket={ticket}&practice={0|1}
```

| URL 參數 | 可選值 / 說明 |
|---|---|
| `pid` | Play'n GO 指派的 Product Id |
| `game_id`（文件稱 `gid`） | 要啟動的遊戲，例 `bookofdead`、`firejoker` |
| `channel` | `mobile` / `desktop` / `mini` |
| `lang` | 語言代碼（見下方語言代碼表；完整清單見 MAL0146 Appendix） |
| `ticket` | GetTicket 回傳的 Ticket |
| `practice` | `1` = Fun play（試玩）；`0` = Real play（真錢） |

> MAL0153 §2.1.4「Supported launch parameters」因字型嚴重缺失（PDF 渲染為方塊）幾乎不可讀；以上為可讀片段（`pid`/`practice`/`channel`/`gid`/`origin`/`mode`）與實作回填的組合，無法逐欄交叉驗證完整參數清單。

### ENGAGE API — 遊戲前端 JS（MAL0096，非後端 API）

`MAL0096 ENGAGE API` 為遊戲前端 JS 模組，供站方頁面控制遊戲 UI（`gameDisable` / `gameEnable` / `gameEnd` / `logout` / `refreshBalance` / `inGameMessage` 等 request，與 `roundStarted` / `roundEnded` / `balanceUpdate` / `roundWin` 等 event）。Desktop 直接呼叫 `Engage.request(...)`；行動 / 嵌入用 `window.postMessage` 經 `ContainerLauncher`。後端 Driver 不涉及。

### Reload（免費遊戲重載，MAL0155 §5）

Desktop 透過 `reloadgame` 函式、Mobile 透過 `&reloadgame=<url>` 重載同一遊戲取得新 ticket，用於 Free Game / 多次免費遊戲場景。相關選項 `showingamefreegamemessage` 需聯絡 Play'n GO 開啟，並補實作 `AddFreegameOffers`。

### 語言代碼對照（MAL0146 Appendix 子集）

| 語言 | Play'n GO `lang` 代碼 |
|---|---|
| 繁體中文 | `zh_TW` |
| 簡體中文 | `zh_CN` |
| 馬來語 | `ms_MY` |
| 印尼語 | `id_ID` |
| 英語 | `en_GB` |

> 完整幣別 / 國家 / 語言代碼見 MAL0146 Appendix。

---

## 8. GetUnfinishedGames

查詢玩家未結束的遊戲局。玩家可重連續玩；逾設定天數的未完成局由系統自動 void 並回送站方（bet voided）。（MAL0155 §4.8）

### 請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `ExternalId` | string(64) | ✅ | 玩家識別 |

### 回應欄位（每筆 OpenGameId）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `GameId` | int | 遊戲識別（數字） |
| `Gid` | string(32) | 遊戲識別（字串） |
| `Serialized` | bool | 遊戲資料是否已存入資料庫 |
| `MobileGame` | bool | 是否為手機遊戲 |
| `Bet` | money（`Value` + `Currency`） | 注額 |
| `Currency` | string(3) | 幣別 |
| `GameName` | string(64) | 遊戲名稱 |
| `GameType` | string(32) | 遊戲類型 |

### SOAP 範例

```xml
<!-- 請求 -->
<v1:GetUnfinishedGames>
  <v1:ExternalId>TestUser</v1:ExternalId>
</v1:GetUnfinishedGames>

<!-- 回應 -->
<GetUnfinishedGamesResponse xmlns="http://example.invalid/v1">
  <OpenGameIds xmlns:i="http://example.invalid/2001/XMLSchema-instance">
    <OpenGameId>
      <GameId>307</GameId>
      <Gid>firejoker</Gid>
      <Serialized>true</Serialized>
      <MobileGame>false</MobileGame>
      <Bet><Value>1425.0000</Value><Currency>EUR</Currency></Bet>
      <GameName>Fire Joker</GameName>
      <GameType>casino</GameType>
    </OpenGameId>
  </OpenGameIds>
</GetUnfinishedGamesResponse>
```

---

## Live Data Feed（注單 / 對帳事件推送）

Play'n GO 不在 SOAP API 回傳注單，而是透過 **Live Data Feed** 近即時將「所有交易與登入」事件 POST 到站方提供的 endpoint（MAL0162）。

### 推送規則

- 事件排隊推送；站方須回 HTTP 成功狀態碼，否則該批重送。
- **未在約一週內被成功接收的事件會被永久刪除**。
- 訊息可能重送 / 亂序，須以 global message id 去重，並容忍「先收到 Release 再收到保留（投注）」的亂序。

### 主要訊息類型（MAL0162 §3）

| 訊息類型 | 說明 |
|---|---|
| `CasinoPlayerLogin` | 玩家登入 |
| `CasinoPlayerLogout` | 玩家登出 |
| `CasinoTransactionReleaseOpen` | 單回合開始（保留投注） |
| `CasinoTransactionReleaseClosed` | 單 session 結算彙總（**注單對帳主要事件**） |
| `CasinoFreegameEnd` | 免費遊戲結束 |

### CasinoTransactionReleaseClosed 欄位（注單對帳核心）

`CasinoTransactionReleaseClosed` 攜帶單一遊戲 session 的彙總（投注 / 中獎 / 回合數 / 起訖時間 / 匯率 / jackpot 貢獻與獲得等）。對應 `PgBetRecord` 欄位：

| Feed 欄位 | 說明 |
|---|---|
| `TransactionId` | 交易識別 |
| `ProductGroup` | 遊戲產品分組 |
| `ExternalUserId` | 玩家識別（站方） |
| `GamesessionId` | 遊戲 session 識別 |
| `GameId` | 遊戲識別 |
| `Currency` | 幣別 |
| `ExternalTransactionId` | 站方交易識別 |
| `Balance` | 結算後餘額 |
| `NumRounds` | 回合數 |
| `TotalLoss` | 投注總額 |
| `TotalGain` | 贏得總額 |
| `GamesessionStarted` | session 開始時間（`betTimeColumn`） |
| `GamesessionFinished` | session 結束時間（`payoutTimeColumn`） |
| `ExchangeRate` | 匯率 |
| `ContextId` | 上下文識別 |
| `ClientIP` | 玩家 IP |
| `ExternalFreegameId` | 外部免費遊戲識別 |
| `JackpotLoss` | Jackpot 貢獻 |
| `JackpotGain` | Jackpot 獲得 |
| `FreegameRounds` | 免費遊戲回合數 |
| `FreegameBet` | 免費遊戲投注 |
| `FreegameWin` | 免費遊戲贏得 |
| `Channel` | 裝置類型 |
| `MessageId` | 訊息去重識別 |
| `MessageTimestamp` | 訊息時間戳 |

> MAL0162 英文版與 CN 版的欄位「名稱 / 類型」欄皆渲染為方塊，僅章節標題、訊息類型與中文「說明」欄可讀。上表欄位名稱以 **PgBetRecord 實作 + feed 章節標題 / 說明**交叉佐證；個別欄位的三方原始命名 / 型別 / 日期格式無法從 PDF 逐欄確認，以實際接收 payload 為準。

---

## 對帳 / 補單機制

| 手段 | 說明 |
|---|---|
| Live Data Feed | 近即時推送，事件約一週後永久刪除 |
| GetUnfinishedGames | 主動查詢玩家未結束遊戲局，可補掛單清理 |
| Separate Account 交易紀錄 | MAL0155 §2 說明：**僅保留 3 個月**。對帳 / 補帳工具須在此期限內完成。 |

> 無「補單查詢」或「交易狀態查詢」API（Credit / Debit 系列無狀態查詢端點）。

---

## 錯誤碼

MAL0155 **未定義業務錯誤碼**；Credit / Debit / CreditAccount / DebitAccount 失敗時回 SOAP Fault，無明確失敗碼可列舉。Balance / GetTicket 等亦同。

三方回應異常需依 SOAP Fault 訊息判斷；轉帳結果判斷邏輯見 `integration.md`「轉帳結果判斷」節。

---

## Sources

- `MAL0155-1-Separate Account Manual.pdf`（2019-11-14，Document id MAL0155）— 帳號 / 錢包 SOAP API 全部章節（§1–§5）。
- `MAL0096-1-Play'n GO ENGAGE API.pdf`（2019-05-08）— 前端 ENGAGE JS / postMessage 說明（§附註）。
- `MAL0153-1-Game Launch Manual.pdf` — 遊戲啟動 URL（大量缺字，以實作回填）。
- `MAL0162-1-Live Data Feed.pdf` / `MAL0162-1-Live Data Feed_CN.pdf` — 注單推送事件規格（欄位類型欄缺字，以 PgBetRecord 交叉佐證）。
- `MAL0146-1-Currency, Country & Language Appendix - updated.pdf` — 代碼附錄。
- `Project ID (PID).docx`、`info.txt` — 測試環境 PID / endpoint / 帳密。
- 交叉驗證：`app/Vendors/Pg/Driver.php`、`app/Vendors/Models/PgBetRecord.php`、`config/vendor.php`。
