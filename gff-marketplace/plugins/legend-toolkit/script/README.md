# legend-toolkit 部署 Makefile（人工操作）

透過 `make` 指令對 RD-Studio 各站台上版，以及對 `WEB_AgentBackend`、`API_Agent`、`WEB_CustomerServiceSocket` 執行分支合併、推送等操作。

> 🤖 **這份是給人在自己 Terminal 跑的。** Claude 代跑 RD-Studio 部署走 `deploy-rd-studio` skill 的原始 `gcloud --command`，**不經本 Makefile**——每個 target 內建 `read -p` 二次確認（`CONFIRM`），需要 TTY，Claude Bash 無 TTY 會被判「已取消」中止。`back/` / `api/` / `cs/` 的流程與決策規範見 `../skills/deploy-agent-stack/`。
>
> 完整流程一覽：`make help`

## 環境需求

使用前請確認已安裝以下工具：

| 工具 | 用途 | 安裝指令 |
|---|---|---|
| `make` | 執行 Makefile 指令 | `sudo apt install make` |
| `git` | 分支合併與推送 | `sudo apt install git` |
| `gcloud` | SSH 登入與部署 rd-studio | 參考 [Google Cloud SDK 安裝文件](https://example.invalid/sdk/docs/install) |

---

## repo 路徑設定（只有 `back/`、`api/`、`cs/` 系列需要）

`rd/` 系列免設定（直接 `gcloud compute ssh`）。`back/` / `api/` / `cs/` 需要 `WEB_AgentBackend`、`API_Agent`、`WEB_CustomerServiceSocket` 的本機路徑，**用環境變數傳入（不用 `.env`）**：

```bash
# 一次解出並套用（推薦）：/resolve-repos 會自動探測 + 驗證 origin，輸出 export 行
eval "$(在 Claude 跑 /resolve-repos 取得的 export 區塊)"

# 或手動 export 一次，該 shell session 內的 make 都生效
export AGENT_BACKEND_REPO_DIR=/your/path/to/WEB_AgentBackend
export API_AGENT_REPO_DIR=/your/path/to/API_Agent
export CS_SOCKET_REPO_DIR=/your/path/to/WEB_CustomerServiceSocket
make back/deploy-dev

# 或單次命令列覆蓋
make back/deploy-dev AGENT_BACKEND_REPO_DIR=/your/path/to/WEB_AgentBackend
```

> 未給時 Makefile 用預設 `$HOME/projects/www/{WEB_AgentBackend,API_Agent,WEB_CustomerServiceSocket}`。

---

# WEB_AgentBackend / API_Agent / WEB_CustomerServiceSocket 上版流程

`back/` = WEB_AgentBackend、`api/` = API_Agent、`cs/` = WEB_CustomerServiceSocket，**三者指令與分支拓樸完全相同**，以下用 `<前綴>` 代表其一。

## 分支拓樸

**dev 由 feature 分支直接合入；`master` 只收 QA 驗收通過的內容，再由 `master` 合往 prod。**

```
feature ──┬──> develop / tpower-develop / vw-develop / wtt-develop   （上 dev，QA 驗收）
          │
          └─(驗收通過)─> master ──┬──> tpower-master tpower-new alfawin77-new
                                 │    fon666 vplus33 yes2win     （上 prod：tpower）
                                 ├──> vw-master vwb2c            （上 prod：vworld）
                                 └──> wintoto                    （上 prod：wintoto）
```

> 🔑 **為什麼 dev 不從 `master` 合**：dev 是驗收場，內容要能單獨進退。`master` 若先收了未驗收的 feature，被 QA 退掉的那份就洗不掉，之後每次上 prod 都會帶著它。
> ⚠️ **`tpower-master` / `vw-master` 是 prod 的「目標」，不是 merge 來源。** 舊版 Makefile 曾把它們當來源（`tpower-master` → fon666 …），與現行做法不符，已更正。
> ⚠️ **`tpower-hk` 不在本 Makefile 內**：它目前由 `tpower-master` 手動合入，需要時請自行處理。
> **`develop` 是不綁白標的共用 dev 分支**，與三支站台 dev 並列（變數 `COMMON_DEV`）。它**沒有**單獨的 `<前綴>/<站台>/deploy-dev` target——要單獨補跑就直接跑 `deploy-dev`，已合過的分支再跑是 no-op。
> ⚠️ **`develop2` 刻意不納入**：最後一次提交 2024-12-29，判定為停用分支。若要重啟需先確認後再加進 `COMMON_DEV`。

## 四個步驟

| 步驟 | 指令 | 做什麼 |
|---|---|---|
| 1. 上 dev | `make <前綴>/deploy-dev FEATURE=<分支>` | `<分支>` → `develop` `tpower-develop` `vw-develop` `wtt-develop`，全部 push |
| 2. QA 驗收 | —— | 有修正就改**同一支 feature**，再跑一次步驟 1，直到驗收完成 |
| 3. 收進主幹 | `make <前綴>/promote-master FEATURE=<分支>` | 驗收通過才跑：`<分支>` → `master`，接著 push `master` |
| 4. 上 prod | `make <前綴>/deploy-prod` | 互動選擇範圍後，`master` → 該組 prod 分支，全部 push |

> `FEATURE` 未給時步驟 1 / 3 會直接中止並提示正確用法，不會誤用預設值。
> 步驟 2 的修正**不要另開分支**——改回同一支 feature 再重跑步驟 1，這樣 `master` 最後收到的是完整且驗過的那一份。

### 步驟 3 的範圍選單

`deploy-prod` 會先問要一次上完還是一站一站：

```
  [a] 全部三組（tpower + vworld + wintoto）
  [t] 只 tpower   → tpower-master tpower-new alfawin77-new fon666 vplus33 yes2win
  [v] 只 vworld   → vw-master vwb2c
  [w] 只 wintoto  → wintoto
  [q] 取消
```

選 `[a]` 時三組依序執行，**任一組失敗或被取消即中止，後面的組不會跑**。要一站一站測也可以跳過選單直接指定：`make back/tpower/deploy-prod`。

> **vworld 上 prod 完成後會自動為 `vw-master` 建立時間戳記 tag**（`_online_<YYYYMMDDHHMM>`）。

### 每個目標分支實際跑的指令

merge 前**先確認來源分支是最新的**，整批只做一次（`<來源>` = 步驟 1/3 是 feature 分支、步驟 4 是 `master`）：

```
git fetch origin <來源>
# 落後 origin → 自動 git merge --ff-only origin/<來源> 快轉
# 領先 / 分岔 origin → 視 STRICT_SOURCE 決定中止或只警告（見下方「來源分支同步檢查」）
```

目標分支若在本機不存在（**新 clone 只有 `master`**），先從 origin 建起來，不會卡在 `checkout` 失敗：

```
git fetch origin <目標分支>:<目標分支>     # 只對本機缺的做；已存在的完全不動
```

接著逐一處理每個目標分支：

```
git checkout <目標分支>
git pull origin <目標分支>
git merge <來源> -m "Merge branch '<來源>' into <目標分支>"
```

**先把該組全部 merge 完，才開始 push。** 所以某支衝突時會停在推任何東西之前。

### 來源分支同步檢查

同一支腳本（`merge_branches.sh`）依來源分兩種嚴格度，由 Makefile 的兩個變數帶入：

| Makefile 變數 | 用在 | 來源 | `STRICT_SOURCE` |
|---|---|---|---|
| `MERGE_FROM_FEATURE` | 步驟 1 上 dev、步驟 3 `promote-master` | `$(FEATURE)` | 不帶（只警告） |
| `MERGE_FROM_MASTER` | 步驟 4 各 `deploy-prod`、`merge-all` | `master` | `=1`（會中止） |

**`STRICT_SOURCE=1`（來源為 `master`，上 prod）**：

| 本機 `master` 相對 origin | 行為 |
|---|---|
| 一致 | 直接繼續 |
| 落後 | **自動 fast-forward** 更新後繼續（`--ff-only`，不會產生 merge commit） |
| 領先（有未 push 的提交） | **中止**——否則會把 origin 沒有的提交推上白標站台分支 |
| 與 origin 分岔 | **中止**，需人工處理 |

**不帶 `STRICT_SOURCE`（來源為 feature 分支，上 dev / promote）**：本機 feature 領先遠端、甚至遠端還沒有這支，都屬正常，只警告不中止。目標分支本來就會在迴圈內 `git pull origin <目標分支>`。

---

## 分支清單改哪裡（SSOT）

分支清單集中在 **Makefile 開頭的變數區**（`TPOWER_PROD`、`ALL_DEV`…），`back/`、`api/`、`cs/` 共用同一份，`pull-all` / `push-all` 的 `ALL_BRANCHES` 也由它們組出來。**新增或改站台分支只改那一處**，不要改 `tools/git/*.sh`——那些腳本不含任何分支預設值，清單一律由 Makefile 傳入（未傳即報錯中止）。

```
tools/git/
├── ensure_branches.sh  # BRANCHES → 本機缺的從 origin 建立（已存在的不動）
├── merge_branches.sh   # SOURCE_BRANCH + BRANCHES → 逐一 merge（不推送）
├── push_branches.sh    # BRANCHES → 逐一 push
├── pull_branches.sh    # BRANCHES → 逐一 pull
├── tag_release.sh      # TAG_BRANCH → 建 _online_<時間戳> tag 並推送
└── clear_feature_branch.sh
```

### 失敗行為

- `merge_branches.sh` — 合併前先跑來源分支同步檢查（見上節），之後**失敗即停**（回傳非 0，make 隨即中止後續步驟）。合併衝突會先 `git merge --abort` 還原，不把衝突狀態留給後面的 push。
- `push_branches.sh` / `tag_release.sh` — **失敗即停**（回傳非 0，make 隨即中止後續步驟）。
- `pull_branches.sh` — 逐分支容錯（本機缺某分支不影響其他分支），但結尾列出失敗清單並以非 0 結束。所以 `pull-all` 可以先跑來確認缺哪些分支，其餘 target 則一遇錯就停。

---

## 其他指令

| 指令 | 說明 |
|---|---|
| `make <前綴>/ensure-branches` | 補齊本機缺少的分支（從 origin 建立，已存在的不動） |
| `make <前綴>/pull-all` | 拉取所有分支最新代碼 |
| `make <前綴>/push-all` | 推送所有分支到遠端 |
| `make <前綴>/merge-all` | **維護用（不是上版路徑）**：`master` 同步到全部 dev + prod 分支（**不推送**），用於讓長期沒動的分支追上 |
| `make <前綴>/vworld/tag` | 為 `vw-master` 建立時間戳記 tag |
| `make <前綴>/clear-feature` | 刪除本地所有 `feature/*` 分支 |
| `make <前綴>/clear-feature DRYRUN=1` | 預覽要刪除的 `feature/*` 分支（不實際刪除） |

> **不確定指令會做什麼？** 先用 `make -n <target>` 預覽將執行的指令，不會實際執行任何操作。
> 例如：`make -n back/tpower/deploy-prod`

### 兩個已知落差（本 Makefile 尚未涵蓋）

從三個 repo 的實際 git 紀錄看到、但目前沒有對應 target 的做法，**要做請自行手動處理並自負驗證**：

1. **`issues_*` / `hotfix/*` / `fix/*` 直接合進 `vw-master` / `tpower-master`（prod）** — `WEB_AgentBackend` 與 `API_Agent` 各約 9~10 次，`WEB_CustomerServiceSocket` 亦有（如 `fix/op_issue_2296_game_wallet` 直合 `vw-master`），繞過 dev 驗收直上 prod。緊急修正若真要這樣走，記得事後把同一份內容補回 `master`，否則下次 `deploy-prod` 會用舊內容覆蓋回去。
2. **`clear-feature` 只認 `feature/*`** — 實際分支名大量是 `issues_*` / `issue_*` / `op_*`，這些**不會被清到**，需要自己 `git branch -d`。

---

# rd-studio

對各站台執行上版、SSH 登入、查看 git log。

| 站台 | deploy | ssh | log |
|---|---|---|---|
| alfawin77 | `make rd/alfawin77/deploy` | `make rd/alfawin77/ssh` | `make rd/alfawin77/log` |
| yes2win | `make rd/yes2win/deploy` | `make rd/yes2win/ssh` | `make rd/yes2win/log` |
| tpower | `make rd/tpower/deploy` | `make rd/tpower/ssh` | `make rd/tpower/log` |
| vworld | `make rd/vworld/deploy` | `make rd/vworld/ssh` | `make rd/vworld/log` |
| me77 | `make rd/me77/deploy` | `make rd/me77/ssh` | `make rd/me77/log` |
| dev | `make rd/dev/deploy` | `make rd/dev/ssh` | `make rd/dev/log` |

> **代碼推上機器不會自動 build**：`git push` 後仍須跑對應 `rd/<站台>/deploy` 才會在 VM 上更新。
> 分支策略：dev 走 `develop`、prod 走 `master`（`update_rd.sh` 會 `git checkout develop`；`update_live.sh` 依賴 gcloud source repo 的 default branch）。
> `make rd/tpower/deploy` 一次會更新 tpower-new + tpower-hk 兩站（`update_live.sh` 已含兩者）。

⚠️ **alfawin77 / yes2win / me77** 的 env file / 部署目標 / 分支尚未在文件中確認；deploy 指令可用，但上版前建議先 `make rd/<站台>/ssh` 進機器確認 `.env.*` 與 default branch。

## 上 prod 前後該做的事

`rd/<站台>/deploy` 只負責「在 VM 上跑 update 腳本」，不含驗證。完整的**部署前 preflight**（`<TARGET_SHA>` 是否已在 `origin/master`）與**部署後四件事驗證**（grep 行號 / mtime / `git rev-parse` / `merge-base`），見 `../skills/deploy-rd-studio/` skill 的 §3 / §5 與其 `reference/verification.md`、`reference/troubleshooting.md`。**prod 上版請照那套走，不要只 deploy 不驗。**
