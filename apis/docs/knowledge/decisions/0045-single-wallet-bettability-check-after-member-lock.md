---
type: Decision
id: decisions/0045-single-wallet-bettability-check-after-member-lock
title: 無縫錢包下注資格檢查放在鎖會員錢包之後
description: 無縫錢包遊戲商的下注資格改在交易內、鎖住會員錢包之後以 verifyBettability() 檢查，不再在交易外以 BetHelper::isMemberAbleToBet() 檢查。
domain: 遊戲與遊戲商
tags: [單一錢包, 無縫錢包, 下注資格, verifyBettability, ShouldVerifyGameBettability, BetHelper, 鎖序, REPEATABLE READ, bettable, AcewinSW, UUSlotSW, RCB988, OneBXSW]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/BaseController.php, title: "verifyBettability()", at: 2026-10-22 }
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/AcewinController.php }
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/UUSlotSWController.php }
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/Rcb988Controller.php }
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/OnebxSWController.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2267 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2276 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2277 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2278 }
related: [rules/single-wallet-callback-common-checks, rules/vendor-acewin, rules/vendor-uuslotsw, rules/vendor-rcb988, rules/vendor-onebxsw]
decided_at: 2026-09-21
decision_status: accepted
---

# 無縫錢包下注資格檢查放在鎖會員錢包之後

## 背景

工單 target/tickets#4375 把 AcewinSW、UUSlotSW、RCB988、OneBXSW 四家的下注資格檢查改走共用寫法。原本是在交易外、鎖之前呼叫 `BetHelper::isMemberAbleToBet()`（MR !2267、MR !2276 的 commit body）。

## 決定

- controller 實作 `ShouldVerifyGameBettability`，下注在交易內 `getMemberLockedWallets()` 之後呼叫 `verifyBettability()`（MR !2267、MR !2276、MR !2277、MR !2278 的 commit body）。
- 回應碼與訊息維持改版前，資格不符的例外在入口轉回原本的錯誤碼（MR !2267、MR !2277 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 檢查放在交易外、鎖之前（原狀） | REPEATABLE READ（MySQL 預設的交易隔離等級，同一個交易內的一般讀都讀同一份快照）的快照定在交易第一次一般讀，放在鎖之前會讀不到別人剛提交的會員、遊戲商戶狀態（target/tickets#4297）（MR !2267、MR !2276 的 commit body） |
| 沿用 `BetHelper::isMemberAbleToBet()` | 它吞掉 BetException 只回布林，失敗原因不留 log（MR !2276、MR !2277、MR !2278 的 commit body） |
| 鎖會員錢包之後呼叫 `verifyBettability()`（採用） | 無 |

## 後果

- 掛上介面後，遊戲可不可以下注只看 `vendor_info_games.bettable`，不再檢查 `game.status`、`vendor_info_games.status` 與代理遊戲設定。後台關掉但 bettable 仍是 1 的遊戲會開始收單，例如 AcewinSW 在 TPower 新放行 8 款、Yes2Win 24 款；上線前要請營運確認（MR !2267 的 commit body）。
- UUSlotSW 在 VPlus33、VWorld、Yes2Win 也有新放行的遊戲，TPower 為 0（MR !2276 的描述）。
- 派彩、取消投注不擋被鎖會員，UUSlotSW、RCB988 以測試釘住；OneBXSW 從「所有端點都擋」改成只擋下注（MR !2276、MR !2277、MR !2278 的 commit body）。
- 介面也當作盤點標記：哪些無縫錢包遊戲商有做資格檢查，看介面就知道（MR !2276、MR !2277 的 commit body）。
