# 重工基線案例（2026-09-10 一次性全量調查）

本檔是 `scan-duplicate-work` 的 Step 4 對照表與 Step 6 裁決紀錄。

**用途一：已知案例不重查。** 本次新邊界落在下列任一案例的領域，直接引用該案例編號，不要重新調查。

**用途二：裁決紀錄。** 已裁決的組合不再重複報——沒有這張表，同一件事會每次都被報一遍，兩週內就沒人看了。

**為什麼需要它**：本 skill 是增量偵測，只比「這期新增 × 全庫既有」。漏掉的是「舊 × 舊」組合（兩邊都不是新增），由本檔補這一塊。

---

## 產出方式與限制

一支唯讀調查 agent 以「收斂型專案 ＋ 能力卡 invariant ＋ 近 30 天新增檔案」三路交叉取樣，產出 9 個可查證案例，每個附 `file:line`、commit sha、作者。

⚠️ **三條限制，引用時必須一起帶上**：

1. **非全庫窮舉**，是三路交叉抽樣——沒找到的跨人重複不代表不存在。
2. 「A 不知道 B 寫過」的判定**全部從時間軸與程式碼形狀推出**，沒有 MR 討論串佐證。
3. 案例 4 的 12 家「等效防護」判定沿用既有查證表，僅抽驗 Kira／BillionPay／TakaPay 三家的實際行號，其餘 9 家未逐家覆核。

---

## 案例表

