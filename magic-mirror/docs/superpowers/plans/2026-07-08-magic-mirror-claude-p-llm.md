# 魔鏡 LLM 傳輸層改用 `claude -p` Implementation Plan

> **⚠️ 已修訂（實作階段）**：Task 1/3 原規劃「2 次 claude 呼叫」（`classifyIntent` + `synthesizeAnswer`）。
> 實作時依決策**合併為 1 次** `answerQuery(query, results) → { type, text }`（`type ∈ answer|chitchat|ambiguous`），
> 流程改為「先 `searchMemory` → 一次 `answerQuery`」，一次查詢 LLM 呼叫數 2→1（~14s→~7s）。
> 出貨時 `.env` 先設 `LLM_ENABLED=false`（純檢索）。詳見
> [spec 的「修訂」段](../specs/2026-07-08-magic-mirror-claude-p-llm-design.md#修訂2026-07-08實作階段定案)。
> 下方 task 內容保留原始 2 次呼叫版本以記錄脈絡；出貨程式以 `answerQuery` 單次呼叫為準。

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把魔鏡 Slack bot 的 LLM 供應商從 siraya.ai 換成本機 `claude -p`（預設 haiku），實作 07-03 spec 的「意圖判斷 + RAG 綜合回答」。

**Architecture:** 新增 `src/llmClient.js`（`runClaude` 薄封裝 + `classifyIntent` + `synthesizeAnswer`，皆可注入 mock）；把純 Slack 版面組裝抽到 `src/blocks.js`（可獨立測試）；`src/index.js` 只留 wiring：三入口共用 `computeResponse(query)` 走「classify → 分支 → search → synthesize」，並改為逐筆三鍵評分。LLM 全程可經 `LLM_ENABLED` 與各失敗點降級回純檢索。

**Tech Stack:** Node 20+ ESM、`@slack/bolt`（Socket Mode）、Node 內建 `child_process` / `node:test`。無新外部依賴（不裝 `openai`）。

## Global Constraints

- **執行環境**：bot 與 `claude` CLI 同一台本機、已登入 Claude Code；透過 PATH spawn `claude`（可選 `CLAUDE_BIN` override）。
- **claude -p 純推理**：於中性 cwd（`os.tmpdir()`）執行，不授予工具、不吃專案 MCP、不外送任何憑證。
- **預設模型** `haiku`（`CLAUDE_MODEL` 覆蓋）；**單次逾時** 20000ms（`CLAUDE_TIMEOUT_MS` 覆蓋）。
- **降級鐵律**：LLM 掛掉時 bot 絕不比純檢索差。`classifyIntent` 失敗 → 當 `memory_query`；`synthesizeAnswer` 失敗 → 頂部顯示最符合結果原文；`LLM_ENABLED=false` → 完全跳過 LLM。
- **繁體中文**：所有面向使用者的字串與 LLM 輸出一律繁中。
- **不裝新套件**：只用 Node 內建與現有依賴。
- **語言/風格**：沿用現有 `src/*.js` 的 ESM、命名與註解風格。

### ⚠️ 待實作者留意的設計裁決（解 07-03 spec 內部矛盾）

07-03 spec 同時要求「移除舊的四鍵 handler」與「`LLM_ENABLED=false` 完全等同現況 bot（現況為四鍵評分）」，兩者衝突。本計劃**統一為單一評分系統**：只保留新的逐筆三鍵評分（`rate_src_up/mid/down`）。`LLM_ENABLED=false` 與「synthesize 失敗」都走同一個 `buildBlocks(query, results, { answer: null })` 分支——**檢索行為不變，但頂部顯示最符合結果原文、來源改用三鍵評分**。即降級路徑的「評分 widget」由四鍵變三鍵，其餘與現況相同。

## File Structure

- `src/blocks.js`（**新增**）：純函式 Slack 版面組裝。`buildBlocks(query, results, { answer })` + 顯示 helper（`cleanForDisplay` / `scoreLabel` / `extractTitle`）。無副作用、可獨立匯入測試。
- `src/llmClient.js`（**新增**）：`runClaude(prompt, opts)`、`classifyIntent(query, opts)`、`synthesizeAnswer(query, results, opts)`。`opts` 可注入 `exec` / `run` 供測試。
- `src/index.js`（**改寫**）：移除內嵌的 helper、舊 `buildBlocks`、舊四鍵 `RATING_CONFIG` 與其 handler；改為 import `blocks.js` / `llmClient.js`，新增 `computeResponse(query)` 與逐筆三鍵 handler。
- `src/memoryClient.js`（**不動**）：`searchMemory` / `rateMemory` / `closeClient` 沿用。
- `test/blocks.test.js`（**新增**）、`test/llmClient.test.js`（**新增**）：`node:test`。
- `package.json`（**改**）：加 `"test": "node --test"`。
- `.env.example`（**改**）：附加 LLM（claude -p）設定段。
- `ARCHITECTURE.md`（**改**）：供應商註記改為本機 `claude -p`。

---

## Task 1: `src/llmClient.js`（runClaude + classifyIntent + synthesizeAnswer）

**Files:**
- Create: `src/llmClient.js`
- Create: `test/llmClient.test.js`
- Modify: `package.json`（加 `test` script）

**Interfaces:**
- Consumes: 無（Node 內建 `child_process` / `util` / `os`）。
- Produces:
  - `runClaude(prompt: string, opts?: { model?: string, timeoutMs?: number, exec?: Function }) → Promise<string>`（回傳 JSON 信封的 `.result`）
  - `classifyIntent(query: string, opts?: { run?: Function }) → Promise<{ intent: "memory_query"|"chitchat"|"ambiguous", reply: string|null }>`
  - `synthesizeAnswer(query: string, results: Array<{content:string}>, opts?: { run?: Function }) → Promise<string>`

- [ ] **Step 1: 加 test script 到 `package.json`**

把 `scripts` 區塊改為：

```json
  "scripts": {
    "start": "node src/index.js",
    "dev": "node --watch src/index.js",
    "test": "node --test"
  },
```

- [ ] **Step 2: 寫 `test/llmClient.test.js`（失敗測試）**

```js
import { test } from "node:test";
import assert from "node:assert/strict";
import { runClaude, classifyIntent, synthesizeAnswer } from "../src/llmClient.js";

// ── runClaude ──
test("runClaude parses .result from JSON envelope", async () => {
  const fakeExec = async () => ({ stdout: JSON.stringify({ result: "hello" }) });
  assert.equal(await runClaude("hi", { exec: fakeExec }), "hello");
});

test("runClaude rejects when exec throws (non-zero exit / timeout)", async () => {
  const fakeExec = async () => { throw new Error("Command failed"); };
  await assert.rejects(() => runClaude("hi", { exec: fakeExec }));
});

test("runClaude rejects when envelope has no string .result", async () => {
  const fakeExec = async () => ({ stdout: JSON.stringify({ foo: 1 }) });
  await assert.rejects(() => runClaude("hi", { exec: fakeExec }));
});

// ── classifyIntent ──
for (const intent of ["memory_query", "chitchat", "ambiguous"]) {
  test(`classifyIntent parses ${intent}`, async () => {
    const run = async () =>
      JSON.stringify({ intent, reply: intent === "memory_query" ? "" : "一句話" });
    const res = await classifyIntent("q", { run });
    assert.equal(res.intent, intent);
    assert.equal(res.reply, intent === "memory_query" ? null : "一句話");
  });
}

test("classifyIntent tolerates code-fenced JSON", async () => {
  const run = async () => '```json\n{"intent":"chitchat","reply":"嗨"}\n```';
  assert.equal((await classifyIntent("q", { run })).intent, "chitchat");
});

test("classifyIntent degrades to memory_query on bad JSON", async () => {
  const run = async () => "not json at all";
  assert.deepEqual(await classifyIntent("q", { run }), { intent: "memory_query", reply: null });
});

test("classifyIntent degrades when run throws", async () => {
  const run = async () => { throw new Error("timeout"); };
  assert.deepEqual(await classifyIntent("q", { run }), { intent: "memory_query", reply: null });
});

// ── synthesizeAnswer ──
test("synthesizeAnswer returns trimmed string", async () => {
  const run = async () => "  答案  ";
  assert.equal(await synthesizeAnswer("q", [{ content: "c" }], { run }), "答案");
});

test("synthesizeAnswer throws when run throws", async () => {
  const run = async () => { throw new Error("boom"); };
  await assert.rejects(() => synthesizeAnswer("q", [{ content: "c" }], { run }));
});
```

- [ ] **Step 3: 執行測試，確認失敗**

Run: `npm test`
Expected: FAIL（`Cannot find module '../src/llmClient.js'` 或匯出不存在）

- [ ] **Step 4: 寫 `src/llmClient.js`**

```js
// src/llmClient.js — LLM orchestration via local `claude -p` (Claude Code CLI)
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import os from "node:os";

const execFileAsync = promisify(execFile);

const DEFAULT_MODEL = process.env.CLAUDE_MODEL || "haiku";
const DEFAULT_TIMEOUT = Number(process.env.CLAUDE_TIMEOUT_MS) || 20000;
const CLAUDE_BIN = process.env.CLAUDE_BIN || "claude";

/**
 * Spawn `claude -p` as a pure text reasoner; return the model's output text.
 * Neutral cwd (tmpdir), no tools/MCP, relies on host login. `exec` injectable for tests.
 * @returns {Promise<string>} the JSON envelope's `.result`
 */
export async function runClaude(
  prompt,
  { model = DEFAULT_MODEL, timeoutMs = DEFAULT_TIMEOUT, exec = execFileAsync } = {}
) {
  const { stdout } = await exec(
    CLAUDE_BIN,
    ["-p", prompt, "--model", model, "--output-format", "json"],
    { cwd: os.tmpdir(), timeout: timeoutMs, maxBuffer: 10 * 1024 * 1024 }
  );
  const envelope = JSON.parse(stdout);
  if (typeof envelope.result !== "string") {
    throw new Error("claude -p envelope missing string .result");
  }
  return envelope.result;
}

/** 從可能夾帶 code fence / 前後綴文字的字串裡抽出第一個 JSON 物件並 parse。 */
function extractJson(text) {
  const cleaned = text.replace(/```(?:json)?/gi, "").trim();
  const start = cleaned.indexOf("{");
  const end = cleaned.lastIndexOf("}");
  if (start === -1 || end === -1 || end < start) throw new Error("no JSON object found");
  return JSON.parse(cleaned.slice(start, end + 1));
}

