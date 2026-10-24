# Claude Desktop GitLab MCP 設定指南（Mode B 前置）

> ✅ **首選路徑**：直接裝 `gitlab-mcp` plugin（`op-toolkit` 已宣告為依賴），它就是現成的 GitLab MCP server。PAT 設定 SSOT 見 `gitlab-mcp` plugin 的 README。
> 本檔以下的「自建 / 第三方 server」（Option A/B/C）為**歷史備援**，僅在不便用 plugin 時參考。

> 用途：讓維運 / 客服在 Claude Desktop 透過 MCP 直接呼叫 GitLab API 建單 + 上傳截圖，不切到 GitLab Web UI。
>
> 本檔列必要的 **MCP tool 介面規格**（黑盒）與 **安裝路徑選項**。MCP server 本體屬於獨立 deliverable，由運維選擇方案落地。
>
> ⚠️ **外部規範變動快**：本檔對 Claude Desktop / GitLab API 的細節僅描述本 skill 依賴的最小子集，不複製官方文件。實作前請對照官方文件確認當前語法：
> - Claude 官方 MCP 文件：https://example.invalid/
> - Claude Code MCP 設定：https://example.invalid/docs/en/mcp（也可用 `claude mcp add` CLI）
> - GitLab Projects API：https://example.invalid/ee/api/projects.html（含 uploads 章節）

---

## 1. Skill 期望的 MCP Tool 介面（黑盒規格）

不論是用現成 GitLab MCP 套件、或運維自建薄薄 server，**必須**提供下列 tool。Skill 會用這些 tool 名稱 / 參數呼叫；命名可不同，但功能等價。

### 1.1 `gitlab_create_issue`（必要）

建 issue。

**輸入**：
| 欄位 | 型別 | 必/選 | 說明 |
|---|---|---|---|
| `project_id` | int / string | 必 | `492`（`target/op_tickets`）|
| `title` | string | 必 | 工單標題 |
| `description` | string | 必 | markdown body（含內嵌截圖 markdown） |
| `labels` | string / array | 選 | label name 列表（逗號分隔字串或陣列） |
| `assignee_ids` | array<int> | 選 | user id 陣列（不是 username） |

**輸出**：
```json
{ "iid": 2009, "web_url": "http://example.invalid:1688/target/op_tickets/-/issues/2009" }
```

### 1.2 `gitlab_upload_file`（必要）

上傳檔案到 project uploads，回傳可內嵌的 markdown 字串。

**輸入**：
| 欄位 | 型別 | 必/選 | 說明 |
|---|---|---|---|
| `project_id` | int / string | 必 | `492` |
| `file_path` | string | 必 | 本地檔案路徑 |
| `alt` | string | 選 | 顯示 alt 文字 |

**輸出**：至少要回傳含 `markdown` 字段的物件，例：
```json
{
  "url": "/uploads/<hash>/screenshot.png",
  "markdown": "![screenshot](/uploads/<hash>/screenshot.png)"
}
```

> 不同 MCP 實作回傳格式可能略異；skill 會抓 `markdown` 字串直接插入 description。若 server 只回 `url`，由 skill 自行組裝 `![alt](url)`。

### 1.3 `gitlab_list_labels`（建議）

列出 project labels，用於驗證 label name 是否存在（避免 422 錯誤）。

**輸入**：`project_id`
**輸出**：array of `{name, color}`

### 1.4 `gitlab_list_members`（建議）

列出 project members，用於把 assignee username → user id。

**輸入**：`project_id`
**輸出**：array of `{id, username, name, state}`

### 1.5 `gitlab_get_issue`（選用，給 Mode A 在 Desktop 也能用）

Mode A 讀單在 Claude Code / Claude Desktop 都走此 tool（裝 gitlab-mcp 後即 `mcp__plugin_gitlab-mcp_gitlab__get_issue`）。
**輸入**：`project_id`, `iid` → 回 issue 物件。

---

## 2. 安裝路徑選項

依運維能力選擇一條：

### Option A — 用現成第三方 GitLab MCP（最快上線）

社群有數個 GitLab MCP server 套件。挑選原則：

- ⚠️ **GitHub star < 500 必須警示**，< 50 不建議導入生產
- 必須支援 self-hosted GitLab（本專案是 `example.invalid:1688`，非 example.invalid）
- 必須支援 `uploads/` endpoint（不是所有 GitLab MCP 都實作上傳）

**驗證步驟**（先在測試 project 跑通再進生產）：
1. 用 `claude mcp add` 或 Claude Desktop config 註冊 server
2. 帶上 PAT 環境變數（或 OAuth）
3. 在測試 project 跑 **upload-then-create** 順序（與 SKILL.md B6.1 一致）：
   a. 先呼叫 `upload` tool 上傳一張 PNG → 取回 `markdown` 字串
   b. 把 `![](/uploads/...)` 內嵌進 description
   c. 呼叫 `create_issue` 建單（含完整 description / labels / assignee）→ 確認 web_url 回傳
   d. 確認瀏覽器開啟工單後圖片可正確渲染
4. 驗證 401/403/404/422 錯誤路徑都有合理 error message
5. **故意失敗測試**：把 PAT 改錯後跑 step 3a — 預期 abort，**不會**建出任何 issue（驗證 atomic 保證）

### Option B — 自建薄薄 MCP server（最可控）

本專案內自建一個 Python / Node MCP server，封裝第 1 節 5 個 tool。

