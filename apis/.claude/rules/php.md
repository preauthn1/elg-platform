---
paths:
  - "**/*.php"
---

# PHP 實作慣例與 craft（範例）

> 金流／安全／併發**紅線**常駐於 `CLAUDE.md`（always-on）；本檔放完整範例與 craft，僅在開 `.php` 檔時載入。

## 必守慣例（含範例）

**金額運算**：只走 `Common` 包裝，禁止直接 bc 函式（精度／行為不一致）：

```php
$total   = Common::numAdd($a, $b, 4);   // ✅ 禁止 bcadd($a, $b, 4)
$balance = Common::numSub($wallet, $bet, 4);
if (Common::numCompare($balance, '0', 4) < 0) {
    // 餘額不足
}
```

**外部數值入境正規化**：API JSON decode 或 DB 讀出的浮點數，參與運算／比較前於**進入時**正規化：

```php
$amount = Common::numAdd('0', (string) $response['amount'], 4);  // ✅
// ❌ $amount = (string) $response['amount'];（(string) 1.50000001 仍帶精度誤差）
```

**資料庫交易**：寫入預設包在交易內（不包的例外：單筆 INSERT、佔號流程、三方 API 呼叫，見下方「MySQL 鎖與事務」）；讀出後要改寫、或「該列應已存在」的讀取用 `lockForUpdate()`（何時刻意不鎖見下方「MySQL 鎖與事務」）：

```php
DB::beginTransaction();
try {
    // 必須使用 lockForUpdate() 防止競態
    $order = Order::where('order_no', $no)->lockForUpdate()->first();
    // ...
    DB::commit();
} catch (Throwable $e) {
    DB::rollBack();
    throw $e;
}
```

**HTTP 客戶端**：一律使用 `logAction` macro 以保留詳細請求紀錄：

```php
Http::logAction('ProviderName_deposit')->post($url, $params)->throw()->json();
```

**快取查詢**：高併發共用快取一律使用 `Cache::safeRemember()` 取代 `Cache::remember()`，避免快取擊穿（內含分散式原子鎖，定義於 `app/Providers/MacroProvider.php`）：

```php
Cache::safeRemember($cacheKey, CacheTtl::ONE_HOUR->value, fn () => $this->buildData());
```

**例外**：`throw new ApiException('English message')`（訊息使用英文）。

**日誌**：一律使用 `Common::formatTrace($e)` 記錄 stack trace：

```php
Log::error($this->getDriverName() . ' deposit failed', [
    'order_no' => $order->order_no,
    'error'    => $e->getMessage(),
    'trace'    => Common::formatTrace($e),  // ✅ 禁止使用 Str::limit($e->getTraceAsString(), 500)
]);
```

**敏感資料記錄**（紅線見 `CLAUDE.md`，此為範例與細則）：

```php
Log::info('Deposit callback received', [
    'request' => Common::maskCredentials($request->all()),  // ✅ 禁止直接記 $request->all()
]);
```

`maskCredentials()` 只遮硬 bearer 憑證、保留其餘欄位（如三方簽名用的 `token`）供除錯／驗簽；需連同 token/mobile/email 一起遮的**非 external** 場景改用 `Common::maskData()`。

**回調冪等性**：

```php
$order = $this->findAndLockDepositOrder($orderNo); // 內含 lockForUpdate
$this->checkDepositOrderStatus($order);             // 若已處理則拋出 DepositOrderException(200)
```

## MySQL 鎖與事務

