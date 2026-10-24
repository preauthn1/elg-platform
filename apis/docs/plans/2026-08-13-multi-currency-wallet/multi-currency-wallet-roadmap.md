# 多幣別錢包 — 工作順序總表

> 產出：2026-09-18｜依據：09-17 與 PM 開會後的決議㉚～㊲，加上 RB 09-18 逐題拍板的順序（本檔第二節）。
>
> **本檔的用途**：09-17 之前的三份實作計劃（第零期／第一期／第二期）已落實的部分依「落實即刪除」規則刪掉，沒落實的與 09-17 新增的工作在這裡排順序。**權威順序（2026-09-18 起）**：[`multi-currency-wallet-decisions.md`](multi-currency-wallet-decisions.md)（決議清單）> 本檔 > 各工作包自己的計劃文件。決議清單講「做什麼、為什麼」，本檔只講「先做哪個、前置是什麼」；兩者衝突以決議清單為準。
>
> **射程**：本檔只排到「切換寫入」為止。第三期（TBR 新表）、第四期（73 支轉檔 SP 與 PHP writer 改目標、SBR 加欄位）、第五期的 5.1（28 支報表加幣別維度）／5.3（開放多幣別的四面向過濾與切幣 API）／5.5（停寫舊欄位）仍以決議清單第四節「六期實作計劃」為準；它們的交付定義與入口條件收在本檔附錄。
>
> **由 AI 整理**，順序為 RB 09-18 拍板；三位審查官（文件一致性、舊系統、獨立第二意見）09-18 審過草案，指出的射程缺口已併入各工作包內容。標「未驗證」者不得當已驗結論引用。

---

## 一、現況（2026-09-18，已核對 git 與程式）

| 期別 | 狀態 | 備註 |
|---|---|---|
| 第零期 Task 1～9 | ✅ 全部進 master | 契約 `docs/multi-currency-wallet-phase0-contract.md` 的數字曾落後程式（寫入點 307 實為 308、注單表 141 實為 142、manual 21 實為 22），09-18 已改 |
| 第一期 Task 1～8、11～13 | ✅ 全部進 master | 影子同步 Job 與雙寫開關已寫好，掛點在 `MemberObserver::updated`，開關 `MULTI_CURRENCY_WALLET_DUAL_WRITE` 預設關。Task 9（type=2 錢包清除）、Task 10（唯一鍵改三元組）移出，定義搬到 [`wallet-type2-cleanup-and-unique-key-plan.md`](wallet-type2-cleanup-and-unique-key-plan.md) |
| 第二期 Task 1～8、12、13 | ✅ 進 master | 剩 Task 9／10／11，計劃已縮減為只含這三題 |
| 09-17 後的小程式改動 | `90e6dc20c` 撤回 USDT 對應 USD 已進 master；`05e3a4d30` WithdrawalController 的 GCASH 幣別依賴改 PHP 已推到工作分支與 develop，**未合 master**（MR 待開） | RB 逐件點名放行；未點名的程式仍等 RB |
| 09-17 新增、原本沒有計劃收的工作 | 主檔清洗＋虛擬幣 id 重編＋六白標共用 seeder（㉛）、`agent_group_currencies` 加顯示單位（㉜㉝）、會員互轉自動開戶（㉝②）、活動按幣別獨立（㉞）、兩筆資金處置（㉟）、已關閉站點清理工單（㊱）、TON 入帳比例重設（㉚）、通道側三欄（㉘④、拆分設計決策 4～5） | 全部排進下表 |

## 二、RB 09-18 逐題拍板（順序）

