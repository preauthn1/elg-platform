---
type: Decision
id: decisions/0033-ccpayment-risky-refund-requery-original-deposit
title: CCPayment 風險退款同步逐筆回查原存款
description: CCPayment 風險交易的退款同步排程，付款地址與服務費都逐筆向金流商重新回查原存款取得，不讀充值訂單的 remark。
domain: 金流
tags: [CCPayment, 風險交易, 風險退款, ccpayment:sync-risky-refunds, RiskyRefundSync, deposit_manual_reviews, 回查, orders.remark, 手動上分, 充值訂單, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Services/Payment/Providers/CCPayment/RiskyRefundSync.php, title: "退款同步的判定規則", at: 2026-10-21 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2369 }
related: [rules/provider-ccpayment, decisions/0020-callback-trusts-requery-result]
decided_at: 2026-10-07
decision_status: accepted
---

# CCPayment 風險退款同步逐筆回查原存款

## 背景

CCPayment 標記為風險交易的充值，營運在金流商後台退款後，金流商不會通知我方，充值訂單會一直停在狀態 4「待手動確認上分」。已經退回付款人的單在新舊代理後台仍然能按「手動上分」，會員沒付錢就拿到點數（MR !2369 的描述）。

排程 `ccpayment:sync-risky-refunds` 每 10 分鐘查退款結果，依退款地址判斷是退回付款人還是退到營運方白名單地址，再更新訂單（MR !2369 的描述）。

## 決定

判斷需要的付款地址與服務費，都逐筆向金流商重新回查原存款取得，不讀 `orders.remark`。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 讀 `orders.remark` 取付款地址 | `remark` 是自由文字欄，舊代理後台手動上分時會覆寫它（MR !2369 的描述） |
| 逐筆重新回查原存款（採用） | 無 |

## 後果

- 這個做法的前提是金流商已確認處理後回查結果不會變（MR !2369 的描述）。
- 呼叫金流商都放在交易外；寫入時鎖住訂單與人工確認紀錄，重查確認「仍待處理、仍是狀態 4」才寫，所以重跑不會重複寫入（MR !2369 的描述）。
- 風險退款費不記：退款紀錄只有整次退款的網路費，系統拿不到每筆存款各自的實際值，由財務從金流商後台對帳（MR !2369 的描述）。
- 退回付款人的單（狀態 2）寫入的金流商手續費不會進代理數據看板的彙總，因為看板只算狀態 3；這是現行口徑，維持不變（MR !2369 的描述）。
