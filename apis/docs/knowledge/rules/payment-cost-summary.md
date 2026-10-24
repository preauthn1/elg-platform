---
type: Rule
id: rules/payment-cost-summary
title: 金流商手續費的寫入與彙總
description: 訂單上的金流商手續費何時寫定，系統怎麼每小時彙總給代理數據看板，以及重算與回補命令怎麼補數字。
domain: 金流
subdomain: 金流商手續費
tags: [金流, 報表, 金流商手續費, 支付商手續費, 支付成本, 支付手續費, 提款手續費, payment cost, 成本費率, 代理數據看板, 看板, dashboard, SR99, 充提明細表, 彙總, summary, 每小時排程, 重算, 回補, backfill, recalc, 代收, 代付, 人工出款, CCPayment, 服務費, 網路費]
entities:
  - command: summary:payment-cost
  - command: order:recalc-payment-cost-summary
  - command: order:backfill-payment-cost
  - command: withdrawal:resolve-payout
  - class: 'App\Services\Summary\PaymentCostSummaryService'
  - class: 'App\Services\Payment\Traits\WithdrawalCallbackTrait'
  - class: 'App\Models\WithdrawalOrder'
  - class: 'App\Models\AgentCashFlow'
  - class: 'App\Services\Payment\Providers\CCPayment\DepositCallback'
  - class: 'App\Services\Payment\Providers\CCPayment\WithdrawalCallback'
  - class: 'App\Services\Payment\Providers\CCPayment\CCPaymentTrait'
  - class: 'App\Services\PaymentService'
  - class: 'App\Services\Agent\AgentDashboardService'
  - table: withdrawal_orders
  - table: agent_cash_flows
evidence:
  - kind: code
    ref: /app/Services/Summary/PaymentCostSummaryService.php
    title: 金流商手續費彙總的唯一寫入者，排程與重算共用（先刪後重建、分桶規則）
    at: 2026-10-20
  - kind: code
    ref: /app/Console/Commands/Order/SummarizePaymentCost.php
    title: 每小時排程 summary:payment-cost，回看前 3 個整點小時
  - kind: code
    ref: /routes/console.php
    title: 排程時間 hourlyAt(20)、onOneServer
  - kind: code
    ref: /app/Console/Commands/Order/RecalcPaymentCostSummary.php
    title: 重算命令 order:recalc-payment-cost-summary，台北日曆日換成 UTC 區間
  - kind: code
    ref: /app/Console/Commands/Order/BackfillPaymentCost.php
    title: 回補命令 order:backfill-payment-cost，只補值為 0 的已成功訂單
  - kind: code
    ref: /app/Services/Payment/Traits/WithdrawalCallbackTrait.php
    title: 代付成功時寫入取款訂單的金流商手續費與付款時間
  - kind: code
    ref: /app/Models/WithdrawalOrder.php
    title: 取款訂單依實際出款金流商戶算金流商手續費（paymentCostVia）
  - kind: code
    ref: /app/Models/AgentCashFlow.php
    title: 金流商手續費公式（paymentCostFor），截到小數第 4 位
  - kind: code
    ref: /app/Console/Commands/Order/ResolvePayoutOrder.php
    title: 補結案命令定案代付成功時寫入金流商手續費
  - kind: code
    ref: /app/Services/Payment/Providers/CCPayment/DepositCallback.php
    title: CCPayment 充值寫入回查的服務費
  - kind: code
    ref: /app/Services/Payment/Providers/CCPayment/WithdrawalCallback.php
    title: CCPayment 代付寫入回查的網路費（networkFeeCost）
  - kind: code
    ref: /app/Services/Payment/Providers/CCPayment/CCPaymentTrait.php
    title: CCPayment 實際手續費換算（actualPaymentCost）
  - kind: code
    ref: /app/Services/PaymentService.php
    title: 充值上分時寫入金流商手續費，已有實際值時沿用（paymentCostOf）
  - kind: code
    ref: /app/Services/Agent/AgentDashboardService.php
    title: 代理數據看板成本區讀取彙總（支付手續費、提款手續費分兩項）
  - kind: code
    ref: http://example.invalid:1688/target/gffv2/api_agent/-/blob/master/app/Http/Controllers/Report2Controller.php
    title: SR99 充提明細表逐列讀訂單上的金流商手續費（getSr99）
  - kind: code
    ref: http://example.invalid:1688/target/gffv2/api_agent/-/blob/master/app/Http/Controllers/WithdrawalController.php
    title: 人工出款改成已付款時寫入金流商手續費
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2224
    title: 訂單新增金流商手續費欄位，代收建單／代付派款成功時寫入，附回補命令
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2238
    title: 代收金流商手續費改在回調成功時寫入、自助上分建單時寫入
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2225
    title: 彙總改直接加總訂單上的金流商手續費，修掉跨金流商戶任選費率
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2230
    title: 回補命令改索引取邊界並保留更新時間，新增重算命令
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2234
    title: 取款彙總改依付款時間單一分桶
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2233
    title: 彙總改 Laravel 每小時排程，重算與排程共用
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2352
    title: CCPayment 充值與取款訂單的金流商手續費改記回查的實際值
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4361
    title: SR99 增加支付商手續費欄位
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4369
    title: 支付成本彙總 SP 作廢，切換為 PHP 排程全站統一
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4430
    title: SR99、SR02 CCPayment 訂單顯示手續費
  - kind: code
    ref: /docs/手續費欄位與報表對照.md
    title: 會員手續費與金流商手續費的欄位與報表對照
