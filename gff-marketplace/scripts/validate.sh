#!/usr/bin/env bash
# Marketplace 結構驗證（CI 硬閘：bash + php + grep，皆內建於 CI 鏡像、無需安裝）
#
# 用途：擋住會讓 plugin 載入失敗或外洩祕密的低級錯誤。
#       用 php 而非 jq 解析 JSON：CI runner 是 K8s executor、只拉內部 phpbase 鏡像
#       （有 php、無 jq）；本機為 PHP shop 也一定有 php。
#       官方 `claude plugin validate` 沒有在 CI 跑，pre-commit 也不跑，要在本機手動跑：runner 拉不到 node 鏡像，
#       phpbase 鏡像又沒有合用的 node（原因見 .gitlab-ci.yml 開頭）。
#
# 本機跑法（在 repo 根）：bash scripts/validate.sh
#
# 檢查項目（編號對應下方各段的標題；沒有編號的段落列在最後，並註明排在哪一項後面）：
#   1. marketplace.json 是合法 JSON 且有 name / plugins
#   2. 每個 marketplace plugin 的 source 目錄存在、含 plugin.json、name 一致
#   3. 每個 skills/*/SKILL.md 開頭是 YAML frontmatter 且含 name:；沒加引號的值不可含「 #」
#   3b. plugin 內 markdown 含「..」的相對連結不可退出自己的 plugin 目錄，目標也要存在
#   4. 密鑰外洩掃描：硬寫的 GitLab PAT（glpat-…）、Slack token（xoxb-／xoxp-）、GCP 私鑰、AWS Access Key ID，
#      排除各自的文件佔位符
#   4b. 寫入前密鑰攔截 hook 的冒煙測試：假 token 要被擋、佔位符要放行（需 node，CI 略過）
#   5. bin/*.js 與 .mcp.json 引用的 MCP 套件必須 pin 版本（@<version>）
#   6. apis-toolkit 的 DB 命令攔截 hook（db-command guard）結構：hooks.json 合法、腳本存在且可執行、hooks.json 指向它
#   7. gitlab-mcp 結構：secrets 檔範例與讀取邏輯、.mcp.json 的 user_config PAT 引用、直跑快取入口與 npx 回退、
#      終止信號轉送、gitlab-workitem server 接線
#   7b. apis-toolkit mysql MCP 結構：dbc 代理主路徑與容器解析舊路徑、唯讀旗標與待刪 env、子程序 cwd、
#      直跑與 npx 回退、終止信號轉送、.mcp.json 不含 DB 憑證
#   8. 白標 registry 結構（check-registry.php）
#   9. Skill 速查表（docs/skill-cheatsheet.html）有沒有跟上 skill 異動：只發警告、不擋
#   10. 寫作 skill：mr-report-writing 附的 MR 報告格式驗證器要通過自己的夾具測試（check-mr-report-test.php）、
#      tech-doc-writing 與 mr-report-writing 必須叫起 plain-writing、CONTRIBUTING.md 6-3 必須指向 plain-writing
#   沒有編號（排在 5 後面）：agent 的 tools: 清單引用的市集 MCP（plugin 與 server）必須存在
#   沒有編號（排在 7 後面）：gcp-mcp observability wrapper 的啟動路徑、self-heal 與終止信號轉送
#   沒有編號（排在 9 後面）：MR 合併前核准的判定測試與 .gitlab/approval-rules.json（approval-check-test.php）、
#      MR 介入通知的判定測試（mr-intervention-test.php）

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

fail=0
warned=0
err()  { printf '  \xe2\x9c\x98 %s\n' "$1"; fail=1; }
ok()   { printf '  \xe2\x9c\x94 %s\n' "$1"; }
# 警告：提醒要跟著改的地方，不擋 commit、不讓 CI 變紅
warn() { printf '  \xe2\x9a\xa0 %s\n' "$1"; warned=1; }
sect() { printf '\n=== %s ===\n' "$1"; }

# JSON 解析 helper（用 php 取代 jq）
json_ok()        { php -r '$s=@file_get_contents($argv[1]); if($s===false)exit(2); json_decode($s); exit(json_last_error()===JSON_ERROR_NONE?0:1);' "$1" 2>/dev/null; }
mp_has_fields()  { php -r '$d=json_decode(@file_get_contents($argv[1])); exit((isset($d->name)&&isset($d->plugins)&&is_array($d->plugins))?0:1);' "$1" 2>/dev/null; }
mp_plugins_tsv() { php -r '$d=json_decode(@file_get_contents($argv[1])); foreach(($d->plugins??[]) as $p){ printf("%s\t%s\n",$p->name??"",$p->source??""); }' "$1"; }
plugin_name()    { php -r '$d=json_decode(@file_get_contents($argv[1])); echo $d->name??"";' "$1"; }

# ---------- 1. marketplace.json ----------
sect "marketplace.json"
MP=".claude-plugin/marketplace.json"
if [ ! -f "$MP" ]; then
  err "$MP 不存在"
elif ! json_ok "$MP"; then
  err "$MP 不是合法 JSON"
else
  ok "JSON 合法"
  mp_has_fields "$MP" || err "$MP 缺 name 或 plugins 欄位"
fi

