---
type: Rule
id: rules/agent-self-deposit
title: 代理端開分訂單
description: 代理在代理後台經金流商替自己開分時，哪些支付通道受理、被擋時看到什麼、金流商要求的額外欄位怎麼帶、建單後的結果。
domain: 金流
subdomain: 代理開洗分（對代理）
tags: [金流, 代收, 開分, 開分訂單, 代理開分, 代理自助開分, 代理自助開洗分, 開分申請, 快速充值, 代理充值, 代理存款, agent deposit, 支付通道, 動態欄位, 額外欄位, extra fields, 舊系統, 新舊系統分界, legacy, TNGPIN, TngPin, PIN 碼, 代理]
entities:
  - api: POST agent/api/agent/deposit
  - api: POST agent/api/deposit/channels
  - api: POST agent/api/deposit/request-logs
  - class: App\Enums\CashFlow\CashFlowScopeEnum
  - class: App\Http\Requests\Agent\CashFlow\DepositRequest
  - class: App\Services\OrderService
  - class: App\Services\Payment\Providers\TngPin\DepositCallback
  - class: App\Services\PaymentService
evidence:
  - kind: code
    ref: /app/Http/Controllers/Agent/DepositController.php
    title: 代理端開分建單入口（自助開洗分開關、通道受理檢查、金額缺省、送金流商）
    at: 2026-10-20
  - kind: code
    ref: /app/Enums/CashFlow/CashFlowScopeEnum.php
    title: 新舊系統分界 62 與承接名單（isHandledByApis、notHandledMessage）
  - kind: code
    ref: /app/Http/Requests/Agent/CashFlow/DepositRequest.php
    title: 代理端開分的動態欄位（getExtraFields）
  - kind: code
    ref: /app/Services/OrderService.php
    title: 建立訂單，算會員手續費與點數、寫入額外欄位
  - kind: code
    ref: /app/Services/Payment/Providers/TngPin/DepositCallback.php
    title: TngPin 回調依實付金額改寫訂單金額與點數
  - kind: code
    ref: /app/Services/PaymentService.php
    title: 回調成功後把點數加進代理錢包（updateAgentBalance）
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2329
    title: 代理端存款寫入動態欄位，並擋下舊系統範疇的支付通道
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4433
    title: "[GFF]代理自助開洗分 > 開分申請-快速充值 - TNGPIN 改由 APIs 送單"
related:
  - rules/deposit-order-fees
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
---

## 這個主題在講什麼

代理在代理後台選一條支付通道、填金額或金流商要求的欄位後送出，經金流商付款替自己開分。系統建立一張開分訂單並向金流商送單，等金流商回調確認付款後，點數才加進代理自己的錢包。

過渡期間，支付通道分屬新舊兩套系統，分界是支付通道背後的金流商編號：

- 金流商編號大於 62 的支付通道由 APIs（本 repo 的新系統）處理。
- 金流商編號在 62 以下的支付通道原本都由舊系統處理，只有少數已改由 APIs 承接。
- 新代理後台依同一條分界，決定把開分送到哪一套系統。
- APIs 這一側另外再擋一次，避免舊系統範疇的支付通道被送進來。有些金流商在新舊系統都有實作，APIs 不擋的話，這類開分會直接送給金流商。

本份目前收錄 MR !2329 建立的條目：

- 哪些支付通道受理。
- 被擋時的回應。
- 金流商要求的額外欄位怎麼帶。
- 沒填金額時怎麼建單。
- 理解以上幾項所需的既有建單結果。

代理洗分走代付時，也有同一道支付通道檢查，這部分不在本份。

## 怎麼觸發

代理後台 › 代理自助開洗分 › 開分申請-快速充值，選支付通道後送出。

## 規則

