# PGSSW aggregator 共用表對帳卡（範例 vendor：BWG(target)，vendor_id=145）

> **卡型：aggregator 共用表卡** — 一張 BR 表 `pgssw_bet_records` 由 4 個 vendor 共用，無 `vendor_id` 欄、靠 `vendor_info_id` 歸戶、BR 不能直接 SUM。
> **本卡最重要的一條**：PGSSW 的整點 `summary_bet_records`（SBR）**不經 TBR**，由舊系統直接從 BR 聚合。拿 TBR 比 SBR 會產生假 drift，正確邊界是 **BR ↔ SBR**。
> ⚠️ **但 vendor 145 另有 apis 的每日整天重跑**（從 TBR 以 `payout_time` 重算前一整天），兩套系統可能都在寫 145 的 SBR，見「SBR 由誰寫」。
> 通用 SOP 見 `reconcile-bet-records` skill；vendor 差異寫在這裡。

## ✅ 必填契約聲明區（Step 0 gate 檢查這份）

**共用核心**

- [x] **識別**：範例 `vendor_id=145`、`tname=pgssw` → `pgssw_bet_records`、`is_single_wallet=1`（見「速查表」）
- [x] **TBR/SBR `time_field`**：SBR 實際切口為 **`BR.settlement_time`**（四個 vendor 的排程都由舊系統寫，見「SBR 由誰寫」）。apis `config/summary_bet_record.php` 註冊了 vendor 50 與 145（都是 `payout_time`、`schedule_enabled=false`＝不進整點排程），145 另設 `next_day_full_rerun=true`＝每天由 apis 重算前一整天；91／177 未註冊。apis 與舊系統的來源與切口不同，補帳前先看「SBR 由誰寫」的警告
- [x] **時區 + UTC 換算**：見「時區」
- [x] **金額口徑**：見「欄位 mapping」
- [x] **三方回核**：**無自動對源** — apis 沒有 PGSSW 取三方注單的命令，逐筆三方比對不可行，僅能靠 OP 從遊戲商後台抄表（見「取得三方資料」）
- [x] **未結算判定謂詞**：`status <> 1`（見「status / 結算流程」）
- [x] **終驗 SQL**：見「終驗 SQL」（內聯可執行）

**aggregator 專屬**

- [x] **vendor 歸戶謂詞**：`pgssw_bet_records.vendor_info_id IN (該 vendor 的 vendor_infos.id)`（見「共用表篩 vendor」）
- [x] **共用表特有排除條件**：無（除 `status=1`）；細節與待確認項見「共用表篩 vendor」
- [x] **適用 `vendor_id` 清單 + 範例 vendor**：50 / 91 / 145 / 177，範例 145（見「共用表」）
- [x] **BR 結構差異聲明**：無 `valid_bet` 欄（見「BR 結構」）
- [x] **BR→TBR 機制**：非 SP，走 PHP `TotalBetRecordService`（見「對齊命令一覽」）
- [x] **切口欄位**：SBR 對齊用 `settlement_time`；BR↔TBR 用 `bet_record_id` join（見「時區」）

## 速查表（範例：BWG(target)）

| 項目 | 值 |
|---|---|
| `vendor_id` | 145 |
| `VendorIdEnum` case | `App\Enums\Vendor\VendorIdEnum::BWG` |
| `vendors.name` / `code` | `BWG` / `bwg` |
| `vendors.tname`（共用 BR 表前綴） | `pgssw` → **`pgssw_bet_records`（共用表）** |
| `vendors.third_party_code` | `PGSSW`（共用表上 4 筆同值） |
| apis `summary_bet_record` time_field | 145：`payout_time`，`schedule_enabled=false`、`next_day_full_rerun=true`（每日整天重跑）；同表 vendor 50：`payout_time`、`schedule_enabled=false`；整點 SBR 由舊系統寫，切口 `settlement_time` |
| `vendors.is_single_wallet` | `1`（單錢包） |

## 一行概述

單錢包聚合遊戲商，4 個 vendor 共用 `pgssw_bet_records`；TBR 由 apis 回調當下即時寫入，SBR 由 RD-Studio-01 舊系統直接從 BR 聚合（不經 TBR）。

## ⚠️ PGSSW aggregator 共用表