**最小規格**：
- 語言：Python（用 `mcp` 官方 SDK）或 Node（`@modelcontextprotocol/sdk`）
- 認證：從環境變數讀 `GITLAB_TOKEN`（不要 hardcode；不要從 keychain 跨 process 讀）
- 傳輸：stdio（Claude Desktop 預設）
- 對外 endpoint：5 個 tool（1.1 ~ 1.5）
- 錯誤處理：把 GitLab HTTP error message 原樣回傳，不要吞 error
- 上傳處理：用 `multipart/form-data`，file field 名為 `file`

**Token 取得**：PAT 由維運自 GitLab 申請（scope=api）。先依 gitlab-mcp README 完成 PAT 設定；若自建 server 只吃 env，可從同一 secrets 檔載入：
```bash
# 自 secrets.env 載入後 export 給自建 server（重啟需重做）
set -a; . ~/.gff/secrets.env; set +a
export GITLAB_TOKEN=[REDACTED]"
open -a "Claude"
```

**Repo 建議位置**：另起 repo（如 `tools/gitlab-mcp`），不要塞到 APIs 主專案。完成後在本檔附上實際 repo URL。

### Option C — Claude Code CLI（過渡方案）

若維運改用 Claude Code CLI（不是 Desktop），裝 `gitlab-mcp` plugin 後兩個 mode 的 `mcp__plugin_gitlab-mcp_gitlab__*` tool 直接可用。
維運要學 CLI；目前主訴求是 Desktop，因此 C 僅作為臨時備援。

---

## 3. Claude Desktop / Claude Code 設定

### Claude Code（已驗證，本檔附帶範例）

依官方文件用 CLI 加 server：
```bash
# stdio 自建 server（Option B）；token 自 secrets.env 載入
set -a; . ~/.gff/secrets.env; set +a
claude mcp add --transport stdio --env GITLAB_TOKEN=[REDACTED]" \
  gitlab-op-tickets -- python /path/to/gitlab-mcp/server.py

# 確認
claude mcp list
/mcp
```

> 完整 CLI 規範見 https://example.invalid/docs/en/mcp（含 `--scope`, `--header`, OAuth, JSON 設定等）。

### Claude Desktop

設定方式以 **Claude Desktop 當前官方文件為準**（避免本檔過時引導）。落地步驟：

1. 開啟 Claude Desktop → 設定 / 設定檔（具體位置依當前 Desktop 版本而異）
2. 加入 MCP server 區塊，指向第 2 節選定的 server 命令 / URL
3. 設好環境變數傳遞（含 `GITLAB_TOKEN`）
4. 重啟 Claude Desktop
5. 在對話開始時用 `/mcp` 或內建 MCP panel 確認 server 已連線、tool 列得出來

> 維運首次落地時請對照當前官方文件操作，並把可運作的 config 範本回貼到本檔（避免下個維運又從零摸索）。

---

## 4. 安全 & 權限

- **PAT 範圍**：本 skill 只需 `api` scope。**不**需要 `read_repository` 之外的擴張權限。
- **PAT 過期**：PAT 由個人管理，輪替方式見 gitlab-mcp README；若導入新 bot 帳號需運維另開單。
- **token 儲存**：
  - ✅ gitlab-mcp README 指定的 PAT 來源
  - ✅ 環境變數（自 secrets.env 載入後 export 給自建 server）
  - ❌ 不要塞到 Claude Desktop config JSON 明文
  - ❌ 不要 commit 到 git
- **MCP server 信任**：本 skill 只認 self-hosted GitLab（`example.invalid:1688`）。若 MCP server 還能呼叫其他主機，請限制白名單或在 server 內 hard-code domain。

---

## 5. Skill 端的 MCP tool 探測（B1 環境檢測）

skill 進 Mode B 時做的事：

1. 看當前可用 tool 是否有名稱含 `gitlab` / `op_ticket` / `issue` 的工具
2. 對找到的工具呼叫 `mcp__plugin_gitlab-mcp_gitlab__list_labels(project_id=492)` 作為 smoke test
3. 通過 → 進入 MCP 模式
4. 找不到 tool 或 smoke test 失敗 → 進入草稿 fallback，並提示「請依 reference/mcp-setup.md 配置 MCP」

> Skill **絕對不會臆造**不存在的 MCP tool 名；只認真實存在於當前 session 的 tool。

---

## 6. 落地檢核表

導入新 MCP server 時逐項打勾：

- [ ] PAT 可從環境變數讀到（測試：`echo $GITLAB_TOKEN | head -c 4` 看到 `glpa`）
- [ ] `gitlab_create_issue` 能在測試 project 建 issue 並回 web_url
- [ ] `gitlab_upload_file` 能上傳 PNG 並回 markdown
- [ ] 上傳的 markdown 內嵌到 issue description 後能正確顯示圖片
- [ ] `gitlab_list_labels` 回得到 op_tickets 既有 label
- [ ] `gitlab_list_members` 回得到 op_tickets member 列表（含 id）
- [ ] 401 / 403 / 404 / 422 都有可讀 error message
- [ ] Claude Desktop 重啟後 server 自動連線
- [ ] 至少跑通 1 張完整的 Mode B 建單流程（含 ≥ 1 張截圖上傳 + ≥ 2 個 labels + assignee）
- [ ] 把可運作的 config 範本回貼到本檔

---

## 7. 風險與已知限制

- ⚠️ **Claude Desktop 不同版本對 MCP 設定 schema 可能不同** — 維運落地時對照當前官方文件。
- ⚠️ **MCP server stdio 模式重啟成本**：若 server crash，要重啟 Claude Desktop；HTTP 模式較有彈性但本地架設較重。
- ⚠️ **`target/op_tickets` 既有工單 ~2000 張**：第一次跑 Mode A 大量回追前先設好 `MAX_MCP_OUTPUT_TOKENS`（默認 25000 可能不夠）。
