# DstPlaySW（DST PLAY）— 廠商 API 規格

> **原始來源**
> - `DstPlayApiMan.md` / `DstPlayApiMan.pdf`（DST PLAY 官方《API 說明文檔》，文檔版本 1.14，最新更新 2025 年 11 月 25 日）
>
> 抓取日期：2026-07-21
>
> 我方實作分析、整合落地建議、陷阱與 gaps 見同目錄 **`integration.md`（對接心得，有踩坑才建）**。

DST PLAY 同時提供**轉帳錢包（Transfer Wallet）**與**無縫錢包（Seamless / Single Wallet）**兩種串接模式，本專案僅接入**無縫錢包**模式，`VendorIdEnum::DSTPLAYSW`（目錄名 `DstPlaySW`）。以下原文文件涵蓋兩種模式的完整 API；標註「僅轉帳錢包」的章節本專案不使用，僅保留原文供對照。

---

## 通用約定

### 公司提供資料（原文）

| 項目 | 值 |
|---|---|
| 測試網址（默認網址） | `https://example.invalid` |
| HTTP Methods | `POST` |
| Request Header | `Content-Type: application/x-www-form-urlencoded` |
| 服務器時區 | (GMT+08:00) Kuala Lumpur, Singapore |
| 帳戶名 API Id | 登入名 |
| 密鑰 API Key | 登入後台巡查 API 設定 |
| 後台登入網（測試） | `https://example.invalid/login` |
| 後台登入網（正式） | 請參考後台的 API 設置 |

### 兩種請求方向

| 方向 | Base URL | 適用章節 |
|---|---|---|
| Operator → DST Play（我方主動呼叫） | 默認網址（測試 `https://example.invalid`） + 路徑 | §1–§8、§15–§24 |
| DST Play → Operator（廠商回調，僅無縫錢包） | `(operator-integration-api-url)` + 路徑（我方提供給廠商的網址，需另外申請） | §9–§14 |

### 簽名規則（通用）

所有請求（雙方向）都帶下列三個基本參數：

| 參數名 | 類型 | 長度 | 必須 | 描述 |
|---|---|---|---|---|
| `api_id` | String | 50 | 是 | API 帳戶名 |
| `timestamp` | Long | 13 | 是 | UNIX 時間戳，必須毫秒格式（13 位數） |
| `sign` | String | 32 | 是 | `sign = md5(api_id + timestamp + apiKey)` |

所有響應都至少帶：

| 參數名 | 類型 | 長度 | 描述 |
|---|---|---|---|
| `code` | int | 32 bit | 參考 API 返回錯誤代碼 |
| `msg` | String | 50 | 參考 API 返回錯誤信息 |

---

## Operator → DST Play API

### 1. 由創建用戶啟動的遊戲（轉帳錢包 / 無縫錢包）

**僅轉帳錢包適用**（無縫錢包請用 §2 `gameLaunchByToken`）。

`默認網址 + /api/v1/gameLaunch`

| 參數名 | 類型 | 長度 | 必須 | 描述 |
|---|---|---|---|---|
| `api_id` | String | 50 | 是 | API 帳戶名 |
| `timestamp` | Long | 13 | 是 | UNIX 時間戳（毫秒） |
| `sign` | String | 32 | 是 | `md5(api_id+timestamp+apiKey)` |
| `user_id` | String | 50 | 是 | 玩家帳戶名字（測試模式可跳過） |
| `game_code` | String | 10 | 是 | 參考遊戲代碼 |
| `language` | String | 10 | 是 | 參考語言代碼 |
| `return_url` | String | 255 | 否 | 遊戲返回鍵 URL |
| `mode` | String | 255 | 否 | `DEMO` |

> 如果用戶 ID 之前未被創建，系統將自動創建該用戶。

響應：`{"code": 0,"msg": "Success.","gameUrl": "https://example.invalid/api/index?param=<base64>"}`

| 參數名 | 類型 | 長度 | 描述 |
|---|---|---|---|
| `gameUrl` | String | 255 | 轉跳遊戲網址 |

> 輸入 `game_code = GM001` 就能登入遊戲大廳。