`tname='pgssw'` 不是單一遊戲商，而是共用一張 `pgssw_bet_records` 的 4 個 vendor：

| vendor_id | name | code |
|---|---|---|
| 50 | Pegasus | `pgssw` |
| 91 | Tiger9 | `tiger9` |
| 145 | BWG | `bwg` |
| 177 | JokerBaccarat | `JokerBaccarat` |

`third_party_code='PGSSW'` 在 `vendors` 表上是 4 筆同值。

**對齊單一 vendor 絕對不能直接 `SUM(pgssw_bet_records)`** — 會把 4 個 vendor 的注單全部加起來。

### 共用表篩 vendor

與多數 aggregator 不同，**`pgssw_bet_records` 自帶 `vendor_info_id` 欄**（有索引），不需 join accounts 表即可歸戶：

```sql
-- 先取該 vendor 的 vendor_infos.id 清單（一個 vendor 可能多筆＝多子站／多幣別）
SELECT id, vendor_id, name, currency, status FROM vendor_infos WHERE vendor_id = 145;
-- 實例（VWorld DB）：452 = BWG(target)-MY（VWorld 主站）、453 = BWG(target)-WTT（WTT/Wintoto 子站），皆 MYR
-- 實例（TPower DB，2026-09-07 實查）：145 只有一筆 453 = TPOWER_BWG(target)（MYR）；
--   同表其他 vendor：50 → 355 PEGASUS-TPOWER、91 → 431 TPOWER_Tiger9、177 → 472 JokerBaccarat(target)
-- ⚠️ 453 在 VWorld 是 WTT 子站、在 TPower 是 BWG 唯一歸戶：數字相同、意思不同。換白標必重查，禁止沿用別站的清單

-- BR 層歸戶
SELECT COUNT(*) AS cnt, ROUND(SUM(bet), 4) AS sum_bet, ROUND(SUM(win_lose), 4) AS sum_win_lose
FROM pgssw_bet_records
WHERE vendor_info_id IN (452, 453)
  AND status = 1
  AND settlement_time >= ? AND settlement_time < ?;
```

**排除條件**：除 `status = 1` 外無其他排除。

**兩個範疇要點**：

- **子站**：同一 `vendor_id` 的多筆 `vendor_infos` 可能分屬不同白標子站（452 主站 / 453 WTT）。SBR **沒有** `vendor_info` 維度，`vendor_id=145` 的 SBR 是兩子站合計；要按子站分帳，只有 BR 側能拆，SBR 側拆不出來。
- **幣別**：跨幣別不可加總；145 目前兩筆 `vendor_infos` 同為 MYR，其他 vendor 需先查 `currency` 再決定是否分開跑。

### 與 SBR 生成端的歸戶差異（可能造成微小 drift）

本卡的 BR 查詢用 `BR.vendor_info_id` 直接歸戶；**SBR 生成端（舊系統）走的是** `pgssw_accounts.username → vendor_infos`，並且 `INNER JOIN members` 與 `agents`。差異是：**帳號沒有對應 member / agent 的注單，會被 SBR 排除、卻仍出現在本卡的 BR 查詢裡**。

2026-08 實測（VWorld、vendor 145）多數日子 BR 與 SBR 逐小時完全相等，代表當時沒有這類孤兒帳號。若日後對出小額恆定差異，先查這一項再懷疑排程。

## SBR 由誰寫（本卡最關鍵的一節）

`summary_bet_records` 的寫入來源分散在兩個專案，靠各自的 `config/summary_bet_record.php` 白名單切分。RD-Studio-01 的兩個 config 都沒有 PGSSW 的 4 個 vendor，因此**整點排程一律由 RD-Studio-01 舊版命令寫**：

| 專案 | 命令 | 是否負責 PGSSW |
|---|---|---|
| apis | `summary_bet_record` | 91／177 ❌ 未註冊；**50 已註冊但 `schedule_enabled=false`**（`80aaa0ae5`，`target/tickets#4303`）＝不進整點排程、只有手動 `--vendor=50` 才會跑；**145 已註冊、`schedule_enabled=false`，但設了 `next_day_full_rerun=true`**（`e0f15c60b`，`target/tickets#4357`）＝每天 UTC 00:25 由 `summary_bet_record:rerun-full-day` 重算前一整天。apis 一律從 **TBR** 以 `payout_time` 切 |
| RD-Studio-01 | `new_summary_bet_record` | ❌ 未註冊 |
| RD-Studio-01 | `summary_bet_record`（標 `@deprecated`） | ✅ **4 個 vendor 的排程與補帳都走這支** |

