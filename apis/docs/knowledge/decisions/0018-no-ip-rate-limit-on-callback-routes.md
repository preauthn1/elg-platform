---
type: Decision
id: decisions/0018-no-ip-rate-limit-on-callback-routes
title: 回調路由不做 IP 限流
description: 金流回調路由 deposit/notify 與 withdrawal/notify 不掛依來源 IP 的限流；先整個移除，不靠調門檻。
domain: 金流
tags: [回調, 限流, throttle, rate limit, CallbackRateLimiter, deposit/notify, withdrawal/notify, IP 白名單, order_request_logs, 金流]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /routes/external/api.php, title: "兩條 notify 路由沒有 throttle", at: 2026-10-21 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2403 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2405 }
related: [rules/payment-callback-common-checks, decisions/0016-callback-source-whitelist-fail-closed]
decided_at: 2026-10-12
decision_status: accepted
---

# 回調路由不做 IP 限流

## 背景

MR !2403 的 commit 內文記載 code review 的意見：external 的回調路由對外公開、沒有認證，被白名單擋下的請求仍會寫一筆往返紀錄，等於一條未認證的資料庫寫入管道。MR !2403 因此給兩條回調路由掛上限流：白名單內的 IP 不限流，其餘每分鐘 10 次。

## 決定

`deposit/notify` 與 `withdrawal/notify` 不掛依來源 IP 的限流，`CallbackRateLimiter` 整個移除。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 白名單外每分鐘 10 次（MR !2403 採用，MR !2405 移除） | 前提是錯的：白名單以外不等於可疑來源。只有四家配了白名單，其餘二十幾家的真回調全在白名單外；金流商普遍用一兩個出口 IP 服務整個站，同一 IP 每分鐘的筆數算的是全站流量。2026-10-12 實測 TPower 單組尖峰 112 筆、89.7% 的分組超過 10 筆，Yes2Win 單組尖峰 120 筆、79% 超過；上線等於把那些家的回調擋掉九成，玩家付了錢不會上分（MR !2405 的描述） |
| 保留限流、調高門檻 | 門檻要訂得有依據，得先把各家白名單配齊，或改用不看 IP 的保護方式（MR !2405 的描述） |
| 整個移除（採用） | 無 |

## 後果

- `order_request_logs` 的欄位大小上限保留（request 4096、response 16384、headers 2048），那是另一道防護，而且現在是唯一的一道（MR !2405 的描述）。
