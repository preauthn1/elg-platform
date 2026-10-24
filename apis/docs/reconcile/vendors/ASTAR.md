# ASTAR（vendor_id=101）

> **卡型：專屬表卡（dedicated）** — `astar_bet_records` 一表一 vendor，可直接 SUM。
> **本卡最重要的三條**：(1) OP 對帳表的日切是 **GMT+8 12:00~11:59**，不是午夜；(2) SBR 的 `bet` 欄取 **`SUM(BR.valid_bet)`**，切口是 **`bet_time`**（不是 `payout_time`）；(3) 整點 SBR 由 RD-Studio-01 舊系統寫；apis 的 `summary_bet_record` 已註冊 Astar，但 `schedule_enabled=false`，只供手動補跑。
> 通用 SOP 見 `reconcile-bet-records` skill；vendor 差異寫在這裡。

## ✅ 必填契約聲明區（Step 0 gate 檢查這份）

**共用核心**

- [x] **識別**：`vendor_id=101`、`tname=astar` → `astar_bet_records`、`is_single_wallet=1`（見「速查表」）
- [x] **TBR/SBR `time_field`**：apis `config/summary_bet_record.php` 已註冊 `bet_time`（`schedule_enabled=false`、`reconcile_timezone=-4`）；整點 SBR 由舊系統寫，切口同為 **`BR.bet_time`**（見「SBR 由誰寫」）
- [x] **時區 + UTC 換算**：見「時區」（含 BO 日切公式）
- [x] **金額口徑**：見「欄位與金額口徑」
- [x] **三方回核**：**有** — `php artisan astar:check_bet_record` 讀官方後台匯出的 CSV 逐筆比對（見「取得三方資料」，含一個路徑 bug 與口徑差異）
- [x] **未結算判定謂詞**：`status <> 1`（見「status / 結算流程」）
- [x] **終驗 SQL**：見「終驗 SQL」（內聯可執行）

**專屬表卡專屬**

- [x] **BR 有效投注篩選謂詞**：`status = 1`，無附加排除（見「status / 結算流程」）
- [x] **BR `time_field`**：`bet_time`，與 SBR 同切口
- [x] **status enum 全集**：ROLLOUT=0 / ROLLIN=1 / REFUND=9（見「status / 結算流程」）
- [x] **Phase C 可用性**：apis **可手動補跑**（`--vendor=101`），但來源與舊系統不同（見「SBR 由誰寫」的口徑警告）；RD-Studio-01 三支命令仍可用（見「對齊命令一覽」）

## 速查表

| 項目 | 值 |
|---|---|
| `vendor_id` | 101 |
| `VendorIdEnum` case | `App\Enums\Vendor\VendorIdEnum::ASTAR` |
| `vendors.name` / `code` | `ASTAR` / `astar` |
| `vendors.tname`（BR 表前綴） | `astar` → `astar_bet_records` |
| `vendors.third_party_code` | `ASTAR` |
| apis `summary_bet_record` time_field | `bet_time`（`schedule_enabled=false`＝不進整點排程，只供手動補跑；整點 SBR 由舊系統寫） |
| `vendors.is_single_wallet` | `1`（單錢包） |

`vendor_infos`（VWorld DB 實例）：

| id | name | currency | status |
|---|---|---|---|
| 422 | ASTAR (VWORLD) | MYR | 1（啟用） |
| 419 | ASTAR (TPOWER) | MYR | 0（停用，歷史遺留） |

> TPower 站的 Astar 要切 `TPower` connection 才查得到，不是查 VWorld 上的 419。

## 一行概述

單錢包遊戲商，注單寫入即結算；TBR 由 apis 回調當下即時寫入，SBR 由 RD-Studio-01 舊系統從 BR 聚合（不經 TBR）。

## ⚠️ 對帳日切是 GMT+8 12:00~11:59（最容易錯的一條）

Astar 的遊戲商後台（BO）與 OP 對帳表都以 **GMT+8 中午 12:00 為一天的起點**，OP 表頭會標「12:00~11:59」。換成 UTC：