| # | 重複的能力 | 重複的兩處（含行號） | 時間 / 作者 | 分類 | 本 skill 抓不抓得到 |
|---|---|---|---|---|---|
| 1 | **銀行名稱正規化＋相似度比對**（5 份，各自規則不同） | ① `app/Helpers/StandardBankNameHelper.php:396` / `:408`<br>② `app/Console/Commands/CashFlow/Bank/StandardizeBankCodesCommand.php:347`<br>③ `app/Console/Commands/CompareBankNames.php:333` / `:322`<br>④ `app/Console/Commands/CashFlow/SyncCashFlowsCommand.php:2308`<br>⑤ `app/Services/CashFlow/Bank/BankStandardMappingService.php:338` / `:329` | ① `766edcbfb` 2026-01-23 RB<br>② `1799120d8` 2026-01-23 RB<br>③ `e69355d5a` 2026-01-27 RB<br>④ `3ee348042` 2026-03-11 odin<br>⑤ `6a2aedbe0` 2026-08-12 rb | **混合**（RB×4 ＋ odin 1 件跨人） | **部分**：③⑤ 是新 class → 抓得到；②④ 是在既有大命令裡加 private method → 抓不到 |
| 2 | **標準銀行清單**（同一份權威資料兩份人工維護） | ① `app/Console/Commands/CashFlow/Data/banks/{IDR,MYR,VND,SGD,INR}.php`（今 6,343 行）<br>② `database/data/standard_bank_codes.php`（收斂時已刪） | ① `766edcbfb` 2026-01-23 RB<br>② 表與映射層 `6f58221ae` 2026-05-15 RB；資料檔 `3e9400a46` 2026-08-12 rb<br>收斂 `32c6ef130` 2026-09-02 RB | **同人** | **抓得到**：新 config registry ＋新 migration ＋新 seeder |
| 3 | **代付「本地銀行碼 → 三方銀行碼」查表** | ① `app/Services/Payment/Traits/WithdrawalPayoutTrait.php:244`（入口）/ `:293`<br>② `app/Services/Payment/Providers/TakaPay/Withdrawal.php:49` inline `CashFlowBank::query()`<br>③ `app/Services/Payment/Providers/VaderPay/Withdrawal.php:49` 同型 inline | ① JSON 版 `6f3c42040` 2026-01-20 RB；查表版 `62537ba96` 2026-07-08 RB<br>② `a96fead9d` / `0d9f05256` 2026-01-27~28 odin<br>③ `769dfa511` 2026-02-10 odin | **跨人（雙向）** | **抓得到**：②③ 是新 provider 目錄 ＋ `config/payment.php` 新註冊項 |
| 4 | **空憑證回調守衛**（12 家各自寫 ＋ 1 份共用 trait） | 共用：`app/Services/Payment/Traits/CredentialGuardTrait.php:52`<br>自寫例：`BillionPay/BillionPayTrait.php:110`、`Kira/KiraTrait.php:45`、`TakaPay/TakaPayTrait.php:143-149`（另 9 家未逐家覆核） | trait `d15ede05a` 2026-08-27 RB<br>12 家 provider 首次進版 2026-01~03，作者 6 人：hongze、odin、daniel、RB、rhys、Tim | **跨人（6 人）** | 🔴 **抓不到（時序決定）**：見下方「最重要的一條」 |
| 5 | **「限制對象＝單一會員 or 會員群組」資料模型** | ① `database/migrations/2026_08_17_000001_create_member_restriction_rules_table.php`（morph `target_type`/`target_id`）＋ `app/Enums/MemberRestrictionTargetEnum.php:12-15`<br>② `database/migrations/2026_08_25_000003_create_member_game_restriction_members_table.php`（雙欄 `member_id`/`member_group_id`）＋ `app/Enums/Member/MemberRestrictScopeEnum.php:16-19` | ① `54af23ebb` 2026-08-17 Toro（`target/tickets#4118`）<br>② `87214d443` 2026-08-26 Max（`target/tickets#4126`） | **跨人（相隔 9 天）** | ⭐ **抓得到**：② 的 diff 新增 3 張表＋2 個 Enum。**本 skill 的標準測試題**，見下方 |
| ~~6~~ | ~~**「暫停／灰化某遊戲給某些人」三套**~~ | ① `2025_06_19_091321_create_vendor_maintenances_table.php`<br>② `2026_08_18_081221_create_game_maintenances_table.php` ＋ `_games`<br>③ `2026_08_25_000001_create_member_game_restrictions_table.php` ＋ 2 子表 | ① 2025-06-19 baseline<br>② `75a9e477a` 2026-08-19 Max（#4120）<br>③ `87214d443` 2026-08-26 Max（#4126） | 🔴 **2026-09-13 已撤銷** | 非重工，見下方「案例 6 改判」 |
| 7 | **錢包帳變流水兩張表 ＋ 兩個語意重疊的 Enum** | ① `transaction_logs` / `app/Enums/Transaction/TransactionEnum.php`（55 case）<br>② `wallet_transaction_logs` / `app/Enums/Transaction/WalletTransactionEnum.php`（22 case）<br>逐字重疊：`SEAMLESS_WALLET_BET_SETTLE=27` / `CANCEL=28` / `CHANGE=29` / `TO_VENDOR_FAILED_REFUND=42`；`TransactionEnum::TO_GAME_WALLET=34` ≡ `WalletTransactionEnum::FROM_MAIN_WALLET=1`（註解同為「主錢包轉入遊戲錢包」） | 兩表同屬 `2025_06_19_091321` 的 554 張 baseline snapshot（`2161d328d` RB 2025-06-19）<br>Enum 側 `70f04fb43` 2025-05-19 helo（該 commit 本身是**收斂**：四併二） | **移植** | **抓不到**：不是增量 MR，是整包 schema 快照落地 |
| 8 | **單一錢包回調重送冪等**（四家各自實作，無共用抽象層） | ① `TTHSWController.php:1495` `findCancelForOriginalTransaction()`<br>② `PlayTechController.php:332` `isBetAlreadyRefunded()` ＋ `App\Vendors\PlayTechSW\GameRoundLock`<br>③ `SpadegamingSWController.php:411` `isBetAlreadyCanceled()`<br>④ `RgswController.php:747` `isDuplicateCorrection()` | ① 廠商接入 `7fb90b267` 2025-12-22 hongze；冪等法 `0c6098c2f` 2026-09-08 RB<br>② `85b71f75a` 2026-07-08 rb → `7fd890a56` 2026-09-08 RB<br>③ `599fada42` 2026-09-08 RB<br>④ `97a4fba7f` 2026-09-09 RB | **跨人**（見下方分類修正） | **抓不到**：③④ 只是在既有 controller 裡加 private method |
| 9 | **匯率載體** | 建單三條路徑各讀一張表：`OrderService.php:76`→`agent_cash_flows.rate`；`WithdrawalController.php:597`→`banks.rate`；`AutoDepositController.php:181`→`bank_accounts.rate`。<br>意圖中的單一來源：`currencies.deposit_rate/withdrawal_rate`（`SyncExchangeRateJob` 未實作）。<br>新增第四張：`2026_08_18_000001_create_shareholder_currency_rates_table.php` | 三張 `rate` 欄皆 2025-06-19 baseline（全庫共 17 張表有 `rate` 欄）<br>`currencies` 兩欄 `a83111666` 2026-03-16 RB<br>`shareholder_currency_rates` `b2eb612c6` 2026-08-20 Mars Tseng | **移植 ＋ 1 件跨人新增** | **部分**：`shareholder_currency_rates` 是新表 → 抓得到；三張存量 `rate` 欄 → 抓不到 |
| 10 | **兩個 Model 打同一張 `vendor_maintenances` 表** | `app/Models/VendorMaintenance.php:31`（單數）與 `app/Models/VendorMaintenances.php:31`（複數），docblock 逐字相同、只差 `$guarded`。**兩者都在用**：單數版於 `VendorDetect.php`／`Vendor.php`／`VendorCustomizedItem.php`／`EventService.php`／`GameMaintenance.php`；複數版於 `VendorService.php`／`SyncVendorGameList.php`／`ReplenishVendorAccountPool.php`／`AbstractVendorBatchService.php`／`Nex4DController.php` | 未查 | 舊 × 舊 | **抓不到**：兩者都不是本期新增 |
| 11 | **遊戲商維護生效判斷式散落多份** | 同一組條件（status／type ＋ `hierarchy_array` ＋ `exclude_agent_id`）手寫於 `app/Services/VendorService.php`、`app/Models/Vendor.php`、`app/Http/Controllers/Frontend/LobbyController.php`、`app/Http/Controllers/Frontend/GameController.php`、`app/Helpers/BetHelper.php:76-84`；其中兩份直接寫在 Controller 的 eager-load 閉包裡 | 未查 | 舊 × 舊 | **抓不到**：在既有檔案裡手寫，無新邊界 |
| 12 | **後台可見範圍 helper 各自複製** | `applyVisibilityScope` 在 **5 支** service 各一份（`Agent/GameMaintenanceService`、`Agent/MemberGameRestrictionService`、`Agent/MemberMailService`、`Agent/MemberRestrictionService`、`MemberGroupService`）；配套三件組份數更多——`effectiveHierarchyPath` 8 支、`ownerId` 9 支、`private function isAdmin` 9 支 | 未查 | 舊 × 舊 | **抓不到**：private method，正是已知盲區的教科書例 |

