---
type: Decision
id: decisions/0039-ptsw-lock-timeout-error-code-by-action
title: PTSW 撞同局鎖逾時依動作回不同錯誤碼
description: PTSW 回調取不到同局鎖時，派彩（gameRoundResult）回可重試的 INTERNAL_ERROR，下注回不可重試的 ERR_TRANSACTION_DECLINED，未列舉的動作預設回可重試碼。
domain: 遊戲與遊戲商
tags: [PTSW, PlayTech, 同局鎖, GameRoundLock, 鎖逾時, INTERNAL_ERROR, ERR_TRANSACTION_DECLINED, 可重試, 結算, gameRoundResult]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/PlayTechController.php, title: "lockTimeoutErrorCode()", at: 2026-10-22 }
  - { kind: test, ref: /tests/Feature/External/SingleWallets/PTSW/PtswLockTimeoutErrorCodeTest.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2159 }
related: [rules/vendor-ptsw]
decided_at: 2026-09-09
decision_status: accepted
---

# PTSW 撞同局鎖逾時依動作回不同錯誤碼

## 背景

下注與派彩（gameRoundResult）共用同一段取鎖骨架，同局鎖（5 秒）取不到時一律回 ERR_TRANSACTION_DECLINED。PT 的串接文件 `docs/integrations/vendors/PTSW/api.md` 把這個碼列為不可重試，PT 收到就不再送這筆派彩，注單停在進行中、下注款已扣（MR !2159 的 commit body）。

## 決定

- 派彩回 INTERNAL_ERROR，PT 依退避重送，重送由既有的 deal record（回調去重紀錄表，記下已處理過的回調）冪等接住（MR !2159 的 commit body）。
- 下注維持 ERR_TRANSACTION_DECLINED（MR !2159 的 commit body）。
- 用 match 寫，default 放在可重試的一側（MR !2159 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 一律回 ERR_TRANSACTION_DECLINED（原狀） | 對派彩會造成永久損失，形狀與 target/op_tickets#3083 那 10 筆相同（MR !2159 的 commit body） |
| 下注也回可重試碼 | 撞鎖代表同局另一筆正在處理，重試反而可能變成重複下注（MR !2159 的 commit body） |
| 用三元式區分兩種動作 | 三元式只有兩個出口，沒有明寫的動作一律落到其中一邊；日後有第三種動作接進來時，它會在沒有任何提示的情況下拿到不可重試的碼。未列舉的動作寧可讓三方重送（MR !2159 的 commit body） |
| 依動作回碼，預設可重試（採用） | 無 |

## 後果

- INTERNAL_ERROR 是可重試清單中唯一下注與派彩都適用的碼（MR !2159 的 commit body）。
- 撞鎖補記 warning（帶動作與錯誤碼）；在此之前只看得到一筆錯誤 log，分不出是投注還是派彩（MR !2159 的 commit body）。
- 2026-09-08 查 TPower、Yes2Win 近 30 天 ERR_TRANSACTION_DECLINED 皆零筆，缺陷尚未實際發生（MR !2159 的 commit body）。
