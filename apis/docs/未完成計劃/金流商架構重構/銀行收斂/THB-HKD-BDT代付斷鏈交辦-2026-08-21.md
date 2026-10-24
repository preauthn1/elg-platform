# THB / HKD / BDT 代付斷鏈交辦單（TPower・Yes2Win）

> 🔴🔴 **2026-09-09 停止執行本單的 §4.1「立即止血」——先讀這段再往下看。**
>
> RB 2026-09-09 明確：**這三種幣別目前沒有適配的金流商通道。** 因此 §4.1 那三張 ACF（Yes2Win THB `acf 248`／TPower THB `acf 363`／Yes2Win BDT `acf 229`）**不是「開關沒開、打開就能恢復」的候選**——沒有可用通道，開了也不會恢復出款，本單原寫的「儘快／排程處理」時效**一併作廢、不要照做**。
>
> 另外 RB 2026-08-25 已裁決：**開不開通道是營運方的決策，開發不能擅自啟用。**
>
> **現行結論一律以 [`docs/未完成計劃/RB/TODO.md`](../../RB/TODO.md) §5.7 為準**（本單其餘章節的查證數據仍然有效，只有 §4.1 的處置建議被推翻）。本單仍然成立且仍需要有人處理的是兩件事：①前端銀行清單只查 `banks`、出款卻走 ACF 候選，兩套配置無同步約束也無告警 ⇒ 會員綁得上卻出不了款；②兩個泰銖通道的覆蓋率缺口，**日後真有通道也要一併補**。
>
> **交辦對象**：金流 ops ／代付通道負責人
> **產出日期**：2026-08-21（同日經 Codex 對抗式複查後修訂，修訂點見 §8）
> **前置盤點**：同目錄 `standard_bank對齊率基線-2026-08-21.md` §2.1–§2.4
> **時間慣例**：本文所有時間為 **DB 字面值**。mysql MCP 回傳的 timestamp 標 `Z` 但實際為 DB 字面值減 8 小時，本文已全部換算回字面值。

---

## 0. 結論

會員在前端**看得到、綁得上** THB / HKD / BDT 的提款銀行，但實際**出不了款**。影響 **815 筆銀行綁定 ／ 681 個正式會員**（TPower 489 筆／406 人，Yes2Win 326 筆／275 人）。

斷鏈有**兩種性質，不可一概而論**：

| 性質 | 案例 | 能否靠配置修 |
|---|---|---|
| **純配置**：ACF `status = 0`，正式組別零候選通道 | Yes2Win THB（3 筆全停）、Yes2Win BDT（2 筆全停） | 可，但有覆蓋率缺口（§4.1） |
| **代碼層**：ACF 啟用中，但對應 provider 沒有代付實作 | TPower THB `acf 175` → AllPay | **不可**，配置怎麼改都送不出去 |
| **待釐清**：ACF 啟用中，但幾乎沒被使用過 | TPower HKD `acf 315`（僅 1 筆 pending）、TPower BDT `acf 344`（零 record）、Yes2Win HKD `acf 171`（僅 1 筆 pending） | 需先確認是否真的曾送單（§4.2） |

這不是「通道自然退場、沒人用」——綁定一路持續到 2026-08-18，而成功出款早在 2025-12 至 2026-05 之間就停了。

---

## 1. 機制：前端銀行清單與代付通道，兩套配置之間沒有同步約束

| 面向 | 判定依據 | 代碼位置 |
|---|---|---|
| 前端提款銀行清單 | `banks`：`agent_group_id = 會員代理的 cash_flow_group_id` ＋ `status = 1`。**完全不查 ACF** | `app/Http/Controllers/Frontend/WithdrawalController.php:397-416` |
| 出款通道候選 | `agent_cash_flows`：`cash_flows.type = 3` ＋ `acf.status = 1` ＋ `agent_group_id` ＋ `currency_id`，排除 `cash_flow_id IN (5,6)` | `app/Services/WithdrawalPayoutService.php:422-431`（`fetchPayoutChannels`） |
| 通道對該筆訂單是否可用 | `example.invalid['available_banks'][*].BankCode_ELG` 需含訂單 `bank_code`；另檢 `info.min_amount` / `max_amount` | `app/Services/WithdrawalPayoutService.php:524-556`（`resolveExcludeReason`） |
| provider 解析 | `substr(third_party_code, 2)` → `config('payment.provider.{CODE}')`。`AgentCashFlow::third_party_code` 是 accessor，**轉發到 `cashFlow->third_party_code`**（`app/Models/AgentCashFlow.php:89-94`） | `app/Services/PaymentService.php:33-54` |
| provider 缺代付實作時 | `AbstractDriver::withdrawal()` 拋 `ApiException('{Driver} does not support withdrawal')` | `app/Services/Payment/AbstractDriver.php:65-71` |

