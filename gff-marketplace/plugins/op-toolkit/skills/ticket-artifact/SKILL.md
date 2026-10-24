---
name: ticket-artifact
description: target/tickets 需求單的示意圖（artifact）產製與分享規則：每張需求單都要附一份 artifact 版示意圖、發布後設成 target 全員可檢視、連結放進工單描述最上面。當要為工單做示意圖、發布或更新示意圖、補設示意圖的分享權限，或使用者說「幫這張單做示意圖 / 做需求單示意圖 / 示意圖發 artifact / 更新這張單的示意圖 / 補發示意圖 / 示意圖設成全公司可看 / 示意圖轉 PNG」時使用。由 pm-ticket-create 的 Phase 2 引用。🚫 報告、簡報、流程圖不走本 skill。
---

# 需求單 Artifact 規則

## 1. 每張需求單都要有 artifact

在 `target/tickets` 開的每一張需求單，都要發一份 artifact 版示意圖，並把連結放進工單描述的示意圖區塊下方：

```
> 可放大檢視版本：https://claude.ai/code/artifact/<id>
```

**只放 artifact 連結，不再產 PNG 上 GitLab**（2026-09-14 使用者定案，取代先前「工單放 PNG ＋ artifact 並存」的做法）。

**Why**：兩份等於同一張圖維護兩次，而且 PNG 是快照、artifact 會跟著更新，改完圖還要重新產圖＋重新 patch 工單的圖片連結。省掉的是整條產圖管線（headless Chrome → PIL 去白邊 → upload_markdown → patch），以及每次欄位被截斷就要重 render 的來回。

**開發與 QA 都有 claude.ai 帳號、開得開 artifact**（2026-09-14 使用者確認）。所以連結就是唯一的示意圖入口。日後若有新成員反映打不開，再對那張單補 PNG 即可，不用改回舊做法。

## 2. 分享權限：target 全員可檢視（view）

**每一個新建的 artifact 發布後，Claude 要自己把它設成組織可檢視，不要丟回去叫使用者自己點**（2026-09-04 使用者指示「以後也都要開」）。

Artifact 工具本身**沒有** share／permission 參數，但那個 share 選單是 claude.ai 的網頁 UI，**用使用者的 Chrome（claude-in-chrome）點得到**。實測可行的流程：

1. `mcp__claude-in-chrome__navigate` 開 artifact 網址
2. 點右上角 **Share** 按鈕（`find` 查 "share button" 找得到，ref 點不開時改用座標點，約在視窗右上角）
3. 面板出現 `General access`，預設是 **Only you**，點它展開
4. 三個選項：`Only people with access`／**`Everyone in target`**／`Anyone with the link`
   → 選 **`Everyone in target`**，右側權限維持 **`Can view`**
   ⚠️ **不要選 `Anyone with the link`**，那是公開連結
5. 選完面板即時變成 `Everyone in target` + `Can view`（同時會自動複製連結，按鈕顯示 Copied），不需要按儲存
6. `tabs_close_mcp` 收掉分頁

⚠️ **兩個實測踩過的坑**（2026-09-04，補開 20 幾個舊 artifact 時歸納）：
- **導覽與點擊不可放在同一個 `browser_batch`** —— 頁面還在載入，Share 鍵點不到，後續點擊會落空甚至誤點內容。navigate 要獨立一個呼叫並 wait 5~6 秒
- **`General access` 下拉第一次點常常只取得焦點、不展開** —— 要在**另一個獨立的工具呼叫**再點一次才會開；同一批次內連點兩次無效
- **不要用 JS 操作**：面板一失去焦點就自動關閉，而且注入環境的 `await sleep()` 不會真的等待

所以逐一補開舊 artifact 很花來回（每個 3~5 次呼叫）。**發布當下順手設定最省**——那時分頁已經開著、頁面已載入，通常 2 個呼叫就完成。批量補開如果數量多，先問使用者要不要自己點（每個只要三下）。

