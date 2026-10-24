# E88Sport — 廠商原始 API 規格

> 原始來源：工單 `target/tickets#3869` 附件 `SportService_api_ch_v3.6_转账.pdf`（SPORT API Reference，轉帳錢包版）。本檔為該 PDF 的忠實重述。
> 我方接入分析（vendor_id=247 轉帳錢包直對部署）、踩坑與落地對應見同目錄 `integration.md`。

E88Sport 為球盤（體育）遊戲商，採 **Transfer Wallet（轉帳錢包）** 模式接入。

---

## 通用約定

- **請求方式**：`HttpPost` / `HttpGet` / `HttpSoap` 皆可。
- **回應格式**：**XML**（`<?xml version="1.0" encoding="utf-8"?>`，根節點 `<response>`）。
- **驗證**：IP 白名單 + `APIPassword`（每支 API 必帶）。
- 文件中的 `domain` 指 **API 域名**；`memberdomain` 指 **會員網域名**。

### 通用回應結構

```xml
<?xml version="1.0" encoding="utf-8"?>
<response>
  <errcode>000000</errcode>   <!-- 000000 / 0 表成功，其餘見「錯誤編號」章節 -->
  <errtext></errtext>
  <result> ... </result>
</response>
```

---

## API 一覽

| # | 方法 | 功能 | 備註 |
|---|---|---|---|
| 2.1 | Register | 產生帳號並綁定 | errcode=100002（已存在）視為成功 |
| 2.2 | RegisterByLimit | 產生帳號並綁定（含限額） | |
| 2.3 | Login | 會員登入，取得 LoginCode | |
| 2.4 | Logout | 強制登出會員 | |
| 2.5 | GetOnlineMember | 取得在線會員列表 | |
| 2.6 | Transfer | 轉帳（存入 / 取款） | 收到結果後**務必**呼叫 CheckTransfer 確認 |
| 2.7 | CheckTransfer | 檢查轉帳是否成功（依 SerialNumber） | |
| 2.8 | CheckTransferByAccount | 檢查轉帳是否成功（依 Account+SerialNumber） | |
| 2.9 | GetBalance | 取得餘額 | |
| 2.10 | UpdateMemberLimit | 修改限額 | **已淘汰 → 用 2.30** |
| 2.11 | GetMemberLimit | 取得限額 | **已淘汰 → 用 2.29** |
| 2.12 | GetBetSheet | 取得注單列表（請求間隔 ≥ 1 分鐘） | **已淘汰 → 用 2.28** |
| 2.13 | GetBetSheetByReport | 依報表時間取得注單列表（間隔 ≥ 1 分鐘） | |
| 2.14 | GetParlayBetSheet | 取得混合過關詳細注單（間隔 ≥ 1 分鐘） | |
| 2.15 | GetLeagueName | 取得聯賽名稱（每次回 50 筆） | |
| 2.16 | GetTeamName | 取得球隊名稱（每次回 50 筆） | |
| 2.17 | GetMatchResult | 取得比賽結果 | |
| 2.18 | UpdateGroupComm | 修改會員佣金組別 | |
| 2.19 | UpdateStatus | 修改會員狀態（關閉 / 暫停） | |
| 2.20 | GetStatus | 取得會員狀態 | |
| 2.21 | GetTransferReport | 取得會員轉帳記錄 | |
| 2.22 | GetCurrentBalance | 取得會員當前餘額 | |
| 2.23 | Get12pmBalance | 取得每日 12pm 餘額與未結算注金（保留 60 天，須開通） | |
| 2.24 | CheckTransfer2 | 同 CheckTransfer，回傳更多資訊（含正負金額） | 僅保留 **7 天**資料 |
| 2.25 | GetGoalJackpotMatchResult | 取得龍門積寶進球成績 | |
| 2.26 | /api/Sportbook/GetTeamName | 取得球隊名稱（新版） | 參考 `Request.Response.ts` |
| 2.27 | /api/Sportbook/GetLeagueName | 取得聯賽名稱（新版） | 參考 `Request.Response.ts` |
| 2.28 | /api/BetSheet/GetBetsheet | 取得注單（新版） | 參考 `Request.Response.ts` |
| 2.29 | /api/Sportbook/GetMemberLimit | 取得會員投注設置（新版） | 參考 `Request.Response.ts` |
| 2.30 | /api/Sportbook/UpdateMemberLimit | 更新會員投注設置（新版） | 參考 `Request.Response.ts` |

