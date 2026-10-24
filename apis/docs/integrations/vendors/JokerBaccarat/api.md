# JokerBaccarat — 廠商原始 API 規格

> 原始來源：<https://example.invalid/>（Guest account view，可切換 English / Mandarin）。抓取日期：2026-05-15（中、英文版雙向校驗）。
> 本檔為忠實的第三方 API 規格重述，涵蓋 **Transfer Wallet** 與 **Seamless Wallet** 兩種模式。我方實作分析（235 JOKER_BACCARAT_SW 直對部署）、陷阱與落地對應見同目錄 `integration.md`。

JOKERBACCARAT 為線上百家樂遊戲商，提供 **Transfer Wallet（轉帳錢包）** 與 **Seamless Wallet（單一錢包）** 兩種錢包模式，由代理選擇其一接入。

---

## 通用約定

### 請求格式（代理 → Joker，Merchant API）

- **HTTP Method**：所有 API 一律 `POST`；**Content-Type**：JSON。
- **驗證**：IP Whitelist + 加密簽章。真正的請求參數放在 **Signature Object**，序列化後 3DES 加密成 `signature` 傳輸。

通用請求欄位：

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `apiID` | String | ✅ | 與 Portal BO 登入 ID 相同（demo 為 `guest`） |
| `signature` | String | ✅ | Signature Object → 3DES → Base64 後字串 |

### 通用回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | Boolean | 成功 `true`、失敗 `false` |
| `message` | String | 成功描述 / 失敗原因 |
| `data` | Object \| Array \| Null | 成功資料；失敗 `null` |
| `data2` / `data3` | Object \| Null | 分頁類 API 的總筆數與分頁資訊 |

### Base URL

Production / Staging 皆由 Joker 客服提供（Guest 帳號無法查看，需申請正式帳號）。

### HTTP 狀態碼

| Code | 說明 |
|---|---|
| `200` OK | 成功 |
| `400` Bad Request | 請求無效（附錯誤訊息） |
| `403` Forbidden | 認證失敗，通常為 IP 未加白名單 |
| `500` API Error | 三方系統錯誤，聯絡客服 |

---

## 簽章演算法（3DES，Merchant API 專用）

| 項目 | 值 |
|---|---|
| 對稱演算法 | 3DES（Triple DES） |
| 加密模式 | ECB |
| Padding | PKCS7 |
| 金鑰 | `MD5(API_Token)`（UTF-8 bytes 做 MD5，輸出 16 bytes 直接作 3DES key） |
| 明文 | Signature Object 的 JSON 序列化字串 |
| 輸出 | Base64 |

步驟：① `JsonConvert.SerializeObject(物件)` 序列化 → ② `tdes.Key = MD5(UTF8(ApiToken))`、ECB、PKCS7 加密 → ③ Base64。最終 body：`{ "apiID": "guest", "signature": "<Base64>" }`。

PHP 對應（`JokerBaccaratSWTrait::buildSignature()`）：

```php
$json  = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$key   = md5($API_TOKEN, true);          // ⚠ 第二參數 true = raw 16 bytes（非 hex）
$key24 = $key . substr($key, 0, 8);      // 補滿 24 bytes，避免 OpenSSL 版本差異
$cipher = openssl_encrypt($json, 'des-ede3', $key24, OPENSSL_RAW_DATA, ''); // ECB 不用 IV
return base64_encode($cipher);
```

> 常見錯誤：① `md5($token, true)` 漏第二參數 → key 長度錯 → Joker 回 `400` 含 "decrypt"；② `JSON_UNESCAPED_SLASHES` 不可省，避免 `/` → `\/` 致兩端 JSON 不一致。

> Callback（Seamless `/balance/detail`、`/balance/transfer`、`/goodRoad`）**不帶 signature**，僅以 IP 白名單驗證。

---

## 錢包模式比較

