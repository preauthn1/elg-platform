# DgPay 對接心得（integration）

> 本檔只記「接入才知道、`api.md` ＋ `Driver` / 回調程式碼查不到」的已驗證落差與踩坑。
> 廠商原文規格見同目錄 `api.md`。

---

## 1. 代付回調比對欄位：官方已確認回傳我方單號（IDR 附 @opcode）✅⚠️

**現象 / 落差**

舊系統 `API_External` 的 `PaymentDgPayController::WithdrawalCallback` 以 **`third_party_transaction_no`（DgPay 的 refId）** 比對代付紀錄：

```php
// 舊系統（API_External）
DB::table('withdrawal_payout_records')->where('third_party_transaction_no', $params['orderId']);
```

**官方文檔核對（2026-07-01，經瀏覽器實抓）**：三份 Notion 文檔的 §4.1 Notify 均載明回調 `orderId` = **"Merchant transaction id"（我方送出的單號 = `transaction_no`）**，**非 refId**。故本次遷移採的**框架標準正確**：

- 代付發起 `orderId` 帶我方 `transaction_no`（`Withdrawal::buildPayoutParams`），回調以 `findAndLockPayoutRecord($input['orderId'])` 比對 `transaction_no`（`WithdrawalCallback`）。舊系統用 `third_party_transaction_no` 的作法不採用。

**⚠️ IDR 例外：回調 `orderId` 附 `@opcode`（已實作切割）**

IDR 文檔 §4.1 明寫 `orderId` = **"Merchant transaction id +@+opcode"**（如 `DGxxxx@dgpay_171`）。

- **已實作（本分支）**：`WithdrawalCallback` 解析 `orderId` 時含 `@` 即切第一段再比對 `transaction_no`（比照代收 orderId 解析）；驗簽仍用**原始** orderId、查紀錄才切（見 §2）。
- **首筆真實代付回調仍務必核對**：撈 `withdrawal_payout_records` 確認 callback `orderId` 命中的是 `transaction_no`（MYR/SGD 原值、IDR 去 `@opcode` 後）。

---

## 2. 回調驗簽已實作（硬拒）— go-live 前須以 oracle 驗證公式 ⚠️

> **修正（2026-07-01，經官方 Notion 文檔核對）**：先前記述「payload 不含可驗證的簽名」有誤。官方 MYR / IDR / SGD V2 三份文檔 §4.1 Notify 均定義回調帶回可驗的 `securityToken = [REDACTED] + orderId + amount + status + secretKey)`（`secretKey` = `info.MerchantKey`）。

**現況（2026-07-01 起）**：`DepositCallback` 與 `WithdrawalCallback` 均已 `use DgPayTrait::verifyCallbackSignature`，在 `validate` 後、交易前驗簽，**不符即硬拒**（回 code `-100`，不動訂單、不留 DB 副作用）。IDR 回調 `orderId` 為 `{交易號}@{opCode}`，驗簽用**原始** orderId、查紀錄才切 `@`。

