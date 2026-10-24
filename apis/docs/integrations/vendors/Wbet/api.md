# Wbet（WBET）— 廠商 API 規格

> 原始來源（檔名）：`WBET转账钱包接口对接 - 版本 2.1.pdf`（位於 `遊戲商/102.Wbet/`），輔以同資料夾 `其他資訊.txt`。
> 抓取日期：2026-06-03
> 文件版本：v2.1（修訂管理最後一筆 2024-04-04「更新獲取結果與獲取獎金結果」）
>
> 我方實作分析（整合落地建議、簽名陷阱、實作落差）見同目錄 `integration.md`。

WBET 為**體育博彩（Sports Book）**遊戲商，平台代號 `galaticdragon`。本專案 vid **102** 依 `config/vendor.php` 註冊為**轉帳錢包（Transfer Wallet）**。

> 轉帳錢包模式下 WBET 持有玩家餘額，**不需代理提供 callback endpoint**；下注 / 結算紀錄由代理主動以對帳 API（getresult 等）拉取。

---

## 通用約定

### 請求格式

- **HTTP Method**：所有 API 一律 `POST`
- **Content-Type**：JSON
- **驗證方式**：簽章（`signature`）+ 由 WBET 控管的環境密鑰；URL / 密鑰需簽約後取得
- **時區**：`UTC+08:00`

每個 endpoint 請求結構：

```jsonc
POST <Base URL>/api/<接口名稱>
Content-Type: application/json

{
  "signature": "<md5 簽章，小寫>",
  "operator_id": "<運營商代號>",
  "ukey": "<本次請求隨機字串>",
  ...其餘參數
}
```

### 通用回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | int | `1` = 成功；非 1 為失敗 |
| `statusdesc` | string | 成功 `OK`；失敗為錯誤訊息 |
| `value` | object / array | 部分 API 才有，承載資料；失敗時可能不回傳 |

### 重要規範

- API 數據保留 **150 天**（已標記即無法再抓取）；前台數據亦保留 150 天可查詢。
- 記錄查詢時間條件：**結算時間 = 開獎時間**。
- 結算區間：**前日 12:00 PM 至當日 12:00 PM 算一天**（對帳切日需注意此非自然日）。
- 對帳 API 採「拉取 → 標記」模式，單次最多 300 筆、1 分鐘最多 2 次、間隔 30 秒。

---

## 簽名驗證（Signature）

| 項目 | 值 |
|---|---|
| 雜湊演算法 | `MD5`（輸出 32 字元 hex） |
| 輸出格式 | hex 字串，**加密後需轉成小寫**（文件原文「加密后需转成小写」） |
| 隨機因子 | `ukey`，建議 6~8 個英數字，**每次請求重新產生** |

> `ukey` 與 `secret_key`（API 密鑰）由 WBET 在簽訂商定條款後提供；測試與正式環境的 URL 與密鑰各自不同。

### 公式 A — 帶 `account_id`（玩家相關 API）

```
signature = md5(operator_id + ukey + account_id + ukey + secret_key)
```

適用 API：`createmember`、`get_balance`、`newdeposit`、`newwithdrawal`、`launchsports`、`setbetlimit`

### 公式 B — 不帶 `account_id`（非玩家 / 報表類 API）

```
signature = md5(operator_id + ukey + ukey + secret_key)
```

適用 API：`checktranstatus`、`getbetlist` / `markfetchbetlist`、`getparlaybetlist` / `markfetchparlaybetlist`、`getparlaydetails`、`getresult` / `markfetchresult`、`getbonusresult` / `markfetchbonusresult`

> ⚠️ 公式 B 是把 `ukey` 接續兩次但**沒有** `account_id`，與公式 A 僅差在中間的 `account_id` 區段。實作時不要誤用同一公式。

### 實作對照（app/Vendors/Wbet/Driver.php）

專案目前實作的簽名方法（`generateSignature`）使用公式 A：

```php
private function generateSignature(string $ukey, string $username): string
{
    return md5($this->infoDetail['CLIENT_ID'] . $ukey . $username . $ukey . $this->infoDetail['CLIENT_SECRET']);
}
```

| 文件欄位 | 專案 config 對應 |
|---|---|
| `operator_id` | `vendor_infos.info.CLIENT_ID` |
| `account_id` | 取自 `WbetAccount.username` |
| `secret_key` | `vendor_infos.info.CLIENT_SECRET` |
| `ukey` | 每次請求 `Str::random(8)` 產生 |

