# Skill 目錄

> 持續擴充。新加 skill 請同步更新本表。

> 各 plugin 的概覽、受眾與依賴見 [README](../README.md)。本檔聚焦各 plugin 底下的 skill 詳目。

## 已收錄 Skill

| Plugin | Skill | 主要用途 | 觸發情境 |
|---|---|---|---|
| op-toolkit | [pm-ticket-create](../plugins/op-toolkit/skills/pm-ticket-create/SKILL.md) | 在 `target/tickets` 開立 PM 需求單 + 拆子任務，也替既有主單補拆子任務 | 「開工單 / 建工單 / 拆子任務 / 幫這張單補拆子任務」 |
| op-toolkit | [pm-ticket-edit](../plugins/op-toolkit/skills/pm-ticket-edit/SKILL.md) | 改既有需求單的工單描述（小改用 patch 先預覽，大改存成檔案改完再送回，全文不進對話） | 「改這張單的描述 / 幫 #單號 改工單描述 / 工單描述幫我濃縮」 |
| op-toolkit | [op-ticket-create](../plugins/op-toolkit/skills/op-ticket-create/SKILL.md) | 把 TG 截圖整理成 `target/op_tickets` 線上問題單並建單 | 「幫我開 OP 單 / 整理截圖開單」 |
| op-toolkit | [op-ticket-read](../plugins/op-toolkit/skills/op-ticket-read/SKILL.md) | 讀 `target/op_tickets` 線上問題單：摘要、SURE 評分、關聯與歷史單、帶參數指路排查、整理結案說明草稿 | 「op_tickets / op #N / 客服轉的單 / 整理 <工單連結> 的結案說明」 |
| op-toolkit | [ticket-artifact](../plugins/op-toolkit/skills/ticket-artifact/SKILL.md) | 需求單示意圖（artifact）的產製、發布與設成組織可檢視 | 「幫這張單做示意圖 / 示意圖發 artifact / 補發示意圖 / 示意圖設成全公司可看」 |
| dev-toolkit | [git-commit](../plugins/dev-toolkit/skills/git-commit/SKILL.md) | Git commit message 規範（繁中 conventional commit + amend SOP） | 準備 commit、詢問格式、「收斂 / 收斂 commit / amend」 |
| dev-toolkit | [release-workflow](../plugins/dev-toolkit/skills/release-workflow/SKILL.md) | branch / develop / MR / 發布 / 清理 生命週期 | 「開分支 / 出 MR / 發布 / 清理分支 / 清理 worktree / MR 已合併」 |
| dev-toolkit | [branch-sync](../plugins/dev-toolkit/skills/branch-sync/SKILL.md) | 把本地分支 fast-forward 對齊遠端最新（分岔 / 有本地提交 / 被 worktree 佔用一律跳過回報） | 「同步分支 / 同步 git / 對齊分支 / 對齊 git / 分支拉到最新」（單講「拉最新」「對齊」不觸發） |
| dev-toolkit | [gitlab-release](../plugins/dev-toolkit/skills/gitlab-release/SKILL.md) | 對已合併的發布分支打 tag、建 Release，彙整本批 MR 清單與部署風險（接在 release-workflow 之後）；上線後把功能整理成 wiki 功能開發說明文件 | 「發版 / 發 release / 根據最近的 MR 發 release」（單講「打 tag」不觸發） |
| dev-toolkit | [harness-audit](../plugins/dev-toolkit/skills/harness-audit/SKILL.md) | 盤點 harness 找重複/衝突、出 dedup 精簡計畫 | 只能打命令：`/dev-toolkit:harness-audit` |
| dev-toolkit | [memory-tidy](../plugins/dev-toolkit/skills/memory-tidy/SKILL.md) | 整理本地 Claude auto-memory：MEMORY.md 索引壓縮、淘汰外移、考古分流（附 check.py 驗證器） | 「整理記憶 / 記憶太長了 / 記憶容量不足 / 壓縮索引」 |
| dev-toolkit | [design-grilling](../plugins/dev-toolkit/skills/design-grilling/SKILL.md) | 動手前的逼問式設計訪談：分輪提問、每題附推薦答案，共識達成才進計劃模式；訪談期間一路叫起 domain-modeling 把定下來的詞與決策寫成文件 | 「規劃 <工單網址> / 規劃 XXX / 幫我規劃 / 逼問我 / 挑戰這個設計 / 壓力測試這個計劃」 |
| dev-toolkit | [domain-modeling](../plugins/dev-toolkit/skills/domain-modeling/SKILL.md) | 維護專案詞彙表（`GLOSSARY.md`）與架構決策紀錄（ADR） | 「更新詞彙表 / 把這個詞定下來 / 這兩個名詞差在哪 / 這個詞到底指什麼 / 要不要記個 ADR」；討論程式碼用語、直接寫或改 `GLOSSARY.md`、寫或改 ADR 時也會叫起 |
| dev-toolkit | [tdd](../plugins/dev-toolkit/skills/tdd/SKILL.md) | 測試先行（TDD）的紅綠迴圈紀律，範例為 PHP / PHPUnit | 不自動觸發（frontmatter 設 `disable-model-invocation`）：由 design-grilling 在共識達成後叫起、由 bug-diagnosis 在寫回歸測試前叫起，或手動 `/dev-toolkit:tdd` |
| dev-toolkit | [bug-diagnosis](../plugins/dev-toolkit/skills/bug-diagnosis/SKILL.md) | 通用 bug 排查紀律：開工先問要不要測試先行，再重現、列 3 到 5 個假設、一次只動一個變數驗證，修完補回歸測試 | 「幫我 debug / 這支 API 偶爾回 500 / 找不到原因 / 修了又復發 / 變慢了」；查 log、銀行、對帳、大額通知已有專用 skill 時走 apis-toolkit 那支 |
| dev-toolkit | [plain-writing](../plugins/dev-toolkit/skills/plain-writing/SKILL.md) | 白話寫作規則：判斷讀者、四條判準、中立客觀、句子完整、列點、送出前檢查 | 「寫一份說明」「幫我寫文件」「寫需求單」「寫報告」「潤稿」「改白話」「寫得簡單一點」「這段看不懂，重寫」 |
| dev-toolkit | [tech-doc-writing](../plugins/dev-toolkit/skills/tech-doc-writing/SKILL.md) | 技術文件的結構規則：先判讀者與文件類型，再套流程手冊、機制說明、決策紀錄等結構；叫起 plain-writing | 「寫技術文件」「寫 README」「寫接入流程」「寫機制說明」「寫設計文件」「寫交接」「寫交接文件」「補文件」「補 PHPDoc」「幫我寫 docs」 |
| dev-toolkit | [mr-report-writing](../plugins/dev-toolkit/skills/mr-report-writing/SKILL.md) | MR description 固定九節的 MR 報告寫法，附格式驗證器 | 「寫 MR 描述」「寫 MR description」「MR 說明怎麼寫」「開 MR 前先把描述寫好」「更新 MR 描述」 |
| legend-toolkit | [deploy-rd-studio](../plugins/legend-toolkit/skills/deploy-rd-studio/SKILL.md) | RD-Studio 三環境（dev / prod vw / tpower）上版部署流程 + Prod 安全閘 | 「上版 / 部署（人在 RD-Studio 目錄時）/ 上版 RD-Studio / 更新 <站台> 代碼 / SSH 進 GCP VM / 上 prod」 |
| legend-toolkit | [deploy-agent-stack](../plugins/legend-toolkit/skills/deploy-agent-stack/SKILL.md) | WEB_AgentBackend / API_Agent / WEB_CustomerServiceSocket 多站台分支上版（master → dev / prod 的 merge + push）+ Prod 決策閘 | 「上版 / 部署（人在這三個專案目錄時）/ 上版代理後台 / 上版 API_Agent / 上版客服 socket / 推 agent 後台 / 推客服 socket / 合到 vwb2c / make back/… / make api/… / make cs/…」 |
| apis-toolkit | [gcp-log-troubleshoot](../plugins/apis-toolkit/skills/gcp-log-troubleshoot/SKILL.md) | 依白標查 GCP log 排查錯誤 | 「查 GCP log / 線上報錯排查」 |
| apis-toolkit | [whitelabel-container-exec](../plugins/apis-toolkit/skills/whitelabel-container-exec/SKILL.md) | APIs 多白標容器內執行命令（`DB_CONNECTION` 注入、寫入把關） | 「切到 TPower / VWorld」「在 `<env>` 跑 `<command>`」 |
| apis-toolkit | [whitelabel-db-query](../plugins/apis-toolkit/skills/whitelabel-db-query/SKILL.md) | APIs 多白標唯讀 SQL 分析（mysql MCP 直連、唯讀強制） | 「查 / 統計 / 分析 `<白標>` 訂單注單」「撈一下 X 表」 |
| apis-toolkit | [parameter-tampering-guard](../plugins/apis-toolkit/skills/parameter-tampering-guard/SKILL.md) | 防止參數竄改：新增、寫入的接口擋下畫面範圍外的新值，放行這筆資料原本就存著的值，並附「擋得住」與「不誤擋」兩種測試 | 「新增接口」「防止參數竄改」「做存檔」「這個下拉只能選自己線的代理」；功能同時涵蓋新增、寫入時 |
| apis-toolkit | [whitelabel-write-access](../plugins/apis-toolkit/skills/whitelabel-write-access/SKILL.md) | 對白標正式站寫入前的權限準備：現查主庫 port、唯讀檢查寫入權限，沒有就擬提權申請、使用者確認後發到 Slack | 「查 `<站>` 主庫 port」「我有沒有 `<站>` 的寫入權限」「幫我申請 `<站>` 提權」；whitelabel-container-exec 寫入正式站前也會叫用 |
| apis-toolkit | [reconcile-bet-records](../plugins/apis-toolkit/skills/reconcile-bet-records/SKILL.md) | 對帳**診斷**（純讀）：三表 SUM 對齊、內部 drift 分流判斷，輸出分流結論 | 「對帳 / 對齊看看 / 先看不要動 / bet record 對不上」 |
| apis-toolkit | [repair-bet-records](../plugins/apis-toolkit/skills/repair-bet-records/SKILL.md) | 對帳**修復**（寫入）：依分流結論跑 Phase B′/C/D/E、改 BR/TBR/SBR（經 recovery_logs 審計） | 「補帳 / 補 SBR / 修 BR / 進行補帳 / 補進差額」 |
| apis-toolkit | [list-sbr-corrections](../plugins/apis-toolkit/skills/list-sbr-corrections/SKILL.md) | SBR 補正逐白標盤點 | 「列 SBR 補正 / 哪些單被改」 |
| apis-toolkit | [payment-integration](../plugins/apis-toolkit/skills/payment-integration/SKILL.md) | 金流商接入 | 「接金流 / 新代收代付」 |
| apis-toolkit | [game-integration](../plugins/apis-toolkit/skills/game-integration/SKILL.md) | 遊戲商接入 | 「接遊戲商 / 新 vendor」 |
| apis-toolkit | [split-cash-flow-channels](../plugins/apis-toolkit/skills/split-cash-flow-channels/SKILL.md) | 金流通道拆分 | 只能打命令：`/apis-toolkit:split-cash-flow-channels <金流商>` |
| apis-toolkit | [report-payment-audit](../plugins/apis-toolkit/skills/report-payment-audit/SKILL.md) | 金流商盤點稽核報告 | 「金流稽核報告 / provider 白標對照」 |
| apis-toolkit | [report-order-status-lifecycle](../plugins/apis-toolkit/skills/report-order-status-lifecycle/SKILL.md) | 訂單 status 生命週期跨專案代碼分析報告 | 只能打命令：`/apis-toolkit:report-order-status-lifecycle` |
| apis-toolkit | [report-design-conventions](../plugins/apis-toolkit/skills/report-design-conventions/SKILL.md) | 報告／簡報 HTML 設計規範（Frest 樣式、素色 icon、截圖驗收） | 「產報告 / 做簡報 / 更新 docs/reports」 |
| apis-toolkit | [bank-config-supplement](../plugins/apis-toolkit/skills/bank-config-supplement/SKILL.md) | 補 `Data/banks` 設定（alias / keyword、matcher 信心度低） | 「bank:audit-feed 後補 config / 補 alias keyword」 |
| apis-toolkit | [bank-mismatch-fix](../plugins/apis-toolkit/skills/bank-mismatch-fix/SKILL.md) | 清理 DB banks 表對齊 standard（合併重複、修 mismatch） | 「banks duplicate / DB 對齊 standard」 |
| apis-toolkit | [bank-why-missing](../plugins/apis-toolkit/skills/bank-why-missing/SKILL.md) | 診斷會員 App / 前端為何看不到某銀行 | 「前端缺銀行 / 提款銀行不見了」 |
| apis-toolkit | [large-amount-notify-debug](../plugins/apis-toolkit/skills/large-amount-notify-debug/SKILL.md) | 排查 `LargeAmountNotifyService` 大額通知沒觸發 | 「為什麼沒觸發大額通知 / 大額通知沒發」 |
| qa-toolkit | [web-browser](../plugins/qa-toolkit/skills/web-browser/SKILL.md) | 以 Playwright 操作 dev 站點前台/後台（可讀可寫） | 「操作 dev 前台/後台 / 登入 dev 後台做 X / 巡檢 dev 站點」 |
| qa-toolkit | [script-recorder](../plugins/qa-toolkit/skills/script-recorder/SKILL.md) | 把 web-browser 操作側錄成可重播 Playwright 腳本（本機保存、不入 repo） | 「錄成腳本 / 側錄這段 / 存成可重播腳本」 |
| qa-toolkit | [agent-backend-ui-sync](../plugins/qa-toolkit/skills/agent-backend-ui-sync/SKILL.md) | 對齊代理後台側邊欄選單 ↔ memory-mcp 共享記憶（需搭配 memory-mcp；以側邊欄為權威） | 「同步後台選單記憶 / 對齊代理後台記憶 / reconcile webui 記憶」 |
| memory-mcp | [memory-search](../plugins/memory-mcp/skills/memory-search/SKILL.md) | 從共享記憶（魔鏡）查資料；列舉型走 `memory_list`、單筆 recall 走 `memory_search` | 「問魔鏡 X / 魔鏡你知道 X 嗎 / 魔鏡有哪些 XX / XX 清單」 |
| memory-mcp | [memory-store](../plugins/memory-mcp/skills/memory-store/SKILL.md) | 把資料以最佳語意搜尋品質寫入共享記憶（魔鏡） | 「存到魔鏡 / 記進魔鏡 / 分享坑點 / 分享給團隊 / 存到共用 memory」 |
| wiki-toolkit | [wiki-authoring](../plugins/wiki-toolkit/skills/wiki-authoring/SKILL.md) | 照知識庫結構與格式寫功能說明頁、操作手冊，並在魔鏡建 pointer；上線後也由 gitlab-release 叫起，寫功能開發說明文件 | 「寫 wiki / 補功能頁 / 寫操作手冊 / 幫這頁建 pointer / 審這頁 wiki」 |

