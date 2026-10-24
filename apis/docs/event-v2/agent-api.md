# 活動 V2 — 後台 API

> Controller：`app/Http/Controllers/Agent/EventV2Controller.php`
> 路由檔：`routes/agent/auth.php`

---

## 權限規則

| 操作 | 限制 |
|---|---|
| `create`、`update` | 只有 admin（`agent.id=1`）或 admin 子帳號（`host_id=1`）可執行，其他代理回傳 `無權限操作` |
| `update-info`、`detail` | 非 admin / admin 子帳號的代理，只能操作／查看**自己 hierarchy 下代理所擁有的活動**（以 `event_v2_details.agent_id` 判定，`Agent::canSeeAgent()`）；越權或查無回 `活動不存在`<br>2026-10 起生效（工單 4495），原 `update-info` 為「任何有登入的代理均可操作」、`detail` 不檢查範疇，可跨線讀取／改別人的活動 |
| `allow-join`、`quit`、`update-wallet-balance`、`reject-detail` | 非 admin / admin 子帳號的代理，只能操作**自己 hierarchy 下代理所擁有的活動**（以 `event_v2_details.agent_id` 判定，範疇與查詢類一致）；越權或查無回 `權限不足`（`error_message.9003`）<br>2026-08-17 起生效，原規則為「任何有登入的代理均可操作」，因可對任意會員活動錢包加值／重置任意會員活動而收斂 |
| 所有查詢類 | 非 admin / admin 子帳號的代理，只看得到自己 hierarchy 下的資料（依 `hierarchy_path LIKE` 過濾） |

---

## 查詢類 API

### `POST event-v2/event-list`

