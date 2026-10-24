---
type: Command
id: apis/console/app-upgrade
title: 升級腳本
description: 版本升級或環境初始化時，把遊戲商、金流商戶等多項主檔資料同步進資料庫的命令。
domain: 系統
tags:
  - app-upgrade
  - 升級
  - 主檔同步
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/UpgradeCommand.php
    at: '2026-10-22'
entity:
  class: /app/Console/Commands/UpgradeCommand.php
  signature: 'app:upgrade'
---

<!-- knowledge:generated start -->
## 入口

`php artisan app:upgrade`

Command：`App\Console\Commands\UpgradeCommand`

升級腳本

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--silent` | 選項（旗標） | 不輸出任何資訊 |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

系統管理者在版本升級或環境初始化時執行這支命令，依序把遊戲商、金流商戶、資料庫 stored procedure、代理設定定義、代理後台選單、簡訊商、國家、幣別、標準銀行這些主檔資料同步到資料庫，並輸出各類資料更新的筆數。規則見相關規則。

## 相關規則

* [rules/vendor-and-channel-management](../../rules/vendor-and-channel-management.md)
