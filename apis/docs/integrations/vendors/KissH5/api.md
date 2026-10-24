# KissH5 API 整合參考文件

> 本文件整理自 KissH5 Operator Integration Documentation，涵蓋：
> - **取得遊戲清單**（GetGameList）
> - **登入遊戲**（GameLogin / LaunchGame）
> - **無縫錢包（Seamless Wallet）所有回調接口**

---

## 通用規範

### 認證方式

所有請求均使用 **MD5 簽名**，簽名字串規則依各接口不同（詳見各接口說明）。

```
Signature = MD5(FunctionName + ... + SecretKey + ...)
```

### 通用請求欄位

| 欄位 | 類型 | 說明 | 必填 |
|---|---|---|---|
| `OperatorId` | String(20) | 營運商 ID | ✅ |
| `RequestDateTime` | String(50) | UTC 時間 `yyyy-MM-ddTHH:mm:ssZ`，**僅用於 MD5 簽名，不可作為交易時間記錄** | ✅ |
| `Signature` | String(50/200) | MD5 簽名，規則依接口而異 | ✅ |

### 通用回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | 狀態碼（200 = 成功） |
| `Description` | String(50) | 狀態描述 |
| `ResponseDateTime` | String(50) | UTC 回應時間 `yyyy-MM-dd HH:mm:ss`，**僅用於 MD5 驗證** |

---

## 一、Integration API（§ 2，營運商 → 遊戲商）

### 1.1 `POST /GetGameList`

取得營運商帳號下所有可用遊戲列表。

#### Signature 規則

```
MD5(GetGameList + RequestDateTime + OperatorId + SecretKey)
```

#### 請求參數

| 欄位 | 類型 | 說明 | 必填 |
|---|---|---|---|
| `OperatorId` | String(20) | 營運商 ID | ✅ |
| `RequestDateTime` | String(50) | UTC 時間 | ✅ |
| `Signature` | String(200) | MD5 簽名 | ✅ |
| `Lang` | String(50) | 語言代碼（ISO 639-1），預設 `en-us` | ❌ |
| `Currency` | String(50) | 幣別代碼，未指定則使用預設值 | ❌ |

#### 請求範例

```json
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30T14:16:32Z",
  "Signature": "8d3d050acf123op78a42328215872745"
}
```

#### 回應參數

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | 狀態碼 |
| `Description` | String(50) | 狀態描述 |
| `ResponseDateTime` | String(50) | UTC 回應時間 |
| `Game` | Array | 遊戲物件陣列 |

**Game 物件欄位：**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `GameCode` | String(50) | 遊戲唯一代碼 |
| `GameName` | String(50) | 英文遊戲名稱 |
| `GameType` | Integer | 遊戲類型（參考 Game Type 表） |
| `ImageUrl` | String(100) | 英文版遊戲圖片 URL |
| `HasDemo` | Bool | 是否支援試玩模式 |

#### 回應範例

```json
{
  "Status": 200,
  "Description": "Success",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "Game": [
    {
      "GameCode": "10000",
      "GameName": "Game name 001",
      "GameType": 1,
      "ImageUrl": "https://<base-domain-url>/thumbs/Web/10000.png",
      "HasDemo": true
    },
    {
      "GameCode": "10001",
      "GameName": "Game name 002",
      "GameType": 1,
      "ImageUrl": "https://<base-domain-url>/thumbs/Web/10001.png",
      "HasDemo": false
    }
  ]
}
```

---

## 二、登入遊戲（§ 2.1.2–2.1.3）

### 2.1 `POST /GameLogin`

取得遊戲啟動連結（推薦使用，支援 JSON 格式）。

#### Signature 規則

```
MD5(GameLogin + RequestDateTime + OperatorId + SecretKey + PlayerId)
```

#### 請求參數

| 欄位 | 類型 | 說明 | 必填 |
|---|---|---|---|
| `OperatorId` | String(20) | 營運商 ID | ✅ |
| `RequestDateTime` | String(50) | UTC 時間 | ✅ |
| `Signature` | String(50) | MD5 簽名 | ✅ |
| `PlayerId` | String(50) | 玩家帳號（英數字與 `. _ $ - ?`，不可有空格） | ✅ |
| `Ip` | String(50) | 玩家 IPv4 地址（來源為玩家 IP，非營運商伺服器 IP） | ✅ |
| `GameCode` | String(50) | 遊戲代碼（傳入 `0` 回傳 Lobby URL） | ✅ |
| `Currency` | String(5) | 幣別代碼（如 `MYR`、`SGD`） | ✅ |
| `Lang` | String(5) | 玩家語言代碼（如 `en-us`） | ❌ |
| `RedirectUrl` | String(100) | 返回大廳按鈕的 Redirect URL | ❌ |
| `AuthToken` | String(200) | 驗證 Token，用於無縫錢包 GetBalance / Bet 回調的身分驗證 | ❌ |
| `LaunchDemo` | Bool | 是否啟動試玩模式，預設 `false` | ❌ |

