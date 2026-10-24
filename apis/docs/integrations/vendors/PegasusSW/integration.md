# PegasusSW（target，vendor_id=50）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。
>
> ⚠️ **聚合 Driver**：`PegasusSW\Driver` + `PegasusController` 同時服務 PGSSW(50) / TIGER9(91) / BWG(145) / JOKER_BACCARAT(177)，runtime 從 `vendor_infos` 覆寫 `vendor_id`。改行為會牽動多品牌。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 單一錢包（Seamless）；玩家餘額由本平台保管，target 回調扣加款 |
| 回調驗證 | `X-target-IDENTITY` token（明文比對）＋ `merchantSecretKey` ＋ IP 白名單；**無簽章演算法** |
| 成功判定 | HTTP 恆 200，`code === 0`；失敗回指定錯誤碼 |
| 匯率 | 進 `numDiv(amount, rate, 4)`、回 `numMul(balance, rate, 2)`（回應精度見下方陷阱 5） |
| 時區 | UTC（+0），ISO8601 |
| 冪等鍵 | `transactionNo`（重複 → `500029`） |

## 陷阱（高風險）

### 1. 回調標頭名稱（v1.1.10 時的最高風險，v1.3.9 起已對齊）

`PegasusController::callAction()` 讀的是 **`X-PROVIDER-IDENTITY`**。舊版 v1.1.10 文件指定的是 `X-target-IDENTITY`，曾與實作不一致（target 若送舊標頭 → `$identity` 為空、`getVendorInfo()` 比對失敗回 `Merchant not found`）。**v1.4.8 已解：v1.3.9（2025-02-13）官方將標頭正名為 `X-PROVIDER-IDENTITY`，與實作一致**；舊版 `X-target-IDENTITY` target 仍保留。對接新商戶仍建議實測 target 實際送出的標頭名，以防殘留舊設定。

### 2. `cancel` 的邊界行為（2026-06 已改）與 cancel 先於 bet 的競態

舊版直接讀 `$oldBetRecord->status`，查無注單會 NPE 回 `500001`；現行（`7a7378a72` 2026-06-15、`348c60a79` 2026-06-29）如下：

- 查無注單（以 `transactionNo` 對 `bet_id`、`playerUniqueID` 對 `username`，兩者都要對上）→ 直接回 `code 0`、不寫 BR，`pgssw_cancel_logs` 留 `status 0`。
- 同一 `transactionNo` 重送 cancel → 以 `pgssw_cancel_logs.status != 0` 判冪等，直接回 `code 0`（不再回 `403601`）。
- 命中注單 → clone BR、bet／win／win_lose 歸 0、status 改 ROLLBACK(2)、退款（禮碼走 `rolling_chip_logs`）、TBR 沖銷、cancel log 改 `status 1`。

**cancel 先於 bet 落地的競態（2026-09-08 已修）。** placeBet 卡在會員鎖時，target 逾時（約 15 秒）先送 cancel；`cancel()` 進交易後第一句一般讀（`$member->agent`）已建立 REPEATABLE READ 快照，等到鎖後再用一般讀查注單仍看不到剛 commit 的 bet，走「查無回 0」分支；bet 隨後照常扣款，target 卻認定已取消、不再重送。2026-09-05 TPower DDoS 事故實測 70 筆（禮碼 27.97），請 target 重送 cancel 後才自癒（`target/op_tickets#3075`）。

現行修法分兩層，缺一不可：

1. **注單層**：`cancel()`／`settle()`／`settleChange()` 查注單一律 `lockForUpdate()`（鎖定讀讀最新 commit，不受快照限制）；`settleChange()` 同時改為先鎖會員錢包再鎖定讀注單，鎖序與 `settle()` 一致（代價：查無注單時也會先鎖會員列、禮碼開啟時會順手建 0 元禮碼錢包）。
2. **TBR 層**：`TotalBetRecordService::create()` 對已存在的注單改用鎖定讀判斷 `total_bet_records` 是否存在。只修注單層時，`updateOrCreate` 內部的一般讀仍看不到 placeBet 寫的那列 TBR，會改走 INSERT 撞 `(vendor_id, bet_record_id)` 唯一鍵，整筆交易回滾、回 `500001`，錢一樣沒退。新建注單維持原路徑（對不存在的唯一鍵 `FOR UPDATE` 會拿 gap lock）。此改動是共用服務，所有遊戲商的結算／取消一起受益。