- 🔴 **禁止在事務內呼叫三方 API**：事務能回滾，已經送出去的 HTTP 不能。把兩者綁在一起，中斷時的結果不是「兩邊都沒做」，而是**「三方做了、我方不知道」**——比單純失敗更難收拾，而且事務持有時間被拉長成 HTTP 往返時間，鎖跟著卡住。正確寫法是**先打三方拿到結果，再開短事務寫入**（`MemberTransferService::transferToLocal()`／`transferToVendor()`），或三段式「短事務寫 pending → 事務外送請求 → 短事務結算」（`WithdrawalPayoutService`）。兩個實例：① 2025-12-23 會員轉帳把三方呼叫移出事務（commit `0ffb743a1`）；② 帳號池補建把整商包在一個事務裡，每輪跑到 job `$timeout` 被 SIGALRM 砍掉就整趟回滾，連續 26 天一筆都補不進去、三方卻已經開好帳號（`target/op_tickets#3463`）。
- 🔴 **外層事務會讓內層精心切好的邊界失效**：Laravel 巢狀事務下，內層 `DB::beginTransaction()` 只是建 savepoint、內層 `DB::commit()` 只遞減計數，**真正提交要等最外層**。所以在一個已經把三方呼叫移出事務的 Service 外面再包一層 `DB::transaction()`，等於把三方呼叫又收回事務裡。包事務前先確認被呼叫的方法自己有沒有事務邊界。實例：`TransferMoneyBack`（轉回遊戲商餘額）的逐會員迴圈外包了一層事務，把 `MemberTransferService::transferToLocal()` 2025-12-23 才切好的邊界整個吞掉——連它刻意寫在事務外的交易紀錄與轉丟紀錄都會被回滾（2026-10-07 移除）。
- **單筆 INSERT 不需要事務**：單一語句本身即為原子。「INSERT 佔號 → 打三方 → UPDATE 回填」這種流程**刻意不要**包事務，中間的佔號列是「已經送給三方」的憑據，中斷時留著才有東西對帳（`VendorAccountPoolService::createAccounts()`）。
- **寫完馬上要讀回來的查詢加 `useWritePdo()`**：`'sticky' => true` 只在「同一個程序已經寫過」之後才黏主庫，程序一開始的純讀仍走 replica。跨輪次累進的流水號、水位計數這類「讀到舊值就會重複寫入」的查詢，一律明寫 `useWritePdo()`，不要依賴 sticky。
- 🔴 **RR 隔離級別下對「非唯一索引」下 `FOR UPDATE` ＝ next-key／gap lock**：會鎖住相鄰區間排隊，而且**空集合也會拿 gap lock**（彼此相容）⇒ 併發 INSERT 直接 deadlock。要做會員級互斥，一律鎖 `members` 的主鍵列（record lock、無 gap lock），查詢類（例如查 token）不要加鎖。
- 🔴 **「一般讀」看的是快照、不是現在的資料**：沒加鎖的 `SELECT`（Eloquent 的 `->first()`／`->get()`）在 RR 下叫 consistent nonlocking read，讀到的是**這個交易第一次做一般讀那一刻**的快照——不是交易 `BEGIN` 那一刻。所以「先做一般讀 → 再等鎖 → 拿到鎖後又用一般讀判斷某列存不存在」的寫法，等多久都還是看舊照片。實例：取消請求進交易後先讀了 `$member->agent`（看似無害，實際會查表＝快照就此定住），接著等會員鎖 20～40 秒，拿到鎖後查注單仍是一般讀 ⇒ 讀不到投注剛 commit 的注單 ⇒ 判定「查無此單」回成功、錢沒退。
- ⭐ **`UPDATE`／`DELETE` 與 `FOR UPDATE` 都是 current read（讀最新已提交）**：所以「把某列關掉／改狀態」這種純寫入動作**不必**特地先改鎖定讀，`UPDATE ... WHERE` 本來就看得到別人剛 commit 的內容。反過來，**「讀出值 → 計算 → 寫回」（read-modify-write）一定要鎖定讀重取**，否則拿過期值累加會把別人剛寫入的金額整個蓋掉（遺失更新）。鎖要放在**真正做讀改寫的那個方法裡**，放在外層呼叫端擋不住。
- **要不要鎖，看那一列該不該存在**：注單「應該已存在」的查詢（結算／取消／改單）一律 `lockForUpdate()`；「不該存在」的重複檢查（下注、派彩前的防重）**刻意不鎖**——對不存在的唯一鍵下 `FOR UPDATE` 會拿 gap lock，兩個並發 INSERT 撞同一個 gap 就 deadlock（見本節第一條）。
- **`DB::afterCommit` 的 callback 是裸跑的**：`executeCallbacks()` 沒有 try/catch、沒有重試，**首筆例外會中斷後續所有 callback**。cache 失效這類動作要雙保險——交易內先做一次 fail-safe 的 forget，`afterCommit` 再 forget 一次，兩邊各自 try/catch。
- **「驗 `updated_at` 欄位值」型的測試會被 DDL 的 `ON UPDATE CURRENT_TIMESTAMP` 恆真化**：不管程式有沒有寫那個欄位，DB 都會更新它，斷言永遠會過。要驗就改驗「UPDATE 的 SQL 字串是否含該欄位」。

