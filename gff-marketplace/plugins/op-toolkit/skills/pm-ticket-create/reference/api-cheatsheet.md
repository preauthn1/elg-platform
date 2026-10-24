# GitLab API Cheatsheet (deterministic steps)

> 透過 `gitlab-mcp` plugin 提供的兩組 MCP tool 執行：上游套件的 `mcp__plugin_gitlab-mcp_gitlab__*`，以及工單小工具 `mcp__plugin_gitlab-mcp_gitlab-workitem__*`。
> 不走 curl、不需要 `target-pat`：PAT 由 gitlab-mcp 注入（見該 plugin README）。
> 進 skill 前先確認 `/mcp` 有 `gitlab` 與 `gitlab-workitem` 兩個 server；沒有就依 gitlab-mcp README 設定後再回來。
> `create_work_item` 屬上游套件的 `workitems` toolset（非預設）；gitlab-mcp 的 `.mcp.json` 已設 `GITLAB_TOOLSETS=all`，直接可用，**不需** `discover_tools`。
> 上游的 `update_work_item` 在本站一律失敗（不論改什麼都會先要回本站沒有的 `WorkItemWidgetStatus`／`Weight`／`HealthStatus` 型別），已隱藏；掛父子關係與改起訖日改用工單小工具。

## Project IDs

| parent_project | parent_id | subtask_project | subtask_id |
|---|---|---|---|
| `target/tickets` | 365 | `target/ticket_tasks` | 544 |

> MCP tool 的 `project_id` 參數可直接用數字 id 或 URL-encoded path（如 `target%2Ftickets`）。本 skill 統一用數字 id；工單小工具也吃數字 id，會自己查出專案路徑。

---

## Step-by-step execution order

> **Skill MUST execute steps in this order**。每個 step 的「Abort on」條件觸發 → 立即停止 + 回報（不可靜默繼續）。
> `create_work_item` 一次就能帶 `labels`（名稱）、`assignee_usernames`（名稱）、`milestone_id`、`start_date`、`due_date`。

### Step 0：Preflight token + auth

- tool：`mcp__plugin_gitlab-mcp_gitlab__whoami`（驗 PAT 有效）
- tool：`mcp__plugin_gitlab-mcp_gitlab__list_labels`（`project_id=<parent_id>`, `search=0.0不收費`，驗目標 project 可達 + 有權限）。不用 `get_project`：它的回傳帶有 `runners_token` 明文，會被帶進對話內容
- **Expected**：whoami 回傳當前 user（含 `id` / `username`）；list_labels 回傳標籤清單（`0.0不收費` 在內）
- **Abort on**：whoami 失敗（401/未連線）→ 提示「MCP gitlab 未連線或 PAT 失效，見 gitlab-mcp README」；list_labels 403/404 → 提示權限或 project id 錯
- **Capture**：`author_username` = whoami 的 `username`（判斷開單身分、Step 2 決定主單負責人用）。只取 `username`，回傳裡的 Email、真實姓名等欄位不轉述

---

### Step 1：Verify assignee usernames ＋ resolve milestone id

對每個子任務的 assignee（使用者從名單檔 `team-roster.yaml` 點選的，或測試預設的 `Benwu`）逐一驗證其存在於 subtask project：

- tool：`mcp__plugin_gitlab-mcp_gitlab__list_project_members`（`project_id=<subtask_id>`, `query=<username>`, `include_inheritance=true`）
- **Expected**：回傳清單含預期 `username`
- **Abort on**：某 assignee `username` 不在清單 → PREVIEW 標 `⚠ 待認領`，使用者確認 fallback 規則後才繼續
- 備註：`create_work_item` 直接吃 `assignee_usernames`（用名稱，不需 user id），本 step 僅作存在性驗證以提早抓出拼錯

主單負責人是 `rd-ticket-create` 的「主單要指派給哪位 PM」那一題點選的 PM 時，同樣驗證，但查的是主單專案：

- tool：`mcp__plugin_gitlab-mcp_gitlab__list_project_members`（`project_id=<parent_id>`, `query=<username>`, `include_inheritance=true`）
- **Abort on**：查不到 → PREVIEW 標 `✗ 主單負責人不存在`，不寫入

Q6 選了里程碑時，用名稱查出全域編號：

- tool：`mcp__plugin_gitlab-mcp_gitlab__list_milestones`（`project_id=<parent_id>`, `title=<里程碑名稱>`）
- **Capture**：回傳的 `id`（全域編號，例如「TTN項目」是 349），Step 2 當 `milestone_id` 用
- **Abort on**：回傳空清單 → PREVIEW 標 `✗ 里程碑不存在`，不寫入
- ⚠️ 回傳裡的 `iid`（例如 27）是專案內編號，網址上看到的也是它；填進 `milestone_id` 會指到別的里程碑

