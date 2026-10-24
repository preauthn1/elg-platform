# PTSW（vendor_id=144）

> **卡型：專屬表卡（dedicated）** — BR 一表一 vendor、有 status / valid_bet、可直接 SUM。
> 對接卡。通用 SOP 見 `reconcile-bet-records` skill；vendor 細節（差異點、incident）寫在這裡。

## 速查表

| 項目 | 值 |
|---|---|
| `vendor_id` | `144` |
| `VendorIdEnum` case | `App\Enums\Vendor\VendorIdEnum::PTSW` |
| `vendors.name` | `PlayTech SW` |
| `vendors.code` | `ptsw` |
| `vendors.tname`（**BR 表前綴**） | `ptsw` → `ptsw_bet_records` |
| `vendors.third_party_code` | `PTSW` |
| `vendors.is_single_wallet` | `1`（SW） |
| `summary_bet_record` config 註冊 | ✅ **已註冊**（`config/summary_bet_record.php`，time_field=`payout_time`；2026-06 合併衝突誤刪後補回）|
| Phase C（通用 SBR 命令） | ✅ 可用：`summary_bet_record --vendor=144 --date=YYYY-MM-DD --hour=H --dry-run` 先試跑，確認後去 `--dry-run` 實跑 |
| 暫定 BR/TBR `$timeField`（供 Step 2 對齊 SQL） | `payout_time` |

## 一行概述

PlayTech 單錢包（PT Live Casino / Slot）；接入後一直穩定運行；2026-05 處理過 multi-bet placeBet 覆寫 + reconcile refund overshoot incident（root cause 已修，新請求安全）。

## 取得三方資料（Step 0）

- **來源**：PlayTech `/reports/playerGameTransactionReport` API
- **命令**：`php artisan playtech:check_bet_record [--no-cache]`
  - 自動抓三方 daily 報表並與 DB `ptsw_bet_records` 比對
  - 透過 cache 避免重複請求；除錯時用 `--no-cache` 強制重抓
- **找 SBR 缺漏**：`php artisan playtech:find_missing` — 精確定位 `summary_bet_records` 中遺失的記錄（以 hour bucket 為單位）
- **diff 命令**：`php artisan playtech:compare_bet`

## 時區

| 對象 | 時區 |
|---|---|
| 三方 callback `transactionDate` | UTC |
| BR.bet_time | UTC（`Carbon::parse(transactionDate)->toSystemTime()`）|
| BR.payout_time | UTC（settle 時刻）|
| `config/summary_bet_record.php` time_field | `payout_time` |

> 跨日切日邊界：vendor 端 `transactionDate` 與我方 `BR.bet_time`（toSystemTime UTC）在 Taipei 00:00 邊界可能因毫秒級時差歸入不同日。對帳工具顯示「DB 沒找到 / 三方沒找到」訊息時，先對前後一天跑一次確認鏡像，再判定是否為真實漏單。

## 欄位 mapping（三方 → BR）

| 三方欄位 | BR 欄位 | 備註 |
|---|---|---|
| `username` | `username` | 三方玩家帳號 |
| `gameRoundCode` / round id | `bet_id` | live casino 同 round 多次 bet 共用同一 bet_id |
| `transactionCode` | `transaction_code` | 每次 bet/refund/settle 都不同 |
| `gameCode` | `game_code` | |
| `amount`（with rate） | `bet`（**累加**） | `Common::numDiv(abs(amount), rate, 4)`；multi-bet 必須 `Common::numAdd`，禁止覆寫（見 incident §1）|
| 同上 | `valid_bet`（**累加**） | 同 `bet` |
| `win` | `win_lose`（fallback） | **不含 jackpot**；`getWin()` fallback 公式 = `win_lose + bet` |
| `win` 中的 jackpot 部分 | `jackpot` | 獨立欄位，玩家錢包透過 `changeBalance` 中 `jackpotChange` 獨立累加 |
| `transactionDate` | `bet_time` | UTC |
| settle 時刻 | `payout_time` | UTC |

> ⚠️ 對帳腳本比對三方 `win` 必須加回 jackpot：`DB.win = bet + win_lose + jackpot`（見 incident §1.3）。

## bet_id 處理

- 編碼：字串（基於 `gameRoundCode`）
- 大整數陷阱：無（非純數字）
- multi-bet：**是** — 同一 `gameRoundCode` 可有多次 placeBet 回調（21 點 split/保險、百家樂多手）

## status / 結算流程

- enum：`App\Vendors\PlayTechSW\BetRecordStatusEnum`

| Status | 值 | 意義 |
|---|---|---|
| `IN_PROGRESS` | `1` | 進行中（placeBet 後、結算前） |
| `REFUND` | `2` | 退款 |
| `SETTLE` | `3` | 已結算（對帳目標 status）|

- multi-bet：**是**（同 `gameRoundCode` 多次 placeBet 累加）
- deal_records 沖銷模式：**是**（refund 觸發時寫 `ptsw_deal_records` `type='gameRoundResult' bet_amount=-X` 沖銷事件，`applied_at` 永遠 NULL；見 incident §1.2、§5.2）。被 `settlement()` 拒絕的 REFUND 也留同形事件但 raw 帶 `_rejected`，補帳 SQL 與重複退款閘都排除它（§5.2）

## 對齊命令一覽

| 步驟 | 命令 | 用途 |
|---|---|---|
| Step 0 取資料 / 校對 | `playtech:check_bet_record` | 抓三方 daily 報表 + 與 DB 比對 |
| Step 0.5 找漏 | `playtech:find_missing` | 精確定位 SBR 遺失的 hour bucket |
| Step 0.6 diff | `playtech:compare_bet` | 顯示三方 vs DB 落差明細 |
| Step 2 補帳（incident only）| `playtech:reconcile-bet-accumulation` | 用 `ptsw_deal_records` 重建 BR.bet/valid_bet；SQL 含 `type='gameRoundResult'` 沖銷（修復後版本），並排除 raw 帶 `_rejected` 的被拒退款事件（§5.2）|
| Step 2 補丁（incident only）| ~~`playtech:patch-overshoot-refund-2026may`~~ | 修 reconcile 初版對 refund 注單補過頭的 overshoot。**命令已於 2026-07-28 移除**——TPower／VWorld dry-run 皆實測零殘留，Yes2Win 當時已修，其餘三站從未跑過 reconcile |
| Step 4 補帳通用 | `summary_bet_record --vendor=144` | SBR 調帳 |
| 撤回 | `bet-record-recovery:revert --log-ids=...` | 全部補帳走 `bet_record_recovery_logs`，可還原 |
| 已扣款卻永遠結不了的注單退款（incident only）| `bet-record-recovery:refund-unsettled --vendor=ptsw --bet-id-file=<帶表頭 CSV>` | PT 端已作廢、我方結算回滾且 PT 停止重送時用；預設 dry-run，`--execute` 必帶 `--reason`。CSV 表頭 `bet_id,bet_tc,refund_tc,refund_request_id,refund_transaction_date,round_close_date,refund_amount`（PT 原始退款回調值，從 GCP `PTSW Refund:` log 取），退款後 status 1→2、錢包退回、不建 TBR、補一筆 `gameRoundResult` 退款 deal record；含禮碼或已有 `gameRoundResult` 事件的一律跳過；清單另附 `br_id`／`member_id` 時會與命中列核對，不符即 `identity_mismatch` 不退。⚠️ `bet-record-recovery:revert` 尚不支援 type=refund，與 FUMA 先例相同；人工撤回時除了還原 BR status／錢包／transaction_log，還要一併刪掉 recovery log `after._meta.refund_deal_record_id` 指到的那筆 deal record，否則同局會殘留一筆假的退款事件；且 `PlayTechController::settlement()` 的重複退款閘（`target/op_tickets#3089`）把帶 `_recovery` 的退款事件視為整局已退，沒刪掉時 PT 對該局後續合法 REFUND 會被永久回 `ERR_NO_BET` |

