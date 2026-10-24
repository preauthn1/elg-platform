# 第零期契約：幣別與匯率

> **定版狀態**：✅ **全文定版，第零期交付完成**（2026-09-01）。六個章節與四個附錄都已落定，
> 後續各期可直接引用。
>
> - 幣別與匯率（第一～三節）已定版
> - 注單表清單（第五節）142 筆已定版
> - 寫入者清單（第四節）三段共 308 個寫入點全部定版，並行防護（`fencing`）逐筆指定（09-18 依 registry 實數更正：原寫 141／307／26／21，`tables.php` 現為 142 筆（`registry_only` 3→4）、`writers.php` 現為 308 個寫入點＝轉檔程序 73＋本專案 27＋四個舊 repo 208，`manual:` 22）
> - **Gate B 已於 2026-08-31 由 RB 拍板正式解除**（第六節），第四期可以開工
> - 三支稽核命令的六站實跑結果見附錄 A、C、D
>
> 🔴 **定版不代表沒有待辦。** 本文帶著以下未解事項，接手各期前先看這張表：
>
> | 未解事項 | 出處 | 誰接 |
> |---|---|---|
> | 37 筆注單表的 `owner` 標「待查」 | 第五節 | 第三期前置 |
> | 12 筆注單表的 `owner` 標「未驗證」（同名排程含寫入呼叫但表名未逐筆實證） | 第五節 | 第三期前置 |
> | 跨 repo 四類合計 177 與 `external` 段 208 對不起來，差額 31 筆歸屬未查明 | 第四節 | 要當硬證據引用前須重新覆核 |
> | 幣別主檔補齊（`PHP`／`USD`／`CNY`）＋ `HKB` 語意裁決 ＋ **輸入端驗證** | 附錄 A | 第二期 |
> | `rate = 0.01` 語意未查明（VWorld 2 筆、1 筆啟用中） | 第三節 | 動到那兩筆商戶前必須先問清楚 |
> | `bolakk4d0217`／`bolakk4d_0217` 兩張零列備份表待 ops 確認清理 | 附錄 C | ops；清掉後六站表集合才一致 |
> | `ADMIN_copy_total_bet_record_bak1_to_orig` 加欄位即斷（上線阻斷點） | 第四節「範圍界線」 | 第三期上線檢查清單 |
> | 第四期開工要帶的三項並行風險 | 第六節 | 第四期 |
>
> 最後更新：2026-09-01

---

## 一、這份契約管什麼

凍結兩件事，讓後續五期不必各自解讀：**幣別怎麼表示**、**`rate` 這個欄位到底是什麼意思**。

第零期的完整交付還包含「哪些是注單表」與「誰能寫注單統計表」。兩者都已定版
（第五節與第四節），並行防護與覆蓋率稽核也已完成。

### 交付現況

| 交付項 | 產出 | 狀態 |
|---|---|---|
| 幣別代碼對照表 | `database/data/currency_aliases.php` | ✅ 已定版 |
| 對照表的守衛測試 | `tests/Unit/Data/CurrencyContractTest.php` | ✅ 6 passed |
| 遊戲商幣別稽核命令 | `artisan currency:audit-vendor-info` | ✅ 六站實跑完成，結果見附錄 A |
| 注單表 canonical 清單 | `database/data/bet_records/tables.php` | ✅ 142 筆已定版 |
| 注單表結構稽核命令 | `artisan bet-record:audit-tables` | ✅ 六站實跑完成，結果見附錄 C |
| 寫入者清單 | `database/data/bet_records/writers.php` | ✅ **308 個寫入點已定版**（轉檔程序 73＋本專案 27＋四個舊 repo 208） |
| 寫入者覆蓋率稽核命令 | `artisan bet-record:audit-writers` | ✅ 退出碼 0，結果見附錄 D |

### 名詞說明

| 名詞 | 意思 |
|---|---|
| **注單統計總表** | `total_bet_records`，各遊戲商注單匯總後的單一大表 |
| **注單彙總表** | `summary_bet_records`，注單統計總表再往上匯總的報表來源 |
| **標準幣別** | 收錄在 `currencies` 主檔的 ISO 4217 代碼（`MYR`／`VND`／`IDR` 等） |
| **計價單位變體** | 同一種幣別的不同計價尺度，例如千分制的 `KVND`（1 單位 = 1,000 越南盾） |
| **Gate B**（簡報稱 **Gate 2**，同一個閘門） | 開始改造注單寫入者之前必須通過的閘門。現行判定是**四項全數滿足**：找全所有寫入者 → 指定各自的 owner → 同一遊戲商×時段的並行防護 → 驗證覆蓋率。⚠️ 舊定義「等跨專案排程遷入本專案」已被推翻 |
| **RD-Studio-01** | 另一個專案（另一個 repo），與本專案同時寫注單彙總表，是並行防護的另一方 |
| **canonical 清單** | 權威版清單。此處指「哪些資料表算注單表」的唯一定義，用來對照資料庫實況找出漏登記或已廢棄的表 |

---

## 二、幣別的三層語意

同一個「幣別」在系統裡有三種表示方式，各自服務不同對象。**混用是後續各期最容易出錯的地方**，所以先分清楚。

| 層級 | 用什麼表示 | 值域 | 誰在用 |
|---|---|---|---|
| 會員／組織層 | `organizations.currency_id` → `currencies.id` | 只用標準幣別 | 錢包幣別的來源 |
| 遊戲商對接層 | `vendor_infos.currency`（自由字串）＋ `vendor_infos.rate` | 含計價單位變體（`KVND`／`KIDR`／`IDR2`／`kIDR`） | 送給遊戲商的協議幣別 |
| 統計層 | `total_bet_records.currency_id`（第三期新增） | **只允許標準幣別** | 報表的分組維度 |

**這張表的兩個限定條件**（來自已拍板決議，引用時不可漏）：

- **幣別的權威來源是 `vendor_infos`，一幣別一商戶**。幣別綁在遊戲帳號（按商戶建立）而不是會員狀態，所以「投注當下與回調當下幣別不同」的時間差問題不存在。
- **歷史資料的幣別要「填對」，不是填預設值**。遷移時以 `INSERT ... SELECT` join 各遊戲商的帳號表取得 `vendor_infos.currency_id`。填預設值只在單一幣別站台才正確——VWorld 現在就是多幣別營運，填預設會把既有的其他幣別注單全部標錯。

### 三條規則

**規則一：`vendor_infos.currency` 這個字串欄位一律不動。**

它已經流進對外 API。全專案實測 **57 處／29 檔**在讀它並送給遊戲商：

| 層 | 檔數 | 意義 |
|---|---|---|
| 單一錢包 controller | 12 | 直接回傳給遊戲商，改值等於改 API 回應內容 |
| 遊戲商 Driver 與 Service | 14 | 同上 |
| 轉帳錢包 controller | 2 | 同上 |
| 後台顯示標籤 | 1 | 純顯示，唯一非對外的用途 |

