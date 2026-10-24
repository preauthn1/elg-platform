# gff-marketplace

> 本目录原始 README 见文末，本节为快照补充信息。

原始路径：`gff-marketplace`　|　文件 **306**　|　主语言：JSON 22, Shell 15, PHP 13

## 目录构成

```
plugins/apis-toolkit/  — 74
plugins/dev-toolkit/  — 44
plugins/op-toolkit/  — 36
plugins/legend-toolkit/  — 16
plugins/gitlab-mcp/  — 9
plugins/qa-toolkit/  — 8
plugins/wiki-toolkit/  — 8
scripts/  — 7
plugins/codegraph-mcp/  — 6
docs/  — 5
plugins/memory-mcp/  — 5
evals/repair-rebuild-sbr/  — 4
```

## 关键文件

- CI/构建：`.gitlab-ci.yml`、`plugins/legend-toolkit/script/Makefile`

---

## 原始说明

# GFF-Marketplace

> target GFFv3 內部 Claude Code plugin marketplace：PM / 維運 / 客服 / 開發共用的 plugin 集合（skill / MCP server / 工作流程）。

## 這是什麼

**受眾與目的**：給 mac 與 Windows 開發者使用，兩平台皆一等支援；除了當日常工具，也藉這個 marketplace 實作並示範多種 Anthropic / Claude Code 能力（skill / MCP server / hook / agent / command），讓開發團隊邊用邊學各種用法與技巧。

集中存放公司 Claude skill 與相關 MCP server 設定，以 10 個 plugin 切分；`gitlab-mcp` / `gcp-mcp` 為全員共用基礎，其餘按工作目的選裝：

| Plugin | 內容 | 主要使用者 |
|---|---|---|
| `gitlab-mcp` | GitLab MCP server + PAT 注入（PAT 設定 SSOT 見 plugin README）＋ 工單小工具 `gitlab-workitem`（掛父子關係、讀改起訖日、描述存檔送回） | 所有需 GitLab MCP 的同事 |
| `gcp-mcp` | GCP Observability MCP server（Cloud Logging 查詢基礎；純 MCP，gcp-log-troubleshoot 已遷 apis-toolkit） | 全員 |
| `memory-mcp` | 共用長期記憶 MCP server（memory，直連 LAN 共享服務） | 全員 |
| `codegraph-mcp` | CodeGraph 符號知識圖 MCP server（callers / callees / impact；不含 skill，附開 session 自動同步索引的 hook）；各專案需自行 `codegraph init` 建索引 | 開發（選裝） |
| `op-toolkit` | 建工單流程（PM 需求單 / OP 線上問題單）＋ 讀 OP 單並指路排查 ＋ 改工單描述 ＋ 需求單示意圖 | PM / 維運 / 客服 / 開發 |
| `dev-toolkit` | 開發流程（git-commit / release-workflow / branch-sync / gitlab-release）＋ 設計與文件方法（design-grilling / domain-modeling / tdd / bug-diagnosis）＋ harness 稽核（harness-audit）＋ 本地記憶整理（memory-tidy） | 開發 |
| `apis-toolkit` | APIs 領域一站式（金流/遊戲商接入 + DB 連線切換 + 對帳 + 報表 + 白標 registry + GCP log 排查 + scaffolding 命令 + agent）；另附一道 PreToolUse guard hook，fail-closed 攔破壞性 Artisan DB 命令（細節見 `plugins/apis-toolkit/hooks/README.md`） | 開發 |
| `legend-toolkit` | Legend 系列舊專案維運（RD-Studio 多站台部署 deploy-rd-studio ＋ WEB_AgentBackend / API_Agent / WEB_CustomerServiceSocket 分支上版 deploy-agent-stack ＋ 人工 make 腳本 `script/`） | Legend 維運 / 開發 |
| `qa-toolkit` | QA 用 dev 站點瀏覽/操作（Playwright，可讀可寫）＋操作側錄成可重播腳本；自帶 Playwright MCP、opt-in 不影響其他 plugin | QA / 開發（選裝） |
| `wiki-toolkit` | 寫 APIS 知識庫 wiki（Outline）：功能說明頁、操作手冊、發頁後建魔鏡 pointer；自帶 Outline MCP，依賴 memory-mcp | RD / PM / QA（選裝） |

## Quick install

第一次安裝請從 [ONBOARDING.md](ONBOARDING.md) 開始；那份文件會分 macOS / Windows 帶你完成 Node.js、GitLab PAT、GCP ADC、Git 認證與驗證。

下面只保留已完成前置設定後，在 Claude Code 內要跑的 plugin 指令。

