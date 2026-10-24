# UUSlotSW — API 規格（合併版）

> **原始來源**：Google Doc「UU Slots Integration Documentation.docx」（fileId: `1VTlw_jKpKwI8N71hnGpu9l2IYbVWrnS1`）
> 抓取日期：2026-06-03
> 文件版本：v3.5.7（文件內最新版本）

UU Slots 為老虎機（Slots）/ 街機（Arcade）遊戲商，支援 Seamless Wallet（單一錢包）與 Fund Transfer Wallet（轉帳錢包）兩種模式。本專案採用 **Single Wallet** 模式（vid 82）。

---

## 通用約定

### 請求格式

- **HTTP Method**：所有 callback API 一律使用 `POST`
- **Content-Type**：`application/json`
- **驗證方式**：IP Whitelist + MD5 簽章（詳見 [簽名](#簽名) 章節）
- **字元編碼**：UTF-8

### Seamless Wallet 公共請求欄位（UU Slots → 代理）

每個 callback 均包含以下公共欄位：

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `OperatorId` | String(20) | ✅ | 代理商識別碼（對應 `vendor_infos.info.Merchant_Code`） |
| `RequestDateTime` | String(50) | ✅ | UTC 時間（yyyy-MM-dd HH:mm:ss），僅用於 MD5 驗簽，不作為交易時間 |
| `Signature` | String(50) | ✅ | MD5 簽章（演算法見 [簽名](#簽名) 章節） |
| `PlayerId` | String(50) | ✅ | 玩家帳號（對應 `uuslot_sw_accounts.username`） |
| `Currency` | String(5) | ✅ | 貨幣代碼（如 `MYR`） |

### 通用回應欄位（代理 → UU Slots）

每個 callback 回應均須包含：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | 狀態碼（見 [狀態碼對照表](#狀態碼對照表)） |
| `Description` | String(50) | 狀態描述 |
| `ResponseDateTime` | String(50) | 代理回應時間（yyyy-MM-dd HH:mm:ss） |

> **回應時限**：官方要求 callback 回應時間須在 **3 秒內**，否則影響遊戲體驗。

---

## IP 白名單

UU Slots 需提供 IP 讓代理白名單接受回調（UAT / PROD 各一組）：

| 環境 | IP |
|---|---|
| UAT | 0.0.0.0 |
| PROD | 0.0.0.0、0.0.0.0、0.0.0.0 |

> 代理同樣需提供伺服器 IP 給 UU Slots，讓主動呼叫 Provider API 的請求通過白名單。

---

## Callback API（UU Slots → 代理）

### POST GetBalance

**Endpoint**：`POST /api/sw/uuslot/GetBalance`

查詢玩家當前可用餘額。

**簽名串接**：`md5(GetBalance + RequestDateTime + OperatorId + SecretKey + PlayerId)`（GetBalance 無 UniqueId）

#### 額外請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `AuthToken` | String(500) | - | GameLogin 時代理傳入的 session token；UU Slots 原樣回傳 |

#### 請求範例

```json
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30 14:16:32",
  "Signature": "8d3d050acf123op78a42328215872745",
  "PlayerId": "Player001",
  "Currency": "MYR",
  "AuthToken": "0rV2Lz..."
}
```

#### 回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | 狀態碼（200 = 成功） |
| `Description` | String(50) | 狀態描述 |
| `ResponseDateTime` | String(50) | 代理回應時間（yyyy-MM-dd HH:mm:ss） |
| `Balance` | Decimal | 玩家當前可用餘額（如 `5123.23`） |

#### 回應範例

```json
{
  "Status": 200,
  "Description": "OK",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "Balance": 5123.23
}
```

---

### POST Bet

**Endpoint**：`POST /api/sw/uuslot/Bet`

扣除玩家下注金額，回傳更新後餘額。具冪等性：相同 `BetId` 不得重複處理。

**簽名串接**：`md5(Bet + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId)`

#### 額外請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `RoundId` | Long | ✅ | 遊戲局唯一 ID（多玩家可能共用同一 RoundId） |
| `BetId` | Long | ✅ | 本次投注交易唯一 ID（**冪等主鍵**） |
| `GameCode` | String(50) | ✅ | 遊戲代碼 |
| `GameType` | String(50) | ✅ | 遊戲類型字串（如 `Slots`、`Arcade`；整數值見 [附錄 7.4](#74-game-type)） |
| `BetAmount` | Decimal | ✅ | 下注金額（用此值更新餘額，如 `0.2000`） |
| `ExchangeRate` | Decimal | ✅ | 匯率 |
| `TranDateTime` | DateTime | ✅ | 實際交易 UTC 時間（yyyy-MM-dd HH:mm:ss） |
| `AuthToken` | String(500) | - | GameLogin 時代理傳入的 session token |
| `ProviderTimeZone` | String | ✅ | Provider 側時區（如 `Singapore Standard Time`） |
| `ProviderTranDt` | DateTime | ✅ | 依 ProviderTimeZone 的實際交易時間 |

#### 請求範例

```json
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30 14:16:32",
  "Signature": "8d3d050acf123op78a42328215872745",
  "PlayerId": "Player001",
  "Currency": "MYR",
  "RoundId": 438664,
  "BetId": 438665,
  "BetAmount": 0.2000,
  "ExchangeRate": 1.0000,
  "GameCode": "10000",
  "GameType": "Slots",
  "TranDateTime": "2014-09-30 14:16:32",
  "AuthToken": "0rV2Lz...",
  "ProviderTimeZone": "Singapore Standard Time",
  "ProviderTranDt": "2014-09-30T22:16:32.000+08:00"
}
```

#### 回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | 狀態碼（200 = 成功） |
| `Description` | String(50) | 狀態描述 |
| `ResponseDateTime` | String(50) | 代理回應時間 |
| `OldBalance` | Decimal | 扣款前餘額 |
| `NewBalance` | Decimal | 扣款後餘額 |

#### 回應範例

```json
{
  "Status": 200,
  "Description": "OK",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "OldBalance": 100.0000,
  "NewBalance": 98.0000
}
```

---

### POST GameResult

**Endpoint**：`POST /api/sw/uuslot/GameResult`

遊戲局結算，將獲勝金額加入玩家餘額。具冪等性：相同 `ResultId` 不得重複處理。已結算的交易**不可取消**。

**簽名串接**：`md5(GameResult + ResultId + RequestDateTime + OperatorId + SecretKey + PlayerId)`

#### 額外請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `ResultId` | Long | ✅ | 結算交易唯一 ID（**冪等主鍵**）；亦用於 GetTransactionDetails |
| `BetId` | Long | ✅ | 對應 Bet 的交易 ID |
| `RoundId` | Long | ✅ | 遊戲局唯一 ID |
| `GameCode` | String(50) | ✅ | 遊戲代碼 |
| `GameType` | String(50) | ✅ | 遊戲類型字串（整數值見 [附錄 7.4](#74-game-type)） |
| `GameName` | String(50) | ✅ | 遊戲名稱（英文） |
| `ResultType` | Integer | ✅ | 結算類型（見 [附錄 7.5](#75-game-result-type)，目前僅 `0` = Single/Quick） |
| `BetAmount` | Decimal | ✅ | 下注金額 |
| `ValidBetAmount` | Decimal | ✅ | 有效下注金額 |
| `Payout` | Decimal | ✅ | 彩金（用此值更新餘額；可為 `0.0000`） |
| `WinLose` | Decimal | ✅ | 輸贏金額（Payout - BetAmount，可為負值） |
| `ExchangeRate` | Decimal | ✅ | 匯率 |
| `TranDateTime` | DateTime | ✅ | 實際交易 UTC 時間（yyyy-MM-dd HH:mm:ss） |
| `RoundType` | Integer | ✅ | 局類型（見 [附錄 7.7](#77-round-type)，`0`=一般局，`1`=買免費旋轉/特殊局） |
| `ProviderTimeZone` | String | ✅ | Provider 側時區 |
| `ProviderTranDt` | DateTime | ✅ | 依 ProviderTimeZone 的實際交易時間 |

#### 請求範例

```json
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30T14:16:32Z",
  "Signature": "8d3d050acf123op78a42328215872745",
  "PlayerId": "Player001",
  "Currency": "MYR",
  "ResultId": 438666,
  "BetId": 438665,
  "RoundId": 438664,
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

#### 回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | 狀態碼 |
| `Description` | String(50) | 狀態描述 |
| `ResponseDateTime` | String(50) | 代理回應時間 |
| `OldBalance` | Decimal | 結算前餘額 |
| `NewBalance` | Decimal | 結算後餘額 |

#### 回應範例

```json
{
  "Status": 200,
  "Description": "OK",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "OldBalance": 100.0000,
  "NewBalance": 102.0000
}
```

---

### POST Rollback

**Endpoint**：`POST /api/sw/uuslot/Rollback`

因遊戲中斷取消下注，退還玩家餘額。具冪等性：相同 `BetId` 不得重複退款。

**簽名串接**：`md5(Rollback + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId)`

#### 額外請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `RoundId` | Long | ✅ | 遊戲局唯一 ID |
| `BetId` | Long | ✅ | 需要退款的 Bet 交易 ID（**冪等主鍵**） |
| `GameCode` | String(50) | ✅ | 遊戲代碼 |
| `GameType` | String(50) | ✅ | 遊戲類型字串（整數值見 [附錄 7.4](#74-game-type)） |
| `BetAmount` | Decimal | ✅ | 原始下注金額（退款此金額） |
| `ExchangeRate` | Decimal | ✅ | 匯率 |
| `TranDateTime` | DateTime | ✅ | 實際交易 UTC 時間（yyyy-MM-dd HH:mm:ss） |
| `RollbackType` | Integer | ✅ | 退款類型（見 [附錄 7.6](#76-rollback-type)，目前僅 `1` = Single/Quick Refund） |
| `ProviderTimeZone` | String | ✅ | Provider 側時區 |
| `ProviderTranDt` | DateTime | ✅ | 依 ProviderTimeZone 的實際交易時間 |

#### 請求範例

```json
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30T14:16:32Z",
  "Signature": "8d3d050acf123op78a42328215872745",
  "PlayerId": "Player001",
  "Currency": "MYR",
  "RoundId": 438664,
  "BetId": 438665,
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

#### 回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | 狀態碼 |
| `Description` | String(50) | 狀態描述 |
| `ResponseDateTime` | String(50) | 代理回應時間 |
| `OldBalance` | Decimal | 退款前餘額 |
| `NewBalance` | Decimal | 退款後餘額 |

#### 回應範例

```json
{
  "Status": 200,
  "Description": "OK",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "OldBalance": 100.0000,
  "NewBalance": 98.0000
}
```

---

### POST CashBonus

**Endpoint**：`POST /api/sw/uuslot/CashBonus`

Provider 活動（錦標賽、現金掉落）通知代理玩家獲得獎金，代理需增加玩家餘額。具冪等性：相同 `TranId` 不得重複處理。

**簽名串接**：`md5(CashBonus + TranId + RequestDateTime + OperatorId + SecretKey + PlayerId)`

#### 額外請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `TranId` | String(25) | ✅ | 獎金交易唯一 ID（**冪等主鍵**） |
| `BonusId` | String(50) | ✅ | 獎金類型唯一碼 |
| `BonusName` | String(50) | ✅ | 獎金名稱 |
| `BonusType` | Integer | ✅ | 獎金類型（見 [附錄 7.9](#79-bonus-type)：`0`=None, `2`=Angpao, `3`=Event, `4`=FreeRound） |
| `Payout` | Decimal | ✅ | 獎金金額（用此值更新玩家餘額） |
| `ExchangeRate` | Decimal | ✅ | 匯率 |
| `TranDateTime` | DateTime | ✅ | 實際交易 UTC 時間 |
| `Result` | String(100) | ✅ | 獎金說明字串（見 [附錄 8.0](#80-result-格式)） |
| `ProviderTimeZone` | String | ✅ | Provider 側時區 |
| `ProviderTranDt` | DateTime | ✅ | 依 ProviderTimeZone 的實際交易時間 |

#### 請求範例

```json
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30T14:16:32Z",
  "Signature": "8d3d050acf123op78a42328215872745",
  "PlayerId": "Player001",
  "Currency": "MYR",
  "TranId": "438665",
  "BonusId": "TNN2023",
  "BonusName": "Tournament 2023",
  "BonusType": 1,
  "Payout": 200.0000,
  "ExchangeRate": 1.0000,
  "TranDateTime": "2014-09-30 14:16:32",
  "Result": "1st place",
  "ProviderTimeZone": "Singapore Standard Time",
  "ProviderTranDt": "2014-09-30T22:16:32.000+08:00"
}
```

#### 回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | 狀態碼 |
| `Description` | String(50) | 狀態描述 |
| `ResponseDateTime` | String(50) | 代理回應時間 |
| `OldBalance` | Decimal | 入款前餘額 |
| `NewBalance` | Decimal | 入款後餘額 |

#### 回應範例

```json
{
  "Status": 200,
  "Description": "OK",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "OldBalance": 100.0000,
  "NewBalance": 200.0000
}
```

---

### POST Jackpot

**Endpoint**：`POST /api/sw/uuslot/Jackpot`

通知代理玩家中彩池（Jackpot），代理需增加玩家餘額。具冪等性：相同 `TranId` 不得重複處理。

**簽名串接**：`md5(Jackpot + TranId + RequestDateTime + OperatorId + SecretKey + PlayerId)`

#### 額外請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `TranId` | String(25) | ✅ | Jackpot 交易唯一 ID（**冪等主鍵**） |
| `JackpotId` | String(50) | - | Jackpot 類型唯一碼 |
| `Payout` | Decimal | ✅ | Jackpot 金額（用此值更新玩家餘額） |
| `ExchangeRate` | Decimal | ✅ | 匯率 |
| `TranDateTime` | DateTime | ✅ | 實際交易 UTC 時間 |
| `Result` | String(100) | ✅ | Jackpot 說明字串（如 `Minor Jackpot`） |
| `ProviderTimeZone` | String | ✅ | Provider 側時區 |
| `ProviderTranDt` | DateTime | ✅ | 依 ProviderTimeZone 的實際交易時間 |

#### 請求範例

```json
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

#### 回應欄位

同 CashBonus（`Status`、`Description`、`ResponseDateTime`、`OldBalance`、`NewBalance`）。

#### 回應範例

```json
{
  "Status": 200,
  "Description": "OK",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "OldBalance": 100.0000,
  "NewBalance": 200.0000
}
```

---

### POST BetNSettle（文件有，實作未見）

**Endpoint（文件）**：`POST /BetNSettle`

單一請求同時完成下注與結算（即投即結），回傳更新後餘額。具冪等性：相同 `BetId` 不得重複處理。

> **未實作警告**：官方文件 v3.5.1（2025-07-15）新增此 API，本專案目前無對應路由與 Controller method。上線前須向 UU Slots 確認是否啟用，若啟用則必須補實作。

**簽名串接**：`md5(BetNSettle + ResultId + RequestDateTime + OperatorId + SecretKey + PlayerId)`

欄位與 GameResult 相同（含 `ResultId`、`BetId`、`RoundId`、`GameCode`、`GameType`、`GameName`、`ResultType`、`BetAmount`、`ValidBetAmount`、`Payout`、`WinLose`、`ExchangeRate`、`TranDateTime`、`RoundType`、`ProviderTimeZone`、`ProviderTranDt`），另加 `AuthToken`（optional）。

---

### POST CancelBetNSettle（文件有，實作未見）

**Endpoint（文件）**：`POST /CancelBetNSettle`

確保 BetNSettle 結果送達代理。若已存在則回 success；若有錯誤則回 rejected 並持續重試，**直到代理回 success 為止**。BetNSettle 一旦建立，**不可 void / cancel / rollback**。

> **未實作警告**：同 BetNSettle，本專案尚無對應實作。

---

## Provider API（代理主動呼叫 UU Slots）

Endpoint 格式：

```
POST https://<API service domain>/api/opgateway/v1/op/<method>
```

### 常用 API 一覽

| Method | Path | 說明 | 必要性 |
|---|---|---|---|
| `GetGameList` | `/GetGameList` | 取得可用遊戲清單 | Optional |
| `GameLogin` | `/GameLogin` | 取得遊戲啟動 URL | **Required** |
| `GetTransactionDetails` | `/GetTransactionDetails` | 依 ResultId 查詢詳細交易報表連結 | Optional |
| `GetDaySummary` | `/GetDaySummary` | 每日交易匯總（依幣別） | Optional |
| `KickPlayer` | `/KickPlayer` | 將玩家踢出遊戲（臨時） | Optional |
| `GetUnfinishRound` | `/GetUnfinishRound` | 查詢玩家未完成局 | Optional |
| `CancelRound` | `/CancelRound` | 取消指定局（需局建立後 5 分鐘） | Optional |
| `SettleRound` | `/SettleRound` | 強制結算指定局並踢出玩家 | Optional |
| `GetRoundDetails` | `/GetRoundDetails` | 查詢指定局詳細資訊 | Optional |

### GameLogin 請求欄位

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `OperatorId` | String(20) | ✅ | Merchant Code |
| `RequestDateTime` | String(50) | ✅ | UTC 時間（yyyy-MM-ddTHH:mm:ssZ） |
| `Signature` | String(50) | ✅ | `md5(GameLogin + RequestDateTime + OperatorId + SecretKey + PlayerId)`（無 UniqueId） |
| `PlayerId` | String(50) | ✅ | 玩家帳號 |
| `Ip` | String(50) | ✅ | **玩家** IP（IPv4 only；非代理伺服器 IP） |
| `GameCode` | String(50) | ✅ | 遊戲代碼；傳 `0` 返回大廳 URL |
| `Currency` | String(5) | ✅ | 幣別（如 `MYR`） |
| `Lang` | String(5) | - | 語言（如 `en-us`、`zh-tw`；完整清單見 [附錄 7.1](#71-supported-language-code)） |
| `RedirectUrl` | String(100) | - | 返回大廳按鈕 URL |
| `AuthToken` | String(200) | - | 用於 Seamless 的 session 控制；透過 `GetBalance`、`Bet` 回傳 |
| `LaunchDemo` | Bool | - | 是否以 Demo 模式啟動 |

---

## 資料保留政策

| 類別 | 保留期限 |
|---|---|
| 即時交易（Real-time Transaction） | **30 天** |
| 未完成局（Unfinished Round）自動結算 | **1 小時** |
| Log Pulling 交易資料 | **60 天** |

---

## 重試機制（Reconciliation）

| 場景 | Provider 行為 |
|---|---|
| Bet 失敗 | Provider 自動觸發 Rollback，**不重試 Bet** |
| Rollback 失敗（未收到有效回應） | 最多 3 次，之後進入背景佇列每小時重試 |
| GameResult 失敗 | 最多 3 次，之後進入背景佇列每小時重試 |
| CashBonus / Jackpot 失敗 | 同 GameResult 機制 |

---

## 簽名

UU Slots 採用純 MD5（HEX 小寫）無額外加鹽演算法。**不同 API 的串接字串組成略有不同**，通用原則：

```
Signature = md5( FunctionName + [UniqueId] + RequestDateTime + OperatorId + SecretKey + PlayerId )
```

其中 `[UniqueId]` 依 API 不同：

| API | UniqueId |
|---|---|
| `GetBalance` | （無，此欄省略） |
| `Bet` | `BetId` |
| `GameResult` | `ResultId` |
| `Rollback` | `BetId` |
| `CashBonus` | `TranId` |
| `Jackpot` | `TranId` |
| `BetNSettle` | `ResultId` |
| `GameLogin`（代理主動呼叫） | （無） |
| `KickPlayer`（代理主動呼叫） | （無） |

> **重要**：`SecretKey` 對應 `vendor_infos.info.Security_key`。

### PHP 範例

```php
// Callback API（有 UniqueId）
$hash = md5($FunctionName . $UniqueId . $RequestDateTime . $OperatorId . $SecretKey . $PlayerId);

// Provider API（無 UniqueId，如 GameLogin、GetGameList）
$hash = md5($FunctionName . $RequestDateTime . $OperatorId . $SecretKey . $PlayerId);
```

---

## 狀態碼對照表

### Provider 回傳給代理（Provider Status Code）

| 狀態碼 | 說明 |
|---|---|
| 200 | OK |
| 400 | Bad Request |
| 403 | Forbidden Access |
| 503 | Service Maintenance |
| 504 | Game Maintenance |
| 900300 | Not Eligible |
| 900401 | Invalid Operator ID |
| 900402 | Invalid Player |
| 900403 | Invalid Signature |
| 900404 | Invalid Game Id |
| 900405 | Insufficient Balance |
| 900408 | Invalid TranID |
| 900500 | Internal Server Error |

### 代理回傳給 Provider（Operator Status Code）

| 狀態碼 | 說明 | 對應實作常數 |
|---|---|---|
| 200 | OK | `StatusCodeEnum::OPERATOR_OK` |
| 900404 | Invalid Player | `StatusCodeEnum::OPERATOR_INVALID_PLAYER` |
| 900405 | Operator ID Error | `StatusCodeEnum::OPERATOR_INVALID_OPERATOR_ID` |
| 900406 | Incoming Request Info Incomplete | `StatusCodeEnum::OPERATOR_INCOMING_REQUEST_INFO_INCOMPLETE` |
| 900407 | Invalid Signature | `StatusCodeEnum::OPERATOR_INVALID_SIGNATURE` |
| 900409 | Duplicate Transaction | `StatusCodeEnum::OPERATOR_DUPLICATE_TRANSACTION` |
| 900415 | Bet Transaction Not Found | `StatusCodeEnum::OPERATOR_BET_TRANSACTION_NOT_FOUND` |
| 900416 | Player Inactive | `StatusCodeEnum::OPERATOR_PLAYER_INACTIVE` |
| 900605 | Insufficient Balance | `StatusCodeEnum::OPERATOR_INSUFFICIENT_BALANCE` |
| 900500 | Internal Server Error | `StatusCodeEnum::OPERATOR_INTERNAL_SERVER_ERROR` |
| 800401 | Max Payout Reached（玩家達到代理設定的最高獲勝上限） | `StatusCodeEnum::OPERATOR_MAX_PAYOUT_REACHED` |

---

## 附錄

### 7.1 Supported Language Code

| 語言 | Code |
|---|---|
| English | `en-us` |
| Chinese (Simplified) | `zh-cn` |
| Chinese (Traditional) | `zh-tw` |
| Thai | `th-th` |
| Burmese | `my-mm` |
| Indonesia | `id-id` |
| Korean | `ko-kr` |
| Khmer | `km-kh` |

### 7.4 Game Type

| 值 | 說明 |
|---|---|
| 0 | Others |
| 1 | Slots |
| 2 | Arcade |
| 3 | Table |
| 4 | Event |
| 5 | Mini Game |
| 6 | Fish Arcade |
| 101 | Cash Bonus（已棄用） |

### 7.5 Game Result Type

| 值 | 說明 |
|---|---|
| 0 | Single / Quick Game Round Result |

### 7.6 Rollback Type

| 值 | 說明 |
|---|---|
| 1 | Single / Quick Game Refund |

### 7.7 Round Type

| 值 | 說明 |
|---|---|
| 0 | Normal Round |
| 1 | Buy FreeSpin/Feature Round |

### 7.8 Transaction Type

| 值 | 說明 |
|---|---|
| 0 | Base |
| 2 | Bonus |

### 7.9 Bonus Type

| 值 | 說明 |
|---|---|
| 0 | None |
| 2 | Angpao |
| 3 | Event |
| 4 | FreeRound |

### 8.0 Result 格式

| BonusType | Result 字串格式 |
|---|---|
| 4 (FreeRound) | `gameName\|minLineBet\|noOfLine\|betValue\|gameId\|freeRoundId` |
| 其他 | `gameName\|minLineBet\|noOfLine\|betValue` |
