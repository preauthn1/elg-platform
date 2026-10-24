---
type: Decision
id: decisions/0063-pp-live-casino-status-f-as-cancelled
title: PP 拉注單只把真人局的 status F 當取消單
description: PragmaticPlay 拉回的注單中，只有真人局（dataType LC）且 status 為 F 的才當取消單歸零，RNG 的 F 局照一般注單入帳。
domain: 遊戲與遊戲商
tags: [PragmaticPlay, PP, 拉注單, 取消單, status F, 真人, LC, RNG, 自動完成, 對帳, Yes2Win]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Vendors/Pp/Driver.php, title: "isCancelled()", at: 2026-10-22 }
  - { kind: test, ref: /tests/Feature/Vendors/Pp/DriverTest.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2454 }
related: [rules/bet-record-pull]
decided_at: 2026-10-21
decision_status: accepted
---

# PP 拉注單只把真人局的 status F 當取消單

## 背景

- Yes2Win 對 PragmaticPlay（vendor_id 54）2026-10 的帳，有 6 天我方投注比 PP 後台多，輸贏沒差。差額剛好是 PP 回傳 status F 的真人局（MR !2454 的 commit body）。
- 拉注單用的 `gamerounds/finished` 有 C（已完成）與 F（取消或自動完成）兩種 status。對接程式原本只認 `roundDetails.result = Betcancelled` 為取消單（MR !2454 的 commit body）。

## 決定

dataType 為 LC（真人）且 status 為 F 的注單視為取消單：投注、輸贏歸零，注單狀態記 0。RNG 的 F 局與所有 C 局照一般注單入帳（MR !2454 的 commit body）。原本 `roundDetails.result = Betcancelled` 就當取消單的判斷保留，兩個條件任一成立都算取消單（`Pp\Driver::isCancelled()`）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 只認 `roundDetails.result = Betcancelled`（原狀） | F 的真人局被當成一般注單入帳，我方投注比 PP 後台多（MR !2454 的 commit body） |
| 所有 F 局都歸零 | RNG 的 F 局可能是自動完成，玩家有真實輸贏，全部歸零會漏記（MR !2454 的 commit body） |
| F 且投注等於派彩才歸零（先前試過） | 改用 dataType 判斷、不比對金額，規則與 PP 的說法一致（MR !2454 的 commit body） |
| 只把真人局的 F 當取消單（採用） | 無 |

## 後果

- 依據是 PP 客服 2026-10-21 的回覆「真人局不會自動完成，注單 ID 以 008 結尾且 status 為 F 就是取消單」（MR !2454 的 commit body）。
- Yes2Win 2026-10-03 實測：LC 注單 11,578 筆的 bet_id 全以 008 結尾，RNG 968,639 筆沒有一筆是，用 dataType 判斷與 008 結尾等價（MR !2454 的 commit body）。
- 只影響之後拉進來的注單。已入庫的真人取消局要用 `php artisan fetch_bet_record --vendor=54 --update` 重拉，再重跑該時段的注單彙總，另案處理（MR !2454 的 commit body）。
