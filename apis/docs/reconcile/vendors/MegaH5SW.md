# MegaH5SW（vendor_id=119）

> **卡型：專屬表卡（dedicated）** — BR 表一表一 vendor（`SELECT COUNT(*) FROM vendors WHERE tname='megah5sw'` = 1）、可直接 SUM、有 status / payout_time / valid_bet。
> 通用 SOP 見 `reconcile-bet-records` skill；vendor 細節（差異點）寫在這裡。
> 與 **KissH5SW（233）/ Slot4DSW（254）同屬一套三方協議**（opgateway seamless：GameLogin / Bet / GameResult / Rollback / CashBonus / Jackpot / BetNSettle；見 `Slot4DSW.md` 卡首註記）。
> ⚠️ **但本卡「三個對帳踩雷點」是 MegaH5SW 專有**（lineage 鍵誤置、回滾列筆數口徑、NULL 比較），**不可拿兄弟卡的 SQL 直接套本 vendor**。

## ✅ 必填契約聲明區

- [x] **識別**：`vendor_id=119`、`tname=megah5sw` → `megah5sw_bet_records`、`is_single_wallet=1`（見「速查表」）
- [x] **TBR/SBR `time_field`**：`payout_time`（須與 `config/summary_bet_record.php` 的 time_field 同步，改 config 要回補本卡）
- [x] **時區 + UTC 換算**：全欄位 UTC，換算公式見「時區」
- [x] **金額口徑**：見「欄位 mapping」；`win = win_lose + bet`，**不含 bonus / jackpot**（CashBonus 與 Jackpot 各自是獨立的 type=B / type=J 列）
- [x] **三方回核**：遊戲商後台（BO）的**日彙總頁**，人工登入抄錄，只能做**日級 aggregate** 核對；**無自動化取單管道、無逐筆明細**（bet_id 級逐筆比對做不到）。欄位對應與兩個口徑差見「取得三方資料（Step 0）」
- [x] **未結算（pending）判定謂詞**：`status = 0`（**禁止附加 `payout_time IS NULL`**，理由見「status / 結算流程」）
- [x] **終驗 SQL**：見「終驗 SQL」（時間窗與數字已內聯，2026-09-16 於 TPower 實跑驗證）
- [x] **BR 有效投注篩選謂詞**：金額用 `status = 1`、**筆數用 `status IN (1, 2)`**（無額外排除條件；理由見「踩雷點 1」）
- [x] **BR `time_field`**：`payout_time`（與 TBR 同切口）
- [x] **status enum 全集**：`0` 建立中 / `1` 結算派彩 / `2` 回滾，見「status / 結算流程」
- [x] **Phase C 可用性**：**已註冊**於 `config/summary_bet_record.php`（119 → MEGAH5SW / `payout_time`），通用 `summary_bet_record` 命令可用

## 速查表

| 項目 | 值 |
|---|---|
| `vendor_id` | 119 |
| `VendorIdEnum` case | `App\Enums\Vendor\VendorIdEnum::MEGAH5SW`（`app/Enums/Vendor/VendorIdEnum.php:126`） |
| `vendors.name` | MEGAH5 |
| `vendors.code` | MEGAH5SW |
| `vendors.tname`（**BR 表前綴**） | `megah5sw` → `megah5sw_bet_records` |
| `vendors.third_party_code` | MEGAH5SW |
| `summary_bet_record` time_field | `payout_time` |
| `vendors.is_single_wallet` | `1` (SW) |

## 一行概述

MegaH5 Seamless Wallet（單錢包）；H5 slot；BR 表 2026-07-06 建立；生產運行中（TPower 近 90 天約 97 萬筆結算注單）。

## ⚠️ 三個對帳踩雷點（動手前必讀）

通用 SOP 的範本 SQL 套在本 vendor 上會跑出**假 drift** 或**靜默漏抓**。下列三點是 MegaH5SW 專有的硬規則。

### 踩雷點 1：筆數比對**必須**用 `status IN (1, 2)`，金額比對**必須**用 `status = 1`

