# AI 工具使用指南

> 本文件介紹 APIs 專案裡 Claude Code 可用的 **Commands**、**Skills** 與審查用的 **Agents**：各自放在哪、怎麼叫、什麼時候用，供團隊成員快速上手 AI 輔助開發。
> 大部分 skill 與 command 已搬到公司的 plugin 市集 GFF-Marketplace，本專案的 `.claude/` 只留少數專案專屬的檔案。

---

## 1. 基本概念

| 機制 | 是什麼 | 怎麼觸發 |
|---|---|---|
| **Command** | 一份寫好步驟的指令稿，叫起來就照步驟跑 | 手動輸入 `/名稱 參數`；plugin 內的 command 要帶 plugin 名：`/<plugin>:<名稱>` |
| **Skill** | 一份領域手冊，AI 做到相關任務時自己載入、邊做邊查 | 自動：用自然語言描述任務，命中 skill 的 description 就載入。手動：輸入 `/<plugin>:<skill>`，或在訊息裡說「用 `<skill>` 處理」 |
| **Agent** | 專門負責某個審查角度的子代理（subagent，另開一個獨立對話去做事、做完回報） | 說「請 Timo 看一下」「找 rorin review」這類話；或用 `/apis-toolkit:panel-review` 一次派多位 |
| **MCP server** | 讓 AI 直接連外部服務的工具（資料庫、GitLab、GCP log 等） | 裝好就常駐，AI 需要時自己呼叫 |
| **Hook** | 在 AI 執行工具前後自動跑的檢查程式 | 不用叫，裝了 plugin 就生效 |

部分 command 與 skill 的設定檔寫了 `disable-model-invocation: true`，代表 AI 不會自己叫它，只能由人手動輸入。

### 檔案放在哪

| 類型 | 位置 | 誰維護 |
|---|---|---|
| 專案 Command | 本 repo `.claude/commands/{name}.md` | 本 repo，隨 MR 改 |
| 專案 Skill | 本 repo `.claude/skills/{name}/SKILL.md` | 見 `.claude/rules/project-skills.md`：兩支由 `composer boost` 同步、一支手工維護 |
| 專案 Rule | 本 repo `.claude/rules/*.md` | 本 repo；依檔案開頭的 `paths` 設定，只在讀寫對應路徑的檔案時載入 |
| Plugin 的 Command／Skill／Agent／Hook | GFF-Marketplace repo `plugins/<plugin>/` 底下 | GFF-Marketplace，隨該 repo 的 MR 改 |
| 個人 Command／Skill | `~/.claude/commands/`、`~/.claude/skills/` | 個人，不進任何 repo |

### 安裝與更新 plugin

- 第一次安裝照 GFF-Marketplace 的 `ONBOARDING.md` 做：先 `/plugin marketplace add` 加入市集，再逐支 `/plugin install <plugin>`（一次只能裝一支）。
- `apis-toolkit` 是 APIs 專案專屬，在本 repo 內使用。它依賴 `dev-toolkit` 與 `gcp-mcp`，安裝時會自動一起帶入。
- 市集更新後要拿到新版：在對話框輸入 `/plugin marketplace update gff-marketplace`，再輸入 `/reload-plugins`。也可以在 `/plugin` 的 Marketplaces 分頁替 `gff-marketplace` 開自動更新。

---

## 2. Commands 一覽

> 用 `/` 開頭呼叫，指令後的文字會帶入 `$ARGUMENTS`，沒帶參數時通常會反問。

### 2.1 專案 Command（本 repo `.claude/commands/`）

六支都只能手動叫。

| Command | 用途 | 使用時機 |
|---|---|---|
| `/daily-startup` | 每日開工盤點：依序跑 `/dev-status`、`/backlog`、`/prod-health`，合成一份「今日可做」清單。唯讀 | 每天開工 |
| `/dev-status` | 代碼面盤點：分支同步、worktree 健康度、可清理分支候選、GitLab 上未合併的 MR 與待我 review 的 MR。唯讀（只會 fetch 更新本機的遠端分支資訊） | 想知道「我的變更現在在哪、卡在哪」 |
| `/backlog` | 待辦面盤點：整理 `TODO.md`，掃記憶裡懸而未決的事項，並核對兩邊是否不一致。唯讀 | 排今天要做什麼 |
| `/prod-health` | 生產面盤點：排程有沒有按時跑、近 7 天卡住的代付單。唯讀 SQL | 檢查正式站有沒有沒人發現的異常 |
| `/db-prod-grant` | 申請生產庫寫入權限，並在用完後收回：先確認真的沒有替代做法、從代碼查出會改哪些表、產出申請單 | 非得寫正式庫不可時 |
| `/bank-dedup-rollout` | 逐站清理提款銀行清單裡重複的銀行 | ⚠️ 六站都已清完，檔案開頭寫明「先停下，不要執行 `--execute`」；遇到新的重複銀行先回報，不照這份走 |

