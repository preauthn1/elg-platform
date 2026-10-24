---
name: branch-sync
description: 把本地 git 分支快轉對齊遠端最新（fast-forward）。當使用者說「同步分支」「同步 git」「對齊分支」「對齊 git」「分支拉到最新」（含點名分支，如「把 develop 拉到最新」）「把專案拉到最新」「本地落後遠端幾個 commit」時觸發。🚫 單講「拉最新」沒說對象時不觸發，先問要拉什麼；單講「對齊」是對帳的說法（走 `reconcile-bet-records`），不觸發；拉的是工單／log／報表／plugin 時也不觸發。開分支 / push / 開 MR / 合併後清理走 `release-workflow`；多站台 merge 上版走 `deploy-agent-stack`。
---

# 分支對齊（branch-sync）

把**當前 repo** 所有設有 upstream 的本地分支 fast-forward 到各自遠端最新，異常分支一律**跳過並回報**，不擅自處理。

兩個詞先講白話：**upstream** 是本地分支對應的那條遠端分支（例如本地 `master` 對應 `origin/master`）；**fast-forward（快轉）** 是本地分支落後遠端、且沒有自己的新 commit 時，直接把本地指標往前移到遠端位置，不產生任何新 commit、不改任何檔案內容。

**做的事**：`fetch` → 逐支判狀態 → 能快轉的快轉 → 出一份逐支回報表。

**不做的事**（各有去處，本 skill 不重述其規則）：

| 不做 | 去處 |
|---|---|
| 開工作分支、push、開 MR、合併後清理分支與 stash | `release-workflow` |
| 多站台 merge/push 上版 | `deploy-agent-stack`（legend-toolkit） |
| 打 tag、建 Release | `gitlab-release` |
| `--prune` 清遠端追蹤 ref | 不屬本 skill 職責，不做 |
| 自動建立「遠端有、本地沒有」的分支 | 不做（會把幾十上百支遠端分支灌進本地） |
| 自動 `stash` / `merge` / `rebase` / `push` | 不做（見「禁止清單」） |

---

## 1. 判定執行位置

```bash
git rev-parse --show-toplevel   # 取 repo 根目錄；不在 git repo 內就停下告知
```

多數 repo（`WEB_*` / `Hyperf/*` / marketplace / 任何外部專案）**全部命令都在 host 執行**，直接進第 2 步。

只有 **APIs / API_\*** 這類「本地 git 操作須在容器內」的 repo 需要拆開執行位置（`fetch` 走 host、快轉走容器）。對照表、`docker exec` 模板與容器未啟動時的處置見 [`reference/execution-contexts.md`](reference/execution-contexts.md)。

以下命令一律標示 `[host]` 或 `[本地]`：`[host]` 恆在 host；`[本地]` 在一般 repo 是 host，在容器 repo 依 reference 改走 `docker exec`。

## 2. 抓遠端最新（不改任何本地分支）

```bash
# [host]
git fetch origin
```

- **不加 `--prune`**。
- **輸出為空是正常的**：代表遠端追蹤 ref（`refs/remotes/origin/*`）在先前某次 fetch 已更新過。此時本地分支**仍可能落後**：「落後」比較的是本地已有的快照，不是即時遠端。**絕對不可因為 fetch 沒輸出就回報「已是最新」並結束**，必須繼續第 3 步實際盤點。

## 3. 盤點分支狀態

三條唯讀命令，讀輸出後在第 4 步分流：

```bash
# [本地] 每支本地分支的：分支名 | 目前 SHA | upstream | 落後/領先
git for-each-ref --format='%(refname:short)|%(objectname:short)|%(upstream:short)|%(upstream:track)' refs/heads

# [本地] 當前 checkout 的分支，以及「已追蹤檔」有無未提交變更
git symbolic-ref --short HEAD
git status --porcelain -uno

# [host] 哪些分支正被其他 worktree 佔用（輸出含 worktree 路徑與其 branch）
git worktree list --porcelain
```

> **`-uno` 不可省**：`git status --porcelain` 預設會列出未追蹤檔（`?? .claude/` 之類），但未追蹤檔**不會**阻擋 `merge --ff-only`：git 只在乎已追蹤檔有無未提交變更。用預設值當判斷依據，會把「這個 repo 有個沒進版控的資料夾」誤判成「工作區髒」而白白跳過當前分支（實測於 API_Agent 出現過）。

`%(upstream:track)` 是 git 對「本地與 upstream 差幾顆 commit」的描述，它的取值即為分流依據：

| track 值 | 意義 |
|---|---|
| `[behind N]` | 純落後，可快轉 |
| `[ahead N]` | 有未推送的本地提交 |
| `[ahead N, behind M]` | 已分岔 |
| `[gone]` | upstream 遠端分支已被刪除 |
| 空字串 | 已與 upstream 同步 |
| （整欄 upstream 為空） | 該分支沒有設 upstream |

## 4. 分流表（逐支比對，不得跳過判斷）

