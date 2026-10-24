---
type: Decision
id: decisions/0048-summary-backfill-by-completion-marker
title: 注單彙總以完成標記判斷要不要補跑，手動補跑的標記不算數
description: 整點注單彙總改由佇列執行並寫完成標記，每輪回看近 6 小時補跑缺標記的格子；手動補跑留下的標記不讓回看跳過。
domain: 遊戲與遊戲商
tags: [注單彙總, SBR, summary_bet_record, 完成標記, queue_job_runs, 回看補洞, 手動補跑, 稽核, 佇列]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Console/Commands/SummaryBetRecord/SummaryBetRecordRunner.php, title: "BACKFILL_IGNORED_SOURCES", at: 2026-10-22 }
  - { kind: code, ref: /app/Jobs/SummarizeBetRecordHour.php }
  - { kind: code, ref: /app/Models/QueueJobRun.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2080 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2298 }
related: [rules/summary-bet-record]
decided_at: 2026-09-23
decision_status: accepted
---

# 注單彙總以完成標記判斷要不要補跑，手動補跑的標記不算數

## 背景

2026-08-28 TPower 因 K8s 節點汰換，cron pod 拉 image 延遲 4 分鐘，錯過每小時 30 分的觸發點，整小時的注單彙總永久跳過且全程零告警（MR !2080 的描述）。

## 決定

- 整點排程只派發，彙總由佇列工作執行，並在同一個交易內寫入完成標記（`queue_job_runs`）（MR !2080 的描述）。
- 每輪回看近 6 小時，缺標記的格子一律補跑；任何一輪觸發缺失由下一個整點自動補回（MR !2080 的描述）。
- 每日稽核掃 48 小時的標記缺口並告警（MR !2080 的描述）。
- 回看判缺時，手動補跑留下的標記不算數，排程會自己重算一次（MR !2298 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 整點 cron 直接跑彙總（原狀） | 錯過觸發點時整小時永久跳過，排程、框架、應用三層各自無錯，沒有任何一層叫出來（MR !2080 的描述） |
| 完成標記欄位用預設定序 | 尾端空白相等加上大小寫不敏感，會讓 SQL 判已完成、PHP 判缺格同時成立，稽核永久誤報（MR !2080 的描述） |
| 結算失敗時不重置標記 | 回看格的結算失敗會因遊戲商標記已齊，被重試「洗成成功」，缺格永久靜默（MR !2080 的描述） |
| 手動補跑的成功標記也算完成（MR !2080 採用，MR !2298 拿掉） | 手動補跑若發生在該小時結束前、或遲到注單補進前，結果不完整；排程回看看到成功列就跳過，該格從此停在手動當下的資料（MR !2298 的 commit body） |
| 每日稽核也排除手動補跑的標記 | 超出回看視窗的缺口只能靠人工補，補完就不應再報缺（MR !2298 的 commit body） |
| 完成標記加回看補洞，回看不認手動標記（採用） | 無 |

## 後果

- 遊戲商彙總是冪等覆蓋，排程重算手動補跑過的格子不會重複計帳（MR !2298 的 commit body）。
- 結算失敗重置標記時保留手動補跑的列：它已不影響判缺，刪掉只會少一筆補跑紀錄（MR !2298 的 commit body）。
- 上線時要預塞近 52 小時的完成標記，否則首次稽核會把 48 小時內的舊格子全數誤判為缺口（MR !2080 的描述）。
- 三支結算 SP（stored procedure，存在資料庫裡、由程式呼叫執行的程序）移植成 PHP，SP 本體保留不刪，供舊系統雙軌呼叫（MR !2080 的描述）。
