# MTG (Meta Gaming) — API 整合文件（Single Wallet / Seamless）

> 原始來源：《MTG_API Doc(CHN).pdf》「API集成规范」，版本 v3.4.9（2025-04-07，SH 修改），© 2023 Meta Gaming
>
> 抓取日期：2026-07-14
>
> 本文件為廠商原文規格之整理（中文原文），涵蓋集成 API（Generic）、無縫錢包集成 API（Seamless Wallet）、資金轉帳錢包集成 API（Fund Transfer Wallet）三類。APIs 專案預計以 **無縫錢包（Seamless / Single Wallet）** 模式接入（vendor 目錄名 `MetaGamingSW`）。
>
> 我方實作分析見 `integration.md`（如有踩坑才建）。

---

## 1. API 概覽

### 1.1 三類 API

| 分類 | 用途 | 狀態 |
|---|---|---|
| 無縫錢包 API（Seamless Wallet） | 提供商作為遊戲提供者，運營商需在下注/派彩時呼叫，即時更新玩家餘額 | 本次採用 |
| 資金轉帳 API（Fund Transfer） | 運營商將資金轉入提供商錢包生態系統的玩家餘額 | 不採用（僅供對照） |
| 集成 API（Integration / Generic） | 遊戲列表、遊戲啟動、報表、免費回合等通用功能 | 需搭配使用（GameLogin 等） |

### 1.2 無縫錢包 API 方法列表

| 方法 | 描述 | 狀態 |
|---|---|---|
| `GetBalance` | 查詢玩家目前餘額 | 必需 |
| `Bet` | 檢查玩家餘額是否足夠並扣款，回傳更新後餘額 | 必需 |
| `GameResult` | 完成一輪遊戲後，獲勝金額加入玩家餘額，回傳更新後餘額 | 必需 |
| `Rollback` | 遊戲未完成而取消下注時，退還玩家餘額 | 必需 |
| `CashBonus` | 通知運營商玩家的活動/錦標賽/現金獎勵，導致餘額增加 | 必需 |
| `Jackpot` | 通知運營商玩家中大獎，導致餘額增加 | 必需 |
| `BetNSettle` | 下注與結算合併為單一請求（適用即時出結果的遊戲，如捕魚機），`BetId` 冪等 | 必需 |
| `CancelBetNSettle` | 確保 `BetNSettle` 結果送達；非真正取消，遊戲回合已存在則回應成功，否則遊戲商會持續重試 | 必需 |

### 1.3 資金轉帳 API 方法列表（不採用，僅供對照）

| 方法 | 描述 | 狀態 |
|---|---|---|
| `CreatePlayer` | 註冊新玩家（現在視為可選，`CheckBalance`/`GameLogin` 會自動建立） | 可選 |
| `CheckBalance` | 取得玩家在提供商系統的餘額 | 必需 |
| `Deposit` | 轉入資金至提供商系統玩家餘額 | 必需 |
| `Withdraw` | 從提供商系統玩家餘額轉出資金 | 必需 |
| `PullLog` | 取得玩家投注紀錄 | 可選 |
| `FlagLog` | 標記已取得紀錄，避免 `PullLog` 重複回傳 | 可選 |

### 1.4 集成 API 方法列表

| 方法 | 描述 | 狀態 |
|---|---|---|
| `GetGameList` | 取得提供商系統可訪問的遊戲集合 | 可選 |
| `GameLogin` | 取得遊戲啟動連結 | 必需 |
| `GetTransactionDetails` | 依遊戲結果 ID 取得交易詳情報告連結 | 可選 |
| `GetDaySummary` | 依貨幣分類的每日交易概覽 | 可選 |
| `CreateFreeRound` | 授予玩家免費回合 | 可選 |
| `CancelFreeRound` | 撤銷免費回合 | 可選 |
| `GetFreeRoundInfoByPlayer` | 取得玩家免費回合詳情 | 可選 |
| `GetFreeRoundGames` | 取得適用免費回合的遊戲列表 | 可選 |

### 1.5 玩家 ID

- `PlayerId` 為運營商系統內用戶的唯一標識符，運營商需先建立並存於自身系統。
- 若玩家在提供商系統不存在，會依 `CreatePlayer` / `GameLogin` 請求資料自動建立；已存在則依需要更新。
- **`PlayerId` 欄位限制最大 50 字元**，建議不超過此限制。

### 1.6 遊戲環節

- **下注和遊戲結果**：每個遊戲回合包含一個下注、一個遊戲結果或一個下注退款的獨立實例。
- **交易 ID**：下注、遊戲結果、現金獎金、Jackpot 使用的交易 ID，於提供商整個遊戲系統中保持唯一。
- **驗證令牌（AuthToken）**：運營商可在遊戲登入時傳入 `AuthToken`，並透過回調（**僅限 GetBalance、Bet 回調**）實現會話控制。**僅適用無縫運營商**；資金轉帳運營商可選用 `KickPlayer` API。

### 1.7 資料保留政策

| 項目 | 保留期限 | 說明 |
|---|---|---|
| 即時交易 | 30 天 | 未完成的交易/未結算的回合將於 **1 小時後自動結算** |
| 運營商日誌提取（Log Pulling） | 60 天 | 超過保留期限的交易資料將被歸檔，後台管理系統不再可見 |

---

## 2. 集成 API（Integration API）

### 2.0 通用約定

- 這組 API 為 HTTPS 監聽器，監控特定 URL 的傳入 `POST` 請求。
- 所有請求/回應皆為 **JSON** 格式；請求需帶 header `Content-Type: application/json`；HTTP 方法一律 `POST`。
- 提供商將分別為 production / test 環境提供 **OperatorId** 與 **SecretKey**。
- **服務 URL 格式**：

  ```
  https://<API service domain>/api/opgateway/v1/op/<method>
  https://<API service domain>/api/opgateway/v1/op/<path>/<method>
  https://<API service domain>/api/opgateway/v1/op/<path>/<path>/<method>
  ```

- 安全性：不應由玩家瀏覽器（終端使用者）發起請求；須提供 IP 供白名單設定。

### 2.1 API 文檔

#### 2.1.1 POST GetGameList

`Request path: POST /GetGameList`

取得提供商系統可訪問的遊戲集合。

**請求參數**

| 名稱 | 類型 | 描述 | 狀態 |
|---|---|---|---|
| OperatorId | String(20) | Operator Id | 必需 |
| RequestDateTime | String(50) | UTC datetime (`yyyy-MM-dd HH:mm:ss`)。僅用於 MD5 哈希與驗證，勿用於交易紀錄 | 必需 |
| Signature | String(200) | `MD5(FunctionName + RequestDateTime + OperatorId + SecretKey)` | 必需 |

```json
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30 14:16:32",
  "Signature": "8d3d050acf123op78a42328215872745"
}
```

**回應參數**

| 名稱 | 類型 | 描述 |
|---|---|---|
| Status | Integer | 狀態碼 |
| Description | String(50) | 狀態描述 |
| ResponseDateTime | String(50) | UTC datetime，僅用於 MD5 哈希與驗證 |
| Game | Array | 遊戲物件陣列 |

**Game 物件**

| 名稱 | 類型 | 描述 |
|---|---|---|
| GameCode | String(50) | 供應商系統內遊戲唯一代碼 |
| GameType | Integer | 遊戲類型（見 §7.4） |
| GameName | String(50) | 英文遊戲名稱/描述 |
| ImageUrl | String(100) | 遊戲圖片 URL（英文版） |
| HasDemo | Bool | 是否支援演示遊戲 |

