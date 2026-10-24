# AiGamingSW（AI Gaming）— 三方 API 規格

> 原始來源：Postman 文件「AiGaming (Seamless)」（https://example.invalid/view/3316999/2s9YsQ6ooT）。抓取日期：2026-08-31。
> 錢包模式：**Single Wallet（Seamless）**。工單：target/tickets#4251（TPower，MYR）。
> 本檔為廠商 API 純規格重述；測試商戶憑證見工單，不落於 repo。

AI Gaming 為老虎機（Slots）遊戲商。單一錢包模式下玩家餘額由我方保管，AI Gaming 在投注 / 結算時回調我方端點扣加款。

---

## 通用約定

### Base URL

| 方向 | URL |
|---|---|
| 我方 → AI Gaming（Staging） | `https://example.invalid` |
| AI Gaming → 我方（回調） | `https://{domain}/external/api/sw/aigaming`（廠商對此 base 固定追加 `/seamless/{endpoint}`；工單原登記 `.../sw/ai`，已改用完整名，需請廠商同步更新商戶設定） |
| 設定來源 | `vendor_infos.info.API_URL` |

### 請求格式

- 除 `/game/getGameList`（GET）外，所有 API 一律 `POST`，Content-Type `application/json`。
- `timestamp`：Unix timestamp **毫秒**。
- `walletType`：幣別（如 `MYR`、`USDT`）。
- `provider`：商戶代碼（Merchant ID，如 `TPOWER_t`）。
- `gameTypeName`：與 `gameCode` 同值。

### 簽名（MD5）

各端點以指定欄位順序串接後 MD5、**轉小寫**：

```
sign = md5(apiKey + <依端點指定的欄位串接>).toLowerCase()
```

各端點串接順序見各節。回調請求另帶 header `signature` / `timeStamp`（值與 body 內 `sign` / `timestamp` 相同）。

---

## 回調 API（AI Gaming → 我方）

回應格式統一：

```json
{ "errorCode": 0, "errorMessage": "success", "balance": 1816067.95 }
```

### 錯誤碼表（回調回應用）

| errorCode | Message | 說明 |
|---|---|---|
| 0 | No Error | 成功 |
| 1 | Member not exist | 玩家不存在 |
| 2 | Invalid Ip | IP 不合法 |
| 3 | Username empty | username 為空 |
| 4 | apiKey Error | 驗簽失敗 |
| 5 | Not enough balance | 餘額不足 |
| 6 | Bet not exists | 注單不存在（**回此碼後廠商停止重送**） |
| 7 | Internal Error | 內部錯誤 |
| 2001 | Bet Already Settled | 已結算（**回此碼後廠商停止重送 settle**） |
| 5003 | Bet With Same TransactionId Exists | 同 transactionId 注單已存在 |
| 5008 | Bet Already Returned Stake | 注單已退還本金 |

### 1. POST /seamless/getBalance

查詢玩家餘額。簽名：`md5(apiKey + timestamp + username)`。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | ✅ | 玩家帳號 |
| `walletType` | string | ✅ | 幣別 |
| `gameTypeName` | string | ✅ | 同 gameCode |
| `gameCode` | string | ✅ | 遊戲代碼 |
| `timestamp` | integer | ✅ | 毫秒 timestamp |
| `sign` | string | ✅ | 簽名 |

### 2. POST /seamless/deduct（扣注）

投注扣款。簽名：`md5(apiKey + timestamp + username + amount + transactionId + betTime + gameTypeName)`。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `amount` | number | ✅ | 投注金額，必須 > 0 |
| `username` | string | ✅ | 玩家帳號 |
| `walletType` | string | ✅ | 幣別 |
| `gameInfo` | string | ✅ | 遊戲資訊（JSON 字串，含盤面 / 派彩細節） |
| `transactionId` | string | ✅ | 唯一交易 ID |
| `betTime` | string | ✅ | 投注時間；文件寫 `YYYY-MM-DD HH:mm:ss`，範例實為 ISO8601 帶納秒（`2024-12-03T14:00:37.804932245`） |
| `gameCode` | string | ✅ | 遊戲代碼 |
| `gameTypeName` | string | ✅ | 同 gameCode |
| `timestamp` | integer | ✅ | 毫秒 timestamp |
| `hasBonus` | boolean | – | true 表示該注單後續還有 `/bonus` 回調 |
| `sign` | string | ✅ | 簽名 |

### 3. POST /seamless/settle（派彩）