related:
  - rules/deposit-order-fees
  - rules/provider-ccpayment
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
---

## 這個主題在講什麼

金流商手續費是平台為一筆代收或代付付給金流商的費用，由平台出錢，不影響會員入帳或實收。

- 每張充值訂單與取款訂單在成功的那一刻，把這筆費用算好、寫在訂單上，之後不再變動。
- 一般金流商用「金額乘以金流商戶的成本費率」算。CCPayment 例外，記的是向金流商回查到的實際值。
- 系統每小時把訂單上已寫好的金流商手續費加總成小時彙總，代理數據看板的成本區讀這份彙總。
- 「SR99 充提明細表」不讀彙總，每一列直接顯示該訂單上的值。
- 彙總算錯，或訂單上的值是後補的，用命令列的重算命令重建彙總。訂單上的值缺了，用回補命令補。

本份目前收錄的範圍：

- MR !2224、!2225、!2230、!2233、!2234 建立的取款訂單寫入時機、每小時彙總、重算與回補命令。
- MR !2352 建立的 CCPayment 例外。
- 看板與報表讀哪個值。

充值訂單的寫入時機由 MR !2238 建立，條文在 [rules/deposit-order-fees](deposit-order-fees.md)，本份只引用。分潤報表（顯示欄位「活動/支付成本」）怎麼算金流商手續費，不在本份。

## 怎麼觸發

- 每小時彙總：排程，每小時 20 分自動執行，沒有操作畫面。
- 重算與回補：命令列，由 RD 或維運在各站點容器內執行（重算命令、回補命令）。
- 看結果有兩處：
  - 代理後台 › 統計報表查詢 › 代理數據看板的成本區（「支付手續費」「提款手續費」兩張卡）。
  - 代理後台 › 統計報表查詢（待確認）› SR99 充提明細表的「支付商手續費」欄（新代理後台才有這一欄）。

## 規則

