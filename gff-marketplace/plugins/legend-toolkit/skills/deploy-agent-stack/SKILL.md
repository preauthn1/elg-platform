---
name: deploy-agent-stack
description: WEB_AgentBackend、API_Agent 與 WEB_CustomerServiceSocket 的多站台上版流程（feature 直接上四支 dev、QA 驗收通過後才 feature → master，再由 master 上各 prod 分支的 merge + push），分支清單以 plugins/legend-toolkit/script/Makefile 的變數區為準。當使用者人在這三個專案的目錄裡說「上版」「部署」（例如「上版吧」「部署上正式」），或提到上版舊代理後台（WEB_AgentBackend）/ 上版 API_Agent / 上版客服 socket（說「部署」亦同）、推 WEB_AgentBackend、推 API_Agent、推客服 socket、上版 WEB_CustomerServiceSocket、把 feature 合進 dev 或 master、合併到 develop / tpower-develop / vw-develop / wtt-develop、合到 tpower-master / vw-master / wintoto / fon666 / vplus33 / yes2win / tpower-new / alfawin77-new / vwb2c、make back/… 或 make api/… 或 make cs/…、清 feature 分支，或問這三個專案的上版順序 / dev 先驗再上 prod / Claude 能不能代跑時，必須使用此 skill。單講「上版」「部署」時依目前所在的專案（看工作目錄與 git remote）判斷，不必再問是哪個專案；看不出是哪個專案時才問。「發版」指 APIs 發 release，走 gitlab-release；RD-Studio 站台上版走 deploy-rd-studio，不走本 skill。🚫 新代理後台 agent-backend（remote `target/gffv3/agent-backend`，Vben Admin 的 pnpm monorepo）不是 WEB_AgentBackend。人在 agent-backend 目錄裡說「上版」「部署」，或只說「代理後台」「後台」、沒點名 WEB_AgentBackend 時，不走本 skill：新後台走 MR 與 release tag，分支與 MR 走 release-workflow，發版走 gitlab-release。
---

# WEB_AgentBackend / API_Agent / WEB_CustomerServiceSocket 上版流程

> 🚫 **本 skill 不管新代理後台 agent-backend**（`target/gffv3/agent-backend`）。它是另一個 repo，靠 MR 和 `release_*` tag 部署，走 `release-workflow` 與 `gitlab-release`。沒點名 WEB_AgentBackend 的「代理後台」，一律當成新後台。

> 故障處理（衝突、push 被拒、部分推成功、誤建 tag、誤上 prod）見 [reference/troubleshooting.md](reference/troubleshooting.md)。

## 1. TL;DR

這三個專案的「部署」**只有 git 動作**：把 `feature` merge 進各 dev 分支、把 `master` merge 進各 prod 分支，然後 push。本 skill 與 `make` 都**不碰伺服器**、不跑 build。

**四步驟**：上 dev（把 `feature` 合進四支 dev）→ QA 驗收（有修正就改**同一支 feature**再跑一次）→ 收進主幹（`feature → master`，含 push）→ 上 prod（`master →` 各站台分支）。

**`master` 只收驗收通過的內容**——dev 是驗收場，內容要能單獨進退；`master` 若先收了未驗收的 feature，被 QA 退掉的那份就洗不掉，之後每次上 prod 都會帶著它。

人工操作走 `plugins/legend-toolkit/script/` 的 `make`（清單見該目錄 `README.md`，`make help` 一覽）。**Claude 不要呼叫 `make`**：每個 target 有 `read -p` 二次確認（`CONFIRM`），需要 TTY，Claude Bash 無 TTY 會被判「已取消」`exit 1`，動作不會發生。Claude 代跑時用 §4 的原始 git 指令。

## 2. 分支拓樸

**dev 由 feature 分支直接合入；`master` 只收驗收通過的內容，再由 `master` 合往 prod。**

```
feature ──┬──> develop / tpower-develop / vw-develop / wtt-develop   （上 dev，QA 驗收）
          │
          └─(驗收通過)─> master ──┬──> tpower-master tpower-new alfawin77-new
                                 │    fon666 vplus33 yes2win     （上 prod：tpower 組）
                                 ├──> vw-master vwb2c            （上 prod：vworld 組）
                                 └──> wintoto                    （上 prod：wintoto 組）
```

