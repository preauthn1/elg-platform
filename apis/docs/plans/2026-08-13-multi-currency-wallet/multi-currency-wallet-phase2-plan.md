# 多幣別錢包 第二期（幣別主檔治理）— 實作計劃


> 產出：2026-08-28｜對應簡報第 9 節六期實作計劃的 **第二期（階段代號 2.1～2.3）**
>
> 🎫 **工單**：`target/ticket_tasks#1267`（2026-09-02 自 `target/tickets#4247` 遷入，原單已封存）。本計劃各 task 的 commit 範本原引用 `#4247`，2026-09-07 開工時查證後全數改為新單；`feat` 類 commit 一律帶 `target/ticket_tasks#1267`。
>
> 🔴 **權威順序（2026-09-18 起）**：[`multi-currency-wallet-decisions.md`](multi-currency-wallet-decisions.md) > [`multi-currency-wallet-roadmap.md`](multi-currency-wallet-roadmap.md)（工作順序總表）> 本計劃。簡報 `docs/reports/multi-currency-wallet-target-flow.html` 停在 09-14 版、未含決議㉚～㊲，已降為歷史會審材料，不再作衝突仲裁。
>
> ✂️ **本計劃 2026-09-18 依「落實即刪除」規則縮減，只留尚未開工的 Task 9～11**。Task 1～8、12、13 已全數進 master，原文（含各 task 的執行結果與圓桌會審紀錄）見 `git show 05e3a4d30:docs/plans/2026-08-13-multi-currency-wallet/multi-currency-wallet-phase2-plan.md`。第零期與第一期計劃同日刪除，同樣以該 commit 為查閱點。
>
> 🔴 **Task 9／10 的執行時點已改**：依總表，兩者排在「幣別主檔清洗工作包」（決議㉛：清七列、虛擬幣 id 重編）**之後**，因為兩者的比對基準都是主檔 id；Task 11 純字串正規化，可插任何空檔。另依決議⑲，Task 9 的 guard 與 Task 10 的稽核**要同時涵蓋 `vendor_infos` 與 `event_vendor_infos` 兩張主檔**，本檔內文尚未展開第二張表，開工時補。

**Goal：** 讓每個遊戲商帳號都能查出「這個帳號屬於哪一種標準幣別」，並讓幣別主檔本身有唯一性保證與一致性防線——這是後續所有期別填 `currency_id` 的前提。

**Architecture：** 三步走。先讓主檔可信（補齊缺的標準幣別、`code` 加唯一鍵），再讓商戶帳號帶上正規化的幣別編號（`vendor_infos.currency_id`，回填走專用 artisan 命令），最後加一道防線讓「字串幣別」與「編號幣別」不會再分岔（Model 層 guard ＋ 每日稽核排程）。**既有的 `vendor_infos.currency` 字串欄位全程不動**——全 repo 實測 **57 處／29 檔**直接進三方 payload，改它等於改三方對接。契約定版見 [`multi-currency-wallet-phase0-contract.md`](../../multi-currency-wallet-phase0-contract.md)。

**Tech Stack：** PHP 8.4／Laravel 13.17.0／MySQL 8.0／Docker（`php-fpm8`）／Laravel Scheduler

**Spec：** 簡報 `docs/reports/multi-currency-wallet-target-flow.html` 第 9 節（`#plan`）第二期交付定義＋第 11 節（`#currency-config`）幣別配置盤點＋第 13 節（`#amount-semantics`）幣別金額語意

---

## Global Constraints

每個 task 的要求都隱含包含本節。

- **測試與 artisan 一律在容器內、且指向本 worktree**：`docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && <command>"`。⚠️ 不可用 `/var/www/APIs`——那是主 checkout，跑的是 master 的程式碼。
- 🔴 **本期會動 schema 與生產資料，每一個寫入步驟前都要停下來確認**。所有回填一律先 `--dry-run`，把預覽結果貼給 RB 看過再實跑。
- 🔴 **禁止裸寫**：不得用 tinker／臨時 SQL 改資料。補主檔走 `database/data/currencies.php` ＋ `SyncCurrenciesSeeder`，回填走專用 artisan 命令。（記憶點 `feedback_raw_db_write_stays_forbidden`）
- **migration 一律用 `SchemaMonitor`** 並以 `hasColumn()`／`hasIndex()` 包住，比照 `database/migrations/2026_03_03_000001_alter_currencies_add_sort.php` 的寫法。
- 🔴 **`SchemaMonitor::table()` 有列數門檻**：`app()->isProduction()` 時列數超過 `$maxRows`（預設 500000）**直接 return，但 migration 仍被記錄為已執行**——欄位沒建成、程式端 unknown column 全面失敗（實證：`app/Facades/SchemaMonitor.php:78-95`）。`currencies`（21 列）與 `vendor_infos`（六站皆數百列）都遠低於門檻，**但 migration 內仍要明確處理**，不可依賴「反正不會超過」。
- **禁止在 config 以外使用 `env()`**，一律 `config()`。
- **禁止魔術數字**：幣別一律走 `App\Enums\CurrencyEnum`。
- **金額運算只走 `Common::numAdd/numSub/numMul/numDiv` 與 `Common::numCompare`**；`rate` 參與運算時同樣適用。
- **測試庫只同步 schema，禁止灌業務資料**：缺表缺欄跑 `php artisan migrate --env=testing`；**除 `app:upgrade` 與 `SyncProcedureSeeder` 外禁止任何 `db:seed --env=testing`**。本期的 `SyncCurrenciesSeeder` **只在 Dev／生產跑，不可對測試庫跑**，測試所需的幣別資料由各測試檔自行 init。
- 🔴 **測試禁止 `use RefreshDatabase;`**（2026-08-31 事故）：它對 MySQL 測試庫會重建整個資料庫、無條件 drop 全部資料表，`procedure_hashes` 會被清空、stored procedure 測試全紅，CI 上還會依平行測試的分配變成隨機紅。**父類 `Tests\TestCase` 已 `use DatabaseTransactions`**（實測 528 個測試類別繼承它，另有 38 個自己再寫一次；`RefreshDatabase` 零使用），每個測試結束自動回滾，不需要也不可以再重整資料庫。本計劃原稿的測試範本曾帶著這一行，已於同日全數移除。
- **commit message 繁體中文**，格式 `<類型>[可選作用域]: <簡短描述>`，標題上限 100 字元。

---

## 這一期為什麼必須排在第一期影子資料之前

簡報第 9 節明載：第二期「**須先於第一期影子資料**」。理由是第一期 1.2 會把 `members.balance` 影子同步進 `member_wallets` 並寫入 `currency_id`——如果那個時候「哪個會員屬於哪個幣別」還沒有可信的來源，寫進去的值就是猜的，之後要重寫一次。

**兩期的實際依賴關係**：

```
第二期 2.1（主檔可信）→ 2.2（商戶帶幣別編號）→ 2.3（一致性防線）
                                    ↓
                       第一期 1.2（影子同步寫 currency_id）
```

第一期的 1.0（寫入點收斂）與 1.1（錢包表加欄位改唯一鍵）**不依賴本期**，可以並行推進。真正的交會點只有 1.2。

---

## 開工前必須知道的事（2026-08-28 六站實查、2026-09-01 圓桌補記）

### `currencies` 主檔的真實現況

