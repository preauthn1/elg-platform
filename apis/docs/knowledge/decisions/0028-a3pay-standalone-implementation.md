---
type: Decision
id: decisions/0028-a3pay-standalone-implementation
title: A3Pay 獨立實作，不與 GlobePay 共用
description: A3Pay 的代收與代付完整獨立實作，不與同閘道的 GlobePay 共用類別或 trait；薄繼承只用在協定完全相同的商戶。
domain: 金流
tags: [A3Pay, GlobePay, 金流商整合, 獨立實作, 繼承, trait, SGD, 代收, 代付, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Services/Payment/Providers/A3Pay/A3PayTrait.php, title: "A3Pay 自己的信封、加解密與簽名", at: 2026-10-21 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2312 }
related: [rules/provider-a3pay]
decided_at: 2026-09-30
decision_status: accepted
---

# A3Pay 獨立實作，不與 GlobePay 共用

## 背景

新接金流商 A3Pay（新加坡幣，VWorld-SG），範圍是代收（網銀 OB、掃碼 BQR）與代付（銀行轉帳）。它與既有的 GlobePay 是同一個閘道（MR !2312 的描述）。

## 決定

A3Pay 實作在 `app/Services/Payment/Providers/A3Pay/`，完整獨立，不與任何既有金流商共用類別或 trait。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 與同閘道的 GlobePay 共用實作或繼承 | 兩家 API 版本已分岐（欄位命名、端點、回應鍵、狀態碼表），改一家不得波及另一家；薄繼承只用在協定完全相同的商戶（MR !2312 的描述） |
| 完全獨立實作（採用） | 無 |

## 後果

- 代價是回調與加解密邏輯兩邊各一份（MR !2312 的描述）。
