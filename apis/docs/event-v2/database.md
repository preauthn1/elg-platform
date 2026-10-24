# 活動 V2 — 資料庫設計

> 所有資料表以 `event_v2` 為前綴，共 15 張表。

---

## 表關聯圖

```
event_v2                          (活動類型定義，handler_class + schedule)
└── event_v2_details              (活動細節，formulas / configs / 門檻，每個 agent 一筆)
    ├── event_v2_detail_langs         (多語系名稱 / 圖片 / 說明)
    ├── event_v2_detail_vendors       (限定遊戲商：只有持有指定遊戲商帳號才可參加)
    ├── event_v2_detail_exclude_vendors  (排除遊戲商：這些遊戲商的下注不計流水)
    ├── event_v2_detail_game_types    (遊戲類型標籤：前台顯示用)
    ├── event_v2_detail_exclude_agents   (排除代理：這些代理（含下線）看不到此 detail)
    ├── event_v2_detail_targets       (目標用戶：只開放給指定會員或會員群組；無列＝不限制)
    ├── event_v2_reject_joins         (驗證失敗彙整，每個玩家 + 活動唯一一筆)
    │   └── event_v2_reject_records   (驗證失敗明細，每次拒絕各一筆)
    └── event_v2_user_joins           (玩家參加記錄)
        ├── event_v2_accounts             (參加時綁定的遊戲商帳號)
        ├── event_v2_bonus_codes          (獎勵碼記錄，bonus_vendor_id 場景)
        ├── event_v2_invites              (邀請好友明細，InviteFriendService 用)
        ├── event_v2_wallet_transaction_logs  (活動錢包交易記錄聯結)
        └── event_v2_recycles             (活動錢包剩餘回收記錄，is_recycle=1 才有)
```

---

## 一、活動定義層

### `event_v2`

活動類型的母表，一個 `event_v2` 代表一種活動邏輯（如「每日儲值」、「邀請好友」），可對應多個 `event_v2_details`（不同代理不同設定）。

| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | int | 主鍵 |
| `name` | varchar | 活動類型名稱（內部識別用，如「每月儲值」） |
| `schedule` | varchar\|null | Cron 重置時間（null=不重置）；詳見下方 |
| `handler_class` | varchar | 處理邏輯的 service class 名稱（不含命名空間） |
| `created_at` | timestamp | — |
| `updated_at` | timestamp | — |

**`schedule` 常用值**

| 值 | 重置時機 |
|---|---|
| null | 不重置（一次性活動） |
| `0 0 * * *` | 每日 UTC 00:00（= 台灣 08:00）重置。建表 migration 的預設值；但實際有在營運每日活動的站（vplus33，2026-10-01 實查）已把每日登入、每日儲值組合改成 `0 16 * * *`（台灣 00:00），dev 亦同。其他站仍是 `0 0` 只因那些每日活動沒在跑；新站開每日活動要記得改 `0 16` |
| `0 0 1 * *` | 每月 1 日 00:00 重置 |
| `0 0 * * 1` | 每週一 00:00 重置 |
| `0 16 * * *`（紅包） | 台灣 00:00 觸發；紅包不整個類型全清，由 `AngpowPeriodResetService` 依各檔 `member_period` 只清上一期的 JOIN（target/tickets#4234） |

> 重置是由後端排程觸發，將進行中的 `event_v2_user_joins` 結算為 `SYSTEM_INIT`，並依 `is_recycle` 決定是否建立 `event_v2_recycles`。

---

### `event_v2_details`

活動細節設定，一個 `event_v2` 可有多筆（按 `agent_id` 區分，讓不同代理下的玩家有不同公式或門檻）。每一筆是前台 API 的實際操作目標。

| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | int | 主鍵，也是前台 API 傳入的 `event_detail_id` |
| `event_v2_id` | int | 關聯 `event_v2.id` |
| `agent_id` | int | 適用的代理 ID（`getEventV2List` 依 `hierarchy_array` 篩選） |
| `condition_formula` | varchar\|null | 參加條件公式（見 [field-reference.md](field-reference.md)） |
| `condition_config` | JSON\|null | 條件補充設定 |
| `reward_formula` | varchar\|null | 獎勵計算公式 |
| `reward_config` | JSON\|null | 獎勵階梯 / 上限設定 |
| `balance_formula` | varchar\|null | 最低活動錢包餘額公式（提款前需達到） |
| `balance_config` | JSON\|null | 餘額限制補充設定（目前未使用，預留） |
| `flow_formula` | varchar\|null | 活動內流水門檻公式（提款前需達到） |
| `flow_config` | JSON\|null | 流水限制補充設定（目前未使用，預留） |
| `withdraw_formula` | varchar\|null | 可提款金額公式；積分型＝積分數量公式 |
| `withdraw_config` | JSON\|null | 提款補充設定（如 `{"balance_multiple": 1}`）；積分型＝`{"expire":有效期小時,"limit":上限}`，缺省永久／不封頂 |
| `withdraw_type` | tinyint | 領取去向：1=主錢包（預設）、2=積分；見 `EventV2WithdrawTypeEnum` 與 [field-reference.md — withdraw_type](field-reference.md#withdraw_type) |
| `member_period` | tinyint | 會員週期（後台「可重複參加」）：0=不分週期（預設）、1=日、2=週、3=月、4=年；每人次數／金額的累計區間、不勾參加即領獎時的作廢週期。見 `EventV2PeriodEnum` 與 [field-reference.md — member_period／total_period](field-reference.md#member_period--total_period)。只有紅包可設非 0；其他類型的週期由 `event_v2.schedule` 決定 |
| `total_period` | tinyint | 全站週期（後台「包括期間」）：值域同上；`max_join`／`max_amount` 的累計區間，過了重新計、不作廢。只有紅包可設非 0 |
| `bonus_vendor_id` | int\|null | 觸發優惠碼的遊戲商 ID |
| `redirect_path` | varchar\|null | 參加成功後前台導向路徑 |
| `ip_block` | varchar\|null | IP 重複參加限制（值見 EventV2IpBlockEnum） |
| `phone_block` | varchar\|null | 手機重複參加限制（值見 EventV2PhoneBlockEnum） |
| `account_block` | varchar\|null | 實名帳號重複參加限制（值見 EventV2AccountBlockEnum） |
| `join_withdraw` | tinyint | 1=參加即自動提款，0=手動申請 |
| `cost_type` | int | 1=JOIN（參加時記成本），2=COMPLETE（完成時記成本） |
| `start_time` | timestamp\|null | 活動開始時間（null=不限） |
| `end_time` | timestamp\|null | 活動結束時間（null=不限） |
| `sort` | int | 前台排序（數字越大越前） |
| `is_recycle` | bool | 1=退出時記錄剩餘活動餘額，0=不記錄 |
| `status` | tinyint | 0=停用，1=啟用（StatusEnum::ENABLE） |
| `created_at` / `updated_at` | timestamp | — |

---

## 二、細節設定附屬表

### `event_v2_detail_langs`

多語系的前台顯示內容，一個 detail 可有多語系。

| 欄位 | 說明 |
|---|---|
| `event_v2_detail_id` | 關聯 `event_v2_details.id` |
| `language_id` | 語系 ID（1=英文為預設備用語系） |
| `name` | 活動顯示名稱 |
| `image` | 活動封面圖 URL |
| `reward_name` | 獎勵說明文字 |
| `content` | 活動詳細說明（longtext，HTML 或 Markdown） |

前台語系回退邏輯：先取玩家語系，找不到則取 `language_id=1`（英文）。

---

### `event_v2_detail_vendors`（限定遊戲商）

中間表。設定後，只有**持有這些遊戲商帳號的玩家**才能參加此活動。不設定=全部玩家均可。

後台 API Response 欄位名稱：`restrict_vendor`

| 欄位 | 說明 |
|---|---|
| `event_v2_detail_id` | 關聯 `event_v2_details.id` |
| `vendor_id` | 遊戲商 ID |

---

### `event_v2_detail_exclude_vendors`（排除遊戲商流水）

中間表。設定後，玩家在這些遊戲商的下注**不計入活動流水**門檻。不設定=全部遊戲商均計入。

後台 API Response 欄位名稱：`exclude_vendor`

| 欄位 | 說明 |
|---|---|
| `event_v2_detail_id` | 關聯 `event_v2_details.id` |
| `vendor_id` | 排除的遊戲商 ID |

---

### `event_v2_detail_game_types`（遊戲類型限制）

中間表。限制玩家**參加活動期間**只能進入指定遊戲類型的遊戲。同一組條件用在兩個地方：

- 進入遊戲時擋下：`GameController::checkGameAvailable()`。取遊戲網址的 `getUrl`、`getWidgetUrl`、`getBonusCode` 都會先呼叫它，不符合就拋例外。
- 遊戲列表灰化：`EventV2Service::isGameDisabled()`。由 `GameController` 的 `list`、`listByTypes`、`recentlyGameIds` 與 `LobbyController::games` 呼叫，結果寫進遊戲的 `disable` 欄位，前台據此把遊戲顯示成不可點。這條只回傳是否停用，不拋例外。

**驗證邏輯**（兩處條件相同，以下以 `GameController::checkGameAvailable()` 描述）：
- 玩家有進行中活動（`member->is_event_v2`）且嘗試進入某款遊戲時，確認該遊戲的 `type_id` 是否在活動允許的遊戲類型清單中
- 此表**無記錄**（未設定）→ 任何遊戲類型均可進入
- 此表**有記錄** → 僅允許進入符合 `game_type_id` 的遊戲，否則拋出 `event_v2.validation.game_type` 例外

另外，前台活動列表 API 也會將 `game_type_ids` 回傳給前端，供 UI 顯示活動適用的遊戲類型標籤。

| 欄位 | 說明 |
|---|---|
| `event_v2_detail_id` | 關聯 `event_v2_details.id` |
| `game_type_id` | 允許進入的遊戲類型 ID |

---

### `event_v2_detail_exclude_agents`（排除代理）

中間表。設定後，這些代理（及其**全部下線**）看不到此活動（在 `getEventV2List` 用 `whereDoesntHave` 過濾）。

| 欄位 | 說明 |
|---|---|
| `event_v2_detail_id` | 關聯 `event_v2_details.id` |
| `agent_id` | 排除的代理 ID |

### `event_v2_detail_targets`（目標用戶，target/tickets#4234）

中間表，所有活動類型共用。一檔活動**沒有任何一列＝不限制**（既有活動全部是這樣）；有的話，只有被指定的會員、或**目前**所屬群組被指定的會員能參加，否則 `validate()` 回 `TARGET_NOT_MATCHED`（0021）。只存勾選的 id、不展開群組成員：參加當下才比對，群組調整即時生效。

結構與判斷方式比照風控會員限制 `member_restriction_rules`（型別列舉沿用 `MemberRestrictionTargetEnum`）。

| 欄位 | 說明 |
|---|---|
| `event_v2_detail_id` | 關聯 `event_v2_details.id` |
| `target_type` | morph alias：`member`（指定會員）／`member_group`（會員群組）。一檔活動只會有一種 |
| `target_id` | `members.id` 或 `member_groups.id` |

索引：`unique(event_v2_detail_id, target_type, target_id)`、`index(target_type, target_id)`（給前台反查「這個會員看得到哪些活動」）。

後台 create／update 送 `target_type`（0／不送＝不限制、1＝指定會員、2＝會員群組）＋`target_ids`：選了類型必須有內容、沒選類型不能帶內容；**只能從活動代理商帳號那條線裡選**——代理商帳號照舊決定誰看得到活動，目標用戶只是在這條線內再縮小誰能參加。會員＝上層代理是活動代理或它的下線；群組＝建立者是活動代理的上層／自己（可套用到它底下的會員）或它的下線（`EventV2TargetScope`）。後台下拉用 `event-v2/target-options`（`agent_id`＋`target_type`＋會員時的 `keyword`）取選項，與驗證同一份規則；Update 沒送 `target_type`＝沿用原值（舊後台），送了就整組重寫。

---

## 三、參加記錄層

### `event_v2_user_joins`

玩家每次參加活動的記錄，計算出的門檻值（`balance_limit`、`flow_limit` 等）於 `join()` 時快照寫入，後續以本筆數值判斷。

| 欄位 | 說明 |
|---|---|
| `id` | 主鍵 |
| `event_v2_detail_id` | 關聯 `event_v2_details.id` |
| `member_id` | 會員 ID |
| `order_id` | 觸發活動的儲值訂單 ID（無儲值條件時為 0） |
| `deposit_transaction_log_id` | 觸發活動的後台開分 `transaction_logs.id`（nullable；僅代理組別設置 `AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT` 開啟且候選來源為後台開分時非 null，target/tickets#4352，見 [field-reference.md](field-reference.md#後台開分視為儲值-elgtickets4352)），與 `order_id` 分屬不同 ID 空間、互斥 |
| `reward` | 進活動錢包的獎勵金額（已含主錢包扣款部分） |
| `balance_limit` | 提款前需達到的活動錢包餘額門檻（快照） |
| `flow_limit` | 提款前需達到的活動內流水門檻（快照） |
| `withdraw_limit` | 可提款的上限金額（快照） |
| `withdraw_type` | 領取去向（快照）：參加當下自 `event_v2_details.withdraw_type` 凍結，Observer 只看這裡，運營中途改活動不回溯；積分型的紀錄 `status` 直接為 COMPLETE、`reward`＝`withdraw`＝積分數 |
| `withdraw` | 實際提款金額（提款後寫入）；積分型為 null，實發積分看 `reward`（target/tickets#4235） |
| `final_balance` | 提款/結束時的活動錢包最終餘額（快照） |
| `ip` | 參加時的玩家 IP |
| `phone` | 參加時的玩家手機（用於 phone_block 稽查） |
| `status` | 參見 `EventV2UserJoinStatusEnum` |
| `end_time` | 活動到期時間（null=無期限） |

**`status` 值（EventV2UserJoinStatusEnum）**

| 值 | 說明 |
|---|---|
| 1 `JOIN` | 已參加，尚未完成 |
| 2 `COMPLETE` | 已提款完成 |
| 3 `QUIT` | 玩家自行放棄 |
| 4 `SYSTEM_INIT` | 系統排程重置（schedule 觸發） |
| 5 `OPERATOR_QUIT` | 後台人工操作放棄 |

---

### `event_v2_accounts`

記錄玩家參加活動時，綁定的遊戲商帳號。主要用於後續確認玩家在哪些遊戲商的流水可算入本活動。

| 欄位 | 說明 |
|---|---|
| `vendor_id` | 遊戲商 ID |
| `account_id` | 遊戲商帳號 ID |
| `event_v2_user_join_id` | 關聯 `event_v2_user_joins.id` |

---

### `event_v2_bonus_codes`

記錄因活動發送的優惠碼（`bonus_vendor_id` 場景）。每次參加最多一筆。

| 欄位 | 說明 |
|---|---|
| `event_v2_user_join_id` | 關聯 `event_v2_user_joins.id` |
| `vendor_id` | 發送優惠碼的遊戲商 ID |
| `bonus_code` | 取得的優惠碼字串 |

---

### `event_v2_invites`

記錄 `InviteFriendService` 活動中，符合條件的被邀請好友明細。每位符合條件的好友一筆。

| 欄位 | 說明 |
|---|---|
| `event_v2_user_join_id` | 關聯 `event_v2_user_joins.id` |
| `member_id` | 被邀請好友的會員 ID |

---

### `event_v2_wallet_transaction_logs`

活動錢包交易的聯結記錄，將 `wallet_transaction_logs` 中的活動錢包變動與 `event_v2_user_joins` 綁定，並附上遊戲商帳號資訊（用於 `initEventV2` 關閉遊戲帳號的場景）。

| 欄位 | 說明 |
|---|---|
| `wallet_transaction_log_id` | 關聯 `wallet_transaction_logs.id` |
| `vendor_id` | 對應的遊戲商 ID |
| `account_id` | 對應的遊戲商帳號 ID |
| `account_username` | 遊戲商帳號名稱（快照，避免 join） |
| `event_v2_user_join_id` | 關聯 `event_v2_user_joins.id` |
| `operate_agent_id` | 操作代理 ID（後台人工操作時記錄，系統自動為 null） |

---

## 四、風控稽核層

### `event_v2_reject_joins`

驗證失敗的彙整記錄。每個玩家對每個活動 detail，**只會有一筆**（重複失敗時更新 `last_event_v2_reject_record_id` 指向最新記錄）。玩家成功參加後，`status` 更新為 `JOIN`，且 `event_v2_user_join_id` 填入。

| 欄位 | 說明 |
|---|---|
| `event_v2_detail_id` | 關聯 `event_v2_details.id` |
| `member_id` | 被拒絕的會員 ID |
| `last_event_v2_reject_record_id` | 指向最新一筆 `event_v2_reject_records.id` |
| `event_v2_user_join_id` | 最終成功參加後填入（初始為 null） |
| `allowed_time` | 允許再次嘗試的時間（block 解除時間） |
| `status` | 參見 `EventV2RejectJoinStatusEnum` |

**`status` 值（EventV2RejectJoinStatusEnum）**

| 值 | 說明 |
|---|---|
| 1 `REJECT` | 拒絕參加中 |
| 2 `ALLOW_JOIN` | 允許重新參加（已解除限制） |
| 3 `JOIN` | 已成功參加 |

---

### `event_v2_reject_records`

每次驗證失敗的明細，一次拒絕一筆。同一次錯誤（相同 `validate_code`）若 IP 或訊息有變，更新現有記錄；不同 `validate_code` 則新增。

| 欄位 | 說明 |
|---|---|
| `event_v2_reject_join_id` | 關聯 `event_v2_reject_joins.id` |
| `validate_code` | 驗證錯誤碼（見 `EventV2ValidateCodeEnum`，如 `0012`=IP 重複） |
| `message` | 錯誤訊息（繁中，供後台顯示） |
| `ip` | 拒絕時的玩家 IP |

**常見 validate_code 說明（EventV2ValidateCodeEnum）**

| 代碼 | 說明 |
|---|---|
| `0001` | 活動不存在 |
| `0003` | 一次只能參加一個活動 |
| `0005` | 今天已參加過（daily 限制） |
| `0007` | 這個月已參加過（monthly 限制） |
| `0009` | 儲值尚未達到標準 |
| `0010` | 主錢包餘額不足 |
| `0011–0013` | IP 重複（分別對應全站/V1+V2/V2） |
| `0014–0016` | 手機重複（同上分類） |
| `0017–0019` | 實名帳號重複（同上分類） |
| `0020` | 活動人數已達上限（max_join） |
| `1301` | 邀請人數不足（InviteFriendService） |
| `1401` | 已超過可參加時間（DownlineCommissionService） |

---

## 五、結算回收層

### `event_v2_recycles`

當 `event_v2_details.is_recycle=1` 時，活動結束（提款、放棄、系統重置）後，記錄活動錢包剩餘餘額的回收資訊，供財務統計使用。

| 欄位 | 說明 |
|---|---|
| `event_v2_user_join_id` | 關聯 `event_v2_user_joins.id` |
| `final_balance` | 結束時的活動錢包最終餘額 |
| `withdraw` | 本次活動實際提款金額 |
| `recycle_amount` | 回收金額（= final_balance - withdraw） |
| `status` | 參見 `EventV2RecycleStatusEnum` |

**`status` 值（EventV2RecycleStatusEnum）**

| 值 | 說明 |
|---|---|
| 1 `SYSTEM_SCHEDULE` | 系統排程重置觸發 |
| 2 `COMPLETE` | 玩家完成活動（提款）後回收 |
| 3 `QUIT` | 玩家主動放棄 |
| 4 `SYSTEM_INIT` | 系統 initEventV2 觸發 |
| 5 `OPERATOR_QUIT` | 後台人工放棄 |
| 6 `TO_VENDOR_FAILED_REFUND` | 轉入遊戲商失敗退款 |
