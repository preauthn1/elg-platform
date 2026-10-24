#!/usr/bin/env bash
# PostToolUse hook：編輯 plugin.json / marketplace.json / .mcp.json / hooks.json / SKILL.md / *.metadata.yaml、
# plugins/<plugin>/bin/ 底下的 .js（MCP 包裝程式）、plugins/<plugin>/skills/<skill>/scripts/ 底下的檔案
# （skill 附帶的驗證器與夾具）或 docs/skill-cheatsheet.html（Skill 速查表）後，
# 立即跑 marketplace 結構驗證（scripts/validate.sh），把錯誤即時餵回給 Claude。
#
# 由 .claude/settings.json 的 PostToolUse(Write|Edit|MultiEdit) 觸發。
# 契約（見 https://example.invalid/docs/en/hooks）：
#   - 透過 stdin 收到一包 JSON，被編輯的檔案路徑在 .tool_input.file_path
#   - PostToolUse 無法 block（工具已執行完）；exit 2 + stderr 會被當作回饋餵回給 Claude
#   - 用 node 解析 JSON：CI 鏡像無 jq、repo 一律用 php/node 解 JSON（與 validate.sh 同調）
#
# 只在改到「會影響 plugin 載入」的檔案時才驗證，其餘編輯直接放行。
# validate.sh 本身掃全 repo，故 file_path 僅作為「要不要跑」的過濾條件。
#
# 本機單測：
#   echo '{"tool_input":{"file_path":"plugins/op-toolkit/.claude-plugin/plugin.json"}}' \
#     | bash .claude/hooks/validate-on-edit.sh; echo "exit=$?"

set -uo pipefail

ROOT="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"

# 從 stdin 取被編輯的檔案路徑（node 解析；解析失敗回空字串）
input="$(cat)"
file_path="$(printf '%s' "$input" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{try{process.stdout.write(((JSON.parse(s).tool_input)||{}).file_path||"")}catch(e){process.stdout.write("")}})' 2>/dev/null)"

base="$(basename "$file_path" 2>/dev/null || true)"

# 取 repo 內的相對路徑來比對位置：路徑裡有 /plugins/ 或 /docs/ 就從最後一個算起，其餘照原樣
case "$file_path" in
  */plugins/*) rel="plugins/${file_path##*/plugins/}" ;;
  */docs/*)    rel="docs/${file_path##*/docs/}" ;;
  *)           rel="$file_path" ;;
esac

case "$base" in
  plugin.json|marketplace.json|.mcp.json|hooks.json|SKILL.md|*.metadata.yaml)
    : # 命中目標檔 → 往下驗證
    ;;
  *)
    case "$rel" in
      plugins/*/bin/*.js)
        # 只認 plugins/<plugin>/bin/<檔>.js；case 的 * 會跨過 /，所以另外確認 plugin 名與檔名都不含 /
        p="${rel#plugins/}"; p="${p%%/bin/*}"; f="${rel#plugins/"$p"/bin/}"
        case "$p/$f" in
          */*/*) exit 0 ;;
        esac
        ;;
      docs/skill-cheatsheet.html)
        :
        ;;
      plugins/*/skills/*/scripts/*)
        : # skill 附帶的驗證器與夾具（validate.sh 第 10 項會跑它們的測試）
        ;;
      *)
        # 非目標檔且路徑可解析 → 放行；路徑解析不到（base 為空）→ 保守起見仍驗證
        [ -n "$base" ] && exit 0
        ;;
    esac
    ;;
esac

out="$(cd "$ROOT" && bash scripts/validate.sh 2>&1)"
status=$?

if [ "$status" -eq 0 ]; then
  warnings="$(printf '%s\n' "$out" | grep -F '⚠' | grep -vF '全部通過')"
  if [ -z "$warnings" ]; then
    # 成功：訊息只進 debug log（exit 0 的 stdout 不會洗對話版面）
    echo "✔ marketplace 結構驗證通過（觸發檔：${base:-全量}）"
    exit 0
  fi
  # 通過但有警告：exit 0 的輸出 Claude 看不到，改走 exit 2 + stderr 把警告送回去
  {
    echo "⚠ marketplace 結構驗證通過，但有要跟著改的地方（剛編輯：${file_path:-未知檔案}）"
    printf '%s\n' "$warnings"
    echo "這是提醒不是錯誤：同一個 MR 內處理，或向使用者說明為什麼不用改。"
  } >&2
  exit 2
fi

# 失敗：PostToolUse 不能 block，改以 exit 2 + stderr 把錯誤餵回給 Claude
{
  echo "✘ marketplace 結構驗證未通過（剛編輯：${file_path:-未知檔案}）"
  echo "----- scripts/validate.sh 輸出 -----"
  printf '%s\n' "$out"
  echo "------------------------------------"
  echo "請依上面錯誤修正後再繼續（此為自動結構驗證，非人工指示）。"
} >&2
exit 2
