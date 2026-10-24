# ClotPlayLegendSlotSW（vendor_id=213）

> **卡型：專屬表卡（dedicated）** — BR 表一表一 vendor（`tname_vendor_count` = 1）、可直接 SUM、有 `settle_status` / `payout_time` / `valid_bet`。
> 對接卡。通用 SOP 見 `reconcile-bet-records` skill；本檔聚焦 Clot Play Legend Slot 的差異點。
> ⚠️ **本 vendor 的 `settle_status` 不是「0=未結算 1=已結算」**：回調從不寫入 1，結算與否要看 `payout_time` 是否仍是 `1970-01-01 00:00:00` 佔位值。直接套用通用 `status = 1` 謂詞會得到全空結果、造成假 drift。詳見「status / 結算流程」。

## ✅ 必填契約聲明區（Step 0 gate 檢查這份；缺任一項即 STOP）

> reconcile / repair 的 Step 0 gate 逐項檢查下列必填段是否存在且已填實值。缺任一項，skill 會停在 Step 0、要求補卡後才續。

**共用核心（兩型都必填）**

- [x] **識別**：`vendor_id=213`、`clotlssw` → `clotlssw_bet_records`、`is_single_wallet=1`（見「速查表」）
- [x] **TBR/SBR `time_field`**：`payout_time`（須與 `config/summary_bet_record.php` 的 time_field 同步，改 config 要回補本卡）（見「速查表」/「時區」）
- [x] **時區 + UTC 換算**：BR / TBR / SBR 時間欄位均 UTC；GMT+8「YYYY-MM-DD」整日 = UTC `[前一日 16:00:00, 當日 16:00:00)`（見「時區」）
- [x] **金額口徑**：`valid_bet` ≡ `bet`；`win_lose` = `win` − `bet`（負值＝玩家輸）；無 jackpot、無 commission，win 不含 jackpot（見「欄位 mapping」）
- [x] **三方回核**：三方 API `/get-transaction-details`，經 `php artisan fetch_bet_record --vendor=213 --dry-run` 乾跑比對（見「取得三方資料」/「欄位 mapping」/「對齊命令一覽」）
- [x] **未結算（pending）判定謂詞**：`settle_status = 0 AND payout_time = '1970-01-01 00:00:00'`，時間窗以 `bet_time` 切（見「status / 結算流程」）
- [x] **終驗 SQL**：已內聯 2026-09-04 實值，可直接執行（見「終驗 SQL」）

**專屬表卡專屬**

- [x] **BR 有效投注篩選謂詞**：`payout_time <> '1970-01-01 00:00:00' AND settle_status <> 2`（排除未結算佔位列與取消列；無 test 帳號或 valid_bet=0 的額外排除）
- [x] **BR `time_field`**：`payout_time`（與 TBR 同切口）
- [x] **status enum 全集**：`settle_status` 實際只出現 0（未結算或已結算，靠 `payout_time` 區分）/ 2（取消）；**1 從未被寫入**（見「status / 結算流程」）
- [x] **Phase C 可用性**：已註冊於 `config/summary_bet_record.php`（`time_field=payout_time`、`schedule_enabled=false`），通用 `summary_bet_record --vendor=213` 可用；不在整點排程內，僅明確指定 `--vendor` 時執行

## 速查表

| 項目 | 值 |
|---|---|
| `vendor_id` | 213 |
| `VendorIdEnum` case | `App\Enums\Vendor\VendorIdEnum::CLOT_LEGEND_SLOT_SW`（`app/Enums/Vendor/VendorIdEnum.php:220`） |
| `vendors.code` | `clotlssw` |
| `vendors.tname`（**BR 表前綴**） | `clotlssw` → `clotlssw_bet_records` |
| `vendors.third_party_code` | `CLOTLSSW` |
| `summary_bet_record` time_field | `payout_time`（`schedule_enabled=false`，見「Phase C 可用性」） |
| `vendors.is_single_wallet` | `1` (SW) |
| `vendor_infos` | 單筆（無子站切分） |
| 資料起點 | vendors 建立日 2026-01-26；BR 首筆 `bet_time` 2026-01-26；截至 2026-10-06 共約 452 萬列 |

