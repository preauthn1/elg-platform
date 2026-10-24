# 活動類型（Event Handler Classes）

每種活動在 `event_v2` 表有一筆目錄列，`handler_class` 欄位存的是負責處理這種活動的類別名稱（handler class）。`EventV2Service::getEventClass()` 先由活動明細（`event_v2_details`）找到所屬的 `event_v2` 列，再依 `handler_class` 動態建立對應的 handler。所有 handler class 位於 `app/Services/EventV2/`，繼承 `AbstractEventV2`。

---

## Handler Class 一覽

| Handler Class（`event_v2.handler_class`） | 活動名稱（`event_v2.name`） | 觸發 checkDeposit | 說明 |
|---|---|---|---|
| `RegisterService` | 註冊 | 視設定 | 會員註冊獎勵，一次性；`condition_formula` 含 `'deposit'` 時同時驗證儲值條件 |
| `FirstDepositService` | 首次儲值 | 自帶 `checkFirstDeposit()`（非共用 `checkDeposit`） | 只能參加一次；挑活動期間第一筆符合條件的儲值，不檢查是否為生涯首筆儲值 |
| `DailyFreeService` | 每日登入 | 否 | 每日登入免費領取 |
| `DepositService` | 不限次儲值 | **是** | 每筆儲值觸發，不限次數 |
| `DailyDepositService` | 每日儲值 | **是** | 每日第一筆符合條件的儲值 |
| `DailyDepositRangeService` | 每日區間儲值 | **是** | 每日儲值，依金額區間給不同獎勵（累加階梯式） |
| `DailyDepositComboService` | 每日儲值組合 | **是** | 每日儲值 N 筆特定金額組合 |
| `MonthlyDepositService` | 每月儲值 | **是** | 每月一次儲值活動 |
| `MonthlyDeposit2Service` | 每月儲值2 | **是** | 每月一次儲值活動（有 schedule 重置，含區間金額限制） |
| `WeeklyDepositService` | 每周累積儲值 | **是** | 每週累積儲值達標 |
| `InviteFriendService` | 邀請好友 | **是** | 邀請好友儲值獎勵 |
| `DownlineCommissionService` | 下線傭金 | 否 | 下線輸分傭金（依 win_lose 計算） |
| `AngpowService` | 紅包 | 否 | 紅包：花積分抽一次亂數現金，可重複抽；規則全在 `condition_config`，兩個週期在 `member_period`／`total_period` 欄位（target/tickets#4234） |

> 程式只認 `handler_class`。`event_v2.id` 各站不一定相同，不要拿 id 判斷活動類型。

> **觸發 checkDeposit**：`condition_formula` 含 `'deposit'` 字串時，AbstractEventV2 會呼叫 `checkDeposit()`，進而觸發主錢包扣款邏輯。詳見 [README.md](README.md)。

---

## 各類型詳細說明

### RegisterService — 註冊獎勵

- 固定一次性，會員完成條件即可領取
- `condition_config.realname=1`：需完成實名驗證才能參加
- `condition_formula` 含 `'deposit'`（如 `deposit >= 30`）時，額外呼叫 `checkDeposit()` 驗證儲值條件並觸發主錢包扣款；`condition_formula` 為 `null` 或不含 `'deposit'` 時維持純註冊獎勵，不檢查儲值

---

### FirstDepositService — 首次儲值

- 每位會員對此 `event_v2_details` 只能參加一次（判斷邏輯同 `RegisterService`：`event_v2_user_joins` 依 `event_v2_detail_id` 查到任何狀態的紀錄即視為已參加）
- **不呼叫共用的 `AbstractEventV2::checkDeposit()`**，而是自帶 `checkFirstDeposit()` + `getFirstEventDeposit()`（寫在 `FirstDepositService` 內，未抽到 `AbstractEventV2`，因為目前只有這個 handler 需要）：
  - **只認活動開始後 `pay_time` 最早的那一筆訂單**（`pay_time >= event_v2_details.start_time`，`orderBy pay_time asc`、`first()`），不像 `checkDeposit()` 撈一批「今天」的候選訂單逐筆比對
  - 這筆訂單只要不符合 `condition_formula`、被 V1 互斥獎品領走、或已被拿去參加別的活動 V2，**一律直接判定不符資格**（回傳失敗），不會退而求其次改看第二筆訂單——「首儲」就是那一筆，沒有替代候選
  - `main_wallet_deduction` 換算、餘額不足檢查等金流邏輯與 `checkDeposit()` 相同
- **語意為「活動開始後的第一筆訂單」**，不驗證是否為會員生涯第一筆儲值（不查 `Member.first_deposit` 或 `DepositService::getFirstDeposit()`）
- 無 `schedule`，不會週期重置

---

### DailyFreeService — 每日免費