| 項目 | 實測結果 | 取得方式 |
|---|---|---|
| 六站筆數 | **全部 21 筆**（tpower／vworld／yes2win／alfawin77／me77／vplus33） | `information_schema` ＋ `SELECT ... FROM currencies`（mysql MCP） |
| 六站 `id → code` 對應 | **逐字完全一致**（`1:MYR 2:SGD 3:VND 4:VND2 5:KVND 6:IDR 7:IDR2 8:KIDR 9:THB 10:USDT 11:FON 12:GCASH 13:BUSD 14:MMK 15:PayMaya 16:HKD 17:PGK 18:AUD 19:BDT 20:TON 21:INR`） | 同上 |
| 啟用中筆數 | 🔴 **不是六站一致**：TPower 19／VWorld 19（各停用 `BUSD` 與 `PGK` 兩筆）；Yes2Win／AlfaWin77／Me77／VPlus33 皆 20（只停用 `BUSD`） | 同上 |
| `code` 重複值 | **零重複**（`COUNT(*) = COUNT(DISTINCT code) = 21`） | AlfaWin77 實測 |
| `code` 的索引 | 🔴 **只有 `PRIMARY(id)`，`code` 完全沒有索引** | `information_schema.STATISTICS` |

> ✅ **驗掉一項「未驗證」**：決議清單第八節記「`currencies.code` 沒有 unique constraint（六站 id 與 code 一致是巧合不是保證）」。**08-28 已六站全驗：id→code 完全一致、code 零重複** ⇒ 加 unique 沒有資料障礙，而且六站一致是可以依賴的事實（至少在現況成立）。

### `currencies` 的欄位有兩個沒人用

生產庫的 `currencies` 比版控 migration 多兩欄：`deposit_rate`／`withdrawal_rate`（皆 `decimal(20,8)`、預設 `1.00000000`、註解「代收匯率／代付匯率（主要貨幣固定為 1）」）。

> ✅ **驗掉第二項「未驗證」**：決議清單記「`deposit_rate`／`withdrawal_rate` 語意未查明」。**08-28 已查明**——來源是 `database/migrations/2026_03_16_000001_refactor_payment_channel_schema.php`（金流通道 schema 重構時加的），**程式碼零讀寫**（全 repo 只有 `app/Models/Currency.php:18-19` 的 property 註解）。⇒ **已定義但未啟用**，本期不動它，但要在文件記明「不是死欄位、是金流未來要用的欄位」。

另外 `currencies.rate` 六站全為 `1.00000`、實際未使用；真正生效的是 `vendor_infos.rate`（簡報第 13 節）。

### 32 個啟用中商戶對不到幣別（VWorld 實測）

`vendor_infos.currency` 用 `LEFT JOIN currencies ON code = currency` 比對，**六種代碼查無對應**：

| 代碼 | 商戶數 | 啟用中 | 處置 |
|---|---|---|---|
| `PHP` | 27 | **16** | 菲律賓披索，標準幣別 ⇒ 2.1 補進主檔 |
| `USD` | 29 | **14** | 美元，標準幣別 ⇒ 2.1 補進主檔 |
| `CNY` | 1 | **1** | 人民幣，標準幣別 ⇒ 2.1 補進主檔 |
| `HKB` | 1 | **1** | ✅ 09-07 RB 裁決（決議㉔）＝HKD 拼寫變體：`normalize` 加 `HKB → HKD`，currency_id 對到 16；字串另由 ops 走後台改 HKD（op 單）。證據見 Task 4 執行結果 |
| `BND` | 18 | 0 | 汶萊元，標準幣別但全數停用 ⇒ 視是否恢復營運 |
| `MSC` | 1 | 0 | ✅ 09-07 RB 裁決保留 `unresolved` 不動：站名誤填進幣別欄、商戶已停用不阻斷，稽核持續列為 unresolved 屬預期 |

**啟用中合計 16＋14＋1＋1 ＝ 32**，與簡報 08-27 記載一致。

### 🔴 六個組織的幣別是 0，而且都在營運中

簡報把「VWorld 有 6 個組織 `currency_id = 0`」列為「上線前置清理項」。**08-28 實查發現這個描述太輕描淡寫**——六個組織**全都有啟用中的代理**：

| 組織 id | 名稱 | 代理數 | 啟用中代理 | 從名稱推測的幣別 |
|---|---|---|---|---|
| 2 | MMGames | 2 | 1 | 未知 |
| 3 | vworldau | 3 | 2 | 疑為 AUD（澳幣） |
| **6** | **vworldbn** | **581** | **573** | 疑為 BND（汶萊元） |
| 12 | gff | 6 | 6 | 未知 |
| 18 | JiliGames | 4 | 4 | 未知 |
| 20 | GFF USDT | 27 | 26 | 疑為 USDT |

⚠️ **這不是「清理」而是「必須判定並填對」**——幣別錨在組織層（`organizations.currency_id`，`agents` 與 `members` 皆無幣別欄位），這六個組織底下的會員錢包幣別現在無從判定。**`vworldbn` 有 573 個啟用代理，規模不容忽視。** 名稱推測**不可當依據**，必須由業務確認。

### ⚠️ 簡報的一處斷言需要修正：TPower 不是「123/123 全 MYR」

簡報第 9 節寫「TPower 全站 123／123 個 `vendor_infos` 皆為 MYR、rate 皆為 1」，並據此推出「TPower 可當觀察期基準站，報表數字必須與改動前一模一樣」。

**08-28 實測**：TPower 是 **MYR 116 筆（70 啟用）＋ HKD 7 筆（3 啟用）**，合計 123。**不是全 MYR。**

**但結論仍然成立**，理由要換一個：逐筆查那 7 個 HKD 商戶（CRSW `vendor_id=121`、WESW `123`、WM Live `126` 為啟用中，另 4 筆停用），**`total_bet_records` 全部 0 筆注單**。零注單 ⇒ 加了幣別維度之後彙總鍵仍只對應一筆 ⇒ 報表數字確實應該一模一樣。

🔴 **但這個驗收法有有效期限**：那 3 個啟用中的 HKD 商戶隨時可能開始有注單。**觀察期開始前必須重跑一次這個檢查**，本計劃 Task 10 的稽核排程會涵蓋它。

### 🔴 圓桌批次二新發現（2026-09-01）：TPower 六成會員的幣別解析鏈是斷的——本期治理主體須擴大

第一期圓桌批次二 marz 六站實測（登記於已刪除的第一期計劃「2026-09-01 圓桌審查批次二」節，原文 `git show 05e3a4d30:docs/plans/2026-08-13-multi-currency-wallet/multi-currency-wallet-plan.md`）：**TPower 60.9% 會員（約 60 萬筆）的 `members.parent` 指向不存在的代理**，member→agent→organization 的幣別解析鏈在第一步就斷掉——這些會員的幣別不是「主檔缺值」，是**根本解析不到主檔**。本期開工時須以同法重測取準確基準值（資料會漂移，且圓桌只登記了粗略量級）；其餘五站的同法實測結果未登記於計劃（未驗證），開工時一併補測以決定治理只涵蓋 TPower 或六站全查。✅ **09-07 Task 12 已重測六站（數字見影響盤點第八節）：60.9% 屬實，但啟用中只有 119 名（0.03%）、全群 2024-05-30 後靜止；其餘四站零孤兒（Me77 3 名疑測試帳除外）。治理主體改寫為「60 萬筆死帳的處置」，見 Task 12 執行結果的四類提案。**