> ⚠️ **同家族不同 vendor**：ClotPlay（142，`clotsw`）、Clot Play P2P SW（147，`clotp2psw`）與本卡的 Legend Slot（213，`clotlssw`）各自有獨立 vendor_id 與 BR 表，對帳時不可混查。Legend Slot 的 Driver 與 Controller 都繼承 ClotPlaySW 的實作，回調邏輯與取注單邏輯三者共用。

## 一行概述

Clot Play Legend Slot seamless 單錢包；slot；2026-01 接入；穩定運作，2026-09 對帳發現少量漏 settle 與疑似三方作廢未同步。

## 取得三方資料（Step 0）

- **來源**：三方 API `/get-transaction-details`，由 `app/Vendors/ClotPlaySW/Driver.php` 實作 `SupportsBetRecordFetch`（`fetchBetRecords` / `formatBetRecord` / `groupByRound`，約 223-430 行）；Legend Slot 的 Driver 繼承此實作。
- **命令**：

  ```bash
  # 台北日 2026-09-04 整天 = UTC [09-03 16:00, 09-04 16:00)；--start / --end 格式 yyyy-MM-dd@HH、一律 UTC
  php artisan fetch_bet_record --vendor=213 --dry-run --update --use-cache --start=2026-09-03@16 --end=2026-09-04@16
  ```

  命令本體 `app/Console/Commands/Vendor/FetchBetRecordCommand.php`。**單一錢包只放行 `--dry-run`**（`BetRecordFetchService` 對 `is_single_wallet=1` 擋下寫入：寫入會生出沒有錢包異動的孤兒注單）。乾跑只打三方與 DB 比對、不寫入，列出三份清單：「新增」（三方有、我方無）、「更新」（兩邊都有但金額不同，逐欄 from → to）、「三方查無」（我方有、三方整個窗口沒回；反向提示，窗口頭尾可能誤報）。
  - **`--date` 是 UTC 日、不是台北日**（命令所有時間參數皆 UTC）：對 OP 的台北日對帳一律用 `--start=<前一日>@16 --end=<當日>@16`，用 `--date` 會少掉台北 00:00–08:00 那段（2026-10-06 實測因此漏掉 09/04 00:26 的漏 settle 局）。
  - **不帶 `--update` 不比金額**：預設只比「有無」，漏 settle（兩邊都有、win 不同）不會列出；乾跑要帶 `--dry-run --update`。
  - `--use-cache` 把三方原始回應存 redis 一小時，同窗口重跑不再打三方、不佔限流額度。
- **手動匯出步驟**：無需手動匯出，一律走上述乾跑命令。需要對照舊系統時，RD-Studio 有 `php artisan clotsw_check_bet_record`（同一套比對邏輯，Driver `groupByRound` 即仿其作法）。
- **限制 / 注意**：
  - 單次查詢窗最多 **30 分鐘**（`betRecordApiWindowMinutes()`；Service 自動切片，命令端不用自己切）
  - 限流 **10 req / 60 秒**（`betRecordApiRateLimit()`）；跑全天約需 48 片，時間要抓足
  - 三方只保留 **60 天**交易（`betRecordMinStartTime()`），更早的窗口拉不到東西 —— 超過 60 天的差異無法三方回核
  - 憑證鍵為 `operatorToken`（`betRecordCredentialKey()`）：對帳 API 不吃 agent_code，回傳範圍由 token 決定，同 token 的商戶共用一批注單
  - **執行環境（本機可跑，不改 `.env`）**：`.env` 的 `VW_DB_*` 指向 dbc 代理 `dbc.local:3505`，但 dbc 只綁 host 的 0.0.0.0、且預設不掛 3505（VWorld 主庫）；容器內改走 `host.docker.internal:3403`（VWorld 從庫、唯讀 IAM，寫入會直接報錯）：

    ```bash
    docker exec -e DB_CONNECTION=VWorld -e VW_DB_HOST_READ=host.docker.internal -e VW_DB_PORT_READ=3403 \
      -e VW_DB_HOST_WRITE=host.docker.internal -e VW_DB_PORT_WRITE=3403 php-fpm8 bash -c \
      "cd /var/www/APIs && php artisan fetch_bet_record --vendor=213 --dry-run --update --use-cache --start=2026-09-03@16 --end=2026-09-04@16"
    ```

    先在 host `dbc list` 確認 3403 對應 `tp-wz3-online:xsgcasino-online-replicas`；跑之前用 tinker 唯讀查 `agents.id=66829` 應回 `Acompany`、`@@read_only` 應為 1。全天約 5 分鐘。

