# Top55 (GoldenGateX) — 單一錢包整合規格

> **vendor id**：182–212（31 個子商戶，`VendorIdEnum::TOP55SW_*`）
> **錢包型態**：Single Wallet（單一錢包，Seamless Wallet）
> **原始來源**：`top55 API document_latest.pdf`（由三方提供，存放於 `/Users/rb/Workspace/遊戲商/182-212.Top55聚合/`）
> **文件版本**：API version 2.15.5（最後更新 2026-01-07）
> **底層平台**：GoldenGateX（© 2026 GoldenGateX）
> **抓取日期**：2026-06-03

---

## 通用約定

### 認證方式

1. **Bearer Token（主動 API 呼叫用）**：先呼叫 `POST /auth/createtoken` 取得短效 token，再以 `Authorization: Bearer {token}` 帶入後續請求 Header。Token 有過期時間（`expiration` Unix timestamp 秒），HTTP 401 觸發自動重刷（Driver 已實作，最多重試 3 次）。Driver 以 `CacheTtl::HALF_DAY`（12 小時）快取，key 為 `top55_token:[REDACTED]
2. **Basic Auth（Seamless 回調驗證用）**：GoldenGateX 主動呼叫代理端時，Header 帶 `Authorization: Basic {base64(clientId:clientSecret)}`，代理端需驗證此值是否與 `vendor_infos.info.clientId` / `clientSecret` 一致。

### 請求格式

- **HTTP Method**：依各 endpoint 不同（POST 為主，部分為 GET）
- **Content-Type**：`application/json`
- **Base URL**：`vendor_infos.info.API_URL`（正式 URL 由三方提供，文件示意 `https://example.invalid/api/v2`）

### 通用回應格式

```json
{
  "success": true,
  "message": "<依 API 不同，可為字串/數值/物件/陣列>",
  "errorCode": 0
}
```

| 欄位 | 類型 | 說明 |
|---|---|---|
| `success` | boolean | 成功 `true`，失敗 `false` |
| `message` | 視 API | 成功時為結果資料；失敗時為錯誤描述 |
| `errorCode` | int | `0` 表示成功；其餘為錯誤代碼（見「錯誤碼」章節） |

### API Rate Limiting

| Endpoint | 上限 | 時窗 |
|---|---|---|
| `POST /auth/createtoken` | 5 次 | 30 秒 |
| `POST /betting/history/by-date-v2` | 2 次 | 10 秒 |
| `POST /game/users/batch-rtp` | 1 次 | 3 秒 |

超過限制回傳 HTTP 429，Response Header 含 `X-RateLimit-Limit`、`X-RateLimit-Remaining`、`X-RateLimit-Reset`。

### 遊戲類型對照

| `type` 值 | 意義 |
|---|---|
| 1 | Live Casino（真人娛樂場） |
| 2 | Slot（電子） |
| 3 | Other/Mini Game（其他遊戲與棋牌） |
| 4 | Fishing（捕魚） |

### 語言代碼（部分）

`en`（英文）、`zh`（中文）、`th`（泰文）、`id`（印尼文）、`vi`（越南文）、`ms`（馬來文）等，完整清單見原始文件 Language Codes 章節。

---

## Integration API（代理主動呼叫 GoldenGateX）

> 所有請求需帶 `Authorization: Bearer {token}`（由 `/auth/createtoken` 取得）。

### 2.1 CreateToken

`POST /auth/createtoken`

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `clientId` | string | ✅ | GoldenGateX 代理 clientId |
| `clientSecret` | string | ✅ | GoldenGateX 代理 clientSecret |

回應：

| 欄位 | 類型 | 說明 |
|---|---|---|
| `token` | string(250) | Bearer token |
| `expiration` | long | Token 到期 Unix timestamp（秒） |

> Rate limit：5 次 / 30 秒。過度請求可能導致帳號被封鎖。

### 2.2 Vendor List

