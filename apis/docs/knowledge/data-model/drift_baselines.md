---
type: Table
id: data-model/drift_baselines
title: 漂移比對基準
description: 'drift_baselines 存放各監測對象上次比對時的已知內容，供下次比對是否有變動；目前只有 FPay 代付銀行清單在用。'
domain: 金流
tags:
  - drift_baselines
  - 漂移比對
  - FPay
  - 稽核
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Models/DriftBaseline.php
    at: '2026-10-21'
entity:
  table: drift_baselines
  model: App\Models\DriftBaseline
  columns:
    - { name: id, type: 'int unsigned', nullable: false, comment: 流水號 }
    - { name: subject_key, type: varchar(50), nullable: false, comment: '監測對象(DriftSubjectEnum)；須為 canonical 字串、無前後空白' }
    - { name: scope_key, type: varchar(50), nullable: false, comment: '範圍鍵(語意由各監測對象自訂，FPay 銀行清單=代付設定 id)；須為 canonical 字串、無前後空白' }
    - { name: scope_label, type: varchar(50), nullable: true, comment: 範圍的人可讀標籤，只供閱讀、不參與比對 }
    - { name: item_count, type: 'int unsigned', nullable: true, comment: '內容筆數；內容不是清單時為 NULL' }
    - { name: payload, type: json, nullable: false, comment: 已知內容本身 }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
  read_by:
    - App\Models\DriftBaseline
  written_by:
    - App\Models\DriftBaseline
---

<!-- knowledge:generated start -->
## 表

`drift_baselines`：漂移比對基準（某監測對象的已知內容，供下次比對）

Model：`App\Models\DriftBaseline`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 | 流水號 |
| `subject_key` | varchar(50) | 否 | 監測對象(DriftSubjectEnum)；須為 canonical 字串、無前後空白 |
| `scope_key` | varchar(50) | 否 | 範圍鍵(語意由各監測對象自訂，FPay 銀行清單=代付設定 id)；須為 canonical 字串、無前後空白 |
| `scope_label` | varchar(50) | 是 | 範圍的人可讀標籤，只供閱讀、不參與比對 |
| `item_count` | int unsigned | 是 | 內容筆數；內容不是清單時為 NULL |
| `payload` | json | 否 | 已知內容本身 |
| `created_at` | timestamp | 否 | 建立日期 |
<!-- knowledge:generated end -->

## 做什麼

`drift_baselines` 一列代表某個監測對象在某個範圍下，上次比對時存下的已知內容，供下次比對是否有變動。`subject_key` 是監測對象，`scope_key` 是範圍鍵，語意由各監測對象自己定義；`payload` 是存下的內容本身，`item_count` 是內容筆數（內容不是清單時為 NULL）。這是一張通用表，目前唯一的使用者是 FPay 代付銀行清單的每日稽核：每天向金流商查現行清單，與這張表存的基準比對，有改名或消失才告警。規則見相關規則。

## 相關規則

* [rules/payout-bank-code-mapping](../rules/payout-bank-code-mapping.md)
