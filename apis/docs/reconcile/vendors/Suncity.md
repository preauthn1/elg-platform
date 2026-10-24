# SunCity（vendor_id=292）

> **卡型：專屬表卡（dedicated）** — BR 表一表一 vendor、可直接 SUM、有 status / payout_time / valid_bet。
> 通用 SOP 見 `reconcile-bet-records` skill；vendor 細節（差異點）寫在這裡。

## ✅ 必填契約聲明區

- [x] **識別**：`vendor_id=292`、`suncity` → `suncity_bet_records`、`is_single_wallet=0`
- [x] **TBR/SBR `time_field`**：`payout_time`（須與 `config/summary_bet_record.php` 的 time_field 同步，改 config 要回補本卡）
- [x] **時區 + UTC 換算**：見「時區」
- [x] **金額口徑**：見「欄位 mapping」；`valid_bet = AvailTotalBet`、`win_lose = WinAmount − BetCoin`；無 jackpot／bonus（三方注單無此欄，BR 未建該欄）
- [x] **三方回核**：`game/log/record` API（拉單同一支），見「取得三方資料」/「欄位 mapping」/「對齊命令一覽」
- [x] **未結算（pending）判定謂詞**：`status <> 1`——拉單只入已結算的結果單，理論上恆為零筆；出現即代表有人手動寫入或拉單邏輯被改，須追查
- [x] **終驗 SQL**：見「終驗 SQL」
- [x] **BR 有效投注篩選謂詞**：`status = 1`（無附加排除條件）
- [x] **BR `time_field`**：`payout_time`（與 TBR 同切口；本 vendor `bet_time = payout_time` 同值）
- [x] **status enum 全集**：`0` 建立中 / `1` 已結算 / `2` 取消（標準 schema；本 vendor 實際只會寫 `1`）
- [x] **Phase C 可用性**：已註冊於 `config/summary_bet_record.php`（time_field=payout_time、schedule_enabled 預設 true），通用 `summary_bet_record` 命令可用

## 速查表

| 項目 | 值 |
|---|---|
| `vendor_id` | 292 |
| `VendorIdEnum` case | `App\Enums\Vendor\VendorIdEnum::SUNCITY` |
| `vendors.code` | SUNCITY |
| `vendors.tname`（**BR 表前綴**） | `suncity` → `suncity_bet_records` |
| `vendors.third_party_code` | SUNCITY |
| `summary_bet_record` time_field | `payout_time` |
| `vendors.is_single_wallet` | `0` (TW) |

## 一行概述

轉帳錢包；iGaming 平台 Clubsuncity 品牌（規格同 Play8oy vendor 30）；2026-09 接入（target/tickets#4434，VW2 站 / MYR）；接入中。注單由 APIs `fetch_bet_record` 排程每 5 分鐘拉取（Play8oy 由舊系統拉取，與本 vendor 不同）。

## 取得三方資料（Step 0）

- **來源**：`POST {API_URL}game/log/record`（header `merchantName` + `Sign`，簽名見 `docs/integrations/vendors/Suncity/api.md`）
- **命令**：`fetch_bet_record --vendor=292 --direct --dry-run --start={yyyy-MM-dd@HH} --end={yyyy-MM-dd@HH}`——只打三方與 DB 比對、不寫入，列出將新增／金額異動的注單（即三方回核）；確認後去掉 `--dry-run` 補拉
- **限制 / 注意**：
  - 單次查詢 `StartDate`／`EndDate` **同一日、≤ 600 秒**（Driver 以 10 分鐘切片，跨 UTC+8 午夜自動拆兩段）
  - 頻率：`game/log/record` 每分鐘 40 次；Driver rate limit 設 20 次/60 秒（框架每片計 1 次，但跨日拆段與翻頁會讓一片打出多個請求，故對折）；整天窗口 144 片，乾跑約 7～8 分鐘
  - 三方資料延遲約 20 分鐘（文件建議取 20 分鐘前的資料），回核當下最近 20 分鐘的差異不算 drift
  - 當日查詢的 EndDate 不可 ≥ 三方伺服器現在時間（`-50003`）：Driver 把窗口尾端夾到「現在 − 120 秒」，最近 2 分鐘不會被拉、由下一輪排程補上（見 `docs/integrations/vendors/Suncity/integration.md`）
  - 三方以 IP 白名單限制請求

## 時區

| 對象 | 時區 |
|---|---|
| 三方資料（`CreateTime`、查詢參數 `StartDate`/`EndDate`） | **UTC+8**（`2026-09-29T11:46:36.047`） |
| BR.bet_time | UTC |
| BR.payout_time | UTC |
| `config/summary_bet_record.php` time_field | `payout_time` |

> 對帳換算公式：`UTC = CreateTime − 8h`；vendor「{date}」整日（UTC+8）= UTC `[{date−1} 16:00, {date} 16:00)`。

## 欄位 mapping（三方 → BR）