舊版命令的 PGSSW 區塊（`_release/app/Console/Commands/SummaryBetRecordCommand.php:2461` 起；vendor 145 走 `:2526` 的查詢）從 `pgssw_bet_records` join `pgssw_accounts` → `vendor_infos` → `members` → `agents` 聚合，條件 `b.status = 1`、`settlement_time >= start AND < end`，寫入 `record_count = COUNT(*)`、`bet = SUM(b.bet)`、`win_lose = SUM(b.win_lose)`、`rolling_chip = SUM(b.rolling_chip)`、`jackpot = 0`。

**所以 SBR 的對齊對象是 BR，不是 TBR。**

> ⚠️ **不要把 PGSSW 註冊進 RD-Studio-01 的 config** — 舊版命令的 gate 是「vendor 在 RD-Studio-01 config 內就拒跑、叫你改用 new 版」，但 new 版沒有 PGSSW 區塊，註冊後是雙缺不是修好。
> ⚠️ **apis 與舊系統口徑不同**：apis 從 TBR 以 `payout_time` 切、舊系統從 BR 以 `settlement_time` 切，同一個 `data_time` 桶兩邊算出來的內容不同。
> - vendor 50：只有手動補跑才會用到 apis，補之前先確認由誰寫。
> - **vendor 145：每天都有兩套在寫**——舊系統整點寫、apis 隔天整天重算。重算後那一天的 SBR 是 apis 口徑還是會被舊系統再蓋回去、兩邊結果差多少，本卡**未驗證**。對 145 對帳時，先確認那一天最後是誰寫的，再決定比對 BR 還是 TBR。

### 補 SBR 的命令

```bash
# 在 RD-Studio-01（_release/）、且該白標 DB 有寫入憑證的環境執行
php artisan summary_bet_record --start=2026-08-19@14 --vendor=145
```

- `--start=Y-m-d@H` 為 **UTC**，一次只跑一個小時（`end = start + 1 hour`），該值同時寫成 `data_time`
- **閘門只有一道**（2026-09-07 讀 code 實證）：vendor 若在 RD-Studio-01 的 `config/summary_bet_record` 內就拒跑；PGSSW 四個 vendor 都不在，可跑。**沒有** 3 天代理帳密驗證、**沒有** 30 天封鎖、**沒有**互動式 confirm（命令直接繼承 `Illuminate\Console\Command`，不是帶 `checkEnv()` 詢問的 `BaseCommand`），沒有 TTY 也能跑。本卡舊版寫的「早於 3 天需代理帳密、早於 30 天直接擋」在 RD-Studio-01 整個 `app/Console` 都找不到，已刪除。
- **關帳判斷**：`checkIsCorrection(start)`——`start` 早於「關帳時間」就走 `CorrectionData()` 調帳，否則先刪 `summary_time = start AND correction != 1` 的列再整批重寫（刪除鍵是 `summary_time`，不是 `data_time`）。關帳時間＝取 `(執行時刻 − 6 小時)` 的 **UTC 日期**（RD-Studio-01 `app.timezone=UTC`），把該日 `00:00` 當台北時間再 `− 8 小時` 得 UTC。例：UTC 09-07 09:00 執行 → `2026-09-06 16:00:00`；UTC 09-07 03:00 執行 → `2026-09-05 16:00:00`（UTC 00:00～06:00 執行會多退一天）。09-05 的桶在這兩例下都走調帳。
- **調帳寫法**：該 (member, game) 分組原本沒有列 → 寫全額、`correction=1`（整桶缺失時就是每個分組都寫全額）；原本有列 → 逐 (member, game) 比對 bet／record_count／win_lose／rolling_chip／commission／jackpot／cost 七欄，只寫差額列；原有分組在新結果裡消失 → 寫負數沖銷列。PGSSW 區塊印出的最後一行是 `corrections : N / corrections_2 : M / records : K`（其後還會印 `call sp calc unlimit start`），`corrections_2` 是沖銷列數，正常應為 0，非 0 先停下查原因。調帳列 `data_time`＝桶、`summary_time`＝關帳時間（不是執行時刻，見「已知 incident」）。
- **副作用**：命令結尾會無條件呼叫 `SP_summary_bet_record_team`（全民代理彙總）與 `SP_summary_rolling_chip_record`（禮碼彙總）（`SummaryBetRecordCommand.php:3508-3517`）；走調帳時參數是關帳時間，不是你補的那個桶。補帳前要知道這兩張彙總表也會被重算。
- **在哪跑**（TPower 實例，其他白標的 VM 座標見 `deploy-rd-studio` skill）：tpower VM `rd-sudio-01-tpower`（GCP 專案 `tp-wz3-tpower`）的 `/var/www/html`。`html` 的 `DB_DATABASE=xsgcasino` 是 TPower 正式庫，`html-hk` 是另一個庫 `xsgcasino_hk`，crontab 的 `schedule:run` 只跑 `html`，補帳也在 `html` 跑。一次一桶、看完 stdout 再跑下一桶：

  ```bash
  gcloud compute ssh --zone "asia-southeast1-a" "rd-sudio-01-tpower" --project "tp-wz3-tpower" \
      --command="cd /var/www/html && sudo php artisan summary_bet_record --start=2026-09-05@14 --vendor=145"
  ```

