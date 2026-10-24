# Top55（vendor_id=182–212）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 單一錢包（Seamless）；玩家餘額由本平台保管，GoldenGateX 回調扣加款 |
| 底層平台 | **GoldenGateX**（API version 2.15.5，2026-01-07 更新） |
| 子商戶數量 | **31 個子商戶**（vid 182–212），全部共用同一套 Driver 及 Callback Controller |
| 回調方向 | **GoldenGateX → 本平台**（本平台為 server，被呼叫方） |
| 驗證（主動呼叫） | Bearer Token（先呼 `POST /auth/createtoken`，12 小時快取，401 自動重刷最多 3 次） |
| 驗證（回調） | `Authorization: Basic base64(clientId:clientSecret)` + IP 白名單；**無 HMAC/加密簽章** |
| 成功判定 | `success === true` + `errorCode === 0` |
| 金額符號 | **負數 = 下注扣款，正數 = 派彩加款** |
| 冪等性 Key | `historyId`（注意：非 `transactionCode`，見陷阱） |
| 對帳保留期 | 後台展示 **15 天**；Betting History API 可查 **7 天** |

## 高風險陷阱

### 1. 冪等性以 `historyId` 判斷（非 `transactionCode`）

實作以 `historyId` 作為 `bet_id` 查重（`Top55swBetRecord::where('bet_id', $historyId)`）；`transactionCode` 僅存入 `info` JSON 欄位供對帳，**未建唯一索引**。

> GoldenGateX 文件要求重複 `transactionCode` 回 `errorCode: 6 (DUPLICATE_TRANSACTION)`，但實作以 `historyId` 達成冪等。若三方對同一 `historyId` 傳入不同 `transactionCode`，目前實作會拋 Duplicate。**需向三方確認 `historyId` 與 `transactionCode` 的唯一性保證。**

### 2. batchTransactions 批次失敗行為

`batchTransactions` 以 for loop 逐筆呼叫 `execTransaction`，回傳最後一筆成功後的餘額。若中途某筆失敗（拋出例外），整個 `DB::transaction` rollback；但目前實作在整批失敗 vs 單筆失敗的行為需與三方確認（三方預期整批一致性還是允許部分成功？）。

### 3. Token 快取 TTL 與有效期不明

Driver 以 `CacheTtl::HALF_DAY`（12 小時）快取 Bearer token，但官方文件未明確說明 token 實際有效期（僅提供 `expiration` Unix timestamp）。若三方 token 有效期短於 12 小時，快取期間可能收到 401（Driver 已實作 401 → 清 cache → retry 最多 3 次）。建議確認三方正式 token 有效期後調整 `CacheTtl`。

### 4. `isFinished=true` 後再收相同 `historyId`

文件明確要求：已標 `isFinished=true` 再收到相同 `historyId` 的新 transaction，應回 `INVALID_TRANSACTION (errorCode: 7)`。對應實作中的 `SETTLEMENT`/`CANCEL` 狀態防重邏輯。

## BetRecord 狀態機

`BET(1)` →（`isFinished=true` + `isCanceled=false`）`SETTLEMENT(2)` 或（`isCanceled=true`）`CANCEL(3)`，後兩者皆終態。

- **下注**（amount < 0）：`BetHelper::isMemberAbleToBet` → 確認餘額充足 → 以 `historyId` 查冪等 → 建立 `Top55swBetRecord(status=BET)`（`isFinished=true` 時直接 `SETTLEMENT`）→ `changeBalance` 扣款。
- **派彩**（amount > 0）：查 BR → `SETTLEMENT/CANCEL` 已終態拋冪等例外 → 更新 `payout`、`win_lose`、`payout_time` → `isCanceled=false` → `SETTLEMENT` 並寫 TBR；`isCanceled=true` → `CANCEL`，**不寫 TBR**。
- **batch-transactions**：逐筆走相同 `execTransaction` 邏輯，整批在同一 `DB::transaction`，回傳最後一筆餘額。

## 冪等性

| 場景 | 處理 |
|---|---|
| 下注重複 `historyId` | 拋 Exception：`'Duplicate transaction'` |
| 派彩找不到對應 `historyId` | 拋 Exception：`'Bet transaction not found'` |
| 注單已 SETTLEMENT | 拋 Exception：`'Bet record already settled'` |
| 注單已 CANCEL | 拋 Exception：`'Bet record already canceled'` |

## 整合落地建議

| GoldenGateX 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Top55/Driver.php`（31 個 vid 共用，繼承 `AbstractSingleWallet`） |
| Callback Controller | `app/Http/Controllers/External/SingleWallets/Top55Controller.php` |
| 路由 | `routes/external/sw.php`，前綴 `/top55` |
| `clientId` | `vendor_infos.info.clientId` |
| `clientSecret` | `vendor_infos.info.clientSecret` |
| `API_URL` | `vendor_infos.info.API_URL` |
| `vendorCode`（可選覆蓋） | `vendor_infos.info.vendorCode`（優先於 VENDOR_CODE_MAP） |
| 帳號 Model | `app/Vendors/Models/Top55swAccount.php` |
| 注單 Model | `app/Vendors/Models/Top55swBetRecord.php`（`bet_id` = `historyId`） |
| BetRecord 狀態 | `BetRecordStatusEnum`：`BET=1` / `SETTLEMENT=2` / `CANCEL=3` |
| 子商戶 vendorCode 對應 | 靜態 `VENDOR_CODE_MAP`（vid 182–212 → vendorCode，見 api.md） |
| IP 白名單 | `Common::verifyIPWhitelistByVendorId`（各 vid 分別設定） |
| 對帳 API | `POST /betting/history/by-date-v2`（游標分頁，最多 2000 筆，Rate limit 2次/10秒） |

## Gaps / 待 GoldenGateX 確認

- **`historyId` vs `transactionCode` 唯一性**：三方保證哪個欄位在同一回合唯一？（冪等實作依賴 `historyId`）
- **batchTransactions 部分失敗行為**：三方預期整批原子性還是允許部分成功回報？
- **Bearer token 實際有效期**：`expiration` 的實際秒數（以調整 `CacheTtl` 避免 401）
- **Seamless 模式下 `/user/create`**：文件將此 API 歸類為 Balance Transfer，但實作也用於 Seamless 帳號預建立，三方是否允許？
- **`vendorCode` 對應確認**：上線前需逐一確認 31 個子商戶的 VENDOR_CODE_MAP 是否與三方提供的 `vendorCode` 一致
- **對帳保留期差異**：後台顯示 15 天、API 可查 7 天，超出 7 天需透過其他管道取得
