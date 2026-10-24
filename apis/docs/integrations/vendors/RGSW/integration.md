# RGSW（RG 單一錢包，vendor_id=250）

> ⚠️ **三方沒有提供任何規格文件**（本目錄沒有 `api.md`，repo 內也找不到 RG 的協定說明）。
> 本檔的內容是從 `app/Http/Controllers/External/SingleWallets/RgswController.php` 的實作與 TPower 生產資料反推出來的，
> 凡是推斷而非實證的地方都標了「未驗證」。通用接入規範見 `game-integration` skill。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 單一錢包（玩家餘額由本平台保管，RG 以回調扣加款） |
| 認證 | header `wtoken`，值就是 `vendor_infos.info['token']`；另有 IP 白名單（`Common::verifyIPWhitelistByVendorId`，`vendors.whitelist` 為空時直接放行） |
| 回應 | **一律 HTTP 200**，狀態放在 body `status.code`（`0` = 成功，其餘見 `App\Vendors\RGSW\Enums\StatusCodeEnum`）。寫測試時別用 `assertOk()` 當成功斷言，錯誤也是 200 |
| 金額 | request 帶的是遊戲商幣值，除以 `vendor_infos.rate` 換算成平台幣值 |
| 時間 | RG 傳 UTC-4，`parseVendorTime()` 轉成 UTC 後存 DB |
| 注單表 | `rgsw_bet_records`；唯一鍵只有 `reference_no`（存 `order_sn`），`bet_id` 是非唯一索引 |
| 上線白標 | **只有 TPower**（2026-09-09 實測六站，其餘五站 `rgsw_bet_records` 都是 0 列），rate = 1.0 |

## 端點與注單狀態

| 端點 | 做什麼 | 對注單的影響 |
|---|---|---|
| `POST game/bet` | 下注扣款 | 新增一列，`status = BET` |
| `POST game/payout` | 對已下注的局派彩 | 更新原列為 `PAYOUT`（RG 的 `order_sn` 與原注單撞號，不能新增列） |
| `POST game/endRound` | 一次送多筆派彩並關局 | 更新原列為 `ENDROUND`，`data` 內每筆 `money` 逐一疊加進 `win` |
| `POST game/betPayout` | 下注與結算一次送完 | 新增一列，`status = BETPAYOUT` |
| `POST game/refund` | 退款 | 未結算局縮減 `bet`（**退乾淨才翻 `REFUND`**，退一部分留在 `BET`）；已結算局疊加 `win`，狀態不變 |
| `POST game/debit` | 事後扣帳（`amount` 恆為負值） | 疊加進 `win`，狀態不變 |
| `POST game/credit` | 事後補帳 | 疊加進 `win`，狀態不變 |
| `POST game/sumBet` | 事後追加投注 | 疊加進 `bet` / `valid_bet`，狀態不變 |

`payout` / `endRound` / `refund` / `debit` / `credit` / `sumBet` 都不新增注單列，而是**直接改原本那一列**，因為 RG 對這些請求送的 `order_sn` 一律等於原注單的編號，新增會撞 `reference_no` 唯一鍵。每次改動都會把原始請求追加進 `info.corrections`（見 `appendCorrectionToInfo()`），型別走 `CorrectionTypeEnum`。

## 冪等性

| 端點 | 擋得住重送嗎 | 靠什麼擋 | 重送時回什麼 |
|---|---|---|---|
| `bet`、`betPayout` | ✅ | `reference_no` 唯一鍵，第二次寫入撞 1062 後整筆交易回滾 | `42297 FLOW_NUMBER_IS_EXIST` |
| `payout`、`endRound` | ✅ | 只收 `status = BET` 的注單，第二次已經查不到 | ⚠️ `firstOrFail()` 拋 `ModelNotFoundException` ⇒ HTTP 404、且回應格式不是 RG 的 `status.code`（不會重複入帳，但回應行為未驗證，另案） |
| `refund`（部分退款、已結算局）、`debit`、`credit`、`sumBet` | ✅ | 同一局、同一種調整、同一個金額且在 **300 秒**內 ⇒ 視為重送（`isDuplicateCorrection()`） | `0` + 當下餘額，錢包不動，同時寫一筆 `RGSW_duplicate_correction_skipped` 警告 |
| `refund`（已全額退完的局） | ✅ | 注單已翻 `REFUND`，而 `resolveByBetId()` 的允許清單不收 `REFUND`，在守衛之前就落空 | `42296 RECORD_NOT_FOUND`（與上一列不一致是既有行為，錢是安全的） |

