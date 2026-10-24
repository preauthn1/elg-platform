# AFBSW（vendor_id=176）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 單一錢包（Seamless）；玩家餘額由本平台保管，AFB 回調扣加款 |
| 回調方向 | **AFB → 本平台**（本平台為 server，被呼叫方） |
| 驗證 | IP 白名單（`verifyIPWhitelistByVendorId(176)`）＋ 回調 body 的 `key` 比對 `vendor_infos.info.API_KEY`（`AFBSwController::isApiKeyValid()`） |
| 成功判定 | `status == 0`，HTTP 一律 200 |
| 回調入口 | `POST /sw/afb`，以 body `method` 派發 |
| 匯率 | 回調 `amount / rate`；回傳餘額 `balance × rate` |

## 高風險陷阱

### 1. `sid` 欄位不在三方文件中（落差 §1）

Controller `_validateRequest` 要求 body 帶 `sid`，以 `SITE_ID` 定位商戶。但 AFB 官方 §5 所有回調範例**均無 `sid`**，`Site ID` 出現在我方 → AFB 方向的 `X-Site-ID` Header 中。若 AFB 實際未帶 `sid`，所有回調都會被 `sid is required` 攔截。

**上線前必須向 AFB 確認回調 body 是否包含 `sid`。**

### 2. `betPaid` 方法未實作（落差 §3，高風險）

`method` 白名單已含 `betPaid`，但 Controller 無對應方法。若 AFB 站點開啟 `betPaid` 開關，所有投注結算都會 500。

**上線前必須向 AFB 確認 betPaid 開關狀態；若啟用須補實作。**

### 3. `realBet`/`realPaid` 無存在性判斷

`paid()` 直接取 `$params['realBet']` / `$params['realPaid']`，文件標示兩者為 optional。若遊戲未送這兩個欄位，會觸發未定義鍵錯誤。需與 AFB 確認哪些遊戲必帶這兩個欄位。

### 4. refund 曾把投注量留在 TBR／SBR（2026-09-05 事故，target/op_tickets#3067）

修正前 `refund()` 只改 `payout`／`win_lose`／`status`，`bet`／`valid_bet` 沿用原注金寫進 TBR，SBR 再聚合，於是退款注單被 SR11／SR12 與三層代理返利（`SP_calc_three_hierarchy_bonus` 讀 `summary_bet_records.bet`）當成有效投注。實證：TPower 會員 631177 事故日 4 筆退款（16.40）讓 SR12 顯示 143.30、AFB 後台 126.90，三層代理自身返利多發 0.0164。錢包本身沒錯（退款金額正確），錯的是投注量口徑；另一個副作用是禮碼投注被退成真金（退款金額當派彩寫進 `payout`，禮碼錢包不退）。

Ace333SW／Astar／AiGamingSW／CosmoPlaySW／DstPlaySW 取消投注時都會歸零 `bet`／`valid_bet`，本次把 AFBSW 對齊它們（UUSlotSW 刻意保留原欄位但不寫 TBR；AcewinSW `cancelBet` 原本也以請求金額覆寫後仍寫 TBR，已於 target/op_tickets#3078 一併修正）。修正後行為見下方狀態機的 refund 條目；修正前已落地的退款注單（TPower 僅 6 筆、全在事故日）其 TBR／SBR 是否回補由 PM 決定。

### 5. 回調的第二道防線是 `key`，不是簽名（target/op_tickets#3077）

AFB 的 HMAC 簽章只用在**我方 → AFB** 的方向（api.md「簽章」節）；**AFB → 我方**的 Seamless 回調沒有簽名，
修正前唯一的防線是 IP 白名單——擋不住白名單內來源重放先前的請求，而這條路徑直接異動玩家錢包。

回調 body 的 `key` 與 `vendor_infos.info.API_KEY` 是同一個值（TPower 與 Alfawin77 各取最近 500 筆注單的
`info` 原文與該站 `API_KEY` 比對，**1000／1000 相符、零筆缺漏**，bet 與 paid 兩階段皆然），因此以
`hash_equals()` 比對它作為第二道，不符即回 `status=1`、`Invalid API key`。