## 時區

| 對象 | 時區 |
|---|---|
| 三方回調 `created_at` / `updated_at` | 帶時區字串，Controller 以 `Carbon::parse(...)->timezone('UTC')` 轉 UTC 入庫 |
| 三方對帳 API `/get-transaction-details` | **Asia/Taipei**（Driver `API_TIMEZONE`；`fetchBetRecords` 內部自動把 UTC 窗口換成台北時間，呼叫端不用換） |
| BR.bet_time | UTC |
| BR.payout_time | UTC（未結算時為 `1970-01-01 00:00:00` 佔位值） |
| TBR.payout_time | UTC |
| SBR.data_time | UTC |
| `config/summary_bet_record.php` time_field | `payout_time`（未設 `reconcile_timezone` → 預設 +8；未設 `next_day_full_rerun`） |
| OP 對帳 excel / 遊戲商後台（BO） | GMT+8 日界 |

> **對帳換算公式**：Clot Play Legend Slot「2026-09-04」整日 = UTC `[2026-09-03 16:00:00, 2026-09-04 16:00:00)`
>
> 2026-09 全月實測：我方 API 加總 = SR11 數字；BO 與我方的差額只來自漏 settle 與疑似三方作廢（見「已知 incident」），GMT+8 切口本身一致。
>
> 唯讀查詢走 `mysql-vworld` MCP 時，raw DATETIME 顯示值會偏 −8 小時，取時間一律用 `DATE_FORMAT(...)` 取字串再判讀。

## 欄位 mapping（三方 → BR）

依 `app/Vendors/ClotPlaySW/Driver.php` 的 `formatBetRecord`：

| 三方欄位 | BR 欄位 | 備註 |
|---|---|---|
| `game_round_id` | `bet_id` | 字串（varchar UNI）；TBR 的 `reference_no` 對 BR.`bet_id` |
| `player_unique_id` | `username` | |
| `game` | `game_code` | |
| `total_bet_amount` / rate | `bet`、`valid_bet` | 兩欄寫同一值（`valid_bet` ≡ `bet`） |
| `winning` / rate | `win` | |
| `winning − total_bet_amount`（除 rate 後） | `win_lose` | 負值＝玩家輸 |
| `created_at` | `bet_time` | 轉 UTC |
| `updated_at` | `payout_time` | 轉 UTC；multi-bet 時取回合內最後一筆交易 |
| — | `rolling_chip` | 本系統禮碼欄位，三方不知道，**不比對、不覆蓋**（2026-09 全月為 0） |
| — | `settle_status` | 本系統自有狀態，**不比對、不覆蓋** |

**BR 欄位全集（DESCRIBE 實測）**：`id`、`bet_id` varchar(255) UNI、`username` varchar(255) MUL、`game_code`、`bet` / `valid_bet` / `win` / `win_lose` / `rolling_chip` decimal(18,4) NOT NULL default 0、`bet_time` datetime NOT NULL MUL、`payout_time` datetime NULL（MUL）、`info` text、`settle_status` tinyint NOT NULL default 0、`created_at`、`updated_at`。**無 `jackpot`、無 `commission`、無 `reference_no` 欄。**

**金額口徑**：

- `valid_bet` ≡ `bet`：`bet()`、`settle()`、`betAndSettle()` 都以同一值寫兩欄
- `win_lose` = `win` − `bet`，負值＝玩家輸
- 無 jackpot、無 commission，win 不含 jackpot
- 三方金額是乘過商戶 `rate` 的放大值：Driver `formatBetRecord` 取回時除以 `vendor_infos.rate`；Controller 入庫同樣 `Common::numDiv(..., rate, 4)`

## bet_id 處理

- 編碼：字串（`game_round_id`，形如 `nPRD01M1M1GFBS82HX4XNRY21TNYBB`）
- 大整數陷阱：無（非數字字串，不會被 PHP 轉 float）
- where 必須：無特別要求，Driver 已 `(string)` cast

## status / 結算流程

- enum：無獨立 status enum 檔
- ⚠️ **`ClotlsswBetRecord` 的 docblock 寫「settle_status 0=未結算 1=已結算 2=取消」，這是錯的**：回調代碼從不寫入 1，全表 452 萬列中 `settle_status = 1` 的列數為 0。

