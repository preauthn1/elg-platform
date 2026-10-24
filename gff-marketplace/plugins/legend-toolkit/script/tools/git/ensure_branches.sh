#!/bin/sh
# 確保 BRANCHES 列出的每個分支在本機存在；缺的直接從 origin 建立。
#
# 用法（正常情況由 Makefile 或 merge_branches.sh 傳入）：
#   BRANCHES="tpower-develop vw-develop" sh ensure_branches.sh
#
# 新 clone 的 repo 只有 master，沒有 dev / prod 分支，後續 `git checkout <分支>`
# 會直接失敗。這支負責補齊，讓上版流程在新環境也能一次跑完。
#
# 已存在的分支「完全不動」（不 fetch、不快轉），避免覆蓋本機狀態；
# 更新既有分支是 pull_branches.sh / merge_branches.sh 的職責。
# 遠端也沒有該分支時失敗即停（通常是分支名打錯）。

set -u

: "${BRANCHES:?BRANCHES 未設定（要確保存在的分支清單，空格分隔）}"

created=""
existed=""

for branch in $BRANCHES; do
    if git rev-parse --verify --quiet "refs/heads/$branch" >/dev/null; then
        existed="$existed $branch"
        continue
    fi

    echo "本機缺少 $branch，從 origin 建立…"
    if ! git fetch origin "$branch:$branch"; then
        echo "無法從 origin 建立 $branch（遠端可能沒有這支分支，或分支名打錯），中止" >&2
        exit 1
    fi
    created="$created $branch"
done

if [ -n "$created" ]; then
    echo "已從 origin 建立本機分支:$created"
fi
if [ -n "$existed" ]; then
    echo "本機已存在（未動）:$existed"
fi

exit 0