---

### 2. 通過令牌啟動遊戲（僅無縫錢包）★ 本專案使用的啟動入口

`默認網址 + /api/v1/gameLaunchByToken`

| 參數名 | 類型 | 長度 | 必須 | 描述 |
|---|---|---|---|---|
| `api_id` | String | 50 | 是 | API 帳戶名 |
| `timestamp` | Long | 13 | 是 | UNIX 時間戳（毫秒） |
| `sign` | String | 32 | 是 | `md5(api_id+timestamp+apiKey)` |
| `token` | String | 255 | 是 | 會話令牌（測試模式可跳過） |
| `game_code` | String | 10 | 是 | 參考遊戲代碼 |
| `language` | String | 10 | 是 | 參考語言代碼 |
| `return_url` | String | 255 | 否 | 遊戲返回鍵 URL |
| `mode` | String | 255 | 否 | `DEMO` |

響應同 §1（`gameUrl`）。輸入 `game_code = GM001` 就能登入遊戲大廳。

---

### 3. 創建帳戶

`默認網址 + /api/v1/created`

| 參數名 | 類型 | 長度 | 必須 | 描述 |
|---|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | 是 | 同通用約定 |
| `user_id` | String | 50 | 是 | 要生成的玩家帳號名字 |
| `password` | String | 50 | 否 | 玩家密碼（最少 4 個字母或數字） |

響應：`{"code": 0,"msg": "Success."}`

---

### 4. 更新帳戶狀態

`默認網址 + /api/v1/update`

| 參數名 | 類型 | 長度 | 必須 | 描述 |
|---|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | 是 | 同通用約定 |
| `user_id` | String | 50 | 是 | 要禁用的玩家帳戶名字 |
| `status` | String | 50 | 是 | `active` / `inactive` / `kick` |
| `password` | String | 50 | 否 | 玩家密碼（最少 4 個字母或數字） |

響應：`{"code": 0,"msg": "Success."}`

---

### 5. 獲取帳戶列表

`默認網址 + /api/v1/list`

| 參數名 | 類型 | 長度 | 必須 | 描述 |
|---|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | 是 | 同通用約定 |
| `page_no` | Int | 11 | 是 | 頁面號碼 |
| `record_count` | Int | 11 | 是 | 每頁行數（最多 500） |

響應：
```json
{"code": 0,"msg": "Success.","userWalletDTO": [
  {"name": "demoMember1","statusCode": "active","amount": 10.0000},
  {"name": "demoMember2","statusCode": "active","amount": 130.0000}
]}
```

| 參數名 | 類型 | 長度 | 描述 |
|---|---|---|---|
| `name` | String | 50 | 帳戶名 |
| `status` | String | 50 | 帳戶狀態（`active` / `inactive`） |
| `amount` | Decimal | 18,4 | 帳戶目前餘額 |

---

### 6. 查詢帳戶餘額（僅轉帳錢包）

`默認網址 + /api/v1/getBalance`

| 參數名 | 類型 | 長度 | 必須 | 描述 |
|---|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | 是 | 同通用約定 |
| `user_id` | String | 50 | 是 | 用於檢查錢包餘額的用戶 ID |

> 如果用戶 ID 之前未被創建，系統將自動創建該用戶。

響應：`{"code": 0,"msg": "Success","balance": 0.00}`（`balance` String 50，帳戶目前餘額）

---

### 7. 帳戶存款取款（僅轉帳錢包）

`默認網址 + /api/v1/updateBalance`

| 參數名 | 類型 | 長度 | 必須 | 描述 |
|---|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | 是 | 同通用約定 |
| `user_id` | String | 50 | 是 | 充值的玩家帳戶名字 |
| `amount` | Numeric | 18,2 | 是 | 充值數目 |
| `action` | String | 50 | 是 | `deposit` / `withdraw` |
| `reference_id` | String | 255 | 否 | 驗證重複交易 |

響應：`{"code": 0,"msg": "Success.","balance": 39.4800,"transId": 42,"transDate": 1722521000,"transDateStr": "01/08/2024 14:03:20"}`

