---
type: Decision
id: decisions/0049-metagaming-game-list-filter-by-code-format
title: MetaGamingSW 遊戲清單以代碼格式過濾
description: MetaGamingSW 同步遊戲清單時，唯一的收錄條件是遊戲代碼為六位數字，不維護逐款白名單，也不依產品線排除。
domain: 遊戲與遊戲商
tags: [MetaGamingSW, 遊戲清單, 遊戲清單同步, 白名單, GameProvideCode, 產品線, 遊戲代碼]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:02:45+08:00" }
evidence:
  - { kind: code, ref: /app/Vendors/MetaGamingSW/Driver.php, title: "GAME_CODE_PATTERN", at: 2026-10-22 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2320 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2321 }
related: [rules/game-list-sync]
decided_at: 2026-09-30
decision_status: accepted
---

# MetaGamingSW 遊戲清單以代碼格式過濾

## 背景

MetaGamingSW 原本以 38 個遊戲代碼逐款白名單收錄。三方每次上新遊戲都要改程式才上得了架，target/op_tickets#3347 的 Mahjong Destiny 就卡在這裡（MR !2320 的 commit body）。同一天先後合併兩支 MR 改掉白名單。

## 決定

唯一的收錄條件是遊戲代碼為六位數字；三方四條產品線（MetaGaming、LGMetaGaming、MGH5MetaGaming、LGMGH5MetaGaming）全部收錄（MR !2321 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 38 款逐款白名單（原狀） | 三方每次上新遊戲都要改程式才上得了架（MR !2320 的 commit body） |
| 以 GameProvideCode 只收 MetaGaming 與 LGMetaGaming，排除兩條 MGH5 產品線（MR !2320 採用，MR !2321 拿掉） | 原本排除的理由是 169xxx 與 165xxx 多款同名、三方未給簡中名稱，上架會混淆；後來客服確認四條產品線都可上架，限制取消（MR !2320、MR !2321 的 commit body） |
| 只檢查代碼是六位數字（採用） | 無 |

## 後果

- TPower 乾跑：三方回 52 款全數收錄，原有 38 款保留，新增 14 款（MR !2321 的 commit body）。
- 三方目前回的 52 款代碼都符合六位數字，這道格式檢查沒有排除任何一款；它是防三方日後回傳異常代碼的防線，不是現有資料的篩選條件（MR !2321 的 commit body）。
- 一併移除 `PruneMetaGamingSWGames`：它是導入白名單時的一次性清理工具，判定依據已不存在，留著會在下次執行時誤刪新收錄的遊戲（MR !2320 的 commit body）。