其中 **2 處是拿它擋請求**（比對遊戲商傳入的幣別，PHP 字串比較大小寫敏感）——改了寫法而遊戲商端沒跟著改，該商戶的請求會開始被拒絕。

⚠️ 這個數字曾被低估為「20 餘處」或「13 處」，只算了單一錢包 controller。**做風險評估時用 57 處／29 檔**。

**規則二：新增的 `vendor_infos.currency_id` 一律指向標準幣別。**

`KVND` 商戶的 `currency_id` 填 `VND`（3），不是 `KVND`（5）。字串欄位保留 `KVND` 送給遊戲商，兩者外觀不一致**是設計、不是資料錯誤**，必須寫進欄位註解。

**規則三：`currencies` 主檔只收 ISO 4217 標準碼。**

千分制與仙位一律用 `rate` 表達，不佔獨立的幣別編號。已存在的變體編號（4／5／7／8）**不刪列、不加資料庫旗標**，改在 `CurrencyEnum` 加「可作為會員錢包幣別」白名單來排除。

> 白名單本身是第二期的交付，第零期只凍結規則。
>
> **為什麼保留變體代碼**：有代理商明確希望以千分制計價，所以 `KVND`／`KIDR` 是「計價單位」而不是「幣別」，尺度差由 `rate` 吸收。這是業務需求，不是技術遺留。

---

## 三、`rate` 的語意：一個欄位，兩種意思

`vendor_infos.rate`（`decimal(11,5)`，欄位註解只寫「轉換率」）**同時承擔計價單位換算與跨幣別匯率換算兩種語意，從欄位本身無法區分**。

### 六站全量實測（2026-08-31）

| `rate` | 倒數 | 語意 | 分佈（總數／啟用中） | 出現的幣別 |
|---|---|---|---|---|
| `0.00025` | 4,000 | 🔴 **跨幣別匯率** | Alfawin77 3／3 | `MYR`（站台幣別為 `IDR`） |
| `0.00100` | 1,000 | 計價單位（千分制） | VWorld 28／1、Alfawin77 8／8 | `IDR`／`kIDR`／`KIDR`／`KVND`／`MMK`／`VND` |
| `0.01000` | 100 | ⚠️ 語意未查明 | VWorld 2／1 | `MMK`／`THB` |
| `1.00000` | 1 | 無換算 | 六站合計 1,165／781 | 全部幣別皆有 |
| `100.00000` | 1/100 | 計價單位（仙位） | VWorld 1／1 | `MYR` |

六站商戶總數 1,207 筆，與幣別稽核命令的實跑結果逐項吻合。

⚠️ **`rate = 0.01` 這一組（VWorld 2 筆，`MMK`／`THB`）語意未查明**，先前的規劃文件只記載了四種值、沒有這一組。它可能是百分制計價，也可能是別的換算，**未驗證，需人工確認**。動到這兩筆商戶之前必須先問清楚。

### 由此導出的兩條契約

**契約一：注單統計總表存「會員錢包幣別」的金額。**

換匯在遊戲商層（Driver）入境時就完成，進了總表之後全程不再換算。現況的計算式一行都不用改。

**契約二：注單統計總表必須逐筆快照 `rate`。**

`rate` 是後台可以改的活資料。改了之後，歷史注單無法回推遊戲商端的原始金額。這是「存會員錢包幣別」這個選擇唯一需要的配套，**不做就等於放棄對帳能力**。

### 兩件登記在案、但不在 第零期做的事

1. **改寫 `vendor_infos.rate` 的欄位註解**，從「轉換率」改為「對站台／會員幣別的換算率，兼含計價單位（千分、仙位）與跨幣別匯率兩種情形」。改註解屬於資料表變更，應排在第二期。
   ⚠️ **但第二期的十一個 task 目前沒有任何一項在做這件事**（2026-08-31 核對）——它被指派給了一個不包含它的計劃。執行第二期加 `currency_id` 欄位那個 task 時要順手補上，否則這件事會直接掉出範圍。
2. **`currencies` 表也有 `rate` 欄位**，但全站皆為 1、實際未使用；另有 `deposit_rate`／`withdrawal_rate` 兩欄（資料表註解為「代收匯率／代付匯率，主要貨幣固定為 1」），來自金流通道重構的 migration，**全 repo 零讀寫 ⇒ 已定義但尚未啟用，不是死欄位**（2026-08-28 查明）。這三欄與本契約無關，列出只為避免日後有人以為漏掉。

---

## 四、寫入者的歸屬與並行防護

✅ **清單與並行防護皆已定版。** 單一來源是 `database/data/bet_records/writers.php`，分三段：

| 區段 | 內容 | 狀態 |
|---|---|---|
| `sp` | `database/procedures/` 的 stored procedure（**限 `SP_` 前綴**，見下方範圍界線） | ✅ **73 筆已定版** |
| `php` | 本 repo 的 PHP 寫入路徑 | ✅ **27 個寫入點已定版**（09-18 依 registry 實數更正，定版當時 26） |
| `external` | 跨 repo 的寫入路徑 | ✅ **四個舊 repo 共 208 個寫入點已定版** |

### 這份清單的範圍界線

**`sp` 段只收 `SP_` 前綴的 stored procedure**（2026-09-01 RB 裁定）。生產庫裡另有 `ADMIN_` 前綴的
維運與災難復原工具，**不屬於注單寫入者改造的對象**，因此不在這 73 筆內。

2026-09-01 六站全量查證（`information_schema.ROUTINES`，唯讀），非 `SP_transfer_bet_record_*`
而確實寫 `total_bet_records` **本表**的 routine 共 3 支：

| routine | 動作 | 六站分佈 | 歸屬 |
|---|---|---|---|
| `SP_transfer_event_record_pgs` | INSERT | 六站皆有 | ✅ 已收在本清單（第 73 筆） |
| `ADMIN_Delete_Total_Bet_Record_Mars` | DELETE | 六站皆有 | ⛔ `ADMIN_` 前綴，不納入 |
| `ADMIN_copy_total_bet_record_bak1_to_orig` | INSERT | TPower／Yes2Win／Alfawin77／VPlus33 四站有，VWorld 與 Me77 無 | ⛔ `ADMIN_` 前綴，不納入。**但第三期加欄位時必須處理**，見下方 |

🔴 **`ADMIN_copy_total_bet_record_bak1_to_orig` 是第三期的上線阻斷點，不是第四期的 writer 問題。**
它的語句是 `INSERT INTO total_bet_records SELECT * FROM total_bet_records_bak1`（位置對應、非具名欄位），
TBR 加欄位後備份表欄位數不符會**直接報錯**。風險在於它是災難復原用的搬回工具、平時不執行，
**會在最需要它的時候才發現壞掉**。簡報第 9 節已將它列為上線阻斷點；第三期的上線檢查清單必須帶上它
（修法：補欄名，並為影子表與備份表一併加欄位）。

