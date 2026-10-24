---
type: Command
id: apis/console/currency-audit-orphan-parent
title: 稽核會員幣別解析鏈的缺口
description: 'RD 唯讀稽核命令，逐一檢查會員的幣別解析鏈斷在哪一步，並為缺上層代理的會員蒐集歸屬線索。'
domain: 金流
tags:
  - currency-audit-orphan-parent
  - 幣別
  - 會員幣別
  - 稽核
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/Currency/AuditOrphanParentCommand.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/Currency/AuditOrphanParentCommand.php
  signature: 'currency:audit-orphan-parent'
---

<!-- knowledge:generated start -->
## 入口

`php artisan currency:audit-orphan-parent`

Command：`App\Console\Commands\Currency\AuditOrphanParentCommand`

量測會員幣別解析鏈每一跳斷了多少，並為孤兒 parent 的會員蒐集歸屬線索（唯讀）

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--json` | 選項（旗標） | 以 JSON 輸出，供程式解析 |  |
| `--top` | 選項（帶值） | 列出會員數最多的前 N 個缺失 parent（各附子代理線索） | `20` |

排程：無
<!-- knowledge:generated end -->

## 做什麼

RD 在排查會員查不到幣別、或要確認幣別主檔資料品質時執行這支唯讀命令。命令逐一判定每個會員的幣別解析鏈斷在哪一步，例如上層代理未指派、上層代理不存在、代理所屬組織不存在、組織沒設幣別，或組織的幣別不在幣別主檔；對缺上層代理的會員，另外列出可能推回歸屬的線索。只要有一個會員解析不出幣別，命令就以失敗結束，過程中不寫入任何資料。規則見相關規則。

## 相關規則

* [rules/currency-and-wallet-currency](../../rules/currency-and-wallet-currency.md)