⇒ 銀行列存在（前端顯示）與代付通道可用（實際能出款）之間**沒有任何同步或完整性約束，也沒有告警**。執行時兩者僅以 `bank_code` 交會，任一方單獨變動不會帶動另一方。

### 四個排查時容易走錯的地方

1. **候選查詢隱含 soft-delete**：`AgentCashFlow` 使用 `SoftDeletes`（`app/Models/AgentCashFlow.php:54`），故候選查詢還隱含 `deleted_at IS NULL`。手動重跑 SQL 時必須補上，否則會把軟刪列算進來。
2. **候選查詢不檢查 `cash_flows.status`**，只檢查 `cash_flows.type`。停用 `cash_flows` 層不會讓 ACF 從候選清單消失。
3. **出款鏈不檢查 `available_banks[*].status`**；但診斷用的 `BankVisibilityChecker` 會檢查（`app/Services/CashFlow/Bank/BankVisibilityChecker.php:190-202`）。兩者判準**不完全同源**，診斷結果與實際可出款性可能不一致。
4. **`cash_flow_banks` 不是純診斷表——送單階段部分 provider 會直接查它。**
   - 候選清單生成（`fetchPayoutChannels` / `filterChannels`）確實不看 `cash_flow_banks`。
   - 但 **TakaPay 無條件查 `cash_flow_banks` 取 `provider_bank_code`，缺列或該欄為空即直接回失敗**（`app/Services/Payment/Providers/TakaPay/Withdrawal.php:48-64`）。
   - 另有 allowlist 機制 `payment.payout_bank_lookup_providers`（`app/Services/Payment/Traits/WithdrawalPayoutTrait.php:240-305`）供共用轉碼使用；本輪查 TPower / Yes2Win 該 config 皆為 `[]`，但**不影響 TakaPay 自己的直查路徑**。
   - ⇒ 涉及 TakaPay 的處置（本案 Yes2Win BDT）**必須同時檢查 CFB**。

### 候選為空時的行為

`fetchPayoutChannels` 回空集合 → `filterChannels` 直接回傳空 `available`（`WithdrawalPayoutService.php:456-458`）→ `validatePayoutChannel` 回「渠道不屬於本代理群組、幣別不符或已停用」並寫 `Log::warning('Payout channel validation rejected')`。狀態 21（PAYMENT_SUBMITTED）的寫入在驗證**之後**（`:98-101`）⇒ **代付根本無法發起，訂單進不到 21**。

---

## 2. 逐站逐幣別現況（2026-08-21 實查）

`withdrawal_orders.status` 語意（`app/Enums/WithdrawalOrderStatusEnum.php`）：`2` 失敗（migration 註解為「拒絕」）／`3` 通過／**`9` 退款**（migration 註記「退回」）／`22` 處理中／`24` 三方成功／`29` 三方失敗。
⚠️ 前置文件寫的「錢退回會員錢包」在本倉庫**未找到 status 9 的實際入錢流程**，該語意仍屬**未驗證**；本文只據 enum 名稱使用「退款／退回」。

### 2.1 TPower（正式組別 `g674` ／ 測試 `g688`）

| 幣別 | 正式組別唯一啟用的代付通道 | `available_banks` | 限額 | 訂單結果（g674） | 最後成功 |
|---|---|---|---|---|---|
| THB | `acf 175`「(THB) SpeedPay」→ `cf 52`（`W_ALL`） | 22 | 100 – 200,000 | 成功 50 ／**退款 57** ／失敗 23 | 2026-05-28（最後退款 **2026-07-20**） |
| HKD | `acf 315`「(HKD) EasyPay(DG) - Withdraw」→ `cf 76` | 29 | 100 – 1,000,000 | 成功 10 ／**退款 7** ／通過 2 | **2025-12-17** |
| BDT | `acf 344`「(BDT) GanyoPay」→ `cf 90` | 3 | 500 – 25,000 | 成功 **0** ／**退款 4** | **從未成功** |

#### 🔴 THB 的啟用通道在當前代碼下無法送單

`acf 175` → `cf 52`（`third_party_code = W_ALL`）→ `substr(,2)` = `ALL` → `config/payment.php:11` 映射 `Providers\AllPay\Driver`。
但 **AllPay provider 目錄只有 `Deposit.php` / `DepositCallback.php` / `QueryOrder.php` / `AllPayTrait.php`，沒有 `Withdrawal.php` 或 `WithdrawalCallback.php`**，Driver 也未設 `$withdrawal`（`app/Services/Payment/Providers/AllPay/Driver.php:13-27`）⇒ 呼叫 `withdrawal()` 必拋 `ApiException`。

