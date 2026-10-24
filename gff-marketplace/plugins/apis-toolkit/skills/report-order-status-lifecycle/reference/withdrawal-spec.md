# 代付訂單 status 生命週期 — 內容規格

**輸出**：`docs/reports/withdrawal-status-lifecycle-report.html`（**正式版・線上現況**）
**外殼**：`template.html`，sidebar `logo=money-out SVG brand=Payout Flow`，`foot=withdrawal_orders.status`，
（brand 與 menu 圖示一律用 template 註解的線性 SVG，**不用 emoji**；menu 依序 list / cycle / zap / tool）
`crumb=Reports / Payment / 代付訂單生命週期`，`h1=代付訂單 status 生命週期`，
`chips=<svg class="ni"><use href="#i-cal"/></svg> {date} · APIs L13 + API_Agent L5.5`。
（版本實據：APIs `php-fpm8` 實測 PHP 8.4；API_Agent `_release/composer.json` 宣告 `laravel/framework: 5.5.*`、lock 實裝 v5.5.45，`php-fpm` 容器實測 PHP 7.3.33。）

> **範疇**：純粹「狀態生命週期」——有哪些狀態、怎麼流轉、在哪寫入。
> **守衛的「集合」屬流轉事實，要寫；守衛的「強弱分析」不寫。**
> 入口守衛決定了哪些來源狀態能流向目標狀態（例：`checkWithdrawalOrderStatus` 的放行集合），
> 這類集合必須反映在狀態機與明細表，否則圖會漏邊。至於「這道防線夠不夠、能不能被繞過、
> 冪等是否成立」的評估，仍留給 `report-payment-audit`。產製時不要新增守衛/冪等章節。

> ⚠️ **不要動 `docs/reports/withdrawal-status-lifecycle-target-flow.html`**——那是 2026-08 代付事務邊界
> 改造的**會議紀錄（歷史備查）**，人工撰寫、內文刻意保留當時時點，**不由本 skill 產製、重跑時不得覆蓋**。

## 狀態 enum（事實，渲染前用 grep 對齊現值）

`app/Enums/WithdrawalOrderStatusEnum.php`（int backed）：

| 值 | case | 語意 | 終態 |
|---|---|---|---|
| 0 | `ORDER_CREATED` | 訂單建立 | — |
| 1 | `PENDING_REVIEW` | 待審核（會員送出後預設） | — |
| 2 | `FAILED` | 失敗/拒絕（退還金幣） | ✓ |
| 3 | `APPROVED` | 通過，可發起代付 | — |
| 4 | `TRANSFERRED` | 已轉帳（人工/自助洗分） | 非終態 |
| 9 | `REFUND` | 退款（退還金幣） | ✓ |
| 21 | `PAYMENT_SUBMITTED` | 代付提交中（#4137 後為結果未知的**常駐停留態**，含 attempt token） | — |
| 22 | `PAYMENT_PROCESSING` | 代付處理中（三方受理等回調） | — |
| 23 | （enum case 已移除） | 已退役，禁止重用：原「三方查證中」，#4137 廢除；六站存量 2026-09-16 實查歸零後移除 case。舊系統後台仍有 23 的處理端點，歷史 `change_logs` 也會出現 23 | 不適用（線上訂單不再出現） |
| 24 | `THIRD_PARTY_SUCCESS` | 三方成功（唯一冪等視為已處理） | ✓ |
| 29 | `THIRD_PARTY_FAILED` | 三方失敗（**非終態**，可再次發起代付；#4137 起已移出回調放行集合） | 非終態 |

stat 卡：`10 列舉狀態值 / 21 追蹤時機（5 不轉態） / 2 寫入系統來源 / 3 終態(24/2/9) / 2 非終態(4·29)`。

> ⚠️ **0 目前無任何寫入點**：`WithdrawalOrder::create` 直接寫 1，舊系統無建單路徑。表格照列，說明欄標明。
> ⚠️ **真終態只有 24 / 2 / 9**。4 可 4→3 退回、29 可再發起代付，
> 兩者一律標「非終態」，不可標 ✓。
卡頭 `.avatar` 圖示用線性 SVG（依序 hash / zap / database / check-circle / alert-triangle），不用 emoji。

