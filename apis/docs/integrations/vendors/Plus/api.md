# Plus（100PLUS2）— API 規格

> 原始來源：
> - `Transfer Wallet API Specification.pdf`（英文，V1.0.0，2020/11/11 First Edition）— **主要事實來源**
> - `轉帳錢包 API 文件.pdf`（中文版，內容與英文版對應）— 參考
> - `info.txt`（IDR 測試帳號）、`100PLUS2 Games List游戏分类.xlsx`、`100PLUS 2 GAME LOGO/`（遊戲清單與 LOGO 素材）
> 抓取日期：2026-06-03

---

## 通用約定

- **HTTP Method**：所有 API 一律 `POST`
- **URL 格式**：`{url}/{apiMethod}?hash={hash}`（`hash` 放在 query string）
- **Header**：`Content-Type: application/json; charset=utf-8`
- **驗證方式**：IP 白名單 + `hash` 簽章（見 [§ 簽名](#簽名)）
- **金額**：為避免浮點誤差，所有金額**以字串傳輸**，最多 4 位小數
- **時間**：時間戳一律 timezone **+0（UTC）**，單位 millisecond

### 須向 Plus 客服索取的資料

| 項目 | 說明 |
|---|---|
| `merch` | 專屬商戶帳號（API 存取所需） |
| `API key` | 計算 `hash` 用的金鑰 |
| `url` | 測試站 / 正式站不同；正式站僅允許指定 IP，須提供伺服器 IP 給 Plus 設定白名單 |

### 通用回應約定

- 成功：`status` = `"success"`，其餘欄位視 API 而定。
- 失敗：回傳 error object：

| 欄位 | 說明 |
|---|---|
| `status` | `"error"` |
| `code` | 錯誤碼（見 [§ 錯誤碼](#錯誤碼) ） |
| `message` | 錯誤訊息 |
| `action` | 前端建議動作（選用）：`continue`（不處理，直接下一局）/ `closeWindow`（關閉視窗）/ `retry`（重送網路訊號） |

```json
{ "status": "error", "code": "wp_012", "message": "Invalid playerId", "action": "closeWindow" }
```

---

## Merchant API

代理（本平台）主動呼叫 Plus 的 API。全部使用 `POST`，URL 格式 `{url}/{apiMethod}?hash={hash}`。

### 2.1 建立錢包帳號 createUser

| 項目 | 值 |
|---|---|
| apiMethod | `createUser` |
| 用途 | 建立玩家錢包帳號 |

**Request**

| 欄位 | 必填 | 說明 |
|---|---|---|
| `merch` | Y | 專屬商戶帳號 |
| `playerId` | Y | 玩家 ID，最長 128 字元；**勿用 `-` 或 `:` 等符號**；**大小寫敏感**（`Richard` ≠ `richard`） |
| `currency` | Y | 幣別，例如 `CNY` |

```json
{ "merch": "test", "playerId": "test03", "currency": "CNY" }
```

**Response（成功）**

| 欄位 | 說明 |
|---|---|
| `status` | `success`=成功，其他訊息=失敗 |

```json
{ "status": "success" }
```

---

### 2.2 存入玩家錢包 deposit（轉入遊戲商）

| 項目 | 值 |
|---|---|
| apiMethod | `deposit` |
| 用途 | 將餘額轉入玩家在 Plus 的錢包 |

**Request**

| 欄位 | 必填 | 說明 |
|---|---|---|
| `merch` | Y | 商戶帳號 |
| `playerId` | Y | 玩家帳號 |
| `transactionId` | Y | 交易 ID，需唯一不可重複，建議 UUID，最長 36 字元 |
| `amount` | Y | 交易金額，最多 4 位小數，字串 |

```json
{ "merch": "test", "playerId": "test03", "transactionId": "transactionId...", "amount": "100.02" }
```

**Response（成功）**

| 欄位 | 說明 |
|---|---|
| `status` | `success`=成功 |
| `currency` | 玩家幣別 |
| `amount` | 本次存入金額 |
| `beforeBalance` | 存入前餘額 |
| `afterBalance` | 存入後餘額 |

```json
{ "status": "success", "currency": "CNY", "amount": "100", "beforeBalance": "400", "afterBalance": "500" }
```

---

### 2.3 從玩家錢包提出 withdraw（轉出遊戲商）

| 項目 | 值 |
|---|---|
| apiMethod | `withdraw` |
| 用途 | 從玩家 Plus 錢包提出餘額 |

**Request**

| 欄位 | 必填 | 說明 |
|---|---|---|
| `merch` | Y | 商戶帳號 |
| `playerId` | Y | 玩家 ID |
| `transactionId` | Y | 交易 ID，唯一，建議 UUID，最長 36 字元 |
| `takeAll` | Y | `0`：依 `amount` 部分提出；`1`：全部提出 |
| `amount` | S | 指定提出金額（`takeAll=0` 時必填），最多 4 位小數 |

```json
// 全部提出
{ "merch": "test", "playerId": "test03", "transactionId": "transactionId01", "takeAll": "1" }
```
```json
// 部分提出
{ "merch": "test", "playerId": "test03", "transactionId": "transactionId02", "takeAll": "0", "amount": "100.02" }
```

**Response（成功）**：欄位與 deposit 相同（`status` / `currency` / `amount` / `beforeBalance` / `afterBalance`）。

```json
{ "status": "success", "currency": "CNY", "amount": "100", "beforeBalance": "400", "afterBalance": "500" }
```

---

### 2.4 遊戲列表 gameList

| 項目 | 值 |
|---|---|
| apiMethod | `gameList` |

**Request**：`{ "merch": "test" }`

**Response（成功）**

| 欄位 | 說明 |
|---|---|
| `status` | `success`=成功 |
| `data` | 遊戲陣列，失敗時為 `null` |
| `data[].gameId` | 遊戲 ID |
| `data[].name` | 遊戲名稱 |

```json
{ "status": "success", "data": [ { "gameId": "1001", "name": "WhiteSnake" }, { "gameId": "1002", "name": "WaterMargin" } ] }
```

---

### 2.5 進入遊戲 enterGame

| 項目 | 值 |
|---|---|
| apiMethod | `enterGame` |
| 用途 | 取得遊戲啟動 URL |

**Request**

| 欄位 | 必填 | 說明 |
|---|---|---|
| `merch` | Y | 商戶帳號 |
| `gameId` | Y | 遊戲 ID |
| `playerId` | Y | 玩家 ID |
| `lang` | N | 語系，預設 `zh-CN`，可選 `zh-CN` / `en` |
| `homeUrl` | N | 玩家點擊「首頁」按鈕導向的 URL；不傳則無首頁按鈕 |

```json
{ "merch": "test", "gameId": "1001", "playerId": "test01", "lang": "en", "homeUrl": "https://xxx.xxx/home?token=[REDACTED]" }
```

**Response（成功）**

| 欄位 | 說明 |
|---|---|
| `status` | `success`=成功 |
| `url` | 遊戲 URL |
| `token` | 遊戲 token |

```json
{ "status": "success", "url": "http://xxx.xxx.xxx/?lang=xxx&token=[REDACTED]", "token": "xxxx" }
```

---

### 2.6 玩家登出 logout

| 項目 | 值 |
|---|---|
| apiMethod | `logout` |

**Request**：`{ "merch": "test", "playerId": "test03" }`

**Response**：`{ "status": "success" }`

---

### 2.7 玩家餘額與在線狀態 playerBalance

| 項目 | 值 |
|---|---|
| apiMethod | `playerBalance` |

**Request**：`{ "merch": "test", "playerId": "test03" }`

**Response（成功）**

| 欄位 | 說明 |
|---|---|
| `status` | `success`=成功 |
| `currency` | 幣別 |
| `balance` | 玩家餘額，最多 4 位小數 |
| `isOnline` | `0`=離線，`1`=在線 |

```json
{ "status": "success", "currency": "CNY", "balance": "100.02", "isOnline": "1" }
```

---

### 2.8 在線玩家列表 onlineUsers

| 項目 | 值 |
|---|---|
| apiMethod | `onlineUsers` |

**Request**：`{ "merch": "test" }`

**Response**

| 欄位 | 說明 |
|---|---|
| `status` | `success`=成功 |
| `data` | 玩家 ID 陣列，失敗時為 `null` |

```json
{ "status": "success", "data": ["test01", "test02"] }
```

---

### 2.9 時間區間查錢包轉帳紀錄 transferRecordByTime

| 項目 | 值 |
|---|---|
| apiMethod | `transferRecordByTime` |
| 用途 | 以時間區間查 deposit/withdraw 轉帳紀錄 |

**Request**

| 欄位 | 必填 | 說明 |
|---|---|---|
| `merch` | Y | 商戶帳號 |
| `playerId` | N | 不填則查全部 |
| `start` | Y | 起始時間戳（ms，UTC），條件 `>=` 交易時間 |
| `end` | Y | 結束時間戳（ms，UTC），條件 `<` 交易時間 |
| `page` | N | 頁碼，從 1 起，預設 1 |
| `pageSize` | N | 每頁筆數，從 1 起，最大 2000，預設 20 |

**Response（成功）**

Root 含 `status` / `totalPage` / `totalSize` / `page` / `pageSize` / `data`；`data[]` 每筆含：

| 欄位 | 說明 |
|---|---|
| `transactionId` | 交易 ID |
| `playerId` | 玩家 ID |
| `time` | 交易時間戳（UTC, ms） |
| `method` | `0`=deposit，`1`=withdraw |
| `currency` | 幣別 |
| `amount` | 金額 |
| `balance` | 交易後餘額 |

---

### 2.10 依交易 ID 查轉帳紀錄 transferRecordById

| 項目 | 值 |
|---|---|
| apiMethod | `transferRecordById` |
| 用途 | 依交易 ID 查單筆轉帳紀錄（亦作為 `checkTransferStatus` 補查入口） |

**Request**：`{ "merch": "test", "transactionId": "dcde4341-92c7-c0ca-3aa8-7dcc4276b564" }`

**Response（成功）**：Root 含 `status` / `transactionId` / `playerId` / `time` / `method`（0=deposit,1=withdraw）/ `currency` / `amount` / `balance`。

```json
{ "status": "success", "transactionId": "dcde4341-92c7-c0ca-3aa8-7dcc4276b564", "playerId": "robot6020", "time": "1566230400000", "method": "1", "currency": "CNY", "amount": "2", "balance": "12" }
```

---

### 2.11 依時間查局紀錄明細 roundRecordByTime

| 項目 | 值 |
|---|---|
| apiMethod | `roundRecordByTime` |
| 用途 | 查局明細（**僅支援 completed 或 canceled 的局**） |

**Request**

| 欄位 | 必填 | 說明 |
|---|---|---|
| `merch` | Y | 商戶帳號 |
| `playerId` | N | 玩家 ID |
| `gameId` | N | 遊戲 ID |
| `roundStatus` | N | `1`=列出 Completed 局（預設）；`2`=列出 Canceled 局 |
| `start` | Y | 起始時間戳（ms，UTC），條件 `>=` End Time |
| `end` | Y | 結束時間戳（ms，UTC），條件 `<` End Time |
| `page` | N | 頁碼，預設 1 |
| `pageSize` | N | 每頁筆數，最大 2000，預設 20 |

**Response（成功）**

Root 含 `status` / `totalPage` / `totalSize` / `page` / `pageSize` / `data`；`data[]` 每筆含：

| 欄位 | 說明 |
|---|---|
| `roundId` | 局 ID |
| `gameId` | 遊戲 ID |
| `playerId` | 玩家 ID |
| `betTime` | 下注時間（UTC, ms） |
| `winTime` | 派彩時間（UTC, ms） |
| `wpTime` | 結束時間（UTC, ms） |
| `currency` | 幣別 |
| `bet` | 下注金額 |
| `validBet` | 有效投注金額 |
| `win` | 贏分金額 |
| `netWin` | 淨贏分（win - bet） |
| `roundStatus` | `1`=Complete，`2`=Cancel |
| `beforeBalance` | 該局前餘額 |
| `afterBalance` | 該局後餘額 |

> 對帳取注單以此為主：`validBet` 對應有效投注，`netWin` = win - bet。

---

### 2.12 依局 ID 查局紀錄明細 roundRecordById

| 項目 | 值 |
|---|---|
| apiMethod | `roundRecordById` |

**Request**：`{ "merch": "test", "roundId": "dcde434192c7c0ca3aa87dcc4276b564" }`

**Response（成功）**：Root 含 `status` / `roundId` / `gameId` / `playerId` / `betTime` / `winTime` / `wpTime` / `currency` / `bet` / `win` / `netWin` / `roundStatus` / `beforeBalance` / `afterBalance`。

> 注意：本 API 的 `roundStatus` 比 2.11 多一個 `0: incomplete`（2.11 僅 `1` complete / `2` cancel）：
> - `0`：incomplete（未結算）
> - `1`：complete
> - `2`：cancel

```json
{ "status": "success", "roundId": "dcde434192c7c0ca3aa87dcc4276b564", "gameId": "1001", "playerId": "test01", "betTime": "1566230400000", "winTime": "1566230600000", "wpTime": "1566230700000", "currency": "CNY", "bet": "0.9", "win": "100.02", "netWin": "99.12", "roundStatus": "1", "beforeBalance": "500", "afterBalance": "599.12" }
```

---

### 2.13 局明細 URL roundDetailUrl

| 項目 | 值 |
|---|---|
| apiMethod | `roundDetailUrl` |
| 用途 | 取得單局明細頁面 URL |

**Request**

| 欄位 | 必填 | 說明 |
|---|---|---|
| `merch` | Y | 商戶帳號 |
| `gameId` | Y | 遊戲 ID |
| `roundId` | Y | 局 ID |
| `lang` | N | 語系，預設 `zh-CN`，可選 `zh-CN` / `en` |

```json
{ "merch": "test", "gameId": "1001", "roundId": "000253cc7b0f4af8b4416ed7d406b57a", "lang": "en" }
```

**Response（成功）**：`{ "status": "success", "url": "http://xxx.xxx.xxx/detail?token=[REDACTED]" }`

---

## 簽名

Plus 所有 API 請求都需附帶 `hash` 參數於 **query string**（`{url}/{apiMethod}?hash={hash}`），請求 body 為 JSON 字串。`hash` 是對整個 request body JSON 字串做 HMAC-SHA256 的結果。

| 項目 | 值 |
|---|---|
| 演算法 | HMAC-SHA256 |
| 明文 | 整個 Request Body 的 JSON 序列化字串 |
| 金鑰 | `API key`（由 Plus 客服提供，與 `merch` 一同核發） |
| 輸出編碼 | hex（小寫十六進位字串） |
| 傳遞位置 | URL query string 的 `hash` 參數 |

> **PHP 必須帶 `JSON_UNESCAPED_UNICODE`**（官方明確標註）：`json_encode` 若未帶此旗標，中文字被 escape 成 `\uXXXX`，Plus 端重算的 hash 與送出的不一致，所有含中文值的請求都會驗章失敗。

### 官方 PHP 範例

```php
$json_str = json_encode($message, JSON_UNESCAPED_UNICODE);
$hash = hash_hmac('SHA256', $json_str, $API_key);
```

### 官方驗證樣本

| 項目 | 值 |
|---|---|
| `$API key` | `verySecretKey` |
| `$message` | `{"message":"Hello World!"}` |
| 預期 `hash` | `514acab8e3ff3f6b7562e8b6ef44141d21e90d7aa19002a38314ecc9fbce2553` |

另一組（取自 `playerBalance` 範例）：

| 項目 | 值 |
|---|---|
| `merch` | `test` |
| `API key` | `verySecretKey` |
| API Method | `playerBalance` |
| Request JSON | `{"merch":"test","playerId":"test03"}` |
| 預期 `hash` | `04a6edc5856dc37767fab62a68017f0f10e1cc79b36c8ed5cc0ef7579da09451` |

最終請求 URL：

```
https://example.invalid/playerBalance?hash=04a6edc5856dc37767fab62a68017f0f10e1cc79b36c8ed5cc0ef7579da09451
```

Request Header：`Content-Type: application/json; charset=utf-8`，Request Method：`POST`。

### 本專案實作

`app/Vendors/Plus/Driver.php::hash()`：

```php
private function hash(array $message): string
{
    $json_str = json_encode($message, JSON_UNESCAPED_UNICODE);

    return hash_hmac('SHA256', $json_str, $this->infoDetail->API_KEY);
}
```

與官方範例一致（HMAC-SHA256、`JSON_UNESCAPED_UNICODE`、hex 輸出、放在 query string 的 `hash`）。

---

## 錯誤碼

| code | message |
|---|---|
| `req_007` | server is busy |
| `wp_001` | sysetm error（原文拼字） |
| `wp_002` | invalid parameter |
| `wp_003` | not enough balance |
| `wp_004` | invalid token |
| `wp_007` | invalid roundId |
| `wp_009` | invalid transactionId |
| `wp_012` | invalid playerId |
| `wp_016` | invalid gameId |
| `wp_017` | playerId is prohibited |
| `wp_021` | playerId is exist |
| `wp_022` | invalid merch |
| `wp_023` | transactionId is exist |
| `wp_099` | sysetm error（原文拼字） |

> `wp_023 transactionId is exist`：同一 `transactionId` 重送時 Plus 回此碼，代表先前那筆已存在，**不應視為新失敗**。應改查 `transferRecordById`（[§ 2.10](#210-依交易-id-查轉帳紀錄-transferrecordbyid)）確認原交易結果。

---

## Change Log（官方）

| 日期 | 版本 | 變更 |
|---|---|---|
| 2020/11/11 | V1.0.0 | First Edition |