**只需要設定一次**：之後用同一個 `url` republish，分享狀態保留，觀看者立即看到新版（read 結果會顯示 `shared with your organization (viewers see updates immediately)`）。

## 3. 判斷是新建還是更新

- `Artifact` publish **不帶 `url`** ＝ 新建 → 要提醒設分享
- **帶 `url`**（或本場對話用同一個 file_path 再發一次）＝ 更新原網址 → 分享狀態沿用，不用提醒

要更新舊對話發過的 artifact，一定要帶 `url`，否則會多出一個 private 的新 artifact。找不到網址時用 `Artifact action:"list"`（注意預設 `scope:"mine"`，organization 分享出去的要用 `scope:"all"`）。

## 4. 更新既有 artifact 的成本

republish 前平台會擋：必須先 `action:"read"` 取回線上版，並把存下來的檔案**每一行都 Read 過**才算「已檢視」。所以：

- 本機留著原始 HTML（例如 `~/Desktop/TTN/*示意圖.html`），改本機檔比從 artifact 救檔便宜很多
- 若本機檔已遺失，才走 read → 全檔 Read → 重建的流程

## 5. 示意圖流程

**常態流程（2026-09-14 起）**：寫 HTML → `Artifact` 發布 → 設組織可檢視 → 工單描述最上面放連結。**不產 PNG、不上傳 GitLab、不 patch 圖片連結。**

工單裡的示意圖區塊就寫一行：

```
> 示意圖：https://claude.ai/code/artifact/<id>
```

**產圖管線只在特例才跑**（例如要貼進 Slack／文件、或有人回報開不了 artifact）：
HTML → 本機 http server → headless Chrome → PIL 去白邊 → `upload_markdown` 上 GitLab → patch 工單圖片連結。

⚠️ 產出的 PNG **不要用 Read 整張看過**，那很貴；要檢查版面就 `zoom` 框關鍵區域。

跑產圖時踩過的坑：
- HTML 一定要有 `<meta charset="utf-8">`，否則截圖全是亂碼（可在暫存的 shot 檔前面補一行 prefix，不動原檔）
- 去白邊要用 `im.getpixel((3,3))` 當背景色比對，不可寫死白色（深色主題的圖會整張留白）
- 表格若被容器裁切，在 shot 檔覆寫 `.mock{overflow:visible}`／`.scroller{overflow:visible}`／放寬 `.wrap` max-width，讓欄位一次展開
- 絕對定位的註記框，加字後會疊到下一框，要同步下移 `top`

## 5.5 示意圖的定義：整頁，不是零件

**「示意圖」＝把原畫面補上改動後的整個頁面**，不是只畫新增／異動的那一塊（2026-09-22 使用者指示）。

- 現況圖、改動後圖**都要是完整頁面**：麵包屑／頁標題、全部篩選欄位、查詢區按鈕、批次按鈕、表頭全欄、分頁列都要在。
- 新增的區塊**畫在它實際會出現的位置**，周圍原有元素照原樣留著，看得出它擠掉了什麼、沒擠掉什麼。
- 只框一塊局部圖，開發會看不出其他東西動不動、位置對不對，等於沒給規格。
- 整頁擺不下兩欄並排時，**改成上下堆疊**（現況在上、改動後在下），不要為了並排而砍掉欄位。
- 欄位、按鈕的中文字**去 repo 的 `locales/legacy/zh-TW.json` 查 `tl()` key 的實際值**，不要自己翻。踩過的坑：空狀態實際是「暫無記錄」，不是「查無資料」。

## 6. 內容慣例

- 金額／數值欄：**正數綠、負數紅**，只套用會出現負值的欄位；恆為正的金額、次數、時間不上色，零值不上色
- 橘色框為實作註記，並在圖例標明「非畫面元素」「數字為示意值」
- **範例帳號一律用 `agent01`／`agent02`…（代理）與 `player01`／`player02`…（會員）**，不要用實機上撈到的真實帳號（2026-09-23 使用者指示）。真實帳號會讓看圖的人以為是實際資料，也把測試帳號散到圖裡