`GET /vendors/list`

回傳所有可用遊戲商清單。每筆包含：`vendorCode`（string）、`type`（int，遊戲類型）、`name`（string）、`url`（string，Vendor API endpoint）。

### 2.3 Game List

`POST /games/list`

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `vendorCode` | string | ✅ | 遊戲商代碼 |
| `language` | string | ✅ | 語言代碼，如 `en` |

每筆遊戲包含：`provider`、`vendorCode`、`gameId`（選填）、`gameCode`、`gameName`、`slug`、`thumbnail`、`updatedAt`、`isNew`（boolean）、`underMaintenance`（boolean）。

### 2.4 Mini Game List

`GET /games/mini/list`

回傳 GoldenGateX 自製 mini game 清單（結構同 2.3）。

### 2.5 Game Detail

`POST /game/detail`

| 參數 | 類型 | 必填 |
|---|---|---|
| `vendorCode` | string | ✅ |
| `gameCode` | string | ✅ |

### 2.6 Get Launch URL

`POST /game/launch-url`

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `vendorCode` | string | ✅ | 遊戲商代碼 |
| `gameCode` | string | ✅ | 遊戲代碼 |
| `userCode` | string | ✅ | 代理端玩家帳號 |
| `language` | string | ✅ | 語言代碼 |
| `lobbyUrl` | string | 可選 | 部分遊戲商（如 Hacksaw）關閉遊戲後回跳的大廳 URL |
| `theme` | int | 可選 | 網站主題樣式編號 |

回應 `message` 為遊戲啟動 URL（string）。

### 2.7 Get Betting History (by date) — **已廢棄**

`POST /betting/history/by-date`（deprecated，請改用 §2.20 V2）

### 2.8 Get Betting History (by Id)

`POST /betting/history/by-id`

以注單 History Id 查詢單筆注單詳情。額外包含 `gameName`。

### 2.9 Get Transaction History (by Id)

`POST /transaction/history/by-id`

以 History Id 查詢交易明細，回傳 Transaction 物件清單。

Transaction 欄位：`id`、`userCode`、`roundId`、`wagerId`、`gameCode`、`vendorCode`、`amount`（正負值表示方向）、`status`（0:success/1:fail）、`isFinished`（boolean）、`isCanceled`（boolean）、`beforeBalance`、`gameType`、`transactionCode`、`detail`、`createdAt`（UTC）。

### 2.10 Get Agent Balance

`GET /agent/balance`

查詢代理帳戶餘額（不需要 body）。

### 2.11 Create User

`POST /user/create`

| 參數 | 類型 | 必填 |
|---|---|---|
| `userCode` | string | ✅ |

建立玩家帳號。文件歸類於 Balance Transfer API，實作中 Seamless 模式也用於預建立帳號（見「實作與文件落差 §2」）。

### 2.12–2.15 Balance Transfer API（轉帳錢包，Seamless 模式不呼叫）

| API | Endpoint | 說明 |
|---|---|---|
| Balance | `POST /user/balance` | 查詢玩家餘額 |
| Deposit | `POST /user/deposit` | 充值到 GoldenGateX 玩家餘額 |
| Withdraw | `POST /user/withdraw` | 從 GoldenGateX 提領玩家餘額 |
| WithdrawAll | `POST /user/withdraw-all` | 提領所有餘額 |

### 2.16 Get User Balance Log

`POST /user/balance-history`

以 `orderNo` 查詢餘額操作記錄。

### 2.17–2.19 RTP 控制 API

| API | Endpoint | 說明 |
|---|---|---|
| Set User RTP | `POST /game/user/set-rtp` | 設定單一玩家 RTP（30–99 之間） |
| Get User RTP | `POST /game/user/get-rtp` | 查詢單一玩家 RTP |
| Reset Users RTP | `POST /game/users/reset-rtp` | 重設所有玩家 RTP |
| Set Users RTP (Bulk) | `POST /game/users/batch-rtp` | 批次設定 RTP（最多 500 人，Rate limit: 1次/3秒） |

