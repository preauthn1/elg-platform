---
name: release-workflow
description: 開發到正式的完整生命週期協議（通用，跨 repo 套參數）：排查 → 傳統分支迭代 → CI gate → 發布 → 清理。當使用者說「開分支 / 修 bug / 要改這個檔 / 動手 / 我要開始做 X / 開始進行 X / 新增一個功能 / 接一個新需求 / 對接新廠商（遊戲商、金流商）/ 先暫停目前工作 / 先停一下我需要先處理 X / 插單 / 急件 / hotfix / push / 準備發布 / 走發布流程 / 開 MR / 提交 MR / 合 develop / 上線 / 發布 / 清理分支 / 清理 worktree / MR 已合併」時自動載入；新任務起手即適用，先於 brainstorming／設計流程。只讀的排查不必載入，確定要改檔案時才適用；整理 commit、重整歷史走 git-commit。
---

# 開發生命週期協議（問題 → 正式）

## 適用範圍與術語

本協議為**通用開發工作流程**，適用於所有內部 repo，不綁定特定專案；各 repo 的差異以「repo 參數表」的參數代入條文。

- **發布分支**：repo 的正式分支（`master` 或 `main`，依參數表）。內容只能經 MR（merge request：在 GitLab 上申請「把工作分支合進發布分支」的頁面，會顯示 diff、討論與 pipeline 結果）合入；禁止直接 commit。
- **develop CI gate**：部分 repo 設有 develop 整合分支作為發布前的 CI 閘門（參數表標「有」者）。develop 允許存在僅供測試、不上發布分支的功能；內容層自然趨近 `發布分支 ⊆ develop`：工作分支一律從最新發布分支開，合進 develop 時會連同發布分支的內容一起帶進去，develop 因此會自行追上，**不另設對齊步驟**（短暫落後屬正常，下一條分支合進來就補齊）。合流方向單向：發布分支內容可隨工作分支進 develop，develop 內容禁止以任何方式進入發布分支或工作分支。
- **工作分支**：一切修改的載體。一律從最新 `origin/<發布分支>` 建立；命名為 `<prefix>/<topic>`，prefix 限 `skill/` `feature/` `fix/` `docs/` `refactor/` `hotfix/`，名稱必須具語意，禁止亂數 slug。
- 開發以兩種**分支模式**之一進行，於開工時選定（見「2. 迭代」分支模式選擇）：
  - **一般分支**（預設）：在主 checkout 內以分支切換進行。
  - **worktree**：以 `git worktree` 在主 checkout 的 sibling 目錄建立獨立工作區，主 checkout 不受影響。適用任務獨立性高、預期開發週期長（跨多次會話）、或需與主 checkout 進行中工作並行的任務。
- commit message 格式依 `git-commit` skill，本 skill 不重述。
- 本協議的命令採 Git 2.23+ 官方建議的 `git switch` / `git stash push` 慣例；合流模型對齊 git-flow 的 `merge --no-ff` 與 GitLab Flow 的 feature branch + MR 模式。

## repo 參數表

| 參數 | APIs | agent-backend | GFF-Marketplace | 其他未列 repo |
|---|---|---|---|---|
| 主 checkout | `~/Workspace/APIs` | `~/workspace/www/agent-backend` | `~/Workspace/GFF-Marketplace` | 依實際路徑 |
| worktree 路徑（worktree 模式） | `~/Workspace/APIs-<topic>` | `<主 checkout>-<topic>` | `~/Workspace/GFF-Marketplace-<topic>` | 主 checkout 的 sibling 目錄 |
| worktree 初始化補充（worktree 模式） | 執行 `/new-worktree <topic>`（順序：vendor 實體副本 `cp -a`〔非 symlink／hardlink，避免 `$baseDir` 解析到主 checkout 或回污主 vendor〕→ node_modules／.env／.env.testing 相對 symlink〔VirtioFS 限制於容器內建；`.env.testing` 缺檔會讓 `TestCase::setUp()` 直接 `exit` 中止測試進程〕→ `composer dump-autoload`〔須後於 symlink：`post-autoload-dump` 的 `package:discover` 會 boot 框架、需 `.env` 已就位〕；command 已處理） | 於新 worktree 跑 `pnpm install`（pnpm monorepo，`node_modules` 不隨 worktree 帶入；`prepare` 會順帶裝 lefthook hooks） | 無 | 依該 repo 規範 |
| 發布分支 | `master` | `main` | `main` | `origin/HEAD` 所指分支 |
| 整合方式 | 工作分支 → MR → 人工合併 | 工作分支 → MR → 人工合併（同 APIs） | 工作分支 → MR → 動到需要核准的範圍時由作者以外的審核人核准 → 按 `需要審核人核准` 這個 job 的 ▶ → 人工合併（範圍見該 repo `CONTRIBUTING.md`「合併前核准」） | 依該 repo 規範（預設同 APIs 走 MR） |
| develop CI gate | 有 | **有**，且 `develop` 兼 dev 環境部署來源（見下方 ⚠️） | 無 | 無（除非該 repo 另有規範） |
| 常駐 develop worktree | `~/Workspace/APIs-develop-gate`（常駐 `develop` 分支） | 未設 | 不適用（無 develop gate） | 未設（有則比照 APIs，見「3.1 合入 develop」的「有常駐 develop worktree 時一律用它」） |
| GitLab project path（未編碼的 slash 形式，gitlab MCP 各工具通用） | `target/gffv3/apis` | `target/gffv3/agent-backend` | `target/gffv3/gff-marketplace` | 依 `git remote -v` 推得 |
| 發布前本地驗證 | 容器內測試 / `composer pint` / `composer stan` | `pnpm lint` ＋ `pnpm check:type`（lefthook `pre-commit` 已自動跑這兩支）；要更完整跑 `pnpm check`（circular／dep／type／cspell）與 `pnpm test:unit` | `bash scripts/validate.sh` | 依該 repo 規範 |
| git 執行環境 | 網路操作（push/fetch/pull）走 host；artisan/composer/測試進 `php-fpm8` 容器；worktree 生命週期命令（`add`/`remove`/`prune`/`repair`）與 worktree 內 git 操作亦走 host（見「2. 迭代」的「git 走容器的 repo，worktree 命令一律 host 執行」） | 本機直接執行（Node 24 / corepack `pnpm@11.5.2`），不進容器 | 本機直接執行 | 依該 repo 規範 |
| 新邊界重工掃描（見「3.0.6 新邊界重工掃描」） | `apis-toolkit:scan-duplicate-work` | 無 | 無 | 無（除非該 repo 另有規範） |
| PM 確認檢查（3.3 開 MR 規則 9） | 要 | 要 | 不要（skill 改動多半沒有需求單） | 要 |
| 長期分支（見「2. 迭代」的「長期分支」） | 有：架構級大案用 `refactor/<topic>`，由使用者指定 | 未設 | 未設 | 未設 |
| 放假前合併提醒（3.3 開 MR 規則 5） | 有 | 有 | 無 | tag 會啟動部署時有 |

> **主 checkout 路徑依開發者本機而異**，表中值為參考；實際以 `git rev-parse --show-toplevel` 為準，不要憑本表硬寫路徑。
> ⚠️ **agent-backend 的 `develop` 不只是 CI 閘門，push 進去等同「上 dev 環境」**：`.gitlab-ci/build/build.yml` 的 `build` job 在 `CI_COMMIT_BRANCH == "develop"` 時建 image 推 Artifact Registry，`.gitlab-ci/deploy/release.yml` 的 `release-dev` 接著 trigger gff-cicd（project 526）跑 helm deploy 到 k8s。故「3.1 合入 develop」／「3.2 首推工作分支」合 `develop` 會實際改動 dev 環境，不是單純跑測試。合之前先確認現在可以讓 dev 環境變動。
> ⚠️ **`agent-backend`（`target/gffv3/agent-backend`，pnpm monorepo）不是 `WEB_AgentBackend`。** `WEB_AgentBackend`、`API_Agent`、`WEB_CustomerServiceSocket` 不走本協議，它們是多站台 merge/push 上版（無 MR、無 release tag），見 `legend-toolkit` 的 `deploy-agent-stack`。認錯 repo 會把 MR 流程套到不收 MR 的專案上。

GitLab 上的操作（查 pipeline、開 MR、查 MR 狀態）一律透過 **gitlab MCP**（`gitlab-mcp` plugin 提供的工具）執行：每個工具都用 `project_id="<project-path>"`（見參數表）指定是哪個專案；GitLab 位址與登入憑證由該 plugin 的 `.mcp.json` 統一管理，skill 內不持有 token、不直接呼叫 GitLab 的 `api/v4` 網址。

## 四階段生命週期

| 階段 | 所在分支 | 主要動作 | 進入下一階段的條件 |
|---|---|---|---|
| 1. 排查 | 發布分支（或當前分支） | 只讀偵查；開工先偵殘留分支與 stash | 確定要修改檔案，且通過「0. 新需求 gate」 |
| 2. 迭代 | 工作分支 `<prefix>/<topic>` | 選定分支模式 → 從最新 `origin/<發布分支>` 開分支（一般分支：stash 後於主 checkout 切換；worktree：sibling 獨立工作區） → 反覆 commit（純本地，不 push） | 使用者**主動**表示可發布；未表示則以本地 commit 為終態停手，AI 不催 push |
| 3. 發布 | （develop ↔）工作分支 | 先問發布方式（三選一，見 3.0 規則 1）。有 develop gate 先 `--no-ff` 合 develop 並 push（觸發 CI／更新 dev 環境）→ 等其 CI/CD 通過（hotfix 不等）→（需要驗收時）交 QA 驗收 → 首推工作分支 → 開 MR（工單有測試子任務、或 PM 確認檢查沒通過時開成 Draft）；無 gate 則本地驗證過直接推、開 MR | 有 develop gate 時 dev 環境已部署成功（hotfix 與使用者已放行時除外，見 3.2 第 2 點）、MR pipeline 通過、測試子任務已關閉、PM 確認檢查通過且 MR 已改回 Ready，由使用者本人合併 |
| 4. 清理 | 發布分支 | 刪遠端與本地分支、`--ff-only` 拉最新、stash 歸零 | `git branch --merged <發布分支>` 與 `git stash list` 皆無殘留 |

### 0. 新需求 gate（動檔案前必過）

1. 察覺使用者提出新需求時，先判定它與當前分支進行中工作的關聯：
   - **同一主題的延續**（同 feature 的 review 修正、補測試）→ 沿用當前分支，不需詢問。
   - **與當前分支工作無關**（新 bug、新功能、新調整）→ 必須從最新 `origin/<發布分支>` 另開新分支；動工前先以 **AskUserQuestion** 確認此判定，禁止自行假設：
     - 選項①：確認為獨立新需求 → 從最新 `origin/<發布分支>` 開新分支（走「2. 迭代」開分支程序）
     - 選項②：實為當前主題的延伸 → 沿用當前分支
     - 新需求和某條長期分支（見「2. 迭代」的「長期分支」）同主題時，加列「放進長期分支」並標推薦，即使那條長期分支剛整條合進發布分支也一樣。

   確認為獨立新需求後，依「2. 迭代」的分支模式選擇程序選定模式；得併入同一次 AskUserQuestion 一併詢問。
