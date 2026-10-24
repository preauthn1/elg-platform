# {VendorName}（vendor_id={id}）

> **卡型：專屬表卡（dedicated）** — BR 表一表一 vendor、可直接 SUM、有 status / payout_time / valid_bet。
> 一表掛多 vendor（共用表、靠 `vendor_info_id` 歸戶、BR 不能直接 SUM）的 vendor 改用 `_template-aggregator.md`。
> 對接卡。新 vendor 接入時複製此檔為 `{vendor_lowercase}.md` 後依規格填寫。
> 通用 SOP 見 `reconcile-bet-records` skill；vendor 細節（差異點）寫在這裡。

## ✅ 必填契約聲明區（Step 0 gate 檢查這份；缺任一項即 STOP）

> reconcile / repair 的 Step 0 gate 逐項檢查下列必填段是否存在且已填實值（非 `{佔位符}`）。缺任一項，skill 會停在 Step 0、要求補卡後才續。

**共用核心（兩型都必填）**

- [ ] **識別**：`vendor_id`、`tname`→BR 表名、`is_single_wallet`（見「速查表」）
- [ ] **TBR/SBR `time_field`**：值 + 註「須與 `config/summary_bet_record.php` 的 time_field 同步，改 config 要回補本卡」（見「速查表」/「時區」）
- [ ] **時區 + UTC 換算**：各時間欄位時區、GMT+8↔UTC 公式（見「時區」）
- [ ] **金額口徑**：`valid_bet` / `win_lose` / `jackpot` 定義；尤其 win 是否含 jackpot（見「欄位 mapping」）
- [ ] **三方回核**：取三方注單的來源／命令（API / 後台 CSV / DataFeed）+ 三方欄位→BR 欄位 mapping，供三表 SUM 對齊後逐筆確認 BR 與三方吻合（見「取得三方資料」/「欄位 mapping」/「對齊命令一覽」）；若無三方對源、純信任 callback，須於該段明寫此聲明與原因
- [ ] **未結算（pending）判定謂詞**：月結 / B-2 分流用（通常 `status<>{SETTLE} AND payout_time IS NULL`）（見「status / 結算流程」）
- [ ] **終驗 SQL**：必填值的可執行內聯版、不留 `{SETTLED}` 等佔位符未填（見「終驗 SQL」）

**專屬表卡專屬**

- [ ] **BR 有效投注篩選謂詞**：可貼 WHERE 的完整條件；多數 = `status={SETTLE值}`，須明寫是否附加排除（refund 已沖銷、valid_bet=0、test 帳號…）
- [ ] **BR `time_field`**：值 + 是否與 TBR 同切口（`br_time_field`）
- [ ] **status enum 全集**：含對帳目標 status（見「status / 結算流程」）
- [ ] **Phase C 可用性**：是否註冊於 `config/summary_bet_record.php`（reconcile 據此指分流，不執行重建）

## 速查表

| 項目 | 值 |
|---|---|
| `vendor_id` | {id} |
| `VendorIdEnum` case | `App\Enums\Vendor\VendorIdEnum::{CASE_NAME}` |
| `vendors.code` | {code} |
| `vendors.tname`（**BR 表前綴**） | `{tname}` → `{tname}_bet_records` |
| `vendors.third_party_code` | {third_party_code} |
| `summary_bet_record` time_field | `payout_time` / `bet_time` |
| `vendors.is_single_wallet` | `1` (SW) / `0` (TW) |

> ⚠️ 若同名遊戲商有 SW + TW 雙版本（例如 `epicwin` + `epicwinsw`），各自有獨立 vendor_id 與 tname；判斷錢包類型看 `is_single_wallet` 欄位。

## 一行概述

{單錢包 / 轉帳錢包；遊戲類型；接入時間；當前狀態（穩定 / 接入中 / 處理 incident）}

## 取得三方資料（Step 0）

- **來源**：[API 名稱 / 後台 CSV 匯出 / DataFeed]
- **命令**：`{vendor}:fetch-records {date}`（若有自動化命令）
- **手動匯出步驟**：
  1. ...
  2. ...
- **限制 / 注意**：
  - API quota / IP 白名單 / 同 IP 併發限制
  - CSV 格式陷阱（Excel 匯出會失精的欄位 → 用 直出 CSV）

## 時區

| 對象 | 時區 |
|---|---|
| 三方資料（callback / API / 匯出 CSV） | UTC / SGT(+8) / ... |
| BR.bet_time | ... |
| BR.payout_time | ... |
| `config/summary_bet_record.php` time_field | `payout_time` / `bet_time` |

> 對帳換算公式（若有）：vendor「{date}」整日 = UTC `[?, ?)`

## 欄位 mapping（三方 → BR）

| 三方欄位 | BR 欄位 | 備註 |
|---|---|---|
| {ID 欄位} | `bet_id` | （是否大整數需 string cast） |
| {訂單號} | `reference_no` | （settle 後寫入 / 一開始就有） |
| {投注金額} | `bet` | |
| {派彩金額} | `win` | |
| {時間} | `bet_time` / `payout_time` | （是否需時區換算） |

## bet_id 處理

