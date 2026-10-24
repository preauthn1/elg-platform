# UUSlotSW（vendor_id=82）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 單一錢包（Seamless）；玩家餘額由本平台保管，UU Slots 回調扣加款 |
| 回調方向 | **UU Slots → 本平台**（本平台為 server，被呼叫方） |
| 驗證 | IP 白名單 + MD5 簽章（`FunctionName + [UniqueId] + RequestDateTime + OperatorId + SecretKey + PlayerId`） |
| 成功判定 | `Status === 200`，HTTP 一律 200 |
| 時區 | UTC（`RequestDateTime` / `TranDateTime` 皆為 UTC） |
| 回調入口 | `POST /api/sw/uuslot/<Method>`（各 Method 獨立端點） |
| 資料保留 | 即時交易 30 天；Log Pulling 60 天 |

## 高風險陷阱

### 1. BetNSettle / CancelBetNSettle 未實作

官方文件 v3.5.1（2025-07-15）新增 `POST /BetNSettle` 與 `POST /CancelBetNSettle`，本專案目前**無對應路由與 Controller method**。若 UU Slots 站點開啟此功能，所有即投即結下注都會 404。

**上線前必須向 UU Slots 確認 BetNSettle 開關是否啟用；若啟用須補實作。**

### 2. RequestDateTime 格式不一致（官方文件自身矛盾）

官方文件部分 API 用 `yyyy-MM-ddTHH:mm:ssZ`，部分用 `yyyy-MM-dd HH:mm:ss`。Driver 使用後者（`date('Y-m-d H:i:s')`）。兩種格式均通過，但同一請求中格式須一致。

### 3. GetBalance 簽名無 UniqueId

GetBalance 的簽名串接為 `md5(GetBalance + RequestDateTime + OperatorId + SecretKey + PlayerId)`，**省略 UniqueId**。實作中 `isSignatureValid()` 對 GetBalance 以 `uniqueId = ''`（空字串）處理，數學結果相同。

### 4. CashBonus 請求範例 BonusType=1 不在 enum 定義中

官方提供的 CashBonus 請求範例 `"BonusType": 1`，但 [附錄 7.9](api.md#79-bonus-type) 定義 BonusType 值為 `0/2/3/4`，**無 `1`**。屬文件自身矛盾，上線前須向 UU Slots 確認。

## BetRecord 狀態機

`BET(pending)` →（GameResult 結算）`SETTLED` 或（Rollback 取消）`ROLLED_BACK`，後兩者皆終態。

- **Bet**：建立 BetRecord `status=BET`；扣玩家餘額（`BetAmount`）。
- **GameResult**（冪等主鍵：`ResultId`）：`valid_bet = ValidBetAmount`、`payout = Payout`、`win_lose = WinLose`；加入 `Payout` 金額；status→`SETTLED`；寫 TBR。
- **Rollback**（冪等主鍵：`BetId`）：退還 `BetAmount`；status→`ROLLED_BACK`；寫 TBR。
- **CashBonus / Jackpot**（冪等主鍵：`TranId`）：加入 `Payout` 金額；獨立寫入，不關聯 BetRecord 狀態機。
- **TBR 寫入時機**：`GameResult` / `Rollback` 完成後才寫；**`Bet` 不寫 TBR**（仍為未結算中間態）。

### 會員被鎖定時的行為（target/tickets#4375，2026-09）

下注資格（會員狀態／商戶／遊戲／維護／鎖分等十項）只在 `Bet` 以 `verifyBettability()` 檢查，且排在 `getMemberLockedWallets()` 之後；不符回 `900416 Account could not bet yet`。**`GameResult` / `Rollback` / `CashBonus` / `Jackpot` 刻意不檢查會員狀態**——被鎖的玩家不能再下注，但先前已扣款的注仍要結算、該退的本金仍要退，否則錢會卡在半路（本家改版前就只擋 `Bet` / `GetBalance`，此處只是把決策釘住，與其他已重構的單錢包遊戲商一致）。

## 冪等性

| Callback | 冪等主鍵 | 重複請求處理 |
|---|---|---|
| `Bet` | `BetId` | 回 `900409 Duplicate Transaction` |
| `GameResult` | `ResultId` | 已結算不可再改；重複回 `900409` |
| `Rollback` | `BetId` | 若 Bet 不存在（已結算或不存在）回 `900415 Bet Transaction Not Found` |
| `CashBonus` | `TranId` | 回 `900409 Duplicate Transaction` |
| `Jackpot` | `TranId` | 回 `900409 Duplicate Transaction` |

## 重試機制

| 場景 | Provider 行為 |
|---|---|
| Bet 失敗 | Provider 自動觸發 Rollback，**不重試 Bet** |
| Rollback / GameResult / CashBonus / Jackpot 失敗 | 最多 3 次，之後進入背景佇列每小時重試 |

## 整合落地建議

| UU Slots 概念 | APIs 專案對應 |
|---|---|
| `OperatorId` / `Merchant_Code` | `vendor_infos.info.Merchant_Code` |
| `SecretKey` | `vendor_infos.info.Security_key` |
| `API_URL`（Provider 端主動呼叫） | `vendor_infos.info.API_URL` |
| `Currency` | `vendor_infos.info.CURRENCY`（預設 `MYR`） |
| `PlayerId` | `uuslot_sw_accounts.username` |
| Driver | `app/Vendors/UUSlotSW/Driver.php`（繼承 `AbstractSingleWallet`） |
| Callback Controller | `app/Http/Controllers/External/SingleWallets/UUSlotSWController.php` |
| 回調路由前綴 | `routes/external/sw.php` → `Route::prefix('uuslot')` |
| 狀態碼 Enum | `App\Vendors\UUSlotSW\Enums\StatusCodeEnum` |
| TBR 寫入時機 | `GameResult` callback 完成後（`TotalBetRecordService::create()`） |
| VendorId | `VendorIdEnum::UUSLOTSW`（值 = `82`） |
| IP 白名單 | `Common::verifyIPWhitelistByVendorId(82)`；PROD IP `0.0.0.0`、`0.0.0.0`、`0.0.0.0` |
| 對帳保留期 | 即時交易 30 天；Log Pulling 60 天 |

## 模型對應

| 欄位 | 來源 Callback | BetRecord 欄位 |
|---|---|---|
| `BetId` | Bet | `bet_id`（冪等主鍵） |
| `ResultId` | GameResult | `result_id`（冪等主鍵） |
| `RoundId` | Bet / GameResult / Rollback | `round_id` |
| `BetAmount` | Bet | `bet`（扣款金額） |
| `ValidBetAmount` | GameResult | `valid_bet` |
| `Payout` | GameResult | `payout` |
| `WinLose` | GameResult | `win_lose`（Payout - BetAmount） |
| `TranDateTime` | Bet / GameResult / Rollback | `bet_at` / `result_at`（UTC） |
| `GameCode` | Bet / GameResult | `game_code` |
| `GameType` | Bet / GameResult | `game_type` |
| `GameName` | GameResult | `game_name` |

## Gaps / 待 UU Slots 確認

- **BetNSettle 開關**：是否已在代理站點啟用（目前未實作，會 404）
- **CancelBetNSettle 開關**：同上
- **BonusType=1**：CashBonus 範例出現 `BonusType: 1`，但 enum 無此值
- **RequestDateTime 格式**：官方文件 ISO 8601 與空格格式並存，是否兩者皆接受
- **Error Response 額外餘額欄位**：實作中 `errorResponse()` 回傳 `Balance/OldBalance/NewBalance=0`，官方文件未規定 error response 結構，是否符合預期