> MD5 大小寫：PHP `md5()` 預設即輸出小寫 hex，與文件相符；若改用其他語言或工具請確認輸出已轉小寫。

---

## merchant API（代理 → WBET）

### 帳號與錢包

#### 1. createmember — 創建用戶

建立 WBET 端玩家帳號。成功創建後系統會自動加上後綴（prefix/suffix）。

| 項目 | 值 |
|---|---|
| Endpoint | `/api/createmember` |
| 簽章公式 | A（含 `account_id`） |

##### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `signature` | varchar | ✅ | 簽章（小寫） |
| `account_id` | varchar | ✅ | 玩家 ID，**只允許 4~20 個字元，限英數字** |
| `operator_id` | varchar | ✅ | 運營商代號 |
| `ukey` | varchar | ✅ | 本次請求隨機字串 |
| `full_name` | varchar | ❌ | 玩家姓名 |

##### 回應

```jsonc
{ "status": 1, "statusdesc": "OK" }
```

---

#### 2. getbalance — 獲取餘額

| 項目 | 值 |
|---|---|
| Endpoint | `/api/getbalance` |
| 簽章公式 | A |

##### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `signature` | varchar | ✅ | 簽章 |
| `account_id` | varchar | ✅ | 玩家 ID |
| `operator_id` | varchar | ✅ | 運營商代號 |
| `ukey` | varchar | ✅ | 隨機字串 |

##### 回應

```jsonc
{
  "status": 1,
  "statusdesc": "OK",
  "value": [
    { "available_balance": 100, "frozen_balance": 0, "currency_code": "IDR" }
  ]
}
```

| `value[].` 欄位 | 說明 |
|---|---|
| `available_balance` | 可用於 WBET 投注的餘額 |
| `frozen_balance` | 已下注但結果尚未確定的金額（賽事結算後自動清零並依輸贏反映回 `available_balance`） |
| `currency_code` | 幣別 |

> 餘額皆以 **4 位小數**返回（若有）。專案實作取 `value[0].available_balance` 並 `Common::numFloor($balance, 2)`（2 位小數）。

---

#### 3. newdeposit — 新存款（轉入 WBET）

把代理錢包餘額轉入玩家 WBET 錢包。

| 項目 | 值 |
|---|---|
| Endpoint | `/api/newdeposit` |
| 簽章公式 | A |

##### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `signature` | varchar | ✅ | 簽章 |
| `account_id` | varchar | ✅ | 玩家 ID |
| `operator_id` | varchar | ✅ | 運營商代號 |
| `ukey` | varchar | ✅ | 隨機字串 |
| `amount` | money | ✅ | 轉入金額 |
| `transaction_id` | varchar | ✅ | 交易 ID，**新存款與新取款功能間全局唯一**；只允許 4~20 字元、英數字 |

##### 回應

```jsonc
{ "status": 1, "statusdesc": "OK" }
```

---

#### 4. newwithdrawal — 新取款（轉出 WBET）

把玩家 WBET 錢包餘額轉回代理錢包。

| 項目 | 值 |
|---|---|
| Endpoint | `/api/newwithdrawal` |
| 簽章公式 | A |

##### 請求參數

同 `newdeposit`（`amount`、`transaction_id` 規則一致；`transaction_id` 與存款共用全局唯一鍵命名空間）。

##### 回應

```jsonc
{ "status": 1, "statusdesc": "OK" }
```

> 專案 `transferToVendor` → `newdeposit`、`transferToLocal` → `newwithdrawal`，依回應 `status === '1'` 判斷成功 / 失敗。

---

#### 5. checktranstatus — 查詢交易狀態

依 `transaction_id` 查詢 newdeposit / newwithdrawal 的最終處理結果。**逾時或不確定狀態時務必用此 API 對帳**，避免重複轉帳。

| 項目 | 值 |
|---|---|
| Endpoint | `/api/checktranstatus` |
| 簽章公式 | **B（不含 `account_id`）** |

##### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `signature` | varchar | ✅ | 簽章（公式 B） |
| `operator_id` | varchar | ✅ | 運營商代號 |
| `ukey` | varchar | ✅ | 隨機字串 |
| `transaction_id` | varchar | ✅ | 由 newdeposit / newwithdrawal 傳入的交易 ID |

##### 回應

```jsonc
{ "success": 0, "status": 1, "statusdesc": "OK" }
```