| 參數名 | 類型 | 長度 | 描述 |
|---|---|---|---|
| `balance` | String | 50 | 帳戶充值後的餘額 |
| `transId` | Long | 13 | 交易的唯一編號 |
| `transDate` | String | 50 | 交易日期的時間戳 |
| `transDateStr` | Decimal | 18,4 | 交易時間（原文型別如此，疑似筆誤） |

---

### 8. 帳戶存款取款日誌（僅轉帳錢包）

`默認網址 + /api/v1/getTransactionLog`

| 參數名 | 類型 | 長度 | 必須 | 描述 |
|---|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | 是 | 同通用約定 |
| `user_id` | String | 50 | 是 | 玩家帳戶名字 |
| `date_from` | String | 10 | 否 | 格式 `dd/MM/yyyy HH:mm:ss`，例：`01/01/2019 00:00:00` |
| `date_to` | String | 10 | 否 | 格式同上，例：`28/02/2019 23:59:59` |
| `trans_id` | Long | 13 | 否 | 交易的唯一編號 |
| `reference_id` | String | 255 | 否 | 交易的參考 ID |

響應：`{"code": 0,"msg": "Success.","walletLogDTO": [{"amount": 10.00,"transDate": 1722521000,"transDateStr": "01/08/2024 14:03:20","transType": "deposit","transId": 42,"referenceId": 35454345}]}`

---

## DST Play → Operator 回調 API（僅無縫錢包）

> 以下由 DST Play 主動呼叫，網址前綴為我方提供的 `(operator-integration-api-url)`。除 §9 `getAuth` 外，其餘一律以 `user_id` 直接識別玩家（非 token）。

### 9. 認證（僅無縫錢包）

`POST (operator-integration-api-url) + /v1/getAuth`

> **僅在通過令牌啟動遊戲時觸發**（即 §2 `gameLaunchByToken` 流程專屬）。

