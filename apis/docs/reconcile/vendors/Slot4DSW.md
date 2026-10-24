# Slot4DSW（vendor_id=254）

> **卡型：專屬表卡（dedicated）** — BR 一表一 vendor、有 status / valid_bet、可直接 SUM。
> 對接卡。通用 SOP 見 `reconcile-bet-records` skill；本檔聚焦 Slot4D 差異。
> 與 **KissH5SW（233）/ MegaH5SW 同一套三方協議**（opgateway seamless：GameLogin/Bet/GameResult/Rollback/CashBonus/Jackpot/BetNSettle，MD5 簽名），對帳性質高度可比照 `KissH5SW.md`。
> 📌 2026-07 接入中，尚未上線；標註「待實測」的段落於首次對帳時回填。

## ✅ 必填契約聲明區

- [x] **識別**：vendor_id=254、tname=slot4dsw → `slot4dsw_bet_records`、is_single_wallet=1
- [x] **TBR/SBR `time_field`**：`payout_time`（須與 `config/summary_bet_record.php` 同步，改 config 要回補本卡）
- [x] **時區 + UTC 換算**：見「時區」
- [x] **金額口徑**：見「欄位 mapping」；win 不含 jackpot（jackpot 為獨立 type=J 列）
- [x] **三方回核**：GetDaySummary API + 後台逐筆 CSV（見「取得三方資料」；CSV 欄位待實測）
- [x] **未結算（pending）判定謂詞**：`type='N' AND status=0 AND payout_time IS NULL`
- [x] **終驗 SQL**：見「終驗 SQL」（可執行內聯版）
- [x] **BR 有效投注篩選謂詞**：`type='N' AND status=1`（無額外排除；status=2 回滾列 bet 已歸 0 天然排除）
- [x] **BR `time_field`**：`payout_time`（與 TBR 同切口）
- [x] **status enum 全集**：見「status / 結算流程」
- [x] **Phase C 可用性**：**已註冊**於 `config/summary_bet_record.php`（254 → SLOT4DSW / payout_time）

## 速查表

| 項目 | 值 |
|---|---|
| `vendor_id` | 254 |
| `VendorIdEnum` case | `App\Enums\Vendor\VendorIdEnum::SLOT4DSW` |
| `vendors.code` | SLOT4DSW |
| `vendors.tname`（**BR 表前綴**） | `slot4dsw` → `slot4dsw_bet_records` |
| `vendors.third_party_code` | SLOT4DSW |
| `summary_bet_record` time_field | `payout_time` |
| `vendors.is_single_wallet` | `1` (SW) |

## 一行概述

Slot4D Seamless Wallet（單錢包）；slot（聚合平台）；2026-07 接入中；未上線。

## 取得三方資料（Step 0）

- **來源**（依 api.md，同 KissH5 協議，實際可用性待實測）：
  - `GetDaySummary`（整日總額，按 currency 分組）— 同協議經驗：即便傳 UTC `Date`，內部仍以 SGT 日界計算
  - `GetTransactionDetails`（單筆 ResultId 報表 URL）— 同協議經驗：只回 HTML，不適合自動化
  - `GetRoundDetails`（單回合詳情，需 RoundId + GameCode）
  - 後台逐筆 CSV 匯出（`https://example.invalid/`）— **欄位待實測**；同協議陷阱：xlsx 或 xlsx 轉存 CSV 會把 17 位 BetId 末位四捨五入，**務必取直出 CSV**
- **預期不可用**（同協議 KissH5 實測）：`PullLog` / `PullLogByTimestamp` / `FlagLog` 系列 — 文檔標明「exclusively for fund transfer wallet」，SW 打了只會 900500。
- **命令**：尚無 vendor 專屬命令；Layer 1 健檢可照抄 `KissH5SW.md` 的 GetDaySummary tinker 範本（改 VendorIdEnum::SLOT4DSW 與表名）。

## 時區

| 對象 | 時區 |
|---|---|
| Callback `TranDateTime` | **UTC** |
| `slot4dsw_bet_records.bet_time` / `payout_time` | **UTC**（直存 callback 值，不換算） |
| Slot4D 後台 / CSV / `GetDaySummary`（`ProviderTimeZone` = Singapore Standard Time） | **SGT (UTC+8) 日界** |
| `config/summary_bet_record.php` time_field | `payout_time` |

**對帳換算公式**：

```
Slot4D「2026-08-01」整日 = UTC [2026-07-31 16:00, 2026-08-01 16:00)
```

> 同協議教訓：拿 UTC 整日對個別玩家會出 8 小時錯位假差異（日總和互相抵銷看似對齊）。個別玩家對帳必須換算。

## 欄位 mapping（callback → BR）

| callback 欄位 | BR 欄位 | 備註 |
|---|---|---|
| `BetId` | `bet_id` | type=N；B 列存 `BonusId`、J 列存 `JackpotId` |
| `ResultId` | `reference_no` | settle 後寫入（bet 階段暫存 BetId）；B/J 列存 `TranId` |
| `RoundId` | `round_id` | |
| `BetAmount / rate` | `bet` | 入庫前除以 `vendor_infos.rate` |
| `ValidBetAmount / rate` | `valid_bet` | |
| `WinLose / rate + bet` | `win` | **win = win_lose + bet**；不含 jackpot |
| `WinLose / rate` | `win_lose` | 可為負 |
| `Payout / rate` | `bonus`（type=B）/ `jackpot`（type=J） | CashBonus / Jackpot 為獨立 BR 列，bet=0 |
| `TranDateTime` | `bet_time` / `payout_time` | UTC 直存 |