⚠️ 查證時排除了三支假陽性，記錄下來以免重查：`ADMIN_copy_total_bet_record_to_bak` 與
`ADMIN_move_total_bet_record` 寫的是備份表 `total_bet_records_bak` 而非本表；
`ADMIN_DELETE_total_bet_record` 的 DELETE 語句在定義中是註解掉的（`#DELETE`）。

### SP 段的實測結論

**73 支寫 `total_bet_records` 的 SP**（72 支 `SP_transfer_bet_record_*` ＋ `SP_transfer_event_record_pgs`），
**全部是「具名欄位 INSERT ＋ `ON DUPLICATE KEY UPDATE`」**。

🔴 **具名 INSERT 不會帶上新增的欄位**，所以第四期加幣別欄位時，**每一支的欄位清單與 SELECT 都要改**，
不能只改表結構——否則該路徑寫進去的幣別會永遠是預設值。這推翻了早期「轉檔 SP 零改動」的結論。

🔴 **觸發端幾乎全在舊系統**：

| owner | 支數 | 說明 |
|---|---|---|
| `RD-Studio-01` | 69 | 64 支靜態 `DB::select('call SP_xxx(...)')` ＋ 5 支動態組名 |
| `API_External` ＋ `MySQL-Event` | 1 | `SP_transfer_bet_record_pg`，唯一雙觸發 |
| `manual` | 3 | 真的無呼叫者，`evo2`／`new_ia`／`vpower_new` |

🔴 **靜態 grep 會漏掉動態組名的呼叫。** RD-Studio-01 有 6 個檔案以 `vendors.tname` 組出程序名再 CALL，
初版據此把 8 支誤判為無呼叫者，追查轉檔進度時才發現其中 5 支一直有人在呼叫。
剩下 3 支之所以確定無呼叫者，是因為它們的名稱**對不上任何遊戲商的 `tname`**，動態組名組不出來。

🔴 **判斷某支程序是否還活著，要看排程宣告，不能只看轉檔進度。** 兩者的關係比直覺複雜：

| 證據 | 能證明什麼 | 不能證明什麼 |
|---|---|---|
| 舊系統的排程宣告（未被註解的那些） | **誰在觸發它、多久觸發一次** | — |
| `summary_control_tables.stat_end_time` | 該遊戲商的注單資料還在流動 | **不等於這支程序在跑**——該欄位由彙總命令更新，資料可能由別的路徑寫入 |
| `updated_at` | 什麼都不能證明 | 每天都會被更新，即使轉檔進度停在兩年前也一樣 |
| git 時間、`ROUTINE.CREATED` | 什麼都不能證明 | 兩者都是批次匯入的同一時刻 |

⚠️ **例外：5 支動態組名的程序（`gfg2`／`bolakk4d`／`lfc888`／`m8bet`／`wbet`）自己會回寫
`stat_end_time`**，對這 5 支而言那個欄位就是它們的執行紀錄、可以直接採信。其餘 68 支不回寫。
2026-08-31 逐支確認過，別把這個例外推廣到全部。

🔴 **而且必須六站都查，單站會得到相反的結論。** 2026-08-31 實測三個遊戲商的轉檔進度：

| 遊戲商 | VWorld | TPower | Yes2Win | Alfawin77 | Me77 | VPlus33 |
|---|---|---|---|---|---|---|
| `gfg2` | 2024-05-30 | **2026-08-30** | **2026-08-30** | 2024-05-30 | 2024-05-30 | 2026-03-28 |
| `bolakk4d` | 2024-05-30 | **2026-06-10** | **2026-06-15** | **2026-04-30** | 2024-05-30 | 2024-05-30 |
| `ia` | **2026-08-30** | 2024-05-30 | 2024-06-19（已停用） | 2024-06-19 | 2024-06-19 | 2025-08-25 |

只看 VWorld 會判定 `gfg2` 與 `bolakk4d` 停滯 15 個月、可以廢棄——**那是錯的，它們在別站活得好好的**。
同一個遊戲商在各站的啟用狀態、營運名稱（`bolakk4d` 就有 NEW4D／T-LOTTERY／A-LOTTERY／4D 四種）
與轉檔進度都可能不同。

⚠️ **`APIs` 一支都沒有。** APIs repo 內對這些 SP 只有兩處提及，都是註解不是呼叫。
⇒ 第四期改 APIs 這邊**不會動到這些 SP 的觸發端**。

⚠️ MySQL 排程的權威來源是 `information_schema.EVENTS`，六站複驗一致：每站都只有
`Event_Transfer_Bet_Record_Pg` 一個排程碰轉檔 SP，全部 `ENABLED`。

⚠️ `database/procedures/` 內**沒有任何 SP 寫 `summary_bet_records`**，SBR 的寫入者全在 PHP。

### APIs 側 PHP 寫入路徑的實測結論

`app/` 全掃得到 **17 個候選檔案**（同時出現彙總表符號與寫入動詞），逐檔覆核後
**14 個是真的寫入者、共 26 個寫入點**（定版當時的掃描結果；registry 現為 27，新增的一筆未回溯到本節逐類覆核），3 個只讀不寫。`database/`、`routes/`、`config/`
三處實測皆為 0 個候選。

| 分類 | 寫入點 | 主要位置 |
|---|---|---|
| Service 層主路徑 | 5 | `TotalBetRecordService`、`EventTotalBetRecordService`、`BetRecordFetchService` |
| 彙總排程 | 2 | `SummaryBetRecordService`（delete-then-insert 成對） |
| 單一錢包回調 | 11 | `WcasinoSW`、`JokerBaccaratSw`、`MotivationSW`、`OnebxSW` |
| 對帳與補單工具 | 8 | `Revert`、`PlayTech`、`PragmaticPlay` 三支 |

🔴 **`delete-then-insert` 是這裡的常見樣式**（`WcasinoSW` :548→:550、`MotivationSW`
`Credit`／`Debit` :143→:145、`OnebxSW` :157→:159、`SummaryBetRecordService` :375 與 :369）。
第四期在 delete 條件加幣別時，**成對的 insert 也要同時改**，只改一邊會刪不乾淨或重複寫入。

🔴 **覆核有兩層盲區，兩層都實際踩過**：符號只認 `Model::` 會漏掉 `new Model`；
就算符號抓對了，**寫入動詞也可能離符號十幾行**（`$totalBetRecord = TotalBetRecord::lockForUpdate()`
拿到實例後才 `->delete()`），第一版因此把 5 個檔案誤判成非寫入者。**必須追變數。**

⚠️ 清單的 key 是 `路徑:行號`，**行號會隨代碼變動漂移**。測試只保證該行還在檔案範圍內，
每筆的語句摘要記在 `note` 供漂移時比對。

### 跨 repo 寫入路徑的實測結論

四個舊 repo 全部盤點完畢，共登記 **208 個寫入點／97 個檔案**（RD-Studio-01 9＋API_Agent 3＋API_External 69＋API_Frontend 16）。

