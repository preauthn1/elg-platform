#!/bin/sh
# 推送 BRANCHES 列出的每個分支到 origin。
#
# 用法（環境變數必填，正常情況由 Makefile 傳入）：
#   BRANCHES="tpower-master fon666" sh push_branches.sh
#
# 失敗即停：任一分支 checkout / push 失敗即中止並回傳非 0，
# 不讓後面的分支在前面已失敗的情況下繼續推。

set -u

: "${BRANCHES:?BRANCHES 未設定（要推送的分支清單，空格分隔）}"

for branch in $BRANCHES; do
    echo "開始處理分支: $branch"

    if ! git checkout "$branch"; then
        echo "切換到 $branch 分支失敗，中止" >&2
        exit 1
    fi

    if ! git push origin "$branch"; then
        echo "推送 $branch 失敗，中止" >&2
        exit 1
    fi

    echo "成功推送 $branch"
done

echo "所有分支推送完成"
