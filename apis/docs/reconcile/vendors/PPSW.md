# PPSW（vendor_id=83）

> **卡型：專屬表卡（dedicated）** — BR 一表一 vendor、有 status / valid_bet、可直接 SUM。
> 對接卡。通用 SOP 見 `reconcile-bet-records` skill；本檔聚焦 PPSW 差異。

## 速查表

| 項目 | 值 |
|---|---|
| `vendor_id` | 83 |
| `VendorIdEnum` case | `App\Enums\Vendor\VendorIdEnum::PPSW` |
| `vendors.tname`（**BR 表前綴**） | `ppsw` → `ppsw_bet_records` |
| `vendors.third_party_code` | `PPSW` |
| `summary_bet_record` time_field | `payout_time` |
| `vendors.is_single_wallet` | `1`（SW）|

> ⚠️ Pragmatic Play 也有轉帳錢包版（`pragmatic` / `pragmaticplay` 等），各自有獨立 vendor_id 與 BR 表；本卡片只涵蓋 SW 版（vendor_id=83）。

## 一行概述

Pragmatic Play 單錢包；slot 主力；穩定接入中。

## 取得三方資料（Step 0）

- **來源**：DataFeed API（4 個域名 × 每日 288 個 timepoint）
- **命令**：
  - 單日：`php artisan ppsw:game-rounds {date}`
  - 區間：`php artisan ppsw:game-rounds-range {start} {end}`
- **限制（重要）**：**強制序列、不可併發**
  - 同 IP **同時 ≥ 2 個 in-flight 請求 → 約 43% 被拒絕**
  - 完全序列模式 → 100% 成功率
  - 已**移除** `Http::pool` 並棄用 `--concurrent` 開關
  - 4 個域名串行（一域名跑完才下一個）；域名內 288 timepoint 一個接一個發送
  - 唯一節流方式：調高 `--delay-ms`（預設 30ms / 100ms / 200ms）

**禁止**：
- 不要包 `&` 把多天指令並行（`ppsw:game-rounds 0504 & ppsw:game-rounds 0505 &`）
- 不要同時開兩個 terminal 跑 `ppsw:game-rounds-range`
- 不要把 `--delay-ms` 設為 0

> 程式碼註解見 `app/Console/Commands/Vendor/PragmaticPlay/RetrieveRecords/FetchGameRounds.php:400`

## 時區

| 對象 | 時區 |
|---|---|
| `ppsw_game_rounds.startDate` / `endDate` | UTC |
| `ppsw_bet_records.bet_time` / `payout_time` | UTC |
| TBR `payout_time` | UTC |
| `config/summary_bet_record.php` time_field | `payout_time` |

> 2026-05-27 實測驗證：VWorld 環境 `ppsw_game_rounds.startDate` 與 `ppsw_bet_records.bet_time` 秒級對齊；Dev 環境 PPSW BR/TBR/SBR 三表時間欄位皆與 `created_at`（app TZ=UTC）同時區。本表舊版誤記為 +8。

## 欄位 mapping（DataFeed → BR）

| 三方欄位 | BR 欄位 | 備註 |
|---|---|---|
| `playSessionID` | `bet_id` | 主 join key |
| `endDate` | `payout_time` | 可能為 NULL（IN_PROGRESS） |
| `startDate` | `bet_time` | |
| `bet` / `win` | `bet` / `win` | |

## bet_id 處理

- 編碼：整數（playSessionID）
- 大整數陷阱：無歷史紀錄；仍建議 string cast 保險

## status / 結算流程

`App\Vendors\PPSW\BetRecordStatusEnum`：

| 值 | 名稱 | 語意 |
|---|---|---|
| 0 | BET | 投注中（極少見） |
| 1 | END_ROUND | 結束回合（**未結算**，可能卡這） |
| 2 | REFUND | 退款 |
| 3 | SETTLE | 已派彩（最終態） |

正常事件流：`BET → END_ROUND → SETTLE` 或 `BET → SETTLE`（result 直接結算）。

**multi-bet：支援**（同 roundId 多次 bet 累加；1 BR = 1 TBR）。

### Controller 行為