> ⚠️ **09-03 補充**：下表「會員錢包」三列合計 126 是舊盤點口徑，已被 09-03 四 repo 重盤取代（222 個、單分支下界；新舊口徑的分 repo 明細一律以影響盤點 7.1 為單一來源，RD-Studio-01 該表有登記、此處為 0），待第五期重登記；注單類各列不受影響。詳見 [`multi-currency-wallet-impact-inventory.md`](plans/2026-08-13-multi-currency-wallet/multi-currency-wallet-impact-inventory.md) 第七節。

| repo | 寫入點 | 目標表 | 主要位置 |
|---|---|---|---|
| RD-Studio-01 | 4 | 注單彙總表 | `Services/SummaryBetRecords/Base.php`（新版）、`Console/Commands/SummaryBetRecordCommand.php`（舊版），各一組 delete-then-insert |
| RD-Studio-01 | 10 | 注單統計總表 | 兩支取消注單同步、三支遊戲商拉單命令的批次寫入與逐筆更新、一支派彩時間補寫、一支補單 |
| API_Agent | 7 | 會員錢包 | `TransactionController`（贈送／扣除禮碼）、`EventController`（活動發獎）、`AgentWalletController`（代理轉點） |
| API_External | 56 ＋ 11 | 注單統計總表、活動注單統計總表 | 43 個檔案，全在單一錢包回調路徑（下注／結算／取消／退款） |
| API_External | 83 | 會員錢包 | 41 個檔案，禮碼與遊戲錢包的扣加值 |
| API_Frontend | 1 | 注單統計總表 | `SummaryControl.php`（見下方死碼註記） |
| API_Frontend | 36 | 會員錢包 | 15 個檔案，活動獎勵、任務、優惠券、VIP、綁機 |

🔴 **注單統計總表的改動大宗在 API_External 而不是本專案**：該 repo 有 67 個即時寫入點（43 個
檔案），本專案只有 26 個（現 27）。第四期的工作量估算要以此為準。

⚠️ **四個舊 repo 都沒有任何 PHP 寫注單彙總表**——該表的寫入者只有本專案與 RD-Studio-01 兩家，
與轉檔程序段的結論一致。

✅ **注單彙總表的刪除條件三邊逐字相同**——都是 `vendor_id` ＋ `summary_time` ＋ `correction != 1`：

| 位置 | 檔案 |
|---|---|
| APIs | `app/Console/Commands/SummaryBetRecord/SummaryBetRecordService.php:375` |
| RD-Studio-01（新版，76 個遊戲商共用） | `app/Services/SummaryBetRecords/Base.php:385` |
| RD-Studio-01（舊版） | `app/Console/Commands/SummaryBetRecordCommand.php:3812` |

這排除了 2026-08-27 決議六原本標「未驗證」的風險項（兩邊條件若不一致，加幣別欄位會產生
靜默壞資料）。🔴 反過來說，**第四期加幣別時這三處要同時改**，只改一處會刪不乾淨。

⚠️ **RD-Studio-01 的注單彙總是新舊雙軌**：`config/summary_bet_record.php` 登記 76 個遊戲商走
新版 service，其餘由每日 01:00 的重跑排程分流到舊版單檔命令。兩軌邏輯相同但程式碼各一份，
**第四期兩份都要改**。另外舊版那支的程式碼是由 `Tools/Summary/GameVendor.php` 用 `File::put`
產生的，**產生器的模板也要一起改**，否則之後新增遊戲商會產出沒有幣別的舊寫法。

🔴 **原本的三項預設都與實測不符**：

| 原本的預設 | 實測 |
|---|---|
| 77 支遊戲商專屬彙總 service 各自 delete-then-insert，要用群組條目登記 | 77 個檔案裡**只有父類 `Base.php` 有寫入動詞**，其餘 76 支不寫也不覆寫 ⇒ 登記 2 筆而非 77 筆 |
| `SummaryAgentSettlementCommand` 是彙總表寫入者 | **不是**。它讀彙總表產代理結算，寫入全打在結算類的表；`New4d2026SummaryBetRecord` 同理，只轉呼叫重跑命令 |
| 會員錢包的直寫點在 `MemberController` | **不是**。`memberTransaction` 路由指向 `TransactionController@member`，贈送禮碼在該檔 :562-582 |

🔴🔴 **只用表名字串掃會員錢包會漏掉一半的寫入者**。`TransactionController` 與 `EventController`
全程使用 Model 類名、完全沒出現表名字串，因此不在字串掃描的候選內——而它們正是真正的寫入點。
反過來，候選裡有四個檔案只是讀取或註解。⇒ **表名與 Model 類名要兩路都掃**，這與轉檔程序段
漏掉動態組名呼叫、APIs 段漏掉 `new Model` 是同一類錯誤。

⚠️ API_Agent 的 11 個注單彙總表候選檔（報表與結算 controller）**全部只讀不寫**，逐檔追過變數
綁定，寫入動詞都打在會員、代理、獎金等其他表。

🔴🔴 **補做 API_External／API_Frontend 時又踩到三類新盲區，靜態掃描至此已知十類**：

| # | 盲區 | 這次的實例 |
|---|---|---|
| 7 | **型別提示的函式參數不是賦值**，追賦值追不到 | `payByRollingChip(?MemberWallet $rollingChipWallet)` 內的寫入，檔案裡從沒有 `$rollingChipWallet = ...`。補上參數型別後多抓 **10 個寫入點、3 個原本完全沒登記的檔案** |
| 8 | **實例被當參數傳進沒有型別提示的 helper**，寫入發生在 helper 內 | `updateAddBalance($wallet, $amount)` 內的 `$wallet->update()`，再補 **10 個寫入點**（五支 controller） |
| 10 | **實例存進物件屬性**（`$this->wallet = ...`），只追區域變數賦值的掃描看不到 | EventVpower 三支與 MegaWin 三支，再補 **7 個寫入點** |

⚠️ 第 8、10 類都會遇上**多型**：helper 依呼叫端傳入的實例，會寫 `members`、`member_wallets` 或
`promotion_wallets` 三張表；MegaWin 的 `$this->wallet` 則是代理商設為多錢包時綁 `MemberWallet`、
否則改指 `Member`。**只有會員錢包那條路徑算本表的 writer**，傳 promotion wallet 的 2 個寫入點
因此不登記。⇒ 這類寫入點改造時要連呼叫端與屬性賦值一起看，不能只看方法本體。

（第 9 類是程式碼產生器的模板，見下一段。）

🔴 **單一錢包 controller 有程式碼產生器**：`API_External` 的 `resources/stubs/controllers/vendors/scripts/`
（`Settle.stub` 含 `TotalBetRecord::updateOrCreate`，`Bet`／`Refund`／`Unsettle.stub` 含錢包鎖定與
更新），由 `make:stub-controller-vendor` 與 `append:stub-script-controller-vendor` 使用。**第四期
改單一錢包的寫入邏輯時模板要一起改**，否則之後新接的遊戲商會產出沒有幣別的舊寫法。這與
RD-Studio-01 的 `Tools/Summary/GameVendor.php` 是同一類問題，**已是第二次出現**。

