# Rectangle Games — Seamless Wallet API 整合文件

> 原始規格版本：*Rectangle Games Integration Document* v1.24（2025-05-29）
> 本文僅整理 **Seamless Wallet（運營商提供錢包）** 相關內容，供後端串接參考；原文 §4.4 的 **Transfer Wallet**（Rectangle Games 提供錢包）架構不在本專案（RectangleSW, vendor_id=234）採用範圍內，如需參考請查閱官方原文件。

---

## 目錄

1. [概述](#1-概述)
2. [遊戲啟動網址（URL Scheme）](#2-遊戲啟動網址url-scheme)
3. [API 通用規範與安全](#3-api-通用規範與安全)
4. [Hash Authentication 簽名驗證](#4-hash-authentication-簽名驗證)
5. [API 方法總表](#5-api-方法總表)
6. [PlayerAuthenticateV1](#6-playerauthenticatev1)
7. [PlayerWalletV1](#7-playerwalletv1)
8. [PayoutTransactionV1](#8-payouttransactionv1)
9. [ListAvailableGamesV1](#9-listavailablegamesv1)
10. [其他一般 API（未串接，供參考）](#10-其他一般-api未串接供參考)
11. [Resolve pending bets（重試與人工介入）](#11-resolve-pending-bets重試與人工介入)
12. [Setting player RTP level](#12-setting-player-rtp-level)
13. [錯誤碼](#13-錯誤碼)
14. [幣別](#14-幣別)
15. [Bet Types](#15-bet-types)
16. [Languages](#16-languages)

---

## 1. 概述

本文件描述**運營商錢包模式**（Seamless Wallet）的整合方式：玩家遊玩期間，Rectangle Games 會即時呼叫運營商提供的 API，在每一筆交易發生時對玩家餘額做加減。

- 傳輸方向：**Rectangle Games → 運營商**（RG 呼叫運營商的 callback API）
- 請求／回應格式：JSON
- 傳輸須經 HTTPS（SSL/TLS）

---

## 2. 遊戲啟動網址（URL Scheme）

玩家須先於運營商網站登入，再由運營商的遊戲大廳頁面導向遊戲。遊戲網址（`<game-play-url>`）由 [ListAvailableGamesV1](#9-listavailablegamesv1) 取得。

```
GET https://<game-play-url>/?oc=demo&t=a6aot45gc1nni92jm7gpn4n6&l=pt_br&r=https%3A%2F%example.invalid%2F&bs=2&bl=8
```

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `oc` | String | 是 | Operator Code，由 Rectangle Games 提供，唯一識別運營商 |
| `t` | String | 是 | 運營商產生的玩家 session token；Rectangle Games 之後會帶著此 token 呼叫運營商的 [PlayerAuthenticateV1](#6-playerauthenticatev1) 驗證 |
| `l` | String | 否 | 遊戲語言，ISO 639 兩碼；未指定或該語言尚未支援翻譯時使用預設語言 |
| `r` | String | 否 | 遊戲退出網址（需 URI-encode）。未提供時預設重新載入同一網址 |
| `bs` | Number | 否 | 玩家預設投注額度（索引值 1～4，非實際金額，1 為最小、4 為最大） |
| `bl` | Number | 否 | 玩家預設投注等級（1～10，1 為最小、10 為最大） |

> 官方另提供 **Web Lobby**（`LobbyV1`）與 **Multi-game Launcher**（`LauncherV1`）兩種啟動模式，參數與 URL Scheme 大致相同（Launcher 額外支援 `g` 參數預載最多 4 款遊戲），本專案目前僅使用 URL Scheme 模式，故不展開。

---

## 3. API 通用規範與安全

### 3.1 傳輸安全

API 呼叫受以下兩層防護：

- **IP 白名單**（必要）：雙方於 onboarding 階段交換已知呼叫來源 IP
- **mTLS**（選用）：於網路／基礎設施層實作雙向 TLS 認證

### 3.2 請求／回應格式

- Content-Type：`application/json`
- 運營商 API 成功時須回傳 HTTP `200`；任何非 200 狀態碼一律視為錯誤
- Rectangle Games 提供的一般 API：成功回傳 `200`，失敗回傳 `4xx`

### 3.3 錯誤回應格式（運營商 → Rectangle Games）

運營商的 callback API 發生錯誤時，須依下列格式回應：

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `message` | String | 是 | 給玩家看的錯誤訊息，建議使用玩家慣用語言、以玩家能理解的方式描述；此訊息會原樣顯示給玩家 |
| `error` | Any | 否 | 錯誤的額外細節，Rectangle Games 僅內部記錄供除錯用 |
| `code` | Number | 是 | 錯誤碼（非 HTTP status code），見 [§13 錯誤碼](#13-錯誤碼) |

若回應無法依此格式解析，或 `message` 欄位缺漏／為空，Rectangle Games 會改用通用錯誤訊息顯示給玩家（依運營商預設語言），例如：

> 「Error communicating with `<Operator name>` system. Please contact `<Operator name>` customer support for assistance. Request ID: `<Unique request ID>`」

### 3.4 Rectangle Games 提供的一般 API 錯誤回應格式

與 3.3 類似，另外多帶 `requestId`：

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `message` | String | 是 | 人類可讀的錯誤描述 |
| `error` | Any | 否 | 額外錯誤細節 |
| `requestId` | String | 是 | 唯一請求識別碼，troubleshooting 用 |
| `code` | Number | 是 | 錯誤碼，見 [§13 錯誤碼](#13-錯誤碼) |

---

## 4. Hash Authentication 簽名驗證

除 IP 白名單與 mTLS 外，Rectangle Games 另提供 Hash Authentication 供運營商驗證請求確實來自 Rectangle Games。雙方於 onboarding 階段交換共用密鑰（shared key）。

驗證所需資料皆帶在請求 header：

| Header | 說明 | 範例 |
|---|---|---|
| `Host` | 運營商 callback 網域名稱 | `example.invalid` |
| `X-Date` | 目前日期（UTC），格式 `YYYYMMDD` | `20240425` |
| `X-Content-SHA256` | 請求 body 的 SHA256 雜湊值 | `802786eddaf7db4f07f539532b284527bd7a7f2658b8fdb7b374c3091463c6bf` |
| `Authorization` | 認證資料 | `RG-HMAC-SHA256 SignedHeaders=host;x-content-sha256;x-date,Signature=258b5fa8e7baecbef5240e9e4916f070b9462fcb844695d00895517ae5e53254` |

`Authorization` header 組成：

| 名稱 | 說明 |
|---|---|
| Scheme | 固定為 `RG-HMAC-SHA256` |
| `SignedHeaders` | 參與簽名運算的 header 名稱清單，以 `;` 分隔、皆小寫（例：`host;x-content-sha256;x-date`） |
| `Signature` | 256-bit 簽名值，以 64 個小寫十六進位字元表示 |

簽名計算方式：

```
Signature = HMAC-SHA256(pre-shared key, Host + X-Content-SHA256 + X-Date)
```

即依序將 `Host`、`X-Content-SHA256`、`X-Date` 三個 header 值**直接串接**（不加分隔符）後，以共用密鑰做 HMAC-SHA256。

---

## 5. API 方法總表

### 5.1 運營商提供（Seamless Wallet，Rectangle Games 呼叫運營商）

| 方法 | 路徑 | 說明 |
|---|---|---|
| **PlayerAuthenticateV1** | `POST /api/v1/authenticate` | 驗證玩家 session token，取得錢包餘額 |
| **PlayerWalletV1** | `GET /api/v1/wallets` | 查詢玩家可用於所有 Rectangle Games 遊戲的錢包總餘額 |
| **PayoutTransactionV1** | `POST /api/v1/transactions` | 投注（transfer in）與派彩（transfer out）合併的單一交易端點 |

### 5.2 Rectangle Games 提供（一般 API，運營商呼叫 Rectangle Games）

| 方法 | 路徑 | 說明 |
|---|---|---|
| **ListAvailableGamesV1** | `GET /api/v1/available-games` | 取得可用遊戲清單 |
| GameDetailsV1 | `GET /api/v1/available-games/<game-code>` | 取得單一遊戲詳情 |
| PlayerBetHistoryV1 | `GET /api/v1/bets` | 查詢玩家投注紀錄（分頁） |
| PlayerBetDetailsV1 | `GET /api/v1/bets/<bet-id>` | 查詢單筆投注詳情 |

> 粗體為本專案（RectangleSW）目前已串接的方法；其餘列於 [§10](#10-其他一般-api未串接供參考) 供未來需要時參考。

---

## 6. PlayerAuthenticateV1

**`POST /api/v1/authenticate`**

玩家啟動遊戲時，Rectangle Games 呼叫此 API 驗證遊戲啟動網址中的 session token，並取得玩家錢包餘額。

### Request

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `token` | String | 是 | 運營商產生的玩家 session token（即遊戲啟動網址中的 `t` 參數） |

### Response（成功）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `id` | String | 是 | 運營商系統內的玩家 ID，報表中會顯示為玩家的 Ref |
| `balance` | Number | 是 | 玩家錢包餘額。FIAT 幣別最多 2 位小數、CRYPTO 幣別最多 9 位小數，超出位數會被截斷（例：回傳 `2.125` 會被解讀為 `2.12`） |
| `currency` | String | 是 | 玩家錢包幣別。FIAT 用 ISO 4217 三碼；CRYPTO 用 Rectangle Games 定義的代碼（見 [§14 幣別](#14-幣別)） |
| `language` | String | 否 | 玩家偏好語言 |
| `level` | Number | 否 | 玩家偏好 RTP 等級，見 [§12](#12-setting-player-rtp-level) |
| `maxWin` | Number | 否 | 最大贏分限制（投注金額的倍數） |
| `minWin` | Number | 否 | 最小贏分限制（投注金額的倍數） |
| `group` | String | 否 | 玩家分組，僅供內部追蹤 |
| `betSizeIndex` | Number | 否 | 玩家預設投注額度索引（1～4）；啟動網址帶的 `bs` 參數優先於此值 |
| `betLevelIndex` | Number | 否 | 玩家預設投注等級（1～10）；啟動網址帶的 `bl` 參數優先於此值 |
| `depositCount7Days` / `depositAmount7Days` | Number | 否 | 近 7 天存款次數／金額 |
| `depositCount30Days` / `depositAmount30Days` | Number | 否 | 近 30 天存款次數／金額 |
| `lastDepositTime` / `lastDepositAmount` | String / Number | 否 | 最近一次存款時間（ISO 8601）／金額 |
| `betCount7Days` / `betAmount7Days` | Number | 否 | 近 7 天投注次數／金額 |
| `betCount30Days` / `betAmount30Days` | Number | 否 | 近 30 天投注次數／金額 |
| `betCountHourly7Days` / `betCountHourly30Days` | Number[] | 否 | 近 7／30 天每小時平均投注次數，固定 24 個元素，索引 0 對應 00:00–01:00，依序類推 |

### Response（失敗）

以下情況須回傳錯誤（格式見 [§3.3](#3-api-通用規範與安全)）：

- token 無效（過期或未綁定任何玩家）

---

## 7. PlayerWalletV1

**`GET /api/v1/wallets`**

查詢玩家可用於所有 Rectangle Games 遊戲的錢包總餘額（含現金錢包）。

### Request

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `token`（query string） | String | 是 | 玩家 session token |

### Response（成功）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `id` | String | 是 | 運營商系統內的玩家 ID |
| `balance` | Number | 是 | 玩家錢包餘額，精度規則同 [§6](#6-playerauthenticatev1) |
| `currency` | String | 是 | 玩家錢包幣別 |

### Response（失敗）

- token 無效（過期或未綁定任何玩家）

---

## 8. PayoutTransactionV1

**`POST /api/v1/transactions`**

投注（transfer in）與派彩（transfer out）**合併在同一個端點**處理，運營商需依請求內容更新玩家錢包餘額。

### 重試規則

失敗請求會被 Rectangle Games 重試，規則如下：

- 運營商回傳 HTTP `400`～`499`，或錯誤碼落在 `1000`～`1999` 區間：**不會**重試
- 網路／連線層錯誤（socket timeout、response timeout 等）：**會**重試；重試請求帶**相同的 `Idempotency-Key` header**，採指數退避（exponential backoff）演算法，直到成功為止

運營商收到請求後，須依下列規則確保冪等性：

- 每個請求皆須執行 Hash Authentication 驗證（見 [§4](#4-hash-authentication-簽名驗證)）
- `Idempotency-Key` 用於識別交易，同一 key 的請求須依下列方式處理：
  - 若原始請求已成功處理過，重試請求應**忽略處理**、直接回傳先前的成功回應
  - 若原始請求收到但**未成功處理**，應重新處理並回傳對應結果
- 重試中的注單狀態會被標記為 `OPERATOR_ERROR`，可透過 `PlayerBetHistoryV1` / `PlayerBetDetailsV1` 查詢

### Request Header

| Header | 值 | 說明 |
|---|---|---|
| `Content-Type` | `application/json` | |
| `Idempotency-Key` | — | 冪等鍵，格式參考 [IETF draft](https://example.invalid/doc/html/draft-ietf-httpapi-idempotency-key-header-00)；運營商對已處理過的相同 key 應忽略後續請求 |

### Request Body

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `token` | String | 是 | 玩家 session token |
| `gameId` | String | 是 | 遊戲 ID |
| `gameCode` | String | 是 | 遊戲代碼 |
| `betId` | String | 是 | 注單 ID |
| `betType` | String | 是 | 投注類型，見 [§15 Bet Types](#15-bet-types)：`NORMAL`、`WIN_FREESPIN`、`BUY_FREESPIN`、`GAMBLE`、`JACKPOT` |
| `parentBetId` | String | 否 | 父注單 ID |
| `betAmount` | Number | 是 | 投注金額。FIAT 最多 2 位小數、CRYPTO 最多 9 位小數；免費局為 `0.00` |
| `winAmount` | Number | 是 | 派彩金額，精度規則同上 |
| `isEndRound` | Boolean | 是 | 本局是否已結束 |
| `currency` | String | 是 | 玩家錢包幣別 |
| `transactionId` | String | 是 | 交易 ID |
| `created` | String | 是 | 交易建立時間（ISO 8601） |
| `updated` | String | 是 | 交易更新時間（ISO 8601） |

### Response（成功）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `id` | String | 是 | 運營商系統內的玩家 ID |
| `balance` | Number | 是 | 更新後的玩家錢包餘額 |
| `currency` | String | 是 | 玩家錢包幣別 |
| `level` | Number | 否 | 玩家偏好 RTP 等級，見 [§12](#12-setting-player-rtp-level) |

### Response（失敗）

- token 無效（過期或未綁定任何玩家）
- 請求幣別與玩家錢包幣別不一致
- 玩家餘額不足以支付 `betAmount`

---

## 9. ListAvailableGamesV1

**`GET /api/v1/available-games`**

取得可用遊戲清單。

### Request

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `oc` | String | 是 | Operator Code |
| `l` | String | 否 | 語言碼（ISO 639 兩碼），未指定或不支援時用預設語言 |
| `provider` | String | 否 | 篩選特定 provider：`RG`、`RC`、`PG`、`PP`、`HS` |

### Response（成功）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `launcherUrl` | String | 是 | Multi-game Launcher 網址 |
| `data[].name` | String | 是 | 遊戲名稱 |
| `data[].description` | String | 是 | 遊戲描述 |
| `data[].code` | String | 是 | 遊戲唯一代碼 |
| `data[].url` | String | 是 | 遊戲啟動網址；因地理限制此網址可能變動，**建議快取不超過 2 小時** |
| `data[].preview` | String | 是 | 遊戲預覽圖網址 |
| `data[].type` | String | 是 | 遊戲類型 |
| `data[].status` | String | 是 | `ACTIVE` / `INACTIVE` |
| `data[].launcherUrl` | String | 是 | 預載該遊戲的 Multi-game Launcher 網址 |

### Response（失敗）

- Operator Code 未提供或無法識別

---

## 10. 其他一般 API（未串接，供參考）

以下方法目前未串接，先記錄用途供未來需要時參考；細節請查閱官方原文件對應章節。

| 方法 | 路徑 | 說明 |
|---|---|---|
| **GameDetailsV1** | `GET /api/v1/available-games/<game-code>?oc=<oc>&l=<l>` | 取得單一遊戲詳情（欄位同 ListAvailableGamesV1 的 `data[]`） |
| **PlayerBetHistoryV1** | `GET /api/v1/bets` | 依 `ref`（運營商玩家 ID）分頁查詢投注紀錄，支援 `from`/`to`/`limit`/`cursor` |
| **PlayerBetDetailsV1** | `GET /api/v1/bets/<bet-id>` | 查詢單筆投注詳情，含 `status`（`PENDING`/`RESOLVED`/`OPERATOR_ERROR`/`CANCELLED`）等欄位 |

---

## 11. Resolve pending bets（重試與人工介入）

因網路延遲、系統故障、逾時等因素，可能出現 pending 狀態的注單。多數情況下會依 [§8 重試規則](#8-payouttransactionv1) 自動解決，但下列情境會維持 pending：

- Rectangle Games 未收到運營商任何回應
- Rectangle Games 收到運營商的錯誤回應

此時運營商可申請人工介入解決；完整流程圖請見官方原文件 §5（本文未附圖）。

---

## 12. Setting player RTP level

Rectangle Games 允許運營商為玩家設定 RTP 等級。等級與對應的理論賠付率／波動性：

| Level | 理論賠付率 | 波動性 | Level | 理論賠付率 | 波動性 |
|---|---|---|---|---|---|
| 97 | 80% | Low | 98 | 80% | Medium |
| 99 | 85% | Low | 100 | 85% | Medium |
| 101 | 88% | Low | 102 | 88% | Medium |
| 103 | 90% | Low | 104 | 90% | Medium |
| 105 | 92% | Low | 106 | 92% | Medium |
| 107 | 94% | Low | 108 | 94% | Medium |
| 109 | 96.5% | Low | 110 | 96.5% | Medium |
| 111 | 98% | Low | 112 | 98% | Medium |
| 113 | 105% | Low | 114 | 105% | Medium |
| 115 | 115% | Low | 116 | 115% | Medium |
| 117 | 130% | Low | 118 | 130% | Medium |
| 119 | 150% | Low | 120 | 150% | Medium |

未設定時使用該遊戲的預設 RTP。

### 設定管道與優先順序

RTP 等級可透過三種管道設定，優先序由高到低：

1. `PayoutTransactionV1` 回應中的 `level`（僅影響**後續**投注）
2. `PlayerAuthenticateV1` 回應中的 `level`（整個 session 的等級）
3. 運營商的預設等級（由 Rectangle Games 代表設定）

任何時候在 `level` 回傳 `null` 即可重置回上一層優先序的設定。

**範例序列**：

| # | 玩家操作 | API | 該次 spin 使用的 RTP | 回應中的 `level` | 說明 |
|---|---|---|---|---|---|
| 1 | 開啟遊戲 | PlayerAuthenticateV1 | — | `1` | |
| 2 | Spin | PayoutTransactionV1 | 1 | `null` | 沿用第 1 步設定的 level 1 |
| 3 | Spin | PayoutTransactionV1 | 1 | `5` | 本次未變更等級，仍用 level 1；但同時把後續等級設為 5 |
| 4 | Spin | PayoutTransactionV1 | 5 | `null` | 沿用第 3 步設定的 level 5 |
| 5 | Spin | PayoutTransactionV1 | 1 | `null` | 第 4 步回傳 `null`，重置回第 1 步設定的 level 1 |

---

## 13. 錯誤碼

除標準 HTTP status code（4xx 為客戶端錯誤、5xx 為伺服器錯誤）外，Rectangle Games 另提供以下錯誤碼協助排查：

| 錯誤碼 | 說明 |
|---|---|
| `1000` | Operator Code 無效 |
| `1100` | 遊戲代碼無效 |
| `1200` | 玩家 token 無效 |
| `1300` | 玩家 session 無效 |
| `1301` | 玩家帳號被鎖定 |
| `1302` | 遊戲已關閉 |
| `1400` | 玩家狀態無效 |
| `1500` | Feature buy 無效 |
| `1600` | 投注金額無效 |
| `1601` | 幣別無效 |
| `1602` | 餘額不足 |
| `1800` | 請求無效（方法不存在或不允許） |
| `2000` | 運營商錯誤（原因未指定） |
| `2100` | 運營商尚未完成設定 |
| `2200` | 運營商請求錯誤（一般性） |
| `2201` | 運營商請求逾時 |
| `2202` | 運營商回應格式錯誤 |
| `2300` | 簽名驗證失敗（forbidden） |
| `2401` | 找不到注單 |
| `2402` | 重複交易 |
| `2403` | 重複發獎 |
| `3000` | 內部伺服器錯誤 |

---

## 14. 幣別

> 官方文件另附各幣別符號（含部分特殊符號在轉存過程中編碼損毀），此處僅保留幣別名稱與代碼；符號如有需要請查閱官方原文件。

### FIAT

`USD`、`DZD`、`ARS`、`AMD`、`AUD`、`BDT`、`BND`、`BRL`、`BGN`、`KHR`、`CAD`、`CLP`、`CNY`、`COP`、`KMF`、`CRC`、`HRK`、`CZK`、`DKK`、`DJF`、`DOP`、`EGP`、`ERN`、`EUR`、`FJD`、`GBP`、`GEL`、`GHS`、`HKD`、`HUF`、`ISK`、`INR`、`IDR`、`ILS`、`JPY`、`JOD`、`KZT`、`KES`、`KGS`、`LAK`、`MYR`、`MXN`、`MMK`、`NZD`、`NGN`、`NOK`、`PKR`、`PEN`、`PHP`、`PLN`、`RMB`、`RON`、`RUB`、`SGD`、`ZAR`、`KRW`、`SEK`、`CHF`、`TWD`、`THB`、`TRY`、`UAH`、`AED`、`VES`、`CDF`、`JPU`、`NPR`、`AZN`、`BOB`、`GTQ`、`HNL`、`LBP`、`LKR`、`MDL`、`NIO`、`TND`、`UYU`、`MNT`、`UZS`、`BYN`

> 本專案（RectangleSW）`transactions()` 端點目前僅開放 `MYR`，見 `integration.md`「幣別限制」一節。

### CRYPTO

`USDT`、`USDC`、`ETH`、`TRX`、`BTC`、`BNB`、`LTC`、`CRO`、`DAI`、`UNI`、`WBTC`、`WETH`、`SHIB`、`POL`、`OP`、`ARB`、`DOGE`、`SAND`、`SOL`

---

## 15. Bet Types

| Bet Type | Game Mode |
|---|---|
| `NORMAL` | Base game |
| `ANTE` | Base game |
| `WIN_FREESPIN` | Free game |
| `WIN_FREESPIN_LAST` | Free game |
| `BUY_FREESPIN` | Bonus |
| `BUY_FREESPIN_LAST` | Bonus |
| `FREE_SPIN` | Free game / Bonus |
| `GAMBLE` | Feature |
| `GAMBLE_LAST` | Feature |
| `JACKPOT` | Feature |
| `CARD_PICK` | Feature |

---

## 16. Languages

| Code | Language | Code | Language |
|---|---|---|---|
| `en` | English | `pt` | Portuguese |
| `zh` | Chinese | `pt-br` | Brazilian Portuguese |
| `th` | Thai | `sv` | Swedish |
| `id` | Indonesian | `tr` | Turkish |
| `ko` | Korean | `bg` | Bulgarian |
| `ja` | Japanese | `cs` | Czech |
| `da` | Danish | `nl` | Dutch |
| `de` | German | `el` | Greek |
| `es` | Spanish | `hu` | Hungarian |
| `es-la` | Spanish LATAM | `ro` | Romanian |
| `fr` | French | `sk` | Slovakian |
| `it` | Italian | `fi` | Finnish |
| `no` | Norwegian | `uk_ua` | Ukrainian |
| `pl` | Polish | `ru` | Russian |
| `tzh` | Traditional Chinese | | |

> 本專案（`Driver::getLanguage()`）目前僅對照 `zh`（簡中）、`tzh`（繁中）、`th`（泰文）、`id`（印尼文），其餘一律 fallback 為 `en`。
