---
name: list-sbr-corrections
description: 只查不改：列出遊戲商的補帳紀錄（SBR correction 列）。當使用者用「查／查詢／顯示」問補帳紀錄、補帳明細，例如「查 PTSW 5/15 的補帳紀錄」時載入。🚫 進行補帳、修帳走 repair-bet-records；對帳、對齊走 reconcile-bet-records。
---

你是 `summary_bet_records` 的 correction 列查詢專家——**只列已寫入的 correction 列，不做對帳/修復**。對帳**診斷**（三表對齊檢查、查差在哪）走 `reconcile-bet-records`；**修復寫入**（修 BR/TBR、跑 SBR 調帳）走 `repair-bet-records` skill。

## 觸發與分流

- **觸發**：用「查／查詢／顯示」開頭，詢問遊戲商的補帳紀錄、補帳明細、SBR correction（`summary_bet_records.correction = 1` 的列）。典型句型：「查 PTSW 5/15 的補帳紀錄」「查詢 KissH5 這個月的補帳紀錄」「切到 TPower 查 PTSW 144 5/26 補帳」。
- **🚫 不觸發的動作型說法**：
  - 「進行補帳／跑補帳／執行補帳作業／修 BR／修 TBR／補進 SBR」是寫入修復，走 `repair-bet-records`。
  - 「對帳／三表對齊／對齊看看／先看不要動」是純讀診斷，走 `reconcile-bet-records`。

## 1. 補帳是什麼

`summary_bet_records.correction = 1` 是 SBR 調帳模式寫入的「**差額紀錄**」，發生時機：

- BR/TBR 因事後修復（fix-records、refund、forced-settle）而變動
- 該日期 SBR 已經存在（不是第一次建）
- 調帳模式會：**寫入差額**（新增 correction=1）+ **沖銷舊值**（也是 correction=1，相反金額）

> 調帳模式終端輸出的「新增或補差值 N 筆，沖銷舊記錄 M 筆」即為這些 correction 列。

---

## 3. 各遊戲商代號 / 時區對照

> `@use_utc` 是「日期輸入語意」開關：`1` = 輸入字串視為 SGT 日界（轉成 UTC range）；`0` = 直接視為 DB 原始日期。**不是「DB 是否存 UTC」的旗標**。下表 PPSW / KissH5 / PTSW DB 皆存 UTC 但用 `1`，是因為 OP 視角採 SGT 日界看帳面。

| 遊戲商 | vendor_id | enum case | tname (BR 前綴) | @use_utc | 備註 |
|---|---|---|---|---|---|
| **PPSW** | **83** | `PPSW` | `ppsw` | **1** | DB 存 UTC（2026-05-27 實測） |
| **KissH5** | **233** | `KISSH5SW` | `kissh5sw` | **1** | callback 是 UTC，後台/CSV/GetDaySummary 用 SGT 日界 |
| **PTSW** | **144** | `PTSW` | `ptsw` | **1** | DB 存 UTC（2026-05-27 實測）；SBR 由 `summary_bet_record:dispatch` 每小時 :30 派發逐小時彙總，已關帳範圍的調帳列（correction）一律寫在 `summary_time`＝關帳時間那一格，關帳以 UTC+8 日界為準，也就是前一日 UTC 16:00 整 = SGT 隔日 00:00，所以 correction「掛帳日」是 SGT 隔日（非產生當日）。PTSW 沒有 `next_day_full_rerun`，不會有隔日整天重跑（`config/summary_bet_record.php`，2026-09 核對）|
| 其他 | — | — | （查 vendors.tname）| **先 0** | 不確定先跑 0；若 `data_date` 比預期偏 ±1 天再切 1 重跑 |

> 完整 enum：`app/Enums/Vendor/VendorIdEnum.php`。
> 使用者口語可能寫成 PPSW / Pragmatic / KissH5 / Kiss / 233 / 83，請對應到上表。
> **使用者輸入不在上表（如 "EpicWin"、"PG" 等）** → 套用 `reconcile-bet-records` 的「Step 0：vendor 解析」與其 `reference/vendor_resolver.md`（含 SW/TW 歧義處理），確認 vendor_id 與 tname 後再回到本 skill。
> **同一 DB（如 `vworld`）要分白標／子站看 correction** → 見下方「§10 同 DB 多白標／子站範疇切分」（root `hierarchy_path` 子樹切法、子站 `vendor_info_id` 切法）。

---

## 4. 快速查詢配方（自然語言觸發 → 一鍵列表）

當使用者說「查 X 今日補帳」「查詢 X 昨日的補帳紀錄」「查 X 五月份的補帳」之類的話時，直接套此配方產出列表。

> **執行前**：tinker 範本內已 inline 環境健檢（列出 DB connection、確認 vendor 已啟用），跑出來的訊息要看；若提示 `⚠️ 未啟用` 表示當前 DB_CONNECTION 連到的環境沒裝該 vendor，可能無資料。

> **🔗 環境切換依賴 `whitelabel-container-exec` skill**：使用者帶環境前綴（「在 TPower 查 …」「切到 VW 查 …」「VPlus33 上的 …」）時，把 `docker exec php-fpm8` 改成 `docker exec -e DB_CONNECTION=<env> php-fpm8`——具體 env 名稱、白標 alias 正規化、寫入類命令前的 confirm 流程**全部由 [`whitelabel-container-exec`](../whitelabel-container-exec/SKILL.md) skill 接管**，本 skill 不在這裡複寫。兩 skill 描述都吻合同一 prompt 時會自動同時觸發，組合語法即可。

> **🚨 輸出格式（不可變動 / 不可加工）**：腳本跑完會印兩段：`=== SQL 結果 ===` 表格 + `=== OP 補帳備註 ===` 文字塊。**完整原樣貼回給使用者**：不要轉 markdown、不要省略 4 位小數的尾零、不要把 `補M/D` 改寫成「補了 M 月 D 日」、不要把分隔線轉 `---`、不要把表格欄位重新排序、不要在輸出前後加自己的解讀段（除非使用者明確問）；唯一例外是出現 `netted,drilldown-required` 時，在兩段輸出之後另起一行說明並跑 drilldown。腳本內 `printf` 已決定格式，**你的工作只是傳達**。

### 4.1 預設輸出：每 summary_date 一塊（OP 友善）

