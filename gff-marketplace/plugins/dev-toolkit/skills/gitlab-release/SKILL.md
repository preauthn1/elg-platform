---
name: gitlab-release
description: 已合併進發布分支之後的發版：打 tag、建 GitLab Release、彙整 MR 清單與部署風險，上線後回主單留言並整理 wiki 文件。當使用者說「發版」「發 release」「根據最近的 MR 發 release」，或發版後說「部署完了」「上線了，回主單留言」時載入。🚫 單講「打 tag」不觸發；單講「發布」「上線」「上版」「部署」交給 release-workflow 或各專案的部署 skill。
---

# GitLab Release 發布協議

一句話版本：**內容已經合進發布分支之後，挑一顆 commit 打上 `release_時間` 的 tag、建一個 Release 頁面列出這批進了哪些 MR、部署要注意什麼。建立前每一項都要給使用者看過、點頭，因為 tag 一打就不能改。隔天放假就預設不發，使用者明確放行才發。各品牌部署都成功後，到這批的需求主單留言「已部署正式環境」。接著列出這批主單讓使用者勾選，把勾選的功能交給 `wiki-toolkit:wiki-authoring` 整理成 wiki 功能開發說明文件（一張主單一份），寫完再到主單留言附上文件連結。**

## 定位與分工

| skill | 管什麼 | 顆粒度 |
|---|---|---|
| `release-workflow` | 排查 → 迭代 → MR → 合併 → 清理 | 一條工作分支 |
| **本 skill** | 對已合併的發布分支打 tag、建立 Release | 一次上版（一個 tag 常涵蓋多個 MR） |
| `git-commit` | 單一 commit message 格式 | 一個 commit |
| `wiki-toolkit:wiki-authoring` | wiki 功能開發說明文件怎麼寫、寫進 wiki（第 9 步交給它） | 一張主單一份 |

- 起點：內容**已經**在發布分支上。變更還在工作分支時，先走 `release-workflow` 走完 MR 與清理。
- 終點：參數表「上線後寫 wiki 功能開發說明文件」為「有」的 repo，終點是第 9 步完成：勾選的主單都已寫好功能開發說明文件、並已留言附上連結。使用者一張都不勾，或沒裝 wiki-toolkit 而跳過第 9 步時，終點是需求主單已留言「已部署正式環境」（第 8 步）。其他 repo 的終點是 Release 已建立。**實際部署由使用者或 ops 執行**，本 skill 不觸發、不代跑、也不催促部署，第 8 步只查部署結果、不按部署 job。
- 一個 tag 涵蓋多個 MR 是正常形態，不需要每個 MR 各打一個 tag。
- 使用者只說「發布／上線」而未指明 tag 或 Release 時，屬 `release-workflow` 的階段判斷，不逕行進入本協議。
- 單講「打 tag」不進入本協議：打 tag 只是發布流程裡的一步，不代表要發版。
- 單講「上版」「部署」時，依目前所在的專案判斷：走 MR 的專案（例如 APIs、新代理後台 agent-backend）交給 `release-workflow`；舊代理後台 WEB_AgentBackend、API_Agent、客服 socket 走 `legend-toolkit:deploy-agent-stack`；RD-Studio 走 `legend-toolkit:deploy-rd-studio`。

## repo 參數表

| 參數 | APIs | agent-backend | GFF-Marketplace | 其他未列 repo |
|---|---|---|---|---|
| GitLab project path（**未編碼**，見「GitLab 操作方式」） | `target/gffv3/apis` | `target/gffv3/agent-backend` | `target/gffv3/gff-marketplace` | 依 `git remote -v` 推得 |
| 發布分支 | `master` | `main` | `main` | `origin/HEAD` 所指分支 |
| 是否打 release tag | **有**（既有慣例；**且 tag 會啟動 prod 部署流程**，見下方 🔴） | **有**（同 APIs） | 無（不打 tag、無 Release） | 先以 `list_releases` 查有無既有慣例 |
| tag 命名 | `release_YYYYMMDDHHmm`（`Asia/Taipei`） | `release_YYYYMMDDHHmm`（`Asia/Taipei`，同 APIs） | 無 | 沿用該 repo 既有慣例，禁止自創格式 |
| 放假前閘門（第 0 步） | **有** | **有** | 無 | tag 會啟動部署時有 |
| PM 確認檢查（第 2 步規則 7） | **有** | **有** | 無 | tag 會啟動部署時有 |
| 上線後主單留言（第 8 步） | **有** | **有** | 無 | 無 |
| 上線後寫 wiki 功能開發說明文件（第 9 步） | **有** | **有** | 無 | 無 |

