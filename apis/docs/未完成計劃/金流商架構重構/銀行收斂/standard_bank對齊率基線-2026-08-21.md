# `standard_bank_id` 對齊率基線（2026-08-21 六站實查）

> 用途：軌① 銀行收斂的**現況基線**，供方案分級（B / C / D）決策與後續 rollout 前後對比。
> 取數方式：mysql MCP 唯讀直連六站生產 DB；條件 `banks.deleted_at IS NULL AND banks.status = 1`。
>
> 🔴 **2026-09-10 更新：本檔 §1 的六站整庫數字已被後續作業取代，不可再當現況引用。**
> 09-02 的 `bank:sync-mappings` 回填（1,490 筆）＋`--repair`（62 筆）＋ base 層收斂之後，五站實查已達 **82.0%～97.8%**（未對齊合計剩 407 筆），`standard_banks` 主檔也由 120 筆增為 367 筆。現況數字與對照表見 [續推方案 D－裁決材料－2026-09-10](./續推方案D-裁決材料-2026-09-10.md) §1。
> **§1.2（營運面口徑）亦已於 2026-09-10 以同口徑重測**：七組（不含 VPlus33）由 36.9% 推到 **86.1%**、
> 未對齊剩 122 筆，逐組數字見同一份裁決材料 §1.1。
> 本檔保留為**基線**（rollout 前後對比用），不再更新。

## 1. 六站對齊率

| 白標 | active banks | 未對齊（`standard_bank_id IS NULL`） | 對齊率 | 同名重複組 | `agent_banks` 投影 |
|---|---|---|---|---|---|
| TPower | 940 | 592 | 37.0% | 1 | 210 |
| VWorld 連線（整庫）⚠️ | 782 | 412 | 47.3% | 14 | 230 |
| Yes2Win | 922 | 586 | 36.4% | 3 | 205 |
| Alfawin77 | 515 | 395 | 23.3% | 0 | 64 |
| Me77 | 101 | 32 | 68.3% | 0 | 44 |
| VPlus33 | 252 | 105 | 58.3% | 0 | 43 |
| **合計** | **3,512** | **2,122** | **39.6%** | 18 | 796 |

⚠️ **這張表的數字混了三種東西**，不宜直接當決策依據，兩點修正見 §1.1 與 §1.2：

1. 每站的 active banks 同時含**正式代理**與**測試代理**兩個財務組別的銀行列，還加上不屬任何白標的 base 模板（`agent_group_id = 0`）。只看正式營運面的數字見 **§1.2**。
2. VWorld 那一列是**整個 DB 連線**的數字（含 WTT、VWSG 與 26 棵非白標子樹），不是 VWorld 白標本身；分站拆解見 **§1.1**。

`standard_banks` 主檔六站皆 **120 筆 / 全數 active、完全一致** —— 主檔同步（MR !1928）在六站實證成立。

## 1.1 VWorld 連線分站拆解（2026-08-21 補查）

VWorld 這個 DB 連線同時裝了三個白標（VWorld / WTT / VWSG）與一批非白標子樹，所以整庫的 47.3% 不能當成任何一站的實際狀況。以下是套 scope 之後的分站數字。

**銀行怎麼歸站**：`banks.agent_group_id` 對應的是代理的財務組別欄位 `agents.cash_flow_group_id`（見 `app/Services/CashFlow/Bank/BankVisibilityChecker.php:99`），**不是**代理樹本身。所以分站要先由 `rootAgentId` 取出該子樹全部代理用到的 `cash_flow_group_id` 集合，再用這組 id 去切 `banks`。三站的集合完全互斥，沒有跨站共用：

每站的兩個財務組別，一個給正式代理、一個給測試代理（registry 的 `rootAgentId` 與 `testRootAgentId` 實查對照）。**測試組別的銀行列不服務任何真實會員，不該算進營運面分母**：

| 範疇 | 財務組別 | active banks | 未對齊 | 對齊率 | 同名重複組 |
|---|---|---|---|---|---|
| VWorld 正式（Acompany 66829） | 3 | 25 | 1 | **96.0%** | 0 |
| VWorld 測試（VWMTEST 37898） | 6 | 43 | 11 | 74.4% | 1 |
| WTT 正式（wintoto 59457） | 70 | 46 | 15 | 67.4% | 1 |
| WTT 測試（wintototest 59610） | 93 | 79 | 37 | 53.2% | 0 |
| VWSG 正式（vworldsg 49726） | 19 | 55 | 36 | 34.5% | 2 |
| VWSG 測試（VWSTEST 53614） | 99 | 65 | 42 | 35.4% | 9 |
| base 模板（`agent_group_id = 0`） | 0 | 262 | 151 | 42.4% | 1 |
| 非白標子樹（10 個組別） | 見下 | 207 | 119 | 42.5% | 0 |
| **整庫合計** | — | **782** | **412** | **47.3%** | **14** |