> 2.10–2.12、2.15–2.16 為舊端點；新接入應改用 2.28–2.30、2.26–2.27（端點改為 `/api/...` 形式，回應結構詳見廠商提供的 `Request.Response.ts`）。

---

## 2.1 Register

`http://domain/sportapi.asmx/Register`

| 參數 | 類型 | 說明 |
|---|---|---|
| APIPassword | Varchar(32) | 登入 API 密碼 |
| AgentAccount | Varchar(20) | 代理帳號（為空則 Currency 有效；不為空則 Currency 無效） |
| MemberAccount | Varchar(20) | 會員帳號，字元 `Regex[a-zA-Z0-9@]`，**不分大小寫** |
| NickName | Varchar(32) | 會員暱稱，顯示於使用者介面 |
| Currency | Varchar(10) | 會員貨幣 |

回應：`<errcode>000000</errcode>`，`<result></result>`。

## 2.2 RegisterByLimit

`http://domain/sportapi.asmx/RegisterByLimit`

在 Register 參數基礎上增加限額欄位：

| 參數 | 類型 | 說明 |
|---|---|---|
| MinBet | Int | 最小投注（-1 不修改） |
| MaxBet | Int | 最大投注（-1 不修改） |
| PerMaxBet | Int | 單場最大投注（-1 不修改） |
| MixMinBet | Int | 混合過關最小投注（-1 不修改） |
| MixMaxBet | Int | 混合過關最大投注（-1 不修改） |
| DayLimit | Int | 每天投注限制（信用倍數，範圍 -1~100；-1 不修改、0 不限制） |
| GroupComm | Varchar(1) | 佣金組別 |

## 2.3 Login

`http://domain/sportapi.asmx/Login`

| 參數 | 類型 | 說明 |
|---|---|---|
| APIPassword | Varchar(32) | 登入 API 密碼 |
| MemberAccount | Varchar(20) | 會員帳號（不分大小寫） |

回應 `<result>` = 登入 Web 標識（LoginCode，Varchar(32)），用於登入會員網（見「登入會員網」章節）。

## 2.4 Logout

`http://domain/sportapi.asmx/Logout` — 參數 `APIPassword`、`MemberAccount`。強制登出會員。

## 2.5 GetOnlineMember

`http://domain/sportapi.asmx/GetOnlineMember` — 參數 `APIPassword`、`MemberAccount`。
回應 `<result>` 內多個 `<Account>` 節點 = 在線會員帳號。

## 2.6 Transfer（轉帳）

> ⚠ **注**：收到回傳結果後，**無論成功或失敗，都必須呼叫 CheckTransfer 檢查轉帳狀態**。

`http://domain/sportapi.asmx/Transfer`

| 參數 | 類型 | 說明 |
|---|---|---|
| APIPassword | Varchar(32) | 登入 API 密碼 |
| MemberAccount | Varchar(20) | 會員帳號 |
| SerialNumber | Varchar(100) | 流水單號 |
| Amount | Decimal | 轉帳金額 |
| TransferType | Int | 轉帳類型；`0`=存款到 API，`1`=從 API 取款 |
| Key | Varchar(6) | 驗證碼，見下方規則 |

**Key 簽名規則：**

```
Key = MD5(lowercase(APIPassword + MemberAccount + Amount)) 取後 6 位
注意：Amount 必須是 4 位小數
例如：APIPassword=[REDACTED] MemberAccount=test Amount=10000.0000
      → MD5 後取後 6 位 = 9eebc4
```

回應 `<result>`：`Account` / `SerialNumber` / `Amount` / `Balance`（會員餘額）。

## 2.7 CheckTransfer

`http://domain/sportapi.asmx/CheckTransfer` — 參數 `APIPassword`、`SerialNumber`。
成功回 `errcode=000000`，`<result></result>`。

## 2.8 CheckTransferByAccount

`http://domain/sportapi.asmx/CheckTransfer` — 參數 `APIPassword`、`MemberAccount`、`SerialNumber`。
回應 `<result>` 為數值（範例 `0`）。

## 2.9 GetBalance

`http://domain/sportapi.asmx/GetBalance` — 參數 `APIPassword`、`MemberAccount`。
回應 `<result>`：`Account` / `Balance`（會員餘額，Decimal）。

## 2.10 UpdateMemberLimit（已淘汰 → 2.30）

