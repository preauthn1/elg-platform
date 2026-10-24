# Playtech — GamesLink Seamless Wallet API 整合文件

> 原始文檔：<https://example.invalid/playtech/seamless/cn.html>
> 版本：v1.0.62（2026-05-19）
> 範圍：**單錢包（Seamless / Single Wallet）**整合。本文只保留我方（運營商）對接所需內容，bonus / golden chip 等周邊已壓縮為清單，不展開細節。**對帳所需的 `playerGameTransactionReport` 交易明細報表已完整展開於 [§12](#12-對帳接口playergametransactionreport交易明細報表)**；其餘 reporting 接口維持清單。
>
> 最後對齊線上文檔日：**2026-06-30**（版本 v1.0.62，內容無變動）。

---

## 目錄

1. [概述](#1-概述)
2. [認證機制](#2-認證機制)
3. [關鍵概念](#3-關鍵概念)
4. [API 方向](#4-api-方向)
5. [Operator APIs（Playtech → 運營商，必須實作）](#5-operator-apisplaytech--運營商必須實作)
6. [Platform APIs（運營商 → Playtech，啟動 / 會話相關）](#6-platform-apis運營商--playtech啟動--會話相關)
7. [Context / 資料結構](#7-context--資料結構)
8. [遊戲腳本（回合時序）](#8-遊戲腳本回合時序)
9. [錯誤碼](#9-錯誤碼)
10. [重試與冪等性](#10-重試與冪等性)
11. [重要注意事項](#11-重要注意事項)
12. [對帳接口：playerGameTransactionReport（交易明細報表）](#12-對帳接口playergametransactionreport交易明細報表)
13. [APIs 專案串接補充](#13-apis-專案串接補充)

---

## 1. 概述

Seamless（單一錢包）模式：玩家餘額由**運營商錢包**持有，Playtech 在每次投注 / 派彩 / 退款時主動呼叫運營商 callback 完成扣 / 加款。

- **傳輸**：HTTPS + TLS 客戶端憑證（mTLS）
- **請求格式**：`application/json` POST
- **時區**：所有時間欄位採 **GMT (UTC)**
- **時間格式**：`yyyy-mm-dd hh24:mi:ss.SSS` 或 ISO 8601 `2024-06-14T20:11:19+00:00`
- **金額精度**：多數金額欄位為 `decimal(22, 2)`（如 `pay.amount` / `balance.real` / `bet.amount`）；個別欄位精度不同，例如 `jackpot.contributionAmount` 為 `decimal(36, 16)`，以各欄位文件定義為準

> ⚠️ Playtech 已從 `text/json` 切換到 `application/json`；運營商發送的請求**必須**使用 `application/json`。

---

## 2. 認證機制

| 方向 | 機制 |
|---|---|
| **運營商 → Playtech**（Platform API） | HTTP Header `x-auth-kiosk-key: {{kiosk_key}}`（由 Playtech 技術支援發放） |
| **Playtech → 運營商**（Operator API） | TLS 客戶端憑證 + 請求內 `externalToken` |

文檔未使用 HMAC 等簽名演算法。

---

## 3. 關鍵概念

| 概念 | 說明 |
|---|---|
| **Kiosk** | 運營商在 GamesLink 中的識別單位，對應一組 `kiosk_key` 與 `kiosk_prefix` |
| **kiosk_prefix** | 玩家用戶名強制前綴，1–32 字元，**大寫**；例：`MCCT_PLAYER001` |
| **serverName** | 遊戲伺服器名稱，**大小寫敏感**，由運營商提供（例：`PLAYTECHSERVER`） |
| **username** | 1–32 字元，必須大寫且含 kiosk_prefix |
| **externalToken** | 玩家會話憑證，11–255（部分 endpoint 上限 2,147,483,647） |
| **gameRoundCode** | 遊戲回合代碼，1–128 字元；同一回合多個事件共用 |
| **transactionCode** | 單次交易代碼，0–20 字元 |
| **requestId** | 每次請求唯一識別碼，11–255 字元 |

---

## 4. API 方向

```
┌──────────────┐  Platform API (運營商發起)   ┌──────────────┐
│              │ ─────────────────────────►   │              │
│  Operator    │   x-auth-kiosk-key Header    │  Playtech    │
│  (我方)      │                              │  GamesLink   │
│              │ ◄─────────────────────────   │              │
└──────────────┘  Operator API (Playtech 發起) └──────────────┘
                  TLS Client Cert + externalToken
```

### 通用約定

- **HTTP 狀態碼**：Operator API **一律回 HTTP 200**，錯誤資訊放在 body `error.code`。
- **未知欄位**：必須忽略（向前相容）。
- **冪等性**：相同 `transactionCode` / `gameRoundCode` 收到重複請求，必須回第一次的結果（**即使令牌已過期也要成功**）。
- **healthcheck**：≤ 3 秒回應。
- **自動結算**：未關閉回合 24–48 小時後 Playtech 會自動補發 `gameRoundResult`。

---

## 5. Operator APIs（Playtech → 運營商，必須實作）

| # | Endpoint | 用途 |
|---|---|---|
| 1 | POST `/authenticate` | 驗證外部令牌 |
| 2 | POST `/getBalance` | 查詢餘額 |
| 3 | POST `/bet` | 玩家投注（扣款） |
| 4 | POST `/gameRoundResult` | 回合結果 / 派彩 / 退款 |
| 5 | POST `/transferFunds` | 達成獎金投注要求後資金回轉（單錢包無 bonus 流程可不實作） |
| 6 | POST `/notifyBonusEvent` | 獎金事件通知（單錢包無 bonus 流程可不實作） |
| 7 | POST `/keepAlive` | 保活玩家會話 |
| 8 | POST `/logout` | 玩家登出 |
| 9 | POST `/healthcheck` | 健康檢查（≤3 秒） |

### 5.1 authenticate

請求：

| 欄位 | 必填 | 說明 |
|---|---|---|
| `requestId` | ✓ | 11–255 字元 |
| `username` | ✓ | 1–32 字元 |
| `externalToken` | ✓ | 11–255 字元 |

回應：

| 欄位 | 必填 | 說明 |
|---|---|---|
| `requestId` | ✓ | echo |
| `username` | ✓ | |
| `currencyCode` | ✓ | ISO 4217 |
| `countryCode` | ✓ | ISO 3166-1 alpha-2 |
| `permanentExternalToken` | ✗ | 永久令牌，11–255 字元 |
| `subBrand` | ✗ | VIP 等級 `vipLevel1`–`vipLevel40` |
| `loginDate` | ✗ | |
| `regulation` | ✗ | 例：`FRANCE` |
| `playerData` | ✗ | 見 [7.1](#71-playerdata) |
| `error` | ✗ | 認證失敗時回傳（型別 `AuthenticateError`，含 `code` / `description`） |
| `playerMessage` | ✗ | 顯示給玩家的訊息 |

錯誤：`ERR_AUTHENTICATION_FAILED` / `INTERNAL_ERROR`。

### 5.2 getBalance

請求：`requestId` / `username` / `externalToken`
回應：`requestId` / `balance: { real, timestamp }` / `error?`

### 5.3 bet

請求：

| 欄位 | 必填 | 說明 |
|---|---|---|
| `requestId` | ✓ | |
| `username` | ✓ | |
| `externalToken` | ✓ | 11–255 字元 |
| `gameRoundCode` | ✓ | 1–128 字元 |
| `transactionCode` | ✓ | 0–20 字元 |
| `transactionDate` | ✓ | GMT |
| `amount` | ✓ | `decimal(22,2)` |
| `gameCodeName` | ✓ | 遊戲代碼 |
| `internalFundChanges[]` | ✗ | Playtech 錢包資金變動（型別 `BONUS`） |
| `betDetails` | ✗ | `tableCoverage` / `betType` |
| `liveTableDetails` | ✗ | 真人遊戲桌資訊 |
| `bonusChanges[]` | ✗ | 獎金變動（不接 bonus 可忽略） |
| `gameMetadata` | ✗ | |
| `remoteBonusCode` | ✗ | |
| `jackpotContributions[]` | ✗ | 累積獎金貢獻 |

回應：

| 欄位 | 必填 | 說明 |
|---|---|---|
| `requestId` | ✓ | echo |
| `externalTransactionCode` | ✓ | 運營商交易代碼 |
| `externalTransactionDate` | ✓ | |
| `balance` | ✓ | 交易後餘額 |
| `playerDialog` | ✗ | 現實檢查訊息等 |
| `error` | ✗ | |
| `playerMessage` | ✗ | |

常見錯誤碼：`ERR_INSUFFICIENT_FUNDS` / `ERR_TRANSACTION_DECLINED` / `ERR_REGULATORY_REALITYCHECK` / `INTERNAL_ERROR`（可重試） / `ERR_REGULATORY_GENERAL`（不可重試）。

### 5.4 gameRoundResult

請求：

| 欄位 | 必填 | 說明 |
|---|---|---|
| `requestId` | ✓ | |
| `username` | ✓ | |
| `externalToken` | ✓ | |
| `gameRoundCode` | ✓ | |
| `gameCodeName` | ✓ | |
| `pay` | ✗ | 支付物件（見 [7.3](#73-pay)）；無派彩時為 `null` |
| `jackpot` | ✗ | 累積獎金；有貢獻 / 中獎時帶，退款情境可不帶 |
| `gameRoundClose` | ✗ | 關局時帶 |
| `gameHistoryUrl` | ✗ | |
| `liveTableDetails` | ✗ | |
| `customData[]` | ✗ | 真人遊戲用，含 `validBetAmountInMinorUnits` / `effectiveBetAmountInMinorUnits` |
| `gamingDetails` | ✗ | `betSelections[]` |

回應：

| 欄位 | 必填 | 說明 |
|---|---|---|
| `requestId` | ✓ | echo |
| `balance` | ✓ | 即使本次回合無 `pay` 仍須回傳當前餘額 |
| `externalTransactionCode` | 條件 | 僅當本次請求帶 `pay`（發生實際支付交易）時必填 |
| `externalTransactionDate` | 條件 | 同上 |
| `error` | ✗ | |

> **退款**：`pay.type=REFUND` + `pay.relatedTransactionCode=原投注 transactionCode`。

### 5.5 transferFunds

達成獎金投注要求後將獎金資金轉回運營商錢包。**單錢包整合若不接 bonus 可不實作**。

| 欄位 | 必填 | 說明 |
|---|---|---|
| `requestId` | ✓ | |
| `username` | ✓ | |
| `transactionCode` | ✓ | |
| `transactionDate` | ✓ | |
| `amount` | ✓ | |
| `bonusInstanceCode` | ✓ | |
| `bonusTemplateId` | ✓ | |
| `type` | ✓ | `BONUS` |
| `bonusInfo` | ✓ | `{ remoteBonusCode, bonusInstanceCode, bonusTemplateId, bonusType=CASH }` |

### 5.6 notifyBonusEvent

獎金 ACCEPTED / REMOVED 通知，**單錢包整合若不接 bonus 可不實作**。

請求關鍵欄位：`requestId` / `username` / `remoteBonusCode?` / `bonusInstanceCode?` / `resultingStatus`（`ACCEPTED` / `REMOVED`） / `date` / `bonusBalanceChange?` / `freeSpinsChange?` / `goldenChipsChange?` / `bonusTemplateId?` / `freeSpinValue?`。

### 5.7 keepAlive

請求：`requestId` / `username` / `externalToken` / `timeFromLastAction?`（毫秒）
回應：`requestId` / `error?`

### 5.8 logout

請求：`requestId` / `username` / `externalToken`
回應：`requestId` / `error?` / `playerMessage?`
錯誤：`ERR_AUTHENTICATION_FAILED`（令牌無效或會話已關閉）

### 5.9 healthcheck

請求 body：空
回應：HTTP 200 空 body
超時上限：3 秒
可能狀態碼：`200` / `400` / `500` / `308`（超時）

---

## 6. Platform APIs（運營商 → Playtech，啟動 / 會話相關）

路徑前綴：`/from-operator/`
Header：`Content-Type: application/json`、`x-auth-kiosk-key: {{kiosk_key}}`
回應外殼：`{ code, message, data, timestamp }`

### 6.1 核心啟動 / 會話

| # | Endpoint | 必要 | 用途 |
|---|---|---|---|
| 1 | POST `/from-operator/getGameLaunchUrl` | ✅ 必用 | 取得遊戲啟動 URL |
| 2 | POST `/from-operator/endLoginSession` | ✅ 必用 | 結束玩家會話 |
| 3 | POST `/from-operator/notifyBalanceChange` | ◯ 條件 | 外部錢包餘額變動（後台調整 / 入款 / 提款）後通知 Playtech 重抓餘額；若整合不需即時刷新可不呼叫 |

#### getGameLaunchUrl

| 欄位 | 必填 | 說明 |
|---|---|---|
| `requestId` | ✓ | |
| `serverName` | ✓ | |
| `username` | ✓ | |
| `gameCodeName` | ✓ | 遊戲代碼 |
| `clientPlatform` | ✓ | `web` / `mobile` |
| `externalToken` | ✓ | |
| `language` | ✓ | ISO 639-1 |
| `playMode` | ✗ | `0`=試玩，`1`=真錢 |
| `lobbyUrl` | ✗ | 大廳 URL |
| `configurationKey` | ✗ | 例：`nolobby` |

回應：`data.url` 即遊戲啟動 URL。

#### endLoginSession

| 欄位 | 必填 | 說明 |
|---|---|---|
| `requestId` | ✓ | 11–255 字元 |
| `serverName` | ✓ | |
| `username` | ✓ | 1–32 字元 |
| `externalToken` | ✗ | |

#### notifyBalanceChange

請求：`requestId` / `serverName` / `username` / `timestamp`（GMT）。

### 6.2 周邊（可選，不接 bonus / tag 可略過）

> 若不導入 Playtech 獎金系統，以下 endpoint 完全不需要實作。

| Endpoint | 用途 |
|---|---|
| `/from-operator/getPlayerBonusHistory` | 過去一年未激活獎金 |
| `/from-operator/getPlayerActiveBonuses` | 當前激活獎金 |
| `/from-operator/getSupportedFreeSpinBetValues` | 取得遊戲支援的免費旋轉投注值 |
| `/from-operator/giveFreeSpins` | 發放免費旋轉 |
| `/from-operator/giveGoldenChips` | 發放黃金籌碼 |
| `/from-operator/removeBonus` | 移除已發放獎金 |
| `/from-operator/setPlayerTags` | 設置玩家標籤 |

### 6.3 Reporting（對帳工具用，`GET /reports/`）

> Reporting 接口**自成一套體系**，與 Operator / Platform API 皆不同：統一為 **`GET /reports/...`**、認證 Header **`x-auth-admin-key: {{admin_key}}`**、query string 傳參、回應外殼 `{ code, message, data, timestamp }`（清單型另帶 `pagination`）。整合期可後置；除 `playerGameTransactionReport` 外，其餘欄位細節以原始文檔為準。

| 接口 | 用途 |
|---|---|
| `GET /reports/dailyReport` | 總投注 / 贏利 / 退款日報，按實體名稱與交易日期分組 |
| `GET /reports/gameRoundStatus` | 查特定遊戲局狀態與局明細；僅限當前日前三個月內 |
| `GET /reports/gameStats` | 總下注 / 贏分 / 退款報表，按實體名稱與交易日期分組 |
| `GET /reports/gameTransactionLineItemsReport` | 指定日期範圍與時區內所有遊戲交易明細（一局可含多筆） |
| `GET /reports/unfinishedGameRounds` | 尚未關閉的遊戲交易列表 |
| `GET /reports/playerGameTransactionReport` | 玩家遊戲交易明細（**對帳主力**）— 完整規格見 [§12](#12-對帳接口playergametransactionreport交易明細報表) |

---

## 7. Context / 資料結構

只列單錢包核心結構；bonus / golden chip / freeSpin 等周邊結構，以及各國法規專屬欄位（如 Danish 的 RNG 標識、Slovak 的 `gameRoundSummary`、French 的 `accountVerificationStatus` / `externalId`）皆未展開，請參考原始文檔。

### 7.1 PlayerData

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `currencyCode` | string | ✓ | ISO 4217 |
| `countryCode` | string | ✓ | ISO 3166-1 alpha-2 |

### 7.2 Balance

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `real` | decimal | ✓ | 真實餘額 |
| `timestamp` | string | ✓ | GMT |

### 7.3 Pay

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `transactionCode` | string | ✓ | |
| `transactionDate` | string | ✓ | GMT |
| `amount` | decimal(22,2) | ✓ | |
| `type` | string | ✓ | `WIN` / `REFUND` |
| `betType` | string | ✗ | `MAIN_BET` / `SIDE_BET` |
| `internalFundChanges[]` | array | ✗ | 見 [7.4](#74-fundchange) |
| `bonusChanges[]` | array | ✗ | 不接 bonus 可忽略 |
| `bonusWinningsInfo` | object | ✗ | 不接 bonus 可忽略 |
| `relatedTransactionCode` | string | ✗ | 退款時填原投注交易代碼 |

### 7.4 FundChange

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `type` | string | ✓ | enum：`REAL`（真錢餘額）/ `BONUS`（獎池餘額） |
| `amount` | decimal(22,2) | ✓ | 變動金額（負為扣款） |

### 7.5 Jackpot

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `contributionAmount` | decimal(36,16) | ✗ | 本局貢獻；未中獎 / 無貢獻時可不帶 |
| `winAmount` | decimal(22,2) | ✗ | 本局中獎金額（無中獎為 `0` 或不帶） |
| `jackpotId` | string | ✗ | |
| `jackpotBalanceBeforeWin` | decimal | ✗ | |
| `jackpotBalanceAfterWin` | decimal | ✗ | |
| `jackpotContributions[]` | array | ✗ | 多層貢獻 |
| `jackpotWinnings[]` | array | ✗ | 多層中獎 |

### 7.6 GameRoundClose

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `date` | string | ✓ | GMT |
| `rngGeneratorId` | string | ✗ | 例：`LIVE` / `SecureRandom`；僅 Danish 法規提供 |
| `rngSoftwareId` | string | ✗ | 僅 Danish 法規提供 |

### 7.7 LiveTableDetails

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `launchAlias` | string | ✗ | 桌台啟動別名 |
| `tableId` | string | ✗ | |
| `tableName` | string | ✗ | |

### 7.8 CustomData（真人遊戲）

| 欄位 | 說明 |
|---|---|
| `validBetAmountInMinorUnits` | 有效投注額（最小貨幣單位，`100 = 1.00`） |
| `effectiveBetAmountInMinorUnits` | 實際投注額（最小貨幣單位） |

### 7.9 Error

```json
{
  "error": {
    "code": "ERR_INSUFFICIENT_FUNDS",
    "description": "internal trace only"
  }
}
```

---

## 8. 遊戲腳本（回合時序）

### 8.1 標準回合

```
玩家點擊遊戲
   ↓
[Operator] getGameLaunchUrl  →  取得 URL
   ↓
玩家進入遊戲頁面
   ↓
[Playtech → Operator] authenticate     ← 驗證 externalToken
   ↓
[Playtech → Operator] bet              ← 扣款
   ↓
[Playtech → Operator] gameRoundResult  ← 派彩 / 純結算 / 退款
```

### 8.2 gameRoundResult 四種型態

#### (a) 玩家輸（無 pay）

```json
{
  "pay": null,
  "jackpot": { "contributionAmount": "0", "winAmount": "0" },
  "gameRoundClose": { "date": "...", "rngGeneratorId": "...", "rngSoftwareId": "..." }
}
```

#### (b) 玩家贏

```json
{
  "pay": {
    "transactionCode": "...",
    "transactionDate": "...",
    "amount": "2.00",
    "type": "WIN"
  }
}
```

#### (c) 退款

```json
{
  "pay": {
    "type": "REFUND",
    "amount": "1.00",
    "relatedTransactionCode": "原投注 transactionCode"
  }
}
```

#### (d) 累積獎金中獎

```json
{
  "pay": { "amount": "60.00", "type": "WIN" },
  "jackpot": {
    "contributionAmount": "0.99",
    "winAmount": "50.00",
    "jackpotWinnings": [{ "amount": "50.00", "jackpotId": "..." }]
  }
}
```

---

## 9. 錯誤碼

| 錯誤碼 | 說明 | 可重試 |
|---|---|---|
| `ERR_AUTHENTICATION_FAILED` | 認證失敗 | ✗ |
| `ERR_INSUFFICIENT_FUNDS` | 餘額不足 | ✗ |
| `ERR_TRANSACTION_DECLINED` | 交易被拒 | ✗ |
| `ERR_REGULATORY_REALITYCHECK` | 現實檢查過期 | ✗ |
| `ERR_REGULATORY_GENERAL` | 法規錯誤 | ✗ |
| `ERR_PLAYER_NOT_FOUND` | 找不到玩家 | ✗ |
| `INTERNAL_ERROR` | 內部系統錯誤 | ✓ |
| `CONSTRAINT_VIOLATION` | 約束違反 | ✓（僅 `gameRoundResult`） |
| `INVALID_REQUEST_PAYLOAD` | 請求格式錯誤 | ✓（僅 `gameRoundResult`） |

---

## 10. 重試與冪等性

- **回應時間要求**：每個 Operator API 請求**回應時間必須低於 250ms**。超過上限的 `bet` 可能被 Playtech 視為超時，進而觸發**退款型 `gameRoundResult`**（沖銷原投注）。
- **可重試錯誤**：`INTERNAL_ERROR`（`bet` + `gameRoundResult`）、`CONSTRAINT_VIOLATION` / `INVALID_REQUEST_PAYLOAD`（僅 `gameRoundResult`）。
- **退避演算法**：`1, 2, 4, 8, 16, 32 …` 分鐘，最低頻率每日一次。
- **必達原則**：
  - `gameRoundResult` 必須**最終成功**（24–48 小時內 Playtech 會自動補發）。
  - 同一 `transactionCode` / `gameRoundCode` 收到重複請求，必須回第一次的成功結果（**令牌過期也要成功**）。

---

## 11. 重要注意事項

| 項目 | 規範 |
|---|---|
| HTTP 狀態 | Operator API 一律回 HTTP 200，錯誤放 body `error.code` |
| Content-Type | 一律 `application/json`（舊版 `text/json` 已停用） |
| 時間 | GMT，格式 `yyyy-mm-dd hh24:mi:ss.SSS` 或 ISO 8601 |
| 金額 | `decimal(22, 2)`，禁止原生浮點數運算 |
| Minor units | `100 = 1.00` |
| 用戶名 | 1–32 字元，大寫，需帶 kiosk_prefix |
| 未知欄位 | 必須忽略（向前相容） |
| 安全 | TLS 1.2+，Operator API 必須驗證 TLS 客戶端憑證 |
| 自動結算 | 未關閉回合 24–48 小時自動由 Playtech 補發 `gameRoundResult` |
| healthcheck | ≤ 3 秒回應 |

---

## 12. 對帳接口：playerGameTransactionReport（交易明細報表）

> 來源：原始文檔 `#api-playergametr`。**對帳主力接口**：拉取指定日期範圍與時區內的所有遊戲交易明細；一局遊戲（`game_round`）可含多筆交易明細（`line_items`）。

### 12.1 端點與認證

| 項目 | 值 |
|---|---|
| Method / Path | **`GET`** `/reports/playerGameTransactionReport` |
| 認證 Header | **`x-auth-admin-key: {{admin_key}}`**（獨立 admin key，**非** Platform API 的 `x-auth-kiosk-key`，亦非 Operator API 的 TLS 客戶端憑證） |
| 參數傳遞 | Query string |
| 回應 Content-Type | `application/json; charset=UTF-8` |
| 分頁 | `per_page` / `page`，回應帶 `pagination` 物件 |

> ⚠️ 此接口的認證方式與本文其他接口皆不同，串接時勿沿用 kiosk_key。

### 12.2 請求參數

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `x-auth-admin-key` | string | ✓（Header） | 管理金鑰 `{{admin_key}}` |
| `bet_start_date` | string | 條件 | 依**投注時間**查詢的起，格式 `YYYY-MM-DD HH:MM:SS`（採 `timezone` 指定時區） |
| `bet_end_date` | string | 條件 | 依**投注時間**查詢的迄 |
| `result_start_date` | string | 條件 | 依**結算時間**查詢的起 |
| `result_end_date` | string | 條件 | 依**結算時間**查詢的迄 |
| `game_server` | string | ✓ | 伺服器名稱（`serverName`，大小寫敏感） |
| `timezone` | string | ✓ | 查詢時區，取值為 PHP 時區字串（<https://example.invalid/manual/en/timezones.php>），例 `Asia/Kuala_Lumpur` |
| `entity_name` | string | ✗ | 實體名稱；會包含該實體的所有下線 |
| `game_round` | string | ✗ | 指定遊戲局號精確查詢 |
| `per_page` | integer | ✗ | 每頁筆數（範例用 `1000`，回應預設 `100`） |
| `page` | integer | ✗ | 頁碼 |

> **日期二擇一（互斥必填）**：`bet_*` 與 `result_*` 兩組擇一提供。缺漏會回 `422`，訊息如 `The bet start date field is required when result start date is not present`。對帳時依切口語意選擇：以投注落點對帳用 `bet_*`，以結算落點對帳用 `result_*`。

請求範例：

```
GET /reports/playerGameTransactionReport?bet_start_date=2025-02-01%2000%3A00%3A00&bet_end_date=2025-02-01%2012%3A30%3A00&game_server=AGCASTG&timezone=Asia%2FKuala_Lumpur&per_page=1000&page=1
x-auth-admin-key: {{admin_key}}
```

### 12.3 回應外殼

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `code` | integer | ✓ | 狀態碼（成功 `200`） |
| `message` | string | ✗ | 錯誤描述，僅處理失敗時出現 |
| `data` | `PlayerGameTransactionData[]` | ✓ | 玩家遊戲交易報表陣列（每局一筆） |
| `pagination` | `PaginationData` | ✗ | 分頁詳情 |
| `timestamp` | string | ✓ | 回應時間（ISO 8601，GMT） |

### 12.4 PlayerGameTransactionData（每局一筆）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `player_username` | string | ✓ | 玩家用戶名 |
| `bet_date` | string | ✓ | 投注日期（帶 `timezone` offset，例 `2025-11-10T18:46:51+08:00`） |
| `result_date` | string | ✓ | 結算日期 |
| `operator_token` | string | ✗ | 運營商於本報表的遊戲會話令牌（範例可為 `null`） |
| `game_code` | string | ✓ | 遊戲唯一編碼（例 `ubal`） |
| `game_alias_code` | string | ✓ | 遊戲代號（例 `bal_soireebaccarat`） |
| `game_round` | string | ✓ | 遊戲局唯一標識（對應我方鏡像的 `gameRoundCode`） |
| `status` | string | ✓ | 遊戲局狀態（例 `closed`） |
| `is_closed` | boolean | ✓ | 是否已關局 |
| `is_refund` | boolean | ✓ | 是否為退款局 |
| `currency` | string | ✓ | 投注幣別 |
| `bet` | number | ✓ | 投注金額 |
| `win` | number | ✓ | 中獎金額 |
| `refund` | number | ✓ | 退款金額（如適用） |
| `progressive_bet` | number | ✓ | 投注中分配給累積獎金池的金額 |
| `progressive_win` | number | ✓ | 累積獎金中獎金額 |
| `free_spin_bet` | number | ✓ | 免費旋轉投注金額 |
| `free_spin_win` | number | ✓ | 免費旋轉中獎金額 |
| `golden_chip_bet` | number | ✓ | 黃金籌碼投注金額 |
| `golden_chip_win` | number | ✓ | 黃金籌碼中獎金額 |
| `game_history_url` | array | ✓ | 完整遊戲歷史 URL 陣列 |
| `operator_result_acknowledged` | boolean | ✓ | 運營商是否已確認結果 |
| `line_items` | `PlayerGameTransactionLineItem[]` | ✓ | 該局的逐筆交易明細 |

### 12.5 PlayerGameTransactionLineItem（局內逐筆交易）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `id` | string | ✓ | 交易明細項識別碼（UUID） |
| `bet_date` | string | ✓ | 投注日期時間 |
| `transaction_date` | string | ✓ | 交易日期時間 |
| `transaction_code` | string | ✓ | 交易代碼（對應 callback 的 `transactionCode`） |
| `type` | string | ✓ | 交易類型：`BET` / `WIN` / `NOWIN` / `REFUND` |
| `game_round_close` | boolean | ✓ | 該筆是否為關局交易 |
| `operator_acknowledged` | boolean | ✓ | 運營商是否已接收並確認該交易 |
| `currency` | string | ✓ | 幣別 |
| `amount` | integer | ✓ | 交易金額（範例與 `data` 同量級，投注為負、派彩為正：`BET=-25`、`WIN=25`） |
| `progressive_bet` | integer | ✓ | 累積投注金額 |
| `progressive_win` | integer | ✓ | 累積中獎金額 |
| `free_spin_amount` | number | ✗ | 本筆使用的免費旋轉金額 |
| `golden_chip_amount` | number | ✗ | 本筆使用的黃金籌碼金額 |
| `created_at` | string | ✓ | 交易建立時間 |

### 12.6 PaginationData

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `current_page` | integer | ✓ | 當前頁碼 |
| `last_page` | integer | ✓ | 最後一頁頁碼（迴圈終止條件） |
| `path` | string | ✓ | 分頁請求路徑 |
| `per_page` | integer | ✓ | 每頁筆數 |
| `total_items` | integer | ✓ | 總筆數 |

### 12.7 回應範例（精簡）

```json
{
  "code": 200,
  "message": "",
  "data": [
    {
      "player_username": "CRXD_RR",
      "bet_date": "2025-11-10T18:46:51+08:00",
      "result_date": "2025-11-10T18:47:04+08:00",
      "operator_token": null,
      "game_code": "ubal",
      "game_alias_code": "bal_soireebaccarat",
      "game_round": "live_87617557416#9fdfaad6",
      "status": "closed",
      "is_closed": true,
      "is_refund": false,
      "currency": "MYR",
      "bet": 25, "win": 25, "refund": 0,
      "progressive_bet": 0, "progressive_win": 0,
      "free_spin_bet": 0, "free_spin_win": 0,
      "golden_chip_bet": 0, "golden_chip_win": 0,
      "game_history_url": ["https://.../getgamehistoryurl.php?..."],
      "operator_result_acknowledged": true,
      "line_items": [
        { "id": "019a6d60-...", "transaction_date": "2025-11-10T18:46:51+08:00",
          "transaction_code": "93120496", "type": "BET", "game_round_close": false,
          "operator_acknowledged": true, "currency": "MYR", "amount": -25,
          "progressive_bet": 0, "progressive_win": 0,
          "created_at": "2025-11-10T18:46:56+08:00" },
        { "id": "019a6d60-...", "transaction_date": "2025-11-10T18:47:04+08:00",
          "transaction_code": "93120511", "type": "WIN", "game_round_close": true,
          "operator_acknowledged": true, "currency": "MYR", "amount": 25,
          "progressive_bet": 0, "progressive_win": 0,
          "created_at": "2025-11-10T18:47:08+08:00" }
      ]
    }
  ],
  "timestamp": "2025-11-10T13:53:50+00:00",
  "pagination": { "current_page": 1, "last_page": 1, "path": "https://.../reports/playerGameTransactionReport", "per_page": 100, "total_items": 1 }
}
```

錯誤回應範例：

```json
{
  "code": 422,
  "message": "The bet start date field is required when result start date is not present. (and 1 more error)",
  "data": null,
  "timestamp": "2025-05-07T02:38:03+00:00"
}
```

### 12.8 對帳對接要點（APIs 專案視角）

> 此小節為 APIs 專案專屬，原始文檔未提及。

- **三方鏡像對帳定位**：本報表的「每局一筆 `data` + 局內 `line_items` 逐筆」結構，正對應我方比照 PPSW `game_rounds` 的 PTSW 三方鏡像對帳設計；`game_round` 對應局鍵、`transaction_code` 對應局內交易鍵。
- **分頁迴圈**：以 `page` 由 1 遞增拉取，至 `pagination.current_page == pagination.last_page` 結束；`per_page` 取較大值（範例 `1000`）減少請求數，但須容忍回應實際 `per_page` 與請求值不一致（範例請求 1000、回應 100）。
- **時區切口**：`timezone` 決定 `bet_date` / `result_date` 的呈現 offset。我方 BR / TBR / SBR 時間以 **UTC** 儲存，對帳切口換算須一致：固定傳同一 `timezone`、並把回應時間統一轉回 UTC 後再比對，避免日界與 offset 造成漏單／重複。
- **金額入境正規化**：`bet` / `win` / `refund` / `amount` 等數值欄位 JSON decode 後，參與運算或比較前一律 `Common::numAdd('0', (string) $value, 4)` 正規化，禁止直接 `(string) $value`；金額運算只走 `Common::numAdd/Sub/Mul/Div`。
- **金額單位**：範例 `line_items[].amount` 與 `data` 層 `bet`/`win` 同量級（投注為負、派彩為正），未見最小貨幣單位換算；實際單位仍以串接時實測為準，勿臆測為 minor units。
- **退款 / 未結算判讀**：以 `is_refund` 與 `line_items[].type=REFUND` 識別退款局；`is_closed=false` / `status` 非 `closed` 為未結算局，對帳時須與我方 refund deal_record（PTSW 退款 `applied_at=NULL`）規則對齊。
- **分項涵蓋**：對帳的投注 / 派彩彙總須涵蓋 `progressive_*`、`free_spin_*`、`golden_chip_*` 各分項，勿只取 `bet`/`win` 主額，否則含累積獎金或免費旋轉的局會對不平。

---

## 13. APIs 專案串接補充

> 此節為 APIs 專案專屬，原始文檔未提及。

- **路由位置**：`routes/external/sw.php`（單一錢包路由檔）
- **Controller**：`App\Http\Controllers\External\SingleWallets\PlayTechController`
- **金額正規化**：JSON decode 取得的 `amount` / `balance.real` 等欄位，進入時必須以 `Common::numAdd('0', (string) $value, 4)` 正規化，禁止直接 `(string) $value` 後運算（`decimal(22,2)` → PHP float 仍有精度誤差）。
- **回調冪等（實作現況）**：`bet` / `gameRoundResult` 先取同局 MySQL named lock（`App\Vendors\PlayTechSW\GameRoundLock`，key 為 `gameRoundCode + username`，5 秒逾時，錯誤碼依 action 分流——`bet` 回 `ERR_TRANSACTION_DECLINED`、`gameRoundResult` 回可重試的 `INTERNAL_ERROR`，理由見 `integration.md`「named lock 逾時的錯誤碼依 action 分流」；與 `bet-record-recovery:refund-unsettled` 命令共用同一把）序列化同局併發；鎖在 `DB::transaction` 之外取得、commit 後才釋放。交易內 `createDealRecord` 先以 `requestId + gameRoundCode`、再以 `gameRoundCode + transactionCode` 查 `ptsw_deal_records`，命中即不進業務段、直接回**當下餘額**（200，帶既有 `externalTransactionCode`）；未命中才寫入並處理，併發撞唯一鍵（1062）時 re-select 既有列。`transferFunds` 無 `gameRoundCode`，不取鎖、以 `requestId + transactionCode` 冪等。**不是** `lockForUpdate()` 鎖交易紀錄，重複請求回的也**不是**首次回應的重放——首次被拒（`ERR_NO_BET`）的 `transactionCode` 重送會回 200，取捨見 `integration.md` 退款列。
- **對帳 / SBR 重建 / refund deal_records 結構**：參考 `docs/reconcile/vendors/PTSW.md`。
