# 幣別主檔清洗 — 實作計劃

> 產出：2026-09-18｜依據：決議㉛（主檔清洗＋六白標共用 seeder）、㉜（千分制是顯示單位）、㊳（工作順序）｜順序：[`multi-currency-wallet-roadmap.md`](multi-currency-wallet-roadmap.md) 工作包 1。
>
> **本檔現在有三塊**：引用面盤點（第二到四節）、清洗命令要做的事與硬條件（第五節）、裁決與待裁事項（第七節）。決議㉛ 明定第一步必須全量盤點引用面，盤完才設計清洗命令。2026-09-21 經四位審查官會審，結論是「盤點站得住，但執行與回滾的設計還沒寫」，第五節的硬條件就是那次補上的，會審留下的六題 RB 也在同一天裁完（第七節）。其中「分兩次發版」與「不擋三方回調」兩個設計當天另請兩位審查官專審，結論都是有條件通過，條件已寫進第五節，連帶的三題 RB 也裁了（第七節「專審後裁定」）。**清洗命令本身還沒開始寫，要等 PM 簽收清洗範圍。**
>
> **這份盤點怎麼做的**：三線並行，全部唯讀。① 六個白標正式庫（VWorld／TPower／Yes2Win／Alfawin77／Me77／VPlus33）用唯讀 MCP 查；② APIs 本 repo 用 `rg` 掃；③ 舊系統四個 repo（API_Agent／API_Frontend／API_External／RD-Studio-01）用本機副本掃，一白標一分支的 repo 另以 `git grep` 逐分支確認命中。數字全部來自工具返回；標「未驗證」者不得當已驗結論引用。
>
> **由 AI 整理**：資料面查詢與程式面掃描由 AI 執行，A／B／C 影響分級與第六、七節的建議為 AI 判讀，需人工複核。

---

## 一、要清的東西

| 動作 | 代碼（主檔 id） | 為什麼 |
|---|---|---|
| 刪 | `VND2`(4)、`KVND`(5)、`IDR2`(7)、`KIDR`(8) | 單位變體，不是幣別。千分制改由 `agent_group_currencies.display_unit` 表達（決議㉜） |
| 刪 | `GCASH`(12)、`PayMaya`(15) | 付款方式，不是幣別。掛在這兩個 id 上的通道與銀行對回 `PHP`(22) |
| 刪 | `FON`(11) | 金流商 FonPay 的計價單位（見 2.4） |
| 重編到千位數 | `USDT`(10)、`BUSD`(13)、`TON`(20) | 法幣約 178 種佔前段，虛擬幣統一放千位數（決議㉛②） |

影響分級（第四、五節用）：**A**＝清洗後直接壞（查詢回空、語系鍵找不到、舊 id 被插回）；**B**＝資料語意變（歷史快照指向錯幣別、join 落空）；**C**＝字串、註解或三方代碼，不受影響。

## 二、資料面（六站唯讀，2026-09-18）

### 2.1 帶我方幣別 id 的表：六站同構 21 張

`information_schema` 查 `currency_id` 欄位（排除備份表與遊戲商自帶編號的 `ia_*`），六站相同：

`agent_cash_flows`、`agent_member_field_settings`、`agent_register_limits`、`available_banks_align_logs`、`banks`、`cash_flow_banks`、`currency_names`、`event_vendor_infos`、`member_wallets`、`order_histories`、`order_types`、`orders`、`organizations`、`shareholder_api_infos`、`shareholder_currency_rate_logs`、`shareholder_currency_rates`、`standard_banks`、`summary_shareholder_foreign_records`、`vendor_infos`，加兩個 view `v_agent_cash_flow_channels`、`v_cash_flow_channels`。

09-17 盤過前 12 張（見決議清單決議㉛ 與 09-17 實查段），本次補後 9 張。

### 2.2 目標 id 的分佈

| 表 | 目標 id 分佈 | 清洗命令要做的事 |
|---|---|---|
| `orders`（09-17） | USDT：VWorld 1,703／TPower 6,033／Yes2Win 2,278／Alfawin77 10；TON：VWorld 5；其餘 id 零筆 | 重編 10／20。⚠️ 該欄無索引，VWorld 全表掃一次 107 秒，命令要分批並有進度。分批一律走主鍵區間，不走支付通道索引，理由見 5.2「大表怎麼分批」 |
| `order_histories`（本次） | USDT：VWorld 1,634（全表掃 27 秒）、TPower ≥5,043、Yes2Win ≥2,275、Alfawin77 ≥10；TON：VWorld 5；Me77／VPlus33 零 | 同上。TPower 1,818 萬列、該欄無索引，全表掃超過 MCP 逾時。VWorld 的 1,634 是全表掃出來的實數；TPower、Yes2Win、Alfawin77 **三站**的數字是「以目前 USDT 支付通道反查」的**下界** |
| `agent_cash_flows`、`order_types`、`banks`、`cash_flow_banks`（09-17 盤、09-21 重查一致） | 站別順序為 VWorld／TPower／Yes2Win／Alfawin77／Me77／VPlus33。**USDT**：通道 21／4／8／4／4／0，訂單類型 22／8／6／6／4／0，銀行 11／10／4／2／9／2，`cash_flow_banks` 2／2／2／0／0／2。**TON** 只 VWorld：通道 2、訂單類型 1、銀行 1。**BUSD** 只 VWorld：訂單類型 1。**GCASH**：VWorld 通道 1、訂單類型 1、銀行 2；TPower 只有銀行 2。**PayMaya**：VWorld 通道 1、訂單類型 3、銀行 1；TPower 只有銀行 1。**FON**：VWorld 訂單類型 2、銀行 1；TPower 只有銀行 1。FON 的兩條支付通道 `currency_id` 是 NULL，以幣別 id 查是 0 筆，要用通道 id 才找得到（見 2.4） | 重編 10／13／20；GCASH／PayMaya 列對回 PHP(22)；FON 列停用、幣別欄改成該表的「沒設幣別」值（第七節第 2-1 題） |
| `organizations`、`member_wallets`、`agent_member_field_settings`、`agent_register_limits`、`event_vendor_infos`、`available_banks_align_logs`（09-17） | 零筆 | 不動 |
| `vendor_infos.currency_id`（09-17） | 六站對這些字串全為 0（回填未在正式環境跑） | 不動；字串欄 `vendor_infos.currency` 留著（送三方用），靠別名契約對到 VND／IDR |
| `standard_banks`（本次） | 六站各 367 列，幣別只有 1／2／3／6／21 | 不動 |
| `currency_names`（本次） | 六站全空 | 不動；舊系統前台與後台下拉會 join 它，命令說明要提 |
| `shareholder_currency_rates`、`shareholder_currency_rate_logs`、`shareholder_api_infos`、`summary_shareholder_foreign_records`（本次） | 六站全空（TPower `shareholder_api_infos` 1 列、幣別 1） | 不動。⚠️ API_Agent 股東報表查匯率查不到會 `?? 1` 靜默退成 1:1（見 4.3）。匯率表六站現在是空的，所以現況就已經是 1:1，重編不會讓它變得不同；之後若要設 USDT 匯率，要設在新 id 上 |
| 兩個 view（本次） | 只透傳 `order_types.currency_id`／`agent_cash_flows.currency_id`，不 join 主檔 | 不動 |
| `ia_bet_records.currency_id`、`ia_daily_win_losses.currency_id`、`dg_bet_records.currencyId` | 遊戲商自己的編號空間 | **不在範圍** |

### 2.3 備份表

帶 `currency_id` 欄位的 `_bak`／日期後綴表：VWorld 38、TPower 22、Yes2Win 101、Alfawin77 12、Me77 15、VPlus33 15（絕大多數是 `banks_bak_*`，另有 `agent_cash_flows`、`cash_flow_banks`、`order_types`、`orders` 的 clone）。**不遷**；命令說明與工單要寫明「備份表停在舊 id，還原前要先重跑對照」。

### 2.4 FON(11) 查核結果

- 是金流商 **FonPay** 的計價單位：`cash_flows` 35（`D_FON`）／37（`W_FON`）；VWorld 訂單類型 154（`FON-FON`）、155（`FON`）掛在這兩個金流商上；提款銀行 406（停用）；支付通道 106／107 名稱「(FON,USDT) FonPay」掛支付組別 664，`currency_id` 為 **NULL**。
- 訂單：這兩條通道只有 2023-09-08 一筆、幣別 MYR(1)、狀態 3。VWorld `orders.currency_id = 11` 零筆。
- 六站沒有名稱含 `fon` 的組織或代理組別。
- 結論：可刪。⚠️ `fon666` 是 API_Frontend 的一個線上分支（見 4.2），該站若有自己的資料庫，**不在六站 MCP 內、未驗證**。

### 2.5 JSON 欄位

VWorld schema 的 `json` 型欄位集中在 `activity_logs.properties`、`admin_operation_logs.params`、各家 `*_bet_records.infos`、`event_v2_details.*_config`、`agent_member_field_settings.extra_config`、`agent_operate_general_logs.content` 等。資料面沒有全量搜尋（`activity_logs` 按月分表、量大）；改由程式面確認「誰會把幣別 id 寫進去」，結果見 3.1：**APIs 端沒有任何寫入路徑**。

### 2.6 會審後補查的事實（六站唯讀，整庫，2026-09-21）

會審時有四個前提沒人驗過，當天用唯讀查詢補上。六站結果完全一致的，只寫一次。

| 要確認的事 | 結果 |
|---|---|
| 要對回去的 `PHP`(22) 六站在不在 | **六站都在，而且都是啟用**。六站 `currencies` 完全一致：各 24 列、最大 id 24。`BUSD`(13) 六站都是停用，其餘目標 id 都是啟用。`database/data/currency_aliases.php:45-47` 記的「六站皆 21 筆」是 08-28 的數字，已過期 |
| `banks.currency_id` 能不能改成 NULL | **六站都允許 NULL**。`order_types.currency_id` 六站都是 NOT NULL、預設 0。第七節第 2-1 題用的兩個「沒設幣別」值成立 |
| `banks` 上有沒有啟用唯一鍵 `uk_banks_active` | **六站都有**。它建在一個產生欄位上，內容是「啟用時的 支付組別｜類型｜幣別｜銀行碼」，幣別是鍵的一部分。所以改 `banks.currency_id` 等於把該列換到另一個鍵的位置 |
| GCASH／PayMaya 銀行改掛 PHP 會不會撞鍵 | **不會**。VWorld 與 TPower 各有同樣 4 列（id 406／184／404／405），逐列算出改掛後的鍵，兩站都沒有既存的啟用列佔用。會被改掛而且目前是啟用的，全六站只有 VWorld 的 404（GCASH）一列 |
| USDT／TON 的支付通道屬於哪幾家金流商 | USDT：AllPay（金流商 3）、`USDT`（4）、PayEssence 代收（81），另有代付兩家：VWorld 的 `USDT代付`（31，已停用）、Me77 的 AllPay 代付（23）。TON：GffPay 代收（61）與代付（62）。六站都沒有軟刪除的列 |

第五項的意義：會審指出「清洗中途若引用表指向不存在的幣別，直接取幣別代碼的金流商會出錯」。對照 `app/Services/Payment/Providers/` 裡直接寫 `->currency->code` 的檔案，目前掛在 USDT／TON 通道上的這幾家都不在裡面；PayEssence 的代收有判空（`PayEssence/Deposit.php:137`）。所以以現在的通道配置，出錯的面比最壞情況小。但 GCASH／PayMaya 通道是哪幾家沒有查，通道配置也隨時會變，所以不能靠這個運氣。**保護靠的是 5.2 的單一交易**：主檔與配置表同一個交易改完，交易外看不到中間狀態。原稿這裡寫「執行順序要照主檔新列先在來排」，專審後已撤回，理由見 5.2 第 3 點。

補一條出處：`uk_banks_active` 不在本 repo 的 migration 裡（`rg -n 'uk_banks_active' database/migrations` 零命中），是在 repo 之外建的。六站都有是查正式庫得到的事實，但新建的環境不會有這道鍵。

## 三、程式面：APIs 本 repo

### 3.1 JSON／序列化欄位：零寫入路徑

| 查法 | 結果 |
|---|---|
| `rg -n '"currency_id"'` app／tests／database／config／resources／routes | 零命中 |
| 多行掃 `'info'／'params'／'properties'／'data'／'content'／'payload'／'extra_config'／'setting'／'value'… => [ … currency_id` | 零命中 |
| 活動 V2 服務層與建表 migration 的 `currency` | 零命中 |
| `config/activitylog*.php` 追蹤清單 | 零幣別欄位 |
| 32 個 `array`／`AsArrayObject` cast 欄位逐一看 | 皆無幣別 id |

三條「長得像但不是」的路徑，清洗命令要明確排除：

| 表．欄位 | 內容 | 判定 |
|---|---|---|
| `vendor_infos.info`／`event_vendor_infos.info` 的 `CURRENCY` 鍵 | 三方要的幣別參數；IBC(saba) 的值就是數字 `15`（`database/data/currency_aliases.php:27`） | 🔴 **絕不可動**，與 PayMaya=15 無關 |
| `cash_flows.info`／`agent_cash_flows.info`／`agent_cash_flow_order_types.info` | `CURRENCY` 是代碼字串、`available_banks[].BankCode_ELG` 是銀行碼 | 零影響 |
| `orders.extra_content` | `app/Services/OrderService.php:96` 把建單請求的剩餘欄位整包 `json_encode`，無白名單（`app/Http/Requests/Frontend/Deposit/CreateOrderRequest.php:25-32`） | 現行程式零處寫 `currency_id`，也零處讀；**存量未驗證**，由清洗命令的 dry-run 掃目標幣別的存款單補上（第七節第 3 題） |

`command_logs.input` 會留命令列原文（`withdrawal:debug-channels {agent_group_id} {currency_id}` 等），歷史紀錄不解析、不需改。

### 3.2 語系與靜態資料