### 案例 10–12 的驗證狀態（2026-09-13 複驗 agent 發現，部分經抽驗）

| 項目 | 狀態 |
|---|---|
| #10 兩個 Model 並存、兩者都有使用點 | ✅ 已實測（`git grep -ln` 兩個類名，使用點清單如表） |
| #12 `applyVisibilityScope` 5 支、三件組 8–9 支 | ✅ 已實測（逐個 `git grep -ln` 計數） |
| #12「前三支逐字相同」 | ⚠️ **未驗證**，複驗 agent 的斷言 |
| #11 判斷式的份數與「兩份在 eager-load 閉包」 | ⚠️ **未逐一複核**；`BetHelper.php:76-84` 那份已目視確認 |

⚠️ 複核時踩到一個值得記的坑：用 `grep 'vendorMaintenance\|vendor_maintenances'` 查 `BetHelper.php` 得到 **0 處**，看起來像複驗者報錯——實際上該檔用的是關係方法 `vendorLastMaintenance()`，**不含被搜的字樣**。這又是一次「搜符號名會漏」。

### 案例 6 改判：不是重工，是有意識的平行設計（2026-09-13）

一支獨立複驗 agent（未被給予本檔，先寫判準再查證）判定 ①②③ **不該列入重工清單**。原判撤銷。

