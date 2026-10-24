# Tiger9（TigerNine）— 單一錢包整合規格

> **vendor id**：91（`VendorIdEnum::TIGER9`）
> **錢包型態**：Single Wallet（單一錢包，Seamless Wallet）
> **原始來源（官方文檔）**：
> - `TigerNine API - Seamless Wallet Doc V1.1.7 (EN) (1).pdf`（標題頁：**GALAXY - SEAMLESS WALLET / MERCHANT API DOCUMENTATION V1.1.7**）
> - `TigerNine API - Log Doc V1.1.8 (EN).pdf`（**MERCHANT LOG API DOCUMENTATION V1.1.8**）
> - 來源資料夾：`/Users/rb/Workspace/遊戲商/91.TIGER9/`
> **抓取日期**：2026-06-03

---

## ⚠️ 重要前提：雙協議並存（必讀）

Tiger9（vid 91）在 APIs 專案中**並未使用獨立 Driver**，而是與 PGSSW（vid 50）、BWG（vid 145）共用 `PegasusSW\Driver`：

- `config/vendor.php`：`VendorIdEnum::TIGER9->value => Vendors\PegasusSW\Driver::class`
- 回調 Controller：`app/Http/Controllers/External/SingleWallets/PegasusController.php`
- 路由：`routes/external/sw.php`（`target` prefix）

本文件因此分兩條軸線並存：

| 軸線 | 說明 |
|---|---|
| **§A 官方 PDF 協議**（GALAXY / TigerNine） | `apiID` + 3DES 簽章；`/balance/detail` + `/balance/transfer`（action code）；boolean `status` 回應 |
| **§B APIs 實作協議**（target 聚合，實際落地） | `X-PROVIDER-IDENTITY` header；`/target/*` 路由；整數 `code` 回應 |

兩套欄位名稱、endpoint、簽章、回應格式**全然不同**；落差彙整見文末「實作與文件落差」。

---

## 通用約定

### §A 官方通用約定

| 項目 | 值 |
|---|---|
| HTTP Method | 一律 `POST` |
| 代理 → 三方請求驗證 | `apiID` + `signature`（3DES 加密，見「簽章 §A」） |
| 三方 → 代理回調 | body 為明文 JSON（`POST (json)`），不再封裝 signature |
| 回應判定 | boolean `status`（`true` 成功 / `false` 失敗）；HTTP 200 不代表成功 |
| 玩家 ID | `playerID`，三方自動加上 merchant prefix（例 `tst1` + `testacc` → `tst1testacc`） |

**HTTP 狀態碼（§A）**

| Code | 意義 |
|---|---|
| `200` | Success |
| `400` | Fail |
| `401` | Invalid Token |
| `500` | API Error |

### §B 實作通用約定

| 項目 | 值 |
|---|---|
| 前置驗證 | `X-PROVIDER-IDENTITY` header → 比對 `vendor_infos.info['IDENTITY']` → 覆寫 `$vendorId` → IP 白名單 |
| 玩家識別 | `playerUniqueID`（優先）或 `sessionToken` |
| 成功回應 | `{ code: 0, data: { balance, transactionNo? } }` |
| 失敗回應 | `{ code: <OperatorStatusEnum>, message, data: null }` |
| 金額換算 | 入庫 `Common::numDiv($amount, $rate, 4)`；回應 `Common::numMul($balance, $rate, 4)` |

---

## §A Merchant 主動呼叫 API

> Base URL：三方提供（`<API Url Given>`）。

| API | Endpoint | 重點 |
|---|---|---|
| Register Player | `POST /merchant/player/register-player` | `playerID`、`password`；回傳 `playerToken`、`status`、`balance`、`minBet`、`maxBet` |
| Get Player Info | `POST /merchant/player/get-player-info` | 取得 `playerToken` 等資訊 |
| Update Bet Limit | `POST /merchant/player/update-bet-limit` | `minBet` 不得 < 5.00、`maxBet` 不得 < minBet |
| Get Merchant Info | `POST /merchant/merchant-info` | 回傳 `userPrefix` / `userID` / `balance` |
| Get Game List | `POST /merchant/game/table-list` | `category`（首次傳 `all`）；回傳 Game Table 物件 |
| Open Game Table | `POST /merchant/game/open-table` | `playerID`、`param`、`redirectUrl`、`lang`、`sessionToken?`；回傳 `redirectUrl` |
| Open Lobby | `POST /merchant/game/open-lobby` | `lobbyType`（`lobby` / `three-picture` / `four-seasons` / `roulette` / `sicbo`）、`lang`、`sessionToken?` |