`http://domain/sportapi.asmx/UpdateMemberLimit` — 參數同 RegisterByLimit 的限額欄位（APIPassword、MemberAccount、MinBet、MaxBet、PerMaxBet、MixMinBet、MixMaxBet、DayLimit）。

## 2.11 GetMemberLimit（已淘汰 → 2.29）

`http://domain/sportapi.asmx/GetMemberLimit` — 參數 `APIPassword`、`MemberAccount`。
回應限額欄位：MinBet / MaxBet / PerMaxBet / MixMinBet / MixMaxBet / DayLimit。

## 2.12 GetBetSheet（已淘汰 → 2.28）

`http://domain/sportapi.asmx/GetBetSheet`

| 參數 | 類型 | 說明 |
|---|---|---|
| APIPassword | Varchar(32) | 登入 API 密碼 |
| MemberAccount | Varchar(20) | 會員帳號 |
| BetID | Long | 注單編號 |
| Status | Int | 注單狀態：0=ALL、1=Running、2=Settled、3=Rejected |
| FromDate | Datetime | 開始時間 `yyyy-MM-dd HH:mm:ss` |
| ToDate | Datetime | 結束時間（FromDate 到 ToDate 不能超過 30 分鐘） |

回應 `<betlist>` → 多個 `<bet>`，欄位見下方「注單欄位」。

### 注單欄位（GetBetSheet / GetBetSheetByReport 通用）

| 欄位 | 類型 | 說明 |
|---|---|---|
| BetID | Long | 注單編號 |
| Account | Varchar(32) | 會員帳號 |
| BetAmount | Decimal | 下注金額 |
| BetOdd | Decimal | 投注賠率 |
| Win | Decimal | 輸贏 |
| Comm | Decimal | 佣金 |
| BetType | Varchar(32) | 投注類型 |
| OddStyle | Varchar(1) | 賠率樣式 |
| Hdp | Decimal | 讓球數 |
| BetPos | Varchar(32) | 投注位置 |
| Live | Boolean | 是否滾球 |
| BetDate | Datetime | 投注時間 |
| Status | Varchar(32) | 注單狀態（Rejected / Running / Settled） |
| Result | Varchar(32) | 輸贏結果（Win / Lose / Win 1/2 / Lose 1/2 / Draw） |
| ReportDate | Datetime | 注單報表時間 |
| SportName | Varchar(32) | 體育名字 |
| LeagueID | Int | 聯賽編號 |
| HomeID | Int | 主隊編號 |
| AwayID | Int | 客隊編號 |
| BetScore | Varchar(32) | 投注時比分 |
| MatchDate | Datetime | 比賽時間 |
| BetIP | Varchar(32) | 投注 IP |
| MatchID | Int | 比賽編號 |
| UpdateTime | Datetime | 注單修改時間 |
| OutLevel | Decimal/Empty | OddStyle=B 時才有值 |

> 當 `<BetType>GoalJackpot</BetType>` 且 `<Status>Settled</Status>` 時，請用 `GetGoalJackpotResult()` 取成績。

## 2.13 GetBetSheetByReport

`http://domain/sportapi.asmx/GetBetSheetByReport` — 參數 `APIPassword`、`MemberAccount`、`BetID`、`Status`（0=ALL/1=Running/2=Settled/3=Rejected）、`ReportDate`（`yyyy-MM-dd`）。回應欄位同「注單欄位」。

## 2.14 GetParlayBetSheet

`http://domain/sportapi.asmx/GetParlayBetSheet` — 參數 `APIPassword`、`MemberAccount`、`BetID`、`FromDate`、`ToDate`（間隔不超過 30 分鐘）。

回應 `<betlist>` → `<bet>`：Lastdate / BetID / No（混合過關編號） / BetOdd / BetType / Hdp / BetPos / Status / Result / SportName / LeagueID / HomeID / AwayID / MatchDate / MatchID / UpdateTime。

## 2.15 GetLeagueName

`http://domain/sportapi.asmx/GetLeagueName`

> 此接口每次回 50 筆；下一次請求時請傳回上次結果中最後一個聯賽編號，並 `MinLeagueID=true`。

| 參數 | 類型 | 說明 |
|---|---|---|
| APIPassword | Varchar(32) | 登入 API 密碼 |
| LeagueID | Int | 聯賽編號（>0 起作用） |
| MinLeagueID | Boolean | true=取大於指定編號的聯賽；false=取指定編號的聯賽 |