- 需搭配 `event_v2.schedule` 定時重置；cron 按 UTC 判斷，要在台灣 00:00 重置就填 `0 16 * * *`（實際營運的 vplus33 與 dev 都是這個值；migration 預設的 `0 0 * * *` 是台灣 08:00，沒在跑每日活動的站才留著）
- 無儲值條件
- `condition_config.max_join`：可限制每期活動的參加人數上限
- `cost_type=COMPLETE`：在提款時記錄成本
- `is_recycle=1`：退出或結束後記錄剩餘活動錢包餘額到 `event_v2_recycles`

---

### DepositService — 儲值活動（不限次）

- 每次符合 `condition_formula` 的儲值都可觸發，無次數限制
- 常見於「存多少送多少」、「彩金」等活動
- `join_withdraw=1`：參加即自動提款，適用搭配 `bonus_vendor_id` 的虛擬彩金場景
- `bonus_vendor_id`：指定遊戲商，觸發優惠碼發送（如 id=8）

---

### DailyDepositService — 每日儲值

- 每日只有第一筆符合條件的儲值可觸發
- 需搭配 `schedule`（每日重置）
- `reward_config.limit`：可設定獎勵上限（搭配 formula 使用）

---

### DailyDepositRangeService — 每日區間儲值

- `reward_config` 為陣列，依累加邏輯判斷：
  - 依序評估每個 `{reward, condition}`
  - **累加** reward，直到 condition 成立為止（break）
  - 例：`[{reward:6.99, cond:"deposit<50"}, {reward:9.99, cond:"deposit<100"}, ...]`
  - 若 deposit=60：第一條 false（6.99 累加），第二條 true（+9.99，break），最終 reward=16.98
- `balance_formula` / `withdraw_formula` 可分別設定

---

### DailyDepositComboService — 每日儲值組合

- `condition_config` 為**陣列**，每個元素代表一種儲值組合條件：`{count: N, deposit: "X"}`
- 玩家需在當日累積儲值達到指定筆數和最低單筆金額
- `reward_config` 也是陣列，每個組合對應一筆獎勵：`{reward: X, condition: {count, deposit}}`
- 多個組合可同時達成並累計獎勵

---

### MonthlyDepositService — 每月儲值

- 每月一次，通常不設 `schedule`（或由 admin 手動重置）
- `ip_block` / `phone_block` / `account_block` 常見設定，防止刷活動

---

### MonthlyDeposit2Service — 每月儲值（含重置）

- 類似 MonthlyDepositService，但設有 `schedule`（如 `0 0 1 * *`，每月 1 日重置）
- 可設定儲值金額區間（`condition_formula` 含上下界）

---

### WeeklyDepositService — 每週累積儲值

- `schedule`：每週一重置（`0 0 * * 1`）
- `reward_config` 為陣列，同 DailyDepositRange 的累加邏輯
- `condition_formula` 通常設定累積儲值下限

---

### InviteFriendService — 邀請好友

- `condition_config`：
  - `invite`：需達到的最低邀請人數（被邀請者）
  - `deposit`：被邀請者的最低儲值金額
- `reward_formula`：通常 `invite * X`，依邀請人數計算
- 變數 `invite` 代表符合條件的邀請人數
- **被邀請人去重（`InviteFriendService::validate()`）**：先在被邀請人之間以 `register_ip` → `mobile` → `first_name` 依序 `unique()`（保留 `created_at` 最早者），再經 `app/Services/BloomFilter/` 的三個 filter 比對全站是否已有相同 IP／手機／真實姓名的其他會員，命中即剔除。
  - 三個維度**各自獨立**：某維度為 `null`（會員沒綁手機、沒填真實姓名、無註冊 IP）時，該維度直接略過不比對，仍由其餘維度把關；不會因為缺值就被判定重複，也不可拿 `null`／`''` 當一個值去比對（會把所有缺值會員誤判為互相重複）。
  - filter 內部是 Bloom filter（`Redis db_15`），**只保證「不存在」為真**，回報「存在」時再回 `members` 表確認，因此誤報只會多一次 DB 查詢，不會誤剔除。

---

### DownlineCommissionService — 下線傭金

- **不觸發** `checkDeposit()`，不從主錢包扣款
- `condition_formula`：通常 `win_lose < 0`（下線有輸）
- 變數 `win_lose`：下線在指定期間的輸贏額（系統已取正負，< 0 代表下線輸）
- `condition_config.dayOfWeek`：限制幾號前（週幾前）可參加（0=不限）
- `reward_formula`：通常 `win_lose * 0.X`，依輸分比例計算傭金

---

### AngpowService — 紅包（target/tickets#4234）

會員**花積分抽一次**，得到一筆亂數現金；可重複抽到碰上限為止。積分是門票不是獎品：參加當下經 `MemberScoreService::deduct()` 扣 `condition_config.score`（參照 `event_v2_user_joins.id`），獎金照主錢包型走。