**對本期的影響**：本計劃現有 11 個 task 全數落在「主檔可信＋商戶帳號帶幣別＋一致性防線」，前提都是解析鏈通；孤兒 parent 治理（判定歸屬、回填或另立規則）**尚無對應 task**。圓桌結論是「第二期治理的主體不是補幣別而是孤兒歸屬」——**開工時須先與 RB 確認治理方案並補立 task，不可只跑完現有 11 個 task 就宣告本期完成**。（此發現亦是啟用第一期雙寫的前置：解析不出幣別的會員，影子同步寫入的 `currency_id` 只能是 sentinel 0。）✅ **09-07 RB 拍板（決議⑱）：補 Task 12「六站量測＋歸屬提案」，排在 Task 4 之前執行、只提案不改資料**（見 Task 12）。

### 🔴 `vendor_infos.currency` 不是純顯示標籤，它進對外 API

這個欄位是 `varchar(5)`、註解只寫「幣種代碼」，而且**沒有對應的外鍵**——設計原意就是「免 join 就看得懂」的識別字串。但 08-28 查代碼發現它已經長出行為：

全 repo 實測 `vendorInfo->currency` 共 **57 處／29 檔**：

| 層 | 檔數 | 代表位置 | 影響 |
|---|---|---|---|
| 單一錢包 controller | 12 | `SexySlotCq9SWController.php:148`、`PlayTechController.php:146`、`ImperiumController.php:196`、`BoleController.php:254` | 直接回傳給三方，改值等於改 API 回應內容 |
| **遊戲商 Driver／Service** | **14** | 六支 Driver（`CRSW/Driver.php:72`、`PPPOP/Driver.php:64`、`WFSW`、`ClotPlaySW`、`FFFSW`、`Baison`）＋ `WudiSWService` ＋ `MotivationSWService` ＋ `MotivationSW/Libs/` 六支（`Bet.php:64`／`Credit`／`Debit`／`Refund`／`Rollin`／`Endround`） | 同上 |
| **轉帳錢包 controller** | **2** | `NextSpinController.php:115`、`EbetController.php:65` | 同上 |
| 擋三方請求 | （含在上列 12 檔內） | `PxplaySwController.php:121`、`TTHSWController.php:1286` | 以 `!==` 比對三方傳入的 currency。**PHP 字串比較大小寫敏感**——把 `kIDR` 改成 `KIDR` 而三方仍送 `kIDR`，該商戶的請求會開始被拒絕 |
| 後台顯示標籤 | 1 | `app/Filament/Admin/Pages/Queries/BetRecordCorrection.php:171` | 純顯示，唯一符合設計原意的用途 |

⚠️ **別把曝露面窄化成「單一錢包 controller」**——轉帳錢包與遊戲商 Driver 層同樣在用，漏算會低估改值風險。

⇒ **本期加 `currency_id` 而不動字串值，正是為了讓這兩層解耦**：字串留給三方與人看，新欄位給程式判讀。

### 幣別參數是雙來源，兩者不必然相同

`vendor_infos.info`（`text`、註解「連接資訊」，VWorld 614 筆全為合法 JSON）內另有 `CURRENCY`／`Currency`／`CURRENCY_NAME` 鍵，那才是三方要求的參數格式。與欄位比對的實測：

| 商戶 | `currency` 欄位 | `info.CURRENCY` | 說明 |
|---|---|---|---|
| IBC（saba，AlfaWin77） | `IDR` | `15` | 三方用數字碼 |
| MMK 商戶（VWorld） | `MMK` | `MK2` | 三方自訂縮寫 |
| USDT 商戶（VWorld） | `USDT` | `UST` | 同上 |
| M9BET／AE Sexy（AlfaWin77） | `KIDR` | `IDR` | 欄位是本地計價單位、三方收標準碼 |
| NEW4D（AlfaWin77） | `MYR` | `IDR` | `rate = 0.00025`，MYR 站接印尼盤 |

🔴 **`vendor_infos.rate` 同時承擔兩種語意**：計價單位換算（`0.001` 千分制）與跨幣別匯率（`0.00025`，約等於 1 馬幣兌 4000 印尼盾的倒數）。**不能只靠 rate 反推幣別。**

⚠️ AlfaWin77 的 `Vpower`(id=28)／`Vpower(E2)`(id=61) 的 `info` **沒有 `CURRENCY` 鍵**，三方參數可能直接取自欄位——這兩筆若要改值，風險最高。

### ⭐ `KVND`／`KIDR` 必須保留（與決議三一致，08-28 補上業務理由）

**這不是新決策。** 08-27 決議三已定：「`KVND` 走 `vendor_infos.currency`（字串、送三方），`currency_id` 維持 `VND`——兩者外觀不一致是設計，必須寫進欄位註解」。08-28 補上的是**為什麼**：有代理商明確希望以千分制計價，所以這兩個代碼是**計價單位**，不是錯誤。

- 幣別是 `VND`／`IDR`，由 `currency_id` 承擔
- 千分制的尺度差由 `vendor_infos.rate` 吸收
- 字串 `KVND`／`KIDR` 留著給人識別，同時送三方

⇒ alias 契約只做「字串 → 標準幣別」的對照，**不是要消滅這些代碼**。

⚠️ ~~**`VND2`／`IDR2` 同樣不刪**。決議三明定變體 id（4／5／7／8）一律不刪列、不加資料庫旗標，改由 `CurrencyEnum` 的錢包幣別白名單排除（Task 8）。~~ ⛔ 09-17 決議㉛ 撤回：四個變體 id 連同 FON／GCASH／PayMaya 一併從主檔清除，白名單排除只是清洗前的過渡。六站實測 `VND2` 零筆、`IDR2` 僅 VWorld 1 筆停用列（`rate = 1.0`，與千分制命名不符），但**零使用只代表「不必遷移」，不代表要刪**。

### 兩份幣別種子資料並存且不同步（順手收斂）

| 來源 | 筆數 | 寫法 | 造成的問題 |
|---|---|---|---|
| `database/data/currencies.php`（由 `SyncCurrenciesSeeder` 讀） | 21 | 以 `code` 為 uniqueKey，**只增不改不刪** | `buildToInsert()` 只在 code 不存在時插入 ⇒ 改資料檔無法修正既有列 |
| `database/seeders/CurrencySeeder.php` | **18**（缺 `BDT`／`TON`／`INR`） | `insertOrIgnore`，**明確指定 id** | 若刪掉主檔某列，下次跑這支會把它建回來 |

⇒ **改了資料檔卻沒改 `CurrencySeeder`，新環境重建時會拿到 18 筆的舊基線。** Task 3 補主檔時順手把它收斂為讀同一份資料檔。（決議三已定變體 id 不刪列，所以這裡不是為了廢除做準備，而是為了「補進去的三個新幣別在兩處都生效」。）

✅ **09-07 已收斂**（見 Task 3 執行結果）：`CurrencySeeder` 改讀同一份資料檔，兩支 seeder 寫入前都經 `GuardsCurrencyMasterData` 比對主檔 id→code、衝突即拋例外。本節保留為背景。

### 六站幣別代碼的全量分佈

以 `CAST(currency AS BINARY)` 分組——**預設 collation 大小寫不敏感，直接 `GROUP BY currency` 會把 `kIDR` 與 `KIDR` 併成一列，看不出問題**。

| 白標 | 分佈（總數／啟用中） | 命名問題 |
|---|---|---|
| VWorld | 19 種代碼、614 筆 | 🔴 `KIDR`(3/0) 與 `kIDR`(3/0) 並存；另有 `IDR2`(1/0)、`KVND`(1/0) |