| 階段 | 來源 | 目標分支 | 對應 target |
|---|---|---|---|
| 上 dev | `<feature 分支>` | `develop` `tpower-develop` `vw-develop` `wtt-develop` | `<前綴>/deploy-dev FEATURE=<分支>` |
| 收進主幹 | `<feature 分支>` | `master` | `<前綴>/promote-master FEATURE=<分支>` |
| 上 prod：tpower | `master` | `tpower-master` `tpower-new` `alfawin77-new` `fon666` `vplus33` `yes2win` | `<前綴>/tpower/deploy-prod` |
| 上 prod：vworld | `master` | `vw-master` `vwb2c`（**推完還要為 `vw-master` 打 `_online_` tag，那顆 tag 才是 vworld 的上版開關**，見 §4 規範 5） | `<前綴>/vworld/deploy-prod` |
| 上 prod：wintoto | `master` | `wintoto` | `<前綴>/wintoto/deploy-prod` |

> 🔴 **上 prod 預設一次推全部站台分支，不問「上哪一組」。** 全站跑同一份代碼（2026-10-16 確認），分組只是 Makefile 留給人工單獨處理的 target，不是部署時要做的選擇；問組別等於每次上版多一個來回。要只上某一組或單一站台時，由使用者明說，Claude 不自行縮範圍、也不自行拆組。
> 🔴 **清單以 `plugins/legend-toolkit/script/Makefile` 開頭的變數區（`ALL_DEV` / `TPOWER_PROD` …）為 SSOT。** 本表為對照用；要精確值就讀該檔變數區，不要憑本表或記憶硬寫。
> ⚠️ **`tpower-master` / `vw-master` 是 prod 的目標，不是 merge 來源。** 這兩支曾經是來源（舊 Makefile 寫 `tpower-master` → fon666），現已不是——搞反會把過期內容推上白標站台。
> ⚠️ **`tpower-hk` 不在流程內**：目前由 `tpower-master` 手動合入，Makefile 未涵蓋。要動它必須先問使用者。
> **`develop` 是不綁白標的共用 dev 分支**（Makefile 變數 `COMMON_DEV`），與三支站台 dev 並列、同樣直接收 feature。**上 dev 是四支不是三支**——只推三支等於漏掉它。
> ⚠️ **`develop2` 不在流程內**：最後一次提交 2024-12-29，判定為停用分支，**不要合任何東西進去**。
> **`<前綴>`**：`back` = WEB_AgentBackend、`api` = API_Agent、`cs` = WEB_CustomerServiceSocket，三者拓樸與指令完全相同（分支清單同一份，已核對三個 repo 的 `git ls-remote` 皆具備上表所有分支）。
> ⚠️ **`wintoto-new` 只存在於 WEB_CustomerServiceSocket 且不在流程內**：最後一次提交 2024-12-06，判定為停用分支，勿把它加進 `WINTOTO_PROD`（wintoto 組的目標只有 `wintoto`）。

## 3. 前置檢查

| 檢查項 | 指令 / 行動 |
|---|---|
| repo 路徑已設定 | `back/` 需 `AGENT_BACKEND_REPO_DIR`、`api/` 需 `API_AGENT_REPO_DIR`、`cs/` 需 `CS_SOCKET_REPO_DIR`；未設可跑 `/resolve-repos` 取得 export 行（Makefile 預設 `$HOME/projects/www/<repo>`） |
| 站對 repo | `git -C "$REPO_DIR" remote get-url origin` 尾段須為 `/r/WEB_AgentBackend`、`/r/API_Agent` 或 `/r/WEB_CustomerServiceSocket` |
| working tree 乾淨 | `git -C "$REPO_DIR" status --porcelain` 無輸出（有未提交變更時 merge / checkout 會失敗） |
| **來源 feature 分支確定**（上 dev / promote） | 使用者明確給出分支名；`git -C "$REPO_DIR" rev-parse --verify "refs/heads/<feature>"` 存在。**不要自己猜**是哪一支 |
| **本機 `master` 與 origin 同步**（僅上 prod 需要） | `git -C "$REPO_DIR" fetch origin master` 後比對 `git rev-parse master` 與 `git rev-parse origin/master`；**不一致就不要 merge**（處置見 §4 步驟 A） |
| **`<TARGET_SHA>` 已在 `master`**（僅上 prod 需要） | `git -C "$REPO_DIR" fetch origin --quiet && git -C "$REPO_DIR" merge-base --is-ancestor <TARGET_SHA> origin/master && echo ✅ \|\| echo ❌` |
| 上 prod 前：dev 已由 QA 驗過 | 對話中確認，不自行假設 |
| **上 prod 前：PM 確認檢查**（僅上 prod 需要） | 步驟 A 裡 `master` 確認最新之後、任何 merge 之前跑；程序見該步 |