**Galaxy Lobby URL**

```
https://example.invalid/merchant-login/{playerID}/{playerToken}/lobby/&{redirectUrl?}/{lang?}
```

> 使用 `REDIRECT URL` 時，`/` 需取代為 `|`；不使用時留空。

**語言碼（§A）**

`gb`(English) / `cn`(中文) / `my`(Malay) / `vn`(Tiếng Việt) / `th`(ไทย) / `kr`(한국) / `mm`(緬甸)

---

## §A Seamless Wallet 回調 API（三方 → 代理）

**Action Code 對照**

| Endpoint | `action` | 意義 |
|---|---|---|
| `POST /balance/detail` | `1` | Get Player Balance |
| `POST /balance/transfer` | `2` | Player Bet |
| `POST /balance/transfer` | `3` | Player Win |
| `POST /balance/transfer` | `4` | Refund |
| `POST /balance/transfer` | `5` | Rollback |

### A-1. Get Balance — `POST /balance/detail`

**Request（GALAXY → 代理）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `playerID` | String | ✅ | 含 merchant prefix 的玩家 ID（例 `tst1testacc`） |
| `action` | Int | ✅ | 固定 `1` |
| `sessionToken` | String | ✅ | 玩家 session token |

**Response（代理 → GALAXY）**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | bool | `true` 成功 |
| `data.amount` | Decimal | 玩家當前餘額，**四捨五入至兩位小數** |

### A-2. Transfer Balance — `POST /balance/transfer`

**Request（GALAXY → 代理）**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `playerID` | String | 含 prefix 玩家 ID |
| `action` | Int | `2` Bet / `3` Win / `4` Refund / `5` Rollback |
| `transactionNo` | String | 交易號（例 `B-20230101ASDF`） |
| `amount` | Decimal | 異動金額 |
| `turnover` | Decimal | 玩家本注實際有效流水 |
| `category` | String | 遊戲類別（`baccarat` / `three pictures` / `roulette` 等） |
| `settlementTime` | DateTime | 注單結算時間（例 `2023-09-11 03:46:17.203`） |
| `sessionToken` | String | 玩家 session token |
| `GameSummarySrno` | long | 遊戲彙總序號，對帳關聯用 |

**Response（代理 → GALAXY）**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | bool | `true` 成功 |
| `data.transactionNo` | String | 回傳同一 `transactionNo` 確認收單 |
| `data.amount` | Decimal | 更新後玩家餘額，**四捨五入至兩位小數** |

> 文件未獨立列出退款 / rollback 不存在 bet 時的處理規範，屬 gaps。

---

## §A Log / Report API（代理主動拉取，Log Doc V1.1.8）

| API | Endpoint | 重點 |
|---|---|---|
| Get Game Log | `POST /merchant/report/game-log` | `startDate`/`endDate`（`yyyy-MM-dd HH:mm:ss`）、`page`、`pageSize`；**僅近 7 天** |
| Get Daily Sum | `POST /merchant/report/daily-sum` | `date`（`yyyy-MM-dd`）；僅近 7 天 |
| Get Transaction Record | `POST /merchant/report/transaction-game-log` | `TransactionNo`；回傳注單 + 牌面 + 中獎碼 + `recordingUrl`（牌局錄影） |
| Get Game Type Summary | `POST /merchant/report/game-type-summary` | `startDate`/`endDate`；僅近 7 天 |
| Get Daily Summary Hourly | `POST /merchant/report/daily-sum-hourly` | 同上 |
| Get Game Type Summary 2 | `POST /merchant/report/game-type-summary2` | 同上，**以 settlement date 為基準** |
| Get Bulk Game Log | `POST /merchant/report/bulk-game-log` | `startDate`/`endDate`、`page`、`pageSize`；僅近 7 天 |