---

### Step 1.5：Verify parent 既有子項（僅補拆子任務模式）

本 skill 建立全新主單時，parent 必然沒有子項，本 step 跳過。
**補拆子任務模式一定要執行**，規則見 `subtask-patterns.md` 的「建子任務前一定要先查主單現有的子項」：

- tool：`mcp__plugin_gitlab-mcp_gitlab-workitem__list_child_items`（`project_id=<parent_id>`, `iid=<parent_iid>`）
- **Expected**：回傳 `children`，每筆有 `reference`、`title`、`state`（`OPEN` 或 `CLOSED`），**包含已關閉的**
- 比對後綴時，有空格的 ` - 開發` 與沒空格的 `-開發` 算同一個後綴（線上舊子任務多半沒有空格）
- 「同一張」看對應同一個 repo、同一種角色（開發或測試），而且是同一批或同一期，不是後綴字面一樣：` - APIs開發` 與 ` - 開發(APIs)` 算同一張，不同批號或不同期別則不算
- **Abort on**：已有 `OPEN` 的同一張時，不建，回報既有子項的 reference 與網址；
  已有 `CLOSED` 的同一張時，停下詢問使用者：「已完成，不建」「重新打開原本那張」（`update_issue` 帶 `state_event: reopen`）「另開一張新的」，不可自行補開；
  舊子任務一張涵蓋好幾個 repo 時（例如涵蓋前後端的 ` - 測試`），不能沿用成共用的一張，停下詢問使用者舊單怎麼處理：「舊單改成只對應其中一個 repo，其餘 repo 另建」「關閉舊單，每個 repo 都另建」
- 回傳帶 `children_truncated: true`（子項超過 100 張）→ PREVIEW 標 `⚠ 子項清單未列完`，由使用者裁決是否繼續
- 一句指示涵蓋多張 parent 時**每張各自查**，禁止以其中一張的結果推論其他張

---

### Step 2：Create parent issue（補拆子任務模式跳過）

- tool：`mcp__plugin_gitlab-mcp_gitlab__create_work_item`
- 參數：
  - `project_id`：`<parent_id>`
  - `type`：`issue`
  - `title`：`<TITLE>`
  - `description`：`<DESCRIPTION>`
  - `labels`：`["<label1>", "<label2>", ...]`（**label 名稱陣列**，非 id）
  - `assignee_usernames`：`["<主單負責人>"]`（PM 開單、RD 開單不需要 QA：`<author_username>`；RD 開單需要 QA：`rd-ticket-create` 的「主單要指派給哪位 PM」那一題點選的 PM）
  - `milestone_id`：`<Step 1 查到的里程碑全域編號>`（Q6 = 不掛時省略）
  - `start_date` / `due_date`：`<YYYY-MM-DD>`（Q5 ≠ NONE 時帶主單推算值，依 `reference/scheduling.md`：start ＝開發子任務 start、due ＝測試子任務 due 的下一個工作日再套順延規則（用 `next_workday` 查）；`RD_SELF` 直接帶 RD 給的開發起訖、不順延；Q5 = NONE 則兩者都省略）
- **Expected**：回傳 work item 含 `iid` / `id` / `web_url`
- **Capture**：`parent_iid`（後續設子任務階層用）、`parent_web_url`
- **Abort on**：tool 回 error（如 label 不存在 → 422）→ 印 error，停止後續 step

---

### Step 3：Create each subtask (with labels / assignee / dates inline)

> **跨 project parent-child**：子任務建在 subtask project，階層在 Step 3.1 用工單小工具 `attach_child_items` 掛上。`create_work_item` 的 `parent_iid` 只會在子任務所在的專案找主單，跨專案用不了。

- tool：`mcp__plugin_gitlab-mcp_gitlab__create_work_item`
- 參數：
  - `project_id`：`<subtask_id>`
  - `type`：`task`
  - `title`：`<主單 TITLE 去掉尾端表單項次> - <SUFFIX>`（分隔線前後各一個半形空格，見 `title-format.md`）
  - `description`：開發子單放固定開頭、測試子單放測試步驟（`PHASED` 放該期內容；`rd-ticket-create` 開的單照它寫好的內文），見 subtask-patterns.md 的「子任務的內文寫什麼」
  - `labels`：`["0. 分配開發"]` 或 `["0. 分配QA"]`（名稱陣列；子任務只掛派工標籤，不帶 `0.2 *` 與 `0.0 *`）
  - 不帶 `milestone_id`（里程碑只掛主單）
  - `assignee_usernames`：`["<assignee>"]`（Step 1 已驗證）
  - `start_date` / `due_date`：`<YYYY-MM-DD>`（Q5 ≠ NONE 時帶該子任務的推算值，依 `reference/scheduling.md`；**不是整批抄主單的日期**）
