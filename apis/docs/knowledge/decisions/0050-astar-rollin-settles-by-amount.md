---
type: Decision
id: decisions/0050-astar-rollin-settles-by-amount
title: ASTAR rollin 以 amount 入帳
description: ASTAR 的 rollin 入帳一律以 amount（應還回錢包的金額）為唯一依據，棋牌桌以 tablename 前綴判斷，公式對不上只記警告。
domain: 遊戲與遊戲商
tags: [ASTAR, Astar, rollin, rollout, amount, 棋牌, BOARDCARD, 捕魚, FISHINGGAME, 真人, 多付, 帶入金額]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/AstarController.php, title: "rollin()", at: 2026-10-22 }
  - { kind: test, ref: /tests/Feature/External/SingleWallets/Astar/AstarRollinSettleAmountTest.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2340 }
related: [rules/vendor-astar]
decided_at: 2026-10-01
decision_status: accepted
---

# ASTAR rollin 以 amount 入帳

## 背景

- ASTAR 新版文件規定，棋牌（tablename 前綴 `BOARDCARD#`）與捕魚（`FISHINGGAME#`）的 rollin 裡，win 與 amount 都是「用戶目前餘額」；只有真人遊戲的 win 才是輸贏（MR !2340 的 commit body）。
- 原本 rollin 把 win 當輸贏寫入，再以「輸贏加投注」推算派彩，棋牌局每局多還一次帶入金額。yes2win 自 2026-09-25 開放棋牌投注起，230 局共多付 401,924.60（MR !2340 的 commit body）。

## 決定

- 輸贏一律改為 amount 減 rollout 帶入金額，錢包只補回 amount（MR !2340 的 commit body）。
- 棋牌局的投注與有效投注都記輸贏的絕對值（平手為 0），在錢包算完之後才改；真人遊戲沿用 rollout 的投注與遊戲商傳的有效投注（MR !2340 的 commit body）。
- 真人遊戲若 amount 與公式對不上，照 amount 入帳並記 warning（MR !2340 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 保留 win，只替棋牌桌另寫一套換算 | win 的意思依遊戲種類而變；amount 在兩種遊戲都代表「應還回錢包的金額」，只看 amount 就不會再被欄位意思絆倒（MR !2340 的 commit body） |
| 以 tabletype 大於等於 100000 判斷棋牌 | 文件列出棋牌代碼，但沒有承諾代碼範圍，未驗證（MR !2340 的 commit body） |
| 棋牌與捕魚一起改 | 捕魚在 yes2win 還沒有注單，先只修已出事的棋牌（MR !2340 的 commit body） |
| 在錢包計算之前改投注 | 投注變動時錢包計算會走禮碼扣退邏輯，玩家有禮碼錢包時入帳就不再恰好等於 amount（MR !2340 的 commit body） |
| 真人遊戲公式對不上就拒絕該筆 rollin | 拒絕會讓 ASTAR 持續重送，最後以 Round Check（ASTAR 每 15 分鐘查一次未完成的局，補送 rollin 或退款）自動退款，玩家該局輸贏會消失（MR !2340 的 commit body） |
| 以 amount 為唯一依據，棋牌看 tablename 前綴（採用） | 無 |

## 後果

- 入帳方式與舊系統 API_External 一致（MR !2340 的 commit body）。
- 代價：棋牌注單的 rolling_chip（該注單扣用的禮碼金額）仍是 rollout 時依帶入金額扣的禮碼，可能大於改後的投注（MR !2340 的 commit body）。
- 捕魚入帳改以 amount 為準後已不會多付，但有效投注仍會記成遊戲商傳的負值，且每局會觸發公式不符的警告；開放捕魚前要再處理（MR !2340 的 commit body）。
