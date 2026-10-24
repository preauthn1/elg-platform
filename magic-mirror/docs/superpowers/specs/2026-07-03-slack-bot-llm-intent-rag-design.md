# slack-memory-bot：意圖判斷 + RAG 綜合回答（siraya.ai / OpenAI 相容）

> 日期：2026-07-03
> 目的：在 bot 層加入 LLM，先判斷使用者意圖，再對「查記憶」意圖做 RAG 綜合回答。
> LLM 供應商改用 siraya.ai（OpenAI 相容介面，後端為 Gemini）。
> 原則不變：LLM 只在 bot 層做 orchestration；memory service 仍只負責 embedding 檢索與評分儲存。

## 背景與現況

現況 bot（Node / Bolt / Socket Mode）收到 `@mention`、DM 或「查詢」按鈕觸發時，一律把訊息當關鍵字丟 `searchMemory()`，再把命中的記憶原文貼成 Slack blocks。缺點：回傳是記憶原文堆疊，使用者要自己讀，多筆結果無法綜合，也無法區分「查記憶」與「閒聊 / 太模糊」。

本設計加入兩件事：

1. **意圖判斷**：訊息先經 LLM 分類為 `memory_query`、`chitchat`、`ambiguous` 三類，只有 `memory_query` 才去搜尋。
2. **RAG 綜合回答**：`memory_query` 且有搜尋結果時，把 query + top-k 記憶交給 LLM 綜合成一段繁中回答，取代原本「貼原文」。

## 供應商：siraya.ai

OpenAI 相容端點，使用官方 `openai` 套件連接：

```js
import OpenAI from "openai";
const client = new OpenAI({
  baseURL: process.env.LLM_BASE_URL, // https://llm.siraya.ai/v1
  apiKey: [REDACTED],
});
```

後端為 Gemini，實際 model 字串由 `LLM_MODEL` 環境變數帶入，於 siraya 後台確認後填寫。

**隱私注意**：siraya 後端 Gemini 若使用「會拿 prompt 訓練」的 tier，送進去的是公司內部營運記憶。實際 tier / model 由使用者確認；`.env.example` 需註記此風險。

## 決策紀錄（brainstorming 定案）

| 項目 | 決定 |
|------|------|
| 範圍 | 意圖判斷 + RAG 綜合回答（涵蓋單純綜合回答） |
| 意圖類別 | 三類：`memory_query` / `chitchat` / `ambiguous` |
| `chitchat` 行為 | 禮貌回一句帶回正題，不搜尋、不綜合 |
| `ambiguous` 行為 | LLM 產生反問澄清句，不搜尋 |
| 供應商 | siraya.ai（OpenAI 相容，後端 Gemini），model 走 `LLM_MODEL` |
| 顯示版面 | 綜合回答在上 + 精簡來源（標題清單），不貼最符合結果整段原文 |
| 評分 | 逐筆來源評分（掛回各自 `content_hash`），非整則一組 |
| 評分級距 | 每筆三鍵：👍 有用(+1) / 🤔 普通(0) / 👎 沒用(-1) |
| 降級開關 | 新增 `LLM_ENABLED`，`false` 時完全走現有純檢索行為 |
| 新增依賴 | `openai` 套件（相對舊規劃「不裝新套件」的唯一調整，已同意） |

## 架構與新增元件

維持「LLM 在 bot 層、memory service 純資料庫」的職責分離。

**新增 `src/llmClient.js`**，對外兩個函式，內部用 `openai` 套件指向 siraya：

- `classifyIntent(query)` → `{ intent, reply }`
  - `intent` ∈ `"memory_query" | "chitchat" | "ambiguous"`
  - `reply`：`chitchat` 時為禮貌帶回正題的句子；`ambiguous` 時為反問澄清句；`memory_query` 時為空字串或 `null`（不使用）。
  - 實作：單次 chat completion，system prompt 要求「只回 JSON `{intent, reply}`，繁體中文」。解析 JSON；解析失敗 → 回傳 `{ intent: "memory_query", reply: null }`（降級成現有行為）。
- `synthesizeAnswer(query, results)` → `string`（一段繁中綜合回答）
  - 把 top-k 記憶原文塞進 context，system prompt 要求「只根據提供的記憶回答，找不到就說不知道、不要編造，用繁體中文、簡潔」。
  - 失敗時 throw，由呼叫端 catch 後降級。

`classifyIntent` 必須在 `searchMemory` 之前呼叫（分類不需要搜尋結果，綜合才需要），因此無法與 `synthesizeAnswer` 合併為單次呼叫。

## 資料流（收到一則訊息）

```
訊息 → classifyIntent(query)                    ← LLM 呼叫 #1
  ├─ chitchat  → say(reply)                      不搜尋、不綜合
  ├─ ambiguous → say(reply)                      不搜尋（reply 為反問句）
  └─ memory_query
        → searchMemory(query)
        ├─ 有結果 → synthesizeAnswer(query, results)   ← LLM 呼叫 #2
        │            → buildBlocks(answer, results)
        └─ 無結果 → 現有「查詢『X』沒有找到相關記憶」訊息
```

一次查記憶最多 2 次 LLM 呼叫。三個入口共用此流程：

- `app_mention` handler
- DM `message` handler
- `search_related_*` 按鈕 handler（點來源的「🔎 查詢」後，於 channel 發新訊息並跑同一條流程）