四點判讀：

- **VWorld 白標的正式面其實幾乎全對齊**：25 筆只差 1 筆未對齊＝96.0%。整庫那個 47.3% 是被測試組別、base 模板（262 筆）與非白標子樹（207 筆）稀釋出來的。
- **VWSG 才是真正的問題點**：正式面 34.5%，是三站最低。
- **14 組同名重複裡只有 3 組落在正式面**（VWSG 正式 2 組、WTT 正式 1 組），其餘 11 組在測試組別與 base 模板。原本以為「VWSG 有 11 組」，實際那 9 組在它的測試組別 `g99`，其中 8 組是同名**同碼**的純重複列（如 `(SGD) - Bank of China` 兩筆都是 `BKCHSG`）。
- **base 模板（`agent_group_id = 0`）不屬於任何白標**：它是新開代理組別時複製銀行列的來源樣板（見 `app/Services/CashFlow/Bank/AgentBankSyncService.php:10`），不會直接曝露給會員，因此不該算進任一站的分母；但它的 151 筆未對齊會**持續複製到新組別**，屬於要優先處理的源頭。

非白標子樹明細（`agent_group_id` → 子樹 root：active / 未對齊）：

`674` → T-POWER：104 / 51 ・ `634` → co365th：29 / 29 ・ `95` → CJ7：27 / 16 ・ `116` → **無任何代理指向**：25 / 16 ・ `37` → vworldvn：6 / 1 ・ `28` → vworldvn：6 / 1 ・ `664` → gffusdt：6 / 1 ・ `705` → 7Shwe：2 / 2 ・ `89` → casino365：1 / 1 ・ `747` → gffusdt：1 / 1

**`agent_group_id = 674` 的 104 筆是遷移殘留，不是活資料**（已查證）：VWorld 連線與 TPower 連線是兩個獨立 DB（同名 `xsgcasino`，但 `banks` 1,211 對 1,480、`agents` 76,941 對 44,652）。id 71541 的 `T-POWER` 代理在兩庫都存在、`created_at` 與財務組別完全相同，但——

| | VWorld 連線 | TPower 連線 |
|---|---|---|
| 代理 status | **0（停用）** | 1（啟用） |
| 子樹會員數 | **2** | 388,671 |
| 最後建立會員 | 2025-05-28 | 2026-08-20 |

⇒ TPower 拆成獨立庫時，舊庫留下了停用空殼；registry 的 `sharesConnection: false` 記載正確。這 104 筆屬可清理範圍。

仍待確認：**`agent_group_id = 116` 有 25 筆 active banks，卻沒有任何代理指向它**——孤兒組別，疑似可整組退役（**未驗證**）。

另外 VWorld 的 `agent_banks` 投影（正式＋測試合計 57 筆）略多於已對齊的 56 筆，成因是投影來源（`BankProjectionService`）不篩 `status`，會納入停用但已對齊的列；此差異不影響對齊率，但重跑投影時要留意。

## 1.2 六站正式營運面對齊率（2026-08-21 補查，**這才是決策該用的數字**）

§1 的六站表把正式組別、測試組別與 base 模板混在一起算。把每站的正式代理財務組別單獨切出來後，分布和原表差很多：

| 白標 | 正式財務組別 | active banks | 未對齊 | 對齊率 |
|---|---|---|---|---|
| VWorld | 3 | 25 | 1 | **96.0%** |
| VPlus33 | 7 | 22 | 2 | 90.9% |
| Me77 | 7 | 22 | 3 | 86.4% |
| WTT | 70 | 46 | 15 | 67.4% |
| Yes2Win | 7 | 324 | 212 | 34.6% |
| VWSG | 19 | 55 | 36 | 34.5% |
| TPower | 674 | 337 | 222 | 34.1% |
| Alfawin77 | 7 | 165 | 126 | 23.6% |
| **合計** | — | **996** | **617** | **38.1%** |

三點判讀：