- **Expected**：回傳 work item 含 `iid` / `id` / `web_url`，無 error
- **Capture**：每個 subtask 的 `iid`（記為 `subtask_iids[]`）+ `web_url`
- **Abort on**：tool 回 error → **停下回報**（不靜默繼續）；已建立的其他子任務保留，回報其 `web_url`，使用者決定是否補。labels/assignees 已在 Step 1 與 Phase 3（label-conventions）前置驗證，create 時的 error 視為非預期硬失敗

---

### Step 3.1：Attach subtasks to parent (cross-project hierarchy)

建完全部子任務後，一次把它們掛到 parent 底下：

- tool：`mcp__plugin_gitlab-mcp_gitlab-workitem__attach_child_items`
- 參數：
  - `parent_project_id`：`"<parent_id>"`
  - `parent_iid`：`<parent_iid>`
  - `children`：`[{ "project_id": "<subtask_id>", "iid": <subtask_iid> }, ...]`
- **Expected**：`verified: true`，`attached` 列出這次掛上的子任務，`children_after` 含全部子任務
- **Abort on**：
  - `conflicts` 不為空（某張子任務已掛在別張主單下，或就是主單本身）→ 工具整批都沒寫，回報衝突的子任務，請使用者先在網頁移除原本的父子關係
  - `failed` 不為空 → 回報失敗的子任務與 GitLab 的錯誤訊息。錯誤訊息是「不允許新增此類型的父項」時，代表該子任務建成了 `issue`，回報裡一併提出轉型補救（見 Common pitfalls 第 5 點），使用者同意後才轉型並重跑本步
  - `missing_after_attach` 不為空 → 回報掛完讀回卻沒看到的子任務
- 同一組再跑一次是安全的：已經掛在這張主單下的會列在 `already_attached`，不重複寫入

為什麼用小工具：

- 主單與子任務都用「專案＋畫面上的單號」指定，工具自己查出 GitLab 內部的全域編號。把單號直接當全域編號用**不會報錯**，會靜默掛到另一張不相干的單（`#4306` 的兩張子任務就曾被掛到 `#1234`）。
- 工具從子任務那一側逐張設定父單，只送本站查得到的欄位；掛完立刻讀回主單的子項清單驗證。

---

### Step 3.2：Update parent labels / assignee / dates（僅補拆子任務模式）

PREVIEW 的「主單要改的」區塊有列、使用者回 `y` 才執行：

- 派工標籤：`mcp__plugin_gitlab-mcp_gitlab__update_issue`（`project_id=<parent_id>`, `issue_iid=<parent_iid>`, `labels=[...]`）
  - ⚠️ `labels` 會**整批取代**主單的標籤。一定要帶「主單現有標籤，扣掉 `0. 未分配`，加上派工標籤」的完整清單
- 負責人：同一支 `update_issue`，帶 `assignee_ids=[<數字 id>]`；標籤與負責人可以在同一次呼叫一起改
  - `assignee_ids` 收的是使用者的數字編號，不是帳號。先用 `mcp__plugin_gitlab-mcp_gitlab__list_project_members`（`project_id=<parent_id>`, `query=<帳號>`, `include_inheritance=true`）查回傳的 `id`；查不到就停下回報，不猜
  - **Expected**：回傳的 `assignees[].username` 是預覽寫的那個帳號
- 起訖日：`mcp__plugin_gitlab-mcp_gitlab-workitem__set_work_item_dates`（`project_id=<parent_id>`, `iid=<parent_iid>`, `start_date`, `due_date`）
  - **Expected**：`verified: true`，回傳改前（`before`）與改後（`after`）的日期
  - 只填要改的那個日期時，另一個維持原值

---

### Step 4：Cross-site sister link（僅 Q1=MULTI 且建第 ≥2 個 parent 後）

詳見 `reference/cross-site-link.md`：

- tool：`mcp__plugin_gitlab-mcp_gitlab__create_issue_link`
- 參數：`project_id=<parent_id>`, `issue_iid=<this_iid>`, `target_project_id=<sister_parent_id>`, `target_issue_iid=<sister_iid>`, `link_type=relates_to`
- 對所有已建立的姐妹 parent 兩兩互連（或一次性對最新 parent 與所有先前 parent 連）
- ⚠️ **本 instance 的 issue link 只支援 `relates_to`**：`blocks` / `is_blocked_by` 是付費版功能，
  這台會回 `link_type does not have a valid value`。
  要表達「A 擋住 B」只能建 `relates_to` 連結，再**在被擋的那張單描述最上方寫一段 ⛔ 註記**，
  說明被誰擋、擋到什麼時候。

