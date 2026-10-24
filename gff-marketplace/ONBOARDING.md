# Onboarding：安裝 GFF-Marketplace plugin marketplace

> 對象：第一次裝 GFF-Marketplace 的同事（PM / 維運 / 客服 / 開發都適用）。
> 預估時間：15 分鐘（macOS）/ 20 分鐘（Windows，含裝 Node.js / Google Cloud CLI）。

## 8 步驟總覽

| 步驟 | 動作 | macOS | Windows |
|---|---|---|---|
| 0 | 裝 Node.js LTS | `brew install node` | example.invalid 下載 .msi |
| 1 | 申 GitLab PAT | `/-/profile/personal_access_tokens` scope=api | 同 |
| 2 | 設定 GCP ADC | `gcloud auth application-default login` | 同 |
| 3 | 設 git credential helper | `osxkeychain` | `manager` |
| 4 | add marketplace | `/plugin marketplace add http://...` | 同 |
| 5 | 安裝全員 foundation MCP + 按角色選裝 plugin | `/plugin install <plugin>`（逐支一行） | 同 |
| 6 | 設定 GitLab MCP PAT | 依 `gitlab-mcp` README 建 secrets 檔 | 依 `gitlab-mcp` README 選一種 PAT 設定方式 |
| 7 | reload + verify | `/reload-plugins` → `/mcp` | 同 |

詳細以下展開。

---

## Step 0：安裝 Node.js LTS（前置）

`@zereight/mcp-gitlab` 與 `@google-cloud/observability-mcp` 都透過 `npx` 執行，必須有 Node.js / npm / npx。

### macOS
```bash
brew install node
node -v   # 期望 v20+ 或任一 LTS 版本
npm -v
npx -v
```

### Windows
1. 從 https://example.invalid/ 下載 LTS Windows Installer (.msi)
2. 安裝時勾選「Add to PATH」
3. 安裝完後**開新的 PowerShell 視窗**（舊視窗 PATH 未更新）
4. 驗證：
   ```powershell
   node -v
   npm -v
   npx -v
   ```

## Step 1：申 GitLab Personal Access Token

1. 開 http://example.invalid:1688/-/profile/personal_access_tokens
2. 點 「Add new token」
3. Name：`claude-code`（或任意）
4. Expiration：建議 90 天
5. Scopes：勾 ✅ `api`
6. 點 Create → 複製出現的 token（離開頁面就看不到）

> Token 格式為 `glpat-[REDACTED]`。先把 token 暫存在安全位置；Step 6 會引導你依作業系統設定 GitLab MCP PAT。

## Step 2：設定 GCP Application Default Credentials

`gcp-mcp` 使用 Google Cloud Observability MCP 查 Cloud Logging，不保存 Google credential；認證走使用者本機 `gcloud` 與 Application Default Credentials。

### macOS

若尚未安裝 Google Cloud CLI，先依官方文件安裝：https://example.invalid/sdk/docs/install

```bash
gcloud auth login
gcloud auth application-default login
gcloud auth application-default print-access-token
```

Expected: `print-access-token` 印出 access token。

若組織要求 quota project，請依公司指派 project 設定：

```bash
gcloud auth application-default set-quota-project <QUOTA_PROJECT_ID>
```

### Windows

若尚未安裝 Google Cloud CLI，先依官方文件安裝：https://example.invalid/sdk/docs/install

```powershell
gcloud auth login
gcloud auth application-default login
gcloud auth application-default print-access-token
```

Expected: `print-access-token` 印出 access token。

若組織要求 quota project，請依公司指派 project 設定：

```powershell
gcloud auth application-default set-quota-project <QUOTA_PROJECT_ID>
```

## Step 3：設 git credential helper

Claude Code 的 `/plugin marketplace add` 拉內部 GitLab repo 需要 git 認證。一次性設定：

### macOS
```bash
git config --global credential.helper osxkeychain
git ls-remote http://example.invalid:1688/target/gffv3/gff-marketplace.git
# username：你的 GitLab 帳號
# password：貼 Step 1 的 PAT
```

### Windows
```powershell
git config --global credential.helper manager
git credential-manager --version   # 驗證 GCM 已裝；若無，重新裝 Git for Windows
git ls-remote http://example.invalid:1688/target/gffv3/gff-marketplace.git
# username + password 同上
```