## 取得三方資料（Step 0）

- **來源**：遊戲商後台，由 OP 抄表寫入公司對帳 excel
- **命令**：**無** — apis 沒有 PGSSW 的取三方注單命令（`app/Console/Commands/Vendor/` 下無 Pegasus / PGSSW 目錄）
- **限制**：**逐筆三方比對不可行**。BR 是否忠實反映遊戲商真值，只能靠 OP 抄表的 aggregate 對比，等於純信任 callback 寫入。因此 PGSSW 的對帳最高只能到 `reconcile-bet-records` skill 定義的「內部 rollup pass」，無法達到 source-of-truth 結案；若要嚴格驗證，須先建立三方取單管道。

## 時區

| 對象 | 時區 |
|---|---|
| `pgssw_bet_records` 的 `bet_time` / `payout_time` / `settlement_time` | UTC |
| `total_bet_records` 的 `bet_time` / `payout_time` | UTC（由 BR 帶入） |
| `summary_bet_records.data_time` | UTC（舊版命令 `--start` 的值） |
| OP 對帳 excel | 通常 GMT+8，**每次對帳先跟 OP 確認** |

換算：GMT+8 某日整日 = UTC `[前一日 16:00, 當日 16:00)`。

反向查對（診斷時最常用）：UTC 小時 `+8` 就是 GMT+8 小時，例 UTC `2026-08-19 14:00` = GMT+8 `08-19 22:00`；UTC `2026-08-20 16:00` = GMT+8 `08-21 00:00`。**跨日的那一小時最容易讓 OP 與工程師講不同的日期**。

## 欄位 mapping

### BR 結構（`pgssw_bet_records`）

```
id, vendor_info_id, bet_id(varchar191, UNIQUE), session_token, username, game_code,
bet_time, payout_time, settlement_time, bet, win, win_lose, rolling_chip, status,
info(text), created_at, updated_at
```

索引（2026-09-07 以 `information_schema.statistics` 實查，TPower 與 VWorld 相同，與 APIs migration `2025_06_19_091321_create_pgssw_bet_records_table.php` 一致）：`PRIMARY(id)`、`bet_id`(UNIQUE)、`vendor_info_id`、`updated_at`、**`settlement_time`**、`bet_time`、`username`、`game_code`。本卡 2026-08 記的「只有前四個、`info` 為 varchar(1500)」已過期；換站或動索引前仍先 `SHOW INDEX FROM pgssw_bet_records` 確認。

