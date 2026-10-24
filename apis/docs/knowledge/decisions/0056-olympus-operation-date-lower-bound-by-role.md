---
type: Decision
id: decisions/0056-olympus-operation-date-lower-bound-by-role
title: 拉取注單與重跑注單彙總的日期下限依角色區分
description: Olympus 拉取注單與重跑注單彙總兩頁，運維只能回溯 30 天、管理員 60 天，選 4 天前以上的日期要先確認。
domain: 遊戲與遊戲商
tags: [Olympus, 拉取注單, 重跑注單彙總, 日期下限, 運維, 管理員, 確認視窗, fetch_bet_record, summary_bet_record]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Filament/Admin/Pages/Operations/AbstractOperationPage.php, title: "MAX_DAYS_AGO、ADMIN_MAX_DAYS_AGO 與 maxDaysAgo()", at: 2026-10-22 }
  - { kind: code, ref: /app/Models/User.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2310 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2328 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2363 }
related: [rules/bet-record-pull, rules/summary-bet-record]
decided_at: 2026-10-07
decision_status: accepted
---

# 拉取注單與重跑注單彙總的日期下限依角色區分

## 背景

Olympus 的「拉取注單」「重跑注單彙總」兩頁都有日期欄位。日期下限前後改了三次：

- 原本拉取注單頁以「三方注單保留 14 天」為由，把兩週前的日期鎖住（MR !2310 的 commit body）。
- MR !2310 放寬到 60 天。
- MR !2328 兩頁一律收斂為 30 天，並加上確認視窗（工單 target/tickets#2887）。
- MR !2363 改成依角色區分（工單 target/tickets#4472）。

## 決定

- 兩頁的日期下限：運維 30 天，管理員 60 天；未登入一律套較嚴的 30 天（MR !2363 的 commit body）。
- 選到 4 天前（含）以上的日期時，執行前先跳確認視窗；門檻只比日曆天數（MR !2328 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 不分角色，下限 14 天（MR !2310 之前） | 「三方注單保留 14 天」的前提不成立：各遊戲商的保留期不一致，拉不到三方資料只是回空、沒有副作用，沒有理由在我方先擋（MR !2310 的 commit body） |
| 不分角色一律 30 天（MR !2328 採用，MR !2363 改） | 管理員補舊月份對帳時選不到日期（MR !2363 的描述） |
| 重跑注單彙總頁的管理員不受限（MR !2363 第一顆 commit 採用，第二顆收斂） | 收斂為 60 天，與三方注單保留期一致（MR !2363 的 commit body） |
| 依角色區分：運維 30 天、管理員 60 天（採用） | 無 |

## 後果

- 「執行」按鈕改由 Filament Action 渲染，表單按 Enter 送出也走同一顆 action，不會繞過確認視窗（MR !2328 的 commit body）。
- 沒有日期欄位的操作頁不受影響，按下去照舊直接執行（MR !2328 的 commit body）。
- 管理員可以回溯較長的理由，程式註解寫的是「舊月份對帳補帳需要」（`User` 的 docblock）。