依賴會自動遞迴安裝（需 Claude Code v2.1.143+）：`op-toolkit` 與 `dev-toolkit` 都依賴 `gitlab-mcp`（裝任一即自動帶）；`apis-toolkit` 依賴 `dev-toolkit` / `gcp-mcp`，遞迴帶 `dev-toolkit` + `gcp-mcp` + `gitlab-mcp`。日常角色安裝（`op-toolkit` / `dev-toolkit`）不會帶入 `gcp-mcp`，所以全員仍需先手動安裝 foundation MCP。

執行方式：先跑「全員基礎 MCP」兩行，再依你的角色只選一組角色指令。

```
/plugin marketplace add http://example.invalid:1688/target/gffv3/gff-marketplace.git

# 全員基礎 MCP：
/plugin install gitlab-mcp
/plugin install gcp-mcp
/plugin install memory-mcp            # key 由環境變數 MEMORY_API_KEY 提供（見 plugin README）

# 維運 / 客服（/plugin install 一次一個 plugin，逐支一行）：
/plugin install op-toolkit

# 開發者：
/plugin install op-toolkit
/plugin install dev-toolkit
/plugin install codegraph-mcp         # 選裝：符號關係查詢，裝完需各專案自行建索引（見 plugin README）

# 寫 wiki（RD / PM / QA 選裝；需 OUTLINE_MCP_URL、OUTLINE_API_KEY 兩個環境變數，見 plugin README）：
/plugin install wiki-toolkit

/reload-plugins
/mcp                                  # 驗證 gitlab / observability server connected
```

GitLab MCP PAT 設定請依 [gitlab-mcp README](plugins/gitlab-mcp/README.md#gitlab-pat-設定-ssot)；不要把 PAT 寫進 repo。

> `apis-toolkit` 為 APIs 專案專屬、`legend-toolkit` 為 Legend 系列專案專屬，皆於各自 repo 內使用（不在全域逐角色安裝集）。

## 更新（marketplace 異動後）

本 marketplace 異動（plugin / skill 更新）後，每個人要把已裝的 plugin 換到新 commit。統一做法是兩步：每個人開一次自動更新，要馬上拿到新版時在對話框跑兩行命令。兩步都在 Claude Code 對話框裡完成，Mac、Windows、Linux 做法相同。

### 1. 開啟自動更新（每個人設一次）

在 Claude Code 輸入 `/plugin` → 切到 **Marketplaces** 分頁 → 選 `gff-marketplace` → **Enable auto-update**。

gff-marketplace 屬於第三方市集，Claude Code 預設不替它自動更新，所以要自己開。開了之後，每次啟動 Claude Code，它會在背景（啟動後十分鐘內）更新市集清單並把已裝的 plugin 換到新 commit。有更新時畫面會提示，照提示輸入 `/reload-plugins` 就能在當下的 session 套用，不處理的話下次啟動也會自動套用。來源：[Configure auto-updates](https://example.invalid/docs/en/discover-plugins#configure-auto-updates)。

### 2. 要馬上拿到新版

不想等下次啟動，在對話框輸入：

```
/plugin marketplace update gff-marketplace
/reload-plugins
```

第一行更新市集清單，並把已裝的 plugin 一併換到新 commit，輸出會顯示換了幾支（例如 `✔ Updated 1 marketplace (8 plugins bumped)`）。第二行讓當下的 session 套用新版。有沒有開自動更新都一樣會換版（Claude Code 2.1.280 實測；官方文件只寫這個命令會更新市集清單，沒寫到已裝的 plugin）。

輸出顯示 `0 plugins bumped`、但確定 main 有新版時，多半是 Claude Code 版本較舊：先用 `claude --version` 確認版本，升級 Claude Code 後再跑一次。

不要用 `/plugin update <plugin>@gff-marketplace`：它會打開 plugin 選單，不會直接更新（Claude Code 2.1.280 實測）。

## 完整移除 / 重置

```
/plugin marketplace remove gff-marketplace        # 移除來源 ＋ 連帶卸載所有已裝 plugin
rm -rf ~/.claude/plugins/cache/gff-marketplace    # 立即清 cache（否則 orphan 7 天後才自動清，重裝可能撞 No plugins match）
```

> `marketplace remove` 會連同卸載所有從本 marketplace 安裝的 plugin，**不需**逐一 `/plugin uninstall`；cache 採 7 天 grace period 才自動清，冷啟重裝前手動 `rm -rf` 最乾淨。

## 貢獻與發布

開發者貢獻規範（變更流程、breaking change、commit-SHA 版本制、CI、reviewer）完整見 [CONTRIBUTING.md](CONTRIBUTING.md)（規則 SSOT）。本檔不複述規則。

## 文件入口

- [文件總覽](docs/README.md)
- [Plugin catalogue](docs/plugin-catalogue.md)