每個 `summary_date`（掛帳日）為一塊：塊內每行 `補M/D b:X.XXXX wl:Y.YYYY [jp:..] [comm:..] [rc:..] [recs:..]`（一行一個原始資料日 `data_date`），緊接 35 個 `-` 分隔線，再來一行 `M/D補 b: wl:` 總計（前綴是該塊的 `summary_date`）。多個 summary_date 之間空一行。可直接貼工單／Excel 對帳表備注欄。

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan tinker --execute '
// ========== 修改這裡 ==========
\$vendorName = \"PPSW\";        // PPSW | KissH5 | PTSW（不分大小寫）
\$dateFrom   = \"2026-05-02\";   // today | yesterday | 今日 | 昨日 | YYYY-MM-DD（區間起，SGT）
\$dateTo     = \"\";             // 空＝單日；或 YYYY-MM-DD（區間迄，含當日）
\$days       = 0;               // 「最近 N 天」時填 N，會覆蓋 dateFrom/dateTo
\$whiteLabel   = \"\";       // 白標 canonical 名（如 VWorld / WTT / VWSG / TPower）；**必填**，Read registry 取對應 rootAgentId
\$scopeAgentId = 0;        // 該白標 rootAgentId（Read registry：plugins/apis-toolkit/registry/whitelabels.metadata.yaml）；查詢一律以此切子樹範疇
\$allowWholeDb = false;    // 罕見：整庫 census（含全部子樹 / RD 測試樹）才設 true；輸出會標示整庫
// ============================