回滾列（`status = 2`）的 `bet` / `valid_bet` / `win` / `win_lose` 在 Rollback 時全部歸零（`MegaH5Controller.php:296-301`），**但該列仍然保留在 TBR**（回滾一樣呼叫 `TotalBetRecordService::create()`）。

實查證據（TPower，`payout_time` UTC `['2026-08-16 16:00:00', '2026-09-15 16:00:00')`）：

| 口徑 | 筆數 |
|---|---|
| BR `status = 1` | 625,291 |
| BR `status IN (1, 2)` | 625,313 |
| TBR（該 vendor 全量） | 625,313 |

差的 22 筆正是回滾列。**禁止**照通用範本只用 `status = 1` 篩 BR、TBR 卻取全量——筆數會對不上、被誤判成 TBR-only drift（金額則因回滾列已歸零而仍然相等，因此這個坑只在筆數上現形）。

### 踩雷點 2：BR ↔ TBR 的 lineage **必須**用 `reference_no`，**禁止**用 `bet_id`

`app/Vendors/Models/MegaH5SWBetRecord.php:58-59` 明寫：

```php
// 歷史樣板誤置：本表唯一交易鍵存於 reference_no（bet_id 為回合/非唯一），getBetId() 供 TBR.reference_no 與 log 使用
protected ?string $betIdColumn = 'reference_no';
```

也就是 `TBR.reference_no` 帶入的是 **BR.reference_no**，不是 `BR.bet_id`。`reference_no` 在建表時就是唯一索引（migration 第 20 行 `->unique()`），`bet_id` 只有普通索引、**在本表非唯一**（實例：type=B 的樣本 `bet_id` 全都是字面值 `MGH5A`）。

照通用範本套 `TBR.reference_no = BR.bet_id` 去 JOIN，會跑出整片假 drift。**逐筆比對一律 `JOIN ... ON t.reference_no = b.reference_no`。**

### 踩雷點 3：逐筆金額比對**必須**用 NULL-safe 運算子 `<=>`

`type = 'B'`（以及未來的 `J`）的 BR 列建立時不帶 `valid_bet` 欄，該欄為 **NULL**（`MegaH5Controller.php:348-365`；欄位 nullable 且無 default 值），而 TBR 側對應欄位是 `0`。

SQL 的 `NULL <> 0` 結果是 `NULL`、不是 `TRUE`，WHERE / HAVING 不會被觸發 —— 用 `b.valid_bet <> t.valid_bet` 篩差異時，這些列的 drift 會被**靜默漏抓**。

```sql
-- ❌ 禁止：B / J 列的差異永遠篩不出來
WHERE b.valid_bet <> t.valid_bet

-- ✅ 必須：NULL-safe 比較
WHERE NOT (COALESCE(b.valid_bet, 0) <=> t.valid_bet)
```

## 取得三方資料（Step 0）

> ⚠️ **本 vendor 只有日級 aggregate 可與三方核對；逐筆（bet_id 級）明細沒有任何管道。**

**有的**：MegaH5 遊戲商後台（BO）的**日彙總頁**，人工登入查看抄錄——這是目前**唯一**的三方核對管道。欄位對應（2026-09-16 依 BO 2026-08-28 畫面與我方實查值逐欄比對，全等；幣別 MYR）：

| BO 欄位 | BO 值（2026-08-28） | 我方對應（GMT+8 日切、`payout_time`、`status = 1`） | 我方值 |
|---|---|---|---|
| 貨幣 | MYR | — | — |
| 玩家數 | 79 | `COUNT(DISTINCT member_id)` | 79 |
| 遊戲數 | 19,667 | `type = 'N'` 的筆數 | 19,667 |
| 交易額 | 36,022.03 | `SUM(bet)` | 36,022.03 |
| 有效流水 | 36,022.03 | `SUM(valid_bet)` | 36,022.03 |
| 返回金額 | 36,567.92 | `SUM(win)` | 36,567.92 |
| 遊戲收入 | -545.89 | `-SUM(win_lose)` | `win_lose` = 545.89 |
| RTP | 101.52% | `SUM(win) / SUM(bet)` | 101.5155% |
| 獎金支付 | 40.64 | `type = 'B'` 的 `SUM(bonus)`（該日 3 筆） | 40.64 |
| Net Income | -586.53 | 遊戲收入 − 獎金支付 = -545.89 − 40.64 | -586.53 |

