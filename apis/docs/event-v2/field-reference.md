# event_v2_details 欄位參考

> 本文件說明 `event_v2_details` 資料表所有欄位的規則、型別與設定意義，並附 DB id 1–14 的完整範例。

---

> **架構說明**：`handler_class` 和 `schedule`（重置排程）在**父表 `event_v2`** 上，`event_v2_details` 是細節設定表，一個 `event_v2` 可對應多個 detail（不同代理不同設定）。詳見 [database.md](database.md)。

---

## 欄位總覽

| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | int | 主鍵（也是前台 API 傳入的 `event_detail_id`） |
| `event_v2_id` | int | 關聯的 `event_v2.id`（含 handler_class 與 schedule） |
| `agent_id` | int | 適用的代理 ID（依代理 hierarchy 篩選顯示） |
| `status` | tinyint | 0=停用，1=啟用（`StatusEnum::ENABLE`） |
| `condition_formula` | string\|null | 參加條件公式（MathExecutor 語法） |
| `condition_config` | JSON\|null | 條件補充設定（視 handler 而異） |
| `reward_formula` | string\|null | 獎勵計算公式（null 時改用 `reward_config`） |
| `reward_config` | JSON\|null | 獎勵階梯設定（與 `reward_formula` 擇一或搭配使用） |
| `balance_formula` | string\|null | 最低餘額門檻公式（提款前必須達到，null=無限制） |
| `balance_config` | JSON\|null | 餘額限制補充設定（目前預留，未使用） |
| `flow_formula` | string\|null | 活動內流水門檻公式（null=無流水要求） |
| `flow_config` | JSON\|null | 流水限制補充設定（目前預留，未使用） |
| `withdraw_formula` | string\|null | 可提款金額公式（null=全部活動錢包餘額）；**積分型＝積分數量公式（必填）** |
| `withdraw_config` | JSON\|null | 提款補充設定（`balance_multiple`）；**積分型＝`{"expire":小時,"limit":上限}`** |
| `withdraw_type` | int | 領取去向：1=主錢包（預設），2=積分（`EventV2WithdrawTypeEnum`） |
| `member_period` | int | 會員週期（可重複參加）：0=不分週期（預設）、1=日、2=週、3=月、4=年（`EventV2PeriodEnum`）；只有紅包可設非 0；**建立後鎖定** |
| `total_period` | int | 全站週期（包括期間）：值域同上，`max_join`／`max_amount` 的累計區間；只有紅包可設非 0；**建立後鎖定** |
| `ip_block` | string\|null | IP 重複參加限制 |
| `phone_block` | string\|null | 手機號碼重複參加限制 |
| `account_block` | string\|null | 實名帳號重複參加限制 |
| `bonus_vendor_id` | int\|null | 指定發送優惠碼的遊戲商 ID |
| `join_withdraw` | int | 1=參加後立即自動提款，0=手動申請 |
| `cost_type` | int | 成本記錄時機：1=JOIN（參加時），2=COMPLETE（完成時） |
| `is_recycle` | bool | 1=退出/結束後記錄剩餘活動錢包餘額，0=不記錄 |
| `sort` | int | 前台顯示排序（數字越大越前面） |
| `start_time` | datetime\|null | 活動開始時間（null=不限） |
| `end_time` | datetime\|null | 活動結束時間（null=不限） |
| `redirect_path` | string\|null | 參加成功後的前台導向路徑 |

---

## 公式語法說明

公式由 MathExecutor 解析，支援以下**變數**（依活動類型而定）：

| 變數 | 說明 | 適用 handler |
|---|---|---|
| `deposit` | 觸發本次活動的儲值金額 | 所有 deposit 類型（非儲值類恆為 0，別用） |
| `reward` | 本次計算出的獎勵金額 | balance / withdraw / flow 三個限制公式中 |
| `invite` | 符合條件的邀請人數 | InviteFriendService 的獎勵公式 |
| `win_lose` | 下線輸贏額（負數代表輸） | DownlineCommissionService 的獎勵公式 |
| `rand(min, max)` | 隨機小數（含上下界） | **只有走 `convertRewardFormula()` 的獎勵公式**：註冊、每日登入、五種儲值類（積分型的提款限制公式同樣經此展開）。邀請好友／下線傭金自己執行獎勵公式、三個限制公式也不經過它，寫了 `rand` 會解析失敗 |

