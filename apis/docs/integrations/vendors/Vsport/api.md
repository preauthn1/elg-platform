# Vsport（VSport / Sportsbook API）— API 規格

> 原始來源：
> - `SportsAPI_IGK_v20231124.pdf`（**主要事實來源**，61 頁，版本 2023-11-24，IGK Sportsbook API）
> - `APIU VSport (Live).pdf`、`APIU VSport (UAT).pdf`（Live / UAT 連結與測試憑證）
> - `SportsAPI_IGK_v20220407.pdf`（舊版，僅作參考）
> - `info.txt`（API 帳號 / backend 連結，含敏感憑證）
>
> 抓取日期：2026-06-03
> Vendor Id：**53**（`VendorIdEnum::VSPORT`），錢包型態：**轉帳錢包 Transfer Wallet**（`AbstractTransferWallet`）

Vsport 是一套**體育博彩（Sportsbook）**遊戲商，玩家餘額由 Vsport 端保管，代理進場前須先 `deposit` 轉入、離場時 `withdraw` 領回。注單以遞增拉取（`fetch`/`mark_fetched`）或時間範圍查詢（`ticket`/`fetch_summary`）方式對帳。

---

## 通用約定

### 請求格式

| 項目 | 值 |
|---|---|
| Endpoint（XML） | `https://<host>/api.aspx` |
| Endpoint（JSON） | `https://<host>/apijs.aspx` |
| 傳輸方式 | 所有參數以 **query string** 帶入，本專案 Driver 以 **GET** 送出 |
| 共用必填 | `secret`（agent secret key）、`action`、`agent`；多數 action 另含 `username` |
| 認證 | `secret` + IP 白名單（**無請求簽章**），見[認證](#認證) |

### 回應格式

```json
{ "errcode": 0, "errtext": "", "result": "<mixed>" }
```

| 欄位 | 說明 |
|---|---|
| `errcode` | `0`=成功；**負數**=通用錯誤；**正數**=action 專屬錯誤 |
| `errtext` | 錯誤文字（成功時為空） |
| `result` | 成功時的回傳資料（字串 / 物件 / 陣列；失敗時通常為空字串） |

> XML 版回應結構為 `<response><errcode/><errtext/><result/></response>`，欄位語意相同。

### Base URL

| 環境 | API URL | 下注站 host |
|---|---|---|
| Live | `https://example.invalid/api.aspx` | `example.invalid` |
| UAT | `https://example.invalid/api.aspx` | `example.invalid` |

> 上述為 `info.txt` / Live / UAT PDF 提供的本專案實際對接 URL；doc 範例使用的 `example.invalid` 僅為文件示意 host。實際值由 `vendor_infos.info.API_URL` / `HOST` 帶入。

---

## Merchant API

### Player / Member

#### 1. `create`

建立會員（開戶）。

| Parameter | Type | 必填 | 說明 |
|---|---|---|---|
| `action` | string | ✅ | `create` |
| `secret` | string | ✅ | agent secret key |
| `agent` | string | ✅ | agent name |
| `username` | string | ✅ | 玩家帳號（length 1-16，格式 `A-Za-z0-9`） |

**Success**：`{"errcode":0,"errtext":"","result":"test10"}`（`result` 為建立的 username）
**Error**：`{"errcode":1,"errtext":"Username exist","result":""}`

---

#### 2. `update`

更新會員的最大下注、單場限額與佣金設定。

| Parameter | Type | 必填 | 說明 |
|---|---|---|---|
| `max1`~`max5` | double | ✅ | 各盤口類型最大下注（HDP/OU/OE、1X2/Outright、Mix Parlay、CS/TG/HFT/FLG、Other Sports HDP/OU/OE） |
| `lim1`~`lim5` | double | ✅ | 各盤口類型單場限額 |
| `comtype` | string | ✅ | HDP/OU/OE 佣金等級，`3,A,B,C,D,E,F,G,H,I,J` 擇一 |
| `com1`~`com4` | double | ✅ | 各類佣金（HDP/OU/OE、1X2/Outright、Mix Parlay、Others） |
| `suspend` | boolean | ✅ | `0` 不停權、`1` 停權 |
| `tzid` | int | 否 | TimeZoneId（v20231116 新增，Example `29`） |

**Success**：`{"errcode":0,"errtext":"","result":""}`
**Error**：`{"errcode":1,"errtext":"EXCEED","result":""}`

---

#### 3. `balance`

查詢會員餘額（本專案 `getBalance()`）。

| Parameter | Type | 必填 | 說明 |
|---|---|---|---|
| `action` / `secret` / `agent` / `username` | string | ✅ | 同上 |

**Success**：`{"errcode":0,"errtext":"","result":"2029.77"}`（`result` 為餘額字串）
**Error**：`{"errcode":1,"errtext":"User not exist","result":""}`

---

#### 4. `balance_ap`

查詢會員的可用餘額（available）與凍結中餘額（pending，下注未結算）。v20231116 新增。

**Success**：`{"errcode":0,"errtext":"","result":{"balance":{"available":2029.77,"pending":5}}}`
**Error**：`{"errcode":1,"errtext":"User not exist","result":""}`

---

### Player Wallet（轉帳錢包）

> 轉帳錢包模式下，玩家進場前須先 `deposit` 把本地錢包餘額轉入 Vsport；離場時以 `withdraw` 領回。`serial` 為交易流水號，**必須唯一**。

#### 5. `deposit`

本地 → Vsport（本專案 `transferToVendor()`）。

| Parameter | Type | 必填 | 說明 |
|---|---|---|---|
| `action` | string | ✅ | `deposit` |
| `secret` / `agent` / `username` | string | ✅ | 同上 |
| `serial` | string | ✅ | 交易流水號（**必須唯一**），對應 `VendorTransferData->transactionNo` |
| `amount` | double | ✅ | 轉入金額，對應 `vendorAmount` |

**Success**：`{"errcode":0,"errtext":"","result":"2129.77"}`（`result` 為轉帳後餘額）
**Error**：`{"errcode":2,"errtext":"Serial exist","result":""}`（serial 重複）

---

#### 6. `withdraw`

Vsport → 本地（本專案 `transferToLocal()`）。參數同 `deposit`，僅 `action=withdraw`。

**Success**：`{"errcode":0,"errtext":"","result":"2079.77"}`
**Error**：`{"errcode":2,"errtext":"Serial exist","result":""}`

> **轉帳結果判讀**：
> - `errcode=0` → `VendorTransferResultEnum::SUCCESS`
> - `errcode=2`（Serial exist）→ `VendorTransferResultEnum::FAILED`
> - 連線逾時 / 回應非陣列 / 缺 `errcode` 或 `result` / 其他 errcode → `VendorTransferResultEnum::TIMEOUT`

---

### Game / Launch

#### 7. `login` / `login_new`

取得登入 URL（**60 秒過期**）。`login` 回傳結構化 `<login>` 物件（host + param）；`login_new` 直接回傳單行 URL。

| Parameter | Type | 必填 | 說明 |
|---|---|---|---|
| `action` | string | ✅ | `login` / `login_new` |
| `secret` / `agent` / `username` | string | ✅ | 同上 |
| `lang` | string | ✅ | 語言碼（見[附錄：語言代碼](#附錄語言代碼)） |
| `accType` | string | ✅ | 賠率類型 `MY / HK / ID / EU` |
| `host` | string | ✅ | 下注站主網域（doc 範例 `example.invalid`） |

**login_new Success（JSON）**：
```json
{"errcode":0,"errtext":"","result":"https://example.invalid/public/validate.aspx?us=ap@test01&k=F750F8A1478F4D10BA32FBF91D0205E8&lang=EN-US&accType=HK&r=1453629944"}
```

---

#### 8. `login_mobile`

取得**行動版**登入 URL（單行，60 秒過期）。**本專案 `getGameUrl()` 使用此 action。**

| Parameter | Type | 必填 | 說明 |
|---|---|---|---|
| `action` | string | ✅ | `login_mobile` |
| `secret` / `agent` / `username` | string | ✅ | 同上 |
| `lang` | string | ✅ | 語言碼（見[附錄：語言代碼](#附錄語言代碼)） |
| `accType` | string | ✅ | 賠率類型 `MY / HK / ID / EU` |
| `host` | string | ✅ | 下注站主網域（doc 文字稱 `bettingHost`，**參數名實為 `host`**，見 `integration.md` 陷阱 §1） |
| `returnUrl` | string | 否 | 返回 URL（v20220407 新增） |
| `theme` | string | 否 | 主題名稱（v20220407 新增） |
| `ct` | string | 否 | CT 值（v20220621 新增） |
| `cus` | string | 否 | CUS 值（v20231124 新增） |
| `ctoken` | string | 否 | CTOKEN 值（v20231124 新增） |

**Success（JSON）**：`{"errcode":0,"errtext":"","result":"https://example.invalid/public/validate.aspx?us=ap@test01&k=...&mb=1&returnUrl=...&theme=blue&ct=vs&r=..."}`

本專案直接把 `result` 字串作為 PC 與 mobile 進場 URL（見 `integration.md` 陷阱 §4）。

---

#### 9. `login_newdesktop`

取得**新版桌面**登入 URL（單行，60 秒過期，v20230116 新增）。參數同 `login_mobile`（不含 `cus`/`ctoken`，回傳 URL 含 `mb=2`）。本專案未使用。

---

#### 10. `logout`

登出會員。參數 `action=logout` + `secret`/`agent`/`username`。

**Success**：`{"errcode":0,"errtext":"","result":""}`

---

### 交易查詢

#### 11. `check_payment`

依 `serial` 查交易（**本專案 `checkTransferStatus()`**）。

| Parameter | Type | 必填 | 說明 |
|---|---|---|---|
| `action` | string | ✅ | `check_payment` |
| `secret` / `agent` | string | ✅ | 同上 |
| `username` | string | ✅ | 玩家帳號（傳空字串查全部會員） |
| `serial` | string | ✅ | 交易流水號 |

**Success**：`{"errcode":0,"errtext":"","result":{"payment":[{"username":"ap@test01","amount":100}]}}`
**Error**：`{"errcode":2,"errtext":"No result","result":""}`

> 本專案判讀：`errcode=0` 且 `result.payment` 恰有 1 筆、且該筆 `username` 與當前玩家一致 → `SUCCESS`；查得 payment 但不符 → `FAILED`；其他 → `TIMEOUT`。

---

#### 12. `check_online`

查在線會員。`username` 傳空字串查全部。

**Success**：`{"errcode":0,"errtext":"","result":"tees,test,test01,test02,test04,test05"}`（逗號分隔 username）
**無在線**：`result` 為空字串。

---

### 注單對帳：`ticket` / `fetch` / `parlay`

> Vsport 為體育博彩，注單依 **last modified time** 變動（取消 / 更改會更新時間）。對帳有兩種拉法：時間範圍查詢（`ticket`）與遞增式拉取（`fetch` + `mark_fetched`）。

#### `ticket`（依最後修改時間查）

| Parameter | Type | 說明 |
|---|---|---|
| `action` | string | `ticket` |
| `secret` / `agent` | string | 同上 |
| `username` | string | 空=查全部會員 |
| `start` | string | 起始時間 `2014-11-20 15:03:00` |
| `duration` | string | 秒數，例 `60`（條件 `t >= start && t < start+duration`） |
| `match_over` | string | `0` / `1`（是否已賽果） |

- 一次最多回傳 **100 筆**；若回傳滿 100 筆代表該時間區間可能還有未取得的注單。
- 注單欄位定義見[附錄：Ticket 欄位](#附錄ticket-欄位)。

#### `fetch` / `mark_fetched` / `refetch`（遞增拉取）

| Action | 用途 | 額外參數 |
|---|---|---|
| `fetch` | 拉取所有待取注單（最多 100 筆），每筆帶 `fid`（fetch id） | 無 |
| `mark_fetched` | 標記已處理（刪除佇列），避免重複拉取 | `fetch_ids`（逗號分隔，例 `123,124,125`） |
| `refetch` | 依時間區間重新拉取 | `date_from`、`date_to`（例 `2021-07-01 00:00:00`） |

- `fetch` 成功回傳含 `fid`（非 0）；`ticket` action 回傳的 `fid` 為空。
- `mark_fetched` 錯誤：`{"errcode":-100,"errtext":"Invalid fetch_ids : 12999999999","result":""}`
- 標準對帳流程：`fetch` → 處理注單 → `mark_fetched(fid 清單)`；遺漏時以 `refetch(date range)` 補抓。

#### `parlay`（串關明細）

依 `ticket_id` 列出串關子注單明細。

| Parameter | Type | 說明 |
|---|---|---|
| `action` | string | `parlay` |
| `secret` / `agent` | string | 同上 |
| `ticket_id` | string | 串關母單 id，例 `PAR753983515` |

回傳 `result.ticket` 含 `parent`（母單 id）、`id`、`u`、`league`、`home`、`away`、`status`、`game`、`odds`、`side`、`info`、`half`、`matchdate`、`runscore`、`score`、`res` 等欄位（見[附錄：Parlay (PAR) 欄位](#附錄parlay-par-欄位)）。

---

#### `fetch_summary` / `fetch_summary_wd`

注單彙總（每位玩家一筆統計），兩者差別在過濾基準：

| Action | 過濾基準 | date 格式 |
|---|---|---|
| `fetch_summary` | transaction date（交易日） | `2021-07-01 13:30:00` |
| `fetch_summary_wd` | working date（後台對帳日） | `2021-07-01` |

| Parameter | Type | 必填 | 說明 |
|---|---|---|---|
| `action` | string | ✅ | `fetch_summary` / `fetch_summary_wd` |
| `secret` / `agent` | string | ✅ | 同上 |
| `date_from` / `date_to` | string | ✅ | 起 / 迄 |
| `match_over` | string | ✅ | `1` / `0` |

**Success**：
```json
{"errcode":0,"errtext":"","result":{"summary":[
  {"username":"a93datest1","amt":2960,"winamt":-140.9,"commissionamt":20.9,"count":24},
  {"username":"a93datest127","amt":7310,"winamt":-4459.3,"commissionamt":63.3,"count":57}
]}}
```

| 欄位 | 說明 |
|---|---|
| `amt` | 下注總額（turnover） |
| `winamt` | 輸贏金額（玩家視角，負數=玩家輸） |
| `commissionamt` | 佣金總額 |
| `count` | 注單筆數 |

---

## 認證

Vsport Sportsbook API **沒有 HMAC / MD5 之類的請求簽章機制**。所有 API 一律以 query string 帶 `secret`（Agent secret key）作為認證憑證，並以 **IP 白名單**作為第二道防線。

| 項目 | 值 |
|---|---|
| 認證憑證 | `secret`（每個 agent 一組 secret key，寫入 `vendor_infos.info.SECRET`，日誌記錄前須遮罩） |
| 第二防線 | IP 白名單（白名單外回 `errcode=-3 Ip address not allow`） |
| 請求簽章 | 無 |
| 傳輸 | HTTPS |

### 登入 URL 的二次驗證金鑰（`k` / `r`）

`login_mobile` 等登入類 action 回傳的進場 URL 內含 `k`（驗證金鑰）與 `r`（亂數）：

```
https://example.invalid/public/validate.aspx?us=ap@test01&k=F750F8A1478F4D10BA32FBF91D0205E8&lang=EN-US&accType=HK&r=1453629944
```

| 參數 | 說明 |
|---|---|
| `us` | `<agent>@<username>` 組合 |
| `k` | Vsport 端產生的登入驗證金鑰（由 Vsport 驗證，代理端不需自行計算） |
| `r` | 亂數，防重放 |

> 代理端只需把整段 URL 原封不動回傳給前端。登入 URL **60 秒後過期**。

---

## 錯誤碼

### 通用錯誤（負數）

| ErrCode | ErrText | 說明 |
|---|---|---|
| `0` | — | 成功 |
| `-1` | System maintenance | 維護中 |
| `-2` | Invalid secret | secret 錯誤 |
| `-3` | Ip address not allow | IP 未在白名單 |
| `-4` | Invalid username | 帳號格式錯（length 1-16，`A-Za-z0-9`） |
| `-5` | Invalid action | action 無效 |
| `-6` | Referer not allow | Referer 不允許 |
| `-7` | Invalid agent | agent 無效（Invalid agent CompType） |
| `-8` | Invalid username | Invalid username CompType |
| `-9` | Invalid mode | 模式無效 |
| `-10` | Too many attempts, try again later | 請求過於頻繁 |
| `-100` | (Invalid input) | 輸入錯誤（例 `mark_fetched` 的 `Invalid fetch_ids`） |
| `-101` | (Exception error) | 系統例外 |

### Action 專屬錯誤（正數）

> ⚠️ **同一正數 errcode 在不同 action 代表不同含義**，判讀時須結合 `action` + `errtext`，不可只看數字。

| ErrCode | ErrText（範例） | 出現於 |
|---|---|---|
| `1` | `Username exist` / `User not exist` / `EXCEED` / `No result` | create / balance / update / check_payment |
| `2` | `Serial exist`（轉帳流水號重複）/ `No result` | deposit / withdraw / check_payment |

---

## 附錄：Ticket 欄位

| 欄位 | 說明 |
|---|---|
| `fid` | Fetch Id（非 `action=fetch` 時為空） |
| `id` | 注單 Id |
| `t` | 最後修改時間（精確到毫秒） |
| `u` | username |
| `b` | Bet Amount（下注額） |
| `w` | Win Amount（派彩額） |
| `a` | Commission Amount = `commissionAmt * 100` |
| `c` | 佣金 %（值 `0.4` = 0.4%） |
| `ip` | 下注 IP |
| `league` / `home` / `away` | 聯賽 Id / 主隊 Id / 客隊 Id |
| `status` | `N`=Auto-Accept、`D`=Waiting、`A`=Accepted、`R`=Reject、`C`=Cancel、`RG`=RejectGoal、`RP`=RejectPenalty、`RR`=RejectRedCard |
| `game` | 玩法：`HDP,1X2,OU,OE,CS,CSR,TG,FLG,HFT,PAR,ORT` |
| `odds` | 賠率 |
| `side` | `1`=Home/Over/Odd、`2`=Away/Under/Even、`X`=Draw |
| `info` | 讓分 / 大小 / 波膽 / 總進球等（依 game 而定） |
| `half` | `0`=Full Time、`1`=First Half |
| `trandate` / `workdate` / `matchdate` | 交易日 / 對帳日 / 賽事日 |
| `runscore` | 滾球比分（非滾球時空） |
| `score` | 比分（未賽果時空） |
| `htscore` | 半場比分（非賽果且 HFT 時空） |
| `flg` | First/Last goal 結果（非賽果且非 FLG 時空） |
| `res` | `P`=NotMatchOver、`WA`=WinAll、`LA`=LostAll、`WH`=WinHalf、`LH`=LostHalf、`D`=Draw |
| `sportstype` | 運動種類 Id |
| `oddstype` | `MY / HK / ID / EU` |
| `validamt` | 有效投注額（v20220629 新增） |

### game 玩法 → side / info 對照（doc §4.1.3）

| Type | 重點 |
|---|---|
| `HDP` Handicap | `side` 1=Home/2=Away；`info` 讓分（負=主讓，例 +2.5 / -0.75） |
| `1X2` | `side` 1=Home/2=Away/X=Draw |
| `OU` Over/Under | `side` 1=Over/2=Under；`info` 總進球線 |
| `OE` Odd/Even | `side` 1=Odd/2=Even |
| `CS` / `CSR` Correct Score | `info` 波膽，例 2-1、0-0 |
| `TG` Total Goal | `info` 總進球 |
| `FLG` First/Last Goal | `info` NG=No goal / HLG=Home last goal / ALG=Away last goal / HFG=Home first goal / AFG=Away first goal；`flg` 結果 |
| `HFT` Half Time/Full Time | `info` DD/DH/DA/HD/HH/HA/AD/AH/AA（D=Draw,H=Home,A=Away）；`htscore` 半場比分 |
| `PAR` Parlay | 明細在子注單（見 `parlay` action） |
| `ORT` Outright | `league`=投注描述（例 NBA Champion）；`home`=投注選擇（例 LA Lakers） |

---

## 附錄：Parlay (PAR) 欄位

`parent`（母單 id）、`id`、`u`、`league`、`home`、`away`、`status`、`game`、`odds`、`side`、`info`、`half`、`matchdate`、`runscore`、`score`、`res`（含義同 Ticket 欄位表）。

---

## 附錄：語言代碼

`login` 系列 `lang` 支援：`EN-US, ZH-CN, TH-TH, VI-VN, ID-ID, KO-KR, ES-ES, PT-PT, FR-FR, JA-JP, MY-MM, LO-LA`。

> 本專案 `Driver::mapLanguageCode()` 對照：ZH_TW/ZH_CN→`ZH-CN,`、ID_ID→`ID-ID,`、VI_VN→`VI-VN`、TH_TH→`TH-TH`、MY_MM→`MY-MM`、default→`EN-US`。
>
> ⚠️ `ZH-CN,` 與 `ID-ID,` 尾帶逗號，與文件不符，見 `integration.md` 陷阱 §2。