- **整體比率（38.1%）和原表的 39.6% 差不多，但問題的分布完全不同**：三個小站（VWorld / VPlus33 / Me77）幾乎全對齊，剩下五站落在 23–67%。
- **未對齊集中在三個站**：TPower（222）＋ Yes2Win（212）＋ Alfawin77（126）＝ 560 筆，占正式面 617 筆的 **90.8%**。要拉動對齊率，動這三站就夠。
- **測試組別的銀行列幾乎和正式一樣多**，等於整份基線的分母被放大近一倍：TPower 測試組別 `g688` 有 336 筆（正式 337）、Yes2Win 測試 `g12` 321 筆（正式 324）、Me77 測試 `g12` 41 筆（正式只有 22）。這些列不服務真實會員。

一個要業務確認的配置疑點（**未驗證**）：

- **Alfawin77 的正式代理與測試代理共用同一個財務組別 `g7`**（`AlfaWin77` 與 `alfawin77test` 都指向 7），所以測試代理看到的銀行清單與正式完全相同。其餘五站都是正式、測試各自一組。
- 同站另有 `agent_group_id = 14`，199 筆 active banks（比正式的 165 筆更多）但只有 8 個代理指向、且不是 root 或 test 代理，用途不明。

## 2. 根因：主檔涵蓋度不足，非回填未執行

| 幣別 | 主檔筆數 | 實際在用的不同銀行名（TPower） | 未對齊列數（TPower） |
|---|---|---|---|
| IDR | 39 | 132 | 279 |
| VND | 32 | 59 | 70 |
| MYR | 29 | 43 | 39 |
| SGD | 19 | 45 | 58 |
| INR | 1 | 1 | 0 |
| THB | **0** | — | 74 |
| HKD | **0** | — | 60 |
| BDT | **0** | — | 9 |
| USDT | **0** | — | 3 |

- **THB / HKD / BDT / USDT 主檔完全沒有對應列** ⇒ 該幣別的 banks 結構上不可能對齊，不是漏跑回填。
- 既有幣別亦不足：IDR 主檔 39 筆，實際使用 132 個不同銀行名。
- 抽樣驗證（Alfawin77，type=1 / IDR，377 筆未對齊）：全為正牌印尼銀行長尾（`(IDR) - Bank Kalsel` / `TPID056` 等），每個名稱跨 3 個 `agent_group_id`——**應對齊、僅主檔缺該筆**。

### 2.1 修正：THB / HKD / BDT 既不該補主檔，也還不能停用——先查缺陷

上表把這三個幣別寫成「缺口」，暗示要補 curation。查完實際提款單與引用面後，結論是**兩個方向都不對**：這三個幣別的通道呈現「會員持續在綁定、但出款早已歸零」的異常，該做的是先查清為何綁了出不了款。

#### 涉及的列數：333 列，只存在於三站

| 幣別 | TPower | Yes2Win | VWorld | AW77／Me77／VP33 | 合計 |
|---|---|---|---|---|---|
| HKD | 60 | 60 | 0 | 0 | **120** |
| BDT | 9 | 12 | 0 | 0 | **21** |
| THB | 74 | 66 | 52 | 0 | **192** |
| 合計 | 143 | 138 | 52 | 0 | **333** |

TPower 與 Yes2Win 的列在正式、測試組別**各半**（TPower g674／g688、Yes2Win g7／g12），所以營運面實際只有一半。VWorld 那 52 列 THB **全部落在非白標子樹**（`g634` co365th 29 列、`g674` T-POWER 殘留 23 列，最後更新停在 2024 年），**VWorld / WTT / VWSG 三個白標本身的 THB 是 0 列**。

#### 為何不能直接停用：有活體引用

`banks.status = 0` 之前必須先清 `bank_id` 的引用。`information_schema` 實查有 11 張表帶 `bank_id` 欄位，`bank:dedup-active` 目前只遷了其中**三張**（`member_bank_accounts`／`withdrawal_orders`／`realname_validation_requests`；⚠️ 本檔原記「兩張」偏低，2026-09-09 依 TODO.md §3.1.3 的更正同步）（此即 TODO.md §3.1.3 的紅線「去重前必先補齊 bank_id 外鍵遷移覆蓋面」）。HKD + BDT 的實際引用數：

| 站 | `member_bank_accounts` | `realname_validation_requests` | `cash_flow_banks` | `withdrawal_orders` |
|---|---|---|---|---|
| TPower | 122 | 82 | 3 | 39 |
| Yes2Win | 189 | 192 | 6 | 21 |

（`member_bank_accounts` 這欄只計綁在 active banks 上的帳戶；若不篩 `status`，TPower 是 126、Yes2Win 是 190。）

#### 核心異常：綁定持續，出款歸零

