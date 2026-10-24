---
type: Decision
id: decisions/0017-callback-order-match-by-cash-flow
title: 回調找單比對訂單所屬金流商
description: 回調找到的充值訂單或代付紀錄，經它的金流商戶取出金流商（cash_flow_id），與回調網址上金流商戶的金流商比對；不比對金流商戶本身。
domain: 金流
tags: [回調, 找單, 渠道檢查, 找單限定渠道, cash_flow_id, agent_cash_flow_id, 金流商戶, DepositCallbackTrait, WithdrawalCallbackTrait, FPay, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Services/Payment/Traits/DepositCallbackTrait.php, title: "orderBelongsToCallbackChannel()", at: 2026-10-21 }
  - { kind: code, ref: /app/Services/Payment/Traits/WithdrawalCallbackTrait.php, title: "payoutRecordInCallbackChannel()" }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2381 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2403 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2406 }
related: [rules/payment-callback-common-checks]
decided_at: 2026-10-12
decision_status: accepted
---

# 回調找單比對訂單所屬金流商

## 背景

回調只能處理自己的訂單，要擋掉「拿某家的回調網址去結算別家訂單」（MR !2381 的描述，防線 2；MR !2403 的描述，需求 4）。

## 決定

回調找到充值訂單或代付紀錄後，經它的金流商戶取出 `cash_flow_id`，與回調網址上金流商戶的 `cash_flow_id` 比對；取不到訂單的金流商戶時 fail-closed。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 比對 `agent_cash_flow_id`（MR !2381、!2403 採用，MR !2406 改掉） | 三方把回調網址固定在商戶後台，我方重建金流商戶後網址沒同步時，真回調會被誤擋，例如 TPower FPay 的代收訂單在 271、回調打 263（MR !2406 的描述）。MR !2381 的 commit 內文已預告這個代價：換費率或換商戶號新增金流商戶後，舊金流商戶的在途單會被擋、要人工補單 |
| 直接比對 `orders.cash_flow_id` | 代收建單路徑從沒寫過這個欄位，永遠是 NULL，本來就不能拿來比（MR !2403 的 commit 內文） |
| 經金流商戶比對 `cash_flow_id`（採用） | 無 |

## 後果

- 不符時當作查無訂單處理：回失敗、不動訂單，並發 TG 告警（MR !2403 的描述）。告警 context 帶雙方的 `cash_flow_id`（MR !2406 的描述）。
- Kira、MaxPay、PayEssence 的代付回調原本自己寫一份查詢、繞過共用方法，這道檢查對它們一度是空的；查過歷史確認是漏用，一律改走共用方法（MR !2403 的描述）。
- AP8PAY 的防線 2 保留在三方查單之前，不收斂進共用方法：收斂會讓不屬於本渠道的訂單多打一次三方 API，而且共用方法回 null 分不出原因（MR !2403 的 commit 內文）。