- `resources/lang/{12 語系}/param.php` 的 `withdrawal_name.{0,1,2,3,6,9,10,11,12,13,14,15,16}`：`rg -n "withdrawal_name" app resources/views` **零命中**，13×12＝156 條全是死鍵，清洗時整組刪。
- `order_type.php`（鍵是訂單類型 id）、`bank_info.php`（鍵是電話國碼）、`database/data/*.php` 其他檔、`config/`：零幣別 id 字面值。唯一例外 `config/custom.php:17` `'currency_id' => env('CURRENCY_ID', 1)`（消費端 `app/Services/OrderTypeService.php:63,116`），各站 `.env` 值**未驗證**。
- 標準銀行種子 `app/Console/Commands/CashFlow/Data/banks/{MYR,SGD,VND,IDR,INR}.php` 由檔名注入幣別、id 走 `Currency::pluck('id','code')` 查表，不受影響。

### 3.3 測試與 factory

指定 pattern `'currency_id' => (目標 id)` 在 tests／factories **零命中**；factory 幣別預設皆 0 或 1。用到目標 case 的測試：

| 檔案:行 | 內容 | 清洗後 |
|---|---|---|
| `tests/Unit/Enums/CurrencyEnumTest.php:19,34,44,71,80,87,98` | 白名單三個方法的測試 | 方法拿掉後整組刪 |
| `tests/Unit/Services/Currency/CurrencyCodeResolverTest.php:95,103,137-146` | `CurrencyEnum::FON->value`、fixture 別名表 | 🔴 刪 case 後 `:95` 是 **fatal（未定義 case）**不是紅燈；語意要改（non_currency 不再帶 id） |
| `tests/Feature/Console/Commands/Currency/AuditVendorInfoCurrencyCommandTest.php:114-124,135,147` | 硬編 `11`、`CurrencyEnum::TON/USDT->value` | 改語意與值 |
| `tests/Feature/Console/Commands/CashFlow/AddAgentCashFlowCcpaymentCredentialsTest.php:32,48,64,98` | `CURRENCY_USDT = 10` | 改新 id |

兩支守衛決定改法：`tests/Unit/Data/CurrencyContractTest.php:26-47` 逐一比對 `database/data/currencies.php` 與 `CurrencyEnum`，**主檔與 Enum 必須同一顆 commit 一起改**；`CurrencyCodeResolverTest` 的 fixture 主檔由 `CurrencyEnum::cases()` 生成。

### 3.4 Enum、解析器與別名契約

- `app/Enums/CurrencyEnum.php`：待刪 case 在 12／13／15／16／19／20／23 行，待重編在 18／21／28 行；`walletAllowed()`（43）、`isWalletAllowed()`（51）在 `app/` 沒有外部呼叫端；`walletExcluded()`（78）是私有方法，只被同檔的 47、55 行呼叫。三個一起拿掉，連同 **34-42、58-77** 行的 docblock。⚠️ 原稿寫「30-42、57-77」是錯的：30-32 行是 `PHP`／`USD`／`CNY` 三個現役的 case，其中 `PHP`(22) 正是 GCASH／PayMaya 要對回去的目標，照原稿行號整段刪會誤刪（`tests/Unit/Data/CurrencyContractTest.php:26-47` 會擋下來）。
- `app/Services/Currency/CurrencyCodeResolver.php:112-128` 以 name 比對 `CurrencyEnum::cases()`：刪 FON／GCASH／PayMaya 後三者 `currencyId` 變 null、分類仍是 NON_CURRENCY（這正是現行測試斷言的相反面）；重編後 68 行用到的 `masterCodesById` 要目標庫主檔已改完才對得上，**否則回填落空成 ENUM_ONLY**。落空的意思是「寫不出值」，不是「寫錯值」：`app/Console/Commands/Currency/BackfillVendorInfoCurrencyCommand.php:215` 只在解析得到 id 時才寫，而且只動 `currency_id = 0` 的列。85-90 行 docblock 要改。
- `app/Services/Payment/Providers/TrustPay/TrustPayTrait.php` 的 `getCurrencyCode()` 用 `CurrencyEnum::tryFrom($currencyId)` 轉幣別名：TrustPay 只做 SGD(2)，清洗名單與它無交集；幣別 id 查不到時已改為擋單、記 log、回 500（09-18）。不改讀主檔 `code`（第七節第 1 題裁決）。
- `database/data/currency_aliases.php`：46-51 行整段寫「變體 id 一律不刪列」與決議㉛相反，要改寫；註解 48、57、79、117、141 行提到舊 id；分類 `unit_variant`（128-131）、`crypto`（142）、`non_currency`（145-148）依決議㉜⑤ 覆核——`KVND`／`KIDR`／`VND2`／`IDR2` 是 `vendor_infos.currency` 的**字串值**，刪主檔 id 不等於刪字串對照，四個 key 保留。假陽性別動：27 行 `info.CURRENCY=15` 是三方數字碼、95 行的 15 是組織 id。
- 兩支命令 signature 說明寫死 `USDT=10`：`app/Console/Commands/CashFlow/SyncCashFlowsCommand.php:92`、`AddAgentCashFlow.php:114`。
- 🔴 **APIs 自己有一組快取會把舊幣別 id 寫回配置表（A 級，會審補盤）**：`app/Console/Commands/CashFlow/SyncCashFlowsCommand.php:2509-2524` 的 `getCurrencyId()` 以幣別代碼查 id，結果用鍵 `currency:id:{代碼}` 快取一小時。它的消費點在同檔 `:484`、`:578`、`:1506`、`:1561`，會寫進 `order_types.currency_id`（`:606`、`:739`）與 `banks`／`cash_flow_banks.currency_id`（`:1015`、`:2149`）。重編後一小時內只要同步類命令跑過一次，USDT 就會以舊 id 10 被寫回去。這跟 4.1 判 RD-Studio-01 為 A 級是同一種問題，只是發生在 APIs 內部。
- **APIs 前台的幣別列表也有一小時快取**：`app/Http/Controllers/Frontend/CurrencyController.php:31`，鍵是 `currency:list:{語系 id}`（`app/CacheKeys/CacheKeys.php:221-224`）。純顯示，不會回寫資料。
- 上面兩組鍵走 Laravel 的快取儲存，實際的鍵前綴是 `config/cache.php` 的快取前綴再疊上 `config/database.php` 的 Redis 前綴，**格式與舊系統的 `{REDIS_PREFIX}:currency:list:*` 不同，用同一個 glob 掃不到**。
- `app/Services/WithdrawalService.php:344` 有一處 `'FON'` 字面值（`$bank->type == 3 && $bank->code == 'FON'`，用來擋 FonPay 內轉）。比的是銀行碼不是幣別，清洗不動 `banks.code`，判 **C**（不受影響）。原盤點漏列，這裡補上。

## 四、程式面：舊系統四個 repo

本機副本狀態（未 fetch、未切分支）：API_Agent `master` 最後 commit 2026-09-07（落後遠端 7 顆）；API_Frontend `vw-master` 2026-07-29，白標分支多停在 07-29～07-31；API_External 停在工作分支 2026-09-09；RD-Studio-01 `master` 2026-06-04（最舊）。**逐分支命中數是這份快照的事實，開工前要重 fetch 複驗**。程式碼都在 `_release/` 底下；只有 API_Frontend 有 `database/migrations/`。

### 4.1 RD-Studio-01

| 命中 | 影響 |
|---|---|
| `_release/app/Enums/CurrencyEnum.php:7-26`：四個 repo 中**唯一的 id→code 對照表**，含全部七個待刪 id 與三個待重編 id；`master`／`develop`／`vplus33` 三分支同一個 blob | **A** |
| 消費點：`AllPay/Starpago/USDT/StarpagoWithdraw.php:34`、`StarpagoEwalletDeposit.php:34`（`CurrencyEnum::USDT`）、`AllPay/GffPay/Ton/GffPayDeposit.php:32`、`GffPayWithdraw.php:32`（`CurrencyEnum::TON`）→ 經 `AbstractInsertCashFlowInfoDeposit.php:184,228,292,361` 寫 `agent_cash_flows.currency_id`／`order_types.currency_id`、`AbstractInsertCashFlowInfoWithdraw.php:193,522` 寫通道與銀行列、`:254` `where('currency_id', $this->currency)` | **A**：重編後這四支命令會把舊 id 10／20 寫回通道與訂單類型。這四支是**手動命令**（開新商戶時由人執行），`_release/app/Console/Kernel.php` 裡沒有排任何金流或幣別類的排程，所以風險出現在「有人手動開新商戶的時候」，不是每天。另一處寫入點 `AbstractInsertCashFlowInfoDeposit.php:431`（`$orderType->currency_id = $parentOrderType->currency_id`）原盤點漏列 |
| `CashTool/UpdateAgentCashFlowCurrency.php:39,50,65-74`：從 DB 即時查 `code→id`，只在 `currency_id` 為空時補寫 | **B**：不會修已指向舊 id 的列 |
| `CashTool/UpdateAgentCashFlowName.php:39,71-72`：用訂單類型與銀行的 `currency_id` 反推幣別代碼，拿去**改寫通道名稱**（會審補盤） | **B**：FON 的訂單類型改 0、銀行改 NULL 後反推不到幣別，通道名會被改成空括號開頭。純顯示，不阻擋 |
| `VgPay/VgPayGetBanks.php:128`、`VgPayInsertCashFlowInfo.php:230`：硬編 `18`（AUD） | **C**（不在名單，但同一種踩法） |
| `GetSabaBetRecord.php:35-`、`GetIaBetRecord.php:104`、`AllPay/base_banks.php` 的 `FGCASH`／`FON`／`GCASH`／`paymaya`、`CashFlowEnum` 的 `*_FON_PAY` | **C**：三方幣別碼、銀行碼、金流商編號 |

語系鍵、快取鍵、migration：無。

### 4.2 API_Frontend（一白標一分支）

| 命中 | 影響 |
|---|---|
| `WithdrawalController::getMobilePaymentBank()` 硬編 `->where('currency_id', 12)`：11 個分支命中（vw-master:1133、vw-develop:1133、tpower-master:1173、tpower-new:1173、tpower-develop:1173、yes2win:1197、vplus33:1173、alfawin77-new:1173、fon666:1173、wintoto:1136、vwb2c:1133）；master／alfawin77／develop 用舊版 `getBankByType()` 無幣別條件 | **A**：清洗把 GCASH 通道對回 PHP 後永遠回空。比照 APIs 改看 PHP(22) |
| 語系鍵 `__('param.withdrawal_name.' . $row->currency_id)`：消費端在 master:1035、vw-master／vw-develop／vwb2c:1011、wintoto:1014、develop:1042、alfawin77:1048-1049（唯一有 fallback）；tpower 系列／yes2win／vplus33／alfawin77-new／fon666 無消費端。鍵定義 12 語系 `resources/lang/*/param.php`，id 集合 `0,1,2,3,6,9,10,11,12,13,14,15,(16)`；TON 從來沒有鍵，4／5／7／8 也沒有 | **A**：`.10`／`.13` 重編後找不到鍵，畫面直接吐鍵名字串；`.11`／`.12`／`.15` 刪 |
| `leftJoin('countries as c', 'c.id', 'b.currency_id')` 11 個分支（vw-master:995、tpower-master:1039、yes2win:1063…）把幣別 id 當國家 id join，`currency_name` 實際是國家名 | **B**（既有缺陷，重編後 USDT 站 join 落空變 null） |
| 動態 join 主檔：`WithdrawalController.php:304`、`CurrencyController.php:47`（含 `currency_names`）、`SystemNotificationController.php:69,127`、`OrderController.php:89` | **B**：自動跟上，但 `orders.currency_id` 是歷史快照 |
| Redis `{REDIS_PREFIX}:currency:list:{LANGUAGE}`（`CurrencyController.php:39-57`，`REDIS.php:9-22`，TTL 3600） | **A**：清洗不會清，最長一小時會員 App 幣種列表仍是舊 id |
| `database/migrations/2025_11_17_100000_insert_currency_20_21.php:12-14,36-43`：TON id=20，存在則 update、**不存在則 insert**；分支 vw-master／tpower-master／vplus33／alfawin77-new／fon666／vwb2c 有這支 | **A**：重編後新站建置或重跑會把 TON 以 20 插回，且撞 APIs 的 `code` 唯一鍵 |
| `2025_08_15_100000_insert_currency_16171819.php`（存在則 skip）、`2019_02_21_034631_create_currencies_table.php`（`increments`）、`2019_04_10_064137_create_currency_names_table.php:19` | **C**／**B**（`currency_names` 要同步） |
| `BankController.php:203`、`DepositController.php:138,174`、`OrderController.php:366` 的 `currency_id => 1` | **C** |
| `TgBotPaymentController.php:458` `'GCASHP2P'`、`WithdrawalController.php:660` `code == 'FON'` | **C**（銀行碼） |

### 4.3 API_Agent

- 沒有幣別常數表、沒有語系鍵、沒有 migrations。字面 id 只有 `DepositController.php:373` `'currency_id' => 1`（12 分支同），**零硬編目標 id**。
- 動態 join 主檔（**B**）：`BackendNoticeService.php:26,78`、`BankController.php:55,190,311,878`、`AgentController.php:2903`、`MemberController.php:2421`、`WithdrawalController.php:940-941`、`AgentOnlineDepositController.php:65,66`、`DepositController.php:58`、`CurrencyController.php:52-56`、`Shareholder/V2/ShareholderController.php:94,133`。報表與訂單類型列表也在這一類（會審補盤，都是 leftJoin，會自動跟上）：`Report2Controller.php:389,483`（存款與提款報表）、`OrderTypeController.php:102-104`。
- ⚠️ **兩處 inner join，幣別列不在就整筆靜默消失，沒有錯誤也沒有告警**：
  - `WithdrawalController.php:921` 對 `organizations.currency_id`。六站組織沒有任何目標 id，現況安全。
  - `DepositController.php:635` 對 `order_types.currency_id`（代理後台代客存款的訂單類型清單）。原盤點把它歸在上面的「動態 join」，低估了。對清洗的影響有兩點：GCASH／PayMaya 的訂單類型對回 PHP(22) 後，要 PHP(22) 那一列存在才看得到（2.6 已確認六站都在）；FON 的訂單類型幣別欄改 0 之後，會從這個清單消失（它們同時被停用，所以符合預期）。