`placeBet`／`bonus`／`eventBonus`／`settleWithoutPayout` 的重複檢查**刻意不鎖**：注單此時通常不存在，對不存在的唯一鍵做 `FOR UPDATE` 會拿 gap lock，兩筆並發落在同一 gap 就互相 deadlock，防重靠 `bet_id` 的 unique 索引（撞鍵時例外冒到 `callAction` 回 `500001`，錢包尚未動、整筆回滾，cancel log 維持 `status 0` 可重送）。回歸測試見 `tests/Feature/External/SingleWallets/PegasusSW/StaleSnapshotReadTest.php`（用第二條 DB 連線在測試交易快照之後 commit 注單與 TBR）。

現金紀錄（`transaction_logs`）也踩同一個競態，但只收斂了一部分，**尚未收乾淨**。`TransactionLogCreateService::verifyAndCloseLastTransactionLog()` 先以一般讀取得「該會員最後一筆交易紀錄」再決定怎麼走，舊快照下讀到的可能是過期的內容或根本讀不到。依「快照裡的最後一筆」分四種情況：

| 快照裡的最後一筆 | 2026-09-08 之前 | 現況 |
|---|---|---|
| 一筆都沒有（會員首次交易） | 漏關，留下兩筆 999 | ✅ 已關 |
| 型別不是投注結算（儲值、開分等） | 本來就會關閉 | ✅ 不變 |
| 投注結算、進行中、**別的遊戲商** | 漏關，留下兩筆 999 | ✅ 已關 |
| 投注結算、進行中、**同一個遊戲商** | 以舊快照的金額重算後覆寫 | ✅ 已修 |

前三種的修法是同一件事：關閉走 UPDATE、屬當前讀，本來就看得到最新 commit，不該被「查不查得到上一筆」或「有沒有發生合併」擋住，因此改為沒有合併時一律執行關閉。

**第四種是金額錯誤，不是明細多一列（2026-09-08 已修）。** 單一錢包對同一個遊戲商只維持一筆累加的進行中紀錄，所以競態下 placeBet 是併進既有那筆；cancel 讀到的是它的舊版本，`updateOrLockTransactionLog()` 以舊 `point` 加上本次金額後寫回，placeBet 剛寫入的金額就被覆蓋掉（典型的讀改寫遺失更新）。`balance` 取自鎖定讀所以是對的，於是 `point` 與餘額欄位對不起來，SR14 現金明細的金額直接錯。

修法：`updateOrLockTransactionLog()` 進來就先以**主鍵**鎖定讀重取同一列、把現況寫回手上的物件，之後的已結算判斷與金額累加都以資料庫現值為準。以主鍵定位只鎖該列、不會鎖到相鄰區間，沒有並發插入互相卡住的風險；單一錢包路徑進來時會員列也早已鎖住，等待範圍有限。

⚠️ 這個競態在現行測試框架下無法端到端重現：測試本身包在一個交易裡，快照在 `setUp()` 就固定了，第二條連線之後對同一列的異動，測試交易看不到也改不動。因此回歸測試改為直接注入「內容已過期的物件」給合併方法（`tests/Unit/Services/TransactionLogCreateServiceTest.php`），驗證它以資料庫現值累加而非手上那份，缺陷本體與守護點相同。

根治方向仍是讓交易在拿到會員鎖之前不做任何一般讀（`WalletService::getMemberLockedWallets()` 先鎖 `members`，再讀 `agent` 與 `is_event_wallet` 這類會查表的 accessor），該方法有 224 個呼叫點，屬共用錢包路徑的架構改動，另案。placeBet 前查同 `transactionNo` 的待處理 cancel log（cancel 比 bet 更早拿到鎖的情況）亦另案評估，尚未實作。

對帳側的判讀與補帳見 `docs/reconcile/vendors/PGSSW.md`「已知 incident」。修正前的歷史資料排查判準＝`pgssw_cancel_logs.status = 0` 且 `transaction_no` 對得到 `status 0` 的注單。

### 3. `getGameUrl` 成功判斷型別嚴格比較

實作 `($response['code'] ?? '') !== 0`：target 若回字串 `"0"`，`"0" !== 0` 為 true 會誤判失敗。文件範例 `code` 為數字，待確認 target 不回字串型 code。