const CLASSIFY_PROMPT = (query) => `你是公司內部知識庫「魔鏡」的意圖分類器。判斷下面這句使用者訊息屬於哪一類，只輸出 JSON，不要多餘文字。

類別：
- "memory_query"：在問公司內部知識 / 流程 / 名詞 / 資料，需要查記憶庫。
- "chitchat"：閒聊、打招呼、與知識查詢無關。
- "ambiguous"：太模糊、資訊不足，無法判斷要查什麼。

輸出格式（繁體中文）：
{"intent":"memory_query|chitchat|ambiguous","reply":"chitchat 時給一句禮貌帶回正題的話；ambiguous 時給一句反問澄清；memory_query 時給空字串"}

使用者訊息：${query}`;

/** 分類意圖；任何失敗都降級為 { intent: "memory_query", reply: null }。 */
export async function classifyIntent(query, { run = runClaude } = {}) {
  try {
    const parsed = extractJson(await run(CLASSIFY_PROMPT(query)));
    const intent = ["memory_query", "chitchat", "ambiguous"].includes(parsed.intent)
      ? parsed.intent
      : "memory_query";
    const reply = typeof parsed.reply === "string" ? parsed.reply : null;
    return { intent, reply: intent === "memory_query" ? null : reply };
  } catch {
    return { intent: "memory_query", reply: null };
  }
}