> 🔴 **APIs 與 agent-backend：建立 `release_*` tag 不只是標記版本，它是啟動 prod 部署流程的第一步。**
>
> 兩者同一模式：tag pipeline 的 `release-prod` job（`rules: if: $CI_COMMIT_TAG =~ /^release_\d+$/`）以 curl 打 **gff-cicd（project 526）** 的 trigger API，產生各站、各品牌的 prod 部署 job，job log 末尾會印出 `For production deployment, please visit <url> to select your target environment`。
>
> **那些部署 job 是人工閘門，tag 本身不會直接把程式送上 prod**，仍須有人到下游 pipeline 逐站按下去。所以第 5 步確認時要明講 tag 會啟動 prod 部署 pipeline（措辭見第 5 步），不可說成「只是標記版本、部署由 ops 另行執行」，後者會讓人以為 tag 與部署完全無關。
>
> APIs 的 `release-prod` 定義在 `.gitlab-ci/build/build.yml`，`needs: [build, test, prepare]`：**測試紅了就不會把 trigger 打去 project 526**，這是 prod 前唯一需要的自動把關。發布分支 push 的 pipeline 只跑 lint，不必在建 tag 前另外查它。

## GitLab 操作方式

GitLab 操作一律走 **gitlab MCP**（`gitlab-mcp` plugin），以 `project_id="<project path>"` 定位專案；skill 內不持有 token、不直接打 `api/v4`。

⚠️ **`project_id` 一律用未編碼的 slash 形式（`target/gffv3/apis`），禁止 URL-encoded 形式（`target%2Fgffv3%2Fapis`）。** releases 類工具（`list_releases`／`create_release`／`get_release`）對 encoded 形式回 `404 Project Not Found`（2026-07-30 實測），MR／pipeline 類工具兩種都收。看到 `404 Project Not Found` 是格式錯，先改格式重試。

## 流程

### 第 0 步：放假前閘門（隔天放假就不發版，要發須使用者明確放行）

這步在問：「明天要不要上班？」打 tag 會啟動 prod 部署流程，隔天放假的話，上線後出問題沒有人能即時處理。所以放假前一天（週五、連假前一天、週中單日假的前一天）預設不發版，由使用者決定要不要照常進行。

適用範圍：參數表「放假前閘門」為「有」的 repo。其他未列 repo 的 tag 會啟動部署時同樣適用；不確定時先讀該 repo `.gitlab-ci*` 對 `CI_COMMIT_TAG` 的 rules 再判定。

用工單小工具 `mcp__plugin_gitlab-mcp_gitlab-workitem__check_workdays` 判斷，不自己推算日期。`dates` 不填，它就以台灣時間的今天查明天，依據是政府的辦公日曆（週末、國定假日、補假、補班都已標好，放在 `gitlab-mcp` plugin 的 `calendar/`）：

| 回傳 | 意思 | 處置 |
|---|---|---|
| `days[0].is_workday` 為 `true` | 明天要上班 | `warnings` 為空時本步不輸出任何文字，直接進第 1 步 |
| `days[0].is_workday` 為 `false` | 明天放假（`days[0].note` 有假日名稱時一併帶出） | 依規則 1 停下來問 |
| 工具回錯誤（日曆還沒收錄該年、資料格式不對），或叫不到這個工具 | 判斷不了 | 依規則 2 停下來問 |

規則：

1. 明天放假時，**第 1 步之前先停下**，以 **AskUserQuestion** 詢問，推薦項置首；問題裡寫明「明天 `<日期>` 放假（`<假日名稱>`）」：
   - 選項①：**等下一個工作日再發**（推薦）：停手，不打 tag。
   - 選項②：**今天照常發**：使用者明確選這項才往下走第 1 步。
