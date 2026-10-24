# WFSW（WF Gaming）— 廠商 API 規格

> 原始來源：Google Drive 文件「WF Gaming Integration Documentation Simplified Chinese.docx」（fileId: `1yYHQozhbQTR6j6ZdPvit8S3DH3hrV_8D`）
> 抓取日期：2026-06-03
>
> 我方實作分析（整合落地建議、簽名陷阱、實作落差、SBR 重建）見同目錄 `integration.md`。

WF Gaming（又稱 SlotsMaker）為老虎機及多款遊戲提供商，提供 **Seamless Wallet（單一錢包）** 與 **Fund Transfer Wallet（轉帳錢包）** 兩種接入模式。本專案使用**單一錢包**模式（vid 120）。

---

## 通用約定

### 請求格式

```http
POST <API 服務 URL>
Content-Type: application/json
```

- **HTTP Method**：所有 API 一律使用 `POST`
- **Content-Type**：`application/json`
- **驗證方式**：MD5 簽名（見下方）

### 運營商 API Base URL 格式

```
https://<API service domain>/api/opgateway/v1/op/<method>
https://<API service domain>/api/opgateway/v1/op/<path>/<method>
```

> 實際 URL 由 WF 客服提供（生產 / 測試環境各異）。

### 狀態碼（提供商 → 代理）

WF 使用自定義整數狀態碼（非 HTTP 狀態碼）：

| Status | 說明 |
|---|---|
| `200` | 成功 |
| 其他 | 失敗，見 `Description` 欄位 |

### 代理側回應狀態碼（OperatorStatusEnum）

代理必須回傳以下標準狀態碼：

| Code | 名稱 | 說明 |
|---|---|---|
| `200` | OK | 成功 |
| `900404` | INVALID_PLAYER | 玩家無效 |
| `900405` | INVALID_OPERATOR_ID | OperatorId 錯誤 |
| `900406` | INCOMING_REQUEST_INFO_INCOMPLETE | 請求欄位不完整 |
| `900407` | INVALID_SIGNATURE | 簽名驗證失敗 |
| `900409` | DUPLICATE_TRANSACTION | 重複交易 |
| `900415` | BET_TRANSACTION_NOT_FOUND | 找不到對應投注單 |
| `900416` | PLAYER_INACTIVE | 玩家帳號停用 |
| `900417` | CANCEL_BET_N_SETTLE_REJECTED | CancelBetNSettle 被拒（提供商將持續重送） |
| `900500` | INTERNAL_SERVER_ERROR | 代理內部錯誤 |
| `900605` | INSUFFICIENT_BALANCE | 餘額不足 |
| `800401` | MAX_PAYOUT_REACHED | 已達最大派彩上限 |

### 玩家 ID 規範

- 欄位名稱：`PlayerId`
- 字元限制：最多 50 字元
- 允許字元：英數字及 `. _ $ - ?`（不允許空格）
- 若玩家在提供商系統不存在，`CreatePlayer` / `GameLogin` 時會自動建立

### 交易 ID 唯一性

- `BetId`：在整個提供商遊戲系統中全域唯一
- `ResultId`：同一回合結算的唯一 ID
- `TranId`：CashBonus / Jackpot 使用，全域唯一
- 同一 `RoundId` 多個玩家可共享（桌牌遊戲等）

---

## 簽名驗證演算法

WF 使用 **MD5** 進行雙向驗證，代理需在每次回調到來時驗簽。

### 演算法說明

各 API 的簽名組成方式不同，取決於 `FunctionName`（即 URL 最後一段）：

