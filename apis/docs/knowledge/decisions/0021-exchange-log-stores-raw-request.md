---
type: Decision
id: decisions/0021-exchange-log-stores-raw-request
title: 三方往返紀錄存回調原文
description: order_request_logs 存回調請求的原文而不是驗證過濾後的欄位；後台查詢端點只回純量欄位，往返原文不經任何 API 暴露。
domain: 金流
tags: [三方往返紀錄, order_request_logs, order request log, 回調, 原文, validate, 憑證, 遮罩, RecordsThirdPartyExchangeTrait, AP8PAY, EasyPay, MaxPay, TngPin, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Services/Payment/Traits/RecordsThirdPartyExchangeTrait.php, title: "logExchange()", at: 2026-10-21 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2381 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2403 }
related: [rules/payment-callback-common-checks]
decided_at: 2026-10-08
decision_status: accepted
---

# 三方往返紀錄存回調原文，對外只回純量欄位

## 背景

`order_request_logs` 記下每次與金流商的往返，讓客服查得到「為什麼沒上分」（MR !2381 的描述）。MR !2403 把它擴到 EasyPay、MaxPay、TngPin 的代收代付，代付記在共用流程裡，30 家走共用流程的金流商都有。

## 決定

回調紀錄存 `$request->all()` 的原文，不存 `validate()` 之後的結果；代收與代付的查詢端點只回時間、類型、判定結果、三方單號、HTTP 狀態、來源 IP 這幾個純量欄位，往返原文一個欄位都不回。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 存 `validate()` 之後的結果 | 只留規則裡宣告過的欄位，三方多送的欄位（簽名、額外狀態碼、AP8 實際有送的 `type`、`uniqueKey`）會被丟掉，而那正是事後要查的（MR !2381、!2403 的 commit 內文） |
| 寫入前用 `Common::flattenHeaders()` 處理 headers | 那支會順便遮罩，這張表要的是原文（MR !2381 的 commit 內文） |
| 後台畫面顯示往返原文 | 表裡存的是未遮罩內容、含明文商戶憑證，客服不需要也不該看到；要看內容由開發查資料庫（MR !2381、!2403 的描述） |
| 存原文、端點只回純量（採用） | 無 |

## 後果

- 往返原文禁止經任何 API 或後台暴露、禁止匯出（MR !2381 的 commit 內文）。
- 回調在處理程式外層包 try/finally 記一筆，在交易外寫入；寫紀錄前先收掉還開著的交易，否則接不住 `Error` 的那條路徑紀錄會跟著 rollback 不見（MR !2403 的描述）。
- 欄位上限拆成 request 4096、response 16384、headers 2048，紀錄保留 90 天，由 `clean:order-request-logs` 每日分批刪除（MR !2403 的描述）。
- 被白名單擋下的請求同樣會留下完整紀錄，這是這張表存在的理由（MR !2403 的 commit 內文）。