> ⚠️ **兩個口徑差，抄表前必看**
> 1. **BO「遊戲數」只計 `type = 'N'`**：8/28 BO 是 19,667，我方含 type=B 分錄的總列數是 19,670，差的 3 筆是 CashBonus。比筆數時 BR 側要加上 `type = 'N'`。
> 2. **BO「遊戲收入 / Net Income」是莊家視角，正負與我方 `win_lose` 相反**：BO 的 -545.89 對應我方 `win_lose` = 545.89。

**沒有的**：

- 任何自動化取單命令或 API——`app/Vendors/MegaH5SW/Driver.php` 只實作 `setInfo` / `getGameList` / `createAccount` / `getGameUrl`，沒有拉注單的介面；`app/Console` 下也零命中（`grep -ri megah5 app/Console`）。
- **逐筆注單明細**（bet_id 級）。BO 只給日彙總數字。

> ⚠️ **BO 的日界與登入方式尚未正式查核。** 上表是拿 BO 的 2026-08-28 畫面對我方 **GMT+8 日切**（UTC `['2026-08-27 16:00:00','2026-08-28 16:00:00')`）比對全等推得——筆數（19,667）與玩家數（79）這種整數欄位都能對上，已相當強地指向 **BO 日界＝UTC+8**（與同協議 KissH5SW / Slot4DSW 記載的 SGT 日界相同，SGT 即 UTC+8）。但這仍是**單日樣本**，且同協議的 `GetDaySummary` 有「即便傳 UTC `Date`、內部仍以 SGT 計算」的前科（見 `KissH5SW.md`、`Slot4DSW.md`），建議再挑一天（最好挑跨日結算較多的日子）複驗後把結論釘死。另外本卡**未記載 BO 網址與登入方式**，需要抄表時向 OP 取得。

**對帳能力上限**：

- ✅ **日級 aggregate 可與三方核對**：照上表逐欄比對，對得上即代表該日我方與三方一致。
- ❌ **bet_id 級逐筆比對做不到**：查得出「哪一天對不上」，查不出「差在哪幾筆」。
- 需要 row-level source-of-truth 結案的場合（月結、稽核、客訴牽連金額），**必須另外向遊戲商索取注單明細檔**，工程端無法自助完成。

> 📌 **每日對帳的「遊戲商」欄務必確實從 BO 抄。** 2026-08-28 那一列填的是我方當時漏跑狀態的 SBR 值、不是 BO 值，結果整列顯示「0.00 差異」，實際上是我方數字對我方數字的假對齊（詳見「已知 incident / 注意事項」）。

**若日後接到三方報表 API 或後台匯出格式**：回補本段與「欄位 mapping」，並把上面「逐筆做不到」的能力上限一併改掉。

## 時區

| 對象 | 時區 |
|---|---|
| Callback `TranDateTime` | **UTC**（直存，不換算） |
| `megah5sw_bet_records.bet_time` | **UTC**（migration 註解「投注時間(UTC)」） |
| `megah5sw_bet_records.payout_time` | **UTC**（migration 註解「派彩時間(UTC)」） |
| `total_bet_records.payout_time` | **UTC** |
| `summary_bet_records.data_time` | **UTC** |
| `config/summary_bet_record.php` time_field | `payout_time` |

**對帳換算公式**：

```
GMT+8 的 D 日整日 = UTC [D-1 16:00:00, D 16:00:00)
範例：2026-09-09（GMT+8） = UTC ['2026-09-08 16:00:00', '2026-09-09 16:00:00')
```

## 欄位 mapping（callback → BR）