> ⚠️ **這裡的 19 是區分大小寫的數字**（本表以 `CAST(currency AS BINARY)` 分組）。
> 稽核命令 `currency:audit-vendor-info` 走 SQL 分組、大小寫不敏感，輸出為 **18 種**（`KIDR`／`kIDR` 合併）。
> 兩個數字都對，尺規不同——本期做正規化時看 19（要清點寫法），判斷回填會不會落空時看 18
> （見 [`multi-currency-wallet-phase0-contract.md`](../../multi-currency-wallet-phase0-contract.md) 附錄 A）。
| AlfaWin77 | `IDR`(61/59)、`KIDR`(6/6)、`MYR`(3/3) | `KIDR` 全部啟用中，但寫法本來就標準，不需異動 |
| TPower | `MYR`(116/70)、`HKD`(7/3) | 無 |
| Yes2Win | `MYR`(72/60) | 無 |
| Me77 | `MYR`(192/191) | 無 |
| VPlus33 | `MYR`(136/132) | 無 |

`kIDR` 那 3 筆是 VWorld 的 `100Plus`(id=319)／`Obet33`(id=321)／`DG`(id=326)，同屬 `wintotoidn` 組織、全部停用。

### `VND2`／`KVND`／`IDR2`／`KIDR` 四個 id 目前無人引用（複驗決議三的實測）

決議三記載「實測四個 id 在 12 張業務表皆為空集合，停用零遷移」。08-28 以 VWorld 與 AlfaWin77 複驗其中 8 張：`organizations`、`banks`、`cash_flow_banks`、`standard_banks`、`order_types`、`agent_register_limits`、`agent_member_field_settings`、`agent_cash_flows` ——**四個 id 全部零筆**，有資料的只有 `VND`(3) 與 `IDR`(6)。與決議三一致。

⚠️ **仍有兩張大表未驗**：`orders`（VWorld 816 萬列／4.9 GB）與 `order_histories`（1,358 萬列／6.3 GB）**沒有 `currency_id` 索引**，全量統計等同全表掃描，尚未執行。這不影響「不刪列」的決議（決議三已定），但**任何要動這四個 id 的行為（含改 `code`）之前都必須先補掃這兩張表**。

---

## File Structure

> 下表為第二期原始全貌，✅ 與未標記的 Task 1～8、12、13 項目皆已落地；保留是為了讓 Task 9～11 開工時看得到相鄰檔案的責任分工。

### 會新增的檔案

| 檔案 | 責任 |
|---|---|
| `database/migrations/YYYY_MM_DD_HHMMSS_add_unique_index_to_currencies_code.php` | `currencies.code` 加唯一鍵（2.1／Task 2） |
| `database/migrations/2026_09_07_000002_add_currency_id_to_vendor_infos_table.php` | `vendor_infos` 加 `currency_id` 與索引（2.2／Task 5）✅ |
| `database/migrations/2026_09_07_000003_add_currency_id_to_event_vendor_infos_table.php` | `event_vendor_infos` 加 `currency_id` 與索引（Task 13）✅ |
| `app/Console/Commands/Currency/AuditOrphanParentCommand.php` | `artisan currency:audit-orphan-parent`：會員幣別解析鏈斷點量測與孤兒 parent 線索（Task 12）✅ |
| `app/Enums/AgentMigrateLogTypeEnum.php` | `agent_migrate_logs.type` 的語意（Task 12）✅ |
| `app/Console/Commands/Currency/BackfillVendorInfoCurrencyCommand.php` | `artisan currency:backfill-vendor-info`：依 alias 契約回填 `currency_id`（2.2／Task 6） |
| `app/Console/Commands/Schedule/VerifyCurrencyConsistency.php` | `artisan currency:verify-consistency`：每日稽核不變式（2.3／Task 10） |
| `app/Observers/VendorInfoObserver.php` | `currency` 與 `currency_id` 不一致即拒存（2.3／Task 9） |
| `app/Console/Commands/Currency/NormalizeVendorInfoCurrencyCommand.php` | `artisan currency:normalize-vendor-info`：大小寫變體改寫為標準寫法（Task 11，順手收斂的技術債） |
| `tests/Unit/DB/CurrenciesTableSchemaTest.php` | 守衛：`currencies.php` 灌入後 id→code 與主檔一致、`code` 唯一鍵存在（六站生產基線由 Task 10 稽核排程承擔） |
| `tests/Unit/DB/VendorInfosTableSchemaTest.php` | 守衛：`vendor_infos.currency_id` 欄位、NOT NULL DEFAULT 0、索引（Task 5）✅ |
| `tests/Unit/DB/EventVendorInfosTableSchemaTest.php` | 守衛：`event_vendor_infos.currency_id` 同上（Task 13）✅ |
| `tests/Feature/Console/Commands/Currency/AuditOrphanParentCommandTest.php` | 孤兒 parent 稽核命令的行為測試（Task 12）✅ |
| `tests/Feature/Console/Commands/Currency/NormalizeVendorInfoCurrencyCommandTest.php` | 正規化命令的行為測試（含「啟用中商戶絕不被改」的守衛） |
| `tests/Feature/Console/Commands/Currency/BackfillVendorInfoCurrencyCommandTest.php` | 回填命令的行為測試 |
| `tests/Unit/Observers/VendorInfoObserverTest.php` | guard 的行為測試 |
| `tests/Feature/Console/Commands/Currency/VerifyCurrencyConsistencyTest.php` | 稽核排程的行為測試 |

### 會修改的檔案

| 檔案 | 改什麼 |
|---|---|
| `database/data/currencies.php` | 補 `PHP`(22)／`USD`(23)／`CNY`(24) 三筆（Task 3） |
| `app/Enums/CurrencyEnum.php` | 同步補三個 case ＋ 新增錢包幣別白名單方法（Task 3、Task 8） |
| `app/Models/VendorInfo.php` | 補 `currency_id` property 註解；掛 Observer（Task 5、Task 9） |
| `app/Providers/AppServiceProvider.php`（或既有 Observer 註冊處） | 註冊 `VendorInfoObserver`（Task 9） |
| `database/factories/VendorInfoFactory.php` | 補 `currency_id` 預設值（Task 5）。✅ 該 factory 已存在，不需新建 |
| `database/seeders/CurrencySeeder.php` | 與 `currencies.php` 收斂為單一來源（Task 3，順手收斂的技術債） |
| `routes/console.php` | 掛每日一致性稽核排程（Task 10） |
| `database/data/currency_aliases.php`（第零期產出） | 把 `PHP`／`USD`／`CNY` 從「主檔尚未建立」狀態改為已建立（Task 3） |

### 不在本期範圍

- ⚠️ `vendor_infos.currency` 字串欄位的**值**原則上不動（它進三方 payload，見下節「這個欄位不是純顯示標籤」）。**唯一例外是大小寫正規化**——VWorld 3 筆 `kIDR` 改為 `KIDR`，該 3 筆全部停用、零流量，由 Task 11 處理
- ❌ `KVND`／`KIDR` 這類單位變體代碼的廢除或改名——~~**它們必須保留**（有代理商希望以千分制計價）~~ ⛔ 09-17 決議㉛㉜ 改為：**主檔清除這兩列，字串留在 `vendor_infos.currency` 靠別名契約對到 VND／IDR，千分制改由 `agent_group_currencies.display_unit` 表達**；清除屬主檔清洗工作包，不在本計劃
- ❌ `total_bet_records` 加 `currency_id`（第三期 3.2）
- ❌ `member_wallets` 的欄位與唯一鍵（第一期 1.1）
- ❌ `organizations.currency_id = 0` 的**實際修正**——本期只做**判定與提案**（Task 6），實際改值需業務確認後由 ops 執行
- ❌ 128 張／136 張注單表的幣別來源（第三期 3.1）

