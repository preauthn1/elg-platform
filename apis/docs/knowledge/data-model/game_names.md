---
type: Table
id: data-model/game_names
title: 遊戲多語系名稱
description: 'game_names 記錄每款遊戲在各語系下顯示的名稱，由遊戲清單同步流程寫入、前台與後台依語系讀取。'
domain: 遊戲與遊戲商
tags:
  - game_names
  - 遊戲清單
  - 多語系
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T02:48:11+00:00'
evidence:
  - kind: code
    ref: /app/Models/GameName.php
    at: '2026-10-22'
  - kind: migration
    ref: /database/migrations/2025_06_19_091321_create_game_names_table.php
entity:
  table: game_names
  model: App\Models\GameName
  columns:
    - { name: id, type: 'int unsigned', nullable: false, comment: 流水號 }
    - { name: language_id, type: int, nullable: false, comment: 語言系統編號 }
    - { name: game_id, type: int, nullable: false, comment: 遊戲系統編號 }
    - { name: name, type: varchar(191), nullable: false, comment: 遊戲名稱 }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
    - { name: admin, type: int, nullable: false, comment: 更新者ID }
  read_by:
    - App\Models\GameName
  written_by:
    - App\Models\GameName
---

<!-- knowledge:generated start -->
## 表

`game_names`

Model：`App\Models\GameName`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 | 流水號 |
| `language_id` | int | 否 | 語言系統編號 |
| `game_id` | int | 否 | 遊戲系統編號 |
| `name` | varchar(191) | 否 | 遊戲名稱 |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
| `admin` | int | 否 | 更新者ID |
<!-- knowledge:generated end -->

## 做什麼

這張表一列代表某一款遊戲在某一個語系底下顯示的名稱。遊戲商上架新遊戲、同步流程把它寫進 `games` 主檔時，會一併替每個語系建好對應的名稱列；會員 App 與代理後台顯示遊戲名稱時依當前語系讀這張表。規則見相關規則。

## 相關規則

* [rules/game-list-sync](../rules/game-list-sync.md)