- **無 `valid_bet` 欄**：SBR 的 `bet` 欄取 `SUM(BR.bet)`，TBR 的 `valid_bet` 由 controller 寫入（多數情境等於 `bet`，部分 action 另算）
- **無 `member_id` 欄**：要對到會員須經 `pgssw_accounts.username`
- **`settlement_time`** 是 SBR 的切口（索引狀態見上方索引清單與「效能」）。值的來源（2026-09-07 讀 `PegasusController` 實證）：下注時寫死 `1970-01-01 00:00:00` 佔位（DB 的 `DEFAULT CURRENT_TIMESTAMP` 用不到）；結算類回調（settle／settleChange／settleWithoutPayout／bonus／event-bonus）寫 **target（Pegasus 遊戲商端）請求帶來的時間**，不是我方處理時刻，處理時刻要看 `updated_at`；**cancel 不改寫 `settlement_time`**，未結算就被取消的列恆停在 1970，已結算後被取消的列保留原結算時間。三個後果：(1) 未結算列的 `settlement_time` 恆為 1970，用 `settlement_time` 區間永遠撈不到 pending，pending 要用 `bet_time` 撈；(2) 取消列（status 2）不會落進任何 `settlement_time` 桶，加上 SBR 只取 `status = 1`，取消對 SBR 沒有影響；(3) target 延遲重送結算時（09-05 事故實測晚 2 小時、6 小時重送），列會落回原本的小時桶，但該桶的 SBR 可能早已寫完，形成「遲到結算」缺口，重跑該桶即可補齊。

### 金額口徑

| 欄位 | 定義 |
|---|---|
| `bet` | 原始投注額 |
| `win` | 派彩金額 |
| `win_lose` | 輸贏（公司視角；`SUM` 為正代表玩家贏、公司輸） |
| `rolling_chip` | 禮碼金額，非 0，SBR 有聚合此欄，對帳要比 |
| `jackpot` | BR 無此欄；舊版命令寫入 SBR 時固定填 `0` |
| `commission` | 同上，SBR 實測恆 0 |

## bet_id 處理

`bet_id` 為 `varchar(191)` 且 UNIQUE。以字串處理，比對時一律 `(string)`，勿轉 int。

## status / 結算流程

- `status`（以 `app/Vendors/PegasusSW/BetRecordStatusEnum.php` 為準，Model 註解只列 0／1）：`0` = BET 未結算、`1` = SETTLE 已結算、`2` = ROLLBACK 已取消。`2` 由 `PegasusController::cancel()` 寫入，寫入時 bet／win／win_lose 同時歸 0，TBR 對應列也歸 0。
- 實測另有 `9` 出現在少量列上，不在 enum 內（**待確認**，可能是舊系統時期寫的）；對帳一律以 `status = 1` 為已結算、`status <> 1` 為未結算，不對 9 另作假設
- **取消（status 2）的錢退到哪**：真金退回走 `transaction_logs`；禮碼退回走 `rolling_chip_logs`（`type = 3`，即 `RollingChipLogTypeEnum::BET_CANCEL`）。整筆用禮碼下注的注單被取消時，`transaction_logs` **不會有任何列**（該注單的真金投注額為 0，全程不涉及現金），查退款要看 `rolling_chip_logs`（09-05 事故 70 筆實證）
- **TBR 對未結算注單的記法**：`placeBet` 當下就寫 TBR，未結算列在 TBR 記為 `win_lose = −bet`（全輸），結算後再改。所以「TBR 有、BR `status=1` 沒有」的列，只要 BR 是 status 0 就是正常 pending，不是 drift；依 `reconcile-bet-records` skill 的下游報表對照（代理後台日報讀 TBR），當天 pending 會被算成輸，本卡未另追該報表的讀取層
- multi-bet：單錢包需支援同 `roundId` 多 `reference`，冪等靠 `bet_id` UNIQUE

未結算殘留查詢（月結前用）：

```sql
SELECT status, COUNT(*) AS cnt, SUM(bet) AS sum_bet
FROM pgssw_bet_records
WHERE vendor_info_id IN (452, 453)
  AND status <> 1
  AND bet_time >= ? AND bet_time < ?
GROUP BY status;
```

## 對齊命令一覽

