# cash-flow:sync 的全量重建面與補償命令

> 2026-09-16 同步 master 時，從 `docs/capabilities/` 搬到這裡（master 已廢止能力卡體系），本文未改寫，文中「本卡」即指本檔。
> 規則的權威出處仍是各命令自己的 class docblock，本檔是跨命令的對照清單。來源：2026-09-09 銀行命令全量盤點（34 支）。
>
> - **新命令的登記規則**：新寫的命令若寫入下列任一「重建面」，必須在 class docblock 標【分類】Guard 與「⚠️ 非 sync-safe」，寫明觸發時機與退役條件，並登記進本檔清單。判準是「產出是否落在 sync 的來源資料（provider 碼表檔）之內」，不是「有沒有 --dry-run」，也不是「commit 新舊」。
> - **兩類別記反**：「非 sync-safe」是產出會被 sync 沖掉、sync 後必須補跑；「sync 依賴」是改完必須跑 sync 才對齊。兩者方向相反，混記會補跑錯順序。（`bank:recode-bank` 曾是 sync 依賴的例子，現已連帶改寫對照表、不再依賴 sync，見下方）
> - `app/Console/Commands/CashFlow/Guard/` 目錄的語意就是「非 sync-safe 補償命令」；補償命令一律冪等、預設不寫入，重跑安全。
> - 本檔只管「跑完 sync 之後誰要補跑」；「該用哪一支收斂命令」看各命令 docblock（`bank:rename-bank` 是合併兩列、`bank:fix-mismatch` 的 rename 是改名稱、`bank:recode-bank` 只改單列的碼）。

## 四個重建面

下列四項會被**全量重建**，把人工修補沖掉。⚠️ **重建者不只一支，別一律記成 `cash-flow:sync`**：
第 1、3、4 面是 `cash-flow:sync` 自己做的；**第 2 面（`available_banks`）`cash-flow:sync` 完全不碰**
（該命令對 `available_banks` 零引用、也沒有任何寫 `agent_cash_flows` 的路徑，`AgentBankSyncService`
同樣零引用），真正的重建者是 `agent:add-agent-cash-flow`。
兩者由 `cash-flow:auto-sync` 依序串起來（`AutoSyncCashFlowCommand.php:309` 呼 sync、`:358` 呼
add-agent-cash-flow），所以實際操作時「跑完 sync」多半等於四面全中——但只跑 `cash-flow:sync --ids=`
時第 2 面不會動。

| 重建面 | 機制 | 出處 |
|---|---|---|
| `cash_flow_banks` 全表 | `processWithdrawalBanks()` 起手即 `disableAllCashFlowBanks()` 全設 `status=0`，再只把藍圖內的重啟為 1 ⇒ 配置外的 patch 列留在停用即失效 | `SyncCashFlowsCommand.php:1537` |
| `agent_cash_flows.info.available_banks` | **由 `agent:add-agent-cash-flow` 重建，不是 `cash-flow:sync`**。它依「該渠道 `status=1` 的 `cash_flow_banks` 列」整份重算 ⇒ **沒有對應 active cfb 列的項目會被丟掉**（該渠道 cfb 為零列時提早 return、整份不動） | `AddAgentCashFlow.php:1613`（`buildAvailableBanks()` 定義在 `:1711`，只 map cfb 列） |
| base 層 `banks.code` | `syncBankCode()` 的候選查詢**不限代理群組**，會找到別群組的同名銀行並把 base 層的碼改成那一列的；`generateBankCode()` 註解明寫「複用他 group 的碼」 | `SyncCashFlowsCommand.php:2268`（候選 `:2274-2280`、改寫 `:2313`）、`:2166` |
| base 層限額 | `resolveAmountLimits()` 對既有 > 0 的列取 `max(既有, config)` ⇒ **對 base 層調低的上限會被放大回去**。群組層不受影響（`AgentBankSyncService::syncToAgentGroup()` 既有 > 0 就保留） | `SetBankAmountLimitsCommand.php:57-62` 的實證敘述 |

⭐ **判準**：該表的**全量重建者**是不是 sync。不是「唯一寫入者」——至少 6 支命令也寫 `cash_flow_banks`，但只有 sync 會全量重建。

## 補償命令清單（跑完 sync 要逐支重跑）

全部冪等、預設不寫入、重跑安全。

⚠️ **「非 sync-safe 的範圍」欄要連重建者一起看**：寫 `cash_flow_banks`／base 層 `banks`／base 層限額的，重建者是 `cash-flow:sync`；寫 `available_banks`／代理群組層 `banks` 的，重建者是 `agent:add-agent-cash-flow`。`cash-flow:auto-sync` 兩支都串，所以只有「單獨跑 `cash-flow:sync --ids=`」時才分得出差別。