每個 handler 實際會讀哪幾格、哪些變數／key，以 `AbstractEventV2::fieldParams()` 與各 handler 的 `HANDLER_FIELD_PARAMS` 宣告為準；後台 `event-v2/event-list` 的 `field_params` 就是這份宣告的本地化版本（見 [agent-api.md](agent-api.md#post-event-v2event-list)）。

---

## condition_formula

參加資格驗證公式。含 `'deposit'` 字串時，AbstractEventV2 會呼叫 `checkDeposit()` 並觸發主錢包扣款。

| 範例 | 說明 |
|---|---|
| null | 無條件，任何人都能參加 |
| `deposit >= 30` | 儲值 >= 30 才符合 |
| `deposit == 30` | 儲值剛好等於 30 |
| `20 <= deposit && deposit < 50` | 儲值在 [20, 50) 區間 |
| `deposit >= 50` | 儲值 >= 50 |
| `deposit >= 10` | 儲值 >= 10 |
| `deposit >= 1000` | 儲值 >= 1000 |
| `win_lose < 0` | 下線有輸（DownlineCommissionService 用） |

---

## condition_config

JSON 格式，補充條件設定。內容依 handler 類型不同：

### 通用 key

| key | 型別 | handler | 說明 |
|---|---|---|---|
| `max_join` | int | 任意 | 活動總參加人數上限 |
| `main_wallet_deduction` | float\|null | deposit 類 | 主錢包扣除金額：未設定=扣 deposit->amount 全額；設為 0=不扣；設正數=扣 min(config, deposit->amount) |

### handler 專用 key

| key | 型別 | handler | 說明 |
|---|---|---|---|
| `realname` | int (1) | RegisterService | 需完成實名驗證 |
| `invite` | int | InviteFriendService | 最低邀請人數（符合條件） |
| `deposit` | float | InviteFriendService | 被邀請者需達到的最低儲值金額 |
| `dayOfWeek` | int | DownlineCommissionService | 可參加的最晚週幾（1–7，0=不限） |
| `score` | number | AngpowService | 每抽一次扣幾分（**必填**、正數）；超過 2 位小數由 `AngpowService::scoreCost()` 無條件捨去（10.559 扣 10.55），截完為 0 視同沒設；其他類型帶了後台擋。紅包另外**必填獎勵公式**（沒填＝抽 0 元照扣分，後台回 `angpow_reward_formula_required`） |
| `max_amount` | number | AngpowService | 全站總金額：已發出達到就擋，最後一包照抽到的給（可能超過） |
| `member_times` | int | AngpowService | 每人在 `member_period` 內最多抽幾次 |
| `member_amount` | number | AngpowService | 每人在 `member_period` 內最多抽到多少，到了直接擋 |
| `interval` | int | AngpowService | 同一人兩次抽之間至少隔幾秒 |
| `time_window` | `["HH:MM","HH:MM"]` | AngpowService | 每天可抽時段，起晚於迄視為跨夜；DB 存 UTC、後台以 +8 輸入與顯示（`AngpowService::timeWindowToSystem()`／`timeWindowToBackend()`），判斷用系統時間 |

### DailyDepositComboService 的陣列格式

`condition_config` 為陣列，每個元素代表一種儲值組合：

```json
[
  {"count": 3, "deposit": "10"},
  {"count": 5, "deposit": "10"},
  {"count": 3, "deposit": "30"},
  {"count": 5, "deposit": "30"}
]
```

- `count`：需累積儲值的筆數
- `deposit`：每筆儲值的最低金額

---

## reward_formula / reward_config

兩者擇一使用，或搭配（`reward_config.limit` 作為 formula 的上限）。

### reward_formula 範例

| 範例 | 說明 |
|---|---|
| `"59"` | 固定 59 元 |
| `"10"` | 固定 10 元 |
| `deposit * 0.5` | 儲值金額的 50% |
| `deposit * 0.3` | 儲值金額的 30% |
| `deposit` | 等於儲值金額（1:1） |
| `deposit * 10000` | 儲值乘 10000（彩金場景，搭配 reward_config.limit 上限） |
| `rand(0.5, 0.98)` | 隨機 0.5–0.98 |
| `rand(1.5, 2.98)` | 隨機 1.5–2.98 |
| `invite * 9` | 邀請人數 × 9 |
| `win_lose * 0.1` | 下線輸分 × 10%（傭金） |

### reward_config 格式一：上限物件（搭配 formula）

```json
{"limit": 300}
```

`formula` 計算結果不超過 `limit`。

### reward_config 格式二：條件陣列（DailyDepositRange / WeeklyDeposit）

```json
[
  {"reward": 6.99,  "condition": "deposit < 50"},
  {"reward": 9.99,  "condition": "deposit < 100"},
  {"reward": 16.99, "condition": "deposit < 300"},
  {"reward": 39.99, "condition": "deposit >= 300"}
]
```

**累加邏輯**：依序對每個元素 **先累加 reward**，再判斷 condition，條件成立則 break。

```
deposit=60 → +6.99 (cond false) → +9.99 (cond true, break) → reward=16.98
deposit=200 → +6.99 → +9.99 → +16.99 (cond true, break) → reward=33.97
```

### reward_config 格式三：組合陣列（DailyDepositComboService）

```json
[
  {"reward": 3.99,  "condition": {"count": 3, "deposit": 10}},
  {"reward": 5.99,  "condition": {"count": 5, "deposit": 10}},
  {"reward": 9.99,  "condition": {"count": 3, "deposit": 30}},
  {"reward": 19.99, "condition": {"count": 5, "deposit": 30}}
]
```

多個組合各自判斷是否達成，達成則累加對應 reward。

---

## balance_formula

提款前活動錢包餘額必須達到的最低門檻公式。未達則不能申請提款。

| 範例 | 說明 |
|---|---|
| null | 無門檻，隨時可提款 |
| `"600"` | 固定需餘額 >= 600 |
| `(deposit + reward) * 5` | (儲值+獎勵) × 5 倍 |
| `(deposit + reward) * 8` | (儲值+獎勵) × 8 倍 |
| `reward * 3` | 獎勵 × 3 倍 |

---

## flow_formula

活動錢包流水門檻（在活動錢包內的累計下注額需達到才能提款）。

| 範例 | 說明 |
|---|---|
| null | 無流水要求 |
| `reward * 5` | 獎勵 × 5 倍流水 |
| `reward * 10` | 獎勵 × 10 倍流水 |

---

## withdraw_formula / withdraw_config

提款金額計算。

### withdraw_formula 範例

| 範例 | 說明 |
|---|---|
| null | 全額提款（活動錢包餘額全部） |
| `"30"` | 固定提款 30 元 |
| `"1000"` | 固定提款 1000 元 |
| `reward * 3` | 獎勵 × 3 倍 |
| `reward` | 等於獎勵金額 |

### withdraw_config

```json
{"balance_multiple": 1}
```

提款金額 = 活動錢包餘額 × `balance_multiple`。

```json
{"expire": 720}
```

`expire`／`limit`：**只在 `withdraw_type=2`（積分）時生效**——`expire` 積分有效期（小時，正整數；缺省／空字串＝永久，到期進位到下一個 10 分鐘刻度），`limit` 積分上限（數字）。積分型把整個 `withdraw_config` 當成自己的獎勵配置讀，詳見 [withdraw_type](#withdraw_type)。後台驗證：`expire` 只認正整數（`score_expire_invalid`）、`limit` 只認數字（`score_limit_invalid`），不看去向。

---

## withdraw_type

領取去向（target/tickets#4231）。**積分與活動錢包無關**：積分型的參加條件照類型，但獎金不進活動錢包、沒有領取步驟。

| 值 | Enum | 行為 |
|---|---|---|
| 1 | `MAIN_WALLET` | 既有路徑：`join()` 獎金進活動錢包 → 三道門 → `withdraw()` 主錢包 +實領、`transaction_logs` type 71 → `initEventV2()` |
| 2 | `SCORE` | `join()` 直接算出積分數量、經 `MemberScoreService::grant()` 發成**一批**（來源 `ScoreSourceTypeEnum::EVENT_V2_USER_JOIN`、`source_id`＝`event_v2_user_joins.id`；`member_score_batches.uniq_source_key` 保證同一筆參加紀錄只能發一批，重發撞唯一鍵整筆回滾）、紀錄即 `COMPLETE`（`reward`＝積分數、`withdraw` 為 null（2026-10-02 起，target/tickets#4235；之前的 dev 紀錄寫成積分數、不回填）、三個 limit 為 null、`end_time`＝參加時間）。**不算三道門、不扣主錢包、不進活動錢包、不呼叫 `withdraw()`／`initEventV2()`、不寫 type 11／14／70／71、不寫 `event_v2_recycles`、不記 SR11 成本**；數量 ≤ 0 不發批次、紀錄照樣 COMPLETE |

**積分型怎麼配（數量與有效期在同一對欄位）**

| 欄位 | 積分型 |
|---|---|
| `withdraw_formula`（提款限制公式） | **積分數量，必填**。寫法同獎勵公式：常數、`rand(a, b)`、`deposit * 0.5`、`invite * 10`、`win_lose * 0.1`——程式把它當成該 handler 的獎勵公式來算（`AbstractEventV2::getScoreAmount()`），各類型自己的變數、`rand()`、`limit` 封頂全部沿用 |
| `withdraw_config`（提款限制配置） | `{"expire": 720, "limit": 300, "tiers": [...]}`：`expire`＝有效期（小時，正整數；缺省或空＝永久，到期進位到下一個 10 分鐘刻度）、`limit`＝積分上限（數字，選填）、`tiers`＝階梯（選填，見下） |
| `withdraw_config.tiers`（階梯） | `[{"condition":"deposit >= 300","score":30},{"condition":"deposit >= 100","score":"deposit * 0.1"}]`：**由上往下第一個 `condition` 成立的那組就是積分數**，都不成立＝0（不發批次、紀錄照樣 COMPLETE）；`score` 可為數字或公式。有 `tiers` 時 `withdraw_formula` 必須留空（二擇一，`score_amount_source_conflict`）。實作是 `AbstractEventV2::compileScoreTiers()` 編譯成巢狀 `if(c1, s1, if(c2, s2, … 0))` 再走同一條 `getReward()`，變數／`rand()`／`limit` 全沿用；後台驗每組形狀（`score_tiers_invalid`），運營不必手寫巢狀 if |
| `reward_formula`／`reward_config`／`balance_formula`／`balance_config`／`flow_formula`／`flow_config` | **必須為空**，後台回 `event_v2.validation.score_fields_not_allowed` |
| 主錢包型的 `withdraw_config` | 只認 `balance_multiple`；帶 `expire`／`limit`／`tiers` 回 `main_wallet_config_keys_not_allowed` |
| 建立後改去向 | **不可**：Update 送與現值不同的 `withdraw_type` 回 `withdraw_type_locked`；要改請另建活動。新後台編輯既有活動時下拉 disabled |
| `join_withdraw` | 後台強制存 1（新後台鎖定勾選）；執行端不看它，積分在 `join()` 內已完成 |
| 三種陣列型（`DailyDepositRangeService`／`WeeklyDepositService`／`DailyDepositComboService`） | **不開放**（PM 規則）。Create／Update 以 `event_v2.handler_class` 對 `EventV2WithdrawTypeEnum::SCORE_UNSUPPORTED_HANDLERS` 判定，回 `score_type_not_supported`；**禁止寫死 `event_v2.id`**（各環境類型 id 不同）。`event-list` 接口回傳 `handler_class`，新後台據此互鎖 |

**凍結**：`join()` 把 `event_v2_details.withdraw_type` 複製到 `event_v2_user_joins.withdraw_type`，`EventV2UserJoinObserver` 只看紀錄上的值；運營中途改活動去向，已參加者照參加當下的去向處理（主錢包型停在 JOIN 的紀錄，活動改成積分後手動領取仍領現金）。

**舊後台相容**（`WEB_AgentBackend` 不改、整包送、永不送 `withdraw_type`）：Create 落預設 1；Update 沿用資料庫現值（`UpdateRequest::fallbackWithdrawType()`），不會把積分活動翻回主錢包。有效去向一律經 `effectiveWithdrawType()` 取得，禁止在別處直接讀 `input('withdraw_type')`。

---

## member_period ／ total_period

單一活動自己的兩個週期（target/tickets#4234 紅包，目前只有紅包用），值域同為 `EventV2PeriodEnum`。**跟 `event_v2.schedule` 不同**：schedule 是活動類型一個 cron，到期把整個類型還在 JOIN 的紀錄重置。

| 值 | 意思 |
|---|---|
| 0 `NONE` | 不分週期（預設）：從活動開始累計；會員週期為 0 時參加紀錄不會因週期作廢 |
| 1 `DAILY`／2 `WEEKLY`／3 `MONTHLY`／4 `YEARLY` | 日／週／月／年（週從週一起算）；累計區間與作廢都依前台時區 00:00 切（同一條換日線），見下 |

兩欄各管一件事，互相獨立（Frank 2026-09-30 定義；「每日可抽 1 次、每週全站 50 次」＝`member_period=1`＋`total_period=2`）：

| 欄位 | 後台名稱 | 管什麼 |
|---|---|---|
| `member_period` | 可重複參加 | 同一個會員多久能再抽：`condition_config.member_times`／`member_amount` 在哪個區間內累計（用參加紀錄的 `date`／`week`／`month`／`year` 分桶，與 `checkDaily()` 那組同一套）；也是作廢週期 |
| `total_period` | 包括期間 | 全站的池子多久重新開一池：`condition_config.max_join`／`max_amount` 在哪個區間內累計（`AngpowService::totalPeriodJoins()`，比 `created_at` ≥ 本期起點）。只管統計不作廢 |

**兩欄建立後鎖定**（Max 2026-10-01，比照 `withdraw_type`）：Update 送來與現值不同回 400 `period_locked`「活動週期建立後不可異動，要改請另建活動」；沒送沿用現值。原因：週期決定上限的累計區間與作廢邊界，中途改會讓已累計的次數金額和已抽的紀錄對不上。新後台編輯時兩個下拉呈現 disabled。

作廢只跟會員週期走：
- 不勾參加即領獎、還停在 JOIN 的紀錄，會員週期一過就由 `app:init-event-v2`（紅包類型列 `schedule = 0 16 * * *`，台灣 00:00 觸發）轉給 `AngpowPeriodResetService` 重置：狀態 SYSTEM_INIT、活動錢包歸零回收、已扣積分不退，動作同 `app:init-event-v2`。作廢邊界＝前台時區 00:00（日＝每天 00:00、週＝週一 00:00、月＝1 號 00:00、年＝1/1 00:00），跟「今天抽過沒」同一條線。**不可改成按 UTC**：曾改成 UTC 00:00（台灣 08:00），會讓台灣 00:00～08:00 抽的那包算今天的次數、卻被當昨天的作廢，玩家白扣積分；實際營運每日活動的站把 schedule 調成 `0 16 * * *` 也是同一個道理（2026-10-01）。

**後台驗證**（`ValidatesAngpow`）：兩欄都只有 `handler_class = AngpowService` 可送非 0，其他類型回 `period_not_supported`；舊後台不送＝Create 落 0、Update 沿用現值（`effectivePeriod($field)`）。**紅包類型列的 `event_v2.schedule` 固定 `0 16 * * *`**：`InitEventV2` 對紅包不會整個類型全清，只清各檔上一期的 JOIN；手動排查用 `event-v2:reset-period-joins --dry-run`。

## is_recycle

| 值 | 說明 |
|---|---|
| 0 | 退出或結束後，活動錢包剩餘餘額**不記錄** |
| 1 | 退出或結束後，記錄剩餘餘額到 `event_v2_recycles` 資料表（供統計回收使用） |

---

## cost_type

| 值 | Enum | 說明 | Observer 行為 |
|---|---|---|---|
| 1 | `JOIN` | 參加時記錄成本（以 reward 為成本） | `EventV2UserJoinObserver.created` |
| 2 | `COMPLETE` | 完成/提款時記錄成本（以 withdraw 為成本） | `EventV2UserJoinObserver.updated(status=COMPLETE)` |

---

## 後台開分視為儲值（target/tickets#4352）

`checkDeposit()`（`app/Services/EventV2/Traits/ChecksDepositAndPeriodQualification.php`）預設只認 `orders` 表（`status=DEPOSIT_FINISHED`）的真實儲值訂單；CS 後台手動開分（`ThirdPartyController::transaction()`/`newTransaction()` type=DEPOSIT，寫入 `transaction_logs` 但沒有對應 `orders` 列）本來完全不會被計入任何「含 deposit 條件」的活動資格判斷。

這是**代理組別設置**（`agent_customized_settings`，key=`AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT`，setting_id=6033，代理組別設置頁歸類在「財務設置」），不是個別活動的欄位——要不要把後台開分算成儲值是站台整體的營運政策，同一代理組別下所有活動一體適用，非活動 V2 專屬。目前已知的消費端：活動 V2（本節）、新人專享挑戰活動 `NewbieChallengeService::findQualifyingDeposit()`（見 `docs/新人專享挑戰活動/README.md`）、任務獎勵 `DepositSummary::exec()`（`app/Services/Frontend/Missions/Libs/DepositSummary.php`，SQL 層用 `whereExists` 子查詢判斷代理組別設置，不是 PHP 呼叫 `getSettingValue()`，因為這支是批次彙總查詢、不是單一會員的請求）——三邊各自獨立查詢、互不共用程式碼，只共用這一個開關值。透過 `$member->agent->getSettingValue(...)` 讀取，預設 `false`（`agent_setting_defines` 的 `default_value`）。

開啟後，`getTodayDeposits()`（真實訂單）全部不合格時，才會退而求其次改看當日「後台開分」的 `transaction_logs`（`type=DEPOSIT`），視同一筆候選儲值——**真實儲值訂單優先，後台開分只是備援**。

**與真實儲值訂單的差異**：

| | 真實儲值訂單 | 後台開分 |
|---|---|---|
| 資料來源 | `orders`（`status=DEPOSIT_FINISHED`） | `transaction_logs`（`type=DEPOSIT`，`order_id=0`） |
| `event_v2_user_joins` 記錄欄位 | `order_id` | `deposit_transaction_log_id`（新欄位，不重用 `order_id`，避免兩種 ID 空間混淆） |
| V1 互斥獎品判斷 | 有（`prize_members.lottery_number` 比對 order id） | 無——後台開分不會被 V1 抽獎系統領走，不受影響 |
| `main_wallet_deduction` 扣款時找 `FlowLimit` 的方式 | 先用 `order_id` 查建單當下寫的 `TransactionLog(type=ORDER_DEPOSIT)`，再用該 log id 比對 `FlowLimit.reference_id` | 該筆 `transaction_logs` 本身就是要找的紀錄，直接用其 id 比對 `FlowLimit.reference_id`，不必多一層查詢 |

**設定位置**：代理組別設置（`agent_customized_settings`），介面上歸類在「財務設置」分類（`parent=AgentCustomizedSettingKeyEnum::FINANCE_SETTING`）。本 repo 只讀（`Agent::getSettingValue()`/`AgentService::getSpecificAgentSetting()`），沒有寫入用的 CRUD 介面，寫入走既有的代理組別設置管理系統。定義（`data_type`/`default_value`）登記在 `database/data/agent_setting_defines.php`，經 `SyncAgentSettingDefinesSeeder` 同步進 `agent_setting_defines`。

**已知範圍限制**：`FirstDepositService`（id=15，首次儲值）不呼叫共用的 `checkDeposit()`，而是自帶 `checkFirstDeposit()`（見 [event-types.md](event-types.md)），本設置對它**不生效**——首儲活動的後台開分仍不會被計入。

---

## join_withdraw

| 值 | 說明 |
|---|---|
| 0 | 一般流程，玩家需手動申請提款 |
| 1 | 參加（join）同時自動執行 withdraw，無需玩家申請 |

> `withdraw_type=2`（積分）由後台強制為 1，執行端不看它（積分在 `join()` 內已完成），見 [withdraw_type](#withdraw_type)。

> id=8 的使用場景：搭配 `bonus_vendor_id`，參加即發送虛擬彩金，join 後立即 withdraw 入帳。

---

## bonus_vendor_id

指定遊戲商 ID，參加活動時系統會向該遊戲商發送優惠碼（bonus code）。null = 不發送。

---

## ip_block / phone_block / account_block

防止同一 IP / 手機 / 實名帳號重複刷活動，詳見 [event-types.md — Block 設定說明](event-types.md)。

---

## DB 設定範例（id 1–14）

### id=1（RegisterService）

```
handler_class:    RegisterService
condition_formula: null
condition_config:  null
reward_formula:   "59"
reward_config:    null
balance_formula:  "600"
flow_formula:     null
withdraw_formula: "30"
cost_type:        JOIN(1)
is_recycle:       0
```

**說明**：固定獎勵 59 元，活動錢包需達 600 才能提款，每次只能提 30 元。

---

### id=2（DailyFreeService）

```
handler_class:    DailyFreeService
condition_formula: null
condition_config:  {"max_join": 1000}
reward_formula:   "19"（範例）
reward_config:    null
balance_formula:  null
flow_formula:     null
withdraw_formula: null
schedule:         "0 16 * * *"
cost_type:        COMPLETE(2)
is_recycle:       1
```

**說明**：每日 UTC 16:00 重置（台灣 00:00；實際營運的 vplus33 與 dev 的值，migration 預設 `0 0` 是台灣 08:00），每期限 1000 人，完成時記錄成本，退出回收剩餘。

---

### id=3（MonthlyDepositService）

```
handler_class:    MonthlyDepositService
condition_formula: null（由 getValidate 內部判斷）
condition_config:  null
reward_formula:   "deposit * 0.5"
reward_config:    null
balance_formula:  "(deposit + reward) * 5"
flow_formula:     null
withdraw_formula: null
ip_block:         "1"
phone_block:      "1"
account_block:    "1"
cost_type:        JOIN(1)
is_recycle:       0
```

**說明**：每月一次儲值送 50%，需有 (儲值+獎勵)×5 倍餘額才能提款，全站同 IP/手機/姓名去重。

---

### id=4（MonthlyDeposit2Service）

```
handler_class:    MonthlyDeposit2Service
condition_formula: "deposit >= 30"
condition_config:  null
reward_formula:   "deposit"
reward_config:    null
balance_formula:  "(deposit + reward) * 8"
flow_formula:     null
withdraw_formula: null
schedule:         "0 0 1 * *"
cost_type:        JOIN(1)
is_recycle:       0
```

**說明**：每月 1 日重置，儲值 >= 30，等額送獎勵，需 (儲值+獎勵)×8 倍餘額提款。

---

### id=5（DailyDepositService）

```
handler_class:    DailyDepositService
condition_formula: "deposit == 30"
condition_config:  null
reward_formula:   "10"
reward_config:    null
balance_formula:  null
flow_formula:     null
withdraw_formula: null
schedule:         "0 16 * * *"
cost_type:        JOIN(1)
is_recycle:       0
```

**說明**：每日重置，存剛好 30 元送 10 元，無提款門檻。

---

### id=6（DepositService）

```
handler_class:    DepositService
condition_formula: "20 <= deposit && deposit < 50"
condition_config:  null
reward_formula:   "rand(0.5, 0.98)"
reward_config:    null
balance_formula:  null
flow_formula:     null
withdraw_formula: null
cost_type:        JOIN(1)
is_recycle:       0
```

**說明**：存 20–49 元，隨機獎勵 0.5–0.98 元。

---

### id=7（DepositService）

```
handler_class:    DepositService
condition_formula: "deposit >= 50"
condition_config:  null
reward_formula:   "rand(1.5, 2.98)"
reward_config:    null
balance_formula:  null
flow_formula:     null
withdraw_formula: null
cost_type:        JOIN(1)
is_recycle:       0
```

**說明**：存 50 元以上，隨機獎勵 1.5–2.98 元。id=6 與 id=7 通常搭配同一 event_v2，覆蓋不同儲值區間。

---

### id=8（DepositService — 虛擬彩金）

```
handler_class:    DepositService
condition_formula: "deposit >= 20"
condition_config:  null
reward_formula:   "deposit * 10000"
reward_config:    {"limit": 100000000000}
balance_formula:  null
flow_formula:     null
withdraw_formula: null
bonus_vendor_id:  142
join_withdraw:    1
cost_type:        JOIN(1)
is_recycle:       0
```

**說明**：存 >= 20 元觸發，向遊戲商 142 發送虛擬彩金（reward 極大但有上限），參加後立即自動提款。

---

### id=9（DailyDepositService）

```
handler_class:    DailyDepositService
condition_formula: （由 getValidate 設定）
condition_config:  null
reward_formula:   "deposit * 0.3"
reward_config:    {"limit": 300}
schedule:         "0 16 * * *"
cost_type:        JOIN(1)
is_recycle:       0
```

**說明**：每日第一筆儲值送 30%，最多 300 元。

---

### id=10（DailyDepositRangeService）

```
handler_class:    DailyDepositRangeService
condition_formula: （由 getValidate 設定，通常含 deposit 下限）
condition_config:  null
reward_formula:   null
reward_config:    [
  {"reward": 6.99,  "condition": "deposit < 50"},
  {"reward": 9.99,  "condition": "deposit < 100"},
  {"reward": 16.99, "condition": "deposit < 300"},
  {"reward": 39.99, "condition": "deposit >= 300"}
]
balance_formula:  "reward * 3"
withdraw_formula: "reward * 5"
schedule:         "0 16 * * *"
cost_type:        JOIN(1)
is_recycle:       0
```

**說明**：每日重置，依儲值金額累加獎勵（見累加邏輯說明），需 reward×3 餘額，最多提 reward×5。

---

### id=11（WeeklyDepositService）

```
handler_class:    WeeklyDepositService
condition_formula: "deposit >= 1000"
condition_config:  null
reward_formula:   null
reward_config:    [
  {"reward": 38,  "condition": "deposit < 3000"},
  {"reward": 88,  "condition": "deposit < 5000"},
  {"reward": 188, "condition": "deposit < 10000"},
  {"reward": 388, "condition": "deposit >= 10000"}
]
balance_formula:  "reward * 5"
withdraw_formula: "reward"
schedule:         "0 0 * * 1"
cost_type:        JOIN(1)
is_recycle:       0
```

**說明**：每週一重置，累積儲值 >= 1000，依累加邏輯計算獎勵，提款上限等於獎勵金額。

---

### id=12（DailyDepositComboService）

```
handler_class:    DailyDepositComboService
condition_formula: （由 getValidate 設定）
condition_config:  [
  {"count": 3, "deposit": "10"},
  {"count": 5, "deposit": "10"},
  {"count": 3, "deposit": "30"},
  {"count": 5, "deposit": "30"}
]
reward_formula:   null
reward_config:    [
  {"reward": 3.99,  "condition": {"count": 3, "deposit": 10}},
  {"reward": 5.99,  "condition": {"count": 5, "deposit": 10}},
  {"reward": 9.99,  "condition": {"count": 3, "deposit": 30}},
  {"reward": 19.99, "condition": {"count": 5, "deposit": 30}}
]
schedule:         "0 16 * * *"
cost_type:        JOIN(1)
is_recycle:       0
```

**說明**：每日重置，四種組合各自判斷是否達成，達成則累加獎勵。

---

### id=13（InviteFriendService）

```
handler_class:    InviteFriendService
condition_formula: "deposit >= 10"
condition_config:  {"invite": 1, "deposit": 10}
reward_formula:   "invite * 9"
reward_config:    null
balance_formula:  "reward * 10"
flow_formula:     null
withdraw_formula: "reward * 3"
cost_type:        JOIN(1)
is_recycle:       0
```

**說明**：至少 1 位邀請好友（且好友儲值 >= 10），本人也需儲值 >= 10，每位好友送 9 元，需 reward×10 餘額，最多提 reward×3。

---

### id=14（DownlineCommissionService）

```
handler_class:    DownlineCommissionService
condition_formula: "win_lose < 0"
condition_config:  {"dayOfWeek": 5}
reward_formula:   "win_lose * 0.1"
reward_config:    null
balance_formula:  null
flow_formula:     "reward * 5"
withdraw_formula: "reward * 10"
schedule:         "0 0 * * 1"
cost_type:        JOIN(1)
is_recycle:       0
```

**說明**：每週一重置，統計上週下線輸分，週五前可申請（dayOfWeek=5），傭金為輸分的 10%，需 reward×5 流水，最多提 reward×10。
