---
type: Decision
id: decisions/0026-payout-cost-bucketed-by-payment-time
title: 代付的金流商手續費彙總依 payment_time 分桶
description: 代付的金流商手續費彙總，三方成功與人工出款一律依取款訂單的 payment_time 分到小時，不再對三方成功另走代付紀錄的 callback_time。
domain: 金流
tags: [金流商手續費, 彙總, 代付, payment_time, callback_time, 分桶, PaymentCostSummaryService, withdrawal_orders, 代理數據看板, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Services/Summary/PaymentCostSummaryService.php, title: "代付段依 payment_time 一句完成", at: 2026-10-21 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2234 }
related: [rules/payment-cost-summary, decisions/0006-payment-cost-summary-php-single-writer]
decided_at: 2026-09-16
decision_status: accepted
---

# 代付的金流商手續費彙總依 payment_time 分桶

## 背景

MR !2233 的 `PaymentCostSummaryService` 代付段照舊預存程序的口徑：三方成功（24）走 `withdrawal_payout_records.callback_time`，人工出款（4）走 `payment_time`，兩段 UNION ALL。MR !2234 的描述說明那是當年 24 沒人寫 `payment_time` 的歷史包袱。target/tickets#3967 起回調成功與人工定案都會寫，歷史單也已回填：TPower 7 月起 status 4 與 24 共 137,876 筆，沒有一筆是 NULL（MR !2234 的描述）。

## 決定

代付段改為 `status IN (4, 24) AND payment_time BETWEEN` 一句完成，走 `(status, payment_time)` 索引，不再 JOIN 代付紀錄與「最新一筆成功紀錄」子查詢。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 三方成功依代付紀錄的 `callback_time`、人工出款依 `payment_time`，兩段 UNION ALL（舊預存程序口徑） | 這是 24 當年沒寫 `payment_time` 的歷史包袱；現在兩種狀態都會寫、歷史單已回填（MR !2234 的描述與 commit 內文） |
| 單一依 `payment_time`（採用） | 無 |

## 後果

- 口徑差異：以我方寫入的 `payment_time` 取代三方的 `callback_time`，兩者有秒級落差，極少數跨整點的單會落到相鄰的小時桶；按日看無感（MR !2234 的描述）。
- 上線後要對六站重跑 `order:recalc-payment-cost-summary --type=withdrawal --from=2026-07-01`，讓 7 到 9 月的代付彙總與新分桶口徑一致（MR !2234 的描述）。
- 同一支 MR 一併拿掉舊預存程序遺留的尾數處理：`payment_cost` 是 4 位、`point` 是 decimal(20,6)，直接加總不失真（MR !2234 的描述）。