| API | 對 BR | 對 TBR | 備註 |
|---|---|---|---|
| `bet` | 同 roundId 累加（`lockForUpdate`），新 round 才 INSERT | 不建立 | 多次 bet → 1 BR |
| `result` | 翻 status=SETTLE，累加 win/win_lose | `updateOrCreate` | 中獎派彩 |
| `endRound` | 翻 status=END_ROUND（已 SETTLE 早返回） | `updateOrCreate` | 可能先到 |
| `refund` | 翻 status=REFUND | 寫入 (金額 0) | 退款 |

### 三種事件順序的正常結果

| 順序 | BR 數 | TBR 數 | 最終 BR.status |
|---|---|---|---|
| `bet → result → endRound` | 1 | 1 (result 建立) | SETTLE |
| `bet → endRound → result` | 1 | 1 (endRound 建立、result 透過 updateOrCreate 更新) | SETTLE |
| `bet → bet → ... → result → endRound` (multi-bet) | 1 (累加) | 1 (result 建立) | SETTLE |

> 任何順序下都應只有 **1 筆 BR + 1 筆 TBR**，由 `bet()` 的 `lockForUpdate` 與 `TotalBetRecordService::create` 的 `updateOrCreate` 共同保證。

## 對齊命令一覽

| 步驟 | 命令 | 關鍵參數 |
|---|---|---|
| Step 0 取資料（單日） | `ppsw:game-rounds {date}` | `--missing-only` `--dry-run` |
| Step 0 取資料（區間） | `ppsw:game-rounds-range {start} {end}` | — |
| Step 1 校對 | `ppsw:compare-records {date}` | — |
| Step 1.5 同步 payout_time | `ppsw:sync-payout-time {date}` | `--dry-run` |
| Step 2 修正（A+B 單筆 stuck） | `ppsw:fix-records {date}` | `--dry-run` |
| Step 2 修正（C multi-bet） | `ppsw:backfill-stuck-bet-records {start} {end}` | `--commit` |
| 三層管線比對 | `ppsw:compare-bet-pipeline {date}` | `--with-game-rounds` |
| 結算未收 EndRound 注單 | `ppsw:end-round` | — |

> 已棄用並從 codebase 刪除：`ppsw:fix-stuck-status`、`ppsw:fix-missing-total-bet-records`（已整合進 `ppsw:fix-records`，但 2026-05 incident 顯示舊版命令缺乏 PP 對帳驗證會造成 over-correct）。**部署時請確認 prod image 已重 build 不再包含這兩支舊命令的 binary**。
>
> ⚠️ `ppsw:fix-records` 2026-05 起加上三道驗證（無對應 game_round / 非 Finished / multi-bet 整 group 處理）；對 multi-bet round 請優先用 `ppsw:backfill-stuck-bet-records` consolidate。

### Step 1.5 sync-payout-time 為何重要

`fix-records` 用 `payout_time` 過濾。若 BR.payout_time 與新抓回的 game_rounds.endDate 不同步，剛回 endDate 的紀錄會被 fix-records 漏掉、下一輪又得回頭跑一次。**首次對齊也應跑**，不限重拉場景。

### PPSW 專屬執行順序

依本順序執行（與通用 SOP 對應）：

```
Step 0   ppsw:game-rounds-range {start} {end}      # 補抓 DataFeed
Step 1.5 ppsw:sync-payout-time {date}              # 同步 endDate → payout_time
Step 1   ppsw:compare-records {date}               # 找 game_rounds vs BR 差異
Step 2C  ppsw:backfill-stuck-bet-records (先做)    # multi-bet consolidation
Step 2AB ppsw:fix-records {date}                   # 單筆 stuck
Step 3   §5 SUM SQL（已結算 BR ↔ TBR）
Step 4   summary_bet_record --vendor=83 --date=...
Step 5   §5 SUM SQL（TBR ↔ SBR）
Step 6   list-sbr-corrections「查 PPSW {date} 補帳」
```

## 終驗三表 SQL

```sql
SELECT 'ppsw_game_rounds' AS source, COUNT(*) AS cnt,
  SUM(bet) AS sum_bet, SUM(win) AS sum_win, SUM(win - bet) AS sum_winlose
FROM ppsw_game_rounds
WHERE startDate >= '{start} 00:00:00' AND startDate < '{end+1} 00:00:00'
UNION ALL
SELECT 'ppsw_bet_records (status=3)', COUNT(*), SUM(bet), SUM(win), SUM(win_lose)
FROM ppsw_bet_records
WHERE bet_time >= '{start} 00:00:00' AND bet_time < '{end+1} 00:00:00'
  AND status = 3
UNION ALL
SELECT 'total_bet_records (vendor=83)', COUNT(*),
  SUM(tbr.bet), SUM(tbr.bet + tbr.win_lose), SUM(tbr.win_lose)
FROM total_bet_records tbr
JOIN ppsw_bet_records br ON br.id = tbr.bet_record_id
WHERE tbr.vendor_id = 83
  AND br.bet_time >= '{start} 00:00:00' AND br.bet_time < '{end+1} 00:00:00';
```

