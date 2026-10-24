#!/bin/bash

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Exit when any command fails
set -e

echo -e "${YELLOW}Running pre-commit hooks...${NC}"

files=$(git diff --cached --name-only --diff-filter=ACM -- '*.php')

# 同時有未 stage 修改的 .php（逐 hunk 分批提交時會出現）：結尾不整檔加回，避免把沒打算提交的修改收進 commit
partial_files=""
if [ -n "$files" ]; then
    partial_files=$(git diff --name-only -- $files)
fi

# Function to display error
display_error() {
    echo -e "${RED}❌ $1${NC}"
    exit 1
}

# Function to check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Directory Structure Validation
validate_directory_structure() {
    echo "📁 檢查專案目錄結構規則..."

    local required_directories=(
        "app/Models"
        "app/Http/Controllers"
        "app/Http/Middleware"
        "app/Http/Requests"
        "app/Http/Resources"
        "app/Providers"
        "app/Exceptions"
        "database/migrations"
        "database/seeders"
        "database/factories"
        "tests"
    )

    for dir in "${required_directories[@]}"; do
        if [ ! -d "$dir" ]; then
            display_error "缺少必要的目錄結構: $dir"
        fi
    done
}

# File Location Validation
validate_file_locations() {
    echo "📍 驗證文件路徑規則..."

    # Check Models location
    find "app/" -name "*.php" -not -path "app//Models/*" | while read file; do
        if grep -q "^class.*extends.*Model" "$file"; then
            display_error "Model 文件應置於 app/Models 目錄內: $file"
        fi
    done

    # Check Migration naming
        # find database/ -name "*.php" -path "database/migrations/*" | while read file; do
        #     if ! [[ $(basename "$file") =~ ^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_.*\.php$ ]]; then
        #         display_error "Invalid migration filename format: $file"
        #     fi
        # done
}

# Naming Convention Validation
validate_naming_conventions() {
    echo "✍️ 驗證文件命名規則..."

    # Model naming convention
    find app/Models -type f -name "*.php" | while read file; do
        if ! [[ $(basename "$file") =~ ^[A-Z][a-zA-Z0-9]+\.php$ ]]; then
            display_error "錯誤的 model 文件名稱: $file (應使用大駝峰命名)"
        fi
    done
}

# Check for unnecessary files
check_unnecessary_files() {
    echo "🧹 檢查不允許提交的文件名稱..."

    unnecessary_patterns=(
        "*.log"
        "*.swap"
        "*.swo"
        "*.swp"
        "*~"
        ".DS_Store"
        "Thumbs.db"
        ".idea/"
        ".vscode/"
        "*.tmp"
        "*.temp"
        "*.cache"
    )

    # Get staged files（使用 local 變數，避免覆蓋外層僅含 .php 的 $files）
    local all_staged_files
    all_staged_files=$(git diff --cached --name-only)

    for pattern in "${unnecessary_patterns[@]}"; do
        regex_pattern=$(echo "$pattern" | sed 's/\./\\./g; s/\*/.*/g')

        for file in $all_staged_files; do
            if [[ $file =~ $regex_pattern ]]; then
                display_error "不允許提交的文件名稱: $file"
            fi
        done
    done
}

# Run PHP syntax check
if command_exists "php"; then
    echo "🔍 正在執行 PHP 語法檢查..."
    for FILE in $files; do
        php -l "$FILE" >/dev/null 2>&1 || display_error "PHP 語法錯誤 $FILE"
    done
else
    echo "⚠️  php 不在 PATH，跳過 PHP 語法檢查"
fi

# Run Laravel Pint
# 部分路徑排除 declare_strict_types，使用獨立設定檔 pint-legacy.json
LEGACY_DIRS=(
    "app/Http/Controllers/External/SingleWallets"
    "app/Http/Controllers/External/TransferWallets"
)

if command_exists "php" && command_exists "./vendor/bin/pint"; then
    echo "🔍 正在執行 Laravel Pint 格式化檢查..."

    main_files=""
    legacy_files=""

    for file in $files; do
        is_legacy=false
        for dir in "${LEGACY_DIRS[@]}"; do
            if [[ "$file" == "$dir"* ]]; then
                is_legacy=true
                break
            fi
        done
        if $is_legacy; then
            legacy_files="$legacy_files $file"
        else
            main_files="$main_files $file"
        fi
    done

    if [ -n "$main_files" ]; then
        ./vendor/bin/pint $main_files --verbose || display_error "Laravel Pint 發現以下格式問題"
    fi

    if [ -n "$legacy_files" ]; then
        ./vendor/bin/pint --config pint-legacy.json $legacy_files --verbose || display_error "Laravel Pint (legacy) 發現以下格式問題"
    fi
