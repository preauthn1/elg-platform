---
type: Command
id: apis/console/agent-add-agent-cash-flow
title: 建立金流商戶
description: 'RD 或上線人員接新金流商或替代理新增金流商戶與支付通道時使用的命令，可依拆分或單筆模式建立代收設定。'
domain: 金流
tags:
  - agent-add-agent-cash-flow
  - 金流商戶
  - 建立
  - 支付通道
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/CashFlow/AddAgentCashFlow.php
    at: '2026-10-21'
entity:
  class: /app/Console/Commands/CashFlow/AddAgentCashFlow.php
  signature: 'agent:add-agent-cash-flow'
---

<!-- knowledge:generated start -->
## 入口

`php artisan agent:add-agent-cash-flow`

Command：`App\Console\Commands\CashFlow\AddAgentCashFlow`

新增代理金流配置（代收可按 deposit channel 拆分：每通道一筆 AgentCashFlow、各自 cost）

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `--agent` | 選項（帶值） | 代理 ID |  |
| `--cashflow` | 選項（帶值） | 金流商 ID |  |
| `--currency` | 選項（帶值） | 幣別 ID 或代碼，例如：VND、SGD、3、2（支援：MYR=1, SGD=2, VND=3, IDR=6, THB=9, USDT=10, HKD=16） |  |
| `--status` | 選項（帶值） | 上下架狀態（0=停用並隱藏後台顯示, 1=啟用）；代收套在支付通道關聯、代收金流商戶固定啟用，代付套在金流商戶 | `1` |
| `--mode` | 選項（帶值） | 代收建立形式（split=拆分模式：一商戶對一通道各自 cost；single=單筆模式：一商戶對多通道單一 cost；未指定時互動詢問） |  |
| `--migrate-single` | 選項（旗標） | 拆分模式偵測到既有單筆記錄時就地遷移為拆分形式（非互動模式必須帶此 flag 才會遷移） |  |
| `--dry-run` | 選項（旗標） | 乾跑模式，不寫入資料庫 |  |
| `--force` | 選項（旗標） | 跳過環境確認 |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

RD 或上線人員在新接一家金流商、或替代理新增一個金流商戶時執行這支命令，在命令列建立金流商戶與底下的支付通道。代收情況下可以選擇拆分模式（一個金流商戶對應多條支付通道，各自計算成本費率）或單筆模式（多條支付通道共用同一個成本費率）。建立時的上下架狀態依代收或代付寫在不同層級，完成後在代理後台的支付渠道管理可以看到結果。規則見相關規則。

## 相關規則

* [rules/payment-channel-management](../../rules/payment-channel-management.md)