**`git log --all` 查證：`AllPay/Withdrawal.php` 在全 history 從未存在過。**

⇒ TPower THB 的唯一啟用通道**在代碼層即不可用**，該幣別實際上與 Yes2Win 同為「零可用出款路徑」。

#### 那 34 筆成功是誰送出的：舊系統，且已於 2026-05-28 停止

`acf 175` 有 34 筆成功 payout record（`is_success_payout = 1`），最後一筆 2026-05-28。當前 APIs 代碼不可能產生（同上），故發起端在 APIs 之外。查證結果：

- **判準一（能力面）＝定案**：`app/Services/Payment/Providers/AllPay/` 無 `Withdrawal.php`，且 git 全 history 皆無 ⇒ APIs 側在代碼層即無法發起，只能由舊 `API_Agent/.../ThirdPartyApi/AllPayIntegrationHelper.php` 發起。
- **判準二（callback url 指紋）＝不適用**：37 筆記錄的 `payout_order_request_body` 經 `REPLACE(col,'\\/','/')` 反轉義後**全部為 `no_url`**（0 new / 0 legacy / 37 no_url）。AllPay 的回調位址設在三方後台、不由我方下單時傳遞，故此判準對本通道無鑑別力。
- **請求封包結構**：`{request, signMsg, version, merchantNo}`，回應帶 `gbpOrderID` / `gbpOrderNo`、我方單號前綴 `AP`——與 APIs 內任何 provider 的代付實作皆不同源。
- ⚠️ `withdrawal_payout_records` **新舊系統都會寫**，所以「表裡有記錄」本身不能當成走新系統的證據。

**舊路徑的終止時點（訂單面交叉驗證）**：g674 THB 逐月「訂單數 ／ 有無 payout record」——

| 月份 | 三方成功(24) | 退款(9) | 成功單有 payout record |
|---|---|---|---|
| 2026-03 | 4 | 0 | 4／4 |
| 2026-04 | 5 | 5 | 5／5（退款 5 筆**零** record） |
| 2026-05 | 4 | 3 | 4／4 |
| 2026-06 | 0 | 0 | — |
| 2026-07 | 0 | 1 | 0（**未送單**） |
| 2026-08 | 0 | 0 | — |

⇒ **舊系統的 AllPay 代付路徑在 2026-05-28 之後即無任何活動**；2026-07 唯一那筆提款請求連 payout record 都沒有，代表根本沒被送出。TPower THB **自 2026-05-28 起實質零出款能力**（舊路徑停、APIs 路徑代碼層不可用）。

📌 這條線索與既有的「舊系統代付遷移」支線同源，但**結論是「舊路徑已自行停止」，不是「還有一條體制外通道在運作」** ⇒ 不能靠它替代 §4.1 的止血動作。

#### AntzPay 正式組別未啟用

`acf 363`「(THB) AntzPay - Withdraw」（`cf 112`，`W_ANTZPAY`，2026-03-31 建）在正式組別 `g674` 是 `status = 0`，在測試組別 `g688`（`acf 364`）是 `status = 1`。兩筆的 `available_banks`（各 32 家，**經逐值比對完全相等**）與限額（200 – 2,000,000）相同。AntzPay Driver **具有** withdrawal 實作（`app/Services/Payment/Providers/AntzPay/Driver.php:17-25`）。

### 2.2 Yes2Win（正式組別 `g7` ／ 測試 `g12`）

| 幣別 | 正式組別啟用的代付通道 | 訂單結果（g7） | 最後成功 |
|---|---|---|---|
| THB | **無（3 筆全 `status = 0`）** | 失敗 15 ／**退款 23** | **從未成功**（最後退款 2026-08-03） |
| HKD | `acf 171`「(HKD) EasyPay(DG) - Withdraw」，`available_banks` 29、100 – 1,000,000 | **退款 3，其他 0** | **從未成功** |
| BDT | **無（2 筆全 `status = 0`）** | 成功 2 ／失敗 2 ／**退款 6** | 2025-12-01 |

正式組別 `g7` 被停用的三筆 THB：`acf 84`「(THB) SurePay」、`acf 104`「(Old)(THB) DGPay_v2」、**`acf 248`「(THB) AntzPay - Withdraw」**；BDT 兩筆：`acf 45`「[A] (BDT) Takapay」、**`acf 229`「(BDT) TakaPay」**。

