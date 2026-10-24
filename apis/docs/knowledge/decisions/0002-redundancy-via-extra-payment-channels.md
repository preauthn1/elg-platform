---
type: Decision
id: decisions/0002-redundancy-via-extra-payment-channels
title: 備援與分流用多開支付通道
description: 多開支付通道做備援與分流，不在同一條通道下掛多個金流商戶。
domain: 金流
tags: [支付通道, 金流商戶, agent_cash_flow_order_types, 備援, 分流, 金流, order_type]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
evidence:
  - { kind: code, ref: /app/Models/AgentCashFlow.php, title: "agent_cash_flow_order_types 多對多關聯所在的 Model", at: 2026-10-21 }
decided_at: 2026-09-14
decision_status: accepted
---

# 備援與分流用多開支付通道，不在同一條下掛多個金流商戶

`agent_cash_flow_order_types` 在結構上是多對多，看 schema 會以為一條支付通道可以掛多個金流商戶。實務上不這麼做：要分流或備援，就多開一條支付通道，每條固定對應一個金流商戶。

2026-09 對生產庫查證，**同一個支付組別內，一條支付通道綁超過一個啟用中金流商戶的情形是零**。

## 後果

- 「會員看到的一個付款選項」與「背後是哪個金流商戶」維持一對一。訂單歸屬與對帳時，不必另外記錄該筆實際走了哪個金流商戶。
- 兩條支付通道即使名稱一字不差，只要金流商戶不同就是兩條，名稱不能當識別依據。
- 代價是支付通道數量會隨備援需求增加，後台選項變多。
