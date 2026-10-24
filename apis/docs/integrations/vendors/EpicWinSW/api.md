# EpicWinSW（Seamless / Single Wallet）— API 整合文件

> 原始來源：EpicWin Operator Integration Documentation V3.1.1（2023-10-20，53 頁）
> 我方實作分析見 integration.md

EpicWin（EpicWin Global）為老虎機 / 街機 / 桌台等多品類遊戲商，本平台以 **vid 85（`VendorIdEnum::EPICSW`）、Seamless Wallet（單一錢包）** 模式接入。

---

## 通用約定

### 請求格式

| 項目 | 值 |
|---|---|
| HTTP Method | 一律 `POST` |
| Content-Type | `application/json` |
| 編碼 | UTF-8 |
| 時間格式 | UTC `yyyy-MM-dd HH:mm:ss` |

> **重要（官方紅字）**：`RequestDateTime` / `ResponseDateTime` 僅用於 MD5 簽名與校驗，**交易時間請一律使用 `TranDateTime`**，不可用 `RequestDateTime`。

### 驗證方式

1. **IP 白名單**：EpicWin 的 outbound IP 必須由代理加入白名單；代理的 service URL 亦需提供給 EpicWin。
2. **MD5 簽名**：每個請求帶 `Signature`，拼接規則見本文件〈簽名〉節。
3. **AuthToken（Seamless 專屬）**：`GameLogin` 時代理產生 `AuthToken`，EpicWin 在後續 callback 原樣帶回，代理用以驗證玩家 game session。

### Base URL

```
https://<API service domain>/api/opgateway/v1/op/<FunctionName>
```

| 環境 | ServiceUrl（範例，各白標以客服提供為準） |
|---|---|
| Staging / UAT | `https://example.invalid/api/opgateway/v1/op/{function}` |
| Production | `https://example.invalid/api/opgateway/v1/op/{func_name}` |

> 實際值由 `vendor_infos.info['API_URL']` 取得，Driver 以 `rtrim($API_URL, '/') . '/' . $funcName` 組 URL。

### IP 白名單

代理需放行以下 EpicWin outbound IP（Seamless callback 來源）：

| 環境 | IP |
|---|---|
| Staging / UAT | `0.0.0.0` |
| Production | `0.0.0.0`、`0.0.0.0`、`0.0.0.0` |

---

## Seamless Wallet 回調 API（EpicWin → 代理）

EpicWin 主動 POST 至代理提供的 service URL，路徑即各 FunctionName。代理須提供 6 個 endpoint：

| # | FunctionName | 用途 | 必填 | APIs Controller 方法 |
|---|---|---|---|---|
| 1 | `GetBalance` | 查玩家餘額 | Required | `getBalance` |
| 2 | `Bet` | 下注扣款 | Required | `bet` |
| 3 | `GameResult` | 結算 / 派彩 | Required | `settle` |
| 4 | `Rollback` | 注單取消退款 | Required | `rollback` |
| 5 | `CashBonus` | 活動 / cash drop 獎金 | Required | `bonus` |
| 6 | `Jackpot` | Jackpot 獎金 | Optional | `jackpot` |

### 通用回應格式（代理 → EpicWin）

所有 callback 回應都包含：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | Operator 端狀態碼（成功 `200`，詳見〈錯誤碼〉節） |
| `Description` | String(50) | 狀態描述 |
| `ResponseDateTime` | String(50) | UTC `yyyy-MM-dd HH:mm:ss` |

金額類 callback 另回 `OldBalance` / `NewBalance`；GetBalance 回 `Balance`。

**前置驗證流程（`EpicWinController::callAction`）**

所有 callback 進入真正方法前依序執行：

1. `logRequest`（記錄完整請求）
2. IP 白名單：`Common::verifyIPWhitelistByVendorId(85)`，失敗回 `INTERNAL_SERVER_ERROR`
3. 取會員：`getMemberByThirdPartyUsername(PlayerId)`，無則回 `INVALID_PLAYER`
4. 取商戶：`getVendorInfo(OperatorId, account)`（以 `OPERATOR_ID` 欄位比對），無則回 `INVALID_OPERATOR_ID`
5. 驗簽：`checkSign()`，失敗回 `INVALID_SIGNATURE`
6. 驗 AuthToken：`checkAuthToken()`（請求未帶 token 時放行），失敗回 `INVALID_PLAYER`

