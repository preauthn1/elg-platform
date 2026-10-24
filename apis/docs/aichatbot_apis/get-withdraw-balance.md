# Get Withdraw Balance

查詢指定會員的可提款餘額、流水進度與提款資格。

## Endpoint

```
GET /external/api/api-callback/livechat/bot/get-withdraw-balance/{username}
```

路由名稱：`external.livechat-bot.get-withdraw-balance`

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

本端點與其他 Livechat Bot 端點不同，採用 `{status, code, msg, output}` 格式：

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": { ... }
}
```

## Glossary（`output` 欄位）

| 欄位 | 型別 | 說明 |
|---|---|---|
| `main_wallet_balance` | string | 主錢包餘額，2 位小數字串 |
| `provider_wallet_balance` | string | 最近一次轉入遊戲商的即時餘額，2 位小數字串；取得失敗時為 `"0.00"` |
| `main_wallet_turnover_progress` | string | 主錢包流水進度，格式 `"reach / required"`，各自為 4 位小數字串（如 `"250.5000 / 1000.0000"`） |
| `withdrawable_balance` | string | 可提款金額，2 位小數字串。`ELIGIBLE` 時等於 `main_wallet_balance`；否則為 `"0.00"` |
| `non_withdrawable_balance` | string | 不可提款金額，2 位小數字串。`ELIGIBLE` 時為 `"0.00"`；否則為 `main_wallet_balance + provider_wallet_balance` |
| `withdraw_eligibility` | string | 提款資格訊息，取值見下表 |
| `message` | string[] | 人類可讀訊息陣列，6 行（對應上述 6 個欄位） |

## Eligibility 判定

資格判定依以下順序取首個符合的條件（`match(true)` 由上而下）：

| 觸發條件 | `withdraw_eligibility` 值 |
|---|---|
| `members.is_event_v2 === true`（有進行中的 EventV2 活動） | `Not able to withdraw, the user has ongoing active promotion` |
| `MemberService::getFlowLimit()` 回傳 `pass === 0`（流水未達標） | `Not able to withdraw, turnover requirement not met` |
| `main_wallet_balance <= 0`（主錢包餘額為 0 或負值） | `Not able to withdraw, insufficient main wallet balance` |
| 皆不符合（可提款） | `Eligible for withdrawal` |

## Sample Response（ELIGIBLE）

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": {
        "main_wallet_balance": "500.00",
        "provider_wallet_balance": "200.00",
        "main_wallet_turnover_progress": "1000.0000 / 1000.0000",
        "withdrawable_balance": "500.00",
        "non_withdrawable_balance": "0.00",
        "withdraw_eligibility": "Eligible for withdrawal",
        "message": [
            "Main Wallet Balance: 500.00",
            "Provider Wallet Balance: 200.00",
            "Main Wallet Turnover Progress: 1000.0000 / 1000.0000",
            "Withdrawable Balance: 500.00",
            "Non-Withdrawable Balance: 0.00",
            "Withdraw Eligibility: Eligible for withdrawal"
        ]
    }
}
```

## Sample Response（ACTIVE_PROMOTION）

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": {
        "main_wallet_balance": "300.00",
        "provider_wallet_balance": "100.00",
        "main_wallet_turnover_progress": "0.0000 / 0.0000",
        "withdrawable_balance": "0.00",
        "non_withdrawable_balance": "400.00",
        "withdraw_eligibility": "Not able to withdraw, the user has ongoing active promotion",
        "message": [
            "Main Wallet Balance: 300.00",
            "Provider Wallet Balance: 100.00",
            "Main Wallet Turnover Progress: 0.0000 / 0.0000",
            "Withdrawable Balance: 0.00",
            "Non-Withdrawable Balance: 400.00",
            "Withdraw Eligibility: Not able to withdraw, the user has ongoing active promotion"
        ]
    }
}
```

## Sample Response（TURNOVER_NOT_MET）

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": {
        "main_wallet_balance": "500.00",
        "provider_wallet_balance": "50.00",
        "main_wallet_turnover_progress": "200.0000 / 1000.0000",
        "withdrawable_balance": "0.00",
        "non_withdrawable_balance": "550.00",
        "withdraw_eligibility": "Not able to withdraw, turnover requirement not met",
        "message": [
            "Main Wallet Balance: 500.00",
            "Provider Wallet Balance: 50.00",
            "Main Wallet Turnover Progress: 200.0000 / 1000.0000",
            "Withdrawable Balance: 0.00",
            "Non-Withdrawable Balance: 550.00",
            "Withdraw Eligibility: Not able to withdraw, turnover requirement not met"
        ]
    }
}
```

## Sample Response（INSUFFICIENT_BALANCE）

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": {
        "main_wallet_balance": "0.00",
        "provider_wallet_balance": "150.00",
        "main_wallet_turnover_progress": "0.0000 / 0.0000",
        "withdrawable_balance": "0.00",
        "non_withdrawable_balance": "150.00",
        "withdraw_eligibility": "Not able to withdraw, insufficient main wallet balance",
        "message": [
            "Main Wallet Balance: 0.00",
            "Provider Wallet Balance: 150.00",
            "Main Wallet Turnover Progress: 0.0000 / 0.0000",
            "Withdrawable Balance: 0.00",
            "Non-Withdrawable Balance: 150.00",
            "Withdraw Eligibility: Not able to withdraw, insufficient main wallet balance"
        ]
    }
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

### 404 會員不存在

```json
{
    "code": 404,
    "message": "Member not found",
    "file": "app/Services/LivechatBot/GetWithdrawBalanceService.php:32",
    "tid": "req-xxxxxxxx"
}
```

## curl 範例

```bash
curl -X GET 'https://{host}/external/api/api-callback/livechat/bot/get-withdraw-balance/{username}' \
    -H 'Authorization: Bearer [REDACTED]' \
    -H 'Accept: application/json'
```
