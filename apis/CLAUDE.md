# CLAUDE.md — AI 程式代理指南

> **語言指令**：所有回應一律使用**繁體中文**。變數、類別名稱、技術術語保持英文。

> **領域名詞以 [`GLOSSARY.md`](GLOSSARY.md) 為準**：代碼註解、commit message、文件與對話，優先採用該檔的正式名；標 `_Avoid_` 的說法通常有更精確的替代。**引用介面上實際顯示的文字、或代碼裡的字面值（欄位名、三方回調事件名）時照抄原文**，那些是識別字不是用詞。

> **系統行為以 [`docs/knowledge/`](docs/knowledge/index.md) 為準**：知識文件的規則表是唯一正本，改規則走 MR；Outline 只給客服。寫法見 `.claude/rules/knowledge.md`（讀 `docs/knowledge/` 時載入）。

> **PHP 8.4 / Laravel 13 / MySQL 8.0 / Docker (`php-fpm8`)**
> 高併發博弈平台，提供會員 App、代理商後台、第三方回調三個 API 面向。

> **Laravel Boost 啟用方式**：`laravel-boost` **MCP server** 由專案 `.mcp.json` 定義（`docker exec php-fpm8 php artisan boost:mcp`），經 `.claude/settings.local.json` 的 `enabledMcpjsonServers` 啟用。**該檔被 `.gitignore` 排除、只存在於本機 checkout；worktree 內找不到此檔屬正常。** `.claude/skills/` 底下的專案 skill 已進 git，worktree 內同樣可用；哪幾支由 boost 同步、哪支手工維護，見 `.claude/rules/project-skills.md`（讀 `.claude/skills/` 時載入）。

---

## Agent 行為

- Code review 預設單一聚焦 agent；只有高風險、寬表面、漏掉代價大（金流、安全、舊系統遷移）才升級成兩段式或 `/panel-review` 全 panel。
- subagent 差異只放專精領域與守備範圍深度，不靠人設。
- **實作前復用檢查**：動手寫任何新邏輯前，先查「這件事是不是已經有人做過」。查法沒有單一入口，四條管道併用：`codegraph_explore`（符號層最快，但**用問題語言問常常零命中，要用可能的 class／方法名去問**）＋ 搜代碼（`app/Services`、`app/Helpers`、`Common`、同類 Provider/Vendor）＋ `git log --grep='<關鍵字>'` ＋（可用時）gitlab MCP 搜 MR。查到類似實作先比對再決定：能復用就復用；需要整段複製時改為抽共用方法，禁止複製貼上改值。
  - ⚠️ **這幾條管道都只找得到「已經存在的東西」**。同一件事正被幾個人各做第一次、共用層還沒誕生時，四條全部查不到（實例：12 家金流商各自寫空憑證防護，共用 trait 晚了 5 個月才出生）。改的是別人也會碰的地方時，直接問一句比查半天可靠。
  - 規則的權威出處是**該 class 自己的 docblock**，硬規則寫進去、用「必須／禁止／一律」措辭。
- **業務設計不等於程式漏洞**：流程沒擋某件事時，先分辨是程式寫漏還是業務設計（舊系統也這樣，是業務設計的強烈訊號）。業務設計只說明風險、標「決策在 PM」，不列進 RD 待修，也不自己擬改法。
- **改金流或銀行比對規則，提方案前先自查**：設計回合提方案前，自己先查完三件事：修法放哪一層、每個呼叫端的副作用（逐一讀）、邊界案例，再交第二方複查（有 codex 外掛就用 codex）。例行查詢回合不適用。
- **退役看來源是否已斷**：判斷命令或檔案能不能退役，看它要補的來源是否已斷，不看最後 commit 日期、零引用或「看起來被取代」。查證結果交使用者裁決，不自行刪。理由：三支退役候選查證後都不能刪，其中一支還帶出真缺陷。
  - 盤點命令時先讀完 `handle()` 與 signature，照 `docs/codebase-reference.md`「Console Command 分類架構」判類（可複用維運、sync 後必須重跑的守護、一次性修補三類）。判 A 是否被 B 取代，讀 B 自己的 docblock。結論附具體證據（哪個參數、哪段自檢、哪處來源未斷）。理由：冪等修補工具本來就長期沒人動，誤刪後同樣問題再發生，只能裸寫 SQL 修。

---

## 外部規範查證原則