#### 請求範例

```json
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30T14:16:32",
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

#### 回應參數

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | 狀態碼 |
| `Description` | String(50) | 狀態描述 |
| `ResponseDateTime` | String(50) | UTC 回應時間 |
| `Url` | String(2000) | 遊戲啟動連結 |

#### 回應範例

```json
{
  "Status": 200,
  "Description": "Success",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "Url": "https://<game-url>.com/slot/g/?p=2fht3VYQgXKMaUqxvomKPuz&rd="
}
```

---

### 2.2 `GET /LaunchGame`（URL 參數方式）

以 Query String 方式取得遊戲啟動連結，成功後 HTTP 302 重導至遊戲 URL。

#### Signature 規則

```
MD5(LaunchGame + RD + OI + SecretKey + PI + IP + GC + C)
```

#### 請求參數（Query String）

| 參數 | 類型 | 說明 | 必填 |
|---|---|---|---|
| `OI` | String(20) | OperatorId | ✅ |
| `RD` | String(50) | RequestDateTime `yyyy-MM-ddTHH:mm:ssZ` | ✅ |
| `S` | String(50) | Signature | ✅ |
| `PI` | String(50) | PlayerId | ✅ |
| `IP` | String(50) | 玩家 IPv4 地址 | ✅ |
| `GC` | String(50) | GameCode（傳入 `0` 回傳 Lobby URL） | ✅ |
| `C` | String(5) | Currency | ✅ |
| `L` | String(5) | 語言代碼 | ❌ |
| `RU` | String(100) | RedirectUrl | ❌ |
| `AT` | String(200) | AuthToken | ❌ |
| `LD` | Bool | LaunchDemo | ❌ |

#### 請求範例

```
https://<Operator-Gateway-Url>/?OI=DemoAccount&RD=2014-09-30T14:16:32&S=8d3d050acf123op78a42328215872745&PI=Player001&IP=0.0.0.0&GC=10001&C=MYR&L=en-us&RU=https%3A%2F%2F%3Coperator-lobby-url%example.invalid&AT=0rV2LzVUoLKCM3tbLvTkZW6ElA9...&LD=false
```

#### 回應說明

- **HTTP 302**：成功，`Location` header 指向遊戲 URL
- **HTTP 200**：失敗，回傳 HTML 錯誤頁面

```html
<html>
<body>
  <h1>Error</h1>
  <div name="Status" value="900500">Status: <span>900500</span></div>
  <div name="Description" value="Internal Server Error">Description: <span>Internal Server Error</span></div>
  <div name="ResponseDateTime" value="2025-07-01 08:39:50">ResponseDateTime: <span>2025-07-01 08:39:50</span></div>
</body>
</html>
```

---

## 三、無縫錢包 API（§ 3，遊戲商回調 → 營運商）

> **方向**：以下所有接口均由 **KissH5 遊戲商主動回調至營運商系統**。
> 
> 營運商需提供 HTTPS 服務 URL 給遊戲商，並開放以下 IP 白名單：
> 
> **UAT：** `0.0.0.0`
> 
> **PROD：** `0.0.0.0`、`0.0.0.0`、`0.0.0.0`
> 
> 回調回應時間須在 **3 秒以內**。

---

### 3.1 `POST /GetBalance`

查詢玩家當前餘額。

#### Signature 規則

```
MD5(GetBalance + RequestDateTime + OperatorId + SecretKey + PlayerId)
```

#### 請求參數

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OperatorId` | String(20) | 營運商 ID |
| `RequestDateTime` | String(50) | UTC 時間 |
| `Signature` | String(50) | MD5 簽名 |
| `PlayerId` | String(50) | 玩家 ID |
| `Currency` | String(5) | 幣別代碼 |
| `AuthToken` | String(200) | GameLogin 時傳入的 AuthToken（若有） |

#### 請求範例

```json
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30T14:16:32Z",
  "Signature": "8d3d050acf123op78a42328215872745",
  "PlayerId": "Player001",
  "Currency": "MYR",
  "AuthToken": "0rV2LzVUoLKCM3tbLvTkZW6ElA9jFkrhrm18ABouI27aG2gnEusTiMUfTLe0c1UxwWWBbkErK9qTKhCHxUsvCJUKjSywChcIz11Kx2vkdNwsdT1oU3PIUgZ8dyWQUQeiucw9E0odMvXpFYo3ub"
}
```

