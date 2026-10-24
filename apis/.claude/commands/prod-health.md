---
description: 生產面盤點——排程健康度（該跑的有沒有跑、有沒有失敗、有沒有靜默）與近 7 天卡住的代付單（告警響過但沒人收，並看是否集中在某家金流商或某個時段）。唯讀 SQL，禁任何寫入。
argument-hint: "（無參數＝TPower＋VWorld；可加白標名，或 all 掃六站；schedule / stuck 只跑其中一段）"
disable-model-invocation: true
---

範圍：$ARGUMENTS（空＝TPower＋VWorld；`all`＝六站；指定白標名＝只掃該站；`schedule`／`stuck`＝只跑該段）

---

> **定位**：卡單、金流孤兒、vendor 轉帳異常**已有排程在跑並發 TG 告警**（`withdrawal:alert-stuck-submitted`、`check:cashflow-orphans --notify`、`check_vendor_transfer_status`、`vendor_detect`），本命令不重做那些偵測。它補的是兩個沒人盯的真空：
> 1. **排程自己掛了或靜默** —— 告警不響有兩種可能，沒事，或告警死了
> 2. **告警響過但沒人收的近期卡單** —— 7 天內卡住的代付單，並看是否集中在某家金流商或某個時段；超過 7 天的陳年單不在範圍內
>
> **強制紀律**：
> - **唯讀 SQL**，走 `mysql-<白標>` MCP（`whitelabel-db-query` skill）。**禁止**任何 UPDATE／DELETE／INSERT、禁止 tinker 改資料、禁止跑寫入類 artisan。發現要處置的**回報就好**，處置走既有 skill 並由使用者發起。
> - **只做聚合，不撈明細**：不 `SELECT` 會員姓名／帳號／手機等 PII 欄位。真要看單筆，回報訂單 id 由使用者決定下一步。
> - 每站回應**首行標環境**：`🔌 當前環境：<白標>`。
> - 數字一律附原始查詢返回，取不到就標「未驗證」。

## §1 排程健康度

資料源＝`command_logs`（**不是 `schedule_logs`**，那是舊 API_Agent 的表，新 APIs 完全不寫）。

```sql
SELECT command,
       COUNT(*) AS runs,
       SUM(status = -1) AS failed,
       SUM(status = -2) AS skipped,
       SUM(status = 0)  AS no_finish,
       TIMESTAMPDIFF(MINUTE, MAX(start_time), NOW()) AS mins_ago
FROM command_logs
WHERE day >= CURDATE() - INTERVAL 1 DAY
  AND cronjob IS NOT NULL
GROUP BY command
ORDER BY failed DESC, no_finish DESC, runs ASC
```

### 為什麼是這樣寫（每一條都是踩過的坑）

- 🔴 **必須用 `day` 過濾，不可用 `start_time`**——`start_time` **沒有索引**（migration `2025_09_08_074831` 只給 `day`／`command`／`input`／`status`／`cronjob` 建索引）。實測 TPower：`start_time >= NOW() - INTERVAL 24 HOUR` 要 **11,840ms**，換成 `day >= CURDATE() - INTERVAL 1 DAY` 只要 **114ms**，差 100 倍。
- **`cronjob IS NOT NULL`＝只取排程事件那筆**。一次排程執行會寫多筆：`CRON_` trace 帶 cronjob 字串、`ART_` trace 的 **`cronjob` 是 `NULL`（不是空字串）**。不濾會重複計數。
- **`mins_ago` 一定在 SQL 內用 `TIMESTAMPDIFF` 算**，不要把 timestamp 撈出來自己減——mysql MCP 回傳的時間值有 8 小時偏移，自己算會誤判成「8 小時沒跑」。
- ⚠️ **窗口不是固定 24 小時**——`day >= CURDATE() - INTERVAL 1 DAY` 從昨天 00:00 起算，實際涵蓋隨執行時刻在 24–48 小時間浮動。因此 runs 只能看數量級（每分鐘排程 ~1400–2900、每五分鐘 ~290–580、每小時 ~24–48、每日 1–2），**不能拿 1440／288／24 硬比**；「今天剛停」的排程也因為窗口還含昨日資料而不會從結果消失——**靜默判定一律以 `mins_ago` 為準**（見下），不看「有沒有記錄」。

### status 語意

| 值 | 意義 |
|---|---|
| `0` | 建立時預設，**未收到 finish**（手動中斷常見；持續出現要查） |
| `1` | 成功 |
| `-1` | 失敗 |
| `-2` | 跳過（`withoutOverlapping` 撞鎖等） |

