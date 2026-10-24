#!/usr/bin/env bash
# SessionStart hook：開 session 時讓當前專案的 CodeGraph 索引跟上。
# - 專案已建索引：做一次增量同步。
# - 專案是 git worktree、自己沒索引、主 checkout 有索引：在背景替這個 worktree 建一份。
# - 其他沒建索引的專案：不做事。
# fail-open：任何失敗只寫進 log，一律 exit 0，不擋開工。
# 不輸出到 stdout／stderr：SessionStart 的 stdout 會被當成內容加進對話。
trap 'exit 0' EXIT
exec >/dev/null 2>&1

project_dir="${CLAUDE_PROJECT_DIR:-}"
[ -n "$project_dir" ] && [ -d "$project_dir" ] || exit 0

if [ -d "$project_dir/.codegraph" ]; then
  mode=sync
  # 每次覆寫，只留最近一次的結果。
  log="$project_dir/.codegraph/session-sync.log"
else
  # 主 checkout 建過索引，代表使用者要在這個 repo 用 CodeGraph；worktree 的代碼是另一個分支，
  # 拿主 checkout 的索引來查會對不上，所以替它建自己的。
  # 判斷方式：專案是 worktree 的根目錄，它的 git 目錄與共用的 git 目錄不同（linked worktree），
  # 而且共用 git 目錄的上一層（主 checkout）有 .codegraph/。
  top="$(git -C "$project_dir" rev-parse --show-toplevel 2>/dev/null)"
  git_dir="$(git -C "$project_dir" rev-parse --absolute-git-dir 2>/dev/null)"
  common_dir="$(cd "$project_dir" 2>/dev/null && cd "$(git rev-parse --git-common-dir 2>/dev/null)" 2>/dev/null && pwd -P)"
  [ -n "$top" ] && [ -n "$git_dir" ] && [ -n "$common_dir" ] || exit 0
  [ "$(cd "$top" && pwd -P)" = "$(cd "$project_dir" && pwd -P)" ] || exit 0
  [ "$(cd "$git_dir" && pwd -P)" != "$common_dir" ] || exit 0
  [ -d "$(dirname "$common_dir")/.codegraph" ] || exit 0
  mode=init
  # .codegraph/ 還不存在，log 先放在這個 worktree 自己的 git 目錄，不弄髒工作區。
  log="$git_dir/codegraph-init.log"
  pidfile="$git_dir/codegraph-init.pid"
fi

# log 若是 symlink 先移除連結本身：直接覆寫會清空它指向的檔案。移除不掉就不寫。
if [ -L "$log" ]; then
  rm -f "$log"
  [ -L "$log" ] && exit 0
fi
# 上一次開 session 啟動的建索引還在跑：不重複啟動，也不覆寫它正在寫的 log。
if [ "$mode" = init ] && [ -s "$pidfile" ] && kill -0 "$(cat "$pidfile")" 2>/dev/null; then
  exit 0
fi
: > "$log" || exit 0
exec >> "$log" 2>&1

plugin_root="${CLAUDE_PLUGIN_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
# 版本只認 .mcp.json 這一處，跟 MCP server 用同一版，索引格式才不會不一致。
pkg="$(grep -o '@colbymchenry/codegraph@[0-9][0-9A-Za-z.+-]*' "$plugin_root/.mcp.json" | head -n 1)"
version="${pkg##*@}"
echo "[$(date '+%Y-%m-%dT%H:%M:%S%z')] session-sync（${mode}）codegraph@${version:-（版本未知）} $project_dir"
if [ -z "$version" ]; then
  echo "略過：$plugin_root/.mcp.json 讀不到 @colbymchenry/codegraph 的版本"
  exit 0
fi

# hook 以非互動 shell 執行，不一定帶得到 nvm 的 PATH。
if ! command -v node >/dev/null 2>&1; then
  nvm_sh="${NVM_DIR:-$HOME/.nvm}/nvm.sh"
  if [ -s "$nvm_sh" ]; then
    # shellcheck disable=SC1090
    . "$nvm_sh"
  fi
