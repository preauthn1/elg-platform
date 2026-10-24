# Referral Progress

查詢指定會員的「邀請好友活動」推薦進度，包含個人推薦碼、目標下線數、目前已達成下線數、預計獎勵金額，以及下線帳號清單。

> **同時支援 V2 與 V1 兩種邀請活動**：V2（`EventV2` handler_class = `InviteFriendService`）與 V1（`Event` action 15 `NEW_INVITE_PLAYER_OFFERS`）。兩者皆進行中時依 `end_time` 仲裁（較早者勝、同 `end_time` 時 V2 優先）。回應的 `register_referral_apply.source` 標示實際採用來源（`v1` / `v2`）。當採用 V1 且活動設有 level-1「邀請並充值」獎勵時，額外輸出 `deposit_referral_apply`（V1-only）。

## Endpoint

```
GET /external/api/api-callback/livechat/bot/referral-progress/{username}
```

路由名稱：`external.livechat-bot.referral-progress`

## Headers

| Header | 必填 | 值 |
|---|---|---|
| `Authorization` | 是 | `Bearer <livechat_bot_api_key>` |
| `Accept` | 是 | `application/json` |

## Path Parameters

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | 是 | 會員帳號（`members.username`）。允許字元：`[A-Za-z0-9_.-]`，最大 50 字元 |

## Response 包裝

採用 Livechat Bot 統一的 `{status, code, msg, output, error}` 格式：

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": {
        "referral_code": "TTNBA159",
        "register_referral_apply": {
            "current_count": 1,
            "target_count": 3,
            "reward_on_completion": "100.00",
            "downlines": ["kelvin6"],
            "message": [
                "Referral Code: TTNBA159",
                "Register Bonus: 100.00",
                "Progress: 1/3",
                "Registered Downlines: kelvin6",
                "Complete 2 more registration referral to get bonus of 100.00!"
            ]
        }
    },
    "error": []
}
```

## Glossary

### `referral_code`

| 欄位 | 型別 | 說明 |
|---|---|---|
| `referral_code` | string | 會員的推薦碼（`MemberCode.agent_code`，缺少時自動建立） |

### `register_referral_apply`

| 欄位 | 型別 | 說明 |
|---|---|---|
| `current_count` | integer | 目前有效下線數。**V2**：通過三層＋全站去重後的下線數。**V1**：`event_invite_records` 符合條件的**列數（不去重）**，刻意對齊舊系統 `getNewInvitePrizeCnt`（V1 派彩基準）；詳見下方「V1 計數採列數」註記 |
| `target_count` | integer | 目標下線數。V2 取 `EventV2Detail.condition_config.invite`；V1 取 `prize_contents.achieve_people`（level=0）分級門檻，規則 A：尚未達成的最低一級 |
| `reward_on_completion` | string | **達標後可獲得**的獎勵金額（2 位小數）——為「達到 `target_count` 才會發放的潛在獎勵」，**非已入袋金額**（未達標時實際已得為 0）。V2 由 `EventV2Detail.reward_formula` 計算；V1 取對應 tier（level=0、achieve_people=target）的 `prize_contents.point`，找不到回 `0.00`。前身欄位名為 `upline_bonus`（易被誤讀為已得，故更名） |
| `downlines` | string[] | 下線會員 `username` 陣列（依 `created_at` 升冪）。V2 為去重後清單；V1 經 `whereIn('id')` 天然去重（distinct 被邀請者） |
| `source` | string | 實際採用的活動來源：`v1` 或 `v2`（無進行中活動時為 `null`） |
| `message` | string[] | 人類可讀訊息陣列 |

> **V1 計數採列數（`current_count` 可能 > `downlines` 筆數）**
> V1 的 `current_count` 逐列計算 `event_invite_records`（該表無 `(invite_member_id, member_id)` 唯一約束，偶有重複列），以與舊系統 `EventNewInviteController::getNewInvitePrizeCnt` 的 level-0 計數（`count($invite_members)`）及實際 V1 派彩基準一致。而 `downlines` 依會員 `id` 去重，故當存在重複邀請列時，`current_count` 會**大於** `downlines` 陣列長度——此為刻意取捨（優先對齊 V1 派彩，而非兩者逐位相等）。level-1 `deposit_referral_apply` 因走 `orders` 子查詢 `groupBy('member_id')`，計數本即 distinct，不受此影響。

### `deposit_referral_apply`（V1-only）

僅當採用 **V1** 來源、且活動設有 level-1「邀請並充值」獎勵（`prize_contents` 有 `level=1` 且 `achieve_people>0`）時才輸出此 key；否則完全不放（向後相容）。

| 欄位 | 型別 | 說明 |
|---|---|---|
| `current_count` | integer | 達標的「邀請並充值」下線數：被邀請者於活動窗內首筆完成訂單（`status=3`、`MIN(id)`）之 `orders.point` >= 最低充值門檻者 |
| `target_count` | integer | level-1 分級門檻（`prize_contents.achieve_people`，level=1），規則 A：尚未達成的最低一級 |
| `reward_on_completion` | string | **達標後可獲得**的獎勵金額（2 位小數，非已入袋）：對應 level-1 tier（achieve_people=target）的 `prize_contents.point`，找不到回 `0.00` |
| `downlines` | string[] | 達標的「邀請並充值」下線 `username` 陣列（依 `created_at` 升冪） |
| `deposit_threshold` | integer | 最低充值點數門檻（`EventDetail.point`），被邀請者首單 `orders.point` 須 >= 此值 |
| `source` | string | 固定為 `v1` |
| `message` | string[] | 人類可讀訊息陣列（deposit 語意，含最低充值門檻） |

## Sample Response（達標前）

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": {
        "referral_code": "TTNBA159",
        "register_referral_apply": {
            "current_count": 1,
            "target_count": 3,
            "reward_on_completion": "100.00",
            "downlines": ["kelvin6"],
            "message": [
                "Referral Code: TTNBA159",
                "Register Bonus: 100.00",
                "Progress: 1/3",
                "Registered Downlines: kelvin6",
                "Complete 2 more registration referral to get bonus of 100.00!"
            ]
        }
    },
    "error": []
}
```

