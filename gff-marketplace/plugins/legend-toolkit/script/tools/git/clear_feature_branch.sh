#!/bin/sh
# 強制刪除本地 feature 開頭的分支（不含當前分支）
# 用法：
#   sh clear_feature_branch.sh --dryrun   # 只顯示要刪除的分支
#   sh clear_feature_branch.sh            # 直接刪除

set -eu  # e: 出錯即退出, u: 使用未定義變數即退出

DRYRUN=0
if [ "${1:-}" = "--dryrun" ]; then
    DRYRUN=1
fi

# 找出 feature 開頭的本地分支（排除當前分支）
branches=$(git branch --list "feature*" | sed 's/^[* ]*//' | grep -v "^$(git branch --show-current)$")

if [ -z "$branches" ]; then
    echo "沒有找到要刪除的 feature 分支"
    exit 0
fi

echo "找到以下分支："
echo "$branches"

if [ "$DRYRUN" -eq 1 ]; then
    echo "[DRYRUN] 不會實際刪除"
else
    echo "開始刪除..."
    echo "$branches" | xargs git branch -D
    echo "刪除完成"
fi