> ⚠️ **對帳保留期**：所有 Log API 限「近 7 天」。對帳排程務必在 7 天內完成拉取，逾期需聯絡三方客服。

---

## §B APIs 實作 — 啟動遊戲（getGameUrl）

`app/Vendors/PegasusSW/Driver.php::getGameUrl()`

| 項目 | 值 |
|---|---|
| Endpoint | `GET {API_URL}/game/launch-link` |
| 請求參數 | `playerUniqueID`(=`username`)、`sessionToken`、`merchantID`(=`API_APPID`)、`merchantSecretKey`(=`API_KEY`)、`lang`、`GameID`(=`gameCode`)；`LIVE_BET_LIMIT` 有值時加 `liveCasinoLevelID` |
| 語言 | `ZH_TW`/`ZH_CN` → `zh-cn`，其餘 → `en-us` |
| session_token | **每次啟動前** `Str::uuid()` 重新產生並寫入 account |
| 成功判定 | 回應 `code === 0`，遊戲連結取 `data.link` |

---

## §B Seamless Wallet 回調（`/target/*`）

Controller：`app/Http/Controllers/External/SingleWallets/PegasusController.php`
路由：`routes/external/sw.php`（`target` prefix）

### 路由一覽

| 路由 | 方法 | 用途 |
|---|---|---|
| `POST /target/players/wallet` | `getBalance` | 查詢遊戲錢包餘額 |
| `POST /target/bet/placeBet` | `placeBet` | 玩家投注（扣款，建立 BR，status=BET） |
| `POST /target/bet/settle` | `settle` | 一般投注結算 / 派彩（status=SETTLE） |
| `POST /target/bet/settle-without-payout` | `settleWithoutPayout` | 投注＋結算合併單筆（不需另呼 placeBet） |
| `POST /target/bet/settle-change` | `settleChange` | 已結算注單改單（修正 bet / valid / payout） |
| `POST /target/bonus/settle` | `bonus` | bonus 獎勵 / 免費遊戲結算 |
| `POST /target/bet/cancel` | `cancel` | 取消投注（status=ROLLBACK） |
| `POST /target/transaction/event-bonus` | `eventBonus` | 活動獎勵派彩 |

### B-1. Get Balance — `POST /target/players/wallet`

- `account->status == 0` → `INVALID_SIGNATURE (401002)`
- 回傳 `WalletService::getMemberWallets()->getTotalBalance()` × rate

### B-2. Place Bet — `POST /target/bet/placeBet`

**Request 欄位**

| 欄位 | 說明 |
|---|---|
| `transactionNo` | 注單號 → BR `bet_id`（重複 → `DUPLICATE_TRANSACTION 500029`） |
| `playerUniqueID` | 玩家帳號 |
| `gameID` | 遊戲代碼 |
| `sessionToken` | session |
| `betAmount` | 投注額（÷ rate 入庫） |

流程：`BetHelper::isMemberAbleToBet()` → `DB::transaction` + `getMemberLockedWallets` → 餘額不足 `INSUFFICIENT_BALANCE (403203)` → 建立 `PgsswBetRecord`（status=BET，`win=0`，`win_lose = 0 - bet`，`payout_time/settlement_time = 1970-01-01`）→ `changeBalance` 扣款 → `TotalBetRecordService::create`

### B-3. Settle — `POST /target/bet/settle`

**Request 欄位**

| 欄位 | 說明 |
|---|---|
| `transactionNo` | 對應既有 BR（查無 → `BET_NOT_FOUND 404016`） |
| `validAmount` | **同時作為 `bet` 與 `valid_bet` 使用**（÷ rate）— 代碼註解 `by mars` |
| `payoutAmount` | 派彩額 → `win`（÷ rate） |
| `settlementTime` | 結算時間 |

- BR 狀態非 `BET` → `INVALID_STATUS (403601)`
- 更新 BR：`bet=validAmount`、`win=payoutAmount`、`win_lose=win-bet`、`status=SETTLE`、`settlement_time/payout_time=settlementTime` → `changeBalance` 派彩 → TBR `create(..., ['valid_bet' => $validBet])`

### B-4. Settle Without Payout — `POST /target/bet/settle-without-payout`