const SYNTH_PROMPT = (query, results) => `你是公司內部知識庫「魔鏡」。只根據下面提供的記憶片段回答問題，找不到答案就說「魔鏡目前沒有這則知識」，不要編造。用繁體中文、簡潔作答。

問題：${query}

記憶片段：
${results.map((r, i) => `【片段 ${i + 1}】\n${r.content}`).join("\n\n")}`;

/** 綜合 top-k 記憶成一段繁中回答；失敗時 throw（由呼叫端降級）。 */
export async function synthesizeAnswer(query, results, { run = runClaude } = {}) {
  return (await run(SYNTH_PROMPT(query, results))).trim();
}
```

- [ ] **Step 5: 執行測試，確認通過**

Run: `npm test`
Expected: PASS（llmClient 全部綠）

- [ ] **Step 6: Commit**

```bash
git add src/llmClient.js test/llmClient.test.js package.json
git commit -m "feat(llm): add claude -p transport (runClaude/classifyIntent/synthesizeAnswer)"
```

---

## Task 2: `src/blocks.js`（純函式版面 + 逐筆三鍵）

**Files:**
- Create: `src/blocks.js`
- Create: `test/blocks.test.js`

**Interfaces:**
- Consumes: 無。
- Produces:
  - `buildBlocks(query: string, results: Array<{content_hash,content,similarity_score,tags}>, opts?: { answer?: string|null }) → Array<SlackBlock>`
  - 每筆來源產生一個 `actions` block，`block_id === "src_" + content_hash`，elements 依序為 `rate_src_up` / `rate_src_mid` / `rate_src_down` / `search_related_{i}`。
  - 具名匯出 `cleanForDisplay` / `scoreLabel` / `extractTitle`（給未來測試 / 重用）。

- [ ] **Step 1: 寫 `test/blocks.test.js`（失敗測試）**

```js
import { test } from "node:test";
import assert from "node:assert/strict";
import { buildBlocks } from "../src/blocks.js";

