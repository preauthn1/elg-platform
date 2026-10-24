# {AggregatorName} aggregator 共用表對帳卡（範例 vendor：{VendorName}，vendor_id={id}）

> **卡型：aggregator 共用表卡** — 一張 BR 表 `{shared_tname}_bet_records` 由旗下 N 個 vendor 共用，無 `vendor_id` 欄、靠 `vendor_info_id` 歸戶、BR 不能直接 SUM。
> 一表一 vendor（可直接 SUM、BR 自帶 status / payout_time / valid_bet）的 vendor 改用 `_template-dedicated.md`。
> 對接卡。**一卡涵蓋整張共用表**：下方速查表與終驗 SQL 以一個範例 vendor 示範，其他共用同表的 vendor 把 `vendor_id` / `code` 換成對應值即可套用同一套流程，不另開卡。
> 通用 SOP 見 `reconcile-bet-records` skill；vendor 細節（差異點）寫在這裡。

## ✅ 必填契約聲明區（Step 0 gate 檢查這份；缺任一項即 STOP）

> reconcile / repair 的 Step 0 gate 逐項檢查下列必填段是否存在且已填實值（非 `{佔位符}`）。缺任一項，skill 會停在 Step 0、要求補卡後才續。

**共用核心（兩型都必填）**

- [ ] **識別**：範例 `vendor_id`、`tname`→共用 BR 表名、`is_single_wallet`（見「速查表」）
- [ ] **TBR/SBR `time_field`**：值 + 註「須與 `config/summary_bet_record.php` 的 time_field 同步，改 config 要回補本卡」（多數 vendor 已註冊但 `schedule_enabled=false`＝整點仍由舊系統寫，見「Phase C 可用性」）
- [ ] **時區 + UTC 換算**：各時間欄位時區、來源時區↔UTC 公式（見「時區」）
- [ ] **金額口徑**：`valid_bet` / `win_lose` / `jackpot` 定義；尤其 win 是否含 jackpot、共用表是否恆 0（見「欄位 mapping」）
- [ ] **三方回核**：取三方注單的來源／命令（API / 後台 CSV / DataFeed）+ 三方欄位→BR 欄位 mapping，供三表 SUM 對齊後逐筆確認 BR 與三方吻合（見「取得三方資料」/「欄位 mapping」/「對齊命令一覽」）；若無三方對源、純信任 callback，須於該段明寫此聲明與原因
- [ ] **未結算（pending）判定謂詞**：BR 無 status 時，註明 pending / 非結算如何認定（多由 SP 以特有排除處理，見下「共用表特有排除條件」）
- [ ] **終驗 SQL**：必填值的可執行內聯版、含歸戶 join、不留佔位符未填（見「終驗 SQL」）

**aggregator 共用表卡專屬**

- [ ] **vendor 歸戶謂詞**：如何從共用表切出單一 `vendor_id` 的完整 join（如 `{accounts_table} → vendor_infos → vendor_id`）（見「共用表篩 vendor 的 SQL」）
- [ ] **共用表特有排除條件**：與 BR→TBR SP 一致的排除（如 `JSON_UNQUOTE(JSON_EXTRACT(info,'$.type'))!='0'`）（見「共用表篩 vendor 的 SQL」）
- [ ] **適用 `vendor_id` 清單 + 範例 vendor**：該共用表掛哪些 vendor、速查用哪個當範例（見「共用表」節）
- [ ] **BR 結構差異聲明**：無 status / payout_time / valid_bet 時，列明哪些由 SP 帶（見「欄位 mapping」）
- [ ] **BR→TBR 機制**：SP 名稱（如 `SP_transfer_bet_record_{name}`）（見「對齊命令一覽」）
- [ ] **切口欄位**：共用表用哪個時間欄（如 `bet_time`）（見「時區」）

## 速查表（範例：{VendorName}）

| 項目 | 值 |
|---|---|
| `vendor_id` | {id} |
| `VendorIdEnum` case | `App\Enums\Vendor\VendorIdEnum::{CASE_NAME}` |
| `vendors.code` | {code} |
| `vendors.tname`（**共用 BR 表前綴**） | `{shared_tname}` → **`{shared_tname}_bet_records`（共用表，見下節）** |
| `vendors.third_party_code` | {third_party_code}（**共用表上多筆同值**） |
| `summary_bet_record` time_field | {`payout_time` / `bet_time` / **未註冊**} |
| `vendors.is_single_wallet` | {`0` (TW) / `1` (SW)} |

## 一行概述

{錢包類型；aggregator 系列定位；BR / TBR 對齊由哪個 SP 排程處理}

## ⚠️ {AggregatorName} aggregator 共用表（最重要）

`tname='{shared_tname}'` 並非單一遊戲商，而是 **共用一張 `{shared_tname}_bet_records` 表的 N 個 vendor 集合**（vendor_id {清單}…），其中 {VendorName} = {id}。`third_party_code='{third_party_code}'` 在 vendors 表上是**多筆同值**。

