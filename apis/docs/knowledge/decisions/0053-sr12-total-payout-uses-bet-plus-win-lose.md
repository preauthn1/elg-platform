---
type: Decision
id: decisions/0053-sr12-total-payout-uses-bet-plus-win-lose
title: SR12 總數派彩以押注加輸贏計算
description: SR12 的總數派彩改用押注加輸贏，不用有效押注加輸贏，與 SR14 的派獎口徑一致。
domain: 報表
tags: [SR12, SR14, 總數派彩, 派獎, 押注, 有效押注, 輸贏, 報表, API_Agent]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:02:45+08:00" }
evidence:
  - { kind: code, ref: /app/Http/Controllers/Agent/ReportController.php, title: "sum(bet + win_lose) as payout", at: 2026-10-22 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2350 }
related: [rules/report-sr12-payout]
decided_at: 2026-10-05
decision_status: accepted
---

# SR12 總數派彩以押注加輸贏計算

## 背景

SR12 總數派彩原本是有效押注加輸贏。押注與有效押注不同的注單，會跟 SR14 的派獎（押注加輸贏）對不上。例：單號 134208 押注 10、有效押注 9.5、輸贏 9.5，SR12 顯示 19、SR14 顯示 19.5（MR !2350 的 commit body）。

## 決定

SR12 總數派彩改為押注加輸贏的加總；有效押注欄位不動（MR !2350 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 有效押注加輸贏（原狀） | 押注與有效押注不同的注單，會與 SR14 的派獎對不上（MR !2350 的 commit body） |
| 押注加輸贏，與 SR14 一致（採用） | 無 |

## 後果

- 代理後台實際呼叫的是 API_Agent 的 SR12，同一張工單同步修改（MR !2350 的 commit body）。
- API_Agent 那份 SR12 對 HAO_LONG、BOLAKK4D 保留「只有贏的注單才算派彩」的特例（MR !2350 的描述）。APIs 的 SR12 沒有這個特例（`ReportController` 的總計查詢）。
