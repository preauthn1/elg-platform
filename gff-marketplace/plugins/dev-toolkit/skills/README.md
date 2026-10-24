# dev-toolkit skills

## 已收錄

- [`git-commit`](git-commit/SKILL.md)：Git commit message 規範（繁中 conventional commit、type 清單、amend SOP）
- [`release-workflow`](release-workflow/SKILL.md)：開發到正式的完整生命週期協議（worktree 迭代、develop CI gate、MR 發布、清理）
- [`branch-sync`](branch-sync/SKILL.md)：把本地分支 fast-forward 對齊遠端最新（純快轉；分岔 / 有本地提交 / 被 worktree 佔用一律跳過並回報）
- [`gitlab-release`](gitlab-release/SKILL.md)：GitLab Release 發布協議（打 tag、彙整本批 MR 清單、標部署風險；隔天放假預設不發，以 gitlab-mcp 的 check_workdays 查政府辦公日曆；接在 release-workflow 之後）
- [`harness-audit`](harness-audit/SKILL.md)：稽核精簡 Claude Code harness 設定（唯讀，只出 dedup 計畫）
- [`memory-tidy`](memory-tidy/SKILL.md)：整理本地 Claude auto-memory（MEMORY.md 索引壓縮、淘汰外移、考古分流；附 check.py 驗證器）
- [`design-grilling`](design-grilling/SKILL.md)：動手前的逼問式設計訪談（決策樹分輪提問、每題附推薦答案；共識達成才進計劃模式）
- [`domain-modeling`](domain-modeling/SKILL.md)：維護專案詞彙表與架構決策紀錄（`GLOSSARY.md` 定名、ADR 三條件判準）
- [`tdd`](tdd/SKILL.md)：測試先行的紅綠迴圈紀律（範例為 PHP / PHPUnit；不自動觸發，由 design-grilling 或 bug-diagnosis 叫起，或手動 `/dev-toolkit:tdd`）
- [`bug-diagnosis`](bug-diagnosis/SKILL.md)：通用 bug 排查紀律（開工先問要不要測試先行；重現、列假設、一次動一個變數、修完補回歸測試；領域已有專用排查 skill 時走那支）
- [`plain-writing`](plain-writing/SKILL.md)：白話寫作規則（判斷讀者、四條判準、中立客觀、句子完整、列點、送出前檢查；由 tech-doc-writing 與 mr-report-writing 叫起）
- [`tech-doc-writing`](tech-doc-writing/SKILL.md)：技術文件的結構規則（先判讀者與文件類型，再套流程手冊、機制說明、決策紀錄等結構；叫起 plain-writing）
- [`mr-report-writing`](mr-report-writing/SKILL.md)：MR description 固定九節的 MR 報告寫法（附 `scripts/check-mr-report.php` 驗證器，寫完先跑、綠了才開 MR；叫起 plain-writing）

## 走這套通用上線流程的 repo

`release-workflow`（工作分支 → develop CI gate → MR → 人工合併 → 清理）＋ `gitlab-release`（打 `release_YYYYMMDDHHmm` tag）為**通用**協議，各 repo 差異一律以兩支 skill 的「repo 參數表」代入，**不為單一 repo 另開 skill**。目前已收錄參數的 repo：

| repo | GitLab project | 發布分支 | develop gate | release tag |
|---|---|---|---|---|
| APIs | `target/gffv3/apis` | `master` | 有 | 有 |
| agent-backend | `target/gffv3/agent-backend` | `main` | 有（兼 dev 環境部署來源） | 有（tag 觸發 prod 部署 pipeline） |
| GFF-Marketplace | `target/gffv3/gff-marketplace` | `main` | 無 | 無 |

⚠️ `agent-backend` **不是** `WEB_AgentBackend`。`WEB_AgentBackend`、`API_Agent`、`WEB_CustomerServiceSocket` 走的是另一套多站台 merge/push 上版（無 MR、無 release tag），見 `legend-toolkit` 的 `deploy-agent-stack`。
