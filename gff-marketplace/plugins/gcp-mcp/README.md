# gcp-mcp

> GFFv3 團隊共用的 Google Cloud Observability plugin：Cloud Logging 查詢通道（純 MCP server）。被需要 GCP log 的 plugin（如 apis-toolkit）依賴。

## 是什麼

**`observability` MCP server**（純 MCP plugin，不含業務 skill）——封裝 [`@google-cloud/observability-mcp`](https://example.invalid/googleapis/gcloud-mcp)，安裝後讓 Claude Code 多出 Cloud Logging 查詢通道。

本 plugin 只導入 Observability MCP，不導入泛用 `gcloud-mcp`。Google Observability MCP 套件也會暴露 Monitoring / Trace / Error Reporting 工具，但本 marketplace 的 team convention 只把它當 Cloud Logging 查詢通道。

> 多白標 GCP log 排查助手（白標 alias 正規化、白標 → GCP project 對照、filter compose、本地 cache 衍生查詢）為 **`apis-toolkit` 的 `gcp-log-troubleshoot` skill**，透過本 plugin 的 `observability` MCP 運作。

## 前置條件

| 依賴 | 說明 |
|---|---|
| Node.js + `npx` | macOS：`brew install node`；Windows：[example.invalid](https://example.invalid/) LTS |
| Google Cloud CLI | 需可執行 `gcloud`，用來建立 Application Default Credentials；未安裝請先依官方文件安裝：https://example.invalid/sdk/docs/install |
| Google Cloud 帳號 | 需先登入可讀公司 GCP project 的帳號；`observability` MCP 不保存 credential，會讀本機 ADC |
| GCP 權限 | 使用者帳號需有目標 project 的 Cloud Logging 讀取權限（至少可呼叫 `logging.logEntries.list`） |
| 網路 | 首次 `npx -y @google-cloud/observability-mcp@<version>` 會抓 npm 套件，需通到 example.invalid（pin 版本以 `bin/observability-mcp.js` 的 `PKG` 為準） |

## 安裝

### 1. 先設定 GCP 帳號與 ADC

`observability` MCP 使用 Google Application Default Credentials 查 Cloud Logging。安裝 plugin 前先在本機完成登入，否則 `/mcp` 可能 connected，但實際查 log 會因 credential / IAM 失敗。

macOS / Linux：

```bash
gcloud --version
gcloud auth login
gcloud auth application-default login
gcloud auth application-default print-access-token
```

Windows PowerShell：

```powershell
gcloud --version
gcloud auth login
gcloud auth application-default login
gcloud auth application-default print-access-token
```

若組織要求 quota project，請依公司指派的 project 設定：

```bash
gcloud auth application-default set-quota-project <QUOTA_PROJECT_ID>
```

`print-access-token` 能印出 token 只代表 ADC 存在；若查特定 project 仍回 403，請確認該 Google Cloud 帳號在目標 project 有 Cloud Logging 讀取權限。

### 2. 安裝 plugin

```
/plugin install gcp-mcp
/reload-plugins
/mcp
```

Expected: `observability` server 狀態為 `connected`。

### 3. 驗證查詢權限

開新對話，請 AI 用 `observability` MCP 對某 GCP project 查最近 5 分鐘的 Cloud Logging（裝了 `apis-toolkit` 時可直接說「查 TPower 最近 5 分鐘的 GCP log」，由 `gcp-log-troubleshoot` 自動組白標 project/filter）。

Expected: `observability` MCP 回傳 log entries。若 MCP connected 但查詢失敗，先重跑：

```bash
gcloud auth application-default print-access-token
```

再確認目標 project IAM 是否允許 Cloud Logging 讀取。

## MCP server

`.mcp.json` 註冊的 server 名稱：

```json
{
  "mcpServers": {
    "observability": {
      "command": "node",
      "args": ["${CLAUDE_PLUGIN_ROOT}/bin/observability-mcp.js"]
    }
  }
}
```

wrapper（`bin/observability-mcp.js`）會啟動其 `PKG` 常數 pin 的版本（**版本 SSOT 在該檔，README 不複述版號**）：

```bash
npx -y @google-cloud/observability-mcp@<version>
```

並預設使用 **per-user 持久 npm cache**（非 `/tmp`，避免 macOS tmp cleaner 清掉造成半殘 extraction）：

| 平台 | 預設路徑 |
|---|---|
| macOS | `~/Library/Caches/gcp-mcp-observability/npm-cache` |
| Linux | `$XDG_CACHE_HOME/gcp-mcp-observability/npm-cache`（未設則 `~/.cache/...`） |
| Windows | `%LOCALAPPDATA%\gcp-mcp-observability\npm-cache` |

啟動失敗（快速非零退出）時 wrapper 會自動 purge `_npx` extraction tree 並重試一次（單次、且以 interprocess lock 保護，不影響並行 session）。如需覆蓋 cache，可在啟動 Claude Code 的 shell 先設定 `npm_config_cache`。

## 常見問題

| 症狀 | 原因 / 解法 |
|---|---|
| `/mcp` 看不到 `observability` | 確認已安裝 `gcp-mcp`，再跑 `/reload-plugins` |
| `failed to spawn npx` | host 缺 Node.js / npx，先安裝 Node.js LTS |
| MCP connected 但查 log 權限錯誤 | 檢查 `gcloud auth application-default print-access-token` 與目標 project IAM |
| npm cache 權限錯誤 | wrapper 預設用 per-user 持久 cache（見上表）；wrapper 會自動 purge `_npx` 重試一次，若仍失敗，刪除該 cache 後重試或設定另一個 `npm_config_cache` |

## 依賴本 plugin 的 plugin

- `apis-toolkit` — 其 `gcp-log-troubleshoot` skill 透過本 plugin 的 `observability` MCP 查 Cloud Logging，故宣告依賴以自動帶裝
