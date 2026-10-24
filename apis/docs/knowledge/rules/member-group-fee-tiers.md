---
type: Rule
id: rules/member-group-fee-tiers
title: 會員群組的平台手續費階梯
description: 代理在後台編輯會員群組時，平台手續費階梯可以怎麼設、什麼設定會被擋下。
domain: 會員
subdomain: 帳號與建立
tags: [會員, 會員群組, 會員手續費, 平台手續費階梯, 手續費階梯, 提款, member group, 金流]
entities:
  - class: App\Http\Requests\Agent\MemberGroup\StoreRequest
  - class: App\Models\MemberGroup
  - class: App\Services\MemberGroupService
  - table: member_groups
evidence:
  - kind: code
    ref: /app/Http/Requests/Agent/MemberGroup/StoreRequest.php
    title: 會員群組儲存時的階梯驗證規則
    at: 2026-10-20
  - kind: code
    ref: /app/Models/MemberGroup.php
    title: 階梯的正規化與排序
  - kind: code
    ref: /app/Services/MemberGroupService.php
    title: 寫入前正規化，空陣列存成未啟用
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2325
    title: 會員群組平台手續費階梯，會員提款依當日第幾筆計費
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4353
    title: 下線會員管理 > 會員群組 - 平台手續費階梯設定
related:
  - rules/withdrawal-member-fee
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
---

## 這個主題在講什麼

代理在後台建立或編輯會員群組，決定這一群會員的充值、取款、轉帳限制。

本份目前只收錄「平台手續費階梯」這一組設定。平台手續費階梯依會員當日第幾筆取款決定會員手續費，可以設成「前幾筆免費、之後才收」。

階梯怎麼套用到每一筆取款，見 [rules/withdrawal-member-fee](withdrawal-member-fee.md)。群組的其他設定隨後續 MR 補入。

## 怎麼觸發

代理後台 › 下線會員管理 › 會員群組 › 編輯 › 「提款」區塊最下方，接在「每日提款金額上限」之後。

## 規則

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 平台手續費階梯是一張可增刪的表，最多 10 階。每一階填「從第幾筆起」、手續費類型（百分比或固定金額，每階可以不同）、數值。超過 10 階時擋下儲存。 | code:StoreRequest::rules |
| R-2 | 「從第幾筆起」必須是大於等於 1 的整數，而且一階比一階大；重複或倒退時擋下儲存並提示。填 1 代表第一筆就收費、沒有免費額度，是合法設定。 | code:StoreRequest::withValidator |
| R-3 | 百分比填 0 到 100 的數字（3 代表 3%），固定金額填 0 到 999999.99999，兩者最多 5 位小數。超出範圍時擋下儲存。 | code:StoreRequest::withValidator |
| R-4 | 儲存後重開，每一階的三個欄位值與儲存前一致。階梯依「從第幾筆起」由小到大排列。 | code:MemberGroup |
| R-5 | 階梯全部刪光後儲存，這個群組的會員取款回到沿用取款銀行的手續費。 | code:MemberGroupService |
| R-6 | 「每日提款次數」上限小於某一階的起點時（例如上限 5、階梯設到第 11 筆起），照樣允許儲存，那一階只是用不到。 | code:StoreRequest::rules |