- 股東系列（**B**）：`Shareholder/V3/ShareholderController.php:116-118,1131-1133` 查 `shareholder_currency_rates` 查不到 **`?? 1` 靜默退成 1:1**；`:122-130,237-250` 寫 `summary_shareholder_foreign_records.currency_id`（fallback 1）；`ExchangeRateController.php:18-19,52-53,106-140` 讀寫匯率與流水。這兩張表的 migration 在 APIs（`2026_08_18_000001`、`2026_08_18_000006`）、資料由 API_Agent 寫。
- Redis（**A**）：`CurrencyController.php:45-49,72` `currency:list:{LANGUAGE}`；`:132-136` `currency:show:{id}`——**鍵本身就是主檔 id**，舊 id 的鍵會殘留。清快取只有後台 create／update／delete 三個入口（`:239-243,344-348,414-418`，`REDIS::delete` 用 `keys('*:currency*')` 整批掃）。
- ⚠️ 後台「刪幣別」`CurrencyController::delete()`（`:390-434`）只把 `status` 改 0，不 DELETE 列。清洗命令不走這條路，七列由命令真刪（第七節第 2 題裁決）。
- `Models/Currency.php:20-25` 的 `unique:currencies,code` 與 `CurrencyController.php:293-295` 的 ignore 子句用 id，低風險備查。
- `SurePayIdrIntegrationHelper.php:213-228` 的 `$currency_id`（1＝INR、2＝IDR）是 SurePay 三方碼；`FeiBaoPayIntegrationHelper.php:186-190` 比對銀行碼 → **C**。

### 4.4 API_External

全 repo 程式碼只有一處：`Shareholder/V2/ShareholderController.php:76` `leftjoin('currencies as c', 'c.id', 'o.currency_id')`（`o`＝`organizations`），11 個分支行號一致 → **B**。`:88` 取 `$currenciesResult->code` 沒判 null。原稿寫「基底幣別被刪會 fatal」，說重了：這裡是 leftjoin，幣別列不在只會讓代碼變成 NULL、不會少一列；真的取到 null 時，結果是該 API 回一個錯誤 JSON，而且可能把 PHP 的警告原文回給外部呼叫端，屬於對外介面被破壞，不是整頁掛掉。現況組織零目標 id，不會觸發。其餘 `FonPayController`、`routes/api.php:460-461`、`CashFlowEnum` 皆金流商 → **C**。

## 五、清洗命令必須一併處理的清單

### 5.1 清洗前先出的程式 MR（A 級）

| # | repo | 位置 | 動作 |
|---|---|---|---|
| 1 | RD-Studio-01 | `_release/app/Enums/CurrencyEnum.php:10,11,13,14,17,18,21` ／ `:16,19,26` | 刪七個常數；USDT／BUSD／TON 改新值（三分支同 blob）。這支 Enum 本來就停在 TON(20)，缺 21～24，動它時順手補齊。⚠️ **三個虛擬貨幣的改值不能在六站清洗期間發**：這裡是普通類別常數，一個名字只能有一個值，先改或後改都會讓其中一邊的站寫出主檔裡不存在的幣別 id。改值排在六站都清完之後，與第 5 項的第二版同批發；清洗期間改用禁跑來擋（見 5.3） |
| 2 | API_Frontend | 11 個分支 `WithdrawalController::getMobilePaymentBank()`（分支清單見 4.2 第一列） | `currency_id = 12` 改 PHP(22)，與 APIs `05e3a4d30` 同理由 |
| 3 | API_Frontend | 12 語系的 `resources/lang/*/param.php`，**分支範圍是所有有這組鍵的分支，不是第 2 項那 11 個** | **新舊鍵並存**：清洗前先加上新 id 的鍵，`.10`／`.13` 先留著；六站都清完、觀察期過了，再刪 `.10`／`.13` 與 `.11`／`.12`／`.15`。原稿寫「改鍵到新 id」，專審指出兩個方向都會壞：清洗前改，還沒清的站提款頁會顯示鍵名字串；六站清完才改，已清的站整段期間都顯示鍵名字串。消費端 `WithdrawalController.php:1011` 直接拿 `banks.currency_id` 組鍵、沒有備援值，而 USDT 的銀行列六站都有，所以這條路是活的。語系檔是純資料，兩個鍵並存沒有衝突。⚠️ 原稿寫「× 11 分支」是錯的：那 11 個分支是第 2 項的清單，不含 `master`、`alfawin77`、`develop`，而 4.2 列出會讀這組語系鍵的分支正好包含這三個。照原稿開 MR 會漏改它們，清洗後會員提款頁直接顯示鍵名字串。開工前要重新逐分支確認哪些分支有這組鍵 |
| 4 | API_Frontend | 6 個分支 `2025_11_17_100000_insert_currency_20_21.php` | TON 那段加判斷：找不到就跳過、不插入。不要改成整段不做事，否則新站建置會缺 TON 那一列。這支同時也寫 INR(21)，改的時候別動到那一段 |
| 5 | APIs | `database/data/currencies.php`（刪 27-32、33-38、45-50、51-56、69-74、75-80、93-98 七個 block；改 64、82、124）＋ `app/Enums/CurrencyEnum.php`（刪 case、改值、拿掉三個方法與 docblock） | **資料檔與 Enum 同一顆 commit**（`CurrencyContractTest` 硬綁）。依第七節會審後裁定第 1 題，這一項要拆成兩次發版：第一次把十個項目全部拿掉，六站清完後第二次把三個虛擬貨幣以新 id 加回（見 5.3）。第一版要單獨發，不跟其他功能綁在一起（它是已清洗站的回滾地板，見 5.4）。第二版的資料檔要替這三列明寫 `status`：資料檔現在沒有這一欄，seeder 會補成啟用，而 `BUSD` 六站現況是停用，新建的環境會被種錯 |
| 6 | APIs | `database/data/currency_aliases.php` 46-51、48、57、79、117、141 行；分類 128-131、142、145-148 | 改寫與決議㉛ 相反的段落；四個 `unit_variant` key 保留 |
| 7 | APIs | `tests/Unit/Enums/CurrencyEnumTest.php`、`CurrencyCodeResolverTest.php:95,103,137-146`、`AuditVendorInfoCurrencyCommandTest.php:114-124,135,147`、`AddAgentCashFlowCcpaymentCredentialsTest.php:32,48,64,98` | 刪／改語意／改值 |
| 8 | APIs | `resources/lang/{12 語系}/param.php` 的 `withdrawal_name.*` 13 條 | 整組刪（零消費端） |
| 9 | APIs | `SyncCashFlowsCommand.php:92`、`AddAgentCashFlow.php:114` 的說明文字；`CurrencyCodeResolver.php:85-90` docblock | 改 |
| 10 | APIs | `TrustPayTrait.php` 的 `getCurrencyCode()` | 不改（第七節第 1 題裁決）。清洗驗收時盤一次 TrustPay 的通道／訂單類型／銀行，確認 `currency_id` 只有 2 |
| 11 | APIs | `SyncCashFlowsCommand.php:2509-2524` 的幣別 id 快取（見 3.4） | 不必改程式，但清洗收尾要清這組鍵，而且清洗期間同步類命令禁跑（見 5.3） |
| 12 | APIs | 清洗命令、配套的回滾命令，以及兩支命令的測試 | 新寫。測試至少涵蓋：備份失敗即中止、交易失敗整批回退不留半完成狀態、dry-run 不寫入。dry-run 與實跑要共用同一條判斷，寫法參考 `app/Console/Commands/Database/PruneTableBackupsCommand.php:52-60` |
| 13 | APIs | 審查官建議順手改的兩處，**RB 2026-09-22 裁定都做，跟本表其他 APIs 項目同一個 MR 發**：`SyncCashFlowsCommand.php:2509-2524` 的 `getCurrencyId()` 查不到幣別代碼時回 0，而且會把 0 快取一小時；`PayEssence/Withdrawal.php:123-130` 的 `getCurrencyTypeId()` 直接讀 `->currency->code` 沒判空（同目錄 `Deposit.php:133-139` 有） | 前者改成**查不到就不快取**，其餘不動：只快取查得到的 id，查不到照舊回 0。沒選審查官原建議的「查不到就拋錯」，因為四個呼叫端裡 `:578`、`:1561` 已經是「印警告並略過」，`:484`、`:1506` 拿 0 去比對目標幣別也是略過，改成拋錯會讓資料檔裡任何一個寫錯的代碼把整支同步擋下來，屬於同步命令的行為變更。後者補判空、錯訊寫明「通道沒設幣別」，行為不變（現況已經會擋單，只是錯訊是空字串加一條 PHP 警告） |

### 5.2 清洗命令的資料動作（每站）

**會寫入的資料表總表**。清洗命令每站會寫入下面 8 張表，其餘的表一律不碰。各表各站的列數以 2.2 為準，這裡不重複列，避免兩處數字對不上。

| # | 資料表 | 會做的動作 | 哪幾站有資料 |
|---|---|---|---|
| 1 | `currencies`（幣別主檔） | 真刪七列；USDT／BUSD／TON 三列就地把 id 改成千位數的新值，其他欄位不動 | 六站 |
| 2 | `currency_names`（幣別的多語系名稱） | 要刪的七個幣別若有對應列，一併刪；要重編的三個若有對應列，改成新 id | 六站目前全空，實際不會動到 |
| 3 | `agent_cash_flows`（支付通道） | USDT／TON 重編；GCASH／PayMaya 對回 PHP(22)；FON 的兩條通道 106／107 停用 | USDT：VWorld、TPower、Yes2Win、Alfawin77、Me77；TON、GCASH、PayMaya、FON 通道：只有 VWorld |
| 4 | `order_types`（訂單類型） | USDT／BUSD／TON 重編；GCASH／PayMaya 對回 PHP(22)；FON 列停用、`currency_id` 改 0 | USDT：同上五站；TON、BUSD、GCASH、PayMaya、FON：只有 VWorld |
| 5 | `banks`（銀行） | USDT／TON 重編；GCASH／PayMaya 對回 PHP(22)；FON 列停用、`currency_id` 改 NULL | USDT：六站；TON：VWorld；GCASH、PayMaya、FON：VWorld 與 TPower |
| 6 | `cash_flow_banks`（金流商提供的銀行） | USDT 重編 | VWorld、TPower、Yes2Win、VPlus33 |
| 7 | `orders`（存款單） | USDT／TON 重編，走主鍵區間分批 | USDT：VWorld、TPower、Yes2Win、Alfawin77；TON：VWorld |
| 8 | `order_histories`（存款單的歷程紀錄） | 同上，走主鍵區間分批 | 同 `orders`；TPower、Yes2Win、Alfawin77 三站的 USDT 列數目前只有下界 |

連帶會產生或清除、但不算在上面 8 張裡的東西：

- **備份表**：每張要動的表先建 `<表>_bak_<時間>`。主檔與四張配置表整表備份，`orders`／`order_histories` 只備會被改到的列。「會被改到的列」要用跟更新同一套主鍵區間條件去選，否則備份漏掉的正好就是更新漏掉的那批。
- **快取**：舊系統的 `currency:list:*`、`currency:show:*` 兩組鍵（API_Agent 與 API_Frontend 各一套），加上 APIs 自己的 `currency:id:*` 與 `currency:list:*`（見 3.4）。兩邊的鍵前綴格式不同，要分開處理。
- **CCPayment 的通道與訂單類型列**：清洗當下若已建立就一併重編（出處是工作順序總表 `multi-currency-wallet-roadmap.md` 工作包 1 的「含 CCPayment 新列」）。2026-09-21 重查，六站都還沒有掛 USDT 的 CCPayment 通道。⚠️ 該站若已掛上，要多一道處理：CCPayment 的代收回調會自己建存款單（見 5.3「清洗期間要暫停的東西」）。

**哪些表用 Eloquent、哪些用 query builder**（專審補上，RB 已裁，見第七節專審後裁定第 1 題）：

- **`orders` 與 `order_histories` 一律走 query builder**。`Order` 同時掛了 `OrderObserver` 與 `ActivityObserver`（`app/Providers/ActivityLogServiceProvider.php:20`），用 Eloquent 會替每一列寫一筆 `activity_logs` 並派工作進佇列，約一萬列，全部不在上面 8 張表內。
- **停用 FON 通道 106 走 Eloquent**，讓通道的 observer 照常生效。這個 observer（`app/Observers/AgentCashFlowObserver.php:50-63,211-245`）在通道從啟用變停用時，會把旗下啟用中的「通道與訂單類型關聯」（`agent_cash_flow_order_types`）一起關掉，並寫一筆審計紀錄（`acf_cascade_deactivate_logs`）。這兩張表不在 8 張表內，也不在備份範圍。
  - 2026-09-21 查 VWorld 正式庫：106 旗下 4 筆關聯全部已停用，observer 查到 0 筆就直接返回，不會寫到那兩張表。
  - **dry-run 必須印出 106 旗下啟用中的關聯筆數。正式執行時若不是 0，命令中止、交人判斷**，不自動連鎖。這樣「其餘的表一律不碰」仍然成立。
  - 107 現在就是「通道停用、旗下 1 筆關聯仍啟用」的狀態，是既有問題，與清洗無關，清洗不動它。

確認不動的表見 2.2（零引用的）與第六節（刻意排除的）。

⚠️ **FON 的兩條支付通道要另外處理**：VWorld 的 106／107 在 `agent_cash_flows` 的 `currency_id` 本來就是 NULL（見 2.4），用幣別 id 篩不到它們。RB 裁決一併停用（第七節第 2-2 題），命令要用通道 id 明確指定這兩列。

這兩條通道的現況（2026-09-21 快照）：

| 通道 | 狀態 | 類型 | 金流商 | 名下單據 |
|---|---|---|---|---|
| 106 | **啟用**（`status = 1`） | 代收 | 35（`D_FON`） | 存款單 1 筆，是成功單，2023-09-08 11:02:25（UTC）；之後沒有任何存款單 |
| 107 | **已停用**（`status = 0`） | 代付 | 37（`W_FON`） | 存款單 0 筆、代付記錄 0 筆 |

