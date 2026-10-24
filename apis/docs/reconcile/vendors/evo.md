# EVO aggregator 共用表對帳卡

> **卡型：aggregator 共用表卡** — 一表掛多 vendor（60+）、靠 `vendor_info_id` 歸戶、BR 不能直接 SUM。範本見 `_template-aggregator.md`。
> 通用 SOP 見 `reconcile-bet-records` skill；本卡記 **EVO aggregator 共用表 `evo_bet_records` 的對帳處理規範**——`tname='evo'` 由旗下 60+ vendor（`vendor_id` 57、63-167…）共用同一張 BR 表，無法直接 `SUM` 歸戶。下方速查表與實測以 **Rich Gaming（`vendor_id=98`）** 為範例；其他 EVO vendor 將 `vendor_id` / `code` 換成對應值即可套用同一套對齊流程。

## 速查表（範例：Rich Gaming）

| 項目 | 值 |
|---|---|
| `vendor_id` | 98 |
| `VendorIdEnum` case | `App\Enums\Vendor\VendorIdEnum::TITAN_RG` |
| `vendors.code` | `rg` |
| `vendors.tname`（BR 表前綴） | `evo` → **`evo_bet_records`（共用表，見下節）** |
| `vendors.third_party_code` | `EVO` |
| `summary_bet_record` time_field | `bet_time`（在 config 的 Titan（EVO）聚合區塊內，`schedule_enabled=false`＝不進整點排程，只供手動補跑） |
| `vendors.is_single_wallet` | `0`（轉帳錢包） |

## 一行概述

轉帳錢包；EVO aggregator 旗下的 Titan 系列遊戲商（real-money slots）；BR / TBR 對齊由 `SP_transfer_bet_record_evo` 排程處理。

## ⚠️ EVO aggregator 共用表（最重要）

`tname='evo'` 並非單一遊戲商，而是 **EVO aggregator 共用一張 `evo_bet_records` 表的 60+ vendor 集合**（vendor_id 57, 63-167…），其中 Rich Gaming = 98。`third_party_code='EVO'` 在 vendors 表上是 **多筆同值**。

**對齊單一 vendor（如 98）絕對不能直接 `SUM(evo_bet_records)`**，必須透過 `evo_accounts.vendor_info_id → vendor_infos.vendor_id` 反查篩出該 vendor 的注單；TBR 因為已寫入 `vendor_id` 欄位，可直接用 `where vendor_id=98` 篩。

### 共用表篩 vendor 的 SQL 範本

```sql
-- BR 層：依 vendor_id 篩
SELECT COUNT(*) AS cnt,
       ROUND(SUM(b.bet), 4)       AS sum_bet,
       ROUND(SUM(b.win_lose), 4)  AS sum_winlose
FROM evo_bet_records b
JOIN evo_accounts   a  ON a.username = b.username AND a.member_id > 0
JOIN vendor_infos   vi ON vi.id = a.vendor_info_id
WHERE vi.vendor_id = 98
  AND b.bet_time >= ? AND b.bet_time < ?
  AND JSON_UNQUOTE(JSON_EXTRACT(example.invalid, '$.type')) != '0';  -- 排除非結算交易（SP 同步排除）

-- TBR 層：直接用 vendor_id 篩
SELECT COUNT(*) AS cnt,
       ROUND(SUM(bet), 4)       AS sum_bet,
       ROUND(SUM(valid_bet), 4) AS sum_valid_bet,
       ROUND(SUM(win_lose), 4)  AS sum_winlose
FROM total_bet_records
WHERE vendor_id = 98
  AND bet_time >= ? AND bet_time < ?;
```

## 取得三方資料（Step 0）

- **來源**：Rich Gaming 後台手動匯出 CSV
- **命令**：無自動化命令
- **手動匯出步驟**：登入後台 → Win/Lose Report → 選日期（SGT）→ 匯出 CSV
- **檔名範例**：`WINLOSEREPORT_VWMY_RG_{YYYYMMDD}.csv`
- **CSV header**：`UserId, LoginId, Role, AgentId, AgentLoginId, Currency, Turnover, Bet, Win, Winlose, JPShare, JPWin, Pt`
- **限制 / 注意**：
  - CSV 為 **per-player 彙總**（一玩家一行），**非逐筆注單**；只能對 SUM 與筆數歸戶，無法逐單比對
  - 不含 `bet_id`，要逐單比對需另外從 EVO API / DataFeed 抓
  - 不同白標檔名前綴不同（VWMY、TPower…）

## 時區