```json
{
  "Status": 200,
  "Description": "Success",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "Game": [
    { "GameCode": "10000", "GameName": "Game name 001", "GameType": 1, "ImageUrl": "https://<base-domain-url>/thumbs/Web/10000.png", "HasDemo": true },
    { "GameCode": "10001", "GameName": "Game name 002", "GameType": 1, "ImageUrl": "https://<base-domain-url>/thumbs/Web/10001.png", "HasDemo": false }
  ]
}
```

#### 2.1.2 POST GameLogin

`Request path: POST /GameLogin`

運營商呼叫此方法取得遊戲啟動連結。

**請求參數**

| 名稱 | 類型 | 描述 | 狀態 |
|---|---|---|---|
| OperatorId | String(20) | Operator Id | 必需 |
| RequestDateTime | String(50) | UTC datetime，僅用於 MD5 哈希與驗證 | 必需 |
| Signature | String(50) | `MD5(FunctionName + RequestDateTime + OperatorId + SecretKey + PlayerId)` | 必需 |
| PlayerId | String(50) | 運營商玩家用戶名（僅允許 `A-z A-Z 0-9 . _ $ -`，不允許空格） | 必需 |
| Ip | String(50) | 玩家 IP，僅支援 IPv4 | 必需 |
| GameCode | String(50) | 遊戲唯一代碼（傳 `0` 回傳大廳網址而非遊戲網址） | 必需 |
| Currency | String(5) | 例：MYR、SGD（見 §7.2） | 必需 |
| Lang | String(5) | 玩家語言 ISO 代碼（見 §7.1） | 可選 |
| RedirectUrl | String(100) | 返回大廳按鈕重定向 URL | 可選 |
| AuthToken | String(500) | 驗證玩家遊戲會話用；此令牌會加到後續回調請求中（僅限 GetBalance、Bet） | 可選 |
| DisplayName | String(50) | （已棄用）玩家顯示名稱 | 可選 |
| PlayerBalance | Decimal | （已棄用）玩家目前餘額 | 可選 |
| LaunchDemo | Bool | 是否啟動演示 URL，預設 `false` | 可選 |

```json
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30 14:16:32",
  "Signature": "8d3d050acf123op78a42328215872745",
  "PlayerId": "Player001",
  "Ip": "0.0.0.0",
  "GameCode": "10001",
  "Currency": "MYR",
  "Lang": "en-us",
  "RedirectUrl": "https://<operator-lobby-url>.com",
  "AuthToken": "0rV2LzVUoLKCM3tbLvTkZW6ElA9jFkrhrm18ABouI27aG2gnEusTiMUfTLe0c1UxwWWBbkErK9qTKhCHxUsvCJUKjSywChcIz11Kx2vkdNwsdT1oU3PIUgZ8dyWQUQeiucw9E0odMvXpFYo3ub",
  "LaunchDemo": false
}
```

**回應參數**

| 名稱 | 類型 | 描述 |
|---|---|---|
| Status | Integer | 狀態碼 |
| Description | String(50) | 狀態描述 |
| ResponseDateTime | String(50) | UTC datetime，僅用於 MD5 哈希與驗證 |
| Url | String(2000) | 玩家啟動遊戲的網址 |

```json
{
  "Status": 200,
  "Description": "Success",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "Url": "https://<game-url>.com/slot/g/?p=2fht3VYQgXKMaUqxvomKPuz&rd="
}
```

#### 2.1.3 POST GetTransactionDetails

`Request path: POST /GetTransactionDetails`

運營商使用游戲結果 ID 取得交易詳情報告連結。**RoundId/Betid 不起作用**。視資料可用性而定，參考 §1.7 資料保留政策。

**請求參數**

| 名稱 | 類型 | 描述 | 狀態 |
|---|---|---|---|
| OperatorId | String(20) | Operator Id | 必需 |
| RequestDateTime | String(50) | UTC datetime (`yyyy-MM-ddTHH:mm:ssZ`) | 必需 |
| Signature | String(50) | `MD5(FunctionName + RequestDateTime + OperatorId + SecretKey)` | 必需 |
| TranId | String(50) | 交易 ID（`GameResult` 的 `ResultId` 或 `PullLog` 的 `TranId`） | 必需 |

```json
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30T14:16:32Z",
  "Signature": "8d3d050acf123op78a42328215872745",
  "TranId": "23250644"
}
```

**回應**：`Status` / `Description` / `ResponseDateTime` / `Url`（String(2000)，交易詳情頁面）。

```json
{
  "Status": 200,
  "Description": "Success",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "Url": "https://<game-transaction-url>.com/GameSeriesVXXX_Reporting/viewrounddetails"
}
```

#### 2.1.4 POST GetDaySummary

`Request path: POST /GetDaySummary`

提供按貨幣分類的每日交易簡要概覽。

**請求參數**：`OperatorId`、`RequestDateTime`、`Signature`（同上模式）、`Date`（DateTime，UTC）。

```json
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30 14:16:32",
  "Signature": "8d3d050acf123op78a42328215872745",
  "Date": "2023-08-18T00:00:00Z"
}
```

**回應**：`Status` / `Description` / `ResponseDateTime` / `Trans`（Array，交易物件）。

**交易物件**：`CurrencyCode`、`Turnover`（Decimal）、`ValidTurnover`（Decimal）、`Payout`（Decimal）、`WinLose`（Decimal，可負）。

```json
{
  "Status": 200,
  "Description": "Success",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "Trans": [
    { "CurrencyCode": "MYR", "Turnover": 1.00, "ValidTurnover": 1.00, "Payout": 1.80, "WinLose": 0.80 },
    { "CurrencyCode": "THB", "Turnover": 1.00, "ValidTurnover": 1.00, "Payout": 1.80, "WinLose": 0.80 }
  ]
}
```

#### 2.1.5 POST KickPlayer

`Request path: POST /KickPlayer`

暫時禁止玩家繼續遊戲；玩家下次會話仍可登入。

**請求參數**：`OperatorId`、`RequestDateTime`、`Signature`（`MD5(FunctionName + RequestDateTime + OperatorId + SecretKey + PlayerId)`）、`PlayerId`。

```json
{ "OperatorId": "DemoAccount", "RequestDateTime": "2014-09-30 14:16:32", "Signature": "8d3d050acf123op78a42328215872745", "PlayerId": "Player001" }
```

**回應**：`Status`（見 §7.3.1）/ `Description` / `ResponseDateTime`。

#### 2.1.6 POST GetUnfinishRound

`Request path: POST /GetUnfinishRound`

檢索玩家持有的未完成回合列表（未生成結果的回合）。

**請求參數**：`OperatorId`、`RequestDateTime`、`Signature`（含 `PlayerId`）、`PlayerId`。

**回應**：`Status` / `Description` / `ResponseDateTime` / `Rounds`（Array）。

**Rounds 物件**：`RoundId`（String(50)）、`GameId`（String(30)）、`GameName`（String(50)）、`Turnover`（Decimal）。

```json
{
  "Status": 200,
  "Description": "Success",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "Rounds": [
    { "RoundId": "100000000", "GameId": "10000", "GameName": "GameName 000", "Turnover": 1.00 },
    { "RoundId": "100000001", "GameId": "10001", "GameName": "GameName 001", "Turnover": 1.00 }
  ]
}
```

#### 2.1.7 POST CancelRound

`Request path: POST /CancelRound`

取消玩家正在持有的回合（已結算回合無法取消）。此操作取消當前回合進度、退還下注金額並結束回合；若玩家仍在遊戲中會被踢出，重新登入將開始新回合。

> ⚠️ **回合只能在 RoundId 建立後 5 分鐘內取消**。視資料可用性而定，參考 §1.7。

