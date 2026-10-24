---
type: Table
id: data-model/rgsw_bet_records
title: 'RGSW 注單'
description: 'rgsw_bet_records 存放 RGSW（RG 無縫錢包版）的注單，由 RGSW 回調寫入與更新。'
domain: 遊戲與遊戲商
tags:
  - rgsw_bet_records
  - RGSW
  - RG
  - 無縫錢包
  - 注單
  - 回調
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Vendors/Models/RgswBetRecord.php
    at: '2026-10-22'
  - kind: migration
    ref: /database/migrations/2026_06_26_000001_create_rgsw_accounts_table.php
entity:
  table: rgsw_bet_records
  model: App\Vendors\Models\RgswBetRecord
  columns:
    - { name: id, type: 'int unsigned', nullable: false }
    - { name: vendor_info_id, type: int, nullable: false, comment: 遊戲商設定編號 }
    - { name: member_id, type: int, nullable: false, comment: 會員系統編號 }
    - { name: username, type: varchar(100), nullable: false, comment: 玩家帳號 }
    - { name: bet_id, type: varchar(100), nullable: false, comment: 注單編號（同回合可多筆） }
    - { name: reference_no, type: varchar(100), nullable: false, comment: 交易編號（唯一） }
    - { name: game_code, type: varchar(100), nullable: false, comment: 遊戲代碼 }
    - { name: game_type, type: varchar(20), nullable: false, comment: '遊戲類型 slot/fishing/chess' }
    - { name: status, type: tinyint, nullable: false, comment: '1:BET 2:PAYOUT 3:ENDROUND 4:BETPAYOUT 5:REFUND 6:DEBIT 7:CREDIT 8:SUMBET' }
    - { name: bet, type: 'decimal(18,4)', nullable: false, comment: 下注金額 }
    - { name: valid_bet, type: 'decimal(18,4)', nullable: false, comment: 有效投注 }
    - { name: win_lose, type: 'decimal(18,4)', nullable: false, comment: 輸贏金額 }
    - { name: win, type: 'decimal(18,4)', nullable: false, comment: 派彩金額 }
    - { name: bonus, type: 'decimal(18,4)', nullable: false, comment: 活動獎金 }
    - { name: jackpot, type: 'decimal(18,4)', nullable: false, comment: 彩金 }
    - { name: rebate, type: 'decimal(18,4)', nullable: false, comment: 返水 }
    - { name: rolling_chip, type: 'decimal(18,4)', nullable: false, comment: 使用禮碼 }
    - { name: bet_time, type: datetime, nullable: true, comment: 下注時間 }
    - { name: payout_time, type: datetime, nullable: true, comment: 派彩時間 }
    - { name: info, type: text, nullable: true, comment: 原始請求資訊 }
    - { name: created_at, type: timestamp, nullable: true }
    - { name: updated_at, type: timestamp, nullable: true }
  read_by:
    - App\Vendors\Models\RgswBetRecord
  written_by:
    - App\Vendors\Models\RgswBetRecord
---

<!-- knowledge:generated start -->
## 表

`rgsw_bet_records`

Model：`App\Vendors\Models\RgswBetRecord`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 |  |
| `vendor_info_id` | int | 否 | 遊戲商設定編號 |
| `member_id` | int | 否 | 會員系統編號 |
| `username` | varchar(100) | 否 | 玩家帳號 |
| `bet_id` | varchar(100) | 否 | 注單編號（同回合可多筆） |
| `reference_no` | varchar(100) | 否 | 交易編號（唯一） |
| `game_code` | varchar(100) | 否 | 遊戲代碼 |
| `game_type` | varchar(20) | 否 | 遊戲類型 slot/fishing/chess |
| `status` | tinyint | 否 | 1:BET 2:PAYOUT 3:ENDROUND 4:BETPAYOUT 5:REFUND 6:DEBIT 7:CREDIT 8:SUMBET |
| `bet` | decimal(18,4) | 否 | 下注金額 |
| `valid_bet` | decimal(18,4) | 否 | 有效投注 |
| `win_lose` | decimal(18,4) | 否 | 輸贏金額 |
| `win` | decimal(18,4) | 否 | 派彩金額 |
| `bonus` | decimal(18,4) | 否 | 活動獎金 |
| `jackpot` | decimal(18,4) | 否 | 彩金 |
| `rebate` | decimal(18,4) | 否 | 返水 |
| `rolling_chip` | decimal(18,4) | 否 | 使用禮碼 |
| `bet_time` | datetime | 是 | 下注時間 |
| `payout_time` | datetime | 是 | 派彩時間 |
| `info` | text | 是 | 原始請求資訊 |
| `created_at` | timestamp | 是 |  |
| `updated_at` | timestamp | 是 |  |
<!-- knowledge:generated end -->

## 做什麼

這張表存放 RGSW（遊戲商 RG 的無縫錢包版）的注單，一局在我方只有一張注單、一列。玩家查餘額、下注、取得派彩，或 RG 取消投注、事後補款、扣款、追加投注時，都由 RGSW 回調直接寫入或改這同一列。規則見相關規則。

## 相關規則

* [rules/vendor-rgsw](../rules/vendor-rgsw.md)
