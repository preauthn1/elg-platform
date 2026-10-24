# PTSW（Playtech GamesLink，vendor_id=144）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 單一錢包（GamesLink Seamless）；玩家餘額由本平台保管，Playtech 回調扣加款 |
| 雙向 | 出站 `getGameLaunchUrl`（取遊戲 URL）；入站 Operator API（authenticate/bet/gameRoundResult…）Playtech → 本平台 |
| 認證 | 出站 header `x-auth-kiosk-key`；入站 TLS 1.2+ **客戶端憑證驗證** |
| 回應 | Operator API **一律 HTTP 200**，錯誤放 body `error.code`；**回應須 < 250ms** |
| 金額 | `decimal(22,2)`，minor units（`100 = 1.00`）；進場 `Common::numAdd('0',(string)$value,4)` 正規化 |
| 時間 | GMT，DB 層儲 UTC |

## 陷阱（高風險）

### 1. 250ms 回應上限 → 超時觸發退款型 gameRoundResult

每個 Operator API 回應**必須 < 250ms**。超時的 `bet` 可能被 Playtech 視為超時，進而送**退款型 `gameRoundResult`**沖銷原投注。回調 handler 內勿做重 I/O。

### 2. gameRoundResult 必達 + 冪等重放

`gameRoundResult` 必須**最終成功**（24–48h 內 Playtech 自動補發直到成功）。同一 `transactionCode` / `gameRoundCode` 重複請求**必須回第一次的成功結果**（**即使令牌已過期也要成功**）。實作以同局 named lock（`GameRoundLock`，key `gameRoundCode + username`）序列化併發，再由 `createDealRecord` 以 `requestId + gameRoundCode`／`gameRoundCode + transactionCode` 查 deal record 冪等早退（回當下餘額，不是重放首次回應）；細節見下方「冪等性」。

### 3. refund 的 deal_record 結構特殊

refund 型 `gameRoundResult` 的沖銷事件 `applied_at = NULL`；SBR 重建 / 對帳重算 BR.bet 時必須納入此沖銷事件（見 [[reference_ptsw_refund_deal_records]]）。

## BetRecord 狀態機 / gameRoundResult 四型態

`bet`（扣款，建 BR）→ `gameRoundResult` 四選一：

| 型態 | `pay` | 處理 |
|---|---|---|
| (a) 玩家輸 | `null` | 純結算關閉回合，無派彩。**現行行為（待 PM 拍板，`target/op_tickets#3096`）**：只要帶 `gameRoundClose`，`resolveSettlementOutcome()` 一律把注單改 SETTLE——已被 (c) 退款沖成 bet 0 的局再收純關局（或 REFUND 與關局同一請求）也會變 `status=3`、bet 0／win 0，並建一筆 0 值 TBR；只有 PT 從未再送關局的退款才停在 REFUND。TPower 2026-09-04～09-07 有 146 局走此路徑、停在 REFUND 的僅 15 局。與 `refund-unsettled` 命令「退款改 REFUND、不建 TBR」相反，改法待 PM 決定後另開 MR |
| (b) 玩家贏 | `type=WIN` | 加款、更新 BR win/payout_time、寫 TBR |
| (c) 退款 | `type=REFUND` + `relatedTransactionCode` | 沖銷原投注（applied_at=NULL，見陷阱 3）；缺 `relatedTransactionCode`、原下注未 applied、或同一筆下注已退過（含 `refund-unsettled` 命令整局退款）一律回 `ERR_NO_BET`（`target/op_tickets#3079`／`#3089`）。被拒的請求仍留一筆 deal record（applied_at=NULL，raw 帶 `_rejected` 記 code／reason；重複退款閘與 `playtech:reconcile-bet-accumulation` 都不把它當退款）供稽核與冪等，故同 `requestId`／同 `transactionCode` 重送走冪等早退回 200——與首次的 `ERR_NO_BET` 不一致是刻意的（2026-09-08 拍板）：api.md §9 `ERR_NO_BET` 不可重試、§10 同 `transactionCode` 重複請求須回首次結果，PT 依協定不會踩到這個混合態 |
| (d) Jackpot 中獎 | `type=WIN` + `jackpot.jackpotWinnings[]` | 加款含 jackpot winAmount |

## 冪等性

