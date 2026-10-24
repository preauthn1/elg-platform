
## 📋 Git Commit 訊息規範上線通知

大家好，我們已在專案中導入 **Commit Message 自動化驗證機制**，請各位完成以下一次性設定，避免提交或 MR 被擋下。

---

### 💡 為什麼要遵守這份規範？

統一的 commit 格式不只是形式要求，對團隊日常協作有以下實質好處：

**1. 快速理解變更意圖**
每筆 commit 的 `type` 讓人一眼看出這是新功能、Bug 修正還是重構，不需要打開 diff 才能判斷影響範圍。Code Review 時可以優先聚焦在 `feat` / `fix`，略過 `style` / `chore`，大幅節省審查時間。

**2. 自動化 CHANGELOG 與版本追蹤**
格式一致的 commit 可以直接被工具（如 `git log --oneline`、release note 產生器）解析，未來若需要自動產生版本記錄或追蹤某個功能的所有相關改動，不需要人工整理。

**3. 快速定位問題根源**
當線上出現 Bug，可以用 `git log --grep="fix"` 或按 scope 篩選（如 `fix(wallet)`），在幾秒內縮小排查範圍，而不是逐行讀幾十筆 `update` / `fix bug`。

**4. Revert 與 cherry-pick 更安全**
明確的 subject 讓 `git revert` 和 `git cherry-pick` 的操作對象一目了然，降低誤操作的風險，尤其在多環境部署（dev / staging / prod）時特別重要。

**5. 減少 Code Review 來回溝通**
Reviewer 不需要額外詢問「這個 commit 改了什麼」，訊息本身就是說明。結合 MR 描述，可以讓審查流程更順暢、合併更有信心。

**6. 本地即時攔截，不浪費 CI 資源**
透過本地 Hook，格式錯誤在 `git commit` 當下就被擋下並提示修正方式，不需要等 Pipeline 跑完才發現問題，節省等待時間也節省 Runner 資源。

---

---

### 🔧 第一步：安裝本地 Hook（每人執行一次）

在專案根目錄執行以下指令：

```bash
ln -sf ../../.githooks/pre-commit.sh .git/hooks/pre-commit
ln -sf ../../.githooks/commit-msg.sh .git/hooks/commit-msg
```

安裝後，每次 `git commit` 時會自動檢查格式，不符合規範直接擋下並提示原因。用 symlink 安裝，之後 `.githooks/` 的規則有更新會自動生效，不必重裝。以前用 `cp` 複製安裝的人，重跑上面兩行就會換成 symlink。

---

### 📝 第二步：遵守 Commit 格式

**格式**：`<type>[(可選 scope)]: <描述>`

```
feat: 新增 TngPin 代收金流商
fix(wallet): 修正提款回調金額精度計算錯誤
ci: 更新 pipeline 快取策略
```

**有效的 type：**

| Type | 用途 |
|---|---|
| `feat` | 新增功能 |
| `fix` | 修復 Bug |
| `refactor` | 重構（不影響行為） |
| `test` | 新增或修改測試 |
| `chore` | 雜項維護（依賴更新、設定調整） |
| `docs` | 文件變更 |
| `perf` | 改善效能 |
| `revert` | 回滾先前的 commit |
| `style` | 調整程式碼風格（Pint 格式化） |
| `ci` | CI/CD 相關設定變更 |
| `ai` | AI 輔助生成或修改的代碼 |
| `skill` | 新增或修改 AI skill / command 設定（`.claude/skills/`、`.claude/commands/`） |

**其他規則：**
- 標題總長 ≤ **130 字元**（以字元數計，一個中文字算 1）
- 描述至少 **4 字**
- 語言使用**繁體中文**（英文技術名詞可保留英文）
- **`feat` 類型必須附工單連結**，放標題或內文都可以。接受三種寫法：
  - 短參照：`target/tickets#4300`（也接受 `target/ticket_tasks#N`、`target/op_tickets#N`）
  - `Refs: <GitLab 網址>`
  - 獨立一行的 GitLab 網址

  例：`feat(payment): 新增重送 callback 命令 target/tickets#3668`
- 描述不能全部由泛用詞組成，例如 `fix bug`、`update`、`修改`、`wip` 會被擋；寫成 `修正登入 token 過期問題` 這種帶具體對象的描述就過。這條只在 CI 檢查，本地 hook 不擋

---

### 🚀 CI/CD 自動驗證

送出 MR 後，GitLab Pipeline 會自動對該 MR 內所有 commit 重新驗證，**Pipeline 未通過則無法合併**。工單連結規則在 CI 只檢查 MR 最新的那一筆 commit；本地 hook 則每一筆 `feat` commit 都檢查。

---

### 🛠 Commit 寫錯了怎麼辦？

**修改最新一筆：**
```bash
git commit --amend -m "feat: 正確的描述"
```

**修改較早的 commit：**
```bash
git rebase -i HEAD~3
# 將目標 commit 前的 pick 改為 reword，儲存後修改訊息
```

修改後若已推遠端，需補上 `--force-with-lease`：
```bash
git push --force-with-lease
```

---

### ✅ 自動略過的情境（不需符合格式）

- `Merge branch '...'` — git 自動產生的合併 commit
- `Revert "..."` — `git revert` 自動產生的還原 commit
- `fixup! ...` / `squash! ...` — interactive rebase 用
- `Reapply "..."` — 還原一筆 revert 時 git 自動產生的訊息

---

完整規範文件請參閱 `dev-toolkit` plugin 的 `git-commit` skill（GFF-Marketplace repo `plugins/dev-toolkit/skills/git-commit/SKILL.md`）；裝好 plugin 後說「幫我寫 commit message」會自動載入。
有任何問題歡迎提出 👋
