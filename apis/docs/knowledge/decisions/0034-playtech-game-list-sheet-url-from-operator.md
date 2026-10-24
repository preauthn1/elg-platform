---
type: Decision
id: decisions/0034-playtech-game-list-sheet-url-from-operator
title: PlayTech 遊戲清單的活頁簿連結由觸發者帶入
description: PlayTech 遊戲清單讀官方 SharePoint 活頁簿，連結不寫進 config 或 env，由觸發者每次帶入，程式自動改寫成下載直鏈；只手動同步，不掛排程。
domain: 遊戲與遊戲商
tags: [PlayTech, PlayTechSW, PT, 遊戲清單, 活頁簿, SharePoint, sheet-url, NeedsGameListSourceUrl, 遊戲清單同步, 憑證]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Vendors/PlayTechSW/Driver.php, at: 2026-10-22 }
  - { kind: code, ref: /app/Vendors/Contracts/NeedsGameListSourceUrl.php }
  - { kind: code, ref: /app/Vendors/PlayTechSW/GameListSheet.php }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2099 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2101 }
related: [rules/game-list-sync]
decided_at: 2026-09-02
decision_status: accepted
---

# PlayTech 遊戲清單的活頁簿連結由觸發者帶入

## 背景

- PT 沒有遊戲清單 API，官方以一份 SharePoint 上的 xlsx 發佈。原本走人工 CSV 匯入（MR !2099 的描述）。
- 那條連結不需登入就能下載 PT 全站遊戲代碼，等同憑證（MR !2099 的描述）。
- 客服拿到的是 SharePoint 共用連結，給人在瀏覽器開 Excel Online 用；程式抓它只會拿到網頁（MR !2101 的 commit body）。

## 決定

- 對接程式直接讀活頁簿，接上共用的遊戲清單同步流程（MR !2099 的描述）。
- 連結由觸發者帶入：`php artisan update_game_list --sheet-url`，或 Olympus 系統操作頁選到這類遊戲商時才出現的欄位。未帶入就拋例外，不回空清單（MR !2099 的描述）。
- 共用連結由程式改寫成下載直鏈；只有路徑符合 `/:<檔類代號>:/s/<站台>/<token>` 才改寫，其他形式原樣送出（MR !2101 的 commit body）。
- 只手動觸發，不掛每小時排程（MR !2099 的描述）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 連結寫進 repo 的 config | 寫進 repo 等同永久留在 git 歷史（MR !2099 的描述） |
| 連結放 env | 四個白標的部署端要各自注入，換連結還得走一輪發布（MR !2099 的描述） |
| 沒帶連結時回空清單 | 空清單會讓遊戲清單同步誤判「三方未提供」而跳過偵測（MR !2099 的描述） |
| 要求操作者自己把共用連結改寫成下載直鏈 | dev 實測第一次就漏掉這步；漏一次就是一次假故障，改寫該由程式做（MR !2101 的 commit body） |
| 認不出的連結形式也猜格式改寫 | 可能是 PT 換了新形式，讓 SharePoint 自己回應比猜格式安全；真拿不到 xlsx 仍會被檔案檢查攔下（MR !2101 的 commit body） |
| 掛每小時自動同步 | 來源是人工維護的檔案、更新頻率低且連結可能失效（MR !2099 的描述） |
| 觸發者帶入連結、程式改寫、手動同步（採用） | 無 |

## 後果

- 連結會原文存進 `admin_operation_logs.params`：背景工作要從這一列讀回參數才組得出命令，無法只存遮罩版；能看操作紀錄的人就看得到它（MR !2099 的描述）。
- 改寫時 log 只記站台名、不記 token（MR !2101 的 commit body）。
- 實測 User-Agent 不影響下載，先前 dev 抓不到的原因出在網址形式（MR !2101 的 commit body）。
