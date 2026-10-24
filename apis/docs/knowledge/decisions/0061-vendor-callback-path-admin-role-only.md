---
type: Decision
id: decisions/0061-vendor-callback-path-admin-role-only
title: 廠商管理的回調路徑只給角色為 admin 的帳號看與改
description: 代理後台廠商管理的回調路徑，以帳號角色是否為 admin 判斷能不能看與改，不沿用平台管理員的判定。
domain: 遊戲與遊戲商
tags: [廠商管理, 回調路徑, 權限, admin, role_id, RoleEnum, isPlatformAdmin, 代理後台, callback_visible]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Services/Agent/VendorManagementService.php, title: "canManageCallback()", at: 2026-10-22 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2408 }
related: [rules/vendor-and-channel-management, decisions/0062-vendor-callback-url-uses-external-api-domain, decisions/0003-vendor-channel-and-callback-declared-by-driver]
decided_at: 2026-10-13
decision_status: accepted
---

# 廠商管理的回調路徑只給角色為 admin 的帳號看與改

## 背景

上線前使用者追加需求：廠商管理的回調路徑只開放給帳號角色為 admin 的使用者看與改（MR !2408 的 commit body）。

## 決定

- 比對 `agents.role_id` 是否等於 admin（值 1），判定集中在一個方法，列表與更新共用（MR !2408 的 commit body）。
- 非 admin 查列表：仍可查，回傳 `callback_visible = false`，每列整個不放四個回調欄位、頂層也不放回調網域（key 不存在，不是 null），讓前端無從取得回調路徑（MR !2408 的 commit body）。
- 非 admin 更新回調路徑：回權限不足，與選單權限中介層擋下時同一訊息（MR !2408 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 沿用平台管理員判定（`Agent::isPlatformAdmin()`） | 它判定的是 id 為 1 或 host_id 為 1，也就是 admin 本人及其子帳號，範圍比「帳號角色是 admin」寬，子帳號也會看到回調路徑，不符合需求（MR !2408 的 commit body） |
| 比對帳號角色是否為 admin（採用） | 無 |

## 後果

- admin 看到的欄位與改版前完全相同（MR !2408 的 commit body）。