**對齊單一 vendor（如 {id}）絕對不能直接 `SUM({shared_tname}_bet_records)`**：必須透過 `{accounts_table}.vendor_info_id → vendor_infos.vendor_id` 反查篩出該 vendor 的注單；TBR 因為已寫入 `vendor_id` 欄位，可直接 `where vendor_id={id}` 篩。

### 共用表篩 vendor 的 SQL 範本（歸戶謂詞 + 特有排除）

```sql
-- BR 層：依 vendor_id 歸戶（join {accounts_table} → vendor_infos）
SELECT COUNT(*) AS cnt,
       ROUND(SUM(b.bet), 4)       AS sum_bet,
       ROUND(SUM(b.win_lose), 4)  AS sum_winlose
FROM {shared_tname}_bet_records b
JOIN {accounts_table} a  ON a.username = b.username AND a.member_id > 0
JOIN vendor_infos     vi ON vi.id = a.vendor_info_id
WHERE vi.vendor_id = {id}
  AND b.{切口欄} >= ? AND b.{切口欄} < ?
  AND {共用表特有排除條件};   -- 與 BR→TBR SP 一致（如 JSON_UNQUOTE(JSON_EXTRACT(example.invalid,'$.type'))!='0'）

-- TBR 層：直接用 vendor_id 篩
SELECT COUNT(*) AS cnt,
       ROUND(SUM(bet), 4)       AS sum_bet,
       ROUND(SUM(valid_bet), 4) AS sum_valid_bet,
       ROUND(SUM(win_lose), 4)  AS sum_winlose
FROM total_bet_records
WHERE vendor_id = {id}
  AND {切口欄} >= ? AND {切口欄} < ?;
```

> ⚠️ **跨幣別不可加總**：同 vendor_id 可橫跨多個 `vendor_infos.currency`（各有 `rate` 匯率欄）。對帳範疇 = vendor × 白標 × **幣別**，逐幣別獨立對齊；`vendor_infos.status` 依「當期 / 歷史」意圖選用，BR/TBR 兩端須一致（規則見 `reconcile-bet-records` skill Step 2 路標）。

## 取得三方資料（Step 0）

- **來源**：[後台 CSV 匯出 / API / DataFeed]
- **命令**：{有無自動化命令}
- **手動匯出步驟**：
  1. ...
- **限制 / 注意**：
  - CSV 是否為 per-player 彙總（只能對 SUM 與筆數歸戶、無法逐單比對 `bet_id`）
  - 是否含 `bet_id`；不含則逐單需另抓 API / DataFeed
  - 不同白標檔名前綴差異

## 時區

| 對象 | 時區 |
|---|---|
| 三方資料（callback / API / 匯出 CSV） | {UTC / SGT(+8) / ...} |
| `{shared_tname}_bet_records.{切口欄}` | {UTC / ...} |
| TBR `{切口欄}` | {UTC；SP 帶 BR.{切口欄}} |

> 換算公式（若有）：來源「{date}」整日 = UTC `[?, ?)`

## 欄位 mapping

### 三方 → TBR

| 三方欄位 | TBR 欄位 | 備註 |
|---|---|---|
| {投注額} | `SUM(bet)` 對齊用 | |
| {輸贏} | `win_lose` | **注意三方視角 vs 公司視角符號是否相反** |

### BR `{shared_tname}_bet_records` 結構差異聲明（aggregator 必填）

```
{列出 BR 實際欄位：id, bet_id, username, {切口欄}, bet, win_lose, info(json), ...}
```

- **是否無 `status`** — {BR 寫入即結算？}
- **是否無 `payout_time`** — {TBR 的 payout_time 由 SP 帶哪欄？}
- **是否無 `valid_bet`** — {TBR 的 valid_bet 由 SP 帶什麼？如 `IFNULL(b.bet, 0)`}

## bet_id 處理

- 編碼：[整數 / 字串 / hex ObjectId]
- 大整數陷阱：[有 / 無]
- where 必須：`(string) $betId`（若大整數）

## status / 結算流程

- status enum：{無 / `App\Vendors\{Name}\BetRecordStatusEnum`}
- multi-bet：是 / 否
- 退款：{如何表示；是否由 SP 排除（如 `info.type=0`）}

## 對齊命令一覽

| 步驟 | 命令 / 動作 |
|---|---|
| Step 0 取資料 | {後台下載 CSV / API} |
| Step 1 校對（SUM） | 本卡「終驗 SQL」 |
| Step 2 修正（單筆） | {有無自動化命令；無則手動 INSERT/UPDATE BR + TBR + recovery_logs} |
| **BR → TBR 同步** | `CALL SP_transfer_bet_record_{name}(?, ?, ?);`（排程自動執行，必要時手動重算） |
| Step 4 補帳 | {適用：先 `summary_bet_record --vendor={id} --date=... --dry-run` 試跑 / 不適用：未註冊 config} |

> **Phase C 可用性**：先查 `config/summary_bet_record.php` 有沒有這個 vendor。多數已註冊但 `schedule_enabled=false`：整點 SBR 仍由舊系統寫，apis 只供手動 `--vendor` 補跑，而且 apis 從 TBR 彙總、舊系統可能從 BR 彙總，補跑前先確認兩邊口徑。少數未註冊的（通用 SBR 重建不可用）由舊系統流程處理；未註冊不擋對帳，但 TBR↔SBR drift 走 skill Step 0-bis 三路徑。