回應 `<league>`：LeagueID + 各語系名稱欄位（EN / CH / TW / VN / TH / KH / KO / JP）。

## 2.16 GetTeamName

`http://domain/sportapi.asmx/GetTeamName` — 規則同 GetLeagueName（參數 `TeamID`、`MinTeamID`）。回應 `<team>`：TeamID + 各語系名稱（EN/CH/TW/VN/TH/KH/KO/JP）。

## 2.17 GetMatchResult

`http://domain/sportapi.asmx/GetMatchResult` — 參數 `APIPassword`、`MatchID`。

回應 `<result>`：HalfScore（半場比分） / FullScore（全場比分） / HomeCard（主隊紅卡） / AwayCard（客隊紅卡） / FirstGoal（最先進球） / LastGoal（最後進球）。

## 2.18 UpdateGroupComm

`http://domain/sportapi.asmx/UpdateGroupComm` — 參數 `APIPassword`、`MemberAccount`、`GroupComm`（佣金組別）。

## 2.19 UpdateStatus

`http://domain/sportapi.asmx/UpdateStatus`

| 參數 | 類型 | 說明 |
|---|---|---|
| APIPassword | Varchar(32) | 登入 API 密碼 |
| MemberAccount | Varchar(32) | 會員帳號 |
| Closed | Boolean | 不能登入（true=關閉，false=打開） |
| Paused | Boolean | 可以登入、不能下注（true=暫停，false=打開） |

## 2.20 GetStatus

`http://domain/sportapi.asmx/GetStatus` — 參數 `APIPassword`、`MemberAccount`。
回應 `<result>`：Closed / Paused（Boolean，語意同 UpdateStatus）。

## 2.21 GetTransferReport

`http://domain/sportapi.asmx/GetTransactionReport`

| 參數 | 類型 | 說明 |
|---|---|---|
| APIPassword | Varchar(32) | 登入 API 密碼 |
| AgentAccount | Varchar(20) | 代理帳號（查代理下所有會員轉帳記錄） |
| MemberAccount | Varchar(20) | 會員帳號（只查單一會員時用） |
| FromDate | Datetime | `yyyy-mm-dd hh:mm:ss` |
| ToDate | Datetime | `yyyy-mm-dd hh:mm:ss` |

回應 `<result>`：TransferDate / Account / SerialNumber / Amount。

## 2.22 GetCurrentBalance

`http://domain/sportapi.asmx/GetCurrentBalance` — 參數 `APIPassword`、`AgentAccount`、`MemberAccount`。
回應 `<result>`：CurrentDate / Account / Balance（當前餘額）。

## 2.23 Get12pmBalance

`http://domain/sportapi.asmx/Get12pmBalance` — 參數 `APIPassword`、`BalanceDate`（`yyyy-mm-dd hh:mm:ss`）、`AgentAccount`、`MemberAccount`。

回應 `<result>`：

| 欄位 | 類型 | 說明 |
|---|---|---|
| BalanceDate | Date | 餘額日期 `yyyy-mm-dd` |
| UpdateTime | Datetime | 更新時間 |
| Account | Varchar(32) | 會員帳號 |
| Balance | Decimal | 當前餘額 |
| Outstanding1 | Decimal | 未結算注金金額 |
| Outstanding2 | Decimal | 未結算實際扣除金額（因負水盤所扣的真正金額會與注金不一致） |

## 2.24 CheckTransfer2

`http://domain/sportapi.asmx/CheckTransfer2` — 參數 `APIPassword`、`SerialNumber`。**僅保留 7 天資料。**

回應（有資料）`<result>`：`Account` / `Amount`。
> remark：Amount 正值 = 轉入（transfer in）、負值 = 轉出（transfer out）。

無資料時 `errcode=400001`（流水號不存在）。

## 2.25 GetGoalJackpotMatchResult

`http://domain/sportapi.asmx/GetGoalJackpotMatchResult` — 參數 `APIPassword`、`MatchID`。取龍門積寶進球分鐘成績。

回應 `<result>` 為 **JSON 字串**：

```json
{
  "matchID": 2489442,
  "matchResults": [
    { "minute": "33'", "goal": 1 },
    { "minute": "44'", "goal": 1 },
    { "minute": "50'", "goal": 1 }
  ],
  "updateTime": "2020-08-28 20:41:12"
}
```