## APIs 本地保留（不入市集）

APIs 本地保留 `laravel-best-practices`、`tailwindcss-development` **永久不入市集**（非團隊所撰；`apis-toolkit` 內 timo agent 仍引用 laravel-best-practices）。

## Reference 子文件結構（漸進式揭露）

長 skill 採 `SKILL.md`（runtime 主流程）＋ `reference/`（細規、需要時才載入）結構，SKILL.md 內以「詳規索引」ToC 連入。下表為結構快照；**各 skill reference 子檔的權威清單以該 `SKILL.md` 詳規索引為準**。

| Skill | reference/ 子文件 |
|---|---|
| [game-integration](../plugins/apis-toolkit/skills/game-integration/SKILL.md) | `transfer-wallet`（轉帳錢包 Driver）/ `single-wallet`（單一錢包 Driver 與動錢回調規則）/ `schema`（資料表與 Model）/ `config-and-tools`（商戶設定、共用工具、帳號綁定）/ `reconcile`（對帳與補帳）/ `artisan-and-docs`（命令與整合文檔）/ `testing`（測試規範）/ `acceptance-contract`（單錢包驗收清單）/ `deliberate-decisions`（刻意不做的決定）；紅線速查表與上線步驟留主檔 |
| [payment-integration](../plugins/apis-toolkit/skills/payment-integration/SKILL.md) | `crypto`（加密貨幣型金流商與法幣型不同之處：幣與鏈的配置、永久收款地址、回調判定與冪等、代付差異、測試網 QA）/ `testing`（代收／代收回調／代付／代付回調四支測試的必測情境與強制要求） |
| [reconcile-bet-records](../plugins/apis-toolkit/skills/reconcile-bet-records/SKILL.md)（診斷·純讀） | `policies`（下游報表讀哪一層的對照，與未註冊 vendor 的 TBR↔SBR drift 三路徑處置）/ `sum_validation_advanced`（進階 SUM 驗證、跨日 correction 分析、跨白標、常見誤判陷阱）/ `vendor_resolver`（vendor 名稱模糊或 SW／TW 歧義時的候選查詢）；vendor 對接卡不在 skill 目錄內，放在 APIs repo 的 `docs/reconcile/vendors/`（清單以該目錄為準，**對帳 Step 0 先讀卡**；repair 共用同一份） |
| [repair-bet-records](../plugins/apis-toolkit/skills/repair-bet-records/SKILL.md)（修復·寫入） | `recovery_logs`（補帳 schema、寫入範本、撤回命令、deal_records 沖銷事件陷阱）/ `residual_handling`（殘留 unsettled 的異常分類、兩階段重拉、業務處置表）/ `sbr_command`（SBR 命令完整參數、關帳判斷、註冊新 vendor）；vendor 對接卡同 reconcile-bet-records，引用 APIs repo 的 `docs/reconcile/vendors/` |
| [gcp-log-troubleshoot](../plugins/apis-toolkit/skills/gcp-log-troubleshoot/SKILL.md) | `cache`（本地緩存）/ `examples`（環境標示與完整範例 A-D）；白標對照表、filter 組裝、命令模板、兩階段流程、Console URL 結構、輸出格式、認證硬閘、跨白標掃描留主檔 |
| [report-order-status-lifecycle](../plugins/apis-toolkit/skills/report-order-status-lifecycle/SKILL.md) | `deposit-spec`（代收訂單生命週期報告的內容規格）/ `withdrawal-spec`（代付訂單生命週期報告的內容規格）/ `template.html`（兩份報告共用的 HTML 外殼，替換佔位符後填入章節） |
| [report-payment-audit](../plugins/apis-toolkit/skills/report-payment-audit/SKILL.md) | `findings-schema`（風險發現資料檔的結構、欄位填法、處理狀態轉換與寫入後驗證）/ `report-html`（從資料檔產出風險發現 HTML 報告的步驟）/ `withdrawal-callback-status-rules`（代付回調 `withdrawal_orders.status` 狀態機規範） |
| [scan-duplicate-work](../plugins/apis-toolkit/skills/scan-duplicate-work/SKILL.md) | `baseline-cases`（重工基線案例：已知案例不重查的對照表，與使用者裁決的回寫紀錄） |
| [branch-sync](../plugins/dev-toolkit/skills/branch-sync/SKILL.md) | `execution-contexts`（APIs／API_\* 這類 repo 的執行位置：fetch 走 host、快轉走容器，含 `docker exec` 模板與容器未啟動時的處置） |
| [domain-modeling](../plugins/dev-toolkit/skills/domain-modeling/SKILL.md) | `glossary-format`（`GLOSSARY.md` 格式與單／多上下文判定）/ `adr-format`（ADR 模板、編號、狀態標記與適用判準） |
| [memory-tidy](../plugins/dev-toolkit/skills/memory-tidy/SKILL.md) | `layered-index`（有 `idx_*.md` 分層索引時的結構與契約）/ `archive-triage`（個別記憶檔考古分流的分類判準與 archive 檔規格） |
| [release-workflow](../plugins/dev-toolkit/skills/release-workflow/SKILL.md) | `pm-confirm-check`（開 MR 與上線前的 PM 確認檢查：豁免條件、找主單、讀名單、判斷誰加的標籤） |
| [tdd](../plugins/dev-toolkit/skills/tdd/SKILL.md) | `good-and-bad-tests`（PHP / PHPUnit 好壞測試對照）/ `mocking`（Laravel 替身使用時機） |
| [plain-writing](../plugins/dev-toolkit/skills/plain-writing/SKILL.md) | `examples`（加長的「不要這樣寫／要這樣寫」對照表） |
| [mr-report-writing](../plugins/dev-toolkit/skills/mr-report-writing/SKILL.md) | `template`（九節空白範本）/ `report-data`（報告資料欄位、YAML 子集文法與範例） |
| [deploy-rd-studio](../plugins/legend-toolkit/skills/deploy-rd-studio/SKILL.md) | `troubleshooting` / `update-scripts` / `verification` |
| [deploy-agent-stack](../plugins/legend-toolkit/skills/deploy-agent-stack/SKILL.md) | `troubleshooting`（衝突／push 被拒／部分推成功／退版） |
| [op-ticket-create](../plugins/op-toolkit/skills/op-ticket-create/SKILL.md) | `api-cheatsheet` / `draft-template` / `label-conventions` / `mcp-setup` / `parse-schema` / `screenshot-extraction` |
| [op-ticket-read](../plugins/op-toolkit/skills/op-ticket-read/SKILL.md) | `register-9015-debug` / `routing-rules` |
| [pm-ticket-create](../plugins/op-toolkit/skills/pm-ticket-create/SKILL.md) | `questions.yaml`（路由問題的題目與順序）/ `api-cheatsheet`（GitLab MCP 建單的 Step 0..5 完整流程）/ `cross-site-link`（跨站姐妹單以 `relates_to` 互連的流程）/ `label-conventions`（標籤對照與預設行為）/ `scheduling`（起訖日推算、避開國定假日）/ `subtask-patterns`（子任務 pattern、指派規則、既有子項查證）/ `title-format`（標題格式與站名白名單）/ `writing-quality`（篇幅上限、精簡八條、預期結果與測試步驟各放哪、一律白話）；另 `description-schema/`（A-payment / B-game-vendor / Bp-sms-otp / C-feature / D-maintenance 五類欄位骨架 / _schema-contract 欄位填寫紅線） |
| [rd-ticket-create](../plugins/op-toolkit/skills/rd-ticket-create/SKILL.md) | `rd-questions.yaml`（本 skill 自己的題目：模式、要不要 QA 驗收、主單給哪位 PM）/ `bugfix-schema`（修復單主單骨架）/ `subtask-content`（開發子單與測試子單的骨架、搬細節的規則、寫入方式）；共用題目與骨架引用 `../pm-ticket-create/reference/` |
| [sure-score](../plugins/op-toolkit/skills/sure-score/SKILL.md) | `layer1-checker.mjs`（第一層硬規範檢查器，第一層規則的 SSOT，評分時必跑）/ `scoring-spec`（第一層完整檢查表與第二層階梯明細）/ `scoring-examples`（校準用評分實例，評新單前對照防漂移） |
| [web-browser](../plugins/qa-toolkit/skills/web-browser/SKILL.md) | `site-env`（站台網址與帳密的 env 命名表 `WEBBROWSER_<FE\|BE>_<URL\|USER\|PASS>`） |
| [wiki-authoring](../plugins/wiki-toolkit/skills/wiki-authoring/SKILL.md) | `feature-page`（功能頁的完整範例與每段細則）/ `manual-page`（操作手冊的草案格式與範例）/ `pointer`（在魔鏡建、改 pointer 的做法）/ `dev-doc-categories`（功能開發說明文件的分類表與完整範例） |

> 其餘 skill 為單檔 SKILL.md：內容精簡、無需拆 reference（依 CONTRIBUTING「Reference 子文件」節的 Reference 定位 doctrine，不硬加）。依 plugin 分組：
> - apis-toolkit：bank-config-supplement、bank-mismatch-fix、bank-why-missing、large-amount-notify-debug、list-sbr-corrections、parameter-tampering-guard、report-design-conventions、split-cash-flow-channels、whitelabel-container-exec、whitelabel-db-query、whitelabel-write-access
> - dev-toolkit：design-grilling、git-commit、gitlab-release、harness-audit、tech-doc-writing、bug-diagnosis
> - memory-mcp：memory-search、memory-store
> - op-toolkit：pm-ticket-edit、ticket-artifact
> - qa-toolkit：agent-backend-ui-sync、script-recorder

## 命名規範

詳見 [CONTRIBUTING.md 的「Skill 命名規範」節](../CONTRIBUTING.md)。
