#!/bin/bash

# 檢查最新的 git commit 是否包含 migration 資料夾檔案的修改（忽略新增檔案）
# Usage: ./check_migration.sh [migration_folder_path]

# 設定預設的 migration 資料夾路徑
MIGRATION_PATH="${1:-migration}"

# 檢查是否在 git repository 中
if ! git rev-parse --git-dir > /dev/null 2>&1; then
    echo "錯誤: 當前目錄不是 git repository"
    exit 1
fi

# 檢查 migration 資料夾是否存在
if [ ! -d "$MIGRATION_PATH" ]; then
    echo "警告: migration 資料夾 '$MIGRATION_PATH' 不存在"
    echo "請確認資料夾路徑是否正確"
    exit 1
fi

# 獲取最新的 commit hash
LATEST_COMMIT=$(git rev-parse HEAD)
# 檢查最新 commit 中是否有 migration 相關的檔案修改（排除新增的檔案）
MODIFIED_MIGRATION_FILES=$(git diff-tree --no-commit-id --name-status -r $PREV_COMMIT_HASH $LATEST_COMMIT | grep "^[^A]\s*$MIGRATION_PATH/" | cut -f2)

# 檢查最新 commit 中是否有 migration 相關的新增檔案
ADDED_MIGRATION_FILES=$(git diff-tree --no-commit-id --name-status -r $PREV_COMMIT_HASH $LATEST_COMMIT | grep "^A\s*$MIGRATION_PATH/" | cut -f2)

echo "=== Git Migration 檔案檢查 ==="
echo "PREV_COMMIT_HASH: $PREV_COMMIT_HASH"
echo "檢查的 commit: $LATEST_COMMIT"
echo "檢查的資料夾: $MIGRATION_PATH/"
echo

# 優先檢查是否有修改的檔案
if [ -n "$MODIFIED_MIGRATION_FILES" ]; then
    echo "⚠ 最新的 commit 修改了以下 migration 檔案:"
    echo
    while IFS= read -r file; do
        if [ -n "$file" ]; then
            # 檢查檔案的變更類型
            STATUS=$(git diff-tree --no-commit-id --name-status -r $LATEST_COMMIT | grep "\s*$file" | cut -f1)
            case $STATUS in
                M) STATUS_TEXT="[修改]" ;;
                D) STATUS_TEXT="[刪除]" ;;
                R*) STATUS_TEXT="[重新命名]" ;;
                C*) STATUS_TEXT="[複製]" ;;
                *) STATUS_TEXT="[變更]" ;;
            esac
            echo "  $STATUS_TEXT $file"
        fi
    done <<< "$MODIFIED_MIGRATION_FILES"

    echo
    echo "=== 詳細的變更資訊 ==="
    git --no-pager show --stat $LATEST_COMMIT -- $MIGRATION_PATH/

    exit 1
elif [ -n "$ADDED_MIGRATION_FILES" ]; then
    echo "ℹ 最新的 commit 新增了以下 migration 檔案:"
    echo
    while IFS= read -r file; do
        if [ -n "$file" ]; then
            echo "  [新增] $file"
        fi
    done <<< "$ADDED_MIGRATION_FILES"

    echo
    echo "=== 詳細的變更資訊 ==="
    git --no-pager show --stat $LATEST_COMMIT -- $MIGRATION_PATH/

    exit 2
else
    echo "✓ 最新的 commit 沒有任何 migration 相關的變動"
    exit 0
fi