**回調實際寫法**（`app/Http/Controllers/External/SingleWallets/ClotPlayController.php`）：

| 回調 | 行號 | 對 BR 的寫入 |
|---|---|---|
| `bet()` | 103- | 新建列時 `bet_time` 與 `payout_time` 先寫 `'1970-01-01 00:00:00'` 佔位，再把 `bet_time` 設為三方 `created_at` 轉 UTC；`bet` = `valid_bet` = 扣款額、`win` = 0、`win_lose` = −bet；`payout_time` 維持 1970 |
| `settle()` | 178- | `payout_time` = 三方 `updated_at` 轉 UTC；三方 `valid_bet` 同時寫 `bet` 與 `valid_bet`；`win_lose` = win − bet；**`settle_status` 不動（維持 0）** |
| `betAndSettle()` | 239- | 同 `settle()`：一次寫完 bet / win / `payout_time`；`settle_status` 維持 0 |
| `cancel()` | 302- | `bet` / `valid_bet` / `win` / `win_lose` 歸 0、`settle_status` = 2、**不改 `payout_time`**；已是 2 的直接回餘額（冪等） |

四支回調都經 `TotalBetRecordService->create` 同步寫一列 TBR，所以 TBR 的 `payout_time` 與 BR 同步（pending 列的 TBR `payout_time` 也是 1970）。

**對帳謂詞**：

| 狀態 | 謂詞 | 時間窗 |
|---|---|---|
| 已結算（有效投注篩選） | `payout_time <> '1970-01-01 00:00:00' AND settle_status <> 2` | 以 `payout_time` 切 |
| 未結算（pending） | `settle_status = 0 AND payout_time = '1970-01-01 00:00:00'` | **必須以 `bet_time` 切** |
| 已取消 | `settle_status = 2` | 金額全 0，對 SUM 無影響 |

- **禁止**沿用通用範本的 `status = 1` 類謂詞：本表沒有 `status` 欄，`settle_status = 1` 全表 0 列，會得到全空結果、造成假 drift。
- **禁止**用 `payout_time` range 找 pending：pending 列的 `payout_time` 是 1970，任何當日窗都切不到；`payout_time IS NULL` 也永遠查不到東西（欄位允許 NULL 但實際從未為 NULL）。
- 取消若發生在結算後，`payout_time` 仍留在結算時刻、會落在當日窗內但金額為 0；取消若發生在結算前，`payout_time` 仍是 1970。
- 全表統計（2026-10-06）：pending 53 筆；cancelled 364 筆（其中 357 筆 `payout_time` 為 1970＝結算前取消、7 筆帶結算時刻＝結算後取消）。

**multi-bet：是**。三方以 transaction 為單位，同一 `game_round_id` 可有多筆交易；DB 一回合一列。Driver `groupByRound` 比對時先把回合內交易的 `total_bet_amount` / `winning` 加總、`updated_at` 取最後一筆，才與 BR 對齊。

## 對齊命令一覽

| 步驟 | 命令 |
|---|---|
| Step 0 取資料 | `php artisan fetch_bet_record --vendor=213 --dry-run --update --use-cache --start=<前一日>@16 --end=<當日>@16`（UTC 窗口＝台北日；唯讀；`--date` 是 UTC 日勿用於台北日對帳） |
| Step 1 校對 | 無 vendor 專屬 compare 命令；直接用 Step 0 乾跑輸出（列出三方有我方無、金額異動的注單） |
| Step 1.5 同步 payout_time（若需） | 無；`payout_time` 由 `settle()` / `betAndSettle()` 回調寫入，沒有獨立同步命令 |
| Step 2 修正（單筆 stuck） | 無 vendor 專屬 fix 命令。漏 settle 的補結算（含玩家派彩）屬 `repair-bet-records` Phase D，需三方真值、人工處置 |
| Step 2 修正（multi-bet，若有） | 無；回合內多筆交易由回調累加到同一列，比對時由 Driver `groupByRound` 加總，不需 backfill |
| Step 2 修正（三方作廢未同步） | 無；疑似三方作廢而 cancel 未送達的回合，同屬 `repair-bet-records` Phase D 人工處置，需先取得三方逐局真值 |
| Step 4 補帳（通用） | 先 `summary_bet_record --vendor=213 --date=YYYY-MM-DD --hour=H --dry-run` 試跑 → 確認差異後去掉 `--dry-run` 實跑 |

