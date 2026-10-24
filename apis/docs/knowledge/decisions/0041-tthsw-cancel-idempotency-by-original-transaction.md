---
type: Decision
id: decisions/0041-tthsw-cancel-idempotency-by-original-transaction
title: EliteHub（TTHSW）取消交易以原交易為冪等鍵，擋下時沿用既有錯誤碼
description: EliteHub（TTHSW）的取消交易（CANCEL_TRANSACTION，Type 9）以被取消的原交易判斷是否已取消過，比對不分大小寫；已取消過回 502、整局已取消回 403，不改用 503。
domain: 遊戲與遊戲商
tags: [TTHSW, EliteHub, 撤單, CANCEL_TRANSACTION, OriginalTransactionId, 冪等, 已退款, 錯誤碼, 502, 403, 503, 大小寫]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/TTHSWController.php, title: "findCancelForOriginalTransaction()", at: 2026-10-22 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2158 }
related: [rules/vendor-tthsw]
decided_at: 2026-09-09
decision_status: accepted
---

# EliteHub（TTHSW）取消交易以原交易為冪等鍵，擋下時沿用既有錯誤碼

## 背景

- 取消交易（CANCEL_TRANSACTION）的去重原本只認這筆取消交易自己的 TransactionId，被取消的原扣款從不被標記；三方換一個 TransactionId 重送同一筆取消交易，每次都會從整局注單再扣一次原金額並入帳（MR !2158 的 commit body）。
- 把錢還給玩家的幾個分支沒有「整局已取消」的守衛，先整局取消再取消交易，會在已全數還給玩家的注單上再還一次（MR !2158 的 commit body）。

## 決定

- 先以 game_round_id 縮到同局，再於 PHP 端比對 info 內的 OriginalTransactionId，判斷原交易是否已取消過（MR !2158 的 commit body）。
- 比對用不分大小寫的方式，與上游 SQL 的定序一致（MR !2158 的 commit body）。
- 補上整局已取消的守衛，涵蓋 CANCEL_ROUND、CANCEL_TRANSACTION、ADJUST、RELEASE_RESERVE 與 complete（MR !2158 的 commit body）。
- 原交易已取消過回 502 GAME_ROUND_TRANSACTION_DUPLICATED，整局已取消回 403 GAME_ROUND_REFUNDED（MR !2158 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 只以取消交易自己的 TransactionId 去重（原狀） | 三方換號重送時每次都再扣一次原金額並入帳（MR !2158 的 commit body） |
| PHP 端逐位元比對交易號 | 上游找原交易走 SQL，transaction_id 的定序是 `utf8mb4_unicode_ci`，大小寫不敏感；PHP 端逐位元比對時，三方送 abc123 會在上游命中原交易、在閘門比不中既有的取消交易，放行再把錢還給玩家一次（MR !2158 的 commit body） |
| 改用 503 GAME_ROUND_TRANSACTION_REFUNDED | 沒有 EliteHub 的三方文件，無從確認三方收到 503 會不會持續重送；502、403 是線上已在回的碼，行為已知（MR !2158 的 commit body） |
| 回應的 ExternalTransactionId 填 deal record（回調去重紀錄表，記下已處理過的回調）的 id | 注單表與 deal record 表都是自增整數、值域必然重疊，三方會拿同一個欄位對到不同表的列；改填注單 id，與其他成功回應一致（MR !2158 的 commit body） |
| 以原交易為鍵，沿用 502、403（採用） | 無 |

## 後果

- 2026-09-08 六站唯讀查詢：取消交易依原交易分組，TPower 215 筆、VWorld 620 筆、Yes2Win 422 筆皆零重複，缺口存在但尚未被踩到（MR !2158 的 commit body）。
- ADJUST 與 RELEASE_RESERVE 被擋屬行為變更（原本照做），六站零發生且沒有三方文件佐證；測試把現行決策釘住，日後 EliteHub 確認語意有變時看得到代價（MR !2158 的 commit body）。
- 改用 503 須先向 EliteHub 確認（MR !2158 的 commit body）。
