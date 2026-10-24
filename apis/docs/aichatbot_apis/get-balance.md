# Get Balance

查詢指定會員的各類錢包即時餘額。

## Endpoint

```
GET /external/api/api-callback/livechat/bot/get-balance/{username}
```

路由名稱：`external.livechat-bot.get-balance`

## Headers

| Header | 必填 | 值 |
|---|---|---|
| `Authorization` | 是 | `Bearer <livechat_bot_api_key>` |
| `Accept` | 是 | `application/json` |

API key 對應 `livechat_bots.api_key`，由 bot ↔ agent 對應關係決定可查詢的會員範圍。

## Path Parameters

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | 是 | 會員帳號（`members.username`）。允許字元：`[A-Za-z0-9_.-]`，最大 50 字元 |

## Glossary

| 欄位 | 型別 | 說明 |
|---|---|---|
| `total_wallet_balance` | string | 總錢包餘額（2 位小數）。公式：主錢包 + 遊戲商即時餘額 (+ 遊戲錢包，僅多錢包模式) |
| `main_wallet_balance` | string | 主錢包餘額（2 位小數） |
| `sum_provider_wallet_balance` | string | 遊戲商即時餘額（2 位小數）。取最近一次轉入遊戲商的即時餘額，取得失敗時為 `"0.00"` |
| `rolling_chips` | string | 禮碼／打碼量錢包餘額（2 位小數） |
| `event_wallet_balance` | string | 活動錢包餘額（`wallet_type_id = CODE_EVENT_WALLET`，2 位小數） |
| `in_promo` | boolean | 會員是否參與 Event V2 活動（對應 `members.is_event_v2`） |
| `game_wallet_balance` | string | 遊戲錢包餘額（2 位小數）。**僅在多錢包模式（Multi Wallet）下回傳** |
| `description` | string | 可讀描述，含 Member ID 與錢包模式 |
| `message` | string[] | 人類可讀訊息陣列，每行對應一個錢包欄位 |

## Sample Response（Single Wallet）

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": {
        "total_wallet_balance": "420.00",
        "main_wallet_balance": "420.00",
        "sum_provider_wallet_balance": "0.00",
        "rolling_chips": "20.00",
        "event_wallet_balance": "100.00",
        "in_promo": false,
        "description": "Member ID: 12345, Wallet Type: Single Wallet",
        "message": [
            "Total Wallet Balance: 420.00",
            "Main Wallet Balance: 420.00",
            "Sum Provider Wallet Balance: 0.00",
            "Rolling Chips: 20.00",
            "Event Wallet Balance: 100.00",
            "In Promo: No"
        ]
    },
    "error": []
}
```

## Sample Response（Multi Wallet，含 `game_wallet_balance`）

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": {
        "total_wallet_balance": "470.00",
        "main_wallet_balance": "420.00",
        "game_wallet_balance": "50.00",
        "sum_provider_wallet_balance": "0.00",
        "rolling_chips": "20.00",
        "event_wallet_balance": "100.00",
        "in_promo": false,
        "description": "Member ID: 12345, Wallet Type: Multi Wallet",
        "message": [
            "Total Wallet Balance: 470.00",
            "Main Wallet Balance: 420.00",
            "Sum Provider Wallet Balance: 0.00",
            "Rolling Chips: 20.00",
            "Event Wallet Balance: 100.00",
            "In Promo: No",
            "Game Wallet Balance: 50.00"
        ]
    },
    "error": []
}
```

## 錯誤回應範例

### 401 Bearer token 缺失或錯誤

```json
{
    "code": 401,
    "message": "Invalid or missing API key"
}
```

### 404 會員不存在或不屬於 bot 對應 agent 的代理線

```json
{
    "code": 404,
    "message": "Member not found",
    "tid": "req-xxxxxxxx"
}
```

## curl 範例

```bash
curl -X GET 'https://{host}/external/api/api-callback/livechat/bot/get-balance/{username}' \
    -H 'Authorization: Bearer [REDACTED]' \
    -H 'Accept: application/json'
```