## Sample Response（已達標）

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": {
        "referral_code": "TTNBA159",
        "register_referral_apply": {
            "current_count": 3,
            "target_count": 3,
            "reward_on_completion": "100.00",
            "downlines": ["kelvin6", "alice", "bob"],
            "message": [
                "Referral Code: TTNBA159",
                "Register Bonus: 100.00",
                "Progress: 3/3",
                "Registered Downlines: kelvin6, alice, bob",
                "Bonus of 100.00 reached!"
            ]
        }
    },
    "error": []
}
```

## Sample Response（無進行中活動）

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": {
        "referral_code": "TTNBA159",
        "register_referral_apply": {
            "current_count": 0,
            "target_count": 0,
            "reward_on_completion": "0.00",
            "downlines": [],
            "message": [
                "Referral Code: TTNBA159",
                "No active referral promotion"
            ]
        }
    },
    "error": []
}
```

## Sample Response（V1 來源，含邀請並充值維度）

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": {
        "referral_code": "TTNBA159",
        "register_referral_apply": {
            "current_count": 2,
            "target_count": 3,
            "reward_on_completion": "100.00",
            "downlines": ["alice01", "bob02"],
            "source": "v1",
            "message": [
                "Referral Code: TTNBA159",
                "Register Bonus: 100.00",
                "Progress: 2/3",
                "Registered Downlines: alice01, bob02",
                "Complete 1 more registration referral to get bonus of 100.00!"
            ]
        },
        "deposit_referral_apply": {
            "current_count": 1,
            "target_count": 2,
            "reward_on_completion": "300.00",
            "downlines": ["alice01"],
            "deposit_threshold": 100,
            "source": "v1",
            "message": [
                "Referral Code: TTNBA159",
                "Deposit Referral Bonus: 300.00",
                "Progress: 1/2",
                "Minimum Deposit: 100 points",
                "Deposited Downlines: alice01",
                "Complete 1 more deposit referral (min 100 points each) to get bonus of 300.00!"
            ]
        }
    },
    "error": []
}
```

> V2 來源的 `register_referral_apply` 同樣帶 `source: "v2"`，但**不會**有 `deposit_referral_apply`。

## 業務邏輯說明

1. **推薦碼來源**
   - 透過 `MemberCodeService::get($member)` 取得，若該會員尚未建立 `MemberCode` 會在此 lazy create。
   - 回傳 `MemberCode.agent_code`。

2. **進行中活動篩選與仲裁**
   - **同時支援** V2（`event_v2.handler_class = "InviteFriendService"`）與 V1（`events` action 15 `NEW_INVITE_PLAYER_OFFERS`）兩種邀請好友活動。
   - **V2 篩選**：`status = 1` 且 `start_time <= now <= end_time` 且 `agent_id` 在會員代理層級 `hierarchy_array` 內。多筆取 `end_time ASC` 第一筆。
   - **V1 篩選**（對齊 `PromotionV1Builder::loadEligibleDetails`）：`event.status=1`、`agent_id ∈ hierarchy`、未被 `event_exclude_agents` 排除、`prize.status=1` 且 `expire_date > now`、`event_detail.status=1` 且 `now ∈ [start_time, end_time)`。eager load `event.prize.prizeContents`。多筆取 `end_time ASC` 第一筆。
   - **仲裁規則**：兩者皆進行中 → 取 `end_time` 較早者；同 `end_time` → **V2 優先**；只有一個 → 用該個；皆無 → 回 `buildEmptyProgress()`（無 `source`、無 `deposit_referral_apply`）。
   - **V1 下線過濾與分級**：被邀請者母體取 `event_invite_records`（`invite_member_id = 會員`、`created_at ∈ [start, end)`），依活動 block 旗標**動態**套用 `*_duplicate` 過濾（`ip_block`/`phone_block`/`account_block`/`tgid_block`），`realname_block=1` 時要求被邀請者 `realname_validate=1`；門檻來自 `prize_contents.achieve_people`（level=0），無有效級距時退回 `event_details.point`。
     - 此處僅套用 block 旗標的 `*_duplicate` 過濾，**不對 `member_id` 去重**；`current_count` 逐列計數以對齊舊系統 V1 派彩（見「V1 計數採列數」註記）。

3. **`target_count` 取得**
   - 從 `EventV2Detail.condition_config['invite']` 讀取（活動設定的目標下線數）。

4. **`reward_on_completion` 計算**
   - 沿用 `InviteFriendService::getReward()` 的計算規則：透過 `MathExecutor` 執行 `EventV2Detail.reward_formula`。
   - 公式中的 `invite` 變數會以「目前實際邀請人數」帶入，因此公式如 `invite * 50` 也能正確反映進度。
   - 公式為空、或計算過程拋出例外時回傳 `"0.00"`。

5. **`current_count` / `downlines` 篩選**
   - 完全沿用 `InviteFriendService::getValidate()` 的下線過濾流程：
     1. 排除曾被其他 `event_v2_user_join` 計算過的下線（避免重複領獎）。
     2. 取出 `invitation_code = $referralCode` 且 `created_at >= 活動開始時間` 的所有 Member，依 `created_at ASC` 排序。
     3. 同梯次去重：先以 `register_ip` → `mobile` → `first_name` 三層 `unique` 過濾（保留最早一筆）。
     4. 全站去重：再透過 `MemberRegisterIpFilter` / `MemberMobileFilter` / `MemberFirstNameFilter`（Bloom filter + DB 二次驗證）剔除與其他會員撞 IP / 手機 / 真實姓名者。
   - **不要求首存**（與既有邀請好友邏輯一致）。
   - `downlines[]` 為過濾後下線的 `Member.username` 陣列。

6. **Message 文字**
   - 未達標（`current_count < target_count`）：`Complete {remaining} more registration referral to get bonus of {bonus}!`
   - 已達標（`current_count >= target_count`）：`Bonus of {bonus} reached!`
   - 下線清單為空時 `Registered Downlines:` 後顯示 `-`。

## 錯誤回應範例

### 401 Bearer token 缺失或錯誤

```json
{
    "code": 401,
    "message": "Invalid or missing API key"
}
```

### 404 會員不存在

```json
{
    "code": 404,
    "message": "Member not found",
    "file": "app/Services/LivechatBot/ReferralProgressService.php:54",
    "tid": "req-xxxxxxxx"
}
```

## curl 範例

```bash
curl -X GET 'https://{host}/external/api/api-callback/livechat/bot/referral-progress/{username}' \
    -H 'Authorization: Bearer [REDACTED]' \
    -H 'Accept: application/json'
```