2. 使用者**明示暫停當前工作並插入新需求**（「先暫停目前工作」「先停一下，我需要先處理…」「插單」「急件先做」）時，視為已確認獨立新需求，不需詢問，直接執行「2. 迭代」的「插單中斷與恢復」程序。
3. 本 gate 與分支模式選擇於**新任務起手即執行**，先於 brainstorming／設計討論等其他流程啟動；得與該流程的首個提問合併為同一次 AskUserQuestion。設計文件與計劃文件亦屬檔案修改，寫入前工作分支必須已建立。
4. 使用者選定前，禁止修改任何檔案。
5. 禁止將與當前分支無關的需求堆在當前工作分支上。
6. **互斥分支序列化**：若新需求的變更與另一條 in-flight 工作分支改**同檔／同段且結構互斥**（例：一條重構檔案結構、另一條編輯舊結構），**不要並行**：先合一條、另一條再從最新發布分支 rebase／重套。並行必然衝突，且後合一方須做非平凡的解衝突（見「3.5 MR 衝突解法」）。

### 1. 排查

1. 開工先偵殘留：執行 `git branch --merged <發布分支>`、`git stash list` 與 `git worktree list`；存在已合併的殘留分支、無主 stash 或已完成任務的殘留 worktree 時，先依「4. 清理」處理完畢再開新任務。
2. 排查階段僅允許 read-only 操作（`grep` / `Read` / 診斷命令 / 跑工具）。
3. 禁止在發布分支上 `git commit`。
4. 需要修改檔案時，先完成「0. 新需求 gate」，再進入「2. 迭代」。

### 2. 迭代（工作分支）

**分支模式選擇（開新工作分支前執行）**：

1. 以 **AskUserQuestion** 詢問分支模式；使用者已指明模式時免問：
   - 選項①：**一般分支**（預設；一般情況下標「推薦」）：在主 checkout 內切換分支。
   - 選項②：**worktree**：sibling 目錄獨立工作區。任務獨立性高、預期開發週期長（跨多次會話）、或需與主 checkout 進行中工作並行時，改以此選項為推薦並說明理由。
2. 兩種模式共用同一套迭代、發布與清理規則；差異僅在工作區位置與切換方式（worktree 模式免 stash 切換）。

**分支命名（開分支前執行）**：

1. 分支名 `<prefix>/<topic>` 必須可從名稱辨識「類別＋具體對象」。prefix 依變更性質選定：

| prefix | 適用變更 | 範例 |
|---|---|---|
| `feature/` | 新增功能（對接新遊戲商／金流商、新 API、新欄位） | `feature/pgsoft-integration` |
| `fix/` | 修復缺陷 | `fix/deposit-callback-idempotency` |
| `refactor/` | 重構（不改變外部行為） | `refactor/payment-sign-trait` |
| `skill/` | AI skill／command／hook 設定 | `skill/release-workflow-worktree-mode` |
| `docs/` | 純文件 | `docs/vendor-onboarding-guide` |
| `hotfix/` | 線上問題急修，發布時走 hotfix（3.0 規則 1），免 PM 確認檢查 | `hotfix/withdrawal-callback-timeout` |

2. topic 用 kebab-case 英文，2–4 個 token，包含具體對象（廠商名、模組名、問題域）。禁止過泛名稱（`feature/update`、`fix/bug`、`feature/new-vendor`）與亂數 slug。
3. 開分支前必須已掌握足以命名的資訊：變更性質（對應 prefix）與具體對象（構成 topic）。無法從使用者敘述判定時，先詢問任務目的與對象後再開分支；此詢問得併入分支模式選擇的同一次 AskUserQuestion。
4. 資訊足夠時由 AI 逕行命名，於開分支時告知名稱與選定理由；存在多個合理候選時，以 AskUserQuestion 列出候選（推薦項置首）供使用者選擇。

開分支程序，**一般分支模式**（`<發布分支>` 依參數表代入）：

```bash
git stash push -m "<說明>"                                  # 工作區有未提交變更時必須執行
git fetch origin <發布分支>
git switch -c <prefix>/<topic> --no-track origin/<發布分支>  # 一律以最新發布分支為基底
git stash pop                                               # 僅當 stash 內容屬於新主題時執行；
                                                            # 屬於原主題者保留，切回原分支後再 pop
```

開分支程序，**worktree 模式**（`<worktree 路徑>` 依參數表代入）：

```bash
git fetch origin <發布分支>
# git 走容器的 repo（如 APIs）：worktree add 必須在 host 執行、不進容器（見下方「git 走容器的 repo，worktree 命令一律 host 執行」），否則 gitdir 寫成容器路徑、host 進不去
git worktree add <worktree 路徑> -b <prefix>/<topic> --no-track origin/<發布分支>
cd <worktree 路徑>
# 依參數表「worktree 初始化補充」欄補必要檔案（如 APIs 執行 /new-worktree <topic>）
```

規則：

1. 切換分支前，工作區有未提交變更時必須先 `git stash push -m "<說明>"`；切回後以 `git stash pop` 還原。禁止帶著未提交變更切換分支。
2. 新分支一律從最新 `origin/<發布分支>` 建立（先 `git fetch`）；禁止從其他工作分支建立，禁止沿用已合併的分支。建立時必須加 `--no-track`，upstream 留待首次 `git push -u` 時指向同名遠端分支。
3. **不早 push**：工作分支保持純本地。使用者尚未確認變更收斂前，禁止 push、禁止開 MR；迭代期間使用者仍在給回饋（修正、文風、補充）時即屬未收斂。
4. 未 push 期間的 commit 整理：新變更與上一個 commit 屬**同一變更**時，必須以 `git commit --amend` 併入，並依併入後的全部變更**重寫 commit message**（title 與 body，依 `git-commit` skill 的 amend 工作流程；禁止 `--no-edit` 沿用原訊息）；與上一個 commit 無關時開新 commit。`git reset` 可自由整理未推歷史。已 push 後不改寫歷史：修正一律以新 commit 追加、一般 push；需要乾淨歷史時於 MR 合併階段使用 squash。`git push --force-with-lease` 僅限不得已（使用者明示要求收斂已推歷史，或已推內容含敏感資訊必須抹除），且執行前必須取得使用者同意。

   🔴 **合進 develop（並 push develop）的 commit 也算已發布，即使工作分支本身還沒 push**：develop 上已經有這幾顆 commit，之後再 amend 或 rebase，同一份改動在 develop 和發布分支上就會變成兩組不同的 commit，git 認不出它們是同一件事。下一個把發布分支補進 develop 的人會撞到衝突，而且這個衝突不是他造成的。合進 develop 之後的修正一律以新 commit 追加，並重新執行 3.1 合入 develop。

   **「同一變更」的判準：這顆 commit 單獨看，能不能寫出一句有意義的「做了什麼」？**

   | 寫得出來 | 只寫得出「修正上一顆」 |
   |---|---|
   | 新 commit（例：「新增複製貼上偵測」「pasted=high 時加分歸零」） | amend 併入（例：「上次索引建錯了再改」「補上漏掉的欄位」） |

   分界是**同一變更**、不是同一功能。**切勿把整個 feature 的所有步驟 amend 成一顆**：
   - commit 的價值在於可獨立理解、可獨立 revert。九次獨立的規則校準壓成一顆，就無法回答「某條規則是哪一版引進的、為什麼」，也無法單獨回退其中一次。
   - **粒度可以事後降低、不能事後提高**：想要乾淨歷史，於 MR 合併時 squash 即可；先 amend 壓平則資訊永久消失。
   - 「同一功能就 amend」會要求跨多次對話一路 amend，直接製造「已 push 還想改寫歷史」的誘因，那是 force push 事故的來源。
5. **push 前兩道自檢**（兩者都是靜默失敗：不報錯、不提示，只是內容沒進發布分支）：
   - **`git status` 確認工作區乾淨**：改完檔案接著回答下一個問題、忘了 commit，push 出去的版本就不含那批修改。
   - **確認該分支的 MR 仍是 `opened`**（`get_merge_request(project_id, source_branch=…)` 讀 `state`）。**MR 已 merged 時，往同一分支追加的 commit 永遠不會進入發布分支**：GitLab 不阻止 push 也不提示，該 commit 就靜靜留在分支上。已 merged 就二選一：對同分支**另開一個新 MR**（diff 只含未進去的 commit），或從最新發布分支開新分支重做。
6. 禁止將 develop 合入工作分支。
7. 多 agent 並行僅限 read-only 任務；寫入類任務在同一工作區內必須序列化執行。多個寫入類任務需同時進行時，各自使用獨立 worktree。
8. worktree 一律建立於主 checkout 的 sibling 目錄（參數表「worktree 路徑」）；禁止置於 `/tmp` 或 `.claude/worktrees/` 內。
9. 同一分支同一時間僅能存在於一個工作區（git 原生限制）；worktree 模式的分支操作（commit / amend / reset）一律於該 worktree 內執行。
10. **git 走容器的 repo（如 APIs），worktree 命令一律 host 執行**：`worktree add` / `remove` / `prune` / `repair` 與 worktree 內的 git 操作（commit / amend / reset / status / switch）都在 host 跑、不進容器（worktree 內的 artisan / composer / 測試不受影響，仍進容器）。因 worktree metadata 存絕對路徑，容器掛載點（`/var/www`）與 host（`~/Workspace`）不同，於容器內建會把 gitdir 寫成容器路徑，導致 host `fatal: not a git repository`、`worktree list` 標 prunable。已誤建者以 host `git -C <主 checkout> worktree repair <worktree host 路徑>` 修回。
11. **單 checkout repo（無 worktree 隔離，如 GFF）禁止多 session 並行寫入**：多個 Claude session 共用同一 checkout 時共享 HEAD 與 `index.lock`，並行 commit／switch 會互踩（撞鎖、覆蓋彼此的 HEAD 位置）。**真正的隔離只有獨立 worktree**：只要無法確定自己是唯一 writer，一律為寫入任務另開 worktree（每個寫入任務一個），不共用 checkout。「開工前確認無其他 session、撞見 `index.lock` 或非預期 HEAD 變動即停手」僅是 best-effort 的降險觀察，**不是原子鎖**（兩個 session 可能都通過檢查後才開始寫入而互踩），不得用它取代 worktree 隔離。
12. **worktree 模式開工後，第一批檔案改完就打本地 commit**：之後依規則 4 決定 amend 併入或開新 commit，不讓工作區長時間停在「零 commit、大量未提交變更」。理由：清理殘留 worktree 時是看有沒有 commit 判斷是不是空殼，沒 commit 的改動看不到，曾因此整個 worktree 被當成空殼刪掉。

