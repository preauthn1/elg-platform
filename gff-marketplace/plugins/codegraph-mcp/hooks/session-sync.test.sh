#!/usr/bin/env bash
# 整合測試：session-sync.sh 的 fail-open 與「對話裡零輸出」行為。
# 以偽 codegraph 取代真的 CodeGraph，不碰任何真實索引與 npm 快取。
set -uo pipefail
HOOKS_DIR="$(cd "$(dirname "$0")" && pwd)"
export CLAUDE_PLUGIN_ROOT="$(cd "$HOOKS_DIR/.." && pwd)" # → plugins/codegraph-mcp
HOOK="$HOOKS_DIR/session-sync.sh"
PINNED="$(grep -o '@colbymchenry/codegraph@[0-9][0-9A-Za-z.+-]*' "$CLAUDE_PLUGIN_ROOT/.mcp.json" | head -n 1)"
VERSION="${PINNED##*@}"
fail=0
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
export HOME="$tmp/home" # 不讀真實的 ~/.npm 與 ~/.nvm
export npm_config_cache="$tmp/empty-cache"
mkdir -p "$HOME" "$npm_config_cache"

# 偽 codegraph：--version 回 FAKE_VERSION，並把當下的 CODEGRAPH_NO_DOWNLOAD 與 CODEGRAPH_TELEMETRY 寫進 FAKE_MARK 指的檔案
# （用來確認「有沒有被拿去查版本」與「查版本時是否已禁止下載」）；
# 其餘把每個參數各印一行（會進 log），exit code 由 FAKE_EXIT 決定。
make_stub() {
  mkdir -p "$(dirname "$1")"
  cat > "$1" <<'EOF'
#!/bin/sh
if [ "$1" = "--version" ]; then
  [ -n "${FAKE_MARK:-}" ] && echo "${CODEGRAPH_NO_DOWNLOAD:-unset}/${CODEGRAPH_TELEMETRY:-unset}" > "$FAKE_MARK"
  echo "${FAKE_VERSION:-0.0.0}"
  exit 0
fi
echo "STUB: $0"
for a in "$@"; do echo "ARG: <$a>"; done
echo "NO_DOWNLOAD: ${CODEGRAPH_NO_DOWNLOAD:-unset}"
echo "TELEMETRY: ${CODEGRAPH_TELEMETRY:-unset}"
echo "to-stderr" >&2
exit "${FAKE_EXIT:-0}"
EOF
  chmod +x "$1"
}
global="$tmp/global-bin"
make_stub "$global/codegraph"

check() { [ "$1" = "$2" ] || { echo "FAIL $3（預期 [$2]，實際 [$1]）"; fail=1; }; }
has() { grep -qF -- "$1" "$log" || { echo "FAIL $2：$(cat "$log")"; fail=1; }; }
# hook 的 stdout+stderr 寫進檔案再量大小：連一個換行都不能有。
run() { "$@" bash "$HOOK" > "$tmp/out" 2>&1; status=$?; out_bytes="$(wc -c < "$tmp/out" | tr -d ' ')"; }

# 1. 沒建索引的專案：不做事、不留 log。
plain="$tmp/plain"; mkdir -p "$plain"
run env CLAUDE_PROJECT_DIR="$plain" FAKE_VERSION="$VERSION" PATH="$global:$PATH"
check "$status" 0 "無索引 exit"
check "$out_bytes" 0 "無索引 輸出位元組"
[ ! -e "$plain/.codegraph" ] || { echo "FAIL 無索引卻建立了 .codegraph"; fail=1; }

# 2. 全域安裝的版本與 .mcp.json 相同：對專案目錄跑 sync（路徑含空白），輸出全進 log。
proj="$tmp/my proj"; mkdir -p "$proj/.codegraph"
log="$proj/.codegraph/session-sync.log"
run env CLAUDE_PROJECT_DIR="$proj" FAKE_VERSION="$VERSION" FAKE_MARK="$tmp/mark" PATH="$global:$PATH"
check "$status" 0 "同步成功 exit"
check "$out_bytes" 0 "同步成功 輸出位元組"
has "STUB: $global/codegraph" "沒用全域安裝的 codegraph"
has "ARG: <sync>" "沒跑 sync"
has "ARG: <$proj>" "專案路徑沒有完整傳成一個參數"
has "NO_DOWNLOAD: 1" "沒帶 CODEGRAPH_NO_DOWNLOAD=1"
has "TELEMETRY: 0" "沒帶 CODEGRAPH_TELEMETRY=0"
check "$(cat "$tmp/mark" 2>/dev/null)" "1/0" "查版本時的 CODEGRAPH_NO_DOWNLOAD/CODEGRAPH_TELEMETRY"
has "to-stderr" "stderr 沒進 log"
has "[exit 0]" "log 沒記 exit code"

