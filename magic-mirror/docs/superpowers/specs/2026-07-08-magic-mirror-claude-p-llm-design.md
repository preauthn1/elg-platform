# 魔鏡 (magic-mirror)：LLM 傳輸層改用本機 `claude -p`（預設 haiku）

> 日期：2026-07-08
> 目的：把 [2026-07-03 意圖判斷 + RAG spec](./2026-07-03-slack-bot-llm-intent-rag-design.md) 的 LLM 供應商，
> 從 siraya.ai（OpenAI 相容、後端 Gemini）改成**本機 `claude -p`（Claude Code CLI），預設 `haiku` 模型**。
> 原則不變：LLM 只在 bot 層做 orchestration；memory service 仍只負責 embedding 檢索與評分儲存。

## 修訂（2026-07-08，實作階段定案）

實作時把原設計的「**2 次 claude 呼叫**」（`classifyIntent` 再 `synthesizeAnswer`）**合併為 1 次**（`answerQuery`），砍一半延遲。原因：`classifyIntent` 只是為了決定要不要搜尋，但 `searchMemory`（embedding REST）只要 ~0.2s，先搜不虧，故改成**永遠先 `searchMemory`，再把 query + 命中記憶一次丟給 `claude -p`**，由該次同時判斷意圖並產出回答。

- `classifyIntent` + `synthesizeAnswer` → 單一 `answerQuery(query, results)` → `{ type, text }`，`type ∈ "answer" | "chitchat" | "ambiguous"`。
- 一次查詢的 LLM 呼叫數：**2 → 1**（實測單次 `claude -p` ~7s，故 ~14s → ~7s）。
- 降級：`answerQuery` 失敗 / JSON 解析不出 → `{ type:"answer", text:null }`，呼叫端顯示最符合結果原文。

**下方 §架構、§資料流、§錯誤處理、§測試、§動工待辦仍保留原始 2 次呼叫設計以記錄脈絡；實際出貨程式以本節（單次 `answerQuery`）為準。** 其餘（runClaude 契約、版面、逐筆三鍵評分、`LLM_ENABLED`、`.env`、移除 openai）皆不變。

> 另註：出貨時 `.env` 先設 `LLM_ENABLED=false`（純檢索、零 LLM 延遲）；要開綜合回答改 `true` 重啟即可。

## 背景

07-03 spec 定案了「意圖判斷 + RAG 綜合回答」的完整設計，但**尚未實作**（`src/index.js` 目前仍是原始的「貼記憶原文 + 四鍵評分」）。該 spec 選用 siraya.ai，並自行標注隱私風險：把公司內部營運記憶送進 Gemini 的 training-tier。

本次決定改用**同一台本機已登入的 `claude -p`** 當 LLM，理由：(1) 免把內部記憶送外部供應商，消除該隱私風險；(2) 本機已有 Claude Code CLI 並登入，零額外憑證管理；(3) 少一個外部套件（`openai`）與端點依賴。

## 範圍（brainstorming 定案）

| 項目 | 決定 |
|------|------|
| 變更範圍 | **只換 LLM 傳輸層**。07-03 的意圖判斷 + RAG 綜合回答、資料流、版面、逐筆三鍵評分、`LLM_ENABLED` 降級開關**全部沿用不變** |
| LLM 供應商 | 本機 `claude -p`（Claude Code CLI），透過 `child_process` spawn |
| 預設模型 | `haiku`，可用 `CLAUDE_MODEL` env 覆蓋 |
| 檢索 | 仍由 bot 的 `memoryClient.searchMemory()`（embedding 檢索）負責；`claude -p` 純當「文字推理器」，**不給工具 / 不給 MCP** |
| 執行環境 | bot 與 `claude` CLI 同一台本機、已登入；直接走 PATH spawn `claude`，另留可選 `CLAUDE_BIN` override |
| 輸出解析 | `--output-format json` 取外層信封 `.result` |
| 逾時 | 每次呼叫預設 20s，逾時/非零 exit → throw → 走既有降級 |
| 依賴 | **移除** `openai`（07-03 唯一新增的外部套件）；`claude -p` 用 Node 內建 `child_process`，無新依賴 |
| 隱私 | 消除 siraya/Gemini training-tier 風險；`.env.example` 移除該註記 |

## 架構與新增元件

維持「LLM 在 bot 層、memory service 純資料庫」的職責分離。對外仍是 `src/llmClient.js` 的兩個函式，**介面與行為不變，只換內部傳輸**：