| callback 欄位 | BR 欄位 | 備註 |
|---|---|---|
| `BetId` | `bet_id` | type=N；B 列存 `BonusId`、J 列存 `JackpotId`。**非唯一**，不可當 lineage 鍵（踩雷點 2） |
| `ResultId` | `reference_no` | **唯一索引**；bet 階段先暫存 BetId，settle 時覆寫為 ResultId（`MegaH5Controller.php:239`）。B / J 列存 `TranId` |
| `RoundId` | `round_id` | 可為 NULL；同一 round 可有多筆注單（見「multi-bet」） |
| `BetAmount / rate` | `bet` | 入庫前 `Common::numDiv(BetAmount, vendor_infos.rate, 4)` |
| `ValidBetAmount / rate` | `valid_bet` | 只有 type=N 有值；**B / J 為 NULL**（踩雷點 3） |
| `WinLose / rate` | `win_lose` | 可為負 |
| （計算值） | `win` | `win = win_lose + bet`（`MegaH5Controller.php:235`）；**不含 bonus / jackpot** |
| `Payout / rate` | `bonus`（type=B）/ `jackpot`（type=J） | CashBonus / Jackpot 為獨立 BR 列，`bet = 0`、`win = 0`、`win_lose = 0` |
| `TranDateTime` | `bet_time` / `payout_time` | UTC 直存 |

### type 分錄（N / B / J）

| type | 語意 | `bet_id` | `reference_no` | 建立時 status | 金額落點 |
|---|---|---|---|---|---|
| `N` | 正常投注 | 三方 `BetId` | 三方 `ResultId` | `0`（Bet）或 `1`（BetNSettle） | `bet` / `valid_bet` / `win` / `win_lose` |
| `B` | CashBonus 活動獎金 | 三方 `BonusId` | 三方 `TranId` | **直接 `1`** | `bonus`（`bet=0`、`valid_bet=NULL`、`win_lose=0`） |
| `J` | Jackpot 彩金 | 三方 `JackpotId` | 三方 `TranId` | **直接 `1`** | `jackpot`（同 B 的模式） |

- **B / J 一樣會寫進 TBR**（`TotalBetRecordService::create()` 帶 `['bonus' => ...]` / `['jackpot' => ...]`）。實查：全表 86 筆 type=B，`bet_record_id` 全部都對得到 TBR 列、0 筆孤兒。故 BR↔TBR 筆數口徑**不可**把 B / J 排除。
- 近 90 天資料分佈（TPower，`bet_time >= '2026-06-18'`）：`status=1 / type=N` 970,491 筆、`status=1 / type=B` 86 筆、`status=2 / type=N` 53 筆、**`type=J` 0 筆**（code 支援，實務上尚未出現）。

## bet_id / reference_no 處理

- **編碼**：三方 `BetId` / `ResultId` 驗證規則是 `integer`，Controller 一律 `(string)` cast 後才進 where（`MegaH5Controller.php:137 / 214 / 278 / 487`）。
- **大整數陷阱**：同協議的 KissH5SW 曾因 17~18 位整數走 float 比對命中相鄰記錄（詳見 `KissH5SW.md` §incident）。本卡的對帳紀律比照辦理：**所有 `bet_id` / `reference_no` 的 SQL 比對全程字串，禁止讓 int / float 參與**。
- **唯一鍵**：`reference_no` 單欄唯一；另有複合唯一鍵 `unique_type_bet_payout` = (`type`, `bet_id`, `payout_time`)。

## status / 結算流程

- **無獨立的注單 status enum class**，值寫死在 Controller。

| 值 | 語意 | 寫入點 |
|---|---|---|
| `0` | 建立中（下注已扣款、尚未結算） | `MegaH5Controller.php:163`（Bet） |
| `1` | 結算 / 派彩 | `:238`（GameResult）、`:364`（CashBonus）、`:426`（Jackpot）、`:513`（BetNSettle） |
| `2` | 回滾（Rollback） | `:301` |

> ⚠️ **`app/Vendors/MegaH5SW/OperatorStatusEnum.php` 不是注單 status**，別混淆。那是**三方回應碼**（`200` OK、`900404` INVALID_PLAYER、`900415` BET_TRANSACTION_NOT_FOUND、`900417` CANCEL_BET_N_SETTLE_REJECTED …），寫在 HTTP 回應的 `Status` 欄，與 BR 的 `status` 欄沒有任何對應關係。