## 終驗 SQL

```sql
-- 單日三表 SUM 對齊（含 jackpot；漏比 jackpot 看不到 SBR.jackpot=0 的長期 drift）
SELECT 'ptsw_bet_records' AS source, COUNT(*) AS cnt,
  SUM(bet) AS sum_bet, SUM(valid_bet) AS sum_valid_bet,
  SUM(win_lose) AS sum_winlose, SUM(jackpot) AS sum_jackpot
FROM ptsw_bet_records
WHERE payout_time >= ? AND payout_time < ?
  AND status = 3 -- SETTLE
UNION ALL
SELECT 'total_bet_records (vendor=144)', COUNT(*),
  SUM(t.bet), SUM(t.valid_bet), SUM(t.win_lose), SUM(t.jackpot)
FROM total_bet_records t
JOIN ptsw_bet_records b ON b.id = t.bet_record_id
WHERE t.vendor_id = 144
  AND t.payout_time >= ? AND t.payout_time < ?
UNION ALL
-- ⚠️ summary_bet_records 無 valid_bet 欄；其 bet 欄存的即 valid_bet
--   （SummaryBetRecordService：SUM(total_bet_records.valid_bet) AS bet）。
--   故原始 bet 欄填 NULL、SBR.bet 對齊到 valid_bet 欄比對。
SELECT 'summary_bet_records (vendor=144)', COUNT(*),
  NULL, SUM(bet), SUM(win_lose), SUM(jackpot)
FROM summary_bet_records
WHERE vendor_id = 144
  AND data_time >= ? AND data_time < ?;
```

## jackpot 對帳口徑（vendor 後台 vs SR11 / 我方各層）

**核心公式參見 §欄位 mapping 註腳**（`DB.win = bet + win_lose + jackpot`）。下文補三件 master 既有未涵蓋的細節：bet_time 切口、跨日對沖殘差、SR11 讀取層驗證警告。

### bet_time 切口（依 2026-05-15 case 觀察、未跨多日驗證）

vendor 後台以 bet_time 切日（**推測為 PT 後台慣例**），我方 TBR/SBR 用 payout_time 切日。跨日結算的注單（bet_time D 日 23:59、payout_time D+1 日 00:00）會落不同日，需逐筆抓出補正。

### 殘差解讀

bet_time 切口還原後若仍有小額殘差（5/15 case 約 -8.50），優先級依序為：

1. **跨日 2 筆 wl SUM 進入下一日** — 將前一日 bet_time-late、本日 payout_time-early 的鏡像 row 也納入抵消可進一步收斂
2. PT 後台對「總退款」的內含口徑差
3. commission / rolling_chip 的細項差

先用 (1) 排除大部分殘差；剩餘 < $10 級別可標記「切口微差」不入 drift。

### SR11 對帳的現況（讀取層屬未驗證假設）

⚠️ **SR11 是否真讀 SBR、未在 APIs repo 驗證**（`grep SR11` 在 APIs 內只找到 setting key 與 docblock 字眼、無實際 query）。以下推論建立在「依 OP 經驗 SR11 讀 SBR」前提上、建議搭配後台 owner 確認再對外承諾：

- 若 SR11 確實讀 SBR、且 PTSW SBR.jackpot 全段為 0（見「Known SBR drift」）：SR11 wl 永遠少 jackpot 金額 vs vendor 後台
- ❌ 不可說「PTSW 5/X 對齊」— 永遠差 jackpot 金額
- ✅ 應說「SBR.jackpot 未補；若 SR11 讀 SBR、SR11 wl 與 vendor 後台差額 ≈ 當日 jackpot 金額」並引「Known SBR drift」段
- ✅ 終局解法是補 SBR.jackpot（依 `reconcile-bet-records` skill §Step 0-bis 三選一）— 但若 SR11 實際讀的不是 SBR，補完 SBR 不代表 SR11 跟著對；行動前先確認 SR11 讀取層

### 排查指令（用於回應 OP「為何 SR11 比 vendor 後台少」）

```sql
-- 該日 BR.jackpot vs TBR.jackpot vs SBR.jackpot 對比（payout_time 切口）
SELECT
  (SELECT COALESCE(SUM(jackpot),0) FROM ptsw_bet_records
     WHERE payout_time >= ? AND payout_time < ? AND status=3) AS br_jackpot,
  (SELECT COALESCE(SUM(jackpot),0) FROM total_bet_records
     WHERE vendor_id=144 AND payout_time >= ? AND payout_time < ?) AS tbr_jackpot,
  (SELECT COALESCE(SUM(jackpot),0) FROM summary_bet_records
     WHERE vendor_id=144 AND data_time >= ? AND data_time < ?) AS sbr_jackpot;
-- 期待：br_jackpot = tbr_jackpot = sbr_jackpot（三層 jackpot 欄一致）。
--   該欄存 jackpot.winAmount（中獎派彩）：有中獎日非 0、無中獎日為 0，皆正確，非 drift。
```

## PT 後台 jackpot 三欄口徑（防 OP 抄表比錯欄位）✅（2026-06-29 釐清，帳目正確）

> **2026-06-29 事故結案**：OP 回報 TPower PTSW「win_lose 對不上後台、差 152.54」。深查確認**帳目一直正確**——root cause 是**運維在 PT 後台抄表時比錯欄位**（後台 jackpot 拆多個口徑欄、口徑不同不可互比）。我方 `win_lose` 與後台 `Game Income Share`（玩家輸贏口徑）逐日精確對齊（log + DB + 日報表 xlsx 三重鐵證）。

### 後台日報表 jackpot 拆多欄（口徑不同、不可互比）

PT 後台 `game_stats_by_result_date` 日報表（result date = payout 切口）把 jackpot 拆成多個獨立欄位，**OP 對帳必須認準 `Game Income Share`**：

| 後台欄位 | 口徑 | 我方對應 | 對齊 |
|---|---|---|---|
| **`Game Income Share`** | 玩家淨輸（= 我方 win_lose 口徑） | `\|win_lose\| − jackpot 欄` | ✅ **OP 對帳認準這欄** |
| `Progressive Win` | 累積彩池中獎（玩家收到） | DB `jackpot` 欄（=`jackpot.winAmount`） | ✅ |
| `Progressive Share` | 累積彩池貢獻（純資訊維度，玩家流入彩池） | 我方不追蹤（win_lose 口徑無此需求） | — 非缺口 |
| `Net Gaming` | 平台彩池營收（= Game Income Share − Progressive Share + Progressive Win） | 我方無此口徑 | — |

