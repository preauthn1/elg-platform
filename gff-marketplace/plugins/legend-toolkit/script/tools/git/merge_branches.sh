#!/bin/sh
# 將 SOURCE_BRANCH 合併進 BRANCHES 列出的每個分支（只合併，不推送）。
#
# 用法（兩個環境變數皆必填，正常情況由 Makefile 傳入）：
#   SOURCE_BRANCH=master BRANCHES="fon666 vplus33" sh merge_branches.sh
#
# 合併前一定先確認來源分支與 origin 同步（見下方 sync_source）：
#   - 落後 → 自動 fast-forward 更新到 origin
#   - 領先 / 分岔 → 中止（STRICT_SOURCE=1 時；否則只警告）
#   - 遠端無此分支 → 只警告（本機 feature 分支的正常情況）
#
# 目標分支若在本機不存在（新 clone 只有 master），會先由 ensure_branches.sh
# 從 origin 建立，不會再卡在 `git checkout <分支>` 失敗。
#
# 失敗即停：任一分支 checkout / pull / merge 失敗即中止並回傳非 0。
# 合併衝突會先 `git merge --abort` 還原，避免把衝突狀態留給後續 push 步驟。

set -u

: "${SOURCE_BRANCH:?SOURCE_BRANCH 未設定（要合併的來源分支）}"
: "${BRANCHES:?BRANCHES 未設定（要合併進去的目標分支清單，空格分隔）}"

# STRICT_SOURCE=1：來源分支領先或分岔於 origin 時中止。
# master 為來源的上版流程一律帶 1（Makefile 的 MERGE_FROM_MASTER），
# feature → master 則不帶（本機 feature 領先遠端是正常的）。
STRICT_SOURCE="${STRICT_SOURCE:-0}"

# 合併前確認來源分支是最新的。
sync_source() {
    if ! git rev-parse --verify --quiet "refs/heads/$SOURCE_BRANCH" >/dev/null; then
        echo "本機沒有 $SOURCE_BRANCH 分支，中止" >&2
        exit 1
    fi

    echo "檢查來源分支 $SOURCE_BRANCH 是否為最新…"
    if ! git fetch origin "+refs/heads/$SOURCE_BRANCH:refs/remotes/origin/$SOURCE_BRANCH" 2>/dev/null; then
        echo "⚠️  遠端沒有 $SOURCE_BRANCH（或 fetch 失敗），略過來源同步檢查" >&2
        [ "$STRICT_SOURCE" = "1" ] && { echo "來源必須與 origin 同步，中止" >&2; exit 1; }
        return 0
    fi

    local_sha=$(git rev-parse "$SOURCE_BRANCH")
    remote_sha=$(git rev-parse "origin/$SOURCE_BRANCH")

    if [ "$local_sha" = "$remote_sha" ]; then
        echo "✅ $SOURCE_BRANCH 與 origin 一致（$(git rev-parse --short "$local_sha")）"
        return 0
    fi

    if git merge-base --is-ancestor "$local_sha" "$remote_sha"; then
        behind=$(git rev-list --count "$local_sha..$remote_sha")
        echo "本機 $SOURCE_BRANCH 落後 origin $behind 個提交，快轉更新中…"
        if ! git checkout "$SOURCE_BRANCH"; then
            echo "切換到 $SOURCE_BRANCH 失敗，中止" >&2
            exit 1
        fi
        if ! git merge --ff-only "origin/$SOURCE_BRANCH"; then
            echo "快轉 $SOURCE_BRANCH 失敗，中止" >&2
            exit 1
        fi
        echo "✅ $SOURCE_BRANCH 已更新到 $(git rev-parse --short "$remote_sha")"
        return 0
    fi

    if git merge-base --is-ancestor "$remote_sha" "$local_sha"; then
        ahead=$(git rev-list --count "$remote_sha..$local_sha")
        msg="本機 $SOURCE_BRANCH 領先 origin $ahead 個提交（尚未 push）"
    else
        msg="本機 $SOURCE_BRANCH 與 origin 已分岔（本機與遠端各有提交）"
    fi

    if [ "$STRICT_SOURCE" = "1" ]; then
        echo "❌ $msg，中止。" >&2
        echo "   合併出去的內容會包含遠端沒有的提交；請先確認並 push $SOURCE_BRANCH 再重跑。" >&2
        exit 1
    fi
    echo "⚠️  $msg（非嚴格模式，繼續）" >&2
}

# 目標分支在本機不存在時（新 clone 只有 master）先從 origin 建起來，
# 否則下面的 git checkout 會直接失敗。
BRANCHES="$BRANCHES" sh "$(dirname "$0")/ensure_branches.sh" || exit 1

sync_source

for branch in $BRANCHES; do
    echo "開始處理分支: $branch"

    if ! git checkout "$branch"; then
        echo "切換到 $branch 分支失敗，中止" >&2
        exit 1
    fi

    if ! git pull origin "$branch"; then
        echo "拉取 $branch 最新代碼失敗，中止" >&2
        exit 1
    fi

    if ! git merge "$SOURCE_BRANCH" -m "Merge branch '$SOURCE_BRANCH' into $branch"; then
        echo "合併 $SOURCE_BRANCH 到 $branch 失敗（可能有衝突），已 git merge --abort 還原，中止" >&2
        git merge --abort || true
        exit 1
    fi

    echo "成功將 $SOURCE_BRANCH 合併到 $branch"
done

echo "所有分支合併完成（來源：$SOURCE_BRANCH）"