**插單中斷與恢復**（使用者明示暫停當前工作、先處理新需求時執行；明示暫停即為確認，不需 AskUserQuestion）：

```bash
# 中斷：封存當前 WIP，開插入任務分支
git stash push -m "WIP <原topic>（暫停）"
git fetch origin <發布分支>
git switch -c <prefix>/<新topic> --no-track origin/<發布分支>

# 恢復：插入任務完成（或告一段落）後切回原分支
git switch <原工作分支>
git stash pop
```

規則：

1. 上述 stash 程序適用一般分支模式。原任務採 worktree 模式時免封存：原工作區原樣保留，插單任務依分支模式選擇程序另開工作分支；恢復時直接回到原工作區。
2. stash message 必須標明原 topic；恢復時以 `git stash list` 核對名稱後再 pop，禁止盲目 pop 最新一筆。
3. 插入任務結束後，主動向使用者確認是否恢復原任務，確認後執行恢復程序。

**長期分支**（參數表「長期分支」欄為「有」的 repo）：

長期分支是架構級大案用的工作分支：一個主題一條、配一個 worktree，跨多波發布持續存在。哪些分支是長期分支由使用者指定，命名用 `refactor/<topic>`。

1. 同主題的後續修正一律 commit 在長期分支上，不另開 `fix/` 分支，即使長期分支剛整條合進發布分支也一樣。
2. 對齊發布分支用 `git merge origin/<發布分支>`，不用 rebase：長期分支歷史長，rebase 會改寫大量已推的 commit。
3. 每一波照「3. 發布」發布。開 MR 時設 `remove_source_branch=false`；回報可合併時提醒使用者：合併前在合併畫面取消勾選刪除來源分支（合併由使用者本人按，「3.3 開 MR」規則 6）。`squash` 維持 `false`，squash 合併後分支就報廢、不能續用（「3.3 開 MR」規則 2）。理由：GitLab 預設會刪來源分支，曾因此被迫從發布分支重開。下一波照「2. 迭代」規則 5 對同一分支另開新 MR。
4. 某一波的 MR 合併後，分支與 worktree 都保留，不進「4. 清理」。整個主題結案，而且兩道檢查都通過，才照「4. 清理」清掉：該 worktree 的 `git status --porcelain` 為空（「4. 清理」4.4 用 `worktree remove --force`，未提交的改動會一起刪掉），以及 `git merge-base --is-ancestor <分支 tip> origin/<發布分支>` 為真。
5. 本節只適用長期分支，是規則 2「禁止沿用已合併的分支」、「4. 清理」，以及禁止清單裡保留 branch、worktree 與沿用已 merged branch 三列的例外。

### 3. 發布

#### 3.0 發布前提與路徑分流

1. 進入發布階段的條件：**由使用者主動發起**（「push / 開 MR / 發布 / 上線 / 收斂了」）。迭代的終態是**本地 commit 後停手**：變更收斂後 AI 回報「已 commit、可發布」，**得把 push／發布列為可選的下一步供參考，但不主動催促、不反覆詢問「要不要發布」**，發布時點由使用者自行決定。
   **問發布方式時，有 develop CI gate 的 repo 只給這三種選項**（以 AskUserQuestion 詢問，推薦項置首）：
   - **不用 develop 驗收**：合進 develop → 等 develop 的 CI/CD 通過、dev 環境部署成功 → 開 MR（工單有測試子任務時開成 Draft，見開 MR 規則 8；因規則 8 或規則 9 開成 Draft 的，條件都滿足後改回 Ready，再照規則 8 處理 MR pipeline，APIs 要啟動手動的 `test`）→ 使用者本人合併 → 部署上線。
   - **需要 develop 驗收**：合進 develop → 等 develop 的 CI/CD 通過 → 使用者通知 QA 驗收 → 開 Draft MR（QA 驗收期間就開，MR pipeline 與 QA 同時跑；APIs 的 `test` 在 Draft 時不自動跑）→ QA 關閉測試子任務 → MR 改回 Ready，照開 MR 規則 8 處理 MR pipeline（APIs 要啟動手動的 `test`）→ 使用者本人合併 → 部署上線。
   - **commit 留在本地**：先不發布，之後可依 `git-commit` skill 的「歷史整理」一章整理 commit，再決定走前兩種。

   🚫 不提供「不合 develop、直接 push 開 MR」或「先開 MR、之後再合 develop」這類選項：發布一定先經 develop。無 develop CI gate 的 repo 只有兩種：本地驗證通過後 push 開 MR，或 commit 留在本地。

   **hotfix（只在使用者明說「hotfix」時才走，AI 不主動提供）**：線上問題要馬上修，不等 dev 環境。合進 develop → 開 MR（不等 develop 的 CI/CD）→ MR pipeline 通過 → 使用者本人合併 → 部署上線。和「不用 develop 驗收」的差別：**開 MR 前不等 develop 的 CI/CD，develop 的 CI/CD 與 dev 部署也不當合併關卡**。
   - 仍要合進 develop，而且和平常一樣先合：develop 才不會缺這個修正，dev 環境也跟著更新。
   - 說「急件」「插單」不算 hotfix：那只代表先處理，發布時照前三種選項問。
   - 不套「3.3 開 MR」規則 8 的 Draft：工單有測試子任務時，MR 照一般方式開，不等 QA 關單；測試子任務留著，讓 QA 在上線後補驗。
   - 分支名要以 `hotfix/` 開頭，才免「3.3 開 MR」規則 9 的 PM 確認檢查：分支名是每個人、每支工具都看得到的依據，口頭說 hotfix 只有當下的對話知道。分支還沒 push 時，先 `git branch -m hotfix/<topic>` 改名再發布；已經 push 過的，改名前先問使用者，不改就照常跑 PM 確認檢查。
   - develop 的 CI/CD 事後才紅：照 3.2 第 1 點分辨是不是本分支造成的；是的話，以新分支追加修正。
2. 發布前先執行該 repo 的本地驗證（參數表「發布前本地驗證」欄），未通過禁止繼續。
3. **首次合進 develop 前（無 gate 的 repo：首次 push 前）先事前判斷是否與發布分支衝突，有衝突才合**：

   ```bash
   git fetch origin <發布分支>
   git merge-tree --write-tree origin/<發布分支> HEAD >/dev/null && echo CLEAN || echo CONFLICT
   ```

   - `CLEAN` → **不合，直接進下一步**（有 gate：照「3.1 合入 develop」合 develop；無 gate：push）。沒有衝突就沒有預合的必要，分支上多一個 `Merge branch 'master'` commit 對 review 與最終歷史都沒幫助，只在 commits 頁多一筆雜訊。
   - `CONFLICT` → `git merge origin/<發布分支>`，衝突依「3.5 MR 衝突解法」的紀律本地解、validate 綠、commit merge 後再進下一步。

   `merge-tree --write-tree` 不動工作區與 index，純算合併結果（需 Git ≥ 2.38）。**界線**：此步只攔「push 當下發布分支已有的衝突」；push 之後才並行落地的變更仍會讓 MR 轉 conflict，由後合一方解（見「3.5 MR 衝突解法」）。
4. 路徑分流（依 develop CI gate）：
   - **有 develop CI gate**（如 APIs）→ 依序執行「3.1 合入 develop」→「3.2 首推工作分支」→「3.3 開 MR」。
   - **無 develop CI gate**（如 GFF-Marketplace）→ 跳過「3.1 合入 develop」到「3.2 首推工作分支」，直接 `git push -u origin <工作分支>`，進入「3.3 開 MR」。

工作分支首次 push 的時點：有 develop gate 的 repo 為合進 develop、develop 的 CI/CD 通過（含 dev 部署）之後，開 MR 之前（hotfix 不等 develop 的 CI/CD；選「不用 develop 驗收」且使用者依 3.2 第 2 點放行時，放行後就推；選「需要 develop 驗收」時不必等 QA 驗收，MR 以 Draft 開出），見「3.2 首推工作分支」；無 gate 的 repo 為本地驗證通過之後。「3.1 合入 develop」使用**本地** feature ref，不要求分支已在遠端。

**首次合進 develop 前（無 gate 的 repo：首次 push 前）另須執行「3.0.5 首次合進 develop 前的歷史檢討」**：那是這串 commit 的最後整理機會，排在上述步驟 3 的衝突判斷之前。參數表「新邊界重工掃描」欄非「無」的 repo，同一位置另須執行「3.0.6 新邊界重工掃描」。

#### 3.0.5 首次合進 develop 前的歷史檢討（這串 commit 的最後整理機會）

工作分支純本地、也還沒合進 develop 期間，commit 粒度可以自由調整；**一旦合進 develop 或 push，就不再改寫**（「2. 迭代」規則 4）。因此首次合進 develop 前（無 gate 的 repo：首次 push 前）固定做一次檢討：這串 commit 讓 reviewer 讀起來，是不是一件一件清楚的事。

🔴 **粒度可以事後降低、不能事後提高**：這裡沒整理好，之後只剩「MR squash 壓成一顆」這個不可逆的選項（代價見「3.3 開 MR」規則 2）。

**盤點前先搬設計理由**（本分支新增或修改了 `docs/plans/<日期-主題>/` 文件時，尤其是從未 commit 的）：用 `git diff --name-only <分岔點>..HEAD -- docs/plans/`（分岔點同第 1 步的 `git merge-base origin/<發布分支> HEAD`）加上 `git status --porcelain docs/plans/` 的未追蹤檔找出這些文件；發布分支上本來就有的 `docs/plans/` 檔不算。逐條核對設計文件與計劃文件裡每項決策的理由（為什麼這樣選、刻意不做什麼）已寫進對應 class 的 docblock，或該 repo 指定的說明文件（例如對接卡 `integration.md`）。列出「已搬／已被取代／缺」交給使用者，缺的補進同一條分支，補完才往下盤點。理由：設計文件落實後就刪，從沒 commit 過的刪了就沒有備份；合併後才發現，只能從發布分支另開分支再走一次 MR。

1. **盤點**：`git log --oneline --stat <基準 ref>..HEAD`。基準 ref＝遠端已有該工作分支時取 `origin/<工作分支>`，尚未 push 時取分岔點 `git merge-base origin/<發布分支> HEAD`。不可直接用 `origin/<發布分支>`：它含分岔後別人合進去的 commit，第 5 步 reset 到那裡會把別人的變更當成自己的改動反轉。**只有未推的 commit 進入檢討範圍。**
2. **逐顆判讀**，套「2. 迭代」規則 4 的判準「這顆單獨看，能不能寫出一句有意義的『做了什麼』」：

   | 樣態 | 處置 |
   |---|---|
   | 只寫得出「修正上一顆」 | 併進被修正的那顆 |
   | 一顆做多件事，且各件的讀者不同 | 拆開 |
   | 同一檔案連續多顆、後者整段覆寫前者 | 併。中間態留著，只會讓 reviewer 讀到一個已被丟棄的版本 |
   | 其餘 | 保留，不為了數字好看而合併 |