結算派彩。**`payoutAmount` 含本金（include betStake）**。簽名：`md5(apiKey + timestamp + username + transactionId + payoutAmount + resultType + gameTypeName + resultTime)`。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | ✅ | 玩家帳號 |
| `transactionId` | string | ✅ | 唯一交易 ID（同 deduct） |
| `payoutAmount` | number | ✅ | 派彩金額（含本金），≥ 0；0 = 未中獎 |
| `gameInfo` | string | ✅ | 遊戲資訊 |
| `resultTime` | string | ✅ | 結算時間（格式同 betTime 注意事項） |
| `resultType` | string | ✅ | `WIN` / `LOSE` / `DRAW` / `CANCEL` |
| `walletType` | string | ✅ | 幣別 |
| `gameTypeName` | string | ✅ | 同 gameCode |
| `gameCode` | string | ✅ | 遊戲代碼 |
| `timestamp` | integer | ✅ | 毫秒 timestamp |
| `hasBonus` | Boolean | – | true 表示該注單後續還有 `/bonus` 回調 |
| `isFirst` | Boolean | – | 是否為第一次 spin |
| `isEnd` | Boolean | – | 是否為最後一次 spin |
| `sign` | string | ✅ | 簽名 |

### 4. POST /seamless/bonus（bonus game 輸贏）

bonus game 輸贏回調；`payoutAmount` 為最終派彩金額。簽名：`md5(apiKey + timestamp + username + amount + transactionId + walletType)`。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `amount` | number | ✅ | 投注金額，必須 > 0 |
| `payoutAmount` | number | ✅ | 派彩金額，≥ 0；0 = 未中獎 |
| `username` | string | ✅ | 玩家帳號 |
| `walletType` | string | ✅ | 幣別 |
| `gameInfo` | string | ✅ | 遊戲資訊 |
| `transactionId` | string | ✅ | 唯一交易 ID |
| `resultTime` | string | ✅ | 結算時間 |
| `gameCode` | string | ✅ | 遊戲代碼 |
| `gameTypeName` | string | ✅ | 同 gameCode |
| `resultType` | string | ✅ | `WIN` / `LOSE` / `DRAW` / `CANCEL` |
| `timestamp` | integer | ✅ | 毫秒 timestamp |
| `isFirst` | Boolean | – | 是否為第一次 spin |
| `isEnd` | Boolean | – | 是否為最後一次 spin |
| `sign` | string | ✅ | 簽名 |

### 5. POST /seamless/rollback（取消投注）

已呼叫 `/seamless/deduct` 但下注失敗時，回調通知我方退回扣款。簽名：`md5(apiKey + timestamp + username + amount + transactionId + walletType)`。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `amount` | number | ✅ | 投注金額（原 deduct 金額），必須 > 0 |
| `username` | string | ✅ | 玩家帳號 |
| `walletType` | string | ✅ | 幣別 |
| `gameInfo` | string (JSON) | ✅ | 遊戲資訊 |
| `transactionId` | string | ✅ | 唯一交易 ID（同 deduct） |
| `gameCode` | string | ✅ | 遊戲代碼 |
| `gameTypeName` | string | ✅ | 同 gameCode |
| `timestamp` | integer | ✅ | 毫秒 timestamp |
| `sign` | string | ✅ | 簽名 |

### 6. POST /seamless/cashBonus（純派彩）

純派彩（無對應投注）。簽名：`md5(apiKey + timestamp + username + amount + transactionId + walletType)`（同 bonus / rollback）。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `amount` | number | ✅ | 投注金額，**必須為 0**（⚠️ 官方範例 body 卻帶 `0.15`，以文件敘述為準，實測驗證） |
| `payoutAmount` | number | ✅ | 派彩金額，必須 > 0 |
| `username` | string | ✅ | 玩家帳號 |
| `walletType` | string | ✅ | 幣別 |
| `gameInfo` | string | ✅ | 遊戲資訊 |
| `transactionId` | string | ✅ | 唯一交易 ID |
| `resultTime` | string | ✅ | 派彩時間 |
| `gameCode` | string | ✅ | 遊戲代碼 |
| `gameTypeName` | string | ✅ | 同 gameCode |
| `resultType` | string | ✅ | 如 `WIN` |
| `timestamp` | integer | ✅ | 毫秒 timestamp |
| `sign` | string | ✅ | 簽名 |

---

## Merchant API（我方 → AI Gaming）

回應格式統一（成功 `status: "ok"`、`code: "0"`）：

```json
{ "data": ..., "message": "success", "status": "ok", "balance": 0, "balanceString": "0", "walletType": "", "symbol": "", "separator": "", "locale": "", "code": "0" }
```

錯誤時為 JHipster problem 格式（HTTP 400，`title` / `status` / `message` / `code`），例：`userexists`（帳號已存在）、`10003`（Invalid Login）。