const results = [
  { content_hash: "h1", content: "請假規定總表\n年假14天", similarity_score: 0.82, tags: ["ops"] },
  { content_hash: "h2", content: "特休換算\n比例…", similarity_score: 0.74, tags: [] },
];

test("有 answer 時：頂部顯示綜合回答，每筆來源三鍵 + 查詢", () => {
  const blocks = buildBlocks("請假規定", results, { answer: "年假14天…" });
  assert.ok(JSON.stringify(blocks).includes("💡 *綜合回答*"));
  const actions = blocks.filter((b) => b.type === "actions");
  assert.equal(actions.length, 2);
  assert.equal(actions[0].block_id, "src_h1");
  assert.deepEqual(
    actions[0].elements.map((e) => e.action_id),
    ["rate_src_up", "rate_src_mid", "rate_src_down", "search_related_0"]
  );
  // 評分按鈕 value 為 "{content_hash}:{query}"
  assert.equal(actions[0].elements[0].value, "h1:請假規定");
});

test("answer 為 null 時：頂部降級顯示最符合結果原文，無綜合回答", () => {
  const json = JSON.stringify(buildBlocks("請假規定", results, { answer: null }));
  assert.ok(json.includes("最符合的結果"));
  assert.ok(!json.includes("💡 *綜合回答*"));
});

test("無結果時：顯示沒有找到相關記憶", () => {
  assert.ok(JSON.stringify(buildBlocks("不存在", [])).includes("沒有找到相關記憶"));
});
```

- [ ] **Step 2: 執行測試，確認失敗**

Run: `npm test`
Expected: FAIL（`Cannot find module '../src/blocks.js'`）

- [ ] **Step 3: 寫 `src/blocks.js`**

```js
// src/blocks.js — 純函式 Slack 版面組裝（無副作用，可獨立測試）

const COMMON_TAGS = new Set(["人事行政"]);

export function cleanForDisplay(content) {
  return (content ?? "")
    .replace(/同義詞[：:].*$/s, "")
    .replace(/（(\d+)）/g, "\n• ")
    .replace(/\(\s*(\d+)\s*\)/g, "\n• ")
    .replace(/\n{3,}/g, "\n\n")
    .trim();
}

