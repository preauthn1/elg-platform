---
description: 待辦面盤點——收斂 TODO.md 依「今天能不能動」重分類、掃記憶中懸而未決的 🔴 事項、交叉核對兩套系統的漂移。純唯讀，不改檔。
argument-hint: "（無參數＝全跑；可加 todo 或 memory 只跑其中一段）"
disable-model-invocation: true
---

範圍：$ARGUMENTS（空＝全跑；`todo`＝§1；`memory`＝§2；漂移核對 §3 需兩段都跑）

---

> 使用者的待辦有**兩個居所**：`TODO.md`（文件）與 auto-memory（🔴 標記）。兩套獨立維護、會漂移，本命令把兩邊拉齊。
>
> **強制紀律**：
> - **全程唯讀**。禁止改 `TODO.md`、禁止改記憶檔、禁止 commit。發現要改的地方**回報就好**，由使用者決定。
> - 狀態描述**不可照抄文件**——標「已備」「已合」的，開工前先驗 git／MR 佐證；驗不到就標「未驗證」。

## §1 TODO.md 收斂

讀當前使用者的個人 backlog：`docs/未完成計劃/<git config user.name>/TODO.md`。這是該使用者個人 backlog 的唯一入口，不是全專案共同 backlog，每位工程師各有一份。使用者名稱可能含空白，路徑一律加引號：

```bash
me=$(git config user.name)
[ -n "$me" ] && [ -f "docs/未完成計劃/$me/TODO.md" ] && echo "OK: docs/未完成計劃/$me/TODO.md" || echo "⚠️ 找不到 docs/未完成計劃/$me/TODO.md"
```

取不到 user.name，或對應的目錄不存在時，停下來問使用者。不要猜，也不要改讀別人的目錄。

500+ 行，**不要整份倒出來**。依「今天能不能動」重新分類：

| 類 | 判準 | 輸出 |
|---|---|---|
| **A 可立刻開工** | 設計／計畫已備、前置全合、無外部依賴 | 列全，附設計文件路徑與建議分支名 |
| **B 等使用者決定** | ops 動作或需裁決（開閘、正名、停用、刪檔） | 列全，一句話寫清楚「要決定什麼」 |
| **C 卡外部** | 等三方碼表／CSV／PO 裁決 | 只列標題＋卡在誰身上 |
| **D 大工程** | L 級、需專案排期 | 一行帶過 |

⚠️ **A 類每項開工前先驗證前置真的已合入 master**（`git log --oneline origin/master --grep=...` 或查 MR），別照抄文件的「已備」。§5、§6 是決策紀錄與已完成歸檔，除非要查來龍去脈否則不進今日清單。

## §2 記憶中的懸而未決

TODO.md 看不到的第二處待辦——auto-memory 裡標 🔴 的事項：

```bash
me=$(git config user.name)
[ -n "$me" ] || { echo "⚠️ 取不到 git config user.name，本段整段未驗證"; exit 1; }
root=$(git worktree list --porcelain | awk '/^worktree /{print $2; exit}')
cd "$HOME/.claude/projects/$(printf '%s' "$root" | sed 's#[^A-Za-z0-9]#-#g')/memory/" || { echo "🔴 MEMORY 目錄不存在——本段整段未驗證"; exit 1; }
scan() {
  grep '🔴' MEMORY.md | while IFS= read -r line; do
    title=$(printf '%s' "$line" | grep -oE '^- (\*\*)?[^[]*\[[^]]+\]' | grep -oE '\[[^]]+\]' | head -1 | tr -d '[]')
    printf '%s' "$line" | grep -oE '🔴[^；]*' | while IFS= read -r frag; do
      printf '%s|%s\n' "${title:-（未命名）}" "$frag"
    done
  done
}
echo "=== A. 待你裁決 ==="
scan | grep -E "待 ${me}|待裁決|裁決" | awk -F'|' '{printf "  · %s\n      %s\n", $1, $2}'
echo "=== B. 觀察中／到期要複查 ==="
scan | grep -E '觀察|必複查|切換前|禁停' | grep -vE "待 ${me}|待裁決|裁決" | awk -F'|' '{printf "  · %s\n      %s\n", $1, $2}'
echo "=== C. 已拍板「別動」（供 AI 自律，不列待辦） ==="
scan | grep -E '別催|暫不|別再提|刻意留' | awk -F'|' '{printf "  · %s\n", $1}' | sort -u
echo "=== D. 其餘未分類 🔴（防漏網，逐條人工判讀） ==="
scan | grep -vE "待 ${me}|待裁決|裁決|觀察|必複查|切換前|禁停|別催|暫不|別再提|刻意留" | awk -F'|' '{printf "  · %s\n      %s\n", $1, $2}'
```

判讀：

- **A 組**直接進 §4 的「待你拍板」。
- **B 組**是時效性提醒（「切換前必複查」這種到時候一定會忘的）——**檢查觸發條件今天是否已成立**，成立才提，沒成立只列一行備忘。
- **C 組不列入待辦**，是給 AI 自己看的自律清單（使用者已拍板別催別動的事）。今天不要主動去推那幾項。
- **D 組是防漏網**——A/B/C 的關鍵字分流是白名單，沒命中的 🔴 全落這裡（實例：「副本不去重會雙跑」「cfb 無 on-update 不適用」）。多數是 reference 類技術紅線、不是待辦，但**禁止靜默丟棄**：逐條掃過，真的懸而未決就併入 A/B 一起報。
- ⚠️ `MEMORY.md` 是**啟動快照**，長 session 中可能已過期；引用具體數字、sha、筆數前**先重讀該記憶檔本體**，別引索引裡的摘要。
- ⚠️ 記憶若提到某檔案／命令／flag，**先確認它今天還存在**再拿來建議（例：`bank:standardize` 已刪除）。

## §3 漂移核對

兩套系統會漂移。**實例**：一次性命令清理第 2 批，TODO.md 記「待 RB 裁決」、記憶記「已結案（`bank:standardize` 等四支已刪）」。

對 §1 的 **A 類與 B 類**逐項，去記憶裡找對應條目比對狀態：

- 兩邊一致 → 不提。
- **不一致 → 標出來**，寫清楚「TODO.md 說 X、記憶說 Y」，並指出哪邊有 git／MR 佐證、何者較新。
- **不要自己改任何一邊**，回報就好。使用者要更新會自己說，且改 `TODO.md` 得先開分支（master 禁 commit）。

## §4 輸出

分四段，克制長度：

1. **今天可開工**（A 類，附開工指令：從最新 origin/master 開什麼分支）
2. **待你拍板**（TODO.md B 類 ＋ 記憶 A 組，合併去重，讓使用者一次決掉）
3. **時效性提醒**（記憶 B 組中觸發條件已成立的）
4. **發現的漂移**（§3，順手修文件的機會）

卡外部與大工程各一行帶過即可，不展開。
