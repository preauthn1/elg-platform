---
name: op-ticket-read
description: '讀 target/op_tickets（project 492）線上問題單，整理成結構化摘要，並依問題類型指出下一步該用哪支 skill 排查。只讀不寫。當開發說「op_tickets / OP 工單 / op #N / 客服轉的單 / 解析這個工單 / 這張工單怎麼處理」或貼 `op_tickets/-/work_items/N`／`op_tickets/-/issues/N` 網址時載入；說「整理 <工單連結> 的結案說明」「幫這張 OP 單寫結案說明」時也載入，產出留言草稿。🚫 要開新的 OP 單、整理截圖開單走 `op-ticket-create`；只想評這張單寫得夠不夠清楚走 `sure-score`。'
---

# OP 工單讀單（target/op_tickets）

一句話版本：**開發收到一張線上問題單，幫他讀懂、整理成摘要、指出下一步該用哪支 skill；要回留言或處理完時，幫他整理留言或結案說明的草稿。本 skill 只讀不寫。**

> 目標 project：`http://example.invalid:1688/target/op_tickets`（id `492`）。
>
> 分工：開新的 OP 單走 `op-ticket-create`；GFF 新需求單（project 365）走 `pm-ticket-create`。

## 名詞速查

| 名詞 | 意思 |
|---|---|
| OP 單／op_tickets | 線上問題單，放在 GitLab 專案 `target/op_tickets`（project 492）。客服或維運回報「玩家遇到問題」的地方 |
| TG | Telegram。客服與站方的對話多半發生在 TG 群 |
| MCP | 讓 Claude 直接呼叫外部服務的接口。本 skill 透過 `gitlab-mcp` plugin 提供的 GitLab MCP 讀單，工具名稱以 `mcp__plugin_gitlab-mcp_gitlab__` 開頭 |
| PAT | personal access token，登入 GitLab API 用的個人金鑰，由 `gitlab-mcp` plugin 設定與注入，本 skill 不自己拿 |
| iid | 工單在該 project 內的編號，也就是網址最後或 `#N` 的那個數字 |
| issue／work item | GitLab 對同一張單的兩種呈現方式。網址可能顯示 `work_items/N` 或 `issues/N`，讀取一律用 issue 的工具 |
| label | GitLab 工單上的標籤，例如優先級 `.P1 高優先(24小時)`、站台 `站方平台:TPOWER` |
| assignee | 工單指派給哪位開發 |
| domain | 問題屬於哪個領域（金流、遊戲商、銀行、獎勵……），用來決定下一步走哪支 skill |

---

## 前置條件

| 必備 | 平台限制 |
|---|---|
| 已安裝並設定好 `gitlab-mcp` plugin（PAT 由該 plugin 注入）；網路通到 `example.invalid:1688`（內網或 VPN） | 不限 OS。`op-toolkit` 的 `plugin.json` 已宣告 `dependencies: ["gitlab-mcp"]`，裝 op-toolkit 會自動帶 |
| 選配：本機有 `node`（第 6 步 SURE 評分的格式檢查程式要用）；沒有時照常讀單，只是不評分 | 同上 |

> 讀單統一走 GitLab MCP（`mcp__plugin_gitlab-mcp_gitlab__*` tool）。PAT 設定見 `gitlab-mcp` plugin 的 README。

新環境第一次跑前若 `/mcp` 看不到 `gitlab` server，依 gitlab-mcp README 設定 PAT 並 `/reload-plugins` 後再回來。

---

## 0. 確認是讀單（進入 skill 第一步）

| 訊號 | 走哪支 |
|---|---|
| URL `target/op_tickets/-/issues/<iid>` 或 `work_items/<iid>` | 本 skill |
| 訊息含「op #N」「op_tickets N」「解析這張單」 | 本 skill |
| 使用者貼截圖且**已提工單號** | 本 skill（截圖是補充資訊） |
| 「整理 <工單連結> 的結案說明」「幫這張 OP 單寫結案說明」 | 本 skill，走下方「整理結案說明」 |
| 要回這張單上的某則留言 | 本 skill，走下方「回覆一般留言」 |
| 訊息含「開單」「整理截圖開單」「OP 線上問題」「客服反饋」，或貼截圖且**未提工單號** | `op-ticket-create` |
| 兩者皆不確定 | AskUserQuestion 詢問 |

