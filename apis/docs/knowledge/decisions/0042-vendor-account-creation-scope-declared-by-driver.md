---
type: Decision
id: decisions/0042-vendor-account-creation-scope-declared-by-driver
title: 自動補建玩家帳號的範圍由對接程式宣告
description: 哪些遊戲商納入每 5 分鐘的帳號池自動補建，由對接程式是否實作排程介面決定，不再用 vendor id 分界線加白名單。
domain: 遊戲與遊戲商
tags: [帳號池, 補建遊戲帳號, 預創帳號, create_vendor_account, ShouldScheduleAccountCreation, SupportsAccountCreation, 對接程式, Driver, RD-Studio]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Vendors/Contracts/ShouldScheduleAccountCreation.php, at: 2026-10-22 }
  - { kind: code, ref: /app/Vendors/Contracts/SupportsAccountCreation.php }
  - { kind: code, ref: /app/Services/VendorAccountPoolService.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2144 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2258 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2345 }
related: [rules/vendor-account-pool]
decided_at: 2026-09-10
decision_status: accepted
---

# 自動補建玩家帳號的範圍由對接程式宣告

## 背景

遊戲商預創帳號的排程從 RD-Studio 搬到 APIs。舊的建帳號命令以「vendor id 大於等於 176」為分界線自動涵蓋，另以白名單補列例外（MR !2144 的 commit body）。

## 決定

- 新增兩個介面：支援建號（`SupportsAccountCreation`）與加入自動排程（`ShouldScheduleAccountCreation`）。排程的全量範圍改由對接程式有沒有實作排程介面決定（MR !2144 的 commit body）。
- 只實作支援建號、沒實作排程介面的遊戲商，保留手動補建（MR !2144 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| vendor id 大於等於 176 的分界線加白名單（原狀） | dev 庫實測：Fuma（171）的對接程式已能建號卻被漏掃；另有 5 家沒實作建號，原本只會空跑或報錯（MR !2144 的 commit body） |
| Evo（Titan 聚合）不納入自動排程（MR !2144 的 c10717325 採用，同一支 MR 的 27c339af1 改成納入） | 不納入的理由是 Titan 家族當時仍由 RD-Studio 的 `create_evo_account` 補池，兩邊都跑會互撞流水號（c10717325 的 commit body）。27c339af1 把 Dg、Evo 升級為實作排程介面，改以「上線前 RD-Studio 對應排程必須先下線」避免互撞（27c339af1 的 commit body） |
| 由對接程式實作的介面決定範圍（採用） | 無 |

## 後果

- 新接的遊戲商要記得實作排程介面。AiGamingSW 與搬遷平行開發，照舊架構只寫了建號方法，範圍改由介面決定後就掉出自動補池，事後補掛（MR !2144 的 commit body）。
- 現在 Evo 與 Dg 都實作排程介面，納入自動補池（MR !2144 的 27c339af1 commit body）。
- 上線前 RD-Studio 對應的建帳號排程必須先下線，否則兩邊補池會互撞流水號（MR !2144、MR !2258、MR !2345 的描述）。
- 之後 target 聚合商、PlayTechSW、OneBXSW、WudiSW、VplusSW、PPSW 陸續掛上排程介面接手（MR !2258、MR !2345 的描述）。