2. 判斷不了時同樣停下來，把錯誤原文告訴使用者，問「明天要不要上班」；使用者回答要上班才往下走。**不可因為判斷不了就當作可以發。**
3. 回傳的 `warnings` 非空（政府出了修正版或新年度、repo 還沒跟上）時，照常依判斷結果處置，並把 warnings 原文轉告使用者。更新日曆是另一件事：在 GFF-Marketplace 開工作分支，跑 `node plugins/gitlab-mcp/bin/tw-workday.js --update`（預設更新今年與明年，也可指定年份），把 `calendar/` 的變更走 MR 合進 main；不在發版流程中順手做。
4. 本步只放行「開始盤點」，不取代第 5 步的最終確認；第 5 步照常逐項列出並取得確認。
5. 使用者選照常發時，第 5 步 `question` 的一行摘要前綴 `⚠ 放假前發版`，讓最終確認時仍看得到這件事。

### 第 1 步：找上一個 release（本批的起點）

```
list_releases(project_id="<project path>", per_page=1)
# 讀第一筆的 tag_name 與 commit.id ＝ 本批的起點
```

```bash
git fetch origin --tags   # 後續 rev-list／diff 要用到上一個 tag 的本地 ref
```

### 第 2 步：決定 release SHA 與本批範圍

```bash
git fetch origin <發布分支>
git rev-parse origin/<發布分支>          # 預設候選：發布分支最新那顆（完整 SHA）
```

使用者要求縮到較早的 commit 時，該 SHA 必須在發布分支的 first-parent 主線上（不是某個 feature 分支內部的 commit）：

```bash
git rev-list --first-parent <上一個 tag>..origin/<發布分支> | grep -Fx '<release SHA>'   # 無輸出即停手回報
```

範圍盤點（右界一律用 `<release SHA>`）：

```bash
# (a) 這顆 commit 是不是已經發過（有 release_* tag 貼在上面）
git tag --points-at <release SHA> | grep '^release_'   # 有輸出 = 已發過，停手回報既有 tag，不重複建

# (b) 本批到底有沒有新內容
git rev-list --count <上一個 tag>..<release SHA>

# (c) 本批的 MR：merge commit body 含 "See merge request <path>!NNNN"
git log --first-parent <上一個 tag>..<release SHA> --merges --format='%H | %ci | %s%n%b'

# (d) 非經 MR 進入主線的 commit（直推、cherry-pick、fast-forward）
git log --first-parent <上一個 tag>..<release SHA> --no-merges --format='%h %s'
```

規則：

1. **只有 (b) 為 `0` 才是空範圍**，此時回報後停手、不打空 tag。
2. (a) 有輸出時不打新 tag：同一顆 commit 兩個 release 只會讓部署選錯版本；要重跑部署請用既有 tag 的 pipeline。
3. (d) 有輸出時不停手：照常進流程，但 description 要多一段「非 MR 進入的 commit」列出這些 SHA 與標題，由使用者在確認時決定要不要納入。
4. `--format` 必須含 `%s`：description 需要 merge commit 標題，`%b` 只有 body。本批 MR 只從 merge commit 取；`list_merge_requests` MCP 回傳全專案近期 MR 且會撐爆 context，不要用。
5. 出現非預期的 merge（別人剛合入、與本次上版無關）時**必須回報**，由使用者決定納入或改小 `<release SHA>`。
6. **`<release SHA>` 一旦改變**（例如縮到較早的 commit），本步與第 4 步全部重跑，不可沿用先前結果，否則 description 會列出 tag 未涵蓋的內容。
7. **PM 確認檢查（參數表為「有」的 repo）**：這步在確認這批要上正式環境的改動，需求主單都已經由 PM 驗收過。MR 合併時檢查過一次，但 PM 可能在那之後拿掉標籤，所以打 tag 前再查一次。照 `../release-workflow/reference/pm-confirm-check.md` 逐步做：
   - (c) 的每個 MR 各跑一次：MR 描述用 `get_merge_request` 讀，commit 範圍是 `git log --no-merges --format='%s%n%b' <merge commit>^1..<merge commit>^2`；豁免條件照該文件，例如來源分支以 `hotfix/` 開頭的 MR 直接豁免。
   - (d) 的 commit 合成一批跑一次，沒有 MR 描述，只看 commit 標題與內文。
   - 任何一個不通過就停手，不打 tag。把不通過的 MR 與原因列給使用者，由使用者決定等 PM 貼標籤，或把 `<release SHA>` 縮到不含這些 MR 的 commit（縮了照規則 6 全部重跑）。使用者口頭說「PM 同意了」不算通過，請 PM 在主單貼上「0. PM確認」。

