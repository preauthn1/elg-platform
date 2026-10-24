# MetaGamingSW（vendor_id=252）

> **卡型：專屬表卡（dedicated）** — BR 一表一 vendor、有 status / valid_bet，可直接 SUM。
> 對接卡。通用 SOP 見 `reconcile-bet-records` skill；本檔聚焦 MTG（Meta Gaming）差異。
> 廠商規格見 `docs/integrations/vendors/MetaGamingSW/api.md`。

## ✅ 必填契約聲明區

- [x] **識別**：`vendor_id=252`、`tname=metagamingsw`→`metagamingsw_bet_records`、`is_single_wallet=1`
- [x] **TBR/SBR time_field**：`payout_time`（已同步至 `config/summary_bet_record.php`）
- [x] **時區**：callback `TranDateTime` 為 UTC，`bet_time`/`payout_time` 直接存不需換算；MTG 後台/`GetDaySummary` 為 UTC+8 日界（見下）
- [x] **金額口徑**：`win` = `win_lose + bet`（settle 時寫入，`Payout` 欄位即含所有派彩，doc 未提及 jackpot 併入一般 win，Jackpot 走獨立 type=J 記錄）
- [x] **三方回核**：`GetTransactionDetails`（單筆報表 URL，非結構化 API）、`GetDaySummary`（整日彙總，UTC+8 日界）；MTG 無逐筆結構化拉單 API（`PullLog` 系列文件標註「僅供資金轉帳錢包整合使用」），逐筆回核僅能靠 `GetRoundDetails`（需已知 RoundId）人工抽查，**無法批次自動化逐筆比對**
- [x] **未結算（pending）判定謂詞**：`status = 0`（`type='N'`）
- [x] **終驗 SQL**：見下方「終驗 SQL」，可直接執行

## 速查表

| 項目 | 值 |
|---|---|
| `vendor_id` | 252 |
| `VendorIdEnum` case | `App\Enums\Vendor\VendorIdEnum::METAGAMINGSW` |
| `vendors.code` | `MetaGamingSW` |
| `vendors.tname`（**BR 表前綴**） | `metagamingsw` → `metagamingsw_bet_records` |
| `vendors.third_party_code` | `MetaGamingSW` |
| `summary_bet_record` time_field | `payout_time` |
| `vendors.is_single_wallet` | `1`（SW） |

## 一行概述

MTG（Meta Gaming）Seamless Wallet（單錢包）；Slot / Table / Arcade 等；2026-07 接入；尚無 incident。

## 取得三方資料（Step 0）

### 可用 API（api.md §2.1）

| API | 用途 | 特性 |
|---|---|---|
| `GetDaySummary` | 整日總額（按 currency 分組） | UTC+8 日界（比照 KissH5 系列同源模板，未實測確認） |
| `GetTransactionDetails` | 單筆 ResultId/TranId 報表連結 | 只回 HTML 頁面 URL，不適合自動化批次比對 |
| `GetRoundDetails` | 單回合詳情（需 RoundId + GameCode） | 逐筆人工抽查用 |
| `GetUnfinishRound` | 玩家未完成回合列表 | 對帳輔助，非逐筆來源 |

### 不可用

- `PullLog` / `PullLogByTimestamp` / `FlagLog` 系列 —— 廠商文件 §1.2／§1.3 明列**僅供資金轉帳錢包（Fund Transfer Wallet）整合**，本 vendor 走 Seamless，不適用。

> ⚠️ **逐筆回核無自動化管道**：與 KissH5SW 同源模板，但 MTG 連 CSV 匯出後台格式都未經實測確認。首次對帳時**務必先跑 Layer 1（GetDaySummary）健檢**，若出現落差再向 MTG 客服索取後台匯出或 `GetRoundDetails` 逐筆核對，勿假設既有 KissH5 對帳工具可直接沿用。

## 時區

| 對象 | 時區 |
|---|---|
| Callback `TranDateTime` | **UTC** |
| `metagamingsw_bet_records.bet_time` / `payout_time` | **UTC**（與 callback 一致，直接存） |
| MTG 後台 / `GetDaySummary` | **UTC+8 日界**（未實測，比照同源模板假設；正式對帳前應向客服確認） |
| `config/summary_bet_record.php` time_field | `payout_time` |

> 對帳換算公式（假設）：MTG「YYYY-MM-DD」整日 = UTC `[YYYY-MM-DD 前一日 16:00, YYYY-MM-DD 16:00)`。**首次對帳務必實測驗證**，不可照抄。

## 欄位 mapping（callback → BR）

| Callback 欄位 | BR 欄位 | 備註 |
|---|---|---|
| `BetId`（Bet/Rollback）| `bet_id` | string，非 int |
| `ResultId`（GameResult）| `reference_no` | settle 後寫入，取代 bet 階段暫存的 `bet_id` |
| `TranId`（CashBonus/Jackpot）| `reference_no` | String(25)，B/J 類型 |
| `RoundId` | `round_id` | 多玩家可能共用同一 RoundId |
| `BetAmount` | `bet` | 需除以 `vendor_infos.rate` |
| `ValidBetAmount` | `valid_bet` | 僅 GameResult 帶入 |
| `Payout` | 併入 `win`（`win_lose + bet`） | Bet 階段 `win=0` |
| `WinLose` | `win_lose` | 可負 |
| `TranDateTime` | `bet_time` / `payout_time` | UTC，直接存 |

