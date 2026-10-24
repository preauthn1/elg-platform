# deploy-agent-stack 故障排除

只在出事時讀；正常流程見 [SKILL.md](../SKILL.md)。以下 `$REPO_DIR` = `$AGENT_BACKEND_REPO_DIR`、`$API_AGENT_REPO_DIR` 或 `$CS_SOCKET_REPO_DIR`。

## 對照表

| 症狀 | 原因 | 處置 |
|---|---|---|
| `merge_branches.sh` 印「合併 … 失敗（可能有衝突），已 git merge --abort 還原，中止」 | 目標分支與 `master` 有衝突 | 腳本已還原、**未留衝突狀態**，後續分支也未處理。人工在該分支解衝突後重跑同一個 target |
| `❌ 本機 master 領先 origin N 個提交（尚未 push），中止` | 本機 `master` 有沒推上去的提交 | **別直接繞過**。確認那 N 個提交是否該上版：該上 → `git push origin master` 後重跑；不該上 → 先移出 `master`。詳見下方「來源分支未同步」 |
| `❌ 本機 master 與 origin 已分岔，中止` | 本機與遠端 `master` 各有提交 | 同上，先讓 `master` 與 origin 一致再重跑 |
| `本機 master 落後 origin N 個提交，快轉更新中…` | 正常情況 | 非錯誤：腳本已 `--ff-only` 自動更新，合出去的是最新的 `master` |
| `⚠️ 遠端沒有 <branch>（或 fetch 失敗），略過來源同步檢查` | 來源是純本機分支，或網路 / 認證問題 | 上 dev / `promote-master` 用本機 feature 分支時屬正常；若來源應為 `master`（上 prod）卻出現此訊息，是 fetch 失敗，**先修連線再上版** |
| `切換到 <branch> 分支失敗，中止` | working tree 有未提交變更（缺分支已由 `ensure_branches.sh` 自動補齊，不會走到這裡） | `git -C "$REPO_DIR" status`，先 commit 或 stash |
| `無法從 origin 建立 <branch>（遠端可能沒有這支分支，或分支名打錯），中止` | Makefile 分支清單有錯字，或該分支尚未建於遠端 | 核對 Makefile 變數區與 `git ls-remote --heads origin`；分支確實不該存在就從清單移除 |
| `push` 被拒（non-fast-forward） | 遠端有他人推的提交 | 先 `git pull origin <branch>`（會產生 merge commit）再推；勿用 `--force` |
| `pull-all` 結尾印「以下分支未成功拉取：…」 | 部分分支本機不存在或拉取失敗 | 該清單就是待補分支；`pull-all` 是唯一容錯的 target，其餘一遇錯即停 |
| `請指定來源分支：make <target> FEATURE=<分支名>` | 上 dev / `promote-master` 沒帶 `FEATURE=` | 這兩步的來源是 feature 分支、沒有預設值。補上 `FEATURE=<分支>` 重跑 |
| `BRANCHES 未設定` / `SOURCE_BRANCH 未設定` | 直接手跑 `tools/git/*.sh` 而未帶環境變數 | 這些腳本不含分支預設值（避免第二份 SSOT），請走 `make`，或自行帶入變數 |
| `建立 tag … 失敗（同名 tag 可能已存在）` | 同一分鐘內重複跑 vworld prod | 確認前一次是否已成功；要重打先 `git tag -d <tag>` 並確認遠端狀態 |

## 來源分支未同步（`master` 領先或分岔）

以 `master` 為來源的 target（**各 `deploy-prod`、`merge-all`**）帶 `STRICT_SOURCE=1`，合併前會擋下這兩種狀態。**這道閘不要用 `STRICT_SOURCE=0` 繞過**——它擋的是「把 origin 上沒有的提交推進白標站台分支」，繞過之後 `origin/master` 與各站台分支就對不起來，之後所有 `merge-base` 驗證都會失真。

> 上 dev 與 `promote-master` 的來源是 feature 分支、**不帶** `STRICT_SOURCE`，所以不會遇到本節的中止；那邊「本機領先遠端」是正常狀態。

先看清楚差異：