⚠️ **`API_KEY` 未設定時放行**：舊商戶設定可能沒有這個鍵，缺鍵即整批拒收會讓該站回調全數失敗，
代價遠大於少一道驗證（對齊 `AiGamingSWController::isSignatureValid()`）。新站台建置時務必填入 `API_KEY`，
否則這道驗證形同未啟用。

## BetRecord 狀態機

`BET(1)` →（paid 結算）`PAID(2)` 或（refund 退款）`REFOUND(3)`，後兩者皆終態。

- **bet**：建立 BR `status=BET`、`payout=0`、`win_lose=amount`；扣款；**不寫 TBR**。
- **paid**：以 `realBet`/`realPaid`（÷rate）覆寫 `bet`/`valid_bet`/`payout`，重算 `win_lose=payout-bet`；status→`PAID`；寫 TBR。
- **refund**：`bet`／`valid_bet`／`payout`／`win_lose` 全部歸零；status→`REFOUND`；寫 TBR（投注量 0，退款注單不計入流水／返利）。錢包退款由 `changeBalance()` 依前後注單差額退回：真金回真金錢包、禮碼回禮碼錢包。`amount` 與原注金不符時**拒收**（回 `status=1`、`Refund amount mismatch`，錢包與注單都不動，另記 warning log `AFBSW_request_refund_amount_mismatch`），交由 AFB 重送或人工處理；AFB 規格 §5.5 未定義部分退款，生產退款實例的 amount 全等於原注金。
  ⚠️ 下注後若商戶關閉 `rolling_chip_status`，refund 時取不到禮碼錢包，`changeBalance()` 會把禮碼部分當真金退回且注單 `rolling_chips` 不歸零；此為單一錢包共通行為（Ace333SW／Astar 同），非 AFBSW 專屬。

> 注意：enum 拼字為 `REFOUND`（非 `REFUND`），DB 值 `3`。

## 冪等性

| 場景 | 處理 |
|---|---|
| `bet` 重複 `transactionId` | 拋 `Duplicate transaction` |
| `paid`/`refund` 找不到原 bet | 拋 `Bet transaction not found` |
| 對已 `PAID` 再操作 | 拋 `already settled` |
| 對已 `REFOUND` 再操作 | 拋 `already refunded` |

## 整合落地建議

| AFB 概念 | APIs 專案對應 |
|---|---|
| Driver（主動呼叫 AFB） | `app/Vendors/AFBSW/Driver.php`（`createAccount` / `getGameList` / `getGameUrl`） |
| Callback Controller | `app/Http/Controllers/External/SingleWallets/AFBSwController.php` |
| 路由 | `routes/external/sw.php` → `POST /sw/afb`（單一入口，`method` 派發） |
| BetRecord 狀態 | `app/Vendors/AFBSW/BetRecordStatusEnum.php`（`BET=1` / `PAID=2` / `REFOUND=3`） |
| 帳號 Model | `App\Vendors\Models\AfbswAccount` |
| BetRecord Model | `App\Vendors\Models\AfbswBetRecord`（注意 Driver 內部另指向 `DgBetRecord`，見下方） |
| `vendor_infos.info` | `API_URL`（勘誤後）/ `API_KEY`（HMAC secret = 回調 `key`）/ `SITE_ID` / `CURRENCY` |
| `vendor_infos.rate` | 幣值換算用 |
| IP 白名單 | `Common::verifyIPWhitelistByVendorId(176)` |
| 對帳保留期 | **1 個月**（PDF §9 "normally kept for one month"），對帳工具須在期限內拉取 |

> ⚠️ Driver 內部 `$betRecordModel` 指向 `DgBetRecord`，Controller 內 `$betRecordClass` 指向 `AfbswBetRecord`——**兩處 Model 不同**，排查問題時注意查對應的表。

## Gaps / 待 AFB 確認

- 回調 body 是否包含 `sid`（目前 Controller 強制要求，文件未提及）
- 站點 `betPaid` 開關是否啟用（影響是否需補實作 `betPaid()` 方法）
- `realBet`/`realPaid` 各遊戲是否必帶（文件標 optional）
- 同回合是否有多筆不同 `transactionId` 的 bet（`referenceId` 聚合行為）
- 完整 error code 清單（文件僅說 `status != 0` 為失敗）
- §5.6~§5.8 通知（`open`/`close`/`bonusStatusNotify`）是否需要支援
