# Promotions

查詢指定會員當下可見的所有活動，整合 V1（events / event_processes / prize_members）與 V2（event_v2 / event_v2_user_joins）兩套活動系統，並依會員狀態分為三大分類。

## Endpoint

```
GET /external/api/api-callback/livechat/bot/promotions/{username}
```

路由名稱：`external.livechat-bot.promotions`

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

本端點與 `get-withdraw-balance` 一致，採用 `{status, code, msg, output, error}` 格式：

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": {
        "activePromotions": [...],
        "claimablePromotions": [...],
        "conditionedPromotions": [...]
    },
    "error": []
}
```

## 三大分類資料來源

| 分類 | V1 來源 | V2 來源 |
|---|---|---|
| `activePromotions` | 不適用 | `event_v2_user_joins` 中 status = `JOIN`（活動進行中） |
| `claimablePromotions` | `prize_members` 中 status = `UNAWARD` 且未過期 | `event_v2_details` 中通過 `getQualificationIssues` 檢查者 |
| `conditionedPromotions` | `event_processes` 中 status = 0 且未滿足 `event_details.point` | `event_v2_details` 中 `getQualificationIssues` 回傳非空者 |

## Glossary

### `activePromotions[]`（僅 V2）

| 欄位 | 型別 | 說明 |
|---|---|---|
| `promotionSource` | string | 固定為 `"v2"` |
| `promotionId` | integer | `event_v2_details.id` |
| `promotionName` | string | 活動名稱（`event_v2.name`） |
| `promotionTitle` | string | 標題（`event_v2_detail_langs.name`，優先英文） |
| `activeFrom` | string\|null | 活動開始時間（GMT+8，`Y-m-d H:i:s`） |
| `activeTo` | string\|null | 活動結束時間（GMT+8，`Y-m-d H:i:s`） |
| `applyId` | integer | `event_v2_user_joins.id` |
| `joinStatus` | string | 對應 `EventV2UserJoinStatusEnum` 的 case name |
| `reward` | string | 獎勵金額（4 位小數） |
| `balanceLimit` | string\|null | 餘額限制（4 位小數） |
| `flowLimit` | string\|null | 流水限制（4 位小數） |
| `withdrawLimit` | string\|null | 提款限額（4 位小數） |
| `turnoverProgress` | string | 主錢包流水進度，格式 `"reach / required"`（4 位小數） |
| `message` | string[] | 人類可讀訊息 |

### `claimablePromotions[]`

#### V2

| 欄位 | 型別 | 說明 |
|---|---|---|
| `promotionSource` | string | `"v2"` |
| `promotionId` | integer | `event_v2_details.id` |
| `promotionName` | string | 活動名稱 |
| `promotionTitle` | string | 標題 |
| `activeFrom` | string\|null | 活動開始時間（GMT+8） |
| `activeTo` | string\|null | 活動結束時間（GMT+8） |
| `conditionFormula` | string\|null | 領獎條件原始公式 |
| `rewardFormula` | string\|null | 獎勵公式 |
| `flowFormula` | string\|null | 流水公式 |
| `withdrawFormula` | string\|null | 提款公式 |

#### V1

| 欄位 | 型別 | 說明 |
|---|---|---|
| `promotionSource` | string | `"v1"` |
| `applyId` | integer | `prize_members.id` |
| `promotionName` | string | `events.title` |
| `rewardAmount` | string | 獎勵金額（4 位小數，取自 `prize_members.lottery_point`） |

### `conditionedPromotions[]`

#### V2

| 欄位 | 型別 | 說明 |
|---|---|---|
| `promotionSource` | string | `"v2"` |
| `promotionId` | integer | `event_v2_details.id` |
| `promotionName` | string | 活動名稱 |
| `promotionTitle` | string | 標題 |
| `formulas` | object | `{conditionFormula, rewardFormula, flowFormula, withdrawFormula}` |
| `nonQualify` | string[] | 從 `getQualificationIssues` 取得的 i18n 訊息陣列 |

#### V1

| 欄位 | 型別 | 說明 |
|---|---|---|
| `promotionSource` | string | `"v1"` |
| `promotionName` | string | `events.title` |
| `nonQualify` | string[] | 對應 action 的本地化訊息（取自 `lang/en/livechat_bot_event.php`） |
| `progress` | object | `{actionType, actionId, requiredPoint, currentPoint, remaining}` |

## Sample Response（範例）

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": {
        "activePromotions": [
            {
                "promotionSource": "v2",
                "promotionId": 8,
                "promotionName": "Welcome Bonus",
                "promotionTitle": "Welcome Bonus 50%",
                "activeFrom": "2026-05-01 00:00:00",
                "activeTo": "2026-05-31 23:59:59",
                "applyId": 100123,
                "joinStatus": "JOIN",
                "reward": "100.0000",
                "balanceLimit": "300.0000",
                "flowLimit": "1500.0000",
                "withdrawLimit": "500.0000",
                "turnoverProgress": "200.0000 / 1500.0000",
                "message": [
                    "Promotion: Welcome Bonus 50%",
                    "Apply ID: 100123",
                    "Status: JOIN",
                    "Reward: 100.0000",
                    "Balance Limit: 300.0000",
                    "Flow Limit: 1500.0000",
                    "Withdraw Limit: 500.0000",
                    "Turnover Progress: 200.0000 / 1500.0000"
                ]
            }
        ],
        "claimablePromotions": [
            {
                "promotionSource": "v1",
                "applyId": 4567,
                "promotionName": "Daily Login Bonus",
                "rewardAmount": "10.0000",
                "message": [
                    "Promotion: Daily Login Bonus",
                    "Apply ID: 4567",
                    "Reward Amount: 10.0000"
                ]
            }
        ],
        "conditionedPromotions": [
            {
                "promotionSource": "v1",
                "promotionName": "Daily Bet Quest",
                "nonQualify": [
                    "Daily bet progress 3 / 5, 2 more bet(s) required today."
                ],
                "progress": {
                    "actionType": "BET_ONCE_A_DAY",
                    "actionId": 14,
                    "requiredPoint": 5,
                    "currentPoint": 3,
                    "remaining": 2
                },
                "message": [
                    "Promotion: Daily Bet Quest",
                    "Issue: Daily bet progress 3 / 5, 2 more bet(s) required today.",
                    "Progress: BET_ONCE_A_DAY 3 / 5 (2 remaining)"
                ]
            }
        ]
    },
    "error": []
}
```