> `<TARGET_SHA>` = 本次要上的具體 commit SHA（建議完整 40 碼），**不是** `git rev-parse origin/master`——那會變成「檢查 master 是否在 master」永遠 pass。
> 🔴 **不要把 `master` 合進 dev。** 工作分支一律從最新 `master` 開，合進 dev 時會把 `master` 的內容一起帶進去，dev 自己就會追上——**不另設對齊步驟**，短暫落後屬正常，下一條分支合進來就補齊。合流方向單向：`master` 的內容隨工作分支進 dev，dev 的內容不得以任何方式回到 `master` 或工作分支。（通則出處：`release-workflow` skill「develop CI gate」）

## 4. 執行（Claude 代跑）

Claude 代跑等同手動重現 `make` 的步驟，逐一分支，**不用 `make`**（§1）。

**步驟 1（上 dev）——內容來源是 feature 分支**。四支 dev 各重複一次：
```bash
cd "$AGENT_BACKEND_REPO_DIR"          # 或 $API_AGENT_REPO_DIR / $CS_SOCKET_REPO_DIR
# 本機沒有這支 dev 分支時（新 clone 只有 master）先建起來；已存在則跳過此行
git rev-parse --verify --quiet "refs/heads/<dev 分支>" >/dev/null || git fetch origin "<dev 分支>:<dev 分支>"
git checkout <dev 分支>
git pull origin <dev 分支>
git merge <feature 分支> -m "Merge branch '<feature 分支>' into <dev 分支>"
git push origin <dev 分支>             # 需使用者確認才推
```

**步驟 2（QA 驗收）**：有修正就**改回同一支 feature 分支** commit，再重跑步驟 1。**不要為修正另開分支**，否則 `master` 最後收到的不是完整那一份。

**步驟 3 之前：PM 確認檢查（feature 合進 `master` 之前必做）**：這步在確認這支 feature 對應的需求主單（`target/tickets` 的工單）已經由 PM 驗收過。依據是主單掛著「0. PM確認」標籤，而且最後加上標籤的人在團隊名單 `team-roster.yaml` 的 PM 組（怎麼讀名單寫在下面的程序文件裡）。`master` 只收驗收通過的內容，沒經 PM 確認的內容一進 `master`，下一次上 prod 就會跟著上去。

- **程序**：照 dev-toolkit `release-workflow` 的 PM 確認檢查做。程序文件不在本 plugin 裡，用 `get_file_contents(project_id="target/gffv3/gff-marketplace", file_path="plugins/dev-toolkit/skills/release-workflow/reference/pm-confirm-check.md", ref="main")` 讀，讀不到就停下來回報，不跳過。
- **範圍**：這裡沒有 MR，只看 commit 標題與內文：
  ```bash
  git fetch origin master
  git log --no-merges --format='%s%n%b' origin/master..<feature 分支>
  ```
- **豁免**（符合條件就不必查工單，直接算通過）：照該文件，任一條成立即可：feature 分支名以 `hotfix/` 開頭；commit 全是 docs／style／chore／test／revert；提到的工單全部是線上問題單（`target/op_tickets`）而且查得到。`hotfix/` 分支名的豁免只有這一步看得到：合進 `master` 之後，commit 就分不出來自哪條分支，所以上 prod 的步驟 A 不套這條。
- **沒通過**：停下來，把不通過的主單與原因列給使用者，不 merge、不 push。要不要照常合進 `master` 由使用者決定，Claude 不自行放行。使用者口頭說「PM 同意了」不算通過，請 PM 在主單貼標籤。

