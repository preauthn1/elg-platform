---
type: Decision
id: decisions/0024-apis-scope-guard-at-agent-entry
title: APIs 承接範疇的檢查只放代理端入口
description: 「這條支付通道是否由 APIs 承接」的檢查只放在代理端建單與代付送件兩個入口，不放進 PaymentService::getProvider()，以免擋到會員端與三方回調。
domain: 金流
tags: [CashFlowScopeEnum, isHandledByApis, 承接範疇, 舊系統, 開分, 代理端, TNGPIN, PaymentService, getProvider, 代付, 金流, 代理]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Enums/CashFlow/CashFlowScopeEnum.php, title: "isHandledByApis() 與承接名單", at: 2026-10-21 }
  - { kind: code, ref: /app/Http/Controllers/Agent/DepositController.php }
  - { kind: code, ref: /app/Services/WithdrawalPayoutService.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2329 }
related: [rules/agent-self-deposit]
decided_at: 2026-09-30
decision_status: accepted
---

# APIs 承接範疇的檢查只放代理端入口

## 背景

代理在新代理後台用 TNGPIN（cash_flow_id 58）開分時，建單改由 APIs 送單。APIs 只承接 cash_flow_id 大於 62 或在承接名單內的支付通道，其餘屬舊系統範疇（MR !2329 的描述）。

## 決定

`CashFlowScopeEnum::isHandledByApis()` 的檢查只放在 `DepositController::deposit()` 與 `WithdrawalPayoutService::submit()` 兩個入口，不由 APIs 承接就回「此支付通道（cash_flow_id N）過舊，APIs 無法支援」。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 把檢查放進 `PaymentService::getProvider()` | 它同時服務會員端與三方回調，全域擋會連正常的回調處理一起受影響（MR !2329 的描述） |
| 新增多語系錯誤碼 | 代理端其他業務錯誤（例如「Agent self cash flow is disabled」）都是純文字 ApiException，照慣例（MR !2329 的 commit 內文） |
| 只放在兩個入口、用純文字錯誤訊息（採用） | 無 |

## 後果

- `WithdrawalPayoutService::submit()` 是會員取款與代理洗分出款都會經過的入口，兩者都受這道檢查（MR !2329 的描述）。
- 這條分界同時是新代理後台兩處分流的開關，承接名單前後端各一份，要一起改（MR !2329 的描述）。
- 代理回報「此支付通道過舊，APIs 無法支援」時，先確認該支付通道的 cash_flow_id 是否真的在 62 以下、且不在承接名單內（MR !2329 的描述）。