### 訂單上的金流商手續費何時寫定

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 充值訂單的金流商手續費：經金流商代收的充值訂單在上分那一刻寫入，見 [rules/deposit-order-fees#R-4](deposit-order-fees.md)、[rules/deposit-order-fees#R-5](deposit-order-fees.md)；自助上分的充值訂單在建單當下寫入，見 [rules/deposit-order-fees#R-10](deposit-order-fees.md)。本份的彙總與報表讀的就是這個值。 | code:PaymentService |
| R-2 | 取款訂單經代付成功、轉為「已付款(代付)」（狀態 24）時，系統寫入金流商手續費。金額是取款金額乘以入帳比例，再乘以實際出款的金流商戶當下的成本費率，截到小數第 4 位、不四捨五入，單位是錢包幣別。實際出款的金流商戶依序這樣取：先取這次回調所屬的代付紀錄；沒有對應紀錄時，取這張取款訂單最新一筆代付紀錄；都沒有時寫 0。同一刻若付款時間還沒有值，也一併記下付款時間。 | code:WithdrawalCallbackTrait、code:WithdrawalOrder::paymentCostVia |
| R-3 | 代付回調判定失敗，或結果不明而訂單維持「代付請求中」（21）或「代付處理中」（22）時，不寫金流商手續費。 | code:WithdrawalCallbackTrait |
| R-4 | 代付回調一直沒來，人工核對後以補結案命令把取款訂單定案為代付成功時，系統用 R-2 的算法寫入金流商手續費，出款的金流商戶取這張取款訂單最新一筆代付紀錄。CCPayment 的取款訂單也一樣用成本費率算，不讀網路費。 | code:ResolvePayoutOrder |
| R-5 | 客服在代理後台把取款訂單改成「已付款」（人工出款，狀態 4）時，系統寫入金流商手續費，金額是取款金額乘以入帳比例，再乘以該支付組別人工出款用的金流商戶的成本費率，取到小數第 4 位，同時記下付款時間。 | code:WithdrawalController（API_Agent） |
| R-6 | 取款訂單的金流商手續費寫入後不再變動。之後調整金流商戶的成本費率，只影響之後才成功的取款訂單，已寫入的取款訂單與已彙總的數字都不變（充值訂單同 [rules/deposit-order-fees#R-11](deposit-order-fees.md)）。 | code:AgentCashFlow::paymentCostFor |

### CCPayment 的例外

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-7 | CCPayment 的充值不是風險交易時，回調建單當下寫入金流商手續費，金額是回查到的服務費乘以該充值訂單的入帳比例，截到小數第 4 位、不四捨五入。上分時沿用這個值，不以成本費率覆寫（見 [rules/deposit-order-fees#R-7](deposit-order-fees.md)）。 | code:DepositCallback（CCPayment） |
| R-8 | 回查的服務費缺漏、不是數字或是負數時，CCPayment 充值照常上分並發告警，金流商手續費改用成本費率算法。回查的服務費剛好是 0 時，上分時也當成沒寫，同樣改用成本費率算法。 | code:CCPaymentTrait |
| R-9 | CCPayment 被標為風險交易的充值，建單時不寫金流商手續費，退款成功後由排程補記，見 [rules/provider-ccpayment#R-22](provider-ccpayment.md)。 | code:DepositCallback（CCPayment） |
| R-10 | CCPayment 代付成功時寫入金流商手續費，金額是回查到的網路費乘以該取款訂單的入帳比例，截到小數第 4 位、不四捨五入，不用成本費率算。網路費不論由會員還是平台負擔（見 [rules/provider-ccpayment#R-24](provider-ccpayment.md)）都照記，所以看板的「提款手續費」與 SR99 的「支付商手續費」會包含會員負擔的網路費。這是 PM 的決定。 | code:WithdrawalCallback（CCPayment）::networkFeeCost |
| R-11 | 回查的網路費缺漏、不是數字、是負數，或網路費的幣和出款的幣不同時，CCPayment 代付照常定案為成功並發告警，金流商手續費改用 R-2 的成本費率算法。 | code:CCPaymentTrait |

### 每小時彙總

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-12 | 系統每小時 20 分自動重建前 3 個整點小時的金流商手續費彙總，充值與取款各一份，範圍是全站會員。小時以 UTC 整點為界，正在進行的這一小時不算在內。同一時間只在一台伺服器上執行。 | code:SummarizePaymentCost、code:console.php |
| R-13 | 彙總直接加總訂單上已寫好的金流商手續費，不重新套成本費率。同一會員同一小時走了兩個以上的金流商戶時，各筆用各自的成本費率，不會整小時套用其中一個。 | code:PaymentCostSummaryService |
| R-14 | 充值只計「已上分」的充值訂單，依上分時間歸到所屬小時。取款只計「已付款」（狀態 4）與「已付款(代付)」（狀態 24）的取款訂單，依付款時間歸到所屬小時。待審核的自助上分、失敗、退回或還在代付中的訂單都不計入。 | code:PaymentCostSummaryService |
| R-15 | 每次重建先刪掉時間範圍內的舊彙總再重寫，同一時間範圍重跑結果相同。時間範圍以外的小時不受影響。 | code:PaymentCostSummaryService |
| R-16 | 排程只回看 3 小時。更早小時的訂單之後才補上或改了金流商手續費時（例如跑了回補命令），彙總不會自己更新，要用 R-17 的重算命令重建該區間。 | code:SummarizePaymentCost |

### 重算命令

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-17 | 執行重算命令時指定起日與迄日（台北時間的日曆日，迄日含當天，不填迄日就是今天）。系統把區間換成 UTC，對該區間用與排程相同的規則先刪後重建。區間的結束時間最晚到現在這一個整點。 | code:RecalcPaymentCostSummary |
| R-18 | 重算可以只做充值、只做取款或兩者都做（預設兩者），也可以限定某個代理線（該代理與其各層下級）以下的會員（預設全站）。 | code:RecalcPaymentCostSummary |
| R-19 | 預覽模式只列出換算後的區間與將刪除的會員彙總筆數，不刪也不寫。 | code:RecalcPaymentCostSummary |
| R-20 | 沒填起日、類型不是充值、取款或兩者、迄日早於起日，或指定的代理不存在時，命令直接失敗，不動任何彙總。 | code:RecalcPaymentCostSummary |

### 回補命令

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-21 | 執行回補命令時，系統找出最近 N 個月（預設 6 個月，依建單時間）內金流商手續費仍是 0 的已上分充值訂單與已付款取款訂單，補上金流商手續費。已經有值的訂單不覆寫，所以可以重跑。可以只補充值或只補取款。 | code:BackfillPaymentCost |
| R-22 | 回補的算法如下，都截到小數第 4 位：充值是入帳點數乘以該充值訂單金流商戶的成本費率；代付成功的取款是取款金額乘以入帳比例，再乘以最新一筆成功代付紀錄的金流商戶的成本費率；人工出款的取款改乘該支付組別人工出款用的金流商戶的成本費率。成本費率是 0 的訂單不動。 | code:BackfillPaymentCost |
| R-23 | 回補套用的是執行當下的成本費率，不是訂單成功當時的。 | code:BackfillPaymentCost |
| R-24 | 回補不會改動訂單的最後更新時間，所以不會被當成一次訂單異動。 | code:BackfillPaymentCost |
| R-25 | 回補的預覽模式只列出待處理的範圍，以及第一批訂單裡有幾筆待回補，不寫入。 | code:BackfillPaymentCost |
| R-26 | 回補只改訂單上的值，不更新彙總。回補過的區間要接著跑重算命令，看板的數字才會跟上（見 R-16）。 | code:BackfillPaymentCost |

### 看板與報表讀哪個值

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-27 | 代理數據看板的成本區把金流商手續費分成兩張卡：「支付手續費」是充值的彙總，「提款手續費」是取款的彙總，兩者不合併。數字來自 R-12 的小時彙總，不直接讀訂單。 | code:AgentDashboardService |
| R-28 | 因為看板讀的是小時彙總，正在進行的這一小時成功的訂單，要等下一個整點過後 20 分的排程跑完才會算進看板。 | code:AgentDashboardService |
| R-29 | 「SR99 充提明細表」的「支付商手續費」，每一列在查詢當下直接顯示該訂單上的金流商手續費，不經彙總。充值列是充值訂單上的值，取款列是取款訂單上的值。同一張表的「手續費」欄是會員手續費，取款列固定顯示 0。 | code:Report2Controller（API_Agent） |

## Examples

以下情境的前提：

- 金流商戶 A 成本費率 1%、金流商戶 B 成本費率 0%、金流商戶 C 成本費率 2%。
- 支付組別人工出款用的金流商戶，成本費率 2.5%。
- 入帳比例都是 1，時間都是台北時間。

| 情境 | 結果 | 依據 |
|---|---|---|
| 同一會員 20:00 到 21:00 之間在 A 上分 1,000、在 B 上分 300 | 該小時充值彙總 10（不是 13） | R-1、R-13 |
| 取款 1,000 經 C 代付成功 | 取款訂單金流商手續費 20 | R-2 |
| 取款 200 由客服人工出款 | 取款訂單金流商手續費 5 | R-5 |
| 代付回調失敗 | 金流商手續費維持 0，不進彙總 | R-3、R-14 |
| 12:30 上分一張充值訂單 | 歸到 12:00 那一小時；13:20 的排程跑完後才出現在看板 | R-12、R-14、R-28 |
| 一張金流商手續費 5、一張 1.5 的取款，付款時間都在 13 點這一小時 | 該小時取款彙總 6.5 | R-14 |
| 自助上分 1,000 待審核 | SR99 那一列的「支付商手續費」有值，看板不計 | R-14、R-29、[rules/deposit-order-fees#R-10](deposit-order-fees.md) |
| CCPayment 代付，網路費 1 USDT 由會員負擔，入帳比例 32 | 取款訂單金流商手續費 32，算進「提款手續費」 | R-10、R-27 |
| 上月一張金流商手續費為 0 的已上分 100.5 充值，跑回補時 A 的成本費率是 2% | 補成 2.0100；把成本費率改成 9% 再跑一次，仍是 2.0100 | R-21、R-23 |
| 回補完上月資料，看板上月數字沒變 | 對上月跑重算命令後才更新 | R-16、R-26 |
