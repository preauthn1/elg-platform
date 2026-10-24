---
type: Decision
id: decisions/0007-payment-cost-rate-based-ccpayment-actual
title: 金流商手續費依成本費率計算，CCPayment 例外記實際值
description: 金流商手續費預設以「點數 × 成本費率」計算，不採三方回調回報的手續費；CCPayment 的充值訂單與取款訂單改記向金流商回查到的實際值。
domain: 金流
tags: [金流商手續費, payment_cost, 成本費率, cost, FeeAmount, serviceFee, 網路費, CCPayment, B3Wallet, SR99, 對帳, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Services/Payment/Providers/CCPayment/CCPaymentTrait.php, title: "actualPaymentCost() 換算回查的實際值", at: 2026-10-21 }
  - { kind: code, ref: /app/Services/Payment/Providers/B3Wallet/WithdrawalCallback.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2354 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2352 }
related: [rules/payment-cost-summary, rules/provider-b3wallet, rules/provider-ccpayment, decisions/0005-payment-cost-frozen-on-order]
decided_at: 2026-10-07
decision_status: accepted
---

# 金流商手續費依成本費率計算，CCPayment 例外記實際值

## 背景

兩支 MR 各自處理「金流商手續費要用我方費率算，還是用三方給的數字」：

- B3Wallet 代付回調帶 `FeeAmount`、`FeeRate`、`TotalDeductAmount`，分支一度改成據此寫入，最後改回不採用（MR !2354 的描述）。
- CCPayment 實測對代收收 0.5% 服務費，SR99 的「支付商手續費」直接讀 `payment_cost`，財務要照它跟金流商對帳（MR !2352 的描述）。

## 決定

金流商手續費預設以「點數 × 成本費率」計算；CCPayment 例外，充值訂單寫「回查的 `serviceFee` × 匯率」，取款訂單寫「回查的網路費 × 匯率」。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 一律採三方回調回報的手續費（MR !2354 第一顆 commit 的做法） | 那是三方自己的帳，我方成本以合約費率為準；同一家金流商代收與代付兩邊算法不一致，利潤分成會對不起來（MR !2354 的描述） |
| CCPayment 也照成本費率算 | SR99 讀這個欄位，記實際值財務才能照它跟金流商對帳（MR !2352 的描述） |
| CCPayment 的服務費寫進會員手續費 `orders.tax` | `tax` 是向會員收的，寫進去會減少會員到帳的點數（MR !2352 的描述） |
| 預設依成本費率，CCPayment 記回查實際值（採用） | 無 |

## 後果

- B3Wallet 回調的三個費用欄位仍收進驗證白名單，回調原文完整留在 `withdrawal_payout_records` 供日後對帳；為它在共用層新增的可選參數撤回（MR !2354 的描述）。
- CCPayment 的網路費不論由會員或商戶負擔都照記，這是 PM 的決定（MR !2352 的描述）。
- CCPayment 回查值缺漏，或網路費代幣跟出款代幣不同時，照常入帳或定案、告警，並退回成本費率算法，避免手續費資料的問題卡住會員的錢（MR !2352 的描述）。
- 實際手續費剛好是 0 時跟「沒寫」分不出來，入帳時會退回成本費率算法（MR !2352 的描述）。
- 風險交易建成的待人工確認單不寫，因為服務費要等退款成功才從商戶餘額扣（MR !2352 的描述）。
- 人工定案代付的命令 `ResolvePayoutOrder` 仍寫成本費率算法的值（MR !2352 的描述）。