| 命令 | 補什麼 | 非 sync-safe 的範圍 |
|---|---|---|
| `bank:merge-base-duplicate-banks` | base(group 0) 同碼多 active 列收斂 | 全部。各金流商 sync 會各自 seed base 列，重複列會再長出來 |
| `bank:merge-base-synonym-banks` | base 同行不同碼收斂＋停用列孤兒 cfb repoint | 全部。建議與上一支成對執行 |
| `bank:merge-wintoto-drift-banks` | Wintoto g70/g93 agent 層 drift 雙列合併 | 全部。⚠️ 重建者是 **`agent:add-agent-cash-flow`**（`AgentBankSyncService::syncToAgentGroup()` 全專案唯一呼叫點在 `AddAgentCashFlow.php:1790`），不是 `cash-flow:sync`——後者建的 banks 列一律 `agent_group_id = 0` |
| `cash-flow:backfill-tpower-payout-bankcodes` | TPower paypro 缺口的本地碼→三方碼對映 | 全部。sync 靠 name matching 重建、不命中 `TPIDxx` |
| `cash-flow:backfill-wintoto-payout-bankcodes` | Wintoto TTPay／DGPay 代付渠道 `available_banks` 缺口的 canonical 碼條目（**不是 cfb 對映**） | `available_banks`。⚠️ 重建者是 **`agent:add-agent-cash-flow`**，不是 `cash-flow:sync` |
| `cash-flow:backfill-wintoto-payout-bankcode-aliases` | g70/g93 八條 MYR 代付 ACF 的 `available_banks` 雙碼聯集（193 筆） | `available_banks`。⚠️ 重建者是 **`agent:add-agent-cash-flow`**，不是 `cash-flow:sync`。漏跑＝舊碼訂單直接斷付（2026-07 時窗 g70 曾有 913 筆 `WTMY02` 訂單而該群組無對應 banks 列） |
| `bank:fix-cfb-provider-code` | 修正配錯的三方銀行碼 | **部分**。寫入時一律帶人工覆寫標記（`manual_override_at`），sync 重建比對到這一列時不會把三方碼改回設定檔的值；但標記不保護「列被停用」：這一列若綁代理群組層的銀行列、或名稱比對不中，sync 後會停在停用，要用 `bank:reactivate-cfb-rows` 開回來。⚠️ 這支命令開始寫標記之前改過的列沒有標記，sync 比對到時仍會改回設定檔的值（設定檔已同步修正的就不受影響；未逐列清查有哪些）。退役條件＝sync 不再靠 name matching 重建 |
| `bank:set-amount-limits` | 逐列調整提款限額 | **只有 base 層**（群組層 sync-safe）。命令對 base 層目標列會明示警告、不阻斷 |
| `bank:rename-bank` | 同群組同行不同碼收斂 | **條件式**：base 層仍有同碼 active 列時，收斂可能被後續 sync 推翻（`:265`、`:551`、`:950-953` 皆有警告） |
| `bank:fix-mismatch --collision-keep` | 人工挑碼（讓存活的 banks 列與存活的 cfb 列分離） | 手動挑的 `provider_bank_code` 會被重建回配置檔的值 |
| TPower DANA 的 cfb 補列 | cf102/IDR 四筆 cfb | 全部。漏跑＝DANA 代付回到「不支援銀行」 |
| Yes2Win DGPay 的 OVO cfb 補列（2026-09-17） | cf120/IDR 一筆：cfb 2154 `Y2WID099`→`ovo`，綁 base 層 893「(IDR) - Ovo Payment」。補跑＝`bank:add-cfb-rows --acf=284 --target=Y2WID099` | 全部。sync 起手會先停用整表；重建時能不能再比對到 893 **未驗證**：893 掛著標準銀行 `IDR_OVO`，`Data/banks/IDR.php` 的 OVO 別名也含「Ovo Payment」，可能比對得到。保守起見，sync 後仍重跑一次，這支命令遇到已有一致的啟用列會略過。漏跑時 DGPay 開查表後，OVO 代付會查無碼而擋單；目前 DGPay 不在開關名單上，出款走清單，不受影響 |
| VWorld PayEssence 的 HSBC cfb 補列（2026-09-17） | cf82/MYR 一筆：cfb 1219 `TPMY14`→`26`，綁代理群組 3 的銀行列 1264「(MYR) - HSBC Bank」。補跑＝`bank:add-cfb-rows --acf=270 --target=TPMY14 --allow-alias`（基底層碼 `WTMY13` 的 cfb 513 也是 `26`、同一家銀行，仍有代理群組在用，所以要放行別名） | 全部。這列綁的是**群組層**銀行列，sync 重建只認 base 層，不會被重新啟用 ⇒ 對 PayEssence 跑過 sync 一定要重補。沒有人工覆寫標記。漏跑時 PayEssence 開查表後，代理群組 3 與 6 的 HSBC 代付會查無碼而擋單（兩個群組的清單用的都是 `TPMY14`）；目前 PayEssence 不在開關名單上，出款走清單，不受影響 |
| TPower FPay 的 BCA 對照列重新啟用（2026-09-18） | cf68 一筆：cfb 360 `TPID03`→`13`（原本停用、三方碼舊值 `7`），綁基底層銀行列 1225「Bank Central Asia」。補跑＝`bank:reactivate-cfb-rows --cfb=360 --from-third=7 --to-third=13 --reason=<理由> --execute`（命令在 MR !2251，從 master 開的分支 `fix/reactivate-cfb-row-command`；合併前從該分支的本機 checkout 跑） | 部分。這列綁的是**基底層**列，sync 重建認銀行名稱時有機會把它認回來，但**沒有實跑驗證**，保守當成會漏。已有人工覆寫標記（命令一律寫），標記只保護三方碼、不保護列被停用。漏跑時 FPay 開查表後 BCA（TPower 印尼盾最大宗，代理群組 674 有 178 筆綁定）代付會查無碼而擋單；目前 FPay 不在開關名單上，出款走清單，不受影響。cfb 360 當初就是 2026-02-11 被 sync 停用後沒認回來的，所以這一條不是假設 |
| TPower FPay 的 6 列 cfb 補列（2026-09-17） | cf68 六筆。越南盾一筆：cfb 2608 `VND0059`→`8`（DongA），綁 base 層 1721；補之前先停用了舊碼列 cfb 351（`VND0011`→`8`，綁的銀行列已軟刪除）。印尼盾五筆，都綁代理群組 674 的銀行列：cfb 2609 `IDR0134`→`517`、2610 `IDR0139`→`512`、2611 `IDR0133`→`526`、2612 `IDR0137`→`513`、2613 `IDR0140`→`504`。補跑＝`bank:add-cfb-rows --acf=270 --target=VND0059`，以及 `bank:add-cfb-rows --acf=272 --target=<碼> --allow-alias` 五趟（印尼盾這五個碼和基底層碼 `IDR0046`／`IDR0040`／`IDR0055`／`IDR0042`／`IDR0031` 是同一家銀行、共用三方碼，所以要放行別名） | 全部。印尼盾五列綁的是**群組層**銀行列，sync 重建只認 base 層，這五列不會被重新啟用 ⇒ 對 FPay 跑過 sync 一定要重補。人工覆寫標記（`manual_override_at`）：印尼盾五列同日已補上（跑 `cash-flow:remap-tpower-fpay-idr-bankcodes --execute`，零換碼、補 5 列標記，從庫複驗屬實）；**越南盾的 cfb 2608 仍是空的**，那支命令只處理印尼盾，本分支的 `bank:add-cfb-rows` 還不會寫這個標記。標記只保護三方碼，不保護「列被停用」。漏跑時 FPay 開查表後，這六家銀行的代付會查無碼而擋單；目前 FPay 不在開關名單上，出款走清單，不受影響。🔴 另外，沒有人工覆寫標記的列，sync 可能把三方碼改回共用設定檔的舊值（依讀程式碼判斷，**未實跑驗證**）；TPower 印尼盾的三方碼另有一道補償 `cash-flow:remap-tpower-fpay-idr-bankcodes`，sync 後兩道都要跑 |
| `bank:add-cfb-rows` | 依代付 ACF 的 `available_banks` 補缺的 cfb 列（首發＝VWorld MaxPay cf114 的零列） | 全部。sync 起手停用整表、重建只認碼表名稱比對得到的 base 層銀行 ⇒ 本命令建的**群組層列不會被重啟** |

