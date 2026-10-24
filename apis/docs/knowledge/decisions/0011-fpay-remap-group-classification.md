---
type: Decision
id: decisions/0011-fpay-remap-group-classification
title: FPay 銀行代號換碼採整組判定
description: FPay 印尼盾代付銀行代號的換碼命令，以整組代號判定是舊代號還是新代號，不逐列推斷；新舊混雜時一律中止轉人工。
domain: 金流
tags: [代付銀行碼, FPay, 換碼, 印尼盾, IDR, cash-flow:remap-tpower-fpay-idr-bankcodes, 整組判定, 誤匯, 代付, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Console/Commands/CashFlow/Guard/RemapTpowerFpayIdrBankCodes.php, title: "classifyCodes() 整組判定", at: 2026-10-21 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2232 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2241 }
related: [rules/payout-bank-code-mapping, decisions/0010-manual-override-protects-provider-code-only]
decided_at: 2026-09-17
decision_status: accepted
---

# FPay 銀行代號換碼採整組判定

## 背景

TPower 的 FPay 印尼盾代付自 2026-08-21 起每一筆都被退回「bank id invalid」，原因是 FPay 把這個商戶帳號的代號整批換掉（MR !2232 的描述）。

有 8 個代號（6、8、11、12、13、16、26、27）在新舊兩套裡都有效，但指向不同銀行，例如代號 11 舊的是 UOB、新的是 BRI。照舊代號送出不會被退回，款項會匯到別家銀行（MR !2232 的描述）。

## 決定

換碼命令以整組代號判定：整組都是舊代號才換、整組都是新代號就跳過、新舊混雜或只剩這 8 個重號時一律中止轉人工；寫入端照這個判定結果寫，不再逐列推斷。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 逐筆比對每一列的代號 | 單看一列的代號分不出是舊的還是新的（MR !2241 的描述）。2026-09-16 補標記功能時，寫入端改成逐列查「舊代號到新代號」對照表，對這 8 個代號必然查得到，2026-09-17 在 TPower 正式環境把 `cash_flow_banks` 的 7 列改成了別家銀行的代號（MR !2241 的描述） |
| 整組判定，寫入端沿用判定結果（採用） | 無 |

## 後果

- 被寫壞的 7 列持有的值本身是合法的新代號，整組判定仍會判成「已經是新代號」，所以另加校正模式 `--align-from-payout-config`，以代付設定的 `available_banks` 為準改回來（MR !2241 的描述）。
- 校正模式要求兩條代付設定（272 正式組、264 測試組）的對映完全相同才放行。不同代表其中一條已經被動過，沒有可信的權威，寧可轉人工（MR !2241 的 commit 內文）。
- 這支命令不是 sync-safe：`cash-flow:sync` 或 `agent:add-agent-cash-flow` 任一跑過都要重跑本命令（MR !2232 的描述）。
