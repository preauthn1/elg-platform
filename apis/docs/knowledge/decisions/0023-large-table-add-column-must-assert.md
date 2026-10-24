---
type: Decision
id: decisions/0023-large-table-add-column-must-assert
title: 大表加欄位的 migration 必須確認欄位建成
description: 大表加欄位的 migration 不能假設 SchemaMonitor 一定執行；要嘛手寫 DDL 明寫 ALGORITHM=INSTANT，要嘛在 SchemaMonitor 之後斷言欄位真的建成，沒建成就中止。
domain: 系統
tags: [migration, 大表, DDL, SchemaMonitor, ALGORITHM=INSTANT, hasColumn, 鎖表, member_wallets, orders, withdrawal_orders, Olympus, 系統]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Facades/SchemaMonitor.php, title: "超過列數門檻時靜默跳過的那一層", at: 2026-10-21 }
  - { kind: migration, ref: /database/migrations/2026_09_01_160000_add_currency_id_to_member_wallets_table.php, title: "手寫 DDL 明寫 ALGORITHM=INSTANT" }
  - { kind: migration, ref: /database/migrations/2026_09_16_000001_add_payment_cost_column_to_orders_and_withdrawal_orders_tables.php, title: "SchemaMonitor 之後斷言欄位建成" }
  - { kind: migration, ref: /database/migrations/2026_09_23_000002_add_withdraw_seq_and_tax_source_to_withdrawal_orders_table.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2109 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2224 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2325 }
related: [rules/currency-and-wallet-currency, rules/payment-cost-summary, rules/member-group-fee-tiers, rules/withdrawal-member-fee]
decided_at: 2026-09-03
decision_status: accepted
---

# 大表加欄位的 migration 必須確認欄位建成

## 背景

`SchemaMonitor::table()` 在大表超過列數門檻時會靜默跳過，migration 仍記為已執行（MR !2109 的描述）。三支 MR 都遇到要在大表加欄位：

- `member_wallets` 加 `currency_id`，這是入金、派彩、遊戲回調共寫的熱表（MR !2109 的描述）。
- `orders` 與 `withdrawal_orders` 加 `payment_cost`（MR !2224 的描述）。
- `withdrawal_orders` 加 `withdraw_seq` 與 `tax_source`，TPower 161 萬、VWorld 145 萬、Yes2Win 58 萬筆，超過 50 萬門檻（MR !2325 的描述）。

## 決定

大表加欄位的 migration 二選一：手寫 DDL 明寫 `ALGORITHM=INSTANT`，做不到就報錯；或走 `SchemaMonitor` 之後以 `Schema::hasColumn` 斷言欄位真的建成，沒建成就中止並寫明改用 Olympus 手動加欄位。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 走 `SchemaMonitor` 並假設它一定執行 | 大站的表可能超過列數門檻被靜默跳過，會出現「migration 顯示已跑、欄位卻不在」（MR !2325 的 commit 內文；MR !2109 的描述） |
| 不指定 ALGORITHM，交給 MySQL 挑 | 熱表上可能退化成鎖表重建；`member_wallets` 的 migration 明寫 `ALGORITHM=INSTANT`，做不到就報錯，不會退化成鎖表重建（MR !2109 的描述） |
| 手寫 INSTANT 或 SchemaMonitor 加斷言（採用） | 無 |

## 後果

- `ALGORITHM=INSTANT` 有前提：表的 `ROW_FORMAT` 不是 COMPRESSED、不是分割表；MR !2109 部署前逐站確認過（MR !2109 的描述）。
- VPlus33 的 `orders` 若超過 50 萬列會被斷言擋下，要改用 Olympus 加欄位後重跑 migrate（MR !2224 的描述）。
- `withdrawal_orders` 列入 `config/custom.php` 的 `schema_monitor.row_count_exempt_tables`，三站 MySQL 8.0.37 以上、加欄位走 INSTANT，migration 仍保留 `hasColumn` 斷言（MR !2325 的描述）。
