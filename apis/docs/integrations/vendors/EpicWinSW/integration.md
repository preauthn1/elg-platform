# EpicWinSW（vendor_id=85）
> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| `VendorIdEnum` | `EPICSW = 85` |
| 錢包模式 | 單一錢包（Seamless Wallet） |
| 回調 Controller | `app/Http/Controllers/External/SingleWallets/EpicWinController.php` |
| Driver | `app/Vendors/EpicWinSW/Driver.php`（`AbstractSingleWallet`，僅實作 `getGameUrl`） |
| 路由 | `routes/external/sw.php`（路徑名須與 FunctionName 一字不差） |
| Models | `EpicSwAccount`、`EpicSwBetRecord`、`EpicSwRecord`、`EpicSwEventRecord` |
| `OperatorId` | `vendor_infos.info['OPERATOR_ID']` |
| `SecretKey` | `vendor_infos.info['SECRET_KEY']` |
| `API_URL` | `vendor_infos.info['API_URL']` |
| `Currency` | `vendor_infos.info['CURRENCY']` |
| 匯率 | `vendor_infos.rate`（入站 `numDiv(amount, rate, 4)`，出站 `numMul(balance, rate, 4)`） |
| `AuthToken` | `EpicSwAccount.session_token`（`GameLogin` 時寫入） |
| 簽名演算法 | MD5 hex 小寫，拼接順序逐 API 不同（見下方陷阱） |
| `OperatorStatusEnum` | 借用 `App\Vendors\WFSW\OperatorStatusEnum`（數值與 EpicWin 官方一致） |
| IP 白名單 | UAT `0.0.0.0`；PROD `0.0.0.0` / `0.0.0.0` / `0.0.0.0` |
| 文件版本 | V3.1.1（2023-10-20） |
| 對帳 API | Integration API `GetDaySummary` / `GetTransactionDetails`（APIs 目前未實作主動拉取） |

---

## 高風險陷阱

### 1. GameResult 簽名用 `ResultId`，不是 `BetId`

`Bet` / `Rollback` 簽名在 `FunctionName` 後插 **`BetId`**，`GameResult` 則插 **`ResultId`**。兩者都是 Long，欄位名不同語意不同——誤用 `BetId` 參與 `GameResult` 簽名必定驗簽失敗。

```
Bet       → MD5(FunctionName + BetId    + RequestDateTime + OperatorId + SecretKey + PlayerId)
GameResult→ MD5(FunctionName + ResultId + RequestDateTime + OperatorId + SecretKey + PlayerId)
Rollback  → MD5(FunctionName + BetId    + RequestDateTime + OperatorId + SecretKey + PlayerId)
```

實作 `checkSign()` 以 `switch($functionName)` 路由，`GameResult` 取 `ResultId`——已正確對齊。

### 2. `CashBonus` / `Jackpot` 的 `TranId` 是 String(25)

V3.1.1 前為整數型別，V3.1.1（2023-10-20）改為 String(25)。若還以整數型別比對冪等鍵，高位字串 ID 會靜默 cast 失敗。實作以 `bet_id = TranId`（字串）存入，正確。

### 3. `FunctionName` = URL basename，路由路徑必須與簽名字串完全一致

`checkSign()` 以 `basename(url()->current())` 當 FunctionName。若 `routes/external/sw.php` 的路徑改成 `game-result`（或任何非 `GameResult` 的字串），簽名就會錯。**路由路徑名稱禁止隨意修改。**

### 4. 交易時間用 `TranDateTime`，絕非 `RequestDateTime`

官方紅字警告：`RequestDateTime` / `ResponseDateTime` 僅供簽名與校驗，**禁止用於交易紀錄的時間戳**。`settle()`、`rollback()`、`bonus()`、`jackpot()` 的 `payout_time` 皆需取 `TranDateTime`。

### 5. `AuthToken` optional，未帶時 `checkAuthToken()` 放行

`checkAuthToken()` 在請求未帶 `AuthToken` 時直接回 `true`。官方將 `AuthToken` 標為 Optional，此為已知寬鬆設計，非 bug。但意味著 EpicWin 若未帶 token，session 綁定不被強制驗證。

### 6. Rollback 每小時重送，冪等必須嚴格

官方 §5.2：Rollback 連送 3 次拿不到正確回應後，進背景佇列，之後**每小時**重送一次，直到代理回 `rollback success` 或 `transaction not found`。若冪等實作有漏洞，會重複退款。

### 7. Rollback 實作是「歸零」，不是「還原原始下注金額」