- 所以實際要改狀態的只有 106。對 107，命令只確認它已是停用，不重複寫入。
- 兩條都掛在支付組別 664，整組近 90 天沒有任何成功單。兩條都在 2023-08-25 建立，最後一次更新都是 2026-08-04 07:20:02（UTC）同一秒；是誰改的、改了什麼，沒有查。
- **資料來源與限制**：這不是本計劃自己查的，是銀行收斂那條線（worktree `APIs-payment-bank-convergence`）做六站停用通道盤點時，對 VWorld 正式從庫的唯讀查詢，本計劃這邊沒有重查。107 的「代付記錄 0 筆」不是直接查的：`withdrawal_payout_records` 在 `agent_cash_flow_id` 上沒有索引，依據是當天兩次整表分組（合起來涵蓋整張表）的結果裡都沒有出現 106、107。
- 這是單日快照，dry-run 報告仍要再列一次現況。
- 分工：這兩條通道由多幣別清洗處理，停用通道盤點那條線不列進自己的處置清單。

**執行步驟與硬條件**

> 原稿的七步是「先改引用表、最後才動主檔」。2026-09-21 會審，兩位審查官各自獨立指出這個順序是反的，這一段整段重寫。原稿的問題有三個：順序會開出一段引用表指向不存在幣別的空窗；沒寫交易邊界；大表分批走支付通道索引會漏列。下面每一條都是清洗命令必須遵守的硬條件。

**第一段：主檔與四張配置表，一個交易做完**

1. **備份**：所有要動的表先備份並驗證可還原，備份失敗即中止（RB 09-18 硬規則）。還原怎麼做見 5.4，**「驗證可還原」指的是 5.4 的回滾命令實際跑過**（先在 Dev 演練，見 5.3），不是備份表建出來就算。
   - **備份必須在交易外面、交易開始之前做完，絕不可挪進第 3 步的交易裡**。備份服務用的是「建表同時複製資料」的語句，在 MySQL 屬於會自動提交的結構變更：它會把當下交易之前的寫入一併提交，等於把單一交易的保護拆掉。
2. **動手前先存兩份清單**：目前掛目標幣別的支付通道 id 清單、舊 id 對新 id 的對照。通道一旦被改，就再也查不出「哪些通道原本是 USDT」。兩份清單與備份批次的時間戳都寫進一張資料庫表，不能只印出來，也不能只放在備份服務的對照檔裡（原因見 5.4）。
3. **主檔、`currency_names`、`agent_cash_flows`、`order_types`、`banks`、`cash_flow_banks` 包在同一個交易**，失敗就整批回退，不留半完成狀態。三個虛擬貨幣的主檔列**就地改 id**：一句 `UPDATE currencies SET id = 新值, updated_at = updated_at WHERE id = 舊值`，其他欄位不動（RB 已裁，見第七節專審後裁定第 3 題）。
   - 原稿要求「主檔的新 id 列要先在，引用表才搬過去，舊列最後才消失」，專審後撤回。兩位審查官各自獨立指出：在單一交易裡，交易外的讀者只會看到交易前或交易後，看不到中間，所以這個順序對外沒有任何作用；而要讓新列先在，就得讓同一個代碼同時掛在兩個 id 上，會被代碼的唯一鍵（`currencies_code_unique`）擋死。
   - 就地改 id 為什麼可行：沒有任何外鍵指向 `currencies`。migration 裡零命中；2026-09-21 查 VWorld 正式庫的 `information_schema` 也確認沒有，`currencies` 上除了主鍵只有代碼唯一鍵這一道。其餘五站沒有查，dry-run 要補（5.5）。引用表不會自動跟著變，`currency_names` 與四張配置表都要命令自己改。
   - 就地改 id 的好處：狀態、名稱、匯率、建立時間自動跟著走，不必逐欄承接，`BUSD` 六站現在是停用，不會因為重編被順手啟用；回滾也變成反向一句更新（見 5.4）。
   - `currencies` 的 `updated_at` 同樣設了「有更新就自動寫成現在」（`database/migrations/2025_06_19_091321_create_currencies_table.php:22`），所以更新語句要明寫維持原值。
   - 為什麼一定要同一個交易：半完成狀態（七列已刪、三列還沒重編）下，寫入前的一致性檢查不會拋錯（`database/seeders/Concerns/GuardsCurrencyMasterData.php:49-56` 只在 id 或代碼「已存在」時才判衝突），下一次部署的 seeder 會照資料檔把七列以原 id 插回來，全程沒有錯誤也沒有告警。
   - 半完成狀態還有第二個後果：引用表指向不存在的幣別時，直接取幣別代碼的金流商會出錯。`app/Services/Payment/Providers/` 底下 TakaPay、VaderPay、FPay、B3Wallet、MaxPay、Kira 的存款與 PayEssence 的代付是直接報錯；`MaxPay/Withdrawal.php:39` 與 `Kira/Withdrawal.php:73` 寫的是 `?? ''`，**不報錯，帶著空幣別把代付請求送出去**。以 2026-09-21 的通道配置，這幾家都沒有掛在 USDT／TON 通道上（見 2.6），但不能靠這個運氣。
   - **這個設計有一個明示前提：這五張表（`currencies`、`agent_cash_flows`、`order_types`、`banks`、`cash_flow_banks`）在 `app/` 裡沒有任何加鎖的讀取**（專審對 `lockForUpdate` 與 `sharedLock` 全域搜尋，這五張表零命中）。這是第一段的長交易不會卡住回調、也不會死鎖的原因。日後有人在這五張表加一把鎖，這個設計就要重看。
   - `banks` 的 `status` 與 `currency_id` 要在**同一句更新**裡改。`uk_banks_active` 把幣別算進鍵裡，分兩句改會讓該列短暫落到另一個鍵的位置。
4. 這一段的內容：USDT／BUSD／TON 重編；GCASH／PayMaya 的通道、訂單類型、銀行列對回 PHP(22)；FON 的訂單類型與銀行列停用、幣別欄改 0 與 NULL（第七節第 2-1 題）；FON 的支付通道用通道 id 指定停用（第七節第 2-2 題，依 2026-09-21 快照實際只改 106）；真刪主檔七列（第七節第 2 題）；CCPayment 若已建立通道與訂單類型列，一併搬。

**兩段之間的檢查點**（第七節會審後裁定第 2 題）：同一站、同一次維護窗口內做完兩段。第一段 commit 之後先驗證（主檔與配置表的目標 id 殘留為零、`PHP`(22) 還在、通道與銀行的啟用狀態符合預期），通過才跑第二段。第二段失敗只重跑第二段，不回滾第一段。

**第二段：`orders` 與 `order_histories`，主檔一致之後才做**

5. **大表怎麼分批**：走主鍵區間，條件是「`id` 在某個區間內，而且 `currency_id` 是目標 id」，批寬固定，上界取第二段開始時查到的最大 id。每批自己一個交易，批與批之間留間隔讓從庫追上。
   - **每批拆成兩步，先撈 id、再更新**：先用不加鎖的查詢撈出這個區間裡目標列的 id，再用「`id` 在這份清單裡」去更新。直接用「區間加幣別」當更新條件的話，MySQL 在預設的隔離級別下會把區間內掃過的列全部鎖住，不只目標列；那一瞬間區間內任何幣別的存款回調都要等。拆成兩步就只鎖真正要改的那幾列。
   - **更新語句除了 id 清單，還要再帶「`currency_id` 等於舊 id」這個條件**（專審補上）。撈與改之間清單可能過期（重跑、部分失敗後重試、人工介入）；沒有這個條件，一份算給 USDT 的清單被誤用在 TON 那一輪，就是無聲地寫錯幣別。加它不會擴大鎖的範圍，因為 id 清單已經把掃描限制在那幾列。
   - **每批比對「實際更新的列數」與「id 清單的長度」，不相等就中止該批並報差異**，不往下跑。
   - 批寬與 id 清單的長度要明訂上限（審查官建議 2,000 以內），dry-run 印出實際最大一批的清單長度。
   - 這兩張表一律走 query builder，不走 Eloquent（理由見上面「哪些表用 Eloquent」）。
   - **收尾再掃一次上界以上的區間**：第一段 commit 的前一刻，可能有存款單已經讀到舊幣別、還沒寫進資料庫，它的 id 會落在上界之後。
   - **`order_histories` 是誰寫的（2026-09-21 六站唯讀查清）**：專審查到 APIs 的 `app/` 裡沒有任何程式寫這張表，當天追下去，寫入者不在任何一個 repo 裡，是**資料庫自己的每日排程**。
     - 六站都有一個資料庫排程事件 `Event_Copy_Orders_To_History`，每天 20:00（UTC，台北 04:00）呼叫預存程序 `ADMIN_copy_orders_to_history`。這支預存程序只存在於正式庫，APIs 的 `database/procedures/` 與四個舊 repo 都沒有它的副本，也沒有任何程式呼叫它。
     - 它做的事：把 `orders` 裡建立超過兩週的列**原樣複製**進 `order_histories`，主鍵沿用存款單的 id，`currency_id` 直接抄存款單上的值。六站 `order_histories` 最後一筆的建立時間都落在兩週前的邊界上，可見它每天都在跑。
     - 所以**補掃一次就夠，前提是第二段不要跨過 20:00（UTC）**。第二段先做 `orders` 再做 `order_histories`：清洗之後它再複製進來的列，抄的是已經改好的存款單，帶的是新 id。唯一的漏洞是它在第二段進行當中跑：它複製了一批還沒改到的存款單，而 `order_histories` 那一輪的上界已經取完。把 20:00（UTC）列進要避開的時段（5.3）就沒有這個漏洞。
     - 順帶確認了一件事：`order_histories` 的列數約等於兩週前的存款單數，2.2 那三站的下界用主鍵區間補成實數時，可以拿同一區間的 `orders` 數字交叉核對。
   - **逐站確認 `orders` 與 `order_histories` 的 `currency_id` 上真的沒有索引**。migration 確認沒有，2.2 只對 VWorld 與 TPower 有說法。只要有一站建了索引，分批更新會在那個索引上鎖住區間，而 CCPayment 的回調正好會在同一張表新增列，會真的互相卡死。dry-run 要逐站查（5.5）。
   - 先做 `orders` 再做 `order_histories`，後者的上界在前者做完之後才取。
   - 為什麼不走支付通道索引：會漏三種列。`orders.agent_cash_flow_id` 預設值是 0 的列；通道事後被改幣別、停用或刪除的列；還有 2.2 自己承認的，用現有 USDT 通道反查只拿得到下界。原稿一邊用通道分批、一邊要求驗收時「殘留為零」，這兩件事互相矛盾。
   - 計數、備份、更新、驗收四件事要用同一套主鍵區間條件。`order_histories` 三站的實數也用這個方法補（每段走主鍵範圍掃描，不會撞到全表掃的逾時）。
   - 最後一批也要有明確上界。不設上界的更新在 MySQL 預設的隔離級別下會鎖住表尾，擋住會員建新的存款單。這一點是依鎖的機制推論的，**沒有在測試環境實測過**。
6. **更新時必須保住 `updated_at`**：`orders` 與 `order_histories` 的 `updated_at` 在資料庫層設了「有更新就自動寫成現在」（`database/migrations/2025_06_19_091321_create_orders_table.php:39`）。不處理的話，近 30 天內的 USDT 存款單會整批跑到會員 App 系統通知的最上面，時間顯示成清洗當下，因為舊前台的 `SystemNotificationController.php:58-118` 是用 `updated_at` 篩選和排序的。做法是更新語句裡明確寫「`updated_at` 維持原值」。Eloquent 關掉時間戳沒有用，那只管程式層。原值被蓋掉之後只能靠備份還原。
7. **舊資料檢查（dry-run 必出，第七節第 3、3-1 題）**：內容併進 5.5。

**收尾**

8. **清快取**，兩邊分開做，而且**不是只在收尾做**：
   - **時點**：第一段 commit 之後立刻清一次，收尾再清一次。原稿只排在收尾，專審指出第一段 commit 到收尾之間整段是空窗，只靠「人記得不跑同步命令」在守。回滾命令也要清（見 5.4）。
   - **APIs 這邊用列舉的方式逐鍵刪，不用萬用字元掃**。要刪的鍵是有限而且算得出來的：`currency:id:{代碼}` 由幣別代碼列舉，`currency:list:{語系 id}` 由語系表列舉。原稿寫「用逐步掃描的方式找鍵」，那是假設快取放在 Redis；但 `config/cache.php:33` 的預設儲存是資料庫，各站實際用哪一種寫在各站的 `.env`，沒有讀（第八節）。放在資料庫的話，鍵是 `cache` 表裡的列，沒有萬用字元刪除這回事。逐鍵刪兩種儲存都對，dry-run 也才真的印得出「會刪哪幾個鍵」。
   - 專審補上兩組漏掉的鍵：`order_type_banks`（`app/CacheKeys/CacheKeys.php:127-130`，APIs 只在訂單類型的 observer 裡清它、沒有產生端，產生端在舊系統；第一段若用 query builder 改訂單類型，observer 不會觸發）；`order_types:v2:group_{組別}:lang:{語系}`（`CacheKeys.php:117-122`，內容帶幣別 id，存活時間只有一分鐘，風險小，但要算進清單）。
   - 舊系統的 `{REDIS_PREFIX}:currency:list:*`、`{REDIS_PREFIX}:currency:show:*` 前綴格式不同（見 3.4），要另外處理。不要照抄舊系統的整庫掃描寫法（`API_Frontend` 的 `REDIS.php` 用的那種會卡住整個 Redis，六站共用時會拖累所有站）。
9. 產出對照報告：每張表改了幾列、備份表名、舊 id 對新 id 的對照、殘留的目標 id 檢查為零（用第 5 點同一套主鍵區間條件掃）。

### 5.3 部署協議

**程式版本與資料版本對不上時會怎樣**