---

### 1. GetBalance

| 項目 | 值 |
|---|---|
| Request Path | `POST /GetBalance` |
| 簽名 | `MD5(FunctionName + RequestDateTime + OperatorId + SecretKey + PlayerId)` |

**Request（EpicWin → 代理）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `OperatorId` | String(20) | ✅ | Operator Id |
| `RequestDateTime` | String(50) | ✅ | UTC，僅供簽名 |
| `Signature` | String(50) | ✅ | 見〈簽名〉節 |
| `PlayerId` | String(50) | ✅ | 玩家 ID（含 prefix） |
| `Currency` | String(5) | ✅ | 幣別 |
| `AuthToken` | String(500) | Optional | GameLogin 產生的 session token |

**Response（代理 → EpicWin）**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | 狀態碼 |
| `Description` | String(50) | 描述 |
| `ResponseDateTime` | String(50) | UTC |
| `Balance` | Decimal | 玩家可用餘額（例 `1233.5000`） |

```jsonc
{ "Status": 200, "Description": "Success", "ResponseDateTime": "2016-09-01 09:41:37", "Balance": 1233.5000 }
```

> APIs 實作：`account.status == 0` → `INVALID_PLAYER`；否則以 `WalletService::getMemberWallets()->getTotalBalance()`（真金 + 禮碼合計）回傳。**不需** `BetHelper::isMemberAbleToBet`。

---

### 2. Bet

| 項目 | 值 |
|---|---|
| Request Path | `POST /Bet` |
| 簽名 | `MD5(FunctionName + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId)` |
| 冪等鍵 | `BetId` |

**Request（EpicWin → 代理）**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OperatorId` | String(20) | Operator Id |
| `RequestDateTime` | String(50) | UTC，僅供簽名 |
| `Signature` | String(50) | 見〈簽名〉節 |
| `PlayerId` | String(50) | 玩家 ID |
| `Currency` | String(5) | 幣別 |
| `RoundId` | Long | 回合 ID（V3.1.1 新增；同回合多玩家可共用） |
| `BetId` | Long | 下注交易唯一 id（冪等鍵） |
| `GameCode` | String(50) | 遊戲代碼 |
| `GameType` | String(50) | 遊戲類型字串（如 `Slots` / `Arcade`） |
| `BetAmount` | Decimal | 下注金額（用此更新玩家餘額） |
| `ExchangeRate` | Decimal | 匯率 |
| `TranDateTime` | DateTime | **實際交易時間**（UTC） |
| `AuthToken` | String(500) | session token |

**Response（代理 → EpicWin）**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` / `Description` / `ResponseDateTime` | — | 通用 |
| `OldBalance` | Decimal | 扣款前餘額 |
| `NewBalance` | Decimal | 扣款後餘額 |

```jsonc
{ "Status": 200, "Description": "Success", "ResponseDateTime": "2016-09-01 09:41:37", "OldBalance": 100.0000, "NewBalance": 98.0000 }
```

> APIs 實作：`DB::transaction` 內：lockForUpdate 錢包 → 帳號狀態 / `isMemberAbleToBet` 檢查 → 餘額不足回 `INSUFFICIENT_BALANCE` → `bet_id` 已存在回 `DUPLICATE_TRANSACTION` → 建立 `EpicSwBetRecord`（`type='N'`、`status=0`）→ `changeBalance` 扣款 → 寫 `TotalBetRecord`。`RoundId` 僅整包存進 `info`（不存獨立欄位）。

---

### 3. GameResult（結算 / 派彩）

| 項目 | 值 |
|---|---|
| Request Path | `POST /GameResult` |
| 簽名 | `MD5(FunctionName + ResultId + RequestDateTime + OperatorId + SecretKey + PlayerId)` |
| 冪等鍵 | `ResultId` |

> ⚠️ **簽名用 `ResultId`，不是 `BetId`**——兩者皆為 Long，容易混淆。

