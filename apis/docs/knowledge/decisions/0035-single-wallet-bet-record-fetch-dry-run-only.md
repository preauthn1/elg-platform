---
type: Decision
id: decisions/0035-single-wallet-bet-record-fetch-dry-run-only
title: 無縫錢包遊戲商的拉注單只放行乾跑
description: 無縫錢包遊戲商可以拉三方注單來對帳，但只能乾跑、不寫入注單，也不進自動排程。
domain: 遊戲與遊戲商
tags: [拉注單, 單一錢包, 無縫錢包, 乾跑, 對帳, fetch_bet_record, ClotPlaySW, 孤兒注單, 注單彙總]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Services/BetRecordFetchService.php, title: "fetchChunk() 對無縫錢包的非乾跑呼叫拋例外", at: 2026-10-22 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2100 }
related: [rules/bet-record-pull]
decided_at: 2026-09-02
decision_status: accepted
---

# 無縫錢包遊戲商的拉注單只放行乾跑

## 背景

- ClotPlaySW（142、P2P 147、Legend Slot 213 共用同一支對接程式）要把舊系統 RD-Studio 的 `clotsw_check_bet_record` 對帳搬進 APIs，改走既有的拉注單流程（MR !2100 的 commit body）。
- 無縫錢包的注單由三方回調即時記帳。拉注單寫入的注單沒有對應的錢包異動（MR !2100 的 commit body）。

## 決定

- 無縫錢包遊戲商實作拉注單能力，但只放行乾跑：服務層對無縫錢包的非乾跑呼叫直接拋例外，涵蓋排程工作、`--direct` 與後台頁（MR !2100 的 commit body）。
- Olympus「拉取注單」頁換到無縫錢包遊戲商時自動打開乾跑開關並鎖住，送出時強制帶 `--dry-run`；表單狀態被繞過也不會寫入（MR !2100 的 commit body）。
- 不實作自動排程，只能手動觸發（MR !2100 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 無縫錢包照轉帳錢包的方式寫入拉回來的注單 | 會生出沒有錢包異動的孤兒注單，連帶灌歪 TBR 與注單彙總（MR !2100 的 commit body） |
| 只放行乾跑，服務層與後台頁兩層把關（採用） | 無 |

## 後果

- 乾跑結果另列「三方查無 N 筆」：派彩時間在窗口內、但整個窗口三方都沒回的既有注單（MR !2100 的 commit body）。
- 這份反向清單在整個窗口跑完後才比對，不逐片比；逐片比會把「下注在甲片、派彩在乙片」的注單誤報成缺漏（MR !2100 的 commit body）。
- 已知限制：窗口外緣仍會誤報，命令輸出附警語提示放寬窗口重跑（MR !2100 的 commit body）。
- 撞到三方限速時整片中止，沒有退避重試（MR !2100 的描述）。
