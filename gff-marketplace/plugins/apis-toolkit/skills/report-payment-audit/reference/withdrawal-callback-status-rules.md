# 代付回調 withdrawal_orders.status 狀態機規範

適用：`app/Services/Payment/Providers/*/WithdrawalCallback.php`（**所有**金流商代付回調，逐家盤點）。

## 狀態碼（WithdrawalOrderStatus）

| status | 意義 |
|---|---|
| `21` | 代付提交中（結果未知的停留態，回調是定案依據） |
| `22` | 代付處理中（送出代付請求後、等待三方回調的狀態） |
| `24` | 代付成功（終態） |
| `29` | 代付失敗（非終態，可重新發起代付） |

> 放行集合以 `WithdrawalPayoutTrait::checkWithdrawalOrderStatus` 當前程式碼為準（現況：21、22 放行；29 與其他狀態一律拒絕並告警）。審查前先讀這支函式確認。

## 四條規範（逐家 WithdrawalCallback 必須符合）

### 規範 1：只有放行集合內的狀態才接受回調
- 回調進來先查單，確認狀態在共用守衛的放行集合內（21／22）才繼續處理業務。
- 不在放行集合（已是 24／29 或其他狀態）→ 守衛發告警並拋 `WithdrawalOrderException`（code 400）；各家 catch 後 `rollBack`、**不變更狀態**，多數家回給三方的是**失敗碼**（`handleWithdrawalOrderException()` 或各家 inline 判斷：只有 code 200 才回成功碼，代付守衛不會拋 200）；例外如 VtPay 在這裡一律回成功碼，審查時逐家讀 catch 分支確認。
- 重複回調的冪等不靠這道守衛：該筆 payout record 已成功（`isPayoutRecordProcessed()`）時，在呼叫守衛**之前**就回**成功碼**止重送。
- 審查點：處理業務前是否呼叫 `checkWithdrawalOrderStatus()`（自寫等價邏輯時，放行集合須與它一致）？catch 分支是否 `rollBack` 且不動 status？已成功的 payout record 是否在守衛前回**成功碼**（TakaPay 曾在已成功單再回調時回 failResponse → 三方重送）。

### 規範 2：只有「明確成功」或「明確失敗」才變更 status
- 三方明確表達成功 → 改 `24`；明確表達失敗 → 改 `29`。
- 「明確」= 對照該家狀態碼定義的**終態**成功 / 失敗值，**不是「非成功即失敗」**。
- 🔑 **「明確」必須有三方文檔依據**：成功碼 / 失敗碼只能放三方官方文檔 / `docs/integrations/payments/{Name}/api.md` / 該家 `*StatusCodeEnum`（有文檔依據的 case）明確歸類為「終態成功 / 終態失敗」的碼。文檔沒明說的、處理中 / 待處理 / 審核中 / 未知碼一律不放 → 落 INDETERMINATE（保持原狀態 21 或 22）。
- ⚖️ **失敗集寧缺勿濫**：失敗碼不確定時**留空**，不可憑 enum case 名稱或盤點直覺「推測」是失敗。

> **為何「誤判失敗」是最危險方向（重複打款資損）：** 代付三方常在每次狀態變更都回調（processing→success 多次）。把中間態誤判成**失敗(29)** → 客服後台視為該家代付失敗 → **改派另一家三方重新發起代付** → 但原三方稍後可能再回**成功** → 同一張訂單兩家都成功打款 → **重複付款給用戶（真實資損）**。反向（失敗誤判成 INDETERMINATE、保持原狀態）只會暫時停在 21 或 22：21 由停留單排程 `withdrawal:alert-stuck-submitted` 告警，21／22 都可由人工兜底命令 `withdrawal:resolve-payout` 定案，**不造成資損**。

### 規範 4：每家都必須有可達的失敗路徑（禁止「永不失敗」配置）

- **任何一家代付回調的判斷都不得存在「完全不會有失敗」的情境。** 真失敗必須能被標 29，否則失敗單永久停在 21 或 22（需人工撈）。所以「失敗碼不確定就整個留空」是**錯的**：那造成永不失敗。
- 兩種合規寫法（都保證失敗可達），依該家文檔能否列舉失敗碼二選一：
  - **(A) 雙白名單**：文檔能列明確失敗碼 → `resolveWithdrawalOutcome(status, 成功碼, 失敗碼)`，失敗碼**非空**，其餘（未知）→ INDETERMINATE。
  - **(B) doc-literal「其他=失敗」**：文檔只有「成功碼 + 處理中碼 + 其他=失敗」、無可列舉失敗碼 → inline `match`：成功→SUCCESS、**已知處理中/非終態碼→INDETERMINATE、`default`→FAILURE**。
- **(B) 的安全責任在「處理中(pending)白名單必須完整」**：default 落 FAILURE，凡「之後可能轉成功」的非終態碼都必須明列進 INDETERMINATE（含參考該家**代收**狀態表，pending 語意常共用），漏一個就把處理中誤判失敗→雙付。若文檔證實無任何非終態態（如 AkPay 代付僅 Approved+其他）→ 純二分，屬該家文檔事實但雙付曝險最高，須標註。
- 「某個不確定碼算 pending 還是 failure」的**個別**判斷→倒向 pending(INDETERMINATE)；但**整體配置**不得因此變永不失敗。審查時逐家確認：失敗路徑可達 + (B) 家 pending 白名單完整。

### 規範 3：不明確一律維持原狀態（21 或 22）+ 記錄
- pending / processing / 未知 / 不在已知終態集合的回調 → **不可變更 status（保持原狀態 21 或 22）**，只記 log，回成功碼等下一次回調。

## 🔴 最常見違規：二元 isSuccess 把「不明確」當失敗

很多家寫成：

```php
$isSuccess = ($status === '<success-code>');
$this->updateWithdrawalOrderStatus($order, $isSuccess);  // false → 直接打 29
```

→ 收到 pending / 中間態回調時 `$isSuccess = false`，**誤把訂單打成失敗 29**，違反規範 2/3（應維持原狀態 21 或 22）。

**正確做法**：先把回調狀態分成 成功 / 失敗 / 不明確 **三類**；不明確時 early-return（回成功碼、不動 status）。

**參考正解**：`Kira` / `VaderPay` / `ZPay` / `TakaPay` 的 `WithdrawalCallback`——非 SUCCESS/FAILED 狀態時 early-return 回 OK、不更新訂單。

## 候選違規名單（起點，須回 code 驗證）

以下兩個來源可當逐家審查的優先名單（都可能過期，務必回當前 code 確認）：

- 風險發現資料檔 `docs/reports/data/payment-risk-findings.json` 中，分類為「三、代付回調把「不是成功」一律標成失敗」的發現，以及標「已知限制」的純二分代付回調。
- 盤點報告 `docs/reports/payment-providers-report.html`「回調邏輯」一節，狀態判定風格為**二分**的家全列為高嫌疑（二分最容易把中間態當失敗）。這一節同時列代收與代付，本規範只管**代付** `WithdrawalCallback`，逐家須回 code 確認是否出現在代付路徑。

## 逐家審查輸出

每家標記：
- ✅ 符合 4 規範
- ⚠️ 部分違反（指出哪一條 + file:line）
- 🔴 二元 isSuccess 誤把不明確打成失敗 29（最高優先修）

並彙總一張「違規家數 / 規範」對照表。