三方 CSV → BR 欄位對應**待實測**後補（預期同 KissH5：`Round Id`→bet_id、`Tran ID`→reference_no、`Tran Date` SGT 需轉 UTC）。

## bet_id 處理（重要）

- **編碼**：同協議預期 17~18 位整數（待實測確認位數）
- **大整數陷阱**：PHP int ≥ 2^53 走 float 比對會命中相鄰記錄（KissH5 2026-05 精度 bug 實錘，詳見 `KissH5SW.md` §incident）
- **修復狀態**：`Slot4DSWController` 複製自**已修復版** KissH5Controller，所有查詢點（bet/settle/rollback/bonus/jackpot/betNSettle/cancelBetNSettle）進 where 前均已 `(string)` cast，**接入起點即安全**
- **對帳紀律**：所有 `bet_id` / `reference_no` / SQL 比對全程 string，禁止 int/float 參與

## status / 結算流程

| 值 | 語意 |
|---|---|
| 0 | 建立中（bet 已扣款未結算；孤兒可能性） |
| 1 | 已結算 / 派彩（GameResult / BetNSettle / Bonus / Jackpot） |
| 2 | 回滾（Rollback；bet/valid_bet/win/win_lose 歸 0） |

`type`：`N` 一般注單、`B` CashBonus、`J` Jackpot。對帳預設 `type='N' AND status=1`。

**multi-bet：否**（transaction 層級，一 round 一 BetId；同協議 KissH5 亦同。若實測發現同 RoundId 多 BetId，回頭改本段並補 MultiBetTest）。

## 對齊命令一覽

| 步驟 | 命令 |
|---|---|
| Layer 1 健檢 | GetDaySummary tinker（照抄 `KissH5SW.md` 範本改 254 / slot4dsw） |
| Step 4 補帳（通用） | 先 `summary_bet_record --vendor=254 --date={YYYY-MM-DD} --hour={H} --dry-run` → 確認差異後去掉 `--dry-run` 實跑 |

> ⚠️ **Step 4 命令安全規範（金流寫入）**
> - `--date` / `--hour` 輸入為 **UTC+8（台北）**，命令內部自動轉 UTC；下命令前先換算窗口。
> - **禁止裸跑** `summary_bet_record --vendor=254`（預設打「今天整天」，歷史對帳會打錯窗口）。
> - 命令按 (vendor, 小時) **刪除重建** SBR、已關帳時段寫 correction 列；**務必先 `--dry-run`**。

## 終驗 SQL

```sql
-- 單日三表 SUM 對齊（BR=TBR=SBR 才算通過）
-- Slot4D SGT 日 {D} = UTC [{D-1} 16:00, {D} 16:00)；三段時間窗皆帶 UTC
SELECT 'slot4dsw_bet_records' AS source, COUNT(*) AS cnt,
  SUM(bet) AS sum_bet, SUM(valid_bet) AS sum_valid_bet,
  SUM(win_lose) AS sum_winlose, SUM(jackpot) AS sum_jackpot
FROM slot4dsw_bet_records
WHERE payout_time >= ? AND payout_time < ? AND status = 1
UNION ALL
SELECT 'total_bet_records (vendor=254)', COUNT(*),
  SUM(t.bet), SUM(t.valid_bet), SUM(t.win_lose), SUM(t.jackpot)
-- ★ TBR 全量 by vendor_id + 時窗，不 JOIN BR（INNER JOIN 會掩蓋孤兒 TBR）
FROM total_bet_records t
WHERE t.vendor_id = 254
  AND t.payout_time >= ? AND t.payout_time < ?
UNION ALL
-- ⚠️ SBR 無 valid_bet 欄；其 bet 欄即「有效投注」（SUM(TBR.valid_bet) AS bet）
SELECT 'summary_bet_records (vendor=254)', COUNT(*),
  NULL AS sum_bet, SUM(bet) AS sum_valid_bet, SUM(win_lose) AS sum_winlose, SUM(jackpot) AS sum_jackpot
FROM summary_bet_records
WHERE vendor_id = 254
  AND data_time >= ? AND data_time < ?;
```

> ✅ **通過條件**：`sum_valid_bet` / `sum_winlose` / `sum_jackpot` 須 **BR = TBR = SBR** 相等；`sum_bet` 僅 BR=TBR。任一層不等即未通過。跑完 Step 4 後務必回此 SQL 複驗 SBR 列。
> 註：BR 層含 status=1 的 B/J 列（bet=0），與 TBR 對齊口徑一致（bonus/jackpot 皆有寫 TBR）。

## 已知 incident / 注意事項

- 尚未上線，無 incident。
- **繼承同協議的三個預防**：bet_id 全程 string（起點已修）、SGT 日界換算、PullLog 不可用勿嘗試。
- 回調來源 IP 白名單（api.md §1.1.1）：UAT `0.0.0.0`；PROD `0.0.0.0` / `0.0.0.0` / `0.0.0.0`。對不到帳先確認 IP 白名單沒擋回調。

## 關鍵檔案

| 用途 | 路徑 |
|---|---|
| Callback 入口（8 回調） | `app/Http/Controllers/External/SingleWallets/Slot4DSWController.php` |
| Driver | `app/Vendors/Slot4DSW/Driver.php` |
| 運營商狀態碼 | `app/Vendors/Slot4DSW/OperatorStatusEnum.php` |
| 注單 Model（含時區提醒） | `app/Vendors/Models/Slot4dswBetRecord.php` |
| 注單表結構 | `database/migrations/2026_07_17_000001_create_slot4dsw_tables.php` |
| 三方規格 SSOT | `docs/integrations/vendors/Slot4DSW/api.md` |
| 同協議參考卡 | `docs/reconcile/vendors/KissH5SW.md` |
