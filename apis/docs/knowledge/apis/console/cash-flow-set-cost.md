---
type: Command
id: apis/console/cash-flow-set-cost
title: 設定金流商戶的成本費率
description: 'RD 或上線人員用命令批次設定金流商戶的成本費率，決定訂單上要記多少金流商手續費。'
domain: 金流
tags:
  - cash-flow-set-cost
  - 成本費率
  - 金流商手續費
  - 金流商戶
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/CashFlow/SetAgentCashFlowCostCommand.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/CashFlow/SetAgentCashFlowCostCommand.php
  signature: 'cash-flow:set-cost'
---

<!-- knowledge:generated start -->
## 入口

`php artisan cash-flow:set-cost`

Command：`App\Console\Commands\CashFlow\SetAgentCashFlowCostCommand`

設定金流商戶的成本費率 cost（任一配對不合格則整批零寫入；寫入前先備份）

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--pair` | 選項（可多值） | 設定配對，格式 <agent_cash_flows.id>:<費率>（可指定多個；0.005 = 0.5%） |  |
| `--reason` | 選項（帶值） | 設定理由，寫入備份 manifest 與 log 供日後追溯（必填） |  |
| `--allow-high-cost` | 選項（旗標） | 放行大於 0.1（10%）的費率 |  |
| `--execute` | 選項（旗標） | 實際寫入（預設只輸出預檢報表） |  |
| `--force` | 選項（旗標） | 跳過執行前的環境確認 |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

RD 或上線人員在調整金流商戶的手續費條件時，於命令列執行這支命令，批次設定一個或多個金流商戶的成本費率。成本費率是平台付給金流商的手續費占交易金額的比例，命令預設只輸出預檢報表，列出每個金流商戶的現值與目標值，不寫入；加上執行參數並經操作者確認後才真的寫入，寫入前會先備份被改到的金流商戶。改了成本費率之後，只有之後才寫入金流商手續費的訂單用新費率，已經寫入的訂單不會被重算。規則見相關規則。

## 相關規則

* [rules/payment-channel-management](../../rules/payment-channel-management.md)
