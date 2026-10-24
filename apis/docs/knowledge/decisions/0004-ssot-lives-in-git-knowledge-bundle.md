---
type: Decision
id: decisions/0004-ssot-lives-in-git-knowledge-bundle
title: SSOT 正本放 git 的知識庫
description: 系統行為的 SSOT 正本放進 git 的 docs/knowledge/，格式採 Open Knowledge Format，Outline 只留客服用的操作手冊。
domain: 系統
tags: [知識庫, SSOT, OKF, docs/knowledge, Outline, 系統, 文件治理]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
evidence:
  - { kind: code, ref: /docs/knowledge/, title: "本決定治理的知識庫 bundle 本體", at: 2026-10-21 }
decided_at: 2026-10-16
decision_status: accepted
---

# 系統行為的 SSOT 正本放 git 的知識庫，Outline 只給客服

功能說明頁（含 SSOT 表與驗收表）原本寫在 Outline，feature test 用 `#[Group('<功能短編號> SSOT-n')]` 綁回那一頁。這個做法有三個問題：

- Outline 頁面誰都能直接改，沒有審查關卡。
- 規則改了，測試不會知道。
- Outline 同時放客服的操作手冊，兩種讀者的內容混在一起。

2026-10 決定：

- 系統行為的 SSOT 正本搬進 repo 的 `docs/knowledge/`，以 Google Open Knowledge Format（OKF，Markdown 加 YAML frontmatter，一檔一概念）書寫，功能頁檔名沿用功能短編號。
- 每次變更走 MR，PM 在 GitLab 上審。
- Outline 只留客服看的操作手冊與客服詞彙。引用系統規則時，附知識庫的 concept id，不重寫規則。

## 考慮過的選項

- **Outline 維持正本**：PM 最熟悉，但沒有審查關卡，改了不留 diff。SSOT 與 feature test 的對應，只能靠人記得回頭補。
- **git 與 Outline 並行、人工同步**：兩個地方都能改，等於兩個事實來源，內容一定會逐漸不一致。
- **整個 `docs/` 改造成 OKF bundle**：一次全部改完，但要碰幾百個舊檔，多數舊文件的型別與狀態其實不確定。最後的做法是另開 `docs/knowledge/` 當 bundle，舊 `docs/` 原地不動，在 `sources` 引用。

## 後果

- SSOT 的修改與審查全部在 MR 上，PM 看 GitLab 渲染的 Markdown、用 MR 留言回饋。
- feature test 的 Group 標籤一字不改：功能短編號就是檔名，SSOT 編號仍在頁內連號。
- Outline 既有的功能頁：客服用得到的改寫成客服版；純技術頁刪除，魔鏡（團隊共享記憶服務）的 pointer 改指 git。
- `wiki-authoring`、`gitlab-release` 兩支 skill 要改為把 SSOT 寫進知識庫，不再寫 Outline。
- 審查、回答回歸測試、發布關卡不在 OKF 規格內，要另外設計。

## 修訂 2026-10-21

2026-10-20 曾試過「檔名帶中文」「一頁對一個入口」兩版，當天撤回。

本次定案：

- bundle 分五區：`architecture/`、`apis/`、`rules/`、`data-model/`、`decisions/`。
- `rules/` 以業務主題為單位，一份文件可收多條規則，不再一個功能一頁。
- `apis/` 與 `data-model/` 的骨架由 `php artisan knowledge:scaffold` 產生。機器產生的區塊與人寫的區塊分開，重產只覆蓋機器產生的區塊。
- 程式類別的 docblock 加 `@knowledge <Rule 文件 ID>`，指回規則文件。
- feature test 加 `#[Group('<Rule 文件 ID>#R-n')]`，綁定它證明的那一條規則。
- `docs/knowledge/features/` 既有三頁，以及先前自 Outline 遷入的舊功能頁，改寫成 `rules/` 底下的規則文件，`features/` 目錄隨後刪除。

同日稍後再修訂一處：上面「骨架由 `php artisan knowledge:scaffold` 產生」已不成立。這支命令與 `knowledge:lint` 的測試在 CI 平行測試環境連不到資料庫，先拔掉測試後，兩支工具改寫成 Python，移出 repo，放在維護者本機的個人 skill `knowledge-tools`，之後再決定要不要放上 marketplace。骨架改由這支工具產生，規則與格式都沒變。
