# ClotPlay — API 整合文件（Single Wallet / Seamless）

> 原始來源（下載包）：
> - 《ClotPlay Operators Integration Document V2.0.13》（主要事實來源，63 頁）
> - 《Bonus_Code_Guide.pdf》（Bonus Code 補充，10 頁）
> - 同包另含舊版 v1.14.4、v2.0.5（本文件以 **v2.0.13** 為準）
>
> 抓取日期：2026-06-03
>
> 文件站 Game List / 素材：<https://clotplaynews.live/GamesList>
>
> 我方實作分析見 `integration.md`

ClotPlay（API 介面代號 **MOGI** — Operator Gaming Interface）為線上遊戲商，提供 Slot / Table / Arcade / Fishing / Mini / Live Casino / Crash 等多類遊戲，採 **RESTful + JSON** 介面。APIs 專案以**單一錢包（Seamless Wallet）**模式接入（vid 142）。

---

## 通用約定

### 請求格式

- **介面風格**：RESTful，所有回應為 JSON（HTTP body）。
- **HTTP Method**：寫入類多為 `POST`；查詢類（getBalance、報表）為 `GET`。
- **驗證方式**：`Authorization` header（16 字元固定 token）+ IP Whitelist，**不使用加密簽章**（見本文件〈簽名與驗證〉節）。

### Base URL（API Host）

| 項目 | 值 |
|---|---|
| **API Host**（代理 → ClotPlay） | `https://example.invalid/api/MOGI` |

> 官方文件第 3 頁明列 API Host = `https://example.invalid/api/MOGI`。代理端的 callback URL（ClotPlay → 代理）由代理自行提供給 ClotPlay 客服設定，文件僅以 endpoint 相對路徑（`/api/seamless/...`）表示。
>
> ⚠️ **gap**：文件未區分 staging / production host，僅給一個 `api-int`（看似 integration 環境）host。正式環境 host 需向 ClotPlay 客服確認。

### 通用回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `code` | Integer | `0` 成功；非 0 為錯誤碼（見下方〈錯誤碼字典〉） |
| `data` | Object / Array | 各 API 回傳資料 |
| `data.pagination` | Object | 分頁類 API 額外回傳 `current_page` / `last_page` / `per_page` / `total` |

### 預設時區

報表 / 交易查詢類 API 預設 **UTC +8**（文件多處明列「default time zone is UTC +8」）。
回調的 `created_at` / `updated_at` 則帶 ISO8601 含時區偏移字串（例 `2008-09-15T15:53:00+05:00`）；實作中 `Carbon::parse()` 後轉 UTC 存入 DB。

---

## 簽名與驗證

> 來源：《ClotPlay Operators Integration Document V2.0.13》第 4 頁「Authentication」、「IP Whitelisting」章節。

ClotPlay（MOGI 介面）**不使用加密簽章演算法**。驗證僅靠兩道機制：

| 機制 | 說明 |
|---|---|
| **Authorization Header** | 固定 token；代理打 ClotPlay 時必帶，ClotPlay 回打代理 callback 時也帶同一把 token |
| **IP Whitelisting** | 代理須先把自家 server IP 申請進 ClotPlay 白名單，否則回 HTTP 403 `Forbidden Access` |

### Authorization Header

| 項目 | 值 |
|---|---|
| Header 名稱 | `Authorization` |
| 內容 | 由 ClotPlay 管理團隊提供的 **16 字元字串**（明文 token，非 Bearer / 非簽章） |
| 範例 | `Authorization: xY6svl1d19Y86xlK` |

#### 雙向使用

| 方向 | 誰帶 token | 用途 |
|---|---|---|
| 代理 → ClotPlay（Launch Game、Create Member 等主動 API） | 代理在 request header 帶 token | ClotPlay 驗證代理身分 |
| ClotPlay → 代理（Seamless callback：bet / settlement / getBalance / cancel） | ClotPlay 在 request header 帶**同一把 token** | 代理驗證來源是 ClotPlay |

### APIs 專案實作

```php
// 1. IP 白名單（callAction 預處理階段）
if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
    $this->response(0, -1, 'IP not in whitelist');
}

// 2. Authorization token 比對（checkSign）
protected function checkSign($authorizationKey)
{
    return $this->vendorInfo->info['operatorToken'] == $authorizationKey;
}
```

