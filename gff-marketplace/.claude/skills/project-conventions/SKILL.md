---
name: project-conventions
description: Use when working in GFF-Marketplace on skill/plugin changes, marketplace manifests, reference docs, helper scripts, validate.sh, skill placement, naming, descriptions, runtime friction, Superpowers scope, or reducing over-designed skill workflows.
---

# GFF-Marketplace 倉庫慣例

本 repo 是 Claude Code **plugin 市集**，產出物是 skill 與 plugin manifest。

> **規則 SSOT = `CONTRIBUTING.md`（repo 根）。** 本 skill **不複述規則**，只提供兩樣 CONTRIBUTING 沒有的東西：下方「Runtime friction 優先」「Superpowers 使用邊界」「寫給 agent 讀的文件」三節的 AI 判斷準則，以及下方「動作 → 章節」索引。動手前先依索引讀 CONTRIBUTING 對應章節；任何規則細節以 CONTRIBUTING 為準。

## 0. Runtime friction 優先

Skill 是每次對話都會載入與執行的 runtime 文件，不是只供 review 的設計稿。新增任何規則、reference、helper、preflight、resolver、template mode 或 validate block 前，先檢查：

- 這是否是每次使用都必須知道或執行的資訊？
- 是否會增加讀檔、工具呼叫、等待時間、模型判斷負擔？
- 這個成本是否低於它避免的真實風險？
- 是否可以移到 MR checklist、docs、CI、或高風險操作專用流程？
- 使用者只是要查一筆資料時，這套流程是否顯得過重？

若成本大於收益，優先刪除、合併、移到 docs，或只保留成高風險操作前的檢查。日常查詢與一般使用應輕量；高風險寫入才需更嚴格流程。

## 0-1. Superpowers 使用邊界

本 repo 不把 Superpowers brainstorming / writing-plans 當成所有修改的預設流程。**完整流程**只用於：新增或重構 plugin 架構、改 marketplace / onboarding / team install flow、跨多 skill 的行為政策變更、高風險資料操作政策設計。

**輕量流程**（只說明目的、決策、影響範圍、驗證方式，不產厚重 design doc / plan）即可用於：description 精簡、reference 移除/搬移、skill 觸發邊界收斂、validate.sh 小型整理、刪除過度設計 helper。

## 0-2. 寫給 agent 讀的文件

這一節適用於寫或改 skill 正文、description、CLAUDE.md 這類 agent 會讀的文件。這裡是 AI 判斷準則，不是規則，規則仍以 CONTRIBUTING 為準。

1. **觸發詞的措辭決定命中率。** description，以及 CLAUDE.md 裡「什麼情況去讀哪個檔」的那一行，都是 context pointer：常駐在 context 裡、告訴 agent 何時去讀哪份材料的指標。材料很重要卻常被漏掉時，先改措辭，不要急著把材料整段搬進常駐區。每個分支只留一個觸發說法，同義重複的刪掉，只留真正不同的情境。
2. **每句話問一次「拿掉會不會不一樣」。** agent 預設就會做的事，寫出來只花 token、不改行為，這種句子整句刪，不要只修字。兩個人對「預設會不會做」有歧見時，用實際跑的結果判斷，不辯論。
3. **寫正面目標，不寫禁止句。** 「不要想大象」反而讓大象更顯眼，禁止句會把被禁的行為帶進 context。改寫成要做的事，例如寫「註解寫一行」，而不是「不要寫長註解」。真的要硬擋才用禁止句，而且旁邊要擺正面目標；本 repo 現有 skill 的 🚫 排除句是為了把說法分流到別支 skill，屬於硬擋那一類，可以留。
4. **用現成的詞當錨點。** 模型本來就認得的詞（紅／綠、曳光彈、前緣、縫）比一段描述便宜，效果也更穩。同一個概念在三處各寫一遍時，收斂成一個詞反覆用。自創的詞要先定義，否則沒有這個好處。

以上改編自 https://example.invalid/mattpocock/skills 的 writing-for-agents（MIT 授權）。

## 索引：動作 → 讀哪一節

動手前先過對應章節（規則全文在 `CONTRIBUTING.md`）：

| 你要做的事 | 讀 CONTRIBUTING |
|---|---|
| 命名 skill 目錄 | 「Skill 命名規範」 |
| 寫 / 改 `description` | 「Description 觸發詞清單」 |
| 新增 skill（最低門檻、要不要 reference） | 「新增 skill 的最低門檻」、「Reference 子文件」 |
| 決定 skill 放哪個 plugin、要不要新增 plugin | 「Plugin 切分原則」 |
| 宣告 GitLab / GCP MCP 依賴、pin MCP 套件版本 | 「Plugin 切分原則」 |
| apis-toolkit 涉及白標識別 / 查詢範疇 | 「白標事實 SSOT（apis-toolkit）」 |
| 寫 / 拆 `reference/*.md`（該不該拆） | 「Reference 子文件」 |
| 放 markdown 文件（runtime / 人讀 / 入口） | 「Markdown 文件位置」 |
| skill 引用 memory / 確保跨用戶自足 | 「Skill 內容須跨用戶自足（禁引用作者本地 memory）」 |
| 寫或改 skill 正文、reference 讀者文案、commit message、MR description 的措辭（白話四判準） | 「白話寫作規範」 |
| helper / validate.sh 範疇、高風險寫入安全邊界 | 「Helper 與高風險寫入安全邊界」 |
| 本機 hooks 用途（Claude hooks / git pre-commit）、為何 pre-commit 要保留 | 「本機自動把關（hooks）」節 |
| 祕密、`.DS_Store`、wrapper 禁區 | 「不要碰的東西」 |
| 寫 commit message | 「Commit message」（與 `git-commit` skill） |
| 開 MR / breaking change / hotfix / reviewer | 「變更流程」、「Plugin 修改流程」、「Hotfix 流程」 |
| commit 前自驗、CI、`claude plugin validate` | 「CI / 自動驗證」節 |

完整 do/don't 範例與表格也在 `CONTRIBUTING.md` 內（已不另存 reference）。