1. 主檔清洗先做；第二期 Task 9／10 排清洗之後（兩者的比對基準都是主檔 id，主檔要重編）；Task 11 純字串、隨時插空檔。
2. USDT(10) 重編到千位數與 CCPayment 配正式商戶**不綁時程**：商戶照配，清洗命令把 CCPayment 新建的列一併搬。
3. 清洗之後第二個工作包是 `agent_group_currencies` 新表＋顯示單位；決議㉜ 的前置查核（現役 `vendor_infos.rate ≠ 1` 的商戶逐家比對三方原始金額與 TBR）是它的唯讀前置，可與清洗並行先跑。
4. 獨立小件列成「空檔可插隊」清單；工單與問 PM 兩件現在就發。
5. 尾段順序：啟用影子同步觀察期 → 通道側欄位 → 舊系統改造 → 切換寫入 → 會員互轉自動開戶 → 活動按幣別。舊系統改造整包放在切換寫入之前、不拆段、不提前到影子同步之前。
6. 文件形狀：總表一份；刪第零／第一期計劃；第二期只留 Task 9～11；新工作包開工時才寫各自的計劃。
7. （審查後補裁）唯一鍵改三元組從「最後做」提前到切換寫入之後、會員互轉與活動之前：`WalletService` 定位錢包仍是會員＋類型兩元組，第一期計劃本就寫改鍵先於開放多幣別。type=2 錢包清除維持獨立排期、必須在改鍵之前。
8. （審查後補裁）本檔只排到切換寫入；其餘期別的交付定義從被刪的第一期計劃搬進附錄，不跟著檔案消失。

## 三、工作順序總表

前置欄的數字指本表的工作包編號。「內容」欄已併入三位審查官指出的射程缺口，逐項可回溯決議清單。