| 欄位 | 說明 |
|---|---|
| `status` | API 呼叫本身是否成功（1 = 成功） |
| `success` | `1` = 該 `transaction_id` 處理成功；`0` = 處理失敗 |

> ⚠️ `status` 與 `success` 是兩個不同維度：`status=1` 只代表查詢本身成功，真正的交易成敗看 `success`。兩者必須分別檢查。

---

### 遊戲

#### 6. launchsports — 開啟遊戲

| 項目 | 值 |
|---|---|
| Endpoint | `/api/launchsports` |
| 簽章公式 | A |

##### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `signature` | varchar | ✅ | 簽章 |
| `account_id` | varchar | ✅ | 玩家 ID |
| `operator_id` | varchar | ✅ | 運營商代號 |
| `ukey` | varchar | ✅ | 隨機字串 |
| `lang` | varchar | ✅ | 語言：`en` / `cn` / `id` / `vi` / `kr` / `th`，傳錯自動 fallback `en` |
| `type` | varchar | ✅ | `1` = 桌面遊戲；`2` = 手機瀏覽，傳錯自動 fallback 桌面 |

##### 回應

```jsonc
{ "url": "https://example.invalid?a=in/6s5XMtd...&lang=kr", "status": 1, "statusdesc": "OK" }
```

> `url` 為玩家進入遊戲的完整網址；專案以 `response['url']` 為 PC / Mobile 共用網址。
>
> 啟動遊戲預設盤口：`MY` 馬來盤、`HK` 香港盤、`ID` 印尼盤、`DEC` 歐洲盤。

---

#### 7. setbetlimit — 設置投注限額

設定指定玩家各運動別的下注上下限。

| 項目 | 值 |
|---|---|
| Endpoint | `/api/setbetlimit` |
| 簽章公式 | A |

##### 請求參數

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `signature` / `account_id` / `operator_id` / `ukey` | — | ✅ | 同上 |
| `{sport}_min_bet` / `{sport}_max_bet` / `{sport}_max_per_match` | int | ✅ | 每個運動別三個欄位 |

運動別（文件列舉）：`soccer`、`basketball`、`tennis`、`baseball`、`parlay`、`esports`、`others`。

> 專案 `prepareBetLimitData()` 另多送了 `cs_*`（電競 CS 類）三欄；文件範例未列 `cs_*`，屬實作擴充，需向 WBET 客服確認是否被接受（見 integration.md「實作落差」）。

##### 規範

- 對**同一玩家**設置投注限額必須間隔 **2 分鐘**。
- 回應 `status`：文件僅列 `1 = 成功`；專案另放行 `3`（沒有變更 / 已調整）與 `-110`（120 秒內只能打一次）兩個非 `1` 的 status 視為可接受——此放行依據未見於本份文件，標為待確認。

##### 回應

```jsonc
{ "status": 1, "statusdesc": "OK" }
```

---

### 對帳 / 報表（拉取 + 標記模式）

> 這組 API 採「**拉取 → 處理 → 標記**」模式，專案目前**尚未實作**（Driver 無對應方法）。

共同規範：

- 單次請求最多回 **300 筆**。
- **1 分鐘內最多呼叫 2 次，每次間隔 30 秒**。
- 處理完當前 300 筆後須呼叫對應 `markfetch...` 標記，否則下次拉取會回相同 300 筆；標記後不再重複返回。
- **務必確認資料已成功入庫後才呼叫 markfetch**，避免標記後遺失。
- 簽章皆用**公式 B**（不含 `account_id`）。

---

#### 8. getbetlist / markfetchbetlist — 投注列表

回傳**未結算**的單式投注單（玩家投注後、賽事尚無結果）。

| 項目 | 值 |
|---|---|
| 拉取 Endpoint | `/api/getbetlist` |
| 標記 Endpoint | `/api/markfetchbetlist` |

- 投注單有 4 種 `bet_status`：`Accepted`（接受）/ `Rejected`（拒收，被交易員拒絕）/ `Cancel`（取消，賽事取消）/ `Pending`（待定）。
- 對於 `Cancel` 與 `Rejected`，系統會自動將下注金額退回玩家錢包。
- `markfetchbetlist` 請求帶 `bet_list` 陣列，元素為 `{ "a": <bet_id> }`（`a` = getbetlist 回傳的 `bet_id`），單次最多 300 筆。