| 步驟 | 命令 / 動作 |
|---|---|
| Step 0 取三方 | **無管道**（見「取得三方資料」） |
| Step 1 校對（SUM） | 本卡「終驗 SQL」 |
| **BR → TBR** | 無 SP。apis `PegasusController`（`BWGController` 繼承之）在回調當下經 `TotalBetRecordService::create()` 寫入，key 為 `(vendor_id, bet_record_id)` 的 `updateOrCreate` |
| **BR → SBR**（補帳） | RD-Studio-01 舊版 `summary_bet_record --start=<UTC Y-m-d@H> --vendor=<id>`（見「補 SBR 的命令」） |
| apis Phase C | 91／177 **不可用**（未註冊）；50、145 可手動 `--vendor=<id>`，但口徑與舊系統不同（見「SBR 由誰寫」警告） |

## 終驗 SQL

### (1) BR ↔ SBR 逐小時差異（PGSSW 的主邊界）

只印對不上的小時；全對齊時回 0 row。時間窗與 `vendor_info_id` 清單填實值：

```sql
SELECT hr,
       SUM(b_cnt) AS br_cnt, SUM(s_cnt) AS sbr_cnt,
       SUM(b_bet) AS br_bet, SUM(s_bet) AS sbr_bet,
       SUM(b_wl)  AS br_wl,  SUM(s_wl)  AS sbr_wl,
       SUM(b_rc)  AS br_rc,  SUM(s_rc)  AS sbr_rc
FROM (
    SELECT DATE_FORMAT(settlement_time, '%Y-%m-%d %H:00:00') AS hr,
           COUNT(*) AS b_cnt, SUM(bet) AS b_bet, SUM(win_lose) AS b_wl, SUM(rolling_chip) AS b_rc,
           0 AS s_cnt, 0 AS s_bet, 0 AS s_wl, 0 AS s_rc
    FROM pgssw_bet_records
    WHERE updated_at >= '2026-08-17 00:00:00'          -- 只為讓 updated_at 索引生效，須早於窗口起
      AND vendor_info_id IN (452, 453)
      AND status = 1
      AND settlement_time >= '2026-08-18 00:00:00' AND settlement_time < '2026-08-22 00:00:00'
    GROUP BY hr
    UNION ALL
    SELECT data_time AS hr,
           0, 0, 0, 0,
           SUM(record_count), SUM(bet), SUM(win_lose), SUM(rolling_chip)
    FROM summary_bet_records
    WHERE vendor_id = 145
      AND data_time >= '2026-08-18 00:00:00' AND data_time < '2026-08-22 00:00:00'
    GROUP BY data_time
) u
GROUP BY hr
HAVING SUM(b_cnt) <> SUM(s_cnt) OR SUM(b_bet) <> SUM(s_bet)
    OR SUM(b_wl)  <> SUM(s_wl)  OR SUM(b_rc)  <> SUM(s_rc)
ORDER BY hr;
```

判讀：

- `br_cnt > 0 AND sbr_cnt = 0` → SBR 該小時整段缺失，補該小時（最常見）
- `sbr_cnt > 0 AND br_cnt = 0` → SBR 有多餘 correction，BR 已無對應，需查為何
- 兩邊都有但數字不等 → 該小時聚合錯，重跑該小時

差異落在哪個子站（SBR 拆不出來，只能從 BR 側看組成）：

```sql
SELECT DATE_FORMAT(settlement_time, '%Y-%m-%d %H:00:00') AS hr, vendor_info_id,
       COUNT(*) AS cnt, SUM(bet) AS sum_bet, SUM(win_lose) AS sum_wl
FROM pgssw_bet_records
WHERE updated_at >= '2026-08-18 00:00:00'
  AND vendor_info_id IN (452, 453) AND status = 1
  AND settlement_time >= '2026-08-19 14:00:00' AND settlement_time < '2026-08-19 15:00:00'
GROUP BY hr, vendor_info_id ORDER BY hr, vendor_info_id;
```

### (2) BR ↔ TBR 逐筆對齊

```sql
SELECT COUNT(*) AS br_total,
       SUM(CASE WHEN t.id IS NULL THEN 1 ELSE 0 END) AS br_only,
       SUM(CASE WHEN t.id IS NOT NULL AND b.bet      <> t.bet      THEN 1 ELSE 0 END) AS bet_diff,
       SUM(CASE WHEN t.id IS NOT NULL AND b.win_lose <> t.win_lose THEN 1 ELSE 0 END) AS wl_diff
FROM pgssw_bet_records b
LEFT JOIN total_bet_records t ON t.bet_record_id = b.id AND t.vendor_id = 145
WHERE b.updated_at >= '2026-08-17 00:00:00'
  AND b.vendor_info_id IN (452, 453)
  AND b.status = 1
  AND b.settlement_time >= '2026-08-18 00:00:00' AND b.settlement_time < '2026-08-22 00:00:00';
```

