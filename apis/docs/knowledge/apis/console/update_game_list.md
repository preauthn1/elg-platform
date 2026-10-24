---
type: Command
id: apis/console/update_game_list
title: 更新遊戲商的遊戲清單
description: 向遊戲商拉取最新遊戲清單、同步進遊戲主檔的命令說明。
domain: 遊戲與遊戲商
tags:
  - update_game_list
  - 遊戲清單
  - 排程
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T02:48:11+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/Schedule/UpdateVendorGameList.php
    at: '2026-10-22'
entity:
  class: /app/Console/Commands/Schedule/UpdateVendorGameList.php
  signature: update_game_list
  schedule:
    - '12 * * * *'
---

<!-- knowledge:generated start -->
## 入口

`php artisan update_game_list`

Command：`App\Console\Commands\Schedule\UpdateVendorGameList`

更新遊戲商的遊戲清單

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--vendor-id` | 選項（帶值） | 指定要更新的遊戲商 ID |  |
| `--direct` | 選項（旗標） | 同步執行不派發 queue（後台救援用，繞過 Job 唯一鎖） |  |
| `--dry-run` | 選項（旗標） | 乾跑模式，不寫入資料庫 |  |
| `--show-list` | 選項（旗標） | 額外列出三方回傳的完整遊戲清單（乾跑與正式執行皆生效） |  |
| `--sheet-url` | 選項（帶值） | 遊戲清單來源網址；僅 PTSW(144) 這類三方不給 API 的遊戲商需要 |  |

排程：`12 * * * *`
<!-- knowledge:generated end -->

## 做什麼

系統排程每小時 12 分執行這支命令，向支援同步遊戲清單的遊戲商抓最新的遊戲清單，把新遊戲寫進我方、並提示遊戲商下架的遊戲；Olympus 的「更新遊戲清單」頁與維運手動觸發也走同一支命令。可指定遊戲商、以 `--direct` 跳過 queue 同步執行，`--dry-run` 只看結果不寫入，`--show-list` 額外列出三方回傳的完整清單；PTSW 這類三方不提供 API 的遊戲商需另外帶遊戲清單來源網址。規則見相關規則。

## 相關規則

* [rules/game-list-sync](../../rules/game-list-sync.md)