⚠️ `API_Frontend` 的 `SummaryControl.php:60` 寫的是**假資料**（`member_id = 1`／`reference_no =
'abc123'`），且該 controller 在 `routes/` 內查無任何路由 ⇒ 手動測試殘留的死碼。仍然登記，因為
它進得了生產環境；要廢棄需另案處理。

**盤點的反向驗證做了兩層**：

1. 「寫入型靜態呼叫的總次數」對帳——`TotalBetRecord::create` 28/28、`EventTotalBetRecord::create`
   8/8、`MemberWallet::create`（API_Frontend）16/16 全部對得上。
2. **102 個 `MemberWallet::lockForUpdate()` 取實例處逐一追去向**，全部落在四類：直接寫入、傳進
   helper、存進屬性（三者都已登記）、只傳給 `num_add`／`num_sub` 做數值運算（只讀）——沒有第五類。

判定則逐類人工覆核：直接命中 63 筆查語句樣態、變數綁定 97 筆查綁定來源、helper 10 筆查呼叫端、
屬性 7 筆查屬性賦值來源。

⚠️ **這四類合計 177，與 `external` 段的 208 筆對不起來，差額 31 筆的歸屬未查明**（`external`
段扣掉 RD-Studio-01 的 14 筆與 API_Agent 的 7 筆是 187，仍差 10）。這四個數字是掃描腳本
分類後人工覆核的計數，當時未留下與 registry 逐筆對應的紀錄。**這句話是 Gate B 第 1 項
「找全」的證據之一，分母對不上就標「未驗證」——要當硬證據引用前需要重新覆核。**
（第 1 項的其他證據——兩層反向對帳與三組靜態呼叫次數對帳——不受影響。）

### 並行防護（`fencing`）定版

✅ **308 筆全部指定**（定版當時 307，09-18 依 registry 實數更正）。 採「機制＋觸發源」的粒度（2026-08-31 RB 拍板），值一律以機制前綴開頭，
由 `BetRecordWriterRegistryParityTest::test_every_registered_writer_declares_fencing` 守住格式：

| 機制前綴 | 筆數 | 語意 | 值的形狀 |
|---|---|---|---|
| `schedule:` | 78 | 由排程獨佔 | `schedule:<觸發專案>:<命令名>@<排程時機>` |
| `realtime:` | 208 | 即時寫入，沒有時段獨佔 | 後接靠什麼保證不互蓋（唯一鍵或列鎖） |
| `manual:` | 21 | 人工觸發，或排程宣告已停用／查無登記 | 後接判定依據 |

依目標表分佈：

| 目標表 | `schedule:` | `realtime:` | `manual:` |
|---|---|---|---|
| `total_bet_records` | 72 | 69 | 18 |
| `event_total_bet_records` | — | 13 | — |
| `summary_bet_records` | 6 | — | 3 |
| `member_wallets` | — | 126 | — |

> ⚠️ **09-03 補充**：`member_wallets` 這 126 筆是舊盤點口徑，已被 09-03 四 repo 重盤取代（222 個（單分支下界）且非聯集；新舊口徑的分 repo 明細一律以影響盤點 7.1 為單一來源，RD-Studio-01 此處為 0）；該區塊待第五期重登記，見 [`multi-currency-wallet-impact-inventory.md`](plans/2026-08-13-multi-currency-wallet/multi-currency-wallet-impact-inventory.md) 第七節。注單類 181 筆不受影響。

**兩種並行模型，不要混為一談**：

- **批次類**（`schedule:`／`manual:`）獨佔單位是「遊戲商 × 時段」，靠排程時機錯開。
- **即時類**（`realtime:`）獨佔單位是**單筆注單或單一會員**，靠 `(vendor_id, bet_record_id)`
  唯一鍵（注單，APIs 與 API_External 兩側實測同一組鍵）或交易內 `lockForUpdate`（錢包）。
  這類**沒有**時段概念，第四期改造時不能套用批次類的排程錯開思路。

#### 執行規則：修補與災難復原類命令

🔴 **本專案有 5 支這類命令、共 8 個寫入點，執行前必須先確認該遊戲商當下沒有排程在跑**
（`writers.php` 的 `php` 段中 `fencing` 以 `manual:` 開頭者，全數在此）：

| 命令 | 寫入點 | 目標表 | 動作 |
|---|---|---|---|
| `BetRecordRecovery/Revert.php`（回捲） | 1 | `total_bet_records` | delete |
| `Vendor/PragmaticPlay/BackfillStuckBetRecords.php`（補寫卡單） | 2 | `total_bet_records` | update／delete |
| `Vendor/PragmaticPlay/RetrieveRecords/SyncPayoutTimeFromGameRounds.php`（回填派彩時間） | 3 | `summary_bet_records` | insert／update |
| `Vendor/PragmaticPlay/RetrieveRecords/EndRound.php`（補結算局次） | 1 | `total_bet_records` | update |
| `Vendor/PlayTech/ReconcileBetAccumulation.php`（累計注額對帳） | 1 | `total_bet_records` | update |

這 5 支的 `fencing` 都是 `manual:ops-only`——**本專案的 `routes/console.php` 查無它們的排程登記，
所以沒有排程獨佔可依賴，時段互斥完全靠執行的人自己確認**。
確認方式：對照該遊戲商轉檔程序的排程時機（見 `writers.php` 對應條目的 `schedule:` 值），避開那個時段。

⚠️ 這五支裡有四支是 PragmaticPlay／PlayTech 專屬的，**同型的修補命令若日後為別的遊戲商新增，
一樣要登記進 `writers.php` 並標 `manual:`**，否則稽核看不到它。

### 排程層的並行風險

🔴 **6 支轉檔程序各有兩個啟用中的排程在呼叫**，且兩者時段重疊——第四期改造時要一起改，
只改一邊會讓另一邊繼續用舊格式寫：

| 程序 | 呼叫端一 | 呼叫端二 |
|---|---|---|
| `aes` | `aes_bet_record`＠每 5 分 | `aes_tips`＠每 5 分 |
| `ag` | `ag_bet_record_new`＠每 5 分 | `ag_bet_record_daily`＠每日 20:04 |
| `evo`／`c93`／`l4` | `evo_bet_record_new`＠每 30 分 | `evo_bet_record`＠每 30 分 |
| `dct` | `dct_bet_record`＠每 5 分 | `dct_bet_record_new`＠每 5 分 |

⚠️ **`evo`／`c93`／`l4` 三支程序共用同一組呼叫端**，新舊版兩個排程都是每 30 分、時段完全重疊。