### 受理的支付通道

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 代理的設定沒有開啟自助開洗分時，送出開分一律擋下，回應「Agent self cash flow is disabled」，不建開分訂單。這項檢查先於支付通道的檢查。 | code:Agent\DepositController::deposit |
| R-2 | 支付通道背後的金流商編號大於 62 時，APIs 受理這筆開分。 | code:CashFlowScopeEnum::isHandledByApis |
| R-3 | 金流商編號在 62 以下（含 62）時，APIs 只受理已承接的通道，目前只有 TNGPIN 代收（金流商編號 58）；其餘一律不受理。 | code:CashFlowScopeEnum::isHandledByApis |
| R-4 | 選到不受理的支付通道時擋下，回應「此支付通道（cash_flow_id N）過舊，APIs 無法支援」（N 是該金流商編號，回應碼 400），不建開分訂單、不向金流商送單。 | code:CashFlowScopeEnum::notHandledMessage |
| R-5 | R-3、R-4 的檢查只在代理端開分與代付兩個入口；會員在前台充值、以及金流商的回調處理，不受這道檢查影響。 | code:CashFlowScopeEnum::isHandledByApis |

### 金流商要求的額外欄位

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-6 | 送出開分時，表單上除了返回網址、支付通道、金額以外的欄位，都視為金流商要求的額外欄位。這些欄位依原本的鍵與值記在開分訂單上，供該金流商送單與回調時取用。例如 TNGPIN 的 PIN 碼、選填的名字與銀行帳號都屬此類。新代理後台用來決定送往哪套系統的分流參數不記。 | code:DepositRequest::getExtraFields |
| R-7 | 沒有任何額外欄位時，開分訂單上不記額外內容。 | code:DepositRequest::getExtraFields |
| R-8 | R-6 的規則與會員前台充值相同，基本欄位以外全收，不另設只收特定欄位的名單。 | code:DepositRequest::getExtraFields |

### 金額與建單結果

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-9 | 送出時沒填金額時（例如 TNGPIN 的面額寫在 PIN 卡上，建單時不知道），系統以金額 0 建立開分訂單，不擋下。實際金額與點數在金流商回調時，依回報的實付金額改寫，見 [rules/deposit-order-fees#R-3](deposit-order-fees.md)。有填金額時，金額不得小於 0.1。 | code:Agent\DepositController::deposit、code:OrderService::createOrder |
| R-10 | 建立的開分訂單掛在代理自己名下，狀態是等待金流商回調。會員手續費與點數的算法同 [rules/deposit-order-fees#R-1](deposit-order-fees.md)，金流商手續費見 [rules/deposit-order-fees#R-9](deposit-order-fees.md)。 | code:OrderService::createOrder |
| R-11 | 金流商回調確認付款成功後，點數才加進代理自己的錢包。送單當下，代理錢包不變。 | code:PaymentService::updateAgentBalance |
| R-12 | 向金流商送單成功時，代理後台依金流商的回應呈現下一步：跳轉支付頁、顯示金流商回傳的網頁、顯示成功並回錢包頁，或顯示 QR code。 | code:Agent\DepositController::deposit |

## Examples

| 情境 | 結果 | 依據 |
|---|---|---|
| 選金流商編號 74 的支付通道，填金額 100 送出 | 建立開分訂單並送金流商 | R-2、R-10 |
| 選 TNGPIN（金流商編號 58），選電信商、填 PIN 碼、不填金額送出 | 以金額 0 建立開分訂單，PIN 碼記在開分訂單上並送金流商；回調後依實付金額改寫 | R-3、R-6、R-9 |
| 選金流商編號 39 的支付通道送進 APIs | 擋下，回應「此支付通道（cash_flow_id 39）過舊，APIs 無法支援」 | R-3、R-4 |
| 選金流商編號 62 的支付通道送進 APIs | 擋下，因為 62 本身屬舊系統範疇 | R-3、R-4 |
| 代理未開啟自助開洗分，選金流商編號 74 送出 | 擋下，回應「Agent self cash flow is disabled」 | R-1 |
