---
type: Decision
id: decisions/0009-payout-bank-code-guard-per-provider
title: 查不到代付銀行碼時由各金流商自己擋
description: 查不到代付銀行碼時，擋單的檢查放在各金流商自己的代付類別，不放在共用轉碼方法或派單前的共用檢查。
domain: 金流
tags: [代付銀行碼, convertPayoutBankCode, 代付, 擋單, FPay, GMPay, TakaPay, VaderPay, 代付失敗, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Services/Payment/Providers/FPay/Withdrawal.php, title: "查不到代付銀行碼時回失敗", at: 2026-10-21 }
  - { kind: code, ref: /app/Services/Payment/Providers/GMPay/Withdrawal.php }
  - { kind: code, ref: /app/Services/Payment/Providers/TakaPay/Withdrawal.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2215 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2228 }
related: [rules/payout-submit-and-status, decisions/0008-payout-bank-code-strict-empty-check]
decided_at: 2026-09-15
decision_status: accepted
---

# 查不到代付銀行碼時由各金流商自己擋

## 背景

- FPay 與 GMPay 組代付參數時直接放入 `convertPayoutBankCode()` 的結果。查不到對應時它回空字串，兩家照樣把空的 `bank_id`、`bank_name` 送給金流商，而兩家的接入文件都把這個欄位列為必填（MR !2215 的描述）。
- TakaPay 與 VaderPay 沒走共用取碼方法，各自查 `cash_flow_banks`，查詢沒限定啟用中也沒排序（MR !2228 的描述）。

## 決定

查不到代付銀行碼時，由各金流商的代付類別在建立代付紀錄之前回失敗，不在共用轉碼方法或派單前統一擋。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 在共用轉碼方法或派單前統一擋 | 會誤擋合法路徑：DgPay、TtPay 的 MYR 人工通道送的是銀行全名，用不到轉碼結果卻會先轉碼（TtPay 人工通道在 TPower、VWorld、Yes2Win 近一個月合計 15,043 筆）；LegendPay 印度代付完全不用銀行碼（MR !2215 的描述） |
| 其餘 17 處同樣沒擋的代付一起補 | 六站代付紀錄（每站取最近最多 5 萬筆）銀行碼空值 0 筆，目前沒有實際發生，留待改到該家時處理（MR !2215 的描述） |
| TakaPay、VaderPay 改走共用 `convertPayoutBankCode()` | 那支方法依灰度名單分流，名單外改讀金流商戶設定裡的 `available_banks`，那裡未必存著 TakaPay 需要的格式（未驗證），風險大於收益（MR !2228 的 commit 內文） |
| 在各家的代付類別補判斷（採用） | 無 |

## 後果

- 查不到代付銀行碼時本地直接失敗，取款訂單從「代付提交中」(21)轉成「三方失敗」(29)，可以重新發起（MR !2215 的描述）。
- 其餘 17 處未擋的代付沒有處理（MR !2215 的描述）。
- TakaPay、VaderPay 只取啟用中的對應是行為變更：某站的正確對應剛好停用著時，改版後一定撈不到、回失敗（MR !2228 的描述）。
- 依 id 排序取最早那筆沒有業務依據背書，用意只是讓結果確定（MR !2228 的描述）。
