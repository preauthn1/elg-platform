---
type: Decision
id: decisions/0043-vendor-account-placeholder-before-vendor-create
title: 建玩家帳號先寫佔位，遊戲商建成才放進帳號池
description: 向遊戲商建號時，我方先寫一筆佔位帳號，遊戲商確認建成才改成可綁定；遊戲商失敗時帳號留在佔位狀態。
domain: 遊戲與遊戲商
tags: [帳號池, 補建遊戲帳號, 佔位帳號, member_id, -999999999, 兩段式, 競態, createAccount]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Vendors/Contracts/SupportsAccountCreation.php, at: 2026-10-22 }
  - { kind: code, ref: /app/Services/VendorAccountPoolService.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2144 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2258 }
related: [rules/vendor-account-pool]
decided_at: 2026-09-10
decision_status: accepted
---

# 建玩家帳號先寫佔位，遊戲商建成才放進帳號池

## 背景

61 家遊戲商的建帳號邏輯從 RD-Studio 移植到 APIs 的對接程式，移植時統一骨架（MR !2144 的 commit body）。

## 決定

兩段式 `member_id`：插入當下寫 -999999999 佔位，遊戲商建成才改成 0（可綁定）（MR !2144 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 插入當下就是可綁定（`member_id` 0），遊戲商失敗才回頭標成 -999999999 | 存在「遊戲商還沒建成就被玩家綁走」的競態，例外逃逸時還會留下假可用帳號（MR !2144 的 commit body） |
| 先佔位，遊戲商建成才改可綁定（採用） | 無 |

## 後果

- AiGamingSW 原本是「插入即入池、失敗才標記」的寫法，一併改成先佔位（MR !2144 的 commit body）。
- target 聚合商接手排程時同樣採用兩段式（MR !2258 的描述）。
- 佔位值後來也用在補建中斷後的對帳，見 [decisions/0059](0059-vendor-account-replenish-outside-transaction.md)；帳號池卡住的監測也靠它判斷，見 [decisions/0058](0058-vendor-account-pool-stuck-detected-by-tail-placeholders.md)。
- 例外：Evo 家族（含 Connect93、Live22）與 PPSW 仍是帳號寫入當下就可綁定、遊戲商回失敗才改成佔位，見 [rules/vendor-account-pool#R-13](../rules/vendor-account-pool.md)。這幾家要不要改成先佔位，列在該份的「還沒確認的事」。
