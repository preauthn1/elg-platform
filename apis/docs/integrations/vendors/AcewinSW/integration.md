# AcewinSW（vendor_id=110）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 單一錢包（Seamless）；玩家餘額由本平台保管，AcewinSW 回調扣加款 |
| 回調方向 | **AcewinSW → 本平台**（本平台為 server，被呼叫方） |
| 驗證 | IP 白名單（`verifyIPWhitelistByVendorId(110)`）＋ HTTP Basic Auth **強制**（`CLIENT_AUTH_ACCOUNT`；未帶或帶錯一律回 errorCode=5 `Invalid client`，見陷阱 6） |
| 成功判定 | `errorCode == 0`，HTTP 一律 200 |
| 回調入口 | `POST /sw/acewin/auth`、`POST /sw/acewin/bet`、`POST /sw/acewin/cancelBet` |
| 時區 | UTC-4（AcewinSW 所有時間欄位均為 GMT-4） |
| 匯率 | 回調金額 `÷ rate`；回傳餘額 `× rate`（`vendor_infos.rate`） |

## 高風險陷阱

### 1. `bet` 是下注+結算合一，`winloseAmount` 可為零或負淨額

AcewinSW 的 `bet` callback 同時帶 `betAmount`（扣款）與 `winloseAmount`（派彩）。兩者合一，不像部分廠商分兩步（bet → win/lose）。

餘額計算公式：
```
若 下注前餘額 >= betAmount：
    下注後餘額 = 下注前餘額 - betAmount + winloseAmount
否則：
    回 errorCode=2（餘額不足）
```

- 玩家輸光時：`winloseAmount = 0`，餘額扣去 `betAmount`
- 玩家贏時：`winloseAmount > betAmount`，差額為淨盈
- `inGameJPAmount` 為遊戲內 JP 派彩參考值，**不參與加扣款**，僅記錄於 `info` JSON

### 2. KeyG 使用 UTC-4 時間，跨日比 UTC 提早 4 小時

簽名步驟 1 計算 KeyG 時，時間格式使用 `yyMMd`（Carbon `ymj`，UTC-4）：

- 格式特殊：**月份補零、日期不補零**（`02` 月、`7` 日，非 `07`）
- 若伺服器時區為 UTC+8，實際時間與 UTC-4 差距 **12 小時**
- 跨日影響：UTC+8 的午夜 00:00~04:00，UTC-4 仍是前一天 → KeyG 計算的「今日」與本地不同
- 此陷阱只影響代理 → AcewinSW 方向（管理 API、進遊戲）；回調不涉及此簽名

### 3. cancelBet 1 分鐘×10 次重試，5 秒超時，冪等必須嚴密

- bet 超時（5 秒）後 AcewinSW 會在 1 分鐘後發出 cancelBet，每分鐘一次，最多 10 次
- cancelBet 可在玩家離線後送達，此時 token 可能已失效
- 玩家識別雙軌：有 `userId` 則以 `vendor_username` 查，無 `userId` 才用 `token`
- 10 次重試期間，bet 若以任何方式「已成立」（errorCode=1 重複或已 SETTLE），api.md 的實作說明要求回 errorCode=6（注單已成立不可取消）

⚠️ **errorCode=6 未實作，且不宜照 api.md 字面實作**：本平台的 `bet` 是下注結算合一，注單一建立 `status` 就是
`SETTLE`（陷阱 1），若把「已 SETTLE 即回 6」照字面實作，cancelBet 將永遠無效——而 cancelBet 的存在意義正是撤銷
「我方已寫入、但回應逾時所以三方不確定有沒有成立」的那一筆。現行實作只擋重複（已 ROLLBACK → errorCode=1）與
查無注單（errorCode=2）。要區分「真正成立」與「逾時待撤」需要三方定義成立判準，屬待確認項（見 Gaps）。

### 4. GetGameDetailUrl 簽名計算不含 GameId

`GetGameDetailUrl` 請求帶 `WagersId` 與 `GameId` 兩個參數，但**計算 Key 時只用 `WagersId + AgentId`，忽略 `GameId`**。這是官方 spec 明確記載，非筆誤。實作時必須從 Key 計算用的 params 中排除 `GameId`。

