# AiGamingSW（vendor_id=260）

> **卡型：專屬表卡（dedicated）** — BR 表一表一 vendor、可直接 SUM、有 status / payout_time / valid_bet。
> 通用 SOP 見 `reconcile-bet-records` skill；vendor 細節（差異點）寫在這裡。

## ✅ 必填契約聲明區

- [x] **識別**：`vendor_id=260`、`aigamingsw` → `aigamingsw_bet_records`、`is_single_wallet=1`
- [x] **TBR/SBR `time_field`**：`payout_time`（須與 `config/summary_bet_record.php` 的 time_field 同步，改 config 要回補本卡）
- [x] **時區 + UTC 換算**：見「時區」
- [x] **金額口徑**：見「欄位 mapping」；win 不含 jackpot（無 jackpot 回調，BR 未建該欄）；cashBonus 純派彩記 BR.bonus，不入 win / win_lose
- [x] **三方回核**：`/user-center/openApi/gameRecords` API，見「取得三方資料」/「欄位 mapping」
- [x] **未結算（pending）判定謂詞**：`status = 0`
- [x] **終驗 SQL**：見「終驗 SQL」
- [x] **BR 有效投注篩選謂詞**：`status = 1`（rollback 沖銷單為 status=2 且金額已歸零；settle CANCEL 單 status=1 但 valid_bet=0，SUM 對齊天然排除）
- [x] **BR `time_field`**：`payout_time`（與 TBR 同切口）
- [x] **status enum 全集**：0 建立中（deduct 已扣未結算）/ 1 已結算 / 2 取消（rollback 沖銷）
- [x] **Phase C 可用性**：已註冊於 `config/summary_bet_record.php`（time_field=payout_time），通用 `summary_bet_record` 命令可用

## 速查表

| 項目 | 值 |
|---|---|
| `vendor_id` | 260 |
| `VendorIdEnum` case | `App\Enums\Vendor\VendorIdEnum::AIGAMINGSW` |
| `vendors.code` | AIGAMINGSW |
| `vendors.tname`（**BR 表前綴**） | `aigamingsw` → `aigamingsw_bet_records` |
| `vendors.third_party_code` | AIGAMINGSW |
| `summary_bet_record` time_field | `payout_time` |
| `vendors.is_single_wallet` | `1` (SW) |

## 一行概述

單錢包；老虎機（Slots）；2026-08-31 接入（target/tickets#4251，TPower / MYR）；接入中。

## 取得三方資料（Step 0）

- **來源**：`POST {API_URL}/user-center/openApi/gameRecords`（簽名 `md5(apiKey + page + provider + size + timestamp + username + walletType)` 轉小寫）
- **命令**：無自動化命令；以 API 手動撈（或後台 https://example.invalid 查）
- **限制 / 注意**：
  - `dateFrom`/`dateTo` 最大跨度 **90 天**；`size` 上限 1000，`page` 從 0 起
  - username 留空 = 全部玩家
  - 回傳無獨立「transactionId」欄名——`orderNo` 即回調的 `transactionId`（回核時以此對 BR）

## 時區

| 對象 | 時區 |
|---|---|
| 三方資料（callback betTime/resultTime） | **UTC+8 無時區後綴**（2026-08-31 實測；Controller 以 `+08:00` 解析轉 UTC 落帳）；API trxDatetime 待驗 |
| BR.bet_time | UTC |
| BR.payout_time | UTC |
| `config/summary_bet_record.php` time_field | `payout_time` |

## 欄位 mapping（三方 → BR）

| 三方欄位 | BR 欄位 | 備註 |
|---|---|---|
| `transactionId`（API 端 `orderNo`） | `bet_id`（unique，冪等鍵） | 字串；deduct/settle 共用同一 id，**bonus 與 cashBonus 各自帶獨立新 id**；BR 無 reference_no 欄，TBR.reference_no 由 `getBetId()` 帶入 |
| deduct `amount` | `bet` / `valid_bet` | 除以 `vendor_infos.rate` 換算平台單位；settle resultType=CANCEL 時 valid_bet 歸 0 |
| settle `payoutAmount`（含本金） | `win` | 除以 rate |
| bonus `payoutAmount` | 獨立 BR 的 `win`（bet=0） | bonus 為獨立新單號（free spin 每轉一筆）；`amount` 是 payoutAmount 鏡射，不參與運算；**payoutAmount=0 不入 BR**（對帳回核三方 free spin 紀錄時注意此口徑） |
| cashBonus `payoutAmount` | `bonus` | 純派彩活動獎金，不入 win / win_lose |
| `win - bet` | `win_lose` | |
| `betTime` | `bet_time` | ISO8601（含納秒） |
| `resultTime` | `payout_time` | 多段 settle / bonus 以最後一次為準 |