- `bet` / `gameRoundResult`：先取同局 MySQL named lock（`App\Vendors\PlayTechSW\GameRoundLock`，key `gameRoundCode + username`、5 秒逾時，**逾時錯誤碼依 action 分流**見下，與 `bet-record-recovery:refund-unsettled` 命令共用同一把），鎖在 `DB::transaction` 之外取、commit 後釋放；交易內 `createDealRecord` 先查 `requestId + gameRoundCode`、再查 `gameRoundCode + transactionCode`，命中即早退回**當下餘額**（200、帶既有 `externalTransactionCode`），不進業務段；未命中才寫入 deal record 並處理，併發撞唯一鍵（1062）時 re-select 既有列。`transferFunds` 無 `gameRoundCode`，不取鎖、以 `requestId + transactionCode` 冪等。沒有 `lockForUpdate()` 鎖交易紀錄，也不是重放首次回應——首次被拒（`ERR_NO_BET`）的 `transactionCode` 重送會回 200（取捨見上方退款列）。
- 可重試錯誤：`INTERNAL_ERROR`（bet + gameRoundResult）、`CONSTRAINT_VIOLATION` / `INVALID_REQUEST_PAYLOAD`（僅 gameRoundResult）；退避 `1,2,4,8,16,32…` 分鐘。

### named lock 逾時的錯誤碼依 action 分流

5 秒內取不到同局鎖時，回哪個錯誤碼取決於「PT 收到之後還會不會再送這一筆」（`lockTimeoutErrorCode()`）：

| action | 錯誤碼 | 可重試 | 理由 |
|---|---|---|---|
| `gameRoundResult` | `INTERNAL_ERROR` | ✓ | 結算必須最終成功。回不可重試碼等於整筆結算永久遺失——注單停在 `status=1`、TBR 不建，而下注款已扣（`target/op_tickets#3101`，形狀同 `#3083`） |
| `bet` | `ERR_TRANSACTION_DECLINED` | ✗ | 撞鎖代表同局另一筆請求正在處理，這筆本來就不該成立；讓 PT 視為投注失敗才對，重試反而可能重複下注 |

`INTERNAL_ERROR` 是可重試清單中唯一 `bet` 與 `gameRoundResult` 皆適用的碼。重送由既有的 deal record 冪等接住，不會重複入帳。逾時會記一筆 warning（`PTSW 同局鎖逾時`，帶 `action` 與 `error_code`）——在此之前撞鎖只留下 `PlayTech Error` 的 error log，看不出是投注還是結算。

## 整合落地建議

| Playtech 概念 | APIs 專案對應 |
|---|---|
| Vendor 註冊 | `config/vendor.php` → `VendorIdEnum::PTSW->value (144) => Vendors\PlayTechSW\Driver::class` |
| Driver（出站 / 進遊戲） | `app/Vendors/PlayTechSW/Driver.php`（`AbstractSingleWallet`，`getGameUrl` → `POST {API_URL}/from-operator/getGameLaunchUrl`） |
| 回調 Controller | `app/Http/Controllers/External/SingleWallets/PlayTechController.php` |
| Route | `routes/external/sw.php` |
| Account / BetRecord / DealRecord Model | `App\Vendors\Models\PtswAccount` / `PtswBetRecord` / `PtswDealRecord` |
| 狀態 Enum | `app/Vendors/PlayTechSW/BetRecordStatusEnum.php` |
| `vendor_infos.info` | `API_URL` / `SERVER_NAME` / `KIOSK_KEY`（出站 `x-auth-kiosk-key`） |
| 帳號規則 | `username = {prefix}_{8 位數}`（大寫，含 kiosk_prefix）；`token = [REDACTED] 作 externalToken |
| 對帳 / SBR 重建 | `App\Services\SummaryBetRecords\` 對應類別；refund deal_records 結構見 reconcile 工具 vendor 註記 |

## gaps / 待 Playtech / RD 確認（非確認 bug）

1. **正式 Base URL / kiosk 憑證**：由 Playtech 客服開戶提供。
2. **自動結算窗口**：未關閉回合 24–48h 由 Playtech 自動補發 `gameRoundResult`，對帳排程須涵蓋此延遲窗口。
3. **真人遊戲 CustomData / LiveTableDetails** 欄位：若接真人桌需確認額外 context 欄位（見 api.md §7）。
4. **已退款局再收關局的注單語意**（`target/op_tickets#3096`，待 PM）：回調路徑改 SETTLE＋0 值 TBR，`refund-unsettled` 命令維持 REFUND、不建 TBR，兩者相反；PM 選定後才動 `resolveSettlementOutcome()` 與 TBR 建立條件（見上方四型態表 (a) 列）。