# 3. 同步失敗：仍 exit 0、對話零輸出，失敗記在 log；log 只留最近一次。
echo "OLD-RUN" >> "$log"
run env CLAUDE_PROJECT_DIR="$proj" FAKE_VERSION="$VERSION" FAKE_EXIT=7 PATH="$global:$PATH"
check "$status" 0 "同步失敗 exit"
check "$out_bytes" 0 "同步失敗 輸出位元組"
has "[exit 7]" "log 沒記失敗的 exit code"
grep -q 'OLD-RUN' "$log" && { echo "FAIL log 沒有覆寫"; fail=1; }

# 4. 全域安裝的版本不同：改用 npx 快取裡版本相同的那一份。
cache="$tmp/cache"
nm="$cache/_npx/abc123/node_modules"
mkdir -p "$nm/@colbymchenry/codegraph"
printf '{\n  "name": "@colbymchenry/codegraph",\n  "version": "%s"\n}\n' "$VERSION" > "$nm/@colbymchenry/codegraph/package.json"
make_stub "$nm/.bin/codegraph"
run env CLAUDE_PROJECT_DIR="$proj" FAKE_VERSION="0.0.1" npm_config_cache="$cache" PATH="$global:$PATH"
check "$out_bytes" 0 "npx 快取 輸出位元組"
has "STUB: $nm/.bin/codegraph" "全域版本不同時沒改用 npx 快取"
has "[exit 0]" "npx 快取那份沒跑完"

# 5. PATH 上唯一的 codegraph 在專案目錄內（版本相同）：連查版本都不執行它，
#    當成本機沒有安裝，寫明原因、exit 0。
local_bin="$proj/node_modules/.bin"
make_stub "$local_bin/codegraph"
rm -f "$tmp/mark"
run env CLAUDE_PROJECT_DIR="$proj" FAKE_VERSION="$VERSION" FAKE_MARK="$tmp/mark" PATH="$local_bin:$PATH"
check "$status" 0 "專案內的 codegraph exit"
check "$out_bytes" 0 "專案內的 codegraph 輸出位元組"
has "找不到已安裝的 CodeGraph $VERSION" "log 沒寫找不到相同版本"
[ ! -e "$tmp/mark" ] || { echo "FAIL 執行了專案目錄內的 codegraph 來查版本"; fail=1; }
grep -q 'ARG:' "$log" && { echo "FAIL 執行了專案目錄內的 codegraph"; fail=1; }
#    npm 快取指進專案目錄時同樣不採用。
pnm="$proj/.npm/_npx/abc123/node_modules"
mkdir -p "$pnm/@colbymchenry/codegraph"
cp "$nm/@colbymchenry/codegraph/package.json" "$pnm/@colbymchenry/codegraph/package.json"
make_stub "$pnm/.bin/codegraph"
run env CLAUDE_PROJECT_DIR="$proj" FAKE_VERSION="0.0.1" npm_config_cache="$proj/.npm" PATH="$global:$PATH"
has "找不到已安裝的 CodeGraph $VERSION" "採用了專案目錄內的 npm 快取"
grep -q 'ARG:' "$log" && { echo "FAIL 執行了專案目錄內 npm 快取的 codegraph"; fail=1; }