`LLM_ENABLED=false` 時：跳過 `classifyIntent` 與 `synthesizeAnswer`，直接 `searchMemory` → 舊版 `buildBlocks`（頂部顯示最符合結果原文），完全等同現況 bot。

## 訊息版面（RAG 回答）

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
- `📚 來源`：**所有**命中結果（含最符合的）一律以「分數 + 標題」精簡呈現，不貼整段原文。
- 每筆來源結構（因 Slack `section.accessory` 只能放一個元件，故拆兩塊）：
  - 一個 `section`：`分數 + 標題`
  - 一個 `actions` block，`block_id = "src_" + content_hash`：四顆按鈕
    - `👍 有用` → `action_id: rate_src_up`，`value: "{content_hash}:{query}"`
    - `🤔 普通` → `action_id: rate_src_mid`，`value: "{content_hash}:{query}"`
    - `👎 沒用` → `action_id: rate_src_down`，`value: "{content_hash}:{query}"`
    - `🔎 查詢` → `action_id: search_related_{i}`，`value: 標題`（沿用現有再查詢行為）

`value` 沿用現有 `"{content_hash}:{query}"` 格式（第一個冒號切分）。

## Action handlers

### 評分（逐筆來源）

`rate_src_up / rate_src_mid / rate_src_down`，對應 `apiRating` `+1 / 0 / -1`：

1. `ack()`
2. 拆 `value` 取 `content_hash`（第一個冒號前）。
3. `rateMemory(content_hash, apiRating, feedback)` 寫回品質分數。
4. **只更新該筆的 actions block**（用 `block_id === "src_" + content_hash` 比對），把四顆按鈕換成靜態文字 `_已評：👍 有用_`（保留 `🔎 查詢` 或一併移除；以移除為預設，訊息更乾淨）。其他來源不受影響、防止重複評分。
5. 不再加整則訊息層級的 emoji reaction（逐筆評分下訊息級 reaction 語意不清）。

> 相對現況：移除舊的單一組四鍵 handler（`rate_exact / rate_helpful / rate_partial / rate_irrelevant`）與訊息級 reaction 邏輯，改為逐筆的三鍵評分。

### 再查詢

`search_related_*`：沿用現況（於 channel 發新訊息），但改為跑新的完整流程（含意圖判斷 + 綜合）。

## 錯誤處理 / 降級

LLM 掛掉時 bot 絕不比現在差，一律降級回純檢索：

| 失敗點 | 降級行為 |
|--------|----------|
| `classifyIntent` 失敗 / JSON 解析失敗 | 當成 `memory_query`（維持現有「一律搜尋」） |
| `synthesizeAnswer` 失敗 | 頂部改回顯示「最符合結果原文」（現有版面），來源照舊 |
| `LLM_ENABLED=false` | 完全跳過 LLM，走現有純檢索 bot |
| `searchMemory` 失敗 | 沿用現況：回「查詢失敗：{訊息}」 |

## 設定（`.env` / `.env.example` 新增）

```
# LLM（siraya.ai，OpenAI 相容，後端 Gemini）
LLM_ENABLED=true
LLM_BASE_URL=https://llm.siraya.ai/v1
LLM_API_KEY=[REDACTED]
LLM_MODEL=gemini-...          # 實際字串於 siraya 後台確認後填寫
# 注意：若 siraya 後端 Gemini 使用會拿 prompt 訓練的 tier，
#       送進去的是公司內部營運記憶，請確認 tier / model。
```

## 測試

專案目前無測試框架，採 Node 內建 `node:test`，新增 `npm test`（`node --test`）。

- `src/llmClient.js`（mock siraya 回應）：
  - `classifyIntent` 三種意圖各自解析正確。
  - `classifyIntent` 遇壞 JSON → 降級為 `{ intent: "memory_query", reply: null }`。
  - `synthesizeAnswer` 正常回字串；上游錯誤時 throw。
- `buildBlocks`（純函式）：
  - 給定 answer + results → 版面含 `💡 綜合回答` section。
  - 每筆來源含三顆評分鍵（`rate_src_up/mid/down`）+ `🔎 查詢`，且 `block_id` 為 `"src_" + content_hash`。
  - 無結果時 → 「沒有找到相關記憶」訊息。

為了可測試，`buildBlocks`、`classifyIntent`、`synthesizeAnswer` 皆須為可獨立匯入的純函式 / 可注入 mock client 的函式。

## 動工待辦

- [ ] `npm install openai`
- [ ] 新增 `src/llmClient.js`：`classifyIntent(query)`、`synthesizeAnswer(query, results)`
- [ ] `src/index.js`：三個入口改走「classifyIntent → 分支 → (search → synthesize)」流程
- [ ] `buildBlocks`：頂部加 `💡 綜合回答`；來源改精簡標題 + 逐筆三鍵評分 actions block
- [ ] 新增評分 handlers `rate_src_up/mid/down`；移除舊的 `rate_exact/helpful/partial/irrelevant`
- [ ] `LLM_ENABLED` 降級開關與各失敗點 fallback
- [ ] `.env` / `.env.example` 新增 LLM 設定與隱私註記
- [ ] `node:test` 測試 + `npm test`
- [ ] 更新 `ARCHITECTURE.md`（供應商由 Groq/Gemini 改為 siraya；版面與評分改逐筆）