投注與結算合併（不另呼 placeBet）。欄位：`transactionNo`、`playerUniqueID`、`gameID`、`betAmount`、`payoutAmount?`、`settlementTime`。重複 `transactionNo` → `DUPLICATE_TRANSACTION`。建立 BR 直接 `status=SETTLE`，`changeBalance(autoSave:false)`。回應 `{ code, data: { transactionNo } }`。

### B-5. Settle Change — `POST /target/bet/settle-change`

已結算注單改單。欄位：`transactionNo`（查無 → `BET_NOT_FOUND`）、`afterBetAmount`、`afterValidAmount`、`afterPayoutAmount`、`settlementTime`。重算 BR，`status=SETTLE`，TBR 帶 `valid_bet`。回應 `{ code, data: { transactionNo } }`。

### B-6. Bonus Settle — `POST /target/bonus/settle`

bonus 獎勵 / 免費遊戲結算。欄位：`transactionNo`（重複 → `DUPLICATE_TRANSACTION`）、`playerUniqueID`、`gameID`、`payoutAmount`、`settlementTime`。`bet=0`、`win=win_lose=payoutAmount`。TBR 帶 `['bonus' => $win]`。回應走 `bonusResponse`：`{ code, data: { transactionNo, merchantOrderNo, beforeAmount, afterAmount } }`。

### B-7. Cancel — `POST /target/bet/cancel`

**Request 欄位**

| 欄位 | 說明 |
|---|---|
| `transactionNo` | 對應 BR |
| `playerUniqueID` | 玩家 |
| `gameID` | 遊戲 |

流程：先建 `PgsswCancelLog(status=0)` → BR 已是 `ROLLBACK` → `INVALID_STATUS` → 否則 `bet/win/win_lose=0`、`status=ROLLBACK` → `changeBalance` 回退 → TBR create → CancelLog 標 `status=1`。

### B-8. Event Bonus — `POST /target/transaction/event-bonus`

活動獎勵派彩。欄位：`transactionNo`（重複 → `DUPLICATE_TRANSACTION`）、`playerUniqueID`、`amount`、`eventDetailInfo.receiveTime`。`gameCode=0`、`bet=0`、`win=win_lose=amount`。TBR 帶 `['bonus' => $win]`，另寫 `PgsswEventRecord`。回應走 `bonusResponse`。

### §A ↔ §B 對照速查

| 概念 | 官方 PDF（§A） | APIs 實作（§B） |
|---|---|---|
| 查餘額 | `POST /balance/detail` action=1 | `POST /target/players/wallet` |
| 投注 | `POST /balance/transfer` action=2 | `POST /target/bet/placeBet` |
| 派彩 | `POST /balance/transfer` action=3 | `POST /target/bet/settle` |
| 退款 | `POST /balance/transfer` action=4 | （最接近 `cancel`，無直接對應） |
| Rollback | `POST /balance/transfer` action=5 | `POST /target/bet/cancel` |
| 額外行為 | — | settle-change / settle-without-payout / bonus / event-bonus |
| 投注額欄位 | `amount` | `betAmount` / `validAmount` |
| 派彩額欄位 | `amount` | `payoutAmount` |
| 玩家 ID 欄位 | `playerID` | `playerUniqueID` |
| 成敗判定 | `status`（bool） | `code`（int，0=OK） |

---

## 簽章

### §A 官方 3DES-ECB-PKCS7

| 項目 | 值 |
|---|---|
| 演算法 | 3DES（TripleDES） |
| 加密模式 | ECB |
| Padding | PKCS7 |
| 金鑰來源 | `MD5(ApiToken)`（16 bytes raw，補成 24 bytes） |
| 明文 | Request Object 的 JSON 序列化字串 |
| 輸出編碼 | Base64 |

**步驟（§A 官方 C# 範例）**

```csharp
// Step 1：序列化
string signature = JsonConvert.SerializeObject(<Object>);

// Step 2：MD5 衍生金鑰 + 3DES 加密
md5.ComputeHash(UTF8Encoding.UTF8.GetBytes(<Api Token>))  // 作為 tdes.Key
// tdes.Mode = ECB; tdes.Padding = PKCS7

// Step 3：Base64
Convert.ToBase64String(byteArr, 0, byteArr.Length)
```