| API（FunctionName） | MD5 輸入字串 |
|---|---|
| `GetBalance` | `FunctionName + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| `Bet` | `FunctionName + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| `GameResult` | `FunctionName + ResultId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| `Rollback` | `FunctionName + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| `CashBonus` | `FunctionName + TranId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| `Jackpot` | `FunctionName + TranId + RequestDateTime + OperatorId + SecretKey + PlayerId` |

> `FunctionName` 即 endpoint 的方法名稱（如 `"GetBalance"`, `"Bet"`, `"GameResult"`, `"Rollback"`, `"CashBonus"`, `"Jackpot"`）。
> 使用方式：字串拼接後做 MD5，全小寫 hex。
>
> 注意：`GetBalance` 無 `BetId`/`ResultId`/`TranId` 參數，`GameResult` 使用 `ResultId`（非 `BetId`），`CashBonus`/`Jackpot` 使用 `TranId`（非 `BetId`）。詳見 integration.md「簽名陷阱」。

### 代理主動呼叫 WF 的簽名

不同方法有不同組合，常見的：

- `GameLogin`：`MD5(FunctionName + RequestDateTime + OperatorId + SecretKey + PlayerId)`
- `GetGameList`：`MD5(FunctionName + RequestDateTime + OperatorId + SecretKey)`

### 實作對應

簽名驗證在 `WFController::checkSign()` 實作（`app/Http/Controllers/External/SingleWallets/WFController.php`）：

```php
switch ($functionName) {
    case 'Bet':
    case 'Rollback':
        $betId = $request->input('BetId') ?? '';
        break;
    case 'GameResult':
        $betId = $request->input('ResultId') ?? '';
        break;
    case 'CashBonus':
    case 'Jackpot':
        $betId = $request->input('TranId') ?? '';
        break;
    default:
        $betId = '';
}
$signatureToValidate = md5($functionName . $betId . $requestDateTime . $operatorId . $secKey . $playerId);
```

---

## IP 白名單

WF 提供以下 IP 供代理做白名單設定（WFSW 回調來源）：

| 環境 | IP |
|---|---|
| UAT | `0.0.0.0` |
| PROD | `0.0.0.0`, `0.0.0.0`, `0.0.0.0` |

代理回調端使用 `Common::verifyIPWhitelistByVendorId()` 驗證。

---

## Seamless Wallet 回調 API（WF → 代理）

WF 會主動 POST 至代理提供的 URL。代理必須提供以下 6 個 endpoint：

| # | 用途 | URL Path | Controller 方法 |
|---|---|---|---|
| 1 | 查詢玩家餘額 | `sw/wf/GetBalance` | `WFController::getBalance` |
| 2 | 下注扣款 | `sw/wf/Bet` | `WFController::bet` |
| 3 | 遊戲結算（派彩） | `sw/wf/GameResult` | `WFController::settle` |
| 4 | 取消下注退款 | `sw/wf/Rollback` | `WFController::rollback` |
| 5 | 活動現金獎金 | `sw/wf/CashBonus` | `WFController::bonus` |
| 6 | 累積彩金（Jackpot） | `sw/wf/Jackpot` | `WFController::jackpot` |

> 路由定義位於 `routes/external/sw.php`，前綴 `sw/wf`。

### 通用約定

#### 請求格式（WF → 代理）

```http
POST <代理 Callback URL>/wf/<MethodName>
Content-Type: application/json
```

請求 body 以明文 JSON 帶入所有欄位（包含簽名欄位 `Signature`）。

#### 通用請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `OperatorId` | String(20) | ✅ | 運營商 ID |
| `RequestDateTime` | String(50) | ✅ | UTC datetime (yyyy-MM-dd HH:mm:ss)，**僅用於 MD5 簽名，不用於交易記錄** |
| `Signature` | String(50) | ✅ | MD5 簽名，各 API 組成方式不同（見簽名驗證演算法） |
| `PlayerId` | String(50) | ✅ | 玩家 ID（對應 `wfsw_accounts.username`） |
| `Currency` | String(5) | 視 API | 幣種代碼，如 `MYR`、`SGD` |
| `AuthToken` | String(500) | 可選 | 若 GameLogin 時帶入 AuthToken，後續回調中會附帶此值 |

#### 回應格式（代理 → WF）

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `Status` | Integer | ✅ | 狀態碼，成功為 `200`；失敗見 OperatorStatusEnum |
| `Description` | String(50) | ✅ | 狀態描述 |
| `ResponseDateTime` | String(50) | ✅ | UTC datetime (yyyy-MM-dd HH:mm:ss)，**僅用於 MD5 驗證，不用於交易記錄** |

> ⚠️ **重要**：WF 判定成功以 `Status=200` 為準。

#### 前置處理（callAction）

每次回調均由 `WFController::callAction()` 統一執行：

1. 記錄請求 log
2. 驗證 IP 白名單（`Common::verifyIPWhitelistByVendorId(VendorIdEnum::WFSW)`）
3. 依 `PlayerId` 取得會員（`getMemberByThirdPartyUsername`）
4. 依 `OperatorId` 取得 vendor_info
5. 驗證 MD5 簽名（`checkSign`）
6. 呼叫目標方法

---

### 1. GetBalance — 查詢餘額

玩家進入遊戲或每回合開始時，WF 透過此 endpoint 取回玩家代理端餘額。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Path | `sw/wf/GetBalance` |
| Controller | `WFController::getBalance` |

#### Request Body（WF → 代理）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OperatorId` | String(20) | 運營商 ID |
| `RequestDateTime` | String(50) | UTC datetime |
| `Signature` | String(50) | MD5(GetBalance + RequestDateTime + OperatorId + SecretKey + PlayerId) |
| `PlayerId` | String(50) | 玩家 ID |
| `Currency` | String(5) | 幣種 |
| `AuthToken` | String(500) | 可選，GameLogin 帶入時出現 |

