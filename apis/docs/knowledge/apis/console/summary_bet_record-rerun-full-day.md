---
type: Command
id: apis/console/summary_bet_record-rerun-full-day
title: '隔日整天重跑 SBR'
description: '對設定為整天重跑的遊戲商重新彙總前一整天 SBR 的命令說明。'
domain: 遊戲與遊戲商
tags:
  - summary_bet_record-rerun-full-day
  - SBR
  - 注單彙總
  - 轉帳錢包
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/SummaryBetRecord/RerunFullDaySummaryBetRecordCommand.php
    at: '2026-10-22'
entity:
  class: /app/Console/Commands/SummaryBetRecord/RerunFullDaySummaryBetRecordCommand.php
  signature: 'summary_bet_record:rerun-full-day'
  schedule:
    - '25 0 * * *'
---

<!-- knowledge:generated start -->
## 入口

`php artisan summary_bet_record:rerun-full-day`

Command：`App\Console\Commands\SummaryBetRecord\RerunFullDaySummaryBetRecordCommand`

逐一以對帳時區重跑 next_day_full_rerun 遊戲商的前一整天注單彙總（轉帳錢包先拉注單）

參數：無

排程：`25 0 * * *`
<!-- knowledge:generated end -->

## 做什麼

系統排程每天凌晨 0:25 執行這支命令，依各遊戲商自己的對帳時區，逐一重跑設定為「隔日整天重跑」的遊戲商前一整天的 SBR（把 TBR 依每人每小時每款遊戲聚合後的結算數字）；轉帳錢包的遊戲商會先拉一次注單再重跑。規則見相關規則。

## 相關規則

* [rules/summary-bet-record](../../rules/summary-bet-record.md)