> ⚠️ **Step 4 命令安全規範（金流寫入，務必遵守）**
> - `--date` / `--hour` 輸入為 **UTC+8（台北）**，命令內部自動轉 UTC+0；DB 內 BR/TBR 時間為 UTC，下命令前須先換算窗口。
> - **禁止裸跑** `summary_bet_record --vendor=213`：缺 `--date` / `--hour` 時預設「今天（UTC+8）整天 24 小時」，做歷史對帳會打錯帳務窗口。
> - 命令對 `summary_bet_records` 按 (vendor, 小時) **刪除重建**、並可能寫入 correction 列；**務必先 `--dry-run` 確認差異筆數與時間窗無誤，再去掉 `--dry-run` 實跑**。
> - `--dry-run` 的實作是「開交易 → 算並寫入 → 回滾」：在本機唯讀從庫（dbc 3403、SELECT-only）跑會在 INSERT 時報 1142 權限錯誤，但錯誤前印出的「新增或補差值 N 筆」已是正確的差異筆數；要完整預演或實寫須在有寫入權限的主機執行。
> - 本 vendor 已註冊於 `config/summary_bet_record.php`（`schedule_enabled=false`），通用命令可用；`schedule_enabled=false` 只表示不納入整點排程、僅在明確指定 `--vendor` 時執行（後台補跑用），不影響手動補帳。

## 白標範疇

- VWorld DB 與 WTT、VWSG 共用 connection；本 vendor `vendor_infos` 只有一筆，無子站切分需求。
- 2026-09 五個抽查日的 TBR 全部落在 VWorld 主站子樹（root agent 66829 `Acompany`，`hierarchy_path` = `/1/33893/66829`）。
- 需要切白標時，走 `reconcile-bet-records` skill §0.5 (A) 的 `agents.hierarchy_path` 子樹切法。
- 唯讀查詢走 `mysql-vworld` MCP。

## 終驗 SQL

以 GMT+8 2026-09-04 為例，UTC 窗 `'2026-09-03 16:00:00'` ～ `'2026-09-04 16:00:00'`（換日期時三段窗口一起改）：

```sql
-- 單日三表 SUM 對齊（BR=TBR=SBR 才算通過）
-- 本 vendor 無 jackpot / commission 欄：三段改比 rolling_chip，TBR / SBR 另列 commission、jackpot（BR 段填 NULL），兩者應恆為 0
-- 三段時間窗皆為 UTC（BR/TBR.payout_time、SBR.data_time 均為 UTC）
SELECT 'clotlssw_bet_records' AS source, COUNT(*) AS cnt,
  SUM(bet) AS sum_bet, SUM(valid_bet) AS sum_valid_bet,
  SUM(win_lose) AS sum_winlose, SUM(rolling_chip) AS sum_rc,
  NULL AS sum_comm, NULL AS sum_jp
FROM clotlssw_bet_records
WHERE payout_time >= '2026-09-03 16:00:00' AND payout_time < '2026-09-04 16:00:00'
  AND payout_time <> '1970-01-01 00:00:00' AND settle_status <> 2
UNION ALL
SELECT 'total_bet_records (vendor=213)', COUNT(*),
  SUM(t.bet), SUM(t.valid_bet), SUM(t.win_lose), SUM(t.rolling_chip),
  SUM(t.commission), SUM(t.jackpot)
-- ★ TBR 全量 by vendor_id + 時窗，不 JOIN BR：INNER JOIN BR 會把孤兒 TBR（bet_record_id 指向不存在 BR）排除、掩蓋 TBR-only drift
FROM total_bet_records t
WHERE t.vendor_id = 213
  AND t.payout_time >= '2026-09-03 16:00:00' AND t.payout_time < '2026-09-04 16:00:00'
UNION ALL
-- ⚠️ summary_bet_records 無 valid_bet 欄；其 bet 欄存的即「有效投注」
--   （SummaryBetRecordService：SUM(total_bet_records.valid_bet) AS bet）。
--   故原始 bet 欄填 NULL、SBR.bet 對齊到 valid_bet 欄比對。
-- ⚠️ SBR 是彙總列（一列 = 一個 member × game × 小時），筆數取 SUM(record_count)，不是 COUNT(*)
SELECT 'summary_bet_records (vendor=213)', SUM(record_count),
  NULL AS sum_bet, SUM(bet) AS sum_valid_bet, SUM(win_lose) AS sum_winlose,
  SUM(rolling_chip) AS sum_rc, SUM(commission) AS sum_comm, SUM(jackpot) AS sum_jp
FROM summary_bet_records
WHERE vendor_id = 213
  AND data_time >= '2026-09-03 16:00:00' AND data_time < '2026-09-04 16:00:00';
```