3. **設計目標形狀**：以「一份文件／一個模組＝一種讀者」分組（實作者／全 repo 開發者／專案追蹤／業務…）。**接著驗證每個檔案只屬於一組**：只要有檔案跨組，下面的分組法就失效，須改逐 hunk 處理或放棄該次拆分。
4. **提案給使用者拍板**：列出目標形狀（每顆的標題、涵蓋檔案、對應讀者），標明推薦項與理由。**未拍板前不動歷史。**
5. **執行**（`git rebase -i` 在部分環境不可用，故採 reset 分組法，不依賴互動式編輯）：

   ```bash
   git tag backup-before-tidy-<YYYYMMDD>     # 保命點；push 完成後才刪
   git reset --mixed <基準 ref>              # 變更留工作區、index 清空
   # 逐組重複：
   git add <該組檔案>
   git diff --cached --stat                  # 每組獨立確認，不沿用前一組的判斷
   git commit -F <message 檔>                # 中文多行一律走 -F，不用 -m
   ```

6. **驗證零遺失**：`git diff --quiet <backup-tag> HEAD` 不印任何東西，要看 exit code。exit code 為 0（例如 `git diff --quiet <backup-tag> HEAD && echo SAME || echo DIFF` 印出 SAME）才算零遺失，代表重整前後的 tree 逐位元一致。印出 DIFF 即代表漏掉檔案或改到內容，**不得繼續**，回到保命 tag 重來。
7. **收尾**：確認 pre-commit hooks 全綠、`git status` 工作區乾淨，才回到「3.0 發布前提與路徑分流」步驟 3 判斷衝突並進下一步。**工作分支 push 完成後刪除保命 tag。**

**整併 message 的紀律**

- message 描述**終稿狀態**，不描述「原本錯了後來改對」的過程；但錯誤本身若構成有價值的事實（某個判讀被實測推翻、某個前提不成立），要以事實形式保留下來。
- 依 `git-commit` skill 規範重寫，**不要把被併掉那顆的 message 直接串接**。
- 原 message 裡的證據不可在整併中遺失：實測數字、工單編號、檔案行號、驗收方式。

#### 3.0.6 新邊界重工掃描（僅參數表該欄非「無」的 repo）

首次合進 develop 前（無 gate 的 repo：首次 push 前），判斷這條分支有沒有新增**系統邊界**（新資料表／新 Enum／新 provider 或 vendor 目錄等，各 repo 的形狀由該 skill 自己定義）。有才載入參數表指定的 skill 執行掃描，沒有就跳過、不輸出任何文字。

排在「3.0.5 首次合進 develop 前的歷史檢討」之後、「3.0 發布前提與路徑分流」步驟 3 的衝突判斷之前：此時分支仍純本地，掃出重複還能直接改，不必開完 MR 再回頭。

🔴 **這一步只問「新增的東西是不是已經有人做過」，不是 code review。** 判定重複與否是語意判斷，結論交使用者裁決；AI 不得因掃描結果自行改動他人既有實作。

#### 3.1 合入 develop 並觸發 CI（僅限設有 develop CI gate 的 repo）

**有常駐 develop worktree 時一律用它**（通則，不分分支模式）：參數表有常駐 develop worktree 時（如 APIs 的 `~/Workspace/APIs-develop-gate`），一般分支模式與 worktree 模式都在它裡面操作，**主 checkout 全程不動**。該 worktree 常駐 develop 分支，因此不需 `switch -C`、不需 stash，本節命令全部加 `-C <develop worktree>` 執行：

```bash
git -C <develop worktree> status --porcelain          # 必須為空；非空先問使用者，不逕自 stash 或 reset
git -C <develop worktree> fetch origin <發布分支> develop
git -C <develop worktree> merge --ff-only origin/develop   # 對齊最新遠端，取代 switch -C
# 之後的合入 feature、push origin develop 同樣加 -C
```

理由：主 checkout 隨時可能停在別的分支、帶著別的 WIP，或有另一個 session 正在用（「2. 迭代」規則 11）。develop 是頻繁進出的共用分支，把它固定在專屬工作區，主 checkout 就永遠不必為了跑 CI 而切分支。

參數表沒有常駐 develop worktree 時才照以下命令（一般分支模式；worktree 模式的差異見本節「worktree 模式的 develop 操作位置」）：

```bash
git stash push -m "WIP <topic>"             # 一般分支模式：工作分支有未提交變更時必須執行
git fetch origin <發布分支> develop
git switch -C develop origin/develop        # 將本地 develop 重置至最新遠端狀態

# 合入本次 feature
git merge --no-ff <feature-branch> -m "Merge branch '<feature-branch>' into develop"
git push origin develop                     # 觸發 CI

# develop 操作完成後立即切回工作分支（worktree 模式：主 checkout 切回發布分支，見本節「worktree 模式的 develop 操作位置」）
git switch <feature-branch>
git stash pop                               # 上面有 stash 時必須執行
```

規則：

1. 合入 develop 一律使用 `--no-ff`，保留 merge commit 以區分 develop 異動來源；禁止 fast-forward。
2. 合入 feature 的 merge 發生衝突時：「兩邊各自新增條目」型衝突採 union 解，保留雙方全部條目；涉及 vendor 邏輯、金流等敏感分歧時，執行 `git merge --abort` 並回報，禁止自行裁決。
3. develop 操作必須序列化。`git push origin develop` 因 non-fast-forward 被拒時，執行 `git pull --no-edit origin develop` 後重新 push。
4. **worktree 模式的 develop 操作位置**（僅限參數表沒有常駐 develop worktree 的 repo；有的話依本節開頭的通則）：於**主 checkout** 執行（工作分支佔用自身 worktree，主 checkout 可自由切換；merge 使用本地 feature ref，跨工作區共用）。主 checkout 乾淨時不需上方命令開頭的 stash；操作完成後主 checkout 切回發布分支，不執行 `git switch <feature-branch>`。

#### 3.2 首推工作分支（僅限設有 develop CI gate 的 repo）

這步在做什麼：首次 push 工作分支、進入開 MR。**除了 hotfix，都要先等 develop 的 CI/CD 通過、dev 環境部署成功才開 MR**（「不用 develop 驗收」另有使用者放行的例外，見第 2 點），它是合併前的第一道防線（理由見本節末）；兩種方式的差別在通過之後要不要交給 QA 驗收：

| 發布方式 | 開 MR 前先等 develop 的 CI/CD？ | 合併前 develop 的 CI/CD 要通過？ | 依據 |
|---|---|---|---|
| 需要 develop 驗收 | **等**，含部署 job | 要 | QA 要在 dev 環境上驗，不等部署完成他沒有東西可看 |
| 不用 develop 驗收 | **等**，含部署 job | 要（使用者依第 2 點放行時除外） | GitLab 的 MR 只看自己的 pipeline，develop 紅燈時 MR 照樣顯示可合併；等 develop 通過再開，關卡不靠人記得回頭查 |
| hotfix（使用者明說才算，見 3.0 規則 1） | **不等** | **不用** | 線上問題要馬上修；打包壞掉時，release tag pipeline 的 `build` 失敗就不會觸發正式環境部署，不會壞掉上線 |

1. **需要 develop 驗收時**：push develop 後，用 gitlab MCP 查這次 push 的 pipeline（`list_pipelines(project_id, sha=<develop HEAD 的完整 sha>)`，簡寫 sha 查不到），再以 `get_pipeline` 輪詢到 `status=success`。會自動部署的 repo（見參數表下方 ⚠️ 與各 repo 的部署 job），部署 job 也要成功才算通過。通過後回報使用者，由使用者通知 QA，接著直接進下一步首推工作分支、開 Draft MR，不必等驗收結果，也不催促。
   - CI/CD 失敗時先分辨是不是本分支造成：看失敗 job 的輸出。是本分支造成的，依「3.4 CI 失敗回流」處理；是 develop 上其他人的內容造成的，以 AskUserQuestion 讓使用者選「等對方修好」或「重跑」。
   - **pipeline 被取消（`canceled`）時，改看包含本分支的最新一條**：別人緊接著 push develop 時，GitLab 可能取消較舊的 pipeline，這條就永遠等不到 `success`。此時 `git fetch origin develop`，用 `list_pipelines(project_id, ref="develop", per_page=5)` 由新到舊找，第一條 `sha` 滿足 `git merge-base --is-ancestor <本分支合進 develop 的 merge commit> <該 pipeline 的 sha>` 的，就是要等的 pipeline；它的部署 job 成功，代表本分支的改動已經部署到 dev。
   - **驗收通過的依據是「工單的測試子任務已關閉」**，檢查方式與後續動作見「3.3 開 MR」規則 8。使用者口頭轉述「測試通過」代表測試做完了，不能取代關單：子任務還開著時，回報「等 QA 關閉測試子任務」，MR 維持 Draft。
   - 驗收發現問題需改代碼 → 回工作分支以**新 commit 追加**修正（這些 commit 已合進 develop，不再改寫，見「2. 迭代」規則 4）→ 重新執行 3.1 合入 develop → 回到本步重新等 CI/CD、重新驗收。Draft MR 已開的話，一般 push 更新 MR。禁止在 develop 上直接改（3.4 CI 失敗回流規則 1）。
2. **不用 develop 驗收時**：push develop 之後用第 1 點的方式等 develop pipeline，部署 job 也要成功才算通過；通過後不必通知 QA，直接進下一步首推工作分支、開 MR。**沒通過之前不開 MR**，只有下一條的使用者放行例外。
   - CI/CD 失敗或被取消時的判斷與第 1 點相同：本分支造成的，依「3.4 CI 失敗回流」處理；被取消就改看包含本分支的最新一條。develop 上其他人的內容或 dev 產線本身的問題，以 AskUserQuestion 讓使用者選「等對方修好」「重跑」或「放行開 MR」，放行要明列成選項，不能只回報「等對方修好」。使用者選放行之前，AI 不自行放行；放行後開 MR，回報可合併時註明 develop 的 CI/CD 是使用者放行、沒有通過。
3. **首推工作分支**，進入「3.3 開 MR」：

```bash
git push -u origin <feature-branch>   # -u 指向同名遠端分支，禁止指向發布分支
```

合併前的四道 gate：**develop 的 CI/CD 通過、dev 環境部署成功**（本節第 1、2 點；hotfix 不適用，使用者依第 2 點放行時也不適用），**MR 自己的 pipeline**（3.3 開 MR 規則 5），工單有測試子任務時的**測試子任務已關閉**（3.3 開 MR 規則 8），以及參數表要求時的 **PM 確認檢查通過**（3.3 開 MR 規則 9）。

**dev 部署成功後回主單留言「已部署測試環境」**（不論哪種發布方式都要做）：

這步在做什麼：合進 develop 一定會部署 dev 環境，不論要不要驗收。部署成功後在需求主單留言，PM 與 QA 從工單就知道這批改動已經在 dev 上。