### 2.2 `apis-toolkit` 的 Command

| Command | 用途 | 使用時機 |
|---|---|---|
| `/apis-toolkit:new-api-endpoint` | 建立新 API 端點（FormRequest、Controller、Service、Route），分會員 App、代理後台、第三方三個面向 | 新增路由 |
| `/apis-toolkit:db-migration` | 建立或更新 migration，遵守 `SchemaMonitor` 與本專案的 migration 安全原則 | 新增資料表或改結構 |
| `/apis-toolkit:gcloud-artisan-command` | 建立繼承 `GcpCommand`、透過 gcloud 操作 GCP 的 artisan 命令 | 需要用 gcloud 操作 GCP 資源 |
| `/apis-toolkit:code-quality` | 審查代碼品質；只給目標是純審查，加上任務描述就先列計畫再改 | 寫完自審、或要整理一段代碼 |
| `/apis-toolkit:panel-review` | 圓桌會審：依這次改了什麼建議派哪幾位審查官，由你選定後同時審，再合併成一份結論 | 高風險變更合併前 |
| `/apis-toolkit:new-worktree` | 從 `origin/master` 建立 worktree 與分支，並備好 vendor、`.env` 等 | 要開一個獨立的工作目錄並行開發 |
| `/apis-toolkit:payment-report` | 重建金流商盤點報告 `docs/reports/payment-providers-report.html` | 金流商有異動、要更新報告時 |
| `/apis-toolkit:verify-spec` | 強制上網查官方來源，驗證外部規範是否正確 | 懷疑 AI 憑記憶在講 Codex／Cursor／Claude Code 等第三方規範時 |

`new-worktree` 與 `panel-review` 兩支 AI 也可能自己叫起；其餘只能手動。

### 2.3 `dev-toolkit` 的 Command

| Command | 用途 |
|---|---|
| `/dev-toolkit:resolve-repos` | 找出 RD-Studio-01、WEB_AgentBackend、API_Agent、WEB_CustomerServiceSocket 四個本機 clone 的路徑，輸出部署腳本與 skill 要用的設定片段。不寫任何檔 |

### 2.4 範例

```
/daily-startup
/apis-toolkit:new-api-endpoint POST withdrawal/cancel frontend
/apis-toolkit:db-migration 新增 user_kyc 表，含 status 欄位
/apis-toolkit:code-quality app/Services/OrderService.php
/apis-toolkit:panel-review
```

---

## 3. Skills 一覽

> Skills 主要靠**自動觸發**：直接用自然語言描述任務，AI 會依 skill 的 description 自動載入。
> 要強制載入，輸入 `/<plugin>:<skill>`，或在訊息開頭說「用 `<skill>` 處理」。
> 下表只摘要用途。完整清單與觸發詞以 GFF-Marketplace 的 `docs/plugin-catalogue.md` 為準；「這樣說會叫起哪支」的速查表是同一 repo 的 `docs/skill-cheatsheet.html`。

### 3.1 專案 Skill（本 repo `.claude/skills/`）

| Skill | 用途 |
|---|---|
| `laravel-best-practices` | Laravel 寫法、審查、重構規範。由 `composer boost` 同步，不要直接改檔，要覆蓋它的行為改 `.claude/rules/php.md` |
| `tailwindcss-development` | Tailwind 樣式。同樣由 `composer boost` 同步 |
| `shared-table-contract` | 把某功能專屬的資料表改成多個功能共用時的設計檢查 |

### 3.2 `apis-toolkit`：金流

| Skill | 用途 | 觸發說法 |
|---|---|---|
| `payment-integration` | 金流商整合手冊：Provider 必備檔案、簽名、回調冪等、上線步驟；在某站點配置已接好的金流商戶也走它 | 「接 X 金流商」「X 的代收回調」「幫 Yes2Win 配 CCPayment 商戶」 |
| `split-cash-flow-channels` | 把一個代收金流商戶的多個支付通道拆成多筆（每條通道一筆、各用自己的成本費率） | 「拆 channel」「拆代收渠道」 |
| `report-payment-audit` | 金流商深度稽核：比對三方文件與實作，產出風險發現報告。只審不改 | 「稽核金流商」「查金流商風險」 |
| `report-order-status-lifecycle` | 產出充值訂單、取款訂單的狀態流轉報告（HTML，逐行追程式碼） | 「更新 status 生命週期報告」 |
| `large-amount-notify-debug` | 排查大額通知為什麼沒發 | 「為什麼 XXX 沒觸發大額通知」 |