## ⚠️ #4137 造成的檔案位移（2026-08-20 起，重產必讀）

工單 `target/tickets#4137`（代付事務邊界改造，源自 op_tickets#2831）**把代付業務整段下沉**，
舊版報告的錨點已全部失效：

| 舊位置 | 現位置 |
|---|---|
| `app/Http/Controllers/Agent/WithdrawalController.php`（451 行，含全部代付邏輯） | `app/Services/WithdrawalPayoutService.php`（585 行）；controller 瘦身為 **141 行**，只剩入口 |
| `app/Console/Commands/Order/ResolveVerifyingWithdrawalOrder.php` | `app/Console/Commands/Order/ResolvePayoutOrder.php`（更名，舊命令名留 alias） |
| （無） | 新增 `WithdrawalPayoutManualService.php`、`WithdrawalPayoutQueryService.php`、`Schedule/AlertStuckSubmittedWithdrawal.php` |

## 來源檔與追蹤錨點（重產時 grep 取當前 file:line）

| 用途 | 檔案 | grep 錨點 |
|---|---|---|
| 會員送出申請 status=1 | `app/Http/Controllers/Frontend/WithdrawalController.php` | `WithdrawalOrder::create`（`->create([` 呼叫行）、`'status' => 1` |
| 發起代付 →21（第一階段短事務＋attempt token） | `app/Services/WithdrawalPayoutService.php` | `allowedStatuses`(=[APPROVED,THIRD_PARTY_FAILED])、`PAYMENT_SUBMITTED`(→21 update 呼叫行，同筆寫 `'attempt:'.Str::uuid()`) |
| 發起前擋下舊系統範疇的通道（不轉態，target/tickets#4433） | 同上 | `submit()` 內的 `CashFlowScopeEnum::isHandledByApis` 判斷行；不歸 APIs 處理的通道直接回錯誤，寫進發起代付那一列的觸發說明 |
| 結果未知 → **停留 21 不轉態** | 同上 | `isIndeterminate`——取告警行與 `return` 行，**不是** update 行（此路徑不寫 status） |
| 同步明確拒絕 →29 | 同上 | `THIRD_PARTY_FAILED`(→29 update 呼叫行，同時清空 `third_party_response`) |
| 三方受理 →22 | 同上 | `PAYMENT_PROCESSING`(→22 update 呼叫行) |
| 讓路（不轉態） | 同上 | `isStaleAttempt`／`isNewerAttemptPresent`／`isResubmittedAfterAttempt`、`already resolved` 分支；另有 `isManualMisMarkedFailed` 拉回 22 的例外 |
| 人工定案 21→22 / 21·22→29 | `app/Services/WithdrawalPayoutManualService.php` | `fillThirdPartyOrderNo`／`markFailed` 的 `->update([` 呼叫行、狀態守衛行、3 分鐘 in-flight 窗 |
| 人工接口入口 | `app/Http/Controllers/Agent/WithdrawalController.php` | `fillThirdPartyNo`／`markFailed` 方法行（僅入口，業務在 Service） |
| 查單擴充點 21→22 | `app/Services/WithdrawalPayoutQueryService.php` | `tryResolveSubmitted`、`FOUND_ACCEPTED`、`->update([`。⚠️介面 `SupportsPayoutQuery` 目前**無 provider 實作**，報告須標「尚未接入」 |
| 停留單掃描（不轉態） | `app/Console/Commands/Schedule/AlertStuckSubmittedWithdrawal.php` ＋ `routes/console.php` | `alert-stuck-submitted`、`PAYMENT_SUBMITTED` 查詢行、排程註冊行與頻率 |
| 回調成功/失敗寫入 | `app/Services/Payment/Traits/WithdrawalCallbackTrait.php` | `updateWithdrawalOrderStatus`、`$order->update($updateData)` 呼叫行、`INDETERMINATE`（提前 return 不寫）、`isStalePayoutRecord`（歸屬守衛不寫） |
| **回調放行集合**（決定來源狀態） | `app/Services/Payment/Traits/WithdrawalPayoutTrait.php` | `checkWithdrawalOrderStatus`：逐條讀 `return` 與 `throw` 分支。**現為 21 / 22 放行，29 與其他狀態（含已退役的 23）一律拒絕並告警**；寫進狀態機圖與明細表的來源欄 |
| 兜底命令 | `app/Console/Commands/Order/ResolvePayoutOrder.php` | `withdrawal:resolve-payout`、`$aliases`、`RESOLVABLE_STATUSES`、`$locked->update(` 呼叫行 |
| 24 側效（非狀態寫入） | `app/Observers/WithdrawalOrderObserver.php` | `wasChanged('status')`——首次轉 24 觸發會員小時匯總 |
| 舊審核 reviewOrder（1→2/3/9/4） | `API_Agent/_release/app/Http/Controllers/WithdrawalController.php` | `function reviewOrder`、`$data['status'] = $request->status`、**5 道**前置守衛錯誤碼 200201/200204/**200205**/200202/200203、退還金幣分支、`change_logs`、`$order->update($data)`；→4 另寫 `payment_cost` 與 `touchLastWithdrawAt`，尾段寫 `AgentOperationLogRepository` 操作紀錄 |
| 舊發起代付（→21/22/還原） | `API_Agent/_release/.../WithdrawalPayoutController.php` | `availableStatus`、`UpdateOrderStatusToWaitingRequest`(21)／`ToThirdPartyOnProcess`(22)／`ToReviewConfirm`(還原) 的 `->update(` 呼叫行 |
| 舊 23 定案兩端點（工單 #4090，**舊系統仍保有端點；APIs 已退役 23、線上已無 23 訂單**） | `API_Agent/_release/.../WithdrawalPayoutController.php` | `editVerifyingPayoutTransactionNo`（23→23，補三方單號雙寫、狀態刻意不動）、`markVerifyingPayoutAsFailed`（23→29，清空三方單號） |

