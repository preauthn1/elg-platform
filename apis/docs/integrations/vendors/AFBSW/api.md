# AFBSW（AFB Gaming）— 廠商 API 規格

> **原始來源**
> - `AFB-Gaming-Integration-API.pdf`（AFB Gaming System Integration API Document，V1.10 / 2025-03-27）
> - `README.md`（下載包內 API URL 勘誤，Base URL 以此為準）
>
> 抓取日期：2026-06-03
>
> 我方實作分析、整合落地建議、陷阱與 gaps 見同目錄 **`integration.md`（對接心得）**。

遊戲商：**AFB Gaming**（vendor id **176**，`VendorIdEnum::AFBSW`）。遊戲類型：Slots / BlockChain / Fishing。本專案採 **Single Wallet（單一錢包 / Seamless）** 接入。

---

## 通用約定

### 請求格式

- **HTTP Method**：所有 API 一律使用 `POST`
- **Content-Type**：`application/json`（文件 §1.2）
- **回應**：JSON；無論成功或失敗，operator（我方）都須以 **HTTP 200** 回應（文件 §1.3）

### 驗證方式

| 方向 | 驗證機制 |
|---|---|
| 我方主動呼叫 AFB（newAccount / getLauncherUrl 等） | HTTP Header 帶 `X-Site-ID` / `X-Timestamp` / `X-Content-Hash` / `X-Signature`（見下方「簽章」節） |
| AFB 回調我方（Seamless API §5） | **IP 白名單**（`Common::verifyIPWhitelistByVendorId(176)`）為主；回調 body 另帶 `key`（API Key）—本專案目前未校驗 |

### Base URL（採用下載包 README 勘誤值）

> ⚠️ PDF §1.4 印的網址有誤，**以下為下載包 `README.md` 勘誤後的正確值**：

| 環境 | URL |
|---|---|
| 測試（stage） | `https://example.invalid/api/integration` |
| 正式（prod） | `https://example.invalid/api/integration` |

### Timeout（文件 §1.5）

| 請求類型 | 要求回應時間 |
|---|---|
| 下注（bet）類 | 10 秒內回應 |
| 取得注單列表（bet list）類 | 60 秒內回應 |

### 狀態碼 / 回應碼

AFB 文件中不同 API 用了兩種欄位名表示成敗：

| 欄位 | 出現於 | 規則 |
|---|---|---|
| `status` | 多數 API（newAccount / Seamless 全系列 / Transfer / betRecords 等） | `0` = 成功；其它 = 失敗 |
| `code` | Get Launcher Url（§4）、Statistics（§15 範例） | `0` = 成功；其它 = 失敗 |

> ⚠️ 文件**未提供完整錯誤碼列舉表**（只說「0 成功 / other 失敗」，搭配 `message` 字串描述）。

### Seamless 回調通用回應格式（我方 → AFB）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | int | `0` = 成功；其它 = 失敗 |
| `message` | string | 描述訊息（成功為 `"success"`） |
| `balance` | float | 處理後我方錢包餘額（換算成 AFB 幣值 = 真金餘額 × `rate`） |

---

## 簽章（我方 → AFB 主動呼叫）

> 適用：newAccount / getLauncherUrl / gameList / betRecords 等我方主動呼叫 AFB 的 API。
> **不適用**：Seamless 回調（AFB → 我方）。

每筆請求須帶以下 HTTP Header：

| Header | 說明 | 範例 |
|---|---|---|
| `Host` | 目標主機名 | `example.invalid` |
| `X-Site-ID` | AFB 核發的 Site ID | `11111111` |
| `X-Timestamp` | 當前 Unix 秒級時間戳 | `1733223286` |
| `X-Content-Hash` | `sha256(HTTP body)` hex | `93f62b4f43b47d07c09ca9882c8ffe2ed83c4145a7f06c70d48f50556c2d7840` |
| `X-Signature` | HMAC-SHA256 簽章（見下） | `44647fc73d1c91e2e7b0d590f3732dd3ead126faf0d904cb9d7072c0e2c081e7` |

### 簽章演算法

```
X-Content-Hash = sha256( <HTTP body 原始字串> )   // hex

X-Signature = hmac_sha256(
    secret,
    Host + "-" + X-Site-ID + "-" + X-Timestamp + "-" + X-Content-Hash
)   // hex
```