#### 回應參數

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | 狀態碼（參考 Operator Error/Status Code Table） |
| `Description` | String(50) | 狀態描述 |
| `ResponseDateTime` | String(50) | UTC 回應時間 |
| `Balance` | Decimal | 玩家可用餘額（如 `1233.5000`） |

#### 回應範例

```json
{
  "Status": 200,
  "Description": "Success",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "Balance": 5123.23
}
```

---

### 3.2 `POST /Bet`

扣除玩家下注金額。**冪等接口**：相同 `BetId` 只建立一筆交易。

#### Signature 規則

```
MD5(Bet + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId)
```

#### 請求參數

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OperatorId` | String(20) | 營運商 ID |
| `RequestDateTime` | String(50) | UTC 時間 |
| `Signature` | String(50) | MD5 簽名 |
| `PlayerId` | String(50) | 玩家 ID |
| `Currency` | String(5) | 幣別代碼 |
| `RoundId` | Long | 遊戲回合唯一 ID（多玩家可共享同一 RoundId） |
| `BetId` | Long | 下注交易唯一 ID，**用於確保交易唯一性** |
| `GameCode` | String(50) | 遊戲代碼 |
| `GameType` | String(50) | 遊戲類型字串（如 `Slots`、`Arcade`） |
| `BetAmount` | Decimal | 下注金額，**用此值更新玩家餘額** |
| `ExchangeRate` | Decimal | 匯率（如 `1.0000`） |
| `TranDateTime` | DateTime | 實際交易 UTC 時間 `yyyy-MM-dd HH:mm:ss`（遊戲商系統時間） |
| `AuthToken` | String(200) | GameLogin 時傳入的 AuthToken（若有） |
| `ProviderTimeZone` | String | 遊戲商時區 |
| `ProviderTranDt` | DateTime | 依 ProviderTimeZone 的實際交易時間 |

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
  "AuthToken": "0rV2LzVUoLKCM3tbLvTkZW6ElA9jFkrhrm18ABouI27aG2gnEusTiMUfTLe0c1UxwWWBbkErK9qTKhCHxUsvCJUKjSywChcIz11Kx2vkdNwsdT1oU3PIUgZ8dyWQUQeiucw9E0odMvXpFYo3ub",
  "ProviderTimeZone": "Singapore Standard Time",
  "ProviderTranDt": "2014-09-30T22:16:32.000+08:00"
}
```

#### 回應參數

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | 狀態碼 |
| `Description` | String(50) | 狀態描述 |
| `ResponseDateTime` | String(50) | UTC 回應時間 |
| `OldBalance` | Decimal | 扣款前餘額 |
| `NewBalance` | Decimal | 扣款後餘額 |

#### 回應範例

```json
{
  "Status": 200,
  "Description": "Success",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "OldBalance": 100.0000,
  "NewBalance": 98.0000
}
```

---

### 3.3 `POST /GameResult`

遊戲回合結算，將派彩金額加回玩家餘額。**冪等接口**：相同 `ResultId` 只建立一筆交易。

#### Signature 規則

```
MD5(GameResult + ResultId + RequestDateTime + OperatorId + SecretKey + PlayerId)
```

