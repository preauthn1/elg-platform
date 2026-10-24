import { test } from "node:test";
import assert from "node:assert/strict";
import { mkdtemp, readFile, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { logQuery } from "../src/queryLog.js";

const FIXED = new Date(2026, 6, 14, 10, 30, 45); // 2026-07-14 10:30:45 本地時區

async function tmpLogPath() {
  const dir = await mkdtemp(join(tmpdir(), "qlog-"));
  return join(dir, "logs", "questions.log"); // 目錄不存在 → 測自動建立
}

test("logQuery appends formatted line and creates dir", async () => {
  const logPath = await tmpLogPath();
  await logQuery("Rhys", "怎麼更換遊戲商 banner", { logPath, now: FIXED });
  const content = await readFile(logPath, "utf8");
  assert.equal(content, "[2026-07-14 10:30:45] Rhys: 怎麼更換遊戲商 banner\n");
});

test("logQuery appends, does not overwrite", async () => {
  const logPath = await tmpLogPath();
  await logQuery("Rhys", "第一句", { logPath, now: FIXED });
  await logQuery("Chelsea", "第二句", { logPath, now: FIXED });
  const lines = (await readFile(logPath, "utf8")).trim().split("\n");
  assert.equal(lines.length, 2);
  assert.match(lines[1], /Chelsea: 第二句$/);
});

test("logQuery flattens multiline message to single line", async () => {
  const logPath = await tmpLogPath();
  await logQuery("Rhys", "第一行\n第二行", { logPath, now: FIXED });
  const content = await readFile(logPath, "utf8");
  assert.equal(content, "[2026-07-14 10:30:45] Rhys: 第一行 第二行\n");
});

test("logQuery never throws on write failure", async () => {
  // blocker 是一般檔案 → 以它為目錄的路徑必然無效，mkdir/append 會失敗
  const dir = await mkdtemp(join(tmpdir(), "qlog-"));
  const blocker = join(dir, "blocker");
  await writeFile(blocker, "");
  await assert.doesNotReject(() =>
    logQuery("Rhys", "hi", { logPath: join(blocker, "questions.log"), now: FIXED })
  );
});