驗證規則：
- **Timestamp**：測試環境不驗；正式環境誤差不得超過 **60 秒**
- **Hash**：`sha256(body)` hex

完整範例（文件 §2.4）：
```
Site ID : 11111111
Secret  : [REDACTED]
Host            : example.invalid
X-Site-ID       : 11111111
X-Timestamp     : 1733223286
X-Content-Hash  : 93f62b4f43b47d07c09ca9882c8ffe2ed83c4145a7f06c70d48f50556c2d7840
X-Signature     : 44647fc73d1c91e2e7b0d590f3732dd3ead126faf0d904cb9d7072c0e2c081e7
```

---

## Seamless API（§5）— AFB 回調我方

> 所有 Seamless 請求由 **AFB 主動呼叫我方（operator）**。路由：`POST /sw/afb`（單一入口，以 body `method` 派發）。

### method 一覽

| method | 用途 | 本專案實作 | 文件章節 |
|---|---|---|---|
| `getBalance` | 查詢玩家餘額 | ✅ | §5.1 |
| `bet` | 下注扣款 | ✅ | §5.2 |
| `paid` | 結算派彩 | ✅ | §5.3 |
| `betPaid` | bet+paid 合併（站點開關控制） | ⚠️ 未實作 | §5.4 |
| `refund` | 退款 | ✅ | §5.5 |
| `open` | 遊戲開啟通知（選用） | ❌ 未實作 | §5.6 |
| `close` | 遊戲關閉通知（選用） | ❌ 未實作 | §5.7 |
| `bonusStatusNotify` | 特殊模式進出通知（選用） | ❌ 未實作 | §5.8 |

### 5.1 Get Balance（`getBalance`）

#### Request（AFB → 我方）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `method` | string | `getBalance` |
| `key` | string | API Key |
| `username` | string | 玩家帳號 |
| `gameCode` | string | 遊戲代碼 |

```jsonc
{
  "gameCode": "kingArthur",
  "key": "wzK982mAO0vHrP3xtT6lMCjfea7bJdSL",
  "method": "getBalance",
  "username": "V3-TEST"
}
```

#### Response（我方 → AFB）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | int | `0` 成功 / other 失敗 |
| `message` | string | 描述 |
| `balance` | float | 餘額（我方錢包 × rate） |
| `specialBalance` | float | 促銷餘額（文件列出；本專案未帶） |
| `currency` | string | 幣別（文件列出；本專案未帶） |

```jsonc
{ "status": 0, "message": "success", "balance": 100000 }
```

### 5.2 Bet（`bet`）

#### Request（AFB → 我方）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `method` | string | `bet` |
| `key` | string | API Key |
| `username` | string | 玩家帳號 |
| `amount` | string | 下注金額（AFB 幣值） |
| `currency` | string | 幣別 |
| `sessionId` | string | Session ID |
| `transactionId` | string | 交易號（本專案用作 `bet_id` 與冪等鍵；前 10 碼為 Unix 秒時間戳） |
| `referenceId` | string | 關聯 ID（同回合關聯；本專案未用於聚合） |
| `gameCode` / `gameName` / `gameType` | string | 遊戲資訊 |
| `ip` | string | 玩家 IP |
| `lineCount` / `lineBet` / `mode` | string | 遊戲線數 / 線注 / 模式 |
| `business` | string | `normal` / `buyFree` / `buyBonus` / `useCard` / `freeRoundBonus` / `activityBonus` |
| `lastTime` | string | 選用，`"true"`/`"false"`，slot 用於標記該回合最後一筆 paid |
| `freeRoundBonusCode` / `freeRoundBonusTimes` / `freeRoundBonusRemainTimes` | string | 選用，免費遊戲相關 |

```jsonc
{
  "amount": "0.0100",
  "business": "normal",
  "currency": "USD",
  "gameCode": "kingArthur",
  "gameName": "King Arthur's Excalibur",
  "gameType": "slot",
  "gameVendor": "afb",
  "ip": "0.0.0.0",
  "jackpotCommission": "0.0000",
  "key": "wzK982mAO0vHrP3xtT6lMCjfea7bJdSL",
  "lastTime": "false",
  "lineBet": "0.0000",
  "lineCount": "20",
  "method": "bet",
  "mode": "main",
  "referenceId": "",
  "sessionId": "session-48Q45AV9J465",
  "transactionId": "1740622680016562010",
  "username": "V3-TEST"
}
```

