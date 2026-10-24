---
type: Decision
id: decisions/0047-onebx-unimplemented-money-endpoints-return-non-200
title: OneBX 尚未實作的動錢端點先掛路由記 log，一律回非 200
description: OneBX 的 step/bonus 與 step/refund-bonus 規格不明，先掛路由把三方請求完整記下，回系統錯誤讓三方持續重送，不回 404 也不回 200。
domain: 遊戲與遊戲商
tags: [OneBXSW, OneBX, step/bonus, step/refund-bonus, promotion, 未實作端點, 重送, 單一錢包, 無縫錢包]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/OnebxSWController.php, title: "logUnimplementedEndpoint()", at: 2026-10-22 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2278 }
related: [rules/vendor-onebxsw]
decided_at: 2026-09-23
decision_status: accepted
---

# OneBX 尚未實作的動錢端點先掛路由記 log，一律回非 200

## 背景

step/bonus、step/refund-bonus 是 promotion 發放與回收的動錢端點，新舊系統都沒實作。它們的冪等鍵、金額型別、promotion 該記到哪張表、找不到原單時的期望回應，文件全都沒定義，只能等真實請求（MR !2278 的 commit body）。

## 決定

兩支端點只掛路由，controller 記下完整 payload 後回 HTTP 500，body 的錯誤碼是 E_00，不做業務邏輯（MR !2278 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 不掛路由 | 沒有路由就是 404，請求紀錄中介層不會跑，永遠看不到三方送什麼（MR !2278 的 commit body） |
| 掛路由並回 200 | 回 200 等於承認已入帳，OneBX 就不再送，玩家的 promotion 直接消失（MR !2278 的 commit body） |
| 掛路由、記 log、回非 200（採用） | 無 |

## 後果

- 回非 200 時 OneBX 會持續重送到成功，實作上線後待處理的請求會自己補回來（MR !2278 的 commit body）。
- 線上 TPower 2026-09-17 與 Dev 2026-09-18 這兩支端點都零流量；實作屬新功能，另開工單（MR !2278 的 commit body）。