# 6. log 是 symlink：不能動到它指向的檔案。
echo "precious" > "$tmp/target"
rm -f "$log"; ln -s "$tmp/target" "$log"
run env CLAUDE_PROJECT_DIR="$proj" FAKE_VERSION="$VERSION" PATH="$global:$PATH"
check "$(cat "$tmp/target")" "precious" "symlink 指向的檔案內容"
[ ! -L "$log" ] || { echo "FAIL log 仍是 symlink"; fail=1; }
has "[exit 0]" "symlink 移除後沒有正常寫 log"
#    連結移除不掉（目錄唯讀）時也不能動到目標。以 root 執行時唯讀擋不住，略過。
if [ "$(id -u)" -ne 0 ]; then
  rm -f "$log"; ln -s "$tmp/target" "$log"
  chmod 555 "$proj/.codegraph"
  run env CLAUDE_PROJECT_DIR="$proj" FAKE_VERSION="$VERSION" PATH="$global:$PATH"
  chmod 755 "$proj/.codegraph"
  check "$status" 0 "symlink 移除不掉 exit"
  check "$(cat "$tmp/target")" "precious" "symlink 移除不掉時目標檔內容"
  rm -f "$log"
fi

# 7. 索引被另一個程序鎖住：等不到鎖釋放時，log 要寫明這次同步可能沒有執行。
echo "$$" > "$proj/.codegraph/codegraph.lock"
run env CLAUDE_PROJECT_DIR="$proj" FAKE_VERSION="$VERSION" PATH="$global:$PATH"
rm -f "$proj/.codegraph/codegraph.lock"
check "$status" 0 "索引被鎖住 exit"
check "$out_bytes" 0 "索引被鎖住 輸出位元組"
has "索引被另一個程序鎖住（$proj/.codegraph/codegraph.lock）" "log 沒寫索引被鎖住"

# 8. git worktree 自己沒索引：主 checkout 有索引才在背景替它建一份，log 放在 worktree 的 git 目錄。
repo="$tmp/repo"; wt="$tmp/wt one"
git init -q "$repo"
git -C "$repo" -c user.name=t -c user.email=user@example.invalid commit -q --allow-empty -m init
git -C "$repo" worktree add -q "$wt" -b wt-branch
wt_git="$(git -C "$wt" rev-parse --absolute-git-dir)"
log="$wt_git/codegraph-init.log"
#    主 checkout 沒建索引：worktree 也不做事。
run env CLAUDE_PROJECT_DIR="$wt" FAKE_VERSION="$VERSION" PATH="$global:$PATH"
check "$out_bytes" 0 "主 checkout 無索引 輸出位元組"
[ ! -e "$log" ] || { echo "FAIL 主 checkout 沒索引卻替 worktree 建索引"; fail=1; }
#    主 checkout 有索引，但上一次啟動的建索引還在跑：不重複啟動。
mkdir -p "$repo/.codegraph"
echo "$$" > "$wt_git/codegraph-init.pid"
run env CLAUDE_PROJECT_DIR="$wt" FAKE_VERSION="$VERSION" PATH="$global:$PATH"
[ ! -e "$log" ] || { echo "FAIL 建索引還在跑卻又啟動一次"; fail=1; }
rm -f "$wt_git/codegraph-init.pid"
#    主 checkout 有索引：對 worktree 跑 init -y，hook 不等它跑完。
run env CLAUDE_PROJECT_DIR="$wt" FAKE_VERSION="$VERSION" PATH="$global:$PATH"
check "$status" 0 "worktree 建索引 exit"
check "$out_bytes" 0 "worktree 建索引 輸出位元組"
for _ in 1 2 3 4 5 6 7 8 9 10; do [ -e "$wt_git/codegraph-init.pid" ] || break; sleep 0.5; done
[ ! -e "$wt_git/codegraph-init.pid" ] || { echo "FAIL 背景建索引結束後沒有清掉 pid 檔"; fail=1; }
has "ARG: <init>" "沒跑 init"
has "ARG: <-y>" "init 沒帶 -y"
has "ARG: <$wt>" "worktree 路徑沒有完整傳成一個參數"
has "NO_DOWNLOAD: 1" "建索引沒帶 CODEGRAPH_NO_DOWNLOAD=1"
has "[exit 0]" "建索引的 log 沒記 exit code"

# 寫法檢查：變數後面緊接中文或全形符號時要加大括號。
# macOS 內建的 bash 3.2 會把後面的位元組當成變數名稱的一部分，印出亂碼。
if LC_ALL=C grep -nE '\$[A-Za-z_][A-Za-z0-9_]*[^ -~]' "$HOOK"; then
  echo "FAIL 上列變數後面緊接非 ASCII 字元，請改成加大括號的寫法"; fail=1
fi

if [ "$fail" -eq 0 ]; then echo "ALL PASS"; else exit 1; fi
