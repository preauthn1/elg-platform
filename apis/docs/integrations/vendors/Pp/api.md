# Pp（Pragmatic Play）— API 規格

> 原始來源（下載包 `遊戲商/54.pp/`）：
> - 「Integration API Specification ZH.pdf」— 官方規範，API 版本 **3.225**，更新時間 **2026-08**（主要事實來源；§ DataFeeds 依此版補入）
> - 「Pragmatic Play API.pdf」— 舊版官方規範，API 版本 3.219，更新時間 2025-05
> - 「API文檔.txt」— 線上文件入口：`https://example.invalid/apireal`（EN）/ `.../apireal-zh`（ZH），pass：`ilikeppgames`
> - 「開新商戶需提供資訊.txt」— 開戶需提供：運營商名稱、品牌線路、錢包類型、幣別、產品、技術對接人/信箱、優惠負責人/信箱、**API 白名單**
> - 「PragmaticPlay All and Top Game List Nov 2023.xlsx」— 遊戲清單（2023-11，可能過時）
> - 「LCQ v3.5 (external use).xlsx」、「tpower.txt」、「wintotoidr.txt」、`PP Logo/` — 上線檢核清單與品牌資產
>
> 抓取日期：**2026-06-03**

本文件整理 **Transfer Wallet（余额结转 / Balance Transfer）模式**，即 APIs 專案 vid 54 採用的對接模式。PP Seamless Wallet 以獨立 vendor 接入（PPSW vid=83），不在本文件範圍。

---

## 通用約定