- **止住的洞**：補驗簽前，知道 `transaction_no`（`DG` 前綴）的攻擊者可偽造成功回調把訂單推進 `THIRD_PARTY_SUCCESS(24)`；硬拒後此路封閉。
- **硬拒的代價（重點）**：公式或「回調必帶 token」假設一旦有偏差（如 `amount` vs `processAmount`、欄位順序），**真回調會被全數誤拒 → 代收卡單不入帳、代付卡在 `PAYMENT_PROCESSING(22)`**（三方已撥款、對帳黑洞、人工重試恐重複撥款）。影響範圍＝全 DgPay 白標、代收＋代付、全幣別。
- **⚠️ go-live 前置驗證（必做，尚未執行）**：公式來源為官方 Notion（登入牆，repo 內無法覆核）。上線前以**真實回調當 oracle** 驗證：
  1. 代收有現成樣本（見 `DepositCallback.php` docblock）：`opCode=dgpay_171`、`orderId=202511275929184104@dgpay_171`、`amount=30.0000`、`status=20`、`securityToken=[REDACTED] `dgpay_171` 的真實 `MerchantKey` 重算 `strtolower(md5(opCode+orderId+amount+status+MerchantKey))`，須等於該 `securityToken`。（若不符，第一嫌疑：金額欄位應取 `processAmount` 而非 `amount`。）
  2. **代付與 SGD V2 回調 repo 內無實測樣本**——各撈首筆真實回調同法覆核後才全開。
- **緩解（驗證完成前）**：回調 IP 白名單（`0.0.0.0`）維持為入口層防線。

---

## 2.1 全新一組 DGPay 配置 go-live 清單（2026-07-01）

> DEV `cash_flows` 現況為「每幣別各一組」且混入資料品質問題（THB 59/60 實為 PayGate、列 60 type 標錯、MYR/THB/IDR 代付端點為黑洞 IP）。決定不原地遷移，改開**全新一組**作為乾淨起點；舊列維持不動、自然凋零。

**已落程式（本分支）**：
- `config/payment.php` 新增 `'DGPAY' => DgPay\Driver::class`（共用同一 Driver）。
- `cash_flows.php` 新增 **id 119 `D_DGPAY`（代收）/ 120 `W_DGPAY`（代付）**（id 選定依據：DEV cash_flows id 1–118 已連續佔滿、115/116 為既有 TGPAY，119/120 為下一組連號空位；種子檔 max 非 DB 真實 max）。
- `Data/providers/DgPay.php` 的 `deposit/withdrawal.cash_flow_id` 指向 119/120。
- 解析鏈：`PaymentService::getProvider` 以 `substr(code,2)` → `DGPAY` → 同一 Driver；runtime 代付渠道以 `(type=3, agent_group_id, currency_id)` 過濾（`Agent/WithdrawalController::fetchPayoutChannels`），單一 cash_flow ＋ 多幣別 binding 安全。

**go-live 維運步驟（依序，尚未執行）**：
- [ ] 跑 `php artisan db:seed --class=SyncCashFlowsSeeder` 建立 cash_flow 119/120。
- [ ] 逐幣別 `php artisan agent:add-agent-cash-flow --agent=<id> --cashflow=119 --currency=MYR|SGD|IDR`（代收）與 `--cashflow=120`（代付）；先 `--dry-run` 預覽。代付側靠 `cash_flow_banks` 生成 available_banks；代收側 `deposit.channels` 依商戶實際支援配置（2026-07-02 收斂：**MYR 3 通道**＝`5_10`/`5_20`/`49_15`、**IDR 1 通道**＝`55_10` QRIS、**SGD 2 通道**＝`P2P`/`QRKYC`；移除清單與治本下架見 §4），`cash-flow:sync --ids=119` 會生成父層＋銀行子項 order_types 並下架 config 已移除者。
- [ ] 各 binding 填入該幣別真實 `MERCHANT` / `MERCHANT_KEY`（新組 SCREAMING_SNAKE key）。
- [ ] **代收 binding 的 `PROVIDER_ID` / `PROVIDER_TYPE` 必須留 `0`（或空）**：新組通道路由由父層 order_type key 的 `{providerId}_{providerType}` 編碼（如 `49_15`）經 `Deposit::resolveProviderPair()` 解析；binding 填了靜態值會**蓋掉全部通道**、整個 binding 退化成單一 provider pair（舊組行為）。
- [ ] **代付 binding 的 `PROVIDER_ID` / `PROVIDER_TYPE` 必須配實值**：MYR Auto=`5`/`10`（Route2）、MYR Manual=`10`/`10`（Route7）、**IDR=`55`/`10`**（官方 IDR §5 Provider Definition 最後一條 Withdraw，2026-07-03 對齊；舊組 `26/10` 為官方已移除的舊 Provider，新組勿沿用）、**SGD=`10`/`10`（Route3，代付走 V1，見 §5）**。留 `0/0` 三方回 `-180 Invalid Payment`（DEV order 135 實測）。**2026-07-03 起 seeder 自動套用**（`withdrawal.info_overrides`，MYR 取 Auto；切 Manual 屬營運決策，DB 手調後 re-run 不會打回）；僅「機制上線前已建立且 info 已存 `0` 值」的舊 binding 因更新語意（既有值優先）需手動修正。
- [ ] **SGD 代收 binding 的 `PAYMENT_CODE` 必須留空**（2026-07-02 起，`Deposit::resolvePaymentType` 動態解析）：paymentType 由 channel 父層 key（`P2P`/`QR`/`QRKYC`，官方 §7）逐請求解析，一個 binding 服務全部 SGD 通道；填了靜態值會 **override 全部通道**、退化成單一 paymentType（鏡像 V1 PROVIDER_ID/TYPE 的 override 行為）。
- [ ] **SGD 代收 binding 的 `info.VERSION` 須為 `V2`；SGD 代付 binding 維持 `V1`**（⚠️ 兩者不同版本，見 §5）：seeder 自動套用（代收 `deposit.info_overrides['SGD']=VERSION V2`；代付 `withdrawal.info_overrides['SGD']=PROVIDER_ID 10/PROVIDER_TYPE 10`，VERSION 不 override 即維持 credentials 預設 `V1`），經 `MergesProviderInfoOverrides` 依「金流類型×幣別」merge。僅「機制上線前已建立」的舊 binding 因更新語意（既有值優先）需手動修正，核對：`SELECT id, cash_flow_id, JSON_EXTRACT(info,'$.VERSION'), JSON_EXTRACT(info,'$.PROVIDER_ID') FROM agent_cash_flows WHERE cash_flow_id IN (119,120) AND currency_id = 2;`（119 代收應 V2、120 代付應 V1＋PROVIDER_ID=10）
- [ ] 首筆真實回調以 §2 的 oracle 法覆核驗簽公式後才全開。

**程式碼相容邊界（2026-07-02 跨白標查證，binding 層）**：`Deposit.php` 的 THB 分支（11/10）、SGD V1 分支（5/10、12/20、49/10、49/15 舊 shape）、MYR E-Wallet 17 系列與 IDR OVO 26/20 **不可移除**——VWorld（THB 代收 acf 130、SGD 代收 acf 121 `VERSION=null`→fallback V1）、TPower（THB 代收 130／代付 230）、Yes2Win（THB 3 筆，含 acf 98 `VERSION='V3'` 落 V2 分支的資料債）仍有啟用中 binding 走舊組 `D_DG`/`W_DG`（與新組共用同一 Driver）。THB 與 SGD-V1 不在新 DGPAY 組範圍（RB 拍板 2026-07-02），舊組維持自然凋零。

---

## 3. 代付金額格式：payload 與簽名共用同一值

舊系統送往三方的 `amount` 為 `$order->amount * 1`（去尾零）；新實作改用 `$withdrawalOrder->amount` 原值，**payload 的 `amount` 與 MD5 簽名拼接用的是同一個值**，三方以我方送出的欄位重算簽名即可驗過，格式差異不影響驗簽。

> 陷阱：若日後改動 `amount` 格式，務必同時用於 payload 與 `generateSignature`，否則三方驗簽失敗（`securityToken` 不符）。

---

## 4. 代收通道下架：`cash-flow:sync` 補上代收側 prune（2026-07-02）⚠️

**維運注意（接入才知道，程式碼原本查不到）**

商戶端停用了部分代收通道，故從 `Data/providers/DgPay.php` 的 `deposit.channels` 移除。移除清單（cash_flow 119）：

| 幣別 | 保留 | 下架（移除） |
|---|---|---|
| MYR | `5_10` `5_20` `49_15` | `49_10` `49_20` `49_25` `49_30` |
| IDR | `55_10`（QRIS） | `55_15`（VA）`55_20`（OVO）`55_21`（Dana）`55_22`（ShopeePay） |
| SGD | `P2P` `QRKYC` | `QR` |

**⚠️ 原本的陷阱（治本前）**：`cash-flow:sync` 代付側有 `disableRemovedBanks`（config 移除銀行→重跑 sync 自動下架），但**代收側 `processDepositChannel` 原本純新增/更新、無 prune**；`AddAgentCashFlow` 也只新增 ACFOT。故「從 config 移除代收通道」**不會**讓既有 `order_types` 下架——會員照樣看得到。

**治本（本分支）**：`SyncCashFlowsCommand::processDepositChannel` 補 `disableRemovedOrderTypes`——處理完 config 通道後，將該 cash_flow 中 **config 已不存在的父層 `order_types`（連同銀行子項）** 設 `status=0`（下架＝status=0，不刪列）。之後「改 config → `cash-flow:sync --ids=119`」一步到位。保守策略：只下架 config 已移除者，不 force 重啟 config 內通道（避免與人工手動下架打架，刻意比代付 pre-disable-all 保守）。

**為何連銀行子項一起下架（而非只父層）**：`OrderTypeService::getOrderTypes` 以每筆自身 `status=1` 過濾（非只看父層）。主存款清單雖只迭代 `parent=0`，但 `DepositController` 的「依 `parent_id` 列銀行子項」endpoint（`$orderTypes->where('parent', $order_type_id)`）會直接吐出子項——若只下架父層、留子項 `status=1`，stale/crafted 請求帶下架父層 id（如 IDR VA `5522`）仍可撈到其 101 個銀行、甚至下單。故父層＋子項一併 `status=0` 才完全封閉。

**下架範圍（Dev cash_flow 119，`--dry-run` 實測）**：9 父層 + 102 子項 = **111 個 `order_types`**（其中 101 來自 IDR VA `55_15`）。`sync` 只動 cash_flow 層 `order_types`，不碰 agent 層 ACFOT（屬 `AddAgentCashFlow` 守備範圍；會員可見性由 `order_types.status=1` 把關已足夠）。

> 廠商官方 Provider Definition（`api-v1.md` §1 / `api-v2.md`）維持完整不動——那是廠商支援清單；本節記的是「本商戶當前啟用/停用」的維運狀態。

---

## 5. 🕳️ 巨坑：SGD 代收走 V2、代付走 V1（同幣別、兩版本混用）⚠️⚠️

**現象（2026-07-03 官方文檔實抓確認）**

DgPay 對 SGD 的**代收與代付使用不同 API 版本**，這與「一個幣別綁一個 VERSION」的直覺相反：

| 金流類型 | 版本 | 官方文檔 | 端點 | 關鍵結構 |
|---|---|---|---|---|
| **SGD 代收** | **V2** | [SGD V2](https://example.invalid/Power-PG-API-Document-SGD-V2-1653a5833ed8801b8360ee6f5e568168) §3.1 | `/v2/deposit` | `paymentType`（P2P/QR/QRKYC）＋ `sender`，V2 簽名 |
| **SGD 代付** | **V1** | [SGD（舊）](https://example.invalid/Power-PG-API-Document-SGD-6fd128f7c2c64791a2bd1f3b249a662c) §3.2 | `/withdraw` | `providerId=10`/`providerType=10`（Route3）＋ `data:{bankName,accNo,accName}`，V1 簽名 |

**⚠️ 先前記述全錯（本次更正）**：舊 `api.md` 文檔連結表誤記「SGD V1（代付）已 302 重導至 V2」——**實際未重導**，舊 SGD 文檔（`6fd128f7`）仍是一份**完整且有效的 V1 結構文檔**，正是 SGD 代付的權威來源。連帶 `api-v2.md` 先前把 SGD 代付寫成「V2 `/v2/withdraw` ＋ `recipientInfo`」全屬誤解。

**官方 SGD 代付規格（舊 SGD 文檔，2026-07-03 實抓）**：
- §2.1.1 簽名（Deposit / Withdraw 共用 V1 拼接）：`orderId + providerId + providerType + currency + amount + reqDateTime + opCode + secretKey`
- §3.2 Withdraw 端點 `/ajax/api/withdraw`；參數帶 `providerId` / `providerType` / `data`（**無** `recipientInfo`）
- §5 Provider Definition — Withdrawal：`Route3 / providerId=10 / providerType=10`，`data:{bankName, accNo, accName}`
- §6 Bank Code Definition — Route2（Withdraw）代付碼 11 個：`PNSG`（Paynow Singapore）/ `DBS` / `UOB` / `OCBC` / `SCIMB` / `SHSBC` / `SSCB` / `SMBB` / `RHBSG` / `CITISG` / `ICBCSG`

**機制為何成立（分離 binding）**：代收 binding = cash_flow **119**（`info.VERSION=V2`）、代付 binding = cash_flow **120**（`info.VERSION=V1`）是**兩筆獨立的 `agent_cash_flows`**，`VERSION` 各自獨立，故「代收 V2＋代付 V1」天然可共存，不需程式為 SGD 特判。

**程式現況（本分支，`Withdrawal.php`）**：
- `buildPayoutParams` 以 `info.VERSION === 'V2'` 分流；V1 分支的 `buildChannelData()` `default` case 已產生 `{bankName, accNo, accName}`（`Withdrawal.php:170`）——**與官方 SGD Route3 完全吻合**。故 **SGD 代付只要 binding 走 V1（PROVIDER_ID=10/PROVIDER_TYPE=10）即自動正確**，無需改碼。
- ⚠️ **config 修正（本次一併）**：`Data/providers/DgPay.php` 的 `withdrawal.info_overrides['SGD']` 原為 `['VERSION' => 'V2']`（基於錯誤規格），已改為 `['PROVIDER_ID' => 10, 'PROVIDER_TYPE' => 10]`（VERSION 不 override → 維持 credentials 預設 `V1`）。代收側 `deposit.info_overrides['SGD']=['VERSION'=>'V2']` 維持不變。
- ✅ **生產實證（跨白標查證，非僅文檔）**：舊組（`W_DG`）啟用中 SGD 代付 binding——VWorld `acf 261/262`、TPower `acf 226`——實測 `info.VERSION=null`（fallback V1）＋ `PROVIDER_ID/TYPE=10/10` **正在打款中**，直接佐證「SGD 代付走 V1 `/withdraw`＋`10/10`」為正解。
- 🔴 **`Withdrawal.php` 的 V2 代付分支（`recipientInfo` ＋ `/v2/withdraw` ＋ V2 簽名）現已無任何幣別觸發＝ dead code**。保留無立即危害，但屬誤導（曾據此誤配 SGD）。**去留待決策**：移除可消除誤配風險，但若未來 DgPay 真開 V2 代付需重寫。暫記於此，未擅動。

**若沒修（假設 config 留 `VERSION=V2`）的後果**：SGD 代付會打 `/v2/withdraw`（V1 文檔無此端點）、送 `recipientInfo`（三方不認）、用 V2 簽名拼接（驗簽失敗）——**首筆 SGD 代付即失敗或卡單**，三方可能已撥款而我方狀態機停在 `PAYMENT_PROCESSING(22)`，對帳黑洞。影響範圍：全 SGD 代付白標。

> **go-live 前置**：SGD 代付首筆真實請求務必實測——確認端點 `/withdraw`、`data` 結構、V1 簽名通過，且回調 `orderId` 命中 `transaction_no`（見 §1）。代付銀行碼以 `available_banks` 映射（我方 `bank_code` → 三方 `PNSG`/`DBS`/… 等 §6 Route2 Withdraw 碼）。
