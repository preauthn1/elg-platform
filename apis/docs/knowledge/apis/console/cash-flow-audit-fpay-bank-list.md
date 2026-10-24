---
type: Command
id: apis/console/cash-flow-audit-fpay-bank-list
title: 'FPay 代付銀行清單每日稽核'
description: '系統排程每天比對 FPay 代付銀行清單與上次存下的基準，代號被換成別家銀行或消失時用 BotNotify 告警。'
domain: 金流
tags:
  - cash-flow-audit-fpay-bank-list
  - FPay
  - 稽核
  - 告警
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T03:49:37+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/CashFlow/Maintenance/AuditFpayBankList.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/CashFlow/Maintenance/AuditFpayBankList.php
  signature: 'cash-flow:audit-fpay-bank-list'
  schedule:
    - '0 8 * * * --notify'
---

<!-- knowledge:generated start -->
## 入口

`php artisan cash-flow:audit-fpay-bank-list`

Command：`App\Console\Commands\CashFlow\Maintenance\AuditFpayBankList`

每天比對 FPay 代付銀行清單與基準快照，代號消失或換成別家銀行即告警（不動業務資料）

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--acf` | 選項（帶值） | 只查指定代付設定的 agent_cash_flows.id（省略＝本站所有啟用中的 FPay 代付設定） |  |
| `--accept` | 選項（旗標） | 人工確認差異已處理，把這次查到的清單存為新的比對基準 |  |
| `--notify` | 選項（旗標） | 排程模式：有差異即 BotNotify，且一律回 SUCCESS（差異不算命令失敗） |  |

排程：`0 8 * * * --notify`
<!-- knowledge:generated end -->

## 做什麼

系統排程每天台北時間 16 時，對本站每一個啟用中的 FPay 代付金流商戶，向 FPay 查目前的代付銀行清單，與上次存下的基準比對。發現某個代號換成了別家銀行、或代號從清單上消失時，用 BotNotify 發出告警，因為換了銀行的交易會匯到別家、消失的代號送出會被退回。RD 或上線人員也可以手動執行同一支命令查現況，確認差異已經處理完畢後，加上接受參數把當次清單存成新基準。這支命令只寫自己的比對基準，不改任何業務資料。規則見相關規則。

## 相關規則

* [rules/payout-bank-code-mapping](../../rules/payout-bank-code-mapping.md)
