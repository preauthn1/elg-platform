# Wm（vendor_id=16）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | Transfer Wallet（operator 主動推送資金） |
| 回調方向 | 代理 → WM 主動呼叫；**無 WM → 代理回調** |
| 認證方式 | LICENSEE_TOKEN 放在 URL 路徑 + IP 白名單 + HTTPS；**無 per-request 簽名** |
| 成功判斷（Credit/Debit） | HTTP 200 + `Status == "GRANTED"` |
| 成功判斷（Transaction query） | `Status == "GRANTED"` |
| 時區 | 未明確規範 |
| 資料保留（對帳） | EWAPI export/summary feed 時間區間上限 **45 天** |

---

## 最重要陷阱：無 per-request 簽名

WM WAPI **不計算任何 HMAC/MD5 request 簽名**。token 直接放 URL 路徑，body 不含任何 `sign` 欄位。這與本專案其他廠商（Wcasino MD5、各金流商 HMAC 等）完全不同。

對接時**嚴禁**把其他廠商的簽名邏輯套到 WM 請求上；WM 拿到多餘欄位可能回 `TOKEN_NOT_VALID` 或行為異常。

---

## WAPI vs EWAPI 不可混用

| 文件 | 本專案用途 | 禁止事項 |
|---|---|---|
| WAPI（Wallet Web API） | ✅ 所有 REST 呼叫（建玩家/Credit/Debit/Auth/Balance/Transaction） | — |
| EWAPI（External Wallet API） | ⚠️ 僅遊戲啟動 URL 格式與 language 代碼參考 | 不要依此實作 GetCash / TransferCash 等回調 controller |

本專案 Wm（vid=16）**無 external callback controller、無 sw/tw 路由**——所有資金流由本地主動推送，WM 不回呼本系統。EWAPI 的 Seamless / 紅利 / 免費回合 / `TransferBonus` / `CancelTransfer` 等章節與本專案接入**完全無關**。

---

## 成功判斷落差（高風險）

`Driver::transfer()`（`app/Vendors/Wm/Driver.php`）目前以「回應陣列**不含** `Error` 鍵」即視為 `SUCCESS`，並未正面比對 `Status == "GRANTED"`。

- **正常情況**：兩種判斷等價（成功帶 `Status`，失敗帶 `Error`）。
- **風險**：若 WM 回傳非預期結構（無 `Status` 也無 `Error`），現行邏輯會**誤判為成功**。
- `checkTransferStatus()` 則正確使用 `Status == "GRANTED"` 判斷。

建議統一改以 `Status == "GRANTED"` 為成功準則。

---

## 金額精度

| 操作 | 精度 | 實作 |
|---|---|---|
| Credit / Debit（送出金額） | 2 位小數 | `Common::numFloor($vendorAmount, 2)` |
| Balance（讀取餘額） | 4 位小數 | `Common::numFloor($response['Balance'], 4)` |

WAPI 對金額僅規定「decimal、`.` 為分隔符」，未限定位數。混用精度在轉帳與餘額對帳時可能出現微小差異，建議與 WM 確認該幣別實際支援精度。

---

## Reference 使用交易紀錄主鍵非 transaction_no

`checkTransferStatus()` 與 `transfer()` 的 `Reference`/`ref` 都使用 `transactionLogId`（交易紀錄 PK），程式註解標明「遊戲商要求，需與舊版一致」。

文件對 `Reference` 僅要求為 operator 端唯一字串（≤50 chars），故合規；但此設計與本專案其他 vendor 慣用 `transaction_no` 不同，遷移／對帳時需注意。

---

## 語言代碼疑點（繁簡疑似相反）

實作 `mapLanguageCode()`：`ZH_TW → "ZH"`、`ZH_CN → "ZH-HANT"`。

但官方 EWAPI 啟動 URL 章節明示：
- `ZH-HANT` = 繁中
- `ZH-HANS` = 簡中

實作的繁簡對應疑似相反（繁中送 `ZH`、簡中送 `ZH-HANT`）。現存程式行為，**待向 WM 確認正確語言代碼**，非本次變更。

| APIs `LanguageEnum` | 實作送出值 | 預期（依 EWAPI 文件） |
|---|---|---|
| `ZH_TW` | `ZH` | 應為 `ZH-HANT` |
| `ZH_CN` | `ZH-HANT` | 應為 `ZH-HANS` |
| `ID_ID` | `ID` | — |
| 其他（預設） | `EN` | — |

---

## AuthKey 單次性

每次啟動遊戲前**必須**重新呼叫 Auth 取最新 `AuthKey`，不可重用前次取得的值。WAPI Auth Note 1 明示：AuthKey 為單次 token，重用會導致遊戲載入認證失敗。

---

## 冪等性

Credit/Debit 以相同 `Reference` 重送只執行一次（文件 Note 3 明示），逾時重試安全。本專案以交易紀錄主鍵（`transactionLogId`）作為 `Reference`，與舊版一致。

---

## 對帳資料來源

WAPI 無下注明細 feed。注單須走 EWAPI 的 History / Session / Export feed（CSV 建議、JSON 可選），或 WM 後台。對帳保留期：EWAPI export/summary feed 時間區間上限 **45 天**（2018-06-27 修訂）；逾期需聯絡客服。

---

## 實作落差（待確認）

1. **成功判斷**：`transfer()` 以「無 Error 鍵」判斷，而非正面比對 `Status=="GRANTED"`（見上節）。
2. **金額精度**：Credit/Debit 送 2 位、Balance 讀 4 位，混用需確認幣別支援精度。
3. **Reference 使用交易紀錄主鍵**：與其他廠商慣例不同，遷移時需注意。
4. **語言代碼繁簡疑似相反**：`ZH_TW→"ZH"`、`ZH_CN→"ZH-HANT"` 疑似顛倒，待向 WM 確認。

---

## 整合落地建議

| WM / WAPI 概念 | APIs 專案對應 |
|---|---|
| `LICENSEE_TOKEN`（32 碼 hex，URL 路徑帶入） | `vendor_infos.info.LICENSEE_TOKEN` |
| REST Base host | `vendor_infos.info.SERVER_NAME`（`Driver::setInfo()` 取 `trim($info['SERVER_NAME'], '/')`） |
| `Currency`（建玩家／轉帳幣別，需一致） | `vendor_infos.info.CURRENCY` |
| 遊戲啟動 `GAME_URL` | `vendor_infos.info.GAME_URL`（預設回退 `SERVER_NAME`） |
| `LICENSEE_CODE` | `vendor_infos.info.LICENSEE_CODE`（預設 `'XSG'`） |
| `GAME_MODE` | `vendor_infos.info.GAME_MODE`（預設 `'real'`） |
| `GAME_URL_MODE` | `vendor_infos.info.GAME_URL_MODE`（預設 `1`，現行走 mode=2 新式） |
| WMC `UserID`（建玩家冪等鍵） | `wm_accounts.user_id` |
| WMC `UserToken`（玩家識別 hash） | `wm_accounts.player_id` |
| 玩家帳號 | `wm_accounts.username` |
| Driver | `app/Vendors/Wm/Driver.php`（繼承 `AbstractTransferWallet`） |
| Account / BetRecord Model | `app/Vendors/Models/WmAccount.php`、`WmBetRecord.php` |
| Callback controller / 路由 | **無**（轉帳錢包，WM 不回呼） |
| 對帳資料來源 | EWAPI History / Session / Export feed 或 WM 後台 |
| 對帳保留期 | **45 天**（EWAPI export/summary feed 時間區間上限） |