**Request（EpicWin → 代理）**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OperatorId` / `RequestDateTime` / `Signature` / `PlayerId` / `Currency` | — | 通用 |
| `ResultId` | Long | 結算交易唯一 id（冪等鍵；可用於 `GetTransactionDetails`） |
| `BetId` | Long | 對應的下注交易 id |
| `RoundId` | Long | 回合 ID |
| `GameCode` | String(50) | 遊戲代碼 |
| `GameType` | String(50) | 遊戲類型字串 |
| `GameName` | String(50) | 遊戲英文名 |
| `ResultType` | Integer | Game Result Type（目前 `0` = Single/Quick Game Round Result） |
| `BetAmount` | Decimal | 下注金額 |
| `ValidBetAmount` | Decimal | 有效投注額 |
| `Payout` | Decimal | 派彩（**可為 0**；用此更新餘額） |
| `WinLose` | Decimal | 輸贏 = Payout − Turnover（**可為負**） |
| `ExchangeRate` | Decimal | 匯率 |
| `TranDateTime` | DateTime | 實際交易時間（UTC） |
| `AuthToken` | String(500) | session token |

**Response**

同 Bet（`OldBalance` / `NewBalance`）。

```jsonc
{ "Status": 200, "Description": "Success", "ResponseDateTime": "2016-09-01 09:41:37", "OldBalance": 100.0000, "NewBalance": 102.0000 }
```

> APIs 實作（`settle()`）：以 `BetId` 找原注單，無則回 `BET_TRANSACTION_NOT_FOUND`。覆寫 `bet`/`valid_bet`/`win_lose`（皆 `numDiv(..., rate, 4)`）、`payout_time = TranDateTime`、`status = 1`、`result_id = ResultId`，`changeBalance` 結算後寫 `TotalBetRecord`。

---

### 4. Rollback（注單取消）

| 項目 | 值 |
|---|---|
| Request Path | `POST /Rollback` |
| 簽名 | `MD5(FunctionName + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId)` |
| 冪等鍵 | `BetId` |

**重送機制（官方 §5.2）**：連送 3 次失敗後進背景佇列，之後**每小時**重送一次，直到代理回 `rollback success` 或 `transaction not found`——代理必須維持冪等以免重複退款。

**Request（EpicWin → 代理）**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OperatorId` / `RequestDateTime` / `Signature` / `PlayerId` / `Currency` | — | 通用 |
| `RoundId` | Long | 回合 ID |
| `BetId` | Long | 要回滾的下注 id（冪等鍵） |
| `GameCode` | String(50) | 遊戲代碼 |
| `GameType` | String(50) | 遊戲類型字串 |
| `BetAmount` | Decimal | 下注金額（用此回退餘額） |
| `ExchangeRate` | Decimal | 匯率 |
| `TranDateTime` | DateTime | 實際交易時間（UTC） |
| `AuthToken` | String(500) | session token |
| `RollbackType` | Integer | Rollback Type（目前 `1` = Single/Quick Game Refund，整單退款） |

**Response**

同 Bet（`OldBalance` / `NewBalance`，回退後餘額還原）。

> APIs 實作（`rollback()`）：以 `BetId` 找原注單，無則回 `BET_TRANSACTION_NOT_FOUND`（可用於終止 EpicWin 重送佇列）。將 `bet`/`valid_bet`/`win_lose` 全設 0、`status = 0`、`payout_time = TranDateTime`，`changeBalance` 退款後寫 `TotalBetRecord`。注意：實作未讀取 `RollbackType`（官方目前僅 `1` 整單退，無歧義）。

---

### 5. CashBonus

| 項目 | 值 |
|---|---|
| Request Path | `POST /CashBonus` |
| 簽名 | `MD5(FunctionName + TranId + RequestDateTime + OperatorId + SecretKey + PlayerId)` |
| 冪等鍵 | `TranId`（String(25)，V3.1.1 由整數改為字串） |