| 站 / 幣別 | 會員銀行帳戶 | 會員數 | 最後綁定 | 全期間出款 | 最後出款 |
|---|---|---|---|---|---|
| TPower HKD | 87 | 77 | 2026-08-05 | 28 | 2026-05-12 |
| TPower BDT | 39 | 27 | 2026-08-06 | 11 | 2026-03-15 |
| Yes2Win HKD | **146** | 142 | 2026-07-29 | **7** | 2026-04-23 |
| Yes2Win BDT | 44 | 32 | 2026-07-24 | 14 | 2026-04-27 |

兩站合計 **316 個會員銀行帳戶**（此表只含 HKD + BDT），全期間出款卻只有 60 筆。Yes2Win 的 HKD 最誇張：146 個帳戶換到 7 筆出款。同期 TPower 的 MYR 是 167,957 筆。

⚠️ 加上 THB 之後的完整影響面是 **815 筆綁定 / 681 個正式會員**，見 §2.4 末段——THB 才是最大的受害面。

⇒ 會員在前端**看得到、綁得上**這些銀行，但實際出不了款——最後出款都停在 3–5 月，而綁定一路持續到 7–8 月。這像是 ACF（`agent_cash_flows`）或 `order_type` 斷鏈／配置缺失造成的**缺陷**，不是自然退場。**在查清原因之前，停用與補主檔都不該做**：停用會讓這 316 個帳戶失效，補主檔則是為一條壞掉的通道補齊帳面。

#### 一筆需要追的變更軌跡（**未驗證**）

TPower 正式組別 `g674` 的這三個幣別，在 **2026-08-20 04:02:26 / 04:03:17 / 04:03:26**（DB 字面時間）被整批 `updated`，各批分別 37／30／3 列、每批同秒完成——是批次操作的特徵。但查該時間窗（03:50–04:30）的 `command_logs`，只有 `schedule:run`、`check:event_v2_schedule`、`check_vendor_transfer_status`、`create_vendor_account`、`vendor_detect`、`withdrawal:alert-stuck-submitted`、`update_game_list`、`app:summary-mission-datas`，**沒有任何 bank 相關命令**。且只動了正式組別，測試組別 `g688` 未被觸及（仍停在 2–3 月）。

⇒ 不是 APIs 排程做的。要查是後台人工操作、其他專案觸發，還是未經 `command_logs` 記錄的手動命令。

> ⚠️ 時區讀值校正：mysql MCP 回傳的 timestamp 會標 `Z` 但實際是 DB 字面值減 8 小時。上述時間均已換算回 DB 字面值。原始回傳顯示為 `2026-08-19T20:02:26Z` 等。

#### 兩個附帶異常

1. TPower 有 3 列 BDT 掛在 `agent_group_id = 0`（base 模板），Yes2Win 有 6 列——模板層就帶著死通道，會複製到每個新開組別。
2. HKD / BDT 在 TPower 同時掛正式 `g674` 與測試 `g688`，兩組列數相同（HKD 各 30、BDT 各 3）。

### 2.2 斷鏈成因已查明：正式組別的代付通道是舊的或沒開

§2.1 說「疑似 ACF 斷鏈、要查」。查完了，機制很明確。

**先確立一件事：`withdrawal_orders.status = 9` 是「退款」**（`app/Enums/WithdrawalOrderStatusEnum.php:31`），代表會員提了款、系統沒出成、錢退回錢包。TPower 這三個幣別的訂單狀態分布：

| 幣別 / 組別 | 退款(9) | 三方成功(24) | 其他 | 最後成功 | 最後退款 |
|---|---|---|---|---|---|
| THB / g674 正式 | **57** | 50 | 失敗(2) 23 | 2026-05-27 | **2026-07-19** |
| HKD / g674 正式 | **7** | 10 | 通過(3) 2 | **2025-12-17** | 2026-04-24 |
| BDT / g674 正式 | **4** | **0** | — | **從未成功** | 2026-03-15 |
| HKD / g688 測試 | 8 | 0 | 三方失敗(29) 1 | 從未成功 | 2026-05-12 |
| BDT / g688 測試 | 3 | 1 | 處理中(22) 3 | 2025-08-14 | 2026-03-02 |

**BDT 在正式組別從未成功出款過一筆**；HKD 正式最後一次成功是 2025-12-17，之後只有退款。這不是「沒人用」，是**用了出不去**。

#### 成因：代付通道的配置

TPower 正式組別 `g674` 這三個幣別，各自只有一個 `status = 1` 的代付 ACF（`cash_flow_type = 3`）：