| 對象 | 時區 |
|---|---|
| 後台 CSV / Win-Lose Report | **SGT (+8)** |
| `evo_bet_records.bet_time` | **UTC** |
| TBR `bet_time` / `payout_time` | UTC（SP 直接帶 BR.bet_time） |

> SGT 整日換算公式：`{date} 00:00 SGT = 前一日 16:00:00 UTC`，至 `{date} 16:00:00 UTC`（exclusive）

## 欄位 mapping

### CSV（三方）→ TBR

| CSV 欄位 | TBR 欄位 | 備註 |
|---|---|---|
| `Bet` | `SUM(bet)` 對齊用 | 三方視角投注額 |
| `Winlose` | `-SUM(win_lose)` | 兩者符號相反、絕對值相等。**TBR `win_lose` 是玩家視角**（見下方「視角」註），故 CSV `Winlose` 應為公司視角 |
| `Turnover` | （未存） | 通常 = Bet，僅參考 |
| `LoginId` | `member` 反查 | 需透過 `evo_accounts.username` |

### BR `evo_bet_records` 欄位

```
id, bet_id, username, game_code, bet_time(UTC), bet, win_lose, info(json), created_at, updated_at
```

- **無 `status`** — BR 寫入即為結算狀態
- **無 `payout_time`** — TBR 的 payout_time 由 SP 直接帶 `bet_time`
- **無 `valid_bet`** — TBR 的 valid_bet 由 SP 帶 `IFNULL(b.bet, 0)`

### info JSON 結構（節錄）

```json
{
  "id": "...",
  "roundId": "...",
  "startDate": "ISO8601 UTC",
  "endDate":   "ISO8601 UTC",
  "agentId":   "...",
  "userId":    "...",
  "loginId":   "...",
  "gameProviderCode": 24,
  "gameCode":  "22092",
  "type": 2,            // ★ type=0 為非結算交易，SP 與對齊 SQL 一律排除
  "startBalance": ...,
  "endBalance":   ...,
  "deposit": 0,
  "turnover": 8,
  "bet": 8,
  "win": -4.4,
  "winlose": 3.6        // 公司視角；BR.win_lose 即取自此
}
```

## bet_id 處理

- 編碼：MongoDB ObjectId 風格 24 字元 hex（例：`69fe0880d854da5761f509b6`）
- 大整數陷阱：無（純字串）
- 唯一索引：`evo_bet_records_bet_id_unique`

## status / 結算流程

- **無 status enum**（BR 寫入即已結算）
- multi-bet：否
- 退款：由 vendor 寫入 `info.type=0` 的記錄表示（**SP 排除**，故 TBR 不含退款）

## 對齊命令一覽

| 步驟 | 命令 / 動作 |
|---|---|
| Step 0 取資料 | 後台手動下載 CSV |
| Step 1 校對（SUM） | 本卡「終驗 SQL」 |
| Step 2 修正（單筆） | **無自動化命令**；需手動 INSERT/UPDATE BR + TBR + recovery_logs |
| BR → TBR 同步 | `CALL SP_transfer_bet_record_evo(?, ?, ?);`（排程自動執行，必要時可手動重算） |
| Step 4 補帳 | 可手動：先 `summary_bet_record --vendor=98 --date=... --dry-run` 試跑（apis 從 TBR 以 `bet_time` 彙總） |

> 整點 SBR 仍由舊系統寫（`target/tickets#4303` 註冊時全部設 `schedule_enabled=false`，整點排程留在舊專案）；apis 的 `summary_bet_record --vendor=98` 只用在手動補跑。

## 終驗 SQL

對齊一日（SGT）三方 vs BR vs TBR：

```sql
SET @vid = 98;
SET @utc_start = '{D-1} 16:00:00';  -- SGT {date} 00:00 = UTC {date-1} 16:00
SET @utc_end   = '{D}   16:00:00';

-- BR 層（vendor 篩）
SELECT 'BR (evo→vendor=98)' AS layer, COUNT(*) AS cnt,
       ROUND(SUM(b.bet), 4) AS sum_bet,
       ROUND(SUM(b.win_lose), 4) AS sum_win_lose
FROM evo_bet_records b
JOIN evo_accounts   a  ON a.username = b.username AND a.member_id > 0
JOIN vendor_infos   vi ON vi.id = a.vendor_info_id
WHERE vi.vendor_id = @vid
  AND b.bet_time >= @utc_start AND b.bet_time < @utc_end
  AND JSON_UNQUOTE(JSON_EXTRACT(example.invalid, '$.type')) != '0'

UNION ALL

-- TBR 層
SELECT 'TBR (vendor_id=98)', COUNT(*),
       ROUND(SUM(bet), 4), ROUND(SUM(win_lose), 4)
FROM total_bet_records
WHERE vendor_id = @vid
  AND bet_time >= @utc_start AND bet_time < @utc_end

UNION ALL

-- SBR 層（summary_bet_records；其 bet 欄即 valid_bet，evo valid_bet=bet 故可直接對齊 BR/TBR 的 bet）
SELECT 'SBR (vendor_id=98)', COUNT(*),
       ROUND(SUM(bet), 4), ROUND(SUM(win_lose), 4)
FROM summary_bet_records
WHERE vendor_id = @vid
  AND data_time >= @utc_start AND data_time < @utc_end;
```

