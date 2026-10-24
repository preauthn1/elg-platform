# Get Promotion Detail

依據 `promotionName` 關鍵字（部分包含、不分大小寫）查詢指定會員當下可見的活動，並合併三大分類（active / claimable / conditioned）為單一陣列回傳。

本端點為 [promotions](./promotions.md) 端點的「扁平 + 關鍵字過濾」版本，主要差異：

- 回傳格式由「三大分類 object」展平為「單一 list」
- 每筆 item 多一個 `eligibilityStatus` 欄位標示其原始分類
- 必須提供 `promotionName` 關鍵字進行過濾

## Endpoint

```
GET /external/api/api-callback/livechat/bot/get-promotion-detail/{username}/{promotionName}
```

路由名稱：`external.livechat-bot.get-promotion-detail`

## Headers

| Header | 必填 | 值 |
|---|---|---|
| `Authorization` | 是 | `Bearer <livechat_bot_api_key>` |
| `Accept` | 是 | `application/json` |

## Path Parameters

| 參數 | 型別 | 必填 | 規則 | 說明 |
|---|---|---|---|---|
| `username` | string | 是 | 允許字元：`[A-Za-z0-9_.-]`，最大 50 字元 | 會員帳號（`members.username`） |
| `promotionName` | string | 是 | `required\|string\|max:255` | 活動名稱關鍵字，使用 `LIKE %keyword%` 比對，case-insensitive（依資料庫 collation）。缺 segment → 404 |

## Response 包裝

採用 Livechat Bot 統一回應格式 `{status, code, msg, output, error}`，其中 `output` 為扁平 list：

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": [ ... ],
    "error": []
}
```

無符合結果時，`output` 為空陣列 `[]`。

## `eligibilityStatus` 欄位語意

| 值 | 來源 | 說明 |
|---|---|---|
| `active` | `PromotionV2Builder::buildActive` | 該會員已 join 且 status = `JOIN`（V1 不適用） |
| `claimable` | `PromotionV1Builder::buildClaimable` + `PromotionV2Builder::buildClaimable` | V1 為 `prize_members` 待領取；V2 為通過 `getQualificationIssues` 檢查者 |
| `conditioned` | `PromotionV1Builder::buildConditioned` + `PromotionV2Builder::buildConditioned` | V1 為 `event_processes` 未達標；V2 為 `getQualificationIssues` 回傳非空者 |

每筆 item 的其他欄位完全沿用 [promotions](./promotions.md) 端點的對應分類欄位（不新增、不刪除）。詳細欄位定義請對照該文件之「三大分類資料來源」與 Glossary 區段。

## 關鍵字過濾邏輯

| 來源 | 比對欄位 |
|---|---|
| V1 (events) | `events.title` 或 `event_names.title`（跨所有 `language_id` union） |
| V2 (event_v2) | `event_v2.name` 或 `event_v2_detail_langs.name`（跨所有 `language_id` union） |

- 比對方式：`LIKE %keyword%`（部分包含）
- 大小寫：依 MySQL 預設 collation 不分大小寫
- keyword 會 `trim`，全為空白視同未提供

## Sample Response

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": [
        {
            "eligibilityStatus": "active",
            "promotionSource": "v2",
            "promotionId": 8,
            "promotionName": "FREE100 Bonus",
            "promotionTitle": "FREE 100",
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
                "Promotion: FREE 100",
                "Apply ID: 100123",
                "Status: JOIN",
                "Reward: 100.0000",
                "Balance Limit: 300.0000",
                "Flow Limit: 1500.0000",
                "Withdraw Limit: 500.0000",
                "Turnover Progress: 200.0000 / 1500.0000"
            ]
        },
        {
            "eligibilityStatus": "claimable",
            "promotionSource": "v1",
            "applyId": 4567,
            "promotionName": "FREE Login Bonus",
            "rewardAmount": "10.0000",
            "message": [
                "Promotion: FREE Login Bonus",
                "Apply ID: 4567",
                "Reward Amount: 10.0000"
            ]
        }
    ],
    "error": []
}
```

## 與 promotions 端點的差異

| 比較項目 | `promotions` | `get-promotion-detail` |
|---|---|---|
| 必填 | `username`（path） | `username`（path）、`promotionName`（path） |
| `output` 結構 | object：`{activePromotions, claimablePromotions, conditionedPromotions}` | list：扁平合併三類 |
| 每筆 item 是否含 `eligibilityStatus` | 否 | 是 |
| 是否依關鍵字過濾 | 否（回傳全部） | 是（依 `promotionName` LIKE 過濾） |

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
    "tid": "req-xxxxxxxx"
}
```

### 404 缺少 `promotionName` segment

`promotionName` 為 path segment，省略時 Laravel router 會直接 NotFound，不會進到 controller。

```json
{
    "code": 404,
    "message": "Not Found",
    "tid": "req-xxxxxxxx"
}
```

## curl 範例

```bash
curl -X GET 'https://{host}/external/api/api-callback/livechat/bot/get-promotion-detail/{username}/FREE' \
    -H 'Authorization: Bearer [REDACTED]' \
    -H 'Accept: application/json'
```