**請求參數**：`OperatorId`、`RequestDateTime`（`yyyy-MM-ddTHH:mm:ssZ`）、`Signature`（含 `PlayerId`）、`PlayerId`、`RoundId`。

**回應**：`Status`（見 §7.3.1）/ `Description` / `ResponseDateTime`。

#### 2.1.8 POST SettleRound

`Request path: POST /SettleRound`

結算玩家正在進行的回合（已結算回合不予處理）。此操作將當前回合進度彙總為支付並結束回合；若玩家仍在遊戲中會被踢出，重新登入將開始新回合。

> **僅在回合仍在進行且運營商接受投注時，才接受結算回合。**

**請求參數**：`OperatorId`、`RequestDateTime`、`Signature`（含 `PlayerId`）、`PlayerId`、`RoundId`。

**回應**：`Status`（見 §7.3.1）/ `Description` / `ResponseDateTime`。

#### 2.1.9 POST GetRoundDetails

`Request path: POST /GetRoundDetails`

檢索特定回合的完整資訊，包括是否已結算、是否已作廢、回合詳細資訊。視資料可用性而定，參考 §1.7。

**請求參數**：`OperatorId`、`RequestDateTime`（`yyyy-MM-ddTHH:mm:ssZ`）、`Signature`（`MD5(FunctionName + RequestDateTime + OperatorId + SecretKey)`，**不含** PlayerId）、`RoundId`（Long）、`GameCode`（String(50)）。

```json
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30T14:16:32Z",
  "Signature": "8d3d050acf123op78a42328215872745",
  "RoundId": "100000001",
  "GameCode": "10006"
}
```

**回應參數**

| 名稱 | 類型 | 描述 |
|---|---|---|
| Status | Integer | 狀態碼（見 §7.3.1） |
| Description | String(50) | 狀態描述 |
| ResponseDateTime | String(50) | UTC datetime |
| HasSettle | Boolean | 本輪是否已完全結算 |
| HasVoid | Boolean | 本輪是否已作廢 |
| RoundDetails | Object | 見下 |

**RoundDetails 物件**：`RoundId`（Long）、`RoundTranDT`（String(50)）、`ResultId`（Long，未結算/作廢則 NULL）、`ResultTranDT`（String(50)，未結算/作廢則 NULL）、`PlayerId`（String(50)）、`GameCode`（String(50)）、`BetAmount`（Decimal）、`Payout`（Decimal，未結算/作廢則 NULL）、`ProviderTimeZone`（String）、`ProviderRoundTranDT`（DateTime）、`ProviderResultTranDT`（DateTime）。

```json
{
  "HasSettle": true,
  "HasVoid": false,
  "RoundDetails": {
    "RoundId": 109850647,
    "RoundTranDT": "2023-11-17T04:07:20Z",
    "ResultId": 109850648,
    "ResultTranDT": "2023-11-17T04:07:20Z",
    "PlayerId": "UUDemoGuest3",
    "GameCode": "10014",
    "BetAmount": -200,
    "Payout": 0,
    "ProviderTimeZone": "Singapore Standard Time",
    "ProviderRoundTranDT": "2023-11-17T12:07:20.000+08:00",
    "ProviderResultTranDT": "2023-11-17T12:07:20.000+08:00"
  },
  "Status": 200,
  "Description": "Success",
  "ResponseDateTime": "2023-11-17 04:08:42"
}
```

### 2.2 演示啟動遊戲

兩種方式：

#### 2.2.1 GetGameList + GameLogin 方法

- `GetGameList` 回應的 `HasDemo` 欄位決定是否顯示演示連結/按鈕。
- `GameLogin` 請求帶 `LaunchDemo: true` 啟動演示會話；若遊戲無演示版本則觸發「無效遊戲」錯誤。

#### 2.2.2 GET GameList

`Request path: GET /demo/GameList?opId={opid}&lang={lang}`

**查詢參數**：`opId`（String(20)，必需）、`Lang`（String(5)，可選，見 §7.1）。回應同 §2.1.1。

#### 2.2.3 GET LaunchGame

`Request path: GET /demo/LaunchGame?opId={opid}&currency={currency}&gameCode={gameCode}&redirectUrl={redirectUrl}&lang={lang}`

**查詢參數**：`opId`（必需）、`Currency`（必需，§7.2）、`GameCode`（必需，`0` 回傳大廳 URL）、`RedirectUrl`（可選）、`Lang`（可選，§7.1）。

### 2.3 免費回合 API（Free Round）

行銷工具，運營商可授予玩家免費遊戲會話。

#### 2.3.1 POST CreateFreeRound

`Request path: POST /CreateFreeRound`

> **此 API 僅在玩家存在於 slotmaker 系統中時才有效**，新玩家須先透過 `GameLogin` 建立。

**請求參數**

| 名稱 | 類型 | 描述 | 狀態 |
|---|---|---|---|
| OperatorId | String(20) | Operator Id | 必需 |
| RequestDateTime | String(50) | UTC datetime (`yyyy-MM-ddTHH:mm:ssZ`) | 必需 |
| Signature | String(50) | `MD5(FunctionName + RequestDateTime + OperatorId + SecretKey + PlayerId)` | 必需 |
| PlayerId | String(50) | 玩家 ID | 必需 |
| GameCode | String(50) | 遊戲唯一代碼 | 必需 |
| RoundType | Integer | 回合類別/類型 | 必需 |
| TotalRound | Integer | 總回合數 | 必需 |
| BetValue | Integer | 每輪下注值 | 必需 |
| LineBet | Decimal | 每支付線下注金額 | 必需 |
| ReferenceId | String | 關聯特定交易/事件的參數 | 必需 |
| MaxWinAmount | Decimal | 免費回合最高總獎金 | 必需 |
| ExpiryInterval | Integer | 有效持續天數（未提供則用預設值） | 可選 |

```json
{
  "OperatorId": "DemoAccount", "Signature": "8d3d050acf123op78a42328215872745",
  "RequestDateTime": "2014-09-30T14:16:32Z", "PlayerId": "Player001",
  "GameCode": "10001", "RoundType": 1, "TotalRound": 10, "BetValue": 1,
  "LineBet": 0.1, "ReferenceId": "test", "MaxWinAmount": 100.00, "ExpiryInterval": 1
}
```

**回應**：`Status` / `Description` / `ResponseDateTime` / `FreeRoundId`（Long）/ `ExpiryTimeStamp`（Long，Unix timestamp）。

#### 2.3.2 POST CancelFreeRound

`Request path: POST /CancelFreeRound`

撤銷/使先前授予的免費回合無效。

**請求參數**：`OperatorId`、`RequestDateTime`、`Signature`（含 `PlayerId`）、`PlayerId`、`FreeRoundId`（Long）。

**回應**：`Status` / `Description` / `ResponseDateTime`。

#### 2.3.3 POST GetFreeRoundInfoByPlayer

`Request path: POST /GetFreeRoundInfoByPlayer`

**請求參數**：`OperatorId`、`RequestDateTime`、`Signature`（含 `PlayerId`）、`PlayerId`。

**回應**：`Status` / `Description` / `ResponseDateTime` / `FreeRoundInfos`（Array）。

**FreeRoundInfos 物件**：`FreeRoundId`（Long）、`GameCode`（String(50)）、`ExpiryTimeStamp`（Long）、`RoundType`（Integer）、`BetValue`（Integer）、`LineBet`（Decimal）、`CurrentRound`（Integer）、`TotalRound`（Integer）、`TotalWin`（Decimal）、`Status`（Integer：`0`=注冊 `1`=進行中 `2`=已完成 `3`=已領取 `400`=已取消 `500`=已過期）。