### 第 3 步：決定 tag 名

```bash
TZ=UTC-8 date '+release_%Y%m%d%H%M'   # 台北時間：POSIX 寫法的 UTC-8 就是 +08:00
date -u '+UTC %Y-%m-%d %H:%M'         # 核對用：上一行的時間應比這行多 8 小時
```

規則：

1. **不要寫 `TZ=Asia/Taipei`**：Windows 的 Git Bash 沒有時區資料庫，認不得這個名稱，不會報錯，而是直接輸出 UTC，tag 名會少 8 小時。`TZ=UTC-8` 不需要時區資料庫，在 Windows、macOS、Linux 都會得到台北時間。
2. **把兩行輸出一起給使用者看再往下走**：tag 名的時間要比 UTC 多 8 小時，對不上就停手。tag 打錯不能改名，只能往前補新的（見第 5 條）。
3. tag 名沿用該 repo 既有格式（參數表）。
4. **`ref`（建立 tag 時指定「貼在哪顆 commit 上」的參數）一律用 `<release SHA>`，禁止用分支名。** 以分支名當 `ref` 時，若建立瞬間發布分支又前進，tag 會指向未經第 2 步盤點的內容。
5. **禁止刪除或覆蓋既有 tag／Release**：它是對外的版本標記，可能已被部署流程引用。打錯就往前補一個新 tag。同名 tag 不另外預查：分鐘級名稱撞上的機率低，撞了 `create_release` 會失敗，換一分鐘重來即可。

### 第 4 步：掃部署風險（寫進 description）

這一步在問：「這批上去，除了程式之外還要動什麼？」資料庫、設定、排程的變更如果沒跟著部署，程式會跑錯。

```bash
git diff --name-status <上一個 tag>..<release SHA>                    # 全域：看有無新增／刪除／改名
git diff <上一個 tag>..<release SHA> -- database/migrations config .env.example <repo 的排程路徑>
```

規則：**命中的 migration／config／排程 diff 必須實際讀過內容**，不能只看檔名；`--name-only` 看不出 DROP、RENAME 或保留期變更。至少辨識並列出下列項目，其一命中就必須寫進 description 的「部署注意」段：

| 風險 | 為何要標 |
|---|---|
| 新增 migration（資料庫結構變更腳本） | 需與相關 MR 同車部署；漏帶會讓程式讀不到欄位 |
| 資料表或分表的 DROP、RENAME、清理保留期變更 | 不可逆，部署前需各站 dry-run 預演，並查證備份或 PITR（point-in-time recovery，資料庫時間點還原）可用 |
| `config/` 或 `.env.example` 變更 | 需同步各站環境變數，否則行為與預期不符 |
| 排程（schedule）行為變更 | 影響面跨站且非同步發生，出錯不會立即可見 |

風險項無法從 diff 判定影響面時，據實寫「需人工確認」，禁止推測部署步驟或代為宣稱已驗證。

### 第 5 步：逐項確認（不可豁免）

呼叫 `create_release` 前，必須向使用者**逐項列出**下列全部內容，並明示「此呼叫會立即在遠端建立**不可覆寫**的 tag」：

- `project_id`、發布分支、上一個 tag
- `tag_name`
- `<release SHA>` 完整值、commit 標題、作者、時間
- 本批 MR 清單（第 2 步 (c)）；有 (d) 時另列「非 MR 進入的 commit」
- PM 確認檢查結果（第 2 步規則 7，參數表為「有」的 repo）：每個 MR 是通過還是豁免，以及名單版本
- 即將寫入的 description 全文（含部署注意）
- **該 repo 的 tag 會不會觸發部署 pipeline**（見參數表）：**APIs 與 agent-backend 都會**，須明示「此 tag 將啟動 prod 部署 pipeline，各站、各品牌 job 待人工按下」；不確定時先讀該 repo `.gitlab-ci*` 對 `CI_COMMIT_TAG` 的 rules，禁止假設 tag 只是標記