```jsonc
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30 14:16:32",
  "Signature": "8d3d050acf123op78a42328215872745",
  "PlayerId": "Player001",
  "Currency": "MYR",
  "AuthToken": "0rV2Lz..."
}
```

#### Success Response（代理 → WF）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | `200` |
| `Description` | String | `"OK"` |
| `ResponseDateTime` | String | UTC datetime |
| `Balance` | Decimal | 玩家當前餘額（已乘以 `vendorInfo.rate`） |

```jsonc
{
  "Status": 200,
  "Description": "OK",
  "ResponseDateTime": "2026-06-03 10:00:00",
  "Balance": 1000.0000
}
```

#### 實作邏輯

1. 若玩家帳號 `status == 0`，回傳 `INVALID_PLAYER`
2. 透過 `WalletService::getMemberWallets` 取得餘額，乘以 `vendorInfo.rate` 後回傳

> 注意：`GetBalance` 不呼叫 `BetHelper::isMemberAbleToBet`（僅查詢餘額，不做投注資格判斷）。

---

### 2. Bet — 投注扣款

玩家下注時，WF 呼叫此 endpoint 確認餘額充足並扣款。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Path | `sw/wf/Bet` |
| Controller | `WFController::bet` |

> **重要**：此 API 為冪等（idempotent）。相同 `BetId` 再次呼叫只建立一筆交易。

#### Request Body（WF → 代理）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OperatorId` | String(20) | 運營商 ID |
| `RequestDateTime` | String(50) | UTC datetime |
| `Signature` | String(50) | MD5(Bet + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId) |
| `PlayerId` | String(50) | 玩家 ID |
| `Currency` | String(5) | 幣種 |
| `RoundId` | String(50) | 遊戲回合唯一 ID（多個玩家可共享同一 RoundId） |
| `BetId` | String(50) | 此次投注的唯一交易 ID（全域唯一） |
| `GameCode` | String(50) | 遊戲代碼 |
| `GameType` | String(50) | 遊戲類型字串（如 `"Slots"`, `"Arcade"`） |
| `BetAmount` | Decimal | 投注金額（例如 `0.2000`） |
| `ExchangeRate` | Decimal | 匯率（例如 `1.0000`） |
| `TranDateTime` | DateTime | 實際交易發生的 UTC 時間（yyyy-MM-dd HH:mm:ss） |
| `AuthToken` | String(500) | 可選 |
| `ProviderTimeZone` | String | 提供商側時區 |
| `ProviderTranDt` | DateTime | 基於提供商時區的交易時間 |

```jsonc
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30 14:16:32",
  "Signature": "8d3d050acf123op78a42328215872745",
  "PlayerId": "Player001",
  "Currency": "MYR",
  "RoundId": "438664",
  "BetId": "438665",
  "BetAmount": 0.2000,
  "ExchangeRate": 1.0000,
  "GameCode": "10000",
  "GameType": "Slots",
  "TranDateTime": "2014-09-30 14:16:32",
  "ProviderTimeZone": "Singapore Standard Time",
  "ProviderTranDt": "2014-09-30T22:16:32.000+08:00"
}
```

#### Success Response（代理 → WF）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | `200` |
| `Description` | String | `"OK"` |
| `ResponseDateTime` | String | UTC datetime |
| `OldBalance` | Decimal | 扣款前餘額 |
| `NewBalance` | Decimal | 扣款後餘額 |

