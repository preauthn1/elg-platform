#!/usr/bin/env bash
# 整合測試：check-php-hardcode.sh wrapper 的 fail-open 行為與 block 輸出。
set -uo pipefail
HOOKS_DIR="$(cd "$(dirname "$0")" && pwd)"
export CLAUDE_PLUGIN_ROOT="$(cd "$HOOKS_DIR/.." && pwd)" # → plugins/apis-toolkit
GUARD="$HOOKS_DIR/check-php-hardcode.sh"
fail=0
run() { printf '%s' "$1" | bash "$GUARD"; }
assert_block() { run "$1" | grep -q '"decision":"block"' || { echo "FAIL block: $2"; fail=1; }; }
assert_silent() { [ -z "$(run "$1")" ] || { echo "FAIL silent(非空): $2"; fail=1; }; }

# Write 含 hardcode URL → block
assert_block '{"tool_name":"Write","tool_input":{"file_path":"/var/www/APIs/app/Services/FooService.php","content":"$url = '\''https://example.invalid/pay'\'';"}}' 'Write URL'
# Edit new_string 含 env() → block
assert_block '{"tool_name":"Edit","tool_input":{"file_path":"/var/www/APIs/app/Http/Controllers/Foo.php","old_string":"x","new_string":"$k = env('\''APP_KEY'\'');"}}' 'Edit env()'
# 白標名字面值（名單來自 registry）→ block
assert_block '{"tool_name":"Write","tool_input":{"file_path":"/var/www/APIs/app/Services/Foo.php","content":"if ($site === '\''TPower'\'') {}"}}' '白標名'
# 乾淨內容 → 靜默
assert_silent '{"tool_name":"Write","tool_input":{"file_path":"/var/www/APIs/app/Services/Foo.php","content":"$fee = Common::numMul($a, config('\''payment.fee'\''));"}}' '乾淨內容'
# hardcode-ok 豁免 → 靜默
assert_silent '{"tool_name":"Write","tool_input":{"file_path":"/var/www/APIs/app/Services/Foo.php","content":"$u = '\''https://example.invalid'\''; // hardcode-ok: 廠商固定域名"}}' '豁免註解'
# config/ 路徑 → 靜默
assert_silent '{"tool_name":"Write","tool_input":{"file_path":"/var/www/APIs/config/services.php","content":"$k = env('\''KEY'\'');"}}' 'config 路徑'
# 非 php → 靜默
assert_silent '{"tool_name":"Write","tool_input":{"file_path":"/var/www/APIs/app/foo.md","content":"https://example.invalid"}}' '非 php'
# fail-open：malformed JSON → 靜默放行
assert_silent 'not json at all' 'malformed JSON'
# fail-open：node 缺席模擬（偽 node stub exit 127 置於 PATH 前）→ 靜默放行
stub="$(mktemp -d)"
printf '#!/bin/sh\nexit 127\n' > "$stub/node"
chmod +x "$stub/node"
out="$(printf '%s' '{"tool_name":"Write","tool_input":{"file_path":"/x/app/a.php","content":"https://example.invalid"}}' | PATH="$stub:$PATH" bash "$GUARD")"
[ -z "$out" ] || { echo "FAIL node-missing→silent"; fail=1; }
rm -rf "$stub"

if [ "$fail" -eq 0 ]; then echo "ALL PASS"; else exit 1; fi
