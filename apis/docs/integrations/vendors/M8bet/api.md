# M8bet（Sportsbook）— 三方 API 規格

> 來源：整合自原始廠商對接文檔（README，含端點與驗證說明）。
> 本檔為廠商 API 純規格；本平台落地分析見 `integration.md`。

---

## 通用約定

### Endpoint 格式

```
POST https://<host>/apijs.aspx?secret=[REDACTED]
```

- `<host>`：API host（範例 `example.invalid`，正式以 `vendor_infos.info.AUTH_URL` 為準）
- 參數一律放 query string，body 為空
- 回應格式為 JSON

### 驗證方式

| 機制 | 說明 | 失敗 errcode |
|---|---|---|
| `secret` | Agent secret key，每個請求帶於 query string（**明文傳輸**） | `-2` Invalid secret |
| IP 白名單 | 來源 IP 必須先向 M8bet 報備加白 | `-3` Ip address not allow |
| `agent` | Agent name，亦須正確 | `-7` Invalid agent |

M8bet **無加密簽名機制**（無 HMAC/MD5 hash 的 `signature` 欄位），憑 `secret`＋IP 白名單驗證。

### 通用請求參數

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `secret` | string | ✅ | Agent secret key |
| `action` | string | ✅ | 動作名稱 |
| `agent` | string | ✅ | Agent name |
| `username` | string | 視 action | 玩家帳號，格式 `A-Za-z0-9`、長度 1–64（errcode `-4`） |

### 通用回應格式

```jsonc
{"errcode": <ErrCode>, "errtext": "<ErrText>", "result": <Result>}
```

| 欄位 | 說明 |
|---|---|
| `errcode` | `0` = 成功；其他 = 失敗 |
| `errtext` | 錯誤文字，成功時為空字串 |
| `result` | 成功時的資料（字串、物件或陣列），失敗時為空字串 |

### 時區與一天的定義

| 項目 | 值 |
|---|---|
| 時區 | **UTC+08:00** |
| 一天的定義 | 前日 **12:00PM** 至 當日 **11:59:59AM** |
| 記錄查詢時間條件 | 結算時間 = 開彩日期 |
| API 數據保留 | **30 天**（已標記即無法再抓取） |
| 前台數據保留 | 180 天可查詢 |
| 投注額 | = 遊戲商「营业额」 |
| 有效投注額 | = 遊戲商「有效数额」 |

---

## merchant API

### 會員管理

#### create

建立會員。

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | string | ✅ | `create` |
| `username` | string | ✅ | 玩家帳號（長度 1–64，格式 A-Za-z0-9） |

```jsonc
// Success
{"errcode":0,"errtext":"","result":"test10"}
// Error — 重複
{"errcode":1,"errtext":"Username exist","result":""}
```

---

#### update

更新會員的最大注額、限額與佣金設定。

| 參數 | 說明 |
|---|---|
| `max1`–`max7` | Max Bet：HDP/OU/OE、Single Parlay、Correct Score、Step、1D、2D、3D |
| `lim1`–`lim4` | Per Match HDP/OU/OE、Mix Parlay Limit、Per Match Correct Score、Step Limit |
| `comtype` | 佣金類型，選 `A,B,C,D,E,F,G,4,V` |
| `com1`–`com9` | 各類佣金（HDP/OU/OE、1X2/Outright、PAR/CS/TG、Step 各區段） |
| `suspend` | `0` 不停用 / `1` 停用 |

成功 `{"errcode":0,...}`；超限回 errcode `1`「EXCEED」。

---

#### update2

`update` 的擴充版，加入 P3D 與 Myanmar Parlay 相關欄位：`max1`–`max11`、`lim1`–`lim6`、`comtype`、`com1`–`com15`、`suspend`。

