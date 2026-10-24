#!/bin/sh
# 為指定分支建立時間戳記 tag（預設 _online_YYYYMMDDHHMM）並推送到 origin。
#
# 用法（正常情況由 Makefile 傳入）：
#   TAG_BRANCH=vw-master sh tag_release.sh
#   TAG_BRANCH=vw-master TAG_PREFIX=_onlinev2_ sh tag_release.sh

set -u

: "${TAG_BRANCH:?TAG_BRANCH 未設定（要建立 tag 的分支）}"
TAG_PREFIX="${TAG_PREFIX:-_online_}"

if ! git checkout "$TAG_BRANCH"; then
    echo "切換到 $TAG_BRANCH 分支失敗" >&2
    exit 1
fi

if ! git pull origin "$TAG_BRANCH"; then
    echo "拉取 $TAG_BRANCH 最新代碼失敗" >&2
    exit 1
fi

TAG_NAME="${TAG_PREFIX}$(date +%Y%m%d%H%M)"

if ! git tag "$TAG_NAME"; then
    echo "建立 tag $TAG_NAME 失敗（同名 tag 可能已存在）" >&2
    exit 1
fi

if ! git push origin "$TAG_NAME"; then
    echo "推送 tag $TAG_NAME 失敗" >&2
    exit 1
fi

echo "Tag $TAG_NAME 已建立並推送成功。"