```
OP 表上的「D 日」 = UTC [ D 04:00, D+1 04:00 )
```

SQL 分組公式（2026-08 實測，連續 12 天逐日精確重現 OP 對帳表的「api 加總」欄）：

```sql
SELECT DATE_FORMAT(DATE_SUB(bet_time, INTERVAL 4 HOUR), '%Y-%m-%d') AS bo_date,
       COUNT(*) AS cnt,
       ROUND(SUM(valid_bet), 2) AS valid_bet,   -- 對 OP 的「投注」欄
       ROUND(SUM(win_lose), 2)  AS win_lose     -- 對 OP 的「輸贏」欄
FROM astar_bet_records
WHERE status = 1
  AND bet_time >= '2026-08-12 04:00:00' AND bet_time < '2026-08-24 04:00:00'
GROUP BY bo_date ORDER BY bo_date;
```

**用午夜日切去對 OP 的表一定對不上**，而且會對成「每天都有差」，很容易誤判成系統性 drift。

## SBR 由誰寫

Astar 已註冊在 apis 的 `config/summary_bet_record.php`，但 `schedule_enabled=false`（`target/tickets#4303`：整點排程仍由舊專案負責，apis 只開放手動 `--vendor` 補跑）。整點 SBR 由 **RD-Studio-01 舊版 `summary_bet_record` 的 ASTAR 區塊**（`_release/app/Console/Commands/SummaryBetRecordCommand.php:182`）寫入：

- 歸戶 join：`astar_accounts` 的 **`vendor_username` OR `username`**（舊版 BR 存 `vendor_username`、新版改存 `username`，命令用 `orOn` 兩者都接）→ `vendor_infos`（篩 `vendor_id`）→ `members` → `agents`
- 篩選：`b.status = 1`、`b.bet_time >= start AND < end`
- 寫入：`record_count = COUNT(*)`、**`bet = SUM(b.valid_bet)`**、`win_lose = SUM(b.win_lose)`、`rolling_chip = SUM(b.rolling_chip)`、`jackpot = 0`

**所以 SBR 的對齊對象是 BR，不是 TBR**；而且 `SBR.bet` 對的是 BR 的 `valid_bet`，不是 `bet`。

> ⚠️ **用 apis 手動補跑時口徑可能不同**：apis 的 `summary_bet_record` 從 TBR 彙總，舊系統從 BR 彙總（`bet` 取 `valid_bet`、`INNER JOIN members／agents`）。兩邊對同一小時算出的結果是否一致**未驗證**，用 apis 補跑前先對一個小時試算比對。

> ⚠️ 因為命令 `INNER JOIN members` 與 `agents`，**帳號沒有對應 member / agent 的注單會被 SBR 排除**，卻仍出現在直接 SUM BR 的查詢裡。若對出小額恆定差異先查這一項。

## 取得三方資料（Step 0）

- **來源**：官方後台 → 報表查詢 → 遊戲紀錄查詢 → 導出，轉存 CSV
- **命令**：`php artisan astar:check_bet_record`（互動輸入對帳日期 `YYYY-MM-DD`）
- **CSV 檔名**：`gameRecord_{YYYY-MM-DD}.csv`
- **CSV 欄位對應**（`CheckDailyBetRecord::getDataFromCsv()`）：`bet_id` = **第 4 欄 + 第 1 欄** 串接、投注額 = **第 15 欄**、贏錢額 = **第 16 欄**、派彩時間 = 第 10 欄
- **輸出**：分四類逐筆報 —— `DB沒有找到投注紀錄` / `三方沒有找到投注紀錄` / `投注額不同` / `贏錢額不同`，最後印「校正後」總額

### ⚠️ 兩個使用前必須知道的坑