⇒ **THB 與 BDT 在正式組別是「零候選通道」**——客服／營運端按下發起就被 `validatePayoutChannel` 擋掉。這與「THB 38 筆訂單零成功」「HKD 3 筆訂單零成功」的觀測一致。

### 2.3 全庫交叉驗證（排除「別的組別或舊系統在代出款」）

Yes2Win 全庫這三個幣別的 `type = 3` ＋ `status = 1` 代付 ACF **只有 5 筆**：

| 組別 | 代理數 | 啟用的通道 |
|---|---|---|
| `g7`（正式） | 20,643 | 只有 HKD `acf 171` |
| `g12`（測試） | 20 | BDT `acf 145`、HKD `acf 170`、THB `acf 85` ＋ `acf 249` |

**服務 20,643 個代理的正式組別只有 1 條通道；服務 20 個代理的測試組別有 4 條。** 另查 `acf 35`（另案「舊系統代付遷移」提及的在用通道）為 **MYR**，與本案無關。綁定面回推亦確認只有 `g7`／`g12` 兩組綁這三個幣別的銀行列。

⚠️ 訂單結果為（幣別 × 組別）聚合，**不能據此證明每一筆訂單都是由本文指定的 ACF 處理**——§2.1 已查證 TPower THB 的歷史成功來自 APIs 之外的舊系統發起端。

---

## 3. 影響面

回推鏈：`member_bank_accounts.member_id` → `members.parent` → `agents.cash_flow_group_id`，落在正式組別 id 即為正式會員。下表只計**正式會員綁在 `status = 1` 銀行列**上的筆數。

| 站 / 幣別 | 綁定筆數 | 該幣別會員數 | 最後綁定 |
|---|---|---|---|
| TPower THB | **389** | 338 | 2026-08-09 |
| TPower HKD | 75 | 70 | 2026-08-05 |
| TPower BDT | 25 | 20 | 2026-08-06 |
| Yes2Win THB | 150 | 128 | **2026-08-18** |
| Yes2Win HKD | 140 | 137 | 2026-07-29 |
| Yes2Win BDT | 36 | 28 | 2026-07-24 |
| **合計綁定** | **815** | — | — |

⚠️ **會員數不可逐幣別相加**——同一會員可能綁多個幣別。跨三幣別重新取 distinct：**TPower 406 人、Yes2Win 275 人，合計 681 人**（不是各列相加的 721）。

**THB 是最大受害面（539 筆綁定）**，而它正是兩站問題最重的幣別：TPower 的啟用通道在代碼層不可用、Yes2Win 零啟用通道。

### 3.1 曝露面 ≠ 實際受阻需求（排優先序要看這張表）

上表 815 筆綁定是**曝露面**（有多少人「可能」撞到），不是實際被擋的提款量。逐月實查 2026-02 起的正式組別訂單，**真正被擋的請求量小得多**：

| 站 / 幣別 | 2026-02 | 03 | 04 | 05 | 06 | 07 | 08 | 近 3 個月是否仍在被擋 |
|---|---|---|---|---|---|---|---|---|
| TPower THB | 4 | 0 | 5 | 3 | 0 | 1 | 0 | 幾乎停（3 個月 1 筆） |
| TPower HKD | 2 | 0 | 1 | 0 | 0 | 0 | 0 | ❌ 零需求 |
| TPower BDT | 1 | 1 | 0 | 0 | 0 | 0 | 0 | ❌ 零需求 |
| **Yes2Win THB** | 2 | 1 | 2 | **17** | 1 | 2 | **4** | 🔴 **是，持續中** |
| Yes2Win HKD | 1 | 0 | 1 | 0 | 0 | 0 | 0 | ❌ 零需求 |
| Yes2Win BDT | 1 | 0 | 5 | 0 | 0 | 0 | 0 | ❌ 零需求 |

（計入 status `2` 失敗與 `9` 退款，即「提了但沒出成」；TPower THB 另有 14 筆成功由舊系統送出，見 §2.1。）

⇒ **只有 Yes2Win THB 仍在持續產生受阻請求**（6／7／8 月連續有，8 月 4 筆）。其餘五個組合近 3–6 個月零需求或近乎零。

---

## 4. 建議處置

### 4.1 ⛔ 已作廢，不要執行——原標題「立即止血：3 筆通道只是『開關沒開』，打開可恢復**部分**出款能力」

> ⛔ **2026-09-09 推翻**：這三種幣別**目前沒有適配的金流商通道**（RB 口述），下表三張 ACF 因此不是可用候選，「儘快／排程處理」的時效**全部作廢**。詳見檔頭紅字與 `docs/未完成計劃/RB/TODO.md` §5.7。下表保留供追溯當初的判斷依據，**不是待辦**。