---

## 前置依賴：第零期必須先完成 Task 7

本期 Task 6 的回填命令直接讀 `database/data/currency_aliases.php`（第零期 Task 7 的交付）。

✅ **2026-08-28：第零期 Task 7 已完成（commit `24af19945`），這個依賴已解除。** 交付內容為 `currency_aliases.php`（七個分類）＋`tests/Unit/Data/CurrencyContractTest.php`（六個守衛測試）＋`CurrencyEnum` 補齊 `BDT`(19)／`TON`(20)／`INR`(21)。該檔後續又補入六站實測與欄位分工拍板（commit `156385887`），本期各 task 以那一版為準。

---

## Task 9：`currency` 與 `currency_id` 不一致即拒存（2.3）

**依據**：08-27 決議一（幣別權威來源＝`vendor_infos`，字串與編號兩欄必須指向同一種幣別）｜08-27 決議三（兩者外觀可以不一致，但語意必須一致——guard 檢查的正是語意層）

**Files:**
- Create: `app/Observers/VendorInfoObserver.php`
- Modify: `app/Models/VendorInfo.php`（掛 `#[ObservedBy]`）
- Test: `tests/Unit/Observers/VendorInfoObserverTest.php`

**Interfaces:**
- Consumes: `database/data/currency_aliases.php`、`CurrencyEnum`
- Produces: `VendorInfoObserver::saving()`，兩欄不一致時拋 `App\Exceptions\ApiException`。

**慣例來源**：`app/Observers/AgentCashFlowObserver.php` 已用同樣手法（`deleting()` 拋 `ApiException` 擋下有業務足跡的刪除），本 task 比照。Model 端用 `#[ObservedBy]` attribute（`app/Models/Vendor.php` 的慣例）。

🔴 **為什麼要這道防線**：`vendor_infos` 有兩個幣別欄位——字串的 `currency`（進三方 payload）與編號的 `currency_id`（供查詢統計）。後台改了字串卻沒改編號，資料就靜默分岔，而分岔的後果要到報表出數字時才會被發現。

- [ ] **Step 1：寫失敗的測試**

建立 `tests/Unit/Observers/VendorInfoObserverTest.php`：

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Observers;

use App\Exceptions\ApiException;
use App\Models\VendorInfo;
use Tests\TestCase;

/**
 * vendor_infos 幣別兩欄一致性 guard（多幣別錢包第二期／階段 2.3）。
 *
 * php artisan test tests/Unit/Observers/VendorInfoObserverTest.php
 */
class VendorInfoObserverTest extends TestCase
{
    public function test_consistent_pair_is_accepted(): void
    {
        $vendorInfo = VendorInfo::factory()->create(['currency' => 'MYR', 'currency_id' => 1]);

        $this->assertSame(1, $vendorInfo->fresh()->currency_id);
    }

    public function test_zero_currency_id_is_accepted_as_not_yet_backfilled(): void
    {
        $vendorInfo = VendorInfo::factory()->create(['currency' => 'MYR', 'currency_id' => 0]);

        $this->assertSame(0, $vendorInfo->fresh()->currency_id, '0=尚未回填，不可被 guard 擋下');
    }

    public function test_mismatched_pair_is_rejected(): void
    {
        $this->expectException(ApiException::class);

        VendorInfo::factory()->create(['currency' => 'MYR', 'currency_id' => 2]);
    }

    public function test_unit_variant_pairs_with_standard_currency_id(): void
    {
        $vendorInfo = VendorInfo::factory()->create(['currency' => 'KVND', 'currency_id' => 3]);

        $this->assertSame(3, $vendorInfo->fresh()->currency_id, 'KVND 商戶的 currency_id 應為 VND(3)');
    }

    public function test_unit_variant_paired_with_its_own_id_is_rejected(): void
    {
        $this->expectException(ApiException::class);

        VendorInfo::factory()->create(['currency' => 'KVND', 'currency_id' => 5]);
    }
}
```

⚠️ **最後兩個測試是本 task 的核心**：`KVND` 商戶必須配 `VND`(3)，配 `KVND`(5) 要被擋下。這正是簡報第 13 節「要避免的做法」。

- [ ] **Step 2：跑測試確認它失敗**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan test --compact tests/Unit/Observers/VendorInfoObserverTest.php"
```

預期：`test_mismatched_pair_is_rejected` 與 `test_unit_variant_paired_with_its_own_id_is_rejected` 紅（沒有 guard，什麼都存得進去）。

- [ ] **Step 3：寫 Observer**

建立 `app/Observers/VendorInfoObserver.php`：

```php
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\CurrencyEnum;
use App\Exceptions\ApiException;
use App\Models\VendorInfo;

/**
 * vendor_infos 的幣別兩欄一致性防線。
 *
 * 這張表有兩個幣別欄位，語意不同、都不能少：
 * - currency（varchar(5)）：送三方的協議幣別，57 處／29 檔直接進 payload，含單位變體（KVND 等）
 * - currency_id（int）：正規化後的標準幣別編號，供查詢與統計，0=尚未回填
 *
 * 後台改了字串卻沒改編號就會靜默分岔，而分岔要到報表出數字才會被發現。
 * 對照規則的單一來源是 database/data/currency_aliases.php（多幣別錢包 第零期交付）。
 */
class VendorInfoObserver
{
    /**
     * @throws ApiException 兩個幣別欄位對不起來時
     */
    public function saving(VendorInfo $vendorInfo): void
    {
        $currencyId = (int) $vendorInfo->currency_id;

        // 0 = 尚未回填，是合法的中間狀態（回填命令會處理）
        if ($currencyId === 0) {
            return;
        }

        $expected = $this->resolveCurrencyId((string) $vendorInfo->currency);

        if ($expected === null) {
            throw new ApiException(sprintf(
                'VendorInfo currency "%s" cannot be resolved to a standard currency; leave currency_id as 0 until the code is registered in currency_aliases.php. (幣別代碼無法對應標準幣別，登記進 alias 契約前請維持 currency_id=0)',
                $vendorInfo->currency,
            ));
        }

        if ($expected !== $currencyId) {
            throw new ApiException(sprintf(
                'VendorInfo currency mismatch: currency="%s" implies currency_id=%d but %d was given. (幣別字串與編號不一致；單位變體必須指向標準幣別，例如 KVND 應配 VND)',
                $vendorInfo->currency,
                $expected,
                $currencyId,
            ));
        }
    }

    private function resolveCurrencyId(string $raw): ?int
    {
        $aliases = require database_path('data/currency_aliases.php');

        $normalized = $aliases['normalize'][$raw] ?? $raw;
        $resolved = $aliases['unit_variant'][$normalized] ?? $normalized;

        foreach (CurrencyEnum::cases() as $case) {
            if ($case->name === $resolved) {
                return $case->value;
            }
        }

        return null;
    }
}
```

- [ ] **Step 4：掛上 Observer**

`app/Models/VendorInfo.php` 加 import 與 attribute：

```php
use App\Observers\VendorInfoObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
```

並在 `class VendorInfo extends Model` 之前加：

```php
#[ObservedBy(VendorInfoObserver::class)]
```