#### 2.3.4 POST GetFreeRoundGames

`Request path: POST /GetFreeRoundGames`

檢索適用於免費回合的遊戲資訊列表。

**請求參數**：`OperatorId`、`RequestDateTime`、`Signature`（`MD5(FunctionName + RequestDateTime + OperatorId + SecretKey)`，不含 PlayerId）。

**回應**：`Status` / `Description` / `ResponseDateTime` / `Games`（Array）。

**Games 物件**：`GameCode`、`GameName`、`TotalLine`（Integer）、`AvailableRoundType`（Array，內含 `RoundType`/`RoundTypeName`）、`AvailableBetValue`（Array of Integer）、`AvailableLineBet`（Array，內含 `Currency`/`LineBets`）、`MaxSpinCountCap`（Integer）、`MaxWinCapMultiplier`（Integer）。

```json
{
  "Games": [{
    "GameCode": "10050", "GameName": "Blessing of Tu", "TotalLine": 40,
    "AvailableRoundType": [{ "RoundType": 1, "RoundTypeName": "Normal" }],
    "AvailableBetValue": [1],
    "AvailableLineBet": [{ "Currency": "MYR", "LineBets": [0.005, 0.01, 0.02, 0.03, 0.04, 0.05] }],
    "MaxSpinCountCap": 100, "MaxWinCapMultiplier": 1000
  }],
  "Status": 200, "Description": "Success", "ResponseDateTime": "2023-09-19 12:27:17"
}
```

#### 2.3.5 POST GetFreeRoundDetails

`Request path: POST /GetFreeRoundDetails`

**請求參數**：`OperatorId`、`RequestDateTime`、`Signature`（含 `PlayerId`）、`PlayerId`、`FreeRoundId`（Long）。

**回應**：`Status` / `Description` / `ResponseDateTime` / `Trans`（Array）/ `IsMaxWin`（Bool）/ `MaxWinAmount`（Decimal，可空）。

**Trans（FreeRoundDetailsInfo）物件**：`TranDateTime`（DateTime，UTC）、`GameId`（Integer）、`GameName`（String）、`Turnover`（Decimal）、`Payout`（Decimal，可 0）、`WinLose`（Decimal）、`RoundType`（String：Normal/購買免費旋轉）、`TranId`（Long）、`ProviderTimeZone`（String）、`ProviderTranDt`（DateTime）。

#### 2.3.6 POST GetFreeRoundInfoByOperator

`Request path: POST /GetFreeRoundInfoByOperator`

取得運營商層級的免費回合詳情列表（跨玩家）。

**請求參數**：`OperatorId`、`RequestDateTime`（`yyyy-MM-ddTHH:mm:ssZ`）、`Signature`（`MD5(FunctionName + RequestDateTime + OperatorId + SecretKey)`）、`Page`（Integer，每頁最多 50 筆）。

**回應**：`Status` / `Description` / `ResponseDateTime` / `FreeRoundInfos`（Array）/ `TotalPage`（Integer）。

**FreeRoundInfos 物件**：同 §2.3.3，另加 `PlayerId`（String(50)）；`Status` 僅 `0`=注冊 `1`=正在進行 `500`=已逾期。

---

## 3. 無縫錢包集成 API（Seamless Wallet）— **本次採用**

### 3.0 通用約定

- 所有請求/回應為 JSON；header `Content-Type: application/json`；HTTP 方法 `POST`。
- OperatorId、SecretKey 由供應商分別提供 production / test 環境。
- 服務請求僅來自供應商伺服器（透過多個代理機器傳輸），IP 白名單設定：

  | 環境 | IP |
  |---|---|
  | UAT | `0.0.0.0` |
  | PROD | `0.0.0.0`、`0.0.0.0`、`0.0.0.0` |

### 3.1 遊戲開啟方法

透過集成 API `GameLogin`（§2.1.2）取得遊戲啟動 URL，適用任何支援瀏覽器或 WebView。

### 3.2 API 文檔

#### 3.2.1 POST GetBalance

`Request path: POST /GetBalance`

取得玩家目前餘額。

**請求參數**

| 名稱 | 類型 | 描述 |
|---|---|---|
| OperatorId | String(20) | Operator Id |
| RequestDateTime | String(50) | UTC datetime，僅用於 MD5 哈希 |
| Signature | String(50) | `MD5(FunctionName + RequestDateTime + OperatorId + SecretKey + PlayerId)` |
| PlayerId | String(50) | 玩家 ID |
| Currency | String(5) | 幣別（§7.2） |
| AuthToken | String(500) | 遊戲登入時傳入的驗證令牌（GameLogin 帶入時才有） |

```json
{
  "OperatorId": "DemoAccount", "RequestDateTime": "2014-09-30 14:16:32",
  "Signature": "8d3d050acf123op78a42328215872745", "PlayerId": "Player001",
  "Currency": "MYR", "AuthToken": "0rV2LzVUoLKCM3tbLvTkZW6ElA9jFkrhrm18ABouI27aG2gnEusTiMUfTLe0c1UxwWWBbkErK9qTKhCHxUsvCJUKjSywChcIz11Kx2vkdNwsdT1oU3PIUgZ8dyWQUQeiucw9E0odMvXpFYo3ub"
}
```

**回應**：`Status`（§7.3.2）/ `Description` / `ResponseDateTime` / `Balance`（Decimal，例 `1233.5000`）。

#### 3.2.2 POST Bet

`Request path: POST /Bet`

檢查玩家餘額並扣款，回傳更新後餘額。

> ⚠️ **重要：此呼叫是幂等的**。相同 `BetId` 重複發送只會產生一筆交易；運營商系統不應處理相同 `BetId` 兩次。

**請求參數**

| 名稱 | 類型 | 描述 |
|---|---|---|
| OperatorId | String(20) | Operator Id |
| RequestDateTime | String(50) | UTC datetime |
| Signature | String(50) | `MD5(FunctionName + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId)` |
| PlayerId | String(50) | 玩家 ID |
| Currency | String(5) | 幣別 |
| RoundId | String(50) | 遊戲回合唯一 ID（多玩家可共用） |
| BetId | String(50) | 遊戲回合唯一下注交易 ID（**冪等鍵**） |
| GameCode | String(50) | 遊戲唯一代碼 |
| GameType | String(50) | 遊戲類型字串（如 Slots/Arcade，見 §7.4） |
| BetAmount | Decimal | 下注金額（用於更新餘額） |
| ExchangeRate | Decimal | 匯率 |
| TranDateTime | DateTime | 供應商系統實際交易 UTC 時間 |
| AuthToken | String(500) | 驗證令牌 |
| ProviderTimeZone | String | 供應商側時區 |
| ProviderTranDt | DateTime | 基於供應商時區的實際交易時間 |

```json
{
  "OperatorId": "DemoAccount", "RequestDateTime": "2014-09-30 14:16:32",
  "Signature": "8d3d050acf123op78a42328215872745", "PlayerId": "Player001",
  "Currency": "MYR", "RoundId": 438664, "BetId": 438665, "BetAmount": 0.2000,
  "ExchangeRate": 1.0000, "GameCode": "10000", "GameType": "Slots",
  "TranDateTime": "2014-09-30 14:16:32",
  "AuthToken": "0rV2LzVUoLKCM3tbLvTkZW6ElA9jFkrhrm18ABouI27aG2gnEusTiMUfTLe0c1UxwWWBbkErK9qTKhCHxUsvCJUKjSywChcIz11Kx2vkdNwsdT1oU3PIUgZ8dyWQUQeiucw9E0odMvXpFYo3ub",
  "ProviderTimeZone": "Singapore Standard Time", "ProviderTranDt": "2014-09-30T22:16:32.000+08:00"
}
```