| 項目 | Transfer Wallet | Seamless Wallet |
|---|---|---|
| 玩家餘額 | Joker 端保管 | 代理端保管（每次下注經回調驗證） |
| 進遊戲前 | 先呼叫 `Transfer Balance` 把餘額轉入 Joker | 直接 Launch Game，下注由 callback 扣款 |
| 必呼叫 API | Register Player → Transfer Balance → Open Table/Lobby | Register Player → Open Table/Lobby |
| 代理須提供 callback | 否 | 是（`/balance/detail`、`/balance/transfer`、`/goodRoad`） |

> Seamless：Joker 收到下注 → 檢查有效性 → 呼叫代理 `/balance/transfer` action=2 → 依回應決定下注成敗。Transfer：Joker 持有餘額直接確認。切換錢包類型須聯絡客服。

---

## Merchant API（代理 → Joker）

> 每支 endpoint 共同格式 `POST <Base URL><Endpoint>` body `{ apiID, signature }`，參數放 Signature Object。

### Player

**1. Register New Player** — `POST /merchant/player/register`
系統自動為 playerID 加 account prefix；**後續 API 必須用此 API 回傳的 playerID**。
Signature Object：`playerID`（String，❌ 空則自動產生）、`password`（String，❌ 空則自動產生）。
Success `data`：`playerID` / `playerToken` / `balance`。

**2. Get Player Info** — `POST /merchant/player/get-info`
查玩家資料；Transfer Wallet 模式回 Joker 端餘額。
Signature Object：`playerID`（String，✅）。Success `data`：`playerID` / `playerToken` / `balance`。錯誤 `Player not found`。

**3. Update Bet Limit** — `POST /merchant/player/update/bet-limit`
調玩家下注上下限；**只能更嚴格、不能放寬桌台限制**（玩家 minBet 高於桌台取玩家、低於取桌台；maxBet 反向）。
Signature Object：`playerID`（✅）、`minBet`（Decimal，✅，須 < maxBet）、`maxBet`（Decimal，✅，須 > minBet）。

**4. Log Out** — `POST /merchant/player/log-out`
強制踢出玩家；**下注中注單不退款，依正常結算繼續**。
Signature Object：`playerID`（✅）。

### Player Wallet

**5. Transfer Balance** — `POST /merchant/player/wallet/transfer`
> **僅 Transfer Wallet 模式**使用。
Signature Object：`playerID`（✅）、`transferType`（String，✅，**只接受** `deposit` / `withdraw`）、`amount`（Decimal，✅，> 0.00）、`orderNo`（String，❌ 空則自動產生，可於 Portal BO 查單）。
Success `data`：`orderNo`。
> ⚠️ 冪等與重試：文件未明說逾時是否允許重送相同 `orderNo`。實作 `transferToVendor`/`transferToLocal` 遇逾時 / 不確定須回 `VendorTransferResultEnum::TIMEOUT` 並後續查單確認。

### Game

**6. Get Table List** — `POST /merchant/game/table-list`
Signature Object：`category`（String，✅，公開分類僅 `all`）、`lang`（String，✅）。
Response：`data`（桌台清單，每筆 `category`/`tableCode`/`tableName`/`ThumbnailSquareURL`/`ThumbnailRectURL`）、`data2`（可用 category 清單）。

**7. Open Table** — `POST /merchant/game/open-table`
玩家跳過大廳直接進桌台。
Signature Object：`playerID`（✅）、`tableCode`（✅，由 Get Table List 取得）、`redirectURL`（❌）、`lang`（✅）。
Success `data`：遊戲完整 URL（字串）。

**8. Open Lobby** — `POST /merchant/game/open-lobby`
玩家從大廳選遊戲。
Signature Object：`playerID`（✅）、`category`（✅，公開僅 `all`）、`redirectURL`（❌）、`lang`（✅）。
Success `data`：大廳 URL（字串）。

### Report

> **資料保留期限：3 個月**，更早須聯絡客服。