```jsonc
{
  "Status": 200,
  "Description": "OK",
  "ResponseDateTime": "2026-06-03 10:00:00",
  "OldBalance": 100.0000,
  "NewBalance": 98.0000
}
```

#### 實作邏輯

1. 若帳號 `status == 0`，回 `INVALID_PLAYER`
2. `BetHelper::isMemberAbleToBet` 確認投注資格
3. `DB::transaction` 內：
   - `WalletService::getMemberLockedWallets`（含 `lockForUpdate`）
   - 以 `BetId` 查 `wfsw_bet_records`；若已存在，回 `DUPLICATE_TRANSACTION`
   - 扣款（`Common::numDiv(BetAmount / rate, 4)`）
   - 建立 `WfswBetRecord`（`status = BET = 1`）
   - 呼叫 `changeBalance`
   - 呼叫 `TotalBetRecordService::create`

---

### 3. GameResult — 遊戲結算

回合結束後，WF 呼叫此 endpoint 將獲勝金額加入玩家餘額。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Path | `sw/wf/GameResult` |
| Controller | `WFController::settle` |

> **重要**：此 API 為冪等。相同 `ResultId` 再次呼叫只建立一筆交易。

#### Request Body（WF → 代理）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OperatorId` | String(20) | 運營商 ID |
| `RequestDateTime` | String(50) | UTC datetime |
| `Signature` | String(50) | MD5(GameResult + ResultId + RequestDateTime + OperatorId + SecretKey + PlayerId) |
| `PlayerId` | String(50) | 玩家 ID |
| `Currency` | String(5) | 幣種 |
| `ResultId` | String(50) | 此次結算的唯一交易 ID（全域唯一；可傳入 `GetTransactionDetails` 取詳情） |
| `BetId` | String(50) | 對應的投注交易 ID |
| `RoundId` | String(50) | 遊戲回合 ID |
| `GameCode` | String(50) | 遊戲代碼 |
| `GameType` | String(50) | 遊戲類型字串 |
| `GameName` | String(50) | 遊戲英文名稱 |
| `ResultType` | Integer | 遊戲結果類型（見下方 ResultType 對照） |
| `BetAmount` | Decimal | 投注金額 |
| `ValidBetAmount` | Decimal | 有效投注金額 |
| `Payout` | Decimal | 派彩金額（無獲勝時為 `0`） |
| `WinLose` | Decimal | 盈虧金額（= Payout - BetAmount，可為負數） |
| `ExchangeRate` | Decimal | 匯率 |
| `TranDateTime` | DateTime | UTC 交易時間（yyyy-MM-dd HH:mm:ss） |
| `ProviderTimeZone` | String | 提供商側時區 |
| `ProviderTranDt` | DateTime | 提供商時區時間 |
| `RoundType` | Integer | 回合類型（見下方 RoundType 對照） |

```jsonc
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30 14:16:32",
  "Signature": "8d3d050acf123op78a42328215872745",
  "PlayerId": "Player001",
  "Currency": "MYR",
  "ResultId": "438666",
  "BetId": "438665",
  "RoundId": "438664",
  "GameCode": "10000",
  "GameType": "Slots",
  "GameName": "Game 001",
  "ResultType": 0,
  "BetAmount": 0.2000,
  "ValidBetAmount": 0.2000,
  "Payout": 0.0000,
  "WinLose": -0.2000,
  "ExchangeRate": 1.0000,
  "TranDateTime": "2014-09-30 14:16:32",
  "ProviderTimeZone": "Singapore Standard Time",
  "ProviderTranDt": "2014-09-30T22:16:32.000+08:00",
  "RoundType": 0
}
```

#### ResultType 對照

| 值 | 說明 |
|---|---|
| `0` | 正常結算 |
| 其他 | 待 WF 客服補充 |

#### RoundType 對照

| 值 | 說明 |
|---|---|
| `0` | Normal（一般回合） |
| 其他 | 見 WF 文件附錄 |

#### Success Response

```jsonc
{
  "Status": 200,
  "Description": "OK",
  "ResponseDateTime": "2026-06-03 10:00:00",
  "OldBalance": 100.0000,
  "NewBalance": 102.0000
}
```

#### 實作邏輯

