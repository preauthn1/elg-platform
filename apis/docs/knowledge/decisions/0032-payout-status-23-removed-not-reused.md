---
type: Decision
id: decisions/0032-payout-status-23-removed-not-reused
title: 代付狀態 23 直接移除且禁止重用
description: 已廢除的代付狀態 23（三方查證中）從 WithdrawalOrderStatusEnum 直接刪除，原位置註明禁止重用；回調與人工定案命令遇到 23 一律拒絕。
domain: 金流
tags: [代付, 取款訂單狀態, 三方查證中, 狀態 23, WithdrawalOrderStatusEnum, withdrawal:resolve-payout, 人工定案, 回調, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Enums/WithdrawalOrderStatusEnum.php, title: "23 已退役、禁止重用的註解", at: 2026-10-21 }
  - { kind: code, ref: /app/Console/Commands/Order/ResolvePayoutOrder.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2235 }
related: [rules/payout-submit-and-status]
decided_at: 2026-09-16
decision_status: accepted
---

# 代付狀態 23 直接移除且禁止重用

## 背景

代付狀態 23（三方查證中）已在 target/tickets#4137 廢除，結果未明的情境改為停留「代付提交中」(21)；當時保留 23 是為了處理線上的存量訂單（MR !2235 的描述）。2026-09-16 對六站全量查詢，都沒有狀態 23 的訂單；APIs 與舊系統 API_Agent 都不會寫入 23（MR !2235 的描述）。

## 決定

`WithdrawalOrderStatusEnum` 直接刪除 `THIRD_PARTY_VERIFYING = 23`，原位置留註解「23 已退役，禁止重用」；代付回調的狀態守衛與人工定案命令 `withdrawal:resolve-payout` 遇到 23 都拒絕。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 保留 enum case 並標記退役，只刪行為分支 | enum 原註解已承諾清零後移除，保留等於收尾沒做完，之後還得再開一次（MR !2235 的 commit 內文） |
| 人工定案命令仍可處理 23 | 線上若再出現 23，代表有未知的寫入者，應先追查來源，而不是直接改狀態（MR !2235 的 commit 內文） |
| 移除後允許重用 23 這個值 | 舊系統後台仍有 23 的處理端點，歷史訂單的變更紀錄 `change_logs` 也會出現 23，重用會讓這些舊紀錄被誤讀成新狀態（MR !2235 的 commit 內文） |
| 直接刪除、拒絕 23、禁止重用（採用） | 無 |

## 後果

- 收到狀態 23 的回調會走「其餘狀態」路徑：發機器人告警並回 400 拒絕（MR !2235 的描述）。
- 舊命令名 `withdrawal:resolve-verifying` 這個別名保留，因為歷史告警訊息與操作手冊仍引用，保留沒有成本（MR !2235 的 commit 內文）。
- `CrossCodeConvergenceService` 的未結訂單清單仍寫死 23：它不引用 enum，不會因此出錯（MR !2235 的描述）。
- 唯一把狀態值轉成 enum 的地方（客服機器人查單）用 `tryFrom`，遇到 23 會回 null，不會拋錯（MR !2235 的描述）。
- 長期分支 `refactor/payment-bank-convergence` 有 3 支檔案引用這個 case，同步 master 時要從各自的狀態清單刪掉（MR !2235 的描述）。