範例請求：`api_id=API_ID&timestamp=1605970770769&sign=256941f27448e3edbe79289017a19a7e&token=[REDACTED]

| 參數名 | 類型 | 長度 | 描述 |
|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | 同通用約定 |
| `token` | String | 255 | 啟動遊戲時的會話令牌 |

響應：`{"code":0,"msg":"Success.","balance":199875.7479,"userId":"testMember"}`

| 參數名 | 類型 | 長度 | 描述 |
|---|---|---|---|
| `balance` | Decimal | 18,4 | 金額 |
| `userId` | String | 255 | 玩家帳戶 |

---

### 10. 獲取餘額（僅無縫錢包）

`POST (operator-integration-api-url) + /v1/getBalance`

範例請求：`api_id=API_ID&timestamp=1605970770769&sign=...&user_id=apidemo`

| 參數名 | 類型 | 長度 | 描述 |
|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | 同通用約定 |
| `user_id` | String | 50 | 玩家帳戶名字 |

響應：`{"code":0,"msg":"Success.","balance":199875.7479}`

---

### 11. 驗證遊戲結果（僅無縫錢包）★ 核心扣款／派彩介面

`POST (operator-integration-api-url) + /v1/gameResult`

範例請求：`api_id=API_ID&timestamp=...&sign=...&user_id=testMember&game_round_id=32759&game_code=AliceAndWonderland&bet_amount=5&win_amount=-5`

| 參數名 | 類型 | 長度 | 描述 |
|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | 同通用約定 |
| `user_id` | String | 50 | 玩家帳戶名字 |
| `game_round_id` | long | 20 | 遊戲回合 ID |
| `game_code` | String | 30 | 遊戲代碼 |
| `bet_amount` | Decimal | 18,4 | 下注金額 |
| `win_amount` | Decimal | 18,4 | 贏輸（可為負） |
| `log_id` | Long | 20 | 日誌的唯一 ID |

響應：`{"code":0,"msg":"Success.","beginBalance":199875.7479,"endBalance":199870.7479}`

| 參數名 | 類型 | 長度 | 描述 |
|---|---|---|---|
| `beginBalance` | Decimal | 18,4 | 下注前金額 |
| `endBalance` | Decimal | 18,4 | 結算後金額 |

**驗證遊戲結算步驟（原文）**：
1. 查看 `bet_amount` 是否有足夠金額。失敗回覆錯誤代碼 `1`，或金額不足回覆錯誤代碼 `103`。
2. 如果成功下注，把金額加 `win_amount` 即可。
3. 返回響應信息。

**原文注意事項**：
- 使用相同的 `log_id` 重新發送結果只會創建一筆交易（冪等鍵）。
- 失敗或餘額不足將視為該輪投注失敗。
- 超時或未收到響應時，將使用該 `log_id` 觸發退款（見 §14）。

---

### 12. 驗證獎金結果（僅無縫錢包，僅免費旋轉獎勵遊戲觸發）

`POST (operator-integration-api-url) + /v1/bonusResult`

範例請求：`...&user_id=testMember&game_round_id=32759&game_code=AliceAndWonderland&bet_amount=5&win_amount=-5&log_id=8347830&bonus_id=345346457`

| 參數名 | 類型 | 長度 | 描述 |
|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | 同通用約定 |
| `user_id` | String | 50 | 玩家帳戶名字 |
| `game_round_id` | Long | 20 | 遊戲回合 ID |
| `game_code` | String | 30 | 遊戲代碼 |
| `bet_amount` | Decimal | 18,4 | 下注金額 |
| `win_amount` | Decimal | 18,4 | 贏金額 |
| `log_id` | Long | 20 | 日誌的唯一 ID |
| `bonus_id` | Long | 20 | 創建免費旋轉時的 ID |

響應同 §11 格式（`beginBalance` / `endBalance`）。

**驗證有效獎金步驟（原文）**：
1. 檢查 `win_amount` 必須始終為正數，且無重複的 `log_id`。
2. 若有效，獲取用戶餘額並增加 `win_amount`。
3. 返回所需的響應。

**原文注意事項**：
- 使用相同的 `log_id` 重新發送結果只會創建一筆交易。
- 請求失敗或響應超時時，將持續重試調用（最大嘗試次數：20 次）。
- 這次調用只會在免費旋轉獎勵遊戲中觸發。

---

### 13. 驗證促銷結果（僅無縫錢包）

`POST (operator-integration-api-url) + /v1/promoResult`

範例請求：`...&user_id=testMember&game_round_id=32759&game_code=AliceAndWonderland&bet_amount=5&win_amount=-5&log_id=8347830&campaign_id=7162713&campaign_type=T`

| 參數名 | 類型 | 長度 | 描述 |
|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | 同通用約定 |
| `user_id` | String | 50 | 玩家帳戶名字 |
| `game_round_id` | Long | 20 | 遊戲回合 ID |
| `game_code` | String | 30 | 遊戲代碼 |
| `bet_amount` | Decimal | 18,4 | 下注金額 |
| `win_amount` | Decimal | 18,4 | 贏金額 |
| `log_id` | Long | 20 | 日誌的唯一 ID |
| `campaign_id` | Long | 20 | 活動事件編號 |
| `campaign_type` | String | 50 | 活動類型（`T` = 錦標賽） |

響應同 §11 格式。

**驗證有效促銷步驟（原文）**：
1. 檢查 `win_amount` 必須始終為正數，且無重複的 `log_id`。
2. 若有效，獲取用戶餘額並增加 `win_amount`。
3. 返回所需的響應。

**原文注意事項**：
- 使用相同的 `log_id` 重新發送結果只會創建一筆交易。
- 請求失敗或響應超時時，將持續重試調用（最大嘗試次數：20 次）。

---

### 14. 退款（僅無縫錢包）

`POST (operator-integration-api-url) + /v1/refund`

範例請求：`...&user_id=testMember&log_id=347584375349&refund_log_id=8347830`

| 參數名 | 類型 | 長度 | 描述 |
|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | 同通用約定 |
| `user_id` | String | 50 | 玩家帳戶名字 |
| `log_id` | Long | 20 | 退款的唯一 ID |
| `refund_log_id` | Long | 20 | 參考 `gameResult` 的 `log_id` |

響應：`{"code":0,"msg":"Success."}`

**退款步驟（原文）**：
1. 檢查 `refund_log_id` 是否與 `gameResult` 的 `log_id` 匹配。
2. 如找到匹配，按照 `win_amount` 調整餘額並返回代碼 `0`；如未找到 `refund_log_id`，也返回代碼 `0`。

**原文注意事項**：
- 響應失敗或請求超時時，退款將持續重試調用（最大嘗試次數：20 次）。
- 使用相同的 `log_id` 重新發送退款請求，只會創建一筆交易。

---

## Operator → DST Play API（報表 / 對帳 / FSB / 遊戲列表）

### 15. 每日勝負報告

`默認網址 + /api/v1/getWinLose`

| 參數名 | 類型 | 長度 | 必須 | 描述 |
|---|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | 是 | 同通用約定 |
| `user_id` | String | 50 | 否 | 玩家帳戶名字，放空等於 API 代理帳戶 |
| `date_from` | String | 10 | 是 | 格式 `dd/MM/yyyy HH:mm:ss` |
| `date_to` | String | 10 | 是 | 格式同上 |

響應：`{"code": 0,"msg": "Success.","reportDTO": [{"reportDate": 1724292000000,"reportDateStr": "22/08/2024 10:00:00","winLose": 0.520000,"currency": "MYR","bonusMemberAmount": 0.000000,"bonusAgentAmount": 0.000000}]}`

| 參數名 | 類型 | 長度 | 描述 |
|---|---|---|---|
| `winLose` | Decimal | 18,6 | 贏輸 |
| `bonusMemberAmount` | Decimal | 18,6 | 促銷獎金（由本公司即 DST Play 承擔） |
| `bonusAgentAmount` | Decimal | 18,6 | 免費旋轉獎金（由運營商即本方承擔） |

---

### 16. 按最後 ID 的遊戲歷史日誌（原文推薦）

`默認網址 + /api/v1/getGameLogByLastId`

| 參數名 | 類型 | 長度 | 必須 | 描述 |
|---|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | 是 | 同通用約定 |
| `last_id` | Long | 20 | 是 | 最後 ID（最大結果 1500） |

響應：`{"code": 0,"msg": "Success.","userGameLogDTO": [{"logId": 2415,"loginId": "apidemo_demoMember1","gameRoundId": 2329,"gameCode": "VampireMansion","gameName": "VAMPIRE MANSION","createdDateStr": "22/08/2024 10:23:21","createdDate": 1724293401000,"currencyCode": "MYR","betAmount": 0.2000,"winAmount": -0.2000,"jackpotAmount": 0.0000,"totalWinLoseAmount": -0.2000,"bonusMemberAmount": 0.0000,"bonusAgentAmount": 0.0000}],"lastLogId": 2423}`

`lastLogId`（Long 20）：記錄的最後一個 ID，用於下次分頁請求的 `last_id`。

---

### 17. 遊戲歷史日誌

`默認網址 + /api/v1/getGameLog`

| 參數名 | 類型 | 長度 | 必須 | 描述 |
|---|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | 是 | 同通用約定 |
| `user_id` | String | 50 | 是 | 玩家帳戶名字（放空索取所有玩家） |
| `date_from` | String | 10 | 是 | 格式 `dd/MM/yyyy HH:mm:ss` |
| `date_to` | String | 10 | 是 | 格式同上，**24 小時記錄限制** |

回傳欄位與 §16 相同（無 `lastLogId`）。

---

### 18. 按頁顯示的遊戲歷史日誌

`默認網址 + /api/v1/getGameLogPage`

| 參數名 | 類型 | 長度 | 必須 | 描述 |
|---|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | 是 | 同通用約定 |
| `user_id` | String | 50 | 是 | 玩家帳戶名字（放空索取所有玩家） |
| `date_from` / `date_to` | String | 10 | 是 | 格式同 §17，24 小時記錄限制 |
| `record_per_page` | Long | 3 | 是 | 最大值 1500 |
| `page` | Long | 2 | 是 | 當前頁 |

響應多帶 `currentRecords` / `currentPage` / `totalRecords` / `totalPage`（皆 Int 20）。

---

### 19. 按組顯示的遊戲歷史日誌

`默認網址 + /api/v1/getGameLogGroup`

| 參數名 | 類型 | 長度 | 必須 | 描述 |
|---|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | 是 | 同通用約定 |
| `user_id` | String | 50 | 是 | 玩家帳戶名字（放空索取所有玩家） |
| `date_from` / `date_to` | String | 10 | 是 | 格式同 §17，24 小時記錄限制 |
| `page` | Long | 10 | 是 | 每頁顯示 1500 條結果，從第 1 頁開始 |

回傳欄位不含 `logId` / `gameRoundId` / `createdDateStr` / `createdDate`，多帶 `page` / `pageSize`（Long 10）。

---

### 20. 獲取免費旋轉獎金投注列表（僅用於 FSB 整合）

`默認網址 + /api/v1/getFSBBetList`

僅需通用三參數（`api_id` / `timestamp` / `sign`）。

響應：`{"code":0,"msg":"Success.","list":[{"gameCode":"AliceAndWonderland","gameName":"ALICE AND WONDERLAND","lineBet": [0.01,0.02,0.03,…],"totalBet": [0.20,0.40,0.60,…]}]}`

| 參數名 | 類型 | 長度 | 描述 |
|---|---|---|---|
| `lineBet` | Decimal[] | 18,2 | 可用的線注列表 |
| `totalBet` | Decimal[] | 18,2 | 每個投注的總成本 |

---

### 21. 獲取免費旋轉獎金用戶列表（僅用於 FSB 整合）

`默認網址 + /api/v1/getFSBUserList`

| 參數名 | 類型 | 長度 | 必須 | 描述 |
|---|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | Yes | 同通用約定 |
| `user_id` | String | 255 | No | 留空以獲取完整列表 |
| `date_from` / `date_to` | String | 10 | No | 格式 `dd/MM/yyyy HH:mm:ss` |
| `status` | String | 50 | No | 激活 / 已取消 / 已過期 / 已完成 |

響應：`{"code":0,"msg":"Success.","list":[{"bonusId":1,"loginId":"10005","gameCode":"Necromancer","gameName":"NECROMANCER","lineBet":0.05,"rounds":5,"totalRounds":5,"statusCode":"completed","expirationDate":"30/06/2025 00:00:00","createdDate":"17/06/2025 04:35:28"}]}`

---

### 22. 添加免費旋轉獎金（僅用於 FSB 整合）

`默認網址 + /api/v1/addFreeSpinBonus`

| 參數名 | 類型 | 長度 | 必須 | 描述 |
|---|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | Yes | 同通用約定 |
| `user_id` | String | 50 | Yes | 用於添加獎金的玩家 ID |
| `game_code` | String | 50 | Yes | 為該獎金選擇的遊戲 |
| `line_bet` | Decimal | 18,2 | Yes | 每輪的投注額（參考線注投注列表） |
| `rounds` | Integer | 11 | Yes | 旋轉的輪數 |
| `expiration_date` | String | 50 | No | 留空則為永久有效，格式 `dd/MM/yyyy HH:mm:ss` |

響應：`{"code":0,"msg":"Success.","bonusId":134456}`

> 原文注意：所有來自免費旋轉獎金的贏利將與普通 GGR 分開計算，全數由運營商（本方）承擔。

---

### 23. 取消免費旋轉獎金（僅用於 FSB 整合）

`默認網址 + /api/v1/cancelFreeSpinBonus`

| 參數名 | 類型 | 長度 | 必須 | 描述 |
|---|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | Yes | 同通用約定 |
| `bonus_id` | Long | 13 | Yes | 已創建的獎金 ID |

響應：`{"code":0,"msg":"Success."}`

---

### 24. 索取遊戲列表

`默認網址 + /api/v1/getGames`

| 參數名 | 類型 | 長度 | 必須 | 描述 |
|---|---|---|---|---|
| `api_id` / `timestamp` / `sign` | — | — | 是 | 同通用約定 |
| `game_type` | Integer | 1 | 不是 | `0`=全部，`1`=Slot 遊戲，`2`=Arcade 遊戲（空列表獲取所有遊戲） |

響應：`{"code": 0,"msg": "Success.","gameList": [{"gameCode": "AliceAndWonderland","gameName": "ALICE AND WONDERLAND","gameType": "SLOT","special": "","dtReleased": "01-08-2024","language": []}]}`

| 參數名 | 類型 | 長度 | 描述 |
|---|---|---|---|
| `gameCode` | String | 30 | 遊戲代碼 |
| `gameName` | String | 50 | 遊戲名稱 |
| `gameType` | String | 30 | 遊戲種類 |
| `special` | String | 30 | 新或熱門遊戲標籤 |
| `dtReleased` | String | 30 | 出品日期 |
| `language` | Array | — | 其他語言（`code` 語言代碼 / `name` 遊戲名稱） |
| `bonusFeature` | String | 10 | 購買附加功能 |
| `rtp` | Decimal | 20 | 返回率 |

---

## 遊戲標籤圖 / 遊戲記錄 URL 規則

- 遊戲標籤圖（English）：`https://example.invalid/images/thumb/square/[GAME_CODE].jpg`
- 遊戲標籤圖（Chinese）：`https://example.invalid/images/thumb/square/[GAME_CODE]_zh.jpg`
- 查看遊戲記錄：`(BACKOFFICE domain name) + /gameResult/view?gameCode=[GAME_CODE]&apiId=[API_ID]&userId=[USER_ID]&id=[GAME_ROUND_ID]`

