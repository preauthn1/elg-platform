---
type: Rule
id: rules/standard-bank-list
title: 標準銀行清單維護
description: 標準銀行清單從各幣別設定檔同步到各站，取款銀行與金流商的銀行名稱怎麼比對到標準銀行，已合併或改名的銀行怎麼併入，以及怎麼改取款銀行的顯示名稱。
domain: 金流
subdomain: 渠道與貨幣
tags: [金流, 標準銀行, 銀行主檔, standard bank, standard_banks, 銀行別名, alias, 關鍵字, keyword, 取款銀行, 提款銀行, 提現銀行, withdrawal bank, 代付銀行碼, 三方銀行碼, 銀行比對, bank mapping, 銀行合併, 銀行改名, 銀行名稱, bank:set-name, bank:sync-mappings, cash-flow:sync, cash-flow:seed-standard-banks, USDT - TRC20, Maybank, UUS, 會員]
entities:
  - command: cash-flow:seed-standard-banks
  - command: bank:sync-mappings
  - command: cash-flow:sync
  - command: bank:set-name
  - class: 'Database\Seeders\SyncStandardBanksSeeder'
  - class: 'App\Services\CashFlow\Bank\StandardBankRegistry'
  - class: 'App\Services\CashFlow\Bank\BankStandardMappingService'
  - class: 'App\Helpers\StandardBankNameHelper'
  - class: 'App\Services\CashFlow\Bank\AgentBankSyncService'
  - class: 'App\Http\Resources\Frontend\BankResource'
  - class: 'App\Http\Resources\Frontend\Withdrawal\WithdrawalOrderResource'
  - class: 'App\Models\StandardBank'
  - table: standard_banks
evidence:
  - kind: code
    ref: /database/seeders/SyncStandardBanksSeeder.php
    title: 依各幣別設定檔同步標準銀行表，已併入別家的條目設成停用
    at: 2026-10-20
  - kind: code
    ref: /app/Services/CashFlow/Bank/StandardBankRegistry.php
    title: 設定檔的兩種讀法，已併入別家與只寫進標準銀行表的條目怎麼處理
  - kind: code
    ref: /app/Console/Commands/CashFlow/Data/banks/
    title: 各幣別標準銀行設定檔（MYR、VND、IDR、SGD、INR）
  - kind: code
    ref: /database/migrations/2026_08_28_000001_prefix_standard_banks_code_with_currency.php
    title: 標準銀行代碼補回幣別前綴
  - kind: code
    ref: /app/Console/Commands/CashFlow/Bank/SeedStandardBanksCommand.php
    title: 手動同步標準銀行表的命令（cash-flow:seed-standard-banks）
  - kind: code
    ref: /app/Services/CashFlow/Bank/BankStandardMappingService.php
    title: 取款銀行對到標準銀行的回填與重驗（bank:sync-mappings）
  - kind: code
    ref: /app/Console/Commands/CashFlow/Bank/SyncBankMappingsCommand.php
    title: bank:sync-mappings 命令入口
  - kind: code
    ref: /app/Helpers/StandardBankNameHelper.php
    title: 匯入金流商設定時的銀行名稱比對與信心度
  - kind: code
    ref: /app/Console/Commands/CashFlow/SyncCashFlowsCommand.php
    title: 匯入金流商設定（cash-flow:sync），低信心度略過
  - kind: code
    ref: /app/Console/Commands/CashFlow/Bank/SetBankNameCommand.php
    title: 改取款銀行顯示名稱（bank:set-name）
  - kind: code
    ref: /app/Services/CashFlow/Bank/AgentBankSyncService.php
    title: 建代付金流商戶時依模板層覆寫支付組別層的取款銀行
  - kind: code
    ref: /app/Http/Resources/Frontend/BankResource.php
    title: 會員 App 取款銀行清單顯示的名稱
  - kind: code
    ref: /app/Http/Resources/Frontend/Withdrawal/WithdrawalOrderResource.php
    title: 會員 App 取款紀錄顯示的銀行名稱
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2098
    title: 標準銀行權威合併至各幣別分檔並補回代碼幣別前綴
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2393
    title: 收緊標準銀行清單會搶走別家銀行的關鍵字，新加坡 Maybank 母行分行條目改用法人名稱
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2401
    title: 已合併或改名的銀行在標準銀行表併入現在那家，金流商代碼不動
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2286
    title: 新增 bank:set-name，統一 TPower 與 Wintoto 的 USDT 取款銀行名稱
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4239
    title: "[GFF][TPower]CCPayment-USDT代收代付配置"
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4241
    title: Wintoto 配置 CCPayment（統一 USDT 取款銀行名稱的另一站）
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
---

