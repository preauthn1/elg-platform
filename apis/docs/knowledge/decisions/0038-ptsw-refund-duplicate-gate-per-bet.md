---
type: Decision
id: decisions/0038-ptsw-refund-duplicate-gate-per-bet
title: PTSW 取消投注閘以「同一筆下注」為粒度
description: PTSW 的取消投注（REFUND）回調在錢包上鎖前先檢查：原下注要確實扣過款、同一筆下注不能取消第二次；粒度是單筆下注而不是整局，只有補帳命令補的整局 REFUND 事件例外。
domain: 遊戲與遊戲商
tags: [PTSW, PlayTech, 退款, REFUND, 重複退款, relatedTransactionCode, multi-bet, 冪等, refund-unsettled, _rejected, _recovery]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/PlayTechController.php, title: "isBetAlreadyRefunded()", at: 2026-10-22 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2133 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2141 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2143 }
related: [rules/vendor-ptsw, rules/single-wallet-unsettled-refund]
decided_at: 2026-09-08
decision_status: accepted
---

# PTSW 取消投注閘以「同一筆下注」為粒度

## 背景

- 2026-09-05 TPower 事故：下注在餘額檢查被拒時注單已建立（投注 0），PT 逾時仍送 REFUND，系統由投注前後差額算出入帳，5 筆多付玩家 253.00（MR !2133 的 commit body）。
- PT 對同局換新 transactionCode 重送 REFUND 時，deal record（回調去重紀錄表，記下已處理過的回調）的 requestId 與「bet_id 加 transactionCode」兩層冪等都擋不到，玩家多拿一次下注金額（MR !2141 的 commit body）。

## 決定

- REFUND 先以 `relatedTransactionCode` 比對原下注是否已扣款，未扣款回 ERR_NO_BET；沒帶 `relatedTransactionCode` 一律拒絕（MR !2133 的 commit body）。
- 同局、同帳號已有「指向同一筆下注的 REFUND 事件」時拒絕；比對不分大小寫（MR !2141 的 commit body）。
- 閘門粒度是同一筆下注；唯一的整局判斷是補帳命令補的 REFUND 事件，視為整局已取消（MR !2141 的 commit body）。
- 這些檢查都放在錢包上鎖之前（MR !2133、MR !2141 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 只靠 requestId 與「bet_id 加 transactionCode」兩層冪等（原狀） | 擋不到 PT 換新 transactionCode 對同局重送的 REFUND（MR !2141 的 commit body） |
| 以整局為粒度擋第二次取消投注 | PTSW 真人允許同局多筆下注，PT 取消整局時會對每筆下注各送一次 REFUND，整局閘會誤擋第二筆合法的取消投注（MR !2141 的 commit body） |
| 沒帶 `relatedTransactionCode` 時放行 | multi-bet 局部扣款時會放行錯筆；PT 協定必帶，三站生產 390 筆 REFUND 全帶（MR !2133 的 commit body） |
| 以同一筆下注為粒度，補帳命令的整局 REFUND 事件例外（採用） | 無 |

## 後果

- 補帳命令把整局投注一次退回、只補一筆 REFUND 事件，它的 `relatedTransactionCode` 只指到一筆下注，所以該事件視為整局已取消（MR !2141 的 commit body）。
- 人工撤回補帳命令的退回時，必須一併刪掉補的 deal record，否則該局 PT 之後的合法 REFUND 會被永久拒絕（MR !2141 的 commit body）。
- 被拒的 REFUND 事件在 raw 標記 `_rejected`，取消投注閘與補帳 SQL 不把它當成取消投注；三方 payload 夾帶的 `_rejected`、`_recovery` 寫入前剝除，底線前綴是我方獨占的標記命名空間（MR !2143 的 commit body）。
- 被拒後用同一個 transactionCode 重放會回 200，沿用 MR !2133 的既定取捨：PT 的串接文件 `docs/integrations/vendors/PTSW/api.md` 規定 ERR_NO_BET 不可重試、同 transactionCode 重複請求須回首次結果（MR !2141 的 commit body）。