> RTP 調整對 Live Casino 桌台和 Aviator 無效。

### 2.20 Get Betting History V2 (by date) — 推薦使用

`POST /betting/history/by-date-v2`（Rate limit：2 次 / 10 秒）

| 參數 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `startDate` | string | ✅ | UTC+0 起始時間，格式 `yyyy-mm-dd` 或 `yyyy-mm-ddThh:mm:ss` |
| `limit` | int | ✅ | 每次最多 2000 筆 |

回應含 `nextStartDate`（string，供下一頁游標使用）。History 欄位含 `status`（0=Unfinished, 1=Finished, 2=Canceled）。可查 7 天內資料。

> V1（§2.7）的 `endDate`、`page`、`perPage`、`vendorCode` 在 V2 已移除；V2 改用游標 `nextStartDate` 分頁。

### 2.21 Get Betting Detail Page URL

`POST /betting/history/detail`

以 History Id 取得注單詳情頁 URL（供後台連結用）。

---

## Seamless Wallet 回調（GoldenGateX → 代理）

Controller：`app/Http/Controllers/External/SingleWallets/Top55Controller.php`
路由：`routes/external/sw.php`，前綴 `/top55`

**Seamless 流程概覽**

```
玩家進入遊戲
      │
      ▼
 GoldenGateX 呼叫代理 POST /top55/api/balance（查詢餘額）
      │
      ▼
 玩家下注
      │
      ▼
 GoldenGateX 呼叫代理 POST /top55/api/transaction（amount 負數，扣款）
      │
      ▼
 遊戲結束
      │
      ▼
 GoldenGateX 呼叫代理 POST /top55/api/transaction（amount 正數，派彩）
 或
 GoldenGateX 呼叫代理 POST /top55/api/batch-transactions（批次，如捕魚）
```

### 回調路由明細

| Endpoint | Controller 方法 | 用途 |
|---|---|---|
| `POST /top55/api/balance` | `balance` | 查詢玩家餘額 |
| `POST /top55/api/transaction` | `transaction` | 單筆交易（下注 / 派彩 / 取消） |
| `POST /top55/api/batch-transactions` | `batchTransactions` | 批次交易（多筆同時） |

### 驗證流程（callAction 前置）

1. 檢查 `userCode` 必填
2. 以 `userCode` 查詢 `Top55swAccount`，確認帳號存在且 `status=1`
3. 驗證 `vendorInfo.status=1`、`member.status=1`
4. 驗證 `Authorization: Basic {base64(clientId:clientSecret)}` 與 `vendor_infos.info` 中 clientId/clientSecret 一致
5. 驗證來源 IP 在白名單中（`Common::verifyIPWhitelistByVendorId`）

任何驗證失敗回傳：`{ "success": false, "message": "<錯誤原因>", "errorCode": 1 }`

### 通用回調回應格式

```json
{
  "success": true,
  "message": "<餘額或其他值>",
  "errorCode": 0
}
```

---

### 1. Balance — `POST /top55/api/balance`

**Request Body（GoldenGateX → 代理）**

| 欄位 | 類型 | 必填 | 說明 |
|---|---|---|---|
| `userCode` | string | ✅ | 代理端玩家帳號（`Top55swAccount.username`） |

```json
{ "userCode": "testuser" }
```

**Success Response（代理 → GoldenGateX）**

`message` 為玩家目前餘額（decimal）。

```json
{ "success": true, "message": 1000.00, "errorCode": 0 }
```

**實作說明**：
- `WalletService::getMemberLockedWallets` 取得玩家錢包
- 餘額乘以 rate：`Common::numMul($balance, $this->vendorInfo->rate, 4)`

---

### 2. Transaction — `POST /top55/api/transaction`