**判準**（它自己先寫下再套用）：語意同一 ＋ 可合併而不退化 ＋ 作者在可得資訊下本可復用卻未復用，三條**同時成立**才算重工。明確排除兩條：結構相似不構成證據；enforcement 端併進同一支查詢也不構成證據——那反而是平行設計被正確收口。

**撤銷理由，三條全不成立**：

1. **語意不同**：三者填的是同一張矩陣的不同格子——（永久｜時窗）×（代理｜會員）×（遊戲商｜遊戲）。① 一列＝一個遊戲商且帶「錢包軸」（`type` 3 值含只關錢包，消費點在 `app/Helpers/BetHelper.php:76-84`、`app/Services/MemberTransferService.php:59`）；② 一列＝一張可跨商的維護單，只有「關閉遊戲」一型；③ 是會員／群組層級的永久限制。
2. **合不起來**：三套合一需要三個互斥 discriminator，`vendor_id`／`start_time`／`end_time`／`unlock_type`／`target_scope` 全退化為 nullable。最硬的一條是 **`agent_id` 一名兩義**——② 的 `agent_id` 是 enforcement scope（`app/Services/VendorService.php:225-226`），③ 的 `agent_id` **刻意不進 enforcement**、只做後台可見範圍（`app/Services/VendorService.php:82-84` 有明文註解，且 `:105-126` 的查詢確實零 `agent_id` 條件）。
3. **作者明確知情**：在 migration／Enum／Service／Controller／routes **五個層次**留下交叉引用與「不同義」警告，例如 `database/migrations/2026_08_18_081221_create_game_maintenances_table.php:12` 寫「與 vendor_maintenances 平行的另一套」、`:29` 寫「type 編號不同義」，`app/Enums/Vendor/GameMaintainTypeEnum.php:10` 寫「⚠️ 編號與 VendorMaintainTypeEnum 不同義」。

⚠️ **這條改判自己有一個未驗證的前提**：複驗者沒讀工單 4120／4126 原文。**若兩者其實是同一需求被 PM 拆成兩張單，②vs③ 的判定會翻盤。** 需人工核對工單。

⭐ **這一案的價值從「重工樣本」變成「判準樣本」**：它示範了「結構相似」與「同一件事兩套實作」的分界。掃描時最容易判錯的就是這裡——案例 5 與案例 6 的表結構同樣平行，一個是重工、一個不是。

順帶記錄一個與掃描無關但真實的事實：②③ 皆無測試（`git grep -ln 'GameMaintenance\|MemberGameRestriction' -- tests/` 無輸出），對照 4118 有 `tests/Feature/Agent/MemberRestriction`。

### 案例 8 的分類修正（原始報告判錯，此處已更正）

調查 agent 原判「同人重複（RB 兩天連寫三支）」。**這是錯的**：那四家是 hongze 2025-12、rb 2026-07 等不同人在不同時間接入造成的，RB 2026-09-08 那兩天是在**分別修**四家已存在的 bug（`target/op_tickets#3098`–`#3101`）。**修理工不是製造者。**

修正這一件後的分類統計：

| 分類 | 件數 | 案例 |
|---|---|---|
| **跨人重複** | **4** | #3、#4、#5、#8 |
| 同人重複 | 1 | #2 |
| 跨系統移植 | 2 | #7、#9 |
| 混合 | 1 | #1 |
| ~~已撤銷~~ | 1 | ~~#6~~（2026-09-13 改判非重工） |
| 2026-09-13 複驗新增 | 3 | #10、#11、#12（皆為「舊 × 舊」，增量偵測抓不到） |

⇒ **跨人是最大宗**，「互不關注彼此 MR」的假設成立。（原始報告的結論「主要是同一個人隔幾個月各寫一次」建立在誤分類上，已不成立。）

實例層（每一份重複實作各算一次，共約 30 份）：RB 約 12 份為單一最大來源，odin 5 份，hongze 4 份，其餘各 1–2 份。

