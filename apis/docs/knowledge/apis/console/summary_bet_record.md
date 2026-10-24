---
type: Command
id: apis/console/summary_bet_record
title: 遊戲記錄每個人每小時每款遊戲結算-新版
description: '依指定日期、小時與遊戲商人工補跑 SBR 的命令說明。'
domain: 遊戲與遊戲商
tags:
  - summary_bet_record
  - SBR
  - 注單彙總
  - 人工補跑
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/SummaryBetRecord/SummaryBetRecordCommand.php
    at: '2026-10-22'
entity:
  class: /app/Console/Commands/SummaryBetRecord/SummaryBetRecordCommand.php
  signature: summary_bet_record
---

<!-- knowledge:generated start -->
## 入口

`php artisan summary_bet_record`

Command：`App\Console\Commands\SummaryBetRecord\SummaryBetRecordCommand`

遊戲記錄每個人每小時每款遊戲結算 - 新版

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--date` | 選項（帶值） |  |  |
| `--hour` | 選項（帶值） |  |  |
| `--prev-hour` | 選項（旗標） |  |  |
| `--yesterday` | 選項（旗標） |  |  |
| `--vendor` | 選項（帶值） |  |  |
| `--dry-run` | 選項（旗標） |  |  |
| `--timezone` | 選項（帶值） |  |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

維運要人工補跑 SBR（把 TBR 依每人每小時每款遊戲聚合後的結算數字）時執行這支命令，可指定日期、小時、前一小時、昨天或特定遊戲商，也可以 `--dry-run` 先看會跑出什麼結果再決定要不要真的寫入。規則見相關規則。

## 相關規則

* [rules/summary-bet-record](../../rules/summary-bet-record.md)
