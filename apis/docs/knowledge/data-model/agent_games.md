---
type: Table
id: data-model/agent_games
title: 代理遊戲開放清單
description: 'agent_games 記錄每個代理各自開放了哪些遊戲給底下會員，由遊戲清單同步流程寫入、代理後台讀取。'
domain: 遊戲與遊戲商
tags:
  - agent_games
  - 遊戲清單
  - 代理
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T02:48:11+00:00'
evidence:
  - kind: code
    ref: /app/Models/AgentGame.php
    at: '2026-10-22'
  - kind: migration
    ref: /database/migrations/2025_06_19_091321_create_agent_games_table.php
entity:
  table: agent_games
  model: App\Models\AgentGame
  columns:
    - { name: id, type: 'int unsigned', nullable: false }
    - { name: agent_id, type: int, nullable: false, comment: 代理系統編號 }
    - { name: game_id, type: int, nullable: false, comment: 遊戲ID }
    - { name: status, type: int, nullable: false, comment: '狀態; 1:啟用, 0:停用' }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
    - { name: admin, type: int, nullable: false }
  read_by:
    - App\Models\AgentGame
  written_by:
    - App\Models\AgentGame
---

<!-- knowledge:generated start -->
## 表

`agent_games`

Model：`App\Models\AgentGame`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 |  |
| `agent_id` | int | 否 | 代理系統編號 |
| `game_id` | int | 否 | 遊戲ID |
| `status` | int | 否 | 狀態; 1:啟用, 0:停用 |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
| `admin` | int | 否 |  |
<!-- knowledge:generated end -->

## 做什麼

這張表一列代表某個代理開放了某一款遊戲（`status` 為啟用或停用）。遊戲商上架新遊戲、同步流程把它寫進 `games` 主檔時，會一併替有關聯的代理建好這張表的關聯列；客服之後在後台對個別代理開放或關閉遊戲，改的就是這張表。規則見相關規則。

## 相關規則

* [rules/game-list-sync](../rules/game-list-sync.md)
