# OnebxSW（vendor_id=168）

> 對接心得。通用接入規範見 `game-integration` skill；廠商規格見 Postman「ONE-BX Group Doc V.2」
> （https://example.invalid/view/46115913/2sB2xEC9T9，2026-09-19 對讀）。本檔只記文件與程式碼推不出來、
> 實際對過線上資料或文件才知道的事。`api.md` 尚未建立（文件是 Postman collection，整理成 markdown另案）。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 單一錢包；體育（足球等）與泰拳兩個產品共用同一組端點 |
| 回調入口 | `POST /external/api/sw/onebx/api/{balance,bet,rollback,undo,settle,cancel}` 與 `step/{bet,rollback,undo,settle,cancel}` |
| 驗證 | `signature = base64(HMAC-SHA1(http_build_query(ksort(欄位去 signature)), SECRET_KEY))`；null 值兩端都會被 http_build_query 丟掉 |
| 成功判定 | **只看 HTTP 200**，body 不解讀——唯一例外見下方 settle 的 E_00 |
| 上線站台 | TPower（活躍）、Yes2Win（2026-06-28 後無單）；其餘四站零帳號 |
| 線上回調實際由誰服務 | **舊系統 API_External**（`ext-webapi-container`）；新 APIs 的 `apis-external` 至 2026-09-18 為 0 筆。注單表有資料 ≠ APIs 在處理 |

## 高風險陷阱

### 1. `/api/settle` 絕對不能回 `{"status":"E_00"}`

文件原文：非 200 且 body 帶 `E_00` 會把**整批派彩標成永久失敗、永不重送**，那批玩家永遠收不到錢。其餘任何狀態碼都會讓重送迴圈繼續
（泰拳 4 次退避後回 pending queue、體育 3 次立即重試；之後排程每分鐘重送到 200 為止，線上看過重送 9 次）。
`OnebxSWController::errorLogAndResponse()` 對 `settle`／`stepSettle` 的系統錯誤改回 `RETRY`，HTTP 仍 500。舊系統 API_External 仍會回 E_00。

### 2. undo／rollback／cancel 的 `credit_amount` 不是金額指令

- **undo**：`credit_amount` 恆為 0（Dev 36 筆全 0），`win_side`／比分皆 null。文件：「Always reverse against your own settle record」。
  收回額一律取注單自己記的已結算派彩（`win_lose + bet`）。舊 Libs 拿 `credit_amount` 當收回額 → undo 一毛不收、注單卻退回 BET → 再 settle 重複派彩。
- **rollback**：對我方有的注單，`credit_amount` **等於 bet**（TPower 10 筆已取消注單皆然，等於當初的 `deduct_amount`，不是 `amount_bet`）；
  `credit_amount=0` 的 rollback 打的都是我方沒有的單（下注被拒／超時後 OneBX 補送 void），走「找不到→回餘額」。
  0 元對到存在的注單是異常，回 E_00 讓其重送，不可默默標成取消。
- **cancel**：文件明訂「If the ticket had already been settled, the settle result must be reversed as part of the cancel」。實作在 `refundBet()`：
  已結算的單退本金＋收回派彩＋刪 TBR。舊 Libs 只找 `status=BET`，已結算的單 cancel 會變 no-op。

### 3. settle 的冪等鍵是每筆的 `call_transaction_id`，undo 的不是

- settle：`{bettranstion_id}-S{settleVersion}`，**2026-04-26 起每筆都有**（之前 216 筆全無）。文件：「Do not de-duplicate settle on bettranstion_id alone」。
  實作以注單 `infos` 裡已套用 payload 的 `call_transaction_id` 判斷：同 ID 是重送→靜默；不同 ID 是沒經 undo 的重新結算→**不動錢、留 warning**
  （文件沒定義該補差額還是全額；TPower／Yes2Win 763 張注單從未發生，線上每張都是 bet → 一次 settle 或 cancel）。