| 三方欄位 | BR 欄位 | 備註 |
|---|---|---|
| `GameSerialID` | `bet_id`（unique，冪等鍵） | 大整數，`(string)` 轉字串；BR 無 reference_no 欄，TBR.reference_no = bet_id |
| `UserName` | `username` | = `suncity_accounts.username`（含 `hero9stg_` 前綴），歸戶鍵 |
| `ThemeID` | `game_code` | 取前四碼 `intdiv(ThemeID, 10000)`（如 `57060001` → `5706`），對應 `games.game_type` |
| `BetCoin` | `bet` | 除以 `vendor_infos.rate`（VW2 為 1） |
| `AvailTotalBet` | `valid_bet` | 除以 rate |
| `WinAmount` | `win` | 除以 rate；不含 jackpot（三方無 jackpot 欄） |
| `WinAmount − BetCoin` | `win_lose` | |
| — | `rolling_chip` | 固定 `0` |
| `CreateTime` | `bet_time` / `payout_time` | UTC+8 → UTC，兩欄同值 |
| 整列原文 | `info` | JSON |

- **歸戶**：BR 的 `vendor_info_id`／`member_id` 一律為 `0`（拉單契約只給商戶 info，不知會員）；TBR 轉檔時以 `username → suncity_accounts.username`（`member_id > 0`）解析會員。查 BR 不要以 `member_id`／`vendor_info_id` 篩選；BR 有、TBR 無的注單，先查該 username 在 `suncity_accounts` 是否存在且已綁會員。
- 幣值：三方 MYR 1:100 只影響遊戲畫面，API 金額即實際金額，`rate = 1`。

## bet_id 處理

- 編碼：三方為整數（`345740679818`），BR 存字串
- 大整數陷阱：有（12 位以上）；一律 `(string)` cast
- where 必須：`where('bet_id', (string) $gameSerialId)`

## status / 結算流程

- enum：無獨立 enum，沿用標準 status（0 建立中 / 1 已結算 / 2 取消）
- 已結算：`1`（`game/log/record` 只回已結算的結果單，入庫一律 1）
- 未結算：`0`（本 vendor 不會出現）
- 退款：`2`（本 vendor 不會出現）
- multi-bet：否——每 `GameSerialID` 一筆

## 對齊命令一覽

| 步驟 | 命令 |
|---|---|
| Step 0 取資料／三方回核 | `fetch_bet_record --vendor=292 --direct --dry-run --start={yyyy-MM-dd@HH} --end={yyyy-MM-dd@HH}` |
| Step 2 修正（補拉缺漏） | 同上去掉 `--dry-run`；金額異動加 `--update` |
| Step 4 補帳（通用） | 先 `summary_bet_record --vendor=292 --date={YYYY-MM-DD} --hour={H} --dry-run` 試跑 → 確認差異後去掉 `--dry-run` 實跑 |

> ⚠️ **Step 4 命令安全規範（金流寫入，務必遵守）**
> - `--date` / `--hour` 輸入為 **UTC+8（台北）**，命令內部自動轉 UTC+0。
> - **禁止裸跑** `summary_bet_record --vendor=292`（預設今天整天 24 小時）。
> - 務必先 `--dry-run` 確認差異筆數與時間窗無誤，再實跑。

## 終驗 SQL

```sql
-- 單日三表 SUM 對齊（BR=TBR=SBR 才算通過；本 vendor 無 jackpot，略該欄）
-- 三段 ? 時間窗皆帶 UTC
SELECT 'suncity_bet_records' AS source, COUNT(*) AS cnt,
  SUM(bet) AS sum_bet, SUM(valid_bet) AS sum_valid_bet, SUM(win_lose) AS sum_winlose
FROM suncity_bet_records
WHERE payout_time >= ? AND payout_time < ? AND status = 1
UNION ALL
-- ★ TBR 全量 by vendor_id + 時窗，不 JOIN BR，以免掩蓋孤兒 TBR
SELECT 'total_bet_records (vendor=292)', COUNT(*),
  SUM(t.bet), SUM(t.valid_bet), SUM(t.win_lose)
FROM total_bet_records t
WHERE t.vendor_id = 292
  AND t.payout_time >= ? AND t.payout_time < ?
UNION ALL
-- ⚠️ summary_bet_records 的 bet 欄即「有效投注」，對齊到 sum_valid_bet
SELECT 'summary_bet_records (vendor=292)', COUNT(*),
  NULL AS sum_bet, SUM(bet) AS sum_valid_bet, SUM(win_lose) AS sum_winlose
FROM summary_bet_records
WHERE vendor_id = 292
  AND data_time >= ? AND data_time < ?;
```

> ✅ **通過條件**：`sum_valid_bet` / `sum_winlose` 須 **BR = TBR = SBR** 相等；`sum_bet` 僅 BR=TBR。BR 多於 TBR 時，先依「欄位 mapping」的歸戶說明查 username 是否有綁會員的 `suncity_accounts` 列。

## 已知 incident / 注意事項

- 新 vendor，尚無 incident。
- 簽名依文件 2.2 以原始字串組成（`StartDate`／`EndDate` 含空格冒號不可 URL 編碼），與 Play8oy 共用 `Play8oy\Driver::sign()`；若拉單回 `-10005` 先查簽名與 IP 白名單。

## 關鍵檔案

| 用途 | 路徑 |
|---|---|
| Driver（拉單） | `app/Vendors/Suncity/Driver.php`（繼承 `app/Vendors/Play8oy/Driver.php`） |
| BR Model | `app/Vendors/Models/SuncityBetRecord.php` |
| Account Model | `app/Vendors/Models/SuncityAccount.php` |
| 拉單服務 | `app/Services/BetRecordFetchService.php` |
| 三方規格 | `docs/integrations/vendors/Suncity/api.md` |
