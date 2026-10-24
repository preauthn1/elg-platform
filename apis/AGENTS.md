# AGENTS.md - Codex 專案操作契約

本檔是 Codex 在 APIs 專案內工作的獨立入口。內容應足以讓 Codex 不依賴其他代理工具文件，也能做出安全的工具選擇、命令選擇與驗證判斷。

## 語言與回應

- 所有回應一律使用繁體中文與台灣用詞。
- 變數、類別、方法、檔案路徑、指令、套件名稱與技術術語保留英文。
- 回答先給結論，再補充必要原因、風險與驗證方式。
- 回答保持精簡，優先提供可執行結論與實際檔案/命令。
- 不確定需求、環境、資料庫連線或寫入風險時，先詢問使用者，不要自行假設並展開大量工作。

## 指令優先順序

1. 使用者當下明確要求。
2. 本檔 `AGENTS.md` 的 Codex 執行規則。
3. Codex session 內實際可用的 skill / plugin / MCP / tool 規範。
4. 專案內其他文件、程式碼慣例與命令輸出。

若規則衝突，遵守較高優先級；仍不確定時先詢問使用者。

## 專案基本事實

- APIs 是 PHP 8.4 / Laravel 13 / MySQL 8.0 專案。
- 執行環境以 Docker container `php-fpm8` 為準。
- 主要面向：
  - Frontend：會員 App，路由在 `routes/frontend/`，Controller 在 `app/Http/Controllers/Frontend/`。
  - Agent：代理商後台，路由在 `routes/agent/`，Controller 在 `app/Http/Controllers/Agent/`。
  - External：第三方回調，路由在 `routes/external/`，Controller 在 `app/Http/Controllers/External/`。
- 兩大整合子系統：
  - Payment Providers：`app/Services/Payment/Providers/{Name}/`，註冊於 `config/payment.php`。
  - Game Vendors：`app/Vendors/{Name}/Driver.php`，以 vendor enum value 註冊於 `config/vendor.php`。

## 任務範圍控制

- 預設 sequential 執行，一次完成一個步驟再進下一步。
- 預設不使用 subagents。
- spawn subagent 前必須先詢問使用者，且預設選項為不使用。
- 詢問時必須明確告知預期 subagent 數量、用途與成本/額度風險。
- 可用額度低於 20% 或無法確認額度時，禁止 spawn subagent，改用 sequential 直接執行。
- 能在單一 context 完成的任務不拆成 subagent，例如單一檔案讀寫、格式轉換、簡單資料搜尋、單一 API endpoint 測試。
- 任務範圍不明確時，先列出簡短計畫讓使用者確認，再開始大幅修改。

## Codex 工具與 Skill 解耦

- 本專案的 Codex 原生 skill 入口是 `.agents/skills/<skill-name>/SKILL.md`。
- 若 `.agents/skills` 中尚未提供需要的 skill，不要假設其他代理工具的 skill 會被 Codex 自動載入。
- 遇到尚未 Codex 化的領域流程時，先依本檔、現有程式碼、命令 help、官方文件與測試完成最小安全工作；若流程需要長期重用，再詢問是否建立或同步 Codex-native skill。
- 可以 read-only 參考專案內其他代理工具資料，但不得把那些資料視為 Codex 執行的唯一依賴。
- 不在 `AGENTS.md` 內維護大型領域流程；大型流程應拆到 `.agents/skills`。

## 領域 Skill 路由

- Laravel PHP 撰寫、重構、審查、controller、model、migration、form request、policy、job、service、Eloquent query、路由、快取、授權、驗證、錯誤處理：使用 `laravel-best-practices` skill；若 Codex session 未暴露該 skill，改用本檔規則、現有同層級程式碼、Laravel 官方文件與受影響測試。
- Payment provider 代收/代付/回調/簽名/銀行代碼/上線流程：使用 `payment-integration` skill；若不可用，先讀同 provider 類型的既有實作與 `config/payment.php`，保持最小改動。
- Game Vendor、wallet、bet record、transfer wallet、single wallet、DealRecord、BetRecord、補帳/對帳：使用 `game-integration` 或相關 Codex skill；若不可用，先讀既有 vendor driver、routes/external、config/vendor.php 與測試。
- Tailwind / Blade UI utility class / Filament UI：使用 `tailwindcss-development` skill；若不可用，遵循現有 Blade / Tailwind / Filament 寫法。
- Git commit message：使用 `git-commit` skill；若不可用，仍遵守本檔 Git commit 規範。

## 容器優先執行

