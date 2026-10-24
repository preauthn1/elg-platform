# `orders.cash_flow_id` 欄位現況盤點

> **目標**：釐清 `orders.cash_flow_id` 欄位的當前狀態，判斷該補上寫入端讓設計完整、還是接受其為 dead column 並修復受影響的下游邏輯。
>
> **建立日期**：2026-05-22
>
> **狀態**：草稿（待 PO / 相關 owner 決議）
>
> **關聯**：[費率與手續費雙層收斂計畫.md](./費率與手續費雙層收斂計畫.md) — 同樣面臨「schema 設計與代碼實作脫節」的 pattern，可一併納入金流相關 schema 健檢；上位框架見 [denormalization-設計原則.md](./denormalization-設計原則.md)

---

## 目錄

1. [問題陳述](#1-問題陳述)
2. [實證數據](#2-實證數據)
3. [代碼證據](#3-代碼證據)
4. [推測的設計意圖](#4-推測的設計意圖)
5. [已發現的實際影響](#5-已發現的實際影響)
6. [處理選項](#6-處理選項)
7. [建議](#7-建議)
8. [待決議事項](#8-待決議事項)

---

## 1. 問題陳述

`orders.cash_flow_id`（型別 `int|null`，schema comment「支付商ID; cash_flows.id」）是一個**完全沒有被寫入端實作**的欄位：

- 表結構已建立、預設為 NULL
- `OrderService::createOrder`（所有代收訂單的建立入口）**沒有填入此欄位**
- 全表歷史掃描（TPower 1200 萬筆訂單）顯示 `cash_flow_id IS NOT NULL` 筆數 = **0**

下游有讀取此欄位的代碼，但因為值恆為 NULL，**所有條件式都靜默失效**。

---

## 2. 實證數據

### 2-1 TPower

| 範圍 | 總筆數 | `IS NOT NULL` |
|---|---|---|
| 最近 7 天已付款訂單（依 `pay_time`） | 63,638 | **0** |
| 全表歷史（含 12M 筆） | 12,170,865 | **0** |

```bash
docker exec -e DB_CONNECTION=TPower php-fpm8 \
  bash -c "cd /var/www/APIs && php artisan tinker --execute '
    echo \App\Models\Order::whereNotNull(\"cash_flow_id\")->count();
  '"
# → 0
```

### 2-2 VWorld

| 範圍 | 總筆數 | `IS NOT NULL` |
|---|---|---|
| 最近 7 天已付款訂單（依 `pay_time`） | 22,977 | **0** |

### 2-3 結論

**至少 TPower 上 `orders.cash_flow_id` 從未在任何時間點被寫入過。** VWorld 同樣表現一致，可合理推測**所有白標皆然**（建議跑跨白標 audit 驗證）。

---

## 3. 代碼證據

### 3-1 寫入端：完全缺席

`app/Services/OrderService.php:81-98` `createOrder()` 的 `$data` array：

```php
$data = [
    'agent_cash_flow_id' => $agentCashFlow->id,
    'order_type_id'      => $orderType->id,
    'currency_id'        => $orderType->currency_id,
    ...$memberField,
    'amount'  => $amount,
    'rate'    => $agentCashFlow->rate ?? 1,
    'tax'     => $tax,
    'point'   => $point,
    'balance' => 0,
    'callback' => Common::depositCallbackUrl($agentCashFlow->id),
    'status'  => 0,
    'ip'      => Common::getIp(),
    'third_party_order_no' => '',
    'extra_content' => $extraContent ? json_encode($extraContent) : null,
    'remark' => '',
    'admin'  => 0,
];
```

**`cash_flow_id` 從未出現在 array 中。** 注意：`Order` model 的 `$guarded` 僅涵蓋 `id` / `created_at` / `updated_at`（`app/Models/Order.php:69-73`），**並不會**阻擋 `cash_flow_id` 的 mass assignment——欄位恆為 NULL 純粹是因為所有建立邏輯都未傳入該欄位。

全 codebase 搜尋寫入動作（`Order::create` / `update` 中含 `cash_flow_id`）= 0 筆。

### 3-2 讀取端：有依賴，但因值為 NULL 而靜默失效

| 檔案:行 | 內容 | 影響 |
|---|---|---|
| `app/Http/Controllers/Frontend/DepositController.php:331` | `if ($order->cash_flow_id == CashFlowEnum::DEPOSIT_VG_PAY) { ... }` | **功能性 bug**：VG Pay deposit 永遠走不到該分支，`deposit_url` 永遠為空字串 |
| `app/Services/Payment/Traits/DepositCallbackTrait.php:58` | `'cash_flow_id' => $order->cash_flow_id` 寫入 `Log::withContext` | log 永遠記 null，缺少有用診斷資訊 |
| `app/Console/Commands/Order/CheckDepositTaxApplied.php`（已修） | 命令本身已改為透過 `agent_cash_flow_id → ACF.cash_flow_id` 反查 | — |

---

## 4. 推測的設計意圖

`Order` 表已有 `agent_cash_flow_id`，可透過 JOIN 反查到 `cash_flows.id`：

```
orders.agent_cash_flow_id → agent_cash_flows.id → agent_cash_flows.cash_flow_id → cash_flows.id
```

`orders.cash_flow_id` 的存在最合理的解釋是：**denormalization 用空間換時間**，希望查詢時可以省掉一次 JOIN，直接 `orders.cash_flow_id = X`。

**但寫入端從未實作，使這個設計目標完全沒達成。**

---

## 5. 已發現的實際影響

### 5-1 確定 bug：VG Pay deposit_url 失效

`DepositController.php:331` 的條件式 `$order->cash_flow_id == CashFlowEnum::DEPOSIT_VG_PAY` 永遠為 false。所以無論這個訂單實際是不是 VG Pay，`deposit_url` 永遠是空字串。

需要評估：
- VG Pay 是否仍是 active provider？
- 若是，使用者目前怎麼拿到 deposit_url？是不是繞了別的路徑（例如三方直接 redirect）？
- 若繞了別路徑，這段條件式可能本來就沒人會用到（dead code）；若沒繞，則前端可能少了某個 UI 元素而沒人注意。

### 5-2 診斷資訊不全

`DepositCallbackTrait::findAndLockDepositOrder` 在 Log context 寫入的 `cash_flow_id` 永遠是 null，導致 callback 失敗的日誌排查少了一個有用過濾欄位。

### 5-3 潛在風險：未來開發誤用

任何新代碼若寫成 `Order::where('cash_flow_id', X)` 都會**靜默回 0 筆**，而不是 error。這是個容易踩的坑——欄位看似存在、查詢無語法錯，但結果永遠空集合。

---

## 6. 處理選項

### A. 補上寫入端，讓 schema 設計完整

**做法**：
1. 在 `OrderService::createOrder` 加入 `'cash_flow_id' => $agentCashFlow->cash_flow_id`
2. 寫 backfill migration / 一次性 artisan command，把歷史 `orders.cash_flow_id` 從 `agent_cash_flows.cash_flow_id` 反查回填
3. 修復 `DepositController.php:331` 的 bug（一旦 cash_flow_id 有值就會生效）
4. 評估是否補 index `orders(cash_flow_id)` 以兌現「省 JOIN」收益

**優點**：
- 完成原始設計意圖
- 修復 VG Pay bug 變副作用
- 為未來「依 cash_flow 統計」類查詢開出快速路徑
- Log 診斷資訊變完整

**缺點**：
- 1200 萬筆 backfill 要 lock table / 排期間 / 分批
- 加 index 也需 ALTER TABLE 時間
- 為一個 schema 設計補實作，工程量不小

### B. 接受為 dead column，移除欄位

**做法**：
1. 把所有讀取端改用 `$order->agentCashFlow->cash_flow_id`（透過關聯）
2. 修復 `DepositController.php:331` 的 bug 改成上述形式
3. 移除 `Log::withContext` 該欄位或改為透過關聯
4. Migration `DROP COLUMN orders.cash_flow_id`
5. 更新 model docblock

**優點**：
- 移除半成品欄位，schema 更誠實
- 短期工作量小（修 2-3 處讀取點 + drop column）
- 不需 backfill

**缺點**：
- 失去 denormalization 加速潛力（每次查詢都得 JOIN）
- ALTER TABLE DROP COLUMN 在 12M 行表上仍需謹慎排期

### C. 保留欄位但維持現狀（不寫不讀）

**做法**：
1. 只修復 `DepositController.php:331` 改用關聯
2. 其餘不動，欄位繼續維持永遠 NULL

**優點**：
- 工作量最小
- 不動 schema 不影響部署

**缺點**：
- 欄位繼續是「設計與實作脫節」的負債
- 未來開發者仍可能誤用 `Order::where('cash_flow_id', X)` 踩坑

---

## 7. 建議

**推薦 A**（補上寫入端 + backfill），理由：

1. 設計意圖明確（denormalization），不是設計錯誤而是實作未完成——補完才是對歷史設計的尊重
2. `DepositController.php:331` 已是 production 中的 silent bug，修復方式應該朝「讓欄位真的可用」而不是「繞過欄位」
3. 後續若做相關報表 / 分群統計，省一次 JOIN 在大表上是有意義的性能差距
4. backfill 雖然要排期但是一次性的，做完後維護成本接近 0

**次選 B**（移除欄位）：如果評估 denormalization 收益不大（例如目前沒有「依 cash_flow 統計」的高頻查詢）、且想要降低 schema 複雜度，移除是更乾淨的選擇。

**不推薦 C**：留著但不修不補等同於把已知 bug 變成永久技術負債。

---

## 8. 待決議事項

下列項目需 PO / 相關 owner 拍板才能進入實作：

- [ ] 跨白標 audit：確認其他白標（VPlus33 / Yes2Win / Alfawin77 / Me77）是否也 100% NULL，避免有白標例外
- [ ] 選擇方向：A / B / C（建議 A）
- [ ] 若選 A：backfill 預計時間窗、是否允許線上 ALTER（加 index）、index 策略
- [ ] 若選 B：是否同意接受 schema 簡化以致失去 denormalization 潛力
- [ ] `DepositController.php:331` 的 VG Pay 流程現況是否仍 active？若 deposit_url 從沒生效過、玩家也沒投訴，可能是 dead code 可直接清掉
- [ ] 是否同時整理其他類似「schema 有欄位但寫入端沒實作」的 dead column（建議寫一個通用 audit command 做全表盤點）

---

## 附錄：發現過程

2026-05-22 撰寫 `order:check-deposit-tax-applied` 命令時，初版以 `orders.cash_flow_id IS NOT NULL AND cash_flows.type=1` 判斷代收訂單，結果撈到 0 筆——但已知今天有 26 筆 tax > 0 的 TngPin 訂單。改成透過 `agent_cash_flow_id → ACF.cash_flow_id` 反查後正常撈到。

進一步驗證 hypothesis「所有 orders.cash_flow_id 可能都是 NULL」，跨範圍掃描皆證實 100% NULL，從而確認此為**全域 dead column**而非「PIN 流程特例」。