---

## 最重要的一條：案例 4 證明守門有結構性盲區

空憑證守衛的**因果方向是反的**——不是「已有共用實作還去重造」，而是**12 個人各造一遍之後，共用實作才被抽出來**：

- 12 家 provider 的自寫防護全部落在 **2026-01-19 ~ 2026-03-27**（6 個不同的人）
- `CredentialGuardTrait` 出生於 **2026-08-27**，比最後一家晚 **5 個月**

⇒ 任何「比對既有實作」的守門，在那 12 支 MR 當下**結構上不可能命中**，因為能力本身還不存在。**本 skill 只抓得到第 N 次（N≥2）；技術債發散最貴的第一階段天生看不見。**

同一模式在案例 3 更清楚：odin 2026-01-27 在 TakaPay 內寫了 `cash_flow_banks` 查表，RB 在 **5 個月後**（`62537ba96` 2026-07-08）把同一段邏輯寫進共用 trait——**共用層的作者重造了 provider 作者已經寫過兩次的東西**。

**代價常由第三個人付**：Tim 在 `7a09ca7bd`（2026-08-24）為了補一家 SGD 銀行，被迫同時查案例 2 的兩份清單，補完後自行加了 `BankRegistryParityTest` 棘輪。

---

## 案例 5 是本 skill 的標準測試題

> 🔴 **本節含答案。** 實際執行掃描時，必須先自己查完比對那一步再讀本檔——先讀會變成照抄結論，失去獨立驗證的意義。本節的存在目的是「改 skill 時拿來回歸測試」，不是掃描時的捷徑。


Toro 的 `member_restriction_rules`（08-17）與 Max 的 `member_game_restriction_members`（08-26），相隔 **9 天**、同一個「限制對象＝會員 or 會員群組」資料模型、兩套不同實作。

而 Max 自己在 docblock 寫了「與 4120 的 `game_maintenances` 平行但語意不同」——**他對照了自己的，卻沒對照 Toro 的**（`git grep -n '4118\|member_restriction_rules\|MemberRestrictionTargetEnum'` 對 4126 的 Service／Model／Enum／migration 全部零命中）。

🔴 **2026-09-13 複驗把它擴大成三套**：同一個「會員或會員群組作為限制對象」的模型，**10 天內被三個人各做一次**——

| 落地 | 存法 | 作者 / commit |
|---|---|---|
| `member_restriction_rules`（#4118） | morph：`target_type` ＋ `target_id` | Toro `54af23ebb` 2026-08-17 |
| `member_game_restriction_members`（#4126） | `target_scope` ＋ 兩個 0-default 欄位 | Max `87214d443` 2026-08-26 |
| `member_mail_recipients`（#4152） | 存檔時展開收件人 | Max `66b3a22dd` 2026-08-27 |

判定式結構逐句對應：`app/Services/MemberRestrictionGuard.php:56-68` vs `app/Services/VendorService.php:110-124`。兩個同義 Enum 不同名：`MemberRestrictionTargetEnum` vs `MemberRestrictScopeEnum`。

⇒ 這正是 APIs `CLAUDE.md` 寫的那個情境：**同一件事正被幾個人各做第一次、共用層還沒誕生時，四條管道全部查不到。**

改本 skill 時拿這題驗證：**兩張表名毫無交集，兩組欄位名也毫無交集。**

2026-09-13 實測四種查法，結果如下（改 skill 的 Step 3 時須重跑）：

| 查法 | 命中 Toro | 命中 Max | 判定 |
|---|---|---|---|
| `grep -rl 'member_group_id' database/migrations`（搜 Max 的欄位名） | ✘ | ✔ | **無效**——Toro 用 morph `target_type`/`target_id`，沒有這個欄位 |
| `grep -rl '會員群組' database/migrations`（搜中文註解） | ✔ | ✔ | **有效**，命中 8 檔 |
| `grep -rl 'member_group' database/migrations`（搜詞根） | ✔ | ✔ | **有效**，命中 6 檔 |
| `ls database/migrations \| grep -iE 'member.*restrict'` | ✔ | ✔ | **有效**，5 張全出 |
| `git grep -l '會員群組' -- app/Enums`（同一條查法換到 Enum 目錄） | ✘ | ✔ | 🔴 **失效**——Toro 的 `MemberRestrictionTargetEnum.php:14` 寫的是**簡體「会员群组」** |