#### 請求參數

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OperatorId` | String(20) | 營運商 ID |
| `RequestDateTime` | String(50) | UTC 時間 |
| `Signature` | String(50) | MD5 簽名 |
| `PlayerId` | String(50) | 玩家 ID |
| `Currency` | String(5) | 幣別代碼 |
| `ResultId` | Long | 結算交易唯一 ID，**用於確保交易唯一性**；可用於 GetTransactionDetails |
| `BetId` | Long | 對應的下注交易 ID |
| `RoundId` | Long | 遊戲回合唯一 ID |
| `GameCode` | String(50) | 遊戲代碼 |
| `GameType` | String(50) | 遊戲類型字串 |
| `GameName` | String(50) | 英文遊戲名稱 |
| `ResultType` | Integer | 遊戲結果類型（參考 Game Result Type 表） |
| `BetAmount` | Decimal | 下注金額 |
| `ValidBetAmount` | Decimal | 有效下注金額 |
| `Payout` | Decimal | 派彩金額（可為 0），**用此值更新玩家餘額** |
| `WinLose` | Decimal | 輸贏金額（Payout - Turnover，可為負數） |
| `ExchangeRate` | Decimal | 匯率 |
| `TranDateTime` | DateTime | 實際結算 UTC 時間 |
| `ProviderTimeZone` | String | 遊戲商時區 |
| `ProviderTranDt` | DateTime | 依 ProviderTimeZone 的實際交易時間 |
| `RoundType` | Integer | 回合類型（參考 RoundType 表） |

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

#### 回應參數

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | 狀態碼 |
| `Description` | String(50) | 狀態描述 |
| `ResponseDateTime` | String(50) | UTC 回應時間 |
| `OldBalance` | Decimal | 派彩前餘額 |
| `NewBalance` | Decimal | 派彩後餘額 |

#### 回應範例

```json
{
  "Status": 200,
  "Description": "Success",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "OldBalance": 100.0000,
  "NewBalance": 102.0000
}
```

---

### 3.4 `POST /Rollback`

下注取消退款（遊戲未完成時）。**冪等接口**：相同 `BetId` 只建立一筆退款交易。

#### Signature 規則

```
MD5(Rollback + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId)
```

#### 請求參數

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OperatorId` | String(20) | 營運商 ID |
| `RequestDateTime` | String(50) | UTC 時間 |
| `Signature` | String(50) | MD5 簽名 |
| `PlayerId` | String(50) | 玩家 ID |
| `Currency` | String(5) | 幣別代碼 |
| `RoundId` | Long | 遊戲回合唯一 ID |
| `BetId` | Long | 對應的下注交易 ID，**用於確保交易唯一性** |
| `GameCode` | String(50) | 遊戲代碼 |
| `GameType` | String(50) | 遊戲類型字串 |
| `BetAmount` | Decimal | 退款金額，**用此值更新玩家餘額** |
| `ExchangeRate` | Decimal | 匯率 |
| `TranDateTime` | DateTime | 實際交易 UTC 時間 |
| `RollbackType` | Integer | 退款類型（參考 Rollback Type 表） |
| `ProviderTimeZone` | String | 遊戲商時區 |
| `ProviderTranDt` | DateTime | 依 ProviderTimeZone 的實際交易時間 |

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

#### 回應參數

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | 狀態碼 |
| `Description` | String(50) | 狀態描述 |
| `ResponseDateTime` | String(50) | UTC 回應時間 |
| `OldBalance` | Decimal | 退款前餘額 |
| `NewBalance` | Decimal | 退款後餘額 |

#### 回應範例

```json
{
  "Status": 200,
  "Description": "Success",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "OldBalance": 100.0000,
  "NewBalance": 98.0000
}
```

---

### 3.5 `POST /CashBonus`

通知營運商玩家獲得現金獎勵（錦標賽、活動、現金掉落等）。**冪等接口**：相同 `TranId` 只建立一筆交易。

#### Signature 規則

```
MD5(CashBonus + TranId + RequestDateTime + OperatorId + SecretKey + PlayerId)
```

#### 請求參數

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OperatorId` | String(20) | 營運商 ID |
| `RequestDateTime` | String(50) | UTC 時間 |
| `Signature` | String(50) | MD5 簽名 |
| `PlayerId` | String(50) | 玩家 ID |
| `Currency` | String(5) | 幣別代碼 |
| `TranId` | String(25) | 獎勵交易唯一 ID，**用於確保交易唯一性** |
| `BonusId` | String(50) | 獎勵類型唯一代碼 |
| `BonusName` | String(50) | 現金獎勵名稱 |
| `BonusType` | Integer | 現金獎勵類型（參考 Bonus Type 表） |
| `Payout` | Decimal | 派彩金額，**用此值更新玩家餘額** |
| `ExchangeRate` | Decimal | 匯率 |
| `TranDateTime` | DateTime | 實際交易 UTC 時間 |
| `Result` | String(100) | 現金獎勵結果描述 |
| `ProviderTimeZone` | String | 遊戲商時區 |
| `ProviderTranDt` | DateTime | 依 ProviderTimeZone 的實際交易時間 |

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

#### 回應參數

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | 狀態碼 |
| `Description` | String(50) | 狀態描述 |
| `ResponseDateTime` | String(50) | UTC 回應時間 |
| `OldBalance` | Decimal | 加款前餘額 |
| `NewBalance` | Decimal | 加款後餘額 |

#### 回應範例

```json
{
  "Status": 200,
  "Description": "Success",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "OldBalance": 100.0000,
  "NewBalance": 200.0000
}
```

---

### 3.6 `POST /Jackpot`

通知營運商玩家贏得 Jackpot 獎金。**冪等接口**：相同 `TranId` 只建立一筆交易。

#### Signature 規則

```
MD5(Jackpot + TranId + RequestDateTime + OperatorId + SecretKey + PlayerId)
```

#### 請求參數

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OperatorId` | String(20) | 營運商 ID |
| `RequestDateTime` | String(50) | UTC 時間 |
| `Signature` | String(50) | MD5 簽名 |
| `PlayerId` | String(50) | 玩家 ID |
| `Currency` | String(5) | 幣別代碼 |
| `TranId` | String(25) | Jackpot 交易唯一 ID，**用於確保交易唯一性** |
| `JackpotId` | String(50) | Jackpot 類型唯一代碼 |
| `Payout` | Decimal | 派彩金額，**用此值更新玩家餘額** |
| `ExchangeRate` | Decimal | 匯率 |
| `TranDateTime` | DateTime | 實際交易 UTC 時間 |
| `Result` | String(100) | Jackpot 結果描述 |
| `ProviderTimeZone` | String | 遊戲商時區 |
| `ProviderTranDt` | DateTime | 依 ProviderTimeZone 的實際交易時間 |