- Token 存於 `vendor_infos.info.operatorToken`，比對方式為字串全等（`==`），無 HMAC / 無時間戳。
- Driver 端（代理 → ClotPlay）同樣以 `Authorization => $this->vendorInfo->info['operatorToken']` 帶 header。

> ⚠️ **IP 白名單擋掉時**回 HTTP 403 + `{ "message": "Forbidden Access." }`

---

## 版本說明：V1 vs V2（重要）

| 版本 | 回調 endpoint（代理端） | 狀態 |
|---|---|---|
| **V1**（Deprecated Soon） | `betSettlement`（下注＋結算合一）、`getBalance`、`cancel` | 官方標註「即將棄用，請改接 V2」 |
| **V2** | `bet`（下注）、`settlement`（結算）、`getBalance`、`cancel`、`healthcheck` | 現行建議版本，下注與結算拆成兩步 |

> APIs 專案 `routes/external/sw.php` 同時掛 V1 `betSettlement` 與 V2 `bet`+`settlement` 路由，兩套都接得住。

---

## 錢包流程（Single Wallet / Seamless）

ClotPlay 採用「Game Provider 持有遊戲、Operator 持有玩家錢包」模式：

```
玩家 play
   │
   ▼
Game Client ── send play ──▶ Game Provider
                                 │ send "bet" callback ─────▶ Operator (扣款, 回 balance)
                                 │ generate result
                                 │ send "settlement" callback ─▶ Operator (派彩, 回 balance)
                                 ▼
                            show result
```

- **Bet 失敗**：Response Error 或 timeout → 觸發 `cancel` callback 回滾。
- **Settlement 失敗**：timeout / HTTP error → 重送最多 3 次 → 仍失敗標 **Unsettled transaction**，由 `Retry Unsettled Transactions` API 重觸發。
- **代理主動對帳**：每 5 分鐘以 `Get Transaction Details` + `Get Cancelled Transactions` 比對未結算回合。

---

## Callback API（ClotPlay → 代理）

### 通用回調請求格式

```http
POST  <Agent Callback URL>            # bet / settlement / betSettlement / cancel / healthcheck
GET   <Agent Callback URL>?params...  # getBalance
Authorization: <16 字元 token>
Content-Type: application/json
```

### 通用回應格式（代理 → ClotPlay）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `code` | Integer | ✅ | `0` 成功；非 0 為錯誤碼 |
| `data.balance` | Decimal(4) | ✅ | 處理後玩家最新餘額 |

```jsonc
{
  "code": 0,
  "data": { "balance": 100.0000 }
}
```

> ⚠️ **成功判定以 `code` 為準**：HTTP 200 但 `code != 0` → 視「Response Error」；HTTP 非 200（4xx/5xx）→ 視「HTTP Error」。兩者後續處置不同（詳見各 endpoint 的錯誤處置表）。

### 共通請求欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `player_unique_id` | String(24) | 玩家唯一 ID，**不含 prefix**、無空格、無特殊符號 |
| `currency` | String | 幣別代碼 |
| `transaction_id` | Integer/String | 交易序號 |
| `game` | String | 遊戲 slug |
| `game_round_id` | String | 回合 ID（group 同回合多筆 transaction） |
| `created_at` | String(ISO8601 含時區) | 交易建立時間 |
| `updated_at` | String(ISO8601 含時區) | 交易更新時間；**帳務 invoice 以 `updated_at` 為準** |
| `agent_code` / `agent_name` | String/null | 代理資訊（未啟用 agent system 則為 null） |
| `extra` | jsonObject(nullable) | Launch Game 帶入的附加資料原樣帶回（GET 走 URL-Encode） |

---