**步驟 3（收進主幹，feature → master）——確認 QA 已驗收通過才做**：
```bash
git checkout master
git pull origin master
git merge <feature 分支> -m "Merge branch '<feature 分支>' into master"
git push origin master                 # 需使用者確認才推
```

**步驟 A（上 prod 前必做，整批一次）——確認 `master` 是最新的**：
```bash
cd "$AGENT_BACKEND_REPO_DIR"          # 或 $API_AGENT_REPO_DIR / $CS_SOCKET_REPO_DIR
git fetch origin master
git rev-parse master origin/master     # 兩個 SHA 必須相同才往下走
```
不相同時分三種情況，**不要一律 `git pull` 帶過**：

| 狀況（判斷指令） | 處置 |
|---|---|
| 落後：`git merge-base --is-ancestor master origin/master` 成立 | `git checkout master && git merge --ff-only origin/master`，再繼續 |
| 領先：`git merge-base --is-ancestor origin/master master` 成立 | **停**——本機有未 push 的提交，合出去會讓 prod 分支含有 origin/master 沒有的內容。回報並問使用者要不要先 push `master` |
| 分岔（兩者皆不成立） | **停**，回報兩邊各有哪些提交，交由使用者決定 |

**步驟 A 的第二件事：PM 確認檢查（`master` 確認最新之後、任何 merge 之前）**：這步在確認這次要帶上 prod 的改動，需求主單都已經由 PM 確認。放在這裡、不放在 merge 與 push 之間，是因為這三個 repo 上 prod 常與同事並行，merge 到 push 的窗口要壓到最短，查工單的時間不能夾在中間。

- **程序**：和「步驟 3 之前：PM 確認檢查」相同，用同一個 `get_file_contents(project_id="target/gffv3/gff-marketplace", file_path="plugins/dev-toolkit/skills/release-workflow/reference/pm-confirm-check.md", ref="main")` 讀程序文件，讀不到就停下來回報，不跳過。
- **範圍**：先把全部目標分支拉到最新，再對每個目標分支各列一次這次會帶上去的 commit。各分支的清單合併、去掉重複後，整批跑一次檢查。目標分支清單以 Makefile 變數區為準（見「分支拓樸」一節）。
  ```bash
  git fetch origin
  git log --no-merges --format='%s%n%b' origin/<目標分支>..origin/master   # 每個目標分支各跑一次
  ```
- **和回主單留言共用**：這和「API_Agent 上 prod 生效後回需求主單留言」一節的「找這次帶上 prod 的 commit」是同一套取法（那邊的 `--format` 多帶 `%H` 與分隔線）。步驟 A 算好的 commit 清單可以留給那一節共用，不必重算。
- **豁免**：照該程序文件，commit 全是 docs／style／chore／test／revert，或提到的工單全部是線上問題單（`target/op_tickets`）而且查得到，就豁免。`hotfix/` 分支名的豁免這裡不套：`master` 裡的 commit 分不出來自哪條分支，看不到分支名。
- **沒通過**：停下來，把不通過的主單與原因列給使用者，不 merge、不 push。要不要照常上 prod 由使用者決定，Claude 不自行放行（與 `deploy-rd-studio` 一致）。
- **結果寫進回報**：通過、豁免或不通過，格式照程序文件的「回報結果」一節，附名單版本，寫進「完成後回報」第 7 項。

**步驟 4（上 prod，master → 各目標分支）**，步驟 A 通過後，每個目標分支重複：
```bash
git rev-parse --verify --quiet "refs/heads/<目標分支>" >/dev/null || git fetch origin "<目標分支>:<目標分支>"
git checkout <目標分支>
git pull origin <目標分支>
git merge master -m "Merge branch 'master' into <目標分支>"
git push origin <目標分支>             # 需使用者確認才推
```

規範：

