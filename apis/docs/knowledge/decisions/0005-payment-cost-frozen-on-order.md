---
type: Decision
id: decisions/0005-payment-cost-frozen-on-order
title: 金流商手續費在訂單上凍結
description: 金流商手續費在交易成功時寫進充值訂單與取款訂單並凍結，彙總與分潤直接加總這個值，不在彙總時以成本費率現算。
domain: 金流
tags: [金流商手續費, payment_cost, 成本費率, cost, 彙總, 分潤, SR99, 代理數據看板, 充值訂單, 取款訂單, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Models/AgentCashFlow.php, title: "paymentCostFor() 的換算口徑", at: 2026-10-21 }
  - { kind: code, ref: /app/Services/PaymentService.php, title: "三方代收成功時寫入 payment_cost" }
  - { kind: migration, ref: /database/migrations/2026_09_16_000001_add_payment_cost_column_to_orders_and_withdrawal_orders_tables.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2224 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2225 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2238 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2335 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2354 }
related: [rules/payment-cost-summary, rules/deposit-order-fees, rules/payment-channel-management, rules/provider-b3wallet]
decided_at: 2026-09-17
decision_status: accepted
---

# 金流商手續費在訂單上凍結，彙總直接加總

## 背景

MR !2224 的描述指出，SR99 要另加一欄金流商手續費，但這筆費用當時只存在彙總層：預存程序以 `agent_cash_flows.cost × point` 事後現算，訂單上沒有凍結值。

- 費率改了，歷史數字跟著變（MR !2224 的描述）。
- 預存程序外層依會員分組，卻用未聚合的費率。同一會員同一小時走兩條支付通道時，MySQL 任選其中一條的費率乘全部金額（MR !2225 的描述，TPower 實例應為 10、寫成 13）。
- 分潤預存程序 `SP_calc_profit_share_payment_cost` 同樣現算，重算歷史日期時會套用改過的新費率（MR !2354 的描述）。

## 決定

`orders.payment_cost` 與 `withdrawal_orders.payment_cost` 在交易成功時以「站點主幣別金額 × 成本費率」寫入並凍結，看板彙總與分潤都只加總這個欄位。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 彙總時以成本費率現算（原做法） | 費率改了歷史數字跟著變，跨支付通道時任選一個費率（MR !2224、!2225 的描述） |
| 三方代收在建單時寫入（MR !2224 的寫法） | 自助上分自己建單、沒走 `OrderService::createOrder`，上線首日 TPower 330 筆、VWorld 47 筆、Yes2Win 36 筆的值是 0。MR !2238 的描述定調三方代收的成本發生在付款成功那一刻 |
| 三方代收在回調成功時寫入；自助上分在建單時寫入；代付在成功轉 24 時依實際出款的金流商戶寫入（採用） | 無 |

## 後果

- 預存程序直接加總、不留退路，未回補的訂單會被算成 0，所以要等各站點跑完 `order:backfill-payment-cost` 才合併（MR !2225 的描述）。
- 自助上分在部署前建立的訂單靠回補命令補（MR !2238 的描述）。
- 看板彙總只算 status 3，待審核的訂單不計入（MR !2238 的描述）。
- 改成本費率只影響之後成功的訂單，已入帳的不回補（MR !2335 的描述）。
- 分潤預存程序改為加總兩張表的 `payment_cost`，上線前要先確認 Yes2Win 已回補，否則舊單成本為 0（MR !2354 的描述）。
