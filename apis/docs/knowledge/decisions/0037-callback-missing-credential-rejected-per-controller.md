---
type: Decision
id: decisions/0037-callback-missing-credential-rejected-per-controller
title: 缺遊戲商戶憑證的回調在各家 controller 拒收，不改共用底座
description: AcewinSW 與 Ace333SW 的回調沒帶遊戲商戶憑證一律拒收；這道檢查寫在兩家各自的 controller，無縫錢包共用底座對空遊戲商戶識別碼的語意不動。
domain: 遊戲與遊戲商
tags: [單一錢包, 無縫錢包, 回調, 商戶憑證, Basic Auth, token, fail-closed, AcewinSW, Ace333SW, BaseController, operatorId]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/AcewinController.php, title: "parseOperatorId() 缺憑證回 Invalid client", at: 2026-10-22 }
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/Ace333SWController.php }
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/BaseController.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2148 }
related: [rules/vendor-acewin, rules/single-wallet-callback-common-checks]
decided_at: 2026-09-08
decision_status: accepted
---

# 缺遊戲商戶憑證的回調在各家 controller 拒收，不改共用底座

## 背景

無縫錢包共用底座取遊戲商戶時，若遊戲商戶識別碼是空的，語意是「退回該玩家所屬遊戲商戶」，會整段跳過憑證比對。Acewin 的 Basic Auth 與 Ace333SW 的 header token 都從請求標頭解出來，缺漏時傳進去等於只剩 IP 白名單一道（MR !2148 的 commit body）。

## 決定

- Acewin：解不到憑證就回 errorCode=5 Invalid client，與憑證錯誤同一回應，並照樣記 warning 供觀察（MR !2148 的 commit body）。
- Ace333SW：header token 缺漏或空字串，與 token 錯誤走同一條拒收路徑（MR !2148 的 commit body）。
- 共用底座不動（MR !2148 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| Acewin 不帶憑證照常處理、只記 warning（原狀） | 這是等待三方確認的過渡做法；生產 log 已證實 TPower、Yes2Win 的回調 100% 帶 Authorization，過渡期結束（MR !2148 的 commit body） |
| 在共用底座全域改成缺憑證就拒收 | 另有 12 支 controller 刻意以空遊戲商戶識別碼走「帳號所屬遊戲商戶」語意，全域拒收會打斷它們（MR !2148 的 commit body） |
| 在兩家 controller 各自拒收（採用） | 無 |

## 後果

- 那 12 支 controller 是 PxplaySw、AiGamingSW、PlayTech、RectangleSw、Qqpk、BeSoftSW、DstPlaySW、UUSlotSW、JokerBaccaratSw、Rgsw、Rcb988、Vplus（MR !2148 的 commit body）。
- 合併當時 Acewin 回調仍由舊系統 API_External 處理，APIs 路徑零流量；舊系統是否同步收口另評估（MR !2148 的 commit body）。