- **HTTP Method**：所有 API 一律 `POST`
- **Content-Type**：`application/x-www-form-urlencoded`（請求是 form 參數，**非** JSON body）
- **回應格式**：JSON（TransferTransactions 例外，回 CSV）
- **驗證方式**：IP 白名單 + 每請求 MD5 `hash`（見 [§ 簽名](#簽名)）
- **僅接受 HTTP 200** 作為有效回應；非 200 視同 `error: 1`（內部錯誤，依頻率重試）

### Base URL

| 環境 | URL 形式 |
|---|---|
| 通式 | `https://{API service domain}/IntegrationService/v3/http/CasinoGameAPI` |
| PDF 範例（pre-release 測試） | `api.prerelease-env.biz` |
| 正式 / 測試實際值 | **由 PP 客服提供（文件未列正式 domain）** |

> APIs 專案把完整前綴（到 `.../IntegrationService/v3/http`）存於 `vendor_infos.info.API_URL`，Driver 各 endpoint 再接 `/CasinoGameAPI/...` 路徑。

### 通用回應欄位

| 欄位 | 說明 |
|---|---|
| `error` | `"0"` 成功；否則為錯誤碼（見 [§ 錯誤碼](#錯誤碼411) ） |
| `description` | 成功為 `OK`，失敗為錯誤原因 |
| 其他 | 視各 API 而定（`balance`、`transactionId`、`gameURL` 等） |

---

## Merchant API

### 4.4 CreatePlayer

在 PP 系統建立玩家帳戶。應在玩家被送進遊戲**之前**呼叫。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/player/account/create/` |

#### 請求參數

| 欄位 | 必填 | 說明 |
|---|---|---|
| `secureLogin` | ✅ | API 服務驗證用的用戶名 |
| `externalPlayerId` | ✅ | 玩家在運營商系統中的 ID |
| `currency` | ✅ | 玩家幣別（ISO 4217） |
| `hash` | ✅ | 請求 hash |

#### 回應

| 欄位 | 說明 |
|---|---|
| `playerId` | PP 系統中玩家 ID（**僅在首次以該 `externalPlayerId` 建立時回傳**） |
| `error` / `description` | 錯誤碼 / 說明 |

```json
{ "error": "0", "description": "OK", "playerId": 6749178 }
```

> **實作差異**：`app/Vendors/Pp/Driver.php` **未**呼叫 CreatePlayer。Driver 直接以 `externalPlayerId` 呼叫 Transfer / StartGame，依賴 PP 端「首次請求自動建立帳戶」的行為。目前運作正常，若 PP 端日後要求 Transfer 前帳戶必須先存在，需補上此呼叫。

---

### 4.5 Transfer

在 PP 系統將資金轉入玩家餘額（充值，正金額）或轉出（提款，負金額）。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/balance/transfer/` |

#### 請求參數

| 欄位 | 必填 | 說明 |
|---|---|---|
| `secureLogin` | ✅ | API 驗證用戶名 |
| `externalPlayerId` | ✅ | 玩家 ID |
| `externalTransactionId` | ✅ | 運營商系統中的交易 ID（唯一） |
| `amount` | ✅ | 正值=加到玩家餘額；負值=從玩家餘額扣除；以玩家幣別計 |
| `hash` | ✅ | 請求 hash |

#### 回應

| 欄位 | 說明 |
|---|---|
| `transactionId` | PP 系統中的轉帳交易 ID |
| `balance` | 成功交易後玩家餘額 |
| `error` / `description` | 錯誤碼 / 說明 |

```json
{ "error": "0", "description": "OK", "transactionId": 1908759, "balance": 999.99 }
```

#### 冪等與重試（官方重要說明）

- 若 Transfer 因 **網路錯誤（HTTP ≠ 200）** 或 **`error: 1`（內部錯誤）** 失敗，運營商應以**相同 `externalTransactionId`** 等待後重試。
- **重試是冪等的**：以相同 `externalTransactionId` 再送只會對應到同一筆交易，不會重複扣加款。
- 重試頻率限制：每個 API 帳戶，`Transfer` / `GetTransferStatus` 失敗重試**不得超過每分鐘一次、且不得超過 1 小時**；1 小時後仍失敗應走支援管道。

> ⚠️ **實作差異（風險）**：`Driver::transfer()` 只把 `error === '0'` 視為 `SUCCESS`，其餘一律 `FAILED`。但官方 §4.11 指明 **`error: 8`（交易已存在）代表該筆交易先前已成功**，重試時收到 `8` 應視為**成功**（「無需操作」）。目前實作會把 `error: 8` 判成 `FAILED`，在逾時重送情境下可能誤判已成功的轉帳為失敗。建議增加 `error === '8'` → `SUCCESS` 分支。

> APIs 專案對應：
> - `transferToVendor`（存款）→ `amount` 為正
> - `transferToLocal`（提款）→ `amount` 前置負號 `'-'`

---

### 4.6 GetTransferStatus

查詢某筆轉帳（依 `externalTransactionId`）在 PP 端的狀態。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/balance/transfer/status/` |

> 重要：僅回傳 2017-08-03 之後的成功轉帳。更早的轉帳回傳值為空，`description` 含 `"Transaction is older than 3.08.2017"`。

#### 請求參數

| 欄位 | 必填 | 說明 |
|---|---|---|
| `secureLogin` | ✅ | API 驗證用戶名 |
| `externalTransactionId` | ✅ | 運營商系統內的交易 ID |
| `externalPlayerId` | ✅ | 玩家 ID |
| `hash` | ✅ | 請求 hash |

#### 回應

| 欄位 | 說明 |
|---|---|
| `transactionId` | PP 系統中的轉帳交易 ID |
| `status` | `Success`=交易成功；`Not found`=**未處理（pending）** |
| `amount` | 增加（正）/扣除（負）金額 |
| `balance` | 成功後餘額；若未處理則為當前玩家餘額 |
| `error` / `description` | 錯誤碼 / 說明 |

```json
{ "error": "0", "description": "OK", "transactionId": 1908759, "status": "Success", "amount": "999.99", "balance": 999.99 }
```

> ⚠️ **實作差異（風險）**：`Driver::checkTransferStatus()` 把 `status === 'Not found'` 映射為 `VendorTransferResultEnum::FAILED`，程式碼備註誤寫「Not found=處理中」（邏輯與備註矛盾）。官方語意 `Not found` = **未處理（pending）**，並非確定失敗。建議將 `Not found` 映射為 `TIMEOUT`（讓上層續查/重試）而非 `FAILED`，以符合 §4.11「傳輸正在進行中 → 以當前頻率重試」的規定。

---

### 4.7 GetBalance

取得玩家在 PP 系統中的當前餘額。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/balance/current/` |

#### 請求參數

| 欄位 | 必填 | 說明 |
|---|---|---|
| `secureLogin` | ✅ | API 驗證用戶名 |
| `externalPlayerId` | ✅ | 玩家 ID |
| `hash` | ✅ | 請求 hash |

#### 回應

```json
{ "error": "0", "description": "OK", "balance": 999.99 }
```

> APIs 專案：`Driver::getBalance()` 回傳 `(string) $result['balance']`。依 APIs 規範，後續若參與運算/比較須以 `Common::numAdd('0', (string) $value, 4)` 正規化，避免浮點精度誤差。

---

### 4.8 StartGame

取得開啟指定遊戲的 URL（含一次性票證）。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/game/start/` |

#### 請求參數

| 欄位 | 必填 | 說明 |
|---|---|---|
| `secureLogin` | ✅ | API 驗證用戶名 |
| `externalPlayerId` | ✅ | 玩家 ID |
| `gameId` | ✅ | PP 系統中的遊戲 ID（symbol，如 `vs50aladdin`） |
| `language` | ✅ | 遊戲顯示語言（ISO 639-1，如 `en`） |
| `platform` | ❌ | `MOBILE` / `WEB`（未給預設桌面版） |
| `cashierURL` | ❌ | 玩家資金不足時開啟的收銀台連結 |
| `lobbyURL` | ❌ | 行動版「返回大廳/主頁」按鈕的連結 |
| `ctlgroup` | ❌ | 特定賭桌類組標識（僅真人娛樂場） |
| `playMode` | ❌ | 缺省或 `REAL`=真實遊戲；`DEMO`=試玩 |
| `hash` | ✅ | 請求 hash |

#### 回應

| 欄位 | 說明 |
|---|---|
| `gameURL` | 玩家開啟遊戲的完整連結（含須保留的特殊參數） |

```json
{
  "error": "0",
  "description": "OK",
  "gameURL": "https://{game_server_domain}/gs2c/openGame.do?tc=...&stylename=username&lang=en"
}
```

> ⚠️ **實作差異**：
> - Driver 多送 `country='MY'`（固定），此欄位**不在** §4.8 官方參數表，且進入 hash 計算。目前實測可開局，待 PP 確認。
> - `platform` 目前寫死 `MOBILE`（依 deviceType 切換的程式碼被註解），桌面玩家也拿到 MOBILE 啟動參數。

---

### 4.9 TerminateSession

終止當前玩家會話並將其踢出所有遊戲。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/game/session/terminate/` |

#### 請求參數

| 欄位 | 必填 | 說明 |
|---|---|---|
| `secureLogin` | ✅ | API 驗證用戶名 |
| `externalPlayerId` | ✅ | 玩家 ID |
| `hash` | ✅ | 請求 hash |

```json
{ "error": "0", "description": "OK" }
```

> APIs 專案：Driver 目前**未**實作呼叫此 API。

---

### 4.10 TransferTransactions

回傳所有玩家在指定時間點之後（但不超過 10 分鐘）的轉帳交易（充值與提款），**以 CSV 格式**回傳。供對帳補單用。

| 項目 | 值 |
|---|---|
| Method | `POST` |
| Endpoint | `/balance/transfer/transactions` |

#### 請求參數

| 欄位 | 必填 | 說明 |
|---|---|---|
| `secureLogin` | ✅ | API 驗證用戶名 |
| `timepoint` | ❌ | Unix epoch（毫秒）起點；空則回傳新的 timepoint |
| `hash` | ✅ | 請求 hash |

#### 回應（CSV）

第一行為新的 `timepoint=...`，第二行為欄位表頭，其後為資料列：

```
timepoint=1482510979767
externalPlayerId,timestamp,externalTransactionId,amount,currency
421,1482510741000,1482429190474,100.00,USD
422,1482510742000,1482429190475,-200.00,USD
```

| 欄位 | 說明 |
|---|---|
| `externalPlayerId` | 運營商系統內玩家唯一識別符 |
| `timestamp` | PP 端處理時間（Unix epoch 毫秒） |
| `externalTransactionId` | 運營商系統內交易唯一識別符 |
| `amount` | 正=存款 / 負=取款，以玩家幣別計 |
| `currency` | ISO 4217 |

> APIs 專案：未見實作呼叫；屬選用對帳補單管道。

---

### 集成 API（選用）

§II 集成 API 提供遊戲清單與會話管理，皆為**選用**：

| API | Endpoint | 用途 |
|---|---|---|
| GetCasinoGames | `/getCasinoGames/` | 取可整合遊戲清單（建大廳用），可帶 `options`（`GetFrbDetails`/`GetLines`/`GetFeatures`）與 `FilterStudio=<studio>` 過濾 |
| GetLobbyGames | `/getLobbyGames/` | 取後台老虎機大廳配置（`categories=all,new,hot`、`country`） |
| CloseSession | `/closeSession/` | 終止玩家活動會話，`clearHistory=1` 會刪未完成回合歷史 |
| CancelRound | `/cancelRound/` | 對某 `roundId` 發起退款，回合標記為「已取消」（BT 整合僅 24 小時內可取消） |

---

## DataFeeds（注單拉取，§VIII）

注單由本平台主動拉取，PP 不推送。Driver `fetchBetRecords()` 使用 §8.2 的 `gamerounds/finished`。

### 通用規則

| 項目 | 說明 |
|---|---|
| Base URL | `https://{API service domain}/IntegrationService/v3/DataFeeds/...`（存於 `vendor_infos.info.BET_URL`） |
| Method | `GET` |
| 驗證 | IP 白名單 + query 參數 `login`（secureLogin）、`password`（secret key），**不走 MD5 hash** |
| 時間窗 | 以 `timepoint`（Unix 毫秒）為起點，一次只回該點之後 **10 分鐘**內的資料；回應第一行為下一個 `timepoint=` |
| 頻率 | 同一 API 帳戶、同一方法 **每分鐘最多 1 次** |
| 保留期 | 最晚可取到**上個月**的資料 |
| `dataType` | `RNG`（老虎機等，預設）/ `LC`（真人）/ `VSB`（虛擬體育）；**不帶 dataType 只回 RNG** |
| 格式 | CSV（第 0 行 `timepoint=...`、第 1 行欄名） |

### 8.2 gamerounds 三種路徑

| 路徑 | 內容 |
|---|---|
| `/DataFeeds/gamerounds/` | 時間窗內所有回合，含未完成；未完成回合可能出現兩次（下注一次、派彩一次） |
| `/DataFeeds/gamerounds/finished/` | **只回時間窗內完成的回合，每局只出現一次**（Driver 採用） |
| `/DataFeeds/gamerounds/adjusted/` | 只回時間窗內被調整過的回合（僅 Live Casino） |

#### 請求參數

| 參數 | 必填 | 說明 |
|---|---|---|
| `login` | ✅ | secureLogin |
| `password` | ✅ | secret key |
| `timepoint` | — | 起始時間點（Unix 毫秒）；空值則回傳新的時間點 |
| `dataType` | — | 見上表 |
| `options` | — | 逗號分隔：`addBalance`、`addRoundDetails`、`addBonusBetWin`、`FinalizedRoundsSeparated`、`FinalizedRoundsHidden`（後兩者僅 `finished` 可用） |

#### 回應欄位（CSV）

| 欄位 | 說明 |
|---|---|
| `playerID` | PP 系統內玩家 ID |
| `extPlayerID` | 運營商玩家 ID（＝ `pp_accounts.username`） |
| `gameID` | 遊戲 ID |
| `playSessionID` | 回合唯一編號（＝ `pp_bet_records.bet_id`） |
| `parentSessionID` | 觸發特殊遊戲的母回合；免費旋轉列填母回合 ID，其餘與 `playSessionID` 相同 |
| `startDate` / `endDate` | `yyyy-mm-dd HH:mm:ss`；未完成時 `endDate` 為空 |
| `status` | **`I`＝進行中、`C`＝已完成、`F`＝取消或最終確定（僅 `finished` 會出現）** |
| `type` | `R`＝遊戲回合、`F`＝回合中觸發的免費旋轉 |
| `bet` / `win` | 投注 / **派彩**（不是輸贏；輸贏＝`win - bet`） |
| `currency` | ISO 4217 |
| `jackpot` | 累積獎金 |
| `bonusBet` / `bonusWin` | 帶 `addBonusBetWin` 才有 |
| `roundDetails` | 帶 `addRoundDetails` 才有；真人局投注與結果（例：`type:bets,desc:[...]`） |
| `balance` | 帶 `addBalance` 才有 |

#### `status = F`（取消或最終確定）

`F` 有兩種來源，依產品區分（PP 客服 2026-10-21 確認）：

| 產品 | `F` 的意思 | 說明 |
|---|---|---|
| 真人（`LC`，bet_id 以 `008` 結尾） | **取消** | 真人局不會自動完成，`F` 一律是取消單；PP 後台投注報表不計入 |
| RNG | 取消或**自動完成** | 自動完成規則見 §2.6 / §XVII，轉帳錢包模式如下 |

RNG 自動完成（Balance Transfer）：

| 情況 | PP 處理 |
|---|---|
| a) 玩家根本沒玩該局 | 下注金額退回玩家餘額，結束該局 |
| b) 玩過且有贏 | 部分獎金計入餘額，結束該局 |
| c) 玩過但沒贏 | 餘額不變，結束該局 |