**(1) CSV 路徑對不上（實質 bug，2026-08-24 現況）**：命令讀 `base_path('app/Console/Commands/Astar/gameRecord_{date}.csv')`，但 repo 裡**沒有 `app/Console/Commands/Astar/` 這個目錄** —— 現有範例 CSV 放在 `app/Console/Commands/Vendor/Astar/`（多一層 `Vendor/`）。直接跑會得到「檔案不存在」。暫時解法是手動建那個目錄放 CSV；根治要改命令路徑。

**(2) 命令的 DB 側口徑與 OP 對帳表不同**：`getBetRecords()` 用 **`payout_time`** 切窗（`Asia/Taipei` 12:00 起 +1 天，日切與 OP 相同），而且**沒有 `status = 1` 過濾**。OP 表 / SBR 是 `bet_time` + `status = 1`。實測差異很小但存在（2026-08-23 BO 日：`bet_time` 口徑 1,028 筆 / 107,320.65，`payout_time` 口徑 1,029 筆 / 107,377.65）。**命令印的「DB總投注額」不會等於 OP 表的 api 加總，別當成新差異**；逐筆 `bet_id` 比對本身不受影響。

## 時區

| 對象 | 時區 |
|---|---|
| `astar_bet_records.bet_time` / `payout_time` | UTC |
| `total_bet_records` 時間欄 | UTC（由 BR 帶入） |
| `summary_bet_records.data_time` | UTC |
| 遊戲商後台 / OP 對帳表 | **GMT+8，且日切為 12:00~11:59**（見上方專節） |

## 欄位與金額口徑

BR 表 `astar_bet_records` 欄位：

```
id, bet_id(varchar191, UNIQUE), vendor_username, bet_time, bet, valid_bet, win_lose,
payout_time(可為 NULL), status, game_code, rolling_chip, jackpot, session_token,
info(text), created_at, updated_at
```

索引：`PRIMARY(id)`、`bet_id`(UNIQUE)、`vendor_username`、`bet_time`、`payout_time`、`status`、`session_token`、`updated_at` —— **切口欄 `bet_time` 有索引**，範圍查詢不需要像 PGSSW 那樣借 `updated_at`。

| 欄位 | 定義 / 實測 |
|---|---|
| `bet` | 原始投注額 |
| `valid_bet` | 有效投注，**SBR 與 OP 對帳表對的是這一欄** |
| `bet` vs `valid_bet` | **經常不相等**，每天都有數十到數百筆（2026-08-22 有 263 筆）。對帳差異別往這個方向找 |
| `win_lose` | 輸贏（公司視角） |
| `rolling_chip` | 實測 2026-07~08 恆 **0** |
| `jackpot` | BR 有此欄但實測恆 **0**；舊版命令寫入 SBR 時也固定填 0 |
| `payout_time` | 可為 NULL；實測只有 `status=9`(REFUND) 的列為 NULL |

## bet_id 處理

`bet_id` 為 `varchar(191)` 且 UNIQUE，格式如 `VW1211514635`。以字串處理，勿轉 int。

## status / 結算流程

enum：`app/Vendors/Astar/Enums/BetRecordStatusEnum.php`

| value | case | 說明 |
|---|---|---|
| 0 | `ROLLOUT` | 實測 2026-07~08 **0 筆** —— Astar 寫入即結算，不留未結算中間態 |
| 1 | `ROLLIN` | **已結算**，對帳一律以此為準 |
| 9 | `REFUND` | 退款；實測 2026-07/01~08/25 共 43 筆，且 `payout_time` 皆為 NULL。SBR 只算 `status=1`，退款單不計入 |

> ⚠️ 名稱是 ROLLOUT / ROLLIN（錢流方向），**不是「未結算 / 已結算」的字面語意**。別把它當成別的 vendor 的 `status` 直接套用；判斷已結算就用 `status = 1`。

未結算 / 退款殘留查詢：

```sql
SELECT status, COUNT(*) AS cnt, ROUND(SUM(bet),2) AS sum_bet, ROUND(SUM(valid_bet),2) AS sum_valid_bet
FROM astar_bet_records
WHERE status <> 1
  AND bet_time >= '2026-08-01 04:00:00' AND bet_time < '2026-08-24 04:00:00'
GROUP BY status;
```