### 4. deposit / withdrawal 未實作

5.4.1 / 5.4.2 錢包充值 / 提款（捕魚 430000 系列）的路由與方法在 `PegasusController` **不存在**。接入捕魚預扣遊戲前須補實作。

### 5. 回應餘額精度：我方截兩位，不可改為四捨五入

target 規格「金額最低至小數兩位、不支援小數三位以下」（`api.md` 通用約定），且格式不符時 target 會直接拋錯終止玩家流程。`PegasusController::BALANCE_SCALE = 2` 約束 `response()` 的 `balance` 與 `bonusResponse()` 的 `beforeAmount` / `afterAmount`。

三點必須守住：

- **方向只能捨去**。`Common::numMul` 底層 `bcmul` 為無條件捨去。若改成四捨五入，回報餘額會大於實際餘額，玩家以該金額投注時 `placeBet` 撞 `403203`，target 隨即呼叫 5.2.9 取消投注，體驗比少 0.01 更差。
- **`rate < 1` 會放大誤差**。回應值為 `balance × rate`，rate 越大相對誤差越小（rate=1000 等同截到我方第 5 位）；但若某白標設出 `rate < 1`，小額餘額可能被截成 0。目前四家啟用中的 `vendor_infos.rate` 均為 1，新增商戶時須檢查此欄。
- **小於 0.01 的碎屑餘額在遊戲內顯示為 0**。DB 仍保留 4 位真實值，平台其他頁面照常顯示，帳務不受影響；客服端需知悉此落差。

歷史：2026-08 前回應為 4 位（超出 target 規格），由 target 端自行截斷，曾造成「後台顯示 1.85、遊戲內顯示 1.84」的客訴（op_tickets#2668）。改為我方截斷後玩家看到的數字不變，但截斷行為變成可控、可測。

## BetRecord 狀態機（`BetRecordStatusEnum`）

```
placeBet → BET(0)      建 BR，扣款，payout/settlement_time = 1970-01-01
settle   → SETTLE(1)   bet/valid_bet 取 validAmount、win=payoutAmount、寫 payout_time、TBR
cancel   → ROLLBACK(2) clone BR、bet/win/win_lose 歸 0、退款、TBR 沖銷；先寫 PgsswCancelLog（查無 BR → 回 0 不動任何表，見陷阱 2）
```

- `settleWithoutPayout`：投注+結算合一，直接建 SETTLE(1)，`win=payoutAmount??0`。
- `settleChange`：對已結算單以 `after*` 重算覆寫，狀態保持 SETTLE(1)。
- bonus / event-bonus：`bet=0`、`win=payoutAmount`/`amount`，TBR 帶 `bonus`；event-bonus 另寫 `PgsswEventRecord`。
- **`bet`/`validBet` 一律取 `validAmount`**（by mars：「一律使用有效流水」）。

## 端點 → 方法 → 回應碼

| 文件 § | Endpoint | 方法 | 重複單 | 查無單 |
|---|---|---|---|---|
| 5.1.1 | `/target/players/wallet` | `getBalance` | — | account 停用 → `401002` |
| 5.2.1 | `/target/bet/placeBet` | `placeBet` | `500029` | 餘額不足 → `403203` |
| 5.2.2 | `/target/bet/settle` | `settle` | 非 BET → `403601` | `404016` |
| 5.2.3 | `/target/bonus/settle` | `bonus` | `500029` | — |
| 5.2.4 | `/target/bet/settle-without-payout` | `settleWithoutPayout` | `500029` | — |
| 5.3.1 | `/target/bet/settle-change` | `settleChange` | — | `404016` |
| 5.2.9 | `/target/bet/cancel` | `cancel` | cancel log 已處理 → 回 `0`（冪等，`348c60a79`） | 回 `0`、不寫 BR（`7a7378a72`，陷阱 2） |
| 文件外 | `/target/transaction/event-bonus` | `eventBonus` | `500029` | — |
| 5.4.1/5.4.2 | `/target/transaction/{deposit,withdrawal}` | **未實作** | — | — |

> ⚠️ `bonus()` / `eventBonus()` 回應走 `bonusResponse()`，回 `transactionNo`/`merchantOrderNo`/`beforeAmount`/`afterAmount`（與文件僅標 `balance`/`transactionNo` 不同，待確認）。