# ---------- 2. plugin sources ----------
sect "plugin sources 與 plugin.json"
if [ -f "$MP" ] && json_ok "$MP"; then
  while IFS=$'\t' read -r name source; do
    [ -z "$name" ] && continue
    dir="${source#./}"
    manifest="$dir/.claude-plugin/plugin.json"
    if [ ! -d "$dir" ]; then
      err "$name: source 目錄不存在 ($dir)"; continue
    fi
    if [ ! -f "$manifest" ]; then
      err "$name: 缺 $manifest"; continue
    fi
    if ! json_ok "$manifest"; then
      err "$name: $manifest 不是合法 JSON"; continue
    fi
    pname="$(plugin_name "$manifest")"
    if [ -z "$pname" ]; then
      err "$name: $manifest 缺 name"
    elif [ "$pname" != "$name" ]; then
      err "$name: marketplace 名稱與 plugin.json name ($pname) 不一致"
    else
      ok "$name"
    fi
  done < <(mp_plugins_tsv "$MP")
fi

# ---------- 3. SKILL.md frontmatter ----------
sect "SKILL.md frontmatter"
found_skill=0
while IFS= read -r f; do
  found_skill=1
  if [ "$(sed -n '1p' "$f")" != "---" ]; then
    err "$f: 開頭不是 YAML frontmatter (---)"; continue
  fi
  fm="$(awk 'NR==1{next} /^---[[:space:]]*$/{exit} {print}' "$f")"
  if ! printf '%s\n' "$fm" | grep -qE '^name:[[:space:]]*[^[:space:]]'; then
    err "$f: frontmatter 缺 name:"
  # 沒加引號的值裡出現「空白＋#」，YAML 會把 # 之後整段當註解丟掉（例：「op #N」後面的觸發詞全部消失）
  elif hash_line="$(printf '%s\n' "$fm" | grep -nE "^[A-Za-z_-]+:[[:space:]]+[^\"'>|[:space:]].*[[:space:]]#" | head -1)" && [ -n "$hash_line" ]; then
    err "$f: 第 $(( ${hash_line%%:*} + 1 )) 行的值沒加引號又含「 #」，# 之後會被當註解截掉；整個值用單引號包起來"
  else
    ok "$f"
  fi
done < <(find plugins -name SKILL.md | sort)
[ "$found_skill" -eq 0 ] && ok "（尚無 SKILL.md）"

# ---------- 3b. plugin 內 markdown 的相對連結 ----------
# 安裝後每支 plugin 各自放在 cache/<marketplace>/<plugin>/<版本>/，跑出自己 plugin 目錄的相對連結會失效；
# 引用別的 plugin 改寫成純文字（例：「見 `gitlab-mcp` plugin 的 README」）。抓哪些寫法、不涵蓋什麼見 check-relative-links.php
sect "plugin 內相對連結（不可跨 plugin、目標要存在）"
link_out="$(php scripts/check-relative-links.php 2>&1)"
link_rc=$?
if [ "$link_rc" -eq 0 ]; then
  ok "相對連結都留在自己的 plugin 內，目標也都存在"
else
  # 失敗一律記錯，不靠輸出有沒有內容判斷（php 自己出錯時可能只有 stderr 或完全沒輸出）
  err "相對連結檢查失敗（php exit ${link_rc}）"
  while IFS= read -r l; do [ -n "$l" ] && err "$l"; done <<< "$link_out"
fi

# ---------- 4. 祕密外洩掃描 ----------
sect "密鑰外洩掃描（GitLab PAT / Slack / GCP 私鑰 / AWS）"
# 以 -o 抽出「命中的 token 本身」（輸出 file:line:token），再逐類排除合法佔位符。
# 不可用 `grep -vi xxxx` 排除整行：同一行若另含 xxxx 字串，同行真實密鑰也被放行。
_SI=(--include='*.md' --include='*.json' --include='*.js' --include='*.sh' --include='*.yml' --include='*.yaml')

# GitLab PAT：整體全-x 佔位符（glpat-[REDACTED]）放行
hits_gl="$(grep -rnoE 'glpat-[A-Za-z0-9_-]{20}' "${_SI[@]}" . 2>/dev/null \
  | grep -vE ':glpat-[xX]{20}$' || true)"

# Slack token：xoxb-/xoxp-；token 本身含 xxxx（文件佔位符慣例）放行
hits_slack="$(grep -rnoE 'xox[bp]-[A-Za-z0-9-]{10,}' "${_SI[@]}" . 2>/dev/null \
  | grep -iv 'xxxx' || true)"

# GCP 私鑰 header；用變數拼接避免本檔自掃命中
_GCP_HDR='-----BEGIN PRIVATE KEY'
hits_gcp="$(grep -rno -- "${_GCP_HDR}-----" "${_SI[@]}" . 2>/dev/null || true)"

# AWS Access Key ID：AKIA[A-Z0-9]{16}；官方範例 AKIA[REDACTED] 放行
hits_aws="$(grep -rnoE 'AKIA[A-Z0-9]{16}' "${_SI[@]}" . 2>/dev/null \
  | grep -v ':AKIA[REDACTED]$' || true)"

hits="$(printf '%s\n%s\n%s\n%s\n' "$hits_gl" "$hits_slack" "$hits_gcp" "$hits_aws" \
  | grep -v '^[[:space:]]*$' || true)"