## 這個主題在講什麼

標準銀行是平台認得的銀行本身，例如「印尼的 Bank Central Asia」。同一家銀行在不同地方有不同寫法：

- 每個站點各自有一份取款銀行，也就是會員在取款頁選的銀行，依支付組別各一份。
- 金流商也各自用自己的寫法列出代付銀行。

標準銀行清單是這些寫法的比對基準：靠銀行名稱與銀行別名，判斷某一列取款銀行，或金流商給的某個銀行名稱，到底是哪一家銀行。

清單寫在程式裡的各幣別設定檔（MYR、VND、IDR、SGD、INR 各一份），隨部署同步到各站的標準銀行表。RD 用命令列做三件事：

- 把各站的取款銀行對到標準銀行。
- 匯入金流商設定時，把金流商的代付銀行碼掛到對的取款銀行。
- 改取款銀行給會員看的名稱。

本份目前收錄以下 MR 建立的行為，以及理解它們必要的既有比對規則：

- MR !2098：設定檔成為唯一來源，代碼補幣別前綴。
- MR !2393：收緊比對用的關鍵字與別名，新增新加坡 Maybank 母行分行條目。
- MR !2401：已合併或改名的銀行併入現在那家。
- MR !2286：改取款銀行顯示名稱的命令。

其餘銀行維運命令（合併重複銀行、改代碼等）的條目，隨後續 MR 補入。

## 怎麼觸發

沒有操作畫面，全部在命令列（容器內 artisan）觸發：

- 同步標準銀行表：每次部署由升級流程自動執行。要在部署前先跑，用 `cash-flow:seed-standard-banks`（可加 `--dry-run`）。
- 取款銀行對到標準銀行：`bank:sync-mappings`（回填尚未對上的列），`bank:sync-mappings --repair`（重驗已對上的列），兩者都可加 `--dry-run`。
- 匯入金流商設定、掛代付銀行碼：`cash-flow:sync`。
- 改取款銀行顯示名稱：`bank:set-name --pair=<取款銀行編號>:<新名稱> --reason=<理由>`，加 `--execute` 才寫入。

結果看得到的地方：

- 會員 App › 取款頁的銀行清單與取款紀錄。
- 代理後台的銀行管理畫面（畫面名稱待確認）。

## 規則

