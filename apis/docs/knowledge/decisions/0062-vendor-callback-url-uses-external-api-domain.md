---
type: Decision
id: decisions/0062-vendor-callback-url-uses-external-api-domain
title: 廠商管理的回調路徑以對外 API 網域組成
description: 廠商管理顯示的遊戲商回調路徑，以 custom.api_external_url 為網域、接上回調路徑後綴，不用 app.url。
domain: 遊戲與遊戲商
tags: [廠商管理, 回調路徑, api_external_url, app.url, k8s, 網域]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Services/Agent/VendorManagementService.php, title: "回調網域取 config('custom.api_external_url')", at: 2026-10-22 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2408 }
related: [rules/vendor-and-channel-management, decisions/0061-vendor-callback-path-admin-role-only, decisions/0003-vendor-channel-and-callback-declared-by-driver]
decided_at: 2026-10-13
decision_status: accepted
---

# 廠商管理的回調路徑以對外 API 網域組成

## 背景

代理後台「廠商管理」列表要顯示每家無縫錢包遊戲商的完整回調路徑（MR !2408 的 commit body）。

## 決定

完整網址是 `config('custom.api_external_url')` 去掉結尾斜線後，接上回調路徑後綴；網域與金流回調同源（MR !2408 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 用 `app.url` 當網域 | `app.url` 在 k8s 是叢集內部位址，三方從外面打不到（MR !2408 的 commit body） |
| 用 `custom.api_external_url`（採用） | 無 |

## 後果

無