> ⚠️ 「兩者」列同時引新舊兩個 file:line。
> ⚠️ 引用標 `->update([` / `::create([` **呼叫行**，非 enum 賦值行。
> ⚠️ **舊系統行號易大幅位移**：#4090 一次就在 `WithdrawalPayoutController` 插入 224 行；#4137 的 21 守衛
> 又在 `WithdrawalController` 插入一段。每次重產務必重新 grep，不可沿用前版。
> ⚠️ **API_Agent 有多條站台分支**：報告以 `master` 為準，重產時順手比對 `master` 與某支 prod 分支行號是否一致
> （2026-08-20 實測 master 與 vw-master 全同）。
> ⚠️ **reviewOrder 的四分支歸屬易標錯**：`request.status==4` / `order.status!=1 && request.status==3`
> （＝22·4→3 的回退，洗分 type 5 改 9）/ `request.status==2`（改 -9）/ `else`（僅更新
> operator·admin，→9 退款落此）。標副作用前先確認落在哪一支。**21→3 已不存在**（200205 擋下）。

## 章節（4 段）

1. `#states` 狀態值總覽（WithdrawalOrderStatusEnum）— 上表。重點：21→22→24/29 主流程；**#4137 三項變更**（23 廢除、明確拒絕一律 29、29 移出放行）寫進 desc。
2. `#lifecycle` 生命週期狀態機：**`.fchart` 節點圖**（非 ASCII）：spine [1 待審核]→(審核)→分支[3 通過][2 失敗 term][9 退款 term]→(發起代付)→[21]→(受理/拒絕)→[22]→(回調／人工定案)→分支[24 成功 term][29 失敗]。`.fnotes` 旁註必含：**21 的四個出口**（回調／填寫三方單號→22／修改為代付失敗→29／兜底命令）＋排程掃描只告警、**兩階段事務與 attempt token**（op#2831 根治法）、**結果未知＝不轉態**、**明確拒絕一律 29**（舊系統仍還原，行為分歧）、**29 已移出放行**（推翻 `fa73933ad`）、查單擴充點、後台回退→3 的實際副作用。終態節點只有 24/2/9 加 `.term`。
3. `#triggers` 變更時機明細：**21 列表格**（含 5 條不轉態：結果未知停留 21、讓路、回調 INDETERMINATE／歸屬守衛、排程掃描、舊後台補單號留 23，舊系統端點仍在、線上已無 23 訂單），欄位同代收（#/時機/狀態變更/系統/寫入位置·觸發）。**寫入位置與觸發合併單欄**（`.mono` 行號在上、`.trig` 描述在下堆疊）；時機＋狀態變更欄 nowrap。badge：APIs(b-primary)/舊(b-warning)/兩者(b-info)。回調列的來源狀態欄須列 **21/22**。末尾加 **#4137 前後對照** callout（事務邊界／結果未知承接／明確拒絕／放行集合／人工定案位置／兜底六項）。
4. `#review` 舊後台審核 reviewOrder 深入：前置守衛表（200201/200204/**200205(21 一律拒絕)**/200202/200203 樂觀鎖，共 **5** 道）+ 各目標狀態副作用（1→3 通過扣流水、1→2 拒絕退幣、3/29→9 退款、3/29→4 已轉帳）`.risk` 卡 + 共同尾段 callout（change_logs / `$order->update($data)` 唯一寫入 / CustomerAuditTime）。**須註明 200205 的部署時序**（APIs #4137 六站上線在前、守衛補上在後，風險窗已關閉）與**舊系統另有 #4090 兩支 23 專用端點（線上已無 23 訂單）、不走這五道 guard**。

