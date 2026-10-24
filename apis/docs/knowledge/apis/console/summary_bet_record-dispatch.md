---
type: Command
id: apis/console/summary_bet_record-dispatch
title: '派發前一小時 SBR 彙總工作'
description: '整點排程派發上一小時 SBR 彙總工作的命令說明。'
domain: 遊戲與遊戲商
tags:
  - summary_bet_record-dispatch
  - SBR
  - 注單彙總
  - 排程
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/SummaryBetRecord/DispatchSummaryBetRecordCommand.php
    at: '2026-10-22'
entity:
  class: /app/Console/Commands/SummaryBetRecord/DispatchSummaryBetRecordCommand.php
  signature: 'summary_bet_record:dispatch'
  schedule:
    - '30 * * * *'
---

<!-- knowledge:generated start -->
## 入口

`php artisan summary_bet_record:dispatch`

Command：`App\Console\Commands\SummaryBetRecord\DispatchSummaryBetRecordCommand`

派發前一小時 SBR 彙總 job（Horizon long-running；job 內建回看補洞）

參數：無

排程：`30 * * * *`
<!-- knowledge:generated end -->

## 做什麼

系統排程每小時 30 分執行這支命令，派發前一小時的 SBR（把 TBR 依每人每小時每款遊戲聚合後的結算數字）彙總 job；job 本身長駐在 Horizon 上執行，內建回看補洞機制。規則見相關規則。

## 相關規則

* [rules/summary-bet-record](../../rules/summary-bet-record.md)