| 幣別 | 正式組別唯一啟用的代付通道 | 問題 |
|---|---|---|
| THB | `acf 175`「AllPay代付 (THB) / (THB) SpeedPay」 | **AllPay 是已退場的聚合商** |
| HKD | `acf 315`「EasyPay 代付 / EasyPay(DG) - Withdraw」 | 啟用中，但最後成功停在 2025-12 |
| BDT | `acf 344`「GanyoPay - Withdrawal」 | 2026-03-01 建立，從未成功出款 |

**最關鍵的一筆**：THB 有一條較新的通道 `acf 363`「AntzPay - Withdrawal」，2026-03-31 建立——它在**測試組別 `g688` 是 `status = 1`（啟用），在正式組別 `g674` 卻是 `status = 0`（停用）**。同一天建立的兩筆，只開了測試那邊。

⇒ 這就是「會員綁得上、卻出不了款」的直接成因：**前端銀行清單只看 `banks` 表**（`banks` 有列就顯示），而出款走 ACF 候選清單；正式組別的 ACF 掛在已退場或未啟用的通道上，兩邊不同步。

#### 待確認（**未驗證**）

- AntzPay 正式組別為何沒開：是灰度未完成、是刻意保留，還是漏開？需查 ops 紀錄或問負責人。
- HKD 的 EasyPay(DG) 通道 `status = 1` 但 2025-12 後無成功：通道本身是否還通（三方端狀態），需實際打一筆測試或查三方回應紀錄。
- 這三條通道的失敗屬於哪種：ACF 未啟用、三方拒單，還是銀行碼對不上。
  - ⚠️ **原文寫「查 `cash_flow_banks` 的 THB 為 0 列」是錯的判準**（2026-08-21 讀碼修正）：出款側的通道可用性讀 `agent_cash_flows.info['available_banks']`（`app/Services/WithdrawalPayoutService.php:524-556`），**不是 `cash_flow_banks`**。後者服務於診斷（`BankVisibilityChecker` Step 1）與 feed 層，補它不會讓出款變可行。

> 📄 **本節已展開為獨立交辦單**：`THB-HKD-BDT代付斷鏈交辦-2026-08-21.md`（同目錄）。該文補齊了本節未查的 **Yes2Win 側通道配置**（正式組別 THB／BDT 為零啟用通道，比 TPower 更嚴重），並切出三段處置分級（立即止血／待釐清／根治）。⚠️ 注意本目錄的 `方案D-完整四層收斂.md` 用 `P0`–`P5` 表示**階段**，與優先級無關，勿混用。本節保留為成因分析，處置動作一律以交辦單為準。

### 2.3 列數不等於機構數——這才是「要補多少主檔列」的答案

§2 原本用「未對齊列數」推估要補多少 curation，這個度量是錯的。`standard_banks` 是**跨白標共用的機構主檔**，一家機構一列；而 `banks` 是「每個財務組別一份」的配置列，同一家機構在同一站就會出現兩三次。

| 幣別 | TPower 列 / 機構 | Yes2Win 列 / 機構 | VWorld 列 / 機構 | 機構名稱是否跨站一致 |
|---|---|---|---|---|
| BDT | 9 / **3** | 12 / **3** | — | **完全一致**（名稱集合 hash 相同） |
| HKD | 60 / **30** | 60 / **30** | — | 不一致（需正規化後才能去重） |
| THB | 74 / **37** | 66 / **33** | 52 / 52 | 不一致 |

- **BDT 主檔只需補 3 列**（Bkash Wallet / Rocket Wallet / Nagad Wallet），兩站名稱完全相同，可直接建。21 列 `banks` 描述的就是這 3 家。
- **HKD 兩站各 30 家但名稱集合不同** ⇒ 真實機構數在 30–60 之間，要先做名稱正規化才能定案。THB 同理（37 / 33 / 52）。
- VWorld 那 52 列 THB 是 52 個不同名稱、零重複，且全在非白標子樹（§2.1），格式未經正規化，優先度最低。

⇒ 原文件 §2 寫的「THB 37 / HKD 30 / BDT 3」其實**是對的**——那是 TPower 的機構數。而 333 是列數。兩個數字沒有矛盾，是不同度量；要補的主檔列數以機構數為準。

### 2.4 複製痕跡：站內正式↔測試是整份複本，跨站不是互抄

用銀行代碼集合的 hash 比對（同幣別、同站、跨組別）：