if [ -n "$hits" ]; then
  err "偵測到疑似硬寫的密鑰："
  printf '%s\n' "$hits" | sed 's/^/      /'
else
  ok "未發現硬寫密鑰"
fi

# ---------- 4b. 寫入前密鑰攔截 hook 的冒煙測試（需 node：CI 無 node 時略過）----------
# hook 的 JS 包在 bash 單引號裡，一旦語法壞掉會整支失效；這裡實際送兩包輸入確認它還會擋、還會放。
# 測試用 token 在執行時拼出來，本檔不出現完整字串，避免第 4 項自掃命中。
sect "寫入前密鑰攔截 hook（.claude/hooks/block-pat-write.sh）"
_PAT_HOOK=".claude/hooks/block-pat-write.sh"
if ! command -v node >/dev/null 2>&1; then
  ok "略過：沒有 node（CI 鏡像無 node，本機跑 validate 時才測）"
else
  _fake="glpat-$(printf 'a%.0s' $(seq 1 20))"
  _place="glpat-$(printf 'x%.0s' $(seq 1 20))"
  printf '{"tool_name":"Write","tool_input":{"content":"%s"}}' "$_fake" | bash "$_PAT_HOOK" >/dev/null 2>&1
  _rc_block=$?
  printf '{"tool_name":"Write","tool_input":{"content":"%s"}}' "$_place" | bash "$_PAT_HOOK" >/dev/null 2>&1
  _rc_pass=$?
  if [ "$_rc_block" -eq 2 ] && [ "$_rc_pass" -eq 0 ]; then
    ok "假 token 會被擋下、佔位符會放行"
  else
    err "攔截 hook 行為異常：假 token exit=${_rc_block}（應為 2），佔位符 exit=${_rc_pass}（應為 0）"
  fi
fi

# ---------- 5. MCP 套件版本必須 pin ----------
# 防退步：bin/*.js 或 .mcp.json 內若以字串引用 npx 型 MCP 套件，必須帶 @<version>（見 CONTRIBUTING「Plugin 切分原則」節）。
# 只比對「引號內的套件字串」，避免誤判註解裡提到的套件名。
sect "MCP 套件 pin 版本"
unpinned=""
for pkg in '@zereight/mcp-gitlab' '@google-cloud/observability-mcp' '@benborla29/mcp-server-mysql' '@colbymchenry/codegraph'; do
  hits="$(grep -rnE "[\"']${pkg}([^@]|\$)" --include='*.js' --include='.mcp.json' plugins 2>/dev/null || true)"
  if [ -n "$hits" ]; then
    unpinned="${unpinned}${hits}"$'\n'
  fi
done
if [ -n "$unpinned" ]; then
  err "MCP 套件未 pin 版本（應寫成 <package>@<version>）："
  printf '%s\n' "$unpinned" | sed 's/^/      /'
else
  ok "MCP 套件已 pin 版本"
fi

# ---------- agent 工具清單引用的市集 MCP 必須存在 ----------
# agent 的 tools: 清單若列了不存在的工具，Claude Code 不會報錯，只會默默略過，agent 就少了那個工具。
# 這裡核對 mcp__plugin_<plugin>_<server>__<tool> 的 plugin 與 server 在市集內真的有。
# 工具名稱本身（<tool>）由 MCP server 執行時提供，這裡驗不到；專案自己定義的 MCP（如 mcp__laravel-boost__*）也不在市集，不驗。
sect "agent 工具清單引用的市集 MCP"
agent_mcp_bad=""
agent_mcp_refs="$(grep -rnoE 'mcp__plugin_[a-z0-9-]+_[a-z0-9-]+__' --include='*.md' plugins/*/agents 2>/dev/null | sort -u || true)"
while IFS= read -r ref; do
  [ -n "$ref" ] || continue
  token=[REDACTED]"
  body="${token#mcp__plugin_}"
  body="${body%__}"
  ref_plugin="${body%_*}"
  ref_server="${body##*_}"
  if ! grep -qE "\"${ref_server}\"[[:space:]]*:" "plugins/${ref_plugin}/.mcp.json" 2>/dev/null; then
    agent_mcp_bad="${agent_mcp_bad}${ref%:*}: ${token}（找不到 plugins/${ref_plugin}/.mcp.json 的 ${ref_server}）"$'\n'
  fi
done <<EOF
$agent_mcp_refs
EOF
if [ -n "$agent_mcp_bad" ]; then
  err "agent 的 tools: 引用了市集內不存在的 MCP（plugin 或 server 改名後要一起改）："
  printf '%s' "$agent_mcp_bad" | sed 's/^/      /'
elif [ -n "$agent_mcp_refs" ]; then
  ok "agent 引用的市集 MCP 都存在"
else
  ok "沒有 agent 引用市集 MCP"
fi

# ---------- 6. apis-toolkit db-command guard（結構，php-only：CI 無 node）----------
# node 分類器的行為正確性由本機手動跑的 node --test 把關（CI 無 node，pre-commit 也不跑）；
# 此處只做 CI 能驗的結構檢查：檔案存在、JSON 合法、wrapper 可執行、manifest 指向正確。
sect "apis-toolkit db-command guard（結構）"
GUARD_DIR="plugins/apis-toolkit/hooks"
if [ -f "$GUARD_DIR/hooks.json" ] && json_ok "$GUARD_DIR/hooks.json"; then ok "hooks.json 合法"; else err "hooks.json 缺失或非法 JSON"; fi
for f in guard-db-commands.sh db-command-classifier.mjs; do
  [ -f "$GUARD_DIR/$f" ] && ok "$f 存在" || err "$f 缺失"
