---
type: Decision
id: decisions/0013-fpay-bank-list-audit-against-baseline
title: FPay 代付銀行清單稽核比對上次快照
description: FPay 代付銀行清單的每日稽核，比對的是 FPay 上次回的清單快照而不是我方銀行名稱；有差異時不更新快照，直到人工接受。
domain: 金流
tags: [代付銀行碼, FPay, 稽核, cash-flow:audit-fpay-bank-list, drift_baselines, 快照, 基準, 換碼, 誤匯, 代付, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Console/Commands/CashFlow/Maintenance/AuditFpayBankList.php, title: "cash-flow:audit-fpay-bank-list", at: 2026-10-21 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2255 }
related: [rules/payout-bank-code-mapping, decisions/0011-fpay-remap-group-classification]
decided_at: 2026-09-18
decision_status: accepted
---

# FPay 代付銀行清單稽核比對上次快照，差異未接受前不更新

## 背景

MR !2255 的描述指出，FPay 的銀行代號按「商戶帳號 × 幣別」各發一套，會在不通知的情況下整批換掉。換掉之後有兩種後果：

- 我方的代號在 FPay 那邊不存在了：送出被退回，取款失敗，錢沒跑掉，看得出來。
- 我方的代號還在，但已經是別家銀行：送出不會被退回，錢直接匯到別家銀行，看不出來。

先前沒有任何偵測機制，是靠取款失敗才發現，從故障到查明隔了三週半（target/op_tickets#3174）。

## 決定

`cash-flow:audit-fpay-bank-list` 每天 08:00 向 FPay 查現行清單，與 `drift_baselines` 存的上次快照比對；偵測到改名或代號消失時不更新快照，每天再報一次，直到人工跑 `--accept` 接受新快照。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 比對我方的銀行名稱 `banks.name` | 我方名稱（例「(IDR) - Bank Mandiri」）與 FPay 回的名稱（例「Mandiri Bank」）是各自獨立的兩套寫法，實查八筆逐字相同零筆，照工單字面做會天天誤報（MR !2255 的描述） |
| 每次執行都無條件更新快照 | 改名只會在變動當天報一次，那天沒人看到就再也抓不到（MR !2255 的描述） |
| 比對 FPay 上次回的快照，差異未接受前不更新（採用） | 無 |

## 後果

- 快照兩邊都是 FPay 自己回的字，格式一致，適用所有商戶帳號與幣別（MR !2255 的描述）。
- 「只多了幾家銀行」時快照照常跟上，否則那幾家永遠不在快照裡、日後被改名也偵測不到（MR !2255 的描述）。
- 另一道檢查看我方在用的代號還在不在清單上，沒修好就天天報（MR !2255 的描述）。
- `--accept` 沒有二次確認，差異還沒查清楚就誤按，等於把「錢可能匯錯家」的狀態確認為正常，而且下次不會再報（MR !2255 的描述）。
- 三方查詢連續失敗兩次才告警，這個門檻是依判斷定的，沒有 FPay 端點穩定度的實際數據支撐（MR !2255 的描述）。