## bet_id 處理

- 編碼：MTG 文件未標明長度上限，實測前保守視為可能超過 PHP int 安全整數（同源模板 KissH5SW 曾因 17~18 位大整數以 int 比對誤命中相鄰記錄，`bet_id=<int>` 查詢**務必全程 string cast**）。
- Controller 內所有 `where('bet_id', $betId)` 已用 `$params['BetId']`（validate 為 `string`，非 `integer`）取得，未經 int 轉型，符合此防呆。

## status / 結算流程

| 值 | 語意 |
|---|---|
| 0 | 建立中（Bet 已扣款，未結算） |
| 1 | 結算/派彩（GameResult） |
| 2 | 回滾（Rollback） |

`type` 欄位：`N`=一般注單、`B`=CashBonus、`J`=Jackpot。對帳預設 `type='N' AND status=1`。

**multi-bet：doc 未提及支援同 RoundId 多次 Bet 累加**（§1.5.1 明列「每個遊戲回合包含一個下注、一個遊戲結果或一個下注退款的獨立實例」），本次實作視為 transaction 層級（`Bet`→`BetId` 唯一鍵，`GameResult` 覆寫同一筆）。若日後接入的遊戲類型出現同回合多次 `Bet` 回調（如 live casino），需依 game-integration skill §3.3.1 改為累加寫法並重新設計 migration（見上方 SQL 的 `bet_id` 非 unique 但 `(type,bet_id,payout_time)` 複合唯一）。

## 對齊命令一覽

| 步驟 | 命令 |
|---|---|
| Step 4 補帳（通用） | 先 `summary_bet_record --vendor=252 --date={YYYY-MM-DD} --hour={H} --dry-run` 試跑 → 確認差異後去掉 `--dry-run` 實跑 |

> 尚無 vendor 專屬對帳/修復命令；首次對帳若發現落差，依 `reconcile-bet-records` skill 通用 SOP 處理，逐筆核對走 `GetRoundDetails` 人工抽查（見上方「不可用」節）。

## 終驗 SQL

```sql
-- 單日三表 SUM 對齊（BR=TBR=SBR 才算通過）
-- 三段 ? 時間窗皆帶 UTC（BR/TBR.payout_time、SBR.data_time 均為 UTC）
SELECT 'metagamingsw_bet_records' AS source, COUNT(*) AS cnt,
  SUM(bet) AS sum_bet, SUM(valid_bet) AS sum_valid_bet,
  SUM(win_lose) AS sum_winlose, SUM(jackpot) AS sum_jackpot
FROM metagamingsw_bet_records
WHERE payout_time >= ? AND payout_time < ? AND type = 'N' AND status = 1
UNION ALL
SELECT 'total_bet_records (vendor=252)', COUNT(*),
  SUM(t.bet), SUM(t.valid_bet), SUM(t.win_lose), SUM(t.jackpot)
FROM total_bet_records t
WHERE t.vendor_id = 252
  AND t.payout_time >= ? AND t.payout_time < ?
UNION ALL
SELECT 'summary_bet_records (vendor=252)', COUNT(*),
  NULL AS sum_bet, SUM(bet) AS sum_valid_bet, SUM(win_lose) AS sum_winlose, SUM(jackpot) AS sum_jackpot
FROM summary_bet_records
WHERE vendor_id = 252
  AND data_time >= ? AND data_time < ?;
```

> ✅ **通過條件**：`sum_valid_bet`（SBR 為其 `bet` 欄）/ `sum_winlose` / `sum_jackpot` 須 BR = TBR = SBR 相等；`sum_bet` 僅 BR=TBR。CashBonus（type=B）與 Jackpot（type=J）記錄未計入此表（走各自 `bonus`/`jackpot` 欄，非 `type='N'` 一般投注流水，如需覆核另開 query 移除 `type='N'` 過濾）。

## 已知 incident / 注意事項

- 尚無 incident。首次上線後留意：(1) `bet_id` 是否出現大整數精度問題（同源模板已知坑）；(2) `GetDaySummary` 是否真為 UTC+8 日界（未實測，僅比照假設）。

## 關鍵檔案

| 用途 | 路徑 |
|---|---|
| Callback 入口（getBalance/bet/settle/rollback/bonus/jackpot） | `app/Http/Controllers/External/SingleWallets/MetaGamingSWController.php` |
| Vendor Driver | `app/Vendors/MetaGamingSW/Driver.php` |
| 狀態碼 enum | `app/Vendors/MetaGamingSW/OperatorStatusEnum.php` |
| 帳號 Model | `app/Vendors/Models/MetaGamingSWAccount.php` |
| 注單 Model | `app/Vendors/Models/MetaGamingSWBetRecord.php` |
| 帳號表結構 | `database/migrations/2026_07_14_000001_create_metagamingsw_accounts_table.php` |
| 注單表結構 | `database/migrations/2026_07_14_000002_create_metagamingsw_bet_records_table.php` |
| 廠商規格書 | `docs/integrations/vendors/MetaGamingSW/api.md` |