`value[]` 主要欄位：`bet_id`、`bet_status`、`league_name`、`home_team_name`、`away_team_name`、`bet_type`、`sports_type`、`sports_category`、`game_type_name`、`market_type`、`bet_amount`、`currency`、`rate`、`odds_display`、`bet_hand`、`bet_time`、`match_time`、`working_date`、`odds_type`、`actual_bet_amount`、`member_id`、`int_ref`、`int_ref2`。

---

#### 9. getparlaybetlist / markfetchparlaybetlist — 混合過關投注列表

回傳**未結算**的混合過關（串關）投注單；只含基本資訊，明細需另呼叫 `getparlaydetails`。

| 項目 | 值 |
|---|---|
| 拉取 Endpoint | `/api/getparlaybetlist` |
| 標記 Endpoint | `/api/markfetchparlaybetlist` |

- `markfetchparlaybetlist` 帶 `bet_list_parlay` 陣列，元素 `{ "a": <bet_id> }`。
- `value[]` 主要欄位：`bet_id`、`bet_status`、`bet_time`、`member_id`、`bet_amount`、`odds_display`、`currency`、`rate`、`total_match`（串關場數）、`result_status`、`int_ref`。

---

#### 10. getparlaydetails — 混合過關詳情

依單一 `bet_id` 查混合過關投注中**每場賽事**的明細。

| 項目 | 值 |
|---|---|
| Endpoint | `/api/getparlaydetails` |

##### 請求參數（額外）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `bet_id` | Int64 | ✅ | 可從 `getparlaybetlist` 取得；或 `result_id` 從 `getresult` 取得 |

`value[]` 每場明細欄位：`bet_id`、`league_name`、`home_team_name`、`away_team_name`、`bet_type`、`sports_type`、`game_type_name`、`market_type`、`odds_display`、`bet_hand`、`created_on`、`match_time`。

---

#### 11. getresult / markfetchresult — 結果

回傳所有**已完結且已結算**的投注單（單式）。

| 項目 | 值 |
|---|---|
| 拉取 Endpoint | `/api/getresult` |
| 標記 Endpoint | `/api/markfetchresult` |

- `result_status` 有 2 狀態：`END`（完結，已輸入成績，輸贏已過帳玩家錢包）/ `REOPEN`（重開）。
- **REOPEN 處理流程**：依 `result_id` 從你的 DB 刪除該結果；公司重新結算後會生成另一筆相同 `result_id` 的 `END` 記錄供再次拉取；存好後呼叫 `markfetchresult` 標記。`result_status` 可能 END→REOPEN→再回 END。
- **END 處理流程**：依 `result_id` 查本地是否已存；沒有則存後標記；已有則表注單資料有更新，須依 `result_id` 更新整筆（尤其最新的 `winlose` 與 `payout`），再標記。
- `markfetchresult` 帶 `result_list` 陣列，元素 `{ "a": <result_id> }`。

`value[]` 主要欄位：`result_id`、`result_status`、`member_id`、`league_name`、`sports_type`、`sports_category`、`game_type_name`、`bet_amount`、`currency`、`rate`、`bet_type`、`bet_hand`、`home_team_name`、`away_team_name`、`payout`、`winlose`、`home_running_score`、`away_running_score`、`working_date`、`bet_time`、`result_time`、`home_score`、`away_score`、`match_time`、`odds_type`、`odds_display`、`odds_display_bet`、`ip`、`wl_status`、`int_ref`、`int_ref2`、`valid_bet_amount`。

---

#### 12. getbonusresult / markfetchbonusresult — 獎金結果

回傳所有**已完結且已結算**的「獎金（房間玩法）」投注單。

| 項目 | 值 |
|---|---|
| 拉取 Endpoint | `/api/getbonusresult` |
| 標記 Endpoint | `/api/markfetchbonusresult` |

- `room_type`（房間類型）：`PUBLIC`（公開）/ `PRIVATE`（私人）/ `PUBLIC FREE`（公開免費）。
- `result_type`（結果類型）：`CREATEROOM`（創建房間）/ `JOINROOM`（加入房間）/ `RESULT`（結果）。
- `result_status`（結果狀態）：`END`（完結）/ `REOPEN`（重開）/ `REFUND`（退款）。
  - `END` + `CREATEROOM/JOINROOM`：玩家錢包已依 `bet_amount` 扣除。
  - `REFUND` + `CREATEROOM/JOINROOM`：房間已關閉，退款依 `bet_amount` 退回玩家。
  - `REOPEN` + `RESULT`：已完結賽事重開，先前付給玩家的 `payout` 會自動從錢包扣除。