| 參數 | 說明 |
|---|---|
| `max8`–`max11` | Max Bet P3D BSOE&AT/S、P3D 3D、P3D 1D、P3D 2D |
| `lim5`–`lim6` | Per Match P3D BSOE&AT/S、Per Match P3D 3D |
| `com10`–`com13` | Commission for P3D（BSOE&AT/S、3D、1D、2D） |
| `com14`–`com15` | Commission for Myanmar Parlay（2 match / 3-11 match） |

---

#### balance

查詢會員餘額。

| 參數 | 必填 | 說明 |
|---|---|---|
| `action` | ✅ | `balance` |
| `username` | ✅ | 玩家帳號 |

```jsonc
// Success — result 為餘額字串
{"errcode":0,"errtext":"","result":"100077.28"}
// Error
{"errcode":1,"errtext":"User not exist","result":""}
```

---

#### deposit

存入指定金額至會員（本地 → M8bet）。

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | string | ✅ | `deposit` |
| `username` | string | ✅ | 玩家帳號 |
| `serial` | string | ✅ | Reference number，**須唯一** |
| `amount` | double | ✅ | 金額 |

```jsonc
// Success — result 為轉帳後餘額
{"errcode":0,"errtext":"","result":100527.28}
// Error — serial 重號
{"errcode":2,"errtext":"Serial exist","result":""}
```

---

#### withdraw

從會員領出指定金額（M8bet → 本地）。

參數同 `deposit`（`action=withdraw`，`serial` 須唯一，`amount`）。

```jsonc
// Success — result 為轉帳後餘額
{"errcode":0,"errtext":"","result":100477.28}
// Error
{"errcode":2,"errtext":"Serial exist","result":""}
```

---

#### login

登入會員並取得遊戲 URL。**URL 60 秒後過期。**

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `action` | string | ✅ | `login` |
| `username` | string | ✅ | 玩家帳號 |
| `lang` | string | ✅ | 語言（見附錄 A） |
| `accType` | string | ✅ | Odds type：`MY, HK, ID, EU`（見附錄 B） |
| `timezoneid` | string | ❌ | 時區代碼（見附錄 C，`29`=GMT+8） |
| `ref` | string | ❌ | 行動版主域名（For mobile use） |

```jsonc
// Success
{"errcode":0,"errtext":"","result":{"login":{
  "weburl":"https://....example.invalid/public/auth.aspx?us=...&k=...",
  "weburlsecure":"https://....example.invalid/public/auth.aspx?...",
  "mobiurl":"https://....example.invalid/public/auth.aspx?...",
  "mobiurlsecure":"https://....example.invalid/public/auth.aspx?..."
}}}
// Error
{"errcode":-2,"errtext":"Invalid secret","result":""}
```

> URL 60 秒過期；另有 `weburlsecure`/`mobiurlsecure`（HTTPS 版），本專案目前取 `weburl`/`mobiurl`。

---

#### logout

登出會員。

| 參數 | 必填 | 說明 |
|---|---|---|
| `action` | ✅ | `logout` |
| `username` | ✅ | 玩家帳號 |

成功 `{"errcode":0,...}`；secret 錯回 `-2`。

---

### 查詢

#### check_payment

查詢某筆轉帳（以 serial）。

| 參數 | 必填 | 說明 |
|---|---|---|
| `action` | ✅ | `check_payment` |
| `username` | ❌ | 玩家帳號；空字串=查全部 |
| `serial` | ✅ | Reference number |

```jsonc
// Success
{"errcode":0,"errtext":"","result":{"payment":[{"username":"hattest01","amount":100}]}}
// Error — 查無
{"errcode":1,"errtext":"No result","result":""}
```

> 查無（errcode=1「No result」）不代表轉帳失敗，見 `integration.md` 高風險陷阱#1。

---

#### check_online

查詢在線會員。

| 參數 | 說明 |
|---|---|
| `action` | `check_online` |
| `username` | 空字串=查全部在線 |

`result` 回逗號分隔的在線 username 清單；無人在線 `result` 為空字串（errcode 仍 `0`）。