done
[ -x "$GUARD_DIR/guard-db-commands.sh" ] && ok "guard-db-commands.sh 可執行" || err "guard-db-commands.sh 非可執行（chmod +x）"
grep -q 'guard-db-commands.sh' "$GUARD_DIR/hooks.json" 2>/dev/null && ok "hooks.json 指向 guard 腳本" || err "hooks.json 未指向 guard-db-commands.sh"

# ---------- 7. gitlab-mcp 本地 secrets 檔基礎（結構，php/grep-only）----------
# wrapper 的讀檔行為由本機 node test 把關；此處只驗 CI 能驗的結構。
sect "gitlab-mcp secrets 檔基礎（結構）"
GL_DIR="plugins/gitlab-mcp"
[ -f "$GL_DIR/secrets.env.example" ] && ok "secrets.env.example 存在" || err "缺 secrets.env.example"
if grep -q 'GFF_SECRETS_FILE' "$GL_DIR/bin/gitlab-mcp.js" 2>/dev/null; then
  ok "wrapper 含 secrets 檔讀取邏輯"
else
  err "wrapper 未引用 GFF_SECRETS_FILE（secrets 讀取邏輯缺失）"
fi
# 須引用 ${user_config.gitlab_pat}（legacy 非 breaking）；接受 default 形式 :-（未設時插空字串，
# 避免 Claude Code 因 required/未設 user_config 解析失敗而不啟動 MCP server——見 gff-secrets-file-design.md §10）。
if grep -qE '\$\{user_config\.gitlab_pat(:-)?\}' "$GL_DIR/.mcp.json" 2>/dev/null; then
  ok ".mcp.json 維持 user_config PAT 引用（legacy 非 breaking；支援 :- default 形式）"
else
  err ".mcp.json 的 GITLAB_PERSONAL_ACCESS_TOKEN 不再指向 user_config（破壞 legacy 路徑）"
fi

# 啟動路徑：直跑快取入口（免 npx 常駐層，轉送的終止信號才送得到 server 本身）＋ npx 回退必須同時存在。
if ! grep -q 'resolveCachedBin(PKG)' "$GL_DIR/bin/gitlab-mcp.js" 2>/dev/null; then
  err "gitlab wrapper 未使用直跑路徑（應以 resolveCachedBin 優先，避免 npx 常駐層留孤兒）"
elif ! grep -qE "spawn\(npxBin, \['-y', PKG\]" "$GL_DIR/bin/gitlab-mcp.js" 2>/dev/null; then
  err "gitlab wrapper 移除了 npx 回退（快取未就緒時將無法啟動）"
else
  ok "gitlab wrapper 直跑快取入口 + 保留 npx 回退"
fi
# wrapper 被信號終止時必須把子程序一起帶走，否則 MCP server 會變成孤兒。
# npx 回退路徑多一層 npm exec，必須自成行程群組（detached）整組送信號，否則保底的 SIGKILL 到不了 server。
if ! grep -qE '^[[:space:]]*forwardSignals\(' "$GL_DIR/bin/gitlab-mcp.js" 2>/dev/null; then
  err "gitlab wrapper 未呼叫 forwardSignals（被終止時子程序會變成孤兒）"
elif ! grep -qF 'detached: group' "$GL_DIR/bin/gitlab-mcp.js" 2>/dev/null; then
  err "gitlab wrapper 的 npx 回退路徑未以行程群組啟動（保底 SIGKILL 只到得了 npm exec、到不了 server）"
elif ! grep -qE '^[[:space:]]*reapGroup\(' "$GL_DIR/bin/gitlab-mcp.js" 2>/dev/null; then
  # 外層 npm exec 先結束時 wrapper 會跟著退出，逾時 SIGKILL 的計時器隨之消失；退出前必須當場清掉整組。
  err "gitlab wrapper 的子程序結束時未呼叫 reapGroup（npm exec 先退出時，不理會 SIGTERM 的 server 會留成孤兒）"
else
  ok "gitlab wrapper 收到終止信號會轉送給子程序（npx 回退路徑整組送）"
fi
# gitlab-workitem：自寫的 MCP server，金鑰必須沿用 resolveGitlabPat（secrets 檔規則與 gitlab server 一致）。
# 工具行為由本機 node --test 把關（CI 無 node）；此處只驗 manifest 接線。
WI_JS="$GL_DIR/bin/gitlab-workitem-mcp.js"
wi_wiring="$(php -r '$d=json_decode(@file_get_contents($argv[1]),true); $s=$d["mcpServers"]["gitlab-workitem"]??null;
  if(!$s){echo "缺 gitlab-workitem server";exit;}
  if(($s["command"]??"")!=="node"||($s["args"]??[])!==["\${CLAUDE_PLUGIN_ROOT}/bin/gitlab-workitem-mcp.js"]){echo "啟動命令未指向 bin/gitlab-workitem-mcp.js";exit;}
  if(!preg_match("/^\\\$\\{user_config\\.gitlab_pat(:-)?\\}\$/",$s["env"]["GITLAB_PERSONAL_ACCESS_TOKEN"]??"")){echo "PAT 未指向 user_config";exit;}' "$GL_DIR/.mcp.json" 2>/dev/null)"