- [ ] **Step 5：跑測試確認全綠**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan test --compact tests/Unit/Observers/VendorInfoObserverTest.php"
```

預期：5 passed。

- [ ] **Step 6：確認回填命令沒被自己的 guard 擋住**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan test --compact tests/Feature/Console/Commands/Currency/"
```

預期：Task 6 的測試仍全綠。**回填命令用的是 `VendorInfo::query()->update()`（query builder），不觸發 Observer**——這是刻意的：回填寫入的值本來就是依同一份 alias 契約算出來的，再過一次 guard 是多餘的。但若未來改成 Model 逐筆 `save()`，就會經過 guard，屆時要確認邏輯一致。

- [ ] **Step 7：確認既有測試沒被弄壞**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan test --compact"
```

🔴 **這一步是本期風險最高的地方**：`VendorInfo` 是遊戲商整合的核心 Model，任何建立商戶的測試都會經過新 guard。若有測試紅，先判斷是「測試資料本來就不一致（該修測試）」還是「guard 太嚴（該修 guard）」——**不要為了讓測試變綠而放寬 guard**。

- [ ] **Step 8：靜態分析、格式與 Commit**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && composer pint && composer stan"
git add app/Observers/VendorInfoObserver.php app/Models/VendorInfo.php tests/Unit/Observers/
git commit -m "feat(wallet): vendor_infos 幣別兩欄不一致即拒存 target/ticket_tasks#1267"
```

---

## Task 10：每日幣別一致性稽核排程（2.3）

**依據**：08-18 會議決議**⑨**（觀察期最長兩個月，並以**排程每日校驗**新舊資料作為無縫接軌的驗證依據）｜08-27 決議一。本 task 把 guard 擋不住的路徑（query builder、stored procedure、舊系統直寫）交給每日稽核兜底

**Files:**
- Create: `app/Console/Commands/Schedule/VerifyCurrencyConsistency.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Console/Commands/Currency/VerifyCurrencyConsistencyTest.php`

**Interfaces:**
- Produces: `artisan currency:verify-consistency`，掛在每日排程。三項不變式任一破裂即發 BotNotify 告警。

**三項不變式**：

| # | 不變式 | 破裂代表什麼 |
|---|---|---|
| 1 | `currencies` 的 `id → code` 對應與版控 `database/data/currencies.php` 一致 | 有人手動改了主檔，或某站的 seeder 沒跑到 ⇒ 跨站報表會對不上 |
| 2 | `vendor_infos` 沒有 `currency_id = 0` 的**啟用中**商戶 | 有新商戶建立時沒帶幣別編號 ⇒ 第三期的注單幣別會填不出來 |
| 3 | `vendor_infos` 的 `currency` 與 `currency_id` 全部一致 | guard 被繞過（query builder 直寫、SP、舊系統）⇒ 幣別維度靜默錯亂 |

🔴 **第 3 項會抓到 Observer 抓不到的東西**：Observer 只擋 Eloquent 的 `save()`，擋不住 `DB::table()->update()`、stored procedure、以及 API_Agent／RD-Studio-01 的直寫。排程稽核是那些路徑唯一的防線。

- [ ] **Step 1：寫失敗的測試**

建立 `tests/Feature/Console/Commands/Currency/VerifyCurrencyConsistencyTest.php`：

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Currency;

use App\Models\VendorInfo;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * currency:verify-consistency 的行為測試。
 *
 * php artisan test tests/Feature/Console/Commands/Currency/VerifyCurrencyConsistencyTest.php
 */
class VerifyCurrencyConsistencyTest extends TestCase
{
    public function test_active_vendor_info_without_currency_id_is_reported(): void
    {
        VendorInfo::factory()->create(['currency' => 'MYR', 'currency_id' => 0, 'status' => 1]);

        $this->artisan('currency:verify-consistency')
            ->expectsOutputToContain('currency_id=0')
            ->assertExitCode(1);
    }

    public function test_inactive_vendor_info_without_currency_id_is_tolerated(): void
    {
        VendorInfo::factory()->create(['currency' => 'MYR', 'currency_id' => 0, 'status' => 0]);

        $this->artisan('currency:verify-consistency')
            ->assertExitCode(0);
    }

    public function test_mismatch_written_via_query_builder_is_detected(): void
    {
        // 刻意繞過 Observer，模擬 SP／舊系統／query builder 直寫
        $vendorInfo = VendorInfo::factory()->create(['currency' => 'MYR', 'currency_id' => 1, 'status' => 1]);
        DB::table('vendor_infos')->where('id', $vendorInfo->id)->update(['currency_id' => 2]);

        $this->artisan('currency:verify-consistency')
            ->expectsOutputToContain('不一致')
            ->assertExitCode(1);
    }
}
```

- [ ] **Step 2：跑測試確認它失敗**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan test --compact tests/Feature/Console/Commands/Currency/VerifyCurrencyConsistencyTest.php"
```

預期：`The command "currency:verify-consistency" does not exist.`

- [ ] **Step 3：寫實作**