**9. Game Log** — `POST /merchant/report/game-log`（**回合層級**，每回合每玩家一筆）
Signature Object：`startDate`（✅）、`endDate`（✅）、`playerID`（❌，空=全部）、`roundID`（❌，空=全部）、`page`（Int，✅）、`pageSize`（Int，✅）。
`data[]` 欄位：`playerID` / `category` / `tableCode` / `roundID`（跨玩家相同）/ `beforeBalance` / `totalBet` / `totalWin` / `totalWinLoss` / `validTurnover` / `gameResults`（JSON 字串，含莊閒點數）/ `settlementTime`（ISO8601）。`data2.rowCount`、`data3` 分頁。

**10. Game Log Details** — `POST /merchant/report/game-log/detail`（**交易層級**，一回合可多筆 transaction）
Signature Object：`startDate`（✅）、`endDate`（✅）、`playerID`（❌）、`transactionNo`（❌）、`roundID`（❌）、`page`（✅）、`pageSize`（✅）。
`data[]`：`playerID` / `transactionNo` / `roundID` / `totalBet` / `totalWin` / `netGaming` / `bettingTime` / `settlementTime` / `betItems[]`（`betCode` / `betAmount` / `winAmount`）。
`betCode`（推測，需向客服確認完整對照）：`BW` 莊贏 / `PW` 閒贏 / `T` 和 / `BP` 莊對 / `PP` 閒對。

**11. Daily Summary** — `POST /merchant/report/daily-sum`（彙總）
Signature Object：`startDate`（✅）、`endDate`（✅）、`playerID`（❌）、`page`（✅）、`pageSize`（✅）。
`data[]`：`playerID` / `totalCount` / `totalBet` / `totalWin` / `totalWinLoss` / `totalValidTurnover`。

---

## Seamless Wallet Callback API（Joker → 代理）

> **僅 Seamless Wallet 模式需實作**。Joker 主動 POST 至代理提供的 URL，**不帶 signature**，安全靠 IP 白名單。回應成敗完全以 body `status` 為準（非 HTTP 200）。

代理須提供 3 個 endpoint：`/balance/detail`（查餘額）、`/balance/transfer`（資金異動）、`/goodRoad`（好路推播，可選）。

### Action Code 對照表

| Code | 意義 | 出現於 | 說明 |
|---|---|---|---|
| `1` | Get Balance | `/balance/detail` | 玩家進遊戲 / 新回合開始查餘額 |
| `2` | Player Bet | `/balance/transfer` | 下注扣款 |
| `3` | Player Win | `/balance/transfer` | 派彩；**百家樂和局退注亦屬此類** |
| `4` | Refund | `/balance/transfer` | 遊戲中斷 / 代理回應過慢時的系統退款 |
| `5` | Rollback | `/balance/transfer` | 對帳差異時注單重同步，代理依此校正 |

### 1. Get Balance（`/balance/detail`）

Request：`playerID`（✅）、`action`（固定 `1`）、`actionDesc`（`"Get Balance"`）。
Success：`{ status:true, data:{ balance } }`。
> 不需 `BetHelper::isMemberAbleToBet`（僅查餘額），須帳號狀態檢核。

### 2. Update Balance（`/balance/transfer`）

> **核心規則**：對帳差異**以 Joker 資料庫紀錄為準**。所有資金異動經此，由 `action` 區分。

Request：`playerID`（✅）、`action`（✅，`2`/`3`/`4`/`5`）、`actionDesc`（✅）、`transactionNo`（✅，唯一，同回合可多筆）、`roundID`（✅，同玩家同回合唯一，**Joker 用 roundID 算 valid turnover**）、`amount`（Decimal，✅，正數）。
Success：`{ status:true, data:{ transactionNo, balance } }`。

各 action 代理端應做：

| `action` | 處理 |
|---|---|
| `2` Bet | `lockForUpdate` → 檢查餘額 → 扣款 + 建 `deal_records` + `bet_records` → 回 balance |
| `3` Win | 派彩加錢、更新 BR 的 `win`/`win_lose`/`payout_time`、觸發 TBR 寫入 |
| `4` Refund | BR 標退款、回退已扣金額 |
| `5` Rollback | 依 `transactionNo` 重比對，以 Joker 為準調整 |

冪等：