1. **先全部 merge、確認無衝突，才開始 push**（與 `make` 一致）。這樣某支衝突時不會有半套內容已上遠端。
2. **一次一個分支**，不把多分支塞進同一個 Bash 呼叫。
3. **遇衝突立停**：`git merge --abort` 還原，把完整輸出貼回使用者，**不自行解衝突**（衝突內容屬業務決策）。
4. **不主動 push**：merge 完成後由使用者確認才 push（`release-workflow` 的「使用者確認前不 push」同樣適用）。
5. 🔴 **vworld 的 `_online_` tag 是上版開關，不是事後紀錄**：vworld 這一組（`vw-master`、`vwb2c`）的部署由 tag 觸發，**只 push 分支不會上版**；其餘站台則是 push 到 prod 分支就自動部署（見 §4-9）。`make <前綴>/vworld/deploy-prod` 會跑 `tools/git/tag_release.sh` 為 `vw-master` 建 `_online_<YYYYMMDDHHMM>`。Claude 代跑時照樣打，並在回報裡寫出 tag 名與它指向的 SHA；使用者明說不打時才跳過，同時告訴對方 vworld 這次不會上版。
   - **tag 一律打在 `vw-master` 上**（`tag_release.sh` 的 `TAG_BRANCH=vw-master`）。repo 根目錄的 `run_git_create_production_tags.bat` 是對 `master` 打，那是舊做法，不要照它做。
   - tag 名用台北時間：`TZ=UTC-8 date '+_online_%Y%m%d%H%M'`，不要寫 `TZ=Asia/Taipei`（Windows 的 Git Bash 認不得，會少 8 小時）。

### 🛑 Prod 決策閘（四條件必須同時滿足才動 prod 分支）

1. 使用者明確說出**專案**（`back` / `api` / `cs`，或其中數個）。範圍預設為全部站台分支，**不要回頭問組別**；使用者自己指定某一組或單一站台時才照指定範圍做
2. 使用者明確說「現在執行」/「上 prod」等價指令（不是只在討論流程）
3. `<TARGET_SHA>` 已 `merge-base --is-ancestor` 驗證在 `origin/master`，且 **dev 環境已由 QA 驗過**
4. **這次要帶上 prod 的改動，需求主單都已經由 PM 確認**：步驟 A 的 PM 確認檢查通過或豁免。沒通過而使用者決定照常上時，要由使用者明說，Claude 不自行放行。

任一條件不滿足 → 停下用 `AskUserQuestion` 釐清，不自行假設。跨專案（back + api + cs 任兩個以上同時上 prod）或跨環境（dev + prod 同時）預設拒絕，須逐一確認。

> ⚠️ auto mode（自動模式：由分類器判斷命令能不能直接執行）下，分類器內建一條「Production Deploy」攔阻規則：部署到正式環境時，使用者必須點名部署目標才會放行。若使用者在 `~/.claude/settings.json` 的 `autoMode` 另外設了規則，把白標 prod 分支標成正式環境部署目標，推這些分支就會被這條規則擋下。被擋時，請使用者在對話裡點名要推的分支（或組別），並明說這是正式環境部署。只回「1」這種選項編號，不算點名。

## 4-9. push 之後怎麼確認真的上了（GKE rollout）

API_Agent（`agent-webapi`）**除了 vworld 那一組之外**，push 到 prod 分支後**自動部署、延遲約 8–9 分鐘**（2026-08-20 兩站實證：TPower 約 9 分、Yes2Win 約 8 分）。

⚠️ **vworld（`vw-master`、`vwb2c`）不是 push 觸發，是 `_online_` tag 觸發**（§4 規範 5）。沒打 tag 就不會有 rollout，這時查不到換版是正常的，不要當成部署失敗去排查。

**驗證要看 ReplicaSet hash 切換，不要查 `k8s_pod`**：

```bash
gcloud logging read 'resource.type="k8s_cluster"
  AND jsonPayload.involvedObject.name:"agent-webapi"
  AND jsonPayload.reason="ScalingReplicaSet"
  AND timestamp >= "<UTC>"' \
  --project=<gcpProject> --limit=30 --order=asc \
  --format='value(timestamp,jsonPayload.message)'
```

- `Scaled up replica set <name>-<新hash> from 0 to N` ＋同秒 `Scaled down ...-<舊hash>` ＝**一次真部署**。
- 只有單邊 `Scaled up/down` 且是同一個 hash ＝ **HPA 擴縮容，不是部署**（旁邊常伴 `SuccessfulRescale`）——別誤判成上版。
- 交叉確認可取 `resource.labels.pod_name:"<新hash>"` 的最早 log 當生效時刻。

