---
type: Decision
id: decisions/0025-withdrawal-bank-ownership-uniform-error
title: 取款銀行歸屬不符與不存在回同一個錯誤
description: 會員取款建單與綁定銀行帳號時，選到看不到的銀行一律回既有錯誤碼 1315；「編號不存在」與「存在但不屬於你」回同一個訊息，不新增專屬錯誤碼。
domain: 金流
tags: [取款, 取款訂單, 綁定銀行帳號, 銀行歸屬, findSelectableBank, 錯誤碼 1315, 支付組別, 越權, 會員, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Http/Controllers/Frontend/WithdrawalController.php, title: "findSelectableBank()", at: 2026-10-21 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2151 }
related: [rules/withdrawal-member-fee]
decided_at: 2026-09-08
decision_status: accepted
---

# 取款銀行歸屬不符與不存在回同一個錯誤

## 背景

取款畫面只列出「屬於會員所屬支付組別、而且啟用中」的銀行，但建單與綁定銀行帳號兩支都直接採用請求傳來的 `bank_id`，沒有再檢查一次（MR !2151 的描述）。

每一列銀行的 `rate` 決定申請取款一塊要從餘額扣幾點，`min_amount` 與 `max_amount` 決定單筆上下限，換一列銀行就等於換掉扣點倍率與限額。VWorld 2026-09-08 實查，馬幣同幣別內 `rate` 從 0.23 到 1.0，新幣從 1.0 到 3.59（MR !2151 的描述）。

## 決定

建單與綁定銀行帳號共用 `findSelectableBank()`，條件與銀行清單端一致（同支付組別、啟用中）；看不到的銀行一律回既有錯誤碼 1315（訂單參數錯誤），「編號不存在」與「存在但不屬於你」回同一個訊息。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 「編號不存在」與「不屬於你」回不同訊息 | 分得太細等於告訴對方哪些編號是別人的，可以被逐一試出別的群組有哪些銀行（MR !2151 的描述） |
| 新增專屬錯誤碼 | 要新增十一個語系的翻譯，也可能讓訊息洩漏歸屬資訊；1315 語意略寬，但十一個語系都已有翻譯（MR !2151 的描述） |
| 只修建單、不修綁定 | 綁定本身不會馬上少錢，但它是「先綁、再拿它去取款」的第一步；兩支用同一組條件才不會一邊鬆一邊緊（MR !2151 的描述） |
| 共用檢查、回 1315 同一訊息（採用） | 無 |

## 後果

- MR !2151 的描述註明：若團隊偏好專屬錯誤碼，這裡要改。
- 綁定端只借 `findSelectableBank()` 的例外，不使用回傳值（MR !2151 的描述）。
- 既有不一致的歷史取款訂單不清理：VWorld 自 2026-06-01 起 1 筆，待人工確認是不是會員換過群組造成的假陽性；TPower 0 筆（MR !2151 的描述）。
- 充值端早有同型做法：自助充值以帶群組條件的查詢取帳戶，取不到就報錯；這次是取款端補齊（MR !2151 的描述）。
