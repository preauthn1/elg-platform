---
type: Decision
id: decisions/0044-single-wallet-bet-time-index-skips-large-tables
title: 無縫錢包注單表補 bet_time 索引時排除三張大表
description: 補 bet_time 索引的 migration 刻意排除 fumasw、uuslotsw、wudisw 三張大表，這三家改用既有索引查未結算注單。
domain: 遊戲與遊戲商
tags: [單一錢包, 注單表, 索引, bet_time, migration, SchemaMonitor, 大表, fumasw, uuslotsw, wudisw, 未結算注單報表]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: migration, ref: /database/migrations/2026_09_15_000001_add_bet_time_index_to_single_wallet_bet_records.php, title: "檔頭說明排除三張表", at: 2026-10-22 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2270 }
related: [rules/vendor-afbsw, rules/vendor-fumasw, rules/vendor-wudisw, rules/vendor-uuslotsw, decisions/0023-large-table-add-column-must-assert]
decided_at: 2026-09-21
decision_status: accepted
---

# 無縫錢包注單表補 bet_time 索引時排除三張大表

## 背景

未結算注單報表（target/tickets#4295）盤點發現多張無縫錢包注單表缺 bet_time 索引；另有一些索引只以手動 SQL 建立，查不到 migration 紀錄（MR !2270 的 commit body）。

## 決定

- 一支 migration 為缺的表補上 bet_time 索引，已存在的冪等跳過（MR !2270 的 commit body）。
- 刻意排除 fumasw、uuslotsw、wudisw 三張大表（2026-09-17 決議）；fumasw、wudisw 改用 payout_time 索引，uuslotsw 改用 status 索引（MR !2270 的描述與 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 三張大表一起補 | TPower、Yes2Win 這三張表有數百萬到 5 千萬列。SchemaMonitor 的 50 萬列防線會取消建立，但 migration 仍記為已執行，把「沒建」固化成假象；而且報表排程已改用 status 欄位判定未結算，不靠 bet_time 掃描（MR !2270 的 commit body） |
| 排除三張大表，各自用既有索引（採用） | 無 |

## 後果

- 三家維持站間漂移，現況記在 `單錢包注單表_bet_time索引盤點.xlsx`（MR !2270 的 commit body）。