---

開發收到一張單，要在動手前先弄清楚：誰報的、哪個站、哪個玩家、發生什麼、期望什麼、該找哪支 skill 處理。本 skill 只讀不寫。

## 讀單第 1 步：啟動前檢查（A1）

1. **確認 GitLab MCP 可用**：工具清單裡有 `mcp__plugin_gitlab-mcp_gitlab__get_issue`（PAT 由 gitlab-mcp 注入，本 skill 不自取 token）。沒有時請使用者依 gitlab-mcp README 設定 PAT，再執行 `/reload-plugins`
2. **讀單 tool**：`mcp__plugin_gitlab-mcp_gitlab__get_issue`／`mcp__plugin_gitlab-mcp_gitlab__list_issue_discussions`
   - ⚠️ **禁用 `get_work_item`／`list_work_items`**：本 GitLab instance 的 GraphQL schema 沒有
     `WorkItemWidgetStatus`／`WorkItemWidgetWeight`／`WorkItemWidgetHealthStatus` 等型別，
     上游 MCP 的查詢寫死了這些 widget fragment，解析階段即整條被拒（`No such type ...`），
     一個欄位都拿不到。work item 與 issue 在 REST 層是同一個資源，**一律走 `get_issue`**。
3. 完整 tool 對照：[../op-ticket-create/reference/api-cheatsheet.md](../op-ticket-create/reference/api-cheatsheet.md)

## 讀單第 2 步：解析輸入（A2）

| 形式 | 範例 | 動作 |
|---|---|---|
| URL（work item 形式，GitLab 現行渲染） | `…/target/op_tickets/-/work_items/2008` | iid=2008，讀取仍用 `get_issue` |
| URL（issue 形式） | `…/target/op_tickets/-/issues/2008` | iid=2008 |
| 編號 + 上下文 | `op #2008`／`op_tickets 2008` | iid=2008 |
| 多筆 | `#2008 #2007 #2003` | 並列摘要。多張時只做第 3、5、8、9 步，不讀截圖、不評分、不找歷史單、不接手排查：每張都跑完整流程會把摘要淹沒。RD 指定要深入哪張，再對那張跑完整流程 |
| 純文字（離線） | 貼 telegram 對話 | 離線模式，不查 GitLab |

## 讀單第 3 步：抓取資料（A3）

`PID`=492（`target/op_tickets`），讀單與自動抓最新單列表皆同。

- `mcp__plugin_gitlab-mcp_gitlab__get_issue`（`project_id=<PID>`, `issue_iid=<IID>`）
- `mcp__plugin_gitlab-mcp_gitlab__list_issue_discussions`（`project_id=<PID>`, `issue_iid=<IID>`）

## 讀單第 4 步：讀附件截圖（A4）

這步在做什麼：線上問題單的站台、玩家帳號、單號、錯誤碼常常只出現在截圖裡，不讀截圖，摘要就會缺這些排查參數。