**回應**：`Status`（§7.3.2）/ `Description` / `ResponseDateTime` / `OldBalance`（Decimal）/ `NewBalance`（Decimal）。

#### 3.2.3 POST GameResult

`Request path: POST /GameResult`

完成回合後將贏得金額加入玩家餘額，回傳更新後餘額。

> ⚠️ **重要：此呼叫是幂等的**。相同 `ResultId` 重複發送只會產生一筆交易。

**請求參數**

| 名稱 | 類型 | 描述 |
|---|---|---|
| OperatorId | String(20) | Operator Id |
| RequestDateTime | String(50) | UTC datetime |
| Signature | String(50) | `MD5(FunctionName + ResultId + RequestDateTime + OperatorId + SecretKey + PlayerId)` |
| PlayerId | String(50) | 玩家 ID |
| Currency | String(5) | 幣別 |
| ResultId | String(50) | 遊戲回合唯一結果交易 ID（**冪等鍵**；可用於 `GetTransactionDetails`） |
| BetId | String(50) | 對應的下注交易 ID |
| RoundId | String(50) | 遊戲回合唯一 ID |
| GameCode | String(50) | 遊戲唯一代碼 |
| GameType | String(50) | 遊戲類型字串 |
| GameName | String(50) | 遊戲英文名稱/描述 |
| ResultType | Integer | 遊戲結果類型（§7.5） |
| BetAmount | Decimal | 下注金額 |
| ValidBetAmount | Decimal | 有效下注金額 |
| Payout | Decimal | 贏獎金額（用於更新餘額；無贏獎可為 0） |
| WinLose | Decimal | 輸贏金額（派彩 - 投注額，可負） |
| ExchangeRate | Decimal | 匯率 |
| TranDateTime | DateTime | 供應商系統實際交易 UTC 時間 |
| ProviderTimeZone | String | 供應商側時區 |
| ProviderTranDt | DateTime | 基於供應商時區的實際交易時間 |
| RoundType | Integer | 輪次類型（§7.7） |

```json
{
  "OperatorId": "DemoAccount", "RequestDateTime": "2014-09-30 14:16:32",
  "Signature": "8d3d050acf123op78a42328215872745", "PlayerId": "Player001",
  "Currency": "MYR", "ResultId": 438666, "BetId": 438665, "RoundId": 438664,
  "GameCode": "10000", "GameType": "Slots", "GameName": "Game 001", "ResultType": 0,
  "BetAmount": 0.2000, "ValidBetAmount": 0.2000, "Payout": 0.0000, "WinLose": -0.2000,
  "ExchangeRate": 1.0000, "TranDateTime": "2014-09-30 14:16:32",
  "ProviderTimeZone": "Singapore Standard Time", "ProviderTranDt": "2014-09-30T22:16:32.000+08:00",
  "RoundType": 0
}
```

**回應**：`Status`（§7.3.2）/ `Description` / `ResponseDateTime` / `OldBalance` / `NewBalance`。

#### 3.2.4 POST Rollback

`Request path: POST /Rollback`

遊戲未完成而取消下注時，退還玩家餘額。

> ⚠️ **重要：此呼叫是幂等的**。相同 `BetId` 重複發送回滾只會產生一筆交易。

**請求參數**

| 名稱 | 類型 | 描述 |
|---|---|---|
| OperatorId | String(20) | Operator Id |
| RequestDateTime | String(50) | UTC datetime |
| Signature | String(50) | `MD5(FunctionName + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId)` |
| PlayerId | String(50) | 玩家 ID |
| Currency | String(5) | 幣別 |
| RoundId | String(50) | 遊戲回合唯一 ID |
| BetId | String(50) | 對應下注交易 ID（**冪等鍵**） |
| GameCode | String(50) | 遊戲唯一代碼 |
| GameType | String(50) | 遊戲類型字串 |
| BetAmount | Decimal | 下注金額（用於更新餘額） |
| ExchangeRate | Decimal | 匯率 |
| TranDateTime | DateTime | 供應商系統實際交易 UTC 時間 |
| RollbackType | Integer | 回滾類型（§7.6） |
| ProviderTimeZone | String | 供應商側時區 |
| ProviderTranDt | DateTime | 基於供應商時區的實際交易時間 |

```json
{
  "OperatorId": "DemoAccount", "RequestDateTime": "2014-09-30 14:16:32",
  "Signature": "8d3d050acf123op78a42328215872745", "PlayerId": "Player001",
  "Currency": "MYR", "RoundId": 438664, "BetId": 438665, "BetAmount": 0.2000,
  "ExchangeRate": 1.0000, "GameCode": "10000", "GameType": "Slots",
  "TranDateTime": "2014-09-30 14:16:32", "RollbackType": 1,
  "ProviderTimeZone": "Singapore Standard Time", "ProviderTranDt": "2014-09-30T22:16:32.000+08:00"
}
```

**回應**：`Status`（§7.3.2）/ `Description` / `ResponseDateTime` / `OldBalance` / `NewBalance`。

#### 3.2.5 POST CashBonus

`Request path: POST /CashBonus`

用於提供商活動/比賽/獎金活動，通知運營商玩家獎金收入，增加餘額。

> ⚠️ **重要：此呼叫是幂等的**。相同 `TranId` 重複發送只會產生一筆交易。

**請求參數**

| 名稱 | 類型 | 描述 |
|---|---|---|
| OperatorId | String(20) | Operator Id |
| RequestDateTime | String(50) | UTC datetime (`yyyy-MM-ddTHH:mm:ssZ`) |
| Signature | String(50) | `MD5(FunctionName + TranId + RequestDateTime + OperatorId + SecretKey + PlayerId)` |
| PlayerId | String(50) | 玩家 ID |
| Currency | String(5) | 幣別 |
| TranId | **String(25)** | 唯一交易 ID（**冪等鍵**） |
| BonusId | String(50) | 唯一獎金代碼（代表不同獎金類型） |
| BonusName | String(50) | 現金獎金名稱 |
| BonusType | String(50) | 現金獎金類型（§7.9） |
| Payout | Decimal | 獎金金額（用於更新餘額） |
| ExchangeRate | Decimal | 匯率 |
| TranDateTime | DateTime | 供應商系統實際交易 UTC 時間 |
| Result | String(100) | 現金獎金結果字串（§8.0） |
| ProviderTimeZone | String | 供應商側時區 |
| ProviderTranDt | DateTime | 基於供應商時區的實際交易時間 |

```json
{
  "OperatorId": "DemoAccount", "RequestDateTime": "2014-09-30T14:16:32Z",
  "Signature": "8d3d050acf123op78a42328215872745", "PlayerId": "Player001",
  "Currency": "MYR", "TranId": 438665, "BonusId": "TNN2023", "BonusName": "Tournament 2023",
  "BonusType": 1, "Payout": 200.0000, "ExchangeRate": 1.0000,
  "TranDateTime": "2014-09-30 14:16:32", "Result": "1st place",
  "ProviderTimeZone": "Singapore Standard Time", "ProviderTranDt": "2014-09-30T22:16:32.000+08:00"
}
```

**回應**：`Status`（§7.3.2）/ `Description` / `ResponseDateTime` / `OldBalance` / `NewBalance`。

#### 3.2.6 POST Jackpot

`Request path: POST /Jackpot`

通知運營商玩家中大獎，增加餘額。

> ⚠️ **重要：此呼叫是幂等的**。相同 `TranId` 重複發送只會產生一筆交易。

**請求參數**（同 §3.2.5 結構，`TranId` **String(25)**，另 `JackpotId` 取代 `BonusId`/`BonusName`/`BonusType`）：