**Request（EpicWin → 代理）**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OperatorId` / `RequestDateTime` / `Signature` / `PlayerId` / `Currency` | — | 通用 |
| `TranId` | **String(25)** | 獎金交易唯一 id（V3.1.1 改型別） |
| `BonusId` | String(50) | 獎金代碼 |
| `BonusName` | String(50) | 獎金名稱 |
| `Payout` | Decimal | 派彩金額（用此增加餘額） |
| `ExchangeRate` | Decimal | 匯率 |
| `TranDateTime` | DateTime | 實際交易時間（UTC） |
| `AuthToken` | String(500) | session token |
| `Result` | String(100) | 獎金結果描述（如 `"1st place"`） |

**Response**

同 Bet（`OldBalance` / `NewBalance`）。

> APIs 實作（`bonus()`）：`bet_id = TranId`，撞重回 `DUPLICATE_TRANSACTION`。建立 `EpicSwBetRecord`（`type='B'`、`game_code='CashBonus'`、`bet=0`、`win_lose = numDiv(Payout, rate, 4)`、`status=1`），`changeBalance` 加款後寫 `TotalBetRecord`（含 `['bonus' => $win]`）。

---

### 6. Jackpot

| 項目 | 值 |
|---|---|
| Request Path | `POST /Jackpot` |
| 簽名 | `MD5(FunctionName + TranId + RequestDateTime + OperatorId + SecretKey + PlayerId)` |
| 冪等鍵 | `TranId`（String(25)） |
| 狀態（官方） | Optional |

**Request（EpicWin → 代理）**

欄位與 CashBonus 相同，但以 `JackpotId`（String(50)）取代 `BonusId`/`BonusName`：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OperatorId` / `RequestDateTime` / `Signature` / `PlayerId` / `Currency` | — | 通用 |
| `TranId` | String(25) | Jackpot 交易唯一 id |
| `JackpotId` | String(50) | Jackpot 代碼 |
| `Payout` | Decimal | 派彩金額（用此增加餘額） |
| `ExchangeRate` | Decimal | 匯率 |
| `TranDateTime` | DateTime | 實際交易時間（UTC） |
| `AuthToken` | String(500) | session token |
| `Result` | String(100) | 結果描述（如 `"Minor Jackpot"`） |

**Response**

同 Bet（`OldBalance` / `NewBalance`）。

> APIs 實作（`jackpot()`）：與 `bonus()` 同結構，`type='J'`、`game_code='Jackpot'`，撞重回 `DUPLICATE_TRANSACTION`，`changeBalance` 加款後寫 `TotalBetRecord`（含 `['jackpot' => $win]`）。

---

## Integration API（代理 → EpicWin）

以下 API 由代理主動呼叫 EpicWin，本平台目前只實作 `GameLogin`（`Driver::getGameUrl`）：

| API | 用途 | 本平台狀態 |
|---|---|---|
| `GetGameList` | 取遊戲清單 | 未實作 |
| `GameLogin` | 取玩家遊戲連結（含產生 `AuthToken`） | ✅ 已實作 |
| `GetTransactionDetails` | 查單筆交易（傳 `ResultId`） | 未實作（可用於對帳） |
| `GetDaySummary` | 每日匯總對帳 | 未實作 |
| `KickPlayer` | 踢出玩家 | 未實作 |
| `GetUnfinishRound` | 查未完成回合 | 未實作 |
| `CancelRound` | 取消回合 | 未實作 |

---

## 簽名

演算法：**MD5（hex 小寫）**。明文由各欄位依規定順序直接字串拼接（無分隔符），輸出 32 字元 hex。