### 標準銀行清單的來源與同步

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 標準銀行清單只有一個來源：各幣別設定檔。每次部署時，系統把各站標準銀行表的名稱、別名、類型改成設定檔的內容，以「代碼加幣別」認定是同一家。設定檔沒變時重跑，不會改動任何一列。 | code:SyncStandardBanksSeeder |
| R-2 | 標準銀行代碼一律帶幣別前綴，格式是「幣別_名稱」，例如 MYR_MAYBANK。部署時，各站既有沒有前綴的代碼會自動補上，已經帶前綴的不會重複補。 | code:SyncStandardBanksSeeder、code:2026_08_28_000001_prefix_standard_banks_code_with_currency |
| R-3 | 補前綴後，如果會跟該站既有的另一家代碼重複，部署時這一步整批中止、一列都不改，並列出代碼重複的列交人工處理。 | code:SyncStandardBanksSeeder |
| R-4 | 設定檔有填 SWIFT 才寫入 SWIFT，沒填時保留各站既有的值，不會清空。SWIFT 只是資訊，不影響比對。 | code:SyncStandardBanksSeeder |
| R-5 | 同步不會把各站手動停用的標準銀行改回啟用，也不會把啟用中的改成停用。唯一例外是設定檔標成已併入別家的條目（見 R-14）。 | code:SyncStandardBanksSeeder |
| R-6 | 設定檔裡的條目，所屬幣別在該站不存在時略過不寫，其他條目照常寫入。 | code:SyncStandardBanksSeeder |
| R-7 | 部署時同步失敗，會整批回復並記錯誤 log，但不擋部署，部署照常完成。所以部署後、跑比對命令之前，要先確認各站標準銀行表已經是新內容。 | code:SyncStandardBanksSeeder |
| R-8 | 在部署前手動同步時，命令先顯示設定檔條目數、可寫入數、其中幾筆會設成停用、略過幾筆。加 `--dry-run` 只顯示、不寫入。 | code:SeedStandardBanksCommand |
| R-9 | 標準銀行表更新後，各站取款銀行對到哪一家標準銀行不會自己跟著變，要另外跑 `bank:sync-mappings` 才生效。標準銀行表的變動也不會改會員看到的取款銀行名稱，因為會員 App 顯示的是各站取款銀行自己的名稱。 | code:BankStandardMappingService、code:BankResource |

### 取款銀行對到標準銀行

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-10 | 跑 `bank:sync-mappings` 時，系統處理還沒對到標準銀行、啟用中且未刪除的取款銀行。名稱跟某一家啟用中的標準銀行的名稱或別名完全相同時（不分大小寫、忽略「(幣別) - 」前綴），自動對到那一家並留紀錄。停用中的標準銀行不參與比對。 | code:BankStandardMappingService |
| R-11 | 同一個名稱同時是兩家以上標準銀行的別名時視為對不準，不自動對上，留給人工複核。同一家的大小寫不同寫法算同一家，不算對不準。 | code:BankStandardMappingService |
| R-12 | 加 `--repair` 時只處理已經對上的取款銀行。名稱現在完全對到另一家時，改對到那一家並留紀錄；名稱完全對不到任何一家時，保留原本的對應，不猜也不清空。`--repair` 不兼做尚未對上的回填。加 `--dry-run` 只列出、不寫入。 | code:BankStandardMappingService、code:SyncBankMappingsCommand |

### 金流商銀行名稱的比對

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-13 | 跑 `cash-flow:sync` 匯入金流商設定時，金流商給的每個代付銀行名稱，先比對該站模板層（支付組別 0 那一層）的取款銀行，再比對設定檔的名稱、別名與關鍵字。信心度 80 分以上自動採用，代付銀行碼掛到那一家。未達 80 分列為低信心度，略過不掛，命令最後列出這些名稱。關鍵字命中是 80 到 85 分，同分時設定檔裡排在前面的條目勝出。 | code:StandardBankNameHelper、code:SyncCashFlowsCommand |
| R-14 | 比對用的關鍵字與別名收緊後，下列越南盾名稱改對到正確的銀行：「Joint Stock Commercial Bank for Investment and Development of Vietnam」（QuickPay 碼 2001）對到 BIDV，不再被 Vietcombank 搶走；「Saigon Commercial Joint Stock Bank」（QuickPay 碼 2009）對到 SCB，不再歸到 Sacombank 或 SHB；「Kien Long Commercial Bank」對到 Kien Long。 | code:StandardBankNameHelper |
| R-15 | 銀行改名後的新名稱對到同一家：越南盾「BVBank」對到 Viet Capital Bank，「MBV」對到 OceanBank 那一家；印尼盾「PT Bank Digital BCA」對到 Bank Royal。 | code:StandardBankNameHelper |
| R-16 | 下列名稱不屬於清單上任何一家，比對結果不會被自動採用：越南盾「SMBC」（三井住友銀行，不是 Saigonbank）、印尼盾「PT Bank Pembangunan Daerah Banten Tbk」、新加坡元「Revolut SG」。 | code:StandardBankNameHelper |
| R-17 | 其他幣別的代表情況：印尼盾「PT Bank Jabar Banten Tbk」對到 BJB 本行，不歸它的伊斯蘭子行；馬來西亞令吉「ShopeePay eWallet」對到 ShopeePay，不被 Touch 'n Go 搶走；印度盧比「Central Bank of India Ltd」對到 Central Bank of India，不被 Bank of India 搶走。 | code:StandardBankNameHelper |
| R-18 | 新加坡的 Maybank 分成兩家：「Malayan Banking Berhad」與「Malayan Banking Berhad, Singapore Branch」對到母行新加坡分行（標準銀行名稱「Malayan Banking Berhad, Singapore Branch」，SWIFT MBBESGSG，原名「Maybank (Corporate Account)」仍是它的別名）；「Maybank Singapore」對到子公司（SWIFT MBBESGS2）。新加坡 HSBC 的企業戶條目 SWIFT 是母行分行代碼 HSBCSGSG。 | code:StandardBankRegistry |