## 四層帳目對齊驗證（含 SBR）

完整對帳鏈：**vendor 後台 ≈ DataFeed (ppsw_game_rounds) ≈ BR (callback 真值) → TBR → SBR**

| 層 | 時間欄位 | 內容 | 對齊預期 |
|---|---|---|---|
| **vendor 後台** | SGT 8:00-7:59（= UTC 0:00-23:59）| PP 後台「帳目」報表 | 基準 |
| **DataFeed (ppsw_game_rounds)** | `startDate`（UTC） | PP DataFeed API round-level 真值；UNIQUE `playSessionID` | ≈ vendor 後台（差 ≤ 數百，PP 後台對 `gr.bet=gr.win` 平手 round 不入帳屬正常） |
| **BR (ppsw_bet_records)** | `payout_time`（UTC） | callback 收到的 reference-level 紀錄 | per round：`SUM(BR.bet) = gr.bet` ✓ |
| **TBR (total_bet_records)** | `payout_time`（UTC） | 跨遊戲商統一表；multi-bet 由 BackfillStuckBetRecords consolidate 為 1 round 1 TBR | per round：`SUM(TBR.bet) = gr.bet` ✓ |
| **SBR (summary_bet_records)** | `data_time`（UTC） | hour bucket SUM(TBR)，依 `TBR.payout_time` 分組 | `SBR.sum = TBR.sum` per hour |

### 四層對齊 SQL

```sql
WITH br_sum AS (
  SELECT DATE(payout_time) AS d, SUM(bet) AS br
  FROM ppsw_bet_records
  WHERE payout_time >= '{start}' AND payout_time < '{end}'
  GROUP BY d
), tbr_sum AS (
  SELECT DATE(payout_time) AS d, SUM(bet) AS tbr
  FROM total_bet_records
  WHERE vendor_id = 83 AND payout_time >= '{start}' AND payout_time < '{end}'
  GROUP BY d
), sbr_sum AS (
  SELECT DATE(data_time) AS d, SUM(bet) AS sbr
  FROM summary_bet_records
  WHERE vendor_id = 83 AND data_time >= '{start}' AND data_time < '{end}'
  GROUP BY d
)
SELECT br.d, br.br, tbr.tbr, sbr.sbr,
       (tbr.tbr - br.br) AS tbr_minus_br,
       (sbr.sbr - tbr.tbr) AS sbr_minus_tbr
FROM br_sum br
JOIN tbr_sum tbr ON tbr.d = br.d
JOIN sbr_sum sbr ON sbr.d = br.d
ORDER BY br.d;
```

### 判讀

| 觀察 | 意義 / 對策 |
|---|---|
| `sbr_minus_tbr != 0` | SBR rebuild 沒跑或跑錯 hour → 重跑 `summary_bet_record --vendor=83 --date={SGT} --hour={SGT}` |
| `tbr_minus_br != 0` 且金額較大 | TBR 與 BR 失準 — 通常是 fix-records / fix-missing 重複補 TBR（見下方 2026-05 incident） |
| `tbr_minus_br = ±1~3` | 跨日邊界 BR.payout_time 切桶 tolerance，可接受 |
| `tbr - vendor 後台 = +N` 且 N 對應「平手 round (gr.bet=gr.win)」量級 | PP 後台對玩家平手 round 不入帳、DataFeed/BR/TBR 仍記錄，屬正常 |

### SBR rebuild 命令時區陷阱

`summary_bet_record` 命令的 `--date` / `--hour` 接 **SGT** 輸入，內部轉 UTC 查 TBR。從 DB 取出的 `payout_time` 是 UTC，必須換算 SGT 再餵 rebuild：

```php
$utc = Carbon::parse($payoutTime);                       // 從 DB 取，UTC
$sgt = $utc->copy()->setTimezone('Asia/Singapore');      // SGT
// 用 $sgt->format('Y-m-d') / (int) $sgt->format('H') 傳給 rebuild
```

