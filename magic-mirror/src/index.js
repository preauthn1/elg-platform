import "dotenv/config";
import { randomUUID } from "node:crypto";
import { App } from "@slack/bolt";
import { searchMemory, getMemories, createMemory, rateMemory, closeClient } from "./memoryClient.js";
import { buildBlocks, buildGapPromptBlocks, buildStoreDraftBlocks } from "./blocks.js";
import { agentAnswer } from "./llmClient.js";
import { logQuery } from "./queryLog.js";
import { logRequest } from "./requestLog.js";
import { createUserResolver } from "./users.js";

const app = new App({
  token: [REDACTED],
  signingSecret: [REDACTED],
  socketMode: true,
  appToken: [REDACTED],
});

// 只有明確設 "false" 才停用 LLM；預設啟用。
const LLM_ENABLED = process.env.LLM_ENABLED !== "false";

const resolveUserName = createUserResolver();

// fire-and-forget：名字解析＋寫 log，任何錯誤不影響回答流程。
function logIncoming(client, userId, query) {
  resolveUserName(client, userId)
    .then((name) => logQuery(name, query))
    .catch((err) => console.error("[queryLog] failed:", err.message));
}

// fire-and-forget：解析名字後寫一行結構化請求紀錄（logs/requests.log），任何錯誤不影響回答流程。
function logOutcome(client, userId, entry) {
  resolveUserName(client, userId)
    .then((name) => logRequest({ user: name, user_id: userId, ...entry }))
    .catch((err) => console.error("[requestLog] failed:", err.message));
}

/**
 * 收到一句 query，算出 { payload, meta }：
 *   payload：要 say / postMessage 的內容（{ text, blocks? }）
 *   meta：結構化紀錄用（result_count / top_score / llm_enabled / llm_mode / llm_type…）
 * 兩層策略，失敗降級：
 *   1. agent（LLM_ENABLED，預設）：haiku 掛 memory MCP＋skill SOP 自主查詢
 *   2. 純檢索（停用 LLM、或 agent 失敗 fallback）：原句單搜尋 → buildBlocks(answer:null) 顯示最符合結果原文
 * 搜尋失敗會 throw，由呼叫端 catch。
 */
async function computeResponse(query, userName) {
  if (LLM_ENABLED) {
    try {
      return await computeAgentResponse(query, userName);
    } catch (err) {
      console.error("[agent] fallback to plain search:", err.message);
    }
  }
  const results = await searchMemory(query);
  return {
    payload: { text: `查詢結果：${query}`, blocks: buildBlocks(query, results, { answer: null }) },
    meta: {
      result_count: results.length,
      top_score: results[0]?.similarity_score ?? null,
      llm_enabled: LLM_ENABLED,
      llm_mode: LLM_ENABLED ? "search_fallback" : null,
      llm_type: null,
    },
  };
}

// ── 寫入草稿暫存：draftId → { draft, ts }，按鈕只帶 id（避開 value 2000 字上限）──
// bot 重啟即失（按到失效草稿會提示重新發起）；每次寫入時順手清掉過期的。
const DRAFT_TTL_MS = 60 * 60 * 1000;
const pendingDrafts = new Map();

function stashDraft(draft) {
  for (const [id, entry] of pendingDrafts) {
    if (Date.now() - entry.ts > DRAFT_TTL_MS) pendingDrafts.delete(id);
  }
  const id = randomUUID();
  pendingDrafts.set(id, { draft, ts: Date.now() });
  return id;
}