> ✅ **通過條件**：`cnt` / `sum_valid_bet`（SBR 為其 `bet` 欄）/ `sum_winlose` / `sum_rc` 須 **BR = TBR = SBR** 相等；`sum_bet`（原始投注）僅 BR=TBR（SBR 不存原始 bet，列為 NULL）；`sum_comm` / `sum_jp` 在 TBR / SBR 應為 0。任一層不等即未通過。**跑完 Step 4 後務必回到此 SQL 複驗 SBR 列** —— 命令失敗、時間窗錯誤、欄位 drift 都只會在 SBR 列現形，僅比 BR/TBR 會漏掉。
>
> **2026-09-04 實測通過值**：三表 `cnt` 24,276、`sum_valid_bet` 69,882.04、`sum_winlose` −3,378.05、`sum_rc` / `sum_comm` / `sum_jp` 皆 0。
>
> 若當日窗內有「結算後取消」的列（`settle_status=2` 且 `payout_time` 非 1970），BR 段已把它排除、TBR / SBR 仍會計入一筆金額為 0 的列，`cnt` 會差該筆數而金額相等；遇到 `cnt` 不等先用 `settle_status = 2` 檢查這種情形。

**未結算（pending）查詢**（時間窗必須用 `bet_time`）：

```sql
SELECT settle_status, COUNT(*), SUM(bet), MIN(bet_id)
FROM clotlssw_bet_records
WHERE bet_time >= '2026-09-03 16:00:00' AND bet_time < '2026-09-04 16:00:00'
  AND settle_status = 0 AND payout_time = '1970-01-01 00:00:00'
GROUP BY settle_status;
```

> 2026-09-04 實測：1 筆、bet 1.00、`nPRD01M1M1GFBS82HX4XNRY21TNYBB`（即下方 incident 的漏 settle 注單）。

## 已知 incident / 注意事項

- **2026-09 對帳總結**：全月三表（BR = TBR = SBR）逐日全等，Step 2.5 逐筆與分組查詢 0 row；差額全在 BR ↔ 三方邊界。
- **漏 settle（2026-09）**：BO 投注比我方多 N、輸贏比我方少 (N − win)，與我方 pending 列逐筆吻合。
  | 日期（GMT+8） | bet_id | bet | 推得三方派彩 |
  |---|---|---|---|
  | 09/04 | `nPRD01M1M1GFBS82HX4XNRY21TNYBB` | 1.00 | 0.20 |
  | 09/09 | `nPRD01M22MD96N4GWXG9K6JK6XGXWW` | 2.00 | 1.20 |
  | 09/23 | `nPRD01M36BAVNMJSHMCVWBY0F8VF7T` | 1.00 | 0.20 |

  2026-10-06 乾跑「更新」清單逐筆證實：三局三方 win 分別為 0.20 / 1.20 / 0.20、我方皆為 0，無其他新增或作廢。**三方回覆承認未發送 settlement，並於 2026-10-06 16:31（台北）補送**：回調帶原始 `updated_at`，故 BR / TBR 的 `payout_time` 落回 9 月原時刻、win 已更新、錢包各 +0.20 / +1.20 / +0.20（`transaction_logs` type 27）。補送後 TBR 多三局、SBR 三個小時桶（UTC 09-03 16、09-09 08、09-23 05）各差 1 局，需走 Phase C 重建：`summary_bet_record --vendor=213 --date=2026-09-04 --hour=0`、`--date=2026-09-09 --hour=16`、`--date=2026-09-23 --hour=13`（已關帳桶，各寫 1 列 correction）。09/03、09/11 形態相同。影響：玩家少收派彩、該日報表少一筆投注；pending 列的 TBR `payout_time` 同為 1970，不進任何日報表。補結算屬 `repair-bet-records` Phase D，需三方真值、人工處置。