---

#### check_ventran（One Wallet）

文檔標註「For One Wallet use」。轉帳錢包模式**不適用**。

---

#### Check_fail_bet（One Wallet）

文檔標註「For One Wallet use」（v2025-07-22 新增）。轉帳錢包模式**不適用**。

---

### 注單抓取：fetch / fetch_result / mark_fetched

> 數據保留 **30 天**，標記後不可再抓；前台 180 天。

#### fetch

抓取**所有未標記**注單（最多 100 筆／次）。

| 參數 | 必填 | 說明 |
|---|---|---|
| `action` | ✅ | `fetch` |
| `lang` | ❌ | 語言，預設 `EN-US` |

`result.ticket` 回注單陣列，欄位見附錄 D。每筆含 `fid`（Fetch Id，用於 `mark_fetched`）。

#### fetch_result

抓取**已有結果（已結算）**的注單（最多 100 筆）。參數與回傳同 `fetch`。

#### mark_fetched

標記注單為已抓取（標記後**永不可再抓**）。

| 參數 | 必填 | 說明 |
|---|---|---|
| `action` | ✅ | `mark_fetched` |
| `fetch_ids` | ✅ | fid 清單，逗號分隔（如 `1234568598,1234568599`） |

成功 `{"errcode":0,...}`；無效 fid 回 errcode `-100`「Invalid fetch_ids : xxx」。

#### 其餘 fetch 系列

| action | 說明（原文章節） |
|---|---|
| `fetch2`（3.21） | fetch 進階版 |
| `fetch_result2`（3.22） | fetch_result 進階版 |
| `fetch_group`（3.23） | 群組（parlay grouping）注單抓取 |
| `mark_fetched_group`（3.24） | 標記群組注單已抓取 |

> `fetch`/`fetch_result`/`fetch2`/`fetch_result2` 自 v2025-03-20 起注單含 `result4d`（1D/2D/3D 結果）。

---

### 票券明細（parlay / step / mm_parlay）

| action | 說明 | 子票欄位 |
|---|---|---|
| `parlay`（3.11） | 依 `ticket_id` 列出 Parlay（PAR）子票明細 | 見附錄 D「Parlay (PAR)」 |
| `step`（3.12） | Step（PAS）子票明細 | 原文 4.1.3 |
| `mm_parlay`（3.13） | MM Parlay（PAT）子票明細（v2024-04-04 新增） | 原文 4.1.4 |

請求參數：`action`、`secret`、`agent`、`ticket_id`、`lang`（optional，預設 `EN-US`）。

---

### 體育資料與設定 action

以下為體育賽事主檔與設定查詢，本專案目前未接入：

| action | 章節 | 用途 |
|---|---|---|
| `team` | 3.14 | 隊伍主檔 |
| `league` | 3.15 | 聯盟主檔 |
| `sportstype` | 3.16 | 運動類別 |
| `match_count` | 3.17 | 賽事數量 |
| `group` | 3.25 | 群組資訊 |
| `update_comtype` | 3.29 | 更新佣金類型（v2025-06-17 新增） |

---

### API 投注（bet_hdp / bet_ou）（One Wallet）

文檔標註「For API Betting use」（3.27 `bet_hdp`、3.28 `bet_ou`，v2022-03-10 新增）。供 API 直接下注（讓分盤 / 大小盤），失敗透過 errcode `-14` 回傳 Bet Error。本專案為轉帳錢包，玩家於 M8bet 前台投注，**不使用**。

---

## 驗證

M8bet **無加密簽名機制**，採兩道驗證：

| 機制 | 說明 | 失敗 errcode |
|---|---|---|
| `secret`（agent secret key） | 每個請求 query string 必帶，由 M8bet 配發 | `-2` Invalid secret |
| IP 白名單 | 來源 IP 必須先向 M8bet 報備加白 | `-3` Ip address not allow |