列出後以 **AskUserQuestion** 取得一次明確確認。**不得免問**：先前或同一輪只說「發 release／打 tag／根據最近的 MR 發 release」不構成最終確認，使用者在看到 exact SHA、MR 清單、風險與 description 之前無從判斷；使用者主動指定 tag 名或範圍也只是輸入條件，不豁免本步。

#### 呈現格式（照 Release 頁面的樣子）

**不要把全部內容塞進 `question`**，終端主視窗擠成一片純文字很難讀。分工如下：

| 欄位 | 放什麼 | 原則 |
|---|---|---|
| `question` | tag 名 ＋ 不可覆寫提示 ＋ **一行摘要**（MR 數 · 風險旗標）＋ SHA 短碼 | 5 行內看完，一眼判斷「有沒有異常」 |
| 確認選項的 `preview` | 完整清單：標的表格 ＋ 本批 MR 表格 ＋ 部署注意 | Markdown 會被渲染，**直接長成 Release 頁面的樣子** |
| 取消選項 | `label` 取消、`description` 一句話 | 無 |

`preview` 內容即「第 6 步要寫進 `description` 的東西 ＋ 建立標的」，使用者看到的預覽與最終 Release 頁面一致，不需要腦內轉換：

```markdown
## release_YYYYMMDDHHmm

| 項目 | 值 |
|---|---|
| Project | `target/gffv3/apis` · master |
| 上一個 tag | release_… |
| Release SHA | `<40 字完整 SHA>` |
| Commit | `<title>` · `<author>`, `<date>` |
| tag 觸發部署 | **是，將啟動 prod 部署 pipeline**（APIs／agent-backend；各站 job 待人工按下） |

### 本批 MR

| MR | 標題 |
|---|---|
| !NNNN | … |

### 非 MR 進入的 commit（有才列）

| commit | 標題 |
|---|---|
| abc1234 | … |

### 部署注意

- …（無風險項時寫「無 migration、無 config / .env.example 變更、無排程異動」）
```

`question` 的一行摘要用 `·` 分隔；有 migration、DROP、預存程序（stored procedure）變更或非 MR commit 時前綴 `⚠`，讓使用者不點開預覽也知道這批不是純程式部署。

### 第 6 步：建立 Release

```
create_release(
  project_id="<project path>",
  tag_name="release_YYYYMMDDHHmm",
  name="release_YYYYMMDDHHmm",
  ref="<release SHA>",
  description="<MR 表格 ＋ 非 MR commit（有才列）＋ 部署注意>",
)
```

**name／description 預設**（無使用者另行指定時採用）：

- `name` ＝ tag 名（一 tag 多 MR 時用單一 MR 標題當 name 會失準）。
- `description` ＝ 本批 MR 表格（`!NNNN` ＋ merge commit 標題，GitLab 會自動轉連結）＋「部署注意」段。

`description` 的讀者是全團隊與負責部署的 ops，**可理解性要求與 commit message 相同**（規則見 `git-commit` skill 的「描述要寫給團隊看」一節）：自己撰寫的「部署注意」段不得使用讀者查不到的內部代號（個人 backlog 的節號、計畫文件的 task／phase 編號、只在某次對話中成立的簡稱），要引用文件就寫完整相對路徑與節名。

### 第 7 步：驗證與回報

1. 讀 `create_release` 回傳，核對 `tag_name`、`commit.id`（**須等於 `<release SHA>`**）、`created_at`；**以回傳原文作為完成證據**，禁止僅宣稱「已建立」。
2. `evidences` 在剛建立時為 `[]` 屬正常（GitLab 非同步採集），**不是失敗**，不需重試或重建。
3. 建立 tag 會觸發一條 `ref=<tag 名>` 的 tag pipeline；`release-prod` 在前面的 job 綠了之後才 trigger 下游部署 job（APIs 是 `build`／`test`／`prepare`；agent-backend 的 tag pipeline 只有 `lint:code` → `build` → `release-prod`）。回報時附這條 pipeline 連結，紅了就是這批不能上。
4. 回報 Release 頁面連結，並把第 4 步的風險項列為「剩下的 ops 工作」交還使用者。

### 第 8 步：上線後回需求主單留言（僅參數表「上線後主單留言」為「有」的 repo）