- **我方多、BO 少（2026-09）→ 結論：我方正確、三方後台漏算，不需動帳**：已排除跨日切口與結算後取消；2026-10-06 乾跑（`--dry-run --update`，台北日 UTC 窗口）的「三方查無」清單逐局鎖定，加總與 BO 差額分毫不差。七局回調都標 `is_round_end: false`。**三方回覆**：這些局在 `/get-transaction-details` 顯示未完成，是因為玩家沒看完 free spin 表演就退出，不影響單一錢包交易，全部為正常 settle、沒有 cancel；三方已完成更新，重跑乾跑後 API 已回傳這七局。**判讀規則**：「三方查無」且回調 `is_round_end: false` 的局，先請三方「完成」該局並重查 API／BO，不要當作廢退款。

  | 台北日 | bet_id | 玩家 | 遊戲 | bet | win |
  |---|---|---|---|---|---|
  | 09/17 14:43 | `nPRD01M2Q1NF738932V19ENA94MNN4` | CLOTLS00000021 | rv-3-kingdoms-of-fortune | 1.00 | 0 |
  | 09/20 21:18:26 | `nPRD01M2ZFFKKQ6777FJRF2PZQ3ZZ0` | CLOTLS00008722 | rv-thor-hammer-strike-xtreme-power-pots | 0.50 | 0 |
  | 09/20 21:18:40 | `nPRD01M2ZFG145AZT5GYKKHD30W5AR` | 同上 | 同上 | 0 | 0 |
  | 09/20 21:18:43 | `nPRD01M2ZFG3W6D41735Q8RYNKNVZS` | 同上 | 同上 | 0 | 0 |
  | 09/20 21:18:45 | `nPRD01M2ZFG688XJAJ3WS1FBTHZ83P` | 同上 | 同上 | 0 | 1.01 |
  | 09/20 21:18:54 | `nPRD01M2ZFGF32HYVFF0JPSYHQE1BV` | 同上 | 同上 | 0 | 0.12 |
  | 09/20 21:19:02 | `nPRD01M2ZFGQ8R6DFM7S3PWTGK12TS` | 同上 | 同上 | 0 | 7.94 |

  09/20 六局是同一組特色關卡（首局 0.50 觸發、後五局免費轉）。三方確認正常結算後，OP 需重抄 09/17、09/20 的 BO 數字核對（應各補回 bet 1.00 / wl −1.00 與 bet 0.50 / wl +8.57）。
- **用通用 `status = 1` 謂詞診斷會跑出全空假 drift**：見「status / 結算流程」。
- **`ClotlsswBetRecord` docblock 的 settle_status 描述錯誤**（寫 1=已結算，實際從不寫 1）：以本卡為準。

## 關鍵檔案

| 用途 | 路徑 |
|---|---|
| Controller（只覆寫 vendorId / Model / actionPrefix） | `app/Http/Controllers/External/SingleWallets/ClotPlayLsController.php` |
| Controller 主邏輯（bet / settle / betAndSettle / cancel） | `app/Http/Controllers/External/SingleWallets/ClotPlayController.php` |
| 路由（`clotls` prefix：`api/seamless/getBalance`→balance、`bet`、`settlement`→settle、`betSettlement`→betAndSettle、`cancel`） | `routes/external/sw.php:61-66` |
| BR Model | `app/Vendors/Models/ClotlsswBetRecord.php` |
| Driver | `app/Vendors/ClotPlayLegendSlotSW/Driver.php`（extends `app/Vendors/ClotPlaySW/Driver.php`） |
| 取三方注單實作（`fetchBetRecords` / `formatBetRecord` / `groupByRound`） | `app/Vendors/ClotPlaySW/Driver.php` |
| 取三方注單命令 | `app/Console/Commands/Vendor/FetchBetRecordCommand.php` |
| status enum | 無獨立檔（語意見「status / 結算流程」） |
| SBR 設定 | `config/summary_bet_record.php` 的 `CLOT_LEGEND_SLOT_SW` 區塊 |
| BR 表 migration | `database/migrations/2026_01_23_000001_create_clotlssw_bet_records_table.php` |