- `classifyIntent(query)` → `{ intent, reply }`
  - `intent` ∈ `"memory_query" | "chitchat" | "ambiguous"`
  - `reply`：`chitchat` → 禮貌帶回正題句；`ambiguous` → 反問澄清句；`memory_query` → `null`（不使用）。
  - 實作：一次 `runClaude()` 呼叫，prompt 要求「只回 JSON `{intent, reply}`，繁體中文」。取 `.result` 後 `JSON.parse`；解析失敗 → 回 `{ intent: "memory_query", reply: null }`（降級為現有「一律搜尋」）。
- `synthesizeAnswer(query, results)` → `string`（一段繁中綜合回答）
  - 把 top-k 記憶原文塞進 prompt，要求「只根據提供的記憶回答，找不到就說不知道、不要編造，繁體中文、簡潔」。
  - 失敗時 throw，由呼叫端 catch 後降級。

`classifyIntent` 必須在 `searchMemory` 之前（分類不需搜尋結果，綜合才需要），故無法與 `synthesizeAnswer` 併為單次呼叫；一次查記憶最多 2 次 LLM 呼叫。

### 新增薄封裝 `runClaude(prompt, options)`

`src/llmClient.js` 內新增並 `export`（供測試獨立匯入 / 注入 mock）：

```
runClaude(prompt, { model = process.env.CLAUDE_MODEL || "haiku",
                    timeoutMs = Number(process.env.CLAUDE_TIMEOUT_MS) || 20000 })
  → Promise<string>   // 回傳 .result 字串
```

行為：

1. 用 `child_process.execFile`（**非** `exec`，避免 shell 注入）spawn：
   ```
   <CLAUDE_BIN||"claude"> -p <prompt> --model <model> --output-format json
   ```
   - `prompt` 以參數陣列傳入，不經 shell。
2. **中性 cwd**：`cwd: os.tmpdir()`，避免 `claude` 撈到某專案的 `CLAUDE.md` / 專案 MCP / 工具設定，確保純文字推理且快速。
3. **不授予工具 / 不吃專案 MCP**：純文字進出；不傳任何 API key（靠本機既有登入）。
4. **逾時**：`execFile` 的 `timeout: timeoutMs`；逾時或非零 exit → reject（throw）。
5. **輸出解析**：`--output-format json` 回傳信封，`JSON.parse(stdout).result` 取模型輸出字串回傳。信封解析失敗 → throw。

> 註：`.result` 內容對 `synthesizeAnswer` 是直接回答；對 `classifyIntent` 是「模型被要求輸出的 JSON 字串」，需在 `classifyIntent` 內再 `JSON.parse` 一次（兩層：外層信封 + 內層意圖 JSON）。

## 資料流（收到一則訊息）— 沿用 07-03

```
訊息 → classifyIntent(query)                    ← claude -p 呼叫 #1
  ├─ chitchat  → say(reply)                      不搜尋、不綜合
  ├─ ambiguous → say(reply)                      不搜尋（reply 為反問句）
  └─ memory_query
        → searchMemory(query)
        ├─ 有結果 → synthesizeAnswer(query, results)   ← claude -p 呼叫 #2
        │            → buildBlocks(answer, results)
        └─ 無結果 → 現有「查詢『X』沒有找到相關記憶」訊息
```

三入口共用：`app_mention` handler、DM `message` handler、`search_related_*` 按鈕 handler。
`LLM_ENABLED=false` 時：跳過 `classifyIntent` 與 `synthesizeAnswer`，直接 `searchMemory` → 舊版 `buildBlocks`，完全等同現況 bot。

## 訊息版面（RAG 回答）— 沿用 07-03

```
🔍 查詢：請假規定
────────────────────────
💡 綜合回答
  年假 14 天，病假需附證明，事假不給薪…（LLM 揉 top-k 而成）
────────────────────────
📚 來源
  🟢 82%  請假規定總表
          [👍 有用]  [🤔 普通]  [👎 沒用]  [🔎 查詢]
  🟡 74%  特休換算
          [👍 有用]  [🤔 普通]  [👎 沒用]  [🔎 查詢]
```

- 頂部 `💡 綜合回答` section：`synthesizeAnswer` 的結果。
- `📚 來源`：**所有**命中結果一律「分數 + 標題」精簡呈現，不貼整段原文。
- 每筆來源：一個 `section`（分數 + 標題）+ 一個 `actions` block（`block_id = "src_" + content_hash`），四顆按鈕：
  - `👍 有用` → `rate_src_up`，`value: "{content_hash}:{query}"`
  - `🤔 普通` → `rate_src_mid`，`value: "{content_hash}:{query}"`
  - `👎 沒用` → `rate_src_down`，`value: "{content_hash}:{query}"`
  - `🔎 查詢` → `search_related_{i}`，`value: 標題`
- `value` 沿用 `"{content_hash}:{query}"` 格式（第一個冒號切分）。

## Action handlers — 沿用 07-03