這步在做什麼：各品牌的正式環境部署都成功後，到這批 MR 對應的需求主單（target/tickets 的工單）留言「已部署正式環境」。PM 與 QA 不看 pipeline，從工單就能知道需求已經上線。

啟動時機：使用者回報部署 job 已經按完（「部署完了」「上線了」），或要求盯著部署跑完再回報。部署 job 要人工按，AI 不催促、不代按，只查結果。

**1. 找下游部署 pipeline**：第 7 步那條 tag pipeline 的 `release-prod` job，log 最後幾行會印出 gff-cicd 的 pipeline 網址。

```
get_pipeline_job_output(project_id="<project path>", job_id="<release-prod 的 job id>", limit=15)
# 找 "For production deployment, please visit <url>"
# <url> 形如 http://…/sre/gff-cicd/gffv3/-/pipelines/<pipeline id>
```

**2. 確認每個部署 job 都成功**：

```
list_pipeline_jobs(project_id="sre/gff-cicd/gffv3", pipeline_id="<pipeline id>", per_page=50)
```

| job | 判讀 |
|---|---|
| 名稱以 `deploy ` 開頭（例如 `deploy agent-backend to gff-tpower (tpower)`） | 每一個都要是 `success` 才算上線 |
| 名稱以 `choose ` 開頭（選要部署的品牌）、`info` | 不列入判斷 |

- 有 `deploy` job 還是 `manual`、`created`、`pending` 或 `running`：回報哪些品牌還沒上線，**不留言**，等使用者按完再查一次。
- 有 `deploy` job 是 `failed` 或 `canceled`：回報失敗的品牌與 job 連結，**不留言**，由使用者決定重跑或放棄。
- 下游 pipeline 顯示的 commit 是 gff-cicd 自己 `main` 的 commit，不是本批的 release SHA，屬正常。要部署的版本由 `release-prod` 以 `SRC_COMMIT_TAG` 等變數傳給下游。

**3. 找需求主單**：本次上線的每個 MR（第 2 步 (c) 盤出的 `!NNNN`）逐一找工單號，再換成它所屬的主單。

找工單號的順序：先看 merge commit 的標題與 body（body 帶有 MR 標題）；都沒有時，用 `get_merge_request(project_id="<project path>", merge_request_iid="<NNNN>")` 讀 MR 的 `description`。

| 找到的工單號 | 留言對象 |
|---|---|
| `target/tickets#NNNN`（需求單） | 就是主單，直接留言 |
| `target/ticket_tasks#NNNN`（子任務） | 子任務所屬的主單：`list_child_items(project_id="target/ticket_tasks", iid=NNNN)` 回傳的 `parent`（例如 `target/tickets#4426`）。`get_issue` 讀不到跨專案的父子關係，不要用它找。`parent` 是 `null` 或查詢失敗時列給使用者，不猜 |
| 其他工單（例如 `target/op_tickets#NNNN` 線上問題單），或完全沒有工單號 | 不留言，列給使用者看 |

- 同一張主單出現在多個 MR：只留一則。

**4. 留言**：每張主單各留一則，內文固定為 `已部署正式環境`，不加其他文字。

```
create_work_item_note(project_id="target/tickets", iid=NNNN, body="已部署正式環境")
```

**5. 回報**：列出每張工單的留言回傳（note id），作為完成證據；並列出部署成功的品牌清單。

🔴 **沒有查到「每個 `deploy` job 都是 `success`」，就不能留言。** 使用者口頭說「上線了」只代表可以開始查，不能取代查詢結果：只按了部分品牌時留言，工單上就會留下不實的紀錄。

### 第 9 步：把這批上線的功能整理成 wiki 功能開發說明文件（僅參數表「上線後寫 wiki 功能開發說明文件」為「有」的 repo）

這步在做什麼：需求上了正式環境，這次功能的規格與取捨還散在主單、主單回覆、MR 描述、ADR（architecture decision record，架構決策紀錄）與 MR 的程式變更裡。這步把它們收齊，交給 `wiki-toolkit:wiki-authoring` 寫進 wiki（公司內部的 Outline 站台）的「功能開發說明文件（待整合）」文件集，一張主單一份，團隊之後就查得到這次上線做了什麼、為什麼這樣做。GitLab 的讀寫都在本 skill 做，因為 wiki-toolkit 沒有連 GitLab 的工具；文件怎麼寫全照 wiki-authoring，本 skill 不重述任何 wiki 格式。

