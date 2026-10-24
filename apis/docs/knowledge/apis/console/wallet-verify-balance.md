---
type: Command
id: apis/console/wallet-verify-balance
title: 比對會員主檔餘額與錢包表餘額
description: 'Laravel 排程每日自動執行，比對會員主檔餘額與錢包表裡的一般錢包餘額，兩邊不一致時告警。'
domain: 金流
tags:
  - wallet-verify-balance
  - 錢包
  - 影子同步
  - 餘額校驗
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/Schedule/VerifyMemberWalletBalance.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/Schedule/VerifyMemberWalletBalance.php
  signature: 'wallet:verify-balance'
  schedule:
    - '10 4 * * *'
---

<!-- knowledge:generated start -->
## 入口

`php artisan wallet:verify-balance`

Command：`App\Console\Commands\Schedule\VerifyMemberWalletBalance`

比對 members.balance 與 member_wallets 一般錢包餘額，差異即告警

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--limit` | 選項（帶值） | 每批處理的會員數 | `1000` |
| `--member-id` | 選項（帶值） | 只校驗單一會員（線上排查用；測試亦以此隔離共用測試庫的無關資料） |  |

排程：`10 4 * * *`
<!-- knowledge:generated end -->

## 做什麼

這支命令由 Laravel 排程每日 04:10 自動執行，比對會員主檔的餘額與錢包表裡一般錢包的餘額，比到小數第 10 位。還沒有一般錢包的會員不列入比對。有差異時發告警，列出差異筆數與最多 5 筆會員編號及兩邊的金額，但不會修改任何資料。排查線上問題時可以用 `--member-id` 只校驗單一會員。規則見相關規則。

## 相關規則

* [rules/currency-and-wallet-currency](../../rules/currency-and-wallet-currency.md)
