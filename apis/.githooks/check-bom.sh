#!/bin/bash
#
# 檢查檔案開頭的 UTF-8 BOM（EF BB BF）。
#
# 為什麼要擋：
#   BOM 位於 `<?php` 之前，屬於 PHP 的 inline HTML，會在 script 一啟動就被輸出到
#   回應最前面。對本專案（純 API）等同每個 JSON 回應前面多一個看不見的字元，
#   前端 JSON.parse 直接拋 SyntaxError；shell script 的 BOM 則會讓 shebang 失效。
#
#   2026-08-19 WEB_AgentBackend 就發生過：一次 merge 解衝突把 11 個語系檔存成帶
#   BOM，後台在非繁中語系下所有 AJAX 全滅（畫面卡住／跳 error），但繁體中文完全
#   正常，查了很久才定位。本專案目前全庫零 BOM，故採零容忍。
#
# 用法：
#   bash .githooks/check-bom.sh --cached   # 檢查 staged 檔案（pre-commit 用）
#   bash .githooks/check-bom.sh --all      # 檢查全部受版控檔案（CI 用）
#
# 退出碼：0 = 通過；1 = 發現 BOM；2 = 參數錯誤

set -uo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

MODE="${1:-}"

case "$MODE" in
    --cached)
        FILE_LIST=$(git diff --cached --name-only --diff-filter=ACM)
        SCOPE_DESC="staged 檔案"
        ;;
    --all)
        FILE_LIST=$(git ls-files)
        SCOPE_DESC="全部受版控檔案"
        ;;
    *)
        echo "用法: bash .githooks/check-bom.sh [--cached|--all]" >&2
        exit 2
        ;;
esac

# 只檢查「BOM 會造成實際故障」的檔案類型：
#   php/stub  → 輸出污染（本專案最致命）
#   json/yml  → parser 失敗
#   sh        → shebang 失效
is_target() {
    case "$1" in
        *.php|*.stub|*.json|*.yml|*.yaml|*.sh) return 0 ;;
        *) return 1 ;;
    esac
}

bom_files=""

for f in $FILE_LIST; do
    is_target "$f" || continue
    [ -f "$f" ] || continue

    if [ "$MODE" = "--cached" ]; then
        # 讀 staged 內容而非工作區，確保檢查的就是即將提交的版本
        head3=$(git show ":$f" 2>/dev/null | od -An -N3 -tx1 | tr -d ' \n')
    else
        head3=$(od -An -N3 -tx1 "$f" | tr -d ' \n')
    fi

    if [ "$head3" = "efbbbf" ]; then
        bom_files="$bom_files $f"
    fi
done

if [ -n "$bom_files" ]; then
    echo ""
    echo -e "${RED}❌ 以下檔案開頭含 UTF-8 BOM：${NC}"
    echo ""
    for f in $bom_files; do
        echo "    $f"
    done
    echo ""
    echo -e "${YELLOW}  BOM 會在 PHP script 啟動時就被輸出到回應最前面，讓 JSON 回應多出一個${NC}"
    echo -e "${YELLOW}  看不見的字元、前端 JSON.parse 直接失敗；shell script 則會 shebang 失效。${NC}"
    echo ""
    echo "  修法擇一："
    echo "    1) 編輯器另存為「UTF-8（不含 BOM）」"
    echo "    2) 批次移除（只吃掉開頭 3 個 byte，內容零改動）："
    printf '       perl -0777 -i -pe %s%s\n' "'s/\A\xEF\xBB\xBF//'" "$bom_files"
    echo ""
    echo -e "${RED}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${RED}  禁止提交帶 UTF-8 BOM 的檔案                       ${NC}"
    echo -e "${RED}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    exit 1
fi

echo -e "${GREEN}✅ BOM 檢查通過（${SCOPE_DESC}）${NC}"
exit 0
