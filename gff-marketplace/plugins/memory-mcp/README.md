# memory-mcp

> 共用長期記憶 MCP：把 `memory` server 接進 Claude Code，連到團隊 LAN 共享記憶服務。全員可用。團隊暱稱「**魔鏡**」＝本共享記憶服務。

## 這是什麼

- `memory`：長期記憶儲存 / 檢索。位址不寫在 repo，由環境變數 `MEMORY_MCP_URL` 提供（目前的位址見下方「兩個環境變數」）。

實際是 HTTP MCP server，本 plugin 以 Claude Code 原生 `http` transport **直連** server 的 `/mcp` endpoint 並注入 API key，不再經 `mcp-remote` / node wrapper 橋接；server 本身由維運端維護。

## 前置條件

| 依賴 | 說明 |
|---|---|
| 網路 | 使用時需在公司 LAN 或 VPN 內，連得到 memory server 那台主機。直連不再需要 node / npx。 |
| Server 位址 | 環境變數 `MEMORY_MCP_URL`，值是 server 的 `/mcp` endpoint。目前位址見下方。 |
| Memory MCP API Key | 向維運 / 作者索取 `memory` 的 `X-API-Key`，key 不在 repo。以環境變數 `MEMORY_API_KEY` 提供，設定方式見下方。 |

## 安裝

```
/plugin install memory-mcp
# 先照下方設定好 MEMORY_MCP_URL 與 MEMORY_API_KEY 兩個環境變數
/reload-plugins
/mcp                 # 應看到 memory server connected
```

## 兩個環境變數 (SSOT)

| 變數 | 值 | 說明 |
|---|---|---|
| `MEMORY_MCP_URL` | `http://0.0.0.0:8000/mcp` | server 的 `/mcp` endpoint。`.mcp.json` 以 `${MEMORY_MCP_URL}` 展開，**沒有預設值**。位址搬家時維運會公告，你只要改這個變數 |
| `MEMORY_API_KEY` | 向維運索取 | `.mcp.json` 以 `${MEMORY_API_KEY}` 展開為 `X-API-Key` header，**沒有預設值** |

兩個都是必填。少任何一個，Claude Code 會解析設定失敗、memory server 直接不載入。設定後需**完全重啟 Claude Code**（或 `/reload-plugins`）才會讀到。下面的設定方式兩個變數都照做。

### macOS / Linux（永久）

寫進 shell 設定檔即為**永久**（重開機、每個新終端都在）；不要只在當前視窗 `export`（那只在該 session 有效）。zsh 寫 `~/.zshrc`、bash 寫 `~/.bashrc`：

```bash
echo 'export MEMORY_MCP_URL="http://0.0.0.0:8000/mcp"' >> ~/.zshrc   # bash 改成 ~/.bashrc
echo 'export MEMORY_API_KEY=[REDACTED]"' >> ~/.zshrc
```

存檔後 `source ~/.zshrc` 或重開終端機。**確認變數已載入**（兩個都有印出值才算成功；空白代表沒設好）：

```bash
echo "$MEMORY_MCP_URL" "$MEMORY_API_KEY"
```

確認有值後再啟動 Claude Code，用 `/mcp` 確認 `memory` connected。

> **在終端機啟動 Claude Code**（一般用法）才會讀到 `~/.zshrc`。若你用 **macOS 桌面版 App（GUI 直接點開）**，GUI 程式不經登入 shell、讀不到 `~/.zshrc`；改用（重開機需重跑）：
> ```bash
> launchctl setenv MEMORY_MCP_URL "http://0.0.0.0:8000/mcp"
> launchctl setenv MEMORY_API_KEY "你的key"
> ```
> 要 GUI 也「永久」需建 LaunchAgent 於登入時自動 `launchctl setenv`（進階，非必要）。

> 容器內跑 Claude Code：兩個環境變數都需在容器內可見（例如 `docker run -e MEMORY_MCP_URL -e MEMORY_API_KEY` 或 compose `environment:`）。