⚠️ **`-1` 很少出現是正常的**：排程失敗本來就少，不代表這個狀態寫不進去。
⚠️ **`exitCode` 為 `null` 不可判失敗**——背景任務（`runInBackground()`）不寫 exitCode，另由 `ScheduledBackgroundTaskFinished` 回報（本專案未使用）。

### 靜默偵測

查詢結果只告訴你「跑過的」，**跑掉的排程不會出現在結果裡**。要抓靜默，拿結果去比對 `routes/console.php` 的註冊清單：

```bash
rg "^\s*Schedule::command\(" routes/console.php
```

🔴 **必須排除註解掉的行**（`rg` 的 `^\s*` 錨點就是為此）。實例：`pull_bet_record` 在檔案裡存在但**是註解狀態**（`// Schedule::command('pull_bet_record')...`），照字串搜會誤報成「已註冊卻靜默」。**註冊數與實跑數對不上時，先確認差額是不是註解行，再報異常。**

🔴 **比對只用第一個 token**——`command_logs.command` 只存空白切割後的第一段（`ScheduleProvider::handleTaskStarting()`：`explode(' ', $input)` 後 `reset()`），註冊行的 `--daily`／`--notify`／`--execute`／`--prev-hour`／`--hours=2` 等參數要先剝掉再比，否則帶參數的排程全數被誤判靜默。

判讀：

靜默分兩層抓，缺一不可：

- **有註冊、有啟用、整個窗口零記錄**（不在查詢結果裡）→ 🔴 已靜默超過一天
- **有記錄但 `mins_ago` 超過警戒線** → 🔴 **今天才停的靠這層抓**（窗口含昨日資料，光看「有沒有記錄」會漏掉今天剛死的排程）：

  | 排程頻率 | `mins_ago` 警戒線 |
  |---|---|
  | 每分鐘 | > 15 |
  | 每五分鐘 | > 30 |
  | 每小時 | > 150 |
  | 每日 | > 1560（26h） |

其餘判讀：

- **`failed > 0`** → 🔴 附 command 名與筆數
- **`no_finish > 0`** → ⚠️ 單次聚合只能證明「窗口內曾有未完成」，**判不了「持續」也判不了「仍在跑」**；要下結論先補查該 command 的 `status=0` 明細（`SELECT start_time, input FROM command_logs WHERE day >= CURDATE() - INTERVAL 1 DAY AND command = '<cmd>' AND status = 0`），看最早／最晚時間分辨是仍在執行、被中斷、還是反覆發生
- **runs 明顯低於該頻率數量級的下緣**（例如每分鐘排程只有數百次）→ ⚠️ 間歇性失效
- ⚠️ `archive:command_logs` 每日 05:00 會截斷舊資料——**別用總列數推算歷史區間**。

## §2 非終態堆積

告警響過不等於有人處理。這段看的是**近 7 天卡住、沒人收的代付單**。

🔴 **超過 7 天的陳年單一律不看、不提**：不列出、不當風險、不寫進今日可做或收工待辦。那批是長年累積的殘留，不逐筆處置，每天排在最前面只會稀釋當天真正要處理的事。唯一例外是 `23`，不分年紀都要抓（見下方判讀）。

### 2.1 代付（主軸）

分兩步：第一步看各狀態有沒有近期卡單；有的話才跑第二步，看卡單集中在哪家金流商、哪個時段。

**第一步：各狀態的近期卡單數**

```sql
SELECT status,
       SUM(created_at >= NOW() - INTERVAL 2 HOUR) AS age_under_2h,
       SUM(created_at <  NOW() - INTERVAL 2 HOUR
           AND created_at >= NOW() - INTERVAL 7 DAY) AS age_2h_to_7d,
       TIMESTAMPDIFF(DAY,
           MIN(CASE WHEN created_at >= NOW() - INTERVAL 7 DAY THEN created_at END),
           NOW()) AS oldest_days_in_7d,
       SUM(status = 23) AS status_23_any_age
FROM withdrawal_orders
WHERE status IN (21, 22, 23, 29)
GROUP BY status
ORDER BY status
```

- `oldest_days_in_7d` 只算 7 天內最舊的一張；7 天內沒有單時是 `NULL`。
- `status_23_any_age` 只在 `23` 那列可能非零，不分年紀。其他狀態刻意不出「含陳年單的總數」，免得陳年單又被看到。

**第二步：卡單集中在哪（第一步有近期卡單才跑，見下方判讀）**