### 已合併或改名的銀行

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-19 | 設定檔把一家已合併或改名的銀行標成「併入現在那家」時，同步會把這家舊條目在標準銀行表設成停用，並把它的名稱、全名與別名併進現在那家的別名。目前有 31 家（印尼盾 30 家、越南盾 1 家），例如 Bank Aceh 併入 Bank Aceh Syariah、Bank Sumitomo Mitsui Indonesia 併入現在的 Bank SMBC Indonesia。 | code:StandardBankRegistry、code:SyncStandardBanksSeeder |
| R-20 | 舊條目的名稱如果已經是同幣別另一家現行銀行的名稱或別名，就不會併進來，避免同一個名稱屬於兩家銀行、兩邊都對不到。 | code:StandardBankRegistry |
| R-21 | 因此部署後跑 `bank:sync-mappings --repair`，各站名稱是舊名的取款銀行會改對到現在那家的標準銀行（停用的舊條目不再參與比對，見 R-10）。 | code:BankStandardMappingService |
| R-22 | 匯入金流商設定時，併入別家的舊條目照常拿來比對，金流商的代付銀行碼掛在哪一列取款銀行不變。例如「(IDR) - Bank Aceh」仍對到 Bank Aceh 那一列。 | code:StandardBankNameHelper |
| R-23 | 這次設成停用的舊條目會記進 log（編號與代碼），作為人工復原的依據。同步只會把列寫成停用、不會寫回啟用，所以之後把「併入現在那家」的標記拿掉，舊條目也不會自己恢復啟用。 | code:SyncStandardBanksSeeder |
| R-24 | 印尼盾有 3 家地方銀行的伊斯蘭業務單位（UUS）只寫進標準銀行表、不參與金流商名稱比對：BPD Kalimantan Barat UUS、BPD Kalimantan Timur UUS、BPD Sumatera Barat UUS（Bank Nagari 的 UUS）。寫入時它們的名稱從同幣別其他家的別名移除，只歸它們自己。 | code:SyncStandardBanksSeeder、code:StandardBankRegistry |
| R-25 | 匯入金流商設定時，R-24 這些 UUS 的名稱（含空白、連字號不同的寫法，例如「BPD Kalimantan Barat-UUS」）一律比對不到、列為低信心度略過，不會掛到一般戶。一般戶的名稱（例如「(IDR) - Bank Kalbar」）照常對到一般戶。 | code:StandardBankNameHelper |
| R-26 | 5 家銀行的標準銀行名稱改成現行名稱：Bank Riau Syariah 改為 Bank Riau Kepri Syariah、Bank Victoria Syariah 改為 Bank Syariah Nasional、Bank BTPN 改為 Bank SMBC Indonesia、Bank Fama International 改為 Super Bank Indonesia、OceanBank 改為 Modern Bank of Vietnam Limited。匯入金流商設定時用來找既有取款銀行的標準名不變，所以不會因改名多建一列取款銀行。 | code:StandardBankRegistry |
| R-27 | 這些變動不影響出款，因為代付時查代付銀行碼，依的是取款銀行的代碼，不看它對到哪一家標準銀行。 | code:StandardBank |

