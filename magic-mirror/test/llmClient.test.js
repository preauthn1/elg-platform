import { test } from "node:test";
import assert from "node:assert/strict";
import { EventEmitter } from "node:events";
import { agentAnswer, runClaudeAgent, stripFrontmatter } from "../src/llmClient.js";

/** 假 child process：emit 給定的 stdout 行後 close。 */
function fakeSpawn(lines, { exitCode = 0 } = {}) {
  return () => {
    const child = new EventEmitter();
    child.stdout = new EventEmitter();
    child.stderr = new EventEmitter();
    child.kill = () => {};
    setImmediate(() => {
      child.stdout.emit("data", lines.map((l) => JSON.stringify(l)).join("\n") + "\n");
      child.emit("close", exitCode);
    });
    return child;
  };
}

// ── runClaudeAgent（stream-json 解析）──
test("runClaudeAgent resolves final result and emits events in order", async () => {
  const events = [];
  const lines = [
    { type: "system", subtype: "init" },
    { type: "assistant", message: { content: [{ type: "tool_use", name: "mcp__memory__memory_search", input: { query: "q" } }] } },
    { type: "result", is_error: false, result: "最終回答" },
  ];
  const out = await runClaudeAgent("q", {
    spawnFn: fakeSpawn(lines),
    onEvent: (ev) => events.push(ev.type),
  });
  assert.equal(out, "最終回答");
  assert.deepEqual(events, ["system", "assistant", "result"]);
});

test("runClaudeAgent rejects when stream has no result event", async () => {
  const lines = [{ type: "system", subtype: "init" }];
  await assert.rejects(
    () => runClaudeAgent("q", { spawnFn: fakeSpawn(lines, { exitCode: 1 }), onEvent: () => {} }),
    /no result event/
  );
});

test("runClaudeAgent rejects on is_error result", async () => {
  const lines = [{ type: "result", is_error: true, result: "boom" }];
  await assert.rejects(() => runClaudeAgent("q", { spawnFn: fakeSpawn(lines), onEvent: () => {} }));
});

test("runClaudeAgent skips non-JSON lines and onEvent errors without failing", async () => {
  const child = new EventEmitter();
  child.stdout = new EventEmitter();
  child.stderr = new EventEmitter();
  child.kill = () => {};
  const spawnFn = () => {
    setImmediate(() => {
      child.stdout.emit("data", 'not json\n{"type":"assistant","message":{"content":[]}}\n');
      child.stdout.emit("data", JSON.stringify({ type: "result", is_error: false, result: "ok" }) + "\n");
      child.emit("close", 0);
    });
    return child;
  };
  const out = await runClaudeAgent("q", { spawnFn, onEvent: () => { throw new Error("logger broke"); } });
  assert.equal(out, "ok");
});

// ── agentAnswer（agent 問答：自主查詢＋引用 content_hash）──
const HASH = "a".repeat(64);
const noSkill = async () => "";

test("agentAnswer parses answer with valid 64-hex sources", async () => {
  const run = async () => JSON.stringify({ type: "answer", text: " 回答 ", sources: [HASH] });
  assert.deepEqual(await agentAnswer("q", { run, skill: noSkill }), {
    type: "answer",
    text: "回答",
    sources: [HASH],
    draft: null,
  });
});

test("agentAnswer tolerates code-fenced JSON", async () => {
  const run = async () => '```json\n{"type":"chitchat","text":"嗨","sources":[]}\n```';
  assert.deepEqual(await agentAnswer("q", { run, skill: noSkill }), {
    type: "chitchat", text: "嗨", sources: [], draft: null,
  });
});

test("agentAnswer injects userName into prompt (store 出處用)", async () => {
  let prompt;
  const run = async (p) => {
    prompt = p;
    return JSON.stringify({ type: "chitchat", text: "hi", sources: [] });
  };
  await agentAnswer("q", { userName: "frank", run, skill: noSkill });
  assert.ok(prompt.includes("提問者：frank"));
});

test("agentAnswer parses store draft and sanitizes tags/type", async () => {
  const run = async () =>
    JSON.stringify({
      type: "store",
      text: "確認存這筆？",
      sources: [],
      draft: { content: " 內容 \n---\n出處：frank 分享 ", tags: ["IT", "", 5, "帳號申請"], memory_type: "weird" },
    });
  const res = await agentAnswer("q", { run, skill: noSkill });
  assert.equal(res.type, "store");
  assert.deepEqual(res.draft, {
    content: "內容 \n---\n出處：frank 分享",
    tags: ["IT", "帳號申請"],
    memory_type: "note",
  });
});

test("agentAnswer throws when store draft has no content", async () => {
  const run = async () => JSON.stringify({ type: "store", text: "t", sources: [], draft: { tags: ["a"] } });
  await assert.rejects(() => agentAnswer("q", { run, skill: noSkill }), /draft invalid/);
});

test("agentAnswer drops malformed sources (non-hex / wrong length / non-string)", async () => {
  const run = async () =>
    JSON.stringify({ type: "answer", text: "t", sources: [HASH, "short", "../etc", 123, `  ${HASH}  `] });
  const res = await agentAnswer("q", { run, skill: noSkill });
  assert.deepEqual(res.sources, [HASH, HASH]);
});

test("agentAnswer passes skill body as systemAppend", async () => {
  let seen;
  const run = async (_prompt, { systemAppend }) => {
    seen = systemAppend;
    return JSON.stringify({ type: "chitchat", text: "hi", sources: [] });
  };
  await agentAnswer("q", { run, skill: async () => "SOP 內容" });
  assert.equal(seen, "SOP 內容");
});

test("agentAnswer throws on missing text (caller falls back to plain search)", async () => {
  const run = async () => JSON.stringify({ type: "answer", sources: [] });
  await assert.rejects(() => agentAnswer("q", { run, skill: noSkill }));
});

test("agentAnswer throws on bad JSON", async () => {
  const run = async () => "not json";
  await assert.rejects(() => agentAnswer("q", { run, skill: noSkill }));
});

test("agentAnswer throws when run throws (timeout / non-zero exit)", async () => {
  const run = async () => { throw new Error("timeout"); };
  await assert.rejects(() => agentAnswer("q", { run, skill: noSkill }));
});

test("agentAnswer coerces unknown type to answer", async () => {
  const run = async () => JSON.stringify({ type: "weird", text: "t", sources: [] });
  assert.equal((await agentAnswer("q", { run, skill: noSkill })).type, "answer");
});

// ── stripFrontmatter ──
test("stripFrontmatter removes YAML frontmatter and trims", async () => {
  assert.equal(stripFrontmatter("---\nname: x\ndescription: y\n---\n\n# 標題\n內文"), "# 標題\n內文");
});

test("stripFrontmatter keeps content without frontmatter as-is", async () => {
  assert.equal(stripFrontmatter("# 標題\n內文"), "# 標題\n內文");
});

test("agentAnswer parses not_found with empty sources", async () => {
  const run = async () => JSON.stringify({ type: "not_found", text: "魔鏡目前沒有這則知識。", sources: [] });
  assert.deepEqual(await agentAnswer("q", { run, skill: noSkill }), {
    type: "not_found",
    text: "魔鏡目前沒有這則知識。",
    sources: [],
    draft: null,
  });
});