## 整合落地建議

| 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/PegasusSW/Driver.php`（`AbstractSingleWallet implements BonusCodeInterface`） |
| 回調 Controller | `External/SingleWallets/PegasusController.php`（target 與 Tiger9 共用；BWG 走 `BWGController`） |
| Routes | `routes/external/sw.php` `prefix('sw')->prefix('target')`，完整 path `external/api/sw/target/...` |
| 狀態 Enum | `PegasusSW/OperatorStatusEnum.php`、`BetRecordStatusEnum.php`（BET=0/SETTLE=1/ROLLBACK=2） |
| Models | `PgsswAccount` / `PgsswBetRecord` / `PgsswCancelLog` / `PgsswEventRecord` |
| `vendor_infos.info` | `API_URL` / `API_APPID`(=`merchantID`) / `API_KEY`(=`merchantSecretKey`) / `IDENTITY`(=`X-target-IDENTITY` 來源驗證，`$vendorInfoOperatorField`) / `LIVE_BET_LIMIT`(→`liveCasinoLevelID`) |

## gaps / 待 target / RD 確認（非確認 bug）

1. ~~**標頭名稱 `X-target-IDENTITY` vs `X-PROVIDER-IDENTITY`**~~（陷阱 1）：**v1.4.8 已解**——v1.3.9 官方正名為 `X-PROVIDER-IDENTITY`，與實作一致；舊標頭仍保留，新商戶仍建議實測。
2. **正式 Base URL / 錯誤碼表 URL / 對帳保留期**：PDF 未提供（編號對照站 `http://example.invalid/document/enums/{en,cn}`）。
3. **launch-link（2.1.2）/ 3.x 詳細參數 / Freespin 未在 PDF 主文展開**：以實作 + 外部文件站為準。注：**v1.4.8 已正式文件化 5.2.5 event-bonus**，並新增 Freespin API 系列 `/v1/event/FreespinSpin/*`（僅 GameID 290001~290999、部分商戶開放）。
4. **deposit / withdrawal（5.4.1/5.4.2）未實作**：接入捕魚（430000 系列）前須補。

## v1.4.8 規格刷新對照（vs v1.1.10，2026-06-26）

api.md 已換抄 v1.4.8。對我方實作的影響盤點：

| v1.4.8 變更 | 對實作的影響 |
|---|---|
| 標頭正名 `X-target-IDENTITY`→`X-PROVIDER-IDENTITY`（v1.3.9） | ✅ 與 `callAction()` 一致，陷阱 1 解除（舊標頭仍保留） |
| Retry 改**最多 7 次**遞增（10s/3min/9min/27min/81min/243min/729min） | 冪等防禦（`transactionNo` → `500029`）須能撐長尾重送；非單次 retry |
| `taxAmount` **已廢棄請忽略**（settle / bonus） | 我方本就未用 taxAmount，無影響；勿據此欄位計稅 |
| `5.3.1 settle-change` **已廢棄** | 我方 `settleChange()` 仍在；屬廢棄 API，新行為勿擴充、可評估標 deprecated |
| bonus settle 編號 `5.2.3`→**`5.9.1` `/bonus/settle`**、標選擇性、**BWG 必實作 / target 可忽略** | 我方路由仍 `/target/bonus/settle`（`bonus()`）；編號變動不影響路由，但接 BWG 品牌時此為必要 |
| 新增 `extraInfo.isJackpot` / `gameType` / `roundID`（v1.2.8 起） | settle 可帶 Jackpot 旗標 / 視訊局號；如需入帳區分 Jackpot 再讀此欄 |
| 新增 `4.9.9 強制登出玩家` `/player/logout`、`3.3.1 遊戲結果回放` | 運營商主動呼叫端，目前未串；有需求再補 |
| 進階 **Freespin API 系列**（`/v1/event/FreespinSpin/*`，GameID 290001~290999） | 需先對接 5.2.5 event-bonus；僅部分商戶開放，未串 |
| 部分 API（3.1.1 / 4.6.1）標 **rate limit** | 報表 / 遊戲列表查詢端有每小時次數限制，排程拉取需節流 |
| `playerUniqueID` 格式：英數 + `-` / `_`，長度 4~36 | 建帳 / 驗證玩家識別碼時的格式邊界 |