| 場景 | 處理 |
|---|---|
| 重複 `transactionNo`（相同 action） | 不重複處理，回原 balance（HTTP 200, `status:true`） |
| 相同 `transactionNo` 但不同 `action` | 視為流程錯誤，回 `status:false` 並註明衝突 |
| `action=4 Refund` 對應 Bet 不存在 | 文件未明說；保守回 `status:true` 並記 log，避免阻擋遊戲（建議向客服確認） |

### 3. Good Road Widget（`/goodRoad`）

> **須先聯絡客服啟用**，否則預設不啟用。桌台出現有利路紙時推播給代理。

Request：`TableCode`（✅）、`TableName`（Object[]，✅，`Lang`/`Value` 多語言）、`Category`（✅）、`ThumbnailSquareURL`（✅）、`ThumbnailRectURL`（✅）、`GoodRoadID`（✅，見下）、`Data`（✅，完整路紙資料）。

GoodRoadID 對照：`joker1`/`baccarat1` = LV（長龍）；`joker2`/`baccarat2` = Single Jump（單跳）。

`Data` 含 5 條路紙 `Road_1`~`Road_5`（大路 / 大眼仔 / 小路 / 蟑螂 / 預測）。每 cell 欄位：`result`（`BW`/`PW`/`T`）、`winCode`（中獎碼陣列，如 `["PW","PP"]`）、`winPoint`、`playerPair`/`bankerPair`、`fillColor`/`strokeColor`/`lineColor`、`tieCount`。統計 `data2`：`total`/`banker`/`player`/`tie`。預測色碼 `data3`：`b`（莊預測色 `[紅,藍,空]`）、`p`（閒預測色）。

---

## 介面中英文名稱對照（聯絡客服 / RD 用）

| 英文 | 中文（官方簡中） | 文件站 URL |
|---|---|---|
| Register New Player | 注册新玩家 | `/player/register` |
| Get Player Info | 获取玩家信息 | `/player/get-info` |
| Update Bet Limit | 更新投注限额 | `/player/update/bet-limit` |
| Log Out | 登出 | `/player/log-out` |
| Transfer Balance | 转账余额 | `/wallet/transfer-balance` |
| Get Table List | 获取桌台列表 | `/table/list` |
| Open Table | 进入桌台 | `/table/open` |
| Open Lobby | 进入大厅 | `/lobby/open` |
| Game Log | 游戏记录 | `/report/game-log` |
| Game Log Details | 游戏记录详情 | `/report/game-log/detail` |
| Daily Summary | 每日汇总 | `/report/daily-sum` |
| Get Balance | 获取余额 | `/seamless-wallet/balance/get` |
| Update Balance | 更新余额 | `/seamless-wallet/balance/update` |
| Good Road Widget | 好路小部件 | `/seamless-wallet/good-road` |
| Signature | 签名 | `/signature` |

> 上欄 URL 為**文件站展示 URL**，非實際 endpoint；實際 endpoint 見各 API 章節（如 `/merchant/player/register`、`/balance/transfer`）。

---

## 附錄：語言代碼

`EN` English / `CN` Mandarin / `MY` Malay / `TH` Thai / `VN` Vietnamese / `MM` Burmese / `JP` Japanese。傳入無效代碼 fallback 為 `EN`。

---

## Change Log（官方）

| 日期 | 變更 |
|---|---|
| 2026-02-04 | Comprehensive system upgrade — 系統全面停機升級，代理需重新連接 API |

---

## Sources

- <https://example.invalid/>（Guest view，中英雙語），2026-05-15 抓取：Merchant API（Player / Wallet / Game / Report 共 11 支）、Seamless Wallet Callback（Get Balance / Update Balance / Good Road）、Signature（3DES ECB PKCS7）、語言附錄。
- 交叉驗證（235 JOKER_BACCARAT_SW 直對部署）：`app/Vendors/JokerBaccaratSW/Driver.php` / `JokerBaccaratSWTrait.php`；`app/Http/Controllers/External/SingleWallets/JokerBaccaratSwController.php`；`routes/external/sw.php`。