建立 `app/Console/Commands/Schedule/VerifyCurrencyConsistency.php`：

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Enums\CurrencyEnum;
use App\Models\Currency;
use App\Models\VendorInfo;
use App\Services\BotNotifyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyCurrencyConsistency extends Command
{
    protected $signature = 'currency:verify-consistency
                            {--notify : 有問題時發送 BotNotify 告警}';

    protected $description = '每日稽核幣別三項不變式：主檔 id→code 對應、啟用商戶皆已回填、字串與編號一致';

    public function handle(): int
    {
        $problems = array_merge(
            $this->checkSeedParity(),
            $this->checkActiveVendorInfosBackfilled(),
            $this->checkCurrencyPairConsistency(),
        );

        $this->table(
            ['項目', '結果'],
            [
                ['連線', DB::connection()->getName()],
                ['發現問題', (string) count($problems)],
            ],
        );

        foreach ($problems as $problem) {
            $this->warn('  ' . $problem);
        }

        if ($problems === []) {
            $this->info('  幣別三項不變式全部通過');

            return self::SUCCESS;
        }

        if ($this->option('notify')) {
            (new BotNotifyService)->send(sprintf(
                "[幣別一致性稽核][%s] 發現 %d 項問題：\n%s",
                DB::connection()->getName(),
                count($problems),
                implode("\n", array_slice($problems, 0, 20)),
            ));
        }

        return self::FAILURE;
    }

    /** @return list<string> */
    private function checkSeedParity(): array
    {
        $seed = [];
        foreach (require database_path('data/currencies.php') as $row) {
            $seed[(int) $row['id']] = (string) $row['code'];
        }

        $problems = [];

        foreach (Currency::query()->orderBy('id')->get(['id', 'code']) as $currency) {
            $expected = $seed[$currency->id] ?? null;

            if ($expected === null) {
                $problems[] = sprintf('主檔有版控沒有的幣別：id=%d code=%s', $currency->id, $currency->code);

                continue;
            }

            if ($expected !== $currency->code) {
                $problems[] = sprintf('主檔 id=%d 的 code 是 %s，版控是 %s', $currency->id, $currency->code, $expected);
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function checkActiveVendorInfosBackfilled(): array
    {
        $rows = VendorInfo::query()
            ->where('status', 1)
            ->where('currency_id', 0)
            ->get(['id', 'currency']);

        if ($rows->isEmpty()) {
            return [];
        }

        return [sprintf(
            '有 %d 個啟用中商戶的 currency_id=0（未回填）：id=%s',
            $rows->count(),
            $rows->take(10)->pluck('id')->implode(','),
        )];
    }

    /** @return list<string> */
    private function checkCurrencyPairConsistency(): array
    {
        $aliases = require database_path('data/currency_aliases.php');
        $problems = [];

        foreach (VendorInfo::query()->where('currency_id', '!=', 0)->cursor() as $vendorInfo) {
            $normalized = $aliases['normalize'][$vendorInfo->currency] ?? $vendorInfo->currency;
            $resolved = $aliases['unit_variant'][$normalized] ?? $normalized;

            $expected = null;
            foreach (CurrencyEnum::cases() as $case) {
                if ($case->name === $resolved) {
                    $expected = $case->value;

                    break;
                }
            }

            if ($expected !== (int) $vendorInfo->currency_id) {
                $problems[] = sprintf(
                    'vendor_infos id=%d 幣別不一致：currency=%s 應為 currency_id=%s，實際 %d',
                    $vendorInfo->id,
                    $vendorInfo->currency,
                    $expected === null ? '(無法對應)' : (string) $expected,
                    $vendorInfo->currency_id,
                );
            }
        }

        return $problems;
    }
}
```

- [ ] **Step 4：跑測試確認全綠**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan test --compact tests/Feature/Console/Commands/Currency/VerifyCurrencyConsistencyTest.php"
```

預期：3 passed。

- [ ] **Step 5：掛排程**

在 `routes/console.php` 加（放在既有排程之後，比照該檔的既有寫法）：

```php
Schedule::command('currency:verify-consistency --notify')->dailyAt('06:00')->onOneServer();
```

⚠️ **時間點選 06:00**：避開整點的 SBR 排程（`hourlyAt(30)`）與凌晨的彙總 SP，減少讀取時的鎖競爭。**掛上去之前先確認 `routes/console.php` 該時段沒有其他重排程**。

- [ ] **Step 6：六站實跑（唯讀，不加 `--notify`）**

```bash
for env in TPower VWorld Yes2Win Alfawin77 Me77 VPlus33; do
  echo "=== $env ==="
  docker exec -e DB_CONNECTION=$env php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan currency:verify-consistency"
done
```

**預期**：Task 6 的回填實跑完成後，六站應全部通過；若仍有問題，逐項確認是「待裁決的 `HKB`／`MSC`／`BND`」還是真的有新的不一致。

🔴 **順便重跑 TPower 基準站的檢查**：本計劃開頭記錄了「TPower 的 3 個啟用中 HKD 商戶目前零注單，所以觀察期驗收法成立」。**觀察期開始前要再確認一次**：

```sql
SELECT vi.id, vi.vendor_id, v.name,
       (SELECT COUNT(*) FROM total_bet_records tbr WHERE tbr.vendor_id = vi.vendor_id) AS tbr_rows
FROM vendor_infos vi LEFT JOIN vendors v ON v.id = vi.vendor_id
WHERE vi.currency = 'HKD' AND vi.status = 1;
```

`tbr_rows` 全為 0 才能繼續用「TPower 報表數字必須一模一樣」當驗收標準。

- [ ] **Step 7：靜態分析、格式與 Commit**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && composer pint && composer stan && composer lint"
git add app/Console/Commands/Schedule/VerifyCurrencyConsistency.php routes/console.php tests/Feature/Console/Commands/Currency/
git commit -m "feat(wallet): 新增每日幣別一致性稽核排程 target/ticket_tasks#1267"
```

---

## Task 11：`vendor_infos.currency` 大小寫正規化（順手收斂的技術債）

**依據**：決議清單第九節「新發現二：同一站有兩種大小寫寫法」（08-28 執行期查證）｜08-27 決議三（`KVND`／`KIDR` 保留——本 task 只正規化大小寫，不改代碼語意、不做合併）

**Files:**
- Create: `app/Console/Commands/Currency/NormalizeVendorInfoCurrencyCommand.php`
- Test: `tests/Feature/Console/Commands/Currency/NormalizeVendorInfoCurrencyCommandTest.php`

**Interfaces:**
- Consumes: `database/data/currency_aliases.php` 的 `normalize` 分類（第零期 Task 7 交付）
- Produces: `artisan currency:normalize-vendor-info`，供 ops 在六站執行

**要解決什麼**：同一個幣別在同一個站有兩種寫法。VWorld 的 `vendor_infos.currency` 同時存在 `KIDR`(3 筆) 與 `kIDR`(3 筆)，後者是 `100Plus`(id=319)／`Obet33`(id=321)／`DG`(id=326)，**全部停用**。其餘五站沒有大小寫變體。

✅ **09-07 RB 裁決（決議㉑）擴大範圍：本 task 同時補後台輸入端驗證。** `app/Filament/Admin/Resources/VendorManagement/VendorInfoResource.php` 的 `currency` 欄目前只有 `required`（自由文字，契約 `docs/multi-currency-wallet-phase0-contract.md`「後台輸入端」段（原 `phase0-contract.md:584`） 明載第二期要補），改為只接受 `currency_aliases.php` 的 standard∪unit_variant∪crypto∪non_currency 代碼、大小寫精確比對——只清存量不擋入口，新的髒寫法會再進來且無人察覺。步驟骨架待開工時細化（Modify：`VendorInfoResource.php`；Test：Filament 表單驗證測試）。

🔴 **為什麼不能直接下 `UPDATE`**：專案禁止裸寫資料庫，而且這個欄位會直接回傳給三方、還有兩處拿它比對三方傳入值（見「開工前必須知道的事」）。要有 dry-run、要有紀錄、要能被 code review。

🔴 **啟用中的商戶一律不自動改**：本命令只處理 `status = 0` 的列。啟用中的列即使寫法不標準也只列出、不改——改對外 API 參數必須先查該遊戲商的文檔確認它接受哪種寫法。以現況而言這個限制不會擋到任何事（VWorld 那 3 筆全停用），它是防止日後有人在啟用列上誤用這支命令。

- [ ] **Step 1：寫失敗的測試**

`tests/Feature/Console/Commands/Currency/NormalizeVendorInfoCurrencyCommandTest.php`：

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Currency;

use App\Models\VendorInfo;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NormalizeVendorInfoCurrencyCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dry_run_does_not_write(): void
    {
        $vendorInfo = VendorInfo::factory()->create([
            'currency' => 'kIDR',
            'status' => 0,
        ]);

        $this->artisan('currency:normalize-vendor-info')
            ->expectsOutputToContain('未帶 --execute')
            ->assertSuccessful();

        $this->assertSame('kIDR', $vendorInfo->fresh()->currency);
    }

    public function test_execute_normalizes_disabled_rows(): void
    {
        $vendorInfo = VendorInfo::factory()->create([
            'currency' => 'kIDR',
            'status' => 0,
        ]);

        $this->artisan('currency:normalize-vendor-info', ['--execute' => true])
            ->assertSuccessful();

        $this->assertSame('KIDR', $vendorInfo->fresh()->currency);
    }

    public function test_enabled_rows_are_never_touched(): void
    {
        $vendorInfo = VendorInfo::factory()->create([
            'currency' => 'kIDR',
            'status' => 1,
        ]);

        $this->artisan('currency:normalize-vendor-info', ['--execute' => true])
            ->assertSuccessful();

        $this->assertSame(
            'kIDR',
            $vendorInfo->fresh()->currency,
            '啟用中的商戶不可被自動改寫——這個欄位會回傳給三方，'
            . '且 PxplaySwController 與 TTHSWController 會拿它比對三方傳入值。',
        );
    }

    public function test_rows_already_standard_are_not_matched(): void
    {
        $vendorInfo = VendorInfo::factory()->create([
            'currency' => 'KIDR',
            'status' => 0,
        ]);

        $this->artisan('currency:normalize-vendor-info', ['--execute' => true])
            ->assertSuccessful();

        $this->assertSame(
            'KIDR',
            $vendorInfo->fresh()->currency,
            '已經是標準寫法的列不該被列為待改對象。若這裡紅，'
            . '代表比對用了大小寫不敏感的查詢（資料庫預設 collation）。',
        );
    }
}
```

⚠️ **最後那個測試是這個 task 最重要的守衛**：資料庫預設 collation 大小寫不敏感，`where('currency', 'kIDR')` 會連 `KIDR` 一起撈出來，把正確的列也算成待改對象。

- [ ] **Step 2：跑測試確認它失敗**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan test --compact tests/Feature/Console/Commands/Currency/NormalizeVendorInfoCurrencyCommandTest.php"
```

預期：紅，訊息為找不到 `currency:normalize-vendor-info` 命令。

- [ ] **Step 3：寫命令**

`app/Console/Commands/Currency/NormalizeVendorInfoCurrencyCommand.php`：

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands\Currency;

use App\Models\VendorInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizeVendorInfoCurrencyCommand extends Command
{
    protected $signature = 'currency:normalize-vendor-info {--execute : 實際寫入；未帶此旗標時只列出將要變更的內容}';

    protected $description = '將 vendor_infos.currency 的大小寫變體改寫為標準寫法（預設 dry-run，且只處理停用中的商戶）';

    public function handle(): int
    {
        $aliases = require database_path('data/currency_aliases.php');
        $map = $aliases['normalize'];

        if ($map === []) {
            $this->info('alias 契約的 normalize 對照表是空的，沒有要處理的項目。');

            return self::SUCCESS;
        }

        [$targets, $blocked] = $this->collect($map);

        if ($blocked !== []) {
            $this->warn('以下商戶啟用中，寫法雖不標準但不會自動改寫：');
            $this->table(['id', '名稱', '現值', '標準寫法'], $blocked);
            $this->warn('改動啟用中商戶的 currency 等於改對三方的 API 參數，須先查該遊戲商文檔確認。');
        }

        if ($targets === []) {
            $this->info('沒有需要正規化的停用商戶。');

            return self::SUCCESS;
        }

        $this->table(['id', '名稱', '現值', '標準寫法'], $targets);

        if (!$this->option('execute')) {
            $this->info(sprintf('未帶 --execute，以上 %d 筆不會被寫入。', count($targets)));

            return self::SUCCESS;
        }

        DB::transaction(function () use ($targets): void {
            foreach ($targets as $target) {
                VendorInfo::query()
                    ->whereKey($target['id'])
                    ->update(['currency' => $target['標準寫法']]);
            }
        });

        $this->info(sprintf('已正規化 %d 筆。', count($targets)));

        return self::SUCCESS;
    }

    /**
     * 分成「可自動改寫」與「啟用中、只列出」兩組。
     *
     * @param  array<string, string>  $map
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function collect(array $map): array
    {
        $targets = [];
        $blocked = [];

        // 全量取出在 PHP 層比對，不用 where('currency', $raw)：
        // 資料庫預設 collation 大小寫不敏感，'kIDR' 會連 'KIDR' 一起匹配，
        // 把已經正確的列也算進待改清單。vendor_infos 六站皆數百列，全取無壓力。
        foreach (VendorInfo::query()->get(['id', 'name', 'currency', 'status']) as $vendorInfo) {
            $current = (string) $vendorInfo->currency;

            // PHP 陣列鍵的比對是大小寫敏感的，正是這裡需要的語意。
            if (!array_key_exists($current, $map)) {
                continue;
            }

            $row = [
                'id' => $vendorInfo->id,
                '名稱' => $vendorInfo->name,
                '現值' => $current,
                '標準寫法' => $map[$current],
            ];

            if ((int) $vendorInfo->status === 1) {
                $blocked[] = $row;

                continue;
            }

            $targets[] = $row;
        }

        return [$targets, $blocked];
    }
}
```

- [ ] **Step 4：跑測試確認轉綠**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan test --compact tests/Feature/Console/Commands/Currency/NormalizeVendorInfoCurrencyCommandTest.php"
```

預期：4 passed。

- [ ] **Step 5：Dev 環境跑 dry-run**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && php artisan currency:normalize-vendor-info"
```

預期：列出待改清單、明確說「未帶 --execute」、資料未變。

- [ ] **Step 6：六站生產執行屬 ops**

本 task 只交付命令本身。**六站的實際執行需要 RB 排期**，執行順序建議先 VWorld（唯一有變體的站），跑完用唯讀 SQL 複驗：

```sql
SELECT ANY_VALUE(currency) AS code, HEX(ANY_VALUE(currency)) AS hex_bytes, COUNT(*) AS n
FROM vendor_infos
GROUP BY CAST(currency AS BINARY)
ORDER BY 1;
```

預期：`kIDR`（hex `6B494452`）那一組消失，併入 `KIDR`（hex `4B494452`）。

- [ ] **Step 7：靜態分析、格式與 Commit**

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && composer pint && composer stan"
git add app/Console/Commands/Currency/NormalizeVendorInfoCurrencyCommand.php tests/Feature/Console/Commands/Currency/NormalizeVendorInfoCurrencyCommandTest.php
git commit -m "feat(wallet): 新增遊戲商幣別代碼大小寫正規化命令 target/ticket_tasks#1267"
```

---


## 執行完第二期之後

- **第一期 1.2（影子同步）** 的幣別來源就位，可以開始寫真實的 `currency_id`。
- **第三期 3.1（~~126／136 張 BR 表的幣別來源確認~~ 09-03 決議⑭改為「TBR 兩欄的來源規則」）** 可以用 `vendor_infos.currency_id` 當來源——決議四要求「歷史幣別填對不填預設值」，靠的就是 `xxx_accounts` → `vendor_infos.currency_id` 這條路徑；09-03 六站實查所有遊戲商帳號表皆有 `vendor_info_id`，路徑成立。
- 🔴 **本期漏了一張主檔：`event_vendor_infos`（09-03 發現）**。活動用單一錢包（`event_*sw`）的帳號表帶的是 `event_vendor_info_id`，注單寫進 `event_total_bet_records`，主檔只有 `currency` 字串、沒有 `currency_id`。若活動注單也要分幣別，這張要與 `vendor_infos` 同批補欄位與一致性 guard；本計劃各 task 目前零提及，**開工前先補一個 task**。存量：TPower `event_pgssw_accounts` 62.9 萬列、Yes2Win 四張活動帳號表合計約 14.7 萬列。✅ **09-07 RB 拍板（決議⑲）納入＝Task 13，與 Task 5／6 同批，只做技術對齊**（見 Task 13）。
- **仍然卡住的只剩 Gate A**（TBR／SBR schema 待拍板），與本期無關，本期不解除任何閘門。
  ✅ **Gate B（writer 歸屬）已於 2026-08-31 由 RB 拍板解除**，判定依據與第四期開工要帶的風險見
  [`multi-currency-wallet-phase0-contract.md`](../../multi-currency-wallet-phase0-contract.md) 第六節。