**冪等防護（`MegaH5Controller.php`）**：

| 情境 | 行號 | 行為 |
|---|---|---|
| 已結算再收 GameResult | `:220` | 冪等回傳成功，不重複結算 |
| 已回滾再收 GameResult | `:227-228` | 回 `BET_TRANSACTION_NOT_FOUND`，不允許重新結算 |
| 已回滾再收 Rollback | `:284` | 冪等回傳成功 |
| 已結算再收 Rollback | `:290-292` | 回 `BET_TRANSACTION_NOT_FOUND`，**刻意**用這個碼終止三方重試（回 `INTERNAL_SERVER_ERROR` 會讓遊戲商一直重送） |

### 未結算（pending）判定謂詞

```sql
status = 0
```

> 🚫 **禁止附加 `payout_time IS NULL`。** Bet 建檔時 `payout_time` 寫的是**冪等佔位值 `'1970-01-01 00:00:01'`**（`MegaH5Controller.php:158-162`；因為 `TotalBetRecordService::create()` 以 `payout_time ?? null` 判斷是否帶欄位，`total_bet_records.payout_time` 無預設值，給 NULL 會 INSERT 失敗），**不是 NULL**。加了 `payout_time IS NULL` 這個條件，pending 注單永遠篩不出來。

> 月結查 pending **禁用 `payout_time` 範圍切窗**：佔位值落在 1970 年，不會進任何近期時間窗。**必須改用 `bet_time` 切窗。**

實查現況：全表 `status = 0` **0 筆**，無殘留（2026-09-16 觀察值）。

### multi-bet

**支援**。`round_id` 只有普通索引、非唯一，同一 `RoundId` 可以有多筆各自獨立 `BetId` / `ResultId` 的注單列。`cancelBetNSettle` 的冪等查詢即以 (`type='N'`, `status=1`, `bet_id`, `reference_no`, `round_id`) 五個條件定位單一注單（`MegaH5Controller.php:578-584`），而非以 `round_id` 當唯一鍵。對帳做回合層聚合時注意一個 round 可能對到多列。

## 對齊命令一覽

| 步驟 | 命令 |
|---|---|
| Step 0 取三方資料 | **無**（無對源；需要 source-of-truth 時請 OP 從遊戲商後台匯出） |
| Step 1 校對 | 無專屬命令；以本卡「終驗 SQL」做三表自洽檢查 |
| Step 4 補帳（通用 Phase C） | 先 `summary_bet_record --vendor=119 --date={YYYY-MM-DD} --hour={H} --dry-run` 試跑 → 確認差異後去掉 `--dry-run` 實跑 |

> ⚠️ **Step 4 命令安全規範（金流寫入，務必遵守）**
> - `--date` / `--hour` 輸入為 **UTC+8（台北）**，命令內部自動轉 UTC+0；DB 內 BR / TBR 時間為 UTC，下命令前須先換算窗口。
> - **禁止裸跑** `summary_bet_record --vendor=119`：缺 `--date` / `--hour` 時預設「今天（UTC+8）整天 24 小時」，做歷史對帳會打錯帳務窗口。
> - 命令對 `summary_bet_records` 按 (vendor, 小時) **刪除重建**、已關帳時段會寫 correction 列；**務必先 `--dry-run` 確認差異筆數與時間窗無誤，再去掉 `--dry-run` 實跑**。

## 終驗 SQL

