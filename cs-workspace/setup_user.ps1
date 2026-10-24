# =========================================================
# CS Workspace 首次設定（一般權限，由客服用自己的 Windows 帳號執行）
# 職責：收個人憑證、寫使用者環境變數、存 Git 憑證、套用受管設定、GCP ADC 登入、
#       安裝 Claude Desktop（per-user）、建桌面捷徑。
# =========================================================
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'cs_common.ps1')
$cfg = Get-CsConfig
$gitlabUri = [Uri]$cfg.gitlabBaseUrl   # Authority 含自訂 port（如 example.invalid:1688）

Write-Host '=== CS Workspace 首次設定開始 ===' -ForegroundColor Green

# 1) 輸入個人憑證
$gitlabUser = Read-Host '請輸入你的 GitLab 帳號'
$patSecure  = Read-Host '請輸入你的 GitLab Personal Access Token（scope: api）' -AsSecureString
$pat = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($patSecure))
# 清掉控制字元與前後空白——貼上時混入的換行/CR 會讓後續 HTTP header 拋 "invalid Control characters"
if ($pat) { $pat = ($pat -replace '[\x00-\x1F\x7F]', '').Trim() }
if (-not $pat) {
    Write-Host '【錯誤】Token 不可為空。' -ForegroundColor Red
    exit 1
}
$mirrorKey = Read-Host '請輸入魔鏡（知識庫）API key（沒有就直接按 Enter）'

# 2) 寫入使用者環境變數（GFF-Marketplace 各 plugin 的 MCP 設定會引用；
#    GITLAB_PERSONAL_ACCESS_TOKEN 供 upgrade.bat 抓模板用；MEMORY_API_KEY 供 memory-mcp 用（已與各 plugin .mcp.json 核對）
#    gitlab-mcp 的 PAT 走 plugin user_config（首次啟用時 app 會提示輸入，輸同一組即可）
[Environment]::SetEnvironmentVariable('GITLAB_PERSONAL_ACCESS_TOKEN', $pat, 'User')
if ($mirrorKey) {
    [Environment]::SetEnvironmentVariable('MEMORY_API_KEY', $mirrorKey, 'User')   # memory-mcp 的 .mcp.json 以 X-API-Key header 引用（已核對）
}

# 3) PAT 存進 Git Credential Manager，marketplace 的 clone / pull 用同一組憑證
"protocol=$($gitlabUri.Scheme)`nhost=$($gitlabUri.Authority)`nusername=$gitlabUser`npassword=[REDACTED]" | git credential approve

# 4) 套用受管設定（marketplace 來源＋plugin 白名單），既有個人設定保留
$template = Get-Content (Join-Path $PSScriptRoot 'cs-settings-template.json') -Raw -Encoding UTF8 | ConvertFrom-Json
Merge-CsManagedSettings -Template $template
Write-Host '已寫入 Claude 設定（marketplace 與 plugin 白名單）。' -ForegroundColor Cyan

# 5) GCP：設定預設專案並做 ADC 登入（會開瀏覽器，請用公司 Google 帳號登入）
Write-Host '接著進行 GCP 登入，瀏覽器跳出後請用公司 Google 帳號完成授權 ...' -ForegroundColor Yellow
gcloud config set project $cfg.defaultGcpProject
gcloud auth application-default login

# 6) 安裝 Claude Desktop app（winget 套件 Anthropic.Claude；Squirrel per-user 安裝器，
#    必須在客服本人 session 執行——放管理員階段會裝進提權帳號的 profile）
Write-Host '安裝 Claude Desktop app ...' -ForegroundColor Yellow
winget install -e --id Anthropic.Claude --accept-source-agreements --accept-package-agreements --silent

# 7) 安裝 Claude Code CLI 並主動安裝白名單 plugin
#    settings.json 的 enabledPlugins 只宣告不安裝，外部 plugin 必須用 CLI 主動 install；
#    Desktop app 不隨附 claude CLI，故另裝 Anthropic.ClaudeCode（與 app 共用 ~/.claude）。
Write-Host '安裝 Claude Code CLI 並佈署白名單 plugin ...' -ForegroundColor Yellow
winget install -e --id Anthropic.ClaudeCode --accept-source-agreements --accept-package-agreements --silent
$claudeExe = Resolve-ClaudeCli
if ($claudeExe) {
    $mpName = @($template.extraKnownMarketplaces.PSObject.Properties.Name)[0]
    $mpUrl  = $template.extraKnownMarketplaces.$mpName.source.url
    Sync-CsPlugins -ClaudeExe $claudeExe -Template $template -MarketplaceRepoUrl $mpUrl
    Write-Host '白名單 plugin 已安裝。' -ForegroundColor Cyan
}
else {
    Write-Host '找不到 claude CLI（PATH 可能需重開視窗才生效）；請安裝完成後雙擊 upgrade.bat 補裝 plugin。' -ForegroundColor Yellow
}

# 8) 建立 Claude Desktop 桌面捷徑（找不到只提示，不中斷流程）
try {
    $desktopLnk = Join-Path ([Environment]::GetFolderPath('Desktop')) 'Claude.lnk'
    # 優先複製開始選單既有捷徑（含正確 icon 與參數）
    $startMenus = @(
        (Join-Path $env:APPDATA 'Microsoft\Windows\Start Menu\Programs'),
        (Join-Path $env:ProgramData 'Microsoft\Windows\Start Menu\Programs')
    )
    $existing = $startMenus |
        Where-Object { Test-Path $_ } |
        ForEach-Object { Get-ChildItem $_ -Recurse -Filter 'Claude*.lnk' -ErrorAction SilentlyContinue } |
        Select-Object -First 1
    if ($existing) {
        Copy-Item $existing.FullName $desktopLnk -Force
        Write-Host '已建立桌面捷徑（複製自開始選單）。' -ForegroundColor Cyan
    }
    else {
        # 備援：直接找 claude.exe 建捷徑
        $exe = @(
            (Join-Path $env:LOCALAPPDATA 'AnthropicClaude'),
            (Join-Path $env:LOCALAPPDATA 'Programs')
        ) |
            Where-Object { Test-Path $_ } |
            ForEach-Object { Get-ChildItem $_ -Recurse -Filter 'claude.exe' -ErrorAction SilentlyContinue } |
            Select-Object -First 1
        if ($exe) {
            $shell = New-Object -ComObject WScript.Shell
            $lnk = $shell.CreateShortcut($desktopLnk)
            $lnk.TargetPath = $exe.FullName
            $lnk.WorkingDirectory = $exe.DirectoryName
            $lnk.Save()
            Write-Host '已建立桌面捷徑。' -ForegroundColor Cyan
        }
        else {
            Write-Host '找不到 Claude Desktop 安裝位置，略過桌面捷徑（可自行從開始選單釘選）。' -ForegroundColor Yellow
        }
    }
}
catch {
    Write-Host "桌面捷徑建立失敗（不影響安裝）：$($_.Exception.Message)" -ForegroundColor Yellow
}

Write-Host ''
Write-Host '=== 首次設定完成！ ===' -ForegroundColor Green
Write-Host '最後一步：開啟桌面的 Claude 捷徑，登入公司 Anthropic 帳號後切到「Code」分頁即可開始使用。' -ForegroundColor Cyan