---

## 殘留紀錄分類

PPSW 殘留 status=1 + payout_null 紀錄會分為兩類，需個別處理：

| 類別 | game_rounds 狀態 | 處理方式 |
|---|---|---|
| **NOT_IN_DATAFEED** | 完全沒有對應 round | 重拉 DataFeed 看是否補進，仍無則送 PPSW 客服 |
| **IN_PROGRESS** | `status='I'`、`endDate=NULL` | 玩家中途斷線/未完成，等 DataFeed 更新或送 PPSW 人工結算 |

### 分類查詢（匯出 CSV 給 PPSW 核對）

```sql
SELECT
  br.id AS br_id, br.bet_id, br.username, br.member_id, br.game_code,
  br.bet, br.bet_time, br.created_at AS br_created_at, br.updated_at AS br_updated_at,
  CASE
    WHEN gr.id IS NULL          THEN 'NOT_IN_DATAFEED'
    WHEN gr.endDate IS NULL     THEN CONCAT('IN_PROGRESS (gr.status=', COALESCE(gr.status, 'NULL'), ')')
    ELSE CONCAT('SETTLED_IN_GR (', gr.endDate, ')')
  END AS game_round_state,
  gr.startDate AS gr_start_date, gr.endDate AS gr_end_date,
  gr.status AS gr_status, gr.bet AS gr_bet, gr.win AS gr_win
FROM ppsw_bet_records br
LEFT JOIN ppsw_game_rounds gr ON gr.playSessionID = br.bet_id
WHERE br.bet_time >= '{start} 00:00:00' AND br.bet_time < '{end+1} 00:00:00'
  AND br.status = 1
  AND br.payout_time IS NULL
ORDER BY br.bet_time;
```

匯出 CSV：

```bash
docker exec -i php-fpm8 bash -c "cd /var/www/APIs && \
  mysql --batch --default-character-set=utf8mb4 -e \"<上述 SQL>\" \
  > storage/reports/ppsw_pending_rounds_{start}_to_{end}.csv"
```

> 殘留紀錄業務處置（退款 / 強制結算 / 重拉）見通用 SOP §6.4.3。

## 已知 incident / 注意事項

- **DataFeed 限流**：見上方「取得三方資料」一節
- **stuck status=1 根因**：DataFeed 漏抓 → 先補抓 + sync-payout-time，**不要直接跑 fix-records 重試**（通用根因模板見 SOP §6.2）
- 實測案例（2026-05-04~05）：0504 game_rounds 從 16,446 → 109,808；0505 從 0 → 82,391。補抓 + sync-payout-time + fix-records 即可消化絕大多數

### 2026-05 Incident: fix-records (B) win_lose 灌錯 + fix-missing 重複補 stuck TBR

**症狀**

對外試算表反映 PPSW 4/2~4/16 帳目：4/29 / 5/2 SBR correction 量遠超殘差欄位（補太多）、winloss 偏負、總投注比 PP 後台多。3 個獨立 bug 疊加產生。

**根因**

| # | bug | 影響 |
|---|---|---|
| 1 | `ppsw:fix-records` (B) 對 `status=END_ROUND` 且無 TBR 的 BR 直接補建 TBR，但 BR.win=0（result callback 未進），補出 `TBR.win_lose = -bet` 灌入「玩家全輸」假帳 | 79 筆 TBR 灌錯，sum_winlose 多算 4099.1 |
| 2 | 舊版 `ppsw:fix-missing-total-bet-records` 對 multi-bet round 不知情：BackfillStuckBetRecords 已把 stuck.bet 累加到 primary TBR，fix-missing 仍以「stuck BR 無 TBR」為由重補 → double count | 22 筆 TBR 重複建，sum_bet 多算 1077.5 |
| 3 | `ppsw:compare-bet-pipeline` 提示 OP 跑 `ppsw:fix-missing-total-bet-records`，但這支已從 codebase 移除、prod 卻仍有舊 binary，OP 跟著提示就照跑 → 催化 bug 2 擴大 | 5/4 OP 跑舊版至少 17 次 |

**修復步驟**