#### 請求範例

```json
{
  "OperatorId": "DemoAccount",
  "RequestDateTime": "2014-09-30T14:16:32Z",
  "Signature": "8d3d050acf123op78a42328215872745",
  "PlayerId": "Player001",
  "Currency": "MYR",
  "TranId": 438665,
  "JackpotId": "JP001",
  "Payout": 200.0000,
  "ExchangeRate": 1.0000,
  "TranDateTime": "2014-09-30 14:16:32",
  "Result": "Minor Jackpot",
  "ProviderTimeZone": "Singapore Standard Time",
  "ProviderTranDt": "2014-09-30T22:16:32.000+08:00"
}
```

#### 回應參數

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | 狀態碼 |
| `Description` | String(50) | 狀態描述 |
| `ResponseDateTime` | String(50) | UTC 回應時間 |
| `OldBalance` | Decimal | 加款前餘額 |
| `NewBalance` | Decimal | 加款後餘額 |

#### 回應範例

```json
{
  "Status": 200,
  "Description": "Success",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "OldBalance": 100.0000,
  "NewBalance": 200.0000
}
```

---

### 3.7 `POST /BetNSettle`

下注並結算合併為單一請求（適用於即時結算類遊戲）。**冪等接口**：相同 `BetId` 只建立一筆交易。

#### Signature 規則

```
MD5(BetNSettle + ResultId + RequestDateTime + OperatorId + SecretKey + PlayerId)
```

#### 請求參數

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OperatorId` | String(20) | 營運商 ID |
| `RequestDateTime` | String(50) | UTC 時間 |
| `Signature` | String(50) | MD5 簽名 |
| `PlayerId` | String(50) | 玩家 ID |
| `Currency` | String(5) | 幣別代碼 |
| `ResultId` | Long | 結算交易唯一 ID，**用於確保交易唯一性** |
| `BetId` | Long | 下注交易唯一 ID |
| `RoundId` | Long | 遊戲回合唯一 ID |
| `GameCode` | String(50) | 遊戲代碼 |
| `GameType` | String(50) | 遊戲類型字串 |
| `GameName` | String(50) | 英文遊戲名稱 |
| `ResultType` | Integer | 遊戲結果類型 |
| `BetAmount` | Decimal | 下注金額 |
| `ValidBetAmount` | Decimal | 有效下注金額 |
| `Payout` | Decimal | 派彩金額（可為 0），**用此值更新玩家餘額** |
| `WinLose` | Decimal | 輸贏金額（可為負數） |
| `ExchangeRate` | Decimal | 匯率 |
| `TranDateTime` | DateTime | 實際交易 UTC 時間 |
| `ProviderTimeZone` | String | 遊戲商時區 |
| `ProviderTranDt` | DateTime | 依 ProviderTimeZone 的實際交易時間 |
| `RoundType` | Integer | 回合類型 |
| `AuthToken` | String(200) | GameLogin 時傳入的 AuthToken（若有） |

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
  "RoundType": 0,
  "AuthToken": "0rV2LzVUoLKCM3tbLvTkZW6ElA9jFkrhrm18ABouI27aG2gnEusTiMUfTLe0c1UxwWWBbkErK9qTKhCHxUsvCJUKjSywChcIz11Kx2vkdNwsdT1oU3PIUgZ8dyWQUQeiucw9E0odMvXpFYo3ub"
}
```

#### 回應參數

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | 狀態碼 |
| `Description` | String(50) | 狀態描述 |
| `ResponseDateTime` | String(50) | UTC 回應時間 |
| `OldBalance` | Decimal | 結算前餘額 |
| `NewBalance` | Decimal | 結算後餘額 |

#### 回應範例

```json
{
  "Status": 200,
  "Description": "Success",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "OldBalance": 100.0000,
  "NewBalance": 102.0000
}
```

---

### 3.8 `POST /CancelBetNSettle`

確保 BetNSettle 結果成功傳送至營運商。若遊戲回合已存在則回應成功，若有錯誤則持續重試直到收到成功回應。

> **注意**：BetNSettle 建立後**無法 void、cancel 或 rollback**，遊戲商會持續重試直到營運商回傳派彩成功。

