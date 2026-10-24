#!/usr/bin/env bash
# PreToolUse hook：在密鑰寫入磁碟前就攔截（Write / Edit / MultiEdit；用 Bash 寫入的檔案不經過這裡，只靠 CI 掃描）。
# 這是 scripts/validate.sh 第 4 項「事後掃描」的「事前阻擋」版。
#
# 契約（見 https://example.invalid/docs/en/hooks）：
#   - stdin 收到一包 JSON；「即將寫入」的內容依工具不同：
#       Write     → tool_input.content
#       Edit      → tool_input.new_string
#       MultiEdit → tool_input.edits[].new_string
#     刻意不掃 old_string：那代表正在「移除」既有洩漏的 PAT，掃它會擋下清理動作。
#   - PreToolUse exit 2 = 阻擋工具執行，stderr 餵回給 Claude。
#   - 用 node 解析 JSON（CI 鏡像無 jq、repo 一律用 php/node，與 validate.sh 同調）。
#
# 掃描樣式同 validate.sh：GitLab PAT、Slack token、GCP 私鑰、AWS AKIA；各有專屬佔位符排除規則。
#
# 本機單測（把下方 xxxx 換成任意 20 個英數字即觸發 exit 2；用佔位符本身則 exit 0 放行）：
#   echo '{"tool_name":"Write","tool_input":{"content":"GITLAB=glpat-[REDACTED]"}}' \
#     | bash .claude/hooks/block-pat-write.sh; echo "exit=$?"
# 註：本檔註解刻意只用 xxxx 佔位符，避免絆倒 scripts/validate.sh 第 4 項的祕密掃描。

set -uo pipefail

input="$(cat)"

# 沒有 node 就無法掃描：放行並提醒，最後一道防線是 CI 的 scripts/validate.sh 第 4 項。
# 不改成擋下，否則沒裝 node 的人在本 repo 完全無法寫檔。
if ! command -v node >/dev/null 2>&1; then
  echo "⚠ 找不到 node，寫入前的密鑰攔截沒有執行（CI 的 scripts/validate.sh 仍會掃描）。" >&2
  exit 0
fi

# node 萃取所有「待寫入」字串並偵測 PAT；命中則輸出遮蔽後的樣本，否則空字串。
# 下方 JS 整段包在 bash 單引號裡，JS 內只能用雙引號：混進單引號會讓 bash 提早結束字串，
# node 收到殘缺程式而語法錯誤。
hit="$(printf '%s' "$input" | node -e '
let s = "";
process.stdin.on("data", d => s += d).on("end", () => {
  let j;
  try {
    j = JSON.parse(s);
  } catch (e) {
    // 理論上不會發生（harness 一律送合法 JSON）；萬一無法解析則退而掃描原始輸入，
    // 寧可誤擋也不放過祕密（fail-safe，而非 fail-open）。
    const fb = [
      { re: /glpat-[A-Za-z0-9_-]{20}/, ok: t => /^glpat-[xX]{20}$/.test(t) },
      { re: /xox[bp]-[A-Za-z0-9-]{10,}/, ok: t => /xxxx/i.test(t) },
      { re: new RegExp("-----BEGIN PRIVATE KEY" + "-----"), ok: () => false },
      { re: /AKIA[A-Z0-9]{16}/, ok: t => t === "AKIA[REDACTED]" },
    ];
    for (const { re, ok } of fb) {
      const m = s.match(re);
      if (m && !ok(m[0])) { process.stdout.write(m[0].slice(0, 10) + "…" + m[0].slice(-4)); break; }
    }
    process.exit(0);
  }
  const ti = j.tool_input || {};
  const texts = [];
  if (typeof ti.content === "string") texts.push(ti.content);
  if (typeof ti.new_string === "string") texts.push(ti.new_string);
  if (Array.isArray(ti.edits)) for (const e of ti.edits) {
    if (e && typeof e.new_string === "string") texts.push(e.new_string);
  }
  const PATTERNS = [
    { re: /glpat-[A-Za-z0-9_-]{20}/g, ok: t => /^glpat-[xX]{20}$/.test(t) },
    { re: /xox[bp]-[A-Za-z0-9-]{10,}/g, ok: t => /xxxx/i.test(t) },
    { re: new RegExp("-----BEGIN PRIVATE KEY" + "-----", "g"), ok: () => false },
    { re: /AKIA[A-Z0-9]{16}/g, ok: t => t === "AKIA[REDACTED]" },
  ];
  for (const { re, ok } of PATTERNS) {
    for (const t of texts) {
      re.lastIndex = 0;
      let m;
      while ((m = re.exec(t)) !== null) {
        const tok = m[0];
        if (ok(tok)) continue;
        process.stdout.write(tok.slice(0, 10) + "…" + tok.slice(-4));
        process.exit(0);
      }
    }
  }
  process.exit(0);
});
')"
rc=$?

# node 本身出錯（語法錯誤、執行期例外）時擋下，不默默放行。
if [ "$rc" -ne 0 ]; then
  echo "✘ 已阻擋寫入：密鑰攔截 hook 執行失敗（node exit ${rc}），無法確認內容安全。請修正 .claude/hooks/block-pat-write.sh。" >&2
  exit 2
fi

if [ -n "$hit" ]; then
  {
    echo "✘ 已阻擋寫入：偵測到疑似密鑰（${hit}）。掃描範圍：GitLab PAT、Slack token、GCP 私鑰、AWS key。"
    echo "祕密絕不可進 repo（見 CONTRIBUTING 祕密外洩規則 / scripts/validate.sh 第 4 項）。"
    echo "正確做法：用環境變數或 secrets 檔注入，勿把 token 硬寫進檔案。"
    echo "若這是文件中的範例，請改用含 xxxx 的佔位符（例 glpat-[REDACTED]）。"
  } >&2
  exit 2
fi

exit 0