**Request Body（GoldenGateX → 代理）**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `userCode` | string | 代理端玩家帳號 |
| `vendorCode` | string | GoldenGateX 遊戲商代碼 |
| `gameCode` | string | 遊戲代碼 |
| `historyId` | int | 注單 History Id（**冪等性判斷 Key**） |
| `roundId` | string | 回合 ID |
| `gameType` | int | 遊戲類型（1=Casino, 2=Slot, 3=Other, 4=Fishing） |
| `transactionCode` | string | 唯一交易識別碼（存入 `info` JSON，未建唯一索引） |
| `isFinished` | boolean | 回合是否結束 |
| `isCanceled` | boolean | 注單是否取消 |
| `amount` | decimal | 交易金額（**負數=下注扣款，正數=派彩加款**） |
| `detail` | string | 交易描述 |
| `createdAt` | Datetime(UTC) | 交易建立時間 |

**下注範例（amount 為負數）**

```json
{
  "userCode": "testuser",
  "vendorCode": "slot-pragmatic",
  "gameCode": "vs20doghouse",
  "historyId": 2228221,
  "roundId": "178482632282",
  "gameType": 2,
  "transactionCode": "a2c333bb67",
  "isFinished": false,
  "isCanceled": false,
  "amount": -1000,
  "detail": "{}",
  "createdAt": "2024-06-15 12:0:0"
}
```

**派彩範例（amount 為正數）**

```json
{
  "userCode": "testuser",
  "vendorCode": "slot-pragmatic",
  "gameCode": "vs20doghouse",
  "historyId": 2228221,
  "roundId": "178482632282",
  "gameType": 2,
  "transactionCode": "a2c333bb68",
  "isFinished": true,
  "isCanceled": false,
  "amount": 1500,
  "detail": "{}",
  "createdAt": "2024-06-15 12:0:1"
}
```

**Success Response**

`message` 為交易後玩家最新餘額。

```json
{ "success": true, "message": 1000.00, "errorCode": 0 }
```

**核心處理邏輯（execTransaction）**

Amount 為負數（下注）：
1. `BetHelper::isMemberAbleToBet` 確認玩家可下注
2. `WalletService::getMemberLockedWallets` 確認餘額充足
3. 以 `historyId` 查冪等（`Top55swBetRecord::where('bet_id', $historyId)`）
4. 若記錄不存在：建立 BR，`changeBalance` 扣款
5. `amount` 除以 rate：`Common::numDiv($params['amount'], $this->vendorInfo->rate, 4)`
6. `isFinished=true` 直接設 `SETTLEMENT`；否則設 `BET`

Amount 為正數（派彩）：
1. 以 `historyId` 查現有注單
2. 找不到 → Exception：`'Bet transaction not found'`
3. 已 `SETTLEMENT` 或 `CANCEL` → Exception（冪等）
4. 更新 `payout`、`win_lose`、`payout_time`
5. `isCanceled=false` → `SETTLEMENT`，寫 TBR；`isCanceled=true` → `CANCEL`，**不寫 TBR**

---

### 3. Batch Transactions — `POST /top55/api/batch-transactions`

**Request Body（GoldenGateX → 代理）**

| 欄位 | 類型 | 說明 |
|---|---|---|
| `userCode` | string | 代理端玩家帳號（批次層級） |
| `transactions` | List\<TransactionRequest\> | 交易清單，欄位結構同 §2 單筆 Transaction |

```json
{
  "userCode": "testuser",
  "transactions": [
    {
      "userCode": "testuser",
      "vendorCode": "fishing-jdb",
      "gameCode": "7003",
      "historyId": 2228221,
      "roundId": "178482632282",
      "gameType": 4,
      "transactionCode": "a2c333bb67",
      "isFinished": true,
      "isCanceled": false,
      "amount": 1000,
      "detail": "{}",
      "createdAt": "2024-06-15 12:00:00"
    }
  ]
}
```

**Success Response**