---

## 錯誤碼

### HTTP 返回錯誤信息

| 錯誤代碼 | 信息 | 描述 |
|---|---|---|
| 400 | Bad Request | 錯誤的請求 |
| 401 | UNAUTHORIZED | 未經授權 |
| 403 | FORBIDDEN | 被禁止 |
| 404 | NOT FOUND | 未找到 |
| 500 | INTERNAL SERVER ERROR | 內部服務器錯誤 |

### API 返回錯誤信息

| 錯誤代碼 | 信息 | 描述 |
|---|---|---|
| 0 | success. | 成功 |
| 1 | fail. | 失敗 |
| 100 | Account Exits. | 帳戶名已經存在 |
| 101 | Not Found. | 資料不存在 |
| 102 | Invalid Action. | 訪問無效 |
| 103 | Insufficient Balance. | 餘額不足 |
| 104 | Missing Parameter. | 缺少參數 |
| 105 | Exceed max record allow. | 超過記錄量允許 |
| 106 | Invalid account type. | 帳戶類型無效 |
| 107 | Unauthorized request. | 未經授權的請求 |
| 108 | Invalid Game Code. | 無效的遊戲代碼 |
| 109 | Wallet lock. | 錢包限制提款和入款 |
| 110 | Game maintenance | 遊戲正在維修 |

