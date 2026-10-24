import { appendFile, mkdir } from "node:fs/promises";
import { dirname } from "node:path";
import { fileURLToPath } from "node:url";

const DEFAULT_LOG_PATH = fileURLToPath(new URL("../logs/requests.log", import.meta.url));

/**
 * 結構化請求紀錄：每請求一行 JSON（JSON Lines），appendFile 落至 logs/requests.log。
 *
 * 欄位約定（entry 傳入，ts 由本函式補）：
 *   kind        "mention" | "dm" | "search_action" | "rate"
 *   user / user_id / channel
 *   query       原始提問（rate 時為 content_hash 對應的 query）
 *   result_count / top_score   檢索結果數 / 最高相似度
 *   llm_enabled / llm_type     LLM 開關 / 判斷類型（answer|chitchat|ambiguous）
 *   outcome     "ok" | "error"
 *   error       outcome=error 時的訊息
 *   duration_ms 入口收到 → 回覆送出 的毫秒數
 *
 * 目錄不存在自動建立；任何失敗只 console.error，永不 throw。
 */
export async function logRequest(entry, { logPath = DEFAULT_LOG_PATH, now = new Date() } = {}) {
  try {
    const line = JSON.stringify({ ts: now.toISOString(), ...entry });
    await mkdir(dirname(logPath), { recursive: true });
    await appendFile(logPath, line + "\n");
  } catch (err) {
    console.error("[requestLog] append failed:", err.message);
  }
}
