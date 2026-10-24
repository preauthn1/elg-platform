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

// Slack section 的 text.text 上限為 3000 字；留餘裕切在 2900。
const SLACK_TEXT_LIMIT = 2900;

/**
 * 把長 mrkdwn 文字沿換行切成多個 ≤ maxLen 的 section 推進 blocks（單行過長則硬切）。
 * 避免整段塞一塊 section 超過 Slack 3000 字上限而 invalid_blocks。
 */
export function pushMrkdwnSections(blocks, text, maxLen = SLACK_TEXT_LIMIT) {
  let buf = "";
  const flush = () => {
    if (buf.length) {
      blocks.push({ type: "section", text: { type: "mrkdwn", text: buf } });
      buf = "";
    }
  };
  for (let line of String(text).split("\n")) {
    while (line.length > maxLen) {
      flush();
      blocks.push({ type: "section", text: { type: "mrkdwn", text: line.slice(0, maxLen) } });
      line = line.slice(maxLen);
    }
    const candidate = buf ? `${buf}\n${line}` : line;
    if (candidate.length > maxLen) {
      flush();
      buf = line;
    } else {
      buf = candidate;
    }
  }
  flush();
}

// Slack 按鈕 value 上限 2000 字，留餘裕
const BUTTON_VALUE_LIMIT = 1900;

/**
 * 「魔鏡沒這則知識」的回覆版面：說明＋「登記待補」按鈕。
 * 按下 register_gap 會把問題寫進共享記憶（tag 待補）等管理員補內容。
 * @param {string} query 使用者原問題（塞進按鈕 value，登記時取用）
 * @param {string} text  說明文字（agent 的 not_found 回覆）
 */
export function buildGapPromptBlocks(query, text) {
  return [
    { type: "section", text: { type: "mrkdwn", text: `🪞 ${text}` } },
    {
      type: "section",
      text: { type: "mrkdwn", text: "要幫你登記一筆*待補資料*在共享記憶嗎？管理員檢查後會補上這則知識。" },
    },
    {
      type: "actions",
      block_id: "gap_register",
      elements: [
        {
          type: "button",
          text: { type: "plain_text", text: "📝 登記待補", emoji: true },
          style: "primary",
          action_id: "register_gap",
          value: query.slice(0, BUTTON_VALUE_LIMIT),
        },
        {
          type: "button",
          text: { type: "plain_text", text: "不用了", emoji: true },
          action_id: "dismiss_gap",
          value: "dismiss",
        },
      ],
    },
  ];
}

/**
 * 「寫入魔鏡」草稿確認版面：agent 擬好的記憶內容預覽＋確認/取消按鈕。
 * 按 store_confirm 才由 bot 端實際寫入（LLM 沒有寫入工具）。
 * @param {string} draftId 暫存草稿的 id（按鈕 value；草稿本體存 bot 記憶體，避開按鈕 2000 字上限）
 * @param {{content: string, tags: string[], memory_type: string}} draft
 * @param {string} text agent 跟使用者確認的一句話
 */
export function buildStoreDraftBlocks(draftId, draft, text) {
  const blocks = [
    { type: "section", text: { type: "mrkdwn", text: `📝 ${text}` } },
  ];
  pushMrkdwnSections(blocks, `*擬存入的內容*\n\`\`\`\n${draft.content}\n\`\`\``);
  blocks.push({
    type: "context",
    elements: [
      {
        type: "mrkdwn",
        text: `tags: ${draft.tags.map((t) => `\`${t}\``).join(" ") || "（無）"} ｜ type: \`${draft.memory_type}\``,
      },
    ],
  });
  blocks.push({
    type: "actions",
    block_id: "store_draft",
    elements: [
      {
        type: "button",
        text: { type: "plain_text", text: "✅ 寫入魔鏡", emoji: true },
        style: "primary",
        action_id: "store_confirm",
        value: draftId,
      },
      {
        type: "button",
        text: { type: "plain_text", text: "❌ 取消", emoji: true },
        action_id: "store_cancel",
        value: draftId,
      },
    ],
  });
  return blocks;
}

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
    pushMrkdwnSections(blocks, `💡 *綜合回答*\n\n${answer}`);
  } else {
    const tags = (best.tags ?? []).filter((t) => !COMMON_TAGS.has(t));
    const tagsText = tags.length
      ? `\n_${tags.slice(0, 5).map((t) => `\`${t}\``).join(" ")}_`
      : "";
    pushMrkdwnSections(
      blocks,
      `*最符合的結果* ── ${scoreLabel(best.similarity_score)}\n\n${cleanForDisplay(best.content)}${tagsText}`
    );
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
