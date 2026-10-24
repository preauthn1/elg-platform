---
description: 代碼面盤點——git 分支同步（含所有本地分支落後／失聯）、worktree 健康度、未登記的 APIs-* 殘存目錄、可清理分支候選、GitLab open MR 與待我 review。唯讀盤點（僅 fetch 更新本機 remote refs），不刪分支、不刪目錄、不 push、不 commit。
argument-hint: "（無參數＝全跑；可加 branches 或 mr 只跑其中一段）"
disable-model-invocation: true
---

範圍：$ARGUMENTS（空＝全跑；`branches`＝§1–§2；`mr`＝§3）

---

> 回答同一個問題：**我的變更現在在哪、卡在哪、有什麼該收掉。**
>
> **強制紀律**：
> - **唯讀盤點**。唯一的狀態變更＝`git fetch --prune` 更新本機 remote-tracking refs 與 `FETCH_HEAD`（不動 working tree、不動本地分支、不碰遠端。嚴格說這不是零寫入，是本命令刻意保留的唯一例外，沒有它整份盤點就是舊資料）。除此之外禁止任何寫入：**禁止** `push --delete`、禁止 commit。要刪分支由使用者另外發起。
> - 每個結論**附工具原始返回**；取不到就明說「未驗證」，禁止填推測的 sha／日期／筆數。
> - 判定逐輪重跑，不沿用上一輪清單（狀態每天都在 drift）。

## §1 同步狀態

在 host 執行（容器無 credential）：

```bash
git fetch --all --prune || echo "⚠️ FETCH FAILED"
git status --short
git rev-list --left-right --count master...origin/master
```

🔴 **fetch 失敗就整段降級**：出現 `FETCH FAILED` 時，所有 `origin/*` 都是舊 refs——同步結論與 §2 清理候選一律標「未驗證（stale refs）」，不得當今日狀態回報，更不得據此建議刪分支。

回報：**工作區是否乾淨**（有未提交變更就標出來——開工要切分支得先 stash）、**master 是否與 origin/master 同步**（`0	0` 才算）。

### 1.1 worktree 健康度

```bash
git worktree list --porcelain | awk '/^worktree /{print $2}' | while read -r wt; do
  br=$(git -C "$wt" branch --show-current 2>/dev/null) || br="ERR"
  if st=$(git -C "$wt" status --porcelain 2>/dev/null); then
    dirty=$(printf '%s' "$st" | grep -c .)
  else
    dirty="ERR"
  fi
  up=$(git -C "$wt" rev-parse --abbrev-ref --symbolic-full-name '@{upstream}' 2>/dev/null)
  if [ -n "$up" ]; then ahead=$(git -C "$wt" rev-list --count "$up..HEAD" 2>/dev/null || echo "ERR"); else ahead="無upstream"; fi
  last=$(git -C "$wt" log -1 --format=%cd --date=format:'%Y-%m-%d' 2>/dev/null || echo "ERR")
  printf "%-46s %-38s dirty=%-4s ahead=%-10s last=%s\n" "$(basename "$wt")" "${br:-ERR}" "$dirty" "$ahead" "$last"
done
```

判讀規則：

- `APIs-payment-bank-convergence` 與 `APIs-multi-currency-wallet` 是**長期常駐、不刪**——只回報狀態，永遠不要建議清理。
- 🔴 **任何欄位是 `ERR`＝該 worktree 讀取失敗**（不存在、無權限、metadata 壞掉），**列為異常回報，禁止默認成乾淨**——git 錯誤導向 `/dev/null` 後 `wc -l` 照樣輸出 0，壞掉的 worktree 會偽裝成 `dirty=0`，所以腳本用 if 分流而不是接管道。
- **`dirty` 非 0＝有未提交變更**，點名是哪個 worktree。長期 worktree 帶著未提交變更過夜有損失風險（IDE 緩衝區覆寫踩雷）。
- **`ahead` 用 `rev-list` 精算，不要引用 `git branch -vv` 的數字**——多 worktree 下該數字可能是舊的。
- `ahead` 比記憶中的數字大，代表**另一個 session 正在該 worktree 工作**；提醒使用者，避免兩個 session 互踩。