fi

# Generate IDE Helper files
#if [ -d "./vendor/barryvdh/laravel-ide-helper" ]; then
#    echo "🔧 產生 IDE 助手文件..."
#    php artisan ide-helper:generate
#    php artisan ide-helper:meta
#    # php artisan ide-helper:models -N
#fi

# Run PHPMD cyclomatic complexity check on staged PHP files
check_phpmd() {
    if [ -z "$files" ]; then
        return
    fi

    if ! command_exists "php"; then
        echo "⚠️  php 不在 PATH，跳過 PHPMD 圈複雜度檢查"
        return
    fi

    if [ ! -f "vendor/bin/phpmd" ]; then
        echo "⚠️  vendor/bin/phpmd 不存在，跳過圈複雜度檢查"
        return
    fi

    echo "🔍 正在執行 PHPMD 圈複雜度檢查..."

    # 將 staged PHP 檔案轉為逗號分隔（PHPMD 接受多個路徑）
    # sed 's/,$//' 去除 tr 產生的尾隨逗號，避免 PHPMD 收到空字串路徑
    PHPMD_FILES=$(echo "$files" | tr '\n' ',' | sed 's/,$//')

    PHPMD_ARGS=".phpmd.xml"
    if [ -f "phpmd.baseline.xml" ]; then
        PHPMD_ARGS="$PHPMD_ARGS --baseline-file phpmd.baseline.xml"
    fi

    PHPMD_EXIT=0
    PHPMD_OUTPUT=$(php -d error_reporting=E_ALL^E_DEPRECATED vendor/bin/phpmd \
        "$PHPMD_FILES" text $PHPMD_ARGS \
        --suffixes php \
        2>&1) || PHPMD_EXIT=$?

    CLEAN_OUTPUT=$(echo "$PHPMD_OUTPUT" | grep -v "^Deprecated:" || true)

    if [ -n "$CLEAN_OUTPUT" ]; then
        echo ""
        echo "⚠️  PHPMD 發現以下問題："
        echo ""
        echo "$CLEAN_OUTPUT"
        echo ""
    fi

    if [ "$PHPMD_EXIT" -ne 0 ]; then
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
        echo "  ❌ 圈複雜度超過 10，請拆分方法後重新提交"
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
        exit 1
    fi

    echo "✅ 圈複雜度檢查通過"
}

# Run no-hardcode rule on staged added lines (logic lives in repo so updates don't require re-install)
check_hardcode() {
    if [ ! -f ".githooks/check-hardcode.sh" ]; then
        echo "⚠️  .githooks/check-hardcode.sh 不存在，跳過 no-hardcode 檢查"
        return
    fi

    bash .githooks/check-hardcode.sh --cached || display_error "no-hardcode 規則未通過（新增行含 hardcode，見上方清單）"
}

# Reject UTF-8 BOM (logic shared with CI job lint:no-bom)
check_bom() {
    if [ ! -f ".githooks/check-bom.sh" ]; then
        echo "⚠️  .githooks/check-bom.sh 不存在，跳過 BOM 檢查"
        return
    fi

    bash .githooks/check-bom.sh --cached || display_error "BOM 檢查未通過（見上方清單）"
}

# Run all structural validations
validate_directory_structure
#validate_file_locations
validate_naming_conventions
check_unnecessary_files
check_phpmd
check_hardcode
check_bom

# Pint 會直接改檔，改完的檔案加回 staging；同時有未 stage 修改的檔案不加回，只提醒
restage_files=""
for FILE in $files; do
    if printf '%s\n' "$partial_files" | grep -qxF -- "$FILE"; then
        echo -e "${YELLOW}⚠️  $FILE 有未 stage 的修改，沒有自動加回；Pint 若改了格式，請確認後自行 stage${NC}"
    else
        restage_files="$restage_files $FILE"
    fi
done
if [ -n "$restage_files" ]; then
    git add $restage_files
fi
