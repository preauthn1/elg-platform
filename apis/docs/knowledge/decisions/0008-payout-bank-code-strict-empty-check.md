---
type: Decision
id: decisions/0008-payout-bank-code-strict-empty-check
title: 代付銀行碼判空一律逐字比對
description: 判斷代付銀行碼或銀行名稱有沒有設定時，一律逐字比對空字串，不用 PHP 的 empty()，因為字串 "0" 是合法的代付銀行碼。
domain: 金流
tags: [代付銀行碼, provider_bank_code, empty, 判空, PayEssence, cash-flow:sync, 代付, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Console/Commands/CashFlow/SyncCashFlowsCommand.php, title: "extractWithdrawalBankInput() 的判空", at: 2026-10-21 }
  - { kind: code, ref: /app/Services/Payment/Providers/FPay/Withdrawal.php }
  - { kind: code, ref: /app/Services/Payment/Providers/TakaPay/Withdrawal.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2215 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2228 }
related: [rules/payout-submit-and-status]
decided_at: 2026-09-16
decision_status: accepted
---

# 代付銀行碼判空一律逐字比對

## 背景

代付送件前與同步命令建對照時，程式要判斷代付銀行碼有沒有設定。PayEssence 加密貨幣代付的合法代付銀行碼是字串 `"0"`（MR !2215、!2228 的描述）。

## 決定

代付銀行碼與銀行名稱判空一律逐字比對空字串（`=== ''`），不用 `empty()`。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 用 `empty()` 判空 | PHP 會把字串 `"0"` 判成空。同步命令因此讓 PayEssence 的 USDT(TRC20) 與 USDT(ERC20) 兩筆永遠建不起對應（MR !2228 的描述） |
| 等要開 PayEssence 泰達幣代付時再修同步命令 | 那天會先撞一次「對應怎麼都建不起來」，才回頭找原因；改動只有兩行判斷（MR !2228 的 commit 內文） |
| 逐字比對空字串（採用） | 無 |

## 後果

- 同步命令裡代碼或名稱是 `"0"` 會留下，前後空白會去掉，真正的空白仍然略過（MR !2228 的描述）。
- 這條規則由 RB 於 2026-09-15、2026-09-16 決定（MR !2215 的描述、MR !2228 的 commit 內文）。