> Driver 的處理見 `integration.md` 陷阱 4。選項 `FinalizedRoundsSeparated` / `FinalizedRoundsHidden` 可讓 PP 把 F 局分開回傳或不回傳；本專案目前未使用。

### 其他 DataFeeds（選用，本專案未使用）

| 路徑 | 用途 |
|---|---|
| `/DataFeeds/transactions/` | 回合內資金交易；`type`：`B` 下注、`W` 派彩、`V` 部分派彩、`L` 取消投注、`R` 退款、`J` 累積獎金、`P` 推廣獎勵；帶 `addTransactionStatus` 回 `status`：`S` 成功、`L` 取消、`R` 退款 |
| `/DataFeeds/gamerounds/canceled/` | 被取消的回合（CSV）；`onlyPartialWin` 只回部分派彩回合 |
| `/DataFeeds/totals/daily/` | 依幣別的每日總計（只算已完成回合，跨日以完成日計），可用於交叉核對 |
| `/DataFeeds/gamerounds/incomplete/` | 指定玩家未完成的回合（JSON） |

---

## 簽名

PP 的 Transfer Wallet API 對「所有請求參數」計算 MD5 `hash`，由 PP 端用相同流程重算後比對。

| 項目 | 值 |
|---|---|
| 演算法 | `MD5`（hex 字串輸出） |
| 金鑰 | `SECRET`（建立帳號時由 PP 客服提供，APIs 專案存於 `vendor_infos.info.SECRETKEY`） |
| 輸入 | 除 `hash` 以外的所有請求參數 |

