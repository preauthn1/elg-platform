# CLAUDE.md

## 專案定位

target 內部 **Claude Code plugin 市集**：產出物是 plugin manifest 與 skill 文件，無應用程式碼。**規則 SSOT = `CONTRIBUTING.md`**（衝突以它為準）；動手前的「動作 → 章節」索引與 AI 判斷準則見 `project-conventions` skill（`.claude/skills/`）。本檔只放定位、命令、結構與 Claude 專屬操作，**不複述 CONTRIBUTING 規則**。

## 常用命令

```bash
bash scripts/validate.sh      # 結構/祕密/MCP pin 全套驗證（commit 前必跑，需 php）
claude plugin validate .      # 官方驗證用一般模式，勿加 --strict（commit-SHA 版本制）
```

> skill 改動合進 main 後要在本機生效，照 `CONTRIBUTING.md`「發布與版本機制」的「使用者取得新版」做。

## 結構

- `plugins/<plugin>/skills/<name>/SKILL.md`（`reference/*.md` 為選擇性，見 CONTRIBUTING「新增 skill 的最低門檻」）：plugin skill
- `.claude-plugin/marketplace.json`、`plugins/<plugin>/.claude-plugin/plugin.json`、`plugins/<plugin>/.mcp.json`：manifest（改動屬 breaking，見 CONTRIBUTING「Plugin 修改流程」）
- `.claude/skills/project-conventions/`：本 repo 慣例（專案層級 skill，不在 plugin 內）
- `evals/`：skill 觸發詞測試；改 description 前先照 `evals/README.md` 跑

## Git 工作流（Claude 專屬）

- **AI 不合併**：AI 在工作分支 commit、開 MR（target `main`），合併一律由使用者本人按；AI 不代按，也不替 MR 設定自動合併。`main` 已設為沒有人能直接 push。
- 核准、分支、commit、push、MR、breaking 規則見 `CONTRIBUTING.md`「變更流程」「合併前核准」「Plugin 修改流程」；生命週期（切分支先 stash、新需求 gate、清理）依 `release-workflow` skill。

## Skill 速查表重發（Claude 專屬）

- 原稿 `docs/skill-cheatsheet.html`；改到原稿的 MR 裡，頁尾「依各 skill 的設定整理（YYYY-MM-DD）」同步改成這次核對的日期。
- 動到原稿的 MR 合併、清理完之後，由 artifact 擁有者（RB）用自己本機的 skill 重發；其他人的 session 只改原稿，重發交給 RB。

## 環境（本機自動把關）

- 三層把關的用途與分工見 `CONTRIBUTING.md`「本機自動把關（hooks）」。
- 用 Bash 寫入的檔案（`sed -i`、heredoc）不經過寫入前的密鑰攔截，只靠 CI 掃描。
- git `pre-commit` 要每位開發者手動安裝一次，見 `CONTRIBUTING.md`「開發環境」；沒裝的 clone 只剩另外兩層。