### 5. cancelBet 曾以請求金額覆寫注單，等於完全沒退款（target/op_tickets#3078）

修正前 `cancelBet()` 把 `bet`／`valid_bet` 寫成**請求帶來的金額**、`win_lose` 依請求重算，而不是歸零。兩個後果：

1. **投注量留在 TBR／SBR**：被撤銷的注單仍以原金額計入 SR11／SR12、三層代理返利（`SP_calc_three_hierarchy_bonus`
   讀 `summary_bet_records.bet`）與提款流水門檻——與 AFBSW 的 target/op_tickets#3067 同型。
2. **錢包根本沒退**：`changeBalance()` 以前後注單差額推算錢包變動，請求金額與原注金相同時差額為零，
   於是「取消投注」只把 status 改成 ROLLBACK，玩家的錢沒有退回去（測試實證：扣 10.00 後撤單，餘額停在 90.00）。

修正後 `bet`／`valid_bet`／`win_lose` 一律歸零，錢包退款由 `changeBalance()` 依差額推得（真金回真金、禮碼回禮碼）；
請求 `betAmount` 與原注金不符時**拒收**（回 errorCode=3、`Cancel amount mismatch`，另記 warning log
`ACEWIN_get_request_cancel_amount_mismatch`）——注單金額以我方紀錄為準，不接受三方覆寫。

> 生產資料零影響：六站 `acewin_bet_records` 全掃**零筆 status=9（ROLLBACK）**（TPower 1,148 萬筆、Yes2Win 724 萬筆
> 全為 status=1，其餘四站表空），代表這條路徑從未成功執行過，不需要回補歷史資料。

### 6. 不帶 Basic Auth 一律拒收（target/op_tickets#3077 → target/op_tickets#3085）

`parseOperatorId()` 解不到 `Authorization`（缺漏、或不是 `Basic ` 開頭）時回空字串，而 `BaseController::getVendorInfo('')`
的語意是「沒有指定代理則回傳第一筆」——交給它就會整段跳過憑證比對，該回調只剩 IP 白名單一道。

api.md 附錄 E 的「可選」指的是這個機制可選擇啟用，不是三方可能不帶：2026-09-07 生產 log 實查 TPower 200 筆／Yes2Win 100 筆
回調 **100% 帶 `Authorization`**（`php-auth-user`／`php-auth-pw` 欄位存在即證明）。因此 `callAction()` 在解析憑證後直接拒收空值，
回應與憑證錯誤一致（errorCode=5 `Invalid client`），錢包與注單都不動；仍記 warning log `ACEWIN_get_request_missing_client_credential`
（`vendor_id`、action、`result=rejected`）供觀察三方行為。原本 `checkSign()` 的 `return true;` 空實作已於 #3077 移除——它看起來像
「有一道驗證」，實際上什麼都沒做。

> Acewin 回調目前由舊系統 API_External 處理（GCP container `ext-webapi-container`），APIs 這條路徑零流量；本項是遷移前先把
> APIs 做對，舊系統是否同步收口另評估（見 target/op_tickets#3085）。

## BetRecord 狀態機

```
初始建立（bet callback 到達）
        │
        ▼
   SETTLE（status=1）  ←── bet+settle 合一，直接終態，寫 TBR
        │
        │（異常：bet 超時，AcewinSW 送 cancelBet）
        ▼
   ROLLBACK（status=9）←── 退款，寫 TBR
```

- **bet**：`round`（WagersId）作為幂等鍵；建立 BR，`status = SETTLE`（直接終態，無中間狀態）；扣款；寫 TBR
- **cancelBet**：查 BR `bet_id = round`；若 status 已為 ROLLBACK → 回 errorCode=1（冪等）；請求 `betAmount` 與原注金不符 → 回 errorCode=3（拒收，錢包與注單都不動）；正常流程 `bet`／`valid_bet`／`win_lose` 歸零、status → `ROLLBACK`，退款由 `changeBalance()` 依前後注單差額推得，寫 TBR（投注量 0，撤銷的注單不計入流水／返利）。errorCode=6 未實作，理由見陷阱 3
- **無中間狀態**：不存在「下注中/結算中」過渡狀態（與分兩步廠商不同）

