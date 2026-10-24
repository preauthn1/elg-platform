---
type: Command
id: apis/console/vendor-monitor-account-pool
title: '監測補不進去的帳號池並發 Telegram 告警'
description: 監測遊戲商玩家帳號池補不進去、發送告警的命令說明。
domain: 遊戲與遊戲商
tags:
  - vendor-monitor-account-pool
  - 帳號池
  - 告警
  - 排程
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:28:34+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/Schedule/MonitorVendorAccountPool.php
    at: '2026-10-22'
entity:
  class: /app/Console/Commands/Schedule/MonitorVendorAccountPool.php
  signature: 'vendor:monitor-account-pool'
  schedule:
    - '0 * * * *'
---

<!-- knowledge:generated start -->
## 入口

`php artisan vendor:monitor-account-pool`

Command：`App\Console\Commands\Schedule\MonitorVendorAccountPool`

監測補不進去的帳號池並發 Telegram 告警

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--hours` | 選項（帶值） | 只看這段時間內建立的帳號列 | `1` |
| `--consecutive` | 選項（帶值） | 連續幾筆未完成才告警 | `10` |
| `--sample` | 選項（帶值） | 表尾取樣筆數（同時是連續數上限） | `20` |
| `--dry-run` | 選項（旗標） | 只輸出結果，不發送通知 |  |

排程：`0 * * * *`
<!-- knowledge:generated end -->

## 做什麼

系統排程每小時執行這支命令，檢查近一段時間內建立的遊戲商帳號池帳號，有沒有連續多筆卡在未完成狀態、遲遲補不進去；偵測到異常就發 Telegram 告警給維運。可調整觀察時數、連續筆數門檻與取樣筆數，`--dry-run` 只輸出結果不發通知。規則見相關規則。

## 相關規則

* [rules/vendor-account-pool](../../rules/vendor-account-pool.md)