\$vendorMap = [
    \"ppsw\"   => [\"id\" => 83,  \"use_utc\" => true],
    \"kissh5\" => [\"id\" => 233, \"use_utc\" => true],
    \"ptsw\"   => [\"id\" => 144, \"use_utc\" => true],
];
\$key = strtolower(\$vendorName);
if (! isset(\$vendorMap[\$key])) { echo \"Unknown vendor: {\$vendorName}（不在快取表，請套 reconcile-bet-records 的 Step 0 vendor 解析）\\n\"; exit; }
\$vendorId = \$vendorMap[\$key][\"id\"];
\$useUtc   = \$vendorMap[\$key][\"use_utc\"];

// ----- 白標 scope（必填；rootAgentId SSOT=registry，path 與顯示身分一律 DB 衍生）-----
\$scopeAgent = \$scopeAgentId > 0 ? DB::table(\"agents\")->where(\"id\", \$scopeAgentId)->first() : null;
\$rootPath   = \$scopeAgent?->hierarchy_path;
if (\$scopeAgentId > 0 && ! \$rootPath) { echo \"❌ scopeAgentId=\$scopeAgentId 在本 DB 查無 agent；確認白標 rootAgentId 與 DB_CONNECTION 是否相符\\n\"; return; }
if (\$scopeAgentId === 0 && ! \$allowWholeDb) { echo \"❌ 未指定白標 scope：查詢一律須指定白標（Read registry 取該白標 rootAgentId 填入 scopeAgentId）；整庫 census 才設 allowWholeDb=true\\n\"; return; }
\$wlRoots = \$scopeAgentId === 0 ? (int) (DB::select(\"SELECT COUNT(DISTINCT SUBSTRING_INDEX(SUBSTRING(ag.hierarchy_path, 4), CHAR(47), 1)) c FROM members m JOIN agents ag ON ag.id = m.parent\")[0]->c ?? 0) : 0;
\$wlJoin   = \$rootPath
    ? \"JOIN members m ON m.id = summary_bet_records.member_id JOIN agents a ON a.id = m.parent AND (a.hierarchy_path = \\\"\$rootPath\\\" COLLATE utf8mb4_unicode_ci OR a.hierarchy_path LIKE CONCAT(\\\"\$rootPath\\\" COLLATE utf8mb4_unicode_ci, \\\"/%\\\"))\"
    : \"\";

// ----- 環境健檢 -----
\$dbConn  = config(\"database.default\");
\$vendor  = DB::table(\"vendors\")->where(\"id\", \$vendorId)->first();
if (! \$vendor) { echo \"❌ \$vendorName (id=\$vendorId) 在 \$dbConn 不存在；請確認 .env 切到正確環境\\n\"; return; }
\$wallet  = \$vendor->is_single_wallet ? \"SW\" : \"TW\";
\$enabled = DB::table(\"vendor_infos\")->where(\"vendor_id\", \$vendorId)->where(\"status\", 1)->exists();
echo \"🌐 DB=\$dbConn  vendor={\$vendor->name} (id=\$vendorId, tname={\$vendor->tname}, \$wallet)  \" . (\$enabled ? \"✅ 啟用\" : \"⚠️ 未啟用\") . \"\\n\";
if (\$rootPath) { echo \"🎯 白標 {\$whiteLabel}（DB root：{\$scopeAgent->username}／id={\$scopeAgentId}／{\$rootPath}；僅此子樹會員）\\n\"; }
else { echo \"🗄️ 整庫 census（未切白標，含此 DB 全部 {\$wlRoots} 個 member-bearing root）\\n\"; }
// ----- 健檢結束 -----

\$today = (new DateTime(\"now\", new DateTimeZone(\"Asia/Singapore\")))->format(\"Y-m-d\");
\$normDate = fn (\$s) => match (strtolower(\$s)) {
    \"today\", \"今日\", \"今天\" => \$today,
    \"yesterday\", \"昨日\", \"昨天\" => (new DateTime(\$today))->modify(\"-1 day\")->format(\"Y-m-d\"),
    default => \$s,
};
// fromStr＝起日；lastStr＝迄日（含，只用於提示）；endStr＝迄日的隔天（不含）
if (\$days > 0) {
    \$fromStr = (new DateTime(\$today))->modify(\"-\" . (\$days - 1) . \" day\")->format(\"Y-m-d\");
    \$endStr  = (new DateTime(\$today))->modify(\"+1 day\")->format(\"Y-m-d\");
    \$lastStr = \$today;
} else {
    \$fromStr = \$normDate(\$dateFrom);
    \$endStr  = (new DateTime(\$dateTo === \"\" ? \$fromStr : \$normDate(\$dateTo)))->modify(\"+1 day\")->format(\"Y-m-d\");
    \$lastStr = \$dateTo === \"\" ? \$fromStr : \$normDate(\$dateTo);
}
\$isSingleDay = \$days <= 0 && \$dateTo === \"\";

\$rangeStart = \$useUtc
    ? (new DateTime(\$fromStr . \" 00:00:00\", new DateTimeZone(\"Asia/Singapore\")))->setTimezone(new DateTimeZone(\"UTC\"))->format(\"Y-m-d H:i:s\")
    : \$fromStr . \" 00:00:00\";
\$rangeEnd = \$useUtc
    ? (new DateTime(\$endStr . \" 00:00:00\", new DateTimeZone(\"Asia/Singapore\")))->setTimezone(new DateTimeZone(\"UTC\"))->format(\"Y-m-d H:i:s\")
    : \$endStr . \" 00:00:00\";

\$rows = DB::select(\"
    SELECT
        DATE(CASE WHEN ? = 1 THEN CONVERT_TZ(data_time,    \\\"+00:00\\\", \\\"+08:00\\\") ELSE data_time    END) AS data_date,
        DATE(CASE WHEN ? = 1 THEN CONVERT_TZ(summary_time, \\\"+00:00\\\", \\\"+08:00\\\") ELSE summary_time END) AS summary_date,
        SUM(bet) total_bet, SUM(win_lose) total_win_lose,
        SUM(jackpot) total_jackpot, SUM(commission) total_commission,
        SUM(rolling_chip) total_rolling_chip, SUM(record_count) total_record_count,
        COUNT(*) rows_cnt
    FROM summary_bet_records {\$wlJoin}
    WHERE summary_bet_records.vendor_id = ? AND summary_bet_records.correction = 1 AND summary_bet_records.summary_time >= ? AND summary_bet_records.summary_time < ?
    GROUP BY data_date, summary_date ORDER BY summary_date, data_date
\", [(int) \$useUtc, (int) \$useUtc, \$vendorId, \$rangeStart, \$rangeEnd]);

if (count(\$rows) === 0) { echo \$isSingleDay ? \"✅ \$vendorName \$fromStr 無補帳紀錄\\n\" : \"✅ \$vendorName \$fromStr ~ \$lastStr 區間內無補帳\\n\"; return; }

// ===== Step 1: SQL 結果表（驗證用，欄位順序固定） =====
echo \"\\n=== SQL 結果 ===\\n\";
printf(\"%-12s  %-12s  %14s  %14s  %14s  %14s  %14s  %6s  %5s\\n\",
    \"data_date\", \"summary_date\", \"bet\", \"win_lose\", \"jackpot\", \"commission\", \"rolling_chip\", \"recs\", \"cnt\");
echo str_repeat(\"-\", 122) . \"\\n\";
foreach (\$rows as \$r) {
    printf(\"%-12s  %-12s  %14.4f  %14.4f  %14.4f  %14.4f  %14.4f  %6d  %5d\\n\",
        \$r->data_date, \$r->summary_date,
        (float) \$r->total_bet, (float) \$r->total_win_lose, (float) \$r->total_jackpot,
        (float) \$r->total_commission, (float) \$r->total_rolling_chip,
        (int) \$r->total_record_count, (int) \$r->rows_cnt);
}

// ===== Step 2: OP 文字塊（按 summary_date 分塊；4 位小數固定） =====
echo \"\\n=== OP 補帳備註 ===\\n\";
\$grouped = [];
foreach (\$rows as \$r) { \$grouped[\$r->summary_date][] = \$r; }
\$first = true;
foreach (\$grouped as \$sd => \$gRows) {
    if (! \$first) echo \"\\n\";
    \$first = false;
    \$sumBet = 0.0; \$sumWl = 0.0; \$sumJp = 0.0; \$sumComm = 0.0; \$sumRc = 0.0; \$sumRecs = 0;
    foreach (\$gRows as \$r) {
        \$d = new DateTime(\$r->data_date);
        \$extra = \"\";
        if ((float) \$r->total_jackpot      != 0.0) \$extra .= sprintf(\" jp:%.4f\",   (float) \$r->total_jackpot);
        if ((float) \$r->total_commission   != 0.0) \$extra .= sprintf(\" comm:%.4f\", (float) \$r->total_commission);
        if ((float) \$r->total_rolling_chip != 0.0) \$extra .= sprintf(\" rc:%.4f\",   (float) \$r->total_rolling_chip);
        if ((int)   \$r->total_record_count != 0)   \$extra .= sprintf(\" recs:%d\", (int) \$r->total_record_count);
        // netting（淨額互沖）偵測：6 欄 SUM 全 0 但有 row → 互沖
        \$allZero = (float) \$r->total_bet == 0.0 && (float) \$r->total_win_lose == 0.0 &&
            (float) \$r->total_jackpot == 0.0 && (float) \$r->total_commission == 0.0 &&
            (float) \$r->total_rolling_chip == 0.0 && (int) \$r->total_record_count == 0;
        if (\$allZero && (int) \$r->rows_cnt > 0) {
            \$extra .= sprintf(\" (cnt:%d netted,drilldown-required)\", \$r->rows_cnt);
        }
        printf(\"補%d/%d b:%.4f wl:%.4f%s\\n\",
            (int) \$d->format(\"n\"), (int) \$d->format(\"j\"),
            (float) \$r->total_bet, (float) \$r->total_win_lose, \$extra);
        \$sumBet  += (float) \$r->total_bet;
        \$sumWl   += (float) \$r->total_win_lose;
        \$sumJp   += (float) \$r->total_jackpot;
        \$sumComm += (float) \$r->total_commission;
        \$sumRc   += (float) \$r->total_rolling_chip;
        \$sumRecs += (int) \$r->total_record_count;
    }
    echo \"-----------------------------------\\n\";
    \$sdDt = new DateTime(\$sd);
    \$totExtra = \"\";
    if (\$sumJp   != 0.0) \$totExtra .= sprintf(\" jp:%.4f\",   \$sumJp);
    if (\$sumComm != 0.0) \$totExtra .= sprintf(\" comm:%.4f\", \$sumComm);
    if (\$sumRc   != 0.0) \$totExtra .= sprintf(\" rc:%.4f\",   \$sumRc);
    if (\$sumRecs != 0)   \$totExtra .= sprintf(\" recs:%d\", \$sumRecs);
    printf(\"%d/%d補 b:%.4f wl:%.4f%s\\n\",
        (int) \$sdDt->format(\"n\"), (int) \$sdDt->format(\"j\"),
        \$sumBet, \$sumWl, \$totExtra);
}
'"
```

### 4.3 常見用法範例

| 使用者說 | `\$vendorName` / `\$dateFrom` |
|---|---|
| 查 KissH5 今日的補帳紀錄 | `KissH5` / `today` |
| 查詢 PPSW 昨日的補帳紀錄 | `PPSW` / `yesterday` |
| 查 PPSW 5/2 補帳 | `PPSW` / `2026-05-02` |
| 查詢 KissH5 5/4 的補帳 | `KissH5` / `2026-05-04` |
| 查 PTSW 5/26 補帳紀錄 | `PTSW` / `2026-05-26` | （SGT 日界；PTSW 的調帳列集中寫在關帳時間格＝SGT 隔日 00:00）|

> **要查跨多天的補帳**（自然月、最近 N 天、明確日期區間）：同一份腳本改填 `$dateTo` 或 `$days`，見下方「§6 區間掃描與進階配方」的區間掃描。

---

## 5. 原始參數化 SQL 配方（tinker 背後的手動 SQL）

> **🚨 白標 scope 前提（與 §4/§6 配方一致，不可省）**：下列 raw SQL 範本同樣**必須**以 registry 的該白標 `rootAgentId` 套 hierarchy 錨點 JOIN（`@root_path` 由 `SELECT hierarchy_path FROM agents WHERE id = <rootAgentId>` 在 DB 衍生）。**共用連線白標（VWorld / WTT / VWSG，`sharesConnection: true`）未 scope 即禁用**——不加錨點的整庫聚合會混入同 DB 全部子樹（29 棵）與 RD 測試樹，正是 registry-driven scope 要擋的污染。整庫 census 屬罕見例外，須使用者顯式要求、移除 JOIN 並在輸出標示「整庫（含全部子樹）」。錨定式比對與 collation 雷點見 [`whitelabel-db-query`](../whitelabel-db-query/SKILL.md) 範疇規則的「Scoped template」段。

### 標準 SQL（參數化，單日）

```sql
SET @vendor_id    = 83;             -- 遊戲商 vendor_id
SET @summary_date = '2026-05-02';   -- 只給日期（本地日界），不帶時間
SET @use_utc      = 0;              -- 1 = 把 @summary_date 視為 SGT 日界，轉成 UTC range；0 = 把 @summary_date 直接視為 DB 原始日期
-- 白標 scope（共用連線白標必填）：<rootAgentId> 由 registry 代入；id 查無 → @root_path 為 NULL → JOIN 不匹配 → 空結果（fail-closed）。
-- 整庫 census 屬罕見例外：才整段移除本 SET 與下方 JOIN，並於輸出標示「整庫（含全部子樹）」。
SET @root_path    = (SELECT hierarchy_path FROM agents WHERE id = <rootAgentId>);

-- 預先算好範圍，避免在 WHERE 欄位上套函式（保留索引）
SET @range_start = CASE
    WHEN @use_utc = 1 THEN DATE_SUB(@summary_date, INTERVAL 8 HOUR)
    ELSE @summary_date
END;
SET @range_end = DATE_ADD(@range_start, INTERVAL 1 DAY);

SELECT
    DATE(CASE WHEN @use_utc = 1 THEN CONVERT_TZ(data_time,    '+00:00', '+08:00') ELSE data_time    END) AS data_date,
    DATE(CASE WHEN @use_utc = 1 THEN CONVERT_TZ(summary_time, '+00:00', '+08:00') ELSE summary_time END) AS summary_date,
    MIN(summary_time) AS summary_time_raw,   -- 避免 ONLY_FULL_GROUP_BY 報錯
    SUM(bet)          AS total_bet,
    SUM(win_lose)     AS total_win_lose,
    SUM(jackpot)      AS total_jackpot,
    SUM(commission)   AS total_commission,
    SUM(rolling_chip) AS total_rolling_chip,
    SUM(cost)         AS total_cost,
    SUM(record_count) AS total_record_count,
    COUNT(*)          AS rows_cnt,
    CONCAT(
        DATE_FORMAT(
            DATE(CASE WHEN @use_utc = 1 THEN CONVERT_TZ(summary_time, '+00:00', '+08:00') ELSE summary_time END),
            '%c/%e'
        ),
        ' 補',
        DATE_FORMAT(
            DATE(CASE WHEN @use_utc = 1 THEN CONVERT_TZ(data_time, '+00:00', '+08:00') ELSE data_time END),
            '%c/%e'
        ),
        ' b:',  SUM(bet)      + 0,
        ' wl:', SUM(win_lose) + 0,
        CASE WHEN SUM(jackpot)      + 0 <> 0 THEN CONCAT(' jp:',   SUM(jackpot)      + 0) ELSE '' END,
        CASE WHEN SUM(commission)   + 0 <> 0 THEN CONCAT(' comm:', SUM(commission)   + 0) ELSE '' END,
        CASE WHEN SUM(rolling_chip) + 0 <> 0 THEN CONCAT(' rc:',   SUM(rolling_chip) + 0) ELSE '' END,
        CASE WHEN SUM(record_count)      <> 0 THEN CONCAT(' recs:', SUM(record_count))     ELSE '' END,
        CASE WHEN SUM(bet) + 0 = 0 AND SUM(win_lose) + 0 = 0 AND SUM(jackpot) + 0 = 0
             AND SUM(commission) + 0 = 0 AND SUM(rolling_chip) + 0 = 0 AND SUM(record_count) = 0
             AND COUNT(*) > 0
             THEN CONCAT(' (cnt:', COUNT(*), ' netted,drilldown-required)') ELSE '' END
    ) AS remark   -- 格式：「掛帳日 補原始日 b:X wl:Y [jp:Z] [comm:W] [rc:U] [recs:N] [(cnt:N netted,drilldown-required)]」
FROM
    summary_bet_records
    JOIN members m ON m.id = summary_bet_records.member_id
    JOIN agents  a ON a.id = m.parent
        AND (a.hierarchy_path = @root_path COLLATE utf8mb4_unicode_ci
             OR a.hierarchy_path LIKE CONCAT(@root_path COLLATE utf8mb4_unicode_ci, '/%'))
WHERE
    summary_bet_records.vendor_id    = @vendor_id
    AND summary_bet_records.correction   = 1
    AND summary_bet_records.summary_time >= @range_start
    AND summary_bet_records.summary_time <  @range_end
GROUP BY
    data_date,
    summary_date;
```

> **diff 用 7 欄、persistence 用 6 欄**：`SummaryBetRecordService::applyHourlyCorrection()` 會比對 7 欄（bet / win_lose / record_count / rolling_chip / commission / jackpot / cost）任一有差就生 diff；但 `persistSummaryRecords()` 落地檢查只看 6 欄（不含 cost）——cost-only diff 理論上會被吞（罕見，cost 是 win_lose × gameFeeRate 的衍生欄位，正常隨 win_lose 一起變）。**所以實際落地的 correction row 由這 6 欄驅動**：bet / win_lose / record_count / rolling_chip / commission / jackpot。只看 bet/win_lose 會漏掉純 jackpot（PTSW 已驗證）、純 commission、純 rolling_chip、純 record_count（refund=0 的空 TBR）的補帳，誤判為「無變動」。

### 為什麼這樣寫

| 重點 | 原因 |
|---|---|
| WHERE 用 `summary_time` 直接比 range，**不套函式** | 走索引 (`vendor_id`, `summary_time`) |
| `data_date` / `summary_date` 用 `DATE(...)` 在 SELECT 才轉 | 顯示用，不影響掃描成本 |
| `MIN(summary_time)` | 多筆同 bucket 時避開 ONLY_FULL_GROUP_BY |
| `SUM(bet)+0` | 把 DECIMAL 轉純數字，concat 不帶尾零 |
| GROUP BY `data_date, summary_date` | 同時看「資料屬日」與「桶屬日」是否錯開（跨日訊號） |

### 多日批次查（區間）

把 `@summary_date >= ... AND < ...` 一次涵蓋多天，並把 GROUP 多加一層：

```sql
SET @vendor_id = 83;
SET @date_from = '2026-05-04';
SET @date_to   = '2026-05-06';   -- exclusive
SET @use_utc   = 0;
SET @root_path = (SELECT hierarchy_path FROM agents WHERE id = <rootAgentId>);  -- 白標 scope（共用連線白標必填；整庫 census 才移除本 SET 與 JOIN 並標示整庫）

SET @range_start = CASE WHEN @use_utc = 1 THEN DATE_SUB(@date_from, INTERVAL 8 HOUR) ELSE @date_from END;
SET @range_end   = CASE WHEN @use_utc = 1 THEN DATE_SUB(@date_to,   INTERVAL 8 HOUR) ELSE @date_to   END;

SELECT
    DATE(CASE WHEN @use_utc = 1 THEN CONVERT_TZ(summary_time, '+00:00', '+08:00') ELSE summary_time END) AS summary_date,
    DATE(CASE WHEN @use_utc = 1 THEN CONVERT_TZ(data_time,    '+00:00', '+08:00') ELSE data_time    END) AS data_date,
    SUM(bet)          AS total_bet,
    SUM(win_lose)     AS total_win_lose,
    SUM(jackpot)      AS total_jackpot,
    SUM(commission)   AS total_commission,
    SUM(rolling_chip) AS total_rolling_chip,
    SUM(record_count) AS total_record_count,
    COUNT(*)          AS rows_cnt,
    CONCAT(
        DATE_FORMAT(DATE(CASE WHEN @use_utc = 1 THEN CONVERT_TZ(summary_time, '+00:00', '+08:00') ELSE summary_time END), '%c/%e'),
        ' 補',
        DATE_FORMAT(DATE(CASE WHEN @use_utc = 1 THEN CONVERT_TZ(data_time,    '+00:00', '+08:00') ELSE data_time    END), '%c/%e'),
        ' b:',  SUM(bet)      + 0,
        ' wl:', SUM(win_lose) + 0,
        CASE WHEN SUM(jackpot)      + 0 <> 0 THEN CONCAT(' jp:',   SUM(jackpot)      + 0) ELSE '' END,
        CASE WHEN SUM(commission)   + 0 <> 0 THEN CONCAT(' comm:', SUM(commission)   + 0) ELSE '' END,
        CASE WHEN SUM(rolling_chip) + 0 <> 0 THEN CONCAT(' rc:',   SUM(rolling_chip) + 0) ELSE '' END,
        CASE WHEN SUM(record_count)      <> 0 THEN CONCAT(' recs:', SUM(record_count))     ELSE '' END,
        CASE WHEN SUM(bet) + 0 = 0 AND SUM(win_lose) + 0 = 0 AND SUM(jackpot) + 0 = 0
             AND SUM(commission) + 0 = 0 AND SUM(rolling_chip) + 0 = 0 AND SUM(record_count) = 0
             AND COUNT(*) > 0
             THEN CONCAT(' (cnt:', COUNT(*), ' netted,drilldown-required)') ELSE '' END
    ) AS remark
FROM summary_bet_records
  JOIN members m ON m.id = summary_bet_records.member_id
  JOIN agents  a ON a.id = m.parent
      AND (a.hierarchy_path = @root_path COLLATE utf8mb4_unicode_ci
           OR a.hierarchy_path LIKE CONCAT(@root_path COLLATE utf8mb4_unicode_ci, '/%'))
WHERE summary_bet_records.vendor_id    = @vendor_id
  AND summary_bet_records.correction   = 1
  AND summary_bet_records.summary_time >= @range_start
  AND summary_bet_records.summary_time <  @range_end
GROUP BY summary_date, data_date
ORDER BY summary_date, data_date;
```

---

## 6. 區間掃描與進階配方（跨多天 / hour bucket）

### 進階：含 hour bucket / cnt 的明細格式

當 OP remark 不夠用，需要看小時桶與筆數時，把上方 SQL/輸出換成：

```php
\$rows = DB::select(\"
    SELECT
        DATE(CASE WHEN ? = 1 THEN CONVERT_TZ(data_time, \\\"+00:00\\\", \\\"+08:00\\\") ELSE data_time END) AS data_date,
        DATE(CASE WHEN ? = 1 THEN CONVERT_TZ(summary_time, \\\"+00:00\\\", \\\"+08:00\\\") ELSE summary_time END) AS summary_date,
        HOUR(CASE WHEN ? = 1 THEN CONVERT_TZ(summary_time, \\\"+00:00\\\", \\\"+08:00\\\") ELSE summary_time END) AS hr,
        SUM(bet) total_bet, SUM(win_lose) total_win_lose,
        SUM(jackpot) total_jackpot, SUM(commission) total_commission,
        SUM(rolling_chip) total_rolling_chip, SUM(record_count) total_record_count, COUNT(*) rows_cnt
    FROM summary_bet_records {\$wlJoin}
    WHERE summary_bet_records.vendor_id = ? AND summary_bet_records.correction = 1 AND summary_bet_records.summary_time >= ? AND summary_bet_records.summary_time < ?
    GROUP BY data_date, summary_date, hr
    ORDER BY summary_date, hr, data_date
\", [(int) \$useUtc, (int) \$useUtc, (int) \$useUtc, \$vendorId, \$rangeStart, \$rangeEnd]);
```

`summary_date != data_date` 的列即跨日訂單。

### 區間掃描：跨多天的補帳紀錄

當使用者要查跨多天的補帳紀錄時，套用此配方。常見句型：

- 「查 PPSW 這個月補帳紀錄」「查 PPSW 五月份補帳」（自然月）
- 「查 PTSW 最近 7 天補帳紀錄」「PPSW 近 30 天補了多少」（相對 N 天）
- 「查 KissH5 5/9~5/15 的補帳」「查詢 PPSW 5/1 到 5/15 的補帳紀錄」（明確區間）

格式同 §4.1（SQL 表 + summary_date 分塊），差別只在掃描範圍變成多天。輸出每個 summary_date 一塊，塊間空一行，最後一塊後不再附加區間總計（要的話自己加總各塊 `M/D補` 行）。

**用「預設輸出：每 summary_date 一塊」那份腳本**，只改參數區：
- 明確區間、自然月：填 `$dateFrom`（起日）與 `$dateTo`（迄日，含當日），`$days` 維持 0。
- 最近 N 天：填 `$days`（由 prompt 帶入，無指定預設 14），會覆蓋 `$dateFrom`／`$dateTo`，範圍是 SGT 今天往回共 N 天（含今天）。
  - 「查 PPSW 最近 7 天補帳」→ `$days = 7`
  - 「KissH5 近 30 天補帳」→ `$days = 30`
  - 「PPSW 近期補帳」→ `$days = 14`

#### 輸出範例

```
=== SQL 結果 ===
data_date     summary_date             bet        win_lose         jackpot      commission    rolling_chip    recs    cnt
--------------------------------------------------------------------------------------------------------------------------
2026-04-02    2026-04-28            5.0000         -5.0000          0.0000          0.0000          0.0000       0      1
2026-04-03    2026-04-28           15.0000        -15.0000          0.0000          0.0000          0.0000       0      2
2026-04-03    2026-04-29           20.0000        -20.0000          0.0000          0.0000          0.0000       0      2
2026-05-19    2026-05-26            0.0000          0.0000         67.6600          0.0000          0.0000       0      1
2026-05-22    2026-05-26            0.0000          0.0000        282.6800          0.0000          0.0000       0      1

=== OP 補帳備註 ===
補4/2 b:5.0000 wl:-5.0000
補4/3 b:15.0000 wl:-15.0000
-----------------------------------
4/28補 b:20.0000 wl:-20.0000

補4/3 b:20.0000 wl:-20.0000
-----------------------------------
4/29補 b:20.0000 wl:-20.0000

補5/19 b:0.0000 wl:0.0000 jp:67.6600
補5/22 b:0.0000 wl:0.0000 jp:282.6800
-----------------------------------
5/26補 b:0.0000 wl:0.0000 jp:350.3400
```

#### 解讀提示

| 觀察 | 意義 |
|---|---|
| 沒有任何 `=== OP 補帳備註 ===` 塊 | 區間內 SBR 沒被調帳過（或調帳為 no-op） |
| 同一 `summary_date` 塊下多個 `data_date`（差距大） | 一次大規模 retro fix（fix-records / forced-settle 影響歷史多日） |
| 同一 `data_date` 在多個 `summary_date` 塊出現 | 該日 BR/TBR 被重複動到（注意是否跑了多次調帳） |
| 塊內 `bet + win_lose ≈ 0` | 純沖銷（refund 或舊值翻掉），無淨補入 |

---

## 9. 輸出範例與解讀（逐欄意義 / netting 互沖 / drilldown）

### 輸出範例

#### 範例 A：bet/wl 補帳（同一 summary_date 多個 data_date）

`查 PPSW 2026-05-02 補帳`（範例中 summary_date 皆為 5/2）：

```
=== SQL 結果 ===
data_date     summary_date             bet        win_lose         jackpot      commission    rolling_chip    recs    cnt
--------------------------------------------------------------------------------------------------------------------------
2026-04-05    2026-05-02            2.5000         -2.5000          0.0000          0.0000          0.0000       0      1
2026-04-10    2026-05-02          237.5000       -237.5000          0.0000          0.0000          0.0000       0      4
2026-04-11    2026-05-02          222.5000       -222.5000          0.0000          0.0000          0.0000       0      3
2026-04-12    2026-05-02          275.0000       -275.0000          0.0000          0.0000          0.0000       0      5
2026-04-13    2026-05-02          190.0000       -190.0000          0.0000          0.0000          0.0000       0      2
2026-04-14    2026-05-02          150.0000       -150.0000          0.0000          0.0000          0.0000       0      1

=== OP 補帳備註 ===
補4/5 b:2.5000 wl:-2.5000
補4/10 b:237.5000 wl:-237.5000
補4/11 b:222.5000 wl:-222.5000
補4/12 b:275.0000 wl:-275.0000
補4/13 b:190.0000 wl:-190.0000
補4/14 b:150.0000 wl:-150.0000
-----------------------------------
5/2補 b:1077.5000 wl:-1077.5000
```

#### 範例 B：純 jackpot 補帳（b/wl 皆 0、jp 非 0）

`查 PTSW 2026-05-26 補帳`：

```
=== SQL 結果 ===
data_date     summary_date             bet        win_lose         jackpot      commission    rolling_chip    recs    cnt
--------------------------------------------------------------------------------------------------------------------------
2026-05-19    2026-05-26            0.0000          0.0000         67.6600          0.0000          0.0000       0      1
2026-05-22    2026-05-26            0.0000          0.0000        282.6800          0.0000          0.0000       0      1

=== OP 補帳備註 ===
補5/19 b:0.0000 wl:0.0000 jp:67.6600
補5/22 b:0.0000 wl:0.0000 jp:282.6800
-----------------------------------
5/26補 b:0.0000 wl:0.0000 jp:350.3400
```

#### 範例 C：跨多個 summary_date（區塊重複，塊間空一行）

`查 PPSW 2026-05-01 ~ 2026-05-02 補帳`：

```
=== SQL 結果 ===
（略，照 SQL 排序逐列印出）

=== OP 補帳備註 ===
補4/30 b:50.0000 wl:-50.0000
-----------------------------------
5/1補 b:50.0000 wl:-50.0000

補4/5 b:2.5000 wl:-2.5000
補4/10 b:237.5000 wl:-237.5000
-----------------------------------
5/2補 b:240.0000 wl:-240.0000
```

### 輸出解讀

範例輸出：

```
data_date  | summary_date | total_bet | total_win_lose | total_jackpot | total_rolling_chip | total_record_count | remark
-----------+--------------+-----------+----------------+---------------+--------------------+--------------------+----------------------------
2026-05-02 | 2026-05-02   | 24.00     | -24.00         | 0.00          | 0.00               | 0                  | 5/2 補5/2 b:24 wl:-24
2026-05-09 | 2026-05-11   | -1.50     | -32.50         | 0.00          | 0.00               | 0                  | 5/11 補5/9 b:-1.5 wl:-32.5
2026-05-22 | 2026-05-25   | 0.00      | 0.00           | 302.14        | 0.00               | 0                  | 5/25 補5/22 b:0 wl:0 jp:302.14
2026-05-15 | 2026-05-15   | 0.00      | 0.00           | 0.00          | 0.00               | 1                  | 5/15 補5/15 b:0 wl:0 recs:1
```

### 落地驅動欄位 = 6 個（看 remark 看哪欄非 0）

`SummaryBetRecordService::applyHourlyCorrection()` 會比對 7 欄產生 diff，但 `persistSummaryRecords()` 用以下 **6 欄**判斷是否要落地 correction row（cost 不在內，cost-only diff 會被吞）：

`bet / win_lose / record_count / rolling_chip / commission / jackpot`

任一非 0 即會留 row。OP remark 也以這 6 欄為準（bet/wl 一律印；jp/comm/rc/recs 條件性附加）。

### 解讀（注意：個別 row 層級 vs GROUP 聚合層級不同）

**個別 row 層級**（`SELECT * WHERE correction=1 LIMIT 10` 那種）：

| 觀察 | 意義 |
|---|---|
| 6 欄全 0 的單列 | 不應發生；`persistSummaryRecords()` 會跳過 6 欄全 0 的寫入。若真撈到代表有人手動 INSERT 過或代碼有 bug |
| 任一欄非 0 | 該欄是 correction 的驅動因子 |

**GROUP 聚合層級**（各 recipe〔§4 與「§5 原始參數化 SQL 配方」〕預設輸出）：

| 觀察 | 意義 |
|---|---|
| `rows_cnt = 0` | 該 (summary_date, data_date) bucket 完全沒 correction row |
| `data_date` = `summary_date` | 正常補帳（同日訂單） |
| `data_date` ≠ `summary_date` | 跨日訂單（payout_time 跨 0 點，或 fix-records 同步 payout_time 後 hour bucket 變動） |
| `total_bet > 0` 且 `total_win_lose ≠ 0` | 一般 settle/win 補差 |
| `total_bet = 0`、`total_win_lose ≠ 0` | 純 win 補差 |
| **`b:0 wl:0` 但 remark 帶 `jp:` / `comm:` / `rc:` / `recs:`** | **純 jackpot / commission / rolling_chip / record_count 補差；不是「沒事」** |
| `total_bet < 0` 或 `total_win_lose` 與直覺反向 | 沖銷列（refund 或舊值被翻掉） |
| **`b:0 wl:0` 且 remark 無 jp/comm/rc/recs 後綴、`rows_cnt > 0`** | **同 group 內多筆 correction 互相抵消（net = 0）**；常見於「先補後 reverse」或多次調帳累積互消；**不可當 no-op**，需 drilldown 到 hour bucket 或個別 row 看實際變動量（見下方 §6.1） |
| `rows_cnt > 0` 但所有 SUM 都 0 且 drilldown 後個別 row 也 0 | 才是真正異常（不應發生） |

### 常見錯誤判讀

把 `b:0 wl:0` 直接視為「無變動」是錯的——兩種真實情境會這樣呈現：
1. 有後綴 `jp:` / `comm:` / `rc:` / `recs:`：純非錢欄位補帳（PTSW 已驗證）
2. 無後綴但 `rows_cnt > 0`：group 內互沖，drill down 才看得到（refund 翻舊值最常見）

### 6.1 Cancellation drilldown

當配方（§4 /「§5 原始參數化 SQL 配方」）預設輸出印出 `(cnt:N netted,drilldown-required)` 標籤，代表 group 內可能有互沖，跑下面任一條 drilldown SQL。

**前提**：配方的 group key 是 `DATE(summary_time)` 與 `DATE(data_time)`，所以 drilldown 必須用 **date range**（不可用等值，否則 00:00:00 以外的列查不到）。`@use_utc=1` 的 vendor，把 `@summary_date / @data_date` 換成已 +8 後的 SGT 日界對應 UTC range。

#### A. 看 hour bucket 級別（哪幾小時動了）

```sql
SET @vendor_id    = 144;
SET @summary_date = '2026-05-25';   -- 來自配方 group 的 summary_date
SET @data_date    = '2026-05-22';   -- 來自配方 group 的 data_date
SET @use_utc      = 0;
SET @root_path    = (SELECT hierarchy_path FROM agents WHERE id = <rootAgentId>);  -- 白標 scope（沿用上層配方的同一白標；drilldown 下方 A/B 共用此變數）

SET @summary_start = CASE WHEN @use_utc=1 THEN DATE_SUB(@summary_date, INTERVAL 8 HOUR) ELSE @summary_date END;
SET @summary_end   = DATE_ADD(@summary_start, INTERVAL 1 DAY);
SET @data_start    = CASE WHEN @use_utc=1 THEN DATE_SUB(@data_date,    INTERVAL 8 HOUR) ELSE @data_date    END;
SET @data_end      = DATE_ADD(@data_start, INTERVAL 1 DAY);

SELECT
    summary_time, data_time,
    SUM(bet) tb, SUM(win_lose) twl, SUM(jackpot) tjp, SUM(commission) tcomm,
    SUM(rolling_chip) trc, SUM(record_count) trecs,
    COUNT(*) cnt,
    SUM(ABS(bet)) + SUM(ABS(win_lose)) + SUM(ABS(jackpot))
      + SUM(ABS(commission)) + SUM(ABS(rolling_chip)) + SUM(ABS(record_count)) AS total_movement
FROM summary_bet_records
  JOIN members m ON m.id = summary_bet_records.member_id
  JOIN agents  a ON a.id = m.parent
      AND (a.hierarchy_path = @root_path COLLATE utf8mb4_unicode_ci
           OR a.hierarchy_path LIKE CONCAT(@root_path COLLATE utf8mb4_unicode_ci, '/%'))
WHERE summary_bet_records.vendor_id = @vendor_id AND summary_bet_records.correction = 1
  AND summary_bet_records.summary_time >= @summary_start AND summary_bet_records.summary_time < @summary_end
  AND summary_bet_records.data_time    >= @data_start    AND summary_bet_records.data_time    < @data_end
GROUP BY summary_time, data_time
HAVING total_movement > 0   -- 排除真零、只留「淨零但有動」
ORDER BY summary_time, data_time;
```

#### B. 直接列 individual rows（目視找互沖配對）

```sql
SELECT summary_bet_records.id, summary_bet_records.member_id, game_id, summary_time, data_time,
       bet, win_lose, jackpot, commission, rolling_chip, record_count
FROM summary_bet_records
  JOIN members m ON m.id = summary_bet_records.member_id
  JOIN agents  a ON a.id = m.parent
      AND (a.hierarchy_path = @root_path COLLATE utf8mb4_unicode_ci
           OR a.hierarchy_path LIKE CONCAT(@root_path COLLATE utf8mb4_unicode_ci, '/%'))
WHERE summary_bet_records.vendor_id = @vendor_id AND summary_bet_records.correction = 1
  AND summary_bet_records.summary_time >= @summary_start AND summary_bet_records.summary_time < @summary_end
  AND summary_bet_records.data_time    >= @data_start    AND summary_bet_records.data_time    < @data_end
ORDER BY summary_bet_records.member_id, game_id, summary_time;
```

> **驗證範例**：若 §4.1 對 vendor 144 / `2026-05-25` 輸出 `5/25 補5/22 b:0 wl:0 (cnt:5 netted,drilldown-required)`，套 A 把 `@summary_date='2026-05-25'`、`@data_date='2026-05-22'` 帶入，會列出該日該 hour bucket 的 SUM 與 movement 量（若有互沖就會看到 `tb=0` 但 `trc>0` 或 `trecs!=0` 等）；接著套 B 看是哪兩筆對沖。

> **cost 的特殊性**：`cost = -1 × win_lose × gameFeeRate`，是衍生欄位。diff 比對含 cost，但 persistence 不含 cost——所以「純 cost 變動」（理論上需要 win_lose 不變但 gameFeeRate 配置改動）的 correction 會被 `persistSummaryRecords` 吞掉，本 skill 自然也看不到。實務上極少發生，可忽略。

---

## 10. 同 DB 多白標／子站範疇切分（可選）

部分 DB connection（如 VWorld）底下掛 **≥2 棵白標代理子樹**、或單一白標再切多個 vendor 子站（見 [`whitelabel-container-exec`](../whitelabel-container-exec/SKILL.md) 的「同 DB 子站」一節）。SBR correction 以整個 vendor 為單位寫入，本 skill 的查詢一律先切白標（A），需要時再切子站（B）：

#### (A) 白標層切分 — 已內建於配方

白標層切分是「快速查詢配方」腳本的 `$scopeAgentId` 參數且**必填**：執行前 `Read ../../registry/whitelabels.metadata.yaml` 取該白標 `rootAgentId` 代入，配方自動以 `agents.hierarchy_path` 子樹錨定（`= '/1/<root>'` 或 `LIKE '/1/<root>/%'`、`COLLATE utf8mb4_unicode_ci`）。**rootAgentId 值一律以 registry 為準，本文件不列舉**；錨定式比對與 collation 的完整原理／雷點見 [`whitelabel-db-query`](../whitelabel-db-query/SKILL.md) 範疇規則的「Scoped template」段。

#### (B) 子站 vendor_info_id 切法（vendor-specific，registry 不持有）

子站 `vendor_info_id` 是 **vendor × 白標** 兩維、不屬白標 registry；以 `agent_vendors` 三方 mapping 在 DB 衍生（`$rootAgentId` 取自 registry 該白標的 `rootAgentId`）：

```php
$viId = DB::table('agent_vendors')
    ->where('agent_id', $rootAgentId)  // 白標 rootAgentId（見 registry）
    ->where('vendor_id', $vendorId)
    ->value('vendor_info_id');
```

**各 recipe 的 SQL 加子站 join**（`summary_bet_records` 無 `vendor_info_id` 欄位、走 `{vendor}_accounts` 篩 member_id）：

```sql
FROM summary_bet_records s
JOIN {vendor}_accounts pa ON pa.member_id = s.member_id AND pa.vendor_info_id = ?
WHERE s.vendor_id = ? AND s.correction = 1 AND s.summary_time >= ? AND s.summary_time < ?
```

> ⚠️ 補帳作業（執行 SBR 調帳寫入 correction）以整個 vendor 為單位、不能切子站；本 skill 的查詢輸出可切。要診斷走 `reconcile-bet-records`，要修補走 `repair-bet-records`。

---

## 7. 防呆檢查

- [ ] 確認 `@vendor_id` 對應正確的 PPSW=83 / KissH5=233 / 其他
- [ ] 不確定時區先用 `@use_utc=0`，再依 `data_date` 偏移判斷
- [ ] 大區間查詢（>30 天）優先加 `LIMIT 1000` 試跑，再決定是否分批
- [ ] **不要只看 b/wl**：correction 由 6 個欄位驅動（bet/wl/jp/comm/rc/recs），看到 `b:0 wl:0` 還要看 remark 後綴 jp/comm/rc/recs（逐欄解讀見下方「§9 輸出範例與解讀」）
- [ ] **netting（淨額互沖） 警示**：remark 顯示 `(cnt:N netted,drilldown-required)` 時，**不可當 no-op**，必須跑下方「§9 輸出範例與解讀」的 cancellation drilldown 確認是真零或互沖
- [ ] **PTSW 調帳列時序**：SBR 每小時派發，已關帳範圍的調帳列一律寫在關帳時間那一格 `summary_time = X-XX 16:00:00 UTC`（= SGT 隔日 00:00），所以一批 correction 會「集中掛在 SGT 隔日」（查補帳時若你預期是產生當日，會差一天看不到——對接卡見 §3 表）
- [ ] 補帳結果與 `summary_bet_record` 命令輸出（"新增/沖銷"）數字應對得上，否則代表中間有人手動動過 SBR

---

## 8. 相關命令與 skill

- 觸發 SBR 調帳（會產生 correction 列）：`php artisan summary_bet_record --vendor={id} --date={date}` 或 `--hour={hour}`
- 對帳診斷：`reconcile-bet-records`；修復寫入：`repair-bet-records`（本 skill 是它收尾「查補帳結果」那一步用的查詢工具）
- vendor 解析：`reconcile-bet-records` 的「Step 0：vendor 解析」與其 `reference/vendor_resolver.md`