if [ ! -f "$WI_JS" ]; then
  err "缺 $WI_JS"
elif [ -n "$wi_wiring" ]; then
  err ".mcp.json 的 gitlab-workitem 設定有誤：$wi_wiring"
elif ! grep -qE '^const \{ resolveGitlabPat.*\} = require\(.\./gitlab-mcp\.js.\);' "$WI_JS"; then
  err "gitlab-workitem 未沿用 gitlab-mcp.js 的 resolveGitlabPat 取金鑰"
else
  ok "gitlab-workitem server 接線正確（沿用 resolveGitlabPat）"
fi

# ---------- gcp-mcp observability wrapper 啟動路徑 ----------
sect "gcp-mcp observability wrapper（結構）"
GCP_DIR="plugins/gcp-mcp"
if ! grep -q 'buildLaunchCommand' "$GCP_DIR/bin/observability-mcp.js" 2>/dev/null; then
  err "gcp wrapper 未使用直跑路徑（應以 buildLaunchCommand 優先，避免 npx 常駐層留孤兒）"
elif ! grep -q 'function buildNpxCommand' "$GCP_DIR/bin/observability-mcp.js" 2>/dev/null; then
  err "gcp wrapper 移除了 npx 回退（快取未就緒或 self-heal 清快取後將無法啟動）"
elif ! grep -qE '^    const command = buildLaunchCommand' "$GCP_DIR/bin/observability-mcp.js" 2>/dev/null; then
  # 必須在 launch() 內逐次解析：self-heal 會 purge _npx，啟動前算好的直跑目標會消失，
  # 只有重試時重新解析才會回退 npx 並重新解壓；在 main() 頂層算一次會讓 heal 後的重試必死。
  err "gcp wrapper 的啟動命令未在 launch() 內逐次解析（self-heal 後重試會指向已被清除的路徑）"
elif ! grep -qE '^[[:space:]]*const shutdown = forwardSignals\(' "$GCP_DIR/bin/observability-mcp.js" 2>/dev/null; then
  err "gcp wrapper 未呼叫 forwardSignals（被終止時子程序會變成孤兒）"
elif ! grep -qF 'stopping: shutdown.stopping()' "$GCP_DIR/bin/observability-mcp.js" 2>/dev/null; then
  # 收尾中的 server 可能很快以非零碼結束，沒排除就會被 self-heal 當成啟動失敗而重啟。
  err "gcp wrapper 的 self-heal 未排除收尾狀態（收到終止信號後可能又把 server 重啟）"
elif ! grep -qF 'detached: command.group' "$GCP_DIR/bin/observability-mcp.js" 2>/dev/null; then
  err "gcp wrapper 的 npx 回退路徑未以行程群組啟動（保底 SIGKILL 只到得了 npm exec、到不了 server）"
elif ! grep -qE '^[[:space:]]*reapGroup\(' "$GCP_DIR/bin/observability-mcp.js" 2>/dev/null; then
  err "gcp wrapper 的子程序結束時未呼叫 reapGroup（npm exec 先退出時，不理會 SIGTERM 的 server 會留成孤兒）"
else
  ok "gcp wrapper 直跑快取入口 + npx 回退 + heal 後逐次重解析 + 終止信號轉送"
fi

# ---------- 7b. apis-toolkit whitelabel-db-query mysql MCP（結構，grep/json-only：CI 無 node）----------
# wrapper 讀檔 / 唯讀注入行為由本機 node --test 把關；此處只驗 CI 能驗的結構。
sect "apis-toolkit mysql MCP（結構）"
WLQ_DIR="plugins/apis-toolkit"
if [ -f "$WLQ_DIR/.mcp.json" ] && json_ok "$WLQ_DIR/.mcp.json"; then ok ".mcp.json 合法"; else err ".mcp.json 缺失或非法 JSON"; fi
[ -f "$WLQ_DIR/bin/mysql-mcp.js" ] && ok "mysql-mcp.js 存在" || err "mysql-mcp.js 缺失"
[ -f "$WLQ_DIR/bin/wl-resolve-conn.php" ] && ok "wl-resolve-conn.php 存在" || err "缺 wl-resolve-conn.php（容器連線解析器）"
# 主路徑：registry dbcPort → 本機 dbc 代理（IAM、SELECT-only、查詢稽核）；dbcPort: null 的白標
# 才走容器 config 解析（啟動時 docker exec）/ secrets.env 舊路徑。兩條都要在 wrapper 裡。
if grep -q 'whitelabels.metadata.yaml' "$WLQ_DIR/bin/mysql-mcp.js" 2>/dev/null \
   && grep -q 'dbcPort' "$WLQ_DIR/bin/mysql-mcp.js" 2>/dev/null; then
  ok "wrapper 讀 registry dbcPort 走 dbc 代理主路徑"
else
  err "wrapper 未引用 registry dbcPort（dbc 主路徑）"