footer：`代付訂單 status 生命週期報告 · 產生於 {date} · 資料源：APIs（Frontend WithdrawalController、WithdrawalPayoutService、WithdrawalPayoutManualService、WithdrawalPayoutQueryService、WithdrawalCallbackTrait、WithdrawalPayoutTrait、ResolvePayoutOrder、AlertStuckSubmittedWithdrawal）+ API_Agent（reviewOrder、WithdrawalPayoutController 含工單 #4090 兩端點）原始碼追蹤`。

## 已知易錯點（2026-08-20 依 #4137 上線後實測重寫，重產時逐條複驗）

1. **29 不在回調放行集合內**（#4137 決議，2026-08-20 上線）。現行 `checkWithdrawalOrderStatus` 對 29 **拒絕並告警**，理由是 29 允許重新發起、放行補送回調會與新一輪發起產生競態。重產時**以 code 為準**，不要照抄任何一版敘述。
2. **23 已退役**：#4137 廢除後無任何新增寫入點（`isIndeterminate` 改為停留 21）；六站存量 2026-09-16 實查歸零後，APIs `38d00e35d` 移除 enum case，回調守衛與兜底命令 `withdrawal:resolve-payout` 都不再放行 23（出現即走「其餘狀態」告警並拒絕）。**23 禁止重用**：舊系統 API_Agent 仍保有 #4090 兩支 23 端點，歷史訂單的 `change_logs` 也會出現 23。報告寫「已退役」，不寫「存量仍可定案」。
3. **結果未知是「不轉態」不是「轉某狀態」**：這條路徑沒有 `->update`，錨點取告警行與 `return` 行。誤標成 update 行會產生不存在的邊。
4. **回調有第三態**：`INDETERMINATE` 提前 return 不動 status；另有歸屬守衛（stale payout record）也不寫。兩者都要列進明細但標「不轉態」。
5. **簽名別沿用舊版**：現為 `updateWithdrawalOrderStatus($order, $outcome, $payoutRecord)`，不是 `($order, true/false)`。
6. **新舊系統行為已分歧**：同步明確拒絕時 APIs 一律轉 29、舊 API_Agent 仍還原發起前狀態。不可用「兩者」badge 併成一列，要拆兩列。
7. **32 個 provider 全走同一條回調鏈**（AntzPay、TtPay 為空殼，分別繼承 Kira、DgPay），可放心以 trait 為單一追蹤點。重產時用 `ls app/Services/Payment/Providers/*/WithdrawalCallback.php | wc -l` 重數。
