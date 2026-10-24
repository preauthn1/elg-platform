---
type: Decision
id: decisions/0027-backend-display-optional-flag
title: 後台顯示開關用選用參數，不隨狀態連動
description: cash-flow:channel-status 的後台顯示（backend_display）用選用參數 --backend-display 設定，不帶就不動；不讓 --set-status 自動連動。
domain: 金流
tags: [支付通道, 金流商戶, backend_display, 後台顯示, cash-flow:channel-status, agent:add-agent-cash-flow, 上下架, 止血, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Console/Commands/CashFlow/ChannelStatus.php, title: "--backend-display 選用參數", at: 2026-10-21 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2308 }
related: [rules/payment-channel-management]
decided_at: 2026-09-24
decision_status: accepted
---

# 後台顯示開關用選用參數，不隨狀態連動

## 背景

營運關閉金流時，代收關支付通道關聯（`agent_cash_flow_order_types`），代付關金流商戶（`agent_cash_flows`）；關閉前會先把同一層的 `backend_display`（後台列表是否顯示）設成 0。原本沒有任何命令能改 `backend_display`，「先隱藏」這一步只能靠後台或臨時命令（MR !2308 的描述）。

## 決定

`cash-flow:channel-status` 新增選用參數 `--backend-display`（0 隱藏、1 顯示），作用在 `--acf` 或 `--acfot` 指定的那一層；不帶就不動 `backend_display`。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| `--set-status` 自動連動 `backend_display` | 會讓所有金流商在停用時都被一併隱藏，改變現有命令的既有行為（MR !2308 的描述與 commit 內文） |
| `agent:add-agent-cash-flow` 在「沿用既有列」的路徑也套用 `--status` | 那兩條路徑原本就刻意保留營運手動設定的 status、限額與 settings，`backend_display` 同樣屬於營運值（MR !2308 的 commit 內文） |
| 選用參數，不帶就不動（採用） | 無 |

## 後果

- `agent:add-agent-cash-flow` 新建或覆寫時，`backend_display` 跟著 `--status` 走，停用就隱藏（MR !2308 的描述）。
- 重跑 `updateOrCreate` 那條路徑時，支付通道關聯的 `backend_display` 也會被寫成 `--status` 的值；營運手動隱藏的列，重跑 `--status=1` 就會重新顯示（MR !2308 的描述）。
- 只關支付通道關聯就足以擋住會員，因為存款清單與建單查詢都要求支付通道關聯的 status 為 1（MR !2308 的描述）。