`secret` 為明文 query 參數傳輸的共享密鑰，機密性完全依賴 HTTPS＋IP 白名單。出口 IP 變更時需向 M8bet 報備，否則回 `-3`。

---

## 錯誤碼

### 一般錯誤（負數）

| ErrCode | ErrText / 說明 |
|---|---|
| `-1` | System maintenance（系統維護） |
| `-2` | Invalid secret（secret 錯誤） |
| `-3` | Ip address not allow（IP 未在白名單） |
| `-4` | Invalid username（長度 1–64、格式 A-Za-z0-9） |
| `-5` | Invalid action |
| `-7` | Invalid agent / Invalid agent CompType |
| `-8` | Invalid username CompType |
| `-11` / `-12` / `-13` | Too many attempts, try again later（請求過於頻繁，分三級） |
| `-14` | Bet error（注單錯誤，細節見 errtext，僅 API 投注 action；對照見下） |
| `-100` | Invalid input |
| `-101` | Exception error |

### 動作專屬錯誤（正數）

errtext 直接描述原因，常見如：`Username exist`（create）、`User not exist`（balance）、`Serial exist`（deposit/withdraw 重號）、`No result`（check_payment 查無）、`EXCEED`（update 超限）。

### Bet Error（errcode `-14`）ErrText 對照

| ErrText | 說明 |
|---|---|
| `NA` | Odds is not available（賠率不可用） |
| `CHG\|xxx` | Odds has changed to xxx（賠率已變更） |
| `MAX\|xxx` | Maximum bet = xxx（超過單注上限） |
| `PAUSE` | Game is suspended, updating odds |
| `NOFUND` | Insufficient fund（餘額不足） |
| `EXCEED` | Transaction limit exceeded |
| `SUSPEND` | Account is locked or suspended |
| `MAINT.` | System under maintenance |
| `SERIAL_EXT` | Serial exists（serial 重號） |

---

## 附錄

### 附錄 A：語言（lang）

`login` 等 action 的 `lang` 支援：

`EN-US`、`ZH-CN`、`ZH-TW`、`KO-KR`、`TH-TH`、`VI-VN`、`ES-PA`、`ID-ID`

（parlay 等 action 另支援 `JA-JP`，預設 `EN-US`。）

### 附錄 B：Odds Type（accType）

`MY`（馬來盤）、`HK`（香港盤）、`ID`（印尼盤）、`EU`（歐洲盤）。

### 附錄 C：時區（timezoneid）

常用代碼（完整 1–40 見原文 4.3）：

| Id | 時區 |
|---|---|
| `28` | (GMT +7:00) Bangkok, Hanoi, Jakarta |
| `29` | (GMT +8:00) Beijing, Hong Kong, Kuala Lumpur, Singapore |
| `31` | (GMT +9:00) Tokyo, Seoul, Osaka |
| `16` | (GMT) Western Europe Time, London |

M8bet 營運時區為 UTC+8，對應 `29`。

### 附錄 D：注單欄位（Ticket）

`fetch` / `fetch_result` 回傳的單注（4.1.1）主要欄位：