### 構成步驟（官方 §4.2 原文）

1. 按字母表順序對所有參數排序（`ksort`）。
2. 在 `key1=value1&key2=value2` 中附加它們（標準 query string 格式，不含開頭 `?`）。
3. 附加 SECRET，緊接最後一個 value，**沒有分隔符**：`key1=value1&key2=value2SECRET`。
4. 使用 MD5 計算 hash。
5. 與請求中 `hash` 參數比對；失敗則回 `error: 2`（Transfer Wallet 語意；§4.2 提到的「代碼 5」出現在 Seamless callback 情境，對接 Transfer Wallet 時以代碼 2 為準）。

### 本專案實作（`app/Vendors/Pp/Driver.php::getHash()`）

```php
private function getHash(array $data): string
{
    // 1. 參數依 key 做自然排序
    ksort($data);

    // 2. 以 key=value 用 & 串接（PHP http_build_query）
    $hash = http_build_query($data);

    // 3. 直接把 SECRET 接在字串最後
    $hash .= $this->secretKey;

    // 4. MD5
    return md5($hash);
}
```

與官方 §4.2 一致：`ksort` → `http_build_query` → 串接 SECRET → `md5`。

> **注意**：`http_build_query()` 會對 value 做 URL-encode（空白 → `+`、`/` → `%2F`）。官方範例 HTTP body 也是 `application/x-www-form-urlencoded`，兩端 encode 一致時可正常通過；若參數值含特殊字元（如 `lobbyURL` 含 `://`），需確認 PP 端以 encode 前還是 encode 後的字串重算 hash。目前實作以 encode 後字串計算，與官方範例一致。