export function scoreLabel(score) {
  if (score == null) return "";
  const pct = (score * 100).toFixed(1);
  const tier = score >= 0.8 ? "🟢" : score >= 0.7 ? "🟡" : "🟠";
  return `${tier} ${pct}%`;
}

/** 取第一非空行為標題，去 markdown 符號，截到 Slack 按鈕上限。 */
export function extractTitle(content) {
  const firstLine =
    (content ?? "").split("\n").map((l) => l.trim()).find((l) => l.length > 0) ?? "";
  return firstLine.replace(/^[#*•\-＊●▶︎►]+\s*/, "").slice(0, 72);
}

const RATE_BUTTONS = [
  { text: "👍 有用", action_id: "rate_src_up", style: "primary" },
  { text: "🤔 普通", action_id: "rate_src_mid" },
  { text: "👎 沒用", action_id: "rate_src_down", style: "danger" },
];

/**
 * @param {string} query
 * @param {Array} results  searchMemory 回傳的扁平物件陣列
 * @param {{answer?: string|null}} [opts]  有 answer → 頂部綜合回答；否則頂部顯示最符合結果原文
 */
export function buildBlocks(query, results, { answer = null } = {}) {
  if (!results.length) {
    return [
      { type: "section", text: { type: "mrkdwn", text: `🔍 *查詢「${query}」* 沒有找到相關記憶。` } },
    ];
  }

  const [best] = results;
  const blocks = [
    { type: "section", text: { type: "mrkdwn", text: `🔍 *查詢：${query}*` } },
    { type: "divider" },
  ];

  if (answer) {
    blocks.push({ type: "section", text: { type: "mrkdwn", text: `💡 *綜合回答*\n\n${answer}` } });
  } else {
    const tags = (best.tags ?? []).filter((t) => !COMMON_TAGS.has(t));
    const tagsText = tags.length
      ? `\n_${tags.slice(0, 5).map((t) => `\`${t}\``).join(" ")}_`
      : "";
    blocks.push({
      type: "section",
      text: {
        type: "mrkdwn",
        text: `*最符合的結果* ── ${scoreLabel(best.similarity_score)}\n\n${cleanForDisplay(best.content)}${tagsText}`,
      },
    });
  }

  blocks.push({ type: "divider" });
  blocks.push({ type: "section", text: { type: "mrkdwn", text: "*📚 來源*" } });

  results.forEach((item, i) => {
    const title = extractTitle(item.content) || "（無標題）";
    const ratingValue = `${item.content_hash}:${query}`;
    const searchValue = (extractTitle(item.content) || (item.content ?? "").slice(0, 100)).slice(0, 255);

    blocks.push({
      type: "section",
      text: { type: "mrkdwn", text: `${scoreLabel(item.similarity_score)}  ${title}` },
    });
    blocks.push({
      type: "actions",
      block_id: `src_${item.content_hash}`,
      elements: [
        ...RATE_BUTTONS.map((b) => ({
          type: "button",
          text: { type: "plain_text", text: b.text, emoji: true },
          ...(b.style ? { style: b.style } : {}),
          action_id: b.action_id,
          value: ratingValue,
        })),
        {
          type: "button",
          text: { type: "plain_text", text: "🔎 查詢", emoji: true },
          action_id: `search_related_${i}`,
          value: searchValue,
        },
      ],
    });
  });

  return blocks;
}
```

- [ ] **Step 4: 執行測試，確認通過**

Run: `npm test`
Expected: PASS（blocks + llmClient 全綠）

- [ ] **Step 5: Commit**

```bash
git add src/blocks.js test/blocks.test.js
git commit -m "feat(blocks): extract testable buildBlocks with per-source 3-key rating"
```

---

## Task 3: 改寫 `src/index.js`（wiring + 降級 + 三鍵 handler）

**Files:**
- Modify: `src/index.js`（整檔改寫）
- Modify: `.env.example`（附加 LLM 段）
- Modify: `ARCHITECTURE.md`（供應商註記）

**Interfaces:**
- Consumes:
  - `./blocks.js` → `buildBlocks(query, results, { answer })`
  - `./llmClient.js` → `classifyIntent(query)`、`synthesizeAnswer(query, results)`
  - `./memoryClient.js` → `searchMemory(query)`、`rateMemory(hash, rating, feedback)`、`closeClient()`
- Produces: 執行檔，無對外匯出。

- [ ] **Step 1: 整檔改寫 `src/index.js`**

以下內容**完整取代** `src/index.js`（移除舊 helper、舊 `buildBlocks`、舊四鍵 `RATING_CONFIG` 與其 handler）：

```js
import "dotenv/config";
import { App } from "@slack/bolt";
import { searchMemory, rateMemory, closeClient } from "./memoryClient.js";
import { buildBlocks } from "./blocks.js";
import { classifyIntent, synthesizeAnswer } from "./llmClient.js";

const app = new App({
  token: [REDACTED],
  signingSecret: [REDACTED],
  socketMode: true,
  appToken: [REDACTED],
});

// 只有明確設 "false" 才停用 LLM；預設啟用。
const LLM_ENABLED = process.env.LLM_ENABLED !== "false";

/**
 * 收到一句 query，算出要 say / postMessage 的 payload（{ text, blocks? }）。
 * LLM_ENABLED：classify → 分支(chitchat/ambiguous 直接回) → search → synthesize（失敗降級原文）。
 * 停用 LLM：純檢索 → buildBlocks(answer:null)。
 * searchMemory 失敗會 throw，由呼叫端 catch。
 */
async function computeResponse(query) {
  if (!LLM_ENABLED) {
    const results = await searchMemory(query);
    return { text: `查詢結果：${query}`, blocks: buildBlocks(query, results, { answer: null }) };
  }

  const { intent, reply } = await classifyIntent(query);
  if (intent === "chitchat" || intent === "ambiguous") {
    return { text: reply || "可以再說清楚一點你想問什麼嗎？" };
  }

  const results = await searchMemory(query);
  if (!results.length) {
    return { text: `查詢結果：${query}`, blocks: buildBlocks(query, results) };
  }

  let answer = null;
  try {
    answer = await synthesizeAnswer(query, results);
  } catch (err) {
    console.error("[synthesize] failed, fallback to raw:", err.message);
  }
  return { text: `查詢結果：${query}`, blocks: buildBlocks(query, results, { answer }) };
}

// ── 逐筆來源評分（三鍵）────────────────────────────────────
// value 格式："{content_hash}:{query}"（第一個冒號切分）。
const RATE_CONFIG = {
  rate_src_up:   { apiRating: 1,  feedback: "有用", label: "👍 有用" },
  rate_src_mid:  { apiRating: 0,  feedback: "普通", label: "🤔 普通" },
  rate_src_down: { apiRating: -1, feedback: "沒用", label: "👎 沒用" },
};

for (const [actionId, cfg] of Object.entries(RATE_CONFIG)) {
  app.action(actionId, async ({ ack, client, body, action }) => {
    await ack();
    const colonIdx = (action.value ?? "").indexOf(":");
    const contentHash = colonIdx > 0 ? action.value.slice(0, colonIdx) : "";

    if (contentHash) {
      try {
        await rateMemory(contentHash, cfg.apiRating, cfg.feedback);
      } catch (err) {
        console.error(`[rate] ${actionId} failed for ${contentHash}:`, err.message);
      }
    }

    // 只把該筆來源的 actions block 換成靜態確認，其他來源不動、防止重複評分。
    const targetBlockId = `src_${contentHash}`;
    const updatedBlocks = (body.message.blocks ?? []).map((block) =>
      block.block_id === targetBlockId
        ? { type: "section", text: { type: "mrkdwn", text: `_已評：${cfg.label}_` } }
        : block
    );

    try {
      await client.chat.update({
        channel: body.channel.id,
        ts: body.message.ts,
        text: body.message.text ?? "",
        blocks: updatedBlocks,
      });
    } catch (err) {
      console.error("[rate] update message failed:", err.message);
    }
  });
}

// ── 來源「🔎 查詢」：於 channel 發新訊息並跑完整流程 ─────────
app.action(/^search_related_\d+$/, async ({ ack, client, action, body }) => {
  await ack();
  const channel = body.channel.id;
  try {
    const payload = await computeResponse(action.value);
    await client.chat.postMessage({ channel, ...payload });
  } catch (err) {
    console.error("Query error:", err);
    await client.chat.postMessage({ channel, text: `查詢失敗：${err.message}` });
  }
});

// ── @mention ────────────────────────────────────────────────
app.event("app_mention", async ({ event, say }) => {
  const query = event.text.replace(/<@[A-Z0-9]+>/g, "").trim();
  if (!query) {
    await say({ text: "請在 @ 我之後輸入你的問題。", thread_ts: event.ts });
    return;
  }
  await say({ text: "🔮 魔鏡思考中…", thread_ts: event.ts });
  try {
    const payload = await computeResponse(query);
    await say({ ...payload, thread_ts: event.ts });
  } catch (err) {
    console.error("Query error:", err);
    await say({ text: `查詢失敗：${err.message}`, thread_ts: event.ts });
  }
});

// ── DM ──────────────────────────────────────────────────────
app.message(async ({ message, say }) => {
  if (message.channel_type !== "im" || message.subtype) return;
  const query = message.text?.trim();
  if (!query) return;
  console.log(`[DM] ${message.user}: ${query}`);
  try {
    const payload = await computeResponse(query);
    await say(payload);
  } catch (err) {
    console.error("Query error:", err);
    await say({ text: `查詢失敗：${err.message}` });
  }
});

process.on("SIGTERM", async () => {
  await closeClient();
  await app.stop();
});

(async () => {
  await app.start();
  console.log("🪞 魔鏡 (magic-mirror) is running (Socket Mode)");
})();
```

- [ ] **Step 2: 語法檢查 + 既有測試仍綠**

Run: `node --check src/index.js && npm test`
Expected: `node --check` 無輸出（OK）；`npm test` PASS（blocks + llmClient 全綠，改寫 index 未動到被測純函式）

- [ ] **Step 3: 附加 LLM 設定段到 `.env.example`**

在 `.env.example` 末尾附加（siraya 變數從未落地到檔案，無需移除）：

```
# ── LLM：本機 claude -p（Claude Code CLI）──
# bot 與 claude CLI 需同機且該機已登入 Claude Code。
# claude -p 於中性 cwd 純文字推理，不授予工具、不吃專案 MCP、不外送任何憑證。
LLM_ENABLED=true
CLAUDE_MODEL=haiku            # 預設 haiku，可改其他 Claude 模型別名
# CLAUDE_BIN=claude           # 選用；claude 不在預設 PATH 時指定執行檔位置
CLAUDE_TIMEOUT_MS=20000       # 單次 claude -p 逾時（毫秒）
```

- [ ] **Step 4: 更新 `ARCHITECTURE.md` 供應商註記**

在 `ARCHITECTURE.md` 標題（第 1 行 `# 魔鏡 magic-mirror 架構與 LLM 整合規劃`）**下方**插入一段（放在第一個 `>` 引言區塊之後）：

```markdown
> **LLM 供應商（2026-07-08 定案）**：改用**本機 `claude -p`（Claude Code CLI），預設 `haiku`**，
> 取代先前規劃的 siraya.ai/Gemini。`claude -p` 於中性 cwd 純文字推理、不授予工具、不外送憑證，
> 消除「內部記憶送外部供應商」的隱私風險。詳見
> [docs/superpowers/specs/2026-07-08-magic-mirror-claude-p-llm-design.md](docs/superpowers/specs/2026-07-08-magic-mirror-claude-p-llm-design.md)。
```

- [ ] **Step 5: Commit**

```bash
git add src/index.js .env.example ARCHITECTURE.md
git commit -m "feat(bot): route queries through claude -p RAG flow with per-source rating"
```

---

## Task 4: 手動端到端驗證（真跑 claude -p）

**Files:** 無（驗證與觀察）。

前置：本機 `claude` 已登入；`.env` 具備可用的 Slack tokens 與 MCP 設定（沿用現有 `.env`，LLM 變數可不填、走預設）。

- [ ] **Step 1: 隔離驗證 `runClaude` 真的能呼叫本機 claude**

Run:
```bash
node --input-type=module -e '
import { runClaude } from "./src/llmClient.js";
console.log(await runClaude("只回這三個字：測試通過", { timeoutMs: 60000 }));
'
```
Expected: 印出模型回覆（含「測試通過」字樣）。若報 `claude: command not found` → 設 `CLAUDE_BIN` 指向 claude 執行檔再試。

- [ ] **Step 2: 驗證 classifyIntent 三類**

Run:
```bash
node --input-type=module -e '
import { classifyIntent } from "./src/llmClient.js";
for (const q of ["請假規定是什麼", "哈囉在嗎", "那個東西"]) {
  console.log(q, "→", JSON.stringify(await classifyIntent(q)));
}
'
```
Expected: 三句分別大致落在 `memory_query` / `chitchat` / `ambiguous`（LLM 判斷，允許少量偏差；重點是能解析出合法 intent、不 throw）。

- [ ] **Step 3: 啟動 bot，於 Slack 實測**

Run: `npm start`
Expected: 印出 `🪞 魔鏡 (magic-mirror) is running (Socket Mode)`。於 Slack `@魔鏡 請假規定` 與 DM 各問一題，觀察：
- 出現「🔮 魔鏡思考中…」後回覆含「💡 綜合回答」+「📚 來源」清單。
- 每筆來源三顆評分鍵 + 🔎 查詢；點一顆評分 → 該筆換成「_已評：…_」，其他來源不受影響。
- 點 🔎 查詢 → channel 出現該標題的新查詢結果。
- 閒聊句（如「哈囉」）→ 只回一句帶回正題、不出來源清單。

- [ ] **Step 4: 驗證降級開關**

Run: `LLM_ENABLED=false npm start`
Expected: 同樣可查詢，但頂部顯示「最符合的結果」原文（無綜合回答），來源仍為三鍵評分；全程不呼叫 claude。

- [ ] **Step 5: 收尾**

確認以上皆符合預期即完成；`Ctrl-C` 停止測試用的 bot 進程。無程式變更則本任務無 commit。

---

## Self-Review（作者自審）

**Spec coverage：**
- 只換 LLM 傳輸層、其餘沿用 → Task 1（llmClient）、Task 3（index 沿用流程/版面）。✅
- `runClaude` 契約（execFile、中性 cwd、--output-format json 取 .result、逾時）→ Task 1 Step 4。✅
- `classifyIntent` 雙層 JSON 解析 + 降級 → Task 1（`extractJson` + try/catch）。✅
- `synthesizeAnswer` 失敗 throw → Task 1；呼叫端降級 → Task 3 `computeResponse` try/catch。✅
- RAG 版面（💡綜合回答 + 📚精簡來源）、逐筆三鍵 `rate_src_up/mid/down` + `block_id=src_<hash>` → Task 2。✅
- 移除舊四鍵 handler → Task 3 整檔改寫（已不含 rate_exact/…）。✅
- `LLM_ENABLED` 降級與各失敗點 → Global Constraints + Task 3 `computeResponse`；並在頂部裁決欄說明與「完全等同現況」的取捨。✅
- `.env` 調整、移除 openai 依賴、`node:test` → Task 1 Step 1、Task 3 Step 3、Task 1/2 測試。✅
- `ARCHITECTURE.md` 更新 → Task 3 Step 4。✅

**Placeholder scan：** 各步驟均含實際程式/指令/預期輸出，無 TBD/TODO。✅

**Type consistency：** `buildBlocks(query, results, { answer })`、`classifyIntent → {intent, reply}`、`synthesizeAnswer → string`、`runClaude → string`、評分 `action_id` = `rate_src_up/mid/down`、`block_id` = `src_<hash>`、value = `"<hash>:<query>"` 於 Task 1/2/3 一致。✅
```
