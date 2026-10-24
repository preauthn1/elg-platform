const MCP_SERVER_URL = process.env.MCP_SERVER_URL;
const MCP_AUTH_TOKEN = [REDACTED];
const SEARCH_LIMIT = parseInt(process.env.MEMORY_SEARCH_LIMIT || "5", 10);
const SIMILARITY_THRESHOLD = parseFloat(
  process.env.MEMORY_SIMILARITY_THRESHOLD || "0.5"
);

// MCP_SERVER_URL points at the MCP transport endpoint (…/mcp).
// The REST API lives at the same origin under /api/*.
const REST_BASE = MCP_SERVER_URL.replace(/\/mcp\/?$/, "");

/**
 * Search memory via the REST /api/search endpoint.
 * Returns flat objects: { content_hash, content, similarity_score, tags }.
 */
export async function searchMemory(query) {
  const res = await fetch(`${REST_BASE}/api/search`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-API-Key": MCP_AUTH_TOKEN,
    },
    body: JSON.stringify({
      query,
      n_results: SEARCH_LIMIT,
      similarity_threshold: SIMILARITY_THRESHOLD,
    }),
  });

  if (!res.ok) {
    const detail = await res.text().catch(() => "");
    throw new Error(`Memory search HTTP ${res.status}: ${detail.slice(0, 200)}`);
  }

  const data = await res.json();
  return (data.results ?? []).map((r) => ({
    content_hash: r.memory?.content_hash ?? "",
    content: r.memory?.content ?? "",
    similarity_score: r.similarity_score,
    tags: r.memory?.tags ?? [],
  }));
}

/**
 * 依 content_hash 取回記憶（GET /api/memories/{hash}），映射成與 searchMemory 相同的
 * 扁平物件（similarity_score 為 null）。多筆並行，個別失敗直接略過並留 log
 * （agent 模式引用的 hash 可能失效或打錯，不因一筆壞掉整包）。
 * @param {string[]} hashes
 */
export async function getMemories(hashes) {
  const settled = await Promise.allSettled(
    hashes.map(async (hash) => {
      const res = await fetch(`${REST_BASE}/api/memories/${hash}`, {
        headers: { "X-API-Key": MCP_AUTH_TOKEN },
      });
      if (!res.ok) throw new Error(`Get memory ${hash.slice(0, 8)} HTTP ${res.status}`);
      const m = await res.json();
      return {
        content_hash: m.content_hash ?? hash,
        content: m.content ?? "",
        similarity_score: null,
        tags: m.tags ?? [],
      };
    })
  );
  for (const s of settled) {
    if (s.status === "rejected") console.error("[memory] getMemories:", s.reason.message);
  }
  return settled.filter((s) => s.status === "fulfilled").map((s) => s.value);
}

/**
 * 寫入一筆記憶（POST /api/memories）。目前用於「魔鏡未答 → 登記待補問題」與「使用者寫入草稿」。
 * @param {string} content
 * @param {{tags?: string[], memoryType?: string, metadata?: object, conversationId?: string}} [opts]
 *   conversationId：帶入時 server 會跳過「語意」去重（相似不同筆可並存，完全相同 hash 仍會擋）。
 */
export async function createMemory(
  content,
  { tags = [], memoryType = "observation", metadata = {}, conversationId } = {}
) {
  const res = await fetch(`${REST_BASE}/api/memories`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-API-Key": MCP_AUTH_TOKEN,
    },
    body: JSON.stringify({
      content,
      tags,
      memory_type: memoryType,
      metadata,
      ...(conversationId ? { conversation_id: conversationId } : {}),
    }),
  });

  if (!res.ok) {
    const detail = await res.text().catch(() => "");
    throw new Error(`Create memory HTTP ${res.status}: ${detail.slice(0, 200)}`);
  }

  return res.json();
}

/**
 * Rate a memory via the REST /api/quality/memories/{hash}/rate endpoint.
 * @param {string} contentHash - The memory's content hash
 * @param {-1|0|1} rating      - -1 不相關 / 0 需要補充 / 1 精準或有幫助
 * @param {string} [feedback]  - Optional free-text feedback
 */
export async function rateMemory(contentHash, rating, feedback = "") {
  if (!contentHash) return; // 防呆：無 hash 就不送
  const res = await fetch(`${REST_BASE}/api/quality/memories/${contentHash}/rate`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-API-Key": MCP_AUTH_TOKEN,
    },
    body: JSON.stringify({ rating, feedback }),
  });

  if (!res.ok) {
    const detail = await res.text().catch(() => "");
    throw new Error(`Rate memory HTTP ${res.status}: ${detail.slice(0, 200)}`);
  }

  return res.json();
}

export async function closeClient() {
  // no-op; kept so index.js can call it during shutdown without changes.
}