啟動時機：第 8 步已經在主單留言成功（拿到 note id）之後，接著做。

**1. 檢查能不能跑**：`wiki-toolkit:wiki-authoring` 不在可用的 skill 清單裡（叫不起來），或 Outline 的 MCP 工具（`mcp__plugin_wiki-toolkit_outline__*`）不在，就跳過本步，告訴使用者要裝 wiki-toolkit 才能寫 wiki 功能開發說明文件。此時第 8 步即為終點。

**2. 列清單讓使用者勾**：第 8 步第 3 小步找到的每張主單，都用 `get_issue(project_id="target/tickets", issue_iid="NNNN")` 讀標題、標籤與內文開頭，判斷屬於哪一類並給建議：

| 類別 | 建議 |
|---|---|
| 新行為（新功能、新規則） | 建議寫 |
| 改既有行為 | 建議寫 |
| 純修 bug | 建議不寫 |
| 純維護（重構、升級套件這類使用者看不到差別的改動） | 建議不寫 |

用 `AskUserQuestion` 讓使用者一次選完。這個工具一次最多 4 題，每題要有 2 到 4 個選項，所以依主單張數分三種問法：

| 主單張數 | 問法 |
|---|---|
| 1 張 | 單選題，兩個選項「寫進 wiki」與「不寫」。建議的那個排第一，`label` 後面加「（推薦）」；`question` 寫主單號與標題，`description` 寫類別與理由 |
| 2 到 16 張 | `multiSelect: true`，一張主單一個選項：`label` 放主單號與標題，`description` 放類別與建議，每題裡建議寫的排前面。主單平均分到最少的題數，每題 2 到 4 張，不可以有只剩 1 個選項的題。例：5 張分成 3＋2 兩題，不是 4＋1；9 張分成 3＋3＋3 三題 |
| 超過 16 張 | 選項放不下，改用文字問：編號列出全部主單，每張附類別與建議，請使用者回覆要寫的主單號；問的時候說明這次為什麼沒有選項可點（張數超過工具上限）。這是 `design-grilling`「退路：真的列不成選項時才用文字」一節的同一條退路 |

使用者選「不寫」或一張都不勾，就結束本步，第 8 步即為終點。

**3. 收素材**：只對勾選的主單收。每張主單對應哪些 MR，第 8 步第 3 小步已經對出來。

| 素材 | 怎麼拿 |
|---|---|
| 主單內文 | 第 2 小步 `get_issue` 回傳的 `description` 全文 |
| 主單回覆 | `list_issue_discussions(project_id="target/tickets", issue_iid="NNNN", per_page=100)`。看回傳的 `x_total_pages`，用 `page` 翻完每一頁。回覆裡有大量 `system: true` 的系統紀錄（指派、加標籤、掛子任務），全部濾掉，只留人工回覆 |
| MR 描述 | `get_merge_request(project_id="<project path>", merge_request_iid="<NNNN>")` 回傳的 `description` 全文；「與工單不同」的說明通常寫在這裡 |
| MR 的變更檔 | `list_merge_request_changed_files(project_id="<project path>", merge_request_iid="<NNNN>")`，排除鎖檔（例如 `composer.lock`、`pnpm-lock.yaml`）、vendor 與打包產物 |
| MR 的 diff 內容 | `get_merge_request_file_diff(project_id="<project path>", merge_request_iid="<NNNN>", file_paths=[...])` 讀上一列留下的檔。要讀內容，不能只看檔名：功能開發說明文件寫的是實際做出來的行為 |
| 這次動到的 ADR | 變更檔裡 `docs/adr/` 底下的每一份，用 `get_file_contents(project_id="<project path>", file_path="<路徑>", ref="<release SHA>")` 讀上線版本的全文 |

**4. 交給 wiki-authoring**：用 Skill 工具叫起 `wiki-toolkit:wiki-authoring`，把每張主單的素材包連同這次的 release tag、release SHA 與上線日期交給它，照該 skill「從上線的主單寫功能開發說明文件（由 gitlab-release 叫起）」一節執行。素材包要有哪些項目以那一節為準。分類、草稿全文給使用者確認、查重、寫進 wiki 都由它負責，本 skill 不重述。它寫完會交回每張主單寫進哪一份文件（分類路徑、文件標題、網址、新建或更新）。