1. `DB::transaction` 內：
   - `WalletService::getMemberLockedWallets`
   - 以 `BetId` 查 `WfswBetRecord`；若不存在回 `BET_TRANSACTION_NOT_FOUND`
   - 若 `status == RESULT` 回 `DUPLICATE_TRANSACTION`
   - 更新 `bet / valid_bet / payout / win_lose / payout_time / result_id / status = RESULT (2)`
   - `changeBalance`
   - `TotalBetRecordService::create`

---

### 4. Rollback — 取消下注退款

遊戲中斷或下注取消時，WF 呼叫此 endpoint 退還玩家已扣金額。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Path | `sw/wf/Rollback` |
| Controller | `WFController::rollback` |

> **重要**：此 API 為冪等。相同 `BetId` 再次呼叫只建立一筆交易。

#### Request Body（WF → 代理）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OperatorId` | String(20) | 運營商 ID |
| `RequestDateTime` | String(50) | UTC datetime |
| `Signature` | String(50) | MD5(Rollback + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId) |
| `PlayerId` | String(50) | 玩家 ID |
| `Currency` | String(5) | 幣種 |
| `RoundId` | String(50) | 遊戲回合 ID |
| `BetId` | String(50) | 對應的投注交易 ID |
| `GameCode` | String(50) | 遊戲代碼 |
| `GameType` | String(50) | 遊戲類型字串 |
| `BetAmount` | Decimal | 投注金額（退款金額） |
| `ExchangeRate` | Decimal | 匯率 |
| `TranDateTime` | DateTime | UTC 交易時間 |
| `RollbackType` | Integer | 回滾類型（見下方 RollbackType 對照） |
| `ProviderTimeZone` | String | 提供商側時區 |
| `ProviderTranDt` | DateTime | 提供商時區時間 |

```jsonc
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30 14:16:32",
  "Signature": "8d3d050acf123op78a42328215872745",
  "PlayerId": "Player001",
  "Currency": "MYR",
  "RoundId": "438664",
  "BetId": "438665",
  "BetAmount": 0.2000,
  "ExchangeRate": 1.0000,
  "GameCode": "10000",
  "GameType": "Slots",
  "TranDateTime": "2014-09-30 14:16:32",
  "RollbackType": 1,
  "ProviderTimeZone": "Singapore Standard Time",
  "ProviderTranDt": "2014-09-30T22:16:32.000+08:00"
}
```

#### RollbackType 對照

| 值 | 說明 |
|---|---|
| `1` | 文件未詳述，待 WF 客服補充 |
| 其他 | 待補充 |

#### Success Response

```jsonc
{
  "Status": 200,
  "Description": "OK",
  "ResponseDateTime": "2026-06-03 10:00:00",
  "OldBalance": 100.0000,
  "NewBalance": 98.0000
}
```

#### 實作邏輯

1. `DB::transaction` 內：
   - 以 `BetId` 查 `WfswBetRecord`；若不存在回 `BET_TRANSACTION_NOT_FOUND`
   - 若 `status == ROLLBACK` 回 `DUPLICATE_TRANSACTION`
   - 將 `bet = 0 / payout = 0 / win_lose = 0`；設 `payout_time`；`status = ROLLBACK (3)`
   - `changeBalance`（退還金額至玩家）
   - `TotalBetRecordService::create`

---

### 5. CashBonus — 活動現金獎金

提供商舉辦活動、比賽或提供獎金時，WF 透過此 endpoint 通知代理，增加玩家餘額。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Path | `sw/wf/CashBonus` |
| Controller | `WFController::bonus` |

> **重要**：此 API 為冪等。相同 `TranId` 再次呼叫只建立一筆交易。

#### Request Body（WF → 代理）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OperatorId` | String(20) | 運營商 ID |
| `RequestDateTime` | String(50) | UTC datetime |
| `Signature` | String(50) | MD5(CashBonus + TranId + RequestDateTime + OperatorId + SecretKey + PlayerId) |
| `PlayerId` | String(50) | 玩家 ID |
| `Currency` | String(5) | 幣種 |
| `TranId` | String(25) | 全域唯一獎金交易 ID |
| `BonusId` | String(50) | 唯一獎金代碼（代表不同獎金類型） |
| `BonusName` | String(50) | 獎金名稱 |
| `BonusType` | String(50) | 獎金類型（如 `"Event"`） |
| `Payout` | Decimal | 獎金金額 |
| `ExchangeRate` | Decimal | 匯率 |
| `TranDateTime` | DateTime | UTC 交易時間 |
| `Result` | String(100) | 獎金結果說明（如 `"1st place"`） |
| `ProviderTimeZone` | String | 提供商側時區 |
| `ProviderTranDt` | DateTime | 提供商時區時間 |