- `matchResults[].minute`：進球分鐘（可為 `"No Goal"`、`"First Half extra time"` 等特殊值）；`goal`：進球數量。
- 還沒有成績時 `matchResults` 為空陣列 `[]`。

## 2.26–2.30（新版端點）

| # | 端點 | 功能 |
|---|---|---|
| 2.26 | `/api/Sportbook/GetTeamName` | 拉取球隊名稱 |
| 2.27 | `/api/Sportbook/GetLeagueName` | 拉取聯賽名稱 |
| 2.28 | `/api/BetSheet/GetBetsheet` | 拉取注單 |
| 2.29 | `/api/Sportbook/GetMemberLimit` | 拉取會員投注設置 |
| 2.30 | `/api/Sportbook/UpdateMemberLimit` | 更新會員投注設置 |

> 以上請求/回應結構請參考廠商提供的 `Request.Response.ts`。

---

## 3. 登入會員網

`http://memberdomain/Memberlogin.aspx`

| 參數 | 類型 | 說明 |
|---|---|---|
| LoginCode | Varchar(32) | 登入標識（由 2.3 Login 取得） |
| Language | Varchar(10) | 登入語言（見「語言說明」） |
| PageStyle | Int | 登入頁面樣式（見「頁面樣式說明」） |
| OddsStyle | Int | 盤口樣式：1=my、2=eu、3=hk、4=in |

範例：`http://memberdomain/Memberlogin.aspx?LoginCode=...&Language=ch&PageStyle=1&OddsStyle=1`

---

## 4. 返回錯誤編號說明

| 錯誤編號 | 說明 |
|---|---|
| 000000 | 操作成功 |
| 000001 | 系統維護中 |
| 000002 | IP 不在白名單 |
| 000003 | API 密碼錯誤 |
| 000004 | 系統繁忙 |
| 000005 | 查詢時間範圍越界 |
| 000007 | 查詢太繁密了，請間隔 20 秒再查詢 |
| 000008 | 沒有資料 |
| 010001 | 會員帳號不存在 |
| 100001 | 貨幣不可用，請聯繫技術 |
| 100002 | 會員帳號已經被使用 |
| 100003 | 添加帳號失敗 |
| 100004 | 貨幣錯誤 |
| 200001 | 登入操作失敗 |
| 200002 | 帳號關閉 |
| 300001 | 輸入存取款金額小於或等於 0 |
| 300002 | 存取款失敗 |
| 300003 | 存款流水號已經存在系統中 |
| 300004 | 會員餘額不足 |
| 300005 | 存取款 Key 校驗不正確 |
| 400001 | 存取款流水號不存在 |
| 500001 | 修改限額錯誤 |
| 600001 | 限額資料不存在，請聯繫技術人員 |
| 700001 | 修改佣金組別錯誤 |
| 700002 | 佣金組別不存在 |
| 800001 | 修改會員狀態錯誤 |

---

## 5. 貨幣說明

| Currency | 說明 |
|---|---|
| MYR | Malaysia |
| USD | United States |
| RMB | China |
| HKD | HongKong |
| THB | Thailand |
| JPY | Japan |
| KHR | Korea(1:1000) |
| VND | Vietnam(1:1000) |
| IDR | Indonisia(1:1000) |
| SGD | Singapore |
| KRW | Korea |

---

## 6. 語言說明

| 代碼 | 說明 |
|---|---|
| CH | 簡體中文 |
| EN | 英文 |
| JP | 日文 |
| KO | 韓文 |
| TH | 泰文 |
| TW | 繁體中文 |
| VN | 越南文 |

---

## 7. 頁面樣式說明

| PageStyle | 說明 |
|---|---|
| 1 | ASC |

---

## 8. 佣金組別說明

組別代碼：`A` / `B` / `C` / `D` / `E` / `F`（具體佣金比率由代理後台設定）。

---

## 9. 投注類型（BetType）

`占成` 欄位區分 Live=0（非滾球）/ Live=1（滾球）的對應分類，`佣金` 欄位為佣金歸類。