1. **找出附件**：從 description 與留言串找出所有 `/uploads/<secret>/<filename>` 連結（`<secret>` 是 32 個字元）。圖片（png、jpg 等）才下載；其他檔案（xlsx、pdf 等）不下載，只在摘要列出檔名。
2. **下載**：`mcp__plugin_gitlab-mcp_gitlab__download_attachment`（`project_id=492`, `secret=[REDACTED], `filename=<filename>`），**不帶 `local_path`**。不帶時工具直接回傳圖片內容；帶絕對路徑會被拒絕（`Invalid local_path: directory traversal is not allowed`）。
3. **張數上限**：單張工單最多讀 10 張，因為每張截圖都佔不少 context。超過時先列出附件清單（檔名＋所在位置：內文或第幾則留言），用 AskUserQuestion 問要讀哪幾張。
4. **分類與萃取**：依 [../op-ticket-create/reference/screenshot-extraction.md](../op-ticket-create/reference/screenshot-extraction.md) 的「截圖五大類型」與「每張截圖要記下的東西」兩節，判斷類型並記下看得到的欄位。
5. **跟文字對不上時兩邊都列**：截圖的值與內文不同時，兩個值都寫進摘要並標出處，不拿截圖覆蓋文字，也不自行判斷哪個對。例：內文寫一組 ErrorCode、截圖是另一組，可能是不同次發生，兩組都要查。
6. **看不清楚不猜**：模糊或被裁掉的欄位記為「無法辨識」。
7. **個資遮蔽**：從截圖讀出的姓名、手機、Email、銀行帳號，寫進摘要前一律遮蔽（例：`0912***456`）。玩家帳號、單號、錯誤碼是排查參數，原樣保留。
8. **Design 附件讀不到**：留言串有系統訊息「added N design」時，代表有圖放在工單的 Designs 分頁。它不是 `/uploads` 連結，`download_attachment` 讀不到，摘要附件欄寫「N 個 Design 附件未讀，見 <工單網址>/designs」，讓 RD 自己開來看。

## 讀單第 5 步：結構化解析（A5）

依 [../op-ticket-create/reference/parse-schema.md](../op-ticket-create/reference/parse-schema.md) 萃取下列欄位，來源包含內文、留言串與第 4 步讀到的截圖：

```
tg_group / tg_contact (識別來源 TG 群與聯絡人，若 description 中已標明則抓取)
station / vendor / player_id / order_no / time_range
symptom / expected / domain / severity / attachments
assignee / state / author
```

label 體系見 [../op-ticket-create/reference/label-conventions.md](../op-ticket-create/reference/label-conventions.md)。

## 讀單第 6 步：SURE 評分（A6）

這步在做什麼：評這張單的開單資料齊不齊，分數決定摘要要不要附「回問開單人」的補分清單。評分規則以 `sure-score` 為準，本 skill 不複製、不改動它的規則。

1. **載入 sure-score**：用 Skill 工具載入同 plugin 的 `sure-score`，照它的流程評分，包含它規定必跑的格式檢查程式。
2. **交給它的是工單原文**：第 3 步取得的 title、description、labels 原樣交給 sure-score，不再重抓工單。第 4 步從截圖讀到的內容不併進去：SURE 規定只寫在圖裡的資訊不給分，併進去會讓分數失真。
3. **評分結果照 sure-score 的輸出範本完整輸出**，放在第 9 步摘要之後，不刪改它的格式。
4. **分數怎麼影響下一步**：
   - 60 分以上：照常進第 10 步接手排查。
   - 60 分以下：一樣進第 10 步接手排查，另外在摘要加一行「建議先回問開單人」，並指向 SURE 輸出的「怎麼補分」清單，讓 RD 拿去回問。分數不擋排查。
5. **跑不起來時照常讀單**：sure-score 的檢查程式跑不起來（沒有 node、指令報錯）時，照它的規定不評分、不用估的。本 skill 在摘要寫「SURE 未評分」並附錯誤訊息，其餘步驟照常進行。

## 讀單第 7 步：找關聯與歷史工單（A7）

這步在做什麼：同一個站台、同一類問題常常反覆發生，先看過去的單怎麼處理，可以少走冤枉路。

1. **關聯單**：`mcp__plugin_gitlab-mcp_gitlab__list_issue_links`（`project_id=492`, `issue_iid=<IID>`），列出開單人或 RD 手動連結的單。
2. **歷史單，工單有 label 時**：工單同時有 `站方平台:*` 與 `問題類別:*` 兩個 label，就用這兩個 label 找已關閉的單：`list_issues`（`project_id=492`, `scope=all`, `state=closed`, `labels=[<站方平台 label>, <問題類別 label>]`, `per_page=5`）。
3. **歷史單，工單缺 label 時**：客服開的單常常只有優先級 label。這時改用標題裡描述現象的詞當關鍵字：`list_issues`（`project_id=492`, `scope=all`, `state=closed`, `search="<症狀關鍵詞>"`, `per_page=5`）。例：標題「游戏商户余额不足导致玩家前端无法进行游玩」取 `余额不足`。不加站台，因為同一類問題常在不同站台發生；站台與遊戲商用來排序結果，不用來篩選。標題看不出現象時不找，摘要寫明原因。
4. **排除本張**：結果裡有目前這張單就剔除。
5. **只列標題、連結、結案日期**：`list_issues` 會連同每張單的完整內文一起回傳，不要在摘要展開內文。RD 想看哪張，再用 `get_issue` 讀那張。
6. **關鍵字找到的單要再篩一次**：只留標題看得出是同一類問題的單，同站台或同遊戲商的排前面，並標出和本張的關係（例：「同遊戲商、不同站台」）。都不是同類問題時，寫「找到 N 張，標題看不出同類問題，未列出」。

## 讀單第 8 步：依 domain 指出下一步該用哪支 skill（A8）

依 [reference/routing-rules.md](reference/routing-rules.md)。精簡版：

| domain | 路由 |
|---|---|
| 投注帳變／派彩 | `list-sbr-corrections`（查調帳差額，也就是已關帳時段追加的差額列）／`reconcile-bet-records`（對齊驗證：比對注單各表的數字是否一致，只讀不寫） |
| 大額通知 | `large-amount-notify-debug` |
| 銀行 | `bank-why-missing`／`bank-config-supplement`／`bank-mismatch-fix` |
| 註冊驗證碼錯誤（9992，舊版為 9015） | 見 `reference/register-9015-debug.md` |
| log 查找 | `gcp-log-troubleshoot` |
| 查白標資料／在白標跑命令 | `whitelabel-db-query`（唯讀 SQL）／`whitelabel-container-exec`（跑 artisan 命令） |
| 金流配置 | `payment-integration` |
| 遊戲商配置 | `game-integration` |
| 遊戲商商戶額度不足（玩家有餘額卻跳資金不足） | 營運向遊戲商補額度，RD 不必改程式；用 `whitelabel-db-query` 比對補額度前後的注單數，確認已恢復 |
| 通用 bug | `dev-toolkit:bug-diagnosis`（先建一條會變紅的重現命令再列假設，不直接跳結論） |

**每個建議下一步都寫成「帶好參數、可以直接說的一句話」**。這樣 RD 在第 10 步選了之後，這句話直接交給對應 skill，不用再重打一次參數。

1. **參數只帶工單上真的有的**：站台、時間範圍、玩家帳號、單號、錯誤碼或 trace id、遊戲商（含 vendor_id），來源是第 4、5 步萃取的欄位。沒有的參數不補、不猜，在該句後面標「缺 <欄位>」。
2. **站台照工單原樣帶入**（例：label `站方平台:TPOWER` 就寫 `TPOWER`）。換成白標名稱的工作交給目標 skill：`whitelabel-db-query`、`gcp-log-troubleshoot` 等都會自己把站台別名換成它認得的白標。
3. **句型貼近目標 skill 的觸發講法**，例：
   - `gcp-log-troubleshoot`：「查 VWORLD 9/24 13:00–14:00 ErrorCode <值> 的 log」
   - `whitelabel-db-query`：「在 TPOWER 查代付單 <單號> 的狀態」
   - `reconcile-bet-records`：「PTSW 9/20 對帳，先看不要動」
4. **排序**：影響資金的排前面，其餘照 [reference/routing-rules.md](reference/routing-rules.md)「多 domain 處理原則」一節。

## 讀單第 9 步：輸出摘要（A9）

```
=========== OP 工單 #<iid> ===========
TG 群:    <tg_group>  ← ⚠️ 若未註明則顯示 "未標註，建議回問 author"
聯絡人:   <tg_contact>
站台:     <station>
遊戲商:   <vendor>
玩家:     <player_id>
單號:     <order_no>
時間:     <time_range>
現象:     <symptom>
期望:     <expected>
domain:   <domain>
嚴重度:   <severity label>
SURE:     <分數>/95 <等級>（未評分時寫「未評分：<錯誤訊息>」；60 分以下加「建議先回問開單人，補分清單見下方 SURE 評分」）
Assignee: <assignee_username>
狀態:     <state>