fi
if grep -q 'wl-resolve-conn.php' "$WLQ_DIR/bin/mysql-mcp.js" 2>/dev/null \
   && grep -q "docker" "$WLQ_DIR/bin/mysql-mcp.js" 2>/dev/null; then
  ok "wrapper 保留容器 config 解析舊路徑（dbcPort: null 用）"
else
  err "wrapper 未引用容器連線解析（dbcPort: null 的白標應 docker exec wl-resolve-conn.php）"
fi
# dbc 路徑 fail-closed：port 未監聽不得退舊路徑（grep 錯誤訊息關鍵字）
if grep -q 'dbc 代理未啟動' "$WLQ_DIR/bin/mysql-mcp.js" 2>/dev/null; then
  ok "wrapper dbc 路徑 fail-closed（未掛 dbc start 即拒啟）"
else
  err "wrapper 缺 dbc fail-closed 提示（應含「dbc 代理未啟動」）"
fi
# 唯讀強制（一）：wrapper 必須把全部寫入旗標釘成 false（防 .mcp.json/env 注入繞過）
missing_ro=""
for flag in ALLOW_INSERT_OPERATION ALLOW_UPDATE_OPERATION ALLOW_DELETE_OPERATION \
            ALLOW_DDL_OPERATION MULTI_DB_WRITE_MODE MYSQL_DISABLE_READ_ONLY_TRANSACTIONS; do
  grep -q "$flag: 'false'" "$WLQ_DIR/bin/mysql-mcp.js" 2>/dev/null || missing_ro="$missing_ro $flag"
done
if [ -z "$missing_ro" ]; then
  ok "wrapper 強制唯讀旗標（6 項全釘 false）"
else
  err "wrapper 未釘唯讀旗標:$missing_ro"
fi
# 唯讀強制（二）：能單獨繞過 ALLOW_* 與 READ ONLY transaction 的 env 必須被刪除，非設值
# SCHEMA_*_PERMISSIONS 命中即走 executeWriteQuery（跳過 READ ONLY transaction）；
# IS_REMOTE_MCP/REMOTE_SECRET_KEY/PORT 會讓 server 改開 HTTP 埠，於 stdio 外多出輸入通道。
missing_scrub=""
# MYSQL_CONNECTION_STRING/MYSQL_SOCKET_PATH 優先序高於 MYSQL_HOST/PORT/USER/PASS/DB，留著可整組換掉 dbc 座標。
for key in SCHEMA_INSERT_PERMISSIONS SCHEMA_UPDATE_PERMISSIONS SCHEMA_DELETE_PERMISSIONS \
           SCHEMA_DDL_PERMISSIONS IS_REMOTE_MCP REMOTE_SECRET_KEY PORT \
           MYSQL_CONNECTION_STRING MYSQL_SOCKET_PATH; do
  grep -qE "^[[:space:]]*'$key'," "$WLQ_DIR/bin/mysql-mcp.js" 2>/dev/null || missing_scrub="$missing_scrub $key"
done
if [ -n "$missing_scrub" ]; then
  err "wrapper 未列入待刪 env:$missing_scrub"
elif ! grep -q 'for (const key of SCRUBBED_ENV_KEYS) delete env\[key\]' "$WLQ_DIR/bin/mysql-mcp.js" 2>/dev/null; then
  err "wrapper 有 SCRUBBED_ENV_KEYS 清單但未實際刪除（缺 delete 迴圈）"
else
  ok "wrapper 刪除可繞過唯讀的 env（9 項）"
fi
# 唯讀強制（三）：刪除只擋父程序 env；benborla 會 dotenv.config() 讀子程序 cwd 的 .env 把被刪的 key 補回來，
# 故 spawn 必須固定 cwd 在 plugin bin 目錄（無 .env），不得沿用 Claude Code 的專案目錄。
if [ "$(grep -c 'cwd: SERVER_CWD' "$WLQ_DIR/bin/mysql-mcp.js" 2>/dev/null)" -ge 2 ] \
   && ! ls "$WLQ_DIR/bin/.env" >/dev/null 2>&1; then
  ok "wrapper 以 plugin bin/ 為子程序 cwd（封 dotenv 補 env）且 bin/ 無 .env"
else
  err "wrapper spawn 未固定 cwd: SERVER_CWD（直跑與 npx 回退兩處都要），或 bin/ 下出現 .env"
fi
# 啟動路徑：直跑快取入口（免 npx 常駐層，轉送的終止信號才送得到 server 本身）＋ npx 回退必須同時存在。
# 少了直跑會回到孤兒累積；少了回退則快取未就緒時全團隊起不來。
if ! grep -q 'resolveCachedBin(PKG)' "$WLQ_DIR/bin/mysql-mcp.js" 2>/dev/null; then
  err "wrapper 未使用直跑路徑（應以 resolveCachedBin 優先，避免 npx 常駐層留孤兒）"
elif ! grep -qE "spawn\(npxBin, \['-y', PKG\]" "$WLQ_DIR/bin/mysql-mcp.js" 2>/dev/null; then
  err "wrapper 移除了 npx 回退（快取未就緒時將無法啟動）"
else
  ok "wrapper 直跑快取入口 + 保留 npx 回退"
