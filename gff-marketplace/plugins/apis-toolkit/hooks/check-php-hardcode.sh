#!/usr/bin/env bash
# PostToolUse hook：掃描本次寫入 PHP 內容的 hardcode 樣態（URL/IP/密鑰/env()/白標名），即「no-hardcode 規則」。
# fail-open：node 掃描器非零 exit（失敗/缺席）→ 靜默放行；
#            這是 lint 回饋（decision:block 讓 Claude 當場修正），非破壞性防護，
#            與 guard-db-commands.sh 的 fail-closed 語意相反，屬刻意設計。
set -uo pipefail

input="$(cat)"
out="$(printf '%s' "$input" | node "${CLAUDE_PLUGIN_ROOT}/hooks/php-hardcode-scanner.mjs" 2>/dev/null)"
status=$?

[ "$status" -ne 0 ] && exit 0
[ -n "$out" ] && printf '%s' "$out"
exit 0