| 狀態 | 動作 | 回報分類 |
|---|---|---|
| `[behind N]`，非當前分支，未被其他 worktree 佔用 | 快轉（第 5 步 A） | ✅ 已更新 |
| `[behind N]`，是當前分支，且 `git status --porcelain -uno` 無輸出 | 快轉（第 5 步 B） | ✅ 已更新 |
| `[behind N]`，是當前分支，但已追蹤檔有未提交變更 | **跳過**，提示先自行 `git stash push -m` 或 commit（本 skill 不代為 stash） | ⚠️ 需人工 |
| `[behind N]`，但該分支被**其他** worktree checkout | **跳過**，回報佔用它的 worktree 路徑，請使用者到該工作區處理 | ⚠️ 需人工 |
| `[ahead N]` 或 `[ahead N, behind M]` | **跳過**，回報 ahead / behind 數字 | ⚠️ 需人工 |
| `[gone]` | **跳過**，回報「遠端分支已刪除」，並提示可能是已合併的工作分支、清理走 `release-workflow` | ⚠️ 需人工 |
| 無 upstream | **跳過** | ℹ️ 無追蹤 |
| track 為空 | 不動作 | ℹ️ 已最新 |

## 5. 執行快轉

### A. 非當前分支（多數情況）

用 upstream 的遠端追蹤 ref 直接更新本地 ref，**不切換分支、不動工作區**。`up` 取第 3 步該支的 upstream 欄（例 `origin/master`）：

```bash
# [本地]
git fetch . "refs/remotes/${up}:refs/heads/${b}"
```

- 上面引號內的 `來源:目的` 寫法叫 refspec（告訴 git「拿哪個 ref 更新哪個 ref」）。refspec **不加 `+` 前綴**：git 因此只接受 fast-forward，萬一該支其實有本地提交會直接失敗而非靜默覆蓋。這是本步驟的安全閥，**任何情況都不得加 `+`、不得改用 `git update-ref`**。
- 逐支各跑一次並**獨立讀取該支的返回**（`rc` 與訊息），不得沿用前一支的結果或依模式預測。

### B. 當前分支（工作區乾淨時）

```bash
# [本地]
git merge --ff-only "${up}"
```

被 git 以 `Please commit your changes or stash them` 擋下 → 改判為「⚠️ 需人工」，**不要自行 stash 後重試**。

第 3 步的 `-uno` 檢查只是預先分流，**最終裁決權在 git**：它會依「incoming commits 是否真的動到那些髒檔」決定放不放行，比任何前置檢查都準。前置檢查放寬、由 git 擋，是刻意的分工。反過來把前置檢查寫嚴，只會產生本來能快轉卻被跳過的假陽性。

## 6. 回報

輸出一張逐支表（**寫在回覆的 markdown 裡**，不要用 shell `echo` 產生），欄位：

| 分支 | 落後 | 舊 SHA → 新 SHA | 結果 |

末尾給彙總：`N 支已更新 / M 支已最新 / K 支需人工 / J 支無追蹤`，並把每一支「需人工」的原因與建議下一步逐條列出（分岔數字、佔用的 worktree 路徑、未提交變更等）。

「需人工」的分支**不得省略不報**：它們正是使用者需要知道的部分。

## 範例（實測，APIs repo）

使用者：「**把分支拉到最新**」

第 3 步盤點拿到（節錄）：

```
develop|f1a87329f|origin/develop|[behind 3]
docs/sure-to-surepay-design-review|58df05585||
master|164aabdc9|origin/master|
refactor/multi-currency-wallet|7b8ddabcb||
refactor/payment-bank-convergence|ca42af908|origin/refactor/payment-bank-convergence|[ahead 1]
```

`git worktree list --porcelain` 顯示 `refactor/multi-currency-wallet` 與 `refactor/payment-bank-convergence` 各自被 sibling worktree 佔用。

分流結果：

| 分支 | 落後 | 舊 SHA → 新 SHA | 結果 |
|---|---|---|---|
| develop | 3 | `f1a87329f` → `<新 SHA>` | ✅ 已更新 |
| master | 無 | 無 | ℹ️ 已最新 |
| refactor/payment-bank-convergence | 無 | 無 | ⚠️ ahead 1，且被 `~/Workspace/APIs-payment-bank-convergence` 佔用 |
| refactor/multi-currency-wallet | 無 | 無 | ℹ️ 無 upstream（被 `~/Workspace/APIs-multi-currency-wallet` 佔用） |
| docs/sure-to-surepay-design-review | 無 | 無 | ℹ️ 無 upstream |

彙總：`1 支已更新 / 1 支已最新 / 1 支需人工 / 2 支無追蹤`。

> 這是容器 repo，故盤點與快轉都經 `docker exec php-fpm8`，只有 `git fetch origin` 與 `git worktree list` 在 host（見 reference）。

## 禁止清單

| 動作 | 理由 |
|---|---|
| 對非快轉的分支 `merge` / `rebase` / `reset --hard` | 會改寫或吞掉本地提交；一律停下交人工判斷 |
| refspec 加 `+`（強制更新 ref） | 拆掉唯一的 non-fast-forward 安全閥 |
| 自動 `git stash` 讓當前分支能快轉 | stash 紀律屬 `release-workflow`；此處代勞會讓使用者的 WIP 去向不明 |
| 自動 `git push` | 本 skill 只把本地追上遠端，方向單向 |
| `git fetch --prune` / 刪任何分支 | 不屬本 skill 職責 |
| 以預設 `git status --porcelain`（含未追蹤檔）判定工作區髒 | 未追蹤檔不阻擋 ff，會製造假陽性、白跳過當前分支；一律用 `-uno` |
| 因 `git fetch` 無輸出就回報「全部已最新」 | fetch 靜默是常態，本地分支仍可能落後（見第 2 步） |
| 只跑當前分支就宣告完成 | 預設範圍是全部有 upstream 的分支 |
| 未讀實際返回就宣告某支「已更新」 | 每支的成功與否須以該支命令的原始返回為憑 |
