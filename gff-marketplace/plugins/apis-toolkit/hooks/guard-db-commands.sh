#!/usr/bin/env bash
# PreToolUse hook：攔截破壞性 Laravel Artisan DB 命令。
# fail-closed：node 分類器非零 exit（失敗/缺席）→ 一律 deny；
#              分類器 deny/ask JSON → 原樣轉出；allow → node 無輸出、本腳本亦不輸出。
set -uo pipefail

DENY_JSON='{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"guard 無法可靠判定命令（解析器失效或缺席），依 fail-closed 一律拒絕破壞性 DB 命令。"}}'

input="$(cat)"
out="$(printf '%s' "$input" | node "${CLAUDE_PLUGIN_ROOT}/hooks/db-command-classifier.mjs" 2>/dev/null)"
status=$?

if [ "$status" -ne 0 ]; then
  printf '%s' "$DENY_JSON"
  exit 0
fi
[ -n "$out" ] && printf '%s' "$out"
exit 0
