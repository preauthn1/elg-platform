#!/bin/bash

# 強制 UTF-8 locale：wc -m 在 C/POSIX locale 下會退化成算 byte（CJK 每字 3 bytes），
# 與 CI 一致以「字元數」計算標題長度（中文場景），避免本地過、CI 擋的 drift。
export LC_ALL=C.UTF-8

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

COMMIT_MSG_FILE="$1"
COMMIT_MSG=$(cat "$COMMIT_MSG_FILE")

# 取第一行（標題）
TITLE=$(echo "$COMMIT_MSG" | head -n 1)

# ──────────────────────────────────────────────
# 規則 1：略過 Merge commit / fixup / squash / git revert 自動訊息
# ──────────────────────────────────────────────
if echo "$TITLE" | grep -qE "^(Merge |fixup! |squash! |Revert \"|Reapply \")"; then
    exit 0
fi

VALID=true

echo -e "${CYAN}🔍 檢查 Commit Message 格式...${NC}"

# ──────────────────────────────────────────────
# 規則 2：格式必須符合 <type>(<scope>): <description> 或 <type>: <description>
# ──────────────────────────────────────────────
VALID_TYPES="feat|fix|refactor|test|chore|docs|perf|revert|style|ci|ai|skill"
FORMAT_PATTERN="^($VALID_TYPES)(\([^)]+\))?: .+"

if ! echo "$TITLE" | grep -qE "$FORMAT_PATTERN"; then
    echo -e "${RED}❌ Commit 標題格式錯誤${NC}"
    echo -e "   期望格式：${YELLOW}<type>[可選作用域]: <描述>${NC}"
    echo -e "   有效類型：${YELLOW}${VALID_TYPES//|/、}${NC}"
    echo -e "   範例：${GREEN}feat(payment): 新增 TngPin 代收金流商${NC}"
    echo -e "   範例：${GREEN}feat: 新增 TngPin 代收金流商${NC}"
    echo -e "   你的標題：${RED}${TITLE}${NC}"
    VALID=false
fi

# ──────────────────────────────────────────────
# 規則 3：標題長度限制（130 字元）
# ──────────────────────────────────────────────
# 中文字元以 wcswidth 計算佔 2 格，此處以 byte 長度近似（UTF-8 中文 = 3 bytes）
# 改用字元數（不含 byte）以符合中文場景
TITLE_LEN=$(echo -n "$TITLE" | wc -m | tr -d ' ')
MAX_LEN=130

if [ "$TITLE_LEN" -gt "$MAX_LEN" ]; then
    echo -e "${RED}❌ Commit 標題過長（${TITLE_LEN} 字元，上限 ${MAX_LEN}）${NC}"
    echo -e "   你的標題：${RED}${TITLE}${NC}"
    VALID=false
fi

# ──────────────────────────────────────────────
# 規則 4：描述字數不得少於 4 字
# ──────────────────────────────────────────────
DESCRIPTION=$(echo "$TITLE" | sed -E "s/^($VALID_TYPES)(\([^)]+\))?: //")
DESC_LEN=$(echo -n "$DESCRIPTION" | wc -m | tr -d ' ')

if [ "$DESC_LEN" -lt 4 ]; then
    echo -e "${RED}❌ Commit 描述過短（${DESC_LEN} 字，至少需 4 字）${NC}"
    echo -e "   你的描述：${RED}${DESCRIPTION}${NC}"
    VALID=false
fi

# ──────────────────────────────────────────────
# 規則 5：Commit 必須包含 GitLab ticket 連結
#   ticket 可放在標題行或 body（第 2 行以後），兩者皆可
#   豁免類型：chore / style / ci / docs / revert / ai
# ──────────────────────────────────────────────
TICKET_REQUIRED_TYPES="feat"
# 前置邊界放寬到「非英數字」：訊息以中文寫成時 ticket 常緊貼全形標點（工單：target/tickets#4412），
# 那不是 [[:space:]]，原本會被判成缺 ticket。英數字黏在前面（xxelg/tickets#1）仍擋下。
SHORT_TICKET_PATTERN="(^|[^[:alnum:]_/-])target/(tickets|ticket_tasks|op_tickets)#[0-9]+"
GITLAB_HOST_PATTERN="[^/[:space:]]*[Gg][Ii][Tt][Ll][Aa][Bb][^/[:space:]]*"
GITLAB_URL_PATTERN="https?://$GITLAB_HOST_PATTERN/[^[:space:]]+"
REF_GITLAB_URL_PATTERN="(^|[[:space:]])Refs:[[:space:]]+$GITLAB_URL_PATTERN"
BARE_GITLAB_URL_PATTERN="^[[:space:]]*$GITLAB_URL_PATTERN"
TICKET_PATTERN="($SHORT_TICKET_PATTERN|$REF_GITLAB_URL_PATTERN|$BARE_GITLAB_URL_PATTERN)"
COMMIT_TYPE=$(echo "$TITLE" | sed -E "s/^($VALID_TYPES)(\([^)]+\))?: .*/\1/")

if echo "$COMMIT_TYPE" | grep -qE "^($TICKET_REQUIRED_TYPES)$"; then
    if ! echo "$COMMIT_MSG" | grep -qE "$TICKET_PATTERN"; then
        echo -e "${RED}❌ Commit 缺少 ticket 連結${NC}"
        echo -e "   類型 ${YELLOW}${COMMIT_TYPE}${NC} 必須附上 ticket（標題或 body 均可）"
        echo -e "   範例 1（標題）：${GREEN}feat(fuma): 新增重送 callback 命令 target/tickets#3668${NC}"
        echo -e "   範例 2（body）：${GREEN}feat: 新增重送 callback 命令${NC}"
        echo -e "             ${GREEN}target/tickets#3668${NC}"
        echo -e "             ${GREEN}Refs: http://example.invalid:1688/target/tickets/-/work_items/3837${NC}"
        echo -e "             ${GREEN}https://example.invalid/groups/foo/-/issues/3837${NC}"
        echo -e "   你的訊息：${RED}${COMMIT_MSG}${NC}"
        VALID=false
    fi
fi

# ──────────────────────────────────────────────
# 結果
# ──────────────────────────────────────────────
if [ "$VALID" = false ]; then
    echo ""
    echo -e "${RED}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${RED}  Commit 被拒絕，請修正後重新提交         ${NC}"
    echo -e "${RED}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    exit 1
fi

echo -e "${GREEN}✅ Commit Message 格式檢查通過${NC}"
exit 0
