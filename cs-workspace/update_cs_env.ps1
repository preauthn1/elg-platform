# =========================================================
# CS Workspace 更新腳本（由客服雙擊 upgrade.bat 手動執行）
# 職責：1) 從 cs-workspace repo 抓最新受管設定模板套用（白名單 SSOT）  2) 透過 claude CLI 更新 marketplace 並確保白名單 plugin 為最新。
# 失敗會顯示訊息並寫 log（同資料夾的 update.log），重跑一次即可。
# =========================================================
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'cs_common.ps1')

$logFile = Join-Path $PSScriptRoot 'update.log'
function Write-Log([string]$msg) {
    Write-Host $msg
    Add-Content -Path $logFile -Value ('[{0}] {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $msg)
}

Write-Host '=== CS Workspace 更新開始 ===' -ForegroundColor Green

try {
    $cfg = Get-CsConfig

    # 1) 抓最新受管設定模板並 merge（白名單異動由此派送，不用重發安裝包）
    $template = $null
    $pat = [Environment]::GetEnvironmentVariable('GITLAB_PERSONAL_ACCESS_TOKEN', 'User')
    # 清掉控制字元（換行/CR/tab 等）——PAT 若混入會讓 HTTP header 拋 "invalid Control characters"
    if ($pat) { $pat = ($pat -replace '[\x00-\x1F\x7F]', '').Trim() }
    if ($pat) {
        $url = "$($cfg.gitlabBaseUrl)/api/v4/projects/$($cfg.workspaceProject)/repository/files/cs-settings-template.json/raw?ref=main"
        $resp = Invoke-RestMethod -Uri $url -Headers @{ 'PRIVATE-TOKEN' = $pat }
        $template = if ($resp -is [string]) { $resp | ConvertFrom-Json } else { $resp }
        Merge-CsManagedSettings -Template $template
        Write-Log '受管設定已套用最新模板'
    }
    else {
        # 無 PAT 時退回隨包模板（白名單仍生效，只是拿不到雲端最新版）
        $template = Get-Content (Join-Path $PSScriptRoot 'cs-settings-template.json') -Raw -Encoding UTF8 | ConvertFrom-Json
        Merge-CsManagedSettings -Template $template
        Write-Log '找不到 GITLAB_PERSONAL_ACCESS_TOKEN（尚未跑 FirstLogin.bat？），改用隨包模板套用白名單'
    }

    # 2) 更新 marketplace 並確保白名單 plugin 已安裝／為最新（透過 claude CLI）
    $claudeExe = Resolve-ClaudeCli
    if ($claudeExe) {
        $mpName = @($template.extraKnownMarketplaces.PSObject.Properties.Name)[0]
        $mpUrl  = $template.extraKnownMarketplaces.$mpName.source.url
        Sync-CsPlugins -ClaudeExe $claudeExe -Template $template -MarketplaceRepoUrl $mpUrl -Update -Log { param($m) Write-Log $m }
    }
    else {
        Write-Log '找不到 claude CLI，略過 marketplace/plugin 更新（請確認已裝 Anthropic.ClaudeCode）'
    }

    Write-Host '=== 更新完成，重開 Claude Desktop app 生效 ===' -ForegroundColor Green
}
catch {
    Write-Log "更新失敗：$($_.Exception.Message)"
    Write-Host '更新失敗，請重跑一次；持續失敗請把本資料夾的 update.log 給 IT。' -ForegroundColor Red
}