- undo：文件自承 `call_transaction_id` 不可靠（泰拳 38% 是常數 `-U0`、體育 47% 不送，只有 57% 可用），**只能** key 在 `bettranstion_id` ＋ 我方結算狀態。

### 4. cancel 的 body 沒有頂層 `timestamp`

文件 cancel body 只有 `item` 與 `signature`，`timestamp` 在 `item.data[].timestamp`。`CancelRequest` 的 `timestamp` 改 `nullable`；
settle／undo／step 的 body 都有頂層 timestamp。

### 5. step/* 的欄位與一般端點不同

| | 一般端點 | step 端點 |
|---|---|---|
| item 外層鍵 | `win_side` | `type`（`win`／`cancel`／`undo`／`promotion`／`refund`） |
| 金額欄 | `credit_amount` | **`amount_bet`**（錢包實際動的量）；`amount_winlose` 只是參考值，可能是數字字串 |
| `match_play` | 單一物件（含 null） | **陣列**（多場串關），settle／cancel／undo 時為 `[]` |
| `choose_bet` | red／blue | `handicap`／`overunder`／`step` |

`itemAmount()` 的 `credit_amount ?? amount_bet` 剛好對到兩邊正確欄位。線上 30 天、Dev 7 天內 step/* 皆零流量，測試 fixture 只能照文件與 FormRequest 編。

### 6. 兩支動錢端點尚未實作，但已掛路由收 log（另案）

`POST /api/step/bonus`（發放 promotion）與 `POST /api/step/refund-bonus`（回收 promotion），body 形狀同 step item
（`type: promotion／refund`，`data[]` 含 `promotion_id`／`bill_id`／`transaction_id`／`amount` **字串**）。舊系統同樣沒有。

**路由已掛、但 controller 只記 log 後回 `E_00`／500**（`logUnimplementedEndpoint()`）。兩個理由：

- 沒有路由就是 404，Laravel 的 RequestLogger 不會跑，永遠看不到三方送了什麼。掛上去才收得到真實 payload，
  grep `OnebxSW_request_endpoint_not_implemented` 即得。
- 🔴 **不可回 200**：回 200 等於向 OneBX 承認已入帳，它就不再送，玩家的 promotion 直接消失。回非 200 它會持續重送
  （文件：重送到收到 HTTP 200 為止），等實作上線後這些待處理的請求會自己補回來。

實作前要向 OneBX 問清楚的四件事（文件未定義）：

1. 冪等鍵是 `transaction_id`、`bill_id` 還是 `id`？發放獎金重送會重複加錢。
2. `amount` 是字串（`"100.50"`），入境要 `Common::numAdd('0', ..., 4)` 正規化。
3. promotion 不是投注，不該進 `onebxsw_bet_records`／TBR（會被算進有效投注與返水），但也不能只動錢包不留帳——記到哪？
4. `refund-bonus` 找不到原 bonus 時期望回什麼？

線上（TPower 2026-09-17）與 Dev（2026-09-18）皆零流量，連 404 都沒有——推測該商戶尚未開啟 promotion 功能。

## 其他

- 產品判別：`win_side` 為 `red`／`blue` 是泰拳，`won`／`lose`／`haftwon`／`haftlose`／`cashout` 是體育（**haft 不是 half**，照抄）；`draw` 兩邊都有，
  以 `round` 區分（泰拳 `<timestamp>-<username>`，體育等於 `bettranstion_id`）。
- balance 要在 5 秒內回，逾時 OneBX 以餘額 0 處理。
- MY 盤負賠率時 `deduct_amount < amount_bet`（TPower 實例 122／89.06）：扣款用 `deduct_amount`，有效投注記 `amount_bet`。
- 泰銖盤（`price_type: TH`）押 tor 方時扣款是本金 × 賠率比，退款一律用 `credit_amount`，不可從 `amount_bet` 推。
- 一批 settle／undo／cancel 上限 30 筆，線上 400/400 都是一批一筆。
