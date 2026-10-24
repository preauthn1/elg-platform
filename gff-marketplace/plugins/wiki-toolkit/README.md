# wiki-toolkit

> 寫 APIS 知識庫 wiki（Outline）用的技能包：功能說明頁、操作手冊，以及發頁後在魔鏡建 pointer。RD、PM、QA 都可以裝。

## 這是什麼

| 內容 | 說明 |
|---|---|
| `wiki-authoring` skill | 照知識庫的目錄結構與頁面格式寫功能頁、操作手冊，並在魔鏡建 pointer。說「寫 wiki」「補功能頁」「寫操作手冊」「幫這頁建 pointer」「審這頁 wiki」就會觸發。 |
| `outline` MCP server | 連公司 Outline wiki 的 MCP（Outline 內建的 `/mcp` endpoint），讓 Claude 讀寫 wiki 頁面。 |

依賴 `memory-mcp`（建 pointer 要寫魔鏡），安裝時會自動帶入。

## 前置條件

| 依賴 | 說明 |
|---|---|
| 網路 | 在公司 LAN 或 VPN 內，連得到 wiki 那台主機。 |
| Outline 帳號 | 用 GitLab 帳號登入 wiki 一次，帳號就會建立。 |
| Outline API key | 每個人自己產生，不共用。登入 wiki 後到「設定 › API」建立一把，複製下來。key 不要貼到任何 repo 或聊天室。 |

## 兩個環境變數

| 變數 | 值 | 說明 |
|---|---|---|
| `OUTLINE_MCP_URL` | `http://0.0.0.0:3000/mcp` | wiki 的 MCP endpoint。`.mcp.json` 以 `${OUTLINE_MCP_URL}` 展開，沒有預設值。 |
| `OUTLINE_API_KEY` | 自己在 wiki 建立的 key | `.mcp.json` 以 `Bearer ${OUTLINE_API_KEY}` 放進 `Authorization` header，沒有預設值。 |

兩個都必填，少一個 `outline` server 就不會載入。設定方式跟 `memory-mcp` 一樣：

macOS / Linux 寫進 `~/.zshrc`（bash 用 `~/.bashrc`），存檔後 `source ~/.zshrc` 或重開終端機：

```bash
echo 'export OUTLINE_MCP_URL="http://0.0.0.0:3000/mcp"' >> ~/.zshrc
echo 'export OUTLINE_API_KEY=[REDACTED]"' >> ~/.zshrc
```

Windows PowerShell（寫進使用者環境變數，重開終端機後生效）：

```powershell
[Environment]::SetEnvironmentVariable("OUTLINE_MCP_URL", "http://0.0.0.0:3000/mcp", "User")
[Environment]::SetEnvironmentVariable("OUTLINE_API_KEY", "你的key", "User")
```

## 安裝

```
/plugin install wiki-toolkit
/reload-plugins
/mcp                 # 應看到 outline 與 memory 兩個 server connected
```

以前手動在 `~/.claude.json` 加過 `outline` MCP 的人，裝完後把手動那一筆刪掉，不然會有兩個同樣的 server。

## 常見問題

- `fetch` 回 `Authorization error`：多半是用了網址尾巴的短 id，改用完整 UUID。skill 內有說明。
- `/mcp` 看不到 `outline`：兩個環境變數有一個沒設，或設了之後沒重啟 Claude Code。