> ⚠️ **OP 抄表防雷**：`win_lose` 對帳只能比 `Game Income Share`。若抄到 `Net Gaming`、或自行把 `Progressive Share`（彩池貢獻）加進去比，就會「差一個 contribution」——這是**比錯欄位、不是帳錯**。152.54 事故即此。

### 帳目正確的鐵證（TPower 2026-06，日報表 xlsx 權威）

| 日期 | 我方 win_lose | Game Income Share | =\|wl\|−jackpot? | Progressive Win = DB jackpot? |
|---|---:|---:|:---:|:---:|
| 06-21 | -8,814.50 | 8,768.43 | ✅ (8814.50−46.07) | ✅ 46.07 |
| 06-24 | -51,231.01 | 50,927.38 | ✅ (51231.01−303.63) | ✅ 303.63 |
| 06-26 | -2,240.47 | 2,240.47 | ✅ (−0) | ✅ 0 |

→ `Total Wins` / `Game Income Share` / `Progressive Win` 全部逐日對齊。我方 `win_lose`（玩家輸贏）口徑帳目正確。

### contribution 我方不追蹤（非缺口、非 bug）

- 我方 `win_lose` = 玩家輸贏：玩家下注已扣完整 `amount`（含貢獻彩池那部分），玩家確實輸這麼多 → win_lose **本就不該、也不需要扣 contribution**。
- `Progressive Share`（= `jackpot.contributionAmount`）是後台才有的**彩池會計拆分維度**；我方系統 win_lose 口徑無此需求 → `resolveDealAmounts` 只讀 `jackpot.winAmount`、不讀 `contributionAmount`（grep 零命中）是**設計範疇外、非 bug**。
- 三表 BR→TBR→SBR 彙整零 drift（逐筆＋分組驗證）；`check_bet_record` 比 bet/win 也對齊。

### GCP log 驗證後台兩個 Progressive 欄（未來排查工具）

未來 OP 再報「PTSW 對不上」時，**先確認 OP 抄的是哪一欄、認準 `Game Income Share`**（多數是比錯欄位）。若需驗證後台 Progressive 欄來源：撈當日 `route=external/api/sw/pt/gameRoundResult` AND `SEARCH("jackpotContributions")`、去重 `gameRoundCode` 加總三方 `jackpot` 物件欄位（同局值一致 inconsistent=0），可對後台日報表逐欄驗證：

- `Σ jackpot.contributionAmount` = 後台 `Progressive Share`（06-21=188.56154 / 06-24=744.95762 / 06-26=152.5388，到小數第 4 位）
- `Σ jackpot.winAmount` = 後台 `Progressive Win`（06-21=46.07 / 06-24=303.63 / 06-26=0）

走 `target-gcp:gcp-log-troubleshoot`；大 limit 輸出檔案後 shell 內 jq 加總（不灌 context）。

## 已知 incident / 注意事項

- **2026-05 multi-bet placeBet 覆寫 + reconcile overshoot incident**（3 個關聯 bug）— 詳見下方 §2026-05 incident 章節。
  - bug 已於 commit `0e82fe915` / `74fbddb00` / `1bdc7b0ac` 修復
  - 補帳已執行完畢（Yes2Win 246 筆 / TPower 720 筆 / VWorld/WTT 207 筆 / 合計 +69,377.50），新請求安全
  - 未巡檢白標：Alfawin77 / VPlus33 / Me77（待開單）