🔍 建議下一步：
  1. [<skill 名>] <帶好參數的一句話>（缺 <欄位>，無則省略）
  2. ...

📎 附件：N 張截圖（已讀 M 張）；其他檔案：<檔名，無則 ->；Design 附件：<N 個未讀，見 <工單網址>/designs，無則 ->

🔗 關聯單：#<iid> <標題>（無則 -）
🗂 歷史單（依 label／依關鍵字，最近 5 張）：
  - #<iid> <標題>（<結案日期>；依關鍵字找到時標和本張的關係）
  （沒找時寫原因，例：缺遊戲商名稱，未搜尋）
```

未知欄位用 `-`，不要編造。只從截圖讀到的值，後面標出處（例：`DEMO_00001234（截圖 2）`）；內文與截圖不一致時兩個都列（見第 4 步第 5 點）。
多單批次：每張獨立一格，最後加 `共 N 張 / 主要 domain: X` 統計。

## 讀單第 10 步：接手排查（A10）

這步在做什麼：摘要列出的建議下一步，讓 RD 點選就能開始排查。由 RD 決定走哪條，因為複合問題常常有好幾條路，路由表給的只是建議起點。

1. **問要走哪條**：輸出摘要後，用 AskUserQuestion 把「建議下一步」列成選項，選項文字就是第 8 步那句話。超過 4 條時，前 3 條放選項，其餘請 RD 回覆編號。RD 也可以選擇先不排查。
2. **載入對應 skill**：RD 選定後，用 Skill 工具載入該 skill，把選中的那句話當成輸入交給它。
3. **寫入把關交給目標 skill**：載入後照那支 skill 自己的規則走，本 skill 不替 RD 確認任何寫入。例如 `whitelabel-container-exec` 跑寫入類命令前要先確認、`repair-bet-records` 起手要先有對帳診斷結論，這些都照原樣執行。
4. **目標 skill 沒安裝時**：例如 RD 沒裝 `apis-toolkit`，就明說「沒有安裝 <skill 名>」，把那句話與參數當成手動排查清單交給 RD，不改用其他 skill 代替。

### 範圍與交付終點

這段在做什麼：排查時會看到很多事，先劃清哪些屬於這張單、做到哪裡就算交付，避免把別站的問題或別人的工作寫進這張單。這段同樣適用「整理結案說明」與「回覆一般留言」產出的草稿。

1. **範圍只限標題標的站台**（例：標題的 `[TPOWER]`）。其他站台的同類發現，只在回覆裡一句話告訴 RD，由 RD 決定要不要另開單；不寫進這張單的修復對象、留言草稿與結案條件。
2. **金流商戶的啟用歸站方**：金流商戶（`agent_cash_flows` 的一列，平台在某家金流商那邊的一組收付款身分）修好後要不要啟用，由站方在後台自己操作。RD 的待辦與結案條件不寫「RD 啟用商戶」，結案條件寫到「設定已修正＋實測結果」為止。通知客服時只簡短說修了什麼（例：「已更換 API Key」），不附啟用的步驟或建議。
3. **排查型的單，交付終點是查清原因並留言**。衍生的改善事項寫成轉交留言的草稿：程式要不要改交 PM 判斷；基礎設施問題（例：從庫延遲，也就是唯讀副本資料庫的資料落後主庫）交 SRE（負責主機與資料庫的維運團隊）查。這些改善事項不列成 RD 接下來的選項。補強驗證（例：另一天逐筆核對）RD 沒要求就不做。

---

## 整理結案說明

這段在做什麼：RD 處理完一張 OP 單後，把原因、處理方式、影響範圍、驗證結果整理成一則留言草稿，讓客服看得懂、之後回查的人也查得到依據。只產出草稿，由 RD 自己貼回工單。

1. **讀單**：照讀單第 3 步取工單與留言串。不讀截圖、不評分、不找歷史單、不跑建議下一步。
2. **收集處理結果**：先用這次對話中 RD 已經提供的排查結果。草稿需要的項目在對話和工單裡都找不到時，列出缺哪幾項問 RD，不自行編寫。
3. **找關聯 MR**：從工單內文、留言與 RD 提供的內容找 MR 引用（例：`target/gffv3/apis!2271` 或 MR 網址），用 `mcp__plugin_gitlab-mcp_gitlab__get_merge_request` 讀標題、狀態、合併時間。
   - 只寫了 `!NNNN` 沒寫專案時，問 RD 是哪個專案，不猜。
   - MR 狀態是 `merged` 只能寫「已合併」。「已上線」要有 RD 提供的發版依據（tag、部署紀錄）才寫。
4. **產出草稿**，照下面範本。讀者是客服與站方，正文用白話，不用代碼與檔名；技術依據收在最後的摺疊區：

```
**原因**：<一兩句白話說明>
**處理方式**：<做了什麼；需要客服或站方配合的事也寫在這裡>
**影響範圍**：<哪些站台、哪些玩家或訂單、哪段時間；有沒有影響金額>
**驗證結果**：<怎麼確認處理好了>