| 站 | 幣別 | 正式組別 | 測試組別 | 代碼集合 |
|---|---|---|---|---|
| TPower | HKD | g674（30 列） | g688（30 列） | **hash 相同** |
| TPower | THB | g674（37 列） | g688（37 列） | **hash 相同** |
| TPower | BDT | g674（3 列） | g688（3 列） | **hash 相同** |
| Yes2Win | HKD | g7（30 列） | g12（30 列） | **hash 相同** |
| Yes2Win | THB | g7（33 列） | g12（33 列） | **hash 相同** |

**測試組別是正式組別的整份複本**，這解釋了 §1.2 的「測試組別列數與正式幾乎等量」。建立時間也是秒級批次（例：Yes2Win g12 的 HKD 30 列 + THB 24 列全在 2025-11-26 22:16–22:17 這一分鐘內寫入）。

但**跨站不是互抄**：代碼各自帶站別前綴（TPower 用 `TPHK001`、`TPTH01`；Yes2Win 用 `Y2WHK01`、`Y2WTH001`），所以 HKD / THB 沒有跨白標複製的跡象。

#### 例外：BDT 有標準碼導入到一半的分裂

BDT 是唯一跨站出現同一套代碼（`BDT0001` / `BDT0002` / `BDT0003`）的幣別。依建立時間排序，是**標準碼導入沒做完**，不是站間互抄：

| 時間 | 事件 |
|---|---|
| 2025-08-14 | Yes2Win 正式組別 `g7` 建立 `Y2WBD01-03`（自訂碼，最早） |
| 2026-02-02 | `BDT0001-0003`（不帶站別前綴的標準碼）首次出現於 Yes2Win 的 `g0` 模板與 `g12` 測試組別 |
| 2026-02-26 | 同一套標準碼出現於 TPower 的 `g0` 模板與 `g688` 測試組別 |
| 2026-03-01 | TPower 正式組別 `g674` 採用標準碼 |
| 2026-03-02 | Yes2Win 的 `g0` 模板又補上 `Y2WBD01-03` |

結果是：**TPower 正式已切標準碼，Yes2Win 正式仍卡在自訂碼**（`Y2WBD01-03`，37 個會員帳戶綁在上面），而 Yes2Win 的 `g0` 模板同時存著兩套碼、共 6 列描述同 3 家機構、零綁定——**模板層的垃圾會持續複製到每個新開組別**。

#### 另一組複製殘留：TPower 庫的四筆孤兒列

TPower 庫有 4 筆 active banks 掛在 `g70` / `g93` / `g95` / `g116` 上，這四個組別在該庫**零代理指向**。四筆內容完全相同——都是 `United Overseas Bank Limited` / 代碼 `UOBSG` / 三方碼 `UOB` / SGD / `type = 4`，建立於 2023-04~05（TPower 拆庫之前），而且名稱還是舊格式（缺 `(SGD) - ` 前綴，不符 `banks.name` 命名規範）。

`g70` / `g93` 正好是 VWorld 連線裡 WTT 的正式 / 測試組別 id ⇒ 這是**跨白標複製的殘留**（`agent_group_id` 是 per-DB 的，複製過來的組別 id 在新庫沒有對應代理）。四筆均可清理，但 `updated_at` 都是 2026-08-12 00:04:57，表示仍被某個批次掃到，清理前要先確認是哪支命令在動它們。

#### 清理候選 204 列（判準：綁定者是正式帳號還是測試帳號）

單看「這一列有沒有被綁定」不足以決定能否清理——還要看**綁定的人是誰**。判準是從會員回推：`member_bank_accounts.member_id` → `members.parent` → `agents.cash_flow_group_id`，落在正式組別 id 就是正式帳號，落在測試組別 id 就是測試帳號。**綁定者是測試帳號的列，清理不影響真實會員。**

實查結果（TPower 正式 674／測試 688，Yes2Win 正式 7／測試 12）：

| 銀行列所在組別 | 綁定者所在組別 | TPower | Yes2Win |
|---|---|---|---|
| 測試 | **測試** | HKD 8・BDT 10 | HKD 5・THB 5・BDT 6 |
| 測試 | 正式 | **0** | **0** |
| 正式 | 正式 | HKD 75・THB 389・BDT 25 | HKD 140・THB 150・BDT 36 |
| 正式 | 測試 | HKD 4・THB 1 | HKD 1・THB 1・BDT 1 |

**測試組別的銀行列沒有任何一筆被正式帳號綁定**（兩站皆為 0）。反向有少量測試帳號綁到正式組別的列（TPower 5 筆、Yes2Win 3 筆），同樣是測試帳號，不影響判斷。

於是清理候選可以放大：

