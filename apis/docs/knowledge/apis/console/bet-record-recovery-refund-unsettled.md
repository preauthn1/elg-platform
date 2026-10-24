---
type: Command
id: apis/console/bet-record-recovery-refund-unsettled
title: 退回三方判定投注失敗、永不派彩的單一錢包未結算注單
description: 維運怎麼用命令把無縫錢包遊戲商判定投注失敗、永不派彩的未結算注單投注額退回玩家。
domain: 遊戲與遊戲商
tags:
  - bet-record-recovery-refund-unsettled
  - 無縫錢包
  - 未結算注單
  - 退回投注額
  - 補帳作業
  - FumaSW
  - PTSW
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T02:48:11+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/BetRecordRecovery/RefundUnsettled.php
    at: '2026-10-22'
entity:
  class: /app/Console/Commands/BetRecordRecovery/RefundUnsettled.php
  signature: 'bet-record-recovery:refund-unsettled'
---

<!-- knowledge:generated start -->
## 入口

`php artisan bet-record-recovery:refund-unsettled`

Command：`App\Console\Commands\BetRecordRecovery\RefundUnsettled`

退回三方判定投注失敗、永不派彩的單一錢包未結算注單（預設 dry-run）

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--vendor` | 選項（帶值） | 遊戲商代碼（fumasw / ptsw） |  |
| `--bet-id` | 選項（帶值） | 單筆注單 bet_id |  |
| `--bet-ids` | 選項（帶值） | 多筆 bet_id（逗號分隔） |  |
| `--bet-id-file` | 選項（帶值） | 從檔案讀 bet_id（每行一個；CSV 取第一欄、略過空行；帶 bet_id 表頭時其餘欄位作為該筆 meta，ptsw 必填） |  |
| `--execute` | 選項（旗標） | 真執行；預設 dry-run |  |
| `--reason` | 選項（帶值） | 退款原因（execute 模式必填） |  |
| `--by` | 選項（帶值） | 執行者標記，預設 command 名稱 |  |
| `--source` | 選項（帶值） | 資料來源標記，預設為檔名或 cli |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

FumaSW、PTSW 這類無縫錢包遊戲商，有時會因逾時判定投注失敗、之後不再送派彩或取消投注，讓玩家已扣款的投注停在未結算狀態；維運在容器內手動執行這支命令，把這類注單的投注額退回玩家原扣款的錢包，並留下補帳稽核紀錄供日後查核。規則見相關規則。

## 相關規則

* [rules/single-wallet-unsettled-refund](../../rules/single-wallet-unsettled-refund.md)