| 名稱 | 類型 | 描述 |
|---|---|---|
| OperatorId / RequestDateTime / Signature / PlayerId / Currency / TranId | — | 同 §3.2.5 |
| JackpotId | String(50) | 唯一大獎代碼（代表不同大獎類型） |
| Payout | Decimal | 獎金金額 |
| ExchangeRate | Decimal | 匯率 |
| TranDateTime | DateTime | 實際交易時間 |
| Result | String(100) | 結果字串 |
| ProviderTimeZone / ProviderTranDt | — | 同上 |

```json
{
  "OperatorId": "DemoAccount", "RequestDateTime": "2014-09-30 14:16:32",
  "Signature": "8d3d050acf123op78a42328215872745", "PlayerId": "Player001",
  "Currency": "MYR", "TranId": 438665, "BonusId": "JP001", "Payout": 200.0000,
  "ExchangeRate": 1.0000, "TranDateTime": "2014-09-30 14:16:32", "Result": "Minor Jackpot",
  "ProviderTimeZone": "Singapore Standard Time", "ProviderTranDt": "2014-09-30T22:16:32.000+08:00"
}
```

**回應**：`Status`（§7.3.2）/ `Description` / `ResponseDateTime` / `OldBalance` / `NewBalance`。

#### 3.2.7 POST BetNSettle

`Request path: POST /BetNSettle`

下注與結算合併為單一請求，適用於即時出結果的遊戲（如捕魚機）。

> ⚠️ **重要：此呼叫是幂等的**。相同 `BetId` 重複發送只會產生一筆交易。

**請求參數**

| 名稱 | 類型 | 描述 |
|---|---|---|
| OperatorId | String(20) | Operator Id |
| RequestDateTime | String(50) | UTC datetime (`yyyy-MM-ddTHH:mm:ssZ`) |
| Signature | String(50) | `MD5(FunctionName + ResultId + RequestDateTime + OperatorId + SecretKey + PlayerId)` |
| PlayerId | String(50) | 玩家 ID |
| Currency | String(5) | 幣別 |
| ResultId | Long | 結算交易唯一 ID（**冪等鍵**，可用於 §2.1.3 `GetTransactionDetails`） |
| BetId | Long | 下注交易唯一 ID |
| RoundId | Long | 遊戲回合唯一 ID |
| GameCode | String(50) | 遊戲代碼 |
| GameType | String(50) | 遊戲類型字串（§7.4） |
| GameName | String(50) | 英文遊戲名稱 |
| ResultType | Integer | 遊戲結果類型（§7.5） |
| BetAmount | Decimal | 下注金額 |
| ValidBetAmount | Decimal | 有效下注金額 |
| Payout | Decimal | 派彩金額（可為 0，用於更新餘額） |
| WinLose | Decimal | 輸贏金額（可為負數） |
| ExchangeRate | Decimal | 匯率 |
| TranDateTime | DateTime | 供應商系統實際交易 UTC 時間 |
| ProviderTimeZone | String | 供應商側時區 |
| ProviderTranDt | DateTime | 基於供應商時區的實際交易時間 |
| RoundType | Integer | 回合類型（§7.7） |
| AuthToken | String(200) | GameLogin 時傳入的 AuthToken（若有） |

```json
{
  "OperatorId": "DemoAccount", "RequestDateTime": "2014-09-30T14:16:32Z",
  "Signature": "8d3d050acf123op78a42328215872745", "PlayerId": "Player001",
  "Currency": "MYR", "ResultId": 438666, "BetId": 438665, "RoundId": 438664,
  "GameCode": "10000", "GameType": "Slots", "GameName": "Game 001", "ResultType": 0,
  "BetAmount": 0.2000, "ValidBetAmount": 0.2000, "Payout": 0.0000, "WinLose": -0.2000,
  "ExchangeRate": 1.0000, "TranDateTime": "2014-09-30 14:16:32", "RoundType": 0,
  "ProviderTimeZone": "Singapore Standard Time", "ProviderTranDt": "2014-09-30T22:16:32.000+08:00"
}
```

**回應**：`Status`（§7.3.2）/ `Description` / `ResponseDateTime` / `OldBalance` / `NewBalance`。

#### 3.2.8 POST CancelBetNSettle

`Request path: POST /CancelBetNSettle`

確保 `BetNSettle` 結果成功送達運營商。命名易誤導：**並非取消交易**，遊戲回合已存在則回應成功；若有錯誤，遊戲商會持續重試直到收到成功回應。

> ⚠️ **重要**：`BetNSettle` 一旦建立即**無法 void、cancel 或 rollback**——結果已產生，此 API 只負責確保派彩送達，不會扣回玩家已得到的派彩。

**請求參數**：同 §3.2.7 `BetNSettle`（欄位、Signature 公式皆相同，僅 `RequestDateTime` 為重送時間）。

```json
{
  "OperatorId": "DemoAccount", "RequestDateTime": "2014-09-30T14:16:32Z",
  "Signature": "8d3d050acf123op78a42328215872745", "PlayerId": "Player001",
  "Currency": "MYR", "ResultId": 438666, "BetId": 438665, "RoundId": 438664,
  "GameCode": "10000", "GameType": "Slots", "GameName": "Game 001", "ResultType": 0,
  "BetAmount": 0.2000, "ValidBetAmount": 0.2000, "Payout": 0.0000, "WinLose": -0.2000,
  "ExchangeRate": 1.0000, "TranDateTime": "2014-09-30 14:16:32", "RoundType": 0,
  "ProviderTimeZone": "Singapore Standard Time", "ProviderTranDt": "2014-09-30T22:16:32.000+08:00"
}
```

**回應**：`Status`（§7.3.2）/ `Description` / `ResponseDateTime` / `OldBalance` / `NewBalance`。

### 3.3 無縫投注的流程

流程：玩家下注 → 供應商呼叫 `GetBalance`（§3.2.1）驗證餘額 → 足夠則呼叫 `Bet`（§3.2.2）扣款 → 產生結果並呼叫 `GameResult`（§3.2.3）結算 → 玩家於畫面查看結果。

- 運營商系統須確保依各 API 指定欄位準確更新餘額。
- 運營商系統須保證幂等呼叫不被重複處理（依交易 ID 確認唯一性）。
- **運營商回調響應時間須少於 3 秒**，以確保最佳遊戲效能。

（時序圖：Player → Provider → Operator，`Place bet` → `Get balance`/`Return balance` → `Verify sufficient balance` → `Place bet`/`Bet success` → `Generate game result` → `Game result`/`Success` → `Show result`。）

---

## 4. 資金轉帳錢包集成 API（Fund Transfer Wallet）— 不採用，僅供對照

> APIs 專案本次以無縫錢包接入，此節保留廠商原文以利日後對照，**非本次實作範圍**。

### 4.0 通用約定

同 §2.0 / §3.0；服務 URL 格式同 §2.0。

### 4.1 遊戲啟動方式

透過集成 API `GameLogin`（§2.1.2）取得遊戲啟動 URL。

### 4.2 API 文檔

#### 4.2.1 POST CreatePlayer

`Request path: POST /CreatePlayer`

> **此 API 將很快被廢棄**；`GameLogin` 或 `CheckBalance` 會在需要時自動生成玩家資料。

**請求參數**：`OperatorId`、`RequestDateTime`、`Signature`（含 `PlayerId`）、`PlayerId`。

**回應**：`Status`（§7.3.2）/ `Description` / `ResponseDateTime`。

#### 4.2.2 POST CheckBalance

`Request path: POST /CheckBalance`

**請求參數**：同上結構。