```json
{ "success": true, "message": 1000, "errorCode": 0 }
```

**實作說明**：以 for loop 逐筆呼叫 `execTransaction`；`message` 回傳最後一筆成功後的餘額；整批在同一 `DB::transaction`，任一失敗整批 rollback。

---

### BetRecordStatusEnum

| 值 | 狀態 | 說明 |
|---|---|---|
| 1 | `BET` | 已下注，尚未結算 |
| 2 | `SETTLEMENT` | 已結算 |
| 3 | `CANCEL` | 已取消 |

---

### 冪等性

| 場景 | 處理 |
|---|---|
| 下注重複 `historyId`（amount<0） | Exception：`'Duplicate transaction'`，DB transaction rollback |
| 派彩找不到對應 `historyId` | Exception：`'Bet transaction not found'` |
| 注單已 SETTLEMENT | Exception：`'Bet record already settled'` |
| 注單已 CANCEL | Exception：`'Bet record already canceled'` |

> 注意：文件要求重複 `transactionCode` 回 `errorCode: 6`，但實作以 `historyId` 作為冪等 Key，`transactionCode` 未建唯一索引。見「實作與文件落差 §1」。

---

## 簽章

Top55 整合**不使用 HMAC/加密簽章**：

- **主動呼叫（代理 → GoldenGateX）**：Bearer Token（`Authorization: Bearer {token}`），token 由 `/auth/createtoken` 取得。
- **回調驗證（GoldenGateX → 代理）**：HTTP Basic Auth（`Authorization: Basic base64(clientId:clientSecret)`），代理端比對 `vendor_infos.info.clientId` / `clientSecret` 確認身份，搭配 IP 白名單雙重防護。

---

## 錯誤碼

| Name | Code | 說明 |
|---|---|---|
| `NO_ERROR` | 0 | 成功 |
| `USER_ALREADY_EXISTS` | 1 | 欲建立的帳號已存在 |
| `USER_DOES_NOT_EXIST` | 2 | 找不到指定帳號 |
| `INSUFFICIENT_AGENT_BALANCE` | 3 | 代理餘額不足 |
| `INSUFFICIENT_USER_BALANCE` | 4 | 玩家餘額不足 |
| `NO_BETTING_LOG_EXIST` | 5 | 無注單記錄 |
| `DUPLICATE_TRANSACTION` | 6 | transactionCode 重複 |
| `INVALID_TRANSACTION` | 7 | 此注單已結算（不可再操作） |
| `BALANCE_LOG_DOES_NOT_EXIST` | 8 | 餘額日誌不存在 |
| `VENDOR_IS_UNDER_MAINTENANCE` | 9 | 遊戲商維護中 |
| `GAME_IS_UNDER_MAINTENANCE` | 10 | 遊戲維護中 |
| `DEPRECATED_ENDPOINT` | 20 | 此 endpoint 已廢棄 |
| `BAD_REQUEST` | 400 | 請求格式有誤 |
| `UNAUTHORIZED` | 401 | 認證失敗 |
| `UNKNOWN_SERVER_ERROR` | 500 | 伺服器未知錯誤 |

---

## VendorCode 對照表（VENDOR_CODE_MAP）