## 方向相反的一類：sync 依賴（不要混記）

`bank:recode-bank` **不屬**本清單，也**不再是 sync 依賴**。它改完 `banks.code` 後，同一交易內把引用本列且 `bank_code` 逐字等於原碼的 `cash_flow_banks` 列改成新碼（碼已經不同的列保留並在報表與審計揭露），改完就對齊，不必接著跑 sync。原本的做法是不動對照表、靠人記得跑 sync；那個「改碼後對照表沒跟上」的缺口就是這樣補掉的。目前沒有其他命令屬於 sync 依賴這一類，本節留著是為了下次有人寫出這種命令時能分清方向。

⇒ 非 sync-safe 是「sync 之後補跑」，sync 依賴是「補跑之後 sync」。**順序相反，記錯會讓修補當場失效。**

## 未登記狀態的命令

`cash-flow:reset-banks-min-max-amount` 的 docblock **對 sync 關係零敘述**，且**無 `--execute` 旗標＝預設就寫入**。它與 `bank:set-amount-limits` 功能重疊且行為更粗（從 ACF 重算整個群組＋幣別、只濾 `cash_flow.status` 不濾 ACF 自己的 status），調限額一律改用 `bank:set-amount-limits`。是否退役屬 TODO §2.4 的待裁決項。

## 根治方向（未排程）

讓 matching 讀取端做 identity fallback（sync-safe、全白標受益），而不是每次 sync 後補跑一輪 patch。在那之前，補償命令是唯一手段。

各命令的退役條件寫在自己的 class docblock，多數指向同一個前提：`cash-flow:sync` 改以 `standard_banks` 為單一來源、不再各金流商各自 seed base 列。
