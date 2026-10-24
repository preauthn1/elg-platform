---
type: Decision
id: decisions/0052-summary-full-day-rerun-serial-in-scheduler
title: 前一整天的注單彙總重跑在排程進程內逐商序列執行
description: 每日重跑前一整天注單彙總，在排程進程內一家一家依序跑，不再每家派一個佇列工作併發，排程時刻在 UTC 日界之後。
domain: 遊戲與遊戲商
tags: [注單彙總, SBR, 隔日整天重跑, rerun-full-day, next_day_full_rerun, 結算鎖, 佇列, 排程, 時區]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Console/Commands/SummaryBetRecord/RerunFullDaySummaryBetRecordCommand.php, at: 2026-10-22 }
  - { kind: code, ref: /routes/console.php, title: "summary_bet_record:rerun-full-day dailyAt 00:25" }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2205 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2206 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2343 }
related: [rules/summary-bet-record]
decided_at: 2026-10-02
decision_status: accepted
---

# 前一整天的注單彙總重跑在排程進程內逐商序列執行

## 背景

- MR !2205 新增隔日整天重跑：每個設定 `next_day_full_rerun` 的遊戲商派一個佇列工作，在長時佇列併發執行，排在 UTC 23:00（MR !2205 的 commit body）。
- 工作內的彙總會跑結算。結算有兩把鎖：team 鎖（寫全民代理逐層彙總 `summary_bet_records_team` 時持有，以台北日為單位，整天 24 格共用一把）與滾碼鎖（寫禮碼彙總 `summary_rolling_chip_records` 時持有，以 UTC 小時為單位）。兩把鎖的鍵都不含遊戲商，搶不到立刻拋例外（MR !2343 的 commit body）。

## 決定

- 在排程進程內逐商序列跑完，整支佇列工作移除（MR !2343 的 commit body）。
- 排程時刻在 UTC 日界之後。MR !2206 先從 UTC 23:00 改到 UTC 00:05（UTC+8 08:05），MR !2343 再移到 UTC 00:25（UTC+8 08:25），仍在 UTC 日界之後（MR !2206、MR !2343 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 每個遊戲商派一個佇列工作併發（MR !2205 採用，MR !2343 拿掉） | 兩個遊戲商必然互撞結算鎖：2026-10-02 vwb2c 的 145、253 各失敗 14 次，三站同時發告警。結算重算的是那個小時的全部遊戲商，各商跑的是同一份工，序列化沒有損失（MR !2343 的 commit body） |
| 排在 UTC 23:00（MR !2205 採用，MR !2206 拿掉） | 此時 UTC 當日還沒結束，+0 時區遊戲商推回的「昨天」是 D-1，與 +8 時區遊戲商的 D 差一天（MR !2206 的 commit body） |
| 排程進程內逐商序列，UTC 日界之後執行（採用） | 無 |

## 後果

- 所有遊戲商在同一個進程內依序執行，不會有兩家同時搶鎖，因此不需要任何鎖；也不再需要佇列工作層的防重疊、重試設定，以及與 Horizon（Laravel 的佇列監控與執行管理套件）逾時設定的耦合（MR !2343 的 commit body）。
- 排程加 `withoutOverlapping(3600)`，拉帳久跑時擋住隔天那輪（MR !2343 的 commit body）。
- 單一遊戲商失敗只記錄並續跑下一家，全部跑完若有失敗回非 0，由排程失敗告警送出（MR !2343 的 commit body）。
- 本命令列入慢任務告警的忽略清單，否則每天必噴耗時告警（MR !2343 的 commit body）。