- **2026-05 settlement transaction_code 空字串撞 unique incident**（Alfawin77 only）— 詳見下方 §2026-05 incident #2 章節
  - bug 已於 commit `53f58601f` (MR #1528) 修復
  - 補帳已執行完畢（Alfawin77 2 筆 1,700）
- **白標間 schema 不對齊（schema drift）**：`ptsw_deal_records.transaction_code` unique 約束在 Alfawin77/VPlus33/Me77 有、VWorld/TPower/Yes2Win 無。同份 migration 在不同環境結果不同，未來新 bug 排查時若「同 controller code 但只有部分白標出問題」優先懷疑 schema drift（詳見 §2026-05 incident #2 §1.3）
- **2026-07 重複回調 race → over-count incident**（無 unique 白標）：同一 placeBet `transaction_code` 被 vendor 併發重送時，冪等機制（`findExistingDealRecord` + `bet()` 的 `applied_at` exists 檢查）是「先 SELECT 再寫」無鎖無 unique，race 下兩筆都通過 → deal_record 重複寫入、BR/TBR `bet` 多累加。**與 incident #2 是同一 schema drift 的兩面**：有 unique 白標 → 空字串撞 unique **stuck**；無 unique 白標 → 重複正常 tc **over-count**。詳見下方 §2026-07 incident #3 章節
- **跨日切日邊界**：跨 Taipei 00:00 時對帳工具偶有 ±1 筆毫秒誤差落入相鄰日，先看前後一天鏡像再判定漏單
- **jackpot 雙重計算保護**：`PtswBetRecord::getWin()` fallback **不含 jackpot**（避免錢包雙重扣加），但對帳工具須用 `bet + win_lose + jackpot` 對齊三方 `win`
- **被拒下注會留下「幻影 BR」**：`bet()` 在交易內先 `firstOrNewBetRecord` 建列、再做餘額檢查，餘額不足回拒時該列已 commit（`status=1`、`bet=0`、`bet_time=NULL`，對應 `ptsw_deal_records` 的 `bet` 事件 `applied_at=NULL`）。這類列是常態（2026-09 實測近 9 萬筆區間有 144 列），**盤點未結算殘留時要排除 `bet=0 AND bet_time IS NULL`**，別當卡單補。
- **2026-09-05 REFUND 對幻影 BR 入帳 incident**（`target/op_tickets#3079`）：TPower DDoS 期間 5 筆下注被拒（幻影 BR）但 PT 端逾時送 REFUND，`settlement()` 只查 BR 存在就走 `changeBalance`，由 bet 0 → −X 算出 +X 入帳，3 位會員多得 253.00。修正＝REFUND 前以 `pay.relatedTransactionCode` 驗同局同帳號的 `bet` deal record 已 applied，未扣款即回 `ERR_NO_BET`、不動錢包；**未帶 `relatedTransactionCode` 一律拒絕並告警**（協定必帶、三站生產 390 筆全帶）。測試 `PtswRefundUnappliedBetTest`。事故列在 BR 呈 `status=2`、`bet` 負值、不進 TBR，三表對帳看不到，要從 `wallet_balance_changed` log 或 `bet<0 AND status=2` 掃。

### jackpot 欄狀態（2026-05-27 起追蹤；2026-06-29 結案：正常、非 drift）

> ⚠️ **更正**：先前（2026-06-23）記載「`summary_bet_records.jackpot` 全段為 0、根因在上游 BR/TBR 層 jackpot 即為 0」**是誤判**——2026-06-23 那次碰巧該區間無人中獎才全 0。2026-06-24 實測 BR=TBR=SBR `jackpot` = 303.63，逐層對齊後台 `Progressive Win`，jackpot 欄（winAmount）一直正常運作。

| 項目 | 狀態 |
|---|---|
| `jackpot` 欄（winAmount / Progressive Win） | ✅ **正常運作、非 drift**：有中獎日正確寫入並 BR→TBR→SBR 逐層對齊後台（2026-06-24 = 303.63）；無中獎日為 0 即正確 |
| `Progressive Share`（彩池貢獻） | 我方不追蹤（win_lose 口徑無此需求）；**非缺口**，見上方「PT 後台 jackpot 三欄口徑」段 |
| 帳目狀態 | ✅ **正確**：我方 win_lose 對齊後台 `Game Income Share`；2026-06-29「差 152.54」事故結案，root cause = OP 後台抄表比錯欄位 |

## 關鍵檔案

| 用途 | 路徑 |
|---|---|
| Controller 主邏輯 | `app/Http/Controllers/External/SingleWallets/PlayTechController.php` |
| Driver | `app/Vendors/PlayTechSW/Driver.php` |
| BR Model | `app/Vendors/Models/PtswBetRecord.php` |
| Account Model | `app/Vendors/Models/PtswAccount.php` |
| Deal Records Model | `app/Vendors/Models/PtswDealRecord.php` |
| Status Enum | `app/Vendors/PlayTechSW/BetRecordStatusEnum.php` |
| 日級對帳命令 | `app/Console/Commands/Vendor/PlayTech/CheckDailyBetRecord.php` |
| SBR 漏記查詢 | `app/Console/Commands/Vendor/PlayTech/FindMissingBetRecords.php` |
| 三方 diff 比對 | `app/Console/Commands/Vendor/PlayTech/CompareBetRecordsDifference.php` |
| Reconcile（incident）| `app/Console/Commands/Vendor/PlayTech/ReconcileBetAccumulation.php` |
| Overshoot patch（incident）| ~~`app/Console/Commands/Vendor/PlayTech/Patch/FixOvershootRefund2026May.php`~~ **已於 2026-07-28 移除**（事故收案；需回溯查 git history） |
| Multi-bet 測試 | `tests/Feature/External/SingleWallets/PTSW/PtswMultiBetTest.php` |
| Settlement no-pay 測試 | `tests/Feature/External/SingleWallets/PTSW/PtswSettlementNoPayTest.php` |
| Reconcile 測試 | `tests/Feature/Console/Commands/Vendor/PlayTech/ReconcileBetAccumulationTest.php` |

---

# 2026-05 incident：multi-bet placeBet 覆寫 + reconcile overshoot

> 本節為歷史 incident 善後紀錄。bug 已於 commit `0e82fe915`（controller）+ `74fbddb00`（reconcile SQL）+ `1bdc7b0ac`（check_bet_record）修復；新請求安全。
> 通用對帳 SOP 見 `reconcile-bet-records` skill。

## ⚠️ 開工前必看：三個獨立但會疊加的 bug

| Bug | 影響 | 修復 commit | 補帳命令 |
|---|---|---|---|
| (A) **placeBet 覆寫**（`PlayTechController` L300-301） | 同 `gameRoundCode` 多次 bet 回調時，BR.bet/valid_bet 被覆寫為最後一手，turnover 偏低 | `0e82fe915` | `playtech:reconcile-bet-accumulation` |
| (B) **reconcile refund overshoot**（`ReconcileBetAccumulation` 初版 SQL）| 修 (A) 的補帳命令只算 `type='bet'` 漏算 `type='gameRoundResult'` 負值沖銷，對 refund 注單把 BR.bet 補成 placeBet 金額（過頭） | `74fbddb00` | ~~`playtech:patch-overshoot-refund-2026may`~~（已移除，見上） |
| (C) **check_bet_record win 公式不含 jackpot**（`CheckDailyBetRecord` L518） | DB win = `bet + win_lose` 不含 jackpot，三方 API `win` 含 jackpot → 對帳腳本顯示派彩差但非真實漏記 | `1bdc7b0ac` | （對帳工具修正，無補帳） |

**(A) 與 (B) 是順序產生的 incident**：修 (A) bug → 寫 reconcile 命令 → 暴露 (B) → 寫 patch 命令修 overshoot。
補帳順序必須 `reconcile-bet-accumulation` → `patch-overshoot-refund-2026may` → SBR 調帳。

## 1. Bug 詳細說明

### 1.1 (A) placeBet 覆寫

`PlayTechController::bet()` 在 `placeBet` 階段：

```php
// ❌ 修復前（L300-301）
$betRecord->bet       = $betAmount;
$betRecord->valid_bet = $betAmount;
```

PT live casino（21 點 split/保險、百家樂多手）允許同一 `gameRoundCode` 多次 placeBet 回調，每次帶不同 `transactionCode`。覆寫造成前 N-1 手金額丟失。

**錢包扣減安全性**：`PtswBetRecord::getWin() fallback = win_lose + bet`，`changeBalance` 公式化簡為 `winLoseChange + chipPaidChange`，**與 bet 累加大小無關** — 玩家錢包正確扣減，僅 BR.bet 偏低。

**修法**（commit `0e82fe915`）：

```php
// ✅ 修復後
$betRecord->bet       = Common::numAdd($betRecord->bet, $betAmount, 4);
$betRecord->valid_bet = Common::numAdd($betRecord->valid_bet, $betAmount, 4);
```

### 1.2 (B) reconcile refund overshoot

修復 (A) 寫的 `playtech:reconcile-bet-accumulation` 命令初版用 deal_records 重建 BR.bet：

```php
// ❌ 修復前
DB::table('ptsw_deal_records')
    ->where('type', 'bet')
    ->whereNotNull('applied_at')
    ->groupBy('bet_id')
    ->select('bet_id', DB::raw('SUM(bet_amount) AS expected_bet'));
```

對 refund 注單：placeBet 寫 `type='bet' bet_amount=+X`，refund 觸發時 `createDealRecord('gameRoundResult', ...)` 寫 `type='gameRoundResult' bet_amount=-X` 作為沖銷事件（**`applied_at` 永遠 NULL**，settle 流程沒更新此欄位）。

只算 `type='bet'` 漏算負值沖銷 → BR.bet 已被 settle 階段累加 -X 沖銷為 0、但 reconcile 補成 placeBet 金額（過頭）。

**修法**（commit `74fbddb00`）：

```php
// ✅ 修復後 — 含 refund 沖銷事件、各 type 用各自時間欄位
->where(function ($q) use ($utcStart, $utcEnd) {
    $q->where(function ($q2) use ($utcStart, $utcEnd) {
        $q2->where('type', 'bet')
            ->whereNotNull('applied_at')
            ->where('applied_at', '>=', $utcStart)
            ->where('applied_at', '<', $utcEnd);
    })->orWhere(function ($q2) use ($utcStart, $utcEnd) {
        $q2->where('type', 'gameRoundResult')
            ->where('created_at', '>=', $utcStart)
            ->where('created_at', '<', $utcEnd);
    });
})
```

> 上面是 `74fbddb00` 當時的修法快照。**現行版**的 `gameRoundResult` 分支另含 `whereNull('raw')->orWhere('raw', 'not like', '%"\_rejected"%')` 排除被拒退款事件（`target/op_tickets#3089` 尾巴），照抄本段會把被拒事件算進 SUM；以 `app/Console/Commands/Vendor/PlayTech/ReconcileBetAccumulation.php` 與 §5.2 為準。

### 1.3 (C) check_bet_record win 公式不含 jackpot

`CheckDailyBetRecord::getBetRecords` L518 把 DB win 算為 `bet + win_lose`，未含 jackpot。三方 API `win` 含 jackpot → 比對顯示派彩差 = jackpot 金額，但**玩家錢包透過 `changeBalance` 中 `jackpotChange` 獨立累加**，玩家權益完整。

**修法**（commit `1bdc7b0ac`）：

```php
$dbWin = Common::numAdd(
    Common::numAdd((string) $item->bet, (string) $item->win_lose, 4),
    (string) ($item->jackpot ?? '0'),
    2,
);
```

## 2. 修復狀態

| 階段 | Commit | 內容 |
|---|---|---|
| Controller fix | `0e82fe915` | placeBet 改 `Common::numAdd` 累加；加 `PtswMultiBetTest` 把關 |
| Reconcile 命令 | `7df056fb4` | `playtech:reconcile-bet-accumulation` 含 4 個 feature test |
| Reconcile SQL 修 refund + patch 命令 | `74fbddb00` | SQL 含 `type='gameRoundResult'` 沖銷；當時新增的 ~~`playtech:patch-overshoot-refund-2026may`~~ 已於 2026-07-28 移除 |
| Skill 記錄 | `77783cb26` | `game-integration` §3.3.1/3.3.2、`reconcile-bet-records` §Step 2.0 |
| check_bet_record jackpot | `1bdc7b0ac` | DB win 公式補入 jackpot |

**新請求安全**：placeBet 累加運作中（commit 已部署），所有歷史補帳記錄在 `bet_record_recovery_logs`（type=recover, source=`ptsw-multibet-accumulation-fix-2026-05` 或 `ptsw-overshoot-refund-fix-2026-05`），可用 `bet-record-recovery:revert --log-ids=...` 撤回。

## 3. 已執行補帳記錄（截至 2026-05-27）

### 3.1 Yes2Win

| 階段 | 影響 BR | 補帳金額 | recovery_log source |
|---|---:|---:|---|
| `reconcile-bet-accumulation` 5/1~5/12 | 246 | +9,966.50 | `ptsw-multibet-accumulation-fix-2026-05` |
| `patch-overshoot-refund-2026may` 5/6~5/8 | 7 | -24.50（回退） | `ptsw-overshoot-refund-fix-2026-05` |
| **淨補帳** | 246 | **+9,932.00** | |

跨 26 個 hour bucket / 5 個 Taipei 日（5/6, 5/7, 5/8, 5/9, 5/12）；SBR 調帳 5 次。

### 3.2 TPower

| 階段 | 影響 BR | 補帳金額 | 備註 |
|---|---:|---:|---|
| `reconcile-bet-accumulation` 5/1~5/12 | 720 | +33,569.00 | 用 `--max-bet-diff=10000`（單筆最大 +2,800） |
| `patch-overshoot-refund-2026may` | 0 | — | 無 overshoot（reconcile SQL 已修正） |
| **淨補帳** | 720 | **+33,569.00** | |

跨 53 個 hour bucket / 6 個 Taipei 日（5/7~5/12）；SBR 調帳 6 次。

### 3.3 VWorld（WTT，2026-05-27 執行）

| 階段 | 影響 BR | 補帳金額 | 備註 |
|---|---:|---:|---|
| `reconcile-bet-accumulation` 5/9~5/12 | 207 | +25,876.50 | 篩 `vendor_info_id=615`（WTT, agent=wintoto）|
| `patch-overshoot-refund-2026may` | 0 | — | 無 overshoot（reconcile SQL 已修正） |
| **淨補帳** | 207 | **+25,876.50** | |

跨 17 個 hour bucket / 4 個 Taipei 日（5/9~5/12）；SBR 調帳 4 次（18 筆 correction row）。受影響 PT 帳號：3 位（OWTMY_00000031 / 00000032 / 00000023）。

執行前發現 `config/summary_bet_record.php` 在 commit `ade2f0d93` 已把 PTSW 從通用 SBR 排程移除（設計收斂為「其他遊戲商以各自專屬補帳服務處理」），但 PT 專屬服務尚未實作。為跑 SBR 調帳，本次**臨時把 PTSW 加回 config**；後續需決定 (a) 正式恢復 PTSW 走通用 SBR 排程、(b) 實作 PT 專屬服務。

### 3.4 與 vendor 後台對齊

| 白標 | vendor 後台合計差 | 我方補帳合計 | 對齊精度 |
|---|---:|---:|---|
| Yes2Win 5/6~5/9 | 8,932 | 8,932 | 完美 ✓ |
| TPower 5/7~5/11 | 32,599 | 32,589 | 0.03%（10 元為跨日切日效應）|
| VWorld/WTT 5/9~5/12 | 25,876.50 | 25,876.50 | 完美 ✓ |

## 4. 補帳流程（未來再發生時參考）

> 前提：controller fix（commit `0e82fe915`）已部署到 prod。

```bash
# Step 1: dry-run reconcile（看規模）
php artisan playtech:reconcile-bet-accumulation \
  --from=2026-05-01 --to=2026-05-13 --detail-limit=20
# 若有 skip：單筆 > max-bet-diff，看是否合理放寬 --max-bet-diff

# Step 2: execute reconcile
php artisan playtech:reconcile-bet-accumulation \
  --from=2026-05-01 --to=2026-05-13 \
  --max-bet-diff=10000 \
  --execute --reason="..."

# Step 3 / 4（overshoot patch）已於 2026-07-28 隨命令移除，不再需要：
#   原命令 playtech:patch-overshoot-refund-2026may 用途＝修 reconcile 初版 SQL 對 refund
#   注單補過頭。該 SQL 已於 74fbddb00 補入 type='gameRoundResult' 沖銷，現行 reconcile
#   不會 overshoot；2026-07-28 於 TPower / VWorld 實測 dry-run 皆「沒有 overshoot 注單」。
#   要回溯實作：git log --all -- app/Console/Commands/Vendor/PlayTech/Patch/

# Step 5: SBR 調帳（依命令輸出的 hour bucket list；先 --dry-run 確認窗口再實跑）
#   ⚠️ 缺 --hour 會預設整個台北日（UTC+8）刪重建，務必逐小時指定
for d in 受影響日; do
  for h in 受影響小時; do
    php artisan summary_bet_record --vendor=144 --date=$d --hour=$h --dry-run
    # 確認差異筆數/窗口無誤後，去掉 --dry-run 實跑
  done
done

# Step 6: 驗證
# - 三表 SUM 對齊：BR(payout_time) = TBR(payout_time) = SBR(data_time)
# - 對 vendor API truth：php artisan playtech:check_bet_record --no-cache（每日）
# - 對 vendor 後台：依 SR11 報表或 OP 提供數字比對
```

## 5. 經驗教訓（給新 multi-bet vendor 接入用）

### 5.1 placeBet 必須累加

**檢核清單**：

- [ ] placeBet 階段 BR.bet / BR.valid_bet 用 `Common::numAdd` 累加，不是 `=`
- [ ] 新建注單時 BR.bet=0、BR.valid_bet=0（讓累加從 0 開始）
- [ ] 必須有 `{Name}MultiBetTest` 測試：同 round 連續兩次 bet 後 BR.bet 為兩次之和
- [ ] settle 階段建 TBR 時 valid_bet 取最終累加值

### 5.2 deal_records 沖銷模式

| type | bet_amount | applied_at | 對 BR.bet 的影響 |
|---|---|---|---|
| `bet` | +placeBet | 真實時刻 | 加 |
| `gameRoundResult`（refund 沖銷）| -placeBet | NULL | 減 |
| `gameRoundResult`（被拒 refund，raw 帶 `_rejected`）| -placeBet | NULL | 不變（錢包與 BR 都沒動，SQL 必須排除）|
| `gameRoundResult`（close round）| 0 | NULL | 不變 |

**寫補帳 SQL 必須含 `type='gameRoundResult'`**，且時間 filter 對 `gameRoundResult` 用 `created_at`（`applied_at` 永遠 NULL），並排除 raw 帶 `_rejected` 的被拒事件（`raw NOT LIKE '%"\_rejected"%'`；標記由 `PlayTechController::rejectRefund()` 在四道 `ERR_NO_BET` 分支寫入，記 `code`／`reason`）。

### 5.3 jackpot 與 win 的關係

- PT 三方 API `win` 欄位 **含 jackpot**
- `PtswBetRecord::getWin()` fallback = `win_lose + bet` **不含 jackpot**（避免錢包雙重計算，jackpot 在 `changeBalance` 中經 `jackpotChange` 獨立處理）
- 對帳腳本比對三方 win 必須加回 jackpot：`bet + win_lose + jackpot`

### 5.4 跨日切日邊界

vendor 端 `transactionDate` 與我方 `BR.bet_time`（toSystemTime UTC）在 Taipei 00:00 邊界可能因毫秒級時差歸入不同日。對帳工具顯示「DB 沒找到 / 三方沒找到」訊息時，先對前後一天跑一次確認鏡像，再判定是否為真實漏單。

## 6. 撤回路徑（必要時）

所有補帳走 `bet_record_recovery_logs`，可全部撤回：

```bash
# 撤回 reconcile-bet-accumulation 的 246/720 筆
php artisan bet-record-recovery:revert \
  --log-ids=<csv> \
  --execute --reason="..."

# 或從 source 撈出對應 log ids 後撤回
# SELECT id FROM bet_record_recovery_logs
# WHERE vendor_id=144
#   AND source='ptsw-multibet-accumulation-fix-2026-05'
#   AND type='recover';
```

撤回後 SBR 需再次調帳。

## 7. References

| 用途 | 路徑 |
|---|---|
| Controller 累加修正 | `app/Http/Controllers/External/SingleWallets/PlayTechController.php` 的 `bet()`（`bet`／`valid_bet` 累加處） |
| Controller multi-bet test | `tests/Feature/External/SingleWallets/PTSW/PtswMultiBetTest.php` |
| Reconcile 命令 | `app/Console/Commands/Vendor/PlayTech/ReconcileBetAccumulation.php` |
| Reconcile feature test | `tests/Feature/Console/Commands/Vendor/PlayTech/ReconcileBetAccumulationTest.php` |
| Overshoot patch 命令 | ~~`app/Console/Commands/Vendor/PlayTech/Patch/FixOvershootRefund2026May.php`~~ 已移除（`12b729961`），要看寫法查 git history |
| check_bet_record jackpot 修正 | `app/Console/Commands/Vendor/PlayTech/CheckDailyBetRecord.php`（比對時補回 `jackpot` 那段） |
| `createDealRecord` REFUND 路徑 | `app/Http/Controllers/External/SingleWallets/PlayTechController.php` 的 `createDealRecord()`／`resolveSettlementAmounts()` |
| 通用 multi-bet 規則 | `game-integration` skill §3.3.1 |
| 通用 deal_records 沖銷規則 | `reconcile-bet-records` skill Step 2.0 |
| user memory：refund deal_records 結構 | `reference_ptsw_refund_deal_records` |

---

# 2026-05 incident #2：settlement transaction_code 空字串撞 unique 衝突（Alfawin77）

> 本節為歷史 incident 善後紀錄。bug 已於 commit `53f58601f`（MR #1528）修復；新請求安全。
> 通用對帳 SOP 見 `reconcile-bet-records` skill。

## ⚠️ 開工前必看

| Bug | 影響 | 修復 commit | 補帳路徑 |
|---|---|---|---|
| `createDealRecord` gameRoundResult 階段、無 `pay` 欄位時 `$transactionCode=''` 寫入 `ptsw_deal_records` 撞 `transaction_code_unique` 拋 1062（`PlayTechController` L610） | settle transaction rollback 致 BR 永遠卡 `IN_PROGRESS`、TBR 不建立、玩家錢包已扣但訂單不結算 | `53f58601f` | _local 腳本逐筆 force settle（玩家錢包不動）+ recovery_logs |

**白標暴露差異**（schema drift）：

| 白標 | unique 約束 | 是否觸發 bug |
|---|:---:|---|
| Alfawin77 / VPlus33 / Me77 | ✅ 有 | ✅ 第二筆無 pay settle 後 stuck |
| VWorld / TPower / Yes2Win | ❌ 無 | ❌ 空字串可累積（最高 23 萬筆）、settle 不會 fail |

## 1. Bug 詳細說明

### 1.1 觸發路徑

Vendor 在「玩家輸光的 slot round 關閉」會送 `external/api/sw/pt/settlement` callback，payload **不帶 `pay` 欄位**（無資金支付）：

```json
{
  "requestId": "...",
  "username": "AL7A_00000017",
  "gameRoundCode": "12701532818#f9353e4e",
  "jackpot": { "winAmount": "0", "contributionAmount": "2" },
  "gameRoundClose": { "date": "2026-05-17 14:51:24.000" }
}
```

`PlayTechController::createDealRecord('gameRoundResult', $params)`：

```php
// L495
$transactionCode = '';  // 預設

// L502-530: gameRoundResult 分支
if ($action === 'gameRoundResult') {
    if (isset($params['pay'])) {   // ← 無 pay，跳過
        // ...
        $transactionCode = $params['pay']['transactionCode'];
    }
    // $transactionCode 仍是 ''
}

// L610（修復前）
'transaction_code' => $transactionCode ?? null,  // '' ?? null = ''（空字串不是 null）
```

寫入空字串 → 撞 `ptsw_deal_records_transaction_code_unique` → 1062 → settle DB::transaction rollback → BR 不推進。

### 1.2 修法（commit `53f58601f`）

```php
// ✅ 修復後
'transaction_code' => $transactionCode ?: null,  // '' → null
```

MySQL unique 允許多筆 NULL；schema 本來就 `nullable+unique`，**不需 migration**。

### 1.3 為何只在部分白標出問題（schema drift）

`2025_06_19_091321_create_ptsw_deal_records_table.php` migration 宣告 `nullable()->unique()`，但**實際執行結果各環境不同**：

| 白標 | `ptsw_deal_records.transaction_code` 實際 schema | 推測原因 |
|---|---|---|
| Alfawin77 / VPlus33 / Me77 | `nullable + unique` ✅（與 migration 一致） | migration 正確執行 |
| VWorld / TPower / Yes2Win | `nullable`（無 unique） | 可能手動 ALTER 跳過、或 migration 失敗未補跑 |

**教訓**：未來排查「同 controller code 但只有部分白標出問題」時優先懷疑 schema drift，跑 `SHOW INDEXES FROM <table>` 跨白標對比。

## 2. 補帳記錄

### 2.1 Alfawin77（2026-05-18 執行）

| br_id | bet_id | bet | gameRoundClose.date | status before → after | recovery_log id |
|---:|---|---:|---|---|---:|
| 829 | `12693116122#f9353e4e` | 1,200 | 2026-05-17 07:01:13 | 1 → 3 | 1 |
| 830 | `12701532818#f9353e4e` | 500 | 2026-05-17 14:51:24 | 1 → 3 | 2 |
| **合計** | | **1,700** | | | |

執行細節：
- source = `alphawin77-ptsw-settle-callback-bug-2026-05`
- performed_by = `ai-claude-ptsw-stuck-fix`
- balance_diff = 0（玩家錢包早於 placeBet 階段扣減，補帳時不動）
- 處置 = 強制結算（輸）— 三方 daily report status=closed, win=0, refund=0
- SBR 調帳：`summary_bet_record --vendor=144 --date=2026-05-17` 寫入 2 筆 correction
- 三表 SUM 對齊：BR=TBR=SBR=1,700 ✅

### 2.2 其他白標（未受影響）

VWorld / TPower / Yes2Win 因無 unique 約束、本 bug 不觸發 stuck。三者各自的 IN_PROGRESS BR（29 / 566 / 401 筆，sum_bet 約 4,800）成因另論，本 incident 不處理。

VPlus33 / Me77 PTSW 截至 2026-05-18 尚無投注紀錄，無補帳需求。

## 3. 補帳流程（未來再發生時參考）

> 前提：controller fix（`53f58601f`）已部署。本流程僅處置歷史 stuck，不防止新 stuck。

```bash
# Step 0: 確認影響範圍
docker exec -e DB_CONNECTION=<白標> php-fpm8 bash -c "cd /var/www/APIs && php artisan tinker --execute '
\$stuck = DB::select(\"SELECT id, bet_id, username, bet, bet_time FROM ptsw_bet_records WHERE status = 1 ORDER BY bet_time\");
foreach (\$stuck as \$b) print_r(\$b);
'"

# Step 1: 取 vendor 端真相（GKE log 或 daily report）
#   - 從 Sentry / GCP logging 找對應 br_id 的 settlement callback payload
#   - 取 gameRoundClose.date（vendor 真實 close 時刻）
#   - 確認 vendor status=closed / win=0 / refund=0（force settle as loss 適用）

# Step 2: 比照 reference/script-templates/ptsw_alphawin77_stuck_fix_dryrun.php 寫補帳腳本
#   （複製範本到 APIs 根目錄下的 _local/<script>.php 再改寫；deal_records 補建另見 ptsw_alphawin77_deal_records_backfill.php）
#   - 每筆 BR 進 DB::transaction
#   - 鎖 BR、冪等檢查 BetRecordRecoveryLog::isCurrentlyRecovered
#   - BR.status: 1 → 3、payout_time: NULL → gameRoundClose.date
#   - 直接 INSERT TBR（不走 TotalBetRecordService，避免觸發大額通知）
#   - BetRecordRecoveryLog::create（含 member_id! schema 必填）
#   - balance_diff = 0（錢包不動）
#   - source = '<白標>-ptsw-settle-callback-bug-<YYYY-MM>'

# Step 3: dry-run → execute → 驗 BR/TBR
docker exec -e DB_CONNECTION=<白標> php-fpm8 bash -c "cd /var/www/APIs && php _local/<script>.php"
docker exec -e DB_CONNECTION=<白標> php-fpm8 bash -c "cd /var/www/APIs && php _local/<script>.php --execute"

# Step 4: SBR 調帳
docker exec -e DB_CONNECTION=<白標> php-fpm8 bash -c "cd /var/www/APIs && php artisan summary_bet_record --vendor=144 --date=<YYYY-MM-DD>"

# Step 5: 三表對齊驗證
#   BR(status=3, payout_time 範圍) ↔ TBR(vendor_id=144, payout_time) ↔ SBR(vendor_id=144, data_time)
#   correction_rows 應 = 受影響 hour bucket 數
```

## 4. 經驗教訓

### 4.1 `??` vs `?:` 對空字串的差異

```php
$x = '';
$x ?? 'default'  // 結果：''（?? 只接 null）
$x ?: 'default'  // 結果：'default'（?: 接 falsy 含空字串）
```

對「DB 欄位 nullable+unique」的場景，**寫 null 要用 `?:` 不是 `??`**。否則空字串會成為 unique 殺手。

### 4.2 schema drift 是隱形地雷

不同環境跑同一份 migration 可能得到不同結果（手動 ALTER / migration 失敗未補 / online schema change 工具中斷）。controller bug 與 schema drift **相互掩蓋**時尤其危險：

- 有 unique → 1062、外顯為 stuck（容易發現）
- 無 unique → 髒資料累積、長期不發現

**對策**：跨白標稽核命令（待開發）週期性對比 `SHOW CREATE TABLE` 結果，及早發現漂移。

### 4.3 vendor retry 不一定有效

vendor 端對 settlement callback 會 retry（log 觀察到同 round 兩次以上不同 requestId），但若 root cause 是 DB 約束、retry 也會撞同樣的錯。不要依賴 vendor retry 補救我方錯誤。

### 4.4 `BetRecordRecoveryLog` schema 必填 `member_id`

寫補帳腳本時記得帶 `member_id`，這欄位 NOT NULL 沒 default（`reconcile-bet-records` skill §3.1 未列出，2026-05 補帳一度卡在 1364 錯誤；後續 `reconcile-bet-records` skill 待補）。

## 5. References

| 用途 | 路徑 |
|---|---|
| Controller fix | `app/Http/Controllers/External/SingleWallets/PlayTechController.php` 的 `resolveSettlementOutcome()` |
| Feature test | `tests/Feature/External/SingleWallets/PTSW/PtswSettlementNoPayTest.php` |
| 補帳腳本範本 | `reference/script-templates/ptsw_alphawin77_stuck_fix_dryrun.php`（stuck 修復）、`ptsw_alphawin77_deal_records_backfill.php`（deal_records 補建）；皆一次性、已執行，複製到 `_local/` 改寫後用 |
| 撤回路徑 | `php artisan bet-record-recovery:revert --log-ids=1,2 --execute --reason="..."` |
| MR | http://example.invalid:1688/target/gffv3/apis/-/merge_requests/1528 |

---

# 2026-07 incident #3：重複回調 race → over-count（無 unique 白標）

> 本節為對帳善後 + 根因紀錄。**controller 冪等 race 尚未修**（見 §4 代碼優化建議，待開 MR）；歷史多算資料逐筆修復。

## ⚠️ 開工前必看

| Bug | 影響 | 根因 | 修復狀態 |
|---|---|---|---|
| 同一 placeBet `transaction_code` 被 vendor 併發重送，冪等機制 race 兩筆都通過 → `ptsw_deal_records` 重複寫入 `type='bet'` 正值 → BR/TBR `bet`/`valid_bet` 多累加、`win_lose` 多算 | 我方 turnover 虛高（over-count）；single wallet 玩家錢包因差額式 + 該局淨 0 未受影響（其他情境未必） | 冪等為「先 SELECT 再寫」、無鎖無 unique；無 `transaction_code` unique 的白標（VWorld/TPower/Yes2Win）重複 INSERT 成功 | 歷史資料逐筆修；controller 根因待修（§4） |

## 1. 根因：冪等是 read-then-write，缺鎖與 unique 兜底

`PlayTechController::bet()` 有兩層冪等，**兩層都是「先查存在→再寫」、無鎖無 DB 約束兜底**：

1. `createDealRecord()` → `findExistingDealRecord()`（L602）：查 `requestId+bet_id` / `bet_id+transaction_code` 是否已有 deal_record，命中即回既有（`wasRecentlyCreated=false`、L237 提前返回不累加）。
2. `bet()` L247-252：查 `bet_id + transaction_code + applied_at IS NOT NULL` 是否 `exists()`，命中則 `Log::warning('PTSW 重複交易碼')` 跳過累加。

**race 時序**（vendor 兩次不同 `requestId` 併發重送同一 placeBet tc）：

```
請求 A: findExistingDealRecord 查 bet_id+tc → 無(B 未 commit) → create dr_A
請求 B: findExistingDealRecord 查 bet_id+tc → 無(A 未 commit) → create dr_B
請求 A: L247 查 applied_at exists → 無 → 累加 +10、標記 dr_A.applied_at
請求 B: L247 查 applied_at exists → 無(A 未 commit) → 累加 +10、標記 dr_B.applied_at
```

兩個 `DB::transaction` 併發、SELECT 都在對方 INSERT commit 前執行（REPEATABLE READ 看不到對方未提交列）→ 都沒命中 → 都累加。**缺的是「同 bet_id 序列化」或「DB unique 兜底」**。

## 2. 與 incident #2 是同一 schema drift 的兩面

| 白標 | `ptsw_deal_records.transaction_code` unique | 併發重複 tc 的結果 |
|---|:---:|---|
| Alfawin77 / VPlus33 / Me77 | ✅ 有 | 第二筆 INSERT 撞 1062 → settle rollback → **stuck（incident #2）** |
| VWorld / TPower / Yes2Win | ❌ 無 | 第二筆 INSERT 成功 → **over-count（incident #3）** |

→ 同一份 controller code，schema drift 讓兩組白標各自暴露不同 bug。

## 3. 已修案例（Yes2Win，2026-07-03）

| 項目 | 值 |
|---|---|
| bet_id | `live_15005829224569#a96ce0aa`（member_id=44173 Y2WMY_00000315，game_id=12598） |
| 重複 deal_record | `ptsw_deal_records` id 10924225（正本）/ **10924226（重複，已刪）**，同 tc `28029436421`、各 `+10` |
| 三方真值 | placeBet 50+10 全沖銷（gameRoundResult -50 -10）淨投注 = 0 |
| 修正前 | BR 5095680 / TBR 837492070 = bet/valid_bet/win_lose `10/10/-10` |
| 修正後 | `0/0/0`；玩家錢包 `balance_diff=0`（single wallet 該局 transaction_logs 無真金流水） |
| 定位方法 | `playtech:check_bet_record --no-cache`（bet_time 切）投注額差 -10、逐筆單 1 筆；修正後差 0 |
| SBR 調帳 | `summary_bet_record --vendor=144 --date=2026-07-03 --hour=8`（1 筆 correction） |
| 修復腳本 | `_local/fix_ptsw_yes2win_dup_callback_5095680.php`（含連線 gate、冪等、去重防呆、audit 存檔；**不走通用 recovery_logs**，屬 Type B） |

### ⚠️ SBR 調帳的時區陷阱（跨 Taipei 日界注單）

此注單 `payout_time` DB 裸值 = `2026-07-03 00:00:23`（落在 Taipei 日界）。SBR 命令 `--date/--hour` 是 **UTC+8 輸入、內部轉 UTC 查 payout_time**：

- ❌ `--hour=0`（台北 07-03 00:00 → 內部 UTC 07-02 16:00 窗）→ **撈不到此注單**、dry-run 0 筆（踩坑點）
- ✅ `--hour=8`（→ 內部 UTC 07-03 00:00 窗）→ 命中、correction 1 筆

判 bucket 時以 `compare_bet` 逐小時表的差異 label（＝ payout_time 裸值 hour）為準，換算成 `--hour = (label hour − 8 + 24) mod 24`，勿直接用台北日界的 hour=0。

## 4. 代碼優化建議（根治）

逐筆修只清歷史；**根因在 controller 冪等無兜底**。建議（詳見對話分析）：

> **實作進度**（2026-07-08）：下列 #2（1062 catch re-select）與 #3（同 bet_id 序列化）已於分支 `fix/ptsw-callback-race-guard` 實作完成（MySQL named lock 序列化 bet()/settlement()、createDealRecord 補 1062 catch），待開 MR 合 master。**#1（複合 unique）刻意分階段、本次未做**——需先清各白標歷史重複，另立 backstop 工單（見下方 P1-2 約束）。

1. **補 DB unique 兜底 + 統一各白標 schema**：`ptsw_deal_records` 加 `(bet_id, transaction_code)` 複合 unique（`transaction_code` nullable、多筆 NULL 不衝突，避開 incident #2 空字串問題——該問題已由 `?: null` 修）。這是防 race 漏網的最後防線。
2. **`create` 改 race-safe**：`createDealRecord` 的 INSERT 用 `insertOrIgnore` 或 catch `QueryException` 1062 → 重新 SELECT 既有列返回（視為冪等重試），**不 rollback 整個 bet/settle**（避免重蹈 incident #2 的 stuck）。
3. **（替代/補強）同 bet_id 序列化**：把 `oldBetRecord` 的 `lockForUpdate`（L262）提前到 `createDealRecord` 之前、或對 `bet_id` 上 advisory lock，讓同局併發回調序列化，第二個進來時第一個已 commit、`findExistingDealRecord` 即命中。

> unique 是雙刃劍（incident #2 曾因它 stuck），故 (1) 必須搭配 (2) 的優雅衝突處理；(1)+(2) 才能既防 over-count 又不 stuck。

> **⚠️ P1-2 — backstop（#1 加 unique）階段的實作約束**（timo review 2026-07-08）：#2 的 1062 catch → `findExistingDealRecord` re-select 目前在 `DB::transaction` 的 **REPEATABLE READ 快照內**執行。在現行 named lock 序列化下安全（競爭者已 commit 才進 transaction，快照必看得到其列）。但一旦 #1 的 unique 讓 1062 在**鎖保護外**觸發（如 lock key 計算不一致、或跨 username 同 `(bet_id, transaction_code)` 極端情形），re-select 可能因快照時點早於競爭者 commit 而返回 `null` → `throw $e` → transaction rollback → INTERNAL_ERROR，**1062 死路變靜默吞錯**。→ **backstop 工單必須在該 re-select 處改用 READ COMMITTED 或加 `lockForUpdate()`**，勿只加 unique 就了事。

## 5. References

| 用途 | 路徑 |
|---|---|
| 冪等 race 點 | `app/Http/Controllers/External/SingleWallets/PlayTechController.php` 的 `hasAppliedDealTransaction()`（applied_at exists 檢查）、`findExistingDealRecord()`、`createDealRecord()`（INSERT） |
| 錢包差額式 | `app/Http/Controllers/External/SingleWallets/BaseController.php` 的 `changeBalance()`／`increaseWalletBalance()` |
| 本次修復腳本 | `_local/fix_ptsw_yes2win_dup_callback_5095680.php` + audit `_local/fix_ptsw_yes2win_5095680_audit.json` |
