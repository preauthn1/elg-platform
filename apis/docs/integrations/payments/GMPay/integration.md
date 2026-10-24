# GMPay 接入心得（integration.md）

> 只記「讀 `api.md` ＋ 讀 `GMPay/`、`MajesticPay/DepositCallback.php`、`DepositNotifyController`、`PaymentService` 程式碼**推導不出來**」的一手落差與踩坑。廠商原文規格見 `api.md`。

---

## 1. 工單 #2435：V1.5 `origin_amount` 實付金額重算入帳

**背景**：GMPay V1.5（工單 #2435，2026-07-08，william@TPower P2）在代收回調新增 `origin_amount`。三方為避免交易金額重複，會自動微調實付金額（`amount`＝實付、`origin_amount`＝原始下單），要求**一律以 `amount` 入帳**（例：下單 10.00、實付 10.10 → 入帳 10.10）。

### 為什麼不能天真地「用 amount 上分」——rate≠1 地雷（查現況才知道）

代收上分的金額來源是 **`orders.point`**，不是 `orders.amount`、也不是 callback `amount`：

```
DepositNotifyController::__invoke()
  → provider->depositCallback()          # 只更新 order 狀態/金額，不上分
  → order.status === SUCCESS
      → PaymentService::updateMemberBalance(order)   # 入帳 = orders.point（非 amount）
```

而 `orders.point = orders.amount × orders.rate`（`OrderService::createOrder`，實為 `amount×rate − tax`，GMPay `tax=0`）。**TPower GMPay 的 `rate` 不是 1，而是 3.146 / 3.111**（production `agent_cash_flows` id 280/267 實測；`amount 100 → point 314.60`）。

> ⚠️ 若直接 `point = callback_amount`，在 rate=3.146 下會把 314.60 錯成 10.10——**少上分約 3 倍**。正解：`point = amount × orders.rate`，維持 `order.point = order.amount × order.rate` 不變式。

### 實作落點與時序

`GMPay/DepositCallback::applyCreditAmountAdjustment()`（覆寫父類 no-op hook）：**趕在 controller 上分前**，於回調成功且帶 `origin_amount` 時，以實付 `amount` 覆寫 `orders.amount` 與 `orders.point`（`= amount × order.rate`），`DB::commit()` 後 controller 再依更新後的 `point` 上分。

- **觸發範圍**：邏輯只在 `GMPay/DepositCallback`（provider 級隔離）；並再以 `orderStatus===SUCCESS && isset(origin_amount)` 雙重把關。
- 上分後 `orders.status` 由 `SUCCESS(1)` 轉 `DEPOSIT_FINISHED(3)`（既有流程）。

### 合理範圍防護（業務規則，來自工單/PM）

- **金額關係約束**：`order.amount ≥ origin_amount` 恆成立、差額不超過 **1.00 SGD**；實付 `amount ≥ origin_amount`、差額同樣不超過 1.00 SGD。
- 程式防護：僅當 `origin_amount ≤ amount ≤ origin_amount + 1.00`（`PAID_AMOUNT_ADJUSTMENT_CAP = '1.00'`）自動入帳；逾越（含實付 < 原始）→ 轉 `WAITING_MANUAL_DEPOSIT(4)` + `warning` TG 通知，不自動上分。

### `origin_amount` 未納入回調驗簽（安全性事實）

GMPay 回調 `checkHash` 只簽 `transaction_reference + amount + currency`（見 `GMPayTrait::checkHash`）——**`origin_amount` 未被簽章**。故：

- 入帳基準是**已簽章的 `amount`**（可信）；`origin_amount` 僅作防護範圍的下界參考。
- 防護刻意**不拿 `origin_amount` 與我方 `order.amount` 硬核對**：湊零頭下兩者未必逐分相等（`order.amount ≥ origin_amount` 但可有差額），硬核對會誤殺正常存款轉人工。

**測試**：`tests/Unit/Services/Payment/Providers/GMPay/DepositCallbackTest.php`（範圍內入帳 / CAP 邊界 / 等值 / 逾 CAP 轉人工 / 實付<原始轉人工 / 無 origin_amount 舊行為回歸）。

---

## 2. 與 MajesticPay 的關係：已從「薄包裝」走向分歧，代收回調拆分

GMPay 原為 MajesticPay 協議的薄包裝（各類別繼承 `MajesticPay\*`）。但 **MajesticPay V1.10 與 GMPay V1.5 的代收回調機制已根本分歧**（2026-07-09 對照兩份 PDF 確認）：

| 面向 | MajesticPay V1.10 | GMPay V1.5 |
|---|---|---|
| 實付≠下單 的欄位 | `is_repush` + `repush_amount` | `origin_amount`（無 is_repush） |
| 處理 | `is_repush=1` → **轉人工**（merchant 手動） | 湊零頭 → **自動以 amount 入帳** |
| 其他 | payment_method 多（TNG/Grab/Boost/DuitNow/Telco/Crypto）、sender_bank、crypto_rate、repush_deposit(PIN)、repush_request、MYR 銀行 | SGD Online Banking/PayNow、SGD 銀行 |

**因此 #2435 將 `DepositCallback` 拆分（template-method）**：
- 共用流程（validate / checkHash / lock / 冪等 / determineOrderStatus / update / commit）留父類 `MajesticPay/DepositCallback`，並開兩個 `protected` 擴充點：`depositCallbackRules()`（驗證規則）、`applyCreditAmountAdjustment()`（入帳金額調整，父類 **no-op**）。
- GMPay 專屬（origin_amount 規則 + 實付重算 + 防護）全在 `GMPay/DepositCallback` 覆寫；`GMPayTrait::checkHash()` 仍是回調驗簽差異（拼 `transaction_reference + amount + currency`，MajesticPay 拼 `ref_no + amount + currency`）。
- **MajesticPay 行為零變更**（no-op hook 回傳原狀態、不動 payload，`$order->update(['status','remark'])` 與拆分前逐字相同；MajesticPay 回調本就無 origin_amount）。

> ⚠️ **待辦（非 #2435 範疇）**：MajesticPay 自己的 `api.md` 仍是舊版，V1.10 新增的 crypto_rate / repush_deposit / repush_request / sender_bank / 多 payment_method / MYR 銀行清單尚未落檔，需另立工單刷新。

---

*建立：2026-07-09（工單 #2435）／資料來源：查現況（TPower production `agent_cash_flows`/`orders` 實測）、GMPay V1.5 PDF、工單 #2435 往返*