| 類別 | 列數 | 可否清理 |
|---|---|---|
| 測試組別全部列（TPower g688 70・Yes2Win g12 66） | **136** | ✅ 可清——綁定者全是測試帳號 |
| `g0` 模板零綁定列（TPower 3・Yes2Win 6） | **9** | ✅ 可清——零綁定，且是複製源頭 |
| 正式組別零綁定列（TPower 31・Yes2Win 28） | **59** | ✅ 可清——無人綁定 |
| 正式組別有綁定列（TPower 39・Yes2Win 38） | **77** | ❌ **不可動**——會讓正式會員的已綁帳戶失效 |
| （另計）VWorld 非白標子樹 THB | 52 | 待定——不屬營運白標 |

兩站 281 列中，**204 列可清、77 列不可動**。

#### 缺陷的真實影響面：815 筆綁定 / 681 個正式會員

用同一判準只計正式帳號，受影響的規模比 §2.1 大得多——§2.1 只算了 HKD + BDT，漏掉 THB：

| 站 | HKD | THB | BDT | 綁定筆數 | 跨幣別 distinct 會員 |
|---|---|---|---|---|---|
| TPower | 75 | 389 | 25 | 489 | **406** |
| Yes2Win | 140 | 150 | 36 | 326 | **275** |
| 合計 | 215 | 539 | 61 | **815** | **681** |

🔴 **會員數不可逐幣別相加**（2026-08-21 修正）：初版寫 721 是把各幣別會員數（TPower 338+70+20、Y2W 128+137+28）直接相加，同一會員綁多幣別即被重複計算。跨三幣別重新取 distinct 才是 TPower 406 / Y2W 275 / 合計 **681**。取數 SQL 見交辦單 §6-⑧。

**THB 是最大的受害面**（539 筆綁定），而它正是問題最重的幣別——TPower 的啟用通道在**代碼層**即不可用（`W_ALL` → AllPay 無代付實作）、Yes2Win 則是零啟用通道。詳見交辦單 §2.1／§2.2。

#### USDT 另案處理

它近 90 天有 464 筆、是活通道，但 `banks.type = 3`（加密貨幣），而 `standard_banks` 是銀行主檔，本質上不屬其範疇 ⇒ 不應計入對齊率分母，也不需要補主檔。

附帶查證：`withdrawal_orders.bank_id` 確認指向 `banks.id`（近 90 天 472 筆的 `wo.bank_name` 與 `b.name` 全數相符）。`bank_id` 欄位語意重載的坑只存在於 `order_images.bank_id`（那個指 `bank_accounts.id`），此處不受影響。

> 資料來源：三幣別盤點由另一 session 於 2026-08-21 實查後移交（該 session 先前給的「69 列可清、應停用」版本已由其自行推翻作廢）。本 session 重跑驗證：333 列分布、引用面、綁定 vs 出款落差全數相符；另自行查出 VWorld 52 列全在非白標子樹、以及 08-20 那批 `updated` 不是排程所為。近 90 天提款單抽驗（TPower：THB 8 筆／HKD・BDT 零筆／MYR 167,957）亦一致。

## 3. 判讀：現況比率是方案 B 的設計邊界

已拍板方向為**方案 B（代付查表化 + `standard_banks` 地基正規化）**，canonical 僅覆蓋營運幣別；全幣別 canonical 屬**方案 C / D**（32–52 人天）尚未批。故本基線不應解讀為「進度落後」，而是「B 的 scope 內已達成、往上需 PM 批 C/D」。

該引用哪個數字：**營運面決策一律用 §1.2 的 38.1%（正式代理組別，996 筆）**；§1 的 39.6% 是含測試組別與 base 模板的整庫值，只適合當跨時點的粗略趨勢對照。

依 §1.2 與 §2.1，不寫任何 curation 就能推進的三件事，按可動性排序：

1. **清理 base 模板 `agent_group_id = 0` 的未對齊列**——它會持續複製到每個新開的組別，是源頭而非末端，且不直接曝露給會員，風險最低。
2. **查清 HKD / BDT / THB「綁定持續、出款歸零」的成因**（§2.1）——這是缺陷排查，不是資料清理；查清前不動這 333 列。
3. **釐清測試組別的處置**：測試組別的銀行列與正式幾乎等量（§1.2），是否納入對齊目標要先定義，否則分母永遠虛胖。

⚠️ 原本列在此處的「停用 HKD/BDT 死通道（69 列）」**已作廢**——那個結論建立在「無人使用」的誤判上，實際有 **815 筆綁定 / 681 個正式會員**在這三個幣別上，且列數也不是 69 而是 333。詳見 §2.1 與 §2.4。

