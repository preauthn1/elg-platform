# gitlab-mcp

> GFFv3 內部 GitLab（`example.invalid:1688`）的 MCP server plugin，提供其他 plugin（如 `op-toolkit`）共用的 GitLab API 通道。

## 是什麼

封裝 [`@zereight/mcp-gitlab`](https://example.invalid/package/@zereight/mcp-gitlab)，注入公司內部 GitLab base URL 與 PAT，跨平台啟動腳本見 [`bin/gitlab-mcp.js`](bin/gitlab-mcp.js)。另附一個自寫的小工具 server `gitlab-workitem`，補上游在公司 GitLab 做不到的工單操作，見下方「工單小工具」。

裝完後 Claude Code / Claude Desktop 會多出兩組 tool：`mcp__plugin_gitlab-mcp_gitlab__*`（上游套件）與 `mcp__plugin_gitlab-mcp_gitlab-workitem__*`（小工具）。

## 前置條件

| 依賴 | 說明 |
|---|---|
| Node.js + `npx` | macOS：`brew install node`；Windows：[example.invalid](https://example.invalid/) LTS。容器內 Claude Code 也需 host 有 node |
| 網路 | 首次 `npx -y @zereight/mcp-gitlab` 會抓 npm 套件，需通到 example.invalid |
| 公司網路 / VPN | 實際使用時需連 `http://example.invalid:1688`（內網），外網/家裡需 VPN |
| GitLab Personal Access Token | scope=api，從 http://example.invalid:1688/-/profile/personal_access_tokens 申請。設定方式見下方「GitLab PAT 設定 (SSOT)」；`gitlab_pat` user_config 現為選填（`required: false`），安裝時不再強制輸入 |

## 安裝

```
/plugin install gitlab-mcp
# 不再強制輸入 PAT；請照下方「GitLab PAT 設定 (SSOT)」設定
/reload-plugins
/mcp                            # 驗證 gitlab 與 gitlab-workitem 兩個 server 都是 connected
```

> 本 plugin 是 GitLab PAT 設定的操作 SSOT。Onboarding / tickets skill 只連回本檔，不重複平台細節。

## 單一 PAT 路徑（MCP）

依賴本 plugin 的 skill（`op-toolkit` 的 `pm-ticket-create` / `op-ticket-create` / `op-ticket-read`）**統一走 MCP**，透過 `mcp__plugin_gitlab-mcp_gitlab__*` tool 呼叫 GitLab，PAT 只存一份、由本 plugin 注入：

| 路徑 | 用於 | PAT 來自哪 |
|---|---|---|
| **MCP**（本 plugin 提供） | Claude Code / Claude Desktop 的全部 GitLab tool 呼叫（讀單、建單、跨會話） | 本地 secrets 檔 `~/.gff/secrets.env`（macOS / Linux 推薦；Windows 可 opt-in），或 `.mcp.json` 的 `${user_config.gitlab_pat}`（legacy fallback） |

> 歷史備註：2026-06 前曾有第二條「curl 直呼」路徑（Claude Code Bash 直打 GitLab API，PAT 經 `target-pat` 可執行檔自 OS secret store 取值）。因 MCP 在 Claude Code 也可用，該路徑與 `target-pat` 已退役，雙 PAT 過渡狀態結束。

## GitLab PAT 設定 (SSOT)

PAT 過期時若卡在 plugin `user_config` / Keychain，disable / reinstall 不保證會重新詢問或覆蓋舊值。建議用本地 secrets 檔管理 PAT：token 輪替只要改一行，plugin 更新後仍保留。

### macOS / Linux（推薦）

```bash
mkdir -p ~/.gff
printf '%s\n' 'GITLAB_PERSONAL_ACCESS_TOKEN=[REDACTED]' > ~/.gff/secrets.env
chmod 600 ~/.gff/secrets.env
```

重啟 Claude Code 或跑 `/reload-plugins`，再用 `/mcp` 確認 `gitlab` connected。

### Windows（建議：secrets 檔 opt-in）

Windows 可以和 macOS / Linux 一樣用檔案輪替 PAT，但必須明確 opt-in：設定 `GFF_SECRETS_INSECURE_WIN=1`。這代表你接受 PAT 以明文存在自己的使用者目錄，因 Node 無法可靠驗證 Windows ACL。

在 PowerShell 執行：

```powershell
New-Item -ItemType Directory -Force "$HOME\.gff" | Out-Null
Set-Content -Path "$HOME\.gff\secrets.env" -Value "GITLAB_PERSONAL_ACCESS_TOKEN=[REDACTED]" -NoNewline
[Environment]::SetEnvironmentVariable("GFF_SECRETS_INSECURE_WIN", "1", "User")
```

關閉並重新開啟 Claude Code / PowerShell，確認環境變數已存在：

```powershell
[Environment]::GetEnvironmentVariable("GFF_SECRETS_INSECURE_WIN", "User")
```

再回 Claude Code 跑 `/reload-plugins` 與 `/mcp`，確認 `gitlab` connected。

若你不接受明文 secrets 檔，可以保留 plugin `user_config` fallback；但 token 輪替不如改檔案直覺，因此不作為新人主路線。

### 自訂 secrets 檔位置

預設路徑是 `~/.gff/secrets.env`。如需自訂，設定 `GFF_SECRETS_FILE` 指向檔案完整路徑；Windows 若自訂路徑且仍使用 secrets 檔，也一樣要設 `GFF_SECRETS_INSECURE_WIN=1`。

### 行為（重要）

- **per-key 採用**：只有檔內**有 `GITLAB_PERSONAL_ACCESS_TOKEN` 鍵**才改用檔案；沒建檔、或檔內只放別的鍵 → 行為完全不變（仍走 legacy user_config）。
- **fail-closed**：PAT 鍵留空、檔權限過寬（POSIX 非 600）、格式錯誤、或 `$GFF_SECRETS_FILE` 指向不存在的檔 → MCP **大聲失敗、不啟動**，不會悄悄退回舊 token。依錯誤訊息修正即可。
- **權限閘（POSIX）**：檔須為一般檔案（非 symlink）、擁有者為你本人、mode 600。
- **Windows opt-in**：未設 `GFF_SECRETS_INSECURE_WIN=1` 時，檔內 PAT 會被拒絕使用；設了才啟用。
- secrets 檔為明文、永不可進 git；`.gitignore` 已擋實 `secrets.env`。

## 常見問題

| 症狀 | 原因 / 解法 |
|---|---|
| `/mcp` 顯示 `gitlab` 連不上 | 多半是 PAT 過期或 scope 不足。請依 [GitLab PAT 設定 (SSOT)](#gitlab-pat-設定-ssot) 更新 PAT；macOS / Linux 推薦改 `~/.gff/secrets.env` 一行即可輪替 |
| `failed to spawn npx` | host 缺 node。先裝 node 再 reload |
| 工具有出現但所有 tool 401 | PAT 在公司 GitLab 過期、或 base URL 走錯（檢查 `.mcp.json` 的 `GITLAB_API_URL`）|
| 連線 timeout | 外網沒掛 VPN |

## 依賴本 plugin 的 plugin

- `op-toolkit`：在 `plugin.json` 宣告 `dependencies: ["gitlab-mcp"]`，裝 tickets 會自動裝這個。其 `pm-ticket-create`、`op-ticket-create`、`op-ticket-read` 三支 skill 的**全部 GitLab 呼叫都經本 plugin 的 MCP tool**（2026-06 退役 curl 直呼／`target-pat` 後為唯一路徑）；故本 plugin `/mcp` 未連線時，三支 skill 會 preflight 失敗（op Mode B 退草稿模式）。建單用的 `create_work_item` 屬非預設的 `workitems` toolset，靠 `.mcp.json` 的 `GITLAB_TOOLSETS=all` 啟動即載入；掛父子關係、改起訖日、大幅改寫描述走下方「工單小工具」。

## 隱藏的 tool（`GITLAB_TOOL_POLICY_HIDDEN`）

`.mcp.json` 的 `GITLAB_TOOL_POLICY_HIDDEN` 主要擋破壞性寫入（delete / merge / push / fork 等），另含三個**在本 instance 壞掉**的例外：

| tool | 為何隱藏 |
|---|---|
| `get_work_item` | 上游 MCP 的 GraphQL 查詢寫死 `WorkItemWidgetStatus` / `WorkItemWidgetCustomFields` / `WorkItemWidgetWeight` / `WorkItemWidgetHealthStatus` fragment，本站 GitLab schema 沒有這些型別 → 解析階段整條被拒（`No such type ...`），一個欄位都拿不到 |
| `list_work_items` | 同上（`WorkItemWidgetStatus` / `Weight` / `HealthStatus`）|
| `update_work_item` | 同上，而且不論改什麼都會先送這個請求，見下方說明 |

work item 與 issue 在 REST 層是同一個資源，**讀單一律走 `get_issue` / `list_issues`**（`/-/work_items/N` 的 URL 直接把 N 當 `issue_iid` 用）。隱藏而非只寫文件的理由：規則寫在 skill 內文時，skill 沒載入就不在 context，看到 URL 裡的 `work_items` 仍會字面配對去呼 `get_work_item`；tool 不存在才是真的擋住。

寫入端的 `create_work_item` 可以用，建單當下就能帶起訖日、標籤、里程碑與指派。它的 `parent_iid` 只在子任務所在的專案裡找主單，跨專案掛父子關係要用下方「工單小工具」。

`update_work_item` 在本站一律失敗：不論改什麼，它都會先送一個要回 `WorkItemWidgetStatus`／`WorkItemWidgetWeight`／`WorkItemWidgetHealthStatus` 的更新請求，被拒在 `No such type ...`，真正做事的請求輪不到送出。現釘的 `@zereight/mcp-gitlab@2.1.18` 與上游最新版寫法相同，升版無效。它要做的事改由「工單小工具」處理。上游若修好，升版時可從 hidden 清單移除 `get_work_item`、`list_work_items` 與 `update_work_item`。

## 工單小工具（`gitlab-workitem` server）

[`bin/gitlab-workitem-mcp.js`](bin/gitlab-workitem-mcp.js) 是自寫的 MCP server，每個請求只帶公司 GitLab 查得到的欄位，不引入 npm 套件。金鑰規則與 `gitlab` server 相同（沿用 `resolveGitlabPat`，設定見「GitLab PAT 設定 (SSOT)」）。

| 工具 | 做什麼 | 讀或寫 |
|---|---|---|
| `attach_child_items` | 把既有的子任務掛到主單底下，可以跨專案（例如主單在 `target/tickets`、子任務在 `target/ticket_tasks`） | 寫 GitLab |
| `list_child_items` | 列出一張工單現在的子任務與開關狀態，包含已關閉的；同時回傳它的主單（`parent`），拿到子任務時用它找主單 | 唯讀 |
| `get_work_item_dates` | 讀起始日與結束日；`get_issue` 只讀得到結束日 | 唯讀 |
| `set_work_item_dates` | 改起始日或結束日，沒填的那個維持原值 | 寫 GitLab |
| `save_issue_description` | 把描述存成本機檔案，只回傳路徑、字數與行數 | 讀 GitLab、寫本機檔 |
| `update_issue_description_from_file` | 把改好的描述檔送回 GitLab | 寫 GitLab |
| `check_workdays` | 查台灣的日期是不是工作日，放假日附假日名稱；不填日期時查台灣時間的明天 | 唯讀，不需要 PAT |
| `next_workday` | 找某個日期之後的第一個台灣工作日，列出中間跳過的放假日 | 唯讀，不需要 PAT |

防呆設計：

- **工單一律用「專案＋畫面上的單號」指定**，GitLab 內部的全域編號由工具自己查。把單號直接當成全域編號用不會報錯，而是指到另一張不相干的單。
- **掛父子關係先檢查再動手**：已經掛在同一張主單下的略過；只要有一張掛在別張單下，整批都不寫。掛完讀回主單的子項清單，對不上就回報失敗。
- **改起訖日寫完就讀回**，回傳改前與改後的日期；改完會變成起始日晚於結束日時不寫入。
- **描述全文不進對話**：描述檔放在系統暫存目錄（Node 的 `os.tmpdir()`）底下的 `gff-gitlab-workitem/`，資料夾權限 700；檔名由專案與單號決定（例如 `elg__tickets__4398.md`），呼叫端不能指定其他路徑。檔案裡有還沒送回的修改時，重新存檔不會覆蓋；存檔後有人在網頁上改過描述，送回時會停下，以免蓋掉對方的修改。暫存目錄可能在重開機後被清掉，描述檔只適合當次改單使用。
- **換行與結尾空白照 GitLab 的存法處理**：GitLab 存描述時會把 CRLF 換成 LF、去掉結尾的空白與換行，工具送出與比對前先做同樣處理，編輯器在檔尾補的換行不會被當成修改。送回成功後，本機檔會改成 GitLab 實際存下的內容；寫入結果對不上時保留本機檔並回報失敗。

- **工作日照政府的辦公日曆判斷**：資料是政府資料開放平臺的「中華民國政府行政機關辦公日曆表」，週末、國定假日、補假、補班都已標好，每年一份放在 [`calendar/`](calendar/)，由 [`bin/tw-workday.js`](bin/tw-workday.js) 讀取。日曆沒收錄的年份一律回錯誤，不自己推算。有網路時會比對政府是否出了修正版或新年度，有就在回傳的 `warnings` 提醒；連不上線不影響判斷。

每年政府公布新年度日曆後（通常在前一年年中），由維護者開工作分支更新，走 MR 合進 main：

```bash
node plugins/gitlab-mcp/bin/tw-workday.js --update        # 下載今年與明年的最新版
node plugins/gitlab-mcp/bin/tw-workday.js --update 2028   # 只更新指定年份
```

行為由單元測試把關：`node --test plugins/gitlab-mcp/bin/gitlab-workitem-mcp.test.mjs plugins/gitlab-mcp/bin/tw-workday.test.mjs`（CI 沒有 node，`validate.sh` 只驗 `.mcp.json` 的接線）。