🔴 **APIs 與 RD-Studio-01 的注單彙總排程都設在每小時第 30 分**，同一分鐘啟動、寫同一張表，目前只靠「兩邊的遊戲商名單不重疊」自然錯開，**這不是真正的並行防護**。2026-08-26 實測 TPower 近三天有 50 個遊戲商、73,966 列（96.8%）來自 APIs 名單以外的寫入者。若後續要在彙總表的刪除條件加上幣別，而兩個專案改造不同步，會出現「一邊用新鍵刪、一邊用舊鍵寫」的靜默壞資料。

⚠️ **舊系統的彙總是新舊雙軌並跑**：`new_summary_bet_record`（新版 service，76 家）與
`summary_bet_record`（舊版單檔命令，名單外的遊戲商）**都排在每小時第 30 分**，兩支都對
`summary_bet_records` 做 delete-then-insert。加上 APIs 那支，同一分鐘實際有**三支**在寫同一張表。

### 仍需人工確認的部分

⚠️ **22 筆（定版當時 21）`manual:` 裡，有 6 支轉檔程序的呼叫端命令排程全部已停用**：`bola2`、`esport`、
`fishing`、`lucky`、`skybet`、`vplay`。判定依據是全量掃過 RD-Studio-01 `app/` 找出**所有**
呼叫端（不是只看一個），逐一確認排程宣告都被註解或查無登記。

**來源表的資料流動與這個判定互相印證**（下表的日期是六站一致的轉檔進度，故只列一欄）：

⚠️ 這 6 支都**不在**會自己回寫轉檔進度的那 5 支之內，所以下表只能當旁證（「這個遊戲商的注單
早就不流動了」），不能當「程序沒被呼叫」的直接證據——直接證據是上面的排程宣告。

| 程序 | 最後轉檔進度 | 遊戲商狀態 |
|---|---|---|
| `lucky` | 2023-08-20 | 部分站啟用中 |
| `vplay` | 2022-04-25 | 部分站啟用中 |
| `bola2` | 2021-11-28 | 六站皆停用 |
| `skybet` | 2021-05-04 | 六站皆停用 |
| `esport` | 2021-03-28 | 六站皆停用 |
| `fishing` | 從未轉檔 | 六站皆停用 |

**但停用排程不等於不會被執行** —— 人工隨時可以跑這些命令。動它們之前要先向舊系統的負責人確認。

🔴🔴 **這裡踩過一次坑，記下來**：初版只查了 registry `note` 記的那**一個**呼叫端，因此把
`ia`、`pp`、`pussy888`、`ybet` 四支誤判為「排程已停用」。實際上它們各有第二個呼叫端命令、
名稱與第一個不同（`ia_daily_win_loss`、`pp_bet_record_with_last_end_time`、`pussy888_bet_record`、
`ybet_bet_record`），而且都是啟用中的每 5 分鐘排程。**盤點呼叫端一定要全量掃，不能只信 note。**

### 已定調廢棄

✅ **`evo2`／`new_ia`／`vpower_new` 三支，2026-08-31 由 RB 拍板廢棄。** 判定依據兩層：

1. 六站的遊戲商主檔都查不到對應名稱，動態組名也組不出這三個名字 ⇒ 版控內確定無呼叫者。
2. 生產庫執行統計實測零呼叫——VWorld（運行 102.4 天）／TPower（102.4 天）／Yes2Win（103.4 天）
   三站**完全沒有統計列**，同期正式版的 `evo`／`ia`／`vpower` 有 14 萬～44 萬次紀錄可作對照。

⚠️ 另三站無法複驗：Alfawin77 與 Me77 未啟用程序層級的執行統計，VPlus33 的唯讀帳號無權限。

🔴 **定調廢棄不等於已下架。** 六站生產庫與 `database/procedures/` 內三支都還在，
`DROP PROCEDURE` 與移除檔案屬 ops 另案處理。第四期不改造它們。


---

## 五、注單表清單定版

✅ **已定版。** 單一來源是 `database/data/bet_records/tables.php`，共 **142 筆**（定版當時 141，09-03 登記 AiGamingSW 後為 142）：六站生產庫聯集
的 **138 張**實際存在的表，加上 **3 筆** `registry_only`（`vendors.php` 有 tname 但六站皆無對應表）。

| 分類 | 筆數 | 第三期 3.1 是否加幣別欄位 |
|---|---|---|
| `vendor` | 120 | ✅ 是 |
| `event` | 7 | ✅ 是（寫入者不同） |
| `non_bet_record` | 5 | ❌ 否，命名撞上但不是注單源頭 |
| `orphan` | 6 | ⚠️ 個案判定，`vendors.php` 查無對應 |
| `registry_only` | 3 | ⏸ 生產庫尚未建表（`bngsw`／`coke_fighting`／`testgame`） |

#### 「126／123／136」這三個數字是什麼

規劃期文件裡這三個數字並存過，第三期會讀到，先講清楚它們各自的身份——**本清單的 142 筆已經取代它們**：

| 數字 | 來源 | 是什麼 |
|---|---|---|
| **136** | 生產庫 `information_schema` | 真實存在的 `%_bet_records` 表（含活動表、測試殘留、非注單表）。六站中五站為 136，Yes2Win 為 138 |
| **123** | `database/data/vendors.php` 的 unique `tname` | 遊戲商清單裡的表名前綴，**含 1 個空字串**（`id=7`「沒有雞的鬥雞」，已在停用清單內） |
| **126** | 早期的受影響清單盤點 | 規劃期的掃描口徑，**已無法從現有工具重現** ⇒ 不採用 |

#### `orphan` 類 6 張表的處置狀態

**第零期只登記、不清理**——清理屬 ops，不在本階段範圍。

| 表 | 狀態 |
|---|---|
| `ky`／`qtech`／`baracayfish`／`boongosw` | 六站皆存在，`vendors.php` 查無對應遊戲商；疑為已下架遊戲商的殘留表，待 ops 確認後清理 |
| `bolakk4d0217`／`bolakk4d_0217` | **僅 Yes2Win 存在**，零列、結構與正式表 `bolakk4d` 逐欄相同、六個 repo 零引用；判讀為備份表，待 ops 確認建表意圖（詳見附錄 C） |

🔴 **`total_bet_records`（TBR）與 `summary_bet_records`（SBR）不在這份清單裡**。兩者是彙總去處而非
注單源頭，第三期對它們的改造走本體流程。`artisan bet-record:audit-tables` 的 SQL 以 `NOT IN` 一致排除，
**兩邊口徑由 `BetRecordTableRegistryParityTest` 的機器斷言強制同步**。

🔴 **`owner` 是陣列，因為雙寫是現行常態。** 遊戲商是逐家遷移的，APIs 接手後舊系統的排程／路由未必
同步收掉。2026-08-31 全掃四個舊 repo 的結果：**只有 15 筆是純 APIs**，62 筆的寫入邏輯在 RD-Studio-01、
14 筆在 API_External、13 筆是雙寫。**第三期若只改 APIs 側，那些表的幣別欄位會在生產流量下靜默寫空。**

⚠️ **已知限制**（不影響本節定版）：

