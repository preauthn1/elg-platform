---
type: Decision
id: decisions/0059-vendor-account-replenish-outside-transaction
title: 帳號池補建不包交易，以時間預算分輪續補
description: 補建玩家帳號不把整家遊戲商包在一個資料庫交易裡，每輪只用工作時限的八成，剩下的缺口下一輪從主庫重算再補。
domain: 遊戲與遊戲商
tags: [帳號池, 補建遊戲帳號, 交易, 事務, 時間預算, 主庫, 複本, NextSpin, 三方呼叫]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Services/VendorAccountPoolService.php, title: "replenishVendor() 的時間預算", at: 2026-10-22 }
  - { kind: code, ref: /app/Jobs/ReplenishVendorAccountPool.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2366 }
related: [rules/vendor-account-pool]
decided_at: 2026-10-07
decision_status: accepted
---

# 帳號池補建不包交易，以時間預算分輪續補

## 背景

補建逐帳號打遊戲商開戶，原本整家遊戲商包在一個資料庫交易裡。跑到工作時限被砍掉時整趟回滾，當輪已建好的帳號一列不留，下一輪重算缺口依舊是滿的。VWorld NextSpin（48）因此連續 26 天一筆都補不進去，info 118 的池在 9/21 耗盡，新玩家拿不到帳號；遊戲商那邊的帳號其實已經開好，我方沒有任何紀錄（MR !2366 的 commit body）。

## 決定

- 補建工作與手動補建都移除外層交易；單筆 INSERT 本身就是原子的（MR !2366 的 commit body）。
- 補建加時間預算，用工作時限的八成，到點收手，剩下的缺口下一輪從資料庫重算續補（MR !2366 的 commit body）。
- 水位計數與流水號查詢改讀主庫（MR !2366 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 整家遊戲商包在一個交易裡（原狀） | 被時限砍掉就整趟回滾，已建好的帳號一列不留，遊戲商那邊已開戶、我方卻沒有紀錄（MR !2366 的 commit body） |
| 水位與流水號讀複本 | 每輪只補一部分之後，讀到複本的舊值會重複補建，甚至拿已經用掉的號重送遊戲商（MR !2366 的 commit body） |
| 不包交易、時間預算分輪、讀主庫（採用） | 無 |

## 後果

- 中間狀態的佔位帳號（-999999999）是「已送給遊戲商」的憑據，中斷時留著才有東西對帳（MR !2366 的 commit body）。
- 時間預算取代舊系統 `create_ns_account` 寫死每輪 50 筆的上限（MR !2366 的 commit body）。
- 「交易內不得呼叫三方」與配套三條規則補進 `.claude/rules/php.md`；同一支 MR 也把「轉回遊戲商餘額」外層的交易拿掉（MR !2366 的 commit body）。
