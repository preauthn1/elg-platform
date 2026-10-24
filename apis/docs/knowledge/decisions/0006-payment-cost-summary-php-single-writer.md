---
type: Decision
id: decisions/0006-payment-cost-summary-php-single-writer
title: 金流商手續費彙總由 PHP 排程單一寫入
description: 代理數據看板的金流商手續費彙總由 PaymentCostSummaryService 每小時排程單獨寫入，原本的 MySQL EVENT 與預存程序停用，不做雙軌。
domain: 金流
tags: [金流商手續費, 彙總, summary_event_records, PaymentCostSummaryService, summary:payment-cost, 預存程序, MySQL EVENT, 排程, 代理數據看板, 金流, 報表]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Services/Summary/PaymentCostSummaryService.php, title: "event 3 與 8 的唯一寫入者", at: 2026-10-21 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2233 }
related: [rules/payment-cost-summary, decisions/0005-payment-cost-frozen-on-order]
decided_at: 2026-09-16
decision_status: accepted
---

# 金流商手續費彙總由 PHP 排程單一寫入，不與預存程序雙軌

## 背景

MR !2233 的描述指出，代理數據看板「支付手續費」讀的 `summary_event_records` event 3（代收）與 event 8（代付），原本由 MySQL EVENT 呼叫兩支預存程序產生：

- 代收預存程序六站每小時跑。
- 代付預存程序只有 Yes2Win 設了 EVENT，其餘五站從未產生代付的金流商手續費。
- 代付預存程序逐小時查詢、每小時 `SLEEP(2)`、寫入前不刪舊列（重跑會撞主鍵）、每次呼叫發一則 TG，大站一天跑不完。

## 決定

event 3 與 event 8 由 `PaymentCostSummaryService` 單獨寫入，排程 `summary:payment-cost --hours=3` 每小時第 20 分執行；兩支預存程序停用，不與 PHP 排程並行。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 維持 MySQL EVENT 呼叫預存程序 | 背景所列的問題：五站沒有代付彙總、大站一天跑不完、重跑撞主鍵 |
| PHP 排程與預存程序雙軌並行 | 原代付預存程序不先刪舊列，與 PHP 排程並行會撞主鍵報錯（MR !2233 的描述）；`docs/StoredProcedure(SP)異動方式.md` 也明寫這兩支不做雙軌 |
| `PaymentCostSummaryService` 為唯一寫入者，每小時排程（採用） | 無 |

## 後果

- 部署當天要在各站停用 EVENT（六站停代收那支，Yes2Win 另停代付那支），兩個寫入者不能並存（MR !2233 的描述）。
- Yes2Win 的代付範圍從 `yes2win` 代理線改為全站，需要 PM 確認（MR !2233 的描述）。
- 窗口取「現在整點往前 3 小時到現在整點」，與原代收預存程序一致，用來包容晚到的回調（MR !2233 的描述）。
- 回滾方式是關掉排程、依預存程序檔頭的註解重建 EVENT；彙總表可用 `order:recalc-payment-cost-summary` 針對區間重建（MR !2233 的描述）。
