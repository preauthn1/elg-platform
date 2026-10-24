#!/usr/bin/env bash
# 整合測試：guard-db-commands.sh wrapper 的 fail-closed 行為。
set -uo pipefail
HOOKS_DIR="$(cd "$(dirname "$0")" && pwd)"
export CLAUDE_PLUGIN_ROOT="$(cd "$HOOKS_DIR/.." && pwd)" # → plugins/apis-toolkit
GUARD="$HOOKS_DIR/guard-db-commands.sh"
fail=0
run() { printf '%s' "$1" | bash "$GUARD"; }
assert_deny() { run "$1" | grep -q '"permissionDecision":"deny"' || { echo "FAIL deny: $1"; fail=1; }; }
assert_ask() { run "$1" | grep -q '"permissionDecision":"ask"' || { echo "FAIL ask: $1"; fail=1; }; }
assert_allow() { [ -z "$(run "$1")" ] || { echo "FAIL allow(非空): $1"; fail=1; }; }

assert_deny '{"tool_name":"Bash","tool_input":{"command":"php artisan migrate:fresh"}}'
assert_deny '{"tool_name":"Bash","tool_input":{"command":"php artisan migrate:status; php artisan db:wipe"}}'
assert_ask '{"tool_name":"Bash","tool_input":{"command":"php artisan migrate"}}'
assert_allow '{"tool_name":"Bash","tool_input":{"command":"ls -la"}}'
assert_allow '{"tool_name":"Bash","tool_input":{"command":"php artisan migrate:status"}}'
# fail-closed：malformed JSON → deny
assert_deny 'not json at all'
# fail-closed：缺 command → deny
assert_deny '{"tool_name":"Bash","tool_input":{}}'
# fail-closed：node 缺席模擬（偽 node stub exit 127 置於 PATH 前，保留 coreutils）→ deny
stub="$(mktemp -d)"
printf '#!/bin/sh\nexit 127\n' > "$stub/node"
chmod +x "$stub/node"
out="$(printf '%s' '{"tool_input":{"command":"ls"}}' | PATH="$stub:$PATH" bash "$GUARD")"
echo "$out" | grep -q '"permissionDecision":"deny"' || { echo "FAIL node-missing→deny"; fail=1; }
rm -rf "$stub"

if [ "$fail" -eq 0 ]; then echo "ALL PASS"; else exit 1; fi