**回應**：`Status` / `Description` / `ResponseDateTime` / `CurrentBalance`（Decimal）/ `Currency`（String(3)）。

#### 4.2.3 POST Deposit

`Request path: POST /Deposit`

**請求參數**：`OperatorId`、`RequestDateTime`（`yyyy-MM-ddTHH:mm:ssZ`）、`Signature`（含 `PlayerId`）、`PlayerId`、`Amount`（String(50)，**最多兩位小數，僅允許正數**）、`ReferenceId`（String(50)，運營商端唯一，**重複會被拒絕**）。

**回應**：`Status`（§7.3.2）/ `Description` / `ResponseDateTime` / `CurrentBalance`（Decimal，最多兩位小數）/ `Currency`（String(3)）。

#### 4.2.4 POST Withdraw

`Request path: POST /Withdraw`

**請求參數**：同 `Deposit`（`Amount` 僅允許正數，`ReferenceId` 須唯一）。

**回應**：同 `Deposit`。

#### 4.2.5 POST PullLog

`Request path: POST /PullLog`

取得玩家投注日誌（資金轉帳）。詳見 §4.3 交易日誌拉取機制。

**請求參數**：`OperatorId`、`RequestDateTime`、`Signature`（不含 PlayerId，`MD5(FunctionName + RequestDateTime + OperatorId + SecretKey)`）。

**回應**：`Status` / `Description` / `ResponseDateTime` / `Logs`（Array，**每次 API 呼叫最多回傳 1000 筆**）。

**Logs 物件**：`TranId`（Long，供應商系統唯一）、`PlayerId`、`Turnover`（Decimal）、`ValidTurnover`（Decimal）、`Payout`（Decimal）、`WinLose`（Decimal）、`TranDt`（DateTime）、`TranType`（Integer，§7.8）、`GameCode`、`GameType`（Integer，§7.4）、`GameName`、`ProviderTimeZone`（String）、`ProviderTranDt`（DateTime）、`RoundType`（Integer，§7.7）。

#### 4.2.6 POST FlagLog

`Request path: POST /FlagLog`

標記已檢索記錄，避免 `PullLog` 重複回傳。詳見 §4.3。

**請求參數**：`OperatorId`、`RequestDateTime`、`Signature`（含 PlayerId）、`TransactionIds`（Long[]，**最多 1000 個**）。

**回應**：`Status` / `Description` / `ResponseDateTime`。

#### 4.2.7 POST PullLogByTimestamp

`Request path: POST /PullLogByTimestamp`

依 UTC+0 時間戳檢索投注日誌。詳見 §4.4。

**請求參數**：`OperatorId`、`RequestDateTime`、`Signature`（不含 PlayerId）、`FromTs`（Integer，UTC timestamp，**最多回傳 300 秒/5 分鐘**的日誌）、`Page`（Integer，從 1 開始）。

**回應**：`Status` / `Description` / `ResponseDateTime` / `FromTs` / `ToTs` / `Logs`（Array，同 §4.2.5，最多 1000 筆）。

#### 4.2.8 POST PullLogBonus

`Request path: POST /PullLogBonus`

取得資金轉帳玩家的 Bonus 日誌。

**請求參數**：`OperatorId`、`RequestDateTime`（`yyyy-MM-ddTHH:mm:ssZ`）、`Signature`（不含 PlayerId）。

**回應**：`Status` / `Description` / `ResponseDateTime` / `Logs`（Array，最多 1000 筆）。

**Logs 物件**：`TranId`（Long）、`PlayerId`、`Payout`（Decimal）、`TranDt`（DateTime，`yyyy-MM-ddTHH:mm:ssZ`）、`TranType`（Integer）、`ProviderTimeZone`、`ProviderTranDt`、`RoundType`（Integer）、`AmountCoin`（Long）、`CurrencyCode`（String）、`BonusCode`（String）、`BonusName`（String）、`BonusResult`（String，§8.0）。

#### 4.2.9 POST FlagLogBonus

`Request path: POST /FlagLogBonus`

標記已檢索的 Bonus 記錄，避免 `PullLogBonus` 重複回傳。

**請求參數**：`OperatorId`、`RequestDateTime`、`Signature`（不含 PlayerId）、`TransactionIds`（Long[]，最多 1000 個）。

**回應**：`Status` / `Description` / `ResponseDateTime`。

#### 4.2.10 POST PullLogBonusByTimestamp

`Request path: POST /PullLogBonusByTimestamp`

依 UTC+0 時間檢索投注 Bonus 日誌。

**請求參數**：同 §4.2.7（`FromTs`、`Page`）。

**回應**：同 §4.2.8 `Logs` 結構，另加 `FromTs` / `ToTs`。

#### 4.2.11 POST CheckTransferStatus

`Request path: POST /CheckTransferStatus`

確定玩家的存款/取款狀態。

**請求參數**：`OperatorId`、`RequestDateTime`、`Signature`（`MD5(FunctionName + RequestDateTime + OperatorId + SecretKey + PlayerId + ReferenceId + TransferType)`）、`PlayerId`、`ReferenceId`（String(50)，運營商端唯一）、`TransferType`（Integer：`1`=存款 `2`=取款）。

**回應**：`Status`（§7.3.1）/ `Description` / `ResponseDateTime` / `Trans`（Object：`TranId`、`Amount`、`PrevBalance`、`NewBalance`）。

### 4.3 交易日誌拉取機制（PullLog / FlagLog）

1. 運營商呼叫 `PullLog` 取得投注日誌。
2. 供應商系統回傳最多 1000 筆未標記日誌。
3. 運營商接收後執行內部處理（插入/彙總交易）。
4. 運營商呼叫 `FlagLog` 將交易標記為已處理，避免下次重複拉取（可排除處理失敗的交易，讓其於下次拉取重新出現）。
5. `FlagLog` 成功後回到步驟 1 拉取下一批。

> 建議：回傳少於 1000 筆時，等待額外 5-10 秒再啟動下一次 `PullLog`，以減輕供應商系統負載。

### 4.4 時間戳機制日誌提取（PullLogByTimestamp）

1. 呼叫 `PullLogByTimestamp` 取得投注日誌。
2. 供應商回傳最多 1000 筆未標記日誌。
3. 運營商執行內部處理。
4. 若回傳交易數 > 0，`Page` + 1 再次呼叫（可能有更多分頁）。

> 建議：回傳 0 筆時，等待額外 5-10 秒再啟動下一次 `PullLogByTimestamp`。

---

## 5. 對帳（Reconciliation）

請求因網路逾時或含相關錯誤代碼時，供應商系統會依下述機制對帳。

> ⚠️ 運營商於對帳過程中遇到重試 API 呼叫時，須確保相同 `ReferenceId`/交易 ID 的交易不被重複處理，可實作適當錯誤碼有效停止對帳流程。

| 類型 | 機制 |
|---|---|
| **投注交易** | 下注失敗時觸發 `Rollback`；投注交易**不**套用對帳流程 |
| **回滾交易** | 重試 3 次未收到適當回應後，於後台交易佇列排入回滾請求；此外每小時自主定期重送回滾請求。運營商可回覆「回滾成功」或「找不到交易」表示已終止該交易 |
| **遊戲結果交易** | 到達運營商系統遇到困難時重試 3 次後轉入對帳流程。**已結算交易不可逆轉/取消**；供應商每小時自主重試以確保成功交付 |
| **獎金和彩金交易** | 與遊戲結果交易相同的重試機制 |

---

## 6. MD5 哈希（簽名機制）

**簽名公式（通用型式）**：

