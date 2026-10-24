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

    declare -A required_directories=(
        ["app/Models"]="Model files (*.php)"
        ["app/Services"]="Service files (*Service.php)"
        ["app/Http/Controllers"]="Controller files (*Controller.php)"
        ["app/Http/Middleware"]="Middleware files (*.php)"
        ["app/Http/Requests"]="Form request files (*Request.php)"
        ["app/Http/Resources"]="API resource files (*Resource.php)"
        ["app/Providers"]="Provider files (*Provider.php)"
        ["app/Exceptions"]="Exception files (*.php)"
        ["database/migrations"]="Migration files"
        ["database/seeders"]="Seeder files (*Seeder.php)"
        ["database/factories"]="Factory files (*Factory.php)"
        ["tests"]="Test files (*Test.php)"
    )

    for dir in "${!required_directories[@]}"; do
        if [ ! -d "$dir" ]; then
            display_error "缺少必要的目錄結構: $dir (${required_directories[$dir]})"
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

    # Check Services location
    find "app/" -name "*Service.php" -not -path "app//Services/*" | while read file; do
        display_error "Service 文件應置於 app/Services 目錄內: $file"
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

    # Service naming convention
    find app/Services -type f -name "*.php" | while read file; do
        if ! [[ $(basename "$file") =~ ^[A-Z][a-zA-Z]+Service\.php$ ]]; then
            display_error "錯誤的 service 文件名稱: $file (名稱應該使用 'Service.php' 結尾)"
        fi
    done

    # Model naming convention
    find app/Models -type f -name "*.php" | while read file; do
        if ! [[ $(basename "$file") =~ ^[A-Z][a-zA-Z]+\.php$ ]]; then
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

    # Get staged files
    files=$(git diff --cached --name-only)

    for pattern in "${unnecessary_patterns[@]}"; do
        regex_pattern=$(echo "$pattern" | sed 's/\./\\./g; s/\*/.*/g')

        for file in $files; do
            if [[ $file =~ $regex_pattern ]]; then
                display_error "不允許提交的文件名稱: $file"
            fi
        done
    done
}

# Run PHP syntax check
echo "🔍 正在執行 PHP 語法檢查..."
for FILE in $STAGED_PHP_FILES; do
    php -l "$FILE" >/dev/null 2>&1 || display_error "PHP 語法錯誤 $FILE"
done

# Run Laravel Pint
if command_exists "./vendor/bin/pint"; then
    echo "🔍 正在執行 Laravel Pint 格式化檢查..."
    ./vendor/bin/pint $files --verbose || display_error "Laravel Pint 發現以下格式問題"
    # 直接修正不輸出信息
    # ./vendor/bin/pint $files --q
fi

# Generate IDE Helper files
if [ -d "./vendor/barryvdh/laravel-ide-helper" ]; then
    echo "🔧 產生 IDE 助手文件..."
    php artisan ide-helper:generate
    php artisan ide-helper:meta
    # php artisan ide-helper:models -N
fi

# Run all structural validations
validate_directory_structure
#validate_file_locations
validate_naming_conventions
check_unnecessary_files

# Add modified files back to staging
if [ -n "$STAGED_PHP_FILES" ]; then
    git add $STAGED_PHP_FILES
fi