### V2 — Bet API（下注）

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/api/seamless/bet` |
| APIs 路由 | `POST clot/api/seamless/bet` |
| Controller method | `bet()` |

#### Request Body

| 欄位 | 類型 | 說明 |
|---|---|---|
| `player_unique_id` | String(24) | 玩家 ID |
| `amount` | Decimal(4) | 本回合總下注額（Turnover），**必 ≥ 0** |
| `currency` | String | 幣別 |
| `transaction_id` | Integer | 交易序號 |
| `game` | String | 遊戲 slug |
| `game_round_id` | String | 回合 ID |
| `created_at` / `updated_at` | String | 建立 / 更新時間 |
| `is_buy` | Boolean | 是否購買免費遊戲 |
| `agent_code` / `agent_name` | String | agent 資訊 |
| `extra` | jsonObject(nullable) | 附加資料 |

```jsonc
{
  "player_unique_id": "demo",
  "amount": 10.0000,
  "currency": "CNY",
  "transaction_id": 1,
  "game": "meow-meow",
  "game_round_id": "n000001MGXX",
  "created_at": "2008-09-15T15:53:00+05:00",
  "updated_at": "2008-09-15T15:53:00+05:00",
  "is_buy": false,
  "agent_code": "TEST_AGENT",
  "agent_name": "Test Agent",
  "extra": null
}
```

#### Success Response

```jsonc
{ "code": 0, "data": { "balance": 100.0000 } }
```

#### ClotPlay 端錯誤處置

| 代理回應 | ClotPlay 動作 |
|---|---|
| Success（`code=0`） | Proceed as usual |
| **Response Error**（HTTP 200 但 `code != 0`） | **立即觸發 `cancel` callback** |
| **Timeout**（> 10 秒） | **立即觸發 `cancel` callback** |
| **HTTP Error**（4xx/5xx） | **Do nothing**（不觸發 cancel） |

---

### V2 — Settlement API（結算 / 派彩）

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/api/seamless/settlement` |
| APIs 路由 | `POST clot/api/seamless/settlement` |
| Controller method | `settle()` |

#### Request Body

| 欄位 | 類型 | 說明 |
|---|---|---|
| `player_unique_id` | String(24) | 玩家 ID |
| `amount` | Decimal(4) | 本回合總贏額 / 派彩，**必 ≥ 0** |
| `valid_amount` | Decimal(4) | **（Deprecated，21-07-2025 棄用）** |
| `valid_bet` | Decimal(4) | 有效下注額 |
| `currency` | String | 幣別 |
| `transaction_id` | Integer | 交易序號 |
| `game` | String | 遊戲 slug |
| `game_round_id` | String | 回合 ID |
| `created_at` / `updated_at` | String | 建立 / 結算時間 |
| `is_buy` | Boolean | 是否購買免費遊戲 |
| `agent_code` / `agent_name` | String | agent 資訊 |
| `extra` | jsonObject(nullable) | 附加資料 |

```jsonc
{
  "player_unique_id": "demo",
  "amount": 100.0000,
  "valid_bet": 10.0000,
  "currency": "CNY",
  "transaction_id": 1,
  "game": "meow-meow",
  "game_round_id": "n000001MGXX",
  "created_at": "2008-09-15T15:53:00+05:00",
  "updated_at": "2008-09-15T15:53:00+05:00",
  "is_buy": false,
  "agent_code": "TEST_AGENT",
  "agent_name": "Test Agent",
  "extra": null
}
```

#### Success Response

```jsonc
{ "code": 0, "data": { "balance": 100.0000 } }
```

#### ClotPlay 端錯誤處置

| 代理回應 | ClotPlay 動作 |
|---|---|
| Success | Proceed as usual |
| **Response Error**（HTTP 200 `code != 0`） | **立即觸發 `cancel`** |
| **Timeout / HTTP Error** | **重送 `settlement`，最多 3 次**（interval 300ms，timeout 10s）；3 次仍失敗則標為 **Unsettled transaction** |

> 與 `bet` 不同：`settlement` 對 timeout / HTTP error 是**重試**；`bet` 對 timeout 觸發 cancel、對 HTTP error 不動作。

---