fi
# wrapper 被信號終止時必須把子程序一起帶走：這支 server 在 stdin 收到 EOF 後不會自行退出，
# 沒帶走就一定變成孤兒並繼續佔著 MySQL 連線。
# npx 回退路徑多一層 npm exec，且這支 server 實測未在期限內回應 SIGTERM，必須自成行程群組（detached）整組送 SIGKILL 才收得掉。
if ! grep -qE '^[[:space:]]*forwardSignals\(' "$WLQ_DIR/bin/mysql-mcp.js" 2>/dev/null; then
  err "wrapper 未呼叫 forwardSignals（被終止時子程序會變成孤兒、佔著 MySQL 連線）"
elif ! grep -qF 'detached: group' "$WLQ_DIR/bin/mysql-mcp.js" 2>/dev/null; then
  err "wrapper 的 npx 回退路徑未以行程群組啟動（保底 SIGKILL 只到得了 npm exec、到不了 server）"
elif ! grep -qE '^[[:space:]]*reapGroup\(' "$WLQ_DIR/bin/mysql-mcp.js" 2>/dev/null; then
  # 外層 npm exec 先結束時 wrapper 會跟著退出，逾時 SIGKILL 的計時器隨之消失；退出前必須當場清掉整組。
  err "wrapper 的子程序結束時未呼叫 reapGroup（npm exec 先退出時，不理會 SIGTERM 的 server 會留成孤兒）"
else
  ok "wrapper 收到終止信號會轉送給子程序（npx 回退路徑整組送）"
fi
# .mcp.json 不得內嵌 DB 憑證（env 只帶 WHITELABEL）
if grep -qiE 'MYSQL_PASS|MYSQL_USER|password' "$WLQ_DIR/.mcp.json" 2>/dev/null; then
  err ".mcp.json 內嵌了 DB 憑證（憑證走容器 config 解析、不入 manifest）"
else
  ok ".mcp.json 未內嵌 DB 憑證"
fi

# ---------- 8. apis-toolkit whitelabel registry（結構，php 硬閘）----------
# 完整結構/型別 defense-in-depth（必填欄位、canonical==key、connectionId safe-token、
# 別名全域碰撞、rootAgent 型別、sharesConnection 實況一致、規模閘）由 check-registry.php
# 把關——用 php（phpbase 鏡像鐵定有）而非 python，故 CI 能直接跑成硬閘。
# rootAgentId 的 live 語意（id 存在、username 吻合）另由 verify-registry-live.py 在 DB 可達時驗。
sect "apis-toolkit whitelabel registry（結構）"
REG="plugins/apis-toolkit/registry/whitelabels.metadata.yaml"
CHECK_PHP="plugins/apis-toolkit/scripts/check-registry.php"
if [ ! -f "$REG" ]; then
  err "registry 檔不存在: $REG"
elif [ ! -f "$CHECK_PHP" ]; then
  err "checker 不存在: $CHECK_PHP"
elif reg_out="$(php "$CHECK_PHP" 2>&1)"; then
  ok "$reg_out"
else
  err "check-registry.php 失敗："
  printf '%s\n' "$reg_out" | sed 's/^/      /'
fi

# ---------- 9. Skill 速查表（警告，不擋）----------
# 速查表是給同事看的「這樣說 → 叫起哪支 skill」對照，發布在 claude.ai artifact，原稿是這個檔。
# 兩條檢查：
#   (a) 每支 skill 都要出現在速查表表格的「會叫起」欄，或列在頁內的 cheatsheet-skip 註解（刻意不收錄）
#   (b) 相對 origin/main 改了 SKILL.md 的 description（觸發詞），速查表卻沒動
#   (c) 同一批改了 description 的 skill，列出 evals/ 底下對應的觸發詞測試案例，提醒先照 evals/README.md 跑
# 取不到 origin/main（例如 CI 淺層 clone）時略過 (b)(c)，不誤報。
sect "Skill 速查表（docs/skill-cheatsheet.html）"
CHEAT="docs/skill-cheatsheet.html"
if [ ! -f "$CHEAT" ]; then
  warn "$CHEAT 不存在"
