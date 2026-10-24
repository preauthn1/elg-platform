# 新人專享挑戰活動 — 規格與技術方案

> **狀態**：已上線（target/tickets#3926）。會員端 API 在 `routes/frontend/auth.php` 的 `newbie-challenge` 路由群組，代理後台 API 在 `Agent\NewbieChallengeController`。本文件記錄現行規格與技術決策；第六節「工項與時程估計」是開發前的估算，保留作參考。
> **最後更新**：2026-10-08

---

## 目錄

1. [活動規則（原始需求）](#一活動規則原始需求)
2. [架構決策：不沿用 Event V2](#二架構決策不沿用-event-v2)
3. [資料表設計](#三資料表設計)
4. [API 設計](#四api-設計)
5. [待確認事項（需 PM／風控裁決）](#五待確認事項需-pm風控裁決)
6. [工項與時程估計](#六工項與時程估計)
7. [UI 設計稿](#七ui-設計稿)

---

## 一、活動規則（原始需求）

### 1. 活動基礎

- 新會員僅可參與一次。
- 完成實名認證（KYC）後方可領取。
- 活動期間禁止提款、轉帳。
- 指定遊戲：FUMA、WUDI（活動帳號）。

### 2. 第一階段：領取活動金

- 獎勵：活動金 88。
- 每個帳號僅可領取一次。
- 領取後活動狀態變更為【進行中】。
- 活動金發放至活動錢包。

### 3. 第二階段：完成新人挑戰

- 任務：活動金 × 5 倍流水（預設 88 × 5 = 440）。
- 有效流水僅統計 FUMA、WUDI，其他遊戲不計算。
- 這個階段大廳遊戲商列表（`lobby/vendors`）只顯示 `allowed_vendor_ids` 範圍內的遊戲商，導流玩家玩到有算流水的遊戲；第四階段（彩金流水）同理改用 `bonus_flow_vendor_ids`（設為不限制遊戲商則不限制），其他階段（含未參加）不限制，見 `NewbieChallengeService::getLobbyVendorRestriction()`。
- 達標後系統自動提示「恭喜完成新人挑戰」，並計算可解鎖彩金。
- 可解鎖彩金 = 活動錢包剩餘餘額。
- 限制：最低 10、最高 1888。
  - ＜10：**尚不能進入下一階段**，維持【進行中】、活動錢包不清零，會員可繼續遊玩；之後餘額回到 10 以上再次觸發即可解鎖。僅主動放棄活動（見第八階段）才會結束此狀態。
  - ＞1888：僅保留 1888，超出部分清除。

### 4. 第三階段：彩金解鎖

- 完成挑戰後取得【彩金解鎖資格】，有效時間 48 小時（需顯示倒數）。
- 解鎖規則：**以倒數計時開始後的第一筆充值為準**，僅此一筆決定核銷金額；48hr 視窗內非第一筆的後續充值不再受理彩金核銷（單次性，非多次部分核銷）。
  - 最低充值 10，單次最高 1888。
  - 領取彩金 = 該筆充值金額，不得超過可解鎖彩金。
  - 例：可解鎖彩金 100 → 充值 50 得 50 彩金（剩餘 50 失效）／充值 100 得 100 彩金／充值 1000 僅得 100 彩金。

### 5. 第四階段：彩金流水

- 領取彩金後生成任務：**主錢包餘額（含本次儲值後）× 彩金流水倍數**（例：主錢包原有 100，充值 1000 解鎖 → 流水目標 = 12 × 1100 = 13200；流水目標算的是儲值後的主錢包總餘額，不是只算儲值金額或彩金金額）。
- 彩金流水期間禁止提款、轉帳轉出。
- 完成後：彩金發放至真金錢包，狀態改為【彩金任務完成】。

### 6. 第五階段：累積本金顯示

- 彩金撥款當下，即時查「挑戰完成後累積入金」存為 `principal_total`，供活動中心顯示。
- **不建立流水鎖**（見[待確認事項第 6 點](#五待確認事項需-pm風控裁決)）：儲值本身的流水風控是平台層 `DEPOSIT_FLOW_LIMIT_RATE` 機制（`PaymentService::updateMemberBalance()`）的職責，活動不重複處理，避免疊加成雙倍要求。

### 7. 第六階段：任務進度

- 活動中心即時顯示：當前目標流水、已完成流水、剩餘流水、完成百分比（已完成流水 ÷ 目標流水 × 100%）。

### 8. 第七階段：虧損續充

- 活動期間本金已輸完，允許再次充值繼續活動；系統保留已完成流水與進度，不重新計算任務。
- 例：目標 1200，第一次完成 500 流水後餘額歸零，再次充值繼續，系統顯示已完成 500、剩餘 700。

### 9. 第八階段：放棄活動

- 會員可主動放棄活動，系統執行：回收已領取彩金、作廢未領取彩金、清除活動進度、狀態改為【已放棄】。
- 放棄後：本金僅需完成 1 倍流水即可提款，且不可再次參加活動。

### 10. 第九階段：後台配置

可調整項目（預設值）：

| 項目 | 預設值 |
|---|---|
| 活動金 | 88 |
| 挑戰流水倍數 | 5 |
| 彩金流水倍數 | 12 |
| 最低解鎖金額 | 10 |
| 最高解鎖金額 | 1888 |
| 彩金有效期 | 48 小時 |
| 可參與遊戲商 | — |
| 可參與遊戲 | FUMA、WUDI |
| 有效流水遊戲 | FUMA、WUDI |

### 11. 活動狀態

未參與 → 進行中 → 待領取彩金 → 彩金任務中 → 彩金任務完成（整個活動終態，本金流水完成後提款自動解鎖，不再另立「已完成」狀態） ／ 已放棄

無「已失效」狀態：待領取彩金的 48hr 倒數過期與否，一律由 `bonus_window_expire_at` 跟目前時間即時比對判斷（`AbstractEventV2::checkCrossActivityParticipationBlock()` 即是如此），不落地寫入一個獨立狀態值，避免狀態欄位跟實際時間點不同步；過期後會員還是可以呼叫 `quit()` 自行收尾（此時該階段沒有任何流水鎖待取消、活動錢包也早已歸零，效果上跟「已失效」無異，只是操作紀錄會顯示為「已放棄」）。

### 12. 風控規則

- 每位會員僅可參加一次；須完成 KYC 後領取。
- 活動期間禁止提款、轉帳；本金流水不參與返水、返利及其他優惠。
- 彩金領取資格保留 48 小時，逾期自動失效；放棄活動後不可再次參與。
- 後台須記錄完整操作日誌（領取、充值、流水、彩金、放棄等），所有金額、流水及狀態變更均需可追溯審計。

---

## 二、架構決策：不沿用 Event V2

評估過將此活動掛在既有 `app/Services/EventV2/`（`AbstractEventV2` + `event_v2*` 系列表）之下，結論是**改為獨立模組**，理由：

| 面向 | Event V2 現況 | 與本活動的落差 |
|---|---|---|
| 生命週期模型 | `validate → join → withdraw`，一次 join 對應一次快照式 `balance_limit`/`flow_limit`/`withdraw_limit` | 本活動是 5 個以上狀態、3 組獨立流水目標的多階段狀態機，硬套需要把 `withdraw()` 語意凹成「不真的付款」（`withdraw_formula="0"`）才能避免活動金提早流入主錢包 |
| 放棄流程 | `quit()` 邏輯寫死在 `AbstractEventV2`，只清空活動錢包 | 本活動放棄需回收**已入真金錢包**的彩金、作廢未領部分、本金流水降為 1 倍——遠超現有 `quit()` 職責，且未查證子類別能否覆寫（風險：可能要動到共用基底類別，牽動其他 11 種既有活動類型） |
| 彩金部分領取 | 無對應概念 | 「48hr 內多次儲值、各自核銷同一份上限」是對共用額度做部分核銷，不是一次性參加，`event_v2_user_joins` 單快照 schema 放不下 |
| 稽核輔助機制 | IP/手機/實名 block 矩陣、`reject_joins`/`reject_records` | 本活動風控只有「一人一次 + KYC」，用不到這套機制 |
| 後台列表頁 | `event-v2/join-list` 回傳單快照欄位 | 完全對不上圖示的多階段進度／統計卡需求，無論是否沿用都得開新表新 API |

**仍會沿用的通用平台機制**（獨立於 Event V2 之外）：

- `flow_limits`：全站通用的「流水未達標即擋提款/轉帳」機制（`WithdrawalService::checkFlowLimit()`、`WalletController` 轉帳檢查均已使用），本金鎖定與彩金流水鎖定直接沿用，新增一個 `type` 值即可，不需另建鎖定邏輯。
- 既有錢包入帳／交易紀錄機制（活動金、彩金、真金錢包出入帳）。

---

## 三、資料表設計

### `newbie_challenge_configs`

活動設定（對應後台設定表單）。

| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | int | 主鍵 |
| `agent_id` | int\|null | 適用代理，null＝全站 |
| `activity_amount` | decimal | 活動金（預設 88） |
| `challenge_flow_multiple` | decimal | 挑戰流水倍數（預設 5） |
| `bonus_flow_multiple` | decimal | 彩金流水倍數（預設 12） |
| `min_unlock_amount` | decimal | 最低解鎖金額（預設 10） |
| `max_unlock_amount` | decimal | 最高解鎖金額（預設 1888） |
| `bonus_window_hours` | int | 彩金有效期（預設 48） |
| `allowed_vendor_ids` | JSON | 第二階段挑戰流水計算範圍的遊戲商／遊戲（FUMA、WUDI） |
| `bonus_flow_vendor_ids` | JSON\|null | 第四階段彩金流水計算範圍的遊戲商／遊戲，獨立於 `allowed_vendor_ids`；null＝不限制遊戲商 |
| `allowed_game_type_ids` | JSON\|null | 第二階段挑戰流水計算範圍的遊戲類型；null＝不限制遊戲類型。與 `allowed_vendor_ids` 是兩個獨立維度，建立流水鎖時由 `NewbieChallengeService::buildFlowLimitRestrictions()` 展開成「遊戲商 × 遊戲類型」的組合寫進 `flow_limit_restrictions`，沒設定的維度寫 0 代表不限 |
| `bonus_flow_game_type_ids` | JSON\|null | 第四階段彩金流水計算範圍的遊戲類型，獨立於 `allowed_game_type_ids`；null＝不限制遊戲類型 |
| `ip_block` | tinyint\|null | 1＝同 IP（`members.last_login_ip`）僅一人可參加，比照 `CheckinService` 模式，非 Event V2 那套多層級 scope |
| `phone_block` | tinyint\|null | 1＝同手機號僅一人可參加 |
| `account_block` | tinyint\|null | 1＝同真實姓名（`first_name`）僅一人可參加 |
| `restrict_new_member` | tinyint | 1（預設）＝僅活動開始（`start_time`）後才註冊的玩家可參加；0＝活動開始前註冊的玩家也可參加。`start_time` 未設定則視同不限制，`join()` 內比對 `members.created_at` |
| `sort` | int | 後台活動列表排序（`config-list`），數字越大越前面，純顯示用不影響 `getActiveConfig()` 挑活動的邏輯 |
| `status` | tinyint | 0=停用，1=啟用 |
| `start_time` / `end_time` | timestamp\|null | 活動起訖 |
| `created_at` / `updated_at` | timestamp | — |

### `newbie_challenge_config_exclude_agents`

排除代理（含下線），比照 Event V2 `event_v2_detail_exclude_agents` 的既有慣例：`config_id` + `agent_id` 複合主鍵，兩者皆 FK cascade delete。判斷方式是拿查詢方（會員所屬代理）自己的 `hierarchy_array`（由近到遠的上級代理 ID 陣列，含自己）跟排除清單比對是否有交集，命中即整個活動（`getActiveConfig()`）都查不到，涵蓋 `detail`／`join`／`progress`／`event/list` 所有入口，不是只擋 join。

| 欄位 | 型別 | 說明 |
|---|---|---|
| `config_id` | bigint | 關聯 `newbie_challenge_configs.id` |
| `agent_id` | int | 關聯 `agents.id`，被排除的代理（含其下線） |

### `newbie_challenge_progress`

會員參加進度（每會員一筆，一人僅一次）。

| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | int | 主鍵 |
| `config_id` | int | 關聯 `newbie_challenge_configs.id` |
| `member_id` | int | 會員 ID |
| `stage` | tinyint | 狀態機：1 進行中／2 待領取彩金／3 彩金任務中／4 彩金任務完成（整個活動終態）／5 代理後台強制取消（`OPERATOR_QUIT`）／6 會員自己放棄（`QUIT`）；5、6 業務效果完全一樣（回收＋終態不可逆），差別只在誰觸發；無獨立的「已失效」值，2 過期與否即時看 `bonus_window_expire_at` |
| `activity_reward` | decimal | 領取的活動金（快照） |
| `challenge_flow_limit_id` | int\|null | 第二階段挑戰流水鎖（`flow_limits.id`）；挑戰流水的目標與累計直接讀這筆 `flow_limits`，progress 上不另存 |
| `bonus_flow_limit_id` | int\|null | 第四階段彩金流水鎖（`flow_limits.id`），倒數計時後第一筆充值核銷彩金時建立 |
| `unlocked_bonus_amount` | decimal | 挑戰完成後算出的可解鎖彩金（10～1888） |
| `bonus_window_expire_at` | timestamp\|null | 48hr 倒數到期時間 |
| `deposit_order_id` | int\|null | 觸發彩金核銷的儲值訂單 ID；尚未核銷（confirm-deposit 還沒成功過）則為 null |
| `deposit_transaction_log_id` | int\|null | 觸發彩金核銷的後台開分 `transaction_logs.id`（target/tickets#4352，僅代理組別設置 `AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT` 開啟且候選來源為後台開分時非 null），與 `deposit_order_id` 分屬不同 ID 空間、互斥 |
| `deposit_amount` | decimal\|null | 觸發核銷的該筆儲值金額 |
| `claimed_bonus_amount` | decimal\|null | 核銷的彩金金額，流水跑完撥款後金額不變、只是撥入主錢包 |
| `principal_total` | decimal | 累積入金本金；即時查 `orders` 表算總額，純顯示用，不建立流水鎖 |
| `joined_at` / `challenge_completed_at` / `bonus_completed_at` / `principal_unlocked_at` / `completed_at` / `quit_at` | timestamp\|null | 各階段時間戳 |

> **不再有獨立的 `newbie_challenge_bonus_claims` 表**：原本規劃 48hr 視窗內多筆儲值可各自部分核銷（一對多），後來拍板改成只認第一筆儲值、一次核銷完畢（見[待確認事項第 1 點](#五待確認事項需-pm風控裁決)），變成每個 `progress_id` 至多一列，一對多表失去意義，`deposit_order_id`/`deposit_amount`/`claimed_bonus_amount` 直接收斂成 `newbie_challenge_progress` 上的欄位，比照 `bonus_flow_limit_id` 已經是直接放在 progress 上的模式。

### `newbie_challenge_operation_logs`

完整操作稽核（對應風控規則「後台須記錄完整操作日誌」）。

| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | int | 主鍵 |
| `progress_id` | int | 關聯 `newbie_challenge_progress.id` |
| `operator_type` | tinyint | system／member／agent |
| `operator_id` | int\|null | 操作者 ID（系統操作為 null） |
| `action` | varchar | 動作（領取／充值核銷／流水達標／彩金撥款／放棄／逾期失效等） |
| `from_stage` / `to_stage` | tinyint\|null | 狀態轉換前後值 |
| `remark` | varchar\|null | 補充說明 |
| `created_at` | timestamp | — |

### `newbie_challenge_block_joins` / `newbie_challenge_block_records`

重複阻擋（IP/手機/真實姓名）名單，比照 Event V2 `event_v2_reject_joins`/`event_v2_reject_records` 的既有慣例：一組 join/records 表，join 表記「這個會員在這個 config 下目前的阻擋狀態」，records 表是該阻擋事件的完整觸發歷史（append-only）。`checkDuplicateBlock()` 命中任一維度時寫入，`join()` 成功時消耗掉。

**`newbie_challenge_block_joins`**（一位會員在一個 config 下、還沒被消耗的期間內只有一筆）：

| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | int | 主鍵 |
| `config_id` | int | 關聯 `newbie_challenge_configs.id` |
| `member_id` | int | 被擋下的會員 ID |
| `last_block_record_id` | int\|null | 最新一筆阻擋紀錄 |
| `progress_id` | int\|null | 後台放行後、玩家真的 join 成功時回填 `newbie_challenge_progress.id`；null＝還沒被消耗（可能還在阻擋中、也可能已放行等玩家重試） |
| `allowed_time` | timestamp\|null | 後台按下「允許參加」的時間 |
| `status` | tinyint | 1=阻擋中(`BLOCKED`)／2=已放行(`ALLOW_JOIN`，一次性豁免，等玩家重試)／3=已用此放行完成參加(`JOINED`) |

**`newbie_challenge_block_records`**（同一筆 block_join 下，阻擋原因(`block_type`)換了才新增一筆，原因不變只更新既有那筆的 IP/訊息，避免玩家狂 retry 洗記錄）：

| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | int | 主鍵 |
| `block_join_id` | int | 關聯 `newbie_challenge_block_joins.id` |
| `block_type` | tinyint | 1=IP／2=手機／3=真實姓名；見 `NewbieChallengeBlockTypeEnum` |
| `message` | varchar | 觸發當下的繁中錯誤訊息快照 |
| `ip` | varchar | 觸發當下的玩家 IP |

**放行是一次性豁免、不是永久白名單**：`checkDuplicateBlock()` 一開始查有沒有這個會員＋這個 config 下 `progress_id IS NULL` 且 `status=ALLOW_JOIN` 的 block_join，有的話直接跳過本次所有阻擋檢查；`join()` 成功後把這筆的 `progress_id`/`status=JOINED` 回填消耗掉，下次若又被擋（例如 IP 又跟別人重複）會另開一筆新的 block_join，不會自動放行。

順帶修正一個既有小 bug：`checkDuplicateBlock()` 原本沒有像 `withDuplicateBlockLock()` 一樣先判斷 `mobile`/`first_name` 是否為空值就直接查詢，多個空值會員可能互相誤判成重複；這次一併補上 `!empty()` 判斷。

**每個維度各自比對兩層，任一命中就擋**（比照 Event V2 `EnforcesParticipationBlocks` 的 `ALL_MEMBER` 分層邏輯，2026-08-31 需求變更加上；`ip_block`/`phone_block`/`account_block` 維持原本的 0/1 簡單開關，不像 Event V2 做成複選多層，也刻意不加代理階層範疇，維持跟原本一致的全平台範疇）：

1. 已有人用這個 IP/手機/姓名參加過新人挑戰（`newbie_challenge_progress` join `members`，原本就有的邏輯）
2. 全平台任何「更早註冊」的會員，註冊資料就跟這個 IP/手機/姓名相同（新增，直接查 `members` 表，不限是否參加過任何活動）：IP 比對 `members.register_ip`（不是 `last_login_ip`，同時比對當下請求 IP 跟這位會員自己的註冊 IP）；手機走 `phoneBindings` 綁定紀錄（要求對方 `mobile_validate=1`，不是直接比對 `members.mobile` 目前值）；姓名直接比對 `first_name`。三個查詢都用 `created_at`／綁定時間排早晚，避免自己卡自己。

對應到 `NewbieChallengeService` 的三個私有方法 `hasIpBlock()`/`hasPhoneBlock()`/`hasAccountBlock()`（取代原本單一個 `hasBlockRecord()`）。

---

## 四、API 設計

### 會員端

| API | 說明 |
|---|---|
| `POST newbie-challenge/detail` | 參加頁的活動介紹內容（名稱/說明規則文字，依語系、無對應語系 fallback 英文）＋活動金/流水目標/解鎖金額區間/可參與遊戲商等數字設定；**不含**會員參加狀態 |
| `POST newbie-challenge/join` | 領取活動金（KYC 檢查、一人一次防重、開啟 5 倍流水目標） |
| `POST newbie-challenge/progress` | 目前狀態、挑戰流水與彩金流水各自的進度（目標/已完成/剩餘/百分比）、48hr 倒數秒數、可解鎖彩金、`pending_bonus_amount`（已核銷、流水跑完後將撥款的彩金金額，撥款前後都可用；是否已真的撥款看 `stage`）、累積本金、`can_confirm_deposit`（待領取彩金階段是否已有符合資格的儲值，供前端「下一步」按鈕灰階/可按判斷） |
| `POST newbie-challenge/unlock-bonus` | 挑戰流水達標後結算解鎖彩金，進入待領取彩金階段 |
| `POST newbie-challenge/confirm-deposit` | 待領取彩金階段，會員在倒數頁按「下一步」主動確認已儲值：查首筆符合資格的儲值來核銷彩金並進入彩金任務中；查無符合資格的儲值則明確回錯誤。只適用 `BONUS_PENDING`，其他階段呼叫回錯誤 |
| `POST newbie-challenge/claim-bonus` | 彩金流水達標後撥款進真金錢包，進入彩金任務完成階段 |
| `POST newbie-challenge/quit` | 主動放棄（活動錢包歸零＋未完成流水鎖取消稽核；`BONUS_COMPLETED` 之後不可放棄） |

新人挑戰同時掛在既有的活動總覽 `POST event/list`（`EventV2Controller::list()`，non-auth 路由，訪客也能看）：`NewbieChallengeService::getEventListItems()` 組出一筆 `EventV2ListItemData`（沿用活動 v1/v2 共用的 DTO，新增 `tp_event` 欄位供前端辨識該導去哪個系統，event_v1/event_v2 為 `null`，新人挑戰固定寫死 `1`），跟活動 v1/v2 的清單 `concat()` 後統一以 `sort desc, created_at desc` 排序輸出——`sort` 直接吃 `newbie_challenge_configs.sort`（後台可設定，見上方欄位表），跟 `events.sort`/`event_v2_details.sort` 是同一個排序空間，不是寫死新人挑戰永遠最前面；會員只有終態（已完成/已放棄）才不再出現在列表中，進行中的各階段（含待領取彩金/彩金任務中）仍要顯示，讓會員可以隨時回到列表點回活動頁繼續；訪客則一律看得到（行銷曝光用）。清單項目的 `name`/`image` 取自 `newbie_challenge_config_langs`。

語系內容存在獨立的 `newbie_challenge_config_langs` 表（`config_id` + `language_id` + `name`/`content`），比照活動V2 `event_v2_detail_lang` 的既有慣例：請求語系有資料就用請求語系，沒有則 fallback 英文（`language_id=1`）。`content` 是打包文字（活動說明＋規則），其中出現的活動金/流水目標/上限等數字是後台手動填入的文字，改動 `newbie_challenge_configs` 的數字設定後需要記得手動同步更新對應語系的文字內容，不會自動帶入。

> **彩金領取改由會員主動觸發（`confirm-deposit`），不掛儲值 callback**：原本設計是掛在 `PaymentService::updateMemberBalance()`（三方回調專用），後來發現自助上分（`AutoDepositController` 上傳證明後，實際上分是走**舊版 `API_Agent`** 自己的 `CashFlowService::updateMemberBalance()`，跟 `apis` 完全是兩條不相干的程式碼路徑）不會呼叫到這個 hook，導致該管道的儲值永遠無法核銷彩金。改成 `confirm-deposit` 直接查 `orders` 表（`status=DEPOSIT_FINISHED` 是新舊兩套系統共用同一張表、同一個數值，判斷「錢是否真的到帳」不依賴誰來呼叫）：只適用 `BONUS_PENDING`，查「挑戰完成後、彩金視窗內、達最低解鎖門檻」的第一筆已上分訂單，有則核銷彩金＋開 12x 彩金流水（`bonus_flow_limit_id`），沒有則明確回錯誤，其他階段呼叫一律回錯誤。不開 scheduled job、`progress()` 也不主動查表觸發任何狀態變更（只讀 `orders` 算 `can_confirm_deposit` 旗標供前端顯示），所有查表核銷都由會員主動按按鈕觸發，讓畫面回饋跟查表時機對齊。
>
> **後台開分視為儲值（`AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT`，target/tickets#4352）**：`findQualifyingDeposit()` 預設只認上面的真實訂單；真實訂單全不合格時，若代理組別設置該開關開啟（跟活動 V2 共用同一個開關，見 `docs/event-v2/field-reference.md`），才退而求其次查同一視窗內的後台開分（`transaction_logs` `type=DEPOSIT`，CS 後台 `newTransaction` 建立、無對應 `orders` 列），一樣取「達最低解鎖門檻」的第一筆（依 `created_at` 排序）。命中哪個來源分別寫回 `deposit_order_id` 或 `deposit_transaction_log_id`（兩欄互斥），`FlowLimit.reference_id` 直接用該來源自己的 id（後台開分沒有訂單類流水鎖可查，不需要像活動 V2 那樣多一層 `TransactionLog` 反查）。
>
> **`principal_total`（累積本金）純即時計算，不維護累加欄位、不建立流水鎖**：`getProgress()` 顯示用、`claimBonus()` 撥款用都是直接查 `orders` 表算「挑戰完成後已上分訂單」總和，兩處算法一致，沒有背景累加漏算的風險。原本規劃 `claim-bonus` 撥款當下順便開一筆 1x 本金流水鎖（`principal_flow_limit_id`），後來確認平台層 `PaymentService::updateMemberBalance()` 本身在三方回調儲值入帳時就會依代理的 `DEPOSIT_FLOW_LIMIT_RATE` 設定自動開一筆 `ORDER_DEPOSIT` 流水鎖（`WithdrawalService::checkFlowLimit()` 對會員名下所有 `FlowLimit` 一併加總判斷，不分是誰建的）；活動這邊再開一筆會變成雙重鎖定，且該平台機制不覆蓋自助上分（跟上一段同一個落差）。決定活動不重複處理儲值流水風控——若平台層機制有覆蓋範圍的問題，屬於平台層本身要修，不在本活動職責內，見[待確認事項第 6 點](#五待確認事項需-pm風控裁決)。

### 代理後台

| API | 說明 |
|---|---|
| `POST agent/newbie-challenge/config-list` | 活動設定列表（依代理階層範疇） |
| `POST agent/newbie-challenge/config-detail` | 單筆設定詳情（含 `configLangs`） |
| `POST agent/newbie-challenge/config-create` | 新增設定（含 `exclude_agent` 排除代理清單），僅 admin／admin 子帳號 |
| `POST agent/newbie-challenge/config-update` | 更新設定（`exclude_agent` 全刪重建），僅 admin／admin 子帳號 |
| `POST agent/newbie-challenge/config-update-status` | 切換設定啟用/停用（單獨端點，不用整包 config-update 欄位），僅 admin／admin 子帳號 |
| `POST agent/newbie-challenge/progress-list` | 會員參加紀錄列表（依代理階層範疇），含即時算的活動錢包餘額 `event_wallet_balance` |
| `POST agent/newbie-challenge/progress-detail` | 單一會員進度詳情（含各階段流水 flow_limit/reach） |
| `POST agent/newbie-challenge/operation-log-list` | 操作日誌查詢，可用 `progress_id` 篩單一會員 |
| `POST agent/newbie-challenge/monitor` | 依邏輯狀態分組的即時統計（含依 `bonus_window_expire_at` 即時拆算的已失效） |
| `POST agent/newbie-challenge/update-wallet-balance` | 後台調整會員活動錢包餘額（`progress_id` + 帶正負號的 `balance` 異動量），比照 `EventV2Controller::updateWalletBalance()` 的既有慣例；下限鎖 0。`WalletEnum::CODE_EVENT_WALLET` 是 Event V2 與新人挑戰共用同一顆活動錢包，交易紀錄的 `type` 用獨立的 `WalletTransactionEnum::NEWBIE_CHALLENGE_OPERATOR_CHANGE`（跟 Event V2 的 `EVENT_V2_OPERATOR_CHANGE` 區分開，避免帳目來源混淆） |
| `POST agent/newbie-challenge/quit` | 代理後台強制取消會員參加中的挑戰（`progress_id`）。權限比照 `EventV2Controller::quit()`：代理階層看得到這個會員就能取消，不是 admin-only（PM 2026-08-31 確認採用此權限範圍）。核心回收邏輯（活動錢包歸零、流水鎖取消稽核、`BONUS_COMPLETED` 之後不可取消）完全共用會員主動放棄的 `NewbieChallengeService::quit()`，只是最終 `stage` 記 `OPERATOR_QUIT`（=5，跟會員自己放棄的 `QUIT`=6 區分開，見上方 `stage` 欄位說明），`operation_logs.operator_type` 也記 `AGENT` |
| `POST agent/newbie-challenge/block-list` | 重複阻擋（IP/手機/真實姓名）名單（依代理階層範疇），依最新一筆阻擋時間排序 |
| `POST agent/newbie-challenge/block-detail` | 單一阻擋名單的完整觸發歷史（`block_join_id`），新到舊 |
| `POST agent/newbie-challenge/allow-join` | 後台放行（`block_join_id`），一次性豁免讓玩家下次直接 join 過去，`join()` 成功後自動消耗掉，不是永久白名單 |

原本此處記錄「沒有代理強制放棄 API（曾規劃 `quit`，後決定移除，比照 EventV2 的寬鬆放棄權限風險過高，未實作）」——2026-08-31 需求變更，決定比照 EventV2 的權限範圍補回這個 API（見上表）。`NewbieChallengeService::quit()` 簽名同步擴充 `operatorType`/`operatorId` 參數（預設 `MEMBER`，會員端呼叫點不用改），讓 Agent 端能共用同一套回收邏輯只是換記 `AGENT`。

---

## 五、待確認事項（需 PM／風控裁決）

1. ~~彩金領取的次數語意~~ **已定案**：以倒數計時開始後**第一筆充值**為準，僅此一筆決定核銷金額，48hr 視窗內非第一筆的後續充值不再受理核銷。`deposit_order_id`/`deposit_amount`/`claimed_bonus_amount` 因此收斂成 `newbie_challenge_progress` 上的欄位，不再是獨立的 `newbie_challenge_bonus_claims` 一對多表（原本 `bonus_flow_target`/`bonus_flow_reach` 自算欄位的殘留設計已隨表一併移除）。
2. ~~遊戲限制範圍~~ **已定案**：第二階段挑戰流水與第四階段彩金流水各自設定計算範圍，可以不同。遊戲商用 `allowed_vendor_ids`／`bonus_flow_vendor_ids`，遊戲類型用 `allowed_game_type_ids`／`bonus_flow_game_type_ids`。在這兩個階段期間，範圍外的遊戲會被擋下。大廳與遊戲清單會把它灰化（`GameController` 的 `list`／`listByTypes`／`recentlyGameIds`，以及 `LobbyController::games()`）。會員繞過大廳直接呼叫進遊戲的 API，也會被 `GameController::checkGameAvailable()` 擋下，錯誤訊息是 `newbie_challenge.validation.game_not_allowed`。這些判斷都走 `NewbieChallengeService::isGameDisabled()`，讀的是該會員當下流水鎖實際寫入的 `flow_limit_restrictions`，跟算流水的 stored procedure 用同一份資料。本活動不自建本金流水鎖（見第 6 點），所以沒有第五階段的遊戲限制。
3. ~~放棄活動的彩金回收機制~~ **已定案（設計面自然解掉，非風控裁決）**：`claimBonus()` 要求 12x 彩金流水已完成才會撥款進真金錢包，所以 `quit()` 能被呼叫的所有階段（`CHALLENGING`／`BONUS_PENDING`／`BONUS_WAGERING`，見 `NewbieChallengeService::quit()` 的階段限制）彩金一定還沒撥款，不存在「已撥入真金錢包的錢要往回扣」的情境；一旦進到 `BONUS_COMPLETED`（已撥款）就直接禁止放棄。回收＝活動錢包歸零＋未完成流水鎖取消稽核，不涉及扣會員主錢包餘額。
4. ~~儲值 callback 的交易邊界~~ **已改版（原「掛在 `PaymentService::updateMemberBalance()`」設計已推翻）**：原設計是不碰各金流商 `DepositCallback.php`，收斂到 `DepositNotifyController::processSuccessfulDeposit()` → `PaymentService::updateMemberBalance()` 同一 transaction 內處理。後來發現自助上分（`AutoDepositController` 上傳證明後的實際上分）走的是**舊版 `API_Agent`** 自己的 `CashFlowService`，完全不會呼叫到 `apis` 的 `updateMemberBalance()`，該管道的儲值永遠無法核銷彩金。改成 `confirm-deposit`（會員主動按「下一步」）直接查 `orders.status=DEPOSIT_FINISHED`（新舊系統共用同一張表、同一個數值），不再依賴任何 callback hook，`PaymentService::updateMemberBalance()` 已移除對 `NewbieChallengeService` 的呼叫。
5. ~~與既有活動互斥規則的關係~~ **已定案**：與 Event V2 互斥，兩邊不能同時參加。`NewbieChallengeService::join()` 檢查 `member->is_event_v2`，`AbstractEventV2::validate()` 檢查 `newbie_challenge_progress` 是否有非終態（非 `BONUS_COMPLETED`/`QUIT`/`OPERATOR_QUIT`，且若為 `BONUS_PENDING` 則 `bonus_window_expire_at` 尚未過期）記錄，雙向擋下、共用 `ONLY_ONE_EVENT` 失敗碼。互斥範圍是整個新人挑戰活動週期（含彩金/本金階段），不是只有活動金流水那段。
6. ~~本金鎖定機制~~ **已定案（原「活動自建 1x 流水鎖」設計已推翻）**：原設計是 `claim-bonus` 撥款當下順便開一筆 `NEWBIE_CHALLENGE_PRINCIPAL_FLOW` 型別的 `FlowLimit`（1x 本金流水）。後來確認平台層 `PaymentService::updateMemberBalance()` 本身在三方回調儲值入帳時，若代理設定 `DEPOSIT_FLOW_LIMIT_RATE`（agent 客製化設定 6011）> 0，就會自動開一筆 `ORDER_DEPOSIT` 型別流水鎖，跟活動自建的重複疊加（提款檢查是對會員名下所有 `FlowLimit` 加總，不分建立來源），且該平台機制同樣不覆蓋自助上分。決定活動不再自建本金流水鎖，`principal_flow_multiple`（設定）／`principal_flow_limit_id`（鎖定紀錄）兩個欄位已移除；`principal_total` 保留純顯示（累積入金金額），儲值流水風控完全交由平台層機制負責，若平台層機制的覆蓋範圍（自助上分等）有問題，屬於平台層本身要修，不在本活動職責內。

---

## 六、工項與時程估計

> 以一位熟悉本專案框架的後端工程師抓，不含後台前端 UI 開發、不含 PM 確認等待時間、不含 QA/UAT 驗收週期。

| 工項 | 天數 |
|---|---|
| Migration + Model（4 張新表 + `flow_limits` 新增 type） | 1–1.5 |
| 領取活動金 API | 1 |
| FUMA/WUDI 下注流水累計判斷＋達標觸發（結算可解鎖彩金、切換階段、開 48hr 倒數） | 2–2.5 |
| confirm-deposit（查訂單核銷彩金、開 12 倍流水任務） | 1.5–2 |
| 彩金 12 倍流水累計＋達標撥款（不含本金鎖，見待確認事項第 6 點） | 1.5–2 |
| 虧損續充 | 0.5–1 |
| 放棄活動（回收彩金、作廢、降流水） | 1–1.5 |
| 會員端進度查詢 API | 0.5–1 |
| 48hr 到期排程 job | 0.5 |
| 後台設定 CRUD API | 1–1.5 |
| 後台會員進度列表＋統計卡 API | 1–1.5 |
| 操作日誌查詢 API | 0.5 |
| 客服端強制放棄／人工調整 API | 1 |
| PHPUnit 測試（狀態機、併發鎖、虧損續充、48hr 到期、部分彩金核銷上限等邊界案例） | 3–4 |
| Code review／修正 | 1–2 |

**小計約 17–23 個工作天，抓 3.5–4.5 週（一人）。**

最大工時風險集中在「流水累計判斷」與「測試」兩塊——這是活動邏輯本身的複雜度，與是否沿用 Event V2 無關，躲不掉。

---

## 七、UI 設計稿

### 第一階段：領取活動金（參加頁）

![領取活動金頁面](./join-page-mockup.png)

> 圖檔待補——請將設計稿另存為 `join-page-mockup.png` 放到本目錄（`docs/新人專享挑戰活動/`），連結即可生效。

**畫面內容**：

- 頂部標題「新人專享挑戰活動」，右上角狀態標籤「未參與」。
- 五階段進度指示條：領取／挑戰／解鎖／彩金／完成（對應 [活動狀態](#十一活動狀態) 的簡化版，UI 上合併為 5 步）。
- 活動金金額顯示（依幣別，如 MYR 88.00）。
- 活動說明條列：① 領取 88 活動金 ② 在指定遊戲完成 440 有效流水 ③ 充值解鎖彩金（充多少得多少，最高 1888）。
- 「立即領取活動金」按鈕 → 呼叫 `POST newbie-challenge/join`。
- 規則說明：一人一次、需完成 KYC、活動期間禁止提款轉帳、指定遊戲 FUMA、WUDI。

**API 設計對應**：此頁面只需要「領取成功／失敗」這個結果，活動金金額、流水目標等顯示資料都來自進入頁面時另外呼叫的活動詳情／設定查詢，不是靠 join 回應帶出來。因此 `POST newbie-challenge/join` 定案為**純成功/失敗回應**（`ApiResponse::success()`，不帶 `data`），不回傳 `NewbieChallengeProgressResource` 那類資料——這點修正了[第四節 API 設計](#四api-設計)原先「彩金部分領取不開對外 API」以外，其餘端點預設回傳資料的假設；`join` 是目前確認的例外，其餘查詢類端點（如 `GET newbie-challenge/progress`）仍會回傳完整進度資料。