- 評分（逐筆）`rate_src_up/mid/down` → `apiRating` `+1/0/-1`：`ack()` → 拆 `value` 取 `content_hash` → `rateMemory(...)` → **只更新該筆 actions block**（`block_id === "src_" + content_hash`）換成靜態文字，防止重複評分。
- 移除舊的單一組四鍵 handler（`rate_exact/helpful/partial/irrelevant`）與訊息級 reaction。
- 再查詢 `search_related_*`：沿用（於 channel 發新訊息），跑新的完整流程。

## 錯誤處理 / 降級

LLM 掛掉時 bot 絕不比現在差，一律降級回純檢索：

| 失敗點 | 降級行為 |
|--------|----------|
| `runClaude` 逾時 / 非零 exit / 信封解析失敗 | 由呼叫端視情境降級（見下列兩點） |
| `classifyIntent` 失敗 / 內層 JSON 解析失敗 | 當成 `memory_query`（維持現有「一律搜尋」） |
| `synthesizeAnswer` 失敗 | 頂部改回顯示「最符合結果原文」（現有版面），來源照舊 |
| `LLM_ENABLED=false` | 完全跳過 LLM，走現有純檢索 bot |
| `searchMemory` 失敗 | 沿用現況：回「查詢失敗：{訊息}」 |

## 設定（`.env` / `.env.example`）

**移除**（siraya 那套）：`LLM_BASE_URL`、`LLM_API_KEY`、`LLM_MODEL` 與 siraya 隱私註記。

**保留 / 新增**：

```
# LLM：本機 claude -p（Claude Code CLI）
LLM_ENABLED=true
CLAUDE_MODEL=haiku            # 預設 haiku，可改其他 Claude 模型別名
# CLAUDE_BIN=claude           # 選用；claude 不在預設 PATH 時指定執行檔位置
CLAUDE_TIMEOUT_MS=20000       # 單次 claude -p 逾時（毫秒）
# 說明：bot 與 claude CLI 需同機且該機已登入 Claude Code；
#       claude -p 於中性 cwd 純文字推理，不授予工具、不吃專案 MCP，不外送任何憑證。
```

## 測試

沿用 07-03：Node 內建 `node:test`，`npm test`（`node --test`）。**差異**：mock 的對象由「openai client」改為 `runClaude`（或注入的 claude runner）。

- `src/llmClient.js`（注入 mock `runClaude`）：
  - `classifyIntent` 三種意圖各自解析正確（mock `runClaude` 回傳合法內層 JSON 字串）。
  - `classifyIntent` 遇壞 JSON（內層或外層）→ 降級為 `{ intent: "memory_query", reply: null }`。
  - `synthesizeAnswer` 正常回字串；`runClaude` throw 時 `synthesizeAnswer` 亦 throw。
- `runClaude`（可 mock `execFile`）：
  - 正常路徑解析 `.result` 正確。
  - 非零 exit / 逾時 / 信封非 JSON → reject。
- `buildBlocks`（純函式）：
  - answer + results → 版面含 `💡 綜合回答` section。
  - 每筆來源含三顆評分鍵（`rate_src_up/mid/down`）+ `🔎 查詢`，`block_id === "src_" + content_hash`。
  - 無結果 → 「沒有找到相關記憶」訊息。

為可測試，`buildBlocks`、`classifyIntent`、`synthesizeAnswer`、`runClaude` 皆須可獨立匯入 / 可注入 mock。

## 動工待辦

- [ ] 新增 `runClaude(prompt, options)`：`execFile('claude', ['-p', prompt, '--model', model, '--output-format', 'json'])`，中性 cwd、逾時、解析 `.result`
- [ ] 新增 / 重寫 `src/llmClient.js`：`classifyIntent(query)`、`synthesizeAnswer(query, results)`，內部改用 `runClaude`
- [ ] `src/index.js`：三入口改走「classifyIntent → 分支 → (search → synthesize)」流程
- [ ] `buildBlocks`：頂部加 `💡 綜合回答`；來源改精簡標題 + 逐筆三鍵評分 actions block
- [ ] 新增評分 handlers `rate_src_up/mid/down`；移除舊的 `rate_exact/helpful/partial/irrelevant`
- [ ] `LLM_ENABLED` 降級開關與各失敗點 fallback
- [ ] `.env` / `.env.example`：移除 siraya 三變數與註記，加入 `CLAUDE_MODEL` / `CLAUDE_BIN` / `CLAUDE_TIMEOUT_MS`
- [ ] `package.json`：不需 `openai`；加 `npm test`（`node --test`）
- [ ] `node:test` 測試（mock `runClaude` / `execFile`）
- [ ] 更新 `ARCHITECTURE.md`：供應商由 siraya 改為本機 `claude -p`（預設 haiku），標明純推理、不外送
```