### 3.3 `apis-toolkit`：銀行清單

| Skill | 用途 | 觸發說法 |
|---|---|---|
| `bank-why-missing` | 診斷會員 App 為什麼看不到某家銀行 | 「為何前端看不到 X 銀行」「提款銀行不見了」 |
| `bank-config-supplement` | 補 `Data/banks/{幣別}.php` 的別名、關鍵字、條目 | `bank:audit-feed` 跑出比對不到或信心度低之後 |
| `bank-mismatch-fix` | 清理 DB 裡和標準銀行名稱不一致、或重複的 banks 列 | `bank:audit-feed` 已歸零、要對齊標準名稱時 |

### 3.4 `apis-toolkit`：遊戲商與對帳

| Skill | 用途 | 觸發說法 |
|---|---|---|
| `game-integration` | 遊戲商整合手冊（轉帳錢包、單一錢包、回調扣款與派彩、`make:vendor`） | 「接 X 遊戲商」「單一錢包回調」 |
| `reconcile-bet-records` | 注單三表對帳診斷，只讀不寫，最後給出該走哪條修復路徑 | 「對帳」「三表對不對得上」「先看不要動」 |
| `repair-bet-records` | 依診斷結論實際補帳修復，每個寫入步驟前都要確認 | 「進行補帳」「修 BR／TBR」「重建 SBR」 |
| `list-sbr-corrections` | 列出補帳紀錄（`summary_bet_records.correction = 1`），只查不改 | 「查 PTSW 5/15 的補帳紀錄」 |

### 3.5 `apis-toolkit`：站點、資料庫與排查

| Skill | 用途 | 觸發說法 |
|---|---|---|
| `whitelabel-container-exec` | 指定站點在容器內跑 artisan 命令：以 `docker exec -e DB_CONNECTION=<站點>` 切換連線，寫入類命令先確認 | 「切到 TPower 跑 `<命令>`」「五個站點都查」 |
| `whitelabel-db-query` | 對某站點跑唯讀 SQL 分析，經 `mysql-*` MCP 直連從庫 | 「查 VWorld 的訂單」「跑個 SQL 看看」 |
| `gcp-log-troubleshoot` | 依站點查 GCP Cloud Logging | 「查 TPower 的 log」「找這個 Tid」 |
| `scan-duplicate-work` | 檢查新增的資料表、Enum、金流商、遊戲商目錄是不是已經有人做過 | 分支新增上述四類東西、首次 push 前；或說「跑重工掃描」 |
| `report-design-conventions` | 團隊 HTML 報告與簡報的設計規範 | 「產一份報告」「做一份簡報」 |

### 3.6 `dev-toolkit`：Git 流程與設計

| Skill | 用途 | 觸發說法 |
|---|---|---|
| `release-workflow` | 從開分支、開發、CI、開 MR、發布到清理的完整流程 | 「開分支」「開 MR」「上線」「清理分支」 |
| `git-commit` | commit message 規範與 commit 整理（amend、fixup） | 「寫 commit message」「整理 commit」「可以推了」 |
| `branch-sync` | 把本地分支快轉到遠端最新 | 「同步分支」「把 develop 拉到最新」 |
| `gitlab-release` | 對已合併的內容打 tag、建 GitLab Release | 「發版」「發 release」 |
| `design-grilling` | 動手前逐輪追問，把需求問清楚 | 「規劃 <工單網址>」「逼問我」 |
| `domain-modeling` | 維護詞彙表 `GLOSSARY.md` 與架構決策紀錄（ADR） | 「更新詞彙表」「要不要記個 ADR」 |
| `tdd` | 測試先行的紀律與範例。只能手動叫：`/dev-toolkit:tdd` | — |
| `harness-audit` | 盤點 CLAUDE.md、rules、記憶、hooks 等設定，找重複與衝突 | 「稽核 harness」 |
| `memory-tidy` | 整理本機記憶索引 | 「整理記憶」 |

### 3.7 其他 plugin