```sql
SELECT o.status,
       COALESCE(cf.name, '（無代付紀錄）') AS provider,
       DATE_FORMAT(o.created_at + INTERVAL 8 HOUR, '%Y-%m-%d') AS tpe_date,
       COUNT(*) AS cnt,
       DATE_FORMAT(MIN(o.created_at + INTERVAL 8 HOUR), '%H:%i') AS first_tpe,
       DATE_FORMAT(MAX(o.created_at + INTERVAL 8 HOUR), '%H:%i') AS last_tpe
FROM withdrawal_orders o
LEFT JOIN withdrawal_payout_records r
       ON r.id = (SELECT MAX(r2.id) FROM withdrawal_payout_records r2
                  WHERE r2.withdrawal_order_id = o.id)
LEFT JOIN cash_flows cf ON cf.id = r.cash_flow_id
WHERE o.status IN (21, 22, 29)
  AND o.created_at >= NOW() - INTERVAL 7 DAY
  AND (o.status = 29 OR o.created_at < NOW() - INTERVAL 2 HOUR)
GROUP BY o.status, provider, tpe_date
ORDER BY o.status, cnt DESC, tpe_date
```

篩選條件對應下方判讀：`21`／`22` 只取 2 小時到 7 天（2 小時內是正常在途），`29` 取 7 天內全部。

### 為什麼第一步不設時間範圍

🔴 **不要在 WHERE 加 `created_at >= NOW() - INTERVAL N DAY`**，7 天的切分放在 `SUM` 的條件裡做：

- **`23` 要抓到任何年紀的單**：未知寫入者可能把舊單改成 `23`，舊單的 `created_at` 早就在時間範圍外，加了範圍就看不到。
- **只用 status 過濾反而快**：`status` 有單欄索引（另有 `idx_withdrawal_orders_status_payment_time` 複合索引），符合的筆數天生極少。實測 TPower 全量 65ms，加 30 天範圍反而要 800ms，慢 10 倍以上。

分桶取代時間視窗，**桶名只描述訂單年齡**（`age_under_2h`／`age_2h_to_7d`），語意判讀交給下方規則。刻意不用 in_flight／stuck 這類帶語意的名字，因為同一桶對不同 status 意義不同：21/22 在 2h 內是正常在途，29 在 2h 內卻是剛被拒絕、最需要當天處理。

### 第二步為什麼這樣寫

- **金流商取該單最新一筆代付紀錄（`withdrawal_payout_records`）**：`withdrawal_orders` 沒有金流商欄位。Model 上雖有 `agentCashFlow` 關聯，但它對應的欄位在資料表裡不存在（TPower 實查）。`29` 可重發，同一張單可能換過金流商，最新一筆才是造成目前狀態的那家。
- **「（無代付紀錄）」是正常的一類**：取 token、登入這類前置步驟失敗時，訂單直接轉 `29`，不會建代付紀錄。
- **時間一律換成台北時間**：資料庫時區是 UTC（TPower 實查 `@@system_time_zone` 為 UTC），查詢內加 8 小時。
- **日期必須用 `DATE_FORMAT` 轉成字串**：mysql MCP 會把 DATE 型別顯示成前一天 16:00 的 UTC 時間，例如 09-15 顯示成 `2026-09-14T16:00:00.000Z`。格式要帶年份（`%Y-%m-%d`），只寫月-日的話，跨年那週的排序會錯（01-01 會排在 12-31 後面）。
- 🔴 **`cash_flows` 只取 `name`**：同表的 `info` 欄位存的是金流商憑證，禁止 SELECT。
- 第二步有時間條件但筆數少，六站實測 60–200ms。

### status 語意（來源＝`2025_06_19_091321` migration 的欄位 comment）

| 值 | 意義 | 終態？ |
|---|---|---|
| `0` 訂單建立／`1` 待審核／`3` 等待付款 | 正常流程等待中 | 否，但**不該報警**（等人審、等出款是常態） |
| `2` 拒絕／`9` 退回／`4` 已付款／`24` 已付款（代付） | — | **是**，不列入掃描 |
| `21` 代付請求中／`22` 代付處理中 | 結果未明，停在此擋重送並告警 | 否 |
| `23` | **已廢除**，程式不再認得 23 | 不應出現 |
| `29` 代付回應失敗 | 明確拒絕，可重發 | 否 |

⚠️ **只掃 21/22/23/29**。把 `1`／`2`／`3` 當非終態是錯的——`2` 是拒絕、`9` 是退回，都已經是終態；`1`／`3` 則是正常等待，掃了只會製造噪音。

判讀：