## 業務邏輯說明

1. **V2 active**
   - 直接讀取 `event_v2_user_joins` status = `JOIN` 的記錄。
   - 與每筆活動關聯的 `EventV2Detail` 一同預加載（`with('eventV2Detail.detailLang')`）。
   - `turnoverProgress` 為會員整體流水（`MemberService::getFlowLimit()`），所有 active 共用。
2. **V2 claimable / conditioned**
   - 載入會員代理層級內、時間有效（`start_time <= now <= end_time`）且 `status = 1` 的所有 `EventV2Detail`。
   - 透過 `EventV2Service::getEventClass()` 取得對應子 service，呼叫新增的 `getQualificationIssues($detail, $member)`。
   - 回傳空陣列 → claimable；非空 → conditioned。
3. **V1 claimable**
   - 讀取 `prize_members` status = `UNAWARD` 且未過期的記錄。
4. **V1 conditioned**
   - 讀取 `event_processes` status = 0 的記錄，並查詢對應 `event_details.point` 計算進度差額。
   - `nonQualify` 訊息透過 `lang/en/livechat_bot_event.php` 取得，依 `event_details.action_id` 對應 `EventActionIdEnum` 的 case name；若無對應則 fallback 至 `default`。

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
    "file": "app/Services/LivechatBot/PromotionsService.php:30",
    "tid": "req-xxxxxxxx"
}
```

## curl 範例

```bash
curl -X GET 'https://{host}/external/api/api-callback/livechat/bot/promotions/{username}' \
    -H 'Authorization: Bearer [REDACTED]' \
    -H 'Accept: application/json'
```