| # | 工作包 | 內容 | 前置 | 依據 |
|---|---|---|---|---|
| 0 | 文件與工單重新對齊 | ✅ 09-18 完成：本檔落檔；刪第零／第一期計劃、可行性審查、TBR/SBR 方案摘要、組織幣別提案；第二期計劃縮到 Task 9～11；Task 9／10（第一期移出）搬成獨立計劃；契約搬到 `docs/` 並改數字；決議清單改權威順序句與四處指標；`routes/web.php` 文件索引改指空；開已關閉站點清理工單（`target/op_tickets#3240`）；問 PM 綁手機設定一題（`target/op_tickets#3241`） | 無 | 本檔第二節 |
| 1 | 主檔清洗工作包 | **第一步全量盤點引用面**，09-17 已盤：六站 12 張引用表（單位變體 4／5／7／8 零筆；USDT 是重編大宗，四站 `orders` 近一萬筆、通道／訂單類型／銀行十幾列；GCASH／PayMaya／FON 只在 VWorld／TPower 的通道、訂單類型、銀行有零星列）、app／config／routes、216 支 SP 零寫死。**未盤（審查補列，缺一不可）**：① `order_histories`（VWorld 1,457 萬列）；② JSON 欄位內嵌的幣別；③ 舊系統三個 repo 與 RD-Studio-01 的硬編號，已知：RD-Studio-01 有平行的 `app/Enums/CurrencyEnum.php`（含全部七個待刪 id）且 VgPay 兩支命令會把硬編 id 寫回通道與銀行表；舊 API_Frontend 九支線上分支的提款方式判定硬編 `currency_id = 12`（`WithdrawalController.php` 的 `getMobilePaymentBank`），清洗把 GCASH 通道對回 PHP 後那段會永遠回空；④ 舊 API_Frontend 一支 migration `2025_11_17_100000_insert_currency_20_21.php` 是「找不到 id 就 insert」，TON 重編後會把 id 20 插回來，清洗驗收要含「該 migration 已改 no-op 或已 guard」；⑤ 三套幣別下拉 Redis 快取（`currency:list:{lang}`，TTL 一小時）清洗命令要一併清；⑥ 14 個語系檔以 `currency_id` 當提款方式名稱的鍵（`param.withdrawal_name.{id}`）；⑦ 匯率流水表 `shareholder_currency_rate_logs`、股東報表快照 `summary_shareholder_foreign_records`、`shareholder_api_infos`、`currency_names` 這些 join 主檔 id 的表；⑧ 各站 `_bak` 備份表（不遷但要知道存在）；⑨ **FON 刪前查核**（正式環境有 `fon666` 部署分支、曾查到 1 筆 FON 訂單，可能是某站真正計價單位，未驗證）。**清洗命令**：一次性、預設 dry-run、先備份（含續跑 checkpoint 與還原）、清七列、虛擬幣重編到千位數（含 CCPayment 新列）、跑完清三套快取；**部署協議**：逐站「程式版本 × 資料版本」矩陣（新 seeder 與 `CurrencyCodeResolver` 都要求 Enum 與主檔同步，程式先發或資料先改都失配）、目標 id 空位檢查、暫停受影響的建單／回調／queue／SP 並處理在途訂單；**收尾**：`SyncCurrenciesSeeder` 接手長期同步、`CurrencyEnum` 七個過渡 case 與 `walletExcluded()` 排除名單整個拿掉、`currency_aliases.php` 相關註解改寫。⚠️ 第二期 Task 10 的不變式在六站分批部署期間必然報紅，上線前知會值班 | 無 | ㉚㉛ |
| 2 | 第二期 Task 9／10 | 不一致即拒存、每日稽核，基準用清洗後的主檔；**兩張主檔一起**（`vendor_infos`、`event_vendor_infos`，決議⑲㊲②） | 1 | 第二期計劃 |
| 3 | `agent_group_currencies`＋顯示單位 | 新表、`agents.currency_group_id`＋六站十萬級代理回填、`agent_groups` 新型別、後台勾選、API 回元＋顯示單位、SR 報表顯示單位跟代理組別、GFF USDT 組織填 USDT。**硬前置（決議㉕㉗）**：建代理四個入口先改好並發版（APIs 之外含舊 API_Agent `AgentController` 三處建代理各自逐欄複製上層欄位、都沒有 `currency_group_id`），否則回填完又漏新的 0；`Member::currencyId()` 換源後查無幣別**拋錯不墊底**，`WalletService::resolveCurrencyId()` 現行「回 0」要一併改；`currency:audit-orphan-parent` 改判新表；五步上線、稽核歸零後才切換讀源。決議㉜ 的前置查核（rate≠1 商戶逐家比對）為唯讀前置，可與 1 並行 | 1、2 | ㉕㉗㉜㉝、agent-group 設計文件 |
| 4 | 啟用影子同步觀察期 | 撥 `MULTI_CURRENCY_WALLET_DUAL_WRITE`、每日校驗排程跑起來。定位是**診斷期**，不等舊系統改造。⚠️ 校驗現行口徑量不到舊系統寫入：只 join 有影子列的會員、最後一筆走 APIs 就自癒歸零、校驗排 04:10 但佇列積壓會整批假陽性。本包要先改校驗：輸出拆兩個分母（有／無影子列）、加沉澱窗（`members.updated_at` 距今 N 分鐘以上才比）、另加「舊系統寫入偵測」（用交易流水的 type 反查沒有對應影子同步的餘額變動） | 2、3 | 第一期 1.2、Job docblock 的啟用前置 |
| 5 | 通道側三欄 | `agent_cash_flows.wallet_currency_id`、`withdrawal_orders.currency_id`＋`withdrawal_orders.wallet_currency_id`、`orders.wallet_currency_id`；同一張表的兩欄**同一次 ALTER**（拆分設計決策 5，row version 額度）；含建單、回填、消費端驗收。**啟用門檻**：TON 通道進 TON 錢包的入帳比例要重設為 1（決議㉚ 末段）；㊱ 工單對四條異常通道的處置結果接回回填前置 | 3 | ㉘④、拆分設計決策 4～5 |
| 6 | 舊系統改造 | 依第一期計劃「後續階段 2.3」原列**六項**：① 30 個以上 `member_wallets` 建立點補幣別（解析不到或為 0 必須中止）；② 四 repo 讀取面帶幣別；③ `target/support` 跨 repo 協調；④ API_External `make:vendor` 樣板與 `MemberWalletFactory`；⑤ 三個疑似缺陷先過 PM；⑥ 六站生產 16～19 支直寫 SP（含 repo 沒有的副本）。**審查補列**：⑦ `members.balance` 的舊系統寫入者三張網都要罩——API_Agent 5 條直寫路由、RD-Studio-01、**API_Frontend 至少 9 處已逐行確認**（掃碼互轉、VIP、優惠碼、三態多型 else 分支、主錢包與遊戲錢包互轉；另約 10 處形狀相同未逐一驗證），以及 APIs 自己 `database/procedures/` 11～12 支 `UPDATE members SET balance` 的 SP（數量兩次計數差一支，開工時重數）；⑧ API_Agent `memberTransaction` type=5 贈送禮碼**是 `member_wallets` 建立點、二元組讀取**，不是 `members.balance` 直寫，照建立點的改法處理，且它每天生 `currency_id = 0` 的存量、目前沒有稽核看得到；⑨ 舊 API_Frontend `memberTransfer` 路由（第二套會員互轉、零歸屬零幣別檢查）的處置。⚠️ 影響盤點 7.1 只是 `member_wallets` PHP 寫入點的單一來源，**不是** `members.balance` 與 SP 的來源；本機舊 repo 副本最後 fetch 在 6～7 月，開工前重 fetch 再盤 | 4、5 | 第五期 5.4（原第一期計劃「後續階段 2.3」） |
| 7 | 切換寫入 | `member_wallets` 成真相、`members.balance` 反向同步、先定 10 位→4 位進位規則。**前置**：6 完成並六站發版（含那 11～12 支 SP）；觀察期閘門指標達標（見第四節）；**切換當下停正向派送、排空或使在途與重試中的影子同步 Job 失效、做最後一次對帳**——Job 的 `handle()` 沒有開關檢查，開關只擋新派送 | 6 | 第五期 5.2（原「後續階段 2.1」） |
| 8 | 唯一鍵改三元組 | 逐站判定四項（決議⑯）；定義見 [`wallet-type2-cleanup-and-unique-key-plan.md`](wallet-type2-cleanup-and-unique-key-plan.md) Task 10 | 7；type=2 錢包清除（插隊項）先完成 | 決議⑯、第五期未編代號項 |
| 9 | 會員互轉自動開戶 | 收款方可用幣別內自動建錢包並入帳，名單外擋；存量錢包幣別回填、所有讀寫端帶幣別 | 8 | ㉝② |
| 10 | 活動按幣別獨立 | 各幣別獨立配置、不做活動群組、可各領一次；**積分方案拍板後重開**；純行為活動的獎勵幣別未決 | 8 | ㉞ |

