---
type: Decision
id: decisions/0030-group-fee-tiers-take-over-bank-fee
title: 群組手續費階梯接管後不回退銀行手續費
description: 會員群組設了平台手續費階梯，就完全接管會員取款的手續費：未達第一階時手續費為 0、不回退銀行手續費；「手續費大於等於取款金額就擋」只守群組路徑，銀行路徑維持現行。
domain: 金流
tags: [會員群組, 平台手續費階梯, withdraw_fee_tiers, 會員手續費, 銀行手續費, 取款, WithdrawalFeeResolver, 錯誤碼 1958, 會員, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Services/WithdrawalFeeResolver.php, title: "依當日第幾筆取階梯，沒有群組時沿用銀行手續費", at: 2026-10-21 }
  - { kind: migration, ref: /database/migrations/2026_09_23_000001_add_withdraw_fee_tiers_to_member_groups_table.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2325 }
related: [rules/member-group-fee-tiers, rules/withdrawal-member-fee]
decided_at: 2026-09-30
decision_status: accepted
---

# 群組手續費階梯接管後不回退銀行手續費

## 背景

target/tickets#4353 要求會員群組新增「平台手續費階梯」：會員送出取款時，依「當日第幾筆」決定手續費；群組有設階梯就完全取代銀行管理的手續費，沒設或沒群組維持現行（MR !2325 的描述）。

## 決定

群組有設階梯時，手續費取 `from` 小於等於當日第幾筆的最後一階；沒有任何一階符合時為 0，不回退銀行手續費。群組階梯算出的手續費大於或等於取款金額時擋單（錯誤碼 1958），銀行路徑不加這道檢查。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 有階梯但本筆未達第一階時，回退銀行手續費 | 依工單規格，群組啟用階梯即接管手續費（MR !2325 的 commit 內文） |
| 銀行路徑與群組路徑一起守「手續費大於等於取款金額」 | 銀行手續費現況沒有這道檢查，一併加上會改變已上線站台的建單結果，不在這張工單範圍（MR !2325 的 commit 內文） |
| 後台更新沒帶 `withdraw_fee_tiers` 時當成清空 | 後台部分更新時會把已設定的階梯誤清掉；只有明確送 null 或空陣列才清空（MR !2325 的 commit 內文） |
| 群組階梯完全接管、只守群組路徑（採用） | 無 |

## 後果

- 百分比 100 在驗證層放行，交由建單時「手續費不得大於或等於取款金額」擋下，避免同一條界線在兩處各寫一次（MR !2325 的 commit 內文）。
- 當日第幾筆沿用既有每日取款次數的計法（GMT+8 一日、排除駁回與退回），不另立計數（MR !2325 的描述與 commit 內文）。
- 百分比允許小數，因為後台已拍板允許 2.5% 這類設定（MR !2325 的 commit 內文）。