## 對齊命令一覽

| 步驟 | 命令 / 動作 | 在哪個專案 |
|---|---|---|
| Step 0 取三方 | 官方後台匯出 CSV → `php artisan astar:check_bet_record` | apis |
| Step 1 校對（SUM） | 本卡「終驗 SQL」 | — |
| **BR → TBR** | 無 SP。`AstarController` 在回調當下經 `TotalBetRecordService::create()` 寫入，key 為 `(vendor_id, bet_record_id)` | apis |
| **重抓三方注單 + 重統計** | `php artisan vendor_bet_record_rerun --vendor=101 --date=<GMT+8 日> --summary-disable=no` | RD-Studio-01 |
| **只重統計 SBR（整日）** | `php artisan vendor_summary_daily_rerun --vendor=101 --date=<GMT+8 日>` | RD-Studio-01 |
| **只補單一小時 SBR** | `php artisan summary_bet_record --start=<UTC Y-m-d@H> --vendor=101` | RD-Studio-01 |
| apis Phase C | `php artisan summary_bet_record --vendor=101 ...`（手動，口徑見「SBR 由誰寫」警告） | apis |

### ⚠️ 三支補帳命令的時區與粒度不一致

| 命令 | 參數 | 時區 | 粒度 |
|---|---|---|---|
| `vendor_bet_record_rerun` | `--date` | **GMT+8 日期** | 整日 |
| `vendor_summary_daily_rerun` | `--date` | **GMT+8 日期** | 整日 |
| `summary_bet_record` | `--start=Y-m-d@H` | **UTC** | 單一小時 |

**同一件事、三種輸入口徑**，下命令前務必確認自己在哪一種。三支都有相同的時間 gate：早於 3 天需代理帳密驗證、早於 30 天直接擋。

> 這三支都要在有該白標 DB **寫入**憑證的環境跑。本機容器通常只有 `*_DB_*_READ`，跑不了寫入。

## 終驗 SQL

### (1) BR ↔ SBR 逐小時差異（Astar 的主邊界）

只印對不上的小時；全對齊回 0 row：

```sql
SELECT hr,
       SUM(b_cnt) AS br_cnt, SUM(s_cnt) AS sbr_cnt,
       ROUND(SUM(b_vb),4) AS br_valid_bet, ROUND(SUM(s_vb),4) AS sbr_bet,
       ROUND(SUM(b_wl),4) AS br_wl, ROUND(SUM(s_wl),4) AS sbr_wl,
       ROUND(SUM(b_rc),4) AS br_rc, ROUND(SUM(s_rc),4) AS sbr_rc
FROM (
    SELECT DATE_FORMAT(bet_time,'%Y-%m-%d %H:00:00') AS hr,
           COUNT(*) AS b_cnt, SUM(valid_bet) AS b_vb, SUM(win_lose) AS b_wl, SUM(rolling_chip) AS b_rc,
           0 AS s_cnt, 0 AS s_vb, 0 AS s_wl, 0 AS s_rc
    FROM astar_bet_records
    WHERE status = 1
      AND bet_time >= '2026-08-14 00:00:00' AND bet_time < '2026-08-25 00:00:00'
    GROUP BY hr
    UNION ALL
    SELECT data_time AS hr,
           0, 0, 0, 0,
           SUM(record_count), SUM(bet), SUM(win_lose), SUM(rolling_chip)
    FROM summary_bet_records
    WHERE vendor_id = 101
      AND data_time >= '2026-08-14 00:00:00' AND data_time < '2026-08-25 00:00:00'
    GROUP BY data_time
) u
GROUP BY hr
HAVING SUM(b_cnt) <> SUM(s_cnt) OR SUM(b_vb) <> SUM(s_vb)
    OR SUM(b_wl)  <> SUM(s_wl)  OR SUM(b_rc) <> SUM(s_rc)
ORDER BY hr;
```