| VendorIdEnum | vid | vendorCode |
|---|---|---|
| TOP55SW_DG | 182 | `casino-dream` |
| TOP55SW_MG | 183 | `casino-micro` |
| TOP55SW_PL | 184 | `casino-pragmatic` |
| TOP55SW_SA | 185 | `casino-sa` |
| TOP55SW_WG | 186 | `slot-wg` |
| TOP55SW_NOLIMITCITY | 187 | `slot-nolimitcity` |
| TOP55SW_ATG | 188 | `slot-atg` |
| TOP55SW_KA | 189 | `slot-ka` |
| TOP55SW_HACKSAW | 190 | `slot-hacksaw` |
| TOP55SW_POPIPLAY | 191 | `slot-popiplay` |
| TOP55SW_EVOPLAY | 192 | `slot-evoplay` |
| TOP55SW_MASCOT | 193 | `slot-mascot` |
| TOP55SW_POPOK | 194 | `slot-popok` |
| TOP55SW_CQ9 | 195 | `slot-cq9` |
| TOP55SW_TADA | 196 | `slot-tada` |
| TOP55SW_JJBX | 197 | `slot-homeslot` |
| TOP55SW_FACHAI | 198 | `slot-fachai` |
| TOP55SW_JDB | 199 | `slot-jdb` |
| TOP55SW_3OAKS | 200 | `slot-3oaks` |
| TOP55SW_JILI | 201 | `slot-jili` |
| TOP55SW_RUBY | 202 | `slot-rubyplay` |
| TOP55SW_PLAYSON | 203 | `slot-playson` |
| TOP55SW_DREAMTECH | 204 | `slot-dreamtech` |
| TOP55SW_BOOONGO | 205 | `slot-booongo` |
| TOP55SW_PP | 206 | `slot-pragmatic` |
| TOP55SW_PGSOFT | 207 | `slot-pgsoft` |
| TOP55SW_HABANERO | 208 | `slot-habanero` |
| TOP55SW_AVIATOR | 209 | `mini-aviator` |
| TOP55SW_SPRIBE | 210 | `mini-spribe` |
| TOP55SW_INOUT | 211 | `mini-inout` |
| TOP55SW_JDB_FISHING | 212 | `fishing-jdb` |

> 亦可透過 `vendor_infos.info.vendorCode` 動態覆蓋 VENDOR_CODE_MAP 的靜態對應。

---

## 整合落地建議

| GoldenGateX 概念 | APIs 專案對應 |
|---|---|
| `clientId` | `vendor_infos.info.clientId` |
| `clientSecret` | `vendor_infos.info.clientSecret` |
| `API_URL` | `vendor_infos.info.API_URL` |
| `vendorCode`（可選覆蓋） | `vendor_infos.info.vendorCode` |
| 聚合商戶對應 | `VendorIdEnum::TOP55SW_*`（vid 182–212，共 31 個） |
| Driver | `app/Vendors/Top55/Driver.php`（繼承 `AbstractSingleWallet`） |
| Callback Controller | `app/Http/Controllers/External/SingleWallets/Top55Controller.php` |
| 回調路由 | `routes/external/sw.php`，前綴 `/top55` |
| 帳號 Model | `app/Vendors/Models/Top55swAccount.php` |
| 注單 Model | `app/Vendors/Models/Top55swBetRecord.php`（`bet_id` = `historyId`） |
| 對帳保留期 | 後台展示 15 天；Betting History API 可查 7 天 |

---

## 實作與文件落差（待 RD / 客服確認）

1. **冪等性 Key 差異**：文件要求重複 `transactionCode` 回 `errorCode: 6 (DUPLICATE_TRANSACTION)`，但實作以 `historyId` 作為冪等判斷依據，`transactionCode` 存入 `info` JSON 欄位，未單獨建立唯一索引。需確認是否需調整。

2. **Seamless 帳號建立**：Driver `createAccount` 呼叫 `POST /user/create`（文件 §2.11 歸類為 Balance Transfer API），實作中也用於 Seamless 模式帳號預建立。待確認三方是否允許。

3. **`getGameList` vendorCode 解析路徑**：先呼叫 `GET /vendors/list` 取得 vendorCode metadata，再以此 vendorCode 打 `POST /games/list`（兩次 API 呼叫）。VENDOR_CODE_MAP → `vendor_infos.info.vendorCode` 覆蓋的解析路徑需確認上線時各子商戶 vendorCode 已正確對應。

4. **batchTransactions 批次失敗行為**：for loop 任一筆拋例外整批 rollback。三方預期整批原子性還是允許部分成功，需確認。