1. **37 筆 owner 標「待查」**：靜態解析找不到寫入者。兩個盲區——舊系統部分抓單命令的目標表名由
   `vendors` 表資料動態決定（`$this->table`），以及走 stored procedure 的寫入路徑。
   🔴 **這 37 筆至今未回填。** 原本預期由第四節的寫入者清點順帶解決，但那份清點的對象是
   「誰寫注單統計表」，與「誰寫這 120 張遊戲商注單表」是不同的問題，並未涵蓋。
   **第三期要動這些表之前必須先補完**，否則會重演「以為找全了、其實漏一批」。
2. **12 筆 owner 標「未驗證」**：舊系統有同名排程且含寫入呼叫，但表名對應未逐筆實證，note 已標明。
3. **稽核命令在五站的退出碼恆為 1**：`registry` 登記的是六站聯集，前五站各缺 Yes2Win 獨有的兩張
   備份表。**這是預期行為**，`bet-record:audit-tables` 定位為給人看的報告；有機器判定語意的是
   第六節的 `bet-record:audit-writers`，兩支不要混用。六站逐站數字見附錄 C。
4. **命令並非全路徑唯讀**：查詢層唯讀，但框架層 `CommandProvider` 會在目標白標的生產庫寫一筆
   `command_logs`（且因退出碼 1 而記為失敗）。跨白標稽核時知道即可，不影響資料正確性。

---

## 六、Gate B 解除判定

✅ **已解除**（2026-08-31 由 RB 拍板）。第四期可以開工。

**四項判定條件必須全數滿足**（不是只有最後一項）：

| # | 條件 | 現況 |
|---|---|---|
| 1 | 找全所有寫入者 | ✅ 轉檔程序、本專案與四個舊 repo 全數盤點，共 308 個寫入點（兩層反向驗證與逐類覆核見第四節） |
| 2 | 指定各自的 owner | ✅ 308 筆全部有 owner，無「待查」 |
| 3 | 同一遊戲商×時段的並行防護 | ✅ `fencing` 308 筆逐筆指定，格式由測試守住（`schedule:` 78／`realtime:` 208／`manual:` 22） |
| 4 | 驗證覆蓋率 | ✅ `artisan bet-record:audit-writers` 退出碼 0（`gate_b_clear` 為 `true`，逐段覆蓋率見附錄 D） |

其中第 4 項有機器判定：寫入者覆蓋率稽核命令的退出碼為 0。**前三項是人工確認的，已由 RB 拍板通過。**

⚠️ **「並行防護規則須經兩個 repo 的負責人認可」這道程序沒有單獨走過**——本清單登記的並行防護，
`external` 段涵蓋 RD-Studio-01 與 API_External 等四個舊 repo 的 208 個寫入點，那些規則是**本專案這一側
依實測歸納出來的**，未逐條送對方確認，目前由 RB 代表拍板。**第四期實際動到那兩個 repo 時，
仍要與對方負責人確認一次**，特別是每小時第 30 分那三支彙總排程的互斥安排。

🔴 **解除不代表沒有風險，第四期開工時必須帶著這三件事**：

1. **6 支轉檔程序的排程已停用但仍可人工執行**（見第四節），動它們之前先向舊系統負責人確認。
2. **每小時第 30 分有三支彙總在寫同一張表**（本專案一支、舊系統新舊版各一支），改造必須同步。
3. **6 支轉檔程序各有兩個啟用中的排程呼叫端**（見第四節「排程層的並行風險」），只改一邊會留下舊格式的寫入路徑。

⚠️ 舊定義「等 RD-Studio-01 的排程遷入本專案」**已被推翻**，照舊定義做會等一件不用等的事。

---

## 附錄 A：六站幣別代碼實跑結果

`artisan currency:audit-vendor-info --json`，2026-09-01 複跑。**除「代碼種類」一欄外，數值與 08-31
首跑一致**；該欄的差異來自分組方式改版，原因見下方說明。
表中「代碼種類」＝該命令 JSON 的 `rows` 筆數，「阻斷代碼數」＝ `blocked_codes`，
「對不到的總列數」＝ `unmappable_rows_total`。退出碼只有 VWorld 為 1，其餘五站為 0。

| 白標 | 代碼種類 | 商戶總數 | 阻斷代碼數 | 對不到的總列數 | 退出碼 |
|---|---|---|---|---|---|
| **VWorld** | 18 | 614 | **4** | **77**（含停用中 45 列） | **1** |
| TPower | 2 | 123 | 0 | 0 | 0 |
| Yes2Win | 1 | 72 | 0 | 0 | 0 |
| Alfawin77 | 3 | 70 | 0 | 0 | 0 |
| Me77 | 1 | 192 | 0 | 0 | 0 |
| VPlus33 | 1 | 136 | 0 | 0 | 0 |

六站商戶合計 **1,207 筆**，與附錄 B 的 `rate` 分佈總數逐站吻合。

⚠️ **VWorld 這格原本寫 19，2026-09-01 更正為 18——這是一筆沒跟上改版的過期數字。**
19 是命令還在 PHP 層分組（區分大小寫）時的輸出；08-31 RB 拍板改回 SQL 分組
（`groupBy('currency')`，走 MySQL 預設的大小寫不敏感定序）後，`KIDR` 與 `kIDR`
合併成一列，輸出就是 18 種，附錄當時沒同步。**判定結果完全不變**——阻斷 4 種、
對不到 77 列、商戶 614 筆三個數字改版前後一致，因為回填時 `kIDR` 本來就會先被
正規化成 `KIDR`，是同一種幣別。

> **為什麼分組要合併，而不是讓稽核把大小寫差異呈現出來**：大小寫不一致是**資料缺陷，
> 該修掉**，不該設計一個機制去永久容忍它；正確順序是先從資料面統一，再用 SQL 分組。
> 這支命令只回答一個問題——**回填會不會落空**；「資料有多髒」屬於後續的幣別正規化作業。
> （2026-08-31 RB 拍板，記於 commit `4f3ac514f`。）

**只有 VWorld 需要處置。** 四種阻斷代碼：`PHP`（27 筆／16 啟用）、`USD`（29／14）、`CNY`（1／1）、`HKB`（1／1），合計 32 個啟用中商戶。

另有 45 列雖然對不到、但商戶全數停用，不計入阻斷：`BND`（18）、`MSC`（1）、以及部分 `PHP`／`USD` 的停用列。**這批在回填時一樣會落空**，只看退出碼會漏掉。

⚠️ **補完主檔只解得了 31 個**（`PHP` 16 ＋ `USD` 14 ＋ `CNY` 1）。剩下 1 個是 `HKB`——語意待裁決，不是主檔缺項。要讓稽核轉綠，主檔補齊與 `HKB` 裁決兩件事都得做完。✅ **09-07 `HKB` 已裁決為 HKD 拼寫變體（`normalize` 加 `HKB → HKD`，決議㉔）**，VWorld 稽核轉綠只剩主檔補齊部署一件事；`MSC` 保留 unresolved（停用、不阻斷）。

