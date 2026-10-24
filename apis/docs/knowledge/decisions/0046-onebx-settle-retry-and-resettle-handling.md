---
type: Decision
id: decisions/0046-onebx-settle-retry-and-resettle-handling
title: OneBX 派彩失敗回可重試碼，換交易號的重新派彩不動錢只告警
description: OneBX 派彩（settle）遇到系統錯誤時回 HTTP 500 與錯誤碼 RETRY，讓三方重送；同一個 call_transaction_id 的重送靜默放行，不同 call_transaction_id 的重新派彩不動錢、只記 warning。
domain: 遊戲與遊戲商
tags: [OneBXSW, OneBX, 結算, settle, 重送, 冪等, call_transaction_id, RETRY, E_00, 重新結算, 單一錢包]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/OnebxSWController.php, title: "settle 冪等判定（注單 infos 的 call_transaction_id）", at: 2026-10-22 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2278 }
related: [rules/vendor-onebxsw]
decided_at: 2026-09-23
decision_status: accepted
---

# OneBX 派彩失敗回可重試碼，換交易號的重新派彩不動錢只告警

## 背景

- 遊戲商文件明訂：`/api/settle` 收到非 200 且 body 為 E_00 時，整批派彩永久失敗、永不重送（MR !2278 的 commit body）。
- 文件寫 settle 會重送到收到 200 為止，線上看過重送 9 次（MR !2278 的 commit body）。
- 文件對「沒經 undo 的重新派彩」只寫「must be applied as a new payout」，沒定義金額語意（MR !2278 的 commit body）。

## 決定

- 派彩（settle、stepSettle）遇到系統錯誤時回 HTTP 500，body 的錯誤碼是 RETRY，不回 E_00（MR !2278 的 commit body）。
- 冪等以注單 infos 裡已套用 payload 的 `call_transaction_id` 判斷：同一個 ID 是重送，靜默放行；不同 ID 是沒經 undo 的重新派彩，不動錢，記一筆帶 member_id 與金額的 warning（MR !2278 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 「查不到注單」「超過 30 筆」等系統錯誤回 E_00（原狀） | 依文件，E_00 會讓同批其他玩家也永遠收不到錢（MR !2278 的 commit body） |
| 不同 ID 的重新派彩當新派彩入帳 | 文件沒定義金額語意；TPower、Yes2Win 全部 763 張注單從未發生，每張都是下注後一次派彩或取消（MR !2278 的 commit body） |
| 系統錯誤回 RETRY，重新派彩只告警（採用） | 無 |

## 後果

- 第一次發生重新派彩時，可直接從 log 算出少付了誰、少付多少（MR !2278 的 commit body）。
- 不另開 deal_records 表，判定依據是注單 infos（MR !2278 的 commit body）。