#### Signature 規則

```
MD5(CancelBetNSettle + ResultId + RequestDateTime + OperatorId + SecretKey + PlayerId)
```

#### 請求參數

| 欄位 | 類型 | 說明 |
|---|---|---|
| `OperatorId` | String(20) | 營運商 ID |
| `RequestDateTime` | String(50) | UTC 時間 |
| `Signature` | String(50) | MD5 簽名 |
| `PlayerId` | String(50) | 玩家 ID |
| `Currency` | String(5) | 幣別代碼 |
| `ResultId` | Long | 結算交易唯一 ID，**用於確保交易唯一性** |
| `BetId` | Long | 下注交易唯一 ID |
| `RoundId` | Long | 遊戲回合唯一 ID |
| `GameCode` | String(50) | 遊戲代碼 |
| `GameType` | String(50) | 遊戲類型字串 |
| `GameName` | String(50) | 英文遊戲名稱 |
| `ResultType` | Integer | 遊戲結果類型 |
| `BetAmount` | Decimal | 下注金額 |
| `ValidBetAmount` | Decimal | 有效下注金額 |
| `Payout` | Decimal | 派彩金額（可為 0），**用此值更新玩家餘額** |
| `WinLose` | Decimal | 輸贏金額（可為負數） |
| `ExchangeRate` | Decimal | 匯率 |
| `TranDateTime` | DateTime | 實際交易 UTC 時間 |
| `ProviderTimeZone` | String | 遊戲商時區 |
| `ProviderTranDt` | DateTime | 依 ProviderTimeZone 的實際交易時間 |
| `RoundType` | Integer | 回合類型 |

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

#### 回應參數

| 欄位 | 類型 | 說明 |
|---|---|---|
| `Status` | Integer | 狀態碼 |
| `Description` | String(50) | 狀態描述 |
| `ResponseDateTime` | String(50) | UTC 回應時間 |
| `OldBalance` | Decimal | 結算前餘額 |
| `NewBalance` | Decimal | 結算後餘額 |

#### 回應範例

```json
{
  "Status": 200,
  "Description": "Success",
  "ResponseDateTime": "2016-09-01 09:41:37",
  "OldBalance": 100.0000,
  "NewBalance": 102.0000
}
```

---

## 四、無縫錢包下注流程（§ 3.3）

```
玩家下注
  → [Provider] GET GetBalance（確認餘額充足）
  → [Provider] POST Bet（扣款）
  → [Provider 處理遊戲邏輯]
  → [Provider] POST GameResult（結算派彩）
  → 玩家看到結果
```

> 營運商必須確保：
> 1. 使用對應欄位（`BetId`、`ResultId`、`TranId`）做**冪等性檢查**，相同 ID 不得重複處理。
> 2. 回調回應時間需在 **3 秒以內**。

---

## 五、對帳與重試機制（§ 5）

> 當請求因網路逾時或錯誤碼而失敗時，遊戲商系統會依以下規則進行重試對帳。
>
> **重要**：營運商必須確保相同 `BetId`、`ResultId`、`TranId` 的交易**不被重複處理**，可回傳 `900409 Duplicate Transaction` 來終止重試。

### 5.1 Bet（下注）失敗

遊戲商僅嘗試一次。若失敗，**直接觸發 Rollback**，不會對 Bet 進行重試或對帳。

### 5.2 Rollback（退款）失敗

1. 遊戲商嘗試 **3 次**後仍無法收到正確回應。
2. 將請求放入背景排程，**每小時自動重試**一次。
3. 營運商可回傳「退款成功」或「交易不存在」來終止重試流程。

### 5.3 GameResult（結算）失敗

1. 遊戲商嘗試 **3 次**後仍無法送達。
2. 進入背景排程，**每小時自動重試**。
3. 結算交易**不可取消、不可 void**，遊戲商會持續重試直到成功。

### 5.4 Bonus / Jackpot 失敗

與 GameResult 相同的重試機制（3 次後每小時重試）。

---

> **實作要點**：同一筆交易在對帳期間可能重複送達，務必以對應的唯一 ID（`BetId`、`ResultId`、`TranId`）做冪等性檢查，已處理過的交易回傳 `900409` 即可終止重試。

### 5.5 Rollback 與 GameResult 互斥

Rollback 與 GameResult 針對同一筆 `BetId` **不會同時發生**，兩者走的是互斥路徑：

```
Bet 成功
  ├─ 遊戲正常完成 → GameResult（結算，不可取消）
  └─ 遊戲未完成   → Rollback（退款，Bet 失敗時直接觸發）
```