| Plugin | Skill | 用途 |
|---|---|---|
| `op-toolkit` | `pm-ticket-create`、`pm-ticket-edit`、`rd-ticket-create`、`op-ticket-create`、`op-ticket-read`、`sure-score`、`ticket-artifact` | 開需求單與拆子任務、改工單描述、RD 開修復單與補子單內容、開與讀線上問題單、評估工單寫得夠不夠清楚、需求單示意圖 |
| `legend-toolkit` | `deploy-agent-stack`、`deploy-rd-studio` | 舊代理後台、API_Agent、客服 socket 與 RD-Studio 的上版流程 |
| `qa-toolkit` | `web-browser`、`script-recorder`、`agent-backend-ui-sync` | 用瀏覽器操作 Dev 站點、把操作錄成可重播腳本、同步新代理後台選單記憶（QA 選裝） |
| `memory-mcp` | `memory-search`、`memory-store` | 查詢與寫入團隊共享記憶（又稱「魔鏡」） |
| `wiki-toolkit` | `wiki-authoring` | 寫知識庫 wiki（Outline）頁面 |

`gitlab-mcp`、`gcp-mcp`、`codegraph-mcp` 只提供 MCP server，沒有 skill。

### 3.8 審查官 Agent（`apis-toolkit`）

五位審查官都只讀、不改檔，預設審當前分支的變更。

| Agent | 守的角度 |
|---|---|
| Timo | 金流正確性、併發與事務、金流商與遊戲商整合慣例 |
| Rorin | 安全與事故應變：漏洞能不能被利用、影響範圍、怎麼止血 |
| Rhyse | 可讀性與可維護性、命名、分層、測試、現代 PHP／Laravel 寫法 |
| Marz | 從舊系統搬來的邏輯有沒有漏、查詢效率（N+1、raw SQL 或 Eloquent） |
| Bonn | 實作和設計文件、規則文件、commit message 說的是否一致 |

叫法：「請 Timo 看一下」「找 rorin review」；要多個角度一起看用 `/apis-toolkit:panel-review`。

### 3.9 自動觸發範例

```
# 觸發 payment-integration
"幫我接 TopPay 的代收回調"

# 觸發 reconcile-bet-records
"對帳 KissH5 5/2 三表 SUM"

# 觸發 list-sbr-corrections
"查 KissH5 這個月的補帳紀錄"

# 觸發 split-cash-flow-channels
"拆 TopPay 的 cashflow channel"

# 觸發 bank-why-missing
"為什麼前端看不到越南 Vietcombank"

# 觸發 whitelabel-container-exec
"切到 TPower 跑 bank:why-missing --agent=4249"

# 觸發 whitelabel-db-query
"查 TPower 最近一筆充值訂單"

# 觸發 large-amount-notify-debug
"VWorld 大額取款為什麼沒發通知"

# 觸發 git-commit
"幫我寫個 commit message"
```

---

## 4. 常見使用情境

| 情境 | 推薦做法 |
|---|---|
| 接新金流商 | 描述任務（自動載入 `payment-integration`）→ 寫完用 `/apis-toolkit:code-quality` 自審 → 合併前請 Timo 審 |
| 接新遊戲商 | 描述任務（自動載入 `game-integration`）→ 請 Timo 審 |
| 修 bug | 說「修 X 的 bug」，`release-workflow` 會先帶你開分支；寫 PHP 時自動套用 `laravel-best-practices` 與 `.claude/rules/php.md` |
| 高風險變更要合併 | `/apis-toolkit:panel-review` |
| 對帳出狀況 | 說「對帳 KissH5 5/2」→ `reconcile-bet-records` 診斷；要修再說「進行補帳」→ `repair-bet-records` |
| 查補帳紀錄 | 說「查 KissH5 這個月的補帳紀錄」→ `list-sbr-corrections` |
| 拆支付通道 | 說「拆 TopPay 的 channel」→ `split-cash-flow-channels` |
| 寫完要 commit | 自動觸發 `git-commit` |
| 客服回報前端少銀行 | 說「為何前端看不到 X 銀行」→ `bank-why-missing` |
| 指定站點跑命令 | 說「切到 TPower 跑 `<命令>`」→ `whitelabel-container-exec` |
| 指定站點查資料 | 說「查 VWorld 的 X」→ `whitelabel-db-query` |
| 大額通知失靈 | 說「為什麼大額取款沒發通知」→ `large-amount-notify-debug` |
| 每天開工 | `/daily-startup` |
| 新進開發者安裝 git hook | 照 `README.md` 新手任務的兩行 `ln -sf`；之後 `.githooks/` 有更新會自動生效，不必重裝 |