**空檔可插隊**（不佔主線時程，但正式執行各有閘門）：
- 兩筆資金處置命令（㉟）：**命令開發可插隊，正式執行須過閘門**——VWorld 停用錢包銷帳與 type=2 錢包清除共用同一份清除清單，先停建／停寫（舊入口仍可開分，見 `wallet-type2-cleanup-and-unique-key-plan.md`）、重算實際人數與金額、備份並驗證可還原，再銷帳；TPower 禮碼作廢同理。
- type=2 錢包清除（第一期移出的 Task 9）：獨立排期，必須在工作包 8 之前。
- 第二期 Task 11 大小寫正規化。
- 決議⑮ 排行榜錢包 1003 廢除的四項前置（`WalletEnum` docblock 記著「尚未列入」）。
- ~~第零期契約數字更新~~ ✅ 09-18 已隨契約搬檔一併改。

**已發（09-18）**：已關閉站點清理工單 `target/op_tickets#3240`（㊱；範圍用 `agents.organization_id` 圈：vworldbn＝組織 6 有 573 個啟用代理、MMGames＝組織 2；附 V-World 唯讀複核表；🔴 所有清理動作必須先備份、驗證可還原才能執行）；問 PM 工單 `target/op_tickets#3241`「提款需綁手機（設定 6042）開了卻從未生效，要不要真的啟動」（順帶確認常數名寫提款、註解寫洗分，哪個是原意）。

## 四、觀察期到切換寫入的閘門（工作包 4 → 7）

「連續無差異」不夠，要逐站記錄下列指標，且在工作包 6 發版後**重新累積**：