Expected: 印出 commit hash 列表。之後 marketplace 拉 repo 都不會再問密碼。

## Step 4：在 Claude Code 內 add marketplace

啟動 `claude`（CLI 或桌面版），在 REPL 內：

```
/plugin marketplace add http://example.invalid:1688/target/gffv3/gff-marketplace.git
/plugin marketplace list
```

Expected: 看到 `gff-marketplace` marketplace。

## Step 5：安裝全員 foundation MCP + 按角色選裝 plugin

依賴會自動遞迴安裝（需 Claude Code v2.1.143+）：`op-toolkit` 與 `dev-toolkit` 都依賴 `gitlab-mcp`（裝任一即自動帶）；`apis-toolkit` 依賴 `dev-toolkit` / `gcp-mcp`，故裝 `apis-toolkit` 會遞迴帶 `dev-toolkit` + `gcp-mcp` + （經 `dev-toolkit` 再帶）`gitlab-mcp`。日常角色安裝（`op-toolkit` / `dev-toolkit`）不會帶入 `gcp-mcp`，所以全員仍需先手動安裝 foundation MCP。

**執行方式**：先跑「全員 foundation MCP」兩行，再依你的角色只選一組角色指令。⚠️ `/plugin install` 一次只吃**一個** plugin，多寫只會裝第一個，故逐支一行。

```
# 全員 foundation MCP：
/plugin install gitlab-mcp
/plugin install gcp-mcp
/plugin install memory-mcp            # 需在 LAN/VPN 內；位址與 key 由環境變數 MEMORY_MCP_URL、MEMORY_API_KEY 提供（見 plugin README）

# 維運 / 客服：
/plugin install op-toolkit

# 開發者：
/plugin install op-toolkit
/plugin install dev-toolkit

# CodeGraph（開發者選裝；裝完需各專案自行 codegraph init 建索引，見 codegraph-mcp README）：
/plugin install codegraph-mcp

# QA（選裝；需系統 Google Chrome + 本機 WEBBROWSER_* env，見 qa-toolkit README）：
/plugin install qa-toolkit

# 寫 wiki（RD / PM / QA 選裝；需 OUTLINE_MCP_URL、OUTLINE_API_KEY 兩個環境變數，見 wiki-toolkit README）：
/plugin install wiki-toolkit
```

> `apis-toolkit` 為 APIs 專案專屬，於 APIs repo 內使用（不在全域逐角色安裝集）。
> `qa-toolkit` 為 QA 選裝工具（自帶 Playwright MCP，不影響其他 plugin），非標準角色安裝集。
> `codegraph-mcp` 為開發者選裝工具（純本機，不需認證）；裝完 MCP 就會 connected，但要在各專案跑一次 `codegraph init` 才查得到東西。
> `wiki-toolkit` 為寫 wiki 的選裝工具（自帶 Outline MCP，依賴 memory-mcp）；API key 每人自己在 wiki「設定 › API」建立。

## Step 6：設定 GitLab PAT