### 為什麼調整類回調只能用「時間窗」去重（target/op_tickets#3100）

一般的冪等做法是「記下三方給的交易單號，同一個單號只處理一次」。RG 這四支請求**沒有交易單號可用**——2026-09-09 實測 TPower（`rgsw_bet_records` 共 180,970 列）：

| 請求 | 生產樣本 | payload 欄位 |
|---|---|---|
| `credit` | 10 筆 | `amount`、`bet_no`、`order_sn`、`username` |
| `payout` | 443 筆 | 上列四項再加 `rake`、`payoff`、`payout`、`rebate`、`session`、`bet_real`、`bet_total`、`payout_time` |

連欄位最多的 `payout` 都沒有序號（`session` 的值是空的），而且 `order_sn` 恆等於 `bet_no`。也就是說，「三方逾時重送的第二次」與「同一局的第二筆合法調整」在請求內容上**長得一模一樣**，只靠內容分不出來。

所以守衛的判準是「同一局、同一種調整、同一個金額、**而且剛剛才套用過**」，時間窗（`RgswController::DUPLICATE_WINDOW_SECONDS`，目前 300 秒）是分辨兩者的最後一道邊界：

- 太短 → 擋不住慢速重送（重複入帳，正是本單要修的問題）
- 太長 → 誤擋合法的第二筆同額調整（玩家少拿錢，但會留下 `RGSW_duplicate_correction_skipped` 警告，log 帶 `member_id` 與 `skipped_amount`，可直接算出影響金額）

⚠️ **300 秒沒有 RG 文件依據，而且沒辦法用歷史資料校準**：TPower 的 453 筆 corrections 每張注單都只有一筆，代表生產上從來沒發生過任何重送，也就沒有間隔可以統計。這同時是好消息——歷史誤擋率的上界是 0。問到 RG 的實際重送策略後應以該值校準（要改值就得發版，這個旋鈕刻意沒有做成可線上調整，避免有人把它設成 0 而把整道防線關掉）。

### 為什麼比對「關鍵欄位」而不是整包 payload

守衛只比對 `CorrectionTypeEnum::identityKeys()` 列出的局號欄位加上金額，不比整包請求內容。整包比對看起來更嚴格，實際上更危險：

- RG 哪天在請求裡多帶一個會變動的欄位（生產上的 `payout` 就帶了 443 筆全不重複的 `payout_time`），整包比對就永遠不會相等 ⇒ 守衛**靜默失效**、重複入帳，而且不報錯、不留 log，看起來像修好了。
- 只比關鍵欄位則相反：失效方向是誤擋（玩家少拿、可補、有 warning 可查）。

金額用數值比對（`Common::numCompare`，4 位小數）而非字串相等，`"3"` 與 `"3.0000"` 是同一筆錢。

### 讀取安全性

比對的資料就在**已經被 `lockForUpdate()` 鎖住的那一列**自己的 `info.corrections` 裡，屬於鎖定讀（當前讀），因此不會踩到 SpadegamingSW（`target/op_tickets#3098`）那種「拿到會員鎖之後的一般讀仍看到舊快照」的坑；也因為不另外查一張表，不需要新索引、不會多出 gap lock。

`debit` 與 `sumBet` 的重送判斷**排在餘額檢查之前**：第一次扣款已經讓餘額變小，重送時往往再也扣不動，守衛排在後面就根本問不到，三方會收到「餘額不足」而不是「這筆我處理過了」。

