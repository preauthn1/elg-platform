import { appendFile, mkdir } from "node:fs/promises";
import { dirname } from "node:path";
import { fileURLToPath } from "node:url";

const DEFAULT_LOG_PATH = fileURLToPath(new URL("../logs/questions.log", import.meta.url));

function formatTs(d) {
  const p = (n) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ` +
         `${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}

/**
 * 追加一行提問紀錄：[YYYY-MM-DD HH:mm:ss] <userName>: <message>
 * 多行 message 壓成單行；目錄不存在自動建立；任何失敗只 console.error，永不 throw。
 */
export async function logQuery(userName, message, { logPath = DEFAULT_LOG_PATH, now = new Date() } = {}) {
  try {
    const flat = String(message).replace(/\s*\n+\s*/g, " ").trim();
    await mkdir(dirname(logPath), { recursive: true });
    await appendFile(logPath, `[${formatTs(now)}] ${userName}: ${flat}\n`);
  } catch (err) {
    console.error("[queryLog] append failed:", err.message);
  }
}