| 指標 | 達標 |
|---|---|
| 覆蓋率：有影子列的會員 ÷ 應有影子列的會員（排除決議㉓ 的死帳例外） | 100% |
| 缺錢包數、錯幣別數（`member_wallets.currency_id = 0` 或與代理組別不符） | 0 |
| 差異筆數與絕對金額（排空同步佇列後） | 0 |
| 同步延遲與失敗數 | 有上限並連續達標 |
| 月結與分潤週期 | 至少跑過兩次（第一期計劃原要求，不得省略） |

## 五、文件處置（09-18 已執行）

| 動作 | 檔案 | 說明 |
|---|---|---|
| 刪 | `multi-currency-wallet-phase0-plan.md`、`multi-currency-wallet-plan.md`、`multi-currency-wallet-feasibility-review.md`、`tbr-sbr-scheme-review-summary.md`、`phase2-organization-currency-proposal.md` | 已落實或已被取代；原文一律 `git show 05e3a4d30:<原路徑>` |
| 縮 | `multi-currency-wallet-phase2-plan.md` | 只留 Task 9～11 |
| 新 | 本檔、`wallet-type2-cleanup-and-unique-key-plan.md` | 後者是第一期 Task 9／10 原文搬出 |
| 搬 | `phase0-contract.md` → `docs/multi-currency-wallet-phase0-contract.md` | 長期契約不是計劃，不隨 `docs/plans/` 清空 |
| 留 | 決議清單、`multi-currency-wallet-design.md`、`multi-currency-wallet-impact-inventory.md`（工作包 6 要用）、兩份設計文件 | |
| 降級 | `docs/reports/multi-currency-wallet-target-flow.html` | 停在 09-14 版、未含㉚～㊲，改為歷史會審材料；PM 簡報 `docs/reports/multi-currency-wallet-pm-brief.html` 才是對外現行版 |

---

## 附錄：後續階段界定（自第一期計劃原樣搬入）

> 以下自 `multi-currency-wallet-plan.md`「後續階段界定（另立計劃）」節搬入（原檔已刪，原文 `git show 05e3a4d30:docs/plans/2026-08-13-multi-currency-wallet/multi-currency-wallet-plan.md`）。**未改任何規則**；文中「本文件」「前段」指原第一期計劃，「本文件前段『改唯一鍵為什麼移出第一期』」現在在 [`wallet-type2-cleanup-and-unique-key-plan.md`](wallet-type2-cleanup-and-unique-key-plan.md)。與本檔第三節衝突時，以本檔第三節與決議清單為準（例如舊系統改造與切換寫入的先後、唯一鍵改三元組的時點）。

### 先看這裡：本文件內兩個已移出第一期的 task

2026-08-28 RB 拍板把 Task 9 與 Task 10 移出第一期。**它們的完整定義仍在本文件原位置**（不需另立計劃），改變的只有執行時點：

| Task | 新的執行時點 | 硬性順序 |
|---|---|---|
| **Task 9** type=2 錢包清除 | 獨立排期，不綁第一期 | 必須在 Task 10 **之前** |
| **Task 10** 唯一鍵改三元組 | **逐站判定：該站直寫 SP（repo 14 支＋生產另有副本，以主庫 EVENTS 與 SP 互 CALL 交叉後仍有呼叫者的清單為準）與該站舊 repo 建立點皆已改完發版、全表 `currency_id = 0` 歸零、同 `(member_id, wallet_type_id)` 多列稽核為零之後**（09-03 圓桌新增第 4 項，RB 09-04 認可，見 Task 10 前置條件） | 必須在第五期開放多幣別**之前** |

完整的執行順序與理由見本文件前段「改唯一鍵為什麼移出第一期」。

⚠️ **第四期展開成 task 級計劃時，必須把兩件事納入範圍**（清單與證據都在前段「改唯一鍵為什麼移出第一期」）：

1. **14 支直寫 SP 加填 `currency_id`** —— 否則 Task 10 永遠無法滿足前置條件。
2. **其中 5 支的 `WHERE id = MemberId` 缺陷一併修正**（RB 2026-08-28 同意併入）—— 同一批 SP、同一次逐支驗證，分開做等於驗兩次。**動手前先過 PM**（會改變現有行為，且屬活動類 SP）。