### 部分退款不再一律關單

未結算局的退款是「縮減這一注」。原本不論退多少都把狀態翻成 `REFUND`，於是同一局的第二筆合法部分退款會查不到注單（`resolveByBetId()` 不收 `REFUND`）而少退。現在只有把注**退完**才翻 `REFUND`，退一部分的局留在 `BET` 等後續。

順帶補上兩道防線，都回 `42298 TRANSACTION_FAILED`：

- **退款額大於剩餘下注額**：照原式算下去 `bet` 會變成負數，`changeBalance()` 以 `bet` 差額入帳等於退還玩家沒下過的錢。
- **退款額不是正數**：負數會讓加減反向，變成從玩家錢包倒扣。

### 金額比大小一律指定 4 位小數

`Common::numCompare()` / `numDiv()` 的預設 scale 是 10，而注單金額欄位是 `decimal(18,4)`。拿「除以 rate 之後還帶 10 位小數的請求金額」去比「已經被 DB 收到 4 位的注單金額」，遇到除不盡的 rate（例如 3）會出現兩種壞法：全額退款被判成超額而永遠退不掉，或退完仍留 0.0001 以下的殘量、注單永遠關不了。所以本檔的金額比較一律帶 `RgswController::AMOUNT_SCALE`。TPower 的 rate 是 1.0 踩不到，這是為了下一個接 RG 的白標。

## 未驗證／待 RG 確認

- **重送策略**：RG 逾時後會不會重送？間隔多久、重送幾次？（決定 `DUPLICATE_WINDOW_SECONDS` 的正確值）
- **調整類請求會不會給唯一序號**：若之後 RG 願意帶交易號，冪等鍵應改成該序號，時間窗那套即可退場。
- **同一局是否可能有兩筆金額相同的合法調整**：若會，且間隔在時間窗內，現行守衛會誤擋。
- **部分退款後的有效投注怎麼算**：目前與 `bet` 同步遞減並以 0 為下限，無三方依據。這個值會流進 `TotalBetRecordService` 的有效投注與返水統計。
- **部分退款的語意**：若 RG 的「部分退款」其實是「本局作廢、分次退」，留在 `BET` 的局會永遠掛著。目前沒有任何排程或報表在掃長期未結算的 RGSW 注單，值得補一個「`status = BET` 且超過 N 天」的定期統計。
- **`payout` / `endRound` 重送收到 HTTP 404 時 RG 的行為**：會不會持續重送。

## 已知限制（不擋上線）

- `info` 是 `TEXT`（65,535 bytes），`corrections` 無上限累加。以每筆約 200 bytes 估算約 300 筆到頂，超過會拋 MySQL 1406、被 `catch (QueryException)` 接住回 `42298`（fail closed，不會靜默截斷）。RG 目前的量級（全站 credit 調整共 10 筆）碰不到，但本次把 `info` 從「歷史紀錄」升格成「冪等帳本」，語意變重了。
- `resolveByBetId()` 用 `latest('id')` 取單一列，而 `bet_id` 的欄位註解是「同回合可多筆」。2026-09-09 實測 TPower 的 181,019 個 `bet_id` **全部只有一列**，所以目前不成立；真出現多列回合時，守衛是逐列比對的。
- `payout` / `endRound` 先鎖注單列再取會員鎖，與 `refund` / `debit` / `credit` / `sumBet` 相反，同一局併發時理論上有 ABBA 死鎖（MySQL 1213 → 回 `42298`，帳不會壞但會掉一次回調）。屬既有行為，本次未動。

## 相關

- `target/op_tickets#3100`（本次修正）、`target/op_tickets#3089`（PTSW 同型）、`target/op_tickets#3098`（SpadegamingSW 同型，且是「鎖定讀」那條護欄的由來）
- 測試：`tests/Feature/External/SingleWallets/RGSW/`
