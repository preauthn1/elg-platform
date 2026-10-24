---
type: Rule
id: rules/deposit-order-fees
title: 充值訂單的兩種手續費
description: 會員經金流商充值或自助上分建單時，訂單上記哪兩種手續費、何時記、金額怎麼算。
domain: 金流
subdomain: 會員儲值（上分）
tags: [金流, 代收, 充值, 充值訂單, 存款, 儲值, deposit, 自助上分, 自助開分, auto deposit, 會員手續費, 手續費, 金流商手續費, 支付商手續費, 支付成本, payment cost, 成本費率, 入帳點數]
entities:
  - api: POST frontend/api/auto-deposit/bank-accounts
  - api: POST frontend/api/auto-deposit/order/create
  - api: POST frontend/api/auto-deposit/order/delete
  - api: POST frontend/api/auto-deposit/order/upload-image
  - class: App\Services\PaymentService
  - class: App\Services\OrderService
  - class: App\Models\AgentCashFlow
  - class: App\Services\Payment\Providers\Ap8Pay\DepositCallback
  - class: App\Services\Payment\Providers\TngPin\DepositCallback
evidence:
  - kind: code
    ref: /app/Services/PaymentService.php
    title: 入帳成功時寫入金流商手續費（updateMemberBalance、updateAgentBalance、paymentCostOf）
    at: 2026-10-20
  - kind: code
    ref: /app/Http/Controllers/Frontend/AutoDepositController.php
    title: 自助上分建單，建單時寫入金流商手續費
  - kind: code
    ref: /app/Services/OrderService.php
    title: 經金流商充值建單，算會員手續費與入帳點數，金流商手續費不在此寫入
  - kind: code
    ref: /app/Models/AgentCashFlow.php
    title: 金流商手續費公式（paymentCostFor）
  - kind: code
    ref: /app/Services/Payment/Providers/Ap8Pay/DepositCallback.php
    title: 回調依實付金額改寫點數
  - kind: code
    ref: /app/Services/Payment/Providers/TngPin/DepositCallback.php
    title: 回調依實付金額重算會員手續費與點數
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2238
    title: 代收 payment_cost 改在回調成功時寫入、自助上分建單時寫入
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4361
    title: SR99 增加支付商手續費欄位
  - kind: code
    ref: /docs/手續費欄位與報表對照.md
    title: 會員手續費與金流商手續費的欄位與報表對照
related:
  - rules/agent-self-deposit
  - rules/payment-cost-summary
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
---

## 這個主題在講什麼

會員在會員 App 充值有兩條路：

- 經金流商代收：會員選支付通道、填金額送出，系統建一張充值訂單。會員到金流商付款，金流商回調成功後，點數才加進錢包。
- 自助上分：會員把錢轉到平台公布的銀行帳戶，系統建一張待審核的充值訂單，由客服在代理後台審核後入帳。

每張充值訂單上記兩種手續費，兩者互不影響：

| 手續費 | 誰出錢 | 對會員入帳的影響 |
|---|---|---|
| 會員手續費 | 充值的會員，由平台向會員收 | 會員入帳的點數變少 |
| 金流商手續費 | 平台，為這筆代收付給金流商 | 不影響，只記在平台的帳上 |

金流商手續費在後台報表的欄位名是「支付商手續費」，金額由金流商戶上的成本費率（平台付給金流商的手續費占交易金額的比例）決定。

本份目前收錄的範圍：MR !2238 建立的金流商手續費寫入時機與算法，加上理解它必要的會員手續費與入帳點數算法（既有行為）。其餘建單前檢查（活動進行中、處理中訂單上限、金額上下限、會員群組限額）的條目，隨後續 MR 補入。

## 怎麼觸發

- 經金流商代收：會員 App › 充值頁選支付通道送出。入帳由金流商回調觸發，沒有操作畫面。
- 自助上分：會員 App › 自助上分頁選銀行帳戶、填金額送出。審核在代理後台（待確認）。
- 建好的充值訂單在代理後台 › 下線會員管理 › 儲值訂單查詢看得到。

## 規則