1. **判準是 develop pipeline 的部署 job 成功**：用上面第 1 點的方式找到這次 push develop 的 pipeline（被取消時改看包含本分支的最新一條），部署 job（agent-backend 是 `release-dev`）為 `success` 才算部署完成。agent-backend 的 `release-dev` 會等 gff-cicd 下游部署跑完才結束（log 最後一行 `Deploy completed`），不必再查下游 job。
2. **各發布方式的時機**：
   - 需要 develop 驗收：第 1 點等到部署 job 成功時，留言後再回報使用者通知 QA。
   - 不用 develop 驗收：第 2 點等到部署 job 成功時，留言後再開 MR。使用者放行時先開 MR，之後照下面 hotfix 的方式查 develop pipeline，部署 job 成功才補留言。
   - hotfix：開完 MR 後查 develop pipeline，部署 job 成功就留言；還在跑就回報「dev 部署中」，之後再查；失敗就回報、不留言。hotfix 可能已經先合併、上線，dev 部署成功時仍補這則留言。
3. **找主單**：從這條工作分支的 commit 標題與分支名取工單號，換成主單的規則與 `gitlab-release` skill「第 8 步：上線後回需求主單留言」的「找需求主單」表格相同（需求單直接留言、子任務換成所屬主單、其他工單或沒有工單號時列給使用者，不猜）。
4. **留言**：每張主單一則，內文固定為 `已部署測試環境`，用 `create_work_item_note(project_id="target/tickets", iid=NNNN, body="已部署測試環境")`，回報附回傳的 note id。驗收發現問題、修正後重新合進 develop 並部署成功時，再留一則，讓 QA 知道修正已經上 dev。

🔴 部署 job 沒有查到 `success` 就不能留言：push develop 成功不等於 dev 已經部署好。

**為什麼「不用驗收」也要等 dev 部署成功才開 MR**（下表為 APIs 實測，同一份內容觸發兩條 pipeline）：

| job | MR pipeline | develop pipeline |
|---|---|---|
| lint × 7、prepare、calculate_migration_hash、test | ✅（MR 是 Draft 時 `test` 改成手動，見 3.3 開 MR 規則 8） | ✅ 重複 |
| `build`（打 image） | 不跑 | ✅ |
| `release-dev`（部署 dev 環境） | 不跑 | ✅ |

- `build` 與 `release-dev` 只在 develop pipeline 跑：image 打不打得起來、部署得上不上去，MR pipeline 驗不到。develop 的 CI/CD 是這兩件事的第一道防線，先在 dev 環境出錯，不要等到發 release、上正式環境才發現。
- agent-backend 更明顯：它的 MR pipeline 只有 `lint:code` 與 `lint:commit-message`（target/gffv3/agent-backend!205 的 MR pipeline 實測），連前端打包都不跑。不等 develop 的話，打包壞掉的程式可以直接合進 `main`，要到打 release tag 才發現。
- 先等再開 MR，不讓兩條 pipeline 同時跑：GitLab 的 `mergeable` 只看 MR 自己的 pipeline（3.3 開 MR 規則 5），develop 紅燈時 MR 照樣顯示可合併。同時跑的話 MR 已經開出去，擋住合併只靠有人記得回頭查 develop。代價是多等一條 develop pipeline：APIs 約 8 分鐘（`build` 61s、`test` 352s、`release-dev` 97s；MR pipeline 約 10 分鐘），agent-backend 約 2.6 分鐘（MR pipeline 約 2 分鐘）。
- develop 的 tree 含永遠不上發布分支的 dev 專屬內容，紅燈不一定是本分支造成的。先看失敗 job 的輸出分辨；不是本分支造成的，由使用者決定等或重跑；「不用 develop 驗收」另可明確放行（本節第 2 點），AI 不自行放行。

#### 3.3 開 MR（target 發布分支）

開 MR 就是在 GitLab 上正式提出「請把這條工作分支合進發布分支」。開了之後 GitLab 會對 MR 再跑一條 pipeline、顯示完整 diff；合併鈕由使用者本人按。

```
create_merge_request(
  project_id="<project-path>",
  source_branch="<feature-branch>",
  target_branch="<發布分支>",
  title="<type(scope): 標題>",   # 工單有未關閉的測試子任務（規則 8）或 PM 確認檢查沒通過（規則 9）時，標題前加 "Draft: "
  description="...",
  remove_source_branch=true,   # 長期分支改 false（見「2. 迭代」的「長期分支」）
  squash=false,   # 預設；僅「整條分支是同一件事的迭代」時才改 true（規則 2）
)
```

規則：

1. `source_branch` 必須是工作分支；`target_branch` 必須是發布分支。禁止以 develop 為 source 或 target。
2. **squash 預設關閉（`squash=false`）**。squash 是 GitLab 在合併時把整條分支的 commit 壓成一顆的功能；關閉它，工作分支的每顆 commit 才會原樣進入發布分支歷史。這與「2. 迭代」規則 4 一致：那裡要求把互相獨立的變更拆成各自的 commit，理由是「可獨立理解、可獨立 revert」；若合併時一律壓平，該規則保住的粒度就在最後一步全部丟掉。**粒度可以事後降低、不能事後提高**：要壓隨時能壓，壓掉了救不回來。

   **要單顆進發布分支，優先在 push 前於本地壓好**（「2. 迭代」規則 4 的 `amend`／`reset`，未推歷史可自由整理），而不是丟給平台在合併時壓：

   - push 什麼就合什麼：發布分支上的 commit 與工作分支是**同一顆 SHA**，不會斷頭（「斷頭」的意思與代價見下方 ⚠️）。
   - commit message 由自己在編輯器寫完整並受 `git-commit` skill 規範；平台 squash 的訊息是拿 MR title／description 拼的，格式常常不合規。
   - reviewer 看到的就是最終形狀，不會「review 六顆、進去一顆」。
   - 不依賴平台行為：squash 勾選可被專案設定或他人變更，建立時的設定不保證合併時還在。

   **只有一種情形該用平台 squash**：**push 之後**、review 期間追加的修正碎片想收乾淨。此時本地壓平需要 force push，而「2. 迭代」規則 4 禁止改寫已推歷史，平台 squash 是唯一不 force push 的手段。改 `squash=true` 時於 MR description 說明理由。使用者明確要求乾淨歷史時亦可。

   ⚠️ squash 的代價要一併理解：合併後發布分支上是一顆**全新 SHA**、工作分支的 commit 不是發布分支的祖先（俗稱斷頭：工作分支與發布分支的歷史從此接不起來）。因此「4. 清理」4.5 刪本地分支時 `-d` 會失敗需改 `-D`，且該分支**報廢不得續用**。續用會讓已合入的變更以不同 SHA 再出現一次，後續 MR 的 diff 變髒、遇到他人改同段即成真衝突。
3. MR description 照 `mr-report-writing` skill 的九節寫（主旨、驗收進度、變更、設計決定、跟原計畫不同的地方、測試、上線步驟、待決與後續、報告資料），寫完先跑它附的驗證器，全部通過才開 MR；description 承擔整理後的歷史紀錄：必須涵蓋全部迭代 commit 的變更摘要；審查期間追加 commit 後，必須同步更新 description 使其涵蓋全部變更。
   **title 與 description 的可理解性要求與 commit message 完全相同**（規則見 `git-commit` skill 的「描述要寫給團隊看」一節）：不用讀者查不到的內部代號（個人 backlog 的節號、計畫文件的 task／phase 編號、只在某次對話中成立的簡稱），要引用文件就寫完整相對路徑與節名。
   ⚠️ 本規則在 MR 上比在單一 commit 上更嚴格：description 是這批變更**唯一的整體說明**，且啟用 squash 時它就是進入發布分支的那一顆 commit 的內容。寫得只有自己看得懂，等於把不可理解的紀錄永久寫進主線歷史，之後只能靠改寫已合併的歷史才能修。
4. 建立成功的回應含 `iid`（這個專案內的 MR 編號，也就是 `!NNNN` 的那個數字），後續查詢 pipeline 或 MR 狀態都用它。
5. MR 建立後，GitLab 會對 MR 再跑一條自己的 pipeline。必須等該 pipeline 通過且 `detailed_merge_status=mergeable`（GitLab 判定這個 MR 現在可以直接合併：pipeline 過、沒有衝突、沒有未解決的阻擋），有 develop gate 的 repo 另須 develop 的 CI/CD 通過、dev 環境部署成功（3.2；hotfix 不適用，使用者依 3.2 第 2 點放行時也不適用，但回報時要註明是放行），才得回報「可合併」。GitLab 的 `mergeable` 不會檢查 develop pipeline，要另外查。Draft MR 的狀態是 `draft_status`，要先依規則 8、9 改回 Ready 才會變成 `mergeable`：

```
get_merge_request(project_id="<project-path>", merge_request_iid="<iid>")
# 讀回傳的 detailed_merge_status，須為 mergeable
```

   **隔天放假時，回報改成「已可合併，建議下個上班日再合」**（參數表「放假前合併提醒」為「有」的 repo）：回報可合併前呼叫 `check_workdays`（`gitlab-workitem` 工具；`dates` 不填就查台灣時間的明天）。`days[0].is_workday` 為 `false` 時，回報寫明「明天 `<日期>` 放假（`<假日名稱>`，取 `days[0].note`），建議下個上班日再合」，不寫「今天合沒問題」；使用者明說今天要合，就照一般方式回報可合併。工具回錯誤或叫不到時，附上錯誤原文，照放假處理。回傳的 `warnings` 非空時，照常依判斷結果回報，並把 warnings 原文轉告使用者。diff 只動 `docs/`（不含程式、設定、migration）時不提醒。合 develop、開 MR 不受本條影響。理由：進了發布分支的內容隨時可能被帶上正式站，隔天放假時出事沒人能即時處理。