---

### Step 5：Post-create verification（強制）

- tool：`mcp__plugin_gitlab-mcp_gitlab__get_issue`（`project_id=<parent_id>`, `issue_iid=<parent_iid>`）：驗 parent 的 title / labels / assignees / milestone
- tool：`mcp__plugin_gitlab-mcp_gitlab__get_issue`（`project_id=<subtask_id>`, `issue_iid=<subtask_iid>`）：逐一驗子任務的 title / labels / assignees
- tool：`mcp__plugin_gitlab-mcp_gitlab-workitem__list_child_items`（`project_id=<parent_id>`, `iid=<parent_iid>`）：驗父子關係
- tool：`mcp__plugin_gitlab-mcp_gitlab-workitem__get_work_item_dates`（主單與每張子任務各一次）：驗起訖日；`get_issue` 只讀得到 `due_date`

Skill MUST 對比實際 response 與 PREVIEW：
- `title` 一致
- `labels` 包含 PREVIEW 列出的所有 label，而且沒有多出 PREVIEW 沒列的
- `assignees` 對應到預期 username
- 主單的 `milestone` 是 PREVIEW 列的那一個
- `list_child_items` 的 `children` 包含這次建的全部子任務
- 每張單的 `start_date` / `due_date` 跟 PREVIEW 的 Schedule 一致

不符 → 印 diff，使用者選擇 update / 接受。

---

## 補拆子任務模式的呼叫順序

主單已存在，流程見 SKILL.md 的「補拆子任務模式」：

1. `get_issue`、`get_work_item_dates`：讀主單現況
2. Step 1.5 `list_child_items`：查既有子任務
3. Step 1：驗負責人
4. Step 3：建子任務
5. Step 3.1 `attach_child_items`：掛上主單
6. Step 3.2：改主單的派工標籤、負責人與起訖日（預覽有列才做）
7. Step 5：驗證

---

## Common pitfalls

1. **label 用名稱不用 id**：`create_work_item.labels` 與 `update_issue.labels` 收 label **名稱陣列**。label 必須**已存在於目標 project**（label 是 project-scope），否則 422。subtask project 的 label 與 parent project 獨立，先確認 `0. 分配開發` / `0. 分配QA` 在 subtask project 存在、`0.0不收費` 與派工標籤在 parent project 存在（驗證見 label-conventions.md）。
2. **assignee 用 username 不用 id**：`assignee_usernames` 收名稱陣列；仍須是該 project member，否則指派失敗。
3. **跨 project 階層一律用 `attach_child_items`**：它自己查全域編號、掛完讀回驗證。不要自己拼 `gid://gitlab/WorkItem/<單號>`。
4. **中文與特殊字元**：MCP tool 參數走 JSON，不需手動 URL encoding。
5. **type 對應**：parent 用 `issue`，子任務用 `task`（work item type）。子任務建成 `issue` 的話，Step 3.1 會被 GitLab 拒絕，錯誤訊息是「不允許新增此類型的父項」。這種失敗先照 Step 3.1 的中止規則停下回報。補救不必重建：使用者同意後，用 `mcp__plugin_gitlab-mcp_gitlab__convert_work_item_type`（`project_id=<subtask_id>`, `iid=<subtask_iid>`, `new_type=task`）把子任務轉成 `task`，再重跑 Step 3.1。轉型後用 `get_issue` 讀回，標籤與負責人都還在（實打 target/ticket_tasks#1543、#1544 確認）。
6. **GitLab 的 issue 搜尋對中文不分詞**：用中文關鍵字搜 issue 會回空清單，
   **不可以拿它當作「這張單不存在」的證據**。要確認是否已有同樣的單，改用
   `created_after` / `created_before` 篩時間區間，或分頁列舉逐頁看。
7. **REST 讀不到起始日**：`get_issue` / `update_issue` 只有 `due_date`。
   讀起始日用 `get_work_item_dates`，否則會把已經設好的單誤判成缺漏。
8. **REST `update_issue` 可以用**：建好之後補負責人、標籤、描述、標題都能用它（`issue_type: task` 也吃）；壞的只有上游 `update_work_item` 那條路。
9. **起訖日在建單當下就帶上**：`create_work_item` 的 `start_date` / `due_date` 建立時就吃得下，一次到位最省；建完才要改，用 `set_work_item_dates`。