因此：
- 收到 GameResult 的訂單，**不可能**再收到 Rollback
- 若收到對已結算訂單發出的 Rollback，屬於異常情況，應回傳 `900415 Bet Transaction Not Found` 拒絕處理

## 六、附錄（§ 6–7）

### Signature 規則彙整

| 接口 | Signature MD5 組成 |
|---|---|
| GetGameList | `GetGameList + RequestDateTime + OperatorId + SecretKey` |
| GameLogin | `GameLogin + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| LaunchGame (GET) | `LaunchGame + RD + OI + SecretKey + PI + IP + GC + C` |
| GetBalance | `GetBalance + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| Bet | `Bet + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| GameResult | `GameResult + ResultId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| Rollback | `Rollback + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| CashBonus | `CashBonus + TranId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| Jackpot | `Jackpot + TranId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| BetNSettle | `BetNSettle + ResultId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| CancelBetNSettle | `CancelBetNSettle + ResultId + RequestDateTime + OperatorId + SecretKey + PlayerId` |

---

### 7.1 支援語言代碼

| 語言 | Language Code |
|---|---|
| English | `en-us` |
| Chinese (Simplified) | `zh-cn` |
| Chinese (Traditional) | `zh-tw` |
| Chinese (Traditional HK) | `zh-hk` |
| Thai | `th-th` |
| Vietnamese | `vi-vn` |
| Tagalog (Philippines) | `fil-ph` |
| Indonesian | `id-id` |
| Malay | `ml-my` |
| Burmese (Myanmar) | `my-mm` |
| Khmer (Cambodia) | `km-kh` |
| Japanese | `ja-jp` |
| Korean | `ko-kr` |
| Hindi (India) | `hi-in` |
| Bengali (Bangladesh) | `bn-bl` |
| Nepali | `ne-ne` |
| Urdu (Pakistan) | `ur-pk` |
| Sinhala (Sri Lanka) | `si-sl` |
| Portuguese | `pt-pt` |
| Portuguese (Brazil) | `pt-br` |
| German | `de-de` |
| Spanish | `es-es` |
| French | `fr-fr` |
| Italian | `it-it` |
| Russian | `ru-ru` |
| Dutch | `nl-nl` |
| Swedish | `sv-se` |
| Finnish | `fi-fl` |
| Norwegian | `nb-no` |
| Danish | `da-dk` |
| Irish | `ga-ie` |
| Maltese | `mt-mt` |

---

### 7.2 支援幣別代碼

| 幣別代碼 | 說明 |
|---|---|
| `MYR` | Malaysia Ringgit |
| `MY1` | Malaysia Ringgit (Truncated, 1 MY1 = 0.01 MYR) |
| `THB` | Thai Baht |
| `SGD` | Singapore Dollar |
| `USD` | United States Dollar |
| `USDT` | Crypto USD Tether |
| `VND` | Vietnamese Dong |
| `VNK` | Vietnamese Dong (Truncated, 1 VNK = 1000 VND) |
| `IDR` | Indonesian Rupiah |
| `IDK` | Indonesian Rupiah (Truncated, 1 IDK = 1000 IDR) |
| `MMK` | Myanmar Kyat |
| `MK1` | Myanmar Kyat (Truncated, 1 MK1 = 100 MMK) |
| `MKK` | Myanmar Kyat (Truncated, 1 MKK = 1000 MMK) |
| `HKD` | Hong Kong Dollar |
| `TWD` | Taiwan Dollar |
| `RMB` | RenMinBi |
| `JPY` | Japan Yen |
| `KRW` | Korean Won |
| `KRK` | Korean Won (Truncated, 1 KRK = 100 KRW) |
| `PHP` | Philippine Peso |
| `INR` | Indian Rupee |
| `BDT` | Bangladeshi Taka |
| `PKR` | Pakistani Rupee |
| `NPR` | Nepalese Rupee |
| `LKR` | Sri Lankan Rupee |
| `KHR` | Cambodian Riel |
| `KHK` | Cambodian Riel (Truncated, 1 KHK = 1000 KHR) |
| `LAK` | Laotian Kip |
| `LKK` | Laotian Kip (Truncated, 1 LKK = 1000 LAK) |
| `AUD` | Australian Dollar |
| `NZD` | New Zealand Dollar |
| `CAD` | Canadian Dollar |
| `GBP` | Great Britain Pound |
| `EUR` | Euro |
| `CHF` | Swiss Franc |
| `SEK` | Swedish Krona |
| `NOK` | Norwegian Krone |
| `DKK` | Danish Krone |
| `RUB` | Russian Ruble |
| `TRY` | Turkish Lira |
| `AED` | UAE Dirham |
| `BRL` | Brazilian Real |
| `MXN` | Mexican Peso |
| `CLP` | Chilean Peso |
| `COP` | Colombian Peso |
| `PEN` | Nuevo Sol (Peruvian Sol) |
| `ARS` | Argentine Peso |
| `UYU` | Peso Uruguayo |
| `PYG` | Guarani |
| `BOB` | Boliviano |
| `GTQ` | Guatemalan Quetzal |
| `NGN` | Nigeria Naira |
| `GHS` | Ghana Cedi |
| `ZAR` | South African Rand |
| `KES` | Kenyan Shilling |
| `UGX` | Uganda Shilling |
| `TZS` | Tanzanian Shilling |
| `ZMW` | Zambian Kwacha |
| `AOA` | Angolan Kwanza |
| `CDF` | Congolese Franc |
| `XOF` | CFA Franc BCEAO |
| `XAF` | CFA Franc BEAC |
| `CFA` | West African CFA franc |
| `EGP` | Egyptian Pound |
| `DZD` | Algerian Dinar |
| `MAD` | Moroccan Dirham |
| `TND` | Tunisian Dinar |
| `SDG` | Sudanese Pound |
| `ETB` | Ethiopian Birr |
| `GNF` | Guinean Franc |
| `SLL` | Sierra Leonean Leone |
| `SOS` | Somali Shilling |
| `IRR` | Iranian Rial |
| `AZN` | Azerbaijani Manat |
| `AMD` | Armenian Dram |
| `TJS` | Somoni |
| `TMT` | Turkmenistan New Manat |
| `UZS` | Uzbekistan Sum |
| `CZK` | Czech Koruna |
| `PGK` | Papua New Guinean Kina |
| `HNL` | Honduran Lempira |
| `MBT` | Minebit |
| `LTC` | Litecoin |
| `FUN` | Demo Currency |