### 1. POST /user-center/openApi/createUser

建立玩家帳號。簽名：`md5(apiKey + provider + timestamp + username + walletType)`。

| 欄位 | 類型 | 必填 |
|---|---|---|
| `username` | string | ✅ |
| `provider` | string | ✅ |
| `walletType` | string | ✅ |
| `timestamp` | integer | ✅ |
| `sign` | string | ✅ |

### 2. POST /user-center/openApi/authenticate（取遊戲 URL）

簽名：`md5(apiKey + gameCode + provider + timestamp + username + walletType)`。

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | ✅ | |
| `provider` | string | ✅ | |
| `walletType` | string | ✅ | |
| `gameCode` | string | ✅ | |
| `timestamp` | integer | ✅ | |
| `lang` | string | – | `en`（預設）/ `zh` / `zh-tw` |
| `sign` | string | ✅ | |

成功回傳 `data.gameUrl`（已帶 token）與 `data.id_token`、`data.maintenance`。gameUrl 末端可附 `&origin={url}` 提供「回大廳」功能。

### 3. GET /game/getGameList?platform={provider}

取遊戲清單。回傳 `data.gameList[]`：`gameCode`、`gameName`、`url`、`imageUrl`、`walletType`、`gameType`、`status`（`ACTIVE`）、`platform`。

### 4. POST /user-center/openApi/gameRecords（注單查詢）

簽名：`md5(apiKey + page + provider + size + timestamp + username + walletType)`。

| 欄位 | 必填 | 說明 |
|---|---|---|
| `provider` | ✅ | |
| `walletType` | – | |
| `dateFrom` / `dateTo` | ✅ | `YYYY-MM-DD` 或 `YYYY-MM-DD HH:mm:ss`，最大跨度 90 天 |
| `size` | ✅ | 每頁筆數，最大 1000 |
| `page` | ✅ | 頁碼，從 0 起 |
| `username` | – | 空 = 全部玩家 |
| `orderNo` | – | 訂單號 |
| `timestamp` / `sign` | ✅ | |

回傳 `data.content[]`：`username`、`trxDatetime`、`timestamp`、`orderNo`、`beforeBalance`、`afterBalance`、`walletType`、`betAmount`、`profitAmount`、`gamePayout`、`matchNo`、`gameResult` 等。

### 5. POST /user-center/openApi/gameRecordsTotal

期間彙總（`totalRecord` / `totalBet` / `totalPayout` / `totalProfit`）。簽名：`md5(apiKey + provider + timestamp + username + walletType)`。參數同 gameRecords（無分頁）。

### 6. POST /user-center/openApi/getBetDetail

取注單詳情 URL。簽名：`md5(apiKey + gameCode + orderNo + provider + timestamp)`。參數：`provider`、`gameCode`、`orderNo`（注單 transactionId）、`timestamp`、`sign`。回傳 `data` 為詳情頁 URL。

### 7. POST /user-center/openApi/checkHasPendingGame

查玩家於指定遊戲是否有未完成局。簽名：`md5(apiKey + gameCode + provider + timestamp + username)`。回傳 `data`：boolean。

### 8. POST /user-center/openApi/pendingGameCodes

查玩家所有未完成局的 gameCode 清單。簽名：`md5(apiKey + provider + timestamp + username)`。回傳 `data`：string[]。

### 9. POST /user-center/openApi/gameRecordsSummary / gameRecordsGroupSummary

依玩家 × 遊戲彙總（`totalBetCount` / `totalBet` / `totalPayout`）。簽名：`md5(apiKey + provider + timestamp + username + walletType)`。參數同 gameRecordsTotal。

### 10. POST /user-center/openApi/newPlayerFreeSpin / newPlayerFreeSpinAvailableGameList

新玩家免費 spin 發放（`gameCode`、`quantity`、`betAmount`）與可用遊戲清單。簽名：發放 `md5(apiKey + provider + timestamp + username + walletType)`；清單 `md5(apiKey + provider + timestamp + walletType)`。

---

## 附錄

- 官方 Postman 範例的 `betTime` / `resultTime` 實際為 ISO8601 帶納秒格式，與欄位說明的 `YYYY-MM-DD HH:mm:ss` 不一致；時區文件未載明，須實測確認。
- 文件未載明「輸錢（payoutAmount=0）是否必送 settle」——依 `/seamless/settle` 範例 `resultType: LOSE`、`payoutAmount: 0` 推定輸贏皆送，須 UAT 實測確認後記入 `integration.md`。
- 文件未提供回調重送（retry）機制說明；錯誤碼 6 / 2001 的描述（stop resend）暗示有重送行為。