不過依 §2.4 的判準（回推綁定者是正式或測試帳號），**204 列是可以清的**——測試組別整組 136 列、模板 9 列、正式組別零綁定 59 列；只有正式組別有綁定的 77 列不可動。這比原本一刀切「不能停用」精確得多。

`banks.type` 語意（`app/Models/Bank.php:18`）：1 銀行號碼 / 2 電子錢包 / 3 加密貨幣 / 4 外幣銀行 / 5 未知 / 6 澳洲銀行 / 7 澳洲 PayId。其中 type=3（加密貨幣，六站合計 4 筆）本質上不屬銀行主檔範疇，可排除於分母。

## 4. 可重跑的取數 SQL

```sql
-- 逐站對齊率
SELECT
  (SELECT COUNT(*) FROM banks WHERE deleted_at IS NULL AND status = 1) AS banks_active,
  (SELECT COUNT(*) FROM banks WHERE deleted_at IS NULL AND status = 1 AND standard_bank_id IS NULL) AS unmapped_active,
  (SELECT COUNT(*) FROM standard_banks WHERE status = 1) AS std_active;

-- 主檔涵蓋度 vs 實際使用
SELECT sb.currency_code, COUNT(*) AS std_banks,
       (SELECT COUNT(DISTINCT b.name) FROM banks b
         WHERE b.deleted_at IS NULL AND b.status = 1 AND b.currency_id = sb.currency_id) AS distinct_names_in_use,
       (SELECT SUM(b.standard_bank_id IS NULL) FROM banks b
         WHERE b.deleted_at IS NULL AND b.status = 1 AND b.currency_id = sb.currency_id) AS unmapped_rows
FROM standard_banks sb
GROUP BY sb.currency_id, sb.currency_code
ORDER BY std_banks DESC;
```

VWorld 連線要分站時多一步：先取該子樹的財務組別集合，再用它切 `banks`。`<ROOT_ID>` 代入 registry 的 `rootAgentId`（VWorld 66829 / WTT 59457 / VWSG 49726）；root 的 `hierarchy_path` 由 DB 衍生，不可硬寫成 `/1/<ROOT_ID>`——VWorld 的 root 掛在名義總代下、path 是 `/1/33893/66829`。

```sql
-- Step 1：取該子樹用到的財務組別（cash_flow_group_id）
SELECT DISTINCT ag.cash_flow_group_id
FROM agents ag
JOIN agents root ON root.id = <ROOT_ID>
WHERE ag.hierarchy_path = root.hierarchy_path
   OR ag.hierarchy_path LIKE CONCAT(root.hierarchy_path, '/%');

-- Step 2：用 Step 1 的結果切 banks（一次看完全庫歸屬則用下面這支）
SELECT b.agent_group_id,
       COUNT(*) AS active_banks,
       SUM(b.standard_bank_id IS NULL) AS unmapped
FROM banks b
WHERE b.deleted_at IS NULL AND b.status = 1
GROUP BY b.agent_group_id
ORDER BY active_banks DESC;
```

分辨哪個組別是正式、哪個是測試（`<ROOT_ID>` / `<TEST_ID>` 代入 registry 的 `rootAgentId` / `testRootAgentId`）——單站連線用這支即可，不必先跑 hierarchy：

```sql
SELECT b.agent_group_id,
       COUNT(*) AS active_banks,
       SUM(b.standard_bank_id IS NULL) AS unmapped,
       (SELECT COUNT(*) FROM agents ag WHERE ag.cash_flow_group_id = b.agent_group_id) AS agents_using,
       (SELECT GROUP_CONCAT(x.username) FROM agents x
         WHERE x.id IN (<ROOT_ID>, <TEST_ID>) AND x.cash_flow_group_id = b.agent_group_id) AS role
FROM banks b
WHERE b.deleted_at IS NULL AND b.status = 1
GROUP BY b.agent_group_id
ORDER BY active_banks DESC;
```

死通道判定（§2.1）——逐站跑，看該幣別近 90 天有沒有真實提款單：

```sql
SELECT c.code AS currency, COUNT(*) AS orders_90d, MAX(wo.created_at) AS last_order
FROM withdrawal_orders wo
JOIN banks b ON b.id = wo.bank_id
JOIN currencies c ON c.id = b.currency_id
WHERE wo.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
  AND c.code IN ('THB', 'HKD', 'BDT')
GROUP BY c.code;
```

⚠️ 這支在 TPower / Yes2Win 要跑 4–18 秒（`withdrawal_orders` 是大表），其餘站 <100ms。
