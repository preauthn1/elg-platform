# apis

> 本目录原始 README 见文末，本节为快照补充信息。

原始路径：`apis`　|　文件 **5666**　|　主语言：PHP 4793, SQL 225, JSON 14

## 目录构成

```
database/migrations/  — 947
app/Http/  — 728
tests/Feature/  — 599
app/Services/  — 551
app/Vendors/  — 471
tests/Unit/  — 396
app/Models/  — 302
app/Console/  — 273
database/procedures/  — 220
docs/integrations/  — 213
docs/knowledge/  — 211
app/Enums/  — 151
```

## 关键文件

- CI/构建：`.gitlab-ci.yml`
- 前端入口：`resources/js/app.js`、`public/vendor/log-viewer/app.js`

---

## 原始说明

<div align="center">
  <img src="docs/banner.jpg" alt="APIs Banner" width="100%" />

  <br/>

  ![PHP](https://example.invalid/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white)
  ![Laravel](https://example.invalid/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white)
  ![MySQL](https://example.invalid/badge/MySQL-8.0-4479A1?style=flat-square&logo=mysql&logoColor=white)
  ![Docker](https://example.invalid/badge/Docker-Ready-2496ED?style=flat-square&logo=docker&logoColor=white)
</div>

---

## 新成員報到

你已成功加入團隊，歡迎來到戰場。

這裡是一個高併發平台的後端核心，每天承載大量玩家的交易與遊戲行為。你的程式碼將直接影響數萬筆訂單的正確性——寫好了沒人注意，寫壞了所有人都知道。

在你正式上線之前，請完成以下新手任務：

- [ ] 確認 Docker 環境可以正常啟動（`php-fpm8` 容器）
- [ ] 準備你習慣的 DB GUI tools
- [ ] pull 專案
- [ ] 請求 Google Log Explorer 權限
- [ ] 請求各環境站台資訊文件
- [ ] 閱讀 `CLAUDE.md`，了解這個世界的規則
- [ ] 安裝 Git Hooks，讓你的每次提交都符合規範。在專案根目錄執行：
  ```bash
  ln -sf ../../.githooks/pre-commit.sh .git/hooks/pre-commit
  ln -sf ../../.githooks/commit-msg.sh .git/hooks/commit-msg
  ```
  這兩行建立的是 symlink，之後 `.githooks/` 的規則有更新會自動生效，不必重裝。hook 會跟著你目前所在的分支走：切到很舊的分支時，跑的是那個分支上的 hook 版本。
- [ ] 跑一次測試，確認環境一切就緒：
  ```bash
  docker exec -it php-fpm8 bash -c "cd /var/www/APIs && php artisan test"
  ```

> 遇到問題？先看文件，再問隊友。我們不解釋基礎，但我們歡迎好問題。

---

## 文件導航 (Docs)

這裡存放專案的各類技術文件，請根據你的任務查閱對應的引導：

- **[金流接入流程](docs/金流接入流程/index.md)**：從配置、開發到測試上線的完整金流商對接指南。
- **[單一錢包遊戲接入流程](docs/單一錢包遊戲接入流程/index.md)**：從配置、開發到測試上線的單一錢包 (Seamless) 遊戲商對接指南。

### 工程實踐

- **[領域詞彙表 `GLOSSARY.md`](GLOSSARY.md)**：領域名詞的唯一權威——正式名、應避免的說法、概念之間的關係。
- **[知識庫 `docs/knowledge/`](docs/knowledge/index.md)**：系統行為的唯一正本（SSOT），一個功能一頁，Open Knowledge Format；改規則走 MR。
- **[領域決策紀錄 `docs/knowledge/decisions/`](docs/knowledge/decisions/)**：難回頭、且看代碼會納悶「為什麼這樣做」的決策。
- **[AI 工具使用指南](docs/AI工具使用指南.md)**：本專案 Claude Code / Codex 輔助開發的設定與使用方式。
- **[Commit Message 規範](docs/commit-message-announcement.md)**：commit 訊息類型與格式對照。
- **[Gitlab CI 機制](docs/GitlabCI機制.md)**：pipeline 流程、lint stage、部署環境對應。
- **[升級命令說明](docs/升級命令說明.md)**：部署時 `app:upgrade` 同步哪些資料檔（遊戲商、金流商、SP、代理配置等），以及要改這些資料時怎麼做。

### 機制與背景知識

- **[AgentVendor 機制說明](docs/AgentVendor機制說明.md)**：代理商與遊戲商的對應與配置邏輯。
- **[遊戲商轉入轉出機制說明](docs/遊戲商轉入轉出機制說明.md)**：轉帳錢包點數流動的核心機制。
- **[常用命令速查](docs/Helper.md)**：常用 artisan 命令，包含替 Model 產生 phpdoc（`ide-helper:models`），以及建立與配置遊戲商、金流商的命令（`make:vendor`、`make:payment`、`vendor:check_vendor`、`vendor:add_vendor_info`、`agent:add-agent-cash-flow`）。
- **[Blueprint Macros](docs/Blueprint-Macros.md)**：自訂 Migration Blueprint macro（`timeColumns`、`vendorAccountColumns`、`vendorBetRecordColumns`）的欄位清單與使用範例。

> 撰寫新文件或修改既有文件前，請先閱讀 **[領域詞彙表 `GLOSSARY.md`](GLOSSARY.md)**（名詞用哪個字以它為準）。用字與文件結構走 GFF-Marketplace `dev-toolkit` 的 `plain-writing` 與 `tech-doc-writing` 兩支 skill；APIs 專屬的名詞來源、目錄與版本規則在 [`.claude/rules/docs.md`](.claude/rules/docs.md)，改 `docs/` 底下的檔案時 agent 會自動載入。

---

### 環境變數
* env 變數異動，請先在 .env.example 中新增，並在通知部署人員，避免漏掉變數。 *
### 代碼規範
1. 目前提交代碼檢查詳目
   1. 檢查 php 語法正確性
   2. 執行 Laravel Pint 統一程式碼風格
   3. 更新 ide-helper 文件
   4. 檢查專案目錄結構 `validate_directory_structure`
   5. 檢查文件路徑規則 `validate_file_locations`
   6. 檢查文件命名規範 `validate_naming_conventions`
   7. 檢查是否包含不允許提交的文件名稱 `check_unnecessary_files` (ex: *.log, *.cache)
2. 設定 git pre-commit hook
    ```bash
    # 以 symlink 指向專案內的 hook，.githooks/ 更新後自動生效
    ln -sf ../../.githooks/pre-commit.sh .git/hooks/pre-commit
    ```
3. 代碼規範使用 [Laravel Pint](https://example.invalid/docs/12.x/pint) 規則定義在 pint.json 中，更多規則請參閱 [php-cs-fixer](https://example.invalid/php-cs-fixer-configurator/)