```sql
-- 單日三表 SUM 對齊（BR = TBR = SBR 才算通過）
-- 範例窗：GMT+8 2026-09-09 整日 = UTC ['2026-09-08 16:00:00', '2026-09-09 16:00:00')
-- 三段時間窗皆為 UTC（BR / TBR.payout_time、SBR.data_time 均為 UTC），已內聯實值可直接執行
SELECT 'megah5sw_bet_records' AS source,
  -- ★ 筆數用 status IN (1,2)（WHERE 已篩）：回滾列金額雖歸零但仍在 TBR，漏算會誤判成 TBR-only drift
  COUNT(*) AS cnt,
  -- ★ 金額一律只取 status = 1
  SUM(CASE WHEN status = 1 THEN bet END) AS sum_bet,
  SUM(CASE WHEN status = 1 THEN valid_bet END) AS sum_valid_bet,
  SUM(CASE WHEN status = 1 THEN win_lose END) AS sum_winlose,
  SUM(CASE WHEN status = 1 THEN rolling_chip END) AS sum_rolling_chip,
  NULL AS sum_commission,  -- BR 無 commission 欄
  SUM(CASE WHEN status = 1 THEN jackpot END) AS sum_jackpot
FROM megah5sw_bet_records
WHERE payout_time >= '2026-09-08 16:00:00' AND payout_time < '2026-09-09 16:00:00'
  AND status IN (1, 2)
UNION ALL
-- ★ TBR 全量 by vendor_id + 時窗，不 JOIN BR：INNER JOIN BR 會把孤兒 TBR（bet_record_id 指向不存在的 BR）排除、掩蓋 TBR-only drift
SELECT 'total_bet_records (vendor=119)', COUNT(*),
  SUM(t.bet), SUM(t.valid_bet), SUM(t.win_lose), SUM(t.rolling_chip), SUM(t.commission), SUM(t.jackpot)
FROM total_bet_records t
WHERE t.vendor_id = 119
  AND t.payout_time >= '2026-09-08 16:00:00' AND t.payout_time < '2026-09-09 16:00:00'
UNION ALL
-- ⚠️ summary_bet_records 無 valid_bet 欄；其 bet 欄存的即「有效投注」
--   （SummaryBetRecordService：SUM(total_bet_records.valid_bet) AS bet），故原始 bet 欄填 NULL、SBR.bet 對齊到 valid_bet 欄比對。
-- ⚠️ SBR 是彙總列（一列 = 一個 member × game × 小時），筆數要取 SUM(record_count)，不是 COUNT(*)
SELECT 'summary_bet_records (vendor=119)', SUM(record_count),
  NULL AS sum_bet, SUM(bet) AS sum_valid_bet, SUM(win_lose), SUM(rolling_chip), SUM(commission), SUM(jackpot)
FROM summary_bet_records
WHERE vendor_id = 119
  AND data_time >= '2026-09-08 16:00:00' AND data_time < '2026-09-09 16:00:00';
```

> ✅ **通過條件**：`cnt` / `sum_valid_bet`（SBR 為其 `bet` 欄）/ `sum_winlose` / `sum_rolling_chip` / `sum_jackpot` 須 **BR = TBR = SBR** 相等；`sum_bet`（原始投注）僅 BR = TBR（SBR 不存原始 bet，列為 NULL）；`sum_commission` BR 無該欄。任一層不等即未通過。**跑完 Step 4 後務必回到此 SQL 複驗 SBR 列**——命令失敗、時間窗錯誤、欄位 drift（如 jackpot 未寫）都只會在 SBR 列現形，只比 BR / TBR 會漏掉。

### 已驗證基準（2026-09-16 於 TPower 實跑）

範例日 2026-09-09（GMT+8），即上面 SQL 內聯的 UTC 窗：

| 層 | 筆數 | valid_bet | win_lose | rolling_chip | commission | jackpot |
|---|---|---|---|---|---|---|
| BR（金額取 `status=1`） | 24,688 | 34,016.81 | -4,923.34 | 0 | — | 0 |
| TBR | 24,688 | 34,016.81 | -4,923.34 | 0 | 0 | 0 |
| SBR（`data_time` 切） | 24,688 | 34,016.81 | -4,923.34 | 0 | 0 | 0 |

> 該日**恰好 0 筆回滾列**，所以 `status = 1` 的筆數也剛好對得上。**換一天當基準時必須套用踩雷點 1 的筆數口徑**，否則會被回滾列咬到。

## 已知 incident / 注意事項

### 2026-08-28 SBR 漏跑補跑與 2026-09-09 對帳表紅字

