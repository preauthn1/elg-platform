---
type: Decision
id: decisions/0031-trustpay-currency-code-from-enum
title: TrustPay 幣別代碼取 CurrencyEnum 名稱，缺失即擋單
description: TrustPay 送三方的幣別代碼仍取 CurrencyEnum 的 case 名，不改讀幣別主檔；幣別 id 缺失或不認得時擋單並記 log，不回退預設值。
domain: 金流
tags: [TrustPay, 幣別, CurrencyEnum, currencies, 幣別主檔, 簽名, 擋單, 通道幣別, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Services/Payment/Providers/TrustPay/TrustPayTrait.php, title: "getCurrencyCode()", at: 2026-10-21 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2259 }
related: [rules/currency-and-wallet-currency]
decided_at: 2026-09-18
decision_status: accepted
---

# TrustPay 幣別代碼取 CurrencyEnum 名稱，缺失即擋單

## 背景

幣別主檔清洗的引用面盤點時發現，`TrustPayTrait::getCurrencyCode()` 是全專案 13 家金流商裡唯一拿 `CurrencyEnum` 的 case 名當送三方、而且進簽名的幣別代碼的（MR !2259 的描述）。代付側 `banks.currency_id` 可為 NULL，原本 `int` 參數直接丟 TypeError，被 `catch (Throwable)` 吞成訂單失敗原因字串，擋單時零 log（MR !2259 的描述）。

## 決定

先做最小改法：幣別代碼仍取 `CurrencyEnum` 的 case 名；`getCurrencyCode()` 改收 `?int`，NULL、0、不在 `CurrencyEnum` 內三種情況走同一條擋單路徑，記 `Log::error`，錯誤碼由 400 改 500。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 改讀幣別主檔的 `code` | 改讀主檔會經過 `Bank::currency()` 關聯的 `status = 1` 過濾，主檔幣別停用時代付會從能出單變擋單，屬行為變更，另案拍板（MR !2259 的描述） |
| 幣別缺失時回退預設值 | 幣別代碼進簽名，所以不回退預設值；這點寫進 docblock（MR !2259 的描述） |
| 代付側 pre-flight 失敗改由服務層另行分類 | 改分類要動服務層，不在本次範圍（MR !2259 的描述） |
| 沿用 Enum 名稱、缺失即擋單（採用） | 無 |

## 後果

- 錯誤碼改 500，因為這是我方通道設定缺失，不是玩家請求錯（MR !2259 的描述）。
- 唯一的行為變化：代付側銀行幣別為 NULL 時，訂單失敗原因從 TypeError 原文變成講清楚的幣別設定缺失訊息（MR !2259 的描述）。
- TrustPay 只做 SGD(2)，與清洗名單沒有交集；清洗驗收要對 TrustPay 的支付通道、訂單類型、銀行跑一次 `currency_id` 盤點，確認只有 2（MR !2259 的描述）。
- 六站有沒有 `banks.currency_id IS NULL` 而且被 TrustPay 使用的列，還沒查（MR !2259 的描述）。
