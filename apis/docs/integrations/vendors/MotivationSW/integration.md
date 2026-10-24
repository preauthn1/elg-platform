# MotivationSW（vendor_id=125）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 單一錢包（Seamless）；玩家餘額由本平台保管，Gameboy 回調扣加款 |
| 品牌名稱 | **Gameboy**（對外品牌）；廠商名 MotivationSW |
| 回調方向 | **Gameboy → 本平台**（本平台為 server，被呼叫方） |
| 驗證 | `wtoken` Header（由本平台傳給 Gameboy 的 token，回調時帶回） |
| 成功判定 | 回 HTTP 200 + `{ "status": 1, ... }`；失敗回對應錯誤碼 |
| 時區 | **UTC-4**（注意！非 UTC+8；對帳換算必須注意） |
| 回調入口 | `POST /external/api/sw/motivation/{action}` |
| 幣值換算 | 回調 `amount ÷ rate`；回傳餘額 `balance × rate` |
| 冪等 key | `mtcode`（每次回調帶入的唯一識別碼） |

## 高風險陷阱

### 1. UTC-4 時區——對帳換算必須注意

MotivationSW 全系統使用 UTC-4，與本平台 UTC+8 差距 12 小時。對帳日界、`from_at`/`to_at` 參數、log 時間全部需要換算，否則對帳時間錯位一整天。

### 2. rollout 路由到 bet 的 controller 方法

`routes/external/sw.php` 的 `rollout` 路由指向的是 `bet()` 方法（Controller 名為 `bet` 而非 `rollout`），這是刻意設計——rollout 行為即「投注扣款」。若日後重構路由命名需注意別誤改。

### 3. 回調一律回 HTTP 200，成功與失敗寫在 body 的 `status.code`

本平台回給 Gameboy 的回應固定 HTTP 200，成功 `status.code = "0"`，失敗填 `api.md` 錯誤碼表的碼（`1005` 餘額不足、`1006` 查無玩家、`1014` 查無紀錄、`1100` 伺服器錯誤）。非預期錯誤（DB 錯誤、程式錯誤）一律回 `1100` 與固定訊息 `Internal server error`，真實訊息與 trace 只進 log。改版前的 `errorLogAndResponse()` 曾把錯誤碼當 HTTP 狀態碼回，已於 `MotivationSWController` 改繼承 `BaseController` 時修正。

### 4. 所有回調的金額換算方向一致，`Credit` / `Debit` 沒有例外

請求金額是廠商幣別，進來一律 `Common::numDiv($amount, $vendorInfo->rate)` 轉成平台金額；回給 Gameboy 的餘額一律 `Common::numMul($balance, $vendorInfo->rate, 4)` 轉回廠商幣別。`Credit`／`Debit` 與 `Bet`／`EndRound`／`Rollin` 走同一套，舊文件寫的「Credit/Debit 不乘 rate」與實作不符。

### 5. wtoken 是每次遊戲啟動時由本平台傳入的 token

`wtoken` 儲存於 `vendor_infos.info.token`，在呼叫 Gameboy API（主動方）時帶入 Header，Gameboy 回調時也帶相同 `wtoken` 回來。若 token 過期或不符，Gameboy 無法驗證來源。

## BetRecord 狀態機

```
BET(投注 / rollout) ──→ SETTLE(結算 / rollin/wins/amends)
                    ──→ REFUND(退款 / rollout)
                    ──→ CANCEL(取消 / cancel)
```

- **Rollout（bet）**：建立 BR（`status=BET`），扣款 `amount÷rate`
- **EndRound / Rollin（settle）**：`win = payoutAmount÷rate`，`status=SETTLE`，寫 TBR
- **Rollout（refund）**：退款，`status=REFUND`，寫 TBR
- **TakeAll**：特定遊戲整批結算多筆注單
- **Cancel**：取消未結算注單
- **Wins / Amends**：補充結算 / 結算調整（`status=SETTLE`，重算 `win_lose`）

## 冪等性

| 場景 | 處理 |
|---|---|
| 重複 `mtcode` | 查 BR 若已存在同 mtcode 則回成功（idempotent 回傳） |
| `endRound` 找不到原 bet BR | 拋錯，Gameboy 可重試 |
| 已 SETTLE 再 rollout(refund) | 拋錯，不可 double credit |

冪等 key：`mtcode`（每筆回調帶入的唯一 transaction code）。

## 整合落地建議

| MotivationSW 概念 | APIs 專案對應 |
|---|---|
| Driver（主動呼叫 Gameboy） | `app/Vendors/MotivationSW/Driver.php`（`AbstractSingleWallet`，`getGameUrl`） |
| 回調 Controller | `app/Http/Controllers/External/SingleWallets/MotivationSWController.php` |
| 回調路由 | `routes/external/sw.php`，prefix `motivation` |
| `vendor_infos.info` | `apiUrl`（Gameboy API Base URL）/ `token`（wtoken，Header 驗證）|
| `vendor_infos.rate` | 幣值換算係數，所有回調同向：請求金額除以 rate、回傳餘額乘以 rate |
| 帳號 Model | `MotivationSwAccount`（`app/Vendors/Models/MotivationSwAccount.php`） |
| BetRecord Model | `MotivationSwBetRecord`（`app/Vendors/Models/MotivationSwBetRecord.php`） |
| 對帳保留期 | 文件未載明，須向 Gameboy 客服確認 |

## Gaps / 待 Gameboy 確認

- **注單保留期限**：getGameLog / 查單 API 可回溯多久
- **TakeAll 批次大小**：單次 TakeAll 可帶入多少筆注單
- **Amend 多次的冪等邏輯**：同一注單連續送多次 amend，mtcode 是否相同
- **wtoken 有效期**：token 是否需要定期更新，或遊戲 session 結束後失效
- **Credit / Debit 無 mtcode 的冪等方案**：是否有其他唯一識別 key