fi
if ! command -v node >/dev/null 2>&1; then
  echo "略過：找不到 node（PATH=${PATH}）"
  exit 0
fi

# 以下兩個設定要在第一次執行 codegraph（含查版本）之前就生效。
# 與 .mcp.json 相同：不允許套件改從 GitHub Releases 下載 binary。
export CODEGRAPH_NO_DOWNLOAD=1
# 這是自動執行，不是使用者下的命令：不記使用統計，也不為了上傳統計而連網路。
export CODEGRAPH_TELEMETRY=0

# 只用本機已安裝、版本與 .mcp.json 相同的 CodeGraph，不經過 npx。
# npx 即使加 --no 也會先連 npm registry 查版本，registry 沒回應時會卡到 hook 的 timeout。
# 套件的下載交給 MCP server 啟動時做。
# 候選路徑必須是絕對路徑，且不在專案目錄內：PATH 或 npm 快取若指進專案
# （例如 node_modules/.bin），找到的會是專案自己放的程式。
# 只比對路徑字串，不解析 symlink 與 /./ 這類寫法；那些要靠使用者自己的環境設定把關。
outside_project() {
  case "$1" in
    "$project_dir"/*) return 1 ;;
    /*) return 0 ;;
    *) return 1 ;;
  esac
}

find_codegraph() {
  local bin cache pj
  # 全域安裝（npm i -g）
  bin="$(command -v codegraph 2>/dev/null)"
  if outside_project "$bin" && [ "$("$bin" --version 2>/dev/null)" = "$version" ]; then
    printf '%s' "$bin"
    return 0
  fi
  # npx 快取（MCP server 以 npx 啟動時下載到這裡）
  for cache in "${npm_config_cache:-}" "$HOME/.npm" "${LOCALAPPDATA:-}/npm-cache"; do
    outside_project "$cache" || continue
    [ -d "$cache/_npx" ] || continue
    for pj in "$cache"/_npx/*/node_modules/@colbymchenry/codegraph/package.json; do
      [ -f "$pj" ] || continue
      grep -q "\"version\"[[:space:]]*:[[:space:]]*\"$version\"" "$pj" || continue
      bin="${pj%/@colbymchenry/codegraph/package.json}/.bin/codegraph"
      if [ -x "$bin" ]; then
        printf '%s' "$bin"
        return 0
      fi
    done
  done
  return 1
}

codegraph_bin="$(find_codegraph)"
if [ -z "$codegraph_bin" ]; then
  echo "略過：本機找不到已安裝的 CodeGraph ${version}（全域安裝與 npx 快取都沒有）。MCP server 啟動並下載完成後，下次開 session 會再試"
  exit 0
fi

if [ "$mode" = init ]; then
  # 建索引放背景跑：Claude 的第一則回應要等 hook 結束，而建索引的時間隨 repo 大小而定。
  # 輸出已經導向 log 檔，背景程序不會讓 hook 卡著不結束。
  echo "執行（背景）：$codegraph_bin init -y"
  (
    "$codegraph_bin" init -y "$project_dir"
    echo "[exit $?]"
    rm -f "$pidfile"
  ) &
  echo "$!" > "$pidfile"
  exit 0
fi

# 索引正被另一個程序鎖住時，codegraph sync 不會報錯：它什麼都不做，照樣印
# Already up to date 並以 0 結束。先等鎖釋放；等不到就在 log 寫明，避免誤以為同步成功。
lock="$project_dir/.codegraph/codegraph.lock"
for _ in 1 2 3; do
  [ -e "$lock" ] || break
  sleep 1
done
if [ -e "$lock" ]; then
  echo "注意：索引被另一個程序鎖住（${lock}），下面的同步可能沒有真的執行"
fi

echo "執行：$codegraph_bin sync"
"$codegraph_bin" sync "$project_dir"
echo "[exit $?]"
exit 0
