---
type: Table
id: data-model/member_groups
title: 會員群組
description: 'member_groups 記錄代理設定的會員群組：充值、取款、轉帳限額與平台手續費階梯，決定一群會員能做什麼、要收多少手續費。'
domain: 會員
tags:
  - member_groups
  - 會員群組
  - 手續費階梯
  - 限額
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Models/MemberGroup.php
    at: '2026-10-21'
  - kind: migration
    ref: /database/migrations/2026_07_20_000001_create_member_groups_table.php
entity:
  table: member_groups
  model: App\Models\MemberGroup
  columns:
    - { name: id, type: 'int unsigned', nullable: false }
    - { name: agent_id, type: int, nullable: false, comment: 代理系統編號 }
    - { name: name, type: varchar(50), nullable: false, comment: 群組名稱 }
    - { name: remark, type: varchar(191), nullable: true, comment: 內部備註 }
    - { name: sequence, type: int, nullable: false, comment: 排序 }
    - { name: status, type: tinyint, nullable: false, comment: '1:啟用 0:停用' }
    - { name: default_setting, type: tinyint, nullable: false, comment: '預設群組, 每代理至多一筆' }
    - { name: bank_whitelist_enabled, type: tinyint, nullable: false, comment: '自助存款帳號白名單開關, 0:不限制(忽略白名單) 1:僅允許白名單帳號(可為空清單=完全不可用)' }
    - { name: deposit_min, type: 'decimal(18,4)', nullable: false, comment: '存款單筆下限, -1:不限制' }
    - { name: deposit_max, type: 'decimal(18,4)', nullable: false, comment: '存款單筆上限, -1:不限制' }
    - { name: deposit_daily_count, type: int, nullable: false, comment: '存款每日次數, -1:不限制' }
    - { name: deposit_daily_amount, type: 'decimal(18,4)', nullable: false, comment: '存款每日金額, -1:不限制' }
    - { name: withdraw_min, type: 'decimal(18,4)', nullable: false, comment: '提款單筆下限, -1:不限制' }
    - { name: withdraw_max, type: 'decimal(18,4)', nullable: false, comment: '提款單筆上限, -1:不限制' }
    - { name: withdraw_daily_count, type: int, nullable: false, comment: '提款每日次數, -1:不限制' }
    - { name: withdraw_daily_amount, type: 'decimal(18,4)', nullable: false, comment: '提款每日金額, -1:不限制' }
    - { name: withdraw_fee_tiers, type: json, nullable: true, comment: '平台手續費階梯 [{from,type,value}]；null=沿用銀行' }
    - { name: transfer_min, type: 'decimal(18,4)', nullable: false, comment: '轉帳單筆下限, -1:不限制' }
    - { name: transfer_max, type: 'decimal(18,4)', nullable: false, comment: '轉帳單筆上限, -1:不限制' }
    - { name: transfer_daily_count, type: int, nullable: false, comment: '轉帳每日次數, -1:不限制' }
    - { name: transfer_daily_amount, type: 'decimal(18,4)', nullable: false, comment: '轉帳每日金額, -1:不限制' }
    - { name: admin, type: int, nullable: false, comment: 更新者ID }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
  read_by:
    - App\Models\MemberGroup
  written_by:
    - App\Models\MemberGroup
---

<!-- knowledge:generated start -->
## 表

`member_groups`：會員群組

Model：`App\Models\MemberGroup`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 |  |
| `agent_id` | int | 否 | 代理系統編號 |
| `name` | varchar(50) | 否 | 群組名稱 |
| `remark` | varchar(191) | 是 | 內部備註 |
| `sequence` | int | 否 | 排序 |
| `status` | tinyint | 否 | 1:啟用 0:停用 |
| `default_setting` | tinyint | 否 | 預設群組, 每代理至多一筆 |
| `bank_whitelist_enabled` | tinyint | 否 | 自助存款帳號白名單開關, 0:不限制(忽略白名單) 1:僅允許白名單帳號(可為空清單=完全不可用) |
| `deposit_min` | decimal(18,4) | 否 | 存款單筆下限, -1:不限制 |
| `deposit_max` | decimal(18,4) | 否 | 存款單筆上限, -1:不限制 |
| `deposit_daily_count` | int | 否 | 存款每日次數, -1:不限制 |
| `deposit_daily_amount` | decimal(18,4) | 否 | 存款每日金額, -1:不限制 |
| `withdraw_min` | decimal(18,4) | 否 | 提款單筆下限, -1:不限制 |
| `withdraw_max` | decimal(18,4) | 否 | 提款單筆上限, -1:不限制 |
| `withdraw_daily_count` | int | 否 | 提款每日次數, -1:不限制 |
| `withdraw_daily_amount` | decimal(18,4) | 否 | 提款每日金額, -1:不限制 |
| `withdraw_fee_tiers` | json | 是 | 平台手續費階梯 [{from,type,value}]；null=沿用銀行 |
| `transfer_min` | decimal(18,4) | 否 | 轉帳單筆下限, -1:不限制 |
| `transfer_max` | decimal(18,4) | 否 | 轉帳單筆上限, -1:不限制 |
| `transfer_daily_count` | int | 否 | 轉帳每日次數, -1:不限制 |
| `transfer_daily_amount` | decimal(18,4) | 否 | 轉帳每日金額, -1:不限制 |
| `admin` | int | 否 | 更新者ID |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
<!-- knowledge:generated end -->

## 做什麼

`member_groups` 一列代表一個會員群組，由代理建立，決定這一群會員的充值、取款與轉帳限額，以及 `bank_whitelist_enabled` 開啟時的取款銀行帳號白名單。`withdraw_fee_tiers` 是平台手續費階梯，依會員當日第幾筆取款決定會員手續費要收多少；值為 `null` 時，這個群組的會員取款沿用取款銀行上的手續費設定。`default_setting` 標記每個代理底下的預設群組，至多一筆。規則見相關規則。

## 相關規則

* [rules/member-group-fee-tiers](../rules/member-group-fee-tiers.md)
* [rules/withdrawal-member-fee](../rules/withdrawal-member-fee.md)