**5. 回主單留言**：每張寫了文件的主單另留一則，第 8 步那則「已部署正式環境」不動。內文固定格式，一份文件一行：

```
功能開發說明已寫入 wiki：
- <文件標題>：<網址>
```

```
create_work_item_note(project_id="target/tickets", iid=NNNN, body="<上面的全文>")
```

**留言前把每張主單的留言全文給使用者看，使用者確認才送出。** 沒寫文件的主單不留言。

**6. 回報**：列出每張主單寫進哪一份文件（分類路徑、標題、網址、新建或更新）與留言回傳的 note id，作為完成證據；沒勾選的主單、勾了但最後沒寫文件的主單也列出來。

🔴 **沒有第 8 步的成功留言，就不進第 9 步。** 部署還沒全部成功，功能就還沒上線，不能寫成上線紀錄。

## Checklist

- [ ] 放假前閘門：適用的 repo 呼叫 `check_workdays` 查明天，明天放假或判斷不了先以 AskUserQuestion 問，使用者明確放行才往下走（第 0 步）
- [ ] 目標內容**已在**發布分支（未合併者先走 `release-workflow`）
- [ ] `list_releases(per_page=1)` 取上一個 tag；`git fetch origin --tags`
- [ ] `<release SHA>` 已定；非 HEAD 者已在 first-parent 主線上
- [ ] `git tag --points-at <release SHA>` 無 `release_*`（有 ＝ 這顆已發過，停手）
- [ ] `git rev-list --count <上一個 tag>..<release SHA>` 非 0（為 0 則停手）
- [ ] merge commit 盤出 MR 編號；非 MR 進入的 commit 列進 description 交使用者決定
- [ ] 非預期的 merge 已回報，未默默納入；換了 SHA 就重跑盤點與風險掃描
- [ ] tag 名以 `TZ=UTC-8 date` 產生（不用 `TZ=Asia/Taipei`），已和 UTC 對照確認多 8 小時；格式依 repo 慣例
- [ ] `project_id` 用未編碼 slash 形式
- [ ] 已讀命中的 migration／config／排程 **diff 內容**（非僅檔名），命中項寫入「部署注意」
- [ ] 已逐項列出 project_id／tag／SHA／MR 清單／description 全文與「tag 會啟動 prod 部署 pipeline」，並明示不可覆寫後取得 AskUserQuestion 確認；`question` 只放摘要、完整清單放 `preview`
- [ ] `ref` 傳的是 `<release SHA>` 而非分支名；不刪除、不覆蓋既有 tag
- [ ] `create_release` 回傳原文已核對（`tag_name`／`commit.id` 等於 `<release SHA>`／`created_at`）並作為完成證據
- [ ] `evidences: []` 未誤判為失敗；已附 tag pipeline 連結
- [ ] 已回報 Release 連結 ＋ 剩餘 ops 風險項，未代跑部署
- [ ] 上線後主單留言（參數表為「有」的 repo）：從 `release-prod` log 取得 gff-cicd pipeline，確認每個 `deploy` job 都是 `success` 才留言；從本次上線的每個 MR 找工單號（子任務換成所屬主單），每張 target/tickets 主單各留一則「已部署正式環境」，沒有工單的 MR 列給使用者；回報附 note id（第 8 步）
- [ ] 上線後寫 wiki 功能開發說明文件（參數表為「有」的 repo）：第 8 步留言成功才進；叫不起 wiki-authoring 或沒有 Outline 工具就跳過並告知；列出這批主單讓使用者選要寫哪幾張（1 張用單選、2 到 16 張用 `multiSelect: true` 且每題 2 到 4 張、超過 16 張改用文字列出請使用者回覆主單號）；只對勾選的主單收主單內文、回覆（翻完所有頁、濾掉 `system: true`）、MR 描述、MR 變更檔與 diff 內容、ADR 上線版本全文；交給 wiki-authoring 寫文件；回主單留言附文件連結前先給使用者看全文；回報附文件清單與 note id（第 9 步）
