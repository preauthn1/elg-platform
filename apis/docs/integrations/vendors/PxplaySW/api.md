# Pxplay — Seamless Wallet API 整合文件

> 原始規格版本：*Pxplay 整合文件* v1.24（2025-09-26）
> 本文僅整理 **Seamless Wallet（運營商提供錢包）** 相關內容；原文第 4.4 節的 **Transfer Wallet**（Pxplay 提供錢包）架構不在本專案（PxplaySW, vendor_id=236）採用範圍內。
>
> ⚠️ **來源文件編碼損毀**：使用者提供的原始文件在轉存過程中文繁體中文內容大量變成無法還原的亂碼（UTF-8 位元組遺失，非單純錯誤編碼可逆轉）。本檔內容以下方式重建：
> 1. 文件內殘留可辨識的英文欄位名、JSON 範例、數字、錯誤碼等逐字保留；
> 2. 中文說明文字依**同平台**（Authorization header 簽名 scheme literally 就是 `RG-HMAC-SHA256`，與 `docs/integrations/vendors/RectangleSW/api.md` 完全同名，且章節結構、欄位、範例格式逐條對應）的 RectangleSW 官方文件（英文版 v1.24，內容已核實）比對重建；
> 3. 兩份文件在章節結構上有數處**確認的實際差異**（多了 TestTokenV1、少了 RTP 等級設定、移除了 Multi-game Launcher、錯誤碼少一條 2403），這些差異依 Pxplay 原文可辨識片段如實記錄，不套用 RectangleSW 的內容。
> **如需逐字精確的中文措辭，請提供未損毀的原始文件重新核對。**

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
9. [TestTokenV1（測試令牌，非強制但強烈建議）](#9-testtokenv1測試令牌非強制但強烈建議)
10. [ListAvailableGamesV1](#10-listavailablegamesv1)
11. [其他一般 API（未串接，供參考）](#11-其他一般-api未串接供參考)
12. [Resolve pending bets（重試與人工介入）](#12-resolve-pending-bets重試與人工介入)
13. [錯誤碼](#13-錯誤碼)
14. [幣別](#14-幣別)
15. [Bet Types](#15-bet-types)
16. [Languages](#16-languages)

---

## 1. 概述

本文件說明運營商錢包模式（Seamless Wallet，亦稱無縫錢包模式）的對接方式：玩家遊玩期間，Pxplay 系統會針對每一筆交易即時同步調用運營商提供的 API，以實現玩家餘額的增減。

- 傳輸方向：**Pxplay → 運營商**（Pxplay 呼叫運營商的 callback API）
- 請求／回應格式：JSON
- 傳輸須經 HTTPS（SSL/TLS）

---

## 2. 遊戲啟動網址（URL Scheme）

> ⚠️ 與 RectangleSW 的差異：Pxplay v1.21（2025-07-07）已**移除 Multi-game Launcher（多遊戲啟動器）**，該功能不再支援；v1.20（2025-06-30）起 `ListAvailableGamesV1` / `GameDetailsV1` 回應中也移除了 `launcherUrl` 欄位。故本節僅有 URL Scheme 一種啟動模式（原文另有 Web 大廳模式 `LobbyV1`，結構相同，不贅述）。

玩家須先於運營商網站登入，再由運營商的遊戲大廳頁面導向遊戲。遊戲網址由 [ListAvailableGamesV1](#10-listavailablegamesv1) 取得。

```
GET https://<game-play-url>/?oc=<oc>&t=<token>&l=<language>&r=<exit_url>
```

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `oc` | String | 是 | Operator Code，由 Pxplay 提供，唯一識別運營商 |
| `t` | String | 是 | 運營商產生的玩家 session token；Pxplay 之後會帶著此 token 呼叫運營商的 [PlayerAuthenticateV1](#6-playerauthenticatev1) 驗證 |
| `l` | String | 否 | 遊戲語言，ISO 639 兩碼；未指定或該語言尚未支援翻譯時使用預設語言 |

---

## 3. API 通用規範與安全

### 3.1 傳輸安全

API 呼叫受以下防護（層級與 RectangleSW 相同）：

- **IP 白名單**（強制）：雙方於 onboarding 階段交換已知呼叫來源 IP
- **mTLS**（可選）

### 3.2 請求／回應格式

- Content-Type：`application/json`
- 運營商 API 成功時須回傳 HTTP `200`；任何非 200 狀態碼一律視為錯誤

### 3.3 錯誤回應格式（運營商 → Pxplay）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `message` | String | 是 | 給玩家看的錯誤訊息 |
| `error` | Any | 否 | 錯誤的額外細節，Pxplay 僅內部記錄供除錯用 |
| `code` | Number | 是 | 錯誤碼（非 HTTP status code），見 [§13 錯誤碼](#13-錯誤碼) |

範例：

```json
{
  "message": "get Pxplay auth cache failed, session expired",
  "code": 1300
}
```

---

## 4. Hash Authentication 簽名驗證

與 RectangleSW **完全相同的機制**（原文逐字沿用 `RG-HMAC-SHA256` scheme 名稱，證實兩者為同一底層平台的不同品牌）。標記為可選（optional），但文件建議運營商對所有來自 Pxplay 的請求做簽名驗證。

| Header | 說明 |
|---|---|
| `Host` | 運營商 callback 網域名稱 |
| `X-Date` | 目前日期（UTC），格式 `YYYYMMDD` |
| `X-Content-SHA256` | 請求 body 的 SHA256 雜湊值 |
| `Authorization` | `RG-HMAC-SHA256 SignedHeaders=host;x-content-sha256;x-date,Signature=<64 hex>` |

簽名計算：

```
Signature = HMAC-SHA256(pre-shared key, Host + X-Content-SHA256 + X-Date)
```

---

## 5. API 方法總表

### 5.1 運營商提供（Seamless Wallet，Pxplay 呼叫運營商）

| 方法 | 路徑 | 說明 |
|---|---|---|
| **PlayerAuthenticateV1** | `POST /api/v1/authenticate` | 驗證玩家 session token，取得錢包餘額 |
| **PlayerWalletV1** | `GET /api/v1/wallets` | 查詢玩家錢包總餘額 |
| **PayoutTransactionV1** | `POST /api/v1/transactions` | 投注（轉入）與派彩（轉出）合併的單一交易端點 |
| TestTokenV1 | `POST /api/v1/testtokens` | 非強制、強烈建議：供 Pxplay 取得測試玩家令牌，用於營運期間自動連通性驗證 |

### 5.2 Pxplay 提供（一般 API，運營商呼叫 Pxplay）

| 方法 | 路徑 | 說明 |
|---|---|---|
| **ListAvailableGamesV1** | `GET /api/v1/available-games` | 取得可用遊戲清單 |
| GameDetailsV1 | `GET /api/v1/available-games/<game-code>` | 取得單一遊戲詳情 |
| PlayerBetHistoryV1 | `GET /api/v1/bets` | 查詢玩家投注紀錄（分頁） |
| PlayerBetDetailsV1 | `GET /api/v1/bets/<bet-id>` | 查詢單筆投注詳情 |

> 粗體為本專案（PxplaySW）目前已串接的方法。**TestTokenV1** 官方文件雖標記非強制，但強烈建議提供且未來 Pxplay 會定期自動調用做連通性驗證——目前本專案**未實作**此端點（`routes/external/sw.php` 只註冊了 authenticate / wallets / transactions 三條路由），見 `integration.md` 的落差記錄。其餘列於 [§11](#11-其他一般-api未串接供參考)。

---

## 6. PlayerAuthenticateV1

**`POST /api/v1/authenticate`**

玩家啟動遊戲時，Pxplay 呼叫此 API 驗證 session token，並取得玩家錢包餘額。

### Request

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `token` | String | 是 | 玩家 session token |

```json
{ "token": "2D8679DE-4767-45B7-8762-109A1D1306F6" }
```

### Response（成功）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `id` | String | 是 | 運營商系統內的玩家 ID，報表顯示為 Ref |
| `balance` | Number | 是 | 玩家錢包餘額。FIAT 最多 2 位小數、CRYPTO 最多 9 位小數，超出位數截斷 |
| `currency` | String | 是 | 玩家錢包幣別 |
| `language` | String | 否 | 玩家偏好語言 |
| `nickName` | String | 否 | 玩家暱稱；若運營商希望啟用排行榜／賽事顯示功能則為必填 |
| `picture` | String | 否 | 玩家頭像公開網址；同上情境下為必填 |
| `group` | String | 否 | 玩家分組，供內部追蹤 |

```json
{
  "id": "FC20250101123456BRL",
  "balance": 12.96,
  "currency": "BRL",
  "language": "pt-br",
  "nickName": "Richkid99",
  "picture": "https://example.invalid/..."
}
```

> 與 RectangleSW 的差異：Pxplay 多了 `nickName` / `picture`（排行榜／個人化顯示用），**沒有** RectangleSW 的存款/投注統計類擴充欄位（`depositCount7Days` 等），也沒有 RTP `level` 欄位。

### Response（失敗）

```json
{ "message": "Invalid player token", "code": 1200 }
```

---

## 7. PlayerWalletV1

**`GET /api/v1/wallets`**

### Request

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `token`（query string） | String | 是 | 玩家 session token |

### Response（成功）

```json
{ "id": "FC20250101123456BRL", "balance": 12.96, "currency": "BRL" }
```

### Response（失敗）

同 [§6](#6-playerauthenticatev1)（token 無效）。

---

## 8. PayoutTransactionV1

**`POST /api/v1/transactions`**

投注（轉入）與派彩（轉出）合併在同一個端點，運營商需依請求更新玩家錢包餘額。

### 重試規則（與 RectangleSW 相同）

- HTTP `400`～`499` 或錯誤碼 `1000`～`1999`：**不會**重試
- 網路／連線層錯誤：**會**重試，攜帶相同 `Idempotency-Key` header，指數退避直到成功
- 運營商須依 `Idempotency-Key` 判斷重複請求：已成功處理過的直接回傳先前結果；收到但未處理成功的須重新處理
- 重試中的注單標記為 `OPERATOR_ERROR`，可經 `PlayerBetHistoryV1` / `PlayerBetDetailsV1` 查詢

### Request Body

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `token` | String | 是 | 玩家 session token |
| `gameId` | String | 是 | 遊戲 ID |
| `gameCode` | String | 是 | 遊戲代碼 |
| `betId` | String | 是 | 注單 ID |
| `betType` | String | 是 | 投注類型，見 [§15 Bet Types](#15-bet-types) |
| `parentBetId` | String | 否 | 父注單 ID |
| `betAmount` | Number | 是 | 投注金額；免費局為 `0.00` |
| `winAmount` | Number | 是 | 派彩金額 |
| `isEndRound` | Boolean | 是 | 本局是否已結束 |
| `currency` | String | 是 | 玩家錢包幣別 |
| `transactionId` | String | 是 | 交易 ID |
| `created` | String | 是 | 交易建立時間（ISO 8601） |
| `updated` | String | 是 | 交易更新時間（ISO 8601） |
| `walletType` | String | 是 | 交易使用的錢包類型：`CASH` 或 `BONUS` |

> ⚠️ 原文範例表格的 `betId` / `betType` 兩欄範例值明顯對調（`betId` 範例填了 `"BRL"`，`betType` 範例填了 ULID 格式字串），判斷是來源文件表格解析錯位，非欄位定義本身有誤——欄位定義依上表為準。

```json
{
  "token": "2D8679DE-4767-45B7-8762-109A1D1306F6",
  "gameId": "ol4sditcbkvnscdfwxn2arwq",
  "gameCode": "balonismo",
  "betId": "01K608J6J0WQQ0T9KKYWXV8622",
  "betType": "NORMAL",
  "parentBetId": null,
  "betAmount": 10.00,
  "winAmount": 2.00,
  "isEndRound": true,
  "currency": "BRL",
  "transactionId": "01K608J6J0WQQ0T9KKYWXV8622-NORMAL-1758798051",
  "created": "2025-09-25T11:00:51.256Z",
  "updated": "2025-09-25T11:00:51.256Z",
  "walletType": "CASH"
}
```

> 與 RectangleSW 的差異：官方文件明確列出 `walletType` 只有 `CASH` / `BONUS` 兩種值（RectangleSW 只有 `CASH`）；本專案實作（見 `integration.md`）驗證規則額外接受了 `FREE_GAME`，屬於實作超出文件範圍，需與 Pxplay 確認。

### Response（成功）

```json
{ "id": "FC20250101123456BRL", "balance": 12.96, "currency": "BRL" }
```

### Response（失敗）

- 玩家 token 無效
- 請求幣別與玩家錢包幣別不一致
- 玩家餘額不足以支付 `betAmount`

---

## 9. TestTokenV1（測試令牌，非強制但強烈建議）

**`POST /api/v1/testtokens`**

此接口非強制要求，但官方強烈建議提供。用途：

- 供運營商提供測試用玩家令牌，供 Pxplay 於營運維護期間排查問題
- **正式營運期間，Pxplay 會定期自動調用此接口取得測試令牌，並自動驗證串接連通性是否正常**——透過此接口取得的令牌，必須能通過 `PlayerAuthenticateV1`、`PlayerWalletV1`、`PayoutTransactionV1` 三個接口的驗證

### Request Body

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `currency` | String | 是 | 幣別代碼 |
| `balance` | Number | 是 | 測試玩家的初始餘額 |

```json
{ "currency": "BRL", "balance": 1000.00 }
```

### Response（成功）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `id` | String | 是 | 運營商系統內的玩家 ID |
| `balance` | Number | 是 | 測試玩家餘額 |
| `currency` | String | 是 | 幣別 |
| `token` | String | 是 | 與該測試玩家關聯的 session token，後續用於 `PlayerAuthenticateV1` / `PlayerWalletV1` / `PayoutTransactionV1` |
| `expiry` | String | 是 | 令牌過期時間（ISO 8601），過期後需重新呼叫本接口取得新令牌 |

### Response（失敗）

官方文件註明：**此接口不應返回任何錯誤**。

> ⚠️ 本專案目前**未實作**此端點（`PxplaySwController` 只有 `authenticate`/`wallets`/`transactions` 三個 action）。若 Pxplay 正式營運後真的會定期自動調用此接口做連通性驗證，缺這支 API 可能導致 Pxplay 端誤判串接異常，建議評估是否需要補上。

---

## 10. ListAvailableGamesV1

**`GET /api/v1/available-games`**

### Request

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `oc` | String | 是 | Operator Code |
| `l` | String | 否 | 語言碼 |

### Response（成功）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `data[].name` | String | 是 | 遊戲名稱 |
| `data[].description` | String | 是 | 遊戲描述 |
| `data[].code` | String | 是 | 遊戲唯一代碼 |
| `data[].url` | String | 是 | 遊戲啟動網址；建議快取不超過 2 小時 |
| `data[].preview` | String | 是 | 遊戲預覽圖網址 |
| `data[].type` | String | 是 | 遊戲類型 |
| `data[].status` | String | 是 | `ACTIVE` / `INACTIVE` |

> 與 RectangleSW 的差異：**沒有** `launcherUrl` / `data[].launcherUrl` 欄位（v1.20 起移除，因 Multi-game Launcher 已下架）。

---

## 11. 其他一般 API（未串接，供參考）

| 方法 | 路徑 | 說明 |
|---|---|---|
| **GameDetailsV1** | `GET /api/v1/available-games/<game-code>?oc=<oc>&l=<l>` | 單一遊戲詳情（欄位同 §10，同樣無 `launcherUrl`） |
| **PlayerBetHistoryV1** | `GET /api/v1/bets` | 依 `ref` 分頁查詢投注紀錄，支援 `from`/`to`/`limit`/`cursor`；`status` 可為 `PENDING`/`RESOLVED`/`CANCELLED`/`ERROR` |
| **PlayerBetDetailsV1** | `GET /api/v1/bets/<bet-id>` | 單筆投注詳情；`status` 可為 `PENDING`/`RESOLVED`/`OPERATOR_ERROR`/`CANCELLED` |

---

## 12. Resolve pending bets（重試與人工介入）

因網路延遲、系統維護、逾時等因素，可能出現 pending 狀態注單。多數會依 [§8](#8-payouttransactionv1) 重試規則自動解決，但下列情境會維持 pending：

- Pxplay 系統結算未結算注單時未收到運營商任何回應
- Pxplay 系統結算未結算注單時收到運營商的錯誤回應

此時運營商可申請人工結算；完整流程圖見官方原文件（本文未附圖）。

---

## 13. 錯誤碼

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
| `3000` | 內部伺服器錯誤 |

> 與 RectangleSW 的差異：Pxplay 官方錯誤碼表**沒有 `2403`（Duplicate rewards / 重複發獎）**，其餘 20 條與 RectangleSW 完全一致——強烈佐證兩者共用同一底層平台。

---

## 14. 幣別

> 官方文件另附各幣別符號（轉存過程中編碼損毀），此處僅保留幣別名稱與代碼。

### FIAT

`USD`、`DZD`、`ARS`、`AMD`、`AUD`、`BDT`、`BND`、`BRL`、`BGN`、`KHR`、`CAD`、`CLP`、`CNY`、`COP`、`KMF`、`CRC`、`HRK`、`CZK`、`DKK`、`DJF`、`DOP`、`EGP`、`ERN`、`EUR`、`FJD`、`GBP`、`GEL`、`GHS`、`HKD`、`HUF`、`ISK`、`INR`、`IDR`、`ILS`、`JPY`、`JOD`、`KZT`、`KES`、`KGS`、`LAK`、`MYR`、`MXN`、`MMK`、`NZD`、`NGN`、`NOK`、`PKR`、`PEN`、`PHP`、`PLN`、`RMB`、`RON`、`RUB`、`SGD`、`ZAR`、`KRW`、`SEK`、`CHF`、`TWD`、`THB`、`TRY`、`UAH`、`AED`、`VES`、`CDF`、`JPU`、`NPR`、`AZN`、`BOB`、`GTQ`、`HNL`、`LBP`、`LKR`、`MDL`、`NIO`、`TND`、`UYU`、`MNT`、`UZS`、`BYN`、**`PGK`（巴布亞新幾內亞基那）**

> `PGK` 是 Pxplay 文件相對 RectangleSW 文件**多出來**的一種幣別，其餘幣別清單兩份文件完全相同。
>
> 本專案（PxplaySW）目前 TPower 環境設定幣別為 `MYR`，`transactions()` 端點的幣別驗證是動態比對 `vendorInfo->currency`（見 `integration.md`），不像 RectangleSW 寫死 `in:MYR`。

### CRYPTO

`USDT`、`USDC`、`ETH`、`TRX`、`BTC`、`BNB`、`LTC`、`CRO`、`DAI`、`UNI`、`WBTC`、`WETH`、`SHIB`、`POL`、`OP`、`ARB`、`DOGE`、`SAND`、`SOL`

---

## 15. Bet Types

> ⚠️ 原文此表格在轉存過程中欄位錯位嚴重、內容無法可靠還原（無法確認是否與 RectangleSW 的 `NORMAL`/`ANTE`/`WIN_FREESPIN`/`BUY_FREESPIN`/`GAMBLE`/`JACKPOT` 等枚舉完全一致），故不列出具體清單，避免提供錯誤資訊。實務上 `betType` 為自由字串（見 §8），需要精確枚舉值時請取得未損毀的原文件核對，或直接觀察正式環境收到的請求（見 `integration.md`）。

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