**依 §3.1 的實際受阻需求排序，三筆不同級**（本節動作以下簡稱「止血動作」）：

| 序 | 處置 | 依據 | 建議時效 |
|---|---|---|---|
| **1** | **Yes2Win THB `acf 248`** | 唯一仍在持續被擋的組合（6／7／8 月連續，8 月 4 筆），且該幣別**全期間零成功出款** | 儘快 |
| 2 | TPower THB `acf 363` ＋停用 `acf 175` | 需求近乎停（3 個月 1 筆），但 389 筆綁定隨時可能再產生需求；且 `acf 175` 是必然拋錯的死路，留著有誤選風險 | 排程處理 |
| 3 | Yes2Win BDT `acf 229` | 近 4 個月零受阻需求，曝露面 36 筆綁定 | 可併入下一批 |


| 站 | 幣別 | 要開的 ACF | 測試組別對照 | `available_banks` 是否逐值相同 | 覆蓋率缺口 |
|---|---|---|---|---|---|
| TPower | THB | `acf 363`（g674） | `acf 364`（g688） | ✅ 32 家完全相等 | 🔴 g674 有 **37** 個 active THB code，只涵蓋 32；缺 `TPTH04`(19 筆綁定)、`TPTH15`(3)、`TPTH19`(0)、`TPTH22`(0)、`TPTH23`(0)＝**22 筆綁定仍無法使用** |
| Yes2Win | THB | `acf 248`（g7） | `acf 249`（g12） | ✅ 32 家完全相等 | 🔴 g7 有 **33** 個 active THB code，只涵蓋 32；缺 `Y2WTH007`＝**3 筆綁定仍無法使用** |
| Yes2Win | BDT | `acf 229`（g7） | `acf 145`（g12） | ❌ **不相同**：229 是 `Y2WBD01/02/03`，145 是 `BDT0001/0002/0003`（僅數量與限額相同） | ✅ g7 的 3 個 active BDT code 全部涵蓋 |

⚠️ **不可寫成「開啟即恢復」**——兩個 THB 通道都無法覆蓋該組別全部 active 銀行列。

⚠️ **`acf 229` 與 `acf 145` 是不同的 target code**，這與前置文件 §2.4 記載的「Y2W 正式仍卡在自訂碼 `Y2WBD01-03`、標準碼 `BDT0001-3` 只進了 g0＋g12」一致，屬 BDT 標準碼導入未收尾。**不要把測試組別的配置直接複製到正式組別**。

**執行前置（不可略過）**：

1. **三方合約／商戶號對該站是否生效**：AntzPay（TPower THB、Yes2Win THB）與 TakaPay（Yes2Win BDT）在該白標是否已簽約開通、額度與 IP 白名單是否就緒。若三方端沒開，啟用只會把「無法發起」換成「三方拒單」。**未驗證，本倉庫查不到。**
2. **TakaPay 專屬前置＝`cash_flow_banks`**（§1 第 4 點）。本輪已驗 Yes2Win `cf 90` 的三碼齊備且 `status = 1`：`Y2WBD01 → 1.bkash`、`Y2WBD02 → 1.rocket`、`Y2WBD03 → 1.nagad`。✅ 這項不阻塞。
3. **連帶決策：TPower `acf 175`（AllPay）應停用。** 依 §2.1，它在代碼層即不可用，留在候選清單只會讓操作人員選到必然拋錯的通道。
   - 停用方式僅限「`status` 1 改 0」：回調 controller 只 `AgentCashFlow::find()` ＋ 檢查 `cashFlow->type`，不檢查 `acf.status`（`WithdrawalNotifyController.php:35`、`DepositNotifyController.php:41`）⇒ 不擋 in-flight 回調。
   - ⚠️ 但**不能據此保證 AllPay 舊單一定能正常回調**：AllPay 本身也沒有 `WithdrawalCallback` 實作。若仍有舊系統或外部回調路徑，本倉庫無法驗證。
4. **覆蓋率缺口的處置需另行決定**：是補 `example.invalid.available_banks`、還是停用那幾個無對應通道的 `banks` 列（後者會讓已綁定會員的帳戶失效）。**這是業務決策，不在本交辦單範圍。**
5. **本交辦單不含任何寫入動作。** 上述皆為生產環境 DB／後台配置變更，須由 ops／通道負責人執行，並在變更前後各留一次 §6 的取數快照。

### 4.2 待釐清：4 筆通道開關是開的卻送不出款，成因各不相同