<details><summary>技術細節（RD 用）</summary>

- MR：<專案!編號>（<狀態>，<合併時間>）
- <其他依據：查詢結果、log、commit>

</details>
```

5. **草稿規則**：
   - 缺的項目寫「待補」，不自行編寫。
   - 姓名、手機、Email、銀行帳號遮蔽（例：`0912***456`）；玩家帳號、單號原樣保留。
   - 對帳類的單照 `reconcile-bet-records` 的對帳狀態分級寫：只有內部三表對齊時寫「內部對齊（暫時通過）」，不寫「已結案」或「已完全對齊」。
6. **交付**：草稿放在 code block 裡交給 RD。本 skill 不呼叫 `create_issue_note`、不改工單狀態或 label，回覆與關單由 RD 本人在 GitLab 操作。

---

## 回覆一般留言

這段在做什麼：RD 要回 OP 單上的留言（客服追問、站方補資料、回報處理進度）時，產出留言草稿。讀者是客服、維運與站方，留言本身就用白話寫。

1. **讀單**：照讀單第 3 步取工單與留言串，找出要回的那則留言。
2. **白話結論與待辦在上，技術證據收在最後**：最上面一句白話結論，例如錢有沒有付出去、單能不能結、誰要做什麼；接著分「客服這邊」「RD 這邊」列待辦。時間戳、回應碼、單號表這類技術證據，收進同一則留言最後的 `<details><summary>技術排查細節（RD 用）</summary>`（做法同「整理結案說明」的摺疊區）。
3. **白話的判準**：客服不用查程式碼就看得懂。錯誤碼只在括號內附原文，給 RD 對照。
4. **一則講完，不另標白話版**：標題與段落不寫「白話版」，也不先貼一則技術版、再補一則白話版。
5. **承諾 RD 處理的項目寫明怎麼追**：寫清楚會另開單還是在本單追蹤。另開單的附完整網址，例如 `[target/tickets#N](http://example.invalid:1688/target/tickets/-/work_items/N)`。修好時回同一張 OP 單留言說明。
6. **草稿規則**：照「整理結案說明」第 5 步，缺的寫「待補」，姓名、手機、Email、銀行帳號遮蔽。
7. **交付**：照「整理結案說明」第 6 步，草稿放在 code block 裡交給 RD，由 RD 本人貼回工單。

---

## 副作用界線

- 🚫 不改工單 body／title／label／assignee／state
- 🚫 不替工單下結論狀態
- ✅ 可產 comment 草稿（純文字），由開發自行貼回

---

## 錯誤處理

| 狀況 | 行為 |
|---|---|
| token 過期／401 | 提示 MCP gitlab PAT 失效（見 gitlab-mcp README 更新 PAT），不擅自重試 |
| 404 | 顯示 iid 與 project_id（492）、確認權限 |
| `No such type WorkItemWidget...`（GraphQL） | 誤用了 `get_work_item`／`list_work_items`。改呼 `get_issue`，不要重試同一個 tool |
| 全是截圖 | 依第 4 步讀截圖萃取；截圖也讀不出關鍵欄位時，明說哪幾個欄位無法辨識，並繼續用 title ＋ labels 解析 |
| 附件下載失敗 | 該張標 `下載失敗：<reason>`，其他附件繼續讀，不重試同一張 |
| 多單部分失敗 | 該張標 `ERROR: <reason>`，其他繼續 |
