---
type: Decision
id: decisions/0058-vendor-account-pool-stuck-detected-by-tail-placeholders
title: 帳號池補建卡住以表尾連續佔位帳號判斷
description: 每小時監測帳號池補建是否卡住，判準是每個遊戲商戶帳號表尾端連續幾筆是未完成的佔位帳號，不解析遊戲商錯誤碼，也不看累積量。
domain: 遊戲與遊戲商
tags: [帳號池, 補建遊戲帳號, 監測, 告警, Telegram, 佔位帳號, vendor:monitor-account-pool, NextSpin]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Services/VendorAccountPoolService.php, title: "detectStuckPools()", at: 2026-10-22 }
  - { kind: code, ref: /app/Console/Commands/Schedule/MonitorVendorAccountPool.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2374 }
related: [rules/vendor-account-pool]
decided_at: 2026-10-07
decision_status: accepted
---

# 帳號池補建卡住以表尾連續佔位帳號判斷

## 背景

補建對遊戲商失敗時只在 log 留痕，水位停在原地，沒有人會發現。NextSpin 的 casion365 遊戲商戶（vendor_info 185）自 2022-10-03 第一筆失敗起，四年累積 113 萬筆未完成佔位，真正綁到玩家的只有 234 筆，直到 2026-10-07 排查別的問題才被看見（MR !2374 的 commit body）。

## 決定

- 逐個遊戲商戶從帳號表尾端（主鍵倒序）往回數連續幾筆 `member_id` 小於 0，遇到第一筆大於等於 0 就停（MR !2374 的 commit body）。
- 門檻是連續 10 筆，六站實跑驗證過（MR !2374 的 commit body）。
- 查詢以 UNION ALL 批次化，每個遊戲商戶各自 LIMIT（MR !2374 的 commit body）。
- 通知只在正式環境送（MR !2374 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 逐家解析遊戲商的錯誤碼（例如「帳號已存在」） | 成本高，判錯還會把遊戲商真有的帳號誤刪；67 支對接程式一律以 -999999999 佔位，負值就是「送了但沒完成」，跨遊戲商通用（MR !2374 的 commit body） |
| 看累積量（佔位總數、成功比例） | 遊戲商戶停用後仍持續命中，變成噪音；表尾連續數會在補建恢復或遊戲商戶停用後自動歸零（MR !2374 的 commit body） |
| 以 `created_at` 時間範圍掃描 | 帳號表可達百萬列，時間範圍掃描會逾時（MR !2374 的 commit body） |
| 整張表共用一個 LIMIT | 聚合遊戲商共表（Evo 61 商、EliteHub（TTHSW）51 商），取樣會被單一遊戲商戶的連續失敗佔滿，其餘遊戲商戶漏報（MR !2374 的 commit body） |
| 逐個遊戲商戶各查一次 | 資料庫在遠端，單趟往返 100 到 500ms，266 個遊戲商戶就是 266 趟（MR !2374 的 commit body） |
| 逐表確認有沒有 `vendor_info_id` 欄位 | VWorld 與 TPower 實查 92 張帳號表全部都有，逐表問一次要 509ms；缺欄位的表由批次失敗的退路接住（MR !2374 的 commit body） |
| 透過對接程式實例取帳號表名 | 要解密遊戲商戶配置，單次 100ms、快取一小時，每小時跑剛好每次都冷；改以 reflection 讀類別的預設值（MR !2374 的 commit body） |
| dev 也送通知 | dev 的三方多半打不通，實測命中 14 個遊戲商戶，發出去是噪音（MR !2374 的 commit body） |
| 表尾連續佔位數，批次查詢，只在正式環境通知（採用） | 無 |

## 後果

- VWorld 實測查詢從 54 秒降為 3 秒，各站結果與優化前逐筆一致（MR !2374 的 commit body）。
- 首次實跑：VWorld 2、TPower 1、Yes2Win 1 個遊戲商戶命中，其中 VWorld 的 WESW（vendor_info 456）可綁定帳號已經是 0 筆，目標水位是 50 筆（MR !2374 的 commit body）。