---

### 其餘階段

以下階段**不在本計劃的 task 範圍**。每一項都列出交付定義與入口條件，閘門解除後依此展開成 task 級計劃。

> 🔴 **本節是 08-26 的快照，五項斷言已於 08-27 推翻或更正。展開成 task 級計劃之前，先讀** [`multi-currency-wallet-decisions.md`](multi-currency-wallet-decisions.md)：
>
> | 本節原寫 | 08-27 現行結論 |
> |---|---|
> | 階段編號「第一期 1.3／1.4」「第二期」 | 已重編為六期：舊 1.3 拆成 **3.1～3.4 ＋ 4.1**；舊 1.4 拆成 **4.2 ＋ 5.1**；舊 2.1～2.4 重編為 **5.2～5.5**；另新增 **第零期** 與 **第二期（2.1～2.3）**，這兩期不受任何閘門阻擋 |
> | 「方案 A／方案 B」 | 已更名為**原表加欄位案／新表遷移案**；「P5 不分區變體」的 P5 已改指**收斂版**（熱表只裝三個月），與本節所述的全量遷移不同 |
> | 「73 支轉檔 SP」 | **支數 73 正確**（72 支 `SP_transfer_bet_record_*` ＋ `SP_transfer_event_record_pgs`），且**兩案都要改這 73 支的 SELECT**（具名 INSERT 不會帶新欄位）。⚠️ 本欄一度更正為「72 支」，那是 glob 只掃 `bet_record_*` 造成的誤判，**已於 2026-09-01 改回 73**——判準見 `phase0-contract.md` 第四節 |
> | 「方案 A 歷史免回填」 | **只在單幣別站台成立**。VWorld 是多幣別營運，填 DEFAULT 會把既有 SGD／PHP／THB／VND 注單全標成 MYR |
> | 「`currency_id` 單欄索引兩案都無用（低基數）」 | 該質疑 **08-27 已撤回**——那是拿單幣別舊資料推論新需求；VWorld 已有 18 種幣別代碼、10 種以上啟用中 |

### 舊 1.3（現 3.1～3.4 ＋ 4.1）：注單鏈路