```
Signature = MD5(FunctionName + <其他欄位順序見各 API> + RequestDateTime + OperatorId + SecretKey [+ PlayerId / BetId / ResultId / TranId])
```

- 各 API 的精確欄位順序見前述各節「Signature」列（例：`GameLogin` = `MD5(FunctionName + RequestDateTime + OperatorId + SecretKey + PlayerId)`；`Bet` = `MD5(FunctionName + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId)`；`BetNSettle`/`CancelBetNSettle` = `MD5(FunctionName + ResultId + RequestDateTime + OperatorId + SecretKey + PlayerId)`）。
- `FunctionName` 為端點方法名（如 `GameLogin`、`Bet`、`GameResult`）。
- 文件提供 C#、JavaScript、PHP、Python、Bash、Ruby、Java、Swift、Kotlin 九種語言的 MD5 實作範例，邏輯皆為：字串依序拼接後取 MD5 hex digest（小寫）。

**PHP 範例**（專案採用語言）：

```php
<?php
$functionName = "GameLogin";
$requestDateTime = "2023-06-09 12:34:56";
$operatorId = "op1";
$secretKey = "ABC123";
$playerId = "player123";

$stringToHash = $functionName . $requestDateTime . $operatorId . $secretKey . $playerId;
$hash = md5($stringToHash);
echo $hash;
```

---

## 7. 附錄

### 7.1 支持的語言代碼

| 語言 | 代碼 |
|---|---|
| English | en-us |
| Chinese | zh-cn |
| Thai | th-th |
| Burmese/Myanmar | my-mm |
| Khmer | km-kh |
| Indonesia | id-id |
| Korean | ko-kr |
| Chinese (Trad) | zh-tw |

### 7.2 支持的貨幣代碼

| 代碼 | 說明 |
|---|---|
| MYR | 馬來西亞林吉特 |
| THB | 泰國銖 |
| SGD | 新加坡元 |
| USD | 美元 |
| VND / VNK | 越南盾（`VNK`＝`1000 VND`） |
| IDR / IDK | 印尼盾（`IDK`＝`1000 IDR`） |
| MMK / MK1 / MKK | 緬甸元（`MK1`＝`100 MMK`；`MKK`＝`1000 MMK`） |
| HKD | 港元 |
| BDT | 孟加拉塔卡 |
| RMB | 人民幣 |
| INR | 印度盧比 |
| PKR | 巴基斯坦盧比 |
| AUD | 澳元 |
| KRW / KRK | 韓元（`KRK`＝`100 KRW`） |
| LAK / LKK | 老撾基普（`LKK`＝`1000 LAK`） |
| NPR | 尼泊爾盧比 |
| PHP | 菲律賓比索 |
| TWD | 台灣元 |
| KHR / KHK | 柬埔寨瑞爾（`KHK`＝`1000 KHR`） |
| CAD | 加拿大元 |
| BRL | 巴西雷亞爾 |
| NGN | 尼日利亞奈拉 |
| BND | 文萊元 |
| EUR | 歐元 |
| JPY | 日元 |
| GBP | 英鎊 |
| SEK / NOK / DKK | 瑞典/挪威/丹麥克朗 |
| AED | 阿聯酋迪拉姆 |
| CHF | 瑞士法郎 |
| LKR | 斯里蘭卡盧比 |
| NZD | 新西蘭元 |
| RUB | 俄羅斯盧布 |
| TRY | 土耳其里拉 |
| ZAR | 南非蘭特 |
| MXN | 墨西哥比索 |
| FUN | 演示貨幣 |
| USDT | 加密美元泰達 |
| MBT | Milli-Bitcoin |
| CLP | 智利比索 |
| CZK | 捷克克朗 |
| UZS | 烏茲別克斯坦蘇姆 |
| XOF | CFA 法郎（BCEAO） |
| AZN | 阿塞拜疆馬納特 |
| XAF | CFA 法郎（BEAC） |
| IRR | 伊朗里亞爾 |
| ARS | 阿根廷比索 |
| GHS | 加納塞地 |
| ZMW | 贊比亞克瓦查 |
| PEN | 新索爾 |
| UGX | 烏干達先令 |
| AMD | 亞美尼亞德拉姆 |
| CDF | 剛果法郎 |
| COP | 哥倫比亞比索 |
| TJS | 索莫尼 |
| TZS | 坦桑尼亞先令 |
| PYG | 瓜拉尼 |

> 特殊貨幣需求請洽提供商團隊。

### 7.3 狀態 / 錯誤代碼

#### 7.3.1 提供商的狀態代碼（供應商回應給運營商，即運營商解讀集成 API 回應）

| 代碼 | 描述 |
|---|---|
| 200 | OK |
| 400 | 錯誤請求 |
| 403 | 禁止訪問 |
| 503 | 服務維護 |
| 900300 | 不符合資格 |
| 900401 | 無效的運營商 ID |
| 900402 | 無效的玩家 |
| 900403 | 無效簽名 |
| 900404 | 無效的遊戲 ID |
| 900408 | 無效的 TranID |
| 900500 | 內部服務器錯誤（適用不在列表中的其他錯誤/情況） |

#### 7.3.2 運營商的狀態代碼（運營商回應給供應商，即 APIs 需回傳給 MTG 的錯誤碼——無縫錢包 Bet/GameResult/Rollback/CashBonus/Jackpot/GetBalance/BetNSettle/CancelBetNSettle 的錯誤回應依此表）

| 代碼 | 描述 |
|---|---|
| 200 | OK |
| 900404 | 無效的玩家/密碼。請重試 |
| 900405 | 運營商 ID 錯誤 |
| 900406 | 傳入請求信息不完整 |
| 900407 | 無效的簽名 Invalid Signature |
| 900409 | 重複交易 |
| 900415 | 找不到投注交易 |
| 900416 | 玩家不活躍 |
| 900417 | CancelBetNSettle 被拒絕（BetNSettle 尚未處理，遊戲商會持續重送） |
| 900605 | 餘額不足 |
| 900500 | 內部服務器錯誤 |
| 800401 | 已達到最大派彩（運營商可用此代碼表示玩家已達運營商平台最大贏取限制） |

### 7.4 遊戲類型（遊戲類別）

| 代碼 | 說明 |
|---|---|
| 0 | 其他 |
| 1 | 老虎機 |
| 2 | 街機 |
| 3 | 桌上 |
| 4 | 活動 |
| 5 | 迷你遊戲 |
| 6 | 捕魚街機 |
| 101 | 現金獎勵（已棄用） |

### 7.5 比賽結果類型（`ResultType`）

| 代碼 | 說明 |
|---|---|
| 0 | 單次/快速遊戲回合結果 |

### 7.6 回滾類型（`RollbackType`）

| 代碼 | 說明 |
|---|---|
| 1 | 單次/快速遊戲退款 |

### 7.7 輪次類型（`RoundType`）

| 代碼 | 說明 |
|---|---|
| 0 | 正常回合 |
| 1 | 購買免費旋轉/特別回合 |

### 7.8 交易類型（`TranType`，PullLog 系列使用）

| 代碼 | 說明 |
|---|---|
| 0 | 基礎 |
| 2 | 獎勵 |

### 7.9 獎金類型（`BonusType`）

| 代碼 | 說明 |
|---|---|
| 0 | None |
| 2 | Angpao |
| 3 | Event |
| 4 | FreeRound |

### 8.0 結果（`Result` 字串格式，CashBonus/Jackpot/PullLogBonus 用）

| BonusType | 格式 |
|---|---|
| 4（FreeRound） | `gameName\|minLineBet\|noOfLine\|betValue\|gameId\|freeRoundId` |
| 其他 | `gameName\|minLineBet\|noOfLine\|betValue` |
