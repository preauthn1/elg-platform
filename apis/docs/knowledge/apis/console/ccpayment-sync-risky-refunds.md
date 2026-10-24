---
type: Command
id: apis/console/ccpayment-sync-risky-refunds
title: '同步 CCPayment 風險交易的退款結果'
description: '排程每 10 分鐘執行，查詢 CCPayment 風險交易的退款結果，據以更新或解鎖對應充值訂單。'
domain: 金流
tags:
  - ccpayment-sync-risky-refunds
  - CCPayment
  - 風險交易
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T03:49:37+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/CashFlow/CCPayment/SyncRiskyRefundsCommand.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/CashFlow/CCPayment/SyncRiskyRefundsCommand.php
  signature: 'ccpayment:sync-risky-refunds'
  schedule:
    - '*/10 * * * *'
---

<!-- knowledge:generated start -->
## 入口

`php artisan ccpayment:sync-risky-refunds`

Command：`App\Console\Commands\CashFlow\CCPayment\SyncRiskyRefundsCommand`

CCPayment 風險交易退款同步：查退款紀錄，退回付款人的單改狀態 2、退到營運方白名單的單改為可手動上分

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--dry-run` | 選項（旗標） | 只列出判定結果，不寫入也不告警 |  |

排程：`*/10 * * * *`
<!-- knowledge:generated end -->

## 做什麼

CCPayment 會把部分入帳標成風險交易，這類充值訂單建單時就被鎖住、不能手動上分，只能由營運到金流商後台申請退款。系統排程每 10 分鐘自動查一次這些訂單的退款結果：退回原付款人時，訂單改為失敗、仍不可上分；退到營運方登記的收款地址白名單時，解除鎖定，改由營運手動上分；查不到明確結果，或退款地址既非原付款人也不在白名單時，訂單維持原狀並視情況發告警。規則見相關規則。

## 相關規則

* [rules/provider-ccpayment](../../rules/provider-ccpayment.md)
