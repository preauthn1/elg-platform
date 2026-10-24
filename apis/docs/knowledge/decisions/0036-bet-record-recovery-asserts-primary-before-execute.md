---
type: Decision
id: decisions/0036-bet-record-recovery-asserts-primary-before-execute
title: 注單補帳命令執行前自己確認寫入連線落在主庫
description: 無縫錢包未結算退款與退款注單投注量歸零兩支補帳命令，正式執行前先查寫入連線的 read_only，為 1 就中止，不依賴資料庫擋。
domain: 遊戲與遊戲商
tags: [補帳, bet-record-recovery, refund-unsettled, zero-refunded-turnover, 主庫, 從庫, read_only, CONNECTION_ADMIN, dbc, 複寫分歧]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Console/Commands/BetRecordRecovery/RefundUnsettled.php, at: 2026-10-22 }
  - { kind: code, ref: /app/Console/Commands/BetRecordRecovery/ZeroRefundedTurnover.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2120 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2128 }
related: [rules/single-wallet-unsettled-refund]
decided_at: 2026-09-06
decision_status: accepted
---

# 注單補帳命令執行前自己確認寫入連線落在主庫

## 背景

本機經 dbc 連白標資料庫時，APIs 的寫入主機設定預設指向從庫（34xx port，`read_only=1`）。dbc 帳號的角色帶全域寫入權且持有 CONNECTION_ADMIN，會繞過 read_only，直接把退款寫進從庫、造成複寫分歧（MR !2120 的 commit body）。

## 決定

- `--execute` 模式在處理任何注單前，先查寫入連線的 `@@read_only`；為 1 就中止，提示改指主庫（dbc 的 35xx port）（MR !2120 的 commit body）。
- 乾跑不探測寫入連線（MR !2120 的 commit body）。
- 之後新增的退款注單投注量歸零命令沿用同一道檢查（MR !2128 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 交給資料庫的 read_only 擋 | 持有 CONNECTION_ADMIN 的帳號會繞過 read_only，伺服器不會擋（MR !2120、MR !2128 的 commit body） |
| 命令自己在執行前檢查（採用） | 無 |

## 後果

- 兩支命令都預設乾跑，`--execute` 必填 `--reason`（MR !2120、MR !2128 的 commit body）。