### 會員手續費與入帳點數（既有行為）

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 會員經金流商充值送出時，會員手續費在建單當下算好，並寫進充值訂單。會員手續費等於固定手續費，加上「充值金額乘以入帳比例」再乘以手續費百分比。入帳點數等於充值金額乘以入帳比例，再減掉會員手續費。兩者的單位都是錢包幣別，設定來源是該支付通道背後的金流商戶。 | code:OrderService::createOrder |
| R-2 | 會員自助上分送出時不收會員手續費，入帳點數等於充值金額乘以該銀行帳戶的入帳比例。 | code:AutoDepositController::createOrder |
| R-3 | Ap8Pay 與 TngPin 的回調，會依金流商回報的實付金額改寫充值訂單的點數。Ap8Pay 改成實付金額乘以入帳比例；TngPin 用實付金額重算會員手續費，再算出點數。改寫發生在入帳之前，會員入帳的是改寫後的點數。 | code:Ap8Pay\DepositCallback、code:TngPin\DepositCallback |

### 金流商手續費：經金流商代收

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-4 | 經金流商充值的充值訂單，建單時金流商手續費為 0。等到回調成功、點數加進錢包、訂單變成「已上分」的那一刻，才寫入金流商手續費。 | code:PaymentService::updateMemberBalance |
| R-5 | 寫入的金流商手續費，等於這張充值訂單最終的入帳點數乘以該金流商戶當下的成本費率，截到小數第 4 位、不四捨五入，單位是錢包幣別。入帳點數是已扣掉會員手續費之後的值。 | code:PaymentService::paymentCostOf、code:AgentCashFlow::paymentCostFor |
| R-6 | 回調在入帳前改寫過點數的充值訂單（見 R-3），金流商手續費用改寫後的點數計算。 | code:PaymentService::paymentCostOf |
| R-7 | 回調在入帳前已經寫入大於 0 的金流商手續費時，入帳時沿用該值，不以成本費率覆寫。目前只有 CCPayment 會這樣做，寫的是向金流商回查紀錄得到的實際服務費。值為 0 時才依 R-5 計算。 | code:PaymentService::paymentCostOf |
| R-8 | 回調判定失敗的充值訂單，以及還沒入帳的充值訂單，金流商手續費維持 0。 | code:PaymentService::updateMemberBalance |
| R-9 | 代理替自己開分、經金流商付款的開分訂單，金流商手續費的寫入時機與算法同 R-4 到 R-8。 | code:PaymentService::updateAgentBalance |

### 金流商手續費：自助上分

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-10 | 自助上分的充值訂單，在會員送出、建單當下就寫入金流商手續費。金額是入帳點數乘以該銀行帳戶所屬金流商戶當下的成本費率，截到小數第 4 位、不四捨五入。因此待審核的充值訂單上也有值。代理數據看板的金流商手續費只計已入帳的充值訂單，待審核的不會被算進去。 | code:AutoDepositController::createOrder |

### 兩種手續費的關係

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-11 | 金流商手續費寫入後就不再變動。之後調整金流商戶的成本費率，只影響之後才寫入的充值訂單。自助上分的充值訂單以建單當下的成本費率為準，審核時費率已改也不重算。 | code:PaymentService::paymentCostOf |
| R-12 | 金流商手續費不影響會員：會員入帳的點數就是充值訂單上的入帳點數，不扣金流商手續費。 | code:PaymentService::updateMemberBalance |

## Examples

金流商戶入帳比例 1、固定手續費 2、手續費百分比 1%、成本費率 2%：

| 情境 | 會員手續費 | 入帳點數 | 金流商手續費 | 依據 |
|---|---|---|---|---|
| 經金流商充值 1,000，剛建單 | 12 | 988 | 0 | R-1、R-4 |
| 同一張單回調成功、已上分 | 12 | 988 | 19.76（988 × 2%） | R-5、R-12 |
| 同一張單回調失敗 | 12 | 988（未入帳） | 0 | R-8 |
| 自助上分 1,000，剛送出待審核 | 0 | 1,000 | 20 | R-2、R-10 |
| 入帳點數 100.5、成本費率 2%，已上分 | 依設定 | 100.5 | 2.0100 | R-5 |
| 入帳點數 100.4567、成本費率 1.5% | 依設定 | 100.4567 | 1.5068（截位不四捨五入，不是 1.5069） | R-5 |
| 已上分後把成本費率改成 3% | 不變 | 不變 | 維持原值 | R-11 |