- **不觸發** `checkDeposit()`、不讀條件公式；`deposit` 恆為 0，公式別用
- 領取去向只能主錢包（`EventV2WithdrawTypeEnum::SCORE_UNSUPPORTED_HANDLERS`）
- `join_withdraw`：勾＝抽到直接入主錢包、三道門不檢查；不勾＝進活動錢包打流水，走標準領取流程
- `reward_formula`（**必填**）：`rand(起, 迄)` 金額範圍或固定金額；`reward_config.limit` 單次封頂
- 兩個週期都是**欄位**、不在 JSON（Frank 2026-09-30 定義，各自獨立）：
  - `member_period`（後台「可重複參加」）：`member_times`／`member_amount` 的累計區間，也是不勾時的作廢週期；紅包類型列 `event_v2.schedule` 填 `0 16 * * *`（台灣 00:00），到點 `app:init-event-v2` 看到紅包就交給 `AngpowPeriodResetService` 依各檔活動的會員週期只清上一期的 JOIN（不是整個類型全清）。作廢與次數／金額累計是同一條換日線：前台時區 00:00。
  - `total_period`（後台「包括期間」）：`max_join`／`max_amount` 的累計區間，過了重新開一池，只管統計不作廢；0＝整檔活動總量。
  - 「每日可抽 1 次、每週全站 50 次／5000 元」＝`member_period=1`＋`total_period=2`＋`{"member_times":1,"max_join":50,"max_amount":5000}`
- `condition_config`（只有 `score` 必填，其他不填＝不限制）：

| key | 意思 |
|---|---|
| `score` | 每抽一次扣幾分（正數，超過 2 位小數無條件捨去）。只有紅包能設，其他類型帶了後台擋；紅包獎勵公式必填 |
| `max_join` | 全站總次數（基底處理） |
| `max_amount` | 全站總金額：已發出（參加當下的 `reward` 加總，不含 OPERATOR_QUIT）達到就擋；沒到就能抽、抽到多少給多少，**最後一包可能讓總額超過** |
| `member_times` | 每人週期內最多抽幾次 |
| `member_amount` | 每人週期內最多抽到多少，到了直接擋 |
| `interval` | 同一人兩次抽之間至少隔幾秒 |
| `time_window` | `["08:00", "23:00"]` 每天可抽時段，起晚於迄視為跨夜。後台以後台時區（+8）填，Controller 寫入前轉成 UTC 存（例：`["00:00", "15:00"]`），detail 讀回轉回 +8；判斷用系統時間，比照 `start_time`／`end_time` |

- 上限一律以參加當下的 `reward` 計入、只排除 OPERATOR_QUIT；放棄不吐回額度、已扣積分不退
- 驗證碼 `16xx`（`EventV2ValidateCodeEnum::ANGPOW_*`）：未設 score、不在時段、間隔未到、次數／金額上限、總金額發完、積分不足
- 積分餘額在 `getValidate()` 先擋；真正扣在 `afterJoinRecorded()`（與參加紀錄同一交易），併發下餘額不足由 `deduct()` 拋例外整筆回滾
- 目標用戶（指定會員／會員群組）是所有類型共用的限制，見 [database.md — event_v2_detail_targets](database.md#event_v2_detail_targets目標用戶elgtickets4234)

---

## Block 設定說明

`ip_block`、`phone_block`、`account_block` 三個欄位可獨立設定，防止同一 IP / 手機 / 實名姓名刷活動：

| 值 | 說明 |
|---|---|
| null | 不檢查 |
| `"1"` | 全站同 IP/手機/姓名的會員，只有最早註冊的一人能參加此活動，較晚註冊者被擋 |
| `"2"` | 曾參加過任何 V1 或 V2 活動的同一 IP/手機/姓名不得重複 |
| `"3"` | 只限 V2 活動範圍內去重 |

> 可組合多值（如 `"1,3"`），系統會依序套用所有規則。

---

## 新增活動類型步驟

1. 在 `app/Services/EventV2/` 建立新的 `{Name}Service.php`，繼承 `AbstractEventV2`
2. 實作五個抽象方法：`getValidate`、`getReward`、`getBalanceLimit`、`getFlowLimit`、`getWithdrawLimit`
3. 寫 migration 在 `event_v2` 表新增一筆 `handler_class = '{Name}Service'` 的目錄列（`EventV2Service::getEventClass()` 是依 `event_v2.handler_class` 動態 `App::make()`，並非程式碼內的 switch/map，不需改 PHP 對應表）
4. 若活動需要特殊 `condition_config` key，在 `validate()` 或 `join()` 流程中讀取
5. 更新 [field-reference.md](field-reference.md) 的 `condition_config` 說明