- 寫入前的一致性檢查（`GuardsCurrencyMasterData`）要求資料檔與主檔同步，**程式先發或資料先改，兩個方向都會拋錯**：資料檔寫 USDT 是新 id、該站主檔還是 10，或反過來，都會中「代碼已存在於別的 id」。
- 拋錯的後果不只是幣別沒同步。部署後會自動跑 `app:upgrade`（`docs/升級命令說明.md`），而 `app/Console/Commands/UpgradeCommand.php` 裡幣別 seeder 在第 69 行、標準銀行 seeder 在第 74 行，整支沒有攔例外。幣別那步一拋，**排在後面的標準銀行同步就不會跑**，銀行收斂那條線正靠它隨部署同步。
- 同一顆映像檔的資料檔只能有一組 id，所以如果資料檔直接從舊 id 改成新 id，**六站分批清洗的期間，清了的站和沒清的站必有一邊在拋錯**。
- **裁定的解法是分兩次發版**（第七節會審後裁定第 1 題）：
  1. **清洗前先發第一版**：`database/data/currencies.php` 與 `CurrencyEnum` 同一顆 commit，把十個目標項目全部拿掉（七個要刪的，加上三個要重編的）。一致性檢查只比對資料檔裡有的列，seeder 只增不改，所以這一版在六站都不會拋錯，不管該站清了沒；而且資料檔裡已經沒有那七列，半完成狀態下也不會被 seeder 種回來。
  2. **六站逐站清洗**，站與站之間可以隔天觀察。
  3. **六站都清完再發第二版**：把三個虛擬貨幣以新 id 加回資料檔與 Enum。
  - **這個做法 2026-09-21 經事故應變那一位專審，結論是對 APIs 成立、可以用，但要補下面「專審補上的三個條件」**。金流正確性那一位在讀回調路徑時也獨立驗過同一題，結論相同。
  - 成立的依據（專審後改寫，原稿的理由不夠）：**APIs 的幣別代碼是從資料庫拿的，不是從 Enum 拿的**。十三家金流商都走支付通道的幣別關聯讀主檔的代碼，會員 App 的幣別列表也是直接查主檔。`CurrencyEnum` 在 `app/`、`config/`、`database/`、`routes/` 只有 6 個使用點：沒有任何 Model 把幣別欄轉成這個 Enum，沒有驗證規則用它，拿資料庫的整數值去轉 Enum 的只有 TrustPay 一處，而它只做 SGD(2)。所以兩版之間，還沒清的站（主檔是舊 id）與清過的站（主檔是新 id）都查得到那一列，存提正常。原稿只查了「有沒有程式用 case 名稱引用這十個 case」，沒查「拿整數值去轉」，方向對但論證不夠。
  - 契約測試在兩版都會過：`tests/Unit/Data/CurrencyContractTest.php` 要求資料檔與 Enum 完全相等，以及別名契約的 `standard` 清單都在 Enum 內，而 USDT／TON／BUSD 登記在 `crypto` 分類、不在 `standard`。
  - 第一版部署到任何環境都不會拋錯，也不會刪資料：一致性檢查只跑資料檔裡有的列，seeder 只有新增、沒有任何刪除的路徑。
  - 代價：兩版之間 USDT／TON／BUSD 在解析器裡查不到 id（分類不變，仍是虛擬貨幣），`vendor_infos` 回填對這幾個商戶寫不出值（不會寫錯）。受影響的只有兩支手動命令，`routes/console.php` 沒有排任何幣別類的排程，**所以兩版之間的空窗期本身是安靜的，值班不必盯數週**；會誤報的稽核只發生在各站清洗當下。兩版之間若有新站建置，**確定會缺這三個幣別**（兩支 seeder 讀的是同一份資料檔）。
  - **兩版之間不可上線任何用 `CurrencyEnum::cases()` 產生幣別選單的功能**。`walletAllowed()` 是「這個幣別能不能開會員錢包」的判準，現在沒有呼叫端；第一版拿掉三個虛擬貨幣的 case 之後，接上它的功能會漏掉這三種幣別。主檔清洗排在錢包功能之前，順序本來就對，但這是時序上的依賴，要明寫。

**專審補上的三個條件**

1. **Dev 當第零站，用同一支清洗命令先清**（RB 已裁，見第七節專審後裁定第 2 題）。
   - 為什麼：第二版的資料檔寫 USDT 是新 id，部署到任何還沒清的環境，一致性檢查會以「代碼已存在於別的 id」拋錯，`app:upgrade` 中斷，標準銀行同步跑不到。Dev 不在六站清單內，而 develop 分支一合併就會自動部署 Dev。
   - 2026-09-21 查 Dev 庫（唯讀）證實會發生：Dev 的 USDT 還在 10，而且有資料（支付通道 5、訂單類型 16、銀行 9、金流商銀行對照 3、存款單 45 筆）；TON 有存款單 19 筆；GCASH、PayMaya、FON 在 Dev 也有配置列。
   - 做法：正式六站之前，先在 Dev 跑同一支清洗命令與回滾命令。Dev 有真實形狀的資料，正好當 5.4 要求的回滾演練場，一次解掉兩件事，而且全程只有一條清洗路徑。
   - **順序要求：第二版合進 develop 之前，Dev 必須先清完；第二版部署到正式站之前，六站都必須清完。** 部署 pipeline 的七個部署目標對到的資料庫正好就是這六套（對照見第八節），沒有六站以外的正式環境。
   - 沒選「第二版附一支自動補正的 migration」：等於多一套改 8 張表的邏輯跑在部署掛鉤裡，沒有備份、沒有 dry-run，與「先備份並驗可還原才准跑」衝突，而且正式站的部署掛鉤有 5 分鐘上限。沒選「給一致性檢查開過渡白名單」：它是目前唯一擋得住半完成狀態的東西。
   - 還要處理的：各開發者若有自己的本機庫，要另外通知；共用的測試庫若有人跑過 `app:upgrade`，主檔會留著舊 id 的 USDT，第二版之後 `tests/Feature/Currency/CurrencyMasterDataGuardTest.php` 會整組紅，而且錯誤訊息指向一致性檢查、不指向真正的原因。第二版的送審重點要列這一條。CI 不受影響（CI 建庫不灌幣別）。
2. **舊系統有同樣的版本錯配，要各自處理，不是跟著分兩次**。舊前台的語系鍵改成新舊並存（5.1 第 3 項）；排程專案的幣別常數在清洗期間不改、改用禁跑來擋（5.1 第 1 項與下面的暫停清單）。
3. **第一版是已清洗站的回滾地板**（見 5.4）。
- 清洗命令開跑前自己先比對一次 `CurrencyEnum` 與該站主檔，對不上就拒跑，不要靠人記得。
- `vendor_infos` 回填在失配期間是寫不出值、不是寫錯值（見 3.4），所以就算有人跑了也不會污染資料。「中間不跑回填」仍然保留，理由是避免落空被誤判成資料問題。
- ⚠️ 未驗證：資料同步與應用程式在 helm 上是兩個獨立的發行單位（`gffv3/helm-charts` 底下的 `laravel-migration` 與 `apis`）。如果兩者之間沒有互相卡住，`app:upgrade` 拋錯時應用程式可能照常上線，站點就跑在「新程式配舊資料」上。兩者的先後關係在部署專案的 pipeline 裡，本機沒有副本可查。

**清洗期間要暫停的東西**

原稿只寫「建單／回調／queue／SP」，會審指出漏了三類：

- **提款與代付**。`withdrawal_orders` 確實沒有幣別欄，第二節的表清單沒有漏；但代付在執行當下才去查幣別：`app/Services/WithdrawalPayoutService.php:174` 讀 `banks.currency_id`，再用它去配對支付通道（`:422-441`）。要暫停的有：提款建單、代付發起（含後台手動出款）、代付回調。**關門條件：清洗前該站不得有進行中的提款單，範圍要涵蓋 GCASH、PayMaya、FON 的銀行列，不是只看 USDT／TON。**
  - 暫停的理由專審後改寫。原稿寫「清洗中途這兩張表有先後，USDT／TON 的提款會查無可用渠道」，這與 5.2 的單一交易矛盾：`banks` 與 `agent_cash_flows` 在同一個交易裡改，交易外看不到先後，USDT／TON 兩邊同時換成新 id，配對照樣成立。
  - 真正會壞的是另外幾列：GCASH／PayMaya 的銀行列改掛 PHP(22)、FON 的銀行列幣別欄改 NULL 之後，掛在這幾列上的進行中提款單會配不到渠道（`:176` 直接回「無法取得訂單銀行的幣別資訊」）。
  - 金流商提供的銀行碼查不到時會回空字串，只有 FPay、GMPay、BigPayz、CCPayment 四家有擋空碼，其餘會把空碼送給三方。
- **排程與同步類命令**（`routes/console.php`）：`cash-flow:sync-acf-type --execute`（每日 07:20）會寫 `agent_cash_flows`；`bank:sync-projection`（07:40）會由 `banks` 重建投影；`ccpayment:resend-missing-webhooks`（每 30 分鐘）會自動補發回調，是清洗期間的再進入路徑；`cash-flow:sync` 會從主檔現查幣別 id 回寫（而且吃 3.4 那組一小時快取）。清洗時段避開 07:00～08:00（UTC），`cash-flow:sync` 列入禁跑。
  - **另外要避開 20:00（UTC）**：資料庫的每日排程會在這個時間把兩週前的存款單複製進 `order_histories`（見 5.2 第 5 點）。它不在 `routes/console.php` 裡，是資料庫層的排程事件，停排程服務也停不到它。第二段不要跨過這個時間。
  - 專審逐支看過：07:20 與 07:40 那兩支都不引用幣別，不會把舊 id 寫回去，只會與第一段搶同一批寫鎖，避開那個小時就夠。
  - 🔴 **`ccpayment:resend-missing-webhooks` 要在清洗窗口內顯式停掉**，避開某個小時對它無效（每 30 分鐘一次）。理由見下面「CCPayment 的回調會自己建單」。
  - 審查官曾提出「清洗之後跑 `cash-flow:sync` 會把 GCASH／PayMaya／FON 通道的幣別寫成 0」。複驗後**現況不成立**：同步命令的幣別代碼來自 repo 裡的同步資料檔（`app/Console/Commands/CashFlow/Data/`），不是資料庫裡的通道設定；資料檔裡沒有任何通道以這三個代碼當幣別，用到目標代碼的只有 CCPayment 與 PayEssence 的 `USDT`，而 USDT 清洗後還在主檔。真正要防的是下面這一條。
  - `getCurrencyId()` 查不到代碼時回 0，而且會把 0 快取一小時。清洗本身不會製造「主檔那一刻沒有該代碼」的瞬間（同一個交易），**但回滾過程會**，所以回滾命令也要清這組鍵。5.1 第 13 項已裁定改成查不到就不快取，那個 MR 發了之後這個洞就沒了，回滾命令清鍵仍然保留當第二道。
- **排程專案（RD-Studio-01）的四支開商戶命令**（專審補上）：`StarpagoWithdraw`、`StarpagoEwalletDeposit`、`GffPayDeposit`、`GffPayWithdraw`（路徑見 4.1）。它們把幣別常數寫進支付通道與訂單類型，幣別欄沒有外鍵，寫進主檔不存在的 id 也不會被擋；後果不是報錯，而是 `MaxPay/Withdrawal.php:39` 與 `Kira/Withdrawal.php:73` 那種帶著空幣別把代付送出去。這四支是手動命令、不是排程，所以用流程擋：**從第一站開始清洗，到六站清完、常數改值發出去為止，整段禁跑**，代價是這段期間不能開新的 USDT／TON 商戶。4.1 早就認出這個風險，但原稿沒有把它轉成禁跑項。
- **會誤報的稽核**：`check:cashflow-orphans`（07:00）、`cash-flow:audit-fpay-bank-list`（08:00）、`withdrawal:alert-stuck-submitted`（每 5 分鐘）、第二期 Task 10 的不變式，清洗期間都可能報紅，先知會值班。
- **三方回調不擋**（第七節會審後裁定第 6 題）。回調是三方打進來的，現行架構沒有開關，而且 `app/Http/Controllers/External/CashFlow/DepositNotifyController.php:26-28` 明寫回調端不看通道狀態，停用通道擋不掉已經送到三方的單。在入口層硬擋的話，完全依賴各家三方的重試政策，重試耗盡就是會員付了錢沒上分。所以改成讓清洗本身對回調安全：
  - **這個設計 2026-09-21 經金流正確性那一位專審，結論是「不擋」站得住，但原稿給的三條理由有兩條要改，另外漏了下面兩條會碰幣別的回調路徑。**
  - 站得住的部分（專審逐條驗過）：回調的查單與冪等不碰幣別（`app/Services/Payment/Traits/DepositCallbackTrait.php:43-45,78-89`），入帳金額用的是存款單上的快照。回調入帳鏈上其餘出現幣別 id 的地方都只是寫 log。錢包只在「建立新錢包」時寫幣別，來源是會員所屬組織的幣別，與存款單的幣別無關。三支存款用的預存程序（活動、邀請、成長彩金）整支不碰幣別。
  - 第一段的長交易不會卡住回調，是因為那五張表沒有任何加鎖的讀取（見 5.2 第 3 點的明示前提）。第二段每批只鎖目標列（見 5.2 第 5 點），回調最多等一批更新的時間。
  - **要改的第一條**：原稿寫「回調進來看到的要嘛全是舊的、要嘛全是新的」。這句只在單一交易內成立，而一次回調是三段：控制器先在交易外讀支付通道（`DepositNotifyController.php:45`），金流商的處理自己一個交易，回到控制器再由 `app/Services/PaymentService.php:93-278` 開第二個交易入帳。清洗的 commit 可以插在這三段中間。對現行 USDT／TON 通道沒有具體傷害（理由是上面那一條：入帳不碰幣別），但這句話不能當通則。
  - **要改的第二條**：原稿寫「存款建單暫停只是保險，不停也不會壞」。對 CCPayment 不成立，見下一條。存款建單與提款照暫停清單停，同步類命令與排程照清單禁跑，這些都是必要的。
  - 🔴 **CCPayment 的代收回調會自己建存款單**。它的流程是「回調進來、建單、入帳」（`app/Services/Payment/Providers/CCPayment/DepositCallback.php:342-362` 呼叫 `app/Services/OrderService.php:68-84`，其中 `:84` 把訂單類型的幣別 id 寫進存款單）。存款單是回調生出來的，「停建單」擋不到它。`ccpayment:resend-missing-webhooks` 每 30 分鐘會主動請三方重送，等於主動觸發這條路。
    - 現況的緩解：六站目前都沒有掛 USDT 的 CCPayment 通道（2026-09-21 查）。這是現況，不是設計上的保證。
    - 要做的：清洗窗口內顯式停掉那支排程；該站若已掛上 USDT 的 CCPayment 通道，dry-run 要把「窗口內可能由回調生出的新存款單」列為關門條件之一，收尾補掃要涵蓋它們。
  - **FPay 的代收回調會拿存款單的幣別 id 去查訂單類型**（`app/Services/Payment/Providers/FPay/DepositCallback.php:134-149`），查不到就沿用原本的訂單類型、不報錯。兩段之間（訂單類型已改、存款單還沒改）這個查詢對目標幣別必定落空。FPay 現在只做印尼盾，實際衝擊是零；記在這裡是因為它推翻了「回調不碰幣別」這條通則，這種寫法隨時可能被下一家金流商複製。
  - **這整段論證只涵蓋 APIs 的程式，所以要確認回調確實都打到 APIs。2026-09-21 查了，結論是成立。**
    - 怎麼分辨：回調網址不是三方後台寫死的，是建單時由我方傳給三方、存在存款單的 `callback` 欄。路徑是 `/external/api/` 開頭的由 APIs 處理，`/api/` 開頭的由舊系統處理。存款單由哪個系統建立，回調就回到哪個系統。
    - 查法：六站近 90 天（2026-06-21 起）掛在 USDT／TON 支付通道上的存款單，依通道與回調路徑分組。Alfawin77 與 Me77 零筆；VPlus33 沒有 USDT 通道，沒查。

      | 站點 | 通道（金流商） | 走 APIs | 走舊系統 |
      |---|---|---|---|
      | VWorld | 269、265（PayEssence 代收） | 596、123 | 0 |
      | TPower | 306（PayEssence 代收） | 1,357 | 3（全在 2026-08-07 同一個小時內） |
      | Yes2Win | 185、186（PayEssence 代收）；14、81（AllPay） | 428、93；2、1 | 0 |

    - 近 90 天有流量的只有 PayEssence 與 AllPay，幾乎全部走 APIs。專審擔心的 `USDT`(金流商 4) 通道近 90 天零筆，沒有流量。TON 的通道同樣零筆。
    - TPower 那 3 筆走舊系統的單落在 2026-08-07，與先前盤點「新舊回調分流」時記下的那次一次性事件是同一個時段，之後沒有再出現。
    - 限制：這查的是存款單。提款那一側在清洗前就要求不得有進行中的單據，窗口內不會有代付回調進來，所以沒有另外查。清洗當下若有舊系統建立、還沒回調的 USDT 存款單，dry-run 的「進行中的存款單」要依 `callback` 路徑分開列出來（5.5）。