- 討論 OpenAI Codex、Cursor、Claude Code、外部 SaaS/SDK、file path、frontmatter/YAML/TOML、命令/API 格式、Laravel/PHP/套件版本特性、`latest`、`official`、supported/unsupported 前，先查官方來源；Laravel 優先用 `laravel-boost` MCP `search-docs` 或讀實際 `composer.json`。
- 禁止用「應該、通常、我記得」作為規範依據；規範表格/清單必附 Sources。
- 觸發詞：`Codex`、`Cursor`、`Claude Code`、`frontmatter`、`YAML`、`TOML`、`最新版`、`2025/2026 規範`、`official`、`支援/不支援`。

---

## Git 工作紀律（always-on）

> 這是**規則**（永遠生效，不依賴 skill 載入）；完整生命週期見 `release-workflow` skill。開發以**一般分支**（預設：主 checkout 內分支切換）或 **worktree**（sibling 獨立工作區，適用獨立／長期／需並行任務）之一進行，模式於開工時依該 skill 的分支模式選擇程序選定。

- **禁止 master commit**：master 內容只能經 MR 合入，**無任何例外**——包含 `docs/` 下純文件、`.claude/` skill/command 設定與程式碼，一律先開工作分支再改。排查階段只跑 read-only（grep / Read / 診斷命令）。
- **一動檔案先開分支**：要 Edit/Write 檔案前，必須確認已在工作分支上；新分支一律 `git fetch` 後從最新 origin/master 建立（一般分支 `git switch -c <prefix>/<topic> --no-track origin/master`；worktree 模式用 `apis-toolkit:new-worktree` 建，因為 host 與容器路徑不同，在容器內建的 worktree host 端 git 認不得，host 端用 EnterWorktree 建的則容器內跑不了 artisan），禁止從其他工作分支建立。
- **切分支先 stash**：工作區有未提交變更時，切換分支前必須 `git stash push -m "<說明>"`、切回後 `git stash pop`；禁止帶著未提交變更切換，禁止以 discard 丟棄變更。
- **新需求先確認**：與當前分支無關的新需求，先以 AskUserQuestion 確認判定後從 origin/master 開新分支；使用者明示「暫停目前工作／插單／急件」即為確認，免詢問直接走 `release-workflow` 的插單中斷與恢復程序。
- **git 網路操作走 host**：push / fetch / pull / clone 在 host 跑（容器無 credential）；artisan / composer / 測試才進容器。
- **分支語意命名**：`feature/` `fix/` `skill/` `docs/` `refactor/` + topic，禁止亂數 slug。**有工單時採「工單編號＋語意 topic」**：`target/tickets` 的單用 `feature/<id>-<topic>`（`.../target/tickets/-/work_items/4137` 代付事務邊界改造 → `feature/4137-payout-transaction-boundary`）；`target/op_tickets` 的單用 `op_ticket/<id>-<topic>`（`.../target/op_tickets/-/work_items/1562` → `op_ticket/1562-<topic>`）。**禁止只有編號的分支名**（如 `feature/4137`）——光看分支名就要知道在做什麼，不必回查工單。無工單才回到純 topic 命名。worktree 目錄沿用分支名 `/` 後的完整字串（`APIs-4137-payout-transaction-boundary`）。
- **不早 push＋commit 整理**：迭代期工作分支純本地，使用者確認收斂前禁止 push 與開 MR。未 push 時同一變更以 `git commit --amend` 併入並重寫 message，無關變更開新 commit——**同一變更＝同一個問題的修正，跨檔案／跨模組／跨遊戲商仍算同一變更，範圍擴大不構成開新 commit 的理由**；已 push 後以新 commit 追加，**非不得已不 force push / cherry-pick**（須經使用者同意）。
- **多 agent 並行**：僅限 read-only 任務；寫入類任務序列化執行。
- **職責分工**：commit message 格式走 `git-commit` skill；**branch / develop / MR / 發布 / 清理**生命週期走 `release-workflow` skill。

---

## 活動 V2 修改紀律（always-on）

> 任何觸碰 `app/Services/EventV2/`、`event_v2*` 資料表、`AbstractEventV2`、或 Event V2 相關 Controller / Model / Enum 的任務，必須完整走完以下流程。

1. **改前讀文件**：先閱讀 `docs/event-v2/`（`README.md` → 相關子文件），確認現有規則後再動手。
2. **發現衝突立即停下**：需求與現有規則有衝突，或可能造成金流異常 / 玩家損失 / 業務損失的 bug，**禁止自行決定繼續實作**，必須向 PM 提出並等待確認後再繼續。
3. **改中補文件**：發現 `docs/event-v2/` 有描述不清或遺漏的規則，立即在該處補齊，不留待事後。
4. **改後同步文件**：程式修改完成後，若活動規則有任何變更（新欄位、新邏輯、新活動類型），必須同步更新 `docs/event-v2/` 對應章節。

