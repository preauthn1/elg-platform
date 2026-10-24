---
type: Decision
id: decisions/0016-callback-source-whitelist-fail-closed
title: 回調來源 IP 白名單空白即全部拒絕
description: 金流回調的來源 IP 白名單採 fail-closed，白名單為空等於全部拒絕；白名單只存在金流商層級的 cash_flows.whitelist，來源 IP 一律取信任代理鏈判定的值。
domain: 金流
tags: [回調, 來源 IP, IP 白名單, whitelist, fail-closed, cash_flows.whitelist, CallbackSourceGuardTrait, Common::getIp, AP8PAY, EasyPay, MaxPay, TngPin, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Services/Payment/Traits/CallbackSourceGuardTrait.php, title: "isCallbackSourceAllowed()", at: 2026-10-21 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2381 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2403 }
related: [rules/payment-callback-common-checks]
decided_at: 2026-10-08
decision_status: accepted
---

# 回調來源 IP 白名單空白即全部拒絕

## 背景

- AP8PAY 的代收回調沒有簽名、沒有檢查來源，直接照回調金額上分（MR !2381 的描述）。
- TngPin 原本讀金流商戶設定裡的 `info['CallbackAllowIps']`，而且未設定就放行（MR !2403 的描述）。

## 決定

回調來源 IP 白名單一律 fail-closed：白名單為空等於全部拒絕，不是關掉檢查。白名單的單一來源是金流商層級的 `cash_flows.whitelist`，來源 IP 一律取 `Common::getIp()`。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 沿用既有的 `Common::verifyIPWhitelist()` | 那支在非 production 一律放行、白名單為空也放行，方向相反（MR !2403 的 commit 內文） |
| 白名單存在 `agent_cash_flows.info`，每個金流商戶一份 | 廠商的出口 IP 是金流商層級的屬性，存進每個金流商戶的設定會複製 N 份、各自漂移（MR !2403 的 commit 內文；MR !2381 的 commit 內文也說出口 IP 對該金流商所有金流商戶是同一份） |
| 來源 IP 取 `$request->ip()` | 它直接信任 `X-Forwarded-For`，偽造者自己加一個標頭就繞過了（MR !2403 的 commit 內文） |
| migration 預填廠商給的 IP | 廠商的 IP 會變，寫死在 migration 裡的一旦過期，已跑過的站點也不會再更新；廠商第一次給的五個 IP 就是錯的（MR !2381 的描述） |
| 白名單檢查放在 middleware | 不在白名單的請求根本進不到金流商的處理程式，被擋的往返紀錄也跟著沒了（MR !2403 的 commit 內文） |
| fail-closed、讀 `cash_flows.whitelist`、取 `Common::getIp()`（採用） | 無 |

## 後果

- 白名單要先配好再上線。欄位為空時該金流商的回調全部被擋、充值中斷，而且沒有錯誤訊息，症狀只有「都不上分」（MR !2381、!2403 的描述）。
- 被擋下時充值訂單的狀態、金額、點數一律不動，否則任何人都能從任意 IP 連發偽造回調，把待付訂單打成失敗（MR !2381 的描述）。
- 被擋的訂單會停在 CREATED、與三方脫節且沒有自動回收，要人工反查（MR !2381 的 commit 內文）。
- 清掉 TngPin 舊設定的 `fix:drop-tngpin-callback-allow-ips` 只能在已上新版的站點跑；舊版「未設定就放行」，先清等於把防線拿掉（MR !2403 的描述）。