// agent 模式：haiku 自主查詢，回答附引用 content_hash → 取回原文組來源區（含評分按鈕）。
async function computeAgentResponse(query, userName) {
  const { type, text, sources, draft } = await agentAnswer(query, { userName });
  const meta = { llm_enabled: true, llm_mode: "agent", llm_type: type, sources, result_count: sources.length };
  if (type === "store") {
    // 寫入請求 → 草稿預覽＋確認按鈕；按下 store_confirm 才真正寫入
    return { payload: { text: `📝 ${text}`, blocks: buildStoreDraftBlocks(stashDraft(draft), draft, text) }, meta };
  }
  if (type === "not_found") {
    // 沒這則知識 → 附「登記待補」按鈕，按下把問題寫進共享記憶等管理員補內容
    return { payload: { text: `🪞 ${text}`, blocks: buildGapPromptBlocks(query, text) }, meta };
  }
  if (type === "chitchat" || type === "ambiguous") {
    return { payload: { text }, meta };
  }
  const results = await getMemories(sources);
  meta.result_count = results.length;
  if (!results.length) {
    return { payload: { text }, meta };
  }
  return { payload: { text: `查詢結果：${query}`, blocks: buildBlocks(query, results, { answer: text }) }, meta };
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
        logOutcome(client, body.user.id, {
          kind: "rate", channel: body.channel.id,
          content_hash: contentHash, rating: cfg.feedback, outcome: "ok",
        });
      } catch (err) {
        console.error(`[rate] ${actionId} failed for ${contentHash}:`, err.message);
        logOutcome(client, body.user.id, {
          kind: "rate", channel: body.channel.id,
          content_hash: contentHash, rating: cfg.feedback, outcome: "error", error: err.message,
        });
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

// 把訊息裡指定的按鈕列換成靜態確認文字，防重複操作。
async function replaceActionsBlock(client, body, blockId, confirmText) {
  const updatedBlocks = (body.message.blocks ?? []).map((block) =>
    block.block_id === blockId
      ? { type: "section", text: { type: "mrkdwn", text: confirmText } }
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
    console.error("[actions] update message failed:", err.message);
  }
}

// ── 未答登記：把問題寫進共享記憶（tag 待補）等管理員補內容 ────
app.action("register_gap", async ({ ack, client, body, action }) => {
  await ack();
  const query = action.value ?? "";
  // 每筆登記帶唯一追蹤編號：寫進內容與 metadata 供管理員對帳；
  // 同時當 conversation_id 跳過語意去重，多筆相似待補問題不互擋。
  const gapId = randomUUID();
  const gapRef = gapId.slice(0, 8);
  let confirmText = `_📝 已登記待補（編號 \`${gapRef}\`），管理員補上這則知識後就查得到了。_`;
  try {
    const name = await resolveUserName(client, body.user.id).catch(() => body.user.id);
    await createMemory(
      `魔鏡待補問題：${query}\n\n提問者：${name}（Slack）\n登記時間：${new Date().toISOString()}\n追蹤編號：${gapId}`,
      {
        tags: ["待補", "魔鏡未答"],
        memoryType: "observation",
        metadata: { source: "magic-mirror", slack_user: body.user.id, gap_id: gapId },
        conversationId: `gap-${gapId}`,
      }
    );
    logOutcome(client, body.user.id, {
      kind: "register_gap", channel: body.channel.id, query, gap_id: gapId, outcome: "ok",
    });
  } catch (err) {
    console.error("[gap] register failed:", err.message);
    confirmText = "_登記失敗，請稍後再試或直接找管理員。_";
    logOutcome(client, body.user.id, {
      kind: "register_gap", channel: body.channel.id, query, gap_id: gapId, outcome: "error", error: err.message,
    });
  }
  await replaceActionsBlock(client, body, "gap_register", confirmText);
});

app.action("dismiss_gap", async ({ ack, client, body }) => {
  await ack();
  await replaceActionsBlock(client, body, "gap_register", "_好的，這次先不登記。_");
});

// ── 寫入魔鏡：使用者確認 agent 擬的草稿後，由 bot 端寫入並照 SOP 驗證 ──
app.action("store_confirm", async ({ ack, client, body, action }) => {
  await ack();
  const entry = pendingDrafts.get(action.value);
  if (!entry) {
    await replaceActionsBlock(client, body, "store_draft", "_草稿已失效（超過一小時或 bot 重啟過），請重新發起。_");
    return;
  }
  pendingDrafts.delete(action.value);
  const { draft } = entry;
  let confirmText;
  try {
    const res = await createMemory(draft.content, {
      tags: draft.tags,
      memoryType: draft.memory_type,
      metadata: { source: "magic-mirror", slack_user: body.user.id, draft_id: action.value },
    });
    // memory-store SOP 第 7 步：存入後立即用標題問法驗證搜得到
    let verified = false;
    try {
      const hits = await searchMemory(draft.content.split("\n")[0].slice(0, 100));
      verified = hits.some((h) => h.content_hash === res.content_hash);
    } catch { /* 驗證失敗不影響已寫入的結果 */ }
    confirmText = verified
      ? "_✅ 已寫入魔鏡，並驗證用標題問法搜得到。_"
      : "_✅ 已寫入魔鏡（標題問法暫時搜不到，建議請管理員確認寫法）。_";
    logOutcome(client, body.user.id, {
      kind: "store", channel: body.channel.id,
      content_hash: res.content_hash ?? null, verified, outcome: "ok",
    });
  } catch (err) {
    console.error("[store] create failed:", err.message);
    confirmText = `_寫入失敗：${err.message.slice(0, 200)}_`;
    logOutcome(client, body.user.id, {
      kind: "store", channel: body.channel.id, outcome: "error", error: err.message,
    });
  }
  await replaceActionsBlock(client, body, "store_draft", confirmText);
});

app.action("store_cancel", async ({ ack, client, body, action }) => {
  await ack();
  pendingDrafts.delete(action.value);
  await replaceActionsBlock(client, body, "store_draft", "_已取消，未寫入。_");
});

// ── 來源「🔎 查詢」：於 channel 發新訊息並跑完整流程 ─────────
app.action(/^search_related_\d+$/, async ({ ack, client, action, body }) => {
  await ack();
  logIncoming(client, body.user.id, action.value);
  const channel = body.channel.id;
  const startedAt = Date.now();
  try {
    const userName = await resolveUserName(client, body.user.id);
    const { payload, meta } = await computeResponse(action.value, userName);
    await client.chat.postMessage({ channel, ...payload });
    logOutcome(client, body.user.id, {
      kind: "search_action", channel, query: action.value,
      ...meta, outcome: "ok", duration_ms: Date.now() - startedAt,
    });
  } catch (err) {
    console.error("Query error:", err);
    logOutcome(client, body.user.id, {
      kind: "search_action", channel, query: action.value,
      outcome: "error", error: err.message, duration_ms: Date.now() - startedAt,
    });
    await client.chat.postMessage({ channel, text: `查詢失敗：${err.message}` });
  }
});

// ── @mention ────────────────────────────────────────────────
app.event("app_mention", async ({ event, say, client }) => {
  const query = event.text.replace(/<@[A-Z0-9]+>/g, "").trim();
  if (!query) {
    await say({ text: "請在 @ 我之後輸入你的問題。", thread_ts: event.ts });
    return;
  }
  logIncoming(client, event.user, query);
  const startedAt = Date.now();
  if (LLM_ENABLED) await say({ text: "🔮 魔鏡思考中…", thread_ts: event.ts });
  try {
    const userName = await resolveUserName(client, event.user);
    const { payload, meta } = await computeResponse(query, userName);
    await say({ ...payload, thread_ts: event.ts });
    logOutcome(client, event.user, {
      kind: "mention", channel: event.channel, query,
      ...meta, outcome: "ok", duration_ms: Date.now() - startedAt,
    });
  } catch (err) {
    console.error("Query error:", err);
    logOutcome(client, event.user, {
      kind: "mention", channel: event.channel, query,
      outcome: "error", error: err.message, duration_ms: Date.now() - startedAt,
    });
    await say({ text: `查詢失敗：${err.message}`, thread_ts: event.ts });
  }
});

// ── DM ──────────────────────────────────────────────────────
app.message(async ({ message, say, client }) => {
  if (message.channel_type !== "im" || message.subtype) return;
  const query = message.text?.trim();
  if (!query) return;
  logIncoming(client, message.user, query);
  const startedAt = Date.now();
  if (LLM_ENABLED) await say({ text: "🔮 魔鏡思考中…" });
  try {
    const userName = await resolveUserName(client, message.user);
    const { payload, meta } = await computeResponse(query, userName);
    await say(payload);
    logOutcome(client, message.user, {
      kind: "dm", channel: message.channel, query,
      ...meta, outcome: "ok", duration_ms: Date.now() - startedAt,
    });
  } catch (err) {
    console.error("Query error:", err);
    logOutcome(client, message.user, {
      kind: "dm", channel: message.channel, query,
      outcome: "error", error: err.message, duration_ms: Date.now() - startedAt,
    });
    await say({ text: `查詢失敗：${err.message}` });
  }
});

// 容器友善：docker stop 送 SIGTERM、前景 Ctrl-C 送 SIGINT，皆優雅收線
for (const signal of ["SIGTERM", "SIGINT"]) {
  process.on(signal, async () => {
    await closeClient();
    await app.stop();
    process.exit(0);
  });
}

// 保險網：SDK 內部（如 Socket Mode 斷線重連）冒出的未處理錯誤，
// 落 log 後以非零碼退出，交給容器 restart policy 拉起來，不留半死進程
process.on("unhandledRejection", (reason) => {
  console.error("[fatal] unhandledRejection:", reason);
  process.exit(1);
});
process.on("uncaughtException", (err) => {
  console.error("[fatal] uncaughtException:", err);
  process.exit(1);
});

(async () => {
  await app.start();
  console.log("🪞 魔鏡 (magic-mirror) is running (Socket Mode)");
})().catch((err) => {
  console.error("[fatal] app.start failed:", err);
  process.exit(1);
});