---

## 5. 新增／修改 Command 或 Skill

先決定放哪：只有本專案用、而且和 GFF-Marketplace 已有的 plugin 無關的，放本 repo；其他一律放 GFF-Marketplace。

### 5.1 本 repo 的 Command

於 `.claude/commands/{name}.md` 建立檔案，走一般 MR：

```markdown
---
description: 一句話說這支做什麼
argument-hint: "<參數提示>"
disable-model-invocation: true
---

範圍：$ARGUMENTS

## Step 1：...
## Step 2：...
```

呼叫方式：`/{name} 參數內容`。

### 5.2 本 repo 的 Skill

於 `.claude/skills/{name}/SKILL.md` 建立檔案。改 `laravel-best-practices`、`tailwindcss-development` 前先讀 `.claude/rules/project-skills.md`：這兩支會被 `composer boost` 覆寫。

```markdown
---
name: {name}
description: {一行描述觸發時機，列出常見說法，幫助自動比對}
---

## 1. ...
```

> **重點**：`description` 決定會不會被自動觸發，必須具體列出使用情境與關鍵字。

### 5.3 Plugin 的 Command、Skill、Agent

在 GFF-Marketplace repo 的 `plugins/<plugin>/` 底下改，照該 repo 的 `CONTRIBUTING.md` 開 MR（目標分支 `main`），並同步更新 `docs/plugin-catalogue.md`。合併後大家用 `/plugin marketplace update gff-marketplace` 拿新版。

不要改 `~/.claude/plugins/` 底下的檔案：那是安裝後的副本，更新時會被覆蓋。

---

## 6. FAQ

**Q：打 command 沒帶參數會怎樣？**
A：`$ARGUMENTS` 為空，多數 command 會反問你缺的資訊，或依檔案開頭寫的預設範圍執行（例如 `/daily-startup` 不帶參數就三面全跑）。

**Q：自動觸發失敗怎麼辦？**
A：輸入 `/<plugin>:<skill>`，或在訊息開頭加「用 `<skill>` 處理」強制載入。

**Q：怎麼知道這次 session 有哪些 skill？**
A：每個 session 開始的 system reminder 會列出所有可用的 skill，也可以直接問 AI「目前有哪些 skills」。清單裡沒看到 `apis-toolkit` 的 skill，代表 plugin 沒裝或沒更新，照第 1 節「安裝與更新 plugin」處理。

**Q：Command、Skill、Agent 該選哪個？**
A：
- 需要**固定步驟、有明確產出**（例如建檔、盤點報表）→ Command
- 需要**領域知識或規範**，隨任務動態套用 → Skill
- 需要**另一個角度獨立檢查**、不想佔用主對話的篇幅 → Agent

**Q：放本 repo 還是放 GFF-Marketplace？**
A：
- 只跟本專案有關、別的 repo 不會用到（例如 `/prod-health` 查本專案的排程）→ 本 repo `.claude/`
- 其他情況（跨專案通用的 `git-commit`、APIs 領域但要給多人裝的 `payment-integration`）→ GFF-Marketplace

**Q：AI 想跑 `migrate:fresh` 這類會清資料庫的命令怎麼辦？**
A：有兩道擋：本 repo `.claude/settings.json` 直接拒絕 `migrate:fresh`、`migrate:refresh`、`migrate:reset`、`db:wipe`，並對 `migrate:rollback`、`db:seed` 先問你；`apis-toolkit` 另有一個 hook 攔破壞性的 artisan DB 命令。

---

## 7. 參考資料

- 主要規範：[`/CLAUDE.md`](../CLAUDE.md)
- 專案 Commands：[`.claude/commands/`](../.claude/commands/)
- 專案 Skills：[`.claude/skills/`](../.claude/skills/)（來源與維護方式見 [`.claude/rules/project-skills.md`](../.claude/rules/project-skills.md)）
- 專案 Rules：[`.claude/rules/`](../.claude/rules/)
- Plugin 清單、安裝與更新：GFF-Marketplace repo 的 `README.md`、`ONBOARDING.md`
- 全部 skill 的清單與觸發詞：GFF-Marketplace repo 的 `docs/plugin-catalogue.md`
- 金流接入完整流程：[`docs/金流接入流程/index.md`](金流接入流程/index.md)
- 遊戲商接入完整流程：[`docs/單一錢包遊戲接入流程/index.md`](單一錢包遊戲接入流程/index.md)