---

## 執行指令

**測試、Artisan、Composer、靜態分析一律在容器內執行**，不可在本機直接執行；git 與雲端 CLI 在 host 執行（見「Git 工作紀律」）：

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs && <command>"
```

常用：`php artisan test --compact <path>`、`php artisan test --compact --filter=<name>`、`php artisan cache:clear && php artisan config:clear && php artisan route:clear`、`php artisan make:payment`、`php artisan make:vendor`、`composer pint`、`composer stan`、`composer phpmd`、`composer lint`。

**唯讀 DB 查詢優先走 `mysql` MCP，不要用 tinker**：`mysql-{tpower,vworld,yes2win,alfawin77,me77,vplus33}` 為各站點的唯讀直連（VWorld 例外：數個站點共用同一套資料庫，一條連線對到多個站點），`mysql-dev` 是 Dev 測試環境的唯讀直連，ad-hoc SQL／資料分析／查 schema 一律先用它（見 `whitelabel-db-query` skill）；**只有 MCP 不可用**（未連線、或該站點無對應 server）才退回容器內 tinker，並依 `whitelabel-container-exec` 注入 `DB_CONNECTION`。開查之前先確認答案是不是已經在 `config/`、migration 或程式碼裡。

**測試庫只同步 schema，禁止灌業務資料**：測試庫（`--env=testing`）的內容只能來自 migration 與 stored procedure 定義，業務資料一律由各測試檔自己 init。

- **允許**：缺表或缺欄位跑 `php artisan migrate --env=testing`；缺 stored procedure（`ProcedureDirectorySyncedWithDatabaseTest` 失敗）跑 `php artisan db:seed --class=SyncProcedureSeeder --env=testing`——該 seeder 只做 `DROP/CREATE PROCEDURE` 並寫自己的 `procedure_hashes` 記帳表，不寫入任何業務主檔。
- **禁止**：`app:upgrade` 與 `SyncProcedureSeeder` 以外的任何 `db:seed`。`app:upgrade` 會灌進 Vendor / CashFlow / AgentSettingDefine / AgentMenu / SmsGateway / Country / Currency 等主檔，撞掉以固定 `id` 建資料的測試。

---

## 架構總覽

三個 API 面向：Frontend（`routes/frontend/`、`Http/Controllers/Frontend/`、Member token）、Agent（`routes/agent/`、`Http/Controllers/Agent/`、Agent token）、External（`routes/external/`、`Http/Controllers/External/`、IP 白名單）。**External 的 IP 白名單只在 sw / tw 的 controller 內以 `Common::verifyIPWhitelist()` 驗；`routes/external/api.php` 與 `routes/frontend/third_party_auth.php` 的 `DetectIp` 只寫 Context 不做驗證，該兩組的 IP 防線在 nginx / ingress 層，code 層沒有。**路由依驗證拆成 `auth.php`/`non_auth.php`；external 另拆 `api.php`、`sw.php`、`tw.php`。

兩大整合子系統：Payment Providers 在 `app/Services/Payment/Providers/{Name}/` 並註冊於 `config/payment.php`；Game Vendors 在 `app/Vendors/{Name}/Driver.php` 並以 `VendorIdEnum::XXX->value` 註冊於 `config/vendor.php`。

---

## 金流商整合

代收＋代付 provider 必須包含 `Driver.php`、`Deposit.php`、`DepositCallback.php`、`Withdrawal.php`、`WithdrawalCallback.php`、`{Name}Trait.php`；共用 HTTP / 簽名邏輯放 trait，trait 成員保持 `protected`。上線步驟與銀行規則詳見 `payment-integration` skill。

---

## 遊戲商整合

轉帳錢包繼承 `AbstractTransferWallet`，須處理 `transferToVendor` / `transferToLocal`；單一錢包繼承 `AbstractSingleWallet`，以 `getGameUrl` 與回調扣款為核心。Single wallet 必須支援 multi-bet（同 `roundId` 多 `reference`）並以 DealRecord 做冪等性。詳見 `game-integration` skill。

---

## 🚫 金流／安全／併發紅線（always-on）

> 漏掉代價大的硬規則常駐此處；寫 PHP 時的完整範例、慣例與 craft 見 [`.claude/rules/php.md`](.claude/rules/php.md)（開 `.php` 檔自動載入）。

- **金額運算只走 `Common::numAdd/Sub/Mul/Div` 與 `Common::numCompare`**：禁止直接呼叫 `bcadd/bcsub/bcmul/bcdiv/bccomp` 等 bc 函式。
- **外部 float 入境即正規化**：API 回應（JSON decode）或 DB 讀取（Eloquent）的數值，若要參與運算／比較，於**進入時**即 `Common::numAdd('0', (string) $value, 4)`，禁止直接 `(string) $value`（`(string) 1.50000001` 仍帶精度誤差）。
- **寫入預設包 transaction；讀出後要改寫、或「該列應已存在」的讀取用 `lockForUpdate()`**：防競態。不包的例外只有三種：單筆 INSERT、「INSERT 佔號 → 打三方 → UPDATE 回填」流程、三方 API 呼叫本身（禁止放進事務）。重複檢查這類「該列不該存在」的查詢刻意不鎖（會拿 gap lock 造成 deadlock），細則見 `.claude/rules/php.md`「MySQL 鎖與事務」。
- **回調必冪等**：`findAndLockDepositOrder()` ＋ `checkDepositOrderStatus()`（已處理則拋 `DepositOrderException(200)`）。
- **憑證／PII 入 log 必先 `Common::maskCredentials()`**：external 三方回調尤甚（log channel 比 DB 廣，未消耗憑證等同金錢），禁止 `$request->all()` 原文落 log。**BotNotify 不在此條**：它送的是排查用的除錯資訊（請求網址、SQL、錯誤內容），遮了會難排查，不要求遮罩；同一份內容若也寫進 log，log 那份仍要遮。既有已遮罩的 BotNotify 呼叫點照舊，不必拿掉。
- **HTTP header 是獨立的洩漏面，遮了 body 不等於遮了 header**：進站會員／代理 bearer token 與出站三方 API token 都在 `Authorization` header（曾因此明文落 log）。header 落 log 一律經 `Common::flattenHeaders()`（內建憑證遮罩）或 `Common::maskHeaders()`；遮罩鍵清單一律集中 `MaskTrait`，禁止在呼叫端另立敏感鍵清單（會 drift）。三方簽名類 header（digest／X-Sign／wtoken）刻意不遮，保留「從 log 重算簽名」的排查能力。**遮罩一律部分遮罩（頭尾可見）保留日誌判讀能力，唯值太短、露頭尾等同全洩時由長度門檻退回全遮——禁止新增無條件全遮的鍵（密碼類除外：密碼（含確認密碼、支付密碼）在會員端請求紀錄一律全遮，規則見 `MaskTrait` 檔頭「密碼類例外」）**（三 profile：帳號前 3 後 4／姓名頭尾字／秘密只露尾 4，定義在 `MaskTrait`）。
- **禁止未綁定的 `DB::raw`**（SQL Injection）；**禁止 `DB::table()` 繞過 Model**（僅允許 Model 不存在的聚合查詢、或標註效能的批次寫入）。
- **禁止在 config 以外使用 `env()`**：一律透過 `config()` 讀取。
- **禁止 N+1**（迴圈內不查 DB，用 `with()`／`whereIn()`）；**禁止魔術數字**（封裝 Enum）；**禁止 Controller 邏輯**（業務下沉 Service）。

---

## Git Commit 規範

commit message 一律繁體中文，格式 `<類型>[可選作用域]: <簡短描述>`，標題上限 130 字元、描述最短 4 字。type：`feat`、`fix`、`refactor`、`test`、`chore`、`docs`、`perf`、`revert`、`style`、`ci`、`ai`、`skill`。每位開發者一次性安裝 `.githooks/pre-commit.sh` 與 `.githooks/commit-msg.sh`；MR CI 會重驗 commit message。

---

## 關鍵參考檔案

詳見 [`docs/codebase-reference.md`](docs/codebase-reference.md)，包含關鍵路徑索引、單一錢包 Controller 架構說明與 PPSW 特有邏輯。

---

## Laravel Boost 與 Path-Scoped Rules

- Laravel Boost / Filament / PHPUnit / Pint 的泛用規則不放在 launch-loaded `CLAUDE.md`，避免每個 session 都載入大段框架指南。
- 修改 Laravel PHP / Blade / routes / config / database / tests 時，會由 `.claude/rules/laravel-boost.md` 與 `.claude/rules/php.md` 按路徑載入補充規則。