### V1 — BetSettlement API（下注＋結算合一，Deprecated）

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/api/seamless/betSettlement` |
| APIs 路由 | `POST clot/api/seamless/betSettlement` |
| Controller method | `betAndSettle()` |
| 狀態 | **Deprecated Soon** — 官方建議改用 V2 |

#### Request Body

| 欄位 | 類型 | 說明 |
|---|---|---|
| `player_unique_id` | String(24) | 玩家 ID |
| `bet` | Decimal(4) | 本回合總下注（Turnover），**必 ≥ 0** |
| `win` | Decimal(4) | 本回合總贏額 / 派彩，**必 ≥ 0** |
| `currency` | String | 幣別 |
| `transaction_id` | Integer | 交易序號 |
| `game` | String | 遊戲 slug |
| `game_round_id` | String | 回合 ID |
| `created_at` / `updated_at` | String | 建立 / 結算時間 |
| `agent_code` / `agent_name` | String | agent 資訊 |
| `extra` | jsonObject | 附加資料 |

```jsonc
{
  "player_unique_id": "demo",
  "bet": 100.0000,
  "win": 20.0000,
  "currency": "CNY",
  "transaction_id": 1,
  "game": "meow-meow",
  "game_round_id": "n000001MGXX",
  "created_at": "2008-09-15T15:53:00+05:00",
  "updated_at": "2008-09-15T15:53:00+05:00",
  "extra": {},
  "agent_code": "TEST_AGENT",
  "agent_name": "Test Agent"
}
```

#### ClotPlay 端 retry 規則（V1）

| 情境 | ClotPlay 動作 |
|---|---|
| Timeout error | **重試** betSettlement（最多 3 次，interval 300ms，timeout 10s） |
| HTTP 200 但 `code != 0` | **重試** |
| HTTP 非 200（4xx/5xx） | **不重試**，並接著呼叫 `cancel` |

---

### Get Balance API（查詢餘額）

| 項目 | 值 |
|---|---|
| Method | `GET` |
| Endpoint | `/api/seamless/getBalance` |
| APIs 路由 | `GET clot/api/seamless/getBalance` |
| Controller method | `balance()` |

#### Request Params

| 欄位 | 類型 | 說明 |
|---|---|---|
| `player_unique_id` | String(24) | 玩家 ID |
| `agent_code` | String | 若代理啟用 agent 才會帶 |
| `extra` | jsonString(nullable, URL-Encoded) | 附加資料 |

```
GET /api/seamless/getBalance?player_unique_id=demo&agent_code=TEST_AGENT
Authorization: <token>
```

#### Success Response

```jsonc
{ "code": 0, "data": { "balance": 100.0000 } }
```

#### ClotPlay 端錯誤處置

| 代理回應 | ClotPlay 動作 |
|---|---|
| Success | Proceed as usual |
| Response Error / Timeout / HTTP Error | **Do nothing** |

---

### Cancel API（取消 / 回滾）

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/api/seamless/cancel` |
| APIs 路由 | `POST clot/api/seamless/cancel` |
| Controller method | `cancel()` |

> ⚠️ **No Resend Policy**：cancel 不支援重送。若代理回應 error/timeout，ClotPlay 不會再送第二次。

#### Request Body

| 欄位 | 類型 | 說明 |
|---|---|---|
| `player_unique_id` | String(24) | 玩家 ID |
| `currency` | String | 幣別 |
| `transaction_id` | Integer | 交易序號 |
| `game` | String | 遊戲 slug |
| `game_round_id` | String | 回合 ID |
| `created_at` | String | 建立時間 |
| `agent_code` / `agent_name` | String | agent 資訊 |
| `extra` | jsonObject(nullable) | 附加資料 |

```jsonc
{
  "player_unique_id": "demo",
  "currency": "CNY",
  "transaction_id": 1,
  "game": "meow-meow",
  "game_round_id": "n000001MGXX",
  "created_at": "2008-09-15T15:53:00+05:00",
  "agent_code": "TEST_AGENT",
  "agent_name": "Test Agent",
  "extra": null
}
```

#### Success Response

```jsonc
{ "code": 0, "data": { "balance": 100.0000 } }
```

#### ClotPlay 端錯誤處置

| 代理回應 | ClotPlay 動作 |
|---|---|
| Success | Proceed as usual |
| Error / Timeout / HTTP Error | **Do nothing** |

- 代理須自行監控 Unsettled transactions：用 `Get Cancelled Transactions` + `Get Transaction Details` API 主動比對（建議每 5 分鐘）。

---