## 等價重構的驗證

- **「測試綠」不等於等價**：把 config 的重複 entry 群組化（`array_fill_keys()` 之類）時，單元測試往往只驗「跑得完」、不驗 key 是否正確。等價與否一律以改前／改後的 `ksort($d); md5(json_encode($d))` 比對為準，**md5 相同才算等價**；只憑測試綠就宣告等價，實際漏過。
- **整數 key 的 config 只能用 union（`+`）合併，不可用 array spread（`...`）**：`config/vendor.php` 的 driver map 與 `config/summary_bet_record.php` 以 `VendorIdEnum::XXX->value` 為 key，spread 會把整數 key 重新編號（只有 string key 保留），vendor_id 被洗成偽 id ⇒ Driver 解析錯誤、排程補帳對錯 vendor。union 必須寫在陣列字面外（`] + array_fill_keys(...)`）。

## 測試安全紅線（連錯庫防護）

> 測試連上非測試庫時，破壞性操作等於清空真實資料。

- 🔴 **禁止 `use RefreshDatabase;`**：它對 MySQL 測試庫會執行 `migrate:fresh`，**無條件 drop 全部資料表再重建**——比清空單一張表嚴重一個量級。本專案 `Tests\TestCase` 已 `use DatabaseTransactions`，每個測試結束自動回滾，**不需要也不可以再重整資料庫**。誤用的後果：共用測試庫全部資料表被重建、`procedure_hashes` 記帳資料歸零、stored procedure 測試轉紅；CI 上還會依 paratest 的分配變成隨機紅（哪個 process 分到該測試，該 process 的庫就被洗掉）。修復方式：移除該 trait，再跑 `php artisan db:seed --class=SyncProcedureSeeder --env=testing`。
- **清理只能動「本測試自己建立的東西」**：禁止 `truncate()`、無條件 `delete()`、無條件 `DROP`。資料列用 `insertGetId()` 追蹤 id 逐筆刪；schema 只卸 setUp 時不存在的（先記旗標再比對）。無可預知 id 的審計列，用自己建的主鍵反查刪除。
- **DDL 測試視為不可回滾**：MySQL 的 `ALTER` / `TRUNCATE` / `DROP` 會隱式 commit，不只終結當下交易，還把**它之前的 fixture 寫入一併提交**。別想靠 `DatabaseTransactions` 善後，改為手動精準清理。
- **環境名不是防線**：`phpunit.xml` 固定 `APP_ENV=testing`，`app()->environment('testing')` 恆真，擋不住連錯庫。判斷一律看**實際連線目標**。
- **入口四道防線不得繞過或放寬**（`tests/TestCase.php`）：`verifyDatabaseHost()`（host）→ `verifyDeclaredTestingDatabase()`（宣告庫名須 `_test` / `_testing` 結尾）→ `.env.testing` 缺檔 → `assertTestingDatabaseTarget()`（實際 vs 宣告）。全部 `exit(1)`，不留「先跑看看」的空間。
- **新增測試前自檢**：以 `truncate()|TRUNCATE|DROP COLUMN|DROP INDEX|DROP TABLE|RefreshDatabase|migrate:fresh` 掃自己的檔；掃得到，就必須能回答「**連錯庫時會發生什麼**」——答不出來就改寫法。⚠️ 清單含 `RefreshDatabase` 與 `migrate:fresh`，是因為它們透過 trait 間接觸發 DROP，只掃 SQL 關鍵字抓不到。判斷破壞性要看它實際會做什麼，不是看字面有沒有 DROP。

## Migration 紅線（SchemaMonitor）

- 🔴 **`SchemaMonitor` 的保護是「靜默跳過、不拋錯」**（`create` 遇表已存在就 return；`table`／`dropIfExists` 在 production 遇列數超過上限也 return；`config/custom.php` 的 `schema_monitor.row_count_exempt_tables` 豁免名單只有 `table` 會看，`dropIfExists` 不看名單、超限一律跳過），migration 仍會被記為已執行。因此**每個 SchemaMonitor 操作之後必須補一段生效斷言**（例如 `Schema::hasColumn` 檢查，失敗即 throw），否則會出現「migration 顯示已跑、schema 卻沒改」而無人察覺。這是硬規則不是個人習慣；`database/migrations/2026_08_13_000001_*` 等多支 migration 已照做，新 migration 比照。熱表（超過列數上限）加欄位改走手寫 DDL 並明寫 `ALGORITHM=INSTANT`。

