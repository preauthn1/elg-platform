# =========================================================
# CS Workspace 安裝腳本（管理員階段）
# 職責：只裝 winget 三件套（Claude Desktop 為 Squirrel per-user 安裝器，移至階段 2 由客服本人安裝，避免 UAC 提權帳號不同時裝錯 profile）。解壓後的資料夾即工作目錄——FirstLogin.bat、upgrade.bat、
# cs-config.json（固定參數，隨 zip 附帶）都在本資料夾內執行，不另建目錄。
# 個人憑證與 Claude 設定在重開機後由客服自己執行 FirstLogin.bat（一般權限）處理，
# 避免 UAC 提權用了別的管理員帳號，把憑證寫進錯誤的使用者設定檔。
# =========================================================
$ErrorActionPreference = 'Stop'


# 1) 權限檢查
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Host '【錯誤】請以系統管理員身分執行（雙擊 Install.bat 會自動提權）。' -ForegroundColor Red
    Pause
    exit 1
}

Write-Host '=== CS Workspace 安裝（管理員階段）開始 ===' -ForegroundColor Green

# 2) winget 安裝依賴：Node.js（plugin 的 npx MCP server 用）、Git（marketplace clone 用）、GCP SDK（查 log 憑證用）
foreach ($pkg in @('OpenJS.NodeJS.LTS', 'Git.Git', 'Google.CloudSDK')) {
    Write-Host "安裝 $pkg ..." -ForegroundColor Yellow
    winget install -e --id $pkg --accept-source-agreements --accept-package-agreements --silent
}

Write-Host ''
Write-Host '=== 管理員階段完成 ===' -ForegroundColor Green
Write-Host '請重新開機，之後以客服自己的帳號登入 Windows，回到本資料夾雙擊 FirstLogin.bat 完成個人設定。' -ForegroundColor Cyan
Write-Host '（本資料夾請保留，之後更新工具就是雙擊裡面的 upgrade.bat）' -ForegroundColor Cyan
Pause
