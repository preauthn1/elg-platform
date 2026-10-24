---
type: Decision
id: decisions/0010-manual-override-protects-provider-code-only
title: 人工覆寫標記只保護代付銀行碼
description: cash_flow_banks 被人工修過的列帶上覆寫標記，同步命令看到標記只跳過代付銀行碼一欄，銀行碼與重新啟用照常同步。
domain: 金流
tags: [代付銀行碼, provider_bank_code, cash_flow_banks, manual_override_at, 人工覆寫, cash-flow:sync, FPay, 代付, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Console/Commands/CashFlow/SyncCashFlowsCommand.php, title: "createCashFlowBankRelation() 跳過有標記列的代付銀行碼", at: 2026-10-21 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2232 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2236 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2251 }
related: [rules/payout-bank-code-mapping]
decided_at: 2026-09-16
decision_status: accepted
---

# 人工覆寫標記只保護代付銀行碼一欄

## 背景

- 共用同步命令 `cash-flow:sync` 原本無條件把 `cash_flow_banks` 的 `provider_bank_code` 覆寫成共用設定檔的值（MR !2236 的描述）。
- FPay 的銀行代號是每個商戶帳號各自一套。TPower 的商戶帳號在 2026-08-20 到 08-21 之間被 FPay 整批換碼，Yes2Win 用的是另一個商戶帳號、代號沒變（MR !2232 的描述）。
- 單一站點修好的代號會在下一次同步被沖回設定檔的舊值，target/op_tickets#3174 就這樣壞了三週半（MR !2236 的描述）。

## 決定

被人工修過的列寫上 `manual_override_at` 與 `manual_override_note`；同步命令遇到有標記的列，只跳過 `provider_bank_code` 的覆寫，`bank_code` 與重新啟用照常同步。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 直接改共用設定檔 `Data/providers/FPay.php` | Yes2Win 用另一個商戶帳號、代號沒變、出款正常，共用設定檔不能改，只能單獨修 TPower 的資料（MR !2232 的描述） |
| 有標記的列整列跳過同步 | 同步會先把該金流商該幣別的全部關聯設為 `status = 0`，再把比對得上的重新啟用；整列跳過會讓被保護的列停在 `status = 0`，代付查無而斷付（MR !2236 的描述） |
| 只保護 `provider_bank_code` 一欄（採用） | 無 |

## 後果

- 標記不保護「整列被停用」這類缺口，例如銀行名稱比對不中；這一點寫在 `createCashFlowBankRelation()` 的方法說明（MR !2236 的描述）。
- 同步命令全量重建後，被停用的列要用 `bank:reactivate-cfb-rows` 重新啟用（MR !2251 的描述）。
- 同步報表會列出「保留人工覆寫值」與標記原因，讓跑同步的人看得到哪些列被保護（MR !2236 的描述）。
- 本決定上線時，先前已換好的 353 筆代號沒有標記，部署後要再跑一次 `cash-flow:remap-tpower-fpay-idr-bankcodes --execute` 補標記（MR !2236 的描述）。