| 站 | 幣別 | ACF | payout record 實查 | 正確診斷方向 |
|---|---|---|---|---|
| TPower | THB | `acf 175` | 成功 34 ／pending 3（最後 2026-05-28） | **代碼層缺代付實作**（§2.1）。不是三方端問題。34 筆已查證為舊系統發起、2026-05-28 後停止 ⇒ 該 ACF 應停用，不必查三方 |
| TPower | HKD | `acf 315` | **僅 1 筆**，`is_success_payout = 0`（2026-04-24） | 幾乎沒被使用過。先確認「為何幾乎沒被選中」，再查該筆 pending 的三方回應 |
| TPower | BDT | `acf 344` | **零筆** | **從未送單**。不能判定「打不通」；先確認是否曾被選中 |
| Yes2Win | HKD | `acf 171` | 僅 1 筆 pending | 同 `acf 315` |

`is_success_payout` 語意見 `app/Enums/WithdrawalPayoutStatusEnum.php:13`（負值失敗／0 pending／1 success）。
⚠️ 注意 §2.1 的查證：TPower THB 的歷史成功**全部來自舊系統**，不是本系統。查三方端之前，先用 §6-⑤ 確認該通道到底有沒有被本系統送過單——`acf 344` 零筆即屬此類。

### 4.3 根治：建立「銀行清單 ↔ 代付通道」一致性稽核

現況允許「前端顯示某銀行」與「該銀行可出款」長期不一致且無告警。稽核粒度**不能只按（組別 × 幣別）看有無 active ACF**——本輪實測 TPower THB 缺 5 碼、Yes2Win THB 缺 1 碼，這種缺口在粗粒度下會被判為健康。

稽核至少需按 **（財務組別 × 幣別 × bank_code）** 比對，並納入：

- ACF soft-delete（`deleted_at IS NULL`）
- `example.invalid.available_banks` 是否含該 `bank_code`
- `example.invalid.min_amount` / `max_amount` 與該 `banks` 列限額是否相容
- provider 是否具備代付實作（`config('payment.provider')` → Driver 是否設 `$withdrawal`）
- 需要 `cash_flow_banks` 的 provider（如 TakaPay）該碼是否有 active 的 `provider_bank_code`

可複用 `BankVisibilityChecker` 的判定鏈，但須注意它與出款鏈在 `available_banks[*].status` 上判準不同（§1 第 3 點），對齊後才能當單一事實來源。

---

## 5. 待確認（**未驗證**）

1. **AntzPay / TakaPay 在正式組別為何沒開**：代碼與 DB 只能看到「正式關、測試開」，**無法證明原因**是灰度未收尾、刻意保留或漏開。需查 ops 紀錄或問接入負責人。
2. ~~`acf 175` 那 34 筆成功的發起端~~ **✅ 已查證，見 §2.1**：舊系統 API_Agent 發起，且 2026-05-28 後零活動。**剩餘未驗證＝舊系統為何在該日停止**（三方合約到期、商戶關閉，還是舊系統該支被停用），需查舊專案與 ops 紀錄。
3. **EasyPay(DG) 通道三方端是否還通**（TPower HKD `acf 315`、Yes2Win HKD `acf 171`）：兩站各只有 1 筆 pending record，證據不足以判定三方端狀態。
4. **「AllPay 已退場」**作為外部營運事實仍未驗證；但**當前代碼不支援 AllPay 代付已確認**（§2.1）。
5. **每日約 04:02–04:04 的無主批次**：TPower 的 `banks`（2026-08-20 04:02–04:03）與 `agent_cash_flows`（2026-08-21 04:03:07 / 04:03:18 / 04:03:29 / 04:03:41 / 04:03:54）都被**按幣別分秒批次**改寫，而該時間窗的 `command_logs` **只有分鐘級排程**，無 bank / cashflow 命令。
   - ⇒ 行為者疑在 APIs 之外，但「沒有 log」**不足以唯一定位行為者**，仍屬未驗證。
   - 觀察到的批次含 `type = 1` 與 `type = 2`；**未觀察到 `type = 3`（代付）被動**。
   - **這關係到 §4.1 的變更會不會被某個日常批次覆寫回去，建議執行後隔日複查 `status`。**
   - ⚠️ 前置文件列出的 2026-08-20 `banks.updated_at` 時間，在本輪快照已查不到（`updated_at` 可能又被覆蓋），維持未驗證。
6. **`agent_cash_flows.updated_at` 不可用於追 status 變更時點**：2026-08-04 07:20:02 有整表批次更新（TPower 182 列、Yes2Win 175 列）把絕大多數列推到同一秒。要追「何時被停用」須另找審計來源。
7. **`member_bank_accounts.bank_id` 的 schema comment 誤寫為 `order_types.id`**（`database/migrations/2025_06_19_091321_create_member_bank_accounts_table.php:20`），實際關聯與本文查詢皆為 `banks.id`。屬註解錯誤，不影響本文數字。