```jsonc
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30 14:16:32",
  "Signature": "8d3d050acf123op78a42328215872745",
  "PlayerId": "Player001",
  "Currency": "MYR",
  "TranId": "438665",
  "BonusId": "TNN2023",
  "BonusName": "Tournament 2023",
  "BonusType": "Event",
  "Payout": 200.0000,
  "ExchangeRate": 1.0000,
  "TranDateTime": "2014-09-30 14:16:32",
  "Result": "1st place",
  "ProviderTimeZone": "Singapore Standard Time",
  "ProviderTranDt": "2014-09-30T22:16:32.000+08:00"
}
```

#### Success Response

```jsonc
{
  "Status": 200,
  "Description": "OK",
  "ResponseDateTime": "2026-06-03 10:00:00",
  "OldBalance": 100.0000,
  "NewBalance": 200.0000
}
```

#### 實作邏輯

- 以 `TranId` 作為 `bet_id` 查重複；已存在則回 `DUPLICATE_TRANSACTION`
- 建立 `WfswBetRecord`（`status = BONUS (4)`，`game_code = 'CashBonus'`，`bet = 0`）
- `changeBalance`（加入 Payout）
- `TotalBetRecordService::create`（帶 `['bonus' => $win]`）

---

### 6. Jackpot — 累積彩金

玩家贏得累積彩金時，WF 透過此 endpoint 通知代理增加玩家餘額。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Path | `sw/wf/Jackpot` |
| Controller | `WFController::jackpot` |

> **重要**：此 API 為冪等。相同 `TranId` 再次呼叫只建立一筆交易。

#### Request Body（WF → 代理）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OperatorId` | String(20) | 運營商 ID |
| `RequestDateTime` | String(50) | UTC datetime |
| `Signature` | String(50) | MD5(Jackpot + TranId + RequestDateTime + OperatorId + SecretKey + PlayerId) |
| `PlayerId` | String(50) | 玩家 ID |
| `Currency` | String(5) | 幣種 |
| `TranId` | String(25) | 全域唯一 Jackpot 交易 ID |
| `JackpotId` | String(50) | 彩金代碼（代表不同彩金類型） |
| `Payout` | Decimal | 彩金金額 |
| `ExchangeRate` | Decimal | 匯率 |
| `TranDateTime` | DateTime | UTC 交易時間 |
| `Result` | String(100) | 彩金結果說明（如 `"Minor Jackpot"`） |
| `ProviderTimeZone` | String | 提供商時區時間 |
| `ProviderTranDt` | DateTime | 提供商時區時間 |

```jsonc
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30 14:16:32",
  "Signature": "8d3d050acf123op78a42328215872745",
  "PlayerId": "Player001",
  "Currency": "MYR",
  "TranId": "438665",
  "JackpotId": "JP001",
  "Payout": 200.0000,
  "ExchangeRate": 1.0000,
  "TranDateTime": "2014-09-30 14:16:32",
  "Result": "Minor Jackpot",
  "ProviderTimeZone": "Singapore Standard Time",
  "ProviderTranDt": "2014-09-30T22:16:32.000+08:00"
}
```

#### Success Response

```jsonc
{
  "Status": 200,
  "Description": "OK",
  "ResponseDateTime": "2026-06-03 10:00:00",
  "OldBalance": 100.0000,
  "NewBalance": 200.0000
}
```

#### 實作邏輯

- 以 `TranId` 作為 `bet_id` 查重複；已存在則回 `DUPLICATE_TRANSACTION`
- 建立 `WfswBetRecord`（`status = JACKPOT (5)`，`game_code = 'Jackpot'`，`bet = 0`）
- `changeBalance`（加入 Payout）
- `TotalBetRecordService::create`（帶 `['jackpot' => $win]`）

---

## BetRecord 狀態機

| status 值 | BetRecordStatusEnum | 觸發事件 |
|---|---|---|
| `1` | `BET` | Bet 投注扣款完成 |
| `2` | `RESULT` | GameResult 結算完成 |
| `3` | `ROLLBACK` | Rollback 取消退款完成 |
| `4` | `BONUS` | CashBonus 獎金加入 |
| `5` | `JACKPOT` | Jackpot 彩金加入 |