### 1.2 develop 一致性

```bash
echo "develop 領先 master: $(git rev-list --count origin/master..origin/develop)"
echo "master 領先 develop: $(git rev-list --count origin/develop..origin/master)"
```

⚠️ `master 領先 develop` 非零**不等於 develop 缺東西**——squash-MR 會斷血統，血統層數字天生失真。判定 develop ⊇ master **一律看內容層**；沒實查就標「未驗證」，不要憑這個數字報警。

### 1.3 本地分支落後／領先／失聯

§1 只比 master；其他設了 upstream 的本地分支（develop、fix/*、skill/*）落後遠端時看不到（實例：develop 落後 16 個 commit 沒被 §1 抓到）。一條命令列全部：

```bash
git for-each-ref --format='%(refname:short)|%(objectname:short)|%(upstream:short)|%(upstream:track)' refs/heads
```

`upstream:track` 欄的判讀：

| 值 | 意義 | 回報 |
|---|---|---|
| `[behind N]` | 純落後 | 列出；要快轉走 `branch-sync` skill，本命令唯讀不代跑 |
| `[ahead N]` | 有未推送的本地提交 | 列出；長期 worktree 的分支領先屬正常，別催 |
| `[ahead N, behind M]` | 已分岔 | 列出並標「需人工」 |
| `[gone]` | upstream 遠端分支已被刪除 | 進 §2.4 分流 |
| 空 | 已同步 | 不列 |
| upstream 欄整個為空 | 沒設 upstream | 一行帶過總數 |

⚠️ 這條命令比的是本機 remote-tracking refs；§1 的 fetch 失敗時同樣整段標「未驗證」。

### 1.4 未登記的 `APIs-*` 目錄

§1.1 只走 `git worktree list`，**沒登記的目錄完全不可見**——worktree 被移除後 PhpStorm 留下的 `.idea` 空殼、手工建的資料目錄，都會靜靜堆在主 checkout 的上一層目錄（實際抓過 4 個空殼與 2 個資料目錄，其中一個 65MB 是生產會員與代理快照）。

```bash
reg=$(git worktree list --porcelain | awk '/^worktree /{print $2}')
root=$(printf '%s\n' "$reg" | head -1)   # 第一列固定是主 checkout，worktree 都建在它旁邊
parent=$(dirname "$root")
[ -d "$parent" ] || { echo "⚠️ 找不到 $parent——本段未驗證"; exit 1; }
find "$parent" -maxdepth 1 -type d -name 'APIs-*' | sort | while read -r d; do
  printf '%s\n' "$reg" | grep -qx "$d" && continue
  n=$(ls -A "$d" | wc -l | tr -d ' ')
  if [ -e "$d/.git" ]; then kind="git但未登記"
  elif [ "$n" -eq 1 ] && [ -d "$d/.idea" ]; then kind="空殼(只剩.idea)"
  else kind="非git資料目錄"; fi
  printf "%-46s %-18s %6s  %s\n" "$(basename "$d")" "$kind" "$(du -sh "$d" | cut -f1)" "$(stat -f '%Sm' -t '%Y-%m-%d' "$d")"
done
```

判讀規則：

- **空殼（只剩 `.idea`）**：worktree 已清、IDE 設定沒跟著走，零價值，列為清理候選。
- **非 git 資料目錄**：列大小與最後異動，**不要進去讀內容**。這類目錄常是驗收 harness 的生產快照（ndjson／csv），含會員與代理資料；久放本機是外洩面，提醒使用者決定清或搬。
- **git 但未登記**：`.git` 存在卻不在 worktree 清單，代表 metadata 壞掉或手動 clone，標「需人工」，不猜。
- 已登記的長期 worktree 不會進這段（它們在 §1.1）。
- `find -name` 的 pattern **必須加引號**：本機 shell 是 zsh，未加引號的 glob 在 cwd 找不到對應檔會讓整條命令中止。

只回報不刪。刪空殼、搬資料由使用者發起。

## §2 分支清理候選

### 2.1 已合併 origin/master（30 天判準）

🔴 **只輸出已到期的**。未到期的分支**一律不逐支列出、不預告到期日**。未到期的只用一行講總數，因為「有幾支在排隊」是規模資訊，不是待辦提醒。

```bash
now=$(date +%s)
rows=""; pending=0
for b in $(git for-each-ref --format='%(refname:short)' refs/remotes/origin \
    | grep -v -E '^origin$|^origin/HEAD$|^origin/master$|^origin/develop$'); do
  if git merge-base --is-ancestor "$b" origin/master 2>/dev/null; then
    ts=$(git log -1 --format=%ct "$b"); d=$(( (now - ts) / 86400 ))
    if [ "$d" -ge 30 ]; then
      dt=$(git log -1 --format=%cd --date=format:'%Y-%m-%d' "$b")
      rows="${rows}$(printf '%-52s %-12s %s天' "${b#origin/}" "$dt" "$d")
"
    else
      pending=$((pending + 1))
    fi
  fi
done
if [ -n "$rows" ]; then printf '%s' "$rows" | sort -k2; else echo "🟢 無到期分支"; fi
echo "（另有 ${pending} 支已合併但未滿 30 天，刻意不列）"
```

慣例＝**已併入 `origin/master` 且最後 commit 距今 ≥ 30 天**才列可刪（一個月緩衝，確保作者本地工作已落地）。其他地方寫的天數若不同，以本條為準。

⚠️ 用 commit 日算天數只是**粗篩**；真要刪之前，到期時刻**用該分支的 merge commit 精算**，別信估算日。

⚠️ 腳本刻意不寫成 `for … done | sort`：那樣整個迴圈會落進 subshell，`pending` 計數在迴圈外歸零。改為先把到期列累積進 `rows`、迴圈結束才排序輸出。

### 2.2 未合併 origin/master（需分流）

```bash
now=$(date +%s)
for b in $(git for-each-ref --format='%(refname:short)' refs/remotes/origin \
    | grep -v -E '^origin$|^origin/HEAD$|^origin/master$|^origin/develop$'); do
  if ! git merge-base --is-ancestor "$b" origin/master 2>/dev/null; then
    ts=$(git log -1 --format=%ct "$b"); d=$(( (now - ts) / 86400 ))
    dt=$(git log -1 --format=%cd --date=format:'%Y-%m-%d' "$b")
    indev=$(git merge-base --is-ancestor "$b" origin/develop 2>/dev/null && echo "在develop內" || echo "-")
    [ "$d" -ge 30 ] && age="🔶 已滿30天→需人工核准" || age="⚪ 緩衝期內"
    printf "%-52s %-12s %-5s %-12s %s\n" "${b#origin/}" "$dt" "${d}天" "$indev" "$age"
  fi
done | sort -k2
```

🔴 **到期但未合併 `origin/master` 的分支，必須在報告中單獨註明，且一律需人工核准才能刪除**。這類分支**永遠不進「可刪」清單**——即使滿了 30 天也一樣：

- 未合併意味著它的內容可能只存在於這個 ref 上，刪掉就是真的沒了（已合併的分支刪 ref 不損失 commit，這類不然）。
- 到期只代表「該去查清楚它是什麼」，不代表可刪。逐支查完 MR state 與內容歸屬後，**把結論與風險寫給使用者，由使用者逐支核准**，AI 不得代為判定可刪。
- §2.1 的「未到期不提醒」規則**不適用於這一段**：這裡不論到期與否都要列出，因為它們需要的是分流判斷，不是清理排程。

⚠️ **squash-merge 下「未合併」清單不可靠**（squash 生新 commit、原分支 commit 不可達）。分流：

- **在 develop 內** → 內容零損失。⚠️ **分支名不可當內容依據**，只認 `merge-base --is-ancestor`。
- **不在 develop 內** → 查 GitLab MR state：squash-merged 可刪／MR open **別碰**／closed 未合或無 MR（他人 WIP 或已棄）**別碰**。用 `gitlab` MCP 查，不要 curl。
- 上述任一結論導向「可刪」時，仍受本節開頭的人工核准規則約束。

### 2.3 輸出

分三塊輸出：

1. **§2.1 已合併且已到期的**（列成表格），未到期的照 §2.1 規則只給一行總數。**零到期就一行「無到期分支」帶過**，不要為了填版面把排隊中的分支攤出來。
2. **§2.2 未合併的**（不論到期與否都列），其中**滿 30 天的要單獨點名並標「需人工核准」**。
3. **§2.4 本地 `[gone]` 分支**（有才列，零筆一行帶過）。

然後停住，**不要刪**，也不要問要不要刪；使用者要刪會自己說。

### 2.4 本地 `[gone]` 分支

§2.1／§2.2 只掃 `refs/remotes/origin`。MR 合併時勾了 `should_remove_source_branch`，遠端分支當場被 GitLab 刪掉，`fetch --prune` 再把本機 remote-tracking ref 拿掉——**本地分支反而從 §2 的清單裡消失**，只剩 §1.3 的 `[gone]` 看得到。這段補上分流：

```bash
git for-each-ref --format='%(refname:short)|%(upstream:short)|%(upstream:track)' refs/heads | awk -F'|' '$3=="[gone]"' | while IFS='|' read -r b up tr; do
  inm=$(git merge-base --is-ancestor "$b" origin/master 2>/dev/null && echo "已在origin/master" || echo "不在master")
  wt=$(git worktree list --porcelain | awk -v b="refs/heads/$b" '/^worktree /{w=$2} $0=="branch "b{print w}')
  printf "%-52s %-18s worktree=%s\n" "$b" "$inm" "${wt:--}"
done
```

判定要**兩個證據齊全**才標「可清理」，缺一就標「需人工」：

1. `merge-base --is-ancestor origin/master` 為真——commit 層證明內容已在 master。
2. `gitlab` MCP `list_merge_requests` 以 `source_branch` 查該分支、`state=all`，拿到 `state=merged`——流程層證明是經 MR 合進去的，不是別人 push 後又刪。⚠️ 回應帶完整 description，只取 `iid`、`state`、`merged_at`、`merge_commit_sha`。

- `[gone]` 但**不在 origin/master**：內容可能只剩這個 ref，永遠不進可清理清單，照 §2.2 的規則交人工。
- 被 worktree 佔用的，回報中帶上 worktree 路徑：清理時要先驗該 worktree `status --porcelain -uno` 為空、`stash list` 為空，再 `worktree remove`，最後才 `branch -d`。長期 worktree 的分支不會出現在這裡（它們的遠端分支還在）。
- `branch -d`（小寫）本身就是第三道保險：未合併會拒絕，**不得改用 `-D`**。

只回報不刪，清理走 `release-workflow`。

## §3 GitLab MR

因為 AI 永不代合併 MR，**開著的 MR 必然堆著等使用者手動處理**。這段就是提醒使用者還有哪些 MR 沒處理。

用 `gitlab` MCP（先用 `whoami` 取得目前使用者的 username）：

- `list_merge_requests`：`scope=created_by_me`、`state=opened` —— 我開的、還沒合的
- `list_merge_requests`：`reviewer_username=<whoami 取得的 username>`、`state=opened` —— 等我 review 的

⚠️ **回應每筆帶完整 description，全倒出來會洗版**。**只取**：`iid`、`title`、`target_branch`、開了幾天（`created_at`）、`detailed_merge_status`、`blocking_discussions_resolved`。有 open MR 才進一步查該分支最新 pipeline 狀態（pipeline 輪詢一律走 `gitlab` MCP，`curl` 被 deny）。

兩邊都 0 筆就一行帶過「無待處理 MR」，不要為了填版面展開。

## §4 收尾

用幾行總結：工作區狀態、本地分支幾支落後或失聯、殘存目錄幾個、今天到期幾支分支、MR 卡幾件。要動手走 `release-workflow` 選分支模式，別在 master 上改檔；要快轉本地分支走 `branch-sync`。
