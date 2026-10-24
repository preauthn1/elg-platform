# 🪞 魔鏡 (Magic Mirror)

> 「魔鏡啊魔鏡，公司的事，問你就對了。」對外知識貢獻說明見 [CONTRIBUTING.md](CONTRIBUTING.md)。

Slack bot (Socket Mode) that searches the MCP Memory Service for relevant
memories.

問答策略兩層，失敗降級：

1. **agent**（`LLM_ENABLED=true`，預設）：haiku（`claude -p`）掛 memory MCP（唯讀工具白名單）
   ＋ GFF-Marketplace 的 `memory-search` skill SOP，自主決定查詢方式——列舉型走
   `memory_list` 確定性列舉（不受語意 top-k 截斷）、命中導航清單記憶會逐項補細節。
2. **純檢索**（`LLM_ENABLED=false` 或 agent 失敗）：原句語意搜尋，直接顯示最符合結果原文。

查不到知識（`not_found`）時會附「📝 登記待補」按鈕：按下把問題寫進共享記憶
（tag `待補`＋`魔鏡未答`），管理員用 `memory_list(tags=["待補"])` 巡檢補內容。

使用者要「存入／分享」知識（`store`）時，agent 照 `memory-store` skill SOP 擬草稿
（口語問句標題、同義詞、tag/type、出處：提問者 分享），回覆附草稿預覽＋
「✅ 寫入魔鏡／❌ 取消」按鈕——**LLM 沒有寫入工具**，按確認後才由 bot 端寫入，
寫入後自動用標題問法驗證搜得到。草稿暫存 bot 記憶體一小時，重啟即失。

## Prerequisites

- Node.js 20+
- `.env` configured (see `.env.example`) with Slack tokens and MCP server
  URL / auth token
- A running MCP Memory Service. Default points to `http://localhost:8000/mcp`

## 啟動（Docker，建議）

```bash
# 建置 + 背景啟動（restart: unless-stopped，crash / 重開機自動拉起）
docker compose up -d --build

# 看狀態 / log
docker ps --filter name=magic-mirror
docker logs -f magic-mirror

# 停止
docker compose down
```

- 容器內 `MCP_SERVER_URL` 由 compose 覆蓋為 `http://host.docker.internal:8000/mcp`（MCP 服務跑在 host）。
- `logs/` bind mount 至容器，`questions.log` 照常累積在 repo 內。
- **容器模式開 LLM**：claude CLI 已裝進 image，但容器讀不到 host Keychain 憑證——先在 host 跑 `claude setup-token` 產生長期 OAuth token，填入 `.env` 的 `CLAUDE_CODE_OAUTH_TOKEN`，並確認 `LLM_ENABLED=true`。未填 token 時 LLM 呼叫會失敗，自動降級為純檢索顯示原文。

## 啟動（本機，開發用）

```bash
# 安裝依賴（第一次或 package.json 更新後）
npm install

# 開發模式（檔案變更自動重啟）
npm run dev

# 一般啟動
npm start
```

Socket Mode 不需要對外公開 URL，啟動成功會看到 `🪞 魔鏡 (magic-mirror) is running (Socket Mode)`。

> 本機模式沒有進程守護，終端機關閉 / 睡眠即停；長駐請用 Docker 模式。