---

## 語言代碼

| 語言 | 代碼 | 語言 | 代碼 |
|---|---|---|---|
| English | en | Finnish / Suomi | fi |
| Chinese | zhcn | French | fr |
| Indonesian | idn | Italian | it |
| Thai | th | Portuguese | pt |
| Vietnamese | vi | Romanian | ro |
| Hindi | hi | Russian | ru |
| Japanese | jp | Turkish | tr |
| Khmer | kh | Swedish | sv |
| Korean | ko | Spanish | es |
| Chinese (Traditional) | zhtw | Polish | pl |
| Malay | ms | Deutsch | de |
| Burmese | my | Danish | da |
| Dutch | nl | | |

---

## API 存取時間限制（Rate Limit / Delay）

| API | 需延遲(秒) |
|---|---|
| `/api/v1/getWinLose` | 30s |
| `/api/v1/list` | 30s |
| `/api/v1/getGameLogByLastId` | 3s |
| `/api/v1/getTransactionLog` | 30s |
| `/api/v1/getGameLog` | 30s |
| `/api/v1/getGameLogPage` | 3s |
| `/api/v1/getGameLogGroup` | 30s |
| `/api/v1/created` | 1s |
| `/api/v1/update` | 1s |
| `/api/v1/getGames` | 1s |