| 步驟 | 動作 | 命令 / 檔案 |
|---|---|---|
| 1 | `fix-records` (B) 加三道驗證 | 修 `FixRecords.php`：對 `ppsw_game_rounds` 比對 round 存在、`status='F'`、非 multi-bet |
| 2 | 修正 fix-records 灌錯的 win_lose | ~~`ppsw:patch-fix-records-overshoot-2026may`~~ **命令已於 2026-07-28 移除**（一次性 patch，事故已收案；此列僅留事故紀錄） |
| 3 | 刪除 fix-missing 重複的 stuck TBR | ~~`ppsw:patch-duplicate-stuck-tbr-2026may`~~ **命令已於 2026-07-28 移除**（同上） |
| 4 | 受影響 hour bucket SBR 調帳 | `summary_bet_record --vendor=83 --date={SGT} --hour={SGT}` |
| 5 | 修 compare-bet-pipeline 提示文案 | `CompareBetRecordsPipeline.php` 改為 `ppsw:fix-records` |
| 6 | 清 composer 殘留 classmap | `composer dump-autoload -o` |

**特徵簽名（未來偵測）**

```sql
-- bug 1: fix-records win=0 灌錯
SELECT br.id, br.bet, br.win, tbr.win_lose, gr.win
FROM ppsw_bet_records br
JOIN total_bet_records tbr ON tbr.bet_record_id = br.id AND tbr.vendor_id = 83
JOIN ppsw_game_rounds gr ON gr.playSessionID = br.bet_id AND gr.extPlayerID = br.username
WHERE br.win = 0 AND tbr.win_lose = -tbr.bet AND gr.win > 0;

-- bug 2: multi-bet round 有 primary + stuck 兩種 TBR（duplicate）
SELECT br.bet_id, COUNT(DISTINCT br.id) AS br_cnt, COUNT(DISTINCT tbr.id) AS tbr_cnt
FROM ppsw_bet_records br
JOIN total_bet_records tbr ON tbr.bet_record_id = br.id AND tbr.vendor_id = 83
GROUP BY br.bet_id, br.username
HAVING br_cnt > 1 AND tbr_cnt = br_cnt;
-- 應該 tbr_cnt < br_cnt（consolidated），= 表示有重複 stuck TBR
```

**重要備註**

- fix-records 補建 TBR **不會動玩家錢包**（`transactionLogId=0`），所以 incident 補修是「純帳目對齊」，不影響玩家餘額
- 兩支 patch 都是 ad-hoc 一次性、跑完不再需要；保留為事故參考
- 舊版 `ppsw:fix-missing-total-bet-records` codebase 已刪，但**部署時務必確認 prod image 重 build**，否則 OP 仍可跑到舊 binary
- 對齊基準是 BR (callback 真值)，不是 vendor 後台。剩餘 `tbr - vendor 後台` 殘差屬「PP 後台對玩家平手 round 不入帳」系統差，無需修

## 關鍵檔案

| 用途 | 路徑 |
|---|---|
| Controller 主邏輯 | `app/Http/Controllers/External/SingleWallets/PpswController.php` |
| BR status enum | `app/Vendors/PPSW/BetRecordStatusEnum.php` |
| BR Model | `app/Vendors/Models/PpswBetRecord.php` |
| Account Model | `app/Vendors/Models/PpswAccount.php` |
| 統一修正命令 | `app/Console/Commands/Vendor/PragmaticPlay/FixRecords.php` |
| Multi-bet 修復 | `app/Console/Commands/Vendor/PragmaticPlay/BackfillStuckBetRecords.php` |
| DataFeed 抓取 | `app/Console/Commands/Vendor/PragmaticPlay/RetrieveRecords/` |
| 三層管線比對 | `app/Console/Commands/Vendor/PragmaticPlay/CompareBetRecordsPipeline.php` |
| 測試範例 | `tests/Feature/External/SingleWallets/PPSW/PpswMultiBetTest.php` |
| **2026-05 incident patch — win_lose 灌錯** | ~~`app/Console/Commands/Vendor/PragmaticPlay/Patch/FixRecordsOvershoot2026May.php`~~ **已於 2026-07-28 移除**（事故收案，`bet_record_recovery_logs` 留有 VWorld 7 筆執行紀錄；需回溯查 git history） |
| **2026-05 incident patch — 重複 stuck TBR** | ~~`app/Console/Commands/Vendor/PragmaticPlay/Patch/FixDuplicateStuckTbr2026May.php`~~ **已於 2026-07-28 移除**（同上，recovery log 22 筆） |
