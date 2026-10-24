---
type: Decision
id: decisions/0057-suncity-shares-play8oy-driver
title: SunCity 繼承 Play8oy 對接程式，簽名修正放在父類
description: SunCity 與 Play8oy 同平台同版本，SunCity 繼承 Play8oy 的對接程式；簽名改用原始字串串接，修在父類讓兩家一起生效。
domain: 遊戲與遊戲商
tags: [SunCity, Play8oy, iGaming, 轉帳錢包, 繼承, 簽名, http_build_query, 原始字串, 對接程式]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Vendors/Suncity/Driver.php, at: 2026-10-22 }
  - { kind: code, ref: /app/Vendors/Play8oy/Driver.php, title: "sign() 以原始字串串接" }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2373 }
related: [rules/vendor-and-channel-management]
decided_at: 2026-10-07
decision_status: accepted
---

# SunCity 繼承 Play8oy 對接程式，簽名修正放在父類

## 背景

SunCity 是 iGaming 平台的 Clubsuncity 品牌，API 規格與既有 Play8oy（vendor 30）同一份，VW2 新站要接（MR !2373 的 commit body）。Play8oy 的簽名原本經 `http_build_query` 串接（MR !2373 的 commit body）。

## 決定

- SunCity 的對接程式繼承 Play8oy，只覆寫 App 自動登入的 deep-link scheme 與本地帳號欄位；Play8oy 把這些切點參數化（MR !2373 的 commit body）。
- 簽名在 Play8oy 父類改成：參數名升冪後以 `key=value&…&PrivateKey={key}` 原始字串串接（MR !2373 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 複製一份 Play8oy 對接程式改值 | 兩家同平台同版本，複製會讓簽名與轉帳邏輯兩邊各自漂移（MR !2373 的 commit body） |
| 只在 SunCity 覆寫簽名 | `http_build_query` 會把拉注單的 StartDate、EndDate 編成 + 與 %3A，與遊戲商文件的原始字串驗簽不符；修在父類，Play8oy 日後拉單也正確（MR !2373 的 commit body） |
| 繼承，修正放父類（採用） | 無 |

## 後果

- 轉帳與查單參數都是純英數，新舊算法輸出相同，已以測試逐組比對釘住（含負數浮點金額）；2026-10-01 核對過 VWorld、TPower 的 Play8oy 遊戲商戶配置與帳號只含英數與底線（MR !2373 的 commit body）。
- 建帳號 log 前綴改用類別的動作名稱，Play8oy 的 log 字串由 `[Play8oy]` 變成 `[PLAY8OY]`（MR !2373 的 commit body）。