### Windows（永久）

> **在 WSL 裡跑 Claude Code？** 那是 Linux 環境，PowerShell 設的變數傳不進去；請改照上面「macOS / Linux」段落，在 WSL 的 `~/.bashrc` / `~/.zshrc` 設定。以下只適用「原生 Windows 的 Claude Code」。

PowerShell 寫入**使用者環境變數**即為永久（重開機仍在）；`User` 範圍不需管理員權限。不要用 `$env:MEMORY_API_KEY=[REDACTED]

```powershell
[Environment]::SetEnvironmentVariable("MEMORY_MCP_URL", "http://0.0.0.0:8000/mcp", "User")
[Environment]::SetEnvironmentVariable("MEMORY_API_KEY", "你的key", "User")
```

關閉並重新開啟 Claude Code / 終端機後生效。**確認變數已載入**（有印出你的 key 才算成功；空白代表沒設好）：

```powershell
# 新開的 PowerShell 視窗
echo $env:MEMORY_MCP_URL
echo $env:MEMORY_API_KEY
# 或直接讀使用者環境變數（不需重開視窗）
[Environment]::GetEnvironmentVariable("MEMORY_MCP_URL", "User")
[Environment]::GetEnvironmentVariable("MEMORY_API_KEY", "User")
```

> CMD（非 PowerShell）確認用 `echo %MEMORY_MCP_URL%` 與 `echo %MEMORY_API_KEY%`。

### 注意

- key 為機密，永不可進 git。以環境變數提供，不寫入 repo 或 `.mcp.json` 明碼。
- **設定順序很重要**：`${MEMORY_MCP_URL}` 與 `${MEMORY_API_KEY}` 都沒有預設值，啟動 Claude Code 時任一個**未設**，Claude Code 會**解析設定失敗、memory server 不會載入**（`/mcp` 看不到 memory，並非連上後回 401）。請先設好兩個環境變數並重啟，再 `/mcp`。
- 位址刻意不寫死在 repo：server 搬家時不用發版，維運公告新位址、大家改環境變數即可。
- key **設錯 / 過期**（有值但不對）才會出現「server 連上、工具呼叫回 401」。

## 移轉

- 2026-09-22 起 server 從 `0.0.0.0` 搬到 `0.0.0.0`，且 `.mcp.json` 不再內建預設位址。原本沒設 `MEMORY_MCP_URL` 的人，更新 plugin 後要補設這個變數，否則 memory 不會載入。
- 舊版本以 node wrapper + `~/.gff/secrets.env` 管理 key。改直連後 **不再讀 secrets 檔**；請把 key 改用上方環境變數提供（`~/.gff/secrets.env` 內若還有 `MEMORY_API_KEY` 已無作用，可保留供其他 plugin 用）。
- 若你個人 `~/.claude.json` 曾手動加 `memory`（指向 localhost），安裝本 plugin 後請移除那條，避免同名來源衝突。先確認本 plugin 的 `/mcp` connected，再移除。

## 疑難

| 症狀 | 原因 / 解法 |
|---|---|
| `/mcp` 完全看不到 `memory`（或報設定解析錯誤） | `MEMORY_MCP_URL` 或 `MEMORY_API_KEY` 未設。兩者都沒有預設值，未設會導致設定解析失敗、server 不載入。先設好環境變數（用 `echo` 確認有值）再**完全重啟** Claude Code |
| `/mcp` 顯示 `memory` 連不上 | 多半是不在 LAN / VPN 內，或 `MEMORY_MCP_URL` 填錯（要含 `/mcp` 結尾） |
| 工具有出現但呼叫回 401 | `MEMORY_API_KEY` 有值但填錯 / 已過期；更新環境變數後重啟 Claude Code |
| 連線 timeout | 外網沒掛 VPN，或 server 離線 |