6. **合併一律人工**：AI 得建立與編輯 MR（標題 / description / label）；禁止執行合併：不呼叫任何合併 API 或 MCP 合併工具（如 `merge_merge_request` / `PUT .../merge`）、不按 GitLab merge 鈕、不設 auto-merge / merge-when-pipeline-succeeds。確認 pipeline 通過後，回報 MR 連結並停止，由使用者本人合併。
7. 使用者告知已合併後，必須先驗證 `state=merged`，才進入「4. 清理」。
8. **工單有測試子任務時，QA 關閉子任務前 MR 維持 Draft**。這條在防什麼：MR 一開出來就是 `mergeable`，有合併權限的其他人看到就可能提前合併，「等 QA 驗完才合」只靠大家守流程，GitLab 不會擋。Draft（草稿）MR 會被 GitLab 擋下合併，`detailed_merge_status` 是 `draft_status`（實測）。MR 維持 Draft 期間，MR pipeline 與 code review 照常進行，不必等驗收。APIs 有一個例外：MR 是 Draft 時，不論是因為本條還是規則 9 開成 Draft，MR pipeline 的 `test` 都不會自動跑，要等改回 Ready 後再啟動（見本條的「改回 Ready 後啟動 `test`」）。

   - **適用範圍**：看工單有沒有拆測試子任務，不看是哪個 repo。工單沒有測試子任務（例如只開主單的小修正），或 commit 沒有對應的需求工單時，不套用本條，MR 照一般方式開。使用者明說 hotfix 時也不套用（3.0 規則 1）。
   - **找測試子任務**：從 commit 標題或分支名取需求單號（`target/tickets#NNNN`），呼叫 `list_child_items(project_id="target/tickets", iid=NNNN)`（`gitlab-workitem` 工具，回傳子任務的標題與開關狀態）。標題後綴是測試類的子任務就是測試子任務，後綴有三種寫法：` - 測試`、` - APIs測試` 這類「名稱加測試」，以及 ` - 測試(APIs)`、` - 測試(批號)` 這類括號寫法；線上的舊子任務常寫成沒有空格的 `-測試`，同樣算（後綴清單見 op-toolkit plugin 裡 `pm-ticket-create` 的 `reference/subtask-patterns.md`）。有多張時全部都要關閉才算通過。
   - **開 Draft**：開 MR 時在標題前加 `Draft: `。不要用 `update_merge_request` 的 `draft: true` 參數：GitLab API 會回 400，說缺少可更新的欄位（實測）。已經開成一般 MR 的，用 `update_merge_request` 改標題加上 `Draft: `。
   - **改回 Ready**：再呼叫一次 `list_child_items`，確認測試子任務的狀態是 `CLOSED`，參數表要求時再重跑一次規則 9 的 PM 確認檢查並通過，才用 `update_merge_request` 把標題的 `Draft: ` 拿掉，改完讀回 `draft=false`（拿掉前綴就會解除 Draft，實測）。這個動作屬於規則 6 允許的「編輯 MR 標題」，不是合併；改完照下一點啟動 `test`，再依規則 5 確認 `mergeable` 才回報可合併。
   - **改回 Ready 後啟動 `test`**：這步在把 Draft 期間沒跑的測試補跑一次。APIs 的 MR pipeline 在 MR 是 Draft 時，`test` 這個 job 改成手動，不會自己跑；沒有人按，pipeline 就停在等待手動操作的狀態（GitLab 顯示 blocked），MR 不會變成 `mergeable`（APIs 的 `.gitlab-ci/prepare/test.yml`，2026-10-21 由 target/gffv3/apis!2462 加入）。拿掉 `Draft: ` 不會自動開新的 pipeline。同一條 pipeline 裡的合併關卡 job（APIs 目前是 `PM 確認檢查`、`QA 驗收檢查`、`需要審核人核准`，pipeline 一建立就跑）若在 Draft 期間已經失敗，只補跑 `test`，pipeline 仍是失敗，MR 一樣不會變成 `mergeable`（!2457 的 pipeline 53673 實測：`test` 成功、`PM 確認檢查` 失敗，整條 pipeline 是 `failed`）。所以拿掉之後照下面做：
     1. 用 `list_merge_request_pipelines(project_id, merge_request_iid)` 列出 MR pipeline，取 `id` 最大的那條。
     2. 每一輪先重做第 1 步：出現 `id` 更大的 MR pipeline（改回 Ready 後又推了 commit，APIs 會自動取消同分支的舊 pipeline），就改看新的那條，舊的結果作廢。再用 `list_pipeline_jobs(project_id, pipeline_id, per_page=100)` 重新列出這條 pipeline 的全部 job，照下表逐一處理。job 的狀態會一直變，所以每輪都重新列、重新判斷，不沿用上一輪的結果。不要用 `scope` 篩選：`test` 要等 `calculate_migration_hash` 跑完才輪到它，在那之前狀態是 `created`，用 `scope="manual"` 會漏掉。重跑會產生新的 job id，所以也不要拿舊的 id 去查。

        | job | 狀態 | 怎麼處理 |
        |---|---|---|
        | `test` | `manual` | 用 `play_pipeline_job(project_id, job_id)` 啟動 |
        | `test` 以外的 job | `manual` | 不按。回報使用者由使用者決定，停下來 |
        | 合併關卡 job，`allow_failure` 是 `false`（正式模式，會擋合併） | `failed`，還沒重跑過 | 用 `retry_pipeline_job(project_id, job_id)` 重跑。有沒有重跑過不靠記憶判斷：另用 `list_pipeline_jobs(project_id, pipeline_id, per_page=100, include_retried=true)` 看同名 job 有沒有被重跑掉的舊紀錄 |
        | 合併關卡 job，`allow_failure` 是 `false` | 重跑過、仍是 `failed` | 照下方「關卡重跑後仍失敗」回報，停下來。使用者告知條件補齊後，再用 `retry_pipeline_job` 重跑一次，結果照本列判斷 |
        | 任何 `allow_failure` 是 `true` 的 job（合併關卡的試行模式，或 `lint:strict-types` 這類允許失敗的檢查），失敗只是警告 | `failed` | 不擋合併，不重跑、不等它；回報可合併時註明是哪個 job 的警告與 log 最後幾行的原因 |
        | 其他 `allow_failure` 是 `false` 的 job（`test`、lint、`prepare` 等） | `failed` | 程式碼的問題，照「3.4 CI 失敗回流」處理，停下來 |
        | 任何 job | `skipped` | 它依賴的 job 失敗了，照那個失敗的 job 處理 |
        | 任何 job | `canceled` | 先照本步開頭查有沒有更新的 MR pipeline，有就改看新的。沒有才是有人取消了 pipeline：回報使用者，由使用者決定要不要重跑，不自行開新的 pipeline，停下來 |
        | 任何 job | `created`、`pending`、`running`，以及表上沒列的其他還沒結束的狀態 | 還沒跑完，過一下再列一次 |

     3. 所有 `allow_failure` 是 `false` 的 job 都是 `success`，才依規則 5 確認 `mergeable`。沒有 `test` job 的 pipeline（例如 agent-backend 的 MR pipeline 只有 lint）照同一張表處理，不用找 `test`。

     **關卡重跑後仍失敗**：用 `get_pipeline_job_output` 讀 log 最後幾行的原因，依 job 回報使用者並停下來。同一個原因不再重跑；使用者告知條件補齊後，照表上「重跑過、仍是 `failed`」那一列重跑一次。
     - `PM 確認檢查`：照規則 9 處理，不代貼標籤。
     - `QA 驗收檢查`：把原因轉告使用者。這支檢查比規則 8 多兩個條件：最後關閉子單的人要在團隊名單的「測試」組，而且關閉時間要晚於 MR 最後一次推 commit。常見原因是 QA 驗完之後又推了 commit，要請 QA 重新關閉。AI 不代為關閉或重開子單。
     - `需要審核人核准`：請作者以外的審核人在 MR 上核准。

     重跑關卡 job 不是放行：三支檢查程式都在執行當下才用 GitLab API 讀主單的標籤紀錄、子單的狀態異動紀錄、MR 的留言，條件沒滿足照樣失敗。正式模式或試行模式在 pipeline 建立時就決定，重跑不會換模式。不要用 `create_pipeline` 另開 pipeline 代替重跑：它開的是分支 pipeline，不是 MR pipeline，合併關卡 job 只在 MR pipeline 跑，不會出現在分支 pipeline 裡。

     啟動 `test`、重跑關卡 job 都屬於規則 6 允許的範圍：它們只是跑測試和檢查，不是合併。
9. **參數表「PM 確認檢查」標「要」的 repo，主單沒有 PM 確認前 MR 維持 Draft**。這條在防什麼：沒經過 PM 驗收的改動被合進發布分支、接著上線。依據是需求主單掛著「0. PM確認」標籤，而且最後一次加上它的人在團隊名單的 PM 組；這和 code review（其他 RD 審程式碼）是兩件事，review 通過不代表 PM 確認過。

   - **怎麼檢查**：照 `reference/pm-confirm-check.md` 逐步做，豁免條件（`hotfix/` 分支、全部 commit 都是 docs／style／chore／test／revert、只附線上問題單）、找主單、讀名單、判斷誰加的標籤都寫在那裡。
   - **開 MR 時**：檢查沒通過，標題前加 `Draft: ` 開出（做法同規則 8 的「開 Draft」），並把檢查結果的不通過原因寫給使用者，例如「主單 target/tickets#4519 沒有 0. PM確認」。規則 8 與本條任一條要求 Draft，就開成 Draft。
   - **改回 Ready 時**：重跑一次檢查，不沿用開 MR 時的結果。通過、而且規則 8 也滿足，才拿掉 `Draft: `（做法同規則 8 的「改回 Ready」）。
   - **回報可合併前**：不論 MR 有沒有當過 Draft，依規則 5 回報可合併之前都再跑一次。PM 可能在開 MR 之後拿掉標籤；沒通過就把 MR 改成 Draft（做法同規則 8 的「開 Draft」），回報原因，不回報可合併。
   - **不代貼標籤**：「0. PM確認」只能由 PM 本人貼。使用者說「PM 已經同意了」時，回報「請 PM 在主單貼上 0. PM確認」，不替 PM 貼，也不當成通過。
#### 3.4 CI 失敗回流

1. 禁止在 develop 上修復。
2. 回到工作分支：一般分支模式 `git switch <feature-branch>`；worktree 模式 `cd <worktree 路徑>`。
3. 修正後 commit：只有**還沒合進 develop、也還沒 push** 的 commit（本地驗證失敗）可以 `git commit --amend` 併入並重寫 message。develop 的 CI/CD 失敗時，工作分支雖然還沒 push，但這些 commit 已經在 develop 上，一律以新 commit 追加（「2. 迭代」規則 4）；MR 自己的 pipeline 失敗時同樣以新 commit 追加並一般 push 更新 MR。
4. 有 develop gate 的 repo 修正後重新執行 3.1 合入 develop（合入的是本地工作分支，自動帶上修正），再依 3.2 處理（重新等其 CI/CD，hotfix 除外；選「需要 develop 驗收」時再重新驗收），MR 已開的話一般 push 更新 MR 並同步更新 description（3.3 開 MR 規則 3）；無 gate 的 repo 修正後 push 更新 MR。MR 審查期間因 review 意見追加的修正，同樣走這個回流。

#### 3.5 MR 衝突解法（發布分支於 MR 開啟後前進導致）

MR 開啟後，別人的 MR 先合進了發布分支，兩邊改到同一段，GitLab 就會在 MR 頁面標示 `conflict`（`detailed_merge_status=conflict`）。此時：

1. 在工作分支 `git fetch origin <發布分支>` 後 `git merge origin/<發布分支>`（**merge 不 rebase**：避免改寫已推歷史、保住以本分支為基底的其他分支）。
2. 逐衝突檔解，取哪一方依**內容**判定。**禁止無腦 `git checkout --ours`**：須 `diff` 比對發布分支在衝突段的版本，確認有無「本分支推送之後才落地、我該保留的新內容」（如別人新合的修正、刪除）。漏比＝把對方的更新吞掉。
3. 確認無衝突標記殘留 → `validate` 綠 → commit merge → **一般 push**（不 force-push）更新 MR。
4. 重新確認 MR `detailed_merge_status=mergeable` 才回報可合併（照 3.3 開 MR 規則 5，含隔天放假的回報）；合併仍由使用者本人按（3.3 開 MR 規則 6）。