**其他前置**

- 目標 id 空位檢查（1000 以上尚未被用）。2026-09-21 六站最大 id 都是 24。
- 六站 `currencies` 要有 `PHP`(22) 且為啟用（2.6 已確認，dry-run 仍要再驗一次）。
- 舊系統本機副本重 fetch 後再複驗 5.1 的逐分支命中。
- `TableBackupService` 在 `app/` 裡還沒有任何呼叫點，這次會是它第一次上正式庫。清洗前先拿一張小配置表，在正式庫完整走一次「備份、改、比對、還原」。

### 5.4 改壞了怎麼回來

> 原稿在第七節第 2 題的理由裡寫「還原靠整表備份」。這句話在程式上不成立，是 AI 起草時沒有去讀備份服務本身就寫下的，會審時被指出。

- **備份服務還原得了哪些、還原不了哪些**。`app/Services/Database/TableBackupService.php:25-28` 明寫它刻意不提供整表灌回，`restore()`（`:160`）能做兩件事：把指定列的欄位值寫回去；或在呼叫端明示 `$recreateMissing = true` 時，以備份值、**保留原 id** 把消失的列插回去（`:146-155` 的說明寫明這個參數就是給會合法刪除列的命令用的）。
  - **還原得了**：被真刪的七列（代碼清洗後沒有別的列佔用，插回去不會撞唯一鍵）；被改掛 PHP、被停用、幣別欄被歸零的通道／訂單類型／銀行列（逐列寫回原值）。
  - **還原不了**：重編的三列。備份服務是依主鍵逐列寫回的，而這三列的主鍵本身被改掉了：舊 id 那列「消失」、新 id 那列是它不認得的列。硬用「把消失的列插回去」會撞代碼的唯一鍵。
  - 還原時找備份表靠的是資料庫裡的表名慣例（`backupTables()`，`:225-246`），不靠下面說的那個對照檔。對照檔不見影響的是校驗報告，不影響能不能還原。
- **所以要另外寫一支回滾命令**。主檔改成就地改 id 之後（5.2 第 3 點），重編三列的回滾就是反向一句更新，加上把引用表反向搬回舊 id，包成一個交易；七列與配置列用備份服務現成的 `restore()` 還原。回滾命令收尾同樣要清快取（理由見 5.3 的 `getCurrencyId()` 那一條）。**先在 Dev 演練過（Dev 是第零站，見 5.3）。這支命令寫好並演練過之前，清洗命令不准上正式庫。** RB 09-18 的硬規則「驗證可還原才准跑」指的就是這個。
- **舊 id 對新 id 的對照、備份批次的時間戳，要寫進一張資料庫表**，不能只印出來讓人貼工單（人會忘），也不能只放在備份服務的對照檔。那個檔寫在 `storage/app/backups/table-backup/`，而部署用的是 pod 的暫存磁碟，**pod 一重啟就沒了，不必等到發版**；六站逐站做要好幾週，期間 master 還會為別的功能持續發版。所以回滾命令要直接假設對照檔一定不在。它不見的時候，`diff()` 回報的「備份之後新插入的列」會永遠是空的，校驗報告看起來一片乾淨。
  - 回滾命令不必依賴對照檔：用 `backupTables()` 依時間戳找回備份表，直接從備份表查出主鍵清單，餵給 `restore()`。專審驗過這條路可行。
- **第一版是已清洗站的回滾地板**（專審補上）。站點清洗完之後，程式不能回滾到第一版之前的任何版本：舊的資料檔寫 USDT 在 10，該站主檔的 USDT 已經在新 id，一致性檢查會以「代碼已存在於別的 id」拋錯，`app:upgrade` 中斷。第二版退回第一版是安全的。
  - 這道檢查在這裡其實是保護：沒有它的話，seeder 只增不改，會把七列以原 id 靜靜插回來。
  - 這是清洗本身的代價，不是分兩次發版造成的。它從第一站清洗完成起永久有效，而事故當下最自然的應變動作就是退回上一個已知良好的版本。所以要寫進工單與值班交接，而且**第一版要單獨發、不跟其他功能綁在一起**，否則那些功能出事時會被這個地板擋住。
- **回滾有期限**。備份表保留 15 天（`RETENTION_DAYS`），`db:prune-table-backups` 每天 05:20 自動刪，不問人。第 16 天起沒有備份可用。工單要寫明這個期限，或把清洗批次的備份表排除在自動清理之外。
- **清洗之後才進來的新單不在備份範圍內**，回滾不會碰它們，而且只備部分列的表不回報新插入的列，連期間進了多少新單都查不出來。回滾命令要自己處理這批。
- 只備部分列的模式是單一語句、沒有分批，列數太多會撞到預處理語句的參數上限；`diff()` 會把整張備份表讀進記憶體。dry-run 先給出實數，超過一萬列就要求命令自己分批。

### 5.5 dry-run 報告必出的項目

報告先貼回工單，PM 看過才正式跑。

1. 逐表列出帶十個目標 id 的存量列數。`order_histories` 要給實數，不能只給下界（第七節第 3-1 題）。
2. 目標幣別存款單的 `extra_content` 逐筆檢查有沒有內嵌幣別 id（第七節第 3 題）。
3. `banks` 改掛後的撞鍵預檢：對每一列算出改掛後的鍵，比對該站有沒有既存的啟用列。2026-09-21 查過不會撞，dry-run 仍要重驗。
4. 六站 `PHP`(22) 是否存在且啟用；`banks.currency_id` 是否允許 NULL。
5. 目前掛目標幣別的支付通道各屬於哪幾家金流商，用來判斷清洗中途出錯的面有多大。
6. FON 兩條支付通道的現況。
7. 對回 PHP 之後，「提款需綁手機」的判定會不會新命中代理組別。`app/Http/Controllers/Frontend/WithdrawalController.php:166-172` 現在看的是「電子錢包、幣別 PHP、啟用」，`05e3a4d30` 當時寫「零行為變更」的前提是沒有電子錢包銀行掛 PHP，清洗之後這個前提不成立。全六站會被改掛而且啟用的只有 VWorld 的銀行 404；09-18 查過它所在的支付組別沒有開這個設定，dry-run 要再確認一次並列出會新命中的代理組別與會員數。
8. 實際會刪哪幾個快取鍵（逐鍵列出，含 5.2 第 8 點補上的兩組），以及該站的快取放在 Redis 還是資料庫。
9. 進行中的存款單與提款單數量（關門條件）。提款單的範圍含 GCASH、PayMaya、FON 的銀行列。進行中的 USDT／TON 存款單要依 `callback` 欄的路徑分成「APIs 建立」與「舊系統建立」兩組列出；舊系統建立的那一組不是零的話，交人判斷（5.3 的回調安全論證只涵蓋 APIs）。

以下是 2026-09-21 專審後加的：

10. 通道 106 旗下啟用中的「通道與訂單類型關聯」筆數。不是 0 的話正式執行會中止（5.2）。
11. 該站有沒有任何外鍵指向 `currencies`，`currencies` 上除了主鍵與代碼唯一鍵還有沒有別的鍵（就地改 id 的前提，目前只查過 VWorld）。
12. 該站 `orders` 與 `order_histories` 的 `currency_id` 上有沒有索引（5.2 第 5 點）。
13. 該站有沒有掛 USDT 的 CCPayment 通道。有的話，列出窗口內可能由回調生出新存款單的風險，並確認那支補發排程已停。
14. 第二段實際最大一批的 id 清單長度。
15. `vendor_limits.curency_id`（欄位名拼錯的那一張，見第八節）的存量。

### 5.6 第一站清洗完的驗收

Dev 是第零站，用來演練清洗命令與回滾命令（5.3）；但 Dev 沒有真實的會員與三方流量，下面這幾件事只有正式站驗得到。六站逐站做，第一個正式站就是試點。第一站清洗完、觀察期內要確認下面幾件事，都沒問題才排下一站。

1. 對照報告的殘留檢查為零，包含上界以上區間的補掃。
2. **實機開會員 App 看一次**（第七節會審後裁定第 3 題）：USDT 存款頁、歷史存款單列表、提款頁，幣別名稱顯示正常。存款單的幣別 id 是原樣回給 App 的（舊前台 `OrderController.php:89`、APIs `app/Http/Controllers/Frontend/DepositController.php:390`），App 若有寫死舊 id 的地方，會在這裡露出來。
3. 代理後台的幣別下拉、支付通道列表、代客存款的訂單類型清單顯示正常。
4. 清洗後的第一次部署，`app:upgrade` 沒有拋錯，標準銀行同步有跑到。
5. 恢復存提之後，USDT／TON 的第一筆存款與第一筆提款走完全程。
6. 會員 App 的系統通知沒有冒出一批時間是清洗當下的舊存款單（驗 5.2 第 6 點）。
7. 至少過一個完整的結算日，稽核沒有新增與幣別相關的紅燈。

## 六、明確排除、不可動

- `vendor_infos.info`／`event_vendor_infos.info` 的 `CURRENCY` 鍵（三方參數；IBC(saba) 的值就是 `15`）。
- `vendor_infos.currency` 字串欄位（進三方 payload，57 處／29 檔）。
- `ia_*`、`dg_bet_records.currencyId`、Saba／IA／SurePay 的三方幣別碼、銀行碼裡的 `GCASH`／`FON`／`paymaya`、金流商編號 `*_FON_PAY`。
- `resources/lang/*/order_type.php`、`bank_info.php`、`command_logs.input`／`admin_operation_logs.params` 歷史紀錄、`public/docs` 的 API 文件產物（會過期，不阻擋）。

## 七、RB 裁決

### 已裁定（2026-09-21）

| # | 題目 | 裁決 | 理由 |
|---|---|---|---|
| 1 | TrustPay 要不要改讀主檔幣別代碼 | **不做**，維持最小改法 | TrustPay 只做 SGD(2)，與清洗名單沒有交集，送三方的簽名字串清洗前後都是 `SGD`。改讀主檔會多出「主檔幣別停用時代付變擋單」的行為變更，換不到清洗上的好處 |
| 2 | 要清除的七列主檔是真刪還是軟停用 | **七列真刪** | 符合決議㉛「主檔只留真幣別」，後台無法再把它們重新啟用。⚠️ 原稿這裡還寫了一句「還原靠整表備份」，會審查出這句不成立，已刪，實際的還原方式見 5.4。RB 裁這一題時看到的選項說明裡也有這句，所以這一題重看過一次，結果是維持真刪（見下方會審後裁定第 5 題） |
| 2-1 | 引用 `FON`(11) 的訂單類型與銀行列 | **停用，幣別欄改成該表的「沒設幣別」值**（`order_types.currency_id` 改 0、`banks.currency_id` 改 NULL），不刪列 | `FON` 沒有對應的真幣別可以對回去。刪列會讓 2023-09-08 那筆歷史存款單的訂單類型關聯落空 |
| 2-2 | FON 的兩條支付通道（VWorld 的 106／107） | **一併停用** | RB 裁決。這兩條通道的 `currency_id` 本來就是 NULL，重編與對回都碰不到它們，要另外寫一個停用動作。名下只有 2023-09-08 一筆存款單（見 2.4）。依 2026-09-21 的快照，106 是啟用、107 已是停用，實際要改狀態的只有 106（現況見 5.2） |
| 3 | `orders.extra_content` 存量要不要掃 | **只掃存款單幣別落在目標 id 的列**，併進清洗命令的 dry-run 報告 | 只有這批存款單會因清洗而過期。APIs 端沒有任何程式從這個欄位讀幣別 |
| 3-1 | 已使用目標幣別的舊資料 | **清洗前一律檢查**，列為 dry-run 報告的必出項目 | RB 要求。範圍是第二節列出的所有引用表裡帶十個目標 id 的存量列，`order_histories` 的下界數字要補成實數 |
| 4 | 範圍要不要含前端與文件 | **都不含**，也不列追蹤項 | RB 確認前端沒有寫死幣別 id。`public/docs` 範例值過期不影響執行 |

