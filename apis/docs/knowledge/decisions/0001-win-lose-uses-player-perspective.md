---
type: Decision
id: decisions/0001-win-lose-uses-player-perspective
title: 輸贏以玩家視角記錄
description: 注單 win_lose 的正負號以玩家視角記錄，需要平台視角的數字時在讀取端取負，不改寫入端。
domain: 遊戲與遊戲商
tags: [輸贏, win_lose, 玩家視角, 派彩, 投注, 遊戲商, 對帳, GGR]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
evidence:
  - { kind: code, ref: /app/Vendors/Models/AbstractBetRecord.php, title: "getWinLose()：派彩 − 投注，玩家贏為正", at: 2026-10-21 }
  - { kind: code, ref: /app/Vendors/Models/EvoBetRecord.php, title: "winLoseColumn 直接取三方欄位，方向不由本決定推導" }
decided_at: 2026-09-14
decision_status: accepted
---

# 輸贏以玩家視角記錄

注單的 `win_lose` 存的是「派彩減投注」，玩家贏為正、輸為負。計算在 `app/Vendors/Models/AbstractBetRecord.php::getWinLose()`；遊戲商的注單表上沒有這個欄位時，就以這個算法推得。

平台視角是它的相反數。需要平台側數字時，在**讀取端**取負，不改寫入端。理由是注單是事實紀錄，翻轉寫入端會讓所有既有資料與遊戲商對帳的基準一起失效。

## 後果

- 平台側的數字由讀取端推導，例如 `docs/代理數據看板/api-plan.md` 已定案 `GGR = −SUM(win_lose)`。
- 遊戲商的報表多以公司視角出帳。對齊時必須**先確認對方的視角**，再決定要不要加負號，不能假設同向。
- 少數遊戲商**直接取遊戲商回報的 `win_lose` 欄位**，不走「派彩減投注」推導（例如 `EvoBetRecord::$winLoseColumn = 'win_lose'`），方向不由我方程式碼決定。
  - 已實測的是 `evo_bet_records`。2026-09 對生產庫查證，玩家輸光的注單記 `win_lose = -bet` 共 6,488 筆，反向（`= +bet`）只有 315 筆，**方向與本決定一致**。
  - `docs/reconcile/vendors/evo.md` 原本寫「BR/TBR 是公司視角」，與資料不符，已更正。
- **注意**：上述查證涵蓋整張 `evo_bet_records`。那是 Titan 聚合平台的共用表（60 家以上遊戲商，表上沒有 vendor 欄位），**不等於單獨驗證了某一家**。其餘直接取遊戲商欄位的遊戲商，未逐一查證。