判讀：`br_cnt > 0 AND sbr_cnt = 0` → SBR 該小時缺失（補該小時）；`sbr_cnt > 0 AND br_cnt = 0` → SBR 有多餘 correction；兩邊都有但不等 → 該小時聚合錯。

### (2) BR ↔ TBR 逐筆對齊

```sql
SELECT COUNT(*) AS br_total,
       SUM(CASE WHEN t.id IS NULL THEN 1 ELSE 0 END) AS br_only,
       SUM(CASE WHEN t.id IS NOT NULL AND b.bet       <> t.bet       THEN 1 ELSE 0 END) AS bet_diff,
       SUM(CASE WHEN t.id IS NOT NULL AND b.valid_bet <> t.valid_bet THEN 1 ELSE 0 END) AS vb_diff,
       SUM(CASE WHEN t.id IS NOT NULL AND b.win_lose  <> t.win_lose  THEN 1 ELSE 0 END) AS wl_diff
FROM astar_bet_records b
LEFT JOIN total_bet_records t ON t.bet_record_id = b.id AND t.vendor_id = 101
WHERE b.status = 1
  AND b.bet_time >= '2026-08-14 00:00:00' AND b.bet_time < '2026-08-25 00:00:00';
```

四個計數應全為 0。

### (3) 對 OP 對帳表（BO 日切）

見上方「對帳日切」專節的 SQL —— 輸出可直接逐行比對 OP 表的「api 加總」兩欄。

## 已知 incident / 注意事項

- **用午夜日切對 OP 表**：最常見坑點，會對成「每天都有差」。Astar 是 GMT+8 12:00~11:59。
- **拿 `SUM(bet)` 對 SBR**：SBR 的 `bet` 欄是 `SUM(BR.valid_bet)`，兩者每天都不相等（差額數千到兩萬）。
- **`astar:check_bet_record` 的 CSV 路徑不存在**：見「取得三方資料」的坑 (1)。
- **三支補帳命令時區不一致**：兩支吃 GMT+8 日、一支吃 UTC 小時，見「對齊命令一覽」。
- **2026-08-23 BO 日差異（未結案）**：內部三表全對齊（BR↔SBR 逐小時 0 差異、BR↔TBR 8,490 筆零 mismatch），但 OP 表顯示我方多投注 **4,495.55**、多輸 **2,102.45**（我方 1,028 筆 / valid_bet 107,320.65 / wl −11,743.35；BO 為 102,825.10 / −9,640.90）。已排除的假設：日切錯位（同公式其他 21 天全中）、`bet` vs `valid_bet` 口徑（8/23 的 `SUM(bet)`=116,487.00，離 BO 更遠）、重複記帳（同帳號/遊戲/時間/金額只找到 1 組共 500.00）、`bet_time` 在窗內但 `payout_time` 不在（0 筆）。**差異落在 BR ↔ 三方，待 CSV 逐筆比對**；方向是「我方多」，預期會看到一批「三方沒有找到投注紀錄」。

## 關鍵檔案

| 用途 | 路徑 |
|---|---|
| 回調 Controller | `app/Http/Controllers/External/SingleWallets/AstarController.php` |
| BR Model | `app/Vendors/Models/AstarBetRecord.php` |
| Account Model | `app/Vendors/Models/AstarAccount.php` |
| Driver | `app/Vendors/Astar/Driver.php`（`extends AbstractSingleWallet`） |
| status enum | `app/Vendors/Astar/Enums/BetRecordStatusEnum.php` |
| 三方 CSV 對帳命令 | `app/Console/Commands/Vendor/Astar/CheckDailyBetRecord.php` |
| BR → TBR 寫入 | `app/Services/TotalBetRecordService.php` |
| SBR 寫入（舊系統） | `RD-Studio-01/_release/app/Console/Commands/SummaryBetRecordCommand.php:182` |
| 重抓帳 / 重統計（舊系統） | `RD-Studio-01/_release/app/Console/Commands/VendorBetRecordRerun.php`、`VendorSummaryDailyRerun.php` |
