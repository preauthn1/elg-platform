#!/bin/sh
# 拉取 BRANCHES 列出的每個分支最新代碼。
#
# 用法（環境變數必填，正常情況由 Makefile 傳入）：
#   BRANCHES="tpower-master vw-master" sh pull_branches.sh
#
# 逐分支容錯（某分支本機不存在時不影響其他分支），但結尾會列出失敗清單並以非 0 結束，
# 避免出現「有分支沒拉到卻顯示完成」。

set -u

: "${BRANCHES:?BRANCHES 未設定（要拉取的分支清單，空格分隔）}"

failed=""

for branch in $BRANCHES; do
    echo "開始處理分支: $branch"

    if ! git checkout "$branch"; then
        echo "切換到 $branch 分支失敗，跳過" >&2
        failed="$failed $branch"
        continue
    fi

    if ! git pull origin "$branch"; then
        echo "拉取 $branch 最新代碼失敗，跳過" >&2
        failed="$failed $branch"
        continue
    fi

    echo "成功拉取 $branch 最新代碼"
done

if [ -n "$failed" ]; then
    echo "以下分支未成功拉取：$failed" >&2
    exit 1
fi

echo "所有分支拉取完成"