**現象**：OP 對帳表 2026-09-09 那一列，「遊戲商 34,016.81 / -4,923.34」與「api 加總（SR11）34,233.95 / -5,008.43」對不上，差 `bet 217.14`、`win_lose 85.09`。

**已證實的事實（2026-09-16 於 TPower 實查）**

1. 我方三表以 `data_time`（業務日）視角切 9/09 = 24,688 筆 / 34,016.81 / -4,923.34，BR = TBR = SBR 三層自洽（即上面「已驗證基準」那組數字）。
2. SR11 那一欄是 **`summary_time`（寫入日）視角**：以 `summary_time` 窗查 SBR 得 25,019 筆 / 34,233.95 / -5,008.43，與 SR11 一分不差。
3. 多出來的 331 筆是 9 列 `correction = 1`，`data_time` 全部落在 `2026-08-28 11:00:00` UTC 那一小時（= GMT+8 8/28 19:00），於 GMT+8 9/09 00:00 寫入，合計 `bet 217.14` / `win_lose -85.09`，正好等於差額。
4. **該小時的 SBR 整點彙總排程漏跑，我方 BR / TBR 兩層並無缺漏**（我方與三方之間是否一致屬另一問題，見下方待確認疑點）：那批的 BR 與 TBR `created_at` 都落在 `2026-08-28 11:00:02 ~ 11:48:03`（UTC），屬當下即時寫入、不是遲到的 callback；而 `summary_bet_records` 在 `data_time = '2026-08-28 11:00:00'` 這一小時**只有那 9 列 `correction = 1`，一列 `correction = 0` 的原始列都沒有**。也就是 BR / TBR 從頭就正確，缺的只有 SBR，9/09 補跑 `summary_bet_record` 才把它補回並標記 correction。

**近 40 天漏跑 / 補跑全紀錄**（`vendor_id = 119`、`correction = 1`、`data_time >= '2026-08-06 16:00:00'`）：

| `data_time` (UTC) | `summary_time`（GMT+8） | 列數 | 筆數 | bet | win_lose |
|---|---|---|---|---|---|
| 2026-08-18 09:00 | 8/26 | 1 | 1 | 5.00 | -5.00 |
| 2026-08-28 11:00 | 9/09 00:00 | 9 | 331 | 217.14 | -85.09 |
| 2026-08-31 08:00 | 9/02 00:00 | 1 | 1 | 2.50 | -2.50 |
| 2026-09-03 16:00 | 9/06 00:00 | 8 | 545 | 1168.00 | -1168.00 |

同批查證：近 40 天 TBR vs SBR **逐小時全等，目前沒有任何仍缺失的小時**（漏跑的都已補回）。四次漏跑分散在 40 天內，屬反覆發生，不是一次性事故。

**處置**

- **9/09 那一列**：對帳表說明欄註記「8/28 補到 9/9 b:217.14 wl:-85.09」結案，帳面**無需任何修復**。
- **8/28 那一列必須更正**：「遊戲商」欄改為 BO 實際值 `36,022.03` / `545.89`（對帳表輸贏欄以正值表示玩家輸，對應 BO 的 -545.89）；「api」欄 `35,804.89` / `630.98` 不變；差異欄因此變成 `217.14` / `-85.09`；說明欄註記「SBR 漏跑，9/9 補回」。**該列原本顯示的 0.00 差異是假對齊，不可留著。**

#### ⚠️ OP 對帳表的「遊戲商」欄取自哪裡（8/28 已證實，其餘待確認）

**已證實（2026-09-16，依使用者提供的 BO 畫面逐欄比對）**

- BO 8/28 = 交易額 36,022.03 / 遊戲收入 -545.89，與我方三表（補跑後）**逐欄全等**（完整欄位對照見「取得三方資料（Step 0）」）。→ **我方帳正確。** 嚴格說，日級 aggregate 證明的是**該日總額三方認帳、那 331 筆含在其中**；在這個解析度下無法區分「逐筆都在三方帳上」與「總額湊巧相等」，要到逐筆層級得另外索取明細檔（見「取得三方資料（Step 0）」的能力上限）。
- OP 對帳表 8/28 的「遊戲商」欄填的是 35,804.89，**不等於 BO**，而**恰為補跑前的 SBR 值**。→ **該欄那一天並非取自 BO**；該列顯示的「0.00 差異」是我方數字對我方數字，那天等於沒跟三方對到帳。

