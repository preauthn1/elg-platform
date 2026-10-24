---
type: Table
id: data-model/deposit_manual_reviews
title: 充值人工確認紀錄
description: 'deposit_manual_reviews 記錄需要人工確認的充值訂單，目前由 CCPayment 風險交易建單時寫入，排程依退款結果解鎖或轉為失敗。'
domain: 金流
tags:
  - deposit_manual_reviews
  - 充值人工確認
  - CCPayment
  - 風險交易
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Models/DepositManualReview.php
    at: '2026-10-21'
  - kind: migration
    ref: /database/migrations/2026_10_06_000001_create_deposit_manual_reviews_table.php
entity:
  table: deposit_manual_reviews
  model: App\Models\DepositManualReview
  columns:
    - { name: id, type: 'int unsigned', nullable: false, comment: PK流水號 }
    - { name: order_id, type: 'int unsigned', nullable: false, comment: '充值訂單 orders.id；一張充值訂單最多一列' }
    - { name: agent_cash_flow_id, type: 'int unsigned', nullable: false, comment: '金流商戶 agent_cash_flows.id（排程逐商戶查詢與比對）' }
    - { name: cash_flow_id, type: 'int unsigned', nullable: false, comment: '金流商 cash_flows.id（冗餘存放，方便依金流商篩選）' }
    - { name: reason, type: varchar(30), nullable: false, comment: '需人工確認的原因 risky_transaction:金流商標記風險交易' }
    - { name: result, type: varchar(30), nullable: false, comment: '處理結果 pending:待處理 refunded_to_payer:退回付款人 refunded_to_merchant:退到營運方白名單地址' }
    - { name: allow_credit, type: 'tinyint unsigned', nullable: false, comment: '可否手動上分 0:不可 1:可；舊系統手動上分與重置狀態只看此欄' }
    - { name: proof, type: json, nullable: true, comment: '金流商給的依據，CCPayment: refund_record_id/refund_status/refund_to_address/payer_address；僅供查證' }
    - { name: resolved_at, type: timestamp, nullable: true, comment: 確認處理結果的時間 }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
  read_by:
    - App\Models\DepositManualReview
  written_by:
    - App\Models\DepositManualReview
---

<!-- knowledge:generated start -->
## 表

`deposit_manual_reviews`：存款人工確認紀錄（多金流商共用）

Model：`App\Models\DepositManualReview`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 | PK流水號 |
| `order_id` | int unsigned | 否 | 充值訂單 orders.id；一張充值訂單最多一列 |
| `agent_cash_flow_id` | int unsigned | 否 | 金流商戶 agent_cash_flows.id（排程逐商戶查詢與比對） |
| `cash_flow_id` | int unsigned | 否 | 金流商 cash_flows.id（冗餘存放，方便依金流商篩選） |
| `reason` | varchar(30) | 否 | 需人工確認的原因 risky_transaction:金流商標記風險交易 |
| `result` | varchar(30) | 否 | 處理結果 pending:待處理 refunded_to_payer:退回付款人 refunded_to_merchant:退到營運方白名單地址 |
| `allow_credit` | tinyint unsigned | 否 | 可否手動上分 0:不可 1:可；舊系統手動上分與重置狀態只看此欄 |
| `proof` | json | 是 | 金流商給的依據，CCPayment: refund_record_id/refund_status/refund_to_address/payer_address；僅供查證 |
| `resolved_at` | timestamp | 是 | 確認處理結果的時間 |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
<!-- knowledge:generated end -->

## 做什麼

`deposit_manual_reviews` 一列對應一張需要人工確認的充值訂單，一張訂單最多一列。目前唯一的來源是 CCPayment 把入帳標成風險交易：建單時寫入一列，並把 `allow_credit` 設為不可，擋住手動上分與重置狀態。排程依金流商回查到的退款結果更新 `result` 與 `resolved_at`：退回付款人時維持鎖定，退到營運方白名單地址時把 `allow_credit` 改為可，交由營運手動上分，`proof` 則存金流商給的查證依據。規則見相關規則。

## 相關規則

* [rules/provider-ccpayment](../rules/provider-ccpayment.md)
