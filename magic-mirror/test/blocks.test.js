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

test("答案超過 Slack 3000 字上限：切成多個 ≤3000 的 section，內容不截斷", () => {
  const longAnswer = "代理商資料表明細行。\n".repeat(400) + "尾端標記_ZZZ";
  const blocks = buildBlocks("apis 代理商有哪些表", results, { answer: longAnswer });
  const mrkdwnSections = blocks.filter(
    (b) => b.type === "section" && b.text?.type === "mrkdwn"
  );
  for (const s of mrkdwnSections) {
    assert.ok(
      s.text.text.length <= 3000,
      `section text 長度 ${s.text.text.length} > 3000（Slack 上限）`
    );
  }
  // 內容不可被丟掉：尾端標記仍在
  assert.ok(
    mrkdwnSections.map((s) => s.text.text).join("").includes("尾端標記_ZZZ"),
    "長答案被截斷、尾端內容遺失"
  );
});

test("buildGapPromptBlocks：說明＋登記/不用了按鈕，value 帶原問題", async () => {
  const { buildGapPromptBlocks } = await import("../src/blocks.js");
  const blocks = buildGapPromptBlocks("XX 系統的密碼在哪", "魔鏡目前沒有這則知識。");
  assert.ok(JSON.stringify(blocks).includes("待補資料"));
  const actions = blocks.find((b) => b.type === "actions");
  assert.equal(actions.block_id, "gap_register");
  assert.deepEqual(actions.elements.map((e) => e.action_id), ["register_gap", "dismiss_gap"]);
  assert.equal(actions.elements[0].value, "XX 系統的密碼在哪");
});

test("buildGapPromptBlocks：超長問題截到按鈕 value 上限", async () => {
  const { buildGapPromptBlocks } = await import("../src/blocks.js");
  const blocks = buildGapPromptBlocks("長".repeat(3000), "t");
  const actions = blocks.find((b) => b.type === "actions");
  assert.equal(actions.elements[0].value.length, 1900);
});

test("buildStoreDraftBlocks：內容預覽＋tags/type context＋確認/取消按鈕帶 draftId", async () => {
  const { buildStoreDraftBlocks } = await import("../src/blocks.js");
  const draft = { content: "標題？\n\n內文\n---\n出處：frank 分享", tags: ["IT", "帳號申請"], memory_type: "reference" };
  const blocks = buildStoreDraftBlocks("draft-123", draft, "確認存這筆？");
  assert.ok(JSON.stringify(blocks).includes("擬存入的內容"));
  const ctx = blocks.find((b) => b.type === "context");
  assert.ok(ctx.elements[0].text.includes("`IT`"));
  assert.ok(ctx.elements[0].text.includes("`reference`"));
  const actions = blocks.find((b) => b.type === "actions");
  assert.equal(actions.block_id, "store_draft");
  assert.deepEqual(actions.elements.map((e) => [e.action_id, e.value]), [
    ["store_confirm", "draft-123"],
    ["store_cancel", "draft-123"],
  ]);
});