四個數字應為 `br_only = bet_diff = wl_diff = 0`。

> **不要拿 TBR 的時間欄去比 SBR**：TBR 以 `bet_time` / `payout_time` 切、SBR 以 `settlement_time` 切，同一日的三個切口不一致，硬比會生出整日規模的假 drift。TBR 一律用 `bet_record_id` join BR 驗證。

## 效能

`pgssw_bet_records` 在生產是大表（VWorld 上百萬級以上）。切口欄 `settlement_time` 在 2026-08 沒有索引、range 掃描會退化成全表掃；2026-09-07 實查 TPower 與 VWorld 都已有 `settlement_time` 索引（見「BR 結構」），但本卡的 SQL 仍保留下面的保險寫法，換到沒索引的站也不會炸。

實務作法：加一個 `updated_at >= '<窗口起前 1~2 天>'` 條件讓 `updated_at` 索引生效，再由 `settlement_time` 做精確過濾（`settlement_time` 與 `updated_at` 時間相近，但**不保證相等**——09-05 事故實測遲到結算差 2～6 小時——所以邊界要放寬、不能剛好貼齊窗口起點）。上方所有 SQL 都已內建這個寫法，在有索引的站也無害。

沒索引的站要根治須走 APIs migration 補 `settlement_time` 索引（大表 DDL 紀律），不在對帳流程內處理。

## 已知 incident / 注意事項

- **共用表直接 SUM**：最常見坑點，不歸戶會把 4 個 vendor 加總。
- **拿 TBR 比 SBR**：PGSSW 的 SBR 不經 TBR，這樣比會產生整日規模的假 drift。
- **SBR 小時桶缺失**（2026-08 實測，VWorld / vendor 145）：BR 與 SBR 逐小時比對抓到 SBR 整段缺失的小時 —— UTC `2026-08-19 14:00`（1,076 筆 / bet 1,074.89，全在 453 WTT 子站）、UTC `2026-08-20 16:00`（40 筆 / bet 120.00，同為 453）、UTC `2026-08-23 15:00`（60 筆 / bet 515.80，452 與 453 各有）。同期 BR↔TBR 34,180 筆完全對齊，證實問題只在舊系統的 SBR 排程漏跑該小時，補該小時即可。

  這三筆已與 OP 對帳 excel 交叉驗證，日期軸如下 —— **OP 的日期與 SBR 的 UTC 日期會差一天，對帳時先講清用哪個時區**：

  | OP excel（GMT+8） | excel 上的差異 bet / winlose | SBR 缺失小時（UTC） |
  |---|---|---|
  | 08/19 | 1,074.89 / 893.53 | `2026-08-19 14:00` |
  | 08/20 | 0 / 0（無缺） | — |
  | 08/21 | 120.00 / −18.75 | `2026-08-20 16:00` |