### 改取款銀行的顯示名稱

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-28 | 跑 `bank:set-name` 時理由必填。沒加 `--execute` 只印預檢報表（支付組別、類型、幣別、代碼、狀態、綁定會員數、舊名稱與新名稱），不寫入任何資料。 | code:SetBankNameCommand |
| R-29 | 加 `--execute` 時只改取款銀行的名稱，代碼、手續費、限額、狀態都不動。每改一列留一筆審計，記原名稱、新名稱與理由。要還原，就用同一個命令把名稱改回原名稱。 | code:SetBankNameCommand |
| R-30 | 預檢時只要有一組配對不合格，整批都不寫入。不合格的情況有三種：取款銀行不存在或已刪除；同支付組別、同類型、同幣別裡已經有啟用中的列叫這個名稱；同一批裡有兩組在同範圍內要改成同一個名稱。同名判定不分大小寫。別的支付組別有同名列，或同名的列已停用，不算衝突。 | code:SetBankNameCommand |
| R-31 | 名稱已經逐字等於目標名稱的列略過，不寫入、不留審計，所以同一批配對可以重跑。只差大小寫的仍會改名。 | code:SetBankNameCommand |
| R-32 | 配對格式不對、新名稱是空白或超過 100 字、同一列重複指定時，命令直接擋下、不寫入。新名稱以第一個冒號切開，名稱本身可以含冒號與空白，頭尾空白會去掉。 | code:SetBankNameCommand |
| R-33 | 寫入時先鎖住這些列，再重新檢查一次。預檢之後到寫入之間，如果有人改出同名的列，整批回復。 | code:SetBankNameCommand |
| R-34 | 改名後，會員 App 取款頁的銀行清單顯示新名稱。但該銀行設有會員所用語言的多語名稱時，App 顯示的是多語名稱。這個命令不改多語名稱，預檢報表會提示。 | code:BankResource |
| R-35 | 會員 App 的取款紀錄顯示的是取款銀行現在的名稱，所以改名後，舊的取款紀錄也顯示新名稱。取款訂單建單當下記下的銀行名稱則不改。 | code:WithdrawalOrderResource |
| R-36 | 只改支付組別層的取款銀行不夠。之後建代付金流商戶時，支付組別層同代碼的取款銀行會依模板層整列覆寫，名稱會被蓋回模板層的名稱。所以統一名稱要連模板層一起改，命令不限制層別。 | code:AgentBankSyncService |

## Examples

| 情境 | 結果 | 依據 |
|---|---|---|
| 匯入 QuickPay 越南盾設定，名稱「Joint Stock Commercial Bank for Investment and Development of Vietnam」碼 2001 | 碼 2001 掛到 BIDV | R-13、R-14 |
| 某站有一列取款銀行叫「(IDR) - Bank Aceh」，原本對到 Bank Aceh；部署後跑 `bank:sync-mappings --repair` | 改對到 Bank Aceh Syariah；之後跑 `cash-flow:sync`，金流商的 Bank Aceh 碼仍掛在這一列 | R-19、R-21、R-22 |
| TopPay 印尼盾設定同時有「Bank Kalbar」碼 123 與「BPD Kalimantan Barat UUS」碼 1230 | 123 掛到 Bank Kalbar；1230 列為低信心度略過，不會蓋掉 123 | R-24、R-25 |
| TPower 模板層的「USDT(TRC20)」改成「USDT - TRC20」，支付組別層那列本來就叫「USDT - TRC20」 | 模板層改名並留一筆審計；支付組別層那列略過、不留審計 | R-29、R-31、R-36 |
| 同支付組別已有啟用中的「usdt - trc20」，再把另一列改成「USDT - TRC20」 | 整批不寫入 | R-30 |
