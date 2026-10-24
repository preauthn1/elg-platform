# GitLab API Cheatsheet（雙 mode 共用）

> 兩個 mode 都透過 **GitLab MCP** tool（`mcp__plugin_gitlab-mcp_gitlab__*`）呼叫。
> 本檔下列「REST endpoint」僅標示 MCP tool 底層對應的 GitLab API 與欄位，供理解回傳結構；實際呼叫一律用 tool。

---

## 0. 認證

PAT 由 `gitlab-mcp` plugin 注入；設定 SSOT 見其 README，skill **不自取 token**。
進 skill 前確認 `/mcp` 有 `gitlab` server；設定見 gitlab-mcp README。

---

## 1. Project IDs

project_id：**492**（`target/op_tickets`），讀寫皆同。
MCP tool 的 `project_id` 收數字 id 或 URL-encoded path，本 skill 用數字 id。

---

## 2. Mode A 必用 tool

> ⚠️ 讀單一律走本節的 REST `get_issue`。**`get_work_item` / `list_work_items` 在本 instance 不可用**：
> 上游 MCP 的 GraphQL 查詢寫死 `WorkItemWidgetStatus` / `WorkItemWidgetWeight` /
> `WorkItemWidgetHealthStatus` 等本 instance schema 不存在的型別，整條查詢在解析階段被拒
> （`No such type ...`）。使用者貼 `/-/work_items/N` URL 時，取 N 當 `issue_iid` 走 REST 即可。

### 2.1 讀 issue — `mcp__plugin_gitlab-mcp_gitlab__get_issue`

`project_id=<PID>`, `issue_iid=<IID>`

回傳重點欄位：
| 欄位 | 用途 |
|---|---|
| `iid` | 工單編號 |
| `title` | 標題（解析 station 前綴） |
| `description` | body（解析 symptom / expected / tg_group / 截圖 URL） |
| `labels` | array of label names |
| `state` | `opened / closed` |
| `assignees[].username` | 指派的開發 |
| `author.username` | 客服 / 開單者 |
| `created_at` / `updated_at` | UTC timestamp |
| `web_url` | 工單頁面 URL |

### 2.2 讀 discussions（comment 串）— `mcp__plugin_gitlab-mcp_gitlab__list_issue_discussions`

`project_id=<PID>`, `issue_iid=<IID>`

> 看是否有客服 / PM 在 comment 補充後續資訊。
> 每個 discussion 物件含 `notes[]`，每個 note 有 `author.username` / `body` / `created_at`。

### 2.3 列出 project labels（驗證 label 名）— `mcp__plugin_gitlab-mcp_gitlab__list_labels`

`project_id=<PID>`, `per_page=100`

### 2.4 列出 project members（驗證 assignee）— `mcp__plugin_gitlab-mcp_gitlab__list_project_members`

`project_id=<PID>`, `per_page=50`

回傳 `[{id, username, name, state, access_level}, ...]`

---

## 3. Mode B 必用 tool

### 3.1 上傳截圖 — `mcp__plugin_gitlab-mcp_gitlab__upload_markdown`

`project_id=<PID>`, `file_path=<本地圖片路徑>`

回傳含 `markdown` 字串（如 `![screenshot](/uploads/<hash>/screenshot.png)`）。
把該 `markdown` 字串插入工單 description 的附件區。

> 強制 upload-then-create：先對每張截圖呼叫本 tool 取得 markdown ref，全部成功後才建單（見 SKILL.md B6.1）。

### 3.2 建 issue — `mcp__plugin_gitlab-mcp_gitlab__create_issue`

`project_id=<PID>`, 並帶：

| 欄位 | 型別 | 備註 |
|---|---|---|
| `title` | string | 必填，上限 100 字 |
| `description` | string | markdown，截圖 markdown ref 內嵌於此 |
| `labels` | array<string> | label **名稱**陣列 |
| `assignee_ids` | array<int> | 用 user id（create_issue 收 id；username 先用 list_project_members 換 id） |

回傳含 `iid` / `web_url`。

### 3.3 建立 comment（選用，若未來擴充）— `mcp__plugin_gitlab-mcp_gitlab__create_issue_note`

`project_id=<PID>`, `issue_iid=<IID>`, `body=<comment markdown>`

---

## 4. 錯誤碼與處理

| HTTP | 狀況 | 行為 |
|---|---|---|
| 401 | token 過期或無效 | 提示依 gitlab-mcp README 更新 PAT；不擅自重試 |
| 403 | token 無 project access | 確認 token 範圍與 project member 權限 |
| 404 | project / issue 不存在 | 確認 iid 與 project_id（492） |
| 422 | 欄位驗證失敗（如 labels 不存在 / assignee_ids 含非 member） | 顯示 GitLab 回的 error message，請 user 修正 |
| 500 | GitLab 內部錯誤 | 不重試，提示 user 稍後或聯絡管理員 |

---

## 5. 既有 issue 範本對照

實際 op_tickets 已有 ~2000 張工單。Mode A 解析時可參考的高頻 pattern：

- title 前綴：`[<站台>] ...` 約 80%+
- description 開頭：通常是 `![圖片](/uploads/...)` 或直接是客服貼的對話
- description 結尾：常見 `<br>` 分隔（telegram 自動轉的）
- 玩家 ID 出現位置：description 第一/二段，常以 `玩家 / 帳號 / 會員 ID: xxx` 形式
- 訂單號出現位置：通常單獨一行 `單號 385731` 或 `#385731`

> 既有工單**沒有** TG 群欄位（這是本 skill 新加入的強制欄位）。
> Mode A 解析時若 `tg_group` 取不到，在摘要標 `⚠️ 未標註`。
