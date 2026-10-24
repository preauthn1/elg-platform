# Inquiry Deposit / Withdrawal

查詢指定會員的儲值（Deposit）或提款（Withdraw）歷史紀錄，結果依 `id` 由新至舊排列，**最多回傳最新 50 筆紀錄（依 `id` 倒序）**。

## Endpoint

```
GET /external/api/api-callback/livechat/bot/inquiry-deposit-withdrawal/{username}/{transaction_type}
```

路由名稱：`external.livechat-bot.inquiry-deposit-withdrawal`

## Headers

| Header | 必填 | 值 |
|---|---|---|
| `Authorization` | 是 | `Bearer <livechat_bot_api_key>` |
| `Accept` | 是 | `application/json` |

## Path Parameters

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | 是 | 會員帳號（`members.username`）。允許字元：`[A-Za-z0-9_.-]`，最大 50 字元 |
| `transaction_type` | string | 是 | 查詢類型。限定值：`deposit` 或 `withdraw`（route 層約束）。不合法值或缺少 segment → 404 |

## 查詢限制

- 單次查詢**最多回傳最新 50 筆紀錄**（依 `id` 倒序，等同「最新 50 筆」）。
- 超過 50 筆的舊資料不會於此端點回傳，需透過其他管道（如後台）查詢。

## Glossary

每筆紀錄皆包含下列欄位：

| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | integer | 訂單系統編號（`orders.id` 或 `withdrawal_orders.id`） |
| `status` | string | 對外 API 狀態，固定為 `PENDING` / `APPROVED` / `REJECTED`。映射規則見下表 |
| `currency` | string | 幣別代碼（如 `MYR`、`KES`）。Deposit 取自 `orders.currency.code`；Withdraw 取自 `withdrawal_orders.bank.currency.code` |
| `amount` | string | 訂單金額，4 位小數字串，如 `"55.0000"` |
| `user_received_amount` | string | 扣除手續費後的實收金額，4 位小數字串。公式 `amount - tax` |
| `processing_at` | string\|null | 處理時間，`Y-m-d H:i:s`，未處理時為 `null`。Deposit 使用 `pay_time`；Withdraw 使用 `payment_time` |
| `remark` | string\|null | 備註。Deposit 一律為 `null`；Withdraw 對應 `withdrawal_orders.memo` |
| `message` | string[] | 人類可讀訊息陣列，5 行 |

### `message` 格式

```
[
    "ID: {id}",
    "Status: {status}",
    "Currency: {currency}",
    "Amount: {amount-2dp}",            // 注意：message 內金額為 2 位小數
    "User Received Amount: {urs-2dp}"  // 注意：message 內金額為 2 位小數
]
```

> **注意小數位差異**：`data` 層級的金額為 4 位小數，`message` 內的金額為 2 位小數。

## Status Mapping

### Deposit（`OrderStatusEnum` → API Status）

| 內部狀態 | value | API Status |
|---|---|---|
| `CREATED` | `0` | `PENDING` |
| `SUCCESS` | `1` | `PENDING` |
| `WAITING_MANUAL_DEPOSIT` | `4` | `PENDING` |
| `DEPOSIT_FINISHED` | `3` | `APPROVED` |
| `FAILED` | `2` | `REJECTED` |

未定義狀態一律 fallback 為 `PENDING`。

### Withdraw（`WithdrawalOrderStatusEnum` → API Status）

| 內部狀態 | value | API Status | 備註 |
|---|---|---|---|
| `ORDER_CREATED` | `0` | `PENDING` | |
| `PENDING_REVIEW` | `1` | `PENDING` | |
| `APPROVED` | `3` | `PENDING` | **已審核通過但尚未轉帳，對外仍視為待處理** |
| `PAYMENT_SUBMITTED` | `21` | `PENDING` | |
| `PAYMENT_PROCESSING` | `22` | `PENDING` | |
| `TRANSFERRED` | `4` | `APPROVED` | |
| `THIRD_PARTY_SUCCESS` | `24` | `APPROVED` | |
| `FAILED` | `2` | `REJECTED` | |
| `REFUND` | `9` | `REJECTED` | |
| `THIRD_PARTY_FAILED` | `29` | `REJECTED` | |

未定義狀態一律 fallback 為 `PENDING`。

## Sample Response（Deposit）

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": [
        {
            "id": 20,
            "status": "PENDING",
            "currency": "MYR",
            "amount": "55.0000",
            "user_received_amount": "55.0000",
            "processing_at": null,
            "remark": null,
            "message": [
                "ID: 20",
                "Status: PENDING",
                "Currency: MYR",
                "Amount: 55.00",
                "User Received Amount: 55.00"
            ]
        },
        {
            "id": 18,
            "status": "APPROVED",
            "currency": "MYR",
            "amount": "100.0000",
            "user_received_amount": "98.0000",
            "processing_at": "2026-04-20 09:15:32",
            "remark": null,
            "message": [
                "ID: 18",
                "Status: APPROVED",
                "Currency: MYR",
                "Amount: 100.00",
                "User Received Amount: 98.00"
            ]
        }
    ],
    "error": []
}
```

## Sample Response（Withdraw）

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": [
        {
            "id": 88,
            "status": "APPROVED",
            "currency": "KES",
            "amount": "500.0000",
            "user_received_amount": "495.0000",
            "processing_at": "2026-04-20 10:02:11",
            "remark": "已完成轉帳",
            "message": [
                "ID: 88",
                "Status: APPROVED",
                "Currency: KES",
                "Amount: 500.00",
                "User Received Amount: 495.00"
            ]
        },
        {
            "id": 85,
            "status": "PENDING",
            "currency": "KES",
            "amount": "300.0000",
            "user_received_amount": "298.0000",
            "processing_at": null,
            "remark": null,
            "message": [
                "ID: 85",
                "Status: PENDING",
                "Currency: KES",
                "Amount: 300.00",
                "User Received Amount: 298.00"
            ]
        }
    ],
    "error": []
}
```

## Sample Response（查無紀錄）

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": [],
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

### 404 會員不存在

```json
{
    "code": 404,
    "message": "Member not found",
    "file": "app/Services/LivechatBot/InquiryDepositWithdrawalService.php:40",
    "tid": "req-xxxxxxxx"
}
```

### 404 `transaction_type` 不合法或缺 segment

`transaction_type` 為 route 層 path constraint，限定值為 `deposit` 或 `withdraw`。
不合法值或省略該 segment 會被 Laravel router 判定為 NotFound，不會進到 controller。

```json
{
    "code": 404,
    "message": "Not Found",
    "tid": "req-xxxxxxxx"
}
```

## curl 範例

### Deposit

```bash
curl -X GET 'https://{host}/external/api/api-callback/livechat/bot/inquiry-deposit-withdrawal/{username}/deposit' \
    -H 'Authorization: Bearer [REDACTED]' \
    -H 'Accept: application/json'
```

### Withdraw

```bash
curl -X GET 'https://{host}/external/api/api-callback/livechat/bot/inquiry-deposit-withdrawal/{username}/withdraw' \
    -H 'Authorization: Bearer [REDACTED]' \
    -H 'Accept: application/json'
```