## 終驗 SQL

對齊一日三方 vs BR vs TBR vs SBR（歸戶版）：

```sql
-- ⚠️ 單一 mysql_query 只執行一語句：勿用 SET @var 多語句 prologue（MCP 不保留跨語句變數，見 whitelabel-db-query skill）。
--   vendor_id / 幣別 / UTC 時間窗一律內聯填實值；時間窗在三層各出現一次，務必三處填同一組 [起, 迄)。
-- ★ 範疇必須一致：對帳範疇 = vendor × 白標 × 幣別（見上方「跨幣別不可加總」）。
--   三層（BR/TBR/SBR）都要補同一範疇過濾，否則混算跨幣別 / 跨白標總額、造成假 drift 或藏真 drift。
--   單一幣別且單一白標的 vendor 才可移除下列 currency 與 {範疇過濾} 條件。
-- 四數值欄對齊：cnt / sum_bet（原始投注）/ sum_valid_bet / sum_win_lose
--   ★ jackpot 非恆 0 的 aggregator：三層各加一欄 ROUND(SUM(jackpot),4)（BR/TBR 取自表、SBR 取 summary）
-- BR 層（歸戶 + 特有排除 + 範疇）；BR 常無 valid_bet 欄，其 bet 即 TBR.valid_bet 來源（valid_bet=bet 前提）→ sum_valid_bet 取 SUM(b.bet) 供對齊
SELECT 'BR ({shared_tname}→vendor={id})' AS layer, COUNT(*) AS cnt,
       ROUND(SUM(b.bet), 4) AS sum_bet,
       ROUND(SUM(b.bet), 4) AS sum_valid_bet,   -- BR 無 valid_bet 欄：valid_bet=bet 前提下用 bet；若該 aggregator SP 對 valid_bet 加工，此欄不可比、以 TBR↔SBR 為準
       ROUND(SUM(b.win_lose), 4) AS sum_win_lose
FROM {shared_tname}_bet_records b
JOIN {accounts_table} a  ON a.username = b.username AND a.member_id > 0
JOIN vendor_infos     vi ON vi.id = a.vendor_info_id
WHERE vi.vendor_id = {id}
  AND vi.currency = '{幣別，如 MYR；跨幣別逐幣別獨立跑}'   -- 範疇：幣別（跨白標再加 {白標範疇}，見 skill §0.5）
  AND b.{切口欄} >= '{窗口起 UTC}' AND b.{切口欄} < '{窗口迄 UTC}'
  AND {共用表特有排除條件}

UNION ALL

-- TBR 層；sum_valid_bet 對齊 SBR.bet、sum_bet 對齊 BR
SELECT 'TBR (vendor_id={id})', COUNT(*),
       ROUND(SUM(bet), 4), ROUND(SUM(valid_bet), 4), ROUND(SUM(win_lose), 4)
FROM total_bet_records
WHERE vendor_id = {id}
  AND {TBR 範疇過濾：幣別 / 白標 —— TBR 無 currency 欄，需依該 aggregator 經 member → vendor_info 切，見 skill §0.5}
  AND {切口欄} >= '{窗口起 UTC}' AND {切口欄} < '{窗口迄 UTC}'

UNION ALL

-- SBR 層（其 bet 欄即 SUM(TBR.valid_bet)）→ sum_bet 列 NULL、bet 對齊到 sum_valid_bet 欄
SELECT 'SBR (vendor_id={id})', COUNT(*),
       NULL, ROUND(SUM(bet), 4), ROUND(SUM(win_lose), 4)
FROM summary_bet_records
WHERE vendor_id = {id}
  AND {SBR 範疇過濾：同 TBR}
  AND data_time >= '{窗口起 UTC}' AND data_time < '{窗口迄 UTC}';
```

> 三表通過：`sum_valid_bet`（BR 取 `SUM(bet)`、TBR 為 valid_bet、SBR 為 bet 欄；建立在 aggregator `valid_bet=bet` 前提）/ `sum_win_lose` 須 **BR = TBR = SBR** 相等；`sum_bet`（原始投注）僅 BR=TBR（SBR 不存、列 NULL）。jackpot 非恆 0 的 aggregator 各層加 `SUM(jackpot)` 欄一併比對。任一層不等即未通過。

## 已知 incident / 注意事項

- **共用表直接 SUM 是最常見坑點**：不歸戶會把全部 N vendor 的注單加起來，SUM 偏大數百倍。
- {CSV 是否 per-player 彙總、是否需符號反轉、SP 排除條件需同步到對齊 SQL …}

## 關鍵檔案

| 用途 | 路徑 |
|---|---|
| BR Model | `app/Vendors/Models/{Name}BetRecord.php` |
| Driver（共用 aggregator） | `app/Vendors/{Name}/Driver.php` |
| BR→TBR SP | `database/procedures/SP_transfer_bet_record_{name}.sql` |
| VendorIdEnum case | `App\Enums\Vendor\VendorIdEnum::{CASE_NAME}` |