- **`21`／`22` 落在 `age_2h_to_7d`** → 三方沒回或告警沒人收，該查。落在 `age_under_2h` 是正常在途，不用理。
- **`21`／`22` 的 `oldest_days_in_7d` ≥ 6** → 加註「24 小時內會超過 7 天、本命令不再列出，要在那之前決定定案或放著」，並寫出確切的不再列出時刻＝第二步查到的台北建立時間加 7 天。天數是無條件捨去，6 代表已滿 6 天、未滿 7 天，所以可能當天稍晚就不再列出，不是「明天起」。這類是結果未明的單，直接重送可能重複出款；滿 7 天後就沒有任何提醒，這是最後一次。
- **`29` 的 `age_under_2h` 或 `age_2h_to_7d` 有值** → 非終態、可重發，列出來讓使用者決定拒單或重送；落在 `age_under_2h`＝**今天剛被三方拒絕的新單，最該當天決定**。
- **`status_23_any_age` 非零**＝有未知寫入者，不分年紀。程式已移除 23：回調會被拒絕並告警、人工定案命令也不處理，舊系統 API_Agent 只讀 23、不會寫入。出現時先追查是誰寫入，再決定處置，不要直接改狀態。
- **上面任一條命中 `21`／`22`／`29`** → 跑第二步，依結果判斷集中度：
  - 同一家金流商佔多數 → 多半是那家的問題（通道、限額、回調沒送）。
  - 不同金流商集中在同一時段 → 多半是我方、網路或部署的問題。
  - 同一家金流商在多站同時出現 → 那家整體異常，放進跨站對比。
  - 只有一兩筆時照實列出，不硬下集中結論。
- ⚠️ **不要用 `updated_at` 判「近期活動」**——銀行收斂批次會整批刷新該欄，造成假陽性。一律用 `created_at`。
- ⚠️ 在途狀態的當下快照會一直流轉（`22` 幾分鐘就轉 `24`），**同一天兩次查詢結果不同是正常的**，別當成資料異常。

### 2.2 代收（只看量能，不做卡單掃描）

🚫 **代收不做「卡住超過 N 小時」的掃描**——實測 TPower 近 7 天 `orders` 有 6,777 筆 `status=0`、10,124 筆 `status=2` 且絕大多數已過 2 小時。**玩家創單不付款是常態**，照卡單邏輯掃必然全是噪音。

改看斷流：

```sql
SELECT DATE_FORMAT(created_at, '%Y-%m-%d') AS d_utc,
       COUNT(*) AS orders,
       SUM(status = 3) AS success,
       TIMESTAMPDIFF(MINUTE, CURDATE(), NOW()) AS mins_into_today
FROM orders
WHERE created_at >= NOW() - INTERVAL 7 DAY
GROUP BY d_utc
ORDER BY d_utc DESC
```

- **日期是 UTC 日**（資料庫時區是 UTC），等於台北時間每天 08:00 換日。
- **日期必須用 `DATE_FORMAT` 轉成字串**：用 `DATE(created_at)` 時，mysql MCP 會把日期顯示成前一天 16:00 的 UTC 時間，容易讀錯日（同代付第二步）。
- 別名不要用 `utc_date`：mysql MCP 會把它當保留字拒收。
- `mins_into_today` 是今天（UTC）已經過了幾分鐘，給下方的今日正規化用。

判讀：

- 🔴 **今日那列是不完整日，不可與完整日直接比**——早上跑必然偏低，直接比就是每天一次假斷流。粗正規化＝`今日量 ÷ 已過小時數` 對比 `昨日量 ÷ 24`；或只比完整日之間的趨勢，今日僅在「至今接近零、而昨日同時段明顯有量」時才報。
- 完整日之間量能或成功率明顯掉一截 → 可能有渠道斷流。這是提示，不是結論；要確認是哪個金流商再另外查，別在起手式展開。

## §3 輸出

每站一段，**沒問題就一行「全綠」帶過**，不要為了填版面把所有排程全列出來。有問題才展開：

1. 🔴 **排程靜默或失敗**（含 command 名、筆數、最後執行）
2. 🔴 **近期卡單**（7 天內：status、筆數、最舊幾天，以及集中在哪家金流商、哪個台北時段；最舊 ≥ 6 天的 `21`／`22` 加註 24 小時內不再列出，並寫出確切時刻）
3. ⚠️ **量能異常**（代收斷流跡象）
4. 🔴 **未知寫入者**：`23` 出現非零（零筆不必提）

超過 7 天的代付單不出現在任何一項，也不用一行帶過。

多站掃描時最後補一段**跨站對比**——只有一站出問題通常是該站的事，全站同時出現多半是共用元件或部署問題；同一家金流商在多站同時卡單，多半是那家金流商的問題。

收尾提醒：發現要處置的一律回報，**不要自己動手**；卡單處置走 `whitelabel-container-exec`、對帳修復走 `repair-bet-records`，且都由使用者發起。