⇒ 兩條結論，缺一不可：

1. **本 repo 的欄位中文註解是語意載體**：不同人會取不同欄位名，但描述同一件事的中文用語會撞。
2. 🔴 **但那個「有效」只在 `database/migrations` 範圍成立。** repo 內**簡繁混用**，`app/Enums` 就踩到了——搜中文一律兩種都搜，或改用檔名／表名關鍵詞那條。

⚠️ 第三條查法順帶撈出 `2025_06_19_091321_create_member_restrictions_table.php`——**這個領域其實有三代實作，不是原報告寫的兩套**。原調查是三路抽樣、非窮舉，此處即為一例。

---

## 裁決紀錄

Step 6 回寫區。格式：一列一個已裁決的組合，`狀態` 取「處理中／不處理／已收斂」。已在此表的組合不再重複報。

| 日期 | 組合 | 狀態 | 裁決理由 |
|---|---|---|---|
| 2026-09-02 | #2 標準銀行清單兩份 | 已收斂 | `32c6ef130` 收斂為單一來源 |
| — | #3 VaderPay／TakaPay 繞過代付銀行碼統一入口 | **待裁決** | 兩份副本至今仍在 master 且行為不同（缺 `where('status',1)` 與 `orderBy('id')`，同鍵多列時取列非確定性） |
| 2026-09-13 | #6 遊戲維護／會員限制三套 | **不處理** | 改判非重工（見「案例 6 改判」）。⚠️ 前提未驗證：工單 4120／4126 若為同一需求拆單則須重判 |
| — | #10 兩個 Model 打同一張 `vendor_maintenances` | **未裁決** | 兩者都有使用點，清理需逐一改呼叫端 |
| — | #11 遊戲商維護判斷式散落多份 | **未裁決** | 份數與位置未逐一複核 |
| — | #12 後台可見範圍 helper 各自複製 | **未裁決** | 5 支 `applyVisibilityScope` ＋ 三件組 8–9 支 |
| — | #1 #4 #5 #7 #8 #9 | **未裁決** | 尚未逐案處置 |
| 2026-09-18 | 「我方在用的三方銀行代號還在不在三方現行清單上」的差集判斷兩處：`app/Console/Commands/CashFlow/ThirdPartyQuery.php:532` `compareBankCodes()`（`f549bb6de` 2026-09-15 RB）與 `app/Console/Commands/CashFlow/Maintenance/AuditFpayBankList.php:328` `checkOurCodes()`（target/op_tickets#3229）。同人，相隔 3 天 | **不處理** | 後者是前者的超集：前者只比代付設定的 `available_banks`、後者多比 `cash_flow_banks`，且用途不同（排程告警 vs 人工排查、資料來源一個經共用 DTO 一個讀三方原始回應）。差集判斷本身只有兩三行 `array_diff_key`／`isset`，抽共用的收益低於「得先決定前者要不要也查 `cash_flow_banks`」的風險 |
| 2026-10-06 | 接入通道 `app/Enums/Vendor/VendorChannelEnum.php`＋`HasAggregatorChannel`（target/tickets#4400）與 `vendors.third_party_code`（`app/Services/RefreshRankingService.php:55`、`app/Filament/Admin/Resources/VendorManagement/VendorResource.php:62`）都把聚合商的子遊戲商分成同一組 | **不處理** | 語意不同：`third_party_code` 指共用同一套 API 與帳號池，WUDISW／FUMASW／PP 的本體與 FREE 變體同碼卻是直連，拿來判集成會誤判；且它是後台人工維護的欄位，沒有程式守住。理由記在 apis `docs/adr/0003-vendor-channel-and-callback-declared-by-driver.md` |