### Healthcheck API（V2）

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/api/seamless/healthcheck` |
| Request body | `{}` |
| Success | HTTP status code = **200**（body `{}`） |

> ⚠️ **缺口**：APIs 專案 `routes/external/sw.php` clot 群組目前**未登記** healthcheck 路由。若 ClotPlay 啟用 healthcheck，此路由會 404，需手動補。

---

## 代理主動呼叫 API（代理 → ClotPlay）

### Retry Unsettled Transactions（代理 → ClotPlay）

| 項目 | 值 |
|---|---|
| Host | `https://example.invalid/api/MOGI` |
| Method | `POST` |
| Endpoint | `/transactions/retry-unsettled` |
| Request body | `{ "agent_code": "<string>" }` |
| Response | `{ "code": 0, "data": { "unsettled_count": 10 } }` |

建議於發現遺漏 settlement 或長時間維護後主動呼叫。

### Launch Game

APIs `Driver::getGameUrl` 實際打 `/launch-game`：

```jsonc
{
  "player_unique_id": "<玩家帳號>",
  "player_name": "<玩家帳號>",
  "player_currency": "<幣別>",
  "game_id": "<game slug>",
  "is_demo": false,
  "language": "<en-us|zh-tw|zh-cn|id|vi|th>",
  "agent_code": "<vendor_infos.info.agentCode（若有）>"
}
```

回應 `data.game_url` 為 iframe 啟動網址。`code != 0` 時拋 `ApiException`。

### Bonus Code（Gift Code）

APIs `Driver::getBonusCode` 打 `/bonus-code/create`：

```jsonc
{
  "player_unique_id": "<玩家帳號>",
  "player_currency": "<幣別>",
  "config_name": "<vendor_infos.info.bonusConfigName>",
  "start_at": "<Asia/Taipei 格式>",
  "end_at":   "<Asia/Taipei 格式>"
}
```

回應 `data.bonus_code.code`（8 字元）為禮碼；`config_name` 須向 ClotPlay account manager 索取。

### 其他主動 API（參考）

| 類別 | Endpoint | Method | 用途 |
|---|---|---|---|
| Create Member | `/create-member` | POST | 建立會員 |
| Member Details | `/member-details` | POST | 取會員資料 |
| Member Logout | `/member-logout` / `/member-logout-all` | POST | 強制登出 |
| Get Transaction Details | `/get-transaction-details` | GET | 對帳（90 天內、30 分鐘窗、10 req/min） |
| Get Daily Report | `/get-daily-report` | GET | 每日彙總 |
| Get Cancelled Transactions | `/get-cancelled-transactions` | GET | 已取消交易（對帳用） |
| Get Game List | `/get-game-list` | GET | 遊戲清單（分頁） |
| Create Bonus Code | `/bonus-code/create` | POST | 建立 Bonus / Gift Code |

---

## 資料保留期（Housekeep Rules）

| 資料類別 | 保留期 |
|---|---|
| Transactions / Bet（First API, With Billing） | **60 天** |
| Transactions（Second API, Free Credit） | **14 天** |
| Player 帳號（無登入活動） | 365 天 |
| Freebie / Free Games | 60 天（free spins 1 小時後自動完成） |
| `Get Transaction Details` 可查歷史窗 | **90 天**（單次查詢窗最多 30 分鐘） |

> 計費類交易僅保留 60 天，對帳工具需在期限內完成拉取。

---

## HTTP 狀態碼 / 錯誤碼字典

《v2.0.13》Appendix 完整表（03-10-2025 移除 4021/4023；18-12 與 23-12 更新 6008/6009）：

