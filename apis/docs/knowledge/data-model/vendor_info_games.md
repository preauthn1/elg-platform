---
type: Table
id: data-model/vendor_info_games
title: 遊戲商戶的遊戲設定
description: 'vendor_info_games 記錄每個遊戲商戶底下有哪些遊戲、各自的投注與活動參與設定。'
domain: 遊戲與遊戲商
tags:
  - vendor_info_games
  - 遊戲商戶
  - 遊戲
  - 有效投注比例
  - 遊戲清單同步
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T02:48:11+00:00'
evidence:
  - kind: code
    ref: /app/Models/VendorInfoGame.php
    at: '2026-10-22'
  - kind: migration
    ref: /database/migrations/2025_06_19_091321_create_vendor_info_games_table.php
entity:
  table: vendor_info_games
  model: App\Models\VendorInfoGame
  columns:
    - { name: id, type: 'int unsigned', nullable: false }
    - { name: vendor_info_id, type: int, nullable: false, comment: 商戶資料ID }
    - { name: game_id, type: int, nullable: false, comment: 遊戲ID }
    - { name: join_bet_event, type: tinyint, nullable: false, comment: '是否可參加投注額活動(0:不可，1:可以)' }
    - { name: join_win_event, type: tinyint, nullable: false, comment: '是否可參加贏分活動(0:不可，1:可以)' }
    - { name: is_new, type: tinyint, nullable: false, comment: '是否為新遊戲(0:否,1:是)' }
    - { name: is_hot, type: tinyint, nullable: false, comment: '是否為熱門遊戲(0:否,1:是)' }
    - { name: sort, type: int, nullable: false, comment: 排序 }
    - { name: valid_bet_rate, type: 'decimal(4,2)', nullable: false, comment: 有效投注轉換比例 }
    - { name: calc_flow_limit, type: tinyint, nullable: true, comment: 是否計算稽核流水 }
    - { name: status, type: tinyint, nullable: false, comment: 狀態(0停用，1啟用) }
    - { name: bettable, type: tinyint, nullable: false, comment: '遊戲是否可投注(0:否,1:是)' }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
    - { name: admin, type: int, nullable: false }
  read_by:
    - App\Models\VendorInfoGame
  written_by:
    - App\Models\VendorInfoGame
---

<!-- knowledge:generated start -->
## 表

`vendor_info_games`

Model：`App\Models\VendorInfoGame`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 |  |
| `vendor_info_id` | int | 否 | 商戶資料ID |
| `game_id` | int | 否 | 遊戲ID |
| `join_bet_event` | tinyint | 否 | 是否可參加投注額活動(0:不可，1:可以) |
| `join_win_event` | tinyint | 否 | 是否可參加贏分活動(0:不可，1:可以) |
| `is_new` | tinyint | 否 | 是否為新遊戲(0:否,1:是) |
| `is_hot` | tinyint | 否 | 是否為熱門遊戲(0:否,1:是) |
| `sort` | int | 否 | 排序 |
| `valid_bet_rate` | decimal(4,2) | 否 | 有效投注轉換比例 |
| `calc_flow_limit` | tinyint | 是 | 是否計算稽核流水 |
| `status` | tinyint | 否 | 狀態(0停用，1啟用) |
| `bettable` | tinyint | 否 | 遊戲是否可投注(0:否,1:是) |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
| `admin` | int | 否 |  |
<!-- knowledge:generated end -->

## 做什麼

這張表記錄一個遊戲商戶底下有哪些遊戲，以及這個商戶對這款遊戲的個別設定：是否可投注、有效投注轉換比例、是否參加投注額或贏分活動、是否標為新遊戲或熱門遊戲。同步遊戲商遊戲清單時補新遊戲的關聯，客服在代理後台要開放遊戲給會員時改這張表的狀態。規則見相關規則。

## 相關規則

* [rules/game-list-sync](../rules/game-list-sync.md)