- **SBR 整桶缺失＋遲到結算**（2026-09-05 TPower DDoS 事故，vendor 145 / vendor_info 453）：SBR 缺 UTC `09-05 14:00`（6,583 筆 / bet 94,956.39 / win_lose 22,533.75 / 禮碼 176.90）與 `15:00`（4,139 筆 / 36,419.67 / −15,394.252 / 241.95）兩整桶，另 `16:00` 桶少 36 筆（20.85 / +0.79 / 20.85）。原因：舊系統寫桶時點約為桶結束後 1 小時 40 分（實查 12 點桶寫於 13:39、13 點桶 14:41、16 點桶 17:43、17 點桶 18:35），14、15 點桶的寫入時刻落在事故窗 UTC 15:36～17:41 內，沒寫出任何列；16 點桶的 36 筆是 target 於事故後 2 小時、6 小時重送結算，`settlement_time` 仍是原始結算時間、桶已寫完（見「BR 結構」的 `settlement_time` 說明）。同期 BR↔TBR 109,209 筆零差異、member 層零差異、舊系統 join 用的 account／member／agent 零孤兒。09-07 在 tpower VM 跑舊命令補三桶（`@14`、`@15`、`@16`），三桶皆走調帳、`summary_time` 為 `09-06 16:00`，OP 回填備註 `9/7補 b:131396.91 wl:7140.288 rc:439.70 recs:10758`。

  同事故另有 70 筆「cancel 先於 bet 落地」的競態：我方 `placeBet` 卡在會員鎖，target 逾時先送 cancel，`cancel()` 查無注單回 OK，之後 bet 才扣款。結果是注單留 status 0、`pgssw_cancel_logs` 留 status 0、TBR 記全輸，target 端則認定已取消。target 09-07 重送 cancel 後，`cancel()` 見到注單就走正常回滾，全數自癒（status 2、TBR 歸零、禮碼 27.97 經 `rolling_chip_logs` 退回 25 人）。排查法＝`pgssw_cancel_logs.status = 0` 且 `transaction_no` 對得到注單，就是這一型，先請 target 重送 cancel。工單 `target/op_tickets#3075`。
- **補完帳卻查不到 correction 列**：兩個查詢條件的坑。**(1)** `data_time` 是 **UTC 小時桶**，不是 GMT+8 日期 —— OP 記在 excel 「08/21」那行的補帳，`data_time` 可能是 `2026-08-20 16:00`，用 `DATE(data_time)='2026-08-21'` 查必定空。**(2)** `summary_time` **不是寫入時間**，是「最後關帳時間」：`CorrectionData()` 以 `getExpiredTime()` 計算 —— `(執行時刻 − 6 小時)` 那天的台北 `00:00` 再 `− 8 小時`（實例：UTC `08-24 03:40` 執行 → 寫成 `08-22 16:00`），所以按 `summary_time` 查「今天補的」永遠找不到。**要找剛補的列一律用 `created_at`**，要按歸期找就用 UTC 小時直接命中 `data_time`。
- **correction 的歸期 vs OP 的記帳位置**：補帳寫回的是**原本該歸的 `data_time`**，`summary_time` 才是寫入日（2026-08 實測：`data_time` 8/13、8/14 各有 correction 列、`summary_time` 皆為 8/17）。但 OP 的對帳 excel 常把補帳**記在抄表當日**那一行（實例：8/13 差 941.40 + 8/14 差 806.00 = 8/18 那行的 −1,747.40；winlose −186.36 + 483.79 = −297.43）。**別把 excel 的記帳位置當成 DB 的 `data_time`** —— 這種情況 DB 側查起來是完全對齊的，excel 的差異只是舊快照沒回頭更新。
- **`status` 9 語意未確認**（2 已確認為 ROLLBACK）：見「status / 結算流程」。

> **一則未驗證的觀察**：OP excel 的 `SR11 Bet` / `SR11 Winlose` 欄與「api 加總」欄逐日同值，暗示 SR11 報表與 SBR 同源。這只是 excel 層面的觀察，**未在 code 追過 SR11 的讀取層**，不可當作結論引用（`reconcile-bet-records` skill 也標記 SR11 讀哪一層屬未驗證）。

## 關鍵檔案

| 用途 | 路徑 |
|---|---|
| BR Model | `app/Vendors/Models/PgsswBetRecord.php` |
| Account Model | `app/Vendors/Models/PgsswAccount.php` |
| Driver（4 vendor 共用） | `app/Vendors/PegasusSW/Driver.php` |
| 回調 Controller（基底 / BWG） | `app/Http/Controllers/External/SingleWallets/PegasusController.php`、`BWGController.php` |
| BR → TBR 寫入 | `app/Services/TotalBetRecordService.php` |
| SBR 寫入（舊系統） | `RD-Studio-01/_release/app/Console/Commands/SummaryBetRecordCommand.php:2461`（PGSSW 區塊起點；145 的查詢在 `:2526`、調帳在 `:3578`、結尾 SP 在 `:3508`） |
| VendorIdEnum case | `App\Enums\Vendor\VendorIdEnum::BWG`（145）／`PGSSW`（50）／`TIGER9`（91）／`JOKER_BACCARAT`（177） |