| 欄位 | 說明 |
|---|---|
| `fid` | Fetch Id（非 fetch action 時為空） |
| `id` | Ticket Id |
| `t` | 最後修改時間（至毫秒） |
| `u` | Username |
| `b` | Bet Amount（下注額） |
| `w` | Win Amount（派彩額） |
| `a` | Commission Amount = `CommissionAmt * 100`，`CommissionAmt = BetAmt * ABS(WinRate)/100` |
| `c` | Commission %（0.4 表示 0.4%） |
| `ip` | 投注 IP |
| `league` / `home` / `away` | League Id / Home Id / Away Id |
| `status` | `N` Auto-Accept、`A` Accepted、`R` Reject、`C` Cancel、`RG` RejectGoal、`RP` RejectPenalty、`RR` RejectRedCard、`RA` RejectAbnormalBet |
| `game` | 玩法：HDP,1X2,OU,OE,CS,CSR,TG,FLG,HFT,DC,PAR,ORT,PAS,PAM,1D,2D,3D,1DT,2DT,HDQ,H1Q,H2Q,PAT,MMH,MMO |
| `odds` | 賠率 |
| `side` | `1` Home/Over/Odd、`2` Away/Under/Even、`X` Draw |
| `info` | 讓分 / 大小 / 正確比分 / 總進球 等 |
| `half` | `0` Full Time、`1` First Half |
| `trandate` | 交易日期 |
| `workdate` | 工作日期 |
| `matchdate` | 賽事日期 |
| `runscore` | 即時比分（非走地為空） |
| `score` | 比分（未完賽為空） |
| `htscore` | 半場比分（非完賽或非 HFT 為空） |
| `flg` | 首/末球結果（非 FLG 或未完賽為空） |
| `res` | 結果：`P` NotMatchOver、`WA` WinAll、`LA` LostAll、`WH` WinHalf、`LH` LostHalf、`D` Draw |
| `sportstype` | 運動類別 Id |
| `oddstype` | `MY, HK, ID, EU` |
| `ventransid` | VenTransId（One Wallet 用，作為 BetId） |
| `matchdatetime` | 賽事日期時間 |
| `curcode` | 貨幣代碼（如 `MYR`） |
| `leaguename` / `homename` / `awayname` | 聯盟 / 主隊 / 客隊名稱 |
| `groupid` | Group Id（非 parlay grouping 為空） |
| `combinfo` | Parlay 組合資訊（非 PAM/PAR 為空） |
| `webtype` | `Desktop`, `Mobile`, `API` |
| `transserial` | TransSerial（API Betting 用，bet reference number） |
| `matchoverdate` | 完賽日期；未完賽為 `1900-01-01 00:00:00` |
| `result4d` | 1D/2D/3D 結果（非 1D/2D/3D 為空，v2025-03-20 新增） |

玩法（game）與 side/info 對照重點：

| Type | 說明 |
|---|---|
| HDP（Handicap） | side：1=Home,2=Away；info：讓分（負=主讓，如 +2.5, -0.75） |
| 1X2 | side：1=Home,2=Away,X=Draw |
| OU（Over/Under） | side：1=Over,2=Under；info：總進球 |
| OE（Odd/Even） | side：1=Odd,2=Even |
| CS/CSR（Correct Score） | info：比分（如 2-1, 0-0） |
| TG（Total Goal） | info：總進球 |
| FLG（First/Last Goal） | info：NG/HLG/ALG/HFG/AFG；flg：首末球結果 |
| HFT（Half/Full Time） | info：DD,DH,DA,HD,HH,HA,AD,AH,AA（D=Draw,H=Home,A=Away）；htscore |
| PAR / PAS / PAT | 子票明細（見 parlay/step/mm_parlay action） |
| ORT（Outright） | league=下注描述（如 NBA Champion），home=下注選擇（如 LA Lakers） |
| Double Chance | info：X2,1X,12（X=Draw,1=Home,2=Away） |

### 附錄 E：Game Id 對照

| GameId | 名稱 |
|---|---|
| 2 | Soccer |
| 7 | Basketball |
| 13 | US Football |
| 14 | Tennis |
| 16 | Baseball |
| 102 / 103 | E-Sport / E-Sport Outright |
| 104 / 105 | Cricket / Cricket Outright |
| 106 / 107 | Boxing / Boxing Outright |
| 3 / 51 / 52 / 53 | 4D Game / 1D / 2D / 3D Game |
| 31 | Magnum |
| 30 | Singapore Lotto |
| 26 | HK Lotto |
| 108–111 | Huay Thai / 1D / 2D / 3D |
| 128–133 | Laos Lotto 1D-3D / Hanoi Lotto 1D-3D |
| 134/135/158/159 | Perdana3D BSOE&AT/S / 3D / 1D / 2D |