else
  # 表格每列寫成 ['想做什麼', [講法], 'skill', 'plugin', '備註']，取第三格；命令前面的 / 去掉
  listed=" $(grep -oE "\], '[^']+', '" "$CHEAT" | sed -E "s/^\], '\/?([^']+)'.*/\1/" | tr '\n' ' ') "
  skipped=" $(grep -oE 'cheatsheet-skip:[^>]*' "$CHEAT" | sed 's/^cheatsheet-skip://; s/--$//' | tr '\n' ' ') "
  missing=""
  for d in plugins/*/skills/*/; do
    s="$(basename "$d")"
    case "$listed$skipped" in *" $s "*) ;; *) missing="$missing $s" ;; esac
  done
  if [ -n "$missing" ]; then
    warn "速查表沒收錄這些 skill：${missing# }（補進表格，或列進頁內 cheatsheet-skip 註解）"
  else
    ok "每支 skill 都已收錄或刻意排除"
  fi

  if base="$(git merge-base origin/main HEAD 2>/dev/null)"; then
    # 比對 base 到工作區（含還沒 commit 的修改），改到 description 那一行才算
    changed=""
    for f in $(git diff --name-only "$base" -- 'plugins/*/skills/*/SKILL.md' 2>/dev/null); do
      git diff -U0 "$base" -- "$f" | grep -qE '^[+-]description:' && changed="$changed $(basename "$(dirname "$f")")"
    done
    if [ -z "$changed" ]; then
      ok "這條分支沒改到 skill 的 description"
    elif git diff --quiet "$base" -- "$CHEAT"; then
      warn "這條分支改了${changed} 的 description，速查表沒跟著改；確認講法有沒有變，有變就改 $CHEAT"
    else
      ok "改了${changed} 的 description，速查表也有更新"
    fi

    # 觸發詞測試案例的評分檔命名為 evals/<案例>/graders/fired-<skill>.md（該叫起）或 not-<skill>.md（不該叫起）
    # 速查表已跟上時視為 description 改動已處理，案例清單改用 ok 列出，不再讓改檔 hook 反覆送回警告
    eval_note=warn
    git diff --quiet "$base" -- "$CHEAT" || eval_note=ok
    for s in $changed; do
      cases=""
      for g in evals/*/graders/fired-"$s".md evals/*/graders/not-"$s".md; do
        [ -f "$g" ] || continue
        c="$(basename "$(dirname "$(dirname "$g")")")"
        case "$(basename "$g")" in
          fired-*) cases="$cases ${c}（該叫起）" ;;
          *)       cases="$cases ${c}（不該叫起）" ;;
        esac
      done
      if [ -n "$cases" ]; then
        "$eval_note" "$s 改了 description，先照 evals/README.md 跑這些觸發詞測試案例：${cases# }"
      else
        "$eval_note" "$s 改了 description，evals/ 沒有對應的觸發詞測試案例；講法有變時考慮照 evals/README.md 補一個"
      fi
    done
  else
    ok "取不到 origin/main，略過 description 比對"
  fi
fi

# ---------- MR 合併前核准的判定邏輯與名單 ----------
sect "MR 核准檢查（scripts/approval-check-test.php）"
if php "$ROOT/scripts/approval-check-test.php"; then
  ok "核准判定測試與 .gitlab/approval-rules.json 都通過"
else
  err "核准判定測試或 .gitlab/approval-rules.json 有錯，見上方 ✘"
fi

sect "MR 介入通知（scripts/mr-intervention-test.php）"
if php "$ROOT/scripts/mr-intervention-test.php"; then
  ok "MR 介入通知判定測試通過"
else
  err "MR 介入通知判定測試有錯，見上方 ✘"
fi

# ---------- 10. 寫作 skill（plain-writing / tech-doc-writing / mr-report-writing）----------
# 三支寫作 skill 的結構約定：
#   (a) mr-report-writing 附的 MR 報告格式驗證器（scripts/check-mr-report.php）要通過自己的夾具測試
#       （scripts/check-mr-report-test.php：pass-* 夾具要全過、fail-* 夾具要被擋且訊息含第一行 expect 的關鍵字）。
#       用 php 是因為 CI 鏡像只有 php（見檔頭）；夾具測試與驗證器放在 skill 目錄內，skill 才能跨 repo 自足。
#   (b) tech-doc-writing 與 mr-report-writing 的 SKILL.md 必須叫起 dev-toolkit:plain-writing：
#       用字規則只在 plain-writing 一支維護，應用 skill 不複述，少了這句就等於各自維護一份。
#   (c) CONTRIBUTING.md 的 6-3 白話寫作規範必須指向 plain-writing，不再自己維護一份判準。
sect "寫作 skill（plain-writing / tech-doc-writing / mr-report-writing）"
WR_DIR="plugins/dev-toolkit/skills"
MRT="$WR_DIR/mr-report-writing/scripts/check-mr-report-test.php"
if [ ! -f "$MRT" ]; then
  err "MR 報告驗證器的夾具測試不存在: $MRT"
elif php "$MRT"; then
  ok "MR 報告驗證器通過全部夾具"
else
  err "MR 報告驗證器沒有通過夾具測試，見上方 ✘"
fi
for s in tech-doc-writing mr-report-writing; do
  f="$WR_DIR/$s/SKILL.md"
  if [ ! -f "$f" ]; then
    err "$f 不存在"
  elif grep -q 'dev-toolkit:plain-writing' "$f"; then
    ok "$s 會叫起 dev-toolkit:plain-writing"
  else
    err "$f 沒有叫起 dev-toolkit:plain-writing（用字規則由 plain-writing 管，應用 skill 要用 Skill 工具叫起它）"
  fi
done
# 6-3 節的範圍：從「### 6-3.」那行到下一個「## 」或「### 」標題之前
if sed -n '/^### 6-3\./,/^##[# ]/p' CONTRIBUTING.md | grep -q 'plain-writing'; then
  ok "CONTRIBUTING.md 6-3 指向 plain-writing"
else
  err "CONTRIBUTING.md 6-3 沒有指向 plain-writing（四條判準的正本在 $WR_DIR/plain-writing/SKILL.md）"
fi

# ---------- 結果 ----------
sect "結果"
if [ "$fail" -eq 0 ]; then
  if [ "$warned" -eq 1 ]; then
    echo "全部通過 ✔（有警告 ⚠，見上方）"
  else
    echo "全部通過 ✔"
  fi
  exit 0
else
  echo "有錯誤，請修正後再 commit ✘"
  exit 1
fi
