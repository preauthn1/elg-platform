# Pragmatic Play — Seamless Wallet API 整合文件

> 原始規格版本：API v3.243（2026 年 4 月）  
> 本文僅整理 **Section III. Seamless Wallet API** 相關內容，供後端串接參考。

---

## 目錄

1. [概述](#1-概述)
2. [Hash 計算](#2-hash-計算)
3. [資料型別](#3-資料型別)
4. [Bonus Wallet 機制](#4-bonus-wallet-機制)
5. [API 方法總表](#5-api-方法總表)
6. [3.4 Authenticate](#6-authenticate)
7. [3.5 Balance](#7-balance)
8. [3.6 Bet](#8-bet)
9. [3.7 Result](#9-result)
10. [3.8 BonusWin](#10-bonuswin)
11. [3.9 JackpotWin](#11-jackpotwin)
12. [3.10 EndRound](#12-endround)
13. [3.11 Refund](#13-refund)
14. [3.13 PromoWin](#14-promowin)
15. [錯誤碼](#15-錯誤碼)
16. [重要注意事項](#16-重要注意事項)

---

## 1. 概述

Seamless Wallet API 由**運營商（Casino Operator）實作並提供 URL**，Pragmatic Play 在玩家下注或獲勝時主動呼叫。

- 請求格式：`application/x-www-form-urlencoded` POST
- 回應格式：JSON
- 傳輸方向：**PP → 運營商**（PP 呼叫運營商的 API）

```
Content-Type: application/x-www-form-urlencoded
Content-Type: application/x-www-form-urlencoded; charset=UTF-8
```

---

## 2. Hash 計算

每個請求都包含 `hash` 欄位，用於驗證請求合法性。計算步驟：

1. 取出所有 POST 參數，**排除 `hash` 欄位本身**
2. 按 key 的**字母順序**排序
3. 組成 `key1=value1&key2=value2` 格式的字串
4. 在字串末尾**直接附加 Secret Key**（不加 `&`）
5. 對整個字串計算 **MD5**（小寫 32 字元）
6. 與請求中的 `hash` 比對，不符合回傳錯誤碼 `5`

```
baseString = "amount=100.0&gameId=vs50aladdin&..."
toHash     = baseString + SECRET_KEY
hash       = md5(toHash)
```

---

## 3. 資料型別

| 參數 | 型別 | 說明 |
|---|---|---|
| `userId` | String(100) | 運營商系統內的玩家唯一識別碼，**區分大小寫** |
| `currency` | String(3) | ISO 4217 貨幣代碼，如 `EUR`、`USD` |
| `country` | String(2) | ISO 3166-1 alpha-2 國家代碼，如 `GB`、`CN` |
| `cash` | Decimal(10,2) | 玩家真實餘額，如 `100.00` |
| `bonus` | Decimal(10,2) | 玩家 Bonus 餘額，如 `100.00` |
| `amount` | Decimal(10,2) | 投注或派彩金額，如 `1.00` |
| `usedPromo` | Decimal(10,2) | 本次下注中從 Bonus 餘額扣除的金額 |
| `reference` | String(32) | 交易唯一識別碼 |
| `transactionId` | Varchar(32) | 運營商系統的交易 ID（回應時使用） |
| `roundId` | Long | 回合識別碼 |
| `jackpotId` | Long | Jackpot 識別碼 |
| `gameId` | String(20) | 遊戲識別碼，如 `vs25ultwolgol`、`vs50aladdin` |
| `providerId` | String(32) | 遊戲商識別碼，由 PP 提供 |
| `timestamp` | Long | Unix epoch 時間（**毫秒**），如 `1470926696715` |
| `roundDetails` | String(4000) | 回合附加資訊，如 `spin`、`spin,bonusBuy` |
| `bonusCode` | String | 運營商 Bonus 唯一識別碼（FSB / Free Chips 必填） |
| `campaignId` | String | 活動識別碼 |
| `campaignType` | String(3) | 活動類型：`T`（Tournament）、`M`（Missions）、`CJP`、`CB`（Cashback）、`MR`（Prize Drop） |
| `platform` | String(10) | 平台類型：`MOBILE` 或 `WEB` |

---

## 4. Bonus Wallet 機制

PP 支援「Bonus Wallet」功能，允許運營商將下注金額分為「Real（現金）」與「Bonus（紅利）」兩部分。

- `bonus`：玩家當前可用的 Bonus 餘額
- `usedPromo`：本次 Bet 中從 Bonus 餘額扣除的金額（僅在 Bet 回應中使用）

**範例**：玩家現金 100、Bonus 100，下注 10 元：

| 回應 | 說明 |
|---|---|
| `cash: 90, bonus: 100, usedPromo: 0` | 全部使用現金支付 |
| `cash: 100, bonus: 90, usedPromo: 10` | 全部使用 Bonus 支付 |
| `cash: 95, bonus: 95, usedPromo: 5` | 現金 5 + Bonus 5 混合支付 |

> **重要**：`bonus` 和 `usedPromo` 在有要求的回應中為必填，無 Bonus 時必須填 `0`，不可省略或為 null。

---

## 5. API 方法總表

| 方法 | 路徑 | 狀態 | 說明 |
|---|---|---|---|
| **Authenticate** | `POST /authenticate.html` | Required | 透過 token 驗證玩家身份，取得餘額 |
| **Balance** | `POST /balance.html` | Required | 查詢玩家當前餘額 |
| **Bet** | `POST /bet.html` | Required | 扣款投注，幂等 |
| **Result** | `POST /result.html` | Required | 派彩，加入餘額 |
| **BonusWin** | `POST /bonusWin.html` | Required | Free Spins / Free Chips 總獎金派發，**必須加入餘額** |
| **JackpotWin** | `POST /jackpotWin.html` | Required | 通知 Jackpot 得獎，加入餘額 |
| **EndRound** | `POST /endRound.html` | Optional | 回合結束通知，用於交易最終確認 |
| **Refund** | `POST /refund.html` | Required | 退款，還原投注金額 |
| **GetBalancePerGame** | `POST /getBalancePerGame.html` | Optional | 查詢特定遊戲可用餘額 |
| **PromoWin** | `POST /promoWin.html` | Required | 活動獎金通知（Tournament/Missions/CJP/Cashback 等） |
| **SessionExpired** | `POST /session/expired` | Optional | 玩家 session 過期通知 |
| **Adjustment** | `POST /adjustment.html` | Optional | 餘額調整（僅限 Live Casino） |
| **RoundDetails** | `POST /roundDetails.html` | Optional | 傳送回合詳細資料（需啟用 EndRound） |

---

## 6. Authenticate

**`POST /authenticate.html`**

玩家開啟遊戲時，PP 以 URL 中的 token 向運營商驗證玩家身份並取得初始餘額。

> 同一 token 可能在玩家從 mini lobby 開啟其他遊戲時被重複使用，運營商應接受有效 token 的後續 authenticate 請求。

### Request

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `hash` | String | Required | Hash 驗證碼 |
| `token` | String | Required | 玩家的一次性安全 token |
| `providerId` | String | Required | 遊戲商識別碼 |
| `gameId` | String | Optional | 遊戲 ID（需申請啟用） |
| `ipAddress` | String | Optional | 玩家 IP（需申請啟用） |

### Response

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `userId` | String | Required | 運營商系統內的玩家 ID |
| `currency` | String | Required | 玩家幣種 |
| `cash` | Decimal | Required | 玩家現金餘額 |
| `bonus` | Decimal | Required | 玩家 Bonus 餘額 |
| `error` | Integer | Required | 回應狀態碼，0 為成功 |
| `description` | String | Required | 狀態說明 |

> **重要**：玩家幣種在首次 Authenticate 時確定，之後**不可變更**。

```json
{
  "userId": "421",
  "currency": "USD",
  "cash": 99999.99,
  "bonus": 99.99,
  "error": 0,
  "description": "Success"
}
```

---

## 7. Balance

**`POST /balance.html`**

查詢玩家當前餘額，用於在遊戲中顯示最新餘額。

### Request

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `hash` | String | Required | Hash 驗證碼 |
| `providerId` | String | Required | 遊戲商識別碼 |
| `userId` | String | Required | 玩家 ID |
| `token` | String | Optional | 玩家 token（需申請啟用） |

### Response

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `currency` | String | Required | 玩家幣種 |
| `cash` | Decimal | Required | 玩家現金餘額 |
| `bonus` | Decimal | Required | 玩家 Bonus 餘額 |
| `totalBalance` | Decimal | Optional | 玩家全平台總餘額（義大利市場使用） |
| `error` | Integer | Required | 回應狀態碼 |
| `description` | String | Required | 狀態說明 |

```json
{
  "currency": "USD",
  "cash": 99999.99,
  "bonus": 99.99,
  "error": 0,
  "description": "Success"
}
```

---

## 8. Bet

**`POST /bet.html`**

扣款投注，從玩家餘額中扣除投注金額。

> **重要：幂等性**：相同 reference 重複呼叫只建立一筆交易，重試時應回傳實際餘額。

> **重要：Multi-Bet**：同一 `roundId` 可能收到多個不同 `reference` 的 Bet 請求（一個回合多次下注），每次均須扣款並回傳更新後餘額。

### Request

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `hash` | String | Required | Hash 驗證碼 |
| `userId` | String | Required | 玩家 ID |
| `gameId` | String | Required | 遊戲 ID |
| `roundId` | String | Required | 回合 ID |
| `amount` | Decimal | Required | 投注金額（最小 0.00） |
| `reference` | String | Required | 本次交易唯一識別碼 |
| `providerId` | String | Required | 遊戲商識別碼 |
| `timestamp` | Long | Required | 交易時間（毫秒 epoch） |
| `roundDetails` | String | Required | 回合附加資訊 |
| `bonusCode` | String | Optional* | Bonus ID（FSB / Free Chips 必填） |
| `jackpotContribution` | Decimal | Optional | Jackpot 貢獻金額 |
| `jackpotId` | Long | Optional | 活躍 Jackpot ID |
| `platform` | String | Optional | `MOBILE` 或 `WEB` |

### Response

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `transactionId` | String | Required | 運營商交易 ID |
| `currency` | String | Required | 玩家幣種 |
| `cash` | Decimal | Required | 扣款後現金餘額 |
| `bonus` | Decimal | Required | 扣款後 Bonus 餘額 |
| `usedPromo` | Decimal | Required | 從 Bonus 扣除的金額（無則填 0） |
| `error` | Integer | Required | 回應狀態碼 |
| `description` | String | Required | 狀態說明 |

```json
{
  "transactionId": "1482429190474",
  "currency": "USD",
  "cash": 99899.99,
  "bonus": 99.99,
  "usedPromo": 0,
  "error": 0,
  "description": "Success"
}
```

---

## 9. Result

**`POST /result.html`**

派彩，將獲勝金額加入玩家餘額。

> **重要：幂等性**：相同 reference 重複呼叫只建立一筆交易，重試時應回傳實際餘額。

> `promoWinAmount` 若存在，**必須一同加入玩家現金餘額**（Prize Drop / Bonus Card 活動獎金）。

### Request

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `hash` | String | Required | Hash 驗證碼 |
| `userId` | String | Required | 玩家 ID |
| `gameId` | String | Required | 遊戲 ID |
| `roundId` | String | Required | 回合 ID |
| `amount` | Decimal | Required | 獲勝金額 |
| `reference` | String | Required | 本次交易唯一識別碼（與 Bet reference **不同**） |
| `providerId` | String | Required | 遊戲商識別碼 |
| `timestamp` | Long | Required | 交易時間（毫秒 epoch） |
| `roundDetails` | String | Required | 回合附加資訊 |
| `promoWinAmount` | Decimal | Optional | 活動獎金金額（Prize Drop / Bonus Card），**需加入現金餘額** |
| `promoWinReference` | String | Optional | 活動交易識別碼（與 promoWinAmount 同時出現） |
| `promoCampaignID` | String | Optional | 活動 ID（與 promoWinAmount 同時出現） |
| `promoCampaignType` | String | Optional | 活動類型：`R`（Prize Drop）、`C`（Bonus Card） |
| `bonusCode` | String | Optional* | Bonus ID（FSB / Free Chips 必填） |
| `platform` | String | Optional | `MOBILE` 或 `WEB` |

### Response

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `transactionId` | String | Required | 運營商交易 ID |
| `currency` | String | Required | 玩家幣種 |
| `cash` | Decimal | Required | 派彩後現金餘額 |
| `bonus` | Decimal | Required | 派彩後 Bonus 餘額 |
| `error` | Integer | Required | 回應狀態碼 |
| `description` | String | Required | 狀態說明 |

```json
{
  "transactionId": "1482429805253",
  "currency": "USD",
  "cash": 99809.99,
  "bonus": 99.99,
  "error": 0,
  "description": "Success"
}
```

---

## 10. BonusWin

**`POST /bonusWin.html`**

Free Spins / Live Casino Free Chips 活動**所有回合結束後**，PP 以此方法通知運營商總獎金。

> **重要：必須入帳**：Casino Operator **必須將 `amount` 加入玩家餘額**並回傳更新後餘額。

> **重要：非同步**：此呼叫與回合結束**不直接掛鉤**（非同步發送），不一定在 EndRound 之後立即到達。

> **重要：幂等性**：相同 reference 重複呼叫只建立一筆交易。

### Request

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `hash` | String | Required | Hash 驗證碼 |
| `userId` | String | Required | 玩家 ID |
| `amount` | Decimal | Required | 總獎金金額（最小 0.00，為 0 視為虧損） |
| `reference` | String | Required | 本次交易唯一識別碼 |
| `providerId` | String | Required | 遊戲商識別碼 |
| `timestamp` | Long | Required | 交易時間（毫秒 epoch） |
| `bonusCode` | String | Required* | Bonus ID（FSB / Free Chips 場景必填） |
| `roundId` | String | Optional | 最後一個回合的 ID（需申請啟用） |
| `gameId` | String | Optional | 遊戲 ID（需申請啟用） |
| `requestId` | String | Optional | FSB / Free Chips credit request 唯一識別碼 |
| `remainAmount` | Decimal | Optional | 剩餘免費次數（需申請啟用） |

### Response

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `transactionId` | String | Required | 運營商交易 ID |
| `currency` | String | Required | 玩家幣種 |
| `cash` | Decimal | Required | 入帳後現金餘額 |
| `bonus` | Decimal | Required | 入帳後 Bonus 餘額 |
| `error` | Integer | Required | 回應狀態碼 |
| `description` | String | Required | 狀態說明 |

```json
{
  "transactionId": "1482490793341",
  "currency": "USD",
  "cash": 99710.04,
  "bonus": 99.99,
  "error": 0,
  "description": "Success"
}
```

---

## 11. JackpotWin

**`POST /jackpotWin.html`**

Jackpot 得獎通知，運營商須處理交易並將獎金加入玩家餘額。

> **重要**：Slot Jackpot 中，progressive 與 non-progressive 得獎金額合併在 `amount` 欄位一起傳送。

> **重要：幂等性**：相同 reference 重複呼叫只建立一筆交易。

### Request

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `hash` | String | Required | Hash 驗證碼 |
| `providerId` | String | Required | 遊戲商識別碼 |
| `timestamp` | Long | Required | 交易時間（毫秒 epoch） |
| `userId` | String | Required | 玩家 ID |
| `gameId` | String | Required | 遊戲 ID |
| `roundId` | String | Required | 回合 ID |
| `jackpotId` | String | Required | Jackpot ID |
| `amount` | Decimal | Required | Jackpot 總獎金 |
| `reference` | String | Required | 本次交易唯一識別碼 |
| `jackpotDetails` | String | Optional | 各 tier Jackpot 詳細金額（JSON，需申請啟用） |
| `balanceBeforeWin` | Decimal | Optional | 得獎前餘額（需申請啟用） |
| `balanceAfterWin` | Decimal | Optional | 得獎後餘額（需申請啟用） |
| `instanceId` | String | Optional | 得獎 tier 的 instance ID |
| `platform` | String | Optional | `MOBILE` 或 `WEB` |

### Response

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `transactionId` | String | Required | 運營商交易 ID |
| `currency` | String | Required | 玩家幣種 |
| `cash` | Decimal | Required | 入帳後現金餘額 |
| `bonus` | Decimal | Required | 入帳後 Bonus 餘額 |
| `error` | Integer | Required | 回應狀態碼 |
| `description` | String | Required | 狀態說明 |

```json
{
  "transactionId": "1482492905503",
  "currency": "USD",
  "cash": 99815.04,
  "bonus": 99.99,
  "error": 0,
  "description": "Success"
}
```

---

## 12. EndRound

**`POST /endRound.html`**

每次回合結束時 PP 呼叫，通知運營商可進行交易最終確認。此方法為 **Optional**。

> 若運營商不需要即時交易確認，建議停用 EndRound，改用 Data Feed API，以降低雙方系統間的請求流量。

> **重要**：EndRound 可能發送**多次**。若回合已完成，運營商應**忽略請求並回傳成功**。

> **注意**：EndRound **回應中不含 `transactionId`**。

### Request

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `hash` | String | Required | Hash 驗證碼 |
| `userId` | String | Required | 玩家 ID |
| `gameId` | String | Required | 遊戲 ID |
| `roundId` | String | Required | 回合 ID |
| `providerId` | String | Required | 遊戲商識別碼 |
| `bonusCode` | String | Optional | Bonus ID |
| `platform` | String | Optional | `MOBILE` 或 `WEB` |
| `roundDetails` | String | Optional | 回合附加資訊（包含 totalBet、totalWin 等） |
| `win` | Decimal | Optional | 本回合總獲勝金額（**僅供通知，不可用於交易**） |

### Response

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `cash` | Decimal | Required | 玩家當前現金餘額 |
| `bonus` | Decimal | Required | 玩家當前 Bonus 餘額 |
| `error` | Integer | Required | 回應狀態碼 |
| `description` | String | Required | 狀態說明 |

```json
{
  "cash": 99764.99,
  "bonus": 99.99,
  "error": 0,
  "description": "Success"
}
```

---

## 13. Refund

**`POST /refund.html`**

退款，回滾 Bet 交易，將投注金額歸還玩家餘額。

> **重要：幂等性**：相同 reference 重複呼叫只建立一筆退款交易。

> **重要**：若找不到對應的 Bet 交易，運營商**不應執行任何操作**，並回傳 `error: 0`（成功）或特定錯誤碼。

### Request

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `hash` | String | Required | Hash 驗證碼 |
| `userId` | String | Required | 玩家 ID |
| `reference` | String | Required | **原始 Bet 交易**的 reference |
| `providerId` | String | Required | 遊戲商識別碼 |
| `amount` | Decimal | Optional | 退款金額（需申請啟用） |
| `gameId` | String | Optional | 遊戲 ID（需申請啟用） |
| `roundId` | String | Optional | 回合 ID（需申請啟用） |
| `timestamp` | Long | Optional | 交易時間（需申請啟用） |
| `bonusCode` | String | Optional* | Bonus ID（FSB / Free Chips 必填） |
| `platform` | String | Optional | `MOBILE` 或 `WEB` |

### Response

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `transactionId` | String | Required | 運營商退款交易 ID |
| `error` | Integer | Required | 回應狀態碼 |
| `description` | String | Required | 狀態說明 |

```json
{
  "transactionId": "C1482435726839",
  "error": 0,
  "description": "Success"
}
```

---

## 14. PromoWin

**`POST /promoWin.html`**

Tournament / Missions / Community Jackpot / Cashback / Prize Drop 等活動結束後，PP 通知運營商發放活動獎金。

> **重要**：獎金金額**必須加入玩家現金餘額**（"The prize amount must be added to the player's cash balance"）。

> **重要**：通知為**非同步**，活動結束後可能有短暫延遲。

> **重要：幂等性**：相同 reference 重複呼叫只建立一筆交易。

### Request

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `hash` | String | Required | Hash 驗證碼 |
| `providerId` | String | Required | 遊戲商識別碼 |
| `timestamp` | Long | Required | 交易時間（毫秒 epoch） |
| `userId` | String | Required | 玩家 ID |
| `campaignId` | String | Required | 活動 ID（CJP 格式為 `X:Y`） |
| `campaignType` | String | Required | 活動類型：`T`、`M`、`CJP`、`CB`、`MR` |
| `amount` | Decimal | Required | 獎金金額（最小 0.00，為 0 視為虧損） |
| `currency` | String | Required | 玩家幣種 |
| `reference` | String | Required | 本次交易唯一識別碼 |
| `roundId` | String | Optional | 最後得獎回合 ID（需申請啟用） |
| `gameId` | String | Optional | 遊戲 ID（Prize Drop 填得獎遊戲，Tournament 填最後得獎遊戲，CJP/Cashback 不回傳） |

### Response

| 參數 | 型別 | 狀態 | 說明 |
|---|---|---|---|
| `transactionId` | String | Required | 運營商交易 ID |
| `currency` | String | Required | 玩家幣種 |
| `cash` | Decimal | Required | 入帳後現金餘額 |
| `bonus` | Decimal | Required | 入帳後 Bonus 餘額 |
| `error` | Integer | Required | 回應狀態碼 |
| `description` | String | Required | 狀態說明 |

```json
{
  "transactionId": "1482492905503",
  "currency": "USD",
  "cash": 99815.04,
  "bonus": 99.99,
  "error": 0,
  "description": "Success"
}
```

---

## 15. 錯誤碼

運營商在回應 Seamless Wallet API 請求時應回傳的錯誤碼：

| 錯誤碼 | 說明 | Bet 觸發 Refund | Result/Refund 觸發 retry | EndRound 觸發 retry |
|---|---|:---:|:---:|:---:|
| `0` | 成功 | No | No | No |
| `1` | 餘額不足（只用於 Bet） | No | Yes | No |
| `2` | 玩家不存在或已登出 | Yes | Yes | No |
| `3` | 禁止下注（特殊 Bonus 限制等） | No | Yes | No |
| `4` | token 無效、過期或找不到玩家 | Yes | Yes | No |
| `5` | Hash 驗證失敗 | Yes | Yes | No |
| `6` | 玩家帳號凍結 | Yes | Yes | No |
| `7` | 請求參數錯誤 | Yes | Yes | No |
| `8` | 遊戲不存在或已停用（Bet 使用；Result 仍應正常處理） | Yes | Yes | No |
| `50` | 下注金額超過限制（受管制市場） | No | Yes | No |
| `100` | 內部錯誤，**需要重試**（PP 將進行 Reconciliation） | Yes | Yes | No |
| `120` | 內部錯誤，**不需要重試**（PP 不進行 Reconciliation） | No | No | No |
| `130` | EndRound 處理內部錯誤，**需要 EndRound retry**（僅限 EndRound 方法） | - | - | Yes |
| `210` | Reality Check 警告 | Yes | Yes | No |
| `310` | 下注超出個人限制（受管制市場） | No | No | No |

> **注意**：錯誤碼 `100` 會觸發 PP 的 Reconciliation 流程（對 Bet 發送 Refund；對 Result/Refund 等重試），使用時須謹慎。  
> **注意**：錯誤碼 `130` 僅用於 EndRound 方法，不可用於其他方法。

---

## 16. 重要注意事項

### Transaction Reference 唯一性

> "Transaction reference used for bets and wins **has to be different**." — 1.7 Transaction reference

Bet 和 Result 的 `reference` **必須不同**，每筆交易的 `reference` 在整個系統中應唯一。

### 幂等性（Idempotency）

所有方法均為幂等：相同 `reference` 重複請求只處理一次，重試時回傳當前實際餘額。

### BonusWin 入帳義務

BonusWin **不是通知**，而是**實際的金融交易**。收到請求後必須將 `amount` 加入玩家現金餘額，與 `Result` 的語意相同。

### Refund 找不到交易

若收到 Refund 但找不到對應的 Bet 交易，應回傳 `error: 0`（成功），不執行任何操作。

### EndRound 不含 transactionId

EndRound 的回應只需 `cash`、`bonus`、`error`、`description`，**不包含 `transactionId`**。

### EndRound win 欄位不可用於入帳

EndRound 的 `win` 參數為通知性質（"should not be used for transaction within round"），不可依此入帳。

### Timestamp 單位

所有 `timestamp` 均為 **Unix epoch 毫秒**（13 位數），轉換時需 ÷ 1000。

### 回應金額須為遊戲商幣值

所有回應中的 `cash`、`bonus` 均需以遊戲商幣值（乘以匯率）表示，非平台本地幣值。