GitLab MCP PAT 的操作 SSOT 在 [`plugins/gitlab-mcp/README.md`](plugins/gitlab-mcp/README.md#gitlab-pat-設定-ssot)。請開該段，照你的作業系統完成設定後再回來跑 Step 7。

平台選擇：

- macOS / Linux：照 README 建立 `~/.gff/secrets.env`。
- Windows：建議照 README 的 PowerShell 步驟建立 `$HOME\.gff\secrets.env` 並設定 `GFF_SECRETS_INSECURE_WIN=1`；若不接受明文檔案，先不要建立 secrets 檔，改看 README 的 fallback 說明。
- 不要把 PAT 寫進本 repo，也不要貼到工單或文件。

## Step 7：reload + verify

```
/reload-plugins
/mcp
```

Expected: `gitlab` 與 `gitlab-workitem` 兩個 server 來自 `gitlab-mcp`（被 op-toolkit / dev-toolkit 依賴帶入）；`observability` server 來自 `gcp-mcp`，狀態皆為 `connected`。

> ⚠️ `observability` **只在裝了 `gcp-mcp` 時出現**。維運 / 客服若只照角色裝 `op-toolkit`（只依賴 gitlab-mcp）而略過 Step 5 的「全員 foundation」，`/mcp` 看不到 `observability` 是正常的，回 Step 5 補裝 `gcp-mcp` 即可。

**實際功能驗證**（裝了 op-toolkit 才有）：

新對話：「列我在 target/tickets 最近 5 張單」

Expected: AI 透過 GitLab MCP 回傳 issue 列表。

**GCP Observability 驗證**：

新對話：「查 TPower 最近 5 分鐘的 GCP log」

Expected: AI 使用 `observability` MCP 查詢 Cloud Logging。（依白標自動組 project/filter 的 `gcp-log-troubleshoot` skill 屬 `apis-toolkit`，於 APIs repo 內才有；一般 foundation 安裝只驗 `observability` MCP 連線即可。）

---

## 後續

### 更新 skill 內容

兩步都在 Claude Code 對話框裡完成，Mac、Windows 做法相同。

**1. 開啟自動更新（每個人設一次）**：輸入 `/plugin` → 切到 **Marketplaces** 分頁 → 選 `gff-marketplace` → **Enable auto-update**。gff-marketplace 屬於第三方市集，Claude Code 預設不替它自動更新，所以要自己開。開了之後，每次啟動 Claude Code，它會在背景把市集清單和已裝的 plugin 更新到新版，有更新時照提示輸入 `/reload-plugins` 即可。

**2. 要馬上拿到新版**：

```
/plugin marketplace update gff-marketplace
/reload-plugins
```

第一行會把已裝的 plugin 一併換到新版，輸出顯示換了幾支（例如 `8 plugins bumped`）；第二行讓當下的 session 套用。不要用 `/plugin update <plugin>@gff-marketplace`，它會打開 plugin 選單、不會直接更新（Claude Code 2.1.280 實測）。

第 2 步顯示 `0 plugins bumped`、但確定有新版時，多半是 Claude Code 版本較舊：先用 `claude --version` 確認版本，升級後再跑一次。完整說明見 README「更新（marketplace 異動後）」。

### 解除安裝

```
/plugin uninstall op-toolkit
/plugin marketplace remove gff-marketplace
```

### 常見問題

**Q：我 install 完 gitlab-mcp 沒被 prompt PAT？**
A：正常。現在安裝 plugin 不再靠 prompt 收 PAT。請依 Step 6 連到 `gitlab-mcp` README 設定 PAT。

**Q：`/mcp` 看不到 gitlab？**
A：先確認 `node -v` 與 `npx -v` 都有版本；再 `/reload-plugins`；仍不行用 `claude --debug` 看 server log，常見原因是 secrets 檔權限非 600（wrapper fail-closed 不啟動）或 PAT 過期。

**Q：`/mcp` 看不到 observability？**
A：先確認已安裝 `gcp-mcp`；再確認 `node -v` / `npx -v` 都有版本，並跑 `/reload-plugins`。

**Q：GitLab 查詢回 403？**
A：PAT scope 不是 `api` 或已過期。換新 PAT 後，依 Step 6 指向的 `gitlab-mcp` README 更新你的 PAT 來源；不要期待 reinstall 一定會重新詢問 PAT。

**Q：GCP log 查詢回 ADC / credential 錯誤？**
A：先在同一台機器跑 `gcloud auth application-default print-access-token`。若沒有 token，重跑 Step 2 的 `gcloud auth application-default login`。

**Q：之前裝過、`/plugin marketplace remove` + `/plugin uninstall` 全清掉後，重新 `/plugin marketplace add` 同來源，`/plugin install gitlab-mcp` 回 `No plugins match`？**
A：Claude Code 把舊 plugin 留在 `~/.claude/plugins/cache/gff-marketplace/<plugin>/<version>/.orphaned_at` 標記，重新 add marketplace 不會自動清。修法擇一：

```bash
# 1. 只清 orphan 標記（保留 cache 內容、最快）
find ~/.claude/plugins/cache/gff-marketplace -name ".orphaned_at" -delete

# 2. 整個 cache 砍掉重新拉（最乾淨）
rm -rf ~/.claude/plugins/cache/gff-marketplace
```

清完回 REPL：

```
/plugin marketplace update gff-marketplace
/plugin install gitlab-mcp@gff-marketplace
```