### 4. 清理（強制終結步驟，MR 合併到發布分支之後）

本階段是流程的終態 gate，全部完成才算任務結束。長期分支某一波合併後不做本階段（「2. 迭代」的「長期分支」）。

清的分支或 worktree 不是這個對話自己剛合併的（例如「1. 排查」偵到的殘留）時，刪之前先確認沒有別的對話正拿它走發布流程：內容已在 develop 只代表刪了不會丟資料，不代表沒人在用；剛合進 develop、還沒進發布分支的，通常正是有人走到 develop CI gate 這一步。零 commit 的 worktree 也不等於空殼。殘留的 `refactor/` 分支或 worktree，清之前先問使用者是不是長期分支（`refactor/` 也是一般重構的前綴，名稱看不出來）。確認不了就問使用者，不刪。

**4.1 確認 MR 已 merged**（用 gitlab MCP 查回來的狀態為準，不憑口頭「合了」）：

```
get_merge_request(project_id="<project-path>", merge_request_iid="<iid>")
# 讀回傳的 state，須為 "merged"
```

其餘為 git 本地操作：

```bash
# 4.2 刪除遠端 branch（開 MR 時 remove_source_branch=true 已自動刪則略過）
git push origin --delete <feature-branch>

# 4.3 回發布分支並拉最新（fetch + merge --ff-only）
git switch <發布分支>
git fetch origin <發布分支>
git merge --ff-only origin/<發布分支>
#   被擋（"Please commit your changes or stash them"）= 有未提交 WIP
#   → git stash push -m "<說明>" -- <file>，merge 後 git stash pop；禁止 discard

# 4.4 移除 worktree（僅 worktree 模式；初始化補充含被 ignore 的檔案，需 --force）
#   先結束 codex plugin 替這個 worktree 開的背景服務（app-server-broker）：worktree 刪掉後它不會自己結束，
#   會一直累積。沒裝 codex plugin 或沒有對應的服務時，pkill 找不到程序，照常往下做（macOS／Linux）
pkill -TERM -f "app-server-broker\.mjs serve .*--cwd <worktree 路徑>(/| |$)" || true
git worktree remove --force <worktree 路徑>
git worktree prune

# 4.5 刪本地 branch
#   🔴 禁止無條件 `git branch -d || git branch -D`：那會連真的沒合併的分支一起強刪。
#   先驗證內容是否真的進了發布分支（-d 失敗的兩種原因見下方表）：
git merge-base --is-ancestor <feature-branch> <發布分支> && echo MERGED || echo NOT-ANCESTOR
#   MERGED       → 內容確實在發布分支上，-D 安全
#   NOT-ANCESTOR → 先分辨是 squash 還是真的沒合，不可逕自 -D
git branch -D <feature-branch>

# 4.6 驗證歸零（必跑）
git branch --merged <發布分支>      # 本任務分支必須已不在
git stash list                     # 本任務 stash 必須已全數 pop，無殘留
git worktree list                  # 本任務 worktree 必須已移除（僅剩主 checkout 與其他進行中任務）
```

下個任務必須重新執行「0. 新需求 gate」並從最新 `origin/<發布分支>` 開新分支；禁止沿用已合併的分支。

**`git branch -d` 失敗的兩種原因（不要一律當成 squash）**

| 原因 | 判別 | 處置 |
|---|---|---|
| **當前 HEAD 不在發布分支上** | `merge-base --is-ancestor` 回 **MERGED** | `-d` 判斷「已合併」是**相對當前 HEAD**，不是相對發布分支。**worktree 模式下這是常態**：主 checkout 常停在另一條工作分支，它當然不含本次的 commit。內容確實已進主線，`-D` 安全 |
| **squash 合併** | `merge-base --is-ancestor` 回 **NOT-ANCESTOR**，且該 MR `state=merged`、`squash=true` | feature commit 以全新 SHA 進發布分支，原 commit 不是祖先（俗稱斷頭，見 3.3 開 MR 規則 2）。確認 MR 真的 merged 後才 `-D`，且**該分支報廢不得續用** |
| 以上皆非 | NOT-ANCESTOR 且 MR 不是 merged | 🔴 **停手**：分支內容還沒進主線，刪了就沒了（未推 commit 隨分支刪除即歸零，見 4.7 末）。回 4.1 查清楚 |

**4.7 孤兒 worktree（清理沒做完時的殘留，定期查一次）**

漏做 4.4／4.5 時會留下**孤兒 worktree 目錄**：`git worktree list` 不認得它們（metadata 已被 prune），但目錄還在、多半含實體 vendor 副本。

🔴 **不能用 `git status` 判斷**：那些目錄的 `.git` 檔指向已不存在的 metadata，git 命令直接失效；有些 `.git` 甚至寫成**容器路徑**（host 端根本沒有）。改用唯讀比對，不污染主 repo 的 index：

```bash
IDX=<scratchpad>/idx
GIT_INDEX_FILE="$IDX" git --git-dir="<主 checkout>/.git" \
  --work-tree="<殘骸目錄>" read-tree origin/<發布分支>
GIT_INDEX_FILE="$IDX" git --git-dir="<主 checkout>/.git" \
  --work-tree="<殘骸目錄>" status --short | grep '^??'
```

篩出 `??`（發布分支沒有的檔案），再用 `git log --all --name-only`、`git ls-remote --heads origin`、`git ls-tree -r <本地分支>` 三路確認是不是唯一副本。

判讀要點：

- **大量 `??` 多半是舊路徑假陽性**：檔案已在發布分支改名或搬移過，需人工辨別。
- `M`／`D` 差異多半只代表「該目錄停在舊版」，不是未提交的工作。
- 只剩 `.idea/` 之類編輯器設定的目錄＝IDE 在 worktree 移除後又寫回，零風險可刪。
- ⚠️ 沒有 `.git` 的散檔目錄不是孤兒 worktree，刪前要另外判（可能是沒進版控的盤點資料）。

🔴 **「曾經 commit 過」不等於「有備份」**：分支刪除後，只存在本地的 commit 會被 gc 回收（`git cat-file -t` 回 `Not a valid object name`、reflog 查無），**未 push 的 commit 隨分支刪除即歸零**。刪孤兒目錄或分支前，先用上面三路確認內容在遠端或發布分支上有副本。

**本協議止於此**。要對已合併的發布分支打 tag、建立 GitLab Release（一個 tag 常涵蓋多個 MR）時走 `gitlab-release` skill，本 skill 不重述、不處理。

## 合進 develop 撞衝突時怎麼解

**成因與你無關**：某個 feature 分支先以「未對齊發布分支」的樣子合進 develop，之後才在自己分支上對齊、再經 MR 進發布分支。此時 develop 手上是舊版、發布分支是修補後版本，兩邊都動過同一段 ⇒ **下一個要過 develop gate 的分支（即使完全沒碰這些檔）就會撞到衝突**。

🔴 **無腦 `--ours` / `--theirs` 任一側都可能造成資安或功能回歸，必須逐檔判**：

| 檔案類型 | 解法 | 理由 |
|---|---|---|
| Controller 等含守衛的檔 | 多半**整檔取發布分支側** | 發布分支通常是安全超集（develop 側可能停在守衛補上之前的舊版）。develop 若多出東西，先 `git grep` 確認是不是發布分支刻意移除的死碼，**確認全庫零呼叫端**才砍 |
| 路由檔 | **只改衝突區塊，禁用 `--theirs`** | 發布分支沒有 develop 專屬功能，整檔取代會**無聲刪掉那些路由**。develop 專屬內容合乎 `發布分支 ⊆ develop` 的不變式，必須保留 |

**判別 develop 手上是哪一版**：`git merge-base --is-ancestor <發布分支上修補該檔的 sha> origin/develop`。

**解完必驗**：`diff <(git show origin/<發布分支>:<file>) <file>` 逐行核對「相對發布分支淨多的行」是否**全部**為 develop 專屬功能，**數字對得上才算解對**。

⚠️ **zsh 踩雷**：`git show "$R:app/..."` 會被 zsh 的 `:a` 參數修飾符吃掉（`$R:a` → 絕對路徑），變成無效 ref 而 `git show` 失敗、`grep -c` 回 0 ⇒ **誤判成「兩邊都沒有這個守衛」**。一律寫成 `"${R}:app/..."` 加大括號。

⇒ 撞到時先用 `git diff --name-only origin/<發布分支> <自己分支>` 確認衝突檔與自己有沒有關係；**金流／授權類一律停下回報**，不要自己決定取哪一側。

## 中止與回退

- **放棄已合入 develop 的 feature**：不需處理。它留存於 develop（永不開 MR 上發布分支），符合 `develop ⊋ 發布分支`。
- **develop 被合壞（CI 紅、擋到別人）**：對該 merge commit 執行 `git revert -m 1 <merge-sha>` 後 push develop。禁止 force-push 共用的 develop。
- **工作分支尚未發布而需重來**：一般分支模式 `git switch <發布分支>` 後 `git branch -D <feature-branch>`；worktree 模式先 `git worktree remove --force <worktree 路徑>` 再刪分支。從最新 `origin/<發布分支>` 重開。

## 禁止清單