| 項目 | 內容 |
|---|---|
| **入口條件** | 🔴 **Gate A 會審拍板（原表加欄位案 vs 新表遷移案，兩案的 1.3 內容完全不同）** ＋ Gate B 解除 |
| **交付定義（兩案共通）** | ~~126 張遊戲商注單表加 `vendor_info_id`（商戶帳號）與 `currency_id`~~ **09-03 作廢（決議⑭）：BR 表不加欄位**。改為：TBR 帶 `vendor_info_id`／`currency_id`，值於 TBR 建立當下決定——即時寫入點取回調載入的商戶、73 支轉檔 SP 取既有 `xxx_accounts` → `vendor_infos` join（71 支已有該 join，`pp_event` 補取欄位即可） |
| **交付定義（TBR／SBR，依會審結果二選一）** | **原表加欄位案**：舊表直接 `ADD COLUMN ... DEFAULT 主幣別, ALGORITHM=INSTANT`（不另建新表、不動分區、不動表名）；入口條件另加「298 分區／20.6 億列規模的 INSTANT 等比例演練通過」。**新表遷移案（RB 08-26 傾向，P5 不分區變體）**：另建新表（不分區＋保留全域唯一鍵＋複合索引含 `currency_id`），雙寫後切換；28 支報表＋73 支轉檔 SP 改寫入／讀取目標，範圍與 1.4、Gate B 合併規劃 |
| **歷史資料** | 原表加欄位案：**免回填**（上線前注單本就是主幣別，`DEFAULT` 即正確，Gate 0 實證）。新表遷移案：舊表凍結供歷史查詢、不回灌，新表自切換點起算 |
| **🔴 上線阻斷點（原表加欄位案）** | `ADMIN_copy_total_bet_record_bak1_to_orig` 是 `INSERT INTO total_bet_records SELECT * FROM total_bet_records_bak1`（positional），加欄位後欄位數不符會**直接報錯**。屬災難復原工具、平時不執行＝**最糟時機才會發現壞掉**。修法：補欄名 ＋ 為影子／備份表一併加欄位。**上線 checklist 必列。** |
| **待確認同族表** | `summary_bet_records_for_three`／`_team`／`_team_histories`／`summary_bet_record_by_days`／`summary_bet_record_histories`／`total_bet_records_20260511`／`_bak1`／`_bak` |
| **分批策略** | ~~待決：是否依遊戲商分批？建議先挑小表驗流程，再處理 `great_wall`（9,376 萬筆）等大表~~ **09-03 作廢（決議⑭）**：BR 不加欄位，無需分批 |
| **索引** | 原表加欄位案：`currency_id` 建索引**不能 INSTANT**（INPLACE＋`LOCK=NONE` 可線上做但 20.6 億列上需數小時＋replica lag，須個別排維護窗口）；⚠️ **`currency_id` 單欄索引兩案都無用**（低基數，同表 vendor_id 索引 cardinality 僅 3），有意義的是複合索引尾巴。新表遷移案：新表建表時一次設計好 |
| **回填欄位取法** | ~~幣別取**會員直屬代理的組織幣別**（不從商戶帳號反推，否則推算誤差會傳染）；商戶帳號優先從遊戲帳號表取（125 張有 115 張已記），取不到才用代理綁定推算（實測誤差率 0.008%）~~ **09-03 改寫（決議⑭）**：本列原為 BR 回填規則，BR 不加欄位後只剩 TBR 層。TBR 的商戶＝寫入當下的遊戲帳號所屬商戶（`xxx_accounts.vendor_info_id`，09-03 六站實查所有遊戲商帳號表皆有此欄），幣別＝該商戶的 `vendor_infos.currency_id`（第二期補）。⚠️ 12 張自帶三方幣別字串的 BR 表以何者為準待拍板（決議檔第七節） |
| **⚠️ 相依成本** | ~~123 張注單表有 **107 張沒有會員編號**，補資料要繞「注單 → 遊戲帳號 → 會員 → 代理 → 組織」四層~~ **09-03 消除（決議⑭）**：不回填 BR，四層 join 不再需要；TBR 層只走「注單 → 遊戲帳號 → 商戶」兩層，且轉檔 SP 本來就在做這個 join |

### 舊 1.4（現 4.2 ＋ 5.1）：統計與報表

| 項目 | 內容 |
|---|---|
| **入口條件** | 1.3 完成（幣別要先能傳上來）＋ Gate B 解除 |
| **交付定義** | 統計彙總鍵加幣別；受影響 SP 依決議⑥**改寫為 PHP 走 Queue，不再修補 SQL** |
| **規模** | 8 支彙總 SP ＋ 18 支讀統計做計算的 SP（其中 5 支直接派錢）＋ 15 支直接寫會員餘額的 SP |
| **報表面** | **28 支報表讀 TBR／SBR**（ReportController 13 ＋ NewReportController 7 ＋ **SummaryBetRecordRepository 7** ＋ ReportSummaryController 1）。⚠️ 教訓：只掃 Controller 會漏 Repository 層 SQL |
| **🔴 L1 風險** | **sr97／sr98／sr99 多幣別上線即錯**——`orders`／`withdrawal_orders` 本來就有 `currency_id`，但摘要層無 `GROUP BY` |
| **驗證方式** | 第一期全站只有一種幣別 ⇒ 每個彙總鍵仍只對應一筆 ⇒ **報表數字必須與改動前一模一樣**。任何數字對不上就是分組或條件寫錯 |
| **並行對比** | PHP Job 與舊 SP **並行跑、數字一致才停 SP** |
| **已查明** | 三張統計表（`summary_bet_record_agents`／`_agent_teams`／`_by_days`）**都不是分區表**，唯一鍵可調整 |
| **待確認** | `SP_event_deposit_for_cs`／`SP_event_first_deposit` 四處查無呼叫來源（需問客服後台）；`SP_summary_bet_record_three_hierarchy_by_agent` 註解標 V-Reward 疑似複製未更新（需問原作者） |

