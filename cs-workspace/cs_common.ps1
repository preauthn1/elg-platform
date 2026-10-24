# =========================================================
# CS Workspace 共用函式（由 setup_user.ps1 / update_cs_env.ps1 dot-source）
# 工作目錄＝解壓後的資料夾本身，cs-config.json（固定參數）隨 zip 附帶。
# =========================================================

function Get-CsConfig {
    param([string]$Path = (Join-Path $PSScriptRoot 'cs-config.json'))
    if (-not (Test-Path $Path)) {
        throw "找不到 $Path——請確認 zip 有完整解壓、且是在解壓後的資料夾內執行本工具。"
    }
    Get-Content $Path -Raw -Encoding UTF8 | ConvertFrom-Json
}

function Write-JsonNoBom {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][string]$Content
    )
    # Claude Code 讀 settings.json 不吃 BOM，一律用無 BOM 的 UTF-8 寫檔
    [IO.File]::WriteAllText($Path, $Content, (New-Object System.Text.UTF8Encoding $false))
}

function Merge-CsManagedSettings {
    <#
      把受管設定模板 merge 進使用者的 ~\.claude\settings.json：
      - extraKnownMarketplaces、enabledPlugins 兩個受管 key 整塊以模板為準（白名單移除也會同步生效）
      - 其餘個人設定原封保留
      - 寫入前備份既有檔案為 settings.json.bak
    #>
    param(
        [Parameter(Mandatory)] $Template,
        [string]$SettingsPath = (Join-Path $env:USERPROFILE '.claude\settings.json')
    )

    $settingsDir = Split-Path $SettingsPath -Parent
    if (-not (Test-Path $settingsDir)) {
        New-Item -ItemType Directory -Path $settingsDir -Force | Out-Null
    }

    $settings = [pscustomobject]@{}
    if (Test-Path $SettingsPath) {
        Copy-Item $SettingsPath "$SettingsPath.bak" -Force
        $raw = Get-Content $SettingsPath -Raw -Encoding UTF8
        if ($raw -and $raw.Trim()) {
            $settings = $raw | ConvertFrom-Json
        }
    }

    foreach ($key in @('extraKnownMarketplaces', 'enabledPlugins')) {
        if ($null -ne $Template.$key) {
            if ($settings.PSObject.Properties[$key]) {
                $settings.PSObject.Properties.Remove($key)
            }
            $settings | Add-Member -NotePropertyName $key -NotePropertyValue $Template.$key
        }
    }

    Write-JsonNoBom -Path $SettingsPath -Content ($settings | ConvertTo-Json -Depth 20)
}

function Resolve-ClaudeCli {
    <#
      找出 claude CLI 執行檔路徑。剛用 winget 裝完 Anthropic.ClaudeCode 時，
      本 session 的 PATH 還沒刷新，直接呼叫 claude 會找不到——先從登錄檔重載
      機器＋使用者 PATH，再退而搜尋常見安裝位置。回傳完整路徑，找不到則 $null。
    #>
    $machinePath = [Environment]::GetEnvironmentVariable('Path', 'Machine')
    $userPath    = [Environment]::GetEnvironmentVariable('Path', 'User')
    $env:Path = ($machinePath, $userPath | Where-Object { $_ }) -join ';'

    $cmd = Get-Command claude -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }

    foreach ($base in @($env:LOCALAPPDATA, $env:ProgramFiles, ${env:ProgramFiles(x86)})) {
        if (-not $base -or -not (Test-Path $base)) { continue }
        $hit = Get-ChildItem $base -Recurse -Filter 'claude.exe' -ErrorAction SilentlyContinue |
            Where-Object { $_.FullName -notmatch 'AnthropicClaude' } |   # 排除 Desktop app 的 claude.exe
            Select-Object -First 1
        if ($hit) { return $hit.FullName }
    }
    return $null
}

function Sync-CsPlugins {
    <#
      以 claude CLI 註冊 marketplace 並安裝白名單 plugin（settings.json 的 enabledPlugins
      只宣告、不會自動安裝外部 plugin，必須主動 install）。冪等：marketplace 已存在會忽略錯誤，
      install --yes 重跑等同確保最新已安裝。plugin 清單來自 cs-settings-template.json 的 enabledPlugins keys。
    #>
    param(
        [Parameter(Mandatory)][string]$ClaudeExe,
        [Parameter(Mandatory)]$Template,
        [Parameter(Mandatory)][string]$MarketplaceRepoUrl,
        [switch]$Update,
        [scriptblock]$Log = { param($m) Write-Host $m }
    )

    $mpName = @($Template.extraKnownMarketplaces.PSObject.Properties.Name)[0]

    if ($Update) {
        & $ClaudeExe plugin marketplace update $mpName 2>&1 | Out-Null
        & $Log "marketplace $mpName 已更新"
    }
    else {
        # add 若已存在會報錯，靜默忽略（idempotent）
        & $ClaudeExe plugin marketplace add $MarketplaceRepoUrl 2>&1 | Out-Null
        & $Log "marketplace $mpName 已註冊"
    }

    # 探測此版 CLI 是否支援 --yes（舊版沒有此旗標，直接加會報 unknown option）
    $installHelp = (& $ClaudeExe plugin install --help 2>&1 | Out-String)
    $supportsYes = $installHelp -match '(?m)^\s*-y,\s*--yes|--yes'

    foreach ($key in $Template.enabledPlugins.PSObject.Properties.Name) {
        # key 形如 memory-mcp@gff-marketplace
        $installArgs = @('plugin', 'install', $key)
        if ($supportsYes) { $installArgs += '--yes' }
        & $ClaudeExe @installArgs 2>&1 | Out-Null
        if ($LASTEXITCODE -eq 0) { & $Log "plugin 已安裝／更新：$key" }
        else { & $Log "plugin 安裝失敗（exit $LASTEXITCODE）：$key" }
    }
}
