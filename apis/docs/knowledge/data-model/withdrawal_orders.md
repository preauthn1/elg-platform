---
type: Table
id: data-model/withdrawal_orders
title: 取款訂單
description: 'withdrawal_orders 是會員與代理的取款訂單，記錄金額、手續費、代付狀態與金流商回應，取款建單、審核與代付流程讀寫它。'
domain: 金流
tags:
  - withdrawal_orders
  - 取款訂單
  - 代付
  - 手續費
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Models/WithdrawalOrder.php
    at: '2026-10-21'
  - kind: migration
    ref: /database/migrations/2025_06_19_091321_create_withdrawal_orders_table.php
entity:
  table: withdrawal_orders
  model: App\Models\WithdrawalOrder
  columns:
    - { name: id, type: 'int unsigned', nullable: false }
    - { name: bank_id, type: int, nullable: false, comment: banks.id }
    - { name: bank_name, type: varchar(50), nullable: false, comment: 銀行名稱 }
    - { name: bank_code, type: varchar(16), nullable: false, comment: 銀行代碼 }
    - { name: account_name, type: varchar(191), nullable: false, comment: 帳戶名稱 }
    - { name: account_no, type: varchar(191), nullable: false, comment: 帳戶帳號 }
    - { name: agent_group_id, type: int, nullable: false, comment: 支付組別編號 }
    - { name: agent_id, type: int, nullable: false, comment: withdrawal_order_agents.id }
    - { name: member_id, type: int, nullable: false, comment: 帳號系統編號(Members) }
    - { name: amount, type: 'decimal(18,4)', nullable: false, comment: 金額 }
    - { name: rate, type: 'decimal(14,9)', nullable: false, comment: 匯率(2025/05/05提升至9位) }
    - { name: point, type: 'decimal(18,4)', nullable: false, comment: 平台點數 }
    - { name: tax, type: 'decimal(11,5)', nullable: false, comment: 手續費 }
    - { name: payment_cost, type: 'decimal(18,4)', nullable: false, comment: 支付商手續費 }
    - { name: withdraw_seq, type: 'int unsigned', nullable: false, comment: 當日第幾筆(GMT+8)；0=非會員前台建單 }
    - { name: tax_source, type: tinyint, nullable: false, comment: '手續費來源 0:銀行 1:群組階梯' }
    - { name: third_party_order_no, type: varchar(50), nullable: false, comment: 三方金流訂單編號 }
    - { name: third_party_response, type: text, nullable: true, comment: 三方API的response }
    - { name: status, type: tinyint, nullable: false, comment: '狀態 0:訂單建立,1:待審核,2:拒絕,3:等待付款,4:已付款,9:退回,21:代付請求中,22:代付處理中,24:已付款(代付),29:代付回應失敗' }
    - { name: check_time, type: datetime, nullable: true, comment: 審核時間 }
    - { name: audit_time, type: datetime, nullable: true }
    - { name: audit_operator, type: int, nullable: true }
    - { name: payment_time, type: datetime, nullable: true, comment: 付款時間 }
    - { name: payment_operator, type: int, nullable: true }
    - { name: memo, type: text, nullable: true, comment: 備註 }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
    - { name: admin, type: int, nullable: false }
    - { name: operator, type: int, nullable: false, comment: 審核人 }
    - { name: change_logs, type: text, nullable: false, comment: 修改歷程 }
  read_by:
    - App\Models\WithdrawalOrder
  written_by:
    - App\Models\WithdrawalOrder
---

<!-- knowledge:generated start -->
## 表

`withdrawal_orders`

Model：`App\Models\WithdrawalOrder`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 |  |
| `bank_id` | int | 否 | banks.id |
| `bank_name` | varchar(50) | 否 | 銀行名稱 |
| `bank_code` | varchar(16) | 否 | 銀行代碼 |
| `account_name` | varchar(191) | 否 | 帳戶名稱 |
| `account_no` | varchar(191) | 否 | 帳戶帳號 |
| `agent_group_id` | int | 否 | 支付組別編號 |
| `agent_id` | int | 否 | withdrawal_order_agents.id |
| `member_id` | int | 否 | 帳號系統編號(Members) |
| `amount` | decimal(18,4) | 否 | 金額 |
| `rate` | decimal(14,9) | 否 | 匯率(2025/05/05提升至9位) |
| `point` | decimal(18,4) | 否 | 平台點數 |
| `tax` | decimal(11,5) | 否 | 手續費 |
| `payment_cost` | decimal(18,4) | 否 | 支付商手續費 |
| `withdraw_seq` | int unsigned | 否 | 當日第幾筆(GMT+8)；0=非會員前台建單 |
| `tax_source` | tinyint | 否 | 手續費來源 0:銀行 1:群組階梯 |
| `third_party_order_no` | varchar(50) | 否 | 三方金流訂單編號 |
| `third_party_response` | text | 是 | 三方API的response |
| `status` | tinyint | 否 | 狀態 0:訂單建立,1:待審核,2:拒絕,3:等待付款,4:已付款,9:退回,21:代付請求中,22:代付處理中,24:已付款(代付),29:代付回應失敗 |
| `check_time` | datetime | 是 | 審核時間 |
| `audit_time` | datetime | 是 |  |
| `audit_operator` | int | 是 |  |
| `payment_time` | datetime | 是 | 付款時間 |
| `payment_operator` | int | 是 |  |
| `memo` | text | 是 | 備註 |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
| `admin` | int | 否 |  |
| `operator` | int | 否 | 審核人 |
| `change_logs` | text | 否 | 修改歷程 |
<!-- knowledge:generated end -->

## 做什麼

`withdrawal_orders` 一列代表一張取款訂單，會員送出取款，或代理替自己洗分時都會建一列。`amount` 是取款金額，`point` 是乘上入帳比例後的平台點數，`tax` 是向會員收的會員手續費，`payment_cost` 是平台付給金流商的金流商手續費，在代付成功或人工出款當下才寫入。`status` 記錄訂單目前所在的狀態，從建立、待審核到代付中、已付款或失敗退回都有對應值；`withdraw_seq` 記這是會員當日第幾筆取款（非會員前台建的單記 0），決定套用哪一階的平台手續費階梯。規則見相關規則。

## 相關規則

* [rules/payment-channel-management](../rules/payment-channel-management.md)
* [rules/payment-cost-summary](../rules/payment-cost-summary.md)