### 觀察期（最長 2 個月）

- 開啟雙寫開關，每日校驗排程（Task 13）連續無差異
- **跑過至少兩次月結與分潤週期**
- 真相仍在 `members.balance`，不做任何切換
- 觀察項：影子餘額一致性、報表數字與改動前相同、新注單幣別正確、統計表無非預期重複列

### 第二期（入口條件：觀察期驗證通過）

| 階段 | 交付定義 |
|---|---|
| **2.1 切換寫入** | 開關撥向新表，`member_wallets` 成為主要真相；`members.balance` 反向成為同步目標。⚠️ 此時精度方向反轉（10 位 → 4 位），**必須先定進位規則** |
| **2.2 開放多幣別** | 新增組織支援幣別白名單表；新增「切換幣別錢包」API（唯一切換入口）；遊戲／活動／存款／提款四面向隨幣別過濾；**快取索引鍵加幣別**（否則同代理不同幣別會員互相看到對方清單，最長 70 分鐘才過期）；轉帳錢包交易紀錄記商戶帳號與幣別（**防匯率套利**）。⚠️ 開放前必須補齊金流商設定與銀行表的空值幣別，否則加過濾會讓某白標通道**整批消失**。建議逐站開放 |
| **2.3 舊系統改造**（現 5.4） | API_Agent **5 條直寫路由**（`memberTransaction`／`memberAdjustment`／`refundTransaction`／`reviewOrder`／`payout_cancel_prize`）＋ RD-Studio-01。另 `oldMember` 查無路由掛載、疑似死代碼待確認可否刪除。⚠️ `memberTransaction` type=5「贈送禮碼」**直接寫 `member_wallets`，第一期就要跟著帶幣別**。<br>🔴 **09-03 補盤——本列原只看 `members.balance` 的寫入者，`member_wallets` 這張表四個舊 repo 都在寫**：**222 個（09-03 口徑）、單分支下界、非聯集**（一白標一分支，`vw-master` 已實證有 `tpower-master` 沒有的 3 個建立點；分 repo 明細以影響盤點 7.1 為單一來源，本表不重述——決議⑰）。工作包：①30 個以上建立點補幣別（解析不到或為 0 必須中止）（來源兩跳：會員→上級代理→組織；API_Frontend／API_External 的 Repository 入口只收 `int $memberId` 須改簽章）；②四 repo 讀取面帶幣別（API_External 129 處、API_Frontend 51＋`DB::table` 12、RD-Studio-01 23、API_Agent 7＋1，改唯一鍵後撈錯列；`AgentWalletController:397` 二元組讀 id 再鎖會鎖錯列）；③`target/support` 跨 repo 協調；④API_External `make:vendor` 樣板與 `MemberWalletFactory`；⑤三個疑似缺陷先過 PM（另三項處置見影響盤點 7.3）；⑥六站生產 16～19 支直寫 SP（含 repo 沒有的副本）。逐筆清單見影響盤點第七節 |
| **2.4 停寫舊欄位** | `members.balance` 保留但凍結 |

### 不在本案範圍

| 事項 | 原因 |
|---|---|
| VWorld 停用錢包裡的 121,991.62 元（1,053 會員） | 資金處置屬業務與財務決策，見 Gate C |
| 跨幣別互轉（換匯） | 決策 9：兩期都不做，僅保留擴充彈性（`wallet_transaction_logs` 需新增匯率欄，既有 `rate` 欄語意是點數比例、精度只有 4 位，不能挪用） |
| TBR 分區瓶頸緩解（僅在會審選原表加欄位案時解耦） | 選原表加欄位案則解耦為獨立效能專案、首選 P1（`REORGANIZE PARTITION` 削減分區數，⚠️ 鎖與耗時**未驗證，不可假設 metadata-only**）；選新表遷移案則隨新表設計一併處理、不另立專案 |
| 73 支注單轉檔 SP 的應用層冪等機制 | 只有在會審推翻原表加欄位案、改走時間分區時才需要；屬獨立且不小的專案 |

---