| 動作 | 規則依據 |
|---|---|
| 在發布分支直接 commit | 發布分支內容只能經 MR 合入 |
| 未 stash 就切分支 | 未提交變更會隨切換流入其他分支；切換前必須 `git stash push -m`、切回後 `pop` |
| worktree 置於 `/tmp`、`.claude/worktrees/` 或非 sibling 路徑 | worktree 一律放主 checkout 的 sibling 目錄（參數表）；`/tmp` 被系統清除會留 dangling metadata，容器亦掛載不到 |
| MR merged 後保留 worktree | worktree 鎖住其分支且殘留累積；「4. 清理」必須 remove + prune 歸零。長期分支除外（「2. 迭代」的「長期分支」） |
| 察覺新需求不問就動檔案 | 無關的新需求須經使用者確認判定後從發布分支開新分支（「0. 新需求 gate」） |
| 使用者確認前 push / 開 MR，或催促、反覆詢問「要不要發布」 | 迭代終態為本地 commit；push／發布由使用者主動發起，AI 得列為可選但不催促（「2. 迭代」規則 3、3.0 發布前提規則 1） |
| 往 MR 已 merged 的分支追加 commit（誤以為會進發布分支） | 該 commit 永遠不會進入發布分支，且 GitLab 不報錯；push 前須確認 MR 仍 `opened`（「2. 迭代」規則 5） |
| 從當前工作分支疊開新分支 | 新分支一律以最新 `origin/<發布分支>` 為基底，分支之間不得有時序依賴 |
| AI 代按 / 自動合併 MR | 合併權保留給使用者本人；AI 開完 MR 必須停止等待指示 |
| 測試子任務還沒關閉就把 MR 改回 Ready，或回報可合併 | 驗收通過以測試子任務關閉為準，口頭轉述不算；MR 維持 Draft 才能讓 GitLab 擋住提前合併（3.3 開 MR 規則 8） |
| 主單還沒有 PM 加的「0. PM確認」就把 MR 改回 Ready，或回報可合併 | PM 確認以標籤與加標籤的人為準，口頭轉述「PM 說可以」不算；沿用上一次的檢查結果也不算，每次都重查（3.3 開 MR 規則 9） |
| 跳過 develop 直接 MR（限有 develop gate 的 repo） | 未經 CI gate；且造成 develop 缺內容，破壞 `發布分支 ⊆ develop` |
| MR target develop | develop 是 CI gate，不是發布目標 |
| `git merge develop` 進工作分支 / 拿 develop 當 MR source | develop 專屬內容禁止流向發布分支（單向合流） |
| 在 develop 上 fix bug | 修正必須留在工作分支血統內，經 3.4 CI 失敗回流 |
| Fast-forward merge 到 develop | 必須 `--no-ff` 保留 merge commit |
| 多個發布同時動 develop | develop 操作必須序列化；non-ff push 被拒時 pull-retry 或等待 |
| 單 checkout repo 多 session 並行寫入 | 共用 HEAD／`index.lock` 互踩；真正並行寫入改用獨立 worktree（「2. 迭代」的「單 checkout repo 禁止多 session 並行寫入」） |
| 對已 push 的分支 force push | 已推歷史以新 commit 追加修正；`--force-with-lease` 僅限不得已且經使用者同意，需要乾淨歷史時於 MR 合併階段 squash |
| 以 cherry-pick 搬運 commit | 產生重複 commit、脫離合流血統；內容流動一律走 merge（工作分支 → develop / 發布分支），僅不得已（如自錯誤分支搶救個別 commit）且經使用者同意時使用 |
| 從 stale 本地發布分支開分支 | 必須先 `git fetch`、以 `origin/<發布分支>` 為基底 |
| 亂數 / 隨機 slug branch 名 | branch 名必須可辨識任務內容 |
| MR merged 後保留 branch / stash | 殘留分支與無主 stash 必須在「4. 清理」歸零。長期分支除外（「2. 迭代」的「長期分支」） |
| 沿用已 merged 的 branch 做新任務 | 新任務必須過新需求 gate、開新分支。長期分支除外（「2. 迭代」的「長期分支」） |
| 以 `git checkout -- .` / `git clean` 丟棄變更 | 變更只能 stash 或 commit，禁止 discard；`git clean -fdx` 會刪除被 ignore 的 `.env` 且無法救回，必要時先 `git clean -fdxn` dry-run |

## Checklist（執行前對照）

**開工前**：
- [ ] 新需求 gate：與當前分支無關的新需求，先以 AskUserQuestion 確認判定，確認後從最新 `origin/<發布分支>` 開新分支；確認前不動檔案
- [ ] 分支模式已選定：AskUserQuestion 詢問（使用者已指明免問）；一般分支為預設推薦，獨立／長期／並行任務改推薦 worktree；新任務起手即問，先於 brainstorming／設計流程
- [ ] 分支命名資訊已足（變更性質→prefix、具體對象→topic）；不足先問任務目的（可併入模式詢問），多候選以 AskUserQuestion 供選
- [ ] 偵殘留：`git branch --merged <發布分支>` + `git stash list` + `git worktree list`，有殘骸先清再開
- [ ] 一般分支模式：工作區不乾淨先 `git stash push -m "<說明>"`；`git fetch origin <發布分支>` 後 `git switch -c <prefix>/<topic> --no-track origin/<發布分支>`（語意命名）
- [ ] 一般分支模式：stash 屬於新主題才 pop；屬於原主題留在原分支
- [ ] worktree 模式：`git worktree add <worktree 路徑> -b <prefix>/<topic> --no-track origin/<發布分支>`（sibling 路徑），並依參數表補初始化檔案

**迭代期**：
- [ ] commit 全程純本地；使用者確認收斂前不 push、不開 MR
- [ ] 與上一個 commit 同一變更 → amend 併入並重寫 message（禁止 `--no-edit`）；無關 → 開新 commit。判準：這顆單獨看寫不寫得出有意義的「做了什麼」（「2. 迭代」規則 4）；**不要把整個 feature amend 成一顆**

**發布前**：
- [ ] 使用者已確認可發布，並已選定發布方式（有 develop gate：不用 develop 驗收／需要 develop 驗收／commit 留本地三選一；不提供不合 develop 的選項，3.0 發布前提規則 1）
- [ ] push 前自檢：`git status` 工作區乾淨、該分支的 MR 仍是 `opened`（已 merged 則另開新 MR 或開新分支，「2. 迭代」規則 5）
- [ ] repo 本地驗證通過（參數表「發布前本地驗證」欄）
- [ ] **首次合進 develop 前**（無 gate：首次 push 前）：本分支新增或修改了 `docs/plans/` 文件時（含未追蹤檔），先核對每項決策理由已寫進 docblock 或說明文件，缺的補完才繼續（3.0.5「盤點前先搬設計理由」）
- [ ] **首次合進 develop 前**（無 gate：首次 push 前）做歷史檢討（3.0.5）：盤點未推 commit → 判讀該併／該拆 → 目標形狀交使用者拍板 → 打保命 tag → `reset --mixed` 分組重建 → `git diff --quiet <tag> HEAD` 驗零遺失（exit code 為 0 才算零遺失，見第 6 步）
- [ ] **首次合進 develop 前**（無 gate：首次 push 前）：參數表「新邊界重工掃描」欄非「無」的 repo，判斷這條分支有無新增系統邊界，有才載入該 skill 掃描（3.0.6）；無則跳過、零輸出
- [ ] **首次合進 develop 前**（無 gate：首次 push 前）先 `git fetch origin <發布分支>` 後以 `git merge-tree --write-tree origin/<發布分支> HEAD` 事前判斷：CLEAN 直接進下一步、不預合；CONFLICT 才 `git merge origin/<發布分支>`（依 3.5 MR 衝突解法解、**禁無腦 `--ours`**）
- [ ] 有 develop gate：參數表有常駐 develop worktree（如 APIs）就不分分支模式一律在它裡面操作、主 checkout 不動；沒有才在主 checkout 操作：有未提交變更先 stash，再 `git switch -C develop origin/develop`
- [ ] 有 develop gate：合 develop 用 `--no-ff`；push 被拒則 pull-retry；沒有常駐 develop worktree 時，一般分支模式完成後切回工作分支並 `stash pop`（有 stash 才 pop），worktree 模式完成後主 checkout 切回發布分支（見 3.1「worktree 模式的 develop 操作位置」）
- [ ] 有 develop gate：選「需要 develop 驗收」先等 develop 的 CI/CD 通過（含部署 job），通過後回報使用者通知 QA，接著首推工作分支、開 Draft MR；選「不用 develop 驗收」同樣先等 develop 的 CI/CD 通過（含部署 job），通過後不必通知 QA，直接首推工作分支、開 MR。兩者都不在 develop 上做任何修改（3.2）。使用者明說「hotfix」時照樣合 develop，但 dev 部署不當合併關卡（3.0 規則 1）
- [ ] 有 develop gate：develop pipeline 被取消時改看包含本分支的最新一條；別人造成的紅燈以 AskUserQuestion 讓使用者選等、重跑或放行開 MR（需要驗收時沒有放行選項），AI 不自行放行（3.2）
- [ ] 有 develop gate：不論哪種發布方式，都要在 develop pipeline 的部署 job 查到 `success` 後，到需求主單留言「已部署測試環境」（3.2「dev 部署成功後回主單留言」）
- [ ] 有 develop gate：合進 develop 之後的修正一律新 commit 追加，不 amend、不 rebase（「2. 迭代」規則 4）
- [ ] 首次 push 工作分支（`git push -u origin <feature-branch>`）→ 開 MR，target = 發布分支、`squash=false`（整條分支是同一件事的迭代才改 true，3.3 開 MR 規則 2）
- [ ] MR description 涵蓋全部迭代 commit 的變更摘要；審查期追加 commit 後同步更新
- [ ] MR title / description 不含讀者查不到的內部代號（節號、task 編號、對話簡稱），引用文件附完整路徑（見 3.3 開 MR 規則 3）
- [ ] 工單有測試子任務（hotfix 除外）：MR 標題加 `Draft: ` 開出；`list_child_items` 查到測試子任務 `CLOSED` 才拿掉前綴改回 Ready，口頭轉述不算；改回 Ready 後，最新 MR pipeline 裡 Draft 期間失敗、`allow_failure` 是 `false` 的合併關卡 job 先用 `retry_pipeline_job` 重跑，`test` 停在手動時用 `play_pipeline_job` 啟動，所有 `allow_failure` 是 `false` 的 job 都跑成功才回報可合併，其他狀態照規則 8 的狀態表；試行模式（`allow_failure` 是 `true`）的關卡失敗只在回報裡註明，不等它（3.3 開 MR 規則 8）
- [ ] 參數表要求 PM 確認檢查時：開 MR 前跑 `reference/pm-confirm-check.md`，沒通過就開成 Draft；改回 Ready 前、回報可合併前都再跑一次，通過才拿掉前綴或回報可合併；改回 Ready 後同樣照規則 8 重跑失敗的合併關卡 job、啟動停在手動的 `test`（3.3 開 MR 規則 9）
- [ ] 等 MR 自己的 pipeline 通過（`detailed_merge_status=mergeable`），有 develop gate 時另查 develop 的 CI/CD 通過、dev 環境部署成功（hotfix 與使用者已放行時除外），才回報可合併；隔天放假時改回報「建議下個上班日再合」（3.3 開 MR 規則 5）
- [ ] 開完 MR 停止，不自動合併（回報連結，等使用者本人按）
**MR 合併後（使用者按下後才做），強制終結**（長期分支某一波合併後不做，見「2. 迭代」的「長期分支」）：
- [ ] 確認 MR state=merged
- [ ] 遠端 branch 已刪（`remove_source_branch=true` 或手動 `--delete`）
- [ ] `git switch <發布分支>` + `git merge --ff-only origin/<發布分支>` 拉最新
- [ ] worktree 模式：先結束 codex 對這個 worktree 的背景服務（4.4 的 `pkill`），再 `git worktree remove --force <worktree 路徑>` + `git worktree prune`
- [ ] 刪本地 branch：先 `git merge-base --is-ancestor <feature-branch> <發布分支>` 驗證內容已進主線，再 `-D`；**禁止無條件 `-d || -D`**（`-d` 失敗有兩種原因，worktree 模式下多半是 HEAD 不在發布分支而非 squash，見 4.5）
- [ ] stash 歸零：`git stash list` 無本任務殘留
- [ ] 驗證歸零：`git branch --merged <發布分支>` 與 `git worktree list` 無本任務殘留