取得所有 `event_v2` 清單（id + name + handler_class + field_params），供前端下拉選單使用。不依代理 hierarchy 過濾，所有人看全部。`handler_class` 供新後台判斷該類型可否搭配積分領取去向（見 [field-reference.md — withdraw_type](field-reference.md#withdraw_type)）。

**活動週期（target/tickets#4234 紅包）**：create／update 收 `member_period`（可重複參加）與 `total_period`（包括期間），值 0～4 見 `EventV2PeriodEnum`，只有紅包可送非 0；`event-v2/detail` 與 `event-v2/list` 原樣回這兩欄，編輯時必須原樣送回，否則沿用現值（不送＝不動、不會被翻回 0）。

**目標用戶（target/tickets#4234）**：`event-v2/detail` 另回 `target_type`（0 不限制／1 指定會員／2 會員群組）與 `targets`（`[{id, name}]`，會員給帳號、群組給群組名）；create／update 收 `target_type`＋`target_ids`，規則見 [database.md — event_v2_detail_targets](database.md)。`period` 也在 detail 回傳。

**`field_params`（參數說明彈窗，target/tickets#4231 需求 7）**：該類型「設定條件」十格各自會讀的變數／key，依請求的 `Accept-Language` 本地化（`resources/lang/{locale}/event_v2.php` 的 `params`，沒有該語系退回英文）。`handler_class` 對不到 handler 時為 `null`。清單由各 handler 的 `fieldParams()` 宣告（見 `AbstractEventV2`），後端加 key 只要補宣告與語系，前端不用改。

```json
"field_params": {
  "condition_config": {
    "main": {"kind": "keys", "items": [{"name": "max_join", "type": "integer", "description": "全站參加上限…", "example": "{\"max_join\": 100}"}]}
  },
  "withdraw_config": {
    "main":  {"kind": "keys", "items": [{"name": "balance_multiple", …}]},
    "score": {"kind": "keys", "items": [{"name": "expire", …}, {"name": "limit", …}, {"name": "tiers", "type": "array", …}]}
  },
  "flow_config": {"main": {"kind": "none", "items": []}}
}
```

- `kind`：`variables`（公式可用變數）／`keys`（JSON 物件可用 key）／`arrays`（整格是一段陣列，`items` 只有一筆、`example` 是整段範例）／`none`（沒有任何類型會讀，前端要明確顯示「此欄位目前無可用參數」）。
- `score` 只在領取去向可選積分的類型、且只有 `withdraw_formula`／`withdraw_config` 兩格才有：積分型這一對改讀積分數量與 `expire`／`limit`／`tiers`。前端依目前選的領取去向決定顯示 `main` 或 `score`。

---

### `POST event-v2/list`

`event_v2_details` 列表（分頁）。

**可篩選**：`agent_username`、`name`（模糊）、`status`、`withdraw_type`（1=主錢包、2=積分，不帶＝全部）

**回傳欄位**：detail id、agent、活動名稱（依語系）、`event_v2_id`＋`event_type_name`（活動分類，取 `event_v2.name` 原名，與 `event-v2/event-list` 下拉同一份；target/tickets#4234）、start_time、end_time、sort、status、withdraw_type、member_period、total_period

---

### `POST event-v2/detail`

取得單一 `event_v2_detail` 的完整設定，含所有關聯（多語系、排除代理、遊戲類型、限定 / 排除遊戲商）。

**必填**：`event_detail_id`

範疇：活動所屬代理須在登入者可視範圍內（見上方權限規則），否則回 `活動不存在`。

---

### `POST event-v2/join-list`

玩家參加記錄列表（`event_v2_user_joins`，分頁）。

**可篩選**：`event_v2_id`、`event_v2_detail_id`、`member_username`、`agent_username`、`start_time ~ end_time`（依 join 時間）、`status`

**回傳欄位**：join id、活動名稱、代理、會員、reward、**score_reward**、**score_cost**、withdraw_type（參加當下凍結值）、balance_limit、flow_limit、withdraw_limit、withdraw、bonus_code、ip、status、end_time

**依凍結的 `withdraw_type` 分流（target/tickets#4235，2026-10-02）**：主錢包型 `reward`＝金額、`score_reward`＝null；積分型 `reward`＝null、`score_reward`＝積分數、`withdraw`＝null（積分沒轉進主錢包）。讀參加紀錄上的值、不讀活動現值，活動中途改去向不影響歷史。

**消耗積分與統計卡（target/tickets#4235）**：
- `score_cost`＝這筆參加扣掉的門票積分（`member_score_logs` 中 `reference_type=event_v2_user_join`、`reference_id=join id`、方向扣除的加總）；沒扣分的活動（非紅包）回 `null`，後台顯示「—」不顯示 0。看的是實際扣了多少，不是活動當下的 `condition_config.score`，活動改設定不影響歷史。
- `data.summary` 八個鍵，依同一組篩選算**全部**筆數，翻頁不變；篩到沒資料 `total_count` 回 0、其餘回 `"0.0000"`（4 位小數字串）。一句 `selectRaw` 算完（`EventV2Controller::JOIN_SUMMARY_SQL`）：

| 鍵 | 卡片 | 算法 |
|---|---|---|
| `total_count` | 總領取次數 | `COUNT(*)` |
| `total_reward` | 總獎勵金額 | 主錢包型 `SUM(reward)`，全部狀態 |
| `total_score_reward` | 總獲得積分 | 積分型 `SUM(reward)` |
| `total_score` | 總消耗積分 | `SUM(score_cost)`（消耗，不是獲得） |
| `total_issued` | 已發放 | 主錢包型、完成（2）`SUM(withdraw)` |
| `total_joining` | 進行中 | 主錢包型、參加（1）`SUM(reward)` |
| `total_quit` | 放棄 | 主錢包型、放棄（3）＋後台操作放棄（5）`SUM(reward)` |
| `total_reset` | 重置 | 主錢包型、系統重置（4）`SUM(reward)` |

  驗算：`total_reward` ＝ 完成筆 `reward` ＋ `total_joining` ＋ `total_quit` ＋ `total_reset`。`total_issued` 取實領，不在等式內，與完成筆 `reward` 不同屬正常。
- 當頁合計列由後台前端自己加當頁資料，不走接口。

---

### `POST event-v2/recycle-list`

活動錢包回收記錄列表（`event_v2_recycles`，分頁）。只有 `is_recycle=1` 的活動才會有記錄。

**可篩選**：`event_v2_id`、`member_username`、`agent_username`、`start_time ~ end_time`（依回收時間）、`status`

**回傳欄位**：recycle id、活動名稱、代理、會員、final_balance、withdraw、recycle_amount、status

---

### `POST event-v2/reject-list`

驗證失敗彙整列表（`event_v2_reject_joins`，分頁）。每個玩家對每個 detail 最多一筆，顯示最新一次失敗的 validate_code 與 IP。

**可篩選**：`event_v2_id`、`member_username`、`agent_username`、`start_time ~ end_time`（依最新 reject_record 更新時間）、`status`

---

### `POST event-v2/reject-detail`

查看單一 `event_v2_reject_join` 的所有失敗明細（`event_v2_reject_records`），依更新時間倒序。

**必填**：`reject_join_id`

---

### `POST event-v2/wallet-management`

查詢目前仍在**參加中（status=JOIN）**活動的玩家，以及其活動錢包當前餘額。配合 `update-wallet-balance` 使用。

**可篩選**：`event_v2_id`、`member_username`、`agent_username`

**回傳欄位**：join_id、wallet_id、活動名稱、代理、會員、balance

---

### `POST report/sr25`

活動錢包交易流水報表（`wallet_transaction_logs` + `event_v2_wallet_transaction_logs` JOIN，分頁）。

**必填**：`member_username`

**可篩選**：`start_time ~ end_time`、`agent_username`

**回傳欄位**：代理、會員、遊戲商、遊戲商帳號、join_id、detail_id、操作代理、交易紀錄完整欄位

---

## 操作類 API

### `POST event-v2/create`

新建一筆 `event_v2_detail`，同時建立：
- `event_v2_detail_langs`（多語系名稱 / 圖片 / 說明）
- `event_v2_detail_exclude_agents`（排除代理）
- `event_v2_detail_game_types`（遊戲類型限制）
- `event_v2_detail_vendors`（限定遊戲商）
- `event_v2_detail_exclude_vendors`（排除遊戲商流水）

**限制**：admin / admin 子帳號才能操作。`event_v2_id` 必須存在於 `event_v2` 資料表。

**`withdraw_type`（領取去向，target/tickets#4231）**：選填，不送＝1 主錢包（舊後台整包送、沒有這個欄位）。送 2＝積分時：`join_withdraw` 強制存 1；積分數量二擇一：`withdraw_formula`，或 `withdraw_config.tiers` 階梯（都沒填 `score_formula_required`、都填 `score_amount_source_conflict`、階梯形狀錯 `score_tiers_invalid`）；`reward_formula`／`reward_config`／`balance_*`／`flow_*` 六格**必須為空**（`score_fields_not_allowed`）；`withdraw_config.expire` 正整數（`score_expire_invalid`）、`withdraw_config.limit` 數字（`score_limit_invalid`）；主錢包型帶 `expire`／`limit`／`tiers` 回 `main_wallet_config_keys_not_allowed`；搭配每日區間儲值／每週累積儲值／每日儲值組合（依 `handler_class` 判定）回 `score_type_not_supported`。以上皆 400。

---

### `POST event-v2/update`

更新 `event_v2_detail` 及其所有關聯（關聯採 **delete + re-insert** 方式，不做 diff）。

**限制**：admin / admin 子帳號才能操作。

**重要業務規則**：活動已開始（`now() >= start_time`）後，以下欄位**不可修改**：
- `event_v2_id`（活動類型）
- `agent_id`（適用代理）
- `start_time`（開始時間）

活動開始前三者均可修改。

**`withdraw_type`**：`sometimes`——沒送（舊後台）沿用資料庫現值；**送與現值不同的值回 400 `withdraw_type_locked`（建立後不可異動）**；有效去向為積分時套用與 create 相同的積分型規則（`join_withdraw` 存 1、六格必空、提款限制公式必填、`event_v2_id` 改成三種陣列型回 `score_type_not_supported`）。改去向只影響之後新參加的紀錄，已參加者以 `event_v2_user_joins.withdraw_type` 凍結值處理。

---

### `POST event-v2/update-info`

輕量更新，無需 admin 權限，但活動須在登入者可視範圍內。僅可調整：
- `sort`（排序）
- `status`（啟 / 停用）
- `detail_lang`（多語系名稱 / 圖片 / 說明，採 `updateOrCreate`）

> 用途：前台顯示調整（改順序、上下架、改圖文），不觸碰活動核心規則。

---

### `POST event-v2/allow-join`

解除玩家的驗證封鎖。

**動作**：將 `event_v2_reject_joins.status` 更新為 `ALLOW_JOIN`（2），並寫入當前時間為 `allowed_time`。

> 前台在 block 驗證時，會檢查 `allowed_time`，如已到期或設定了 `ALLOW_JOIN` 則允許重試。

---

### `POST event-v2/quit`

後台強制結束玩家的進行中活動。

**動作**：
1. 找出 `status=JOIN` 的 `event_v2_user_join`
2. 設定 `withdraw=0`、`status=OPERATOR_QUIT`（5）
3. 呼叫 `EventV2Service::initEventV2()`：
   - 關閉玩家遊戲帳號
   - 清空活動錢包（歸零並寫 `EVENT_WALLET_INIT` 交易紀錄）
   - 若 `is_recycle=1`，建立 `event_v2_recycles`（status=`OPERATOR_QUIT`）

> 強制結束後玩家不會收到任何提款，需由後台另行處理補償。

---

### `POST event-v2/update-wallet-balance`

後台人工調整玩家活動錢包餘額（加分 / 扣分）。

**必填**：`wallet_id`（從 `wallet-management` 取得）、`balance`（調整量，可為負數）

**業務規則**：
- `新餘額 = 目前餘額 + balance`，最低為 0（不會變負數）
- 寫入 `EVENT_V2_OPERATOR_CHANGE` 的 `WalletTransactionLog`，`operate_agent_id` 記錄操作代理
- 玩家必須有 `status=JOIN` 的進行中活動，否則回傳 `您尚未參加任何活動`
