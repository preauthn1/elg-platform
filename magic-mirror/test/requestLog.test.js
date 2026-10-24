import { test } from "node:test";
import assert from "node:assert/strict";
import { mkdtemp, readFile, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { logRequest } from "../src/requestLog.js";

const FIXED = new Date("2026-07-20T10:30:45.000Z");

async function tmpLogPath() {
  const dir = await mkdtemp(join(tmpdir(), "rlog-"));
  return join(dir, "logs", "requests.log"); // 目錄不存在 → 測自動建立
}

test("logRequest appends one JSON line with ts and creates dir", async () => {
  const logPath = await tmpLogPath();
  await logRequest(
    { kind: "dm", user: "Rhys", query: "單一錢包是什麼", result_count: 3, outcome: "ok", duration_ms: 120 },
    { logPath, now: FIXED }
  );
  const lines = (await readFile(logPath, "utf8")).trim().split("\n");
  assert.equal(lines.length, 1);
  const entry = JSON.parse(lines[0]);
  assert.equal(entry.ts, "2026-07-20T10:30:45.000Z");
  assert.equal(entry.kind, "dm");
  assert.equal(entry.user, "Rhys");
  assert.equal(entry.result_count, 3);
  assert.equal(entry.outcome, "ok");
});

test("logRequest appends, does not overwrite", async () => {
  const logPath = await tmpLogPath();
  await logRequest({ kind: "mention", outcome: "ok" }, { logPath, now: FIXED });
  await logRequest({ kind: "rate", outcome: "error", error: "HTTP 500" }, { logPath, now: FIXED });
  const lines = (await readFile(logPath, "utf8")).trim().split("\n");
  assert.equal(lines.length, 2);
  assert.equal(JSON.parse(lines[1]).error, "HTTP 500");
});

test("logRequest keeps multiline query parseable (JSON escapes newline)", async () => {
  const logPath = await tmpLogPath();
  await logRequest({ kind: "dm", query: "第一行\n第二行" }, { logPath, now: FIXED });
  const lines = (await readFile(logPath, "utf8")).trim().split("\n");
  assert.equal(lines.length, 1); // 換行被 JSON escape，不會破壞一行一筆
  assert.equal(JSON.parse(lines[0]).query, "第一行\n第二行");
});

test("logRequest never throws on write failure", async () => {
  // blocker 是一般檔案 → 以它為目錄的路徑必然無效，mkdir/append 會失敗
  const dir = await mkdtemp(join(tmpdir(), "rlog-"));
  const blocker = join(dir, "blocker");
  await writeFile(blocker, "");
  await assert.doesNotReject(() =>
    logRequest({ kind: "dm" }, { logPath: join(blocker, "requests.log"), now: FIXED })
  );
});