| 投注類型 | Live=0 占成 | Live=1 占成 | 佣金 |
|---|---|---|---|
| Full Handicap | Non live HDP/OU/OE | Live HDP/OU/OE | Group |
| First Handicap | Non live HDP/OU/OE | Live HDP/OU/OE | Group |
| Over/Under | Non live HDP/OU/OE | Live HDP/OU/OE | Group |
| First Over/Under | Non live HDP/OU/OE | Live HDP/OU/OE | Group |
| 1X2 | Non live 1X2 | Live 1X2 | 1X2 |
| First 1X2 | Non live 1X2 | Live 1X2 | 1X2 |
| Odd/Even | Non live HDP/OU/OE | Live HDP/OU/OE | Group |
| First Odd/Even | Non live HDP/OU/OE | Live HDP/OU/OE | Group |
| Correct Score | Others | Others | Others |
| First Correct Score | Others | Others | Others |
| Total Goal | Others | | Others |
| First Goal/Last Goal | Non live HDP/OU/OE | Non live HDP/OU/OE | Others |
| Half Time/Full Time | Others | Others | Others |
| ML | Others | Others | Group |
| Outright | Others | Others | Others |
| Mix Parlay | MixParlay | MixParlay | MixParlay |

> 完整玩法清單另含（多數 2022 後新增、占成欄留空）：2H Odd/Even、2H Correct Score、GoalJackpot、Double Chance、1H/2H Double Chance、1H Total Goal、1H Total Goal2、Draw No Bet、Winning Margin、CleanSheet、To Win To Nil、Both Teams To Score、1H Both Teams To Score、Both/One/Neither Team To Score、To Win Either Half、To Win Both Halves、Last Digit Score、1H Last Digit Score、Home/Away Team Over/Under、1H Home/Away Team Over/Under、Race To 2/3 Goals、3-Way Handicap、Home/Away Last Digit Score、1H Home/Away Last Digit Score、1H 3-Way Handicap、1H Correct Corners、2H Handicap、2H 1X2、2H Over/Under、1X2 & Goals O/U、1X2 & Both Teams To Score。

---

## 10. 投注位置（BetPos）

依玩法分組對應不同投注位置：

| 玩法群組 | 投注位置 |
|---|---|
| Full Handicap / First Handicap / ML / 1H ML / Draw No Bet / CleanSheet / To Win To Nil / To Win Either Half / To Win Both Halves / Race To 2~3 Goals / 2H Handicap | `Home`（主隊） / `Away`（客隊） |
| Over/Under / First Over/Under / Home(1H) Team Over/Under / Away(1H) Team Over/Under / 2H Over/Under | `Over`（大） / `Under`（小） |
| 1X2 / First 1X2 / 3-Way Handicap / 1H 3-Way Handicap / 2H 1X2 | `Home` / `Away` / `Draw` |
| Odd/Even / First Odd/Even / 2H Odd/Even | `Odd`（單） / `Even`（雙） |
| Correct Score / First Correct Score / 2H Correct Score | `0-0`…`4-4` 各比分 + `AOS`（其他比分） |
| Total Goal | `0-1` / `2-3` / `4-6` / `7Up` |
| 1H Total Goal | `0-1` / `2-3` / `4 & Over` |
| 1H Total Goal2 | `0` / `1` / `2` / `3 & Over` |
| First Goal/Last Goal / 1H First Goal/Last Goal | `Home First` / `Home Last` / `Away First` / `Away Last` / `No Gold`（主客都沒進球） |
| Half Time/Full Time | `Home/Home`、`Home/Draw`、`Home/Away`、`Draw/Home`、`Draw/Draw`、`Draw/Away`、`Away/Home`、`Away/Draw`、`Away/Away` |
| Mix Parlay | `2X1`、`3X1`、`4X1`、`4X4`、`4X5`、`4X11`、`5X1`…`10X1`（混合過關各組合） |
| Goal Jackpot | `1'`…`90'`（進球分鐘）、`First Half extra time`（上半場加時）、`Second Half extra time`（下半場加時）、`No Goal`（無進球） |
| Winning Margin | `Home Win 1~4 or More Goals`、`Away Win 1~4 or More Goals`、`Any Score Draw (Exclude 0-0)`、`No Goal` |
| Both Teams To Score / 1H Both Teams To Score | `Yes` / `No` |
| Both/One/Neither Team To Score | `Both` / `One` / `No Goal` |
| Last Digit Score 系列 | `0`…`9` |
| 1H Correct Corners | `0-0`…`5-4` 各角球比分 + `AOS` |
| 1X2 & Goals O/U | `Home/Away/Draw & Over`、`Home/Away/Draw & Under` |
| 1X2 & Both Teams To Score | `Home/Away/Draw & Yes`、`Home/Away/Draw & No` |