## 冪等性

| 場景 | 處理 |
|---|---|
| `bet` 收到相同 `round`（betId） | 查 `acewin_bet_records.bet_id`；已存在 → 回 errorCode=1（REPEAT_TRANSACTION），回傳目前餘額 |
| `bet` 相同 `round`、不同 `reqId` | 視為重複，回 errorCode=1 |
| `cancelBet` 找不到原 `round` | 回 errorCode=2（BET_RECORD_NOT_FOUND） |
| `cancelBet` 原注單已為 ROLLBACK | 回 errorCode=1（REPEAT_TRANSACTION） |
| `cancelBet` 原注單已為 SETTLE（成立） | api.md 要求回 errorCode=6，**本平台未實作**（bet 建立即 SETTLE，照做會讓 cancelBet 永遠無效，見陷阱 3） |
| `cancelBet` `betAmount` 與原注金不符 | 回 errorCode=3（`Cancel amount mismatch`），錢包與注單都不動 |

## 整合落地建議

| AcewinSW 概念 | APIs 專案對應 |
|---|---|
| `AgentId`（站長 Id） | `vendor_infos.info.CLIENT_ID` |
| `AgentKey`（簽章金鑰） | `vendor_infos.info.CLIENT_SECRET` |
| `AUTH_URL`（API Base URL） | `vendor_infos.info.AUTH_URL` |
| `CLIENT_AUTH_ACCOUNT`（HTTP Basic Auth） | `vendor_infos.info.CLIENT_AUTH_ACCOUNT`（`parseOperatorId()` 解析） |
| `VendorIdEnum` | `VendorIdEnum::ACEWIN`（vid=110） |
| Driver（進遊戲、管理 API） | `app/Vendors/AcewinSW/Driver.php`（繼承 `AbstractSingleWallet`） |
| Callback Controller | `app/Http/Controllers/External/SingleWallets/AcewinController.php` |
| 路由 | `routes/external/sw.php` prefix `acewin`（`/auth`、`/bet`、`/cancelBet`） |
| 帳號 Model | `AcewinSwAccount`（`acewin_accounts`） |
| BetRecord Model | `AcewinSwBetRecord`（`acewin_bet_records`，冪等鍵 `bet_id = round`） |
| DealRecord Model | `AcewinSwRecord`（`acewin_records`） |
| `vendor_infos.rate` | 幣值換算；回調金額 `÷ rate`，回傳餘額 `× rate` |
| IP 白名單 | `Common::verifyIPWhitelistByVendorId(110)` |
| 對帳 | `GetBetRecordSummary` / `GetBetRecordByUuid`；查詢範圍限 **1 個月** |
| SBR 重建 | `App\Services\SummaryBetRecords\AcewinSW`（`$timeField = 'payout_time'`） |

## Gaps / 待確認

1. ~~Basic Auth 能否改成強制~~ **已改為強制**（target/op_tickets#3085，見陷阱 6）：生產 log 證實三方一律帶憑證，APIs 側未帶即拒收；舊系統 API_External 是否同步收口另評估
2. **GetGameDetailUrl 忽略 GameId 行為需實測確認**：官方 spec 記載 Key 計算時忽略 `GameId`，但未見 AcewinSW 官方回函確認；上線前以 staging 實測
3. **cancelBet 重試期間已成立注單的回應**：需向廠商確認「注單已成立」的判準（本平台 bet 建立即 SETTLE，無法用 status 區分），以及 AcewinSW 收到 errorCode=6 後是否停止重試
4. **FreeSpin（betAmount=0）餘額判斷**：文件說明不扣款，但實作中需確認 `isMemberAbleToBet()` 是否會因帳戶狀態封鎖 FreeSpin
5. **GetBetRecordByUuid 對帳用途**：已取消注單無法查詢；對帳工具若依賴此 API 需特殊處理取消注單的對帳邏輯
