---
type: Decision
id: decisions/0051-game-list-sync-trims-half-width-space
title: 遊戲清單同步比對時只去掉遊戲代碼前後的半形空白
description: 遊戲清單同步在遊戲商端與資料庫端都以去掉前後半形空白的遊戲代碼比對，不去 tab 與換行，也不改寫資料庫裡的值。
domain: 遊戲與遊戲商
tags: [遊戲清單, 遊戲清單同步, 遊戲代碼, game_type, 空白, TRIM, 重複遊戲, GameListSyncService]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Services/GameListSyncService.php, title: "GAME_CODE_PADDING 與 trimAndDedupeGameCodes()", at: 2026-10-22 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2316 }
related: [rules/game-list-sync]
decided_at: 2026-10-01
decision_status: accepted
---

# 遊戲清單同步比對時只去掉遊戲代碼前後的半形空白

## 背景

遊戲商回傳的遊戲代碼前後帶空白時，比對不到資料庫裡沒有空白的同一款遊戲，會被當成新遊戲再新增一筆（MR !2316 的 commit body）。這段是所有遊戲商共用的流程。

## 決定

- 遊戲商端：拿到清單後先去掉每筆代碼前後的半形空白，比對、新增寫入、「三方未提供」偵測都用去空白後的代碼；去空白後重複的代碼只留第一筆（MR !2316 的 commit body）。
- 資料庫端：撈既有遊戲時在 SQL 以 `TRIM(game_type)` 取值比對，只拿 id 與代碼、不存回，資料庫裡的值不變（MR !2316 的 commit body）。
- 只去半形空白，不去 tab、換行（MR !2316 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 只在遊戲商端去空白 | 當初帶空白寫入、資料庫裡沒有乾淨版本的舊遊戲會比對不到，反而再新增一筆（MR !2316 的 commit body） |
| PHP 端連 tab、換行一起去掉 | MySQL `TRIM()` 預設只去半形空白，兩邊結果會對不上（MR !2316 的 commit body） |
| 兩端都只去前後半形空白（採用） | 無 |

## 後果

- `games.game_type` 是 `utf8mb4_unicode_ci`（PAD SPACE：比較字串時把尾端空白當作不存在），唯一索引會把尾端空白視同沒有，所以資料庫裡會重複的只有前導空白（MR !2316 的 commit body）。
- 去空白後有兩筆相同的舊重複資料時，以本來就沒有空白的那筆為準，補關聯表也用它（MR !2316 的 commit body）。
- 資料庫裡已經因空白而重複的遊戲不清除（MR !2316 的 commit body）。
