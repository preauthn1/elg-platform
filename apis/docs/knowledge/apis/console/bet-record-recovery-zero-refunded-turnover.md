---
type: Command
id: apis/console/bet-record-recovery-zero-refunded-turnover
title: 把已退款注單殘留的投注量歸零
description: '維運怎麼用命令把已取消投注、但注單與 TBR 上殘留的投注量歸零。'
domain: 遊戲與遊戲商
tags:
  - bet-record-recovery-zero-refunded-turnover
  - 補帳作業
  - 投注量
  - 取消投注
  - AFBSW
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/BetRecordRecovery/ZeroRefundedTurnover.php
    at: '2026-10-22'
entity:
  class: /app/Console/Commands/BetRecordRecovery/ZeroRefundedTurnover.php
  signature: 'bet-record-recovery:zero-refunded-turnover'
---

<!-- knowledge:generated start -->
## 入口

`php artisan bet-record-recovery:zero-refunded-turnover`

Command：`App\Console\Commands\BetRecordRecovery\ZeroRefundedTurnover`

把已退款注單殘留的投注量歸零，使其不再計入流水與返利（預設 dry-run）

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--vendor` | 選項（帶值） | 遊戲商代碼（目前僅 afbsw） |  |
| `--bet-id` | 選項（帶值） | 單筆注單 bet_id |  |
| `--bet-ids` | 選項（帶值） | 多筆 bet_id（逗號分隔） |  |
| `--bet-id-file` | 選項（帶值） | 從檔案讀 bet_id（每行一個；CSV 取第一欄、略過 bet_id 表頭與空行） |  |
| `--all-refunded` | 選項（旗標） | 掃該遊戲商全部已退款且投注量未歸零的注單（與 --bet-id 系列擇一） |  |
| `--limit` | 選項（帶值） | --all-refunded 的筆數上限，超過即中止並要求縮小範圍 | `500` |
| `--execute` | 選項（旗標） | 真執行；預設 dry-run |  |
| `--reason` | 選項（帶值） | 執行原因（execute 模式必填） |  |
| `--by` | 選項（帶值） | 執行者標記，預設 command 名稱 |  |
| `--source` | 選項（帶值） | 資料來源標記，預設為檔名或 cli |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

AFBSW 遊戲商的注單已取消投注、錢也已經退回玩家，但注單與 TBR（各家注單轉成同一組欄位之後的注單）上的投注量還留著時，維運手動執行這支命令，把這些注單的投注、有效投注、派彩、輸贏與禮碼清成 0 並寫入補帳稽核紀錄；玩家錢包與帳變不受影響。規則見相關規則。

## 相關規則

* [rules/single-wallet-unsettled-refund](../../rules/single-wallet-unsettled-refund.md)