---

### 7.3 狀態碼 / 錯誤碼

#### 7.3.1 遊戲商回傳給營運商（Provider → Operator）

| 狀態碼 | 說明 |
|---|---|
| `200` | OK |
| `400` | Bad Request |
| `403` | Forbidden Access |
| `503` | Service Maintenance |
| `504` | Game Maintenance |
| `900300` | Not Eligible |
| `900401` | Invalid Operator ID |
| `900402` | Invalid Player |
| `900403` | Invalid Signature |
| `900404` | Invalid Game Id |
| `900405` | Insufficient Balance |
| `900408` | Invalid TranID |
| `900500` | Internal Server Error（其他未列情境） |

#### 7.3.2 營運商回傳給遊戲商（Operator → Provider，無縫錢包回調用）

| 狀態碼 | 說明 |
|---|---|
| `200` | OK |
| `900404` | Invalid player / password |
| `900405` | Operator ID Error |
| `900406` | Incoming Request Info Incomplete |
| `900407` | Invalid Signature |
| `900409` | Duplicate Transaction（冪等：已處理過相同 ID 的交易） |
| `900415` | Bet Transaction Not Found |
| `900416` | Player Inactive |
| `900417` | CancelBetNSettle Rejected |
| `900605` | Insufficient Balance |
| `900500` | Internal Server Error |
| `800401` | Max Payout Reached（玩家已達最高派彩上限） |

---

### 7.4 遊戲類型（Game Type）

| 值 | 說明 |
|---|---|
| `0` | Others |
| `1` | Slots |
| `2` | Arcade |
| `3` | Table |
| `4` | Event |
| `5` | Mini Game |
| `6` | Fish Arcade |
| `101` | Cash Bonus（Deprecated） |

---

### 7.5 遊戲結果類型（Game Result Type）

| 值 | 說明 |
|---|---|
| `0` | Single / Quick Game Round Result |

---

### 7.6 退款類型（Rollback Type）

| 值 | 說明 |
|---|---|
| `1` | Single / Quick Game Refund |

---

### 7.7 回合類型（Round Type）

| 值 | 說明 |
|---|---|
| `0` | Normal Round |
| `1` | Buy FreeSpin / Feature Round |

---

### 7.8 交易類型（Transaction Type）

| 值 | 說明 |
|---|---|
| `0` | Base |
| `2` | Bonus |

---

### 7.9 獎勵類型（Bonus Type）

| 值 | 說明 |
|---|---|
| `0` | None |
| `2` | Angpao |
| `3` | Event |
| `4` | FreeRound |

---

### 7.10 Result 格式

| 條件 | 格式 |
|---|---|
| BonusType = `4`（FreeRound） | `gameName\|minLineBet\|noOfLine\|betValue\|gameId\|freeRoundId` |
| 其他 | `gameName\|minLineBet\|noOfLine\|betValue` |

---

### 7.11 MD5 簽名實作範例

#### PHP

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