**仍待 OP 說明**

把「遊戲商」欄與我方 BR（`status = 1`、GMT+8 日切）逐日比對 8/28～9/14 共 18 天，結果是 **17 天完全相同、只有 8/28 一天不同**。8/28 這天的來源已經查明是我方數字；其餘 17 天是否為同一取數方式，**尚未經 OP 證實**。要問的兩個問題：

1. 8/28 那天的「遊戲商」欄數字實際是從哪裡取得的？為何會等於我方當時漏跑的 SBR 值？
2. 其餘日期是否為同一取數方式？若是，該欄須改為確實從 BO 日彙總頁抄錄。

> ⚠️ 另有一個未查證的前提：那 18 天的數字確實是 OP 逐日獨立取得、而非事後回填或由我方報表補齊。此前提屬 OP 作業方式、工程端無從查證，請一併向 OP 確認。

若第 2 題的答案是「其餘日期同樣取自我方報表」，代表**本 vendor 的三方對帳在那段期間形同未執行**——BO 日彙總頁是現成的（見「取得三方資料（Step 0）」），缺的只是把它接進日常對帳流程。

> 📌 **可重用的判讀法**：凡遇到「三表全等卻 ≠ 對帳表的 api / SR11 欄」，先以 **`summary_time` 窗**查 SBR，並列出 `summary_time` 落在該日、但 `data_time` 不在該日的 `correction = 1` 列；差額若等於該批總和，就是「寫入日 vs 業務日」的切口差，不是 drift——**其根因通常是某個小時的 SBR 排程漏跑後被補跑**。
>
> ⚠️ `reconcile-bet-records` skill 目前把「SR11 讀哪一層」列為**未驗證**。本案只實證到 **SR11 是寫入日視角**，**未**證實其讀取層就是 SBR——對外說明時不要寫死成「SR11 = SBR」。

### 其他注意事項

- `cancelBetNSettle` **不會沖銷注單**：它只做冪等確認——找得到對應的 `type='N' / status=1` 注單就回 200，找不到就回 `CANCEL_BET_N_SETTLE_REJECTED`（900417）讓三方持續重送（`MegaH5Controller.php:578-593`）。本 vendor 唯一會產生沖銷列的路徑是 **Rollback（`status=2`）**，對帳時不要去找「cancel 產生的沖銷列」。
- `betNSettle` **刻意不加 `lockForUpdate()`**（避免 gap lock 死鎖 1213），併發防線由 `reference_no` 唯一索引承擔，撞號者拋 1062 後回 `DUPLICATE_TRANSACTION`（`MegaH5Controller.php:484-487`）。對帳若看到零星 `DUPLICATE_TRANSACTION`，那是防線正常運作，不是掉單。
- 本 vendor 的 `rolling_chip` 全段為 0（Controller 各建檔點皆寫死 `0`），`commission` BR 無此欄；兩者若在 TBR / SBR 出現非 0，反而要查。

## 關鍵檔案

| 用途 | 路徑 |
|---|---|
| Controller 主邏輯（8 個 callback） | `app/Http/Controllers/External/SingleWallets/MegaH5Controller.php` |
| BR Model（含 lineage 鍵誤置註解） | `app/Vendors/Models/MegaH5SWBetRecord.php` |
| Driver | `app/Vendors/MegaH5SW/Driver.php` |
| 三方回應碼 enum（**非**注單 status） | `app/Vendors/MegaH5SW/OperatorStatusEnum.php` |
| BR 表結構 | `database/migrations/2026_07_06_000002_create_megah5sw_bet_records_table.php` |
| SBR 註冊（Phase C） | `config/summary_bet_record.php` |
| 同協議參考卡 | `docs/reconcile/vendors/KissH5SW.md`、`docs/reconcile/vendors/Slot4DSW.md` |
