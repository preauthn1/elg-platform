#!/bin/bash
# no-hardcode 規則：掃描 git diff「新增行」的 hardcode 樣態（URL / IP / 疑似密鑰 / 白標名）。
# ratchet 設計：只擋新增行，存量不擋（存量另行排消）。樣態集與 GFF apis-toolkit 的
# PostToolUse hook（php-hardcode-scanner.mjs）對齊；env() 不在此掃——CI lint:no-env 已全掃硬閘。
#
# 用法：
#   check-hardcode.sh --cached           # pre-commit：掃 staged 新增行
#   check-hardcode.sh --range <A>...<B>  # CI：掃 MR 新增行
#
# 豁免：該行加註 `hardcode-ok: 原因`。
# 範圍：app/、routes/ 下 .php；排除 app/Console/Commands/Test/（比照 lint:no-debug）。

set -uo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

case "${1:-}" in
    --cached)
        MODE=cached
        DIFF_ARGS=(--cached)
        ;;
    --range)
        [ -n "${2:-}" ] || { echo "用法: $0 --range <A>...<B>"; exit 2; }
        MODE=range
        DIFF_ARGS=("$2")
        ;;
    *)
        echo "用法: $0 --cached | --range <A>...<B>"
        exit 2
        ;;
esac

echo -e "${YELLOW}🔍 no-hardcode 規則：檢查新增行的 hardcode 樣態...${NC}"

# 白標名單：動態取自 config/database.php `connections` 陣列的第一層 key（APIs 側 SSOT），
# 排除非白標的通用連線；取不到時該類別跳過（不阻塞）。
# ⚠️ 必須先用 awk 框住 `connections` 區塊再取 key：直接掃全檔的 8 空白縮排會一併撈到
# $mysqlCommon / redis / migrations 區的 'username'、'password'、'host'、'table' 等，
# 使它們被當成白標名，任何含這些字的字面值（如 'username' => ...）都會被誤報。
WL_NAMES=$(awk '/^    .connections. =>/,/^    \],/' config/database.php 2>/dev/null \
    | grep -oE "^[[:space:]]{8}'[A-Za-z][A-Za-z0-9]+' =>" \
    | tr -d "'" | awk '{print $1}' \
    | grep -vwE 'mysql|Dev|log|testing|sqlite' \
    | paste -sd'|' -) || WL_NAMES=""

# 取新增行，輸出格式 file:line:content（-U0 無 context 行；--diff-filter 排除刪除檔）
extract_added() { # $@ = git diff 的 revision／範圍參數
    git diff "$@" -U0 --diff-filter=ACM -- 'app/' 'routes/' 2>/dev/null | awk '
        /^\+\+\+ / { file = $2; sub(/^b\//, "", file); next }
        /^@@ /     { split($0, a, "+"); split(a[2], b, /[ ,]/); line = b[1] - 1; next }
        /^\+/      { line++; if (file ~ /\.php$/) print file ":" line ":" substr($0, 2); next }
        /^ /       { line++ }
    '
}

# merge 進行中（含 merge --no-commit 與解衝突後）：staged 內容含「被合併進來的既有代碼」，
# 全掃會把對方分支既有的行當成本次新增而誤報（例：merge 發布分支帶進他人已合併的代碼）。
# 只取兩個 parent 都沒有的行 = 解衝突／手動編輯真正引入的內容；兩份 diff 的行號同以 index
# 版本為基準，故可直接以 file:line:content 取交集。--range（CI）為三點 diff，本就不含此問題。
MERGE_HEAD_FILE=$(git rev-parse --git-path MERGE_HEAD 2>/dev/null)
if [ "$MODE" = "cached" ] && [ -n "$MERGE_HEAD_FILE" ] && [ -f "$MERGE_HEAD_FILE" ]; then
    echo -e "${YELLOW}   merge 進行中：只掃解衝突／手動編輯引入的行，略過被合併進來的既有代碼${NC}"
    ADDED=$(comm -12 <(extract_added --cached HEAD | sort) <(extract_added --cached MERGE_HEAD | sort))
else
    ADDED=$(extract_added "${DIFF_ARGS[@]}")
fi

# 過濾：範圍排除、豁免註解、純註解行
CANDIDATES=$(echo "$ADDED" \
    | grep -v '^app/Console/Commands/Test/' \
    | grep -v 'hardcode-ok:' \
    | grep -vE ':[0-9]+:[[:space:]]*(//|/\*|\*|#)' || true)

VIOLATIONS=""
append_violations() { # $1=標籤 $2=命中行
    [ -n "$2" ] && VIOLATIONS="${VIOLATIONS}$(echo "$2" | sed "s/^/[$1] /")
"
    return 0
}

if [ -n "$CANDIDATES" ]; then
    append_violations "URL 寫死" "$(echo "$CANDIDATES" | grep -E 'https?://[^[:space:]'"'"'"]+' || true)"
    append_violations "IP 寫死" "$(echo "$CANDIDATES" | grep -E "['\"]([0-9]{1,3}\.){3}[0-9]{1,3}" || true)"
    append_violations "疑似密鑰寫死" "$(echo "$CANDIDATES" | grep -iE "(api[_-]?key|secret|token|password|passwd|private[_-]?key)['\"]?[[:space:]]*(=>|=|:)[[:space:]]*['\"][A-Za-z0-9+/=_-]{16,}['\"]" || true)"
    if [ -n "$WL_NAMES" ]; then
        append_violations "白標名寫死" "$(echo "$CANDIDATES" | grep -v 'Log::' | grep -E "['\"][^'\"]*\b(${WL_NAMES})\b[^'\"]*['\"]" || true)"
    fi
fi

if [ -n "$VIOLATIONS" ]; then
    echo -e "${RED}❌ 新增行偵測到 hardcode 樣態（no-hardcode 規則）：${NC}"
    echo ""
    echo "$VIOLATIONS"
    echo -e "${RED}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${RED}  請改走 config / DB 配置（白標、環境相關值一律走配置）  ${NC}"
    echo -e "${RED}  確屬必要的字面值，請在該行加註 hardcode-ok: 原因      ${NC}"
    echo -e "${RED}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    exit 1
fi

echo -e "${GREEN}✅ no-hardcode 規則檢查通過${NC}"