### 會審後裁定（2026-09-21 圓桌會審提出，RB 同日全數裁定）

會審由四位審查官各從一個角度看這份計劃：金流正確性、事故應變、舊系統與查詢效率、文件一致性。四位都認可盤點的品質，方向沒有人反對；問題集中在執行與回滾的設計沒有寫。能直接補的已經寫進第五節，需要 RB 拿主意的六題裁定如下。每一題在給選項之前，AI 都先讀程式或掃過資料驗證選項寫的代價。

| # | 題目 | 裁決 | 沒選什麼、為什麼 |
|---|---|---|---|
| 1 | 六站怎麼排 | **分兩次發版，六站逐站做**。清洗前先發一版，把十個目標項目從資料檔與 `CurrencyEnum` 一起拿掉；六站逐站清洗，站與站之間可以隔天觀察；六站都清完再發第二版，把三個虛擬貨幣以新 id 加回來。這個做法是 AI 在裁決當下提出的，同一天已經專審，結論是有條件通過（見下方「專審後裁定」與 5.3） | 沒選「六站壓進同一個部署窗口」：六站的 USDT 存提要同時停，一站出事六站一起卡，也做不到第一站跑完先觀察。沒選「逐站做、接受部署報錯」：清過的站每次部署都會拋錯，標準銀行同步跑不到，凍結期以天計。細節見 5.3 |
| 2 | 大表要不要從第一批拆出去 | **同一站、同一次維護窗口內分兩步，中間設檢查點**。第一段驗證通過才跑第二段；第二段失敗只重跑第二段，不回滾第一段 | 沒選「隔開排期」：隔開期間會員 App 看歷史 USDT 存款單的幣別會異常數天到數週，而且第 1 題的逐站做已經讓第一站當了試點。沒選「一支命令跑到底」：中途失敗沒有明確的停損點 |
| 3 | 前端要不要在簽收前補掃 | **代理後台結案；會員 App 列入第一站的驗收**。代理後台 204 個分支逐一掃過，寫死這十個幣別 id 的判斷式零命中。會員 App 以 RB 的確認為準、不擋送 PM，第一站清洗完實機看一次（見 5.6） | 沒選「請 App 負責人書面確認後才送 PM」：多一個要等人的前置。沒選「不再處理」：存款單的幣別 id 是原樣回給 App 的，實機看一次成本很低 |
| 4 | 自動遞增起點 | **不調。新增幣別一律走資料檔、明寫 id，不從舊後台手建** | 沒選「清洗後用 ALTER 調回法幣區段」：InnoDB 多半不允許把起點設得比目前最大 id 還小，設了不報錯但等於沒做（這一點憑記憶、未驗證）。沒選「另開工單關掉舊後台的新增入口」：要動 API_Agent 各站點分支，不屬於這條分支 |
| 5 | 「七列真刪」要不要重看 | **維持真刪** | 讀程式確認那句寫錯的還原說法不影響這一題：被刪的七列用備份服務現成的 `restore()` 就能以原 id 插回（見 5.4）；還原不回來的是重編的三列，那三列當初就不在選項裡 |
| 6 | 清洗期間三方回調怎麼處理 | **不擋回調，靠設計讓它安全**（見 5.2、5.3）。這個設計是依第 1、2 題的裁決推出來的，同一天已經專審，結論是有條件通過（見下方「專審後裁定」與 5.3） | 沒選「在入口層擋」：完全依賴各家三方的重試次數與間隔，沒有盤過，重試耗盡就是會員付了錢沒上分。沒選「先排空再開始」：停機時間取決於單據逾時多久，而且三方晚回的單仍可能在中途進來 |

### 專審後裁定（2026-09-21，兩個未審設計的專審）

上表第 1 題與第 6 題的做法是 AI 在裁決當下提出或推導的，當天另請兩位審查官各審一個：事故應變那一位審「分兩次發版」，金流正確性那一位審「不擋三方回調」。

**兩位的結論**

- **分兩次發版：對 APIs 成立，可以用。** AI 事前最擔心的一點（兩版之間 Enum 裡沒有 USDT，存提會不會壞）兩位各自獨立查證，都判不成立，理由見 5.3。要補三個條件：Dev 與六站以外環境的處置、舊系統同樣的版本錯配、第一版是回滾地板。
- **不擋三方回調：結論站得住，但 AI 給的三條理由有兩條要改**，另外漏了會碰幣別的回調路徑（CCPayment 的回調會自己建單、FPay 的回調拿存款單幣別查訂單類型），以及一個沒有論證到的範圍（回調打到舊系統的通道）。補上硬條件後放行，內容見 5.2、5.3。
- 兩位意見不同的一處：事故應變那一位對「回調進來看到的要嘛全舊、要嘛全新」沒有意見，金流正確性那一位推翻了它（一次回調是三段）。併發是後者的守備範圍，以他為準。
- 審查官的說法有一條複驗後不成立（清洗之後同步命令會把 GCASH／PayMaya／FON 通道的幣別寫成 0），已在 5.3 註明，沒有照單全收。

**需要 RB 拿主意的三題**。每一題給選項之前，AI 都先讀程式或查資料驗證過選項寫的代價。

| # | 題目 | 裁決 | 沒選什麼、為什麼 |
|---|---|---|---|
| 1 | 停用 FON 通道 106 怎麼寫。通道的 observer 在停用時會連鎖寫到兩張不在 8 張表內的表 | **走 Eloquent，dry-run 印出旗下啟用中的關聯筆數；正式執行時若不是 0，命令中止、交人判斷** | 查 VWorld 正式庫，106 旗下 4 筆關聯全部已停用，現況連鎖會是 0 筆。沒選「允許連鎖」：總表要從 8 張變 10 張，備份與回滾也要涵蓋，為了現況用不到的情況擴大範圍。沒選「用 query builder 繞過 observer」：當天若有啟用中的關聯，會留下「通道停用、關聯仍啟用」的孤兒，隔天孤兒稽核報紅 |
| 2 | 第二版部署到還沒清洗的環境會被一致性檢查擋住，Dev 怎麼處置 | **Dev 當第零站，用同一支命令清** | 見 5.3「專審補上的三個條件」第 1 點 |
| 3 | 主檔那三列怎麼換 id | **就地一句更新改 id，刪掉「新列先建」的要求** | 沒選「舊列先改成暫時代碼、插入新列、搬引用、刪舊列」：多三個步驟，新列要逐欄承接、漏一欄就會把停用的 BUSD 種成啟用，而這些中間狀態在單一交易裡對外根本看不到，多做工換不到保護 |

下面是圓桌會審裁決前的原始意見，留著對照。

| # | 題目 | 現有的意見 |
|---|---|---|
| 1 | 六站分批清洗，還是壓進同一個部署窗口一次做完 | 應變那一位主張同一窗口：同一顆映像檔的資料檔只能有一組幣別 id，分批期間必有一邊在拋錯（見 5.3）。另一條路是讓一致性檢查在過渡期同時接受新舊兩組 id，但那等於把目前唯一擋得住半完成狀態的檢查關掉。其餘三位沒有表態 |
| 2 | `orders` 與 `order_histories` 要不要從第一批拆出去 | 應變那一位建議先做主檔與四張小配置表（列數都是個位到兩位數，備份、校驗、回滾都輕），穩了再處理千萬列級的歷史單據。金流那一位的主張是大表排在主檔一致之後，兩者不衝突。5.2 已經照「分兩段」寫，這一題問的是兩段之間要不要隔開排期 |
| 3 | 前端要不要在 PM 簽收前掃過一次 | 第 4 題 RB 已確認前端沒有寫死幣別 id。應變那一位的意見是：不擋簽收，但建議升級成簽收前的必辦項，因為程式面只實查過 WEB_AgentBackend 的一個分支，而舊前台的幣別列表 API 確實會把幣別 id 回給前端。掃完若零命中，工單一個字都不用改 |
| 4 | 重編後 `currencies` 的自動遞增起點要不要調回法幣區段 | 兩位審查官都建議從「未驗證」升級成要裁的一題。受影響的只有後台手動新增幣別這條路，後果是法幣的 id 落進虛擬貨幣區段，語意難看、功能不壞。調回去的代價是日後資料檔明寫的 id 可能與後台手建的撞號 |
| 5 | 第 2 題「七列真刪」要不要因為還原前提改變而重看 | 見上表第 2 題的註記。真刪的理由（符合決議㉛）沒有變；變的是「改壞了有現成的還原路徑」這個前提不存在，要靠 5.4 另寫的回滾命令 |
| 6 | 清洗期間三方回調要怎麼擋 | 現行架構沒有開關（見 5.3）。這一題沒有現成答案，要先想清楚才能送 PM |

### 第 2 題的事實更正

原稿寫「建議真刪，理由是 seeder 只增不改、遇殘留列 id 會整批回滾」。這個理由只對重編的三列成立，對要清除的七列不成立，錯在沒有分開看。

- **重編的三列（USDT／BUSD／TON）**：資料檔會改成千位數的新 id。舊列只要還在，寫入前的一致性檢查（`database/seeders/Concerns/GuardsCurrencyMasterData.php:54`）就會以「代碼已存在於別的 id」拋錯，軟停用也一樣。這三列的舊列一定要消失，沒有選擇。
- **清除的七列**：資料檔刪掉這幾個區塊後，一致性檢查只比對資料檔裡有的列，不會再碰到這七個 id 與代碼。軟停用不會讓 seeder 失敗。真刪是為了符合決議㉛，不是為了避開回滾。

這兩點是讀程式得出的，沒有實際跑過 seeder。會審時文件一致性那一位重讀了同一段程式，推論相同。

### 第 3 題的補查

會讀 `orders.extra_content` 的程式有七處：TngPin、TakaPay、VtPay、DgPay、ZPay、AllPay 六家金流商，加上 `app/Http/Controllers/Frontend/DepositController.php:379`。讀的鍵是 `pin`、`redirect_url`、`itemDesc` 這類，沒有一處讀幣別。`DepositController.php:387` 會把整個欄位原文回給會員 App。

### 第 1 題的原始說明

兩席審查（金流正確性、craft）結論一致——TrustPay 只做 SGD(2)，清洗名單與它無交集，`tryFrom(1000+)` 照樣對得到；但它是全專案 13 家金流商裡唯一拿 `CurrencyEnum` 的 case 名當送三方且進簽名的幣別代碼的，其他家走支付通道／訂單類型／銀行的 `currency` 關聯讀主檔 `code`，或讀每商戶設定 `info['CURRENCY']`。**已做（最小改法，09-18）**：幣別 id 為 NULL／0／不存在時統一擋單、記 log、回 500，補齊代收與代付測試。**完整改法（裁決不做）**：改讀主檔 `code` 會經過 `Bank::currency()` 關聯的 `status = 1` 過濾，主檔幣別停用時代付會從能出單變擋單，屬行為變更。清洗驗收要對 TrustPay 的通道／訂單類型／銀行跑一次 `currency_id` 盤點確認只有 2 當關門條件。

## 八、未驗證

- 舊系統本機副本是 6～7 月的 fetch；RD-Studio-01 停在 06-04；API_Agent 本機落後遠端 7 顆。
- `order_histories` 的 TPower、Yes2Win、Alfawin77 三站數字是以現有 USDT 通道反查的下界（VWorld 是實數）。
- 各站 `.env` 的 `CURRENCY_ID`、`REDIS_PREFIX` 未讀。
- `agent_operate_general_logs.content` 由舊系統寫，內容未查。
- `fon666` 站若有獨立資料庫，不在六站 MCP 內。
- 舊系統 SP 在四個 repo 沒有副本；APIs `database/procedures/` 實際是 217 支（原稿寫 216），09-17 與 09-21 會審各掃一次，都沒有寫死的幣別編號，也沒有以幣別 id 做 join 或分組的。正式庫另有副本的 SP 未掃。
- 動態表名／字串拼接 SQL 掃不到。
- 2.1 的表清單是用欄位名 `currency_id` 去查的，漏了一個拼錯的欄位：`vendor_limits.curency_id`（`database/migrations/2025_06_19_091321_create_vendor_limits_table.php:20`，少一個 r）。這張表在 APIs 與四個舊 repo 都沒有程式引用，六站的資料沒有查。dry-run 的存量檢查要把它算進去。
- 虛擬貨幣重編後 `currencies` 的自動遞增起點會停在千位數以上（這是以「插入新列」為前提的說法；改成就地改 id 之後會不會一樣，未驗證，見上）。裁定不調（第七節會審後裁定第 4 題），新增幣別一律走資料檔。唯一會用到自動遞增的路是舊後台的新增幣別（`API_Agent` 的 `CurrencyController.php:195-218`，用 `Currency::create()`），正式環境六站沒有任何一列是這樣建的。「InnoDB 不允許把起點設得比目前最大 id 還小」這一點是憑記憶、沒有實測也沒有查官方文件。
- 代理後台（WEB_AgentBackend）2026-09-21 逐分支掃過：本機副本的 204 個遠端分支，「`currency_id` 與這十個數字做比較或賦值」的寫法零命中，零個分支出錯；同一個搜尋方式在 `vw-master` 找得到 52 行 `currency_id`，可見搜尋本身有效。限制：用 `switch` 或別的變數名寫死的掃不到；本機副本最後更新是 09-15。會員 App 本機沒有程式，沒有掃，以 RB 的確認為準，並列入第一站的驗收（5.6）。
- 第七節會審後裁定的第 1 題（分兩次發版）與第 6 題（不擋回調）2026-09-21 已專審，都是有條件通過。專審留下的未驗證項目：
  - ~~哪些 USDT／TON 通道的回調打到舊系統~~：當天已查，近 90 天幾乎全部走 APIs（見 5.3）。剩下的限制是只查了 90 天、只查了存款側。
  - ~~`order_histories` 由誰寫~~：當天已查，是資料庫的每日排程事件（見 5.2 第 5 點）。剩下未驗證的是：查的是從庫（VWorld、TPower、Yes2Win、Me77 顯示「從庫端停用」，這是從庫的正常狀態），主庫上這個事件是不是啟用，是從「六站歷程表最後一筆都落在兩週邊界上」推出來的，沒有直接看主庫。
  - **正式庫有 repo 裡沒有的預存程序與排程事件**。VWorld 正式庫有 360 支預存程序（repo 裡是 217 支）、53 個排程事件。2026-09-21 用「定義裡有沒有出現 `currency_id` 或 `currencies`」掃過 VWorld：排程事件零命中；預存程序命中三支，都是原樣複製存款單的搬移程序（`ADMIN_copy_orders_to_history`、`ADMIN_copy_order_histories_to_bak`、`orders_to_orders_bak3`），沒有一支拿幣別 id 做判斷或寫死數字。後兩支搬去的是備份表，屬於 2.3「備份表不遷」的範圍。其餘五站沒有掃；後兩支由誰、在什麼時間呼叫，沒有查。
  - 其餘五站有沒有外鍵指向 `currencies`、`orders` 與 `order_histories` 的 `currency_id` 上有沒有索引，沒有查（VWorld 的外鍵查過，沒有）。
  - 各站的快取放在 Redis 還是資料庫（`.env` 的 `CACHE_STORE`），沒有讀。
  - 就地改 id 之後，`currencies` 的自動遞增計數器會不會被抬到千位數，沒有實測。兩種結果都不會撞號，而且已裁定新增幣別一律走資料檔，不影響決策。
  - 一致性檢查拋錯時 `app:upgrade` 會以非零的退出碼結束：讀程式確認例外沒有被攔（`UpgradeCommand.php` 直接呼叫 seeder、外面沒有 try/catch；`AbstractSyncSeeder.php:56` 的比對在 try 之前），但沒有實際跑過。
  - 舊前台的翻譯函式找不到鍵時回傳鍵名字串，這是 4.2 與 5.1 第 3 項的後果判斷基礎，沒有實證。
  - `Bank::currency()` 關聯帶了「只取啟用的幣別」的條件，`AgentCashFlow::currency()` 與 `OrderType::currency()` 沒有帶。代付側與代收側對「主檔幣別停用」的反應因此不同，實際影響沒有覆核。就地改 id 會保住狀態欄，清洗本身不會觸發這個差異。