參考實作：`tests/Feature/Console/Commands/CashFlow/Bank/GuardActiveBanksCommandTest.php`、`DedupRollbackCommandTest.php`。

## 清理類命令

- 🔴 **清理、銷帳、作廢類命令必須先備份並驗證可還原**：命令本身先落備份（`TableBackupService` 或同等機制），備份失敗就中止，不進清理段。理由：清理是不可逆的正式庫寫入。

## 缺陷修法

- **錯值型缺陷修根因，不用空方法（no-op）或守衛掩蓋**：缺陷成因是某個值寫錯（表名、常數、欄位名）時，修法就是把值改對，不把危險方法改成 no-op、也不加守衛繞過。對「改對」有疑慮，用查證消除（正式環境現況、其他 repo 是否相依）；no-op／守衛只留給正確值真的查不到的情況，並寫明查了什麼、為什麼查不到。理由：no-op 讓錯值留在原地，`up()`／`down()` 永久不對稱，讀者要看註解才懂（`target/tickets#4298`）。

## 靜態分析（`composer stan`）

- **不要用根命名空間的 facade 別名**：`use Log;`／`use Hash;`／`use DB;` 與 `\Log::info()` 這類寫法指向的是 runtime 才由 `AliasLoader` 建立的別名，靜態分析無從解析——會隨 larastan 升級突然冒出一批 `class.notFound`。一律 import facade 本體。
  - **runtime 完全正常**（`class_exists('Log')` 為 true），所以線上不會有異常可循，是純靜態可見性問題。
  - **trait 會被放大**：新版對 trait 在**每個使用它的類別 context 各報一次**，兩行 `\Log::info()` 被 4 個 provider 使用就報成 8 筆。
  - **「stan 沒報」不等於「沒有這個問題」**（`use DB;` 當時就沒被報）⇒ 同型寫法要自己掃，不能拿 stan 清單當範圍：`grep -rn --include='*.php' -E "^use [A-Z][A-Za-z]*;$" app/`（排除 PHP 內建類）。⚠️ **這條 grep 只抓 import 行，會漏掉不帶 import 的裸呼叫**，`\Log::` 要另外掃。
  - ⛔ 在 `phpstan.neon` 的 `bootstrapFiles` 補 larastan bootstrap **不能解決**（實測仍是同樣數字），別再試這條路。
- **`array_filter($arr, 'strlen')` 過不了檢查**：`strlen` 回 `int` 不是 `bool`，會報 `argument.type`。改顯式 closure：`static fn (string $part): bool => trim($part) !== ''`——對 `'0'` 的行為與 `strlen` 相同（都保留），不會踩 `empty('0')` 那類陷阱。解析逗號分隔的命令參數時特別容易寫出來。
- **比對 stan 結果要看檔名、不要看總數**：總數會隨 master 前進漂移，`grep -n '<你的檔名>' <stan 輸出>` 才是可靠判準。

## 命令測試

- **多個 `expectsOutputToContain` 的斷言字串不能落在同一輸出行**：實作是每個 substring 各註冊一個 Mockery expectation，而 **Mockery 對同一行輸出只觸發第一個匹配的**，其餘永遠不被消耗 ⇒ 誤報 `Output does not contain "B"`，即使實際輸出明明有。
  - 判準是**「是否落在同一輸出行」**，不是「字串互相包含」——`$this->table()` 的同一列左右兩欄、含多個標記的總結行都算同一行。解法是拆成兩個測試方法各斷言一格。
  - **報錯位置會指向鏈的最後一個 `expectsOutputToContain`，但訊息本體才寫真正缺的那個字串——看訊息，別看箭頭行號。**
  - 懷疑誤報時先用 `withoutMockingConsoleOutput()` dump 實際輸出，別急著改命令邏輯。
- **一次性資料修補命令與 config 變更，測試不進版控**：「一次性」指跑一次就退役（判準見 `CLAUDE.md`「Agent 行為」的退役判準）。這類改動用 `--dry-run`、跑既有受影響的測試驗證；本次開發期間自己新增、還沒 commit、只用來暫時驗證的測試（鷹架測試）跑完即刪。已 commit 的既有測試不在此列，改完仍要跑綠。理由：這類測試守的行為跑完就不存在，進版控只剩維護負擔。

