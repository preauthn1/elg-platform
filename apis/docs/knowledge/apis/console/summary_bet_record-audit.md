---
type: Command
id: apis/console/summary_bet_record-audit
title: '稽核 SBR 完成標記缺口'
description: '稽核 SBR 有沒有漏跑或異常的命令說明。'
domain: 遊戲與遊戲商
tags:
  - summary_bet_record-audit
  - SBR
  - 注單彙總
  - 稽核
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/SummaryBetRecord/AuditSummaryBetRecordRunsCommand.php
    at: '2026-10-22'
entity:
  class: /app/Console/Commands/SummaryBetRecord/AuditSummaryBetRecordRunsCommand.php
  signature: 'summary_bet_record:audit'
  schedule:
    - '40 2 * * *'
---

<!-- knowledge:generated start -->
## 入口

`php artisan summary_bet_record:audit`

Command：`App\Console\Commands\SummaryBetRecord\AuditSummaryBetRecordRunsCommand`

稽核 SBR 完成標記缺口（queue_job_runs；回看視窗外的靜默漏桶偵測，不依賴 Redis/Horizon）

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--hours` | 選項（帶值） | 稽核視窗長度（小時），自安全時差起往回掃 | `48` |

排程：`40 2 * * *`
<!-- knowledge:generated end -->

## 做什麼

系統排程每天凌晨 2:40 執行這支命令，稽核過去一段視窗內 SBR（把 TBR 依每人每小時每款遊戲聚合後的結算數字）有沒有該跑卻沒跑的缺口；不依賴 Redis／Horizon 回看，而是直接比對 `queue_job_runs` 的完成標記，可用 `--hours` 調整稽核視窗長度。規則見相關規則。

## 相關規則

* [rules/summary-bet-record](../../rules/summary-bet-record.md)