> 三表通過：BR = TBR = SBR 的 `sum_bet`（evo `valid_bet=bet`）與 `sum_win_lose` 完全相等（jackpot evo 恆 0、不列入）。

對齊判讀：

| 指標 | CSV | BR | TBR | 結論 |
|---|---|---|---|---|
| `SUM(Bet)` | 完全相等 | 完全相等 | 完全相等 | ✅ |
| `SUM(win_lose)` 公司視角 | `-SUM(CSV.Winlose)` | 完全相等 | 完全相等 | ✅ |
| 筆數 | CSV=玩家數，BR/TBR=注單數 | BR=TBR | — | 筆數不必相等（CSV 為彙總） |

## 已知 incident / 注意事項

- **EVO aggregator 共用表是最常見坑點**：直接 `SUM(evo_bet_records)` 會把全部 60+ vendor 的注單加起來，常見 SUM 偏大 400× 級別。
- **CSV 是 per-player 彙總**：不能逐單比對 `bet_id`；若需逐單需另抓 EVO API。
- **CSV Winlose ↔ BR.win_lose 符號相反**：對齊時必須加負號。**視角**：`BR/TBR.win_lose` 是**玩家視角**（玩家贏為正），與全平台一致，見 [`docs/knowledge/decisions/0001-win-lose-uses-player-perspective.md`](../../knowledge/decisions/0001-win-lose-uses-player-perspective.md)。2026-09 對生產庫 `evo_bet_records` 查證：玩家輸光的注單記 `win_lose = -bet` 共 6,488 筆，反向（`= +bet`）僅 315 筆。⚠️ 本檔原本寫「BR/TBR 是公司視角」，與資料不符，已更正；CSV 那一側未直接查證，但既然兩者符號相反，CSV 的 `Winlose` 應為公司視角。加負號的對齊公式不受影響。
- **SP 排除 `info.type=0`**：對齊 SQL 也必須排除，否則 BR cnt 會多出非結算交易。
- **vendor 98 的 apis 補跑只限手動**：config 已註冊但 `schedule_enabled=false`，整點 SBR 由舊系統寫；用 apis 補跑前先 `--dry-run`。

## 首次對齊紀錄

- **2026-05-09 SGT** — 後台 5,523.6700 ↔ TBR 5,523.6700 ↔ BR 5,523.6700 ↔ SBR(`data_time`) 5,523.6700（3,700 筆 / 19 玩家）全對齊 ✅；CSV Winlose +1,056.34 ↔ BR/TBR/SBR win_lose -1,056.34（符號相反，絕對值相等）。
- 同日 SR11 對帳表顯示 5,525.17 / -1,023.84，差異 +1.5 / +32.5 = 一筆 correction 列（SBR id=334792479，原始 `data_time=2026-05-09 12:00 UTC`、`summary_time=2026-05-10 16:00 UTC`、`bet=-1.5 / win_lose=-32.5 / record_count=-1`）。原因見下節「SR11 vs SBR 切口差異」。

## SR11 vs SBR 切口差異

通用規則見 `reconcile-bet-records` skill §5.8「外部對帳工具切口（SR11 等）vs 內部三表對齊」。Rich Gaming 不需特殊處理；2026-05-09 的 +1.5 / +32.5 差額即為單筆跨日 correction 造成（見上節「首次對齊紀錄」）。

## 關鍵檔案

| 用途 | 路徑 |
|---|---|
| BR Model | `app/Vendors/Models/EvoBetRecord.php` |
| Driver（共用 EVO aggregator） | `app/Vendors/Evo/Driver.php` |
| BR→TBR SP | `database/procedures/SP_transfer_bet_record_evo.sql`（另有 `SP_transfer_bet_record_evo2.sql`） |
| VendorIdEnum case | `App\Enums\Vendor\VendorIdEnum::TITAN_RG` |
