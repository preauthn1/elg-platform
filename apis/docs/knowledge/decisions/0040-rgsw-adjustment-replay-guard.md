---
type: Decision
id: decisions/0040-rgsw-adjustment-replay-guard
title: RGSW 調整類回調以同局、同類、同金額、300 秒內判定重送
description: RG 的 refund、credit、debit、sumBet 沒有交易序號，同一局同一種調整同一金額在 300 秒內再來一次就當重送，只比對關鍵欄位，守衛排在餘額檢查之前。
domain: 遊戲與遊戲商
tags: [RGSW, RG, 調整類回調, refund, credit, debit, sumBet, 重送, 冪等, 時間窗, 部分退款, CorrectionTypeEnum]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/RgswController.php, title: "DUPLICATE_WINDOW_SECONDS 與 identityKeys() 比對", at: 2026-10-22 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2160 }
related: [rules/vendor-rgsw]
decided_at: 2026-09-09
decision_status: accepted
---

# RGSW 調整類回調以同局、同類、同金額、300 秒內判定重送

## 背景

- RG 的 refund、credit、debit、sumBet 都是疊加調整，直接把金額加進原注單，錢包再依差額入帳；請求沒有交易序號可以當冪等鍵，同一份 payload 送幾次就入帳幾次（MR !2160 的 commit body）。
- TPower 生產資料顯示 payload 都沒有序號，「三方重送」與「同一局的第二筆合法調整」在請求內容上分不出來，只能再加時間窗（MR !2160 的 commit body）。

## 決定

- 四支調整類回調加重送守衛：同一局、同一種調整、同一個金額，且在 300 秒內，視為重送；錢包不動，回當下餘額的成功回應，並記一筆帶 member_id 與金額的 warning（MR !2160 的 commit body）。
- 比對來源是已鎖住那一列注單自己的 `info.corrections`（MR !2160 的 commit body）。
- 只比對「決定動到哪一局」的欄位加金額；金額以數值比對（MR !2160 的 commit body）。
- debit 與 sumBet 的守衛排在餘額檢查之前（MR !2160 的 commit body）。
- 未結算局收到 refund 時是部分取消投注，整筆投注都取消完才把狀態翻成 REFUND，否則留在 BET（MR !2160 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 比對整包 payload | RG 哪天多帶一個會變動的欄位（生產上的 payout 就帶了 443 筆全不重複的 payout_time），整包比對會永遠不相等，守衛靜默失效、重複入帳；只比關鍵欄位的失效方向是誤擋，玩家少拿但可補、有 log 可查（MR !2160 的 commit body） |
| 守衛排在餘額檢查之後 | 第一次扣款已讓餘額變小，重送時往往再也扣不動，三方會拿到「餘額不足」而不是「已處理過」，一筆成功的扣帳會被當成失敗（MR !2160 的 commit body） |
| 部分取消投注一律把狀態翻成 REFUND（原狀） | 同一局的第二筆合法部分取消投注會查不到注單而少退（MR !2160 的 commit body） |
| 關鍵欄位加金額、300 秒時間窗、排在餘額檢查前（採用） | 無 |

## 後果

- 狀態留在 BET 之後不能再靠「狀態已變」擋重送，改由守衛負責，所以兩個分支都會寫入 corrections（MR !2160 的 commit body）。
- 300 秒沒有 RG 文件依據，也無法用歷史資料校準：TPower 453 筆 corrections 每張注單只有一筆，生產上從未發生重送。部分取消投注後的有效投注算法同樣沒有三方依據，兩者都待 RG 回覆後校準（MR !2160 的 commit body）。
- 調整類型字串封成 `CorrectionTypeEnum`：它從「只寫進 DB」變成「拿來比對」，打錯一個字就是守衛靜默失效（MR !2160 的 commit body）。
- payout、endRound 的重送刻意沒處理，已確認不會重複入帳（MR !2160 的描述）。