- 編碼：[整數 / 字串]
- 大整數陷阱：[有 / 無]
- where 必須：`(string) $betId`（若大整數）

## status / 結算流程

- enum：`App\Vendors\{Name}\BetRecordStatusEnum`（若有）
- 已結算：{value}
- 未結算：{value}
- 退款：{value}
- multi-bet：是 / 否

## 對齊命令一覽

| 步驟 | 命令 |
|---|---|
| Step 0 取資料 | `{vendor}:fetch-records` |
| Step 1 校對 | `{vendor}:compare-records` |
| Step 1.5 同步 payout_time（若需） | `{vendor}:sync-payout-time` |
| Step 2 修正（單筆 stuck） | `{vendor}:fix-records` |
| Step 2 修正（multi-bet，若有） | `{vendor}:backfill-stuck-bet-records` |
| Step 4 補帳（通用） | 先 `summary_bet_record --vendor={id} --date={YYYY-MM-DD} --hour={H} --dry-run` 試跑 → 確認差異後去掉 `--dry-run` 實跑 |

> ⚠️ **Step 4 命令安全規範（金流寫入，務必遵守）**
> - `--date` / `--hour` 輸入為 **UTC+8（台北）**，命令內部自動轉 UTC+0；DB 內 BR/TBR 時間為 UTC，下命令前須先換算窗口。
> - **禁止裸跑** `summary_bet_record --vendor={id}`：缺 `--date` / `--hour` 時預設「今天（UTC+8）整天 24 小時」，做歷史對帳會打錯帳務窗口。
> - 命令對 `summary_bet_records` 按 (vendor, 小時) **刪除重建**、並可能寫入 correction 列；**務必先 `--dry-run` 確認差異筆數與時間窗無誤，再去掉 `--dry-run` 實跑**。
> - 部分 vendor 未註冊於 `config/summary_bet_record.php`，通用命令會直接 abort —— 該情形須於本卡標註並改走 vendor 專屬補帳流程。

## 終驗 SQL

```sql
-- 單日三表 SUM 對齊（BR=TBR=SBR 才算通過；含 jackpot — 漏比 jackpot 看不到 SBR.jackpot=0 的長期 drift；無 jackpot 的 vendor 可移除該欄）
-- 三段 ? 時間窗皆帶 UTC（BR/TBR.payout_time、SBR.data_time 均為 UTC）
SELECT '{vendor}_bet_records' AS source, COUNT(*) AS cnt,
  SUM(bet) AS sum_bet, SUM(valid_bet) AS sum_valid_bet,
  SUM(win_lose) AS sum_winlose, SUM(jackpot) AS sum_jackpot
FROM {vendor}_bet_records
WHERE payout_time >= ? AND payout_time < ? AND status = {SETTLED}
UNION ALL
SELECT 'total_bet_records (vendor={id})', COUNT(*),
  SUM(t.bet), SUM(t.valid_bet), SUM(t.win_lose), SUM(t.jackpot)
-- ★ TBR 全量 by vendor_id + 時窗，不 JOIN BR：INNER JOIN BR 會把孤兒 TBR（bet_record_id 指向不存在 BR）排除、掩蓋 TBR-only drift
FROM total_bet_records t
WHERE t.vendor_id = {id}
  AND t.payout_time >= ? AND t.payout_time < ?
UNION ALL
-- ⚠️ summary_bet_records 無 valid_bet 欄；其 bet 欄存的即「有效投注」
--   （SummaryBetRecordService：SUM(total_bet_records.valid_bet) AS bet）。
--   故原始 bet 欄填 NULL、SBR.bet 對齊到 valid_bet 欄比對。
SELECT 'summary_bet_records (vendor={id})', COUNT(*),
  NULL AS sum_bet, SUM(bet) AS sum_valid_bet, SUM(win_lose) AS sum_winlose, SUM(jackpot) AS sum_jackpot
FROM summary_bet_records
WHERE vendor_id = {id}
  AND data_time >= ? AND data_time < ?;
```

> ✅ **通過條件**：`sum_valid_bet`（SBR 為其 `bet` 欄）/ `sum_winlose` /（有 jackpot 者）`sum_jackpot` 須 **BR = TBR = SBR** 相等；`sum_bet`（原始投注）僅 BR=TBR（SBR 不存原始 bet，列為 NULL）。任一層不等即未通過。**跑完 Step 4 後務必回到此 SQL 複驗 SBR 列** —— 命令失敗、時間窗錯誤、欄位 drift（如 jackpot 未寫）都只會在 SBR 列現形，僅比 BR/TBR 會漏掉。

## 已知 incident / 注意事項

- ...（已修復的歷史 bug、運維踩雷、限流規則、特殊行為）
- 一次性 incident → 詳見 `{incident-skill-name}` skill

## 關鍵檔案

| 用途 | 路徑 |
|---|---|
| Controller 主邏輯 | `app/Http/Controllers/External/{SingleWallets|TransferWallets}/{Name}Controller.php` |
| BR Model | `app/Vendors/Models/{Name}BetRecord.php` |
| Driver | `app/Vendors/{Name}/Driver.php` |
| status enum | `app/Vendors/{Name}/BetRecordStatusEnum.php` |
