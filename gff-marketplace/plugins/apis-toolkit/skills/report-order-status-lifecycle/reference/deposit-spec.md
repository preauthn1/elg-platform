# 代收訂單 status 生命週期 — 內容規格

**輸出**：`docs/reports/deposit-status-lifecycle-report.html`
**外殼**：`template.html`，sidebar `logo=money-in SVG brand=Deposit Flow`，`foot=orders.status`，
（brand 與 menu 圖示一律用 template 註解的線性 SVG，**不用 emoji**；menu 依序 list / cycle / zap / bank）
`crumb=Reports / Payment / 代收訂單生命週期`，`h1=代收訂單 status 生命週期`，
`chips=<svg class="ni"><use href="#i-cal"/></svg> {date} · APIs L13 + API_Agent L5.5`。

> **範疇**：純粹「狀態生命週期」——有哪些狀態、怎麼流轉、在哪寫入。
> **守衛與冪等規則不屬本報告**（如「回調只認 CREATED」「上分前再驗 SUCCESS」「只認 24 視為已處理」這類防線分析）——那是 `report-payment-audit` 拿本報告當輸入去排查的結果。產製時不要新增 §5 守衛/冪等章節。

## 狀態 enum（事實，渲染前用 grep 對齊現值）

`app/Enums/OrderStatusEnum.php`（int backed，代收與線下訂單共用）：

| 值 | case | 語意 | 終態 |
|---|---|---|---|
| 0 | `CREATED` | 建立訂單，唯一可被回調修改 | — |
| 1 | `SUCCESS` | 建立成功，**未上分**（鎖分模式停此） | — |
| 2 | `FAILED` | 失敗 / 審核不通過 | ✓ |
| 3 | `DEPOSIT_FINISHED` | 已上分（活動/流水/報表以此為準） | ✓ |
| 4 | `WAITING_MANUAL_DEPOSIT` | 金額不符轉人工 | 待人工 |

stat 卡：`5 列舉狀態值 / 11 狀態變更時機 / 2 寫入系統來源 / 2 終態(3/2) / 3 回調分支(1/4/2)`。
卡頭 `.avatar` 圖示用線性 SVG（依序 hash / zap / database / check-circle / git-branch），不用 emoji。

## 來源檔與追蹤錨點（重產時 grep 取當前 file:line）

| 用途 | 檔案 | grep 錨點 |
|---|---|---|
| 建單 status=0 | `app/Services/OrderService.php` | `function createOrder` / `'status' => 0` |
| 自助開分（`CashFlowTypeEnum::AUTO_DEPOSIT`：會員轉帳後上傳截圖、後台審核）建單 / 上傳截圖 / 取消 | `app/Http/Controllers/Frontend/AutoDepositController.php` | `createOrder` 的 `Order::create`（→0）、`uploadImage` 的 `$order->update`（→1 待審核）、`deleteOrder` 與 `bankAccounts` 的 `update`（→2）。跟 USDT、鏈上確認無關 |
| 回調成功/金額不符/失敗 | `app/Services/Payment/Traits/DepositCallbackTrait.php` | `checkDepositOrderStatus`（只認 CREATED）、`numCompare`（→4）、`updateDepositOrderSuccess`（`$order->update`）、`updateDepositOrderFailed` |
| 回調入口 + 上分觸發 | `app/Http/Controllers/External/CashFlow/DepositNotifyController.php` | `depositCallback`、`status === OrderStatusEnum::SUCCESS->value`、`processSuccessfulDeposit`、`notifyLockMode` |
| 上分寫 status=3 | `app/Services/PaymentService.php` | `updateMemberBalance` / `updateAgentBalance` / `DEPOSIT_FINISHED`（會員+代理各一處）、`lockForUpdate` |
| 自助開分審核（舊，只收 status=1 的單） | `API_Agent/_release/app/Http/Controllers/OrderController.php` | `function reviewOrder`：通過走 `CashFlowService::updateMemberBalance` / `updateAgentBalance`（→3），其餘 `$order->status = $request->status` 後 `save()` |
| 手動上分（舊，status=3 以外都可） | 同上 | `function manualDeposit`：先 `$queryOrder->update([` 寫 1，再 `updateMemberBalance` / `updateAgentBalance`（→3） |
| 重置狀態（舊，0 與 3 以外） | 同上 | `function resetStatus`：`$queryOrder->update([` 寫 0 |

> ⚠️ 引用一律標 `->update([` / `::create([` **呼叫行**，非 enum 賦值行（兩者常差數行）。

## 章節（4 段，section id 對應 menu）

1. `#states` 狀態值總覽（OrderStatusEnum）：上表。重點 callout：回調成功只到 1(未上分)，加值後才 3。
2. `#lifecycle` 生命週期狀態機：**`.fchart` 節點圖**（非 ASCII）：`.frow` 放狀態節點 `.fnode.s{N}`、`.fconn` 連接（帶 `.fc-l` 標籤）、`.fbranch` 分支標籤列、`.fnotes > .fnote` 旁註。流程：[0 建立]→(三方回調)→分支[1 成功][4 待人工][2 失敗 term]→(上分)→[3 已上分 term]；旁註含鎖分模式停 1、自助開分（上傳截圖到 1，再由舊系統審核到 3 或 2）、4→3 走舊系統手動上分、舊系統可重置回 0。終態節點加 `.term`。
3. `#triggers` 變更時機明細：11 列表格（APIs 8 列、舊系統 3 列：審核、手動上分、重置狀態），欄位 `#/時機/狀態變更/系統/寫入位置(file:line)·觸發`。**寫入位置與觸發合併為單欄**：`.mono` 行號在上、`<span class="trig">` 觸發描述在下堆疊；時機＋狀態變更欄 `white-space:nowrap`（`#triggers` 的 nth-child 2/3，定義於 template）。badge：APIs(b-primary) / 舊(b-warning)。
4. `#credit` 上分機制 1→3：觸發鏈 4 步 `ul.clean` + 連帶寫入清單（TransactionLog / FlowLimit / VipFlowLimit / SP_event_deposit）+ 代理vs會員 callout。

footer：`代收訂單 status 生命週期報告 · 產生於 {date} · 資料源：APIs（OrderService、DepositCallbackTrait、PaymentService、DepositNotifyController、AutoDepositController）+ API_Agent（OrderController 的 reviewOrder、manualDeposit、resetStatus）原始碼追蹤`。