- REOPEN/END 處理流程同 `getresult`（依 `result_id` 判斷新增 / 更新後標記）。
- `result_id` 格式為 `<數字>_<result_type>`（例 `2138_CREATEROOM`）。
- `markfetchbonusresult` 帶 `bonus_list` 陣列，元素 `{ "a": "<result_id>" }`。

`value[]` 主要欄位：`result_id`、`result_status`、`member_id`、`bet_amount`、`payout`、`result_on`、`position`（出結果後玩家排名）、`room_type`、`winlose`、`result_type`、`room_id`。

---

## 錯誤碼

| Error Code | 說明 |
|---|---|
| `2` | 無資料 |
| `100` | 帳號 ID 不能為空 |
| `200` | 帳號不存在 |
| `201` | 主級不存在 |
| `202` | 密鑰不存在 |
| `203` | 預設設定不存在 |
| `300` | 帳號已存在 |
| `301` | 位置設定已存在 |
| `303` | 投注限額設定已存在 |
| `400` | 無效的帳號 |
| `403` | 不允許任何權限 |
| `404` | 餘額不足 |
| `407` | 時域已過期 |

> 這些是 `statusdesc` / status 失敗時可能出現的錯誤碼語意；文件未明確說明每個碼以哪個欄位回傳。

---

## 列舉值附錄

### odds_type（盤口類型）

| 值 | 說明 |
|---|---|
| `1` | 馬來盤 |
| `2` | 香港盤 |
| `3` | 印尼盤 |
| `4` | 歐洲盤 |

### wl_status（輸贏狀態）

| 值 | 說明 |
|---|---|
| `D` | 平局 |
| `WA` | 全贏 |
| `WH` | 贏半 |
| `LA` | 全輸 |
| `LH` | 輸半 |

### bet_status（注單狀態）

| 值 | 說明 |
|---|---|
| `Accepted` | 注單已被接受 |
| `Cancel` | 賽事取消 |
| `Pending` | 注單待定狀態 |
| `Rejected` | 注單被拒收 |

### sports_category

`BASEBALL`（棒球）、`BASKETBALL`（籃球）、`ESPORTS`（電子競技）、`OTHERS`（其他）、`PARLAY`（混合過關）、`SOCCER`（足球）、`TENNIS`（網球）。

### market_type

`EARLY`（早盤）、`LIVE`（滾球 / 實時比賽）、`TODAY`（今日賽事）。

### sports_type

`1` 足球、`2` 籃球、`3` 羽球、`4` 乒乓球、`5` 桌球、`6` 美式足球、`7` 網球、`8` 棒球、`9` 冰球、`10` 高爾夫、`11` 排球、`12` 板球、`13` 橄欖球、`14` 賽車、`15` 泰拳、`16` 飛鏢、`17` 沙灘足球、`18` 手球、`19` 拳擊、`20` 電子競技、`21` 壁球、`22` 刀塔、`23` 班迪球、`24` 射箭、`25` 澳式足球、`26` 投擲、`27` 蓋爾足球、`28` 水球、`29` 冬季運動、`30` 曲棍球、`31` 騎車、`32` 特別、`34` 競技、`35` 混合武術、`36` 奧林匹克、`37` 其他體育、`38` 冬奧會、`39` 金融、`40` 特別 4D、`41` 街頭霸王 V、`42` 終極格鬥 3、`43` 拳王。

> 注意：文件 sports_type 列表**跳過 33**（30 曲棍球 → 31 騎車 → 32 特別 → 34 競技），原文如此。

### bet_type（投注類型，足球為主）

`1X2`、`1X2H`（上半場）、`1X2HDP`（三項讓分）、`1X2HDPH`（上半場三項讓分）、`ANB`（客場無效）、`BS`（雙方/一方/兩者皆不得分）、`CL`（零失球）、`CS`（波膽）、`CSH`（上半場波膽）、`DC`（雙重機會）、`DCH`（上半場雙重機會）、`DNB`（獲勝球隊）、`FGLG`（最先進球/最後進球）、`FGLGH`、`HDP`（全場讓球）、`HDPH`（半場讓球）、`HNB`（主場無效）、`HTFT`（半場/全場）、`ML`（獨贏）、`MLH`（上半場獨贏）、`OE`（單/雙）、`OEH`、`OR`（優勝冠軍）、`OU`（大/小）、`OUH`、`TG`（總進球）、`TGH`、`TWTN`（零失球的勝方）。