**PHP 對應（參考，本專案目前未用）**

```php
$key = md5($apiToken, true);            // 16 bytes raw
$key24 = $key . substr($key, 0, 8);     // 補成 24 bytes
$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$signature = base64_encode(openssl_encrypt($json, 'des-ede3', $key24, OPENSSL_RAW_DATA, ''));
```

> 注意：`md5($token, true)` 的 `true` 必填（raw bytes）；ECB 不需 IV（第五參數空字串）；`JSON_UNESCAPED_SLASHES` 避免 `/` 被轉義。

### §B 實作驗證機制（實際使用）

APIs 實作 Tiger9 回調**不驗 3DES 簽章**，改用三道驗證：

1. **商戶識別**：回調 header `X-PROVIDER-IDENTITY` 比對 `vendor_infos.info['IDENTITY']`，找到對應 VendorInfo 後以 `vendor_id` 覆寫（50 / 91 / 145 共用同一 Controller）
2. **IP 白名單**：`Common::verifyIPWhitelistByVendorId($this->vendorId)`
3. **玩家識別**：`playerUniqueID`（優先）或 `sessionToken` 取得 member；找不到 → `INVALID_SIGNATURE (401002)`

主動呼叫（getGameUrl / getBonusCode）以 `merchantID`(`API_APPID`) + `merchantSecretKey`(`API_KEY`) 隨 query/body 帶出，三方端驗來源。

---

## 錯誤碼

### §B OperatorStatusEnum（實作回應碼）

| 名稱 | code | 意義 |
|---|---|---|
| `OK` | `0` | 成功 |
| `INVALID_SIGNATURE` | `401002` | session / 身分驗證不合法或過期 |
| `INSUFFICIENT_BALANCE` | `403203` | 錢包餘額不足 |
| `INVALID_PARAMETER` | `403001` | 參數 / 格式不合法 |
| `DUPLICATE_TRANSACTION` | `500029` | 注單單號重複 |
| `INVALID_STATUS` | `403601` | 注單狀態不合法（例：重複結算） |
| `BET_ALREADY_CANCELLED` | `403612` | 注單已是取消狀態 |
| `BET_NOT_FOUND` | `404016` | 查無注單 |
| `INTERNAL_SERVER_ERROR` | `500001` | 系統錯誤 |

### §A HTTP 狀態碼（官方 PDF）

| Code | 意義 |
|---|---|
| `200` | Success |
| `400` | Fail |
| `401` | Invalid Token |
| `500` | API Error |

---

## 實作與文件落差（待 RD / 客服確認）

1. **協議整體不一致（最高優先）**：官方 PDF 描述 GALAXY / TigerNine「`apiID` + 3DES signature」協議；APIs 實作 Tiger9 走 target 聚合協議。需確認：Tiger9 正式環境實際串接哪一套？PDF 是否為舊版或誤置文件？
2. **簽章機制**：官方 PDF 要求 3DES（ECB/PKCS7，key=MD5(ApiToken)）；實作完全沒有簽章驗證，僅 IP 白名單 + `X-PROVIDER-IDENTITY` + `merchantSecretKey`（後者僅主動呼叫時帶出）。
3. **回調 endpoint 與欄位名**：PDF 用 `playerID` / `action` / `/balance/transfer`；實作用 `playerUniqueID` / `betAmount` / `payoutAmount` / `validAmount` / `gameID` / `/target/bet/*`。
4. **回應格式**：PDF 用 `{ status: bool, message, data }`；實作用 `{ code: int, data }`（成功 code=0）。
5. **Action 模型**：PDF 將 Bet/Win/Refund/Rollback 收斂為單一 `/balance/transfer` action 2/3/4/5；實作拆成獨立路由，且多了 `settle-change`、`event-bonus`、`settle-without-payout`、`bonus` 等 PDF 無對應行為。
6. **對帳保留期**：Tiger9 官方 Log Doc 明確標註**僅近 7 天**。
7. **getBonusCode freespin gameIDs**：Driver 內含硬編碼 freespin gameID 清單與 `rounds=10`，官方 PDF 無此 API 對應章節，來源待確認。
