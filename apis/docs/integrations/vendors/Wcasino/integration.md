# Wcasino（vendor_id=111）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | Single Wallet / Seamless |
| 回調方向 | Wcasino → 本平台（玩家下注/結算時主動回調） |
| 認證方式 | IP 白名單 + MD5 ASCII-sort 簽名（`bets` 欄位排除在外） |
| 成功判斷 | `result >= 0` |
| 時區 | UTC+8（CST / SGT） |
| 資料保留：getbets | **48 小時**（拉取後呼叫 removebets 清除） |
| 資料保留：注單明細 | **60 天**（`/api/record/bets/detail`） |

---

## 簽名陷阱（高風險）

1. **`bets` 欄位不參與簽名**：驗簽前必須先從請求中移除 `sign` 和 `bets`，再計算 MD5。遺漏此步驟導致驗簽必敗。
2. **key 全小寫後排序**：所有參數 key 必須先 `strtolower()` 再按 ASCII 升序排列，否則簽名不符。
3. **不做 URLEncode**：文件明確要求不對參數值做 URLEncode。實作使用 `http_build_query` 後接 `urldecode` 還原，與此要求一致；直接使用 `http_build_query` 輸出（不 decode）會導致含特殊字元的欄位簽名失敗。
4. **空值欄位排除**：空值欄位不參與簽名（`array_filter` 過濾）。

---

## Rollback 四個 Note 摘要（必讀）

| Note | 重點 |
|---|---|
| **Note I** | rollback 到達時，原始 deduct/deposit **不一定已成功**；必須先查 `notifyid` 紀錄，確認是否存在且 `is_done=true`，再決定是否執行回滾。 |
| **Note II** | rollback 針對的是**最後一筆**請求，不是整張注單；同一注單可能有多次 deposit/deduct，rollback 只撤銷最後那次。 |
| **Note III** | 若代理**從未收到**原始 deduct/deposit（網路全斷），rollback 到達時直接回傳成功，無需實際反操作。 |
| **Note IV** | rollback 的 `type` 與對應 deduct/deposit 完全一致（例如 deposit type=9 的 rollback 也是 type=9）。 |

---

## type=7 CANCEL 雙向處理

Deposit（入款）中 type=7 的行為依 `amount` 正負分叉：

| 條件 | 執行方法 | 說明 |
|---|---|---|
| `amount >= 0` | `executeBetCancel` | 玩家主動取消下注，入款還原已扣金額 |
| `amount < 0` | `executeSettlementCancel` | 取消已結算的入款（反結算） |

Rollback 中 type=7 統一執行 `executeRollbackBetCancel`（反取消，還原為 PENDING）。

---

## Re Settle（type=10）

Re Settle 為**增量**調整，不是覆蓋：`win_lose += delta`（新派彩與舊派彩的差額），並非替換為全新金額。對帳時需累計所有 type=10 通知。

---

## 冪等性

唯一鍵組合：`wcasinosw_notify_records` 表的 `UNIQUE(bet_id, notify_id, type, is_rollback)` 四欄複合唯一索引。

- `bet_id` 對應請求中的 `serialnumber`
- `notify_id` 對應 `notifyid`
- `is_rollback` 區分正常通知與 rollback 通知

Wcasino 因網路問題可能重複送相同 `notifyid`，代理端必須靠此 UNIQUE KEY 防止重複扣款/入款。

---

## BetRecord 狀態機

```
初始
  │
  ▼
[PENDING] ──── Deduct(type=1) ─────────────────── 扣款，建立 BetRecord
  │
  ├── Rollback(type=1) ──────────────────────────── 取消注單，還原餘額
  │
  ├── Deposit(type=7, amount>=0) ─────────────────── BetCancel → CANCELLED
  │       └── Rollback(type=7) ─────────────────── 反取消 → PENDING
  │
  └── Deposit(type=9) ──────────────────────────── 結算 → SETTLED
          │
          ├── Rollback(type=9) ─────────────────── 退派彩 → PENDING
          │
          ├── Deposit(type=10) ─────────────────── ReSettle（win_lose += delta）
          │       └── Rollback(type=10) ────────── 反重新結算
          │
          └── Deposit(type=7, amount<0) ────────── SettlementCancel → PENDING
```

---

## 實作落差（待確認）

1. **登入 API 以 POST 帶 Query String**：文件標示 Method: POST，但參數在 URL Query String 而非 request body。Driver.php 使用 `Http::asJson()->post($url)` 且 URL 包含 Query String，body 為空。待 RD 確認是否與部分 proxy 相容。

2. **`NotifyTypeEnum::ROLLBACK = 12` 無文件依據**：官方 API Type 表僅列 1、7、9、10、28，無 type=12。Rollback 通知的 type 文件說明是「與對應 deposit/deduct 相同」。此 enum 值是否實際被使用待 RD 確認。

3. **`bets` 欄位格式歧義**：HTTP Form 模式下文件說明 `bets` 為 JSONObject，但範例顯示為陣列。`BetInfoData::from()` 實作支援兩種格式（`is_string` 則 `json_decode`），彈性較高。

4. **`setQuota()` 每次登入都呼叫**：Driver.php 在每次 `getGameUrl()` 後都呼叫 `/api/quota/set`，但文件未要求每次登入都更新限額。可考慮改為只在限額變更時呼叫以減少 API 呼叫次數。

---

## 整合落地建議

| Wcasino 概念 | APIs 專案對應 |
|---|---|
| `appid` | `vendor_infos.info.CLIENT_ID` |
| Secret Key | `vendor_infos.info.CLIENT_SECRET` |
| Base URL | `vendor_infos.info.AUTH_URL` |
| 下注限額 ID | `vendor_infos.info.CLIENT_QID`（逗號分隔，最多 3 個） |
| 幣種 | `vendor_infos.info.CURRENCY` |
| 玩家帳號 | `wcasinosw_accounts.username` |
| 注單主鍵 | `wcasinosw_bet_records.bet_id`（對應 `serialnumber`） |
| 冪等鍵 | `wcasinosw_notify_records` 表：`UNIQUE(bet_id, notify_id, type, is_rollback)` |
| Driver | `app/Vendors/Wcasino/Driver.php` |
| 回調 Controller | `app/Http/Controllers/External/SingleWallets/WcasinoSWController.php` |
| 路由 | `routes/external/sw.php` → `Route::prefix('wcasino/api')` |
| 對帳保留期 | 注單 detail **60 天**；GETBETS 僅 **48 小時** |

### 語言映射（`Driver::mapLanguageCode()`）

| APIs `LanguageEnum` | Wcasino code |
|---|---|
| `ZH_TW` | `6`（繁體中文） |
| `ZH_CN` | `1`（簡體中文） |
| `ID_ID` | `11`（Bahasa） |
| `VI_VN` | `10`（Tiếng Việt） |
| `TH_TH` | `5`（ภาษาไทย） |
| 其他（預設） | `2`（English） |