🔴 **第二期做幣別正規化時，要一併補上輸入端驗證**。後台的幣別欄位是自由文字、
只有必填檢查（`VendorInfoResource.php`），而第二期規劃的守衛只擋「字串與編號兩欄
語意不一致」——`kIDR` 配 `currency_id=6` 語意一致、會被放行。**只清理存量而不擋入口，
新的髒寫法會再進來且無人察覺。**

### 值得單獨記住的兩筆

- VWorld 的 `KIDR`（3 筆）與 `kIDR`（3 筆）**並存**，全部停用。稽核命令的報表上看不到這件事（合併為一列 `KIDR total=6`），這是刻意的分工——要清點有幾種寫法，用 `CAST(currency AS BINARY)` 另外查。**這批髒寫法的清理屬於後續的幣別正規化作業，不在 第零期。**
- Alfawin77 的 `KIDR` 6 筆**全數啟用中**，但那是標準寫法、不需異動。

---

## 附錄 B：六站 `rate` 分佈實測

2026-08-31 以唯讀連線查得，查詢語句：

```sql
SELECT rate, COUNT(*) AS n, SUM(status = 1) AS active,
       GROUP_CONCAT(DISTINCT CAST(currency AS BINARY) ORDER BY currency SEPARATOR ',') AS currencies
FROM vendor_infos
GROUP BY rate
ORDER BY rate;
```

🔴 **幣別清單那一欄必須 `CAST(currency AS BINARY)`**，否則大小寫不同的寫法會被併成一個。初版查詢沒加，VWorld `rate = 0.001` 那組就漏掉了 `kIDR`（只顯示 `KIDR`），已更正。分組鍵是 `rate`、不受影響，所以下表的筆數不需重算。

⚠️ 加了 `CAST` 之後 `GROUP_CONCAT` 回傳的是二進位字串，用程式讀取時需自行解碼成文字。

| 白標 | `rate` 分佈（總數／啟用中） |
|---|---|
| TPower | `1` 123／73 |
| **VWorld** | `0.001` 28／1、`0.01` 2／1、`1` 583／268、`100` 1／1 |
| Yes2Win | `1` 72／60 |
| **Alfawin77** | `0.00025` 3／3、`0.001` 8／8、`1` 59／57 |
| Me77 | `1` 192／191 |
| VPlus33 | `1` 136／132 |

**只有 VWorld 與 Alfawin77 有非 1 的 `rate`**，其餘四站全部是 1。跨幣別匯率（`0.00025`）只出現在 Alfawin77 的三個商戶，且全部啟用中。

---

## 附錄 C：六站注單表清單實跑結果

`artisan bet-record:audit-tables --json`，2026-09-01 實跑。欄位取自命令輸出：
`registry_total_count`＝清單登記數、`expected_table_count`＝清單裡預期建在生產庫的張數
（08-31 實跑快照：當時 141 扣掉 3 筆尚未建表的；現為 142 扣 4）、`database_count`＝該站實際查到的張數。

| 白標 | 清單登記 | 預期建表 | 實際張數 | 只在庫裡 | 只在清單 | 退出碼 |
|---|---|---|---|---|---|---|
| TPower | 141 | 138 | 136 | 0 | 2 | 1 |
| VWorld | 141 | 138 | 136 | 0 | 2 | 1 |
| **Yes2Win** | 141 | 138 | **138** | 0 | **0** | **0** |
| Alfawin77 | 141 | 138 | 136 | 0 | 2 | 1 |
| Me77 | 141 | 138 | 136 | 0 | 2 | 1 |
| VPlus33 | 141 | 138 | 136 | 0 | 2 | 1 |

**六站沒有任何一張表是清單漏登的**（「只在庫裡」全數為 0），這是第三期能安全依清單施工的前提。
⚠️ 精確地說，這句話的成立範圍是**表名以 `_bet_records` 結尾者**——命令的掃描條件是
`TABLE_NAME LIKE '%\_bet\_records'`，與 `tables.php` 檔頭的判準一致。**命名不符這個規則的注單表，
這支命令永遠掃不到**；第三期依清單施工前，值得由代碼側再確認一次有無這類命名例外。

五站「只在清單」的那 2 張都是同兩筆：`bolakk4d0217` 與 `bolakk4d_0217`，**只有 Yes2Win 有**。
兩張都是零列、結構與正式表 `bolakk4d` 逐欄相同、六個 repo 皆零引用，判讀為備份表，
待 ops 確認建表意圖後清理；清掉之後六站的表集合就完全一致。

⚠️ **五站退出碼 1 是預期行為，不是稽核失敗**：清單登記的是六站聯集，前五站本來就缺
Yes2Win 獨有的那兩張。這支命令定位為給人看的報告，**有機器判定語意的是
`bet-record:audit-writers`**（見附錄 D），兩支不要混用。

⚠️ **關於「跨白標連線有沒有真的切換」，這批數字能證到什麼程度**：Yes2Win 回 138 而其餘五站回 136，
**這只證明至少命中了兩個不同的庫**——五站回同一個值，仍與「那五次跑的是同一個庫」相容，不能當成
逐站直證。要逐站確認，看命令 JSON 的 `connection` 欄位（回顯當前 Laravel connection 名）。
🔴 **`database` 欄位在六站皆為同一個值**（各白標的資料庫名稱相同、只有主機不同），**拿它比對會誤判成連錯庫**。

---

## 附錄 D：寫入者覆蓋率實跑結果

`artisan bet-record:audit-writers --json`，2026-09-01 複跑，**退出碼 0**。
這支命令讀的是版控裡的 `database/data/bet_records/writers.php`、不連資料庫，
所以不分白標、只跑一次。

| 區段 | 寫入點 | 已指定 owner | 已指定並行防護 |
|---|---|---|---|
| `sp`（轉檔程序） | 73 | 73 / 73 | 73 / 73 |
| `php`（本專案） | 26 | 26 / 26 | 26 / 26 |
| `external`（四個舊 repo） | 208 | 208 / 208 | 208 / 208 |
| **合計** | **308** | **308 / 308** | **308 / 308** |

`missing_owner` 與 `missing_fencing` 皆為空陣列，`gate_b_clear` 為 `true`
⇒ **第六節 Gate B 的第 4 項判定成立**。

---

## 這份契約的下游

| 誰要引用 | 引用哪一節 |
|---|---|
| 第一期（錢包表結構與影子同步） | 第二節的三層語意 |
| 第二期（幣別主檔治理） | 第二節全部、第三節的兩條契約、附錄 A 的**工作量與輸入端驗證要求** |
| 第三期（注單表幣別來源） | 第三節的契約一與契約二、**第五節全部**（142 筆清單、37 筆待查的前置、`orphan` 處置）、**附錄 C**、第四節「範圍界線」的上線阻斷點 |
| 第四期（寫入者改造） | 第四節全部（含修補命令的執行規則）、**附錄 D**、第六節的三項開工風險 |