- 所有 Artisan、Composer、Pint、PHPStan、PHPMD、PHPUnit、測試、腳手架與 Laravel 相關命令都必須在 `php-fpm8` container 內執行。
- 不要在 host 直接執行 `php artisan`、`composer`、`vendor/bin/pint`、`vendor/bin/phpstan`、`vendor/bin/phpmd` 或 PHPUnit。
- Codex shell 是非互動式，`docker exec` 預設不要加 `-it`。
- 遇到 `the input device is not a TTY` 時，移除 `-it` 後重跑。
- 標準格式：

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs && <command>"
```

- 常用命令：

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan test --compact tests/Feature/ExampleTest.php"
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan test --compact --filter=testName"
docker exec php-fpm8 bash -c "cd /var/www/APIs && vendor/bin/pint --dirty --format agent"
docker exec php-fpm8 bash -c "cd /var/www/APIs && composer stan"
docker exec php-fpm8 bash -c "cd /var/www/APIs && composer phpmd"
docker exec php-fpm8 bash -c "cd /var/www/APIs && composer lint"
```

- git push / fetch / pull / clone 等需要 host credential 的 Git 網路操作在 host 執行。

## Laravel / PHP 修改規則

- **實作前復用檢查**：動手寫任何新邏輯前，先查是否已有同型實作——搜代碼（`app/Services`、`app/Helpers`、`Common`、同類 Provider/Vendor）＋ `git log --grep='<關鍵字>'`。查到類似實作先比對再決定：能復用就復用，需要整段複製時改為抽共用方法，禁止複製貼上改值。
- 規則的權威出處是該 class 自己的 docblock。
- 修改前先理解同層級既有檔案慣例，保持最小可用改動，不做無關重構。
- 所有 PHP 檔案首行必須 `declare(strict_types=1);`。
- 控制結構即使只有單行也必須使用大括號。
- 方法參數與回傳值要明確宣告型別。
- DTO / service 可優先使用 `readonly class` 與 constructor property promotion。
- 優先使用 `match`、nullsafe operator 與 Enum，避免魔術數字。
- 參數超過 3 個時使用 named arguments。
- Service 優先使用 dependency injection。
- Controller 只做 request validation / response orchestration，業務邏輯下沉至 service。
- 新增 class、model、migration、test 等檔案時，優先使用 `php artisan make:* --no-interaction`，並先用 `php artisan list` 或 `php artisan <command> --help` 確認參數。

## Laravel 高風險禁止事項

- 禁止原生浮點數運算；金額與精度比較一律使用 `Common::numAdd/Sub/Mul/Div` 與 `Common::numCompare`。
- 禁止直接呼叫 `bcadd`、`bcsub`、`bcmul`、`bcdiv`、`bccomp`；必須透過 `Common::` 統一呼叫。
- 禁止將外部 float 直接 `(string)` 後參與運算；從 API 回應或 DB 取得的數值需先正規化到固定精度。
- 禁止 N+1 查詢；迴圈內不可查 DB，應使用 `with()`、`whereIn()` 或批次查詢。
- 禁止未綁定的 `DB::raw`；必須使用 binding 防 SQL injection。
- 禁止在 config 以外使用 `env()`；程式內一律透過 `config()` 讀取。
- 禁止任意繞過 Model 使用 `DB::table()`；僅允許 Model 不存在的統計聚合查詢，或有明確效能理由的批次寫入。
- 禁止未經使用者核准移除 `tests` 目錄中的測試或測試檔案。

## DB / 交易 / 高併發規則

- 所有寫入必須包裹 transaction。
- 涉及餘額、訂單、錢包、bet record、回調冪等性、補帳、狀態轉換的讀取，必須使用 `lockForUpdate()` 或既有封裝方法。
- 回調處理必須具備冪等性防禦；已處理訂單應走既有狀態檢查流程。
- 高併發共用快取使用 `Cache::safeRemember()`，不要直接使用 `Cache::remember()`。
- HTTP client 需使用 `Http::logAction()` macro 保留請求紀錄。
- exception log 使用 `Common::formatTrace($e)`，不要自行截斷 stack trace。
- 寫入 log 的請求內容含支付憑證或 PII（PIN、卡號、CVV、銀行帳號、API 憑證）時，先以 `Common::maskCredentials($request->all())` 遮罩再記錄，禁止 `$request->all()` 原文落 log；external 三方回調尤其危險。`maskCredentials()` 只遮硬憑證、保留 `token` 等簽名欄位；需連 token/mobile/email 一起遮的非 external 場景用 `Common::maskData()`。
- BotNotify 不要求遮罩：它送的是排查用的除錯資訊（請求網址、SQL、錯誤內容），遮了會難排查。同一份內容若也寫進 log，log 那份仍要遮；既有已遮罩的 BotNotify 呼叫點照舊，不必拿掉。
- 修改 DB connection、tenant、環境或執行 migrate / seed / fix / sync / tinker 寫入前，必須先明確告知目標環境並取得使用者確認。
- 切換 DB connection 不要改 `.env`；優先用 `docker exec -e DB_CONNECTION=<connection> php-fpm8 bash -c "cd /var/www/APIs && <command>"`。