### 官方 HTTP 請求示例（含 hash）

```http
POST /IntegrationService/v3/http/CasinoGameAPI/balance/transfer/ HTTP/1.1
Host: api.prerelease-env.biz
Content-Type: application/x-www-form-urlencoded
Cache-Control: no-cache

secureLogin=username&externalPlayerId=test_player&externalTransactionId=EXT123456789&hash=380720e974ae2b775ce933fb73eb8fcf&amount=999.99
```

> `hash` 本身**不參與**下一輪 hash 計算（計算 hash 時 `data` 陣列中尚未放入 `hash` key，最後一步才 `$data['hash'] = $this->getHash($data)`）。

---

## 錯誤碼（§4.11）

Balance Transfer API 回應的 `error` 碼。非 HTTP 200 視同 `error: 1`（內部錯誤，稍後重試）。

| 代碼 | 說明 | Transfer 方法建議 | 其他方法建議 |
|---|---|---|---|
| `0` | 成功 | — | — |
| `1` | 內部錯誤，請重試 | 以相同 `externalTransactionId` 重試；收到 `0` 或 `8` 即成功，收到「轉帳失敗」則 PP 已退款回玩家錢包 | 至支援管道查原因 |
| `2` | 安全登錄名/密碼組合錯誤（**hash 驗證失敗也回此碼**） | 驗證請求值 | 驗證請求值 |
| `6` | 未找到遊戲或系統不允許該遊戲 | — | 至支援管道查原因 |
| `7` | 一個或多個輸入參數未設置或設置錯誤 | 驗證請求值 | 驗證請求值 |
| `8` | **交易已存在**（重試命中既有成功交易） | **無需操作（視為成功）** | — |
| `17` | 未找到玩家 | 驗證請求值 | 驗證請求值 |
| `21` | 幣別代碼錯誤或不受支援 | — | 驗證請求值 |
| `100` | （Transfer）可用資金不足 / （GetTransferStatus）「請稍後重試」 | 不足：呼叫 GetBalance 取實際餘額；進行中：依頻率重試 | — |

---

## 附錄：通用資料型別（§4.3）

| 欄位 | 型別 | 說明 |
|---|---|---|
| `externalPlayerId` | String(100) | 運營商系統內玩家唯一識別符，**區分大小寫** |
| `playerId` | String(100) | PP 系統內玩家唯一識別符（故障排除用） |
| `currency` | String(3) | ISO 4217 |
| `language` | String(2) | ISO 639-1 |
| `externalTransactionId` | String(100) | 運營商系統內交易唯一識別符 |
| `transactionId` | String(100) | PP 系統內交易唯一識別符 |
| `amount` | Decimal(10,2) | 正=充值、負=提款 |
| `balance` | Decimal(10,2) | 玩家當前餘額 |
| `gameId` | String(20) | PP 遊戲 symbol，如 `vs25queenofgold` |
| `platform` | String(20) | `MOBILE` / `WEB`（缺省為桌面版） |
| `URL` | String(250) | LobbyURL / CashierURL / GameURL |
| `error` | Integer | `0`=成功，否則為錯誤碼 |
| `description` | String(250) | 故障排除錯誤說明 |