🔴 **不要用「線上有沒有出現某個 error code」當生效證明**：那需要剛好有人操作到該情境，查無不代表沒生效。改用 rollout 時刻 vs push 時刻比對。

⚠️ API_Agent 的版控在 **Google Cloud Source Repositories，沒有 MR/PR 功能**；慣例是本地 merge 進 master 後直接 push。GitLab 上的 `target/gffv2/api_agent` 是 2022 年的死副本，**別在那開 MR、別拿它當來源**。repo 內的 `.gitlab-ci.yml` 是遺留檔，CSR 不會執行。

## 4-10. API_Agent 上 prod 生效後回需求主單留言

這步在做什麼：API_Agent 上正式環境並確認生效後，到這批內容對應的需求主單（target/tickets 的工單）留言「已部署正式環境」。PM 與 QA 不看部署紀錄，從工單就能知道需求已經上線。找主單與留言的規則和 `gitlab-release` skill 的「第 8 步：上線後回需求主單留言」是同一套，下面照抄它的表格，只補上 API_Agent 沒有 MR、要從 commit 找工單號的做法。

啟動時機：步驟 4 的 push 完成，而且照上一節「push 之後怎麼確認真的上了」查過各站的 ReplicaSet 換版之後。

**1. 判斷能不能留言**：

| 各站的查詢結果 | 處置 |
|---|---|
| 有 GCP 專案可查的站，全部查到換版 | 可以留言 |
| 有任一站還沒換版，或部署還在跑 | 不留言。回報哪幾站還沒生效，之後再查一次 |
| 沒有 GCP 專案可查的站 | 回報裡列為「已 push、無法確認生效」，不擋留言 |

有沒有 GCP 專案可查，以 `apis-toolkit` 的 `gcp-log-troubleshoot` skill 開頭的「白標 → GCP project 對照表」為準。例如 VPlus33 在表上標「無 GCP log」，fon666 不在表內，這兩站都屬於無法確認。

**2. 找這次帶上 prod 的 commit**：這次帶上 prod 的 commit，是 push 之前 `origin/<prod 分支>` 沒有、push 之後有的非 merge commit。在步驟 4 每個目標分支 merge 完、push 之前，先記下這份清單：

```bash
git log --no-merges --format='%H%n%s%n%b%n---' origin/<目標分支>..<目標分支>
```

各目標分支的清單合併後去掉重複，就是這次上 prod 的內容。步驟 A 的 PM 確認檢查已經用同一套取法列過這份清單（只差沒帶 `%H`），找工單號只需要標題與內文，可以直接沿用，不必重算。

**3. 找需求主單**：從上一步每個 commit 的標題與內文取工單號，再換成它所屬的主單。

| 找到的工單號 | 留言對象 |
|---|---|
| `target/tickets#NNNN`（需求單） | 就是主單，直接留言 |
| `target/ticket_tasks#NNNN`（子任務） | 子任務所屬的主單：`list_child_items(project_id="target/ticket_tasks", iid=NNNN)` 回傳的 `parent`（例如 `target/tickets#4426`）。`get_issue` 讀不到跨專案的父子關係，不要用它找。`parent` 是 `null` 或查詢失敗時列給使用者，不猜 |
| 其他工單（例如 `target/op_tickets#NNNN` 線上問題單），或完全沒有工單號 | 不留言，列給使用者看 |

- 同一張主單出現在多個 commit：只留一則。

**4. 留言**：每張主單各留一則，內文固定為 `已部署正式環境`，不加其他文字。

```
create_work_item_note(project_id="target/tickets", iid=NNNN, body="已部署正式環境")
```

**5. 回報**：列出每張工單的留言回傳（note id），作為完成證據。

🔴 **有 GCP 專案可查的站沒有全部查到換版，就不能留言。** 使用者口頭說「上線了」只代表可以開始查，不能取代查詢結果：只有部分站生效時留言，工單上就會留下不實的紀錄。

⚠️ 這步只適用 API_Agent。WEB_AgentBackend 與客服 socket 無法確認生效，Claude 不自動留言，改由使用者確認上線後再決定要不要留言。

## 5. 完成後回報（缺一視為未完成）