#### Response（我方 → AFB）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | int | `0` 成功 / other 失敗 |
| `message` | string | 描述 |
| `balance` | float | 扣款後餘額 |
| `specialBalance` | float | 促銷餘額（文件列出；本專案未帶） |

```jsonc
{ "status": 0, "message": "success", "balance": 99999.99 }
```

### 5.3 Paid（`paid`）

#### Request（AFB → 我方）

主要欄位同 bet，另含：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `method` | string | `paid` |
| `transactionId` | string | 對應 bet 的同一 `transactionId` |
| `realBet` | string | 選用。`singleBaccarat/classicBaccarat/bullBul`：`realBet = min(bet, abs(win - bet))`；其它遊戲：`realBet = bet` |
| `realPaid` | string | 選用。`realPaid = win` |
| `lastTime` | string | 選用，slot 最後一筆標記 |

```jsonc
{
  "amount": "0.0000",
  "currency": "USD",
  "gameCode": "kingArthur",
  "key": "wzK982mAO0vHrP3xtT6lMCjfea7bJdSL",
  "lastTime": "false",
  "method": "paid",
  "sessionId": "session-48Q45AV9J465",
  "transactionId": "1740622680016562010",
  "username": "V3-TEST"
}
```

#### Response（我方 → AFB）

```jsonc
{ "status": 0, "message": "success", "balance": 99999.99 }
```

### 5.4 Bet Paid（`betPaid`）— 站點開關控制

> 文件 §5.4：若站點啟用 `betPaid`，AFB 把 bet/paid **合併成單一 `betPaid` 請求**，不再分別送 bet 與 paid。**本專案目前未實作 `betPaid()` 方法**（見 integration.md）。

#### Request 關鍵差異欄位

| 欄位 | 說明 |
|---|---|
| `method` | `betPaid` |
| `betAmount` | 下注金額 |
| `paidAmount` | 派彩金額 |
| `realBet` / `realPaid` | 同 §5.3 規則 |

```jsonc
{
  "method": "betPaid",
  "key": "key1234567890",
  "username": "demo001",
  "betAmount": "5.0",
  "paidAmount": "1.0",
  "currency": "USD",
  "sessionId": "CTX02BE2F0DD94E63CFA7",
  "transactionId": "21470192840451222",
  "gameCode": "bib",
  "gameName": "Great Blue",
  "gameType": "slots",
  "ip": "0.0.0.0",
  "mode": "spin",
  "lineCount": "20",
  "lineBet": "0.01"
}
```

#### Response

```jsonc
{ "status": 0, "balance": 1000 }
```

### 5.5 Refund（`refund`）

#### Request（AFB → 我方）

| 欄位 | 類型 | 說明 |
|---|---|---|
| `method` | string | `refund` |
| `key` | string | API Key |
| `username` | string | 玩家帳號 |
| `amount` | string | 退款金額 |
| `currency` | string | 幣別 |
| `sessionId` / `transactionId` | string | Session / 交易號（以 transactionId 對應原 bet） |
| `gameCode` / `gameName` / `gameType` / `ip` | string | 遊戲與玩家資訊 |

```jsonc
{
  "method": "refund",
  "key": "key1234567890",
  "username": "demo001",
  "amount": 3.0,
  "currency": "USD",
  "sessionId": "CTX02BE2F0DD94E63CFA7",
  "transactionId": "21470192840451222",
  "gameCode": "bib",
  "gameName": "Great Blue",
  "gameType": "slots",
  "ip": "0.0.0.0"
}
```

#### Response

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | int | `0` 成功 |
| `message` | string | 描述 |
| `balance` | float | 退款後餘額 |

```jsonc
{ "status": 0, "balance": 1000 }
```

### 5.6~5.8 選用通知（本專案未實作）

| method | 用途 | 控制參數 |
|---|---|---|
| `open` | 遊戲開啟通知 | `game_status` |
| `close` | 遊戲關閉通知 | `game_status` |
| `bonusStatusNotify` | 特殊模式進出通知（`action: in/out`） | `game_status` |

`open`/`close` 請求帶 `username`/`gameCode`/`gameName`/`ip`；回應僅需 `{"status": 0}`。

---

## 錯誤碼

> 文件**未提供完整錯誤碼列舉表**，僅說明 `status`（或 `code`）`= 0` 為成功，其它值為失敗，搭配 `message` 字串說明。如需逐碼處理，須向 AFB 客服索取完整 error code 清單。