- 金額口徑：`payoutAmount` **含本金**（include betStake）；三方 API 的 `profitAmount` = 淨輸贏（對 BR.win_lose）、`gamePayout` 對 BR.win。
- 本 vendor BR 未建 jackpot / reference_no 欄（無 jackpot 回調；transactionId 即 bet_id），終驗 SQL 略 jackpot 欄。

## bet_id 處理

- 編碼：字串（範例為數字字串 `553662`）
- 大整數陷阱：未觀察到；一律 `(string)` cast 保險
- where 必須：`where('bet_id', (string) $transactionId)`

## status / 結算流程

- enum：無獨立 enum，沿用 `vendorBetRecordColumns` 標準 status
- 已結算：`1`（settle 一次即鎖定——實測 isFirst/isEnd 恆 false 不可依賴；重複 settle 回 2001；bonus / cashBonus 建立即 1）
- 未結算：`0`（deduct 已扣款、等 settle）
- 退款：`2`（rollback 沖銷，bet/valid_bet/win 歸零）
- multi-bet：**否**——每 transactionId 一次 deduct＋一次 settle；bonus / cashBonus 各為獨立單
- 輸贏皆送 settle（LOSE payoutAmount=0 也有 settle，實測確認），TBR 結算才建

## 對齊命令一覽

| 步驟 | 命令 |
|---|---|
| Step 0 取資料 | 無專屬命令，走 gameRecords API（見上） |
| Step 1 校對 | 手動比對（orderNo ↔ bet_id） |
| Step 4 補帳（通用） | 先 `summary_bet_record --vendor=260 --date={YYYY-MM-DD} --hour={H} --dry-run` 試跑 → 確認差異後去掉 `--dry-run` 實跑 |

> ⚠️ **Step 4 命令安全規範（金流寫入，務必遵守）**
> - `--date` / `--hour` 輸入為 **UTC+8（台北）**，命令內部自動轉 UTC+0。
> - **禁止裸跑** `summary_bet_record --vendor=260`（預設今天整天 24 小時）。
> - 務必先 `--dry-run` 確認差異筆數與時間窗無誤，再實跑。

## 終驗 SQL

```sql
-- 單日三表 SUM 對齊（BR=TBR=SBR 才算通過；本 vendor 無 jackpot，略該欄）
-- 三段 ? 時間窗皆帶 UTC
SELECT 'aigamingsw_bet_records' AS source, COUNT(*) AS cnt,
  SUM(bet) AS sum_bet, SUM(valid_bet) AS sum_valid_bet, SUM(win_lose) AS sum_winlose
FROM aigamingsw_bet_records
WHERE payout_time >= ? AND payout_time < ? AND status = 1
UNION ALL
SELECT 'total_bet_records (vendor=260)', COUNT(*),
  SUM(t.bet), SUM(t.valid_bet), SUM(t.win_lose)
FROM total_bet_records t
WHERE t.vendor_id = 260
  AND t.payout_time >= ? AND t.payout_time < ?
UNION ALL
SELECT 'summary_bet_records (vendor=260)', COUNT(*),
  NULL AS sum_bet, SUM(bet) AS sum_valid_bet, SUM(win_lose) AS sum_winlose
FROM summary_bet_records
WHERE vendor_id = 260
  AND data_time >= ? AND data_time < ?;
```

> ✅ **通過條件**：`sum_valid_bet` / `sum_winlose` 須 **BR = TBR = SBR** 相等；`sum_bet` 僅 BR=TBR。

## 已知 incident / 注意事項

- 冪等防線完備：deduct/bonus/cashBonus 重送 → bet_id unique 擋（5003）；settle 重送 → `status=1 → 2001` 擋。實測與文件的落差（bonus 獨立單、時區 +8、isEnd 恆 false）見 `docs/integrations/vendors/AiGamingSW/integration.md`。
- 官方文件 `betTime`/`resultTime` 宣稱 `YYYY-MM-DD HH:mm:ss`，實際範例為 ISO8601 帶納秒；時區未載明，UAT 實測後回補本卡「時區」段。
- cashBonus（純派彩）會建 `bet=0` 的 BR 並進 TBR，金額記 `bonus` 欄（活動獎金），不入 win / win_lose、不貢獻 valid_bet。

## 關鍵檔案

| 用途 | 路徑 |
|---|---|
| Controller 主邏輯 | `app/Http/Controllers/External/SingleWallets/AiGamingSWController.php` |
| BR Model | `app/Vendors/Models/AiGamingSwBetRecord.php` |
| Driver | `app/Vendors/AiGamingSW/Driver.php` |
| 三方規格 | `docs/integrations/vendors/AiGamingSW/api.md` |
