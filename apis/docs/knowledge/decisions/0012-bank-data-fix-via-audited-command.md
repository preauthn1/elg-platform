---
type: Decision
id: decisions/0012-bank-data-fix-via-audited-command
title: 銀行資料的正式庫修正走專用命令
description: 正式庫的銀行資料修正寫成可預演、有預檢、有審計的專用 artisan 命令，不直接下 SQL，也不只在後台改。
domain: 金流
tags: [銀行, 正式庫, 資料修正, bank:reactivate-cfb-rows, bank:set-name, bank_dedup_logs, 審計, cash_flow_banks, banks, 代付, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Console/Commands/CashFlow/Bank/ReactivateCfbRowsCommand.php, title: "bank:reactivate-cfb-rows", at: 2026-10-21 }
  - { kind: code, ref: /app/Console/Commands/CashFlow/Bank/SetBankNameCommand.php, title: "bank:set-name" }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2251 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2286 }
related: [rules/payout-bank-code-mapping, rules/standard-bank-list]
decided_at: 2026-09-18
decision_status: accepted
---

# 銀行資料的正式庫修正走專用命令

## 背景

- TPower 的 FPay 印尼盾 BCA 那列對照（`cash_flow_banks.id = 360`）被同步命令停用，代付銀行碼還停在換碼前的舊值；既有命令只會新增列或停用列，沒有一支能把停用的列開回來（MR !2251 的描述）。
- TPower 與 Wintoto 要把 USDT 取款銀行的名稱統一成「USDT - TRC20」「USDT - ERC20」（MR !2286 的描述）。

## 決定

正式庫的銀行資料修正寫成專用 artisan 命令：預設只預演，加 `--execute` 才寫入；`--reason` 必填；預檢任一筆不合格就整批不寫；交易內以 `lockForUpdate` 重驗後才寫；每改一列在 `bank_dedup_logs` 寫一筆審計。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 在正式庫直接下 SQL | 會少掉審計、預檢和人工覆寫標記，幾個月後沒人知道這列為什麼是這樣（MR !2251 的描述）；也違反正式庫禁止直接寫入的規則，沒有審計可以追溯與還原（MR !2286 的描述） |
| 只在後台改支付組別層的名稱 | 建代付金流商戶時，`AgentBankSyncService::syncToAgentGroup()` 會照 base 層整列覆寫支付組別層，名稱會被蓋回（MR !2286 的描述） |
| 擴充 `bank:fix-mismatch` | 它以標準銀行的名稱為準，而加密貨幣（`banks.type = 3`）不在標準銀行的範圍內（MR !2286 的描述） |
| 把命令放進銀行收斂長期分支 | 長期分支暫不開 MR，放進去的命令進不了 master（MR !2251 的描述） |
| 從 master 開分支寫自包自足的專用命令（採用） | 無 |

## 後果

- `bank:reactivate-cfb-rows` 寫入時一併寫人工覆寫標記，同步命令不再覆寫它的代付銀行碼（MR !2251 的描述）。
- `bank:dedup-rollback` 只回放它自己的五種動作，不處理 `bank:set-name` 的紀錄；要還原就照審計裡的原名稱改回去（MR !2286 的描述）。
- 兩支命令都已在 RB 授權下對正式庫實跑過，審計列分別是 `bank_dedup_logs.id` 1037（MR !2251 的描述）與 1038、1039、1763 到 1766（MR !2286 的描述）。
