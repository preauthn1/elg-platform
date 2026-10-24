---
paths:
  - "docs/**/*.md"
---

# 技術文件寫作規則（APIs 專屬）

`docs/` 底下的文件給工程師、營運與 agent 讀。寫法分三層，本檔只管最後一層：

- **用字**：用 Skill 工具叫起 `dev-toolkit:plain-writing`（讀者判斷、四條判準、中立客觀、句子完整、列點、格式細節、送出前檢查）。
- **結構**：用 Skill 工具叫起 `dev-toolkit:tech-doc-writing`（讀者與文件類型、流程步驟寫法、決策類文件結構、術語一致、程式碼註解）。
- **APIs 專屬**：名詞與連結、目錄與版本規則，寫在下面。兩支 skill 沒寫到的以本檔為準，衝突時以本檔為準。

名詞用哪個字以 [`GLOSSARY.md`](../../GLOSSARY.md) 為準；系統行為的規則以 [`docs/knowledge/`](../../docs/knowledge/index.md) 為準（寫法見 `knowledge.md`）。

## 術語來源

- 領域名詞以 `GLOSSARY.md` 為準；各領域流程用語另見：遊戲接入 [`docs/單一錢包遊戲接入流程/index.md`](../../docs/單一錢包遊戲接入流程/index.md) 開頭的「名詞對照」、金流接入 [`docs/金流接入流程/index.md`](../../docs/金流接入流程/index.md)、對帳 [`docs/對帳資料表與時間欄位.md`](../../docs/對帳資料表與時間欄位.md)。流程用語與 `GLOSSARY.md` 衝突時以 `GLOSSARY.md` 為準。
- 金流域的名詞在 `GLOSSARY.md` 的「充值、取款與金流」一節。
- 文件提到 artisan 命令時寫成 `php artisan <命令>`，不單寫命令名。理由：有些命令和資料表名稱幾乎一樣（例如 `summary_bet_record:dispatch` 命令和 `summary_bet_records` 表），單寫會讓人分不清。

## 修正事實與底稿

- **修正文件裡的一個事實時，先搜尋舊值在 repo 還有哪些檔帶著**，一起改完再收工。查不到出處的現象寫「巧合」，不替它補機制。
- **盤點、稽核、掃描類底稿不開新文件入版控**：還沒做的寫進 `docs/未完成計劃/<自己的名字>/TODO.md`，護欄寫進該代碼的 docblock，通則寫進 `docs/codebase-reference.md`。理由：被清的東西消失後，這份文件也沒用了。

## 多階段流程文件的結構

適用 `docs/金流接入流程/`、`docs/單一錢包遊戲接入流程/` 這類一個目錄一套流程的文件：

- `index.md` 是入口，章節拆成 `一-XXX.md`、`二-XXX.md`。
- 每個子文件首行加 `> ← [<上層文件名稱>](index.md)`。
- 改文件時同步更新該目錄的 `CHANGELOG.md`（版本倒序、最新在上），並更新 `index.md` 開頭「文件版本」一節：「最新版本：**vX.Y.Z**（日期）— 一句話摘要」。patch 號疊在當下的最新版本上，例如 `v1.8` → `v1.8.1`。