| API | 明文拼接順序 |
|---|---|
| `GetGameList` | `FunctionName + RequestDateTime + OperatorId + SecretKey` |
| `GameLogin` | `FunctionName + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| `GetTransactionDetails` | `FunctionName + RequestDateTime + OperatorId + SecretKey` |
| `GetDaySummary` | `FunctionName + RequestDateTime + OperatorId + SecretKey` |
| `KickPlayer` / `GetUnfinishRound` / `CancelRound` | `FunctionName + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| **`GetBalance`（Seamless）** | `FunctionName + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| **`Bet`（Seamless）** | `FunctionName + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| **`GameResult`（Seamless）** | `FunctionName + ResultId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| **`Rollback`（Seamless）** | `FunctionName + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| **`CashBonus`（Seamless）** | `FunctionName + TranId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| **`Jackpot`（Seamless）** | `FunctionName + TranId + RequestDateTime + OperatorId + SecretKey + PlayerId` |

> Bet / Rollback 插 `BetId`、GameResult 插 `ResultId`、CashBonus / Jackpot 插 `TranId`——位置一律在 `FunctionName` 與 `RequestDateTime` 之間。`GetBalance` 不插交易 id（等同空字串）。

**`FunctionName`** = API 名稱字串（如 `"GameLogin"`、`"Bet"`、`"GameResult"`），Seamless callback 驗簽時取 `basename(url()->current())`，因此路由路徑名稱須與簽名字串完全一致。

**官方 PHP 範例：**

```php
$stringToHash = $functionName . $requestDateTime . $operatorId . $secretKey . $playerId;
$signature    = md5($stringToHash);  // 輸出 32 字元 hex 小寫
```

**常見錯誤：**

| 症狀 | 可能原因 |
|---|---|
| Seamless callback 一律 `900407 Invalid Signature` | `SECRET_KEY` 設定錯，或路由路徑名與 FunctionName 不一致 |
| 只有 Bet/Rollback/GameResult 驗簽失敗、GetBalance 正常 | 忘記把交易 id 插在 FunctionName 與 RequestDateTime 之間 |
| GameResult 驗簽失敗 | 誤用 `BetId` 而非 `ResultId` 參與簽名 |
| 偶發失敗 | `RequestDateTime` 被重新格式化後再驗簽——必須用 EpicWin 原樣傳來的字串 |

---

## 錯誤碼

### Operator 端狀態碼（代理回應 Seamless callback 時使用）

| Code | 說明 | `OperatorStatusEnum` 常數 |
|---|---|---|
| 200 | OK | `OK` |
| 900404 | Invalid player / password | `INVALID_PLAYER` |
| 900405 | Operator ID Error | `INVALID_OPERATOR_ID` |
| 900406 | Incoming Request Info Incomplete | `INCOMING_REQUEST_INFO_INCOMPLETE` |
| 900407 | Invalid Signature | `INVALID_SIGNATURE` |
| 900409 | Duplicate Transaction | `DUPLICATE_TRANSACTION` |
| 900415 | Bet Transaction Not Found | `BET_TRANSACTION_NOT_FOUND` |
| 900416 | Player Inactive | `PLAYER_INACTIVE` |
| 900605 | Insufficient Balance | `INSUFFICIENT_BALANCE` |
| 900500 | Internal Server Error | `INTERNAL_SERVER_ERROR` |
| 800401 | Max Payout Reached | `MAX_PAYOUT_REACHED` |

> `OperatorStatusEnum` 定義於 `app/Vendors/WFSW/OperatorStatusEnum.php`（EpicWin Controller 共用 WFSW 的 enum）。

### Provider 端狀態碼（Integration API 回應，EpicWin → 代理）

| Code | 說明 |
|---|---|
| 200 | OK |
| 400 | Bad Request |
| 403 | Forbidden Access |
| 503 | Service Maintenance |
| 900401 | Invalid Operator ID |
| 900402 | Invalid Player |
| 900403 | Invalid Signature |
| 900404 | Invalid Game Id |
| 900408 | Invalid TranID |
| 900500 | Internal Server Error |

### Game Type

| Code | 說明 |
|---|---|
| 0 | Others |
| 1 | Slots |
| 2 | Arcade |
| 3 | Table |
| 4 | Event |
| 5 | Mini Game |
| 6 | Fish Arcade |
| 101 | Cash Bonus（已 Deprecated） |

### Game Result Type（`GameResult` 的 `ResultType`）

| Code | 說明 |
|---|---|
| 0 | Single / Quick Game Round Result |

### Rollback Type（`Rollback` 的 `RollbackType`）

| Code | 說明 |
|---|---|
| 1 | Single / Quick Game Refund（整單退款） |

---

## Change Log（官方文件）

| 版本 | 日期 | 重點 |
|---|---|---|
| V1.0 | 2023-01-12 | First Release |
| V2.0 | 2023-02-28 | 移除 buyin/buyout/pushedresult；新增 Jackpot |
| V2.3 | 2023-05-29 | 新增 GetTransactionDetails |
| V2.5 | 2023-06-12 | 更新 MD5 hashing 實作細節 |
| V3.0 | 2023-08-21 | Seamless 與 Fund Transfer 合併；GameLogin 新增 AuthToken |
| V3.1 | 2023-10-20 | 新增 KickPlayer / GetUnfinishRound / CancelRound / Game Result Type / Rollback Type / IP 白名單 |
| **V3.1.1** | 2023-10-20 | **CashBonus/Jackpot `TranId` 改為 String(25)**；Bet/Result/Rollback 新增 `RoundId` |