---

## 6. 可重跑取數 SQL

⚠️ 所有針對 `agent_cash_flows` 的查詢都必須加 `acf.deleted_at IS NULL`（該表有 soft-delete）。

```sql
-- ① 某站三幣別的代付通道配置（正式 vs 測試組別）
--    <PROD_GROUP> / <TEST_GROUP>：TPower = 674 / 688；Yes2Win = 7 / 12
SELECT acf.id AS acf_id, acf.agent_group_id, c.code AS currency, acf.status, acf.name,
       cf.name AS cf_name, cf.third_party_code,
       JSON_LENGTH(JSON_EXTRACT(example.invalid, '$.available_banks')) AS available_banks_cnt,
       JSON_EXTRACT(example.invalid, '$.min_amount') AS min_amt,
       JSON_EXTRACT(example.invalid, '$.max_amount') AS max_amt
FROM agent_cash_flows acf
JOIN currencies c ON c.id = acf.currency_id
JOIN cash_flows cf ON cf.id = acf.cash_flow_id
WHERE acf.deleted_at IS NULL
  AND acf.agent_group_id IN (<PROD_GROUP>, <TEST_GROUP>)
  AND c.code IN ('THB','HKD','BDT')
  AND cf.type = 3
ORDER BY c.code, acf.agent_group_id, acf.id;

-- ② 銀行代碼逐值比對（判「配置是否真的相同」用這支，不要只看 JSON_LENGTH）
SELECT acf.id AS acf_id, acf.agent_group_id,
       JSON_EXTRACT(example.invalid, '$.available_banks[*].BankCode_ELG') AS elg_codes
FROM agent_cash_flows acf
WHERE acf.deleted_at IS NULL AND acf.id IN (<ACF_A>, <ACF_B>);

-- ③ 覆蓋率缺口：該組別該幣別有哪些 active 銀行列不在 ACF 的 available_banks 內
--    先用 ② 取出 ACF 的 code 清單，再跑這支取 banks 側，於外部做差集
SELECT b.code, b.status,
       (SELECT COUNT(*) FROM member_bank_accounts mba WHERE mba.bank_id = b.id) AS bindings
FROM banks b
JOIN currencies c ON c.id = b.currency_id
WHERE b.agent_group_id = <PROD_GROUP> AND c.code = '<CURRENCY>'
  AND b.status = 1 AND b.deleted_at IS NULL
ORDER BY bindings DESC, b.code;

-- ④ 全庫交叉驗證：這三幣別到底有哪些啟用中的代付通道、各服務多少代理
SELECT acf.agent_group_id, c.code AS currency, acf.id AS acf_id, acf.name, acf.status,
       (SELECT COUNT(*) FROM agents ag WHERE ag.cash_flow_group_id = acf.agent_group_id) AS agents_using
FROM agent_cash_flows acf
JOIN currencies c ON c.id = acf.currency_id
JOIN cash_flows cf ON cf.id = acf.cash_flow_id
WHERE acf.deleted_at IS NULL AND cf.type = 3 AND acf.status = 1
  AND c.code IN ('THB','HKD','BDT')
ORDER BY c.code, acf.agent_group_id;

-- ⑤ 該 ACF 到底有沒有被本系統送過單（§4.2 分流的關鍵）
SELECT agent_cash_flow_id, is_success_payout, COUNT(*) AS cnt,
       MIN(created_at) AS first_rec, MAX(created_at) AS last_rec
FROM withdrawal_payout_records
WHERE agent_cash_flow_id IN (<ACF_IDS>)
GROUP BY agent_cash_flow_id, is_success_payout
ORDER BY agent_cash_flow_id;

-- ⑥ 訂單結果分布（判「通道死沒死」用 status=9 退款，比「近 90 天訂單數」精準）
--    ⚠️ withdrawal_orders 是大表，TPower / Yes2Win 需 2–5 秒
SELECT c.code AS currency, wo.agent_group_id, wo.status, COUNT(*) AS cnt,
       MIN(wo.created_at) AS first_order, MAX(wo.created_at) AS last_order
FROM withdrawal_orders wo
JOIN banks b ON b.id = wo.bank_id
JOIN currencies c ON c.id = b.currency_id
WHERE wo.agent_group_id IN (<PROD_GROUP>, <TEST_GROUP>)
  AND c.code IN ('THB','HKD','BDT')
GROUP BY c.code, wo.agent_group_id, wo.status
ORDER BY c.code, wo.agent_group_id, wo.status;

-- ⑦ 影響面：綁定者是正式帳號還是測試帳號（逐幣別）
SELECT c.code AS currency, ag.cash_flow_group_id AS binder_group, b.status AS bank_status,
       COUNT(*) AS bindings, COUNT(DISTINCT mba.member_id) AS members, MAX(mba.created_at) AS last_bind
FROM member_bank_accounts mba
JOIN banks b ON b.id = mba.bank_id
JOIN currencies c ON c.id = b.currency_id
JOIN members m ON m.id = mba.member_id
JOIN agents ag ON ag.id = m.parent
WHERE c.code IN ('THB','HKD','BDT')
GROUP BY c.code, ag.cash_flow_group_id, b.status
ORDER BY c.code, bindings DESC;

-- ⑧ 影響面：跨幣別 distinct 會員數（⚠️ 不可把 ⑦ 的各幣別會員數相加）
SELECT COUNT(*) AS bindings, COUNT(DISTINCT mba.member_id) AS distinct_members
FROM member_bank_accounts mba
JOIN banks b ON b.id = mba.bank_id
JOIN currencies c ON c.id = b.currency_id
JOIN members m ON m.id = mba.member_id
JOIN agents ag ON ag.id = m.parent
WHERE c.code IN ('THB','HKD','BDT') AND b.status = 1
  AND ag.cash_flow_group_id = <PROD_GROUP>;
```