| Error Code | HTTP | 說明 |
|---|---|---|
| `-1` | - | Something went wrong |
| `100` | - | Internal unknown error |
| `101` | - | Validation error |
| `102` | - | Invalid url |
| `103` | - | Disconnected from server（Multiple sessions not allowed） |
| `1000` | 400 | Invalid request parameters |
| `1001` | 400 | Invalid game slug |
| `1002` | 403 | Operator has no access to the game |
| `1003` | 403 | IP is not allowed to access |
| `1004` | 404 | Requested resource is not found |
| `1005` | 406 | Currency is not supported |
| `1006` | 406 | Language is not supported |
| `1007` | 406 | Invalid Date Range（date range too far apart） |
| `1008` | 406 | Invalid asset type |
| `1009` | 404 | Player not found |
| `1010` | 406 | Player session token expired |
| `1011` | 401 | Unauthorized |
| `1012` | 401 | Account suspended |
| `1013` | 401 | Duplicate login |
| `1014` | 401 | Player's free credit account ended |
| `1015` | 401 | Test player ended |
| `1016` | 401 | Missing "agent_code" |
| `1017` | 401 | Agent system is not supported |
| `1018` | 401 | Unauthorized agent |
| `1019` | 401 | Agent has been deactivated |
| `1020` | 401 | Agent is under maintenance |
| `1021` | 406 | Invalid wallet transfer action |
| `1022` | 406 | Fails to perform wallet transfer |
| `1023` | 406 | Too many attempt on calling a specific API |
| `2001` | 406 | Not support reporting system |
| `2002` | 401 | Unauthorized |
| `2003` | 401 | Invalid JTW token |
| `4001` | 406 | Player's balance is insufficient to perform action |
| `4002` | 406 | Invalid bet size |
| `4003` | 406 | Bonus error |
| `4004` | 406 | Game is under maintenance |
| `4005` | 406 | CheatMode unavailable |
| `4006` | 406 | Invalid game slug |
| `4007` | 406 | Merchant error |
| `4008` | 406 | Game refresh error |
| `4009` | 406 | Free spin issue during 100-spins |
| `4010` | 406 | Demo mode is not available on production |
| `4011` | 406 | Invalid win amount |
| `4012` | 406 | Invalid freebie token |
| `4013` | 406 | Invalid adjustment amount |
| `4014` | 406 | Invalid buy feature |
| `4015` | 406 | Invalid buy feature configuration |
| `4016` | 406 | Wallet transfer fails due to player is playing |
| `4017` | 406 | Invalid play result |
| `4018` | 406 | Invalid bet size limit |
| `4019` | 406 | Feature 100Spins not supported |
| `4020` | 406 | Bet Failed |
| `4022` | 406 | Settlement Failed |
| `4024` | 406 | Request Settlement Error |
| `4025` | 406 | Expired freebie token |
| `5001` | 406 | Vendor game exception |
| `5002` | 406 | Vendor game invalid Player Currency |
| `6006` | 406 | Invalid bonus code freebie config |
| `6007` | 406 | Failed to create bonus code |
| `6008` | 406 | Operator do not have permission to create bonus code |
| `6009` | 406 | Bonus code creation limit reached |

> IP 白名單擋掉時直接回 HTTP 403 + `{ "message": "Forbidden Access." }`。

---

## Change Log（官方文件，節選）

| 日期 | 變更 |
|---|---|
| 08-01-2026 | 新增 Seamless Healthcheck API |
| 23-12-2025 | 更新 Error Dictionary（6008, 6009） |
| 18-12-2025 | 新增 Gift Code；更新 Error Dictionary |
| 07-11-2025 | 新增 Housekeep Rules |
| 08-10-2025 | 新增 Member Logout All |
| 03-10-2025 | 移除 Error Code 4021, 4023 |
| 22-08-2025 | Seamless V2 各 API 新增 `extra` 範例 |
| 21-07-2025 | Seamless Settlement 棄用 `valid_amount` |
| 10-07-2025 | 新增 About Freespin / Demo Account Guide；多個欄位棄用 |
| 02-07-2025 | 整合 V1 與 V2；Get Game List 新增分頁 |
| 22-05-2025 | 新增 Retry Unsettled Transactions API |
| 15-05-2025 | Launch Game / Create Member 新增 `extra` 參數；更新 Seamless flows |

---

## Gaps（文件未提供 / 待確認）

- **正式環境 Base URL**：文件僅給 `https://example.invalid/api/MOGI`（疑為 integration），需向客服確認正式環境 host。
- **Authorization token 字元集 / 輪替**：僅知「16 字元、客服提供」，未規範組成與更換機制。
- **healthcheck 啟用條件**：文件未說明何種代理會被 ClotPlay 主動 healthcheck；路由尚未實作。
- **Bonus_Code_Guide.pdf 細節**：玩家領碼流程 UI 等補充未逐頁納入，需要時再補。