---

## 冪等性防禦

| 場景 | 處理 |
|---|---|
| 收到重複 `BetId`（Bet） | 查到 `WfswBetRecord`，直接回 `DUPLICATE_TRANSACTION`（code 900409） |
| 收到重複 `BetId`（Rollback） | 查到 `status == ROLLBACK`，回 `DUPLICATE_TRANSACTION` |
| 收到重複 `ResultId`（GameResult） | 查到 `status == RESULT`，回 `DUPLICATE_TRANSACTION` |
| 收到重複 `TranId`（CashBonus/Jackpot） | 查到紀錄存在，回 `DUPLICATE_TRANSACTION` |
| Bet 時找不到餘額 | 回 `INSUFFICIENT_BALANCE`（code 900605） |
| GameResult 時找不到 Bet | 回 `BET_TRANSACTION_NOT_FOUND`（code 900415） |
| Rollback 時找不到 Bet | 回 `BET_TRANSACTION_NOT_FOUND`（code 900415） |

---

## 可主動呼叫的整合 API（代理 → WF）

以下為代理主動呼叫 WF 側的 API，由 `Driver.php` 負責：

| API | 說明 | 狀態 |
|---|---|---|
| `GameLogin` | 取得遊戲啟動 URL | 已實作（`Driver::getGameUrl`） |
| `GetGameList` | 取得遊戲清單 | 未實作（可選） |
| `GetTransactionDetails` | 取得交易詳情 URL | 未實作（可選） |
| `GetDaySummary` | 每日交易彙總 | 未實作（可選，對帳用） |
| `KickPlayer` | 強制踢出玩家 | 未實作（可選） |
| `GetUnfinishRound` | 查詢未完成回合 | 未實作（可選） |
| `CancelRound` | 取消進行中回合 | 未實作（可選） |
| `SettleRound` | 強制結算進行中回合 | 未實作（可選） |
| `GetRoundDetails` | 查詢回合詳情 | 未實作（可選） |
| `CreateFreeRound` | 建立免費回合（行銷用） | 未實作（可選） |
| `CancelFreeRound` | 取消免費回合 | 未實作（可選） |
| `GetFreeRoundInfoByPlayer` | 查詢玩家免費回合 | 未實作（可選） |
| `GetFreeRoundGames` | 取得支援免費回合的遊戲 | 未實作（可選） |
| `GetFreeRoundDetails` | 免費回合詳情 | 未實作（可選） |
| `GetFreeRoundInfoByOperator` | 查詢運營商所有免費回合 | 未實作（可選） |

> PullLog / FlagLog / PullLogByTimestamp / PullLogBonus 等日誌拉取 API 為 Fund Transfer 模式專用，Seamless Wallet 模式不需使用。

---

## 版本歷史

| 版本 | 重要變更 | 日期 |
|---|---|---|
| V1.0 | 首次發布 | 2023-01-12 |
| V3.0 | 合併 Seamless / Fund Transfer 文件；補充遊戲環節、交易 ID、玩家 ID 說明；新增 Day Summary API | 2023-08-21 |
| V3.3.2 | 刪除 Seamless Wallet 回調請求中的 AuthToken 欄位 | 2023-11-03 |
| V3.3.3 | 新增 GetRoundDetails | 2023-11-17 |
| V3.4.1 | 新增 SettleRound API | 2024-09-02 |
| V3.4.4 | 廢棄 Fish Arcade 的 BuyIn/BuyOut/UpdateGameResult | 2024-10-30 |

---

## 支援語言

| 語言代碼 | 語言 |
|---|---|
| `en-us` | 英文（預設） |
| `zh-cn` | 簡體中文 |
| `zh-tw` | 繁體中文 |
| `th-th` | 泰文 |
| `my-mm` | 緬甸文 |
| `id-id` | 印尼文 |
| `ko-kr` | 韓文 |
| `km-kh` | 高棉文 |

---

## 遊戲類型對照

| GameType Integer | 類型名稱 |
|---|---|
| `1` | Slots（老虎機） |
| 其他 | 依文件/客服補充 |

GameType 字串格式（Seamless API 使用）：`"Slots"`, `"Arcade"` 等。