---

## 7. 本文件產製方式

本交辦單由 AI 生成，資料來源為 2026-08-21 對 TPower / Yes2Win 生產庫 read replica 的唯讀查詢，以及 master 分支代碼與 git history 實讀；**未執行任何寫入**。前置盤點文件的 §2.1 / §2.2 / §2.4 數字經獨立重查，綁定筆數與訂單分布逐項吻合；Yes2Win 側的通道配置為本輪新增（前置文件只查了 TPower）。標註「未驗證」的項目需人工確認後才可作為決策依據。

## 8. 修訂紀錄（2026-08-21，Codex 對抗式複查後）

初版有 5 處實質錯誤，皆已於本版修正並逐項自驗：

| # | 初版寫法 | 修正 |
|---|---|---|
| 1 | 影響 **721** 個正式會員 | **681**。初版把各幣別會員數相加，重複計了跨幣別會員（TPower 406＋Yes2Win 275）。815 筆綁定不變 |
| 2 | 三筆止血候選的 `available_banks` **逐項相同** | 只有 TPower 363/364 與 Yes2Win 248/249 逐值相同；**Yes2Win 229 vs 145 是不同 target code**。初版只比對 `JSON_LENGTH` 與限額即下結論，屬方法錯誤 |
| 3 | **開啟即恢復**通道 | 兩個 THB 通道都有覆蓋率缺口（TPower 缺 5 碼／22 筆綁定、Yes2Win 缺 1 碼／3 筆綁定），只能恢復部分 |
| 4 | 補 `cash_flow_banks` **不會**讓出款變可行 | 僅對候選清單生成階段成立。**送單階段 TakaPay 無條件直查 CFB**，缺列即失敗；本案 Yes2Win BDT 必須連帶檢查（已驗齊備） |
| 5 | 成因在**配置層，不在代碼層** | 對 Yes2Win THB／BDT 成立；**對 TPower THB 不成立**——`acf 175` 經 `W_ALL` 解析到 AllPay，而 AllPay 無代付實作（git 全 history 皆無 `Withdrawal.php`），呼叫必拋例外 |

### 第二輪（同日，追查 `acf 175` 那 34 筆成功的發起端）

| 項目 | 結果 |
|---|---|
| 34 筆成功的發起端 | **舊系統 API_Agent**（判準一定案：APIs 側代碼層無法發起）。判準二（callback url 指紋）對本通道**不適用**——37 筆全為 `no_url`，AllPay 回調位址設在三方後台 |
| 舊路徑是否還在運作 | **否，2026-05-28 後零活動**。2026-07 唯一那筆請求連 payout record 都沒有 ⇒ TPower THB 自該日起實質零出款能力 |
| 對止血急迫性的影響 | 不能靠舊路徑替代 §4.1；但實際受阻需求量遠小於曝露面（§3.1）⇒ **三筆止血候選重新排序，Yes2Win THB 為唯一「儘快」等級** |

另補入：候選查詢的 soft-delete 隱含條件、不檢查 `cash_flows.status`、出款鏈與 `BankVisibilityChecker` 在 `available_banks[*].status` 上判準不同、`acf 344` 零 payout record 應歸「從未送單」而非「打不通」、§4.3 稽核粒度需細到 `bank_code`、以及 `acf 175` 那 34 筆成功由舊系統發起的線索（第二輪已查證定案）。