## Payment / Game 基本規則

- Payment provider 代收與代付通常包含 `Driver.php`、`Deposit.php`、`DepositCallback.php`、`Withdrawal.php`、`WithdrawalCallback.php`、`{Name}Trait.php`。
- Payment provider 的共用 HTTP、簽名與轉換邏輯放在 trait 或既有 helper；trait 成員預設保持 `protected`。
- 新增或修改 provider 後，檢查 `config/payment.php`、相關 enum、測試與文件是否需要同步。
- Game transfer wallet 須處理 vendor/local balance transfer，single wallet 以回調扣款與 `getGameUrl` 為核心。
- Single wallet 必須考慮 multi-bet：同一 `roundId` 可能有多筆不同 `reference` 的 bet，需依既有模型累加或分流。
- Game callback 必須以 DealRecord 或既有請求紀錄作為冪等性判斷基礎。

## 測試與驗證

- 高風險或程式碼修改完成前必須驗證。
- PHP 修改後至少執行受影響測試；測試命令必須在 container 內執行。
- 修改 PHP 後執行 `vendor/bin/pint --dirty --format agent`。
- 需要靜態分析時執行 `composer stan`、`composer phpmd` 或 `composer lint`。
- 測試已涵蓋某功能時，不要另寫臨時驗證腳本或 tinker；優先使用單元測試與功能測試。
- 若必須用 tinker，先確認沒有更合適的 Artisan 指令或測試；寫入型 tinker 必須先取得使用者確認。
- 文件、ignore、設定小修可用 `git diff`、`rg`、格式檢查或 stale-text grep 驗證。
- 若無法執行測試或驗證，最終回覆必須明確說明原因與剩餘風險。
## 外部規範查證

- 討論 OpenAI、Codex、Cursor、Claude Code、Laravel/PHP 版本特性、外部 SDK/API、frontmatter/YAML/TOML、`latest`、`official`、supported/unsupported 類問題時，必須查官方來源。
- Laravel / PHP / 套件版本特性優先讀 `composer.json`、實際 lock/config、Laravel Boost / MCP 文件工具；若不可用，再查官方文件。
- 不用「我記得」「應該是」「通常是」作為規範依據。
- 若查證結果會進入文件或決策，回覆中附上 Sources。

## Git 與工作樹

- 編輯前先看 `git status --short`，確認本次會碰哪些檔案。
- 可能存在使用者未提交變更；不要 revert、checkout、reset 或覆蓋非本任務檔案。
- 未經使用者明確要求，不要執行 `git reset --hard`、`git checkout -- <file>`、強制覆蓋、刪除大量檔案或 destructive command。
- 未經要求不要 commit。
- 若使用者要求 commit，commit message 使用繁體中文，格式為 `<type>[optional scope]: <description>`。
- 常用 type：`feat`、`fix`、`refactor`、`test`、`chore`、`docs`、`perf`、`revert`、`style`、`ci`、`ai`、`skill`。
- commit title 上限 130 字元，描述至少 4 字。
- 若只修改 `AGENTS.md`、`.gitignore` 或文件，不要碰 unrelated PHP 檔案。
- 非文件類大幅修改、批次重構、跨多檔高風險修改前，先詢問是否需要建立 worktree。

## 文件維護

- `AGENTS.md` 維護 Codex 需要的執行契約、工具選擇、驗證邊界與高風險規則。
- `AGENTS.md` 不維護完整 Laravel Boost 指南、完整 domain 教學或長篇範例。
- 可重用且步驟較長的流程應移到 `.agents/skills/<skill-name>/SKILL.md`。
- 若新增重要專案規範，只有在會影響 Codex 行為、命令選擇、驗證或風險控制時才同步到 `AGENTS.md`。
- 純文件小修完成後，用 diff 或 grep 驗證沒有殘留舊稱、舊路徑、舊工具名稱或直接耦合。