`rollback()` 將 `bet` / `valid_bet` / `win_lose` 全部設為 0、`status = 0`，並 `changeBalance` 退回 `BetAmount`（從請求取）。對 `RollbackType=1`（整單退）語意正確。實作未讀取 `RollbackType`——官方目前只定義 `1`，若未來新增部分退款類型需擴充。

### 8. `RoundId` 僅存 `info` JSON，不在獨立欄位

V3.1.1 在 Bet / GameResult / Rollback 新增 `RoundId`（同回合多玩家可共用）。實作以 `BetId` 為注單唯一鍵，`RoundId` 整包塞進 `info`。對單注冪等無影響，但若日後需以 round 層級做聚合對帳，需注意 `RoundId` 只存在 `info` 內。

---

## BetRecord 狀態機

| 回調 | BetRecord 動作 | `status` | `type` | 冪等鍵 |
|---|---|---|---|---|
| Bet | 建立新注單 | `0`（pending） | `'N'` | `bet_id = BetId` |
| GameResult | 以 `BetId` 找原注單，覆寫 `result_id`/`win_lose`/`valid_bet`/`payout_time` | `1`（settled） | `'N'` | `result_id = ResultId` |
| Rollback | 以 `BetId` 找原注單，歸零 `bet`/`valid_bet`/`win_lose` | `0`（zeroed） | `'N'` | `bet_id = BetId` |
| CashBonus | 建立新注單，`bet=0`，`win_lose = payout` | `1` | `'B'` | `bet_id = TranId` |
| Jackpot | 建立新注單，`bet=0`，`win_lose = payout` | `1` | `'J'` | `bet_id = TranId` |

> GetBalance 不改動 BetRecord，僅查詢並回傳錢包餘額。

---

## 冪等性

| 事件 | 冪等鍵 | 撞重時回傳 |
|---|---|---|
| Bet | `EpicSwBetRecord.bet_id`（= `BetId`） | `DUPLICATE_TRANSACTION (900409)` |
| GameResult | 以 `BetId` 找原注單，`result_id` 已存在時視為重送 | 重算並回 `OldBalance`/`NewBalance`（已冪等） |
| Rollback | 以 `BetId` 找原注單，已歸零時回 `transaction not found` 以終止重送迴圈 | `BET_TRANSACTION_NOT_FOUND (900415)` |
| CashBonus | `EpicSwBetRecord.bet_id`（= `TranId`） | `DUPLICATE_TRANSACTION (900409)` |
| Jackpot | `EpicSwBetRecord.bet_id`（= `TranId`） | `DUPLICATE_TRANSACTION (900409)` |

Rollback 重送機制（每小時）：代理回 `900415 BET_TRANSACTION_NOT_FOUND` 即可終止 EpicWin 的重送佇列。

---

## 整合落地建議

| EpicWin 概念 | APIs 對應 |
|---|---|
| Vendor 註冊 | `VendorIdEnum::EPICSW = 85`；`config/vendor.php` key `85`；`database/data/vendors.php`（`code=epic_sw`、`is_single_wallet=1`、`open_window=1`） |
| Driver | `app/Vendors/EpicWinSW/Driver.php`（`AbstractSingleWallet`，`getGameUrl` → 打 `GameLogin`） |
| Callback Controller | `app/Http/Controllers/External/SingleWallets/EpicWinController.php` |
| 前置驗證 | IP 白名單 → 取會員 → 取商戶（`OPERATOR_ID`） → 驗簽 → 驗 `AuthToken` |
| 金額換算 | 入站 `Common::numDiv(amount, rate, 4)`；出站 `Common::numMul(balance, rate, 4)` |
| 對帳 | Integration API `GetDaySummary`（每日匯總）、`GetTransactionDetails`（單筆，傳 `ResultId`）——目前未實作 |

### 路由對照

| EpicWin Request Path | APIs 路由 | Controller 方法 |
|---|---|---|
| `POST /GetBalance` | `GetBalance` | `getBalance` |
| `POST /Bet` | `Bet` | `bet` |
| `POST /GameResult` | `GameResult` | `settle` |
| `POST /Rollback` | `Rollback` | `rollback` |
| `POST /CashBonus` | `CashBonus` | `bonus` |
| `POST /Jackpot` | `Jackpot` | `jackpot` |

---

## Gaps

- **對帳資料保留天數**：文件未載明 EpicWin 端注單 / DaySummary 保留多久，對帳排程上限需向客服確認。
- **正式 Base URL / 金鑰**：各白標正式值由客服交付，不在文件內。
- **`KickPlayer` / `GetUnfinishRound` / `CancelRound`**：屬代理主動呼叫的 Integration API，目前 Driver 未實作。
- **部分退款 / 多 `RollbackType`**：官方目前只定義 `RollbackType=1`，若未來擴充，`rollback()` 需相應處理。
