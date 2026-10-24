---
type: Table
id: data-model/games
title: 遊戲主檔
description: 'games 是遊戲商旗下每一款遊戲的主檔，記錄所屬遊戲商、遊戲類型與有效投注轉換比例，由遊戲清單同步流程寫入。'
domain: 遊戲與遊戲商
tags:
  - games
  - 遊戲清單
  - 有效投注
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T02:48:11+00:00'
evidence:
  - kind: code
    ref: /app/Models/Game.php
    at: '2026-10-22'
  - kind: migration
    ref: /database/migrations/2025_06_19_091321_create_games_table.php
entity:
  table: games
  model: App\Models\Game
  columns:
    - { name: id, type: 'int unsigned', nullable: false, comment: 流水號 }
    - { name: vendor_id, type: int, nullable: false, comment: 遊戲商系統編號 }
    - { name: type_id, type: int, nullable: false, comment: 遊戲類型 }
    - { name: join_bet_event, type: tinyint(1), nullable: false, comment: '是否可參加投注額活動，0:不可，1:可以' }
    - { name: join_win_event, type: tinyint(1), nullable: false, comment: '是否可參加贏分活動，0:不可，1:可以' }
    - { name: game_type, type: varchar(191), nullable: false, comment: 遊戲代碼 }
    - { name: name, type: varchar(191), nullable: false, comment: 名稱 }
    - { name: lottery_pool, type: 'decimal(11,3)', nullable: true, comment: 彩池金額 }
    - { name: image, type: varchar(191), nullable: true, comment: 遊戲圖片 }
    - { name: is_new, type: tinyint(1), nullable: true, comment: '是否為新遊戲(0:否,1:是)' }
    - { name: is_hot, type: tinyint(1), nullable: true, comment: '是否為熱門遊戲(0:否,1:是)' }
    - { name: open_window, type: tinyint, nullable: false, comment: '是否另開視窗; 1:另開, 0:不另開' }
    - { name: status, type: tinyint, nullable: false, comment: 狀態：0停用，1啟用 }
    - { name: rtp, type: 'decimal(5,2)', nullable: true, comment: RTP }
    - { name: sort, type: int, nullable: true, comment: 排序 }
    - { name: info, type: text, nullable: true, comment: info }
    - { name: remark, type: varchar(191), nullable: true, comment: 備註 }
    - { name: memo, type: varchar(100), nullable: true, comment: 內部人員備註用 }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
    - { name: admin, type: int, nullable: false, comment: 更新者ID }
    - { name: valid_bet_rate, type: 'decimal(4,2)', nullable: true, comment: 有效投注轉換比例 }
    - { name: calc_flow_limit, type: tinyint, nullable: true, comment: 是否計算稽核流水 }
  read_by:
    - App\Models\Game
  written_by:
    - App\Models\Game
---

<!-- knowledge:generated start -->
## 表

`games`

Model：`App\Models\Game`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 | 流水號 |
| `vendor_id` | int | 否 | 遊戲商系統編號 |
| `type_id` | int | 否 | 遊戲類型 |
| `join_bet_event` | tinyint(1) | 否 | 是否可參加投注額活動，0:不可，1:可以 |
| `join_win_event` | tinyint(1) | 否 | 是否可參加贏分活動，0:不可，1:可以 |
| `game_type` | varchar(191) | 否 | 遊戲代碼 |
| `name` | varchar(191) | 否 | 名稱 |
| `lottery_pool` | decimal(11,3) | 是 | 彩池金額 |
| `image` | varchar(191) | 是 | 遊戲圖片 |
| `is_new` | tinyint(1) | 是 | 是否為新遊戲(0:否,1:是) |
| `is_hot` | tinyint(1) | 是 | 是否為熱門遊戲(0:否,1:是) |
| `open_window` | tinyint | 否 | 是否另開視窗; 1:另開, 0:不另開 |
| `status` | tinyint | 否 | 狀態：0停用，1啟用 |
| `rtp` | decimal(5,2) | 是 | RTP |
| `sort` | int | 是 | 排序 |
| `info` | text | 是 | info |
| `remark` | varchar(191) | 是 | 備註 |
| `memo` | varchar(100) | 是 | 內部人員備註用 |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
| `admin` | int | 否 | 更新者ID |
| `valid_bet_rate` | decimal(4,2) | 是 | 有效投注轉換比例 |
| `calc_flow_limit` | tinyint | 是 | 是否計算稽核流水 |
<!-- knowledge:generated end -->

## 做什麼

這張表一列代表某個遊戲商底下的一款遊戲，記錄所屬 `vendor_id`、遊戲類型、能否參加投注額或贏分活動，以及可覆寫該遊戲商預設值的 `valid_bet_rate`（有效投注轉換比例）。遊戲商上架新遊戲時，由遊戲清單同步流程寫入新列；同步只新增、不自動關閉遊戲商下架的遊戲，下架的只列出來提示人工停用。規則見相關規則。

## 相關規則

* [rules/game-list-sync](../rules/game-list-sync.md)