## 時間相關測試

- **先凍結時鐘，輸入與期望寫字面日期**：測「今日／歷史」這類與時間有關的判斷時，先 `Carbon::setTestNow()` 釘在會暴露問題的時刻（例如兩個時區不同日的 UTC 22:47），輸入與期望一律寫字面 `'YYYY-MM-DD HH:MM:SS'`，不拿受測服務自己的 helper（例如 `timezoneOf()`）算輸入。理由：測試與服務共用同一段換算，兩邊一起錯也會綠（MR !2142）。
  - 當初出事的邊界案例寫成獨立斷言，`assertSame` 帶第三參數訊息；每個案例一行註解說它代表什麼。
  - 寫完做一次「暫時弄壞服務 → 只有邊界斷言變紅 → 還原」的驗證，不只看綠。

## 大量測試變紅時的判別順序

1. **剛 `git pull` 過、而且紅的規模遠大於新功能本身的範圍** ⇒ 先跑 `php artisan migrate:status --env=testing | grep -i pending`，**不要先讀 stack trace**。新表常被 `Member` 這類核心 model 直接查，缺一張表就會讓所有碰到會員的測試一起炸。
2. **`--compact` 尾端露出的檔案會誤導歸因**：真正的錯誤訊息在 trace **最底部**（`SQLSTATE[42S02] ... Table '...' doesn't exist`）。用 `--stop-on-failure` 取單一失敗的完整輸出，比從 `--compact` 尾巴猜可靠。
3. **判斷「是不是自己弄壞的」**：`grep -c '<你的新類別／方法名>' <輸出檔>` 回 0 ＝變更未出現在任何失敗堆疊，這是最有力的單一證據；再用 `grep -oE 'tests/(Feature|Unit|Contract)/[A-Za-z0-9/]+Test\.php' <輸出檔> | sort -u` 看失敗檔是否全落在與變更無關的子系統。
4. **既有的紅色基線數字會漂移，連根因都會換人**——引用任何「既有基線」前先自己跑一次，不要沿用筆記或別人給的數字。

## 代碼風格（craft）

- **Modern PHP**：DTO/Services 使用 `readonly class` 與 Constructor Property Promotion；`match` 取代 `switch`；Nullsafe `?->` 取代冗長 `if (null)`；以 Enum 取代常數。
- **參數與依賴**：參數超過 3 個時強制使用 Named Arguments；Service 優先使用 Dependency Injection (DI)。
- **門檻放 config，固定領域值用 Enum**：會依站點或環境調整的門檻（例如大額通知門檻）放 config，透過 `config()` 讀；狀態碼、類型這類固定領域值封裝成 Enum。`CLAUDE.md` 紅線「禁止魔術數字（封裝 Enum）」指的是後者；兩種都不在程式裡寫字面數字。
- **SR 報表的 class 名帶 SR 代號**：SR 系列報表的 Controller／Service／Request／Resource，class 名本身含全大寫 SR 代號，格式 `SR{號}{變體}{類型}`（例如 `SR11TotalController`、`SR12Service`），不把 URL 全名塞進 class 名，目錄也不重複 SR 編號。既有已遷入的報表同樣適用。理由：舊系統 7000 行的 `ReportController` 無法從類名對回報表。
- **Strict Types**：`declare(strict_types=1);` 由 pint `declare_strict_types` 自動強制（**例外**：SW/TW legacy controller 走 `pint-legacy.json`，不會自動補。要補就逐支補，補之前先把收金額的方法改成接得住實際傳入的型別，否則收字串的金額方法傳入 float 會變 TypeError；不可用帶路徑的 pint 一次補齊，見 `laravel-boost.md`「測試與格式」）。

> 格式類規則（對齊、`concat_space`、import 排序、`fully_qualified_strict_types` 等）由 `composer pint` 強制，不在此重述。

## 覆蓋 vendored skill（`laravel-best-practices`）

- **業務邏輯歸宿是 `app/Services/`**：不採該 skill §15 的 Action class 模式（本專案無 `app/Actions/`，payment／game 整合全走 Service）。
- **`lockForUpdate()` 是 DB 層的鎖**（`SELECT … FOR UPDATE`；何時要鎖、何時刻意不鎖見上方「MySQL 鎖與事務」），與該 skill §4 並列的 `Cache::lock()` 屬不同層次的工具，不可互相取代。