```bash
cd "$REPO_DIR"
git fetch origin master
git log --oneline origin/master..master   # 本機有、遠端沒有
git log --oneline master..origin/master   # 遠端有、本機沒有
```

| 情況 | 處置 |
|---|---|
| 那些提交本來就該上版 | `git push origin master` 後重跑原 target |
| 那些提交是誤 commit 在 `master` 上 | `git branch feature/<名> master` 保住後，`git reset --hard origin/master`（**確認沒有其他人依賴本機 master 才做**） |
| 分岔 | `git checkout master && git pull origin master` 產生 merge commit，確認內容無誤後 push，再重跑 |

## merge 到一半中斷後的復原

腳本失敗即停且會 `merge --abort`，正常情況不會留下中間狀態。若是人工中斷（Ctrl-C）造成殘留：

```bash
cd "$REPO_DIR"
git status                 # 看是否在 MERGING
git merge --abort          # 還原
git switch master          # 回到來源分支
```

## 部分分支已推、後續失敗

prod 一次推多個分支（tpower 組六支），失敗即停代表**前面的已 push、後面的沒有**。處置順序：

1. 逐一確認實際狀態：`git rev-parse origin/<branch>`，與 `origin/master` 比對
   ```bash
   git fetch origin --quiet
   git merge-base --is-ancestor <TARGET_SHA> origin/<branch> && echo "✅ $branch" || echo "❌ $branch"
   ```
2. 修掉失敗原因後**重跑同一個 target**（已推成功的分支再跑一次是 no-op，安全）
3. 把「哪些分支已 push、哪些還沒 push」明確回報，不要含糊帶過

## 誤推 prod 分支要退版

這三個專案的 prod 分支是白標站台各自的分支，**退版一律用 `git revert` 產生反向提交後再推**，不要 `reset --hard` + force push（會破壞其他人的本機狀態，且白標站台可能已取用）。

```bash
cd "$REPO_DIR"
git checkout <prod分支>
git pull origin <prod分支>
git revert -m 1 <誤推的 merge commit SHA>   # merge commit 用 -m 1；一般 commit 不加
git push origin <prod分支>
```

> 退版後記得把同樣的 revert 回流 `master`，否則下次 deploy 又會把問題推回去。

## 誤在 dev 分支上開發

`*-develop` 是合併目標、不是開發分支。若已直接在上面 commit：把該 commit `cherry-pick` 到對應的 feature 分支後，於 develop 上 revert，維持「內容永遠從 feature 流向 develop」的單向關係。

直接在 develop 上 commit 最大的問題是**那份內容永遠到不了 `master`**——`master` 只從 feature 分支收，所以它不會出現在任何 prod 站台，dev 上驗過的東西上線後就消失。

## dev 驗過了，但內容沒進 prod

先確認步驟 3 有跑過。上 dev 只讓 feature 進到三支 develop，**`master` 不會自動拿到**：

```bash
cd "$REPO_DIR"
git fetch origin --quiet
git merge-base --is-ancestor <feature 分支> origin/master && echo "✅ 已收進 master" || echo "❌ 還沒跑 promote-master"
```

顯示 ❌ 就是漏了步驟 3（`make <前綴>/promote-master FEATURE=<分支>`）。這是新流程最容易漏的一步：dev 綠燈不代表 `master` 有東西。

## 內容只在某一支站台分支缺

各 prod 分支是獨立 merge 的，某支失敗或當時不在範圍內就會落單。逐支比對：

```bash
cd "$REPO_DIR"
git fetch origin --quiet
for b in tpower-master tpower-new alfawin77-new fon666 vplus33 yes2win vw-master vwb2c wintoto; do
  git merge-base --is-ancestor <TARGET_SHA> "origin/$b" && echo "✅ $b" || echo "❌ $b"
done
```

缺的那幾支重跑對應的 `deploy-prod`（已含的分支再跑是 no-op，安全）。

> 若某支長期落單、內容差很多，那通常是有人**直接在該站台分支上 commit** 而沒回流 `master`。先 `git log --oneline origin/master..origin/<branch>` 看那支獨有什麼，把該回流的補回 `master`，否則下次 deploy 會用 `master` 的舊內容蓋回去。