1. 專案（back / api / cs）與範圍（預設全部站台分支；使用者縮過範圍才列是哪幾支）
2. 每個目標分支的 merge 結果（fast-forward / 產生 merge commit / 已是最新）
3. 每個目標分支 push 後的 `git rev-parse origin/<branch>` SHA
4. `git merge-base --is-ancestor <TARGET_SHA> origin/<branch>` 結果（✅ contains / ❌ MISSING）
5. vworld prod：`_online_` tag 名稱與它指向的 SHA。沒打時要明說「vworld 這次不會上版」，不能只寫「未打 tag」
6. API_Agent 上 prod：需求主單留言結果（照「API_Agent 上 prod 生效後回需求主單留言」一節）。已留言的附工單號與 note id；沒留言的寫原因（哪幾站還沒生效、哪些 commit 找不到主單）；沒有 GCP 專案可查的站列為「已 push、無法確認生效」
7. PM 確認檢查結果（步驟 A）：通過、豁免（寫哪一條）、或不通過但使用者決定照常上；附名單版本（`team-roster.yaml` 的 commit_id 前 8 碼）

> ⚠️ push 完成**不等於**站台已更新。API_Agent（`agent-webapi`）照「push 之後怎麼確認真的上了」一節查到 ReplicaSet 換版，才能在回報裡說已上線；沒查到就寫「已 push、尚未確認生效」。WEB_AgentBackend 與客服 socket 的生效方式還沒寫成文件，請使用者向 QA 或維運確認，回報裡不說已上線。

## 6. 驗收標準（使用者檢核 Claude 用）

1. **沒有用 `make`**（§1）：Claude 一律用原始 git 指令。
2. **來源分支對得上階段**（§2）：上 dev 與 `promote-master` 的**內容來源**是 **feature 分支**；上 prod 的來源才是 `master`。
3. **`tpower-master` / `vw-master` 是目標不是來源**（§2）：搞反會把過期內容推上白標站台。
4. **上 prod 前確認過 `master` 是最新的**（§3、§4 步驟 A）：`fetch` 後 `master` 與 `origin/master` 同 SHA；領先或分岔一律停下問，不自行 `git pull` 帶過。
5. **上 dev 沒有把 `master` 合進去**（§3、§4 步驟 1）：dev 只收 feature；工作分支從 `master` 開，`master` 的內容會跟著進去。使用者沒明說就自行補合 `master` 是錯的。
6. **`master` 只收驗收通過的內容**（§1、§4 步驟 3）：QA 未驗過就跑 `promote-master` 是錯的；步驟 3 之前沒跑 PM 確認檢查、或沒通過就自行合進 `master`，也是錯的。
7. **分支清單來自 Makefile 變數區**（§2）：不是憑記憶列站台分支。
8. **prod 四條件齊備**（§4）：專案明確（範圍預設全部站台分支，沒問組別）、明確「現在執行」、`<TARGET_SHA>` 在 `master` 且 dev 已驗、步驟 A 的 PM 確認檢查通過或豁免（沒通過時由使用者明說照常上，Claude 不自行放行）。
9. **衝突不自行解**（§4）：`merge --abort` 後回報。
10. **回報七件事**（§5）；API_Agent 查到 ReplicaSet 換版才說已上線，沒查到寫「已 push、尚未確認生效」；WEB_AgentBackend 與客服 socket **不說已上線**。
11. **vworld 有打 `_online_` tag，而且打在 `vw-master` 上**（§4 規範 5）：那顆 tag 是 vworld 的上版開關，只 push 分支不算上版；把它講成事後紀錄、或打到 `master` 上都是錯的。
12. **API_Agent 上 prod 生效後才回主單留言**（§4-10）：有 GCP 專案可查的站全部查到換版才留 `已部署正式環境`，同一張主單只留一則，回報附 note id；`ticket_tasks` 用 `list_child_items` 換成主單，op_tickets 或沒有工單號的列給使用者、不猜；WEB_AgentBackend 與客服 socket 不自動留言。
13. **上 prod 的 PM 確認檢查在步驟 A、merge 之前跑**（§4 步驟 A）：不放在 merge 與 push 之間；不通過就停下列原因，不 merge、不 push，照常上與否由使用者決定。