- ~~VWorld 那套資料庫底下有哪些站點~~：2026-09-22 查清（VWorld 正式庫唯讀，整庫；部署站點看 SRE 部署專案的最新一次 pipeline）。
  - **資料面**：這套資料庫有 28 棵根代理樹帶會員。清洗改的是全庫的主檔與配置表，所以全部會被改到。目標幣別的配置列實際落在 8 個組織：`vworld`（組織 1）、`wintoto`（11）、`vworldsg`（4）各有 USDT 通道；**`GFF USDT`（組織 20）是 TON、FON 通道 106／107 與 FON 銀行所在的組織，它是活的（983 名會員，最近一次建會員 2026-08-31）**；**`casinos365`（組織 13）是 GCASH／PayMaya 銀行所在的組織**（含唯一啟用的 GCASH 銀行 404，所以 5.5 第 7 項「提款需綁手機」要看的代理組別在這裡），會員 3,747、最近一次建會員 2024-11；`vworldvn`（5，已停用）、`CJ7`（14，已停用）各有 USDT 通道；`TPower`（組織 21，是 VWorld 庫裡的一棵測試樹，2 名會員）有 1 條 USDT 通道與 1 列銀行。
  - **部署面**：最新一次部署 pipeline 有七個部署目標：`tp-wz3-online (vworldmy)`、`tp-wz3-online (wintoto)`、`gff-vwb2c`、`gff-tpower`、`gff-yes2win`、`gff-alfawin77`、`vplus33 baremetal`（`gff-vwb2c` 就是 Me77，見下一條）。vworldmy 與 wintoto 是同一個叢集的兩個部署，**只有 vworldmy 有 migration 工作、wintoto 沒有**，與「兩站共用同一套資料庫」相符。vworldsg 沒有自己的部署工作，應該由 vworldmy 或 wintoto 的部署服務，**沒有驗證**。
  - **`vwb2c` 就是 Me77，不是第七個站**（2026-09-22 查清）。部署專案裡的 `gff-vwb2c` 是 Me77 的 GCP 專案名：`dbc list` 顯示 `gff-vwb2c` 這個專案的從庫掛在本機埠 3406，而白標登記檔裡 Me77 的 `dbcPort` 正是 3406；用 Me77 的唯讀連線查，組織表只有 `gff` 與 `ME77`，登記檔的 Me77 根代理 100005 的帳號就是 `me77`。所以部署 pipeline 的七個部署目標對到的資料庫正好是六套：`tp-wz3-online` 一套（vworldmy 與 wintoto 共用，vworldsg 也在裡面）、`gff-tpower`、`gff-yes2win`、`gff-vwb2c`（Me77）、`gff-alfawin77`、`vplus33 baremetal`，與六站 MCP 一一對應，**沒有第七個站**。AI 當天曾把 vwb2c 誤判成第七站並寫進計劃，隨即查證更正；教訓是部署名稱與白標名稱對不上時，先用 `dbc list` 的埠號對白標登記檔，不要用名稱猜。
  - 工單標籤：`站方平台:V-World` 之外，Wintoto 若有自己的站方平台標籤要一併掛；`GFF USDT` 與 `casinos365` 有沒有對應標籤，沒有查。
- 5.2 的「最後一批不設上界會擋住建單」與 5.3 的「helm 兩個發行單位之間有沒有互相卡住」，都是推論，沒有實測。
- GCASH／PayMaya 的支付通道屬於哪幾家金流商，沒有查（2.6 只查了 USDT／TON）。
- 工作順序總表 `multi-currency-wallet-roadmap.md` 工作包 1 有三個數字與本計劃對不上：舊前台「九支分支」對本計劃的 11 個、「14 個語系檔」對 12 個、「三套快取」對兩組鍵。會審實測 APIs 的語系檔是 12 個，這一項是總表過期；另外兩項沒有複驗。總表的權威順序在本計劃之上，要回寫總表或在這裡註明差異來源，尚未處理。
- 決議清單 `multi-currency-wallet-decisions.md` 第 297-299 行（09-07 補查段）還寫著「GCASH／PayMaya 主檔列依決議三保留」，沒有像其他被撤回的段落那樣加上 09-17 的撤回註記，讀起來與決議㉛ 相反。它講的是遊戲商側的幣別字串，與本計劃的對象不同，但措辭會被誤讀。修正屬於決議清單，尚未處理。

## 修訂紀錄

由舊到新排列。

- 2026-09-18：引用面盤點完成（三線並行，現在的第二到四節）。命令設計待第七節裁決後補。
- 2026-09-18：第七節第 1 題依 Timo／Rhyse 審查改寫，TrustPay 最小改法已進版控（擋單、log、測試）。
- 2026-09-21：第七節四題全數裁定，另裁三個連帶的子題（2-1、2-2、3-1），並更正第 2 題原稿的理由；5.1 第 10 項、5.2 的資料動作依裁決改寫。
- 2026-09-21：5.2 開頭新增「會寫入的資料表總表」（8 張表與連帶產物）。
- 2026-09-21：RB 裁決 FON 的兩條支付通道（VWorld 106／107）一併停用，新增為第七節第 2-2 題。
- 2026-09-21：給 PM 簽收清洗範圍與影響的工單草稿另存成 [`currency-master-cleanup-pm-ticket-draft.md`](currency-master-cleanup-pm-ticket-draft.md)，尚未送出。
- 2026-09-21：5.2 補上 FON 兩條支付通道的現況（106 啟用、107 已停用），資料來自銀行收斂那條線當天的唯讀查詢。
- 2026-09-21：**圓桌會審後大改**。四位審查官（金流正確性、事故應變、舊系統與查詢效率、文件一致性）審過本計劃，結論是盤點站得住、執行與回滾的設計沒寫。這次改了：
  - 5.2 的執行步驟整段重寫：順序改成「主檔新列先在」、主檔與配置表包在同一個交易、大表改走主鍵區間分批、更新時保住 `updated_at`。
  - 5.3 補上失配時的實際後果、完整的暫停清單（原稿漏了提款與代付、排程與同步類命令）、前置條件。
  - 新增 5.4「改壞了怎麼回來」與 5.5「dry-run 報告必出的項目」。
  - 更正 AI 起草時寫錯的一句：第七節第 2 題的「還原靠整表備份」在程式上不成立。
  - 新增 2.6：會審指出四個沒人驗過的前提，當天用六站唯讀查詢補上，四個都成立。
  - 2.2 的數字改成 09-21 重查後的逐站數字，修掉四處自相矛盾（下界是三站不是四站、TPower 沒有 GCASH／FON 的訂單類型列、FON 通道以幣別 id 查是 0 筆、`cash_flow_banks` 的站別）。
  - 3.4、4.1、4.3、4.4 補上會審盤到的缺漏；5.1 第 3 項的分支範圍寫錯，已更正；3.4 的 Enum docblock 行號寫錯，已更正。
  - 四處交叉引用整組差一節，已修。
  - 第七節新增「會審後待裁」六題，RB 尚未裁。
  - **送 PM 簽收的條件變了**：除了 PM 簽收範圍之外，5.4 的回滾命令要先寫好並演練過，第七節待裁的六題要先有答案。
- 2026-09-21：**會審後的六題 RB 全數裁定**，寫進第七節。六站分兩次發版、逐站做；大表同窗口分兩步、中間設檢查點；代理後台 204 分支掃過零命中、會員 App 列入第一站驗收；自動遞增起點不調；七列真刪維持；三方回調不擋、靠設計讓它安全。連帶改了 5.2（檢查點、每批先撈 id 再更新、收尾補掃）、5.3（分兩次發版的做法與依據、回調不擋的理由）、5.4（釐清備份服務還原得了七列、還原不了重編的三列），新增 5.6 第一站驗收。**送 PM 的條件剩兩項**：回滾命令寫好並演練過；第 1 題與第 6 題這兩個還沒審過的設計先過審查。
- 2026-09-21：**兩個未審設計專審完成，連帶三題 RB 裁定**，寫進第七節「專審後裁定」。兩個設計都是有條件通過。這次改了：
  - 5.2：主檔三列改成就地改 id，撤回「新列先建」的順序要求；備份必須在交易外面；寫明哪些表走 Eloquent、哪些走 query builder，以及通道 106 的中止條件；第二段的更新語句加上舊幣別 id 的條件與列數比對；清快取改成逐鍵刪、第一段 commit 後就先清一次，補上兩組漏掉的鍵；標出 `order_histories` 的寫入者沒查、各站索引要逐站確認。
  - 5.3：分兩次發版的成立依據改寫（幣別代碼來自資料庫、不是 Enum）；補上三個條件（Dev 當第零站、舊系統各自處理、第一版是回滾地板）；提款暫停的理由改寫、關門條件擴大；補上 CCPayment 補發排程要顯式停、排程專案四支開商戶命令整段禁跑；「不擋回調」的理由改掉兩條，補上 CCPayment 與 FPay 兩條會碰幣別的回調路徑，並標明論證只涵蓋 APIs。
  - 5.4：回滾改成反向更新；對照與時間戳寫進資料庫表；新增「第一版是回滾地板」。
  - 5.1：第 1 項的常數改值延到第二版同批；第 3 項的語系鍵改成新舊並存；第 5 項補上第一版單獨發、第二版明寫 `status`；新增第 13 項（審查官建議、RB 未裁的兩處小改）。
  - 5.5 新增六個 dry-run 項目；第八節換成專審留下的未驗證項目；2.6 補上 `uk_banks_active` 不在 repo 的 migration 裡。
  - **送 PM 的條件現在是**：回滾命令寫好，並在 Dev 演練過；查清楚哪些 USDT／TON 通道的回調走舊系統，並補做分析；查清楚 `order_histories` 的寫入者。
- 2026-09-21：**上一條的後兩個條件當天查清**（六站唯讀）。`order_histories` 的寫入者是資料庫層的每日排程事件（20:00 UTC 把兩週前的存款單原樣複製進去），不在任何 repo 裡；補掃一次就夠，但第二段不能跨過這個時間，已寫進 5.2 與 5.3。USDT／TON 通道近 90 天的存款單幾乎全部由 APIs 建立、回調也回到 APIs，已寫進 5.3。另外掃了 VWorld 正式庫裡 repo 沒有的預存程序與排程事件，碰到幣別 id 的只有三支原樣複製的搬移程序，其餘五站沒有掃（第八節）。**送 PM 的條件剩一項：回滾命令寫好，並在 Dev 演練過。**
- 2026-09-22：RB 裁定 5.1 第 13 項的兩處小改都做（查不到就不快取；補判空），與清洗前的 APIs MR 同批發。**送 PM 的條件 RB 確認為兩項**：回滾命令寫好並在 Dev 演練過；V-World 共用資料庫底下的站點清單查清楚（工單要掛站方平台標籤、要寫清會動到哪些站，清單沒查清工單寫不完整）。昨天寫「剩一項」是 AI 整理時漏了後者，與工單草稿文末的核對清單對不齊，這次對齊。
- 2026-09-22：**V-World 共用資料庫的站點清單查清**（正式庫唯讀整庫＋部署專案的 pipeline），寫進第八節與工單草稿第二節。目標幣別的配置列落在 8 個組織，其中 `GFF USDT`（TON、FON 所在）仍在營運、`casinos365`（GCASH／PayMaya 銀行所在）要納入工單。部署專案裡的 `gff-vwb2c` 曾被 AI 誤判成第七個站，同日用 `dbc list` 的埠號對白標登記檔查清：**它就是 Me77**，七個部署目標對到的正好是六站的六套資料庫，沒有第七站。**送 PM 的條件剩一項：回滾命令在 Dev 演練過。**
