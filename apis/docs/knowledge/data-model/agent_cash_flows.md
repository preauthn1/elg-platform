---
type: Table
id: data-model/agent_cash_flows
title: 金流商戶
description: 'agent_cash_flows 記錄金流商戶的憑證、入帳比例與成本費率，由金流商戶管理命令寫入，代收建單與金流商手續費計算讀取。'
domain: 金流
tags:
  - agent_cash_flows
  - 金流商戶
  - 成本費率
  - 支付通道
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Models/AgentCashFlow.php
    at: '2026-10-21'
  - kind: migration
    ref: /database/migrations/2025_06_19_091321_create_agent_cash_flows_table.php
entity:
  table: agent_cash_flows
  model: App\Models\AgentCashFlow
  columns:
    - { name: id, type: 'int unsigned', nullable: false, comment: 流水號 }
    - { name: cash_flow_info_id, type: int, nullable: false }
    - { name: cash_flow_id, type: int, nullable: false, comment: 金流商系統編號 }
    - { name: cash_flow_type, type: 'tinyint unsigned', nullable: true, comment: '支付類別（繼承 cash_flows.type 1:線上支付,2:自助開分,3:代付,4:自助洗分）；便利欄，權威=cash_flows.type；NULL=尚未同步（舊系統寫入），由排程收斂' }
    - { name: agent_group_id, type: int, nullable: false, comment: 支付組別編號 }
    - { name: currency_id, type: 'int unsigned', nullable: true }
    - { name: rate, type: 'decimal(15,9)', nullable: false, comment: 入帳比例 }
    - { name: tax, type: 'decimal(11,5)', nullable: false, comment: 手續費 }
    - { name: tax_percentage, type: 'decimal(11,5)', nullable: false, comment: 手續費(百分比) }
    - { name: name, type: varchar(255), nullable: false, comment: 名稱 }
    - { name: info, type: text, nullable: false, comment: API使用資訊 }
    - { name: sorts, type: int, nullable: false, comment: 排序權重 }
    - { name: cost, type: 'decimal(10,4)', nullable: false }
    - { name: status, type: tinyint, nullable: false, comment: '設置啟用狀態 0:停用, 1:啟用' }
    - { name: backend_display, type: tinyint(1), nullable: false, comment: '後台顯示 0:隱藏, 1:顯示' }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
    - { name: admin, type: int, nullable: false, comment: 更新者ID }
    - { name: deleted_at, type: timestamp, nullable: true, comment: '軟刪除時間；NULL=有效（fix:acf-delete 可回滾刪除）' }
  read_by:
    - App\Models\AgentCashFlow
  written_by:
    - App\Models\AgentCashFlow
---

<!-- knowledge:generated start -->
## 表

`agent_cash_flows`

Model：`App\Models\AgentCashFlow`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 | 流水號 |
| `cash_flow_info_id` | int | 否 |  |
| `cash_flow_id` | int | 否 | 金流商系統編號 |
| `cash_flow_type` | tinyint unsigned | 是 | 支付類別（繼承 cash_flows.type 1:線上支付,2:自助開分,3:代付,4:自助洗分）；便利欄，權威=cash_flows.type；NULL=尚未同步（舊系統寫入），由排程收斂 |
| `agent_group_id` | int | 否 | 支付組別編號 |
| `currency_id` | int unsigned | 是 |  |
| `rate` | decimal(15,9) | 否 | 入帳比例 |
| `tax` | decimal(11,5) | 否 | 手續費 |
| `tax_percentage` | decimal(11,5) | 否 | 手續費(百分比) |
| `name` | varchar(255) | 否 | 名稱 |
| `info` | text | 否 | API使用資訊 |
| `sorts` | int | 否 | 排序權重 |
| `cost` | decimal(10,4) | 否 |  |
| `status` | tinyint | 否 | 設置啟用狀態 0:停用, 1:啟用 |
| `backend_display` | tinyint(1) | 否 | 後台顯示 0:隱藏, 1:顯示 |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
| `admin` | int | 否 | 更新者ID |
| `deleted_at` | timestamp | 是 | 軟刪除時間；NULL=有效（fix:acf-delete 可回滾刪除） |
<!-- knowledge:generated end -->

## 做什麼

`agent_cash_flows` 一列代表一個金流商戶：金流商發給平台的一組收付款身分，固定對應一種通道幣別，底下掛著支付通道，代收與代付都可能用到。這些列由金流商戶管理的 artisan 命令建立與維護，包括新建金流商戶、調整上下架與後台顯示、調整成本費率。`rate` 是入帳比例，`cost` 是成本費率，也就是平台付給金流商的手續費占交易金額的比例，用來計算金流商手續費；代收建單、入帳與排程彙總金流商手續費時都會讀到這兩個欄位。規則見相關規則。

## 相關規則

* [rules/deposit-order-fees](../rules/deposit-order-fees.md)
* [rules/payment-channel-management](../rules/payment-channel-management.md)
* [rules/payment-cost-summary](../rules/payment-cost-summary.md)
