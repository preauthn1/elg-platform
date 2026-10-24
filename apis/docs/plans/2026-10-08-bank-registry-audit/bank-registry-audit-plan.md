# 標準銀行清單盤點：待處理項目

盤點對象是標準銀行清單，也就是 `app/Console/Commands/CashFlow/Data/banks/{IDR,INR,MYR,SGD,VND}.php`，共 374 條。
2026-10-08 由三個 AI 代理唯讀審查，主線再抽查原始碼，並在六個正式站實跑比對來驗證。
已經處理的部分寫在 `refactor/payment-bank-convergence` 分支上兩個 commit 的內文裡，這份文件只列還沒處理、或要先做決定的項目：

- `fix(bank): 新加坡 Maybank 母行分行條目改用法人名稱，兩個企業戶條目的 SWIFT 改為母行分行代碼`
- `fix(bank): 收緊標準銀行清單裡會搶走別家銀行名稱的關鍵字與別名`

## 先懂比對機制：為什麼清單寫錯會直接寫錯資料

這節在說明下面每一項的嚴重度從哪裡來。

- **同步命令會自動採用比對結果。** `cash-flow:sync` 呼叫 `StandardBankNameHelper::matchBankName()`，信心度 80 以上就算可靠（`isMatchReliable`），直接把三方銀行碼掛到比對到的銀行。光靠關鍵字比中，就有 80～85 分。
- **比對方式很寬鬆。** 比對前會拿掉所有空白，再看輸入有沒有包含那個關鍵字。分數上限是 85，同分時排在清單前面的條目勝出。所以通用字（例如 commercial bank）、短字（例如 sg），還有跨字湊出來的字（「Banten Tbk」拿掉空白後含有「ntb」），都會被比中。
- **回填命令只送人工複核。** `BankStandardMappingService` 的模糊比對固定給 80 分，只放進人工複核清單，不會自動寫入。

## 一、法人合併與改名沒有整併

現在的問題是同一家銀行在清單上有兩個身分，用舊名稱的銀行列和用新名稱的銀行列，會對到不同的標準銀行。

**決策（RB 2026-10-08）**：已經合併或改名的銀行，舊名稱一律收進現在那家的條目當別名，舊條目退場。做法跟 Allo／Harda 那次一樣，一次改到現在那家。

### 退場怎麼做：分兩階段（RB 2026-10-08 選定）

這節說明為什麼光改清單不夠，以及兩階段各做什麼。依據是 2026-10-08 讀原始碼、在六個正式站唯讀查詢的結果。

**查到的事實**

- **seeder 不會讓舊條目退場。** `SyncStandardBanksSeeder` 只做新增與更新，不刪列，也不寫 status。舊條目從清單拿掉後，`standard_banks` 那一列會一直啟用，舊別名也留著。逐字比對（`BankStandardMappingService::matchExact`）只接受唯一的擁有者，舊名稱同時比中新條目和這一列，兩邊都對不到。所以退場一定要把舊列設成停用。
- **沒有前例可照抄。** Allo／Harda 那次是在 `standard_banks` 改由清單產生之前做的，TPower 的表裡沒有 Harda 那一列。
- **引用標準銀行的地方有四個**：`banks`、`agent_banks`（投影表）、`cash_flow_banks` 的 `standard_bank_id`（給投影用的方便欄位）、`bank_mapping_logs`（稽核紀錄）。都沒有外鍵。
- **不影響出款。** 代付查表不使用 `standard_bank_id`（`WithdrawalPayoutTrait` 有註明）。`agent_banks` 投影表在 `app/Http` 和金流以外的服務裡沒有讀取端，只有銀行收斂相關命令在用。前台會員端有沒有間接讀到，沒有逐一追，未驗證。
- **各站同時有舊名列和新名列。** 金流商的銀行清單常同時列舊名和新名（例如 Bank Ekonomi 和 HSBC Indonesia），所以各站兩列都是啟用中。舊條目併進新條目後，同一群組會有兩列啟用中的銀行列對到同一個標準銀行，投影命令（`BankProjectionService`）遇到這種格子會跳過，原因記為 multiple_active。

| 站 | 有銀行列掛在要退場的條目上 | 併完會變成兩列並存的組數 | 有會員綁定的舊名列 |
|---|---|---|---|
| Alfawin77 | 30 條 | 約 70 組，幾乎都是 IDR | Commonwealth 9、Sumitomo 7，其他各 1～2 |
| TPower | 11 條 | 23 組 | DongA 2 |
| Yes2Win | 11 條 | 21 組 | Aceh 10、Riau 2、ANDA 2、DongA 3 |
| VWorld | 10 條 | 6 組，只在基底層 | DongA 3 |
| VPlus33 | 10 條 | 6 組，只在基底層 | 無 |
| Me77 | 無 | 無 | 無 |

「會員綁定」數字是綁在舊名列上的會員提款銀行筆數，查詢時只計數、沒有讀個資。

**第一階段：改清單（在長期分支上做，隨發版生效）**

這步讓舊名稱改對到現在那家，並讓舊的標準銀行列停用。**金流商代碼一個都不動。**

1. 舊條目留在分檔，加上 `retired_into`（併入哪一家的 code）。seeder 寫 `standard_banks` 時，把這一列設成停用，它的名稱與別名併進現在那家。
   - 不讓 seeder 自動停用「清單裡找不到的 code」，原因是有人誤刪一個條目，部署時就會默默停掉一家銀行。明確的標記看得出改了什麼，也能寫測試守住。
2. 要從既有條目拆出來的新條目（第一階段待決項第 3 項的 UUS），標 `sync_visible => false`，只寫進 `standard_banks`。
3. 部署後跑 `bank:sync-mappings` 與 `--repair`，掛在舊條目的銀行列改指到新條目。
4. 代價：上表約 120 組會變成兩列並存，投影表會跳過這些格子。投影表沒有前台或代付在讀，影響只在內部工具。

**修訂：原本寫「舊條目從清單拿掉、併進現在那家」，2026-10-12 實作前改成留在分檔加標記。**
- 錯在哪：`cash-flow:sync` 讀的是分檔，不是 `standard_banks`。它每跑一次，都先把該金流商所有 `cash_flow_banks` 列停用，再把比對得到的列重新啟用（`SyncCashFlowsCommand::processWithdrawalBanks`）。舊條目拿掉後，「BPD Riau Dan Kepri」這類只靠別名才對得到的名稱，會改對到另一列平台銀行，原本那列的 `cash_flow_banks` 就停在停用。綁那列的會員，出款查表會查不到碼。
- 另一個原因：同一個金流商常把新舊名稱各列一筆、各帶一個碼。要留哪個碼依定案一律留新名稱的碼，見下方「第二階段：新舊兩筆都列的金流商」。
- 現在的做法：`cash-flow:sync` 與 `bank:fix-mismatch` 看到的分檔內容跟改之前一樣，只有 `standard_banks` 跟著改。兩種讀法集中在 `StandardBankRegistry`。等第二階段把各站舊名銀行列收成一列，再拿掉這兩個標記。

**第二階段：逐站收斂舊名的銀行列（正式站資料寫入）**

這步把兩列並存收成一列。要等第一階段上線後，逐站排時間。

1. 沒有會員綁定的舊名列：停用。
2. 有會員綁定的舊名列：先把綁定改指到新名列，再停用。
3. 每一站都先備份並確認能還原，先跑預演，由 RB 逐站確認後才寫入。用既有的收斂命令，不寫新的裸 SQL。
4. 要避開的地方：不跑 `dedup-active`；`converge-cross-code` 會硬性排除基底層（群組 0），基底層的列要另外處理。

### 第一階段待決項的拍板（RB 2026-10-12）

這節記錄下面各幣別小節原本留待決定的五件事，以及拍板結果。

| # | 待決項 | 拍板 |
|---|---|---|
| 1 | Aceh、NTB、Riau 已整行轉成伊斯蘭銀行，清單有一般戶與 Syariah 兩條 | 併進 Syariah 條目，一般戶條目列入退場對照表 |
| 2 | Permata、CIMB Niaga、BTN 的伊斯蘭業務單位有重複條目 | 三對都照最終狀態收：Permata UUS 併進 `IDR_PERMATASYARIAH`；CIMB Niaga UUS 併進 `IDR_CIMBNIAGASYARIAH`；BTN UUS 併進 `IDR_VICTORIASYARIAH`，name 改 Bank Syariah Nasional |
| 3 | Kalbar、Kaltim、Nagari 把伊斯蘭業務單位名稱收在一般戶條目 | 三家各開 UUS 條目（`IDR_KALBARUUS`、`IDR_KALTIMUUS`、`IDR_NAGARIUUS`），跟其他地方銀行一致 |
| 4 | Citibank 印度條目去留 | 保留，補註解說明零售已轉 Axis |
| 5 | 改名的銀行要改到清單哪一層 | 只改 `name` 並補別名，`standard_name` 不動 |

**名詞說明。** UUS（Unit Usaha Syariah）是一般銀行內部的伊斯蘭業務單位，不是獨立的公司。伊斯蘭銀行（BUS，Bank Umum Syariah）才是獨立的公司。依印尼規定，UUS 的資產達到 Rp50 兆或母行的一半，就要分拆成伊斯蘭銀行。

**第 2 項的依據。**
- Permata 的伊斯蘭業務單位至今沒有分拆。2026 年 4 月的資產約 Rp37 兆，還沒達到分拆門檻。https://investortrust.id/financial/81340/bank-permata-masih-kaji-peluang-spin-off-lebih-fokus-kembangkan-bisnis
- BTN 的伊斯蘭業務單位借 Bank Victoria Syariah 分拆。Bank Victoria Syariah 在 2025-08-20 改名 Bank Syariah Nasional（BSN），2025-10-02 正式開業。https://example.invalid/news/57678/bbtn-officially-spins-off-its-business-unit-into-bank-syariah-nasional
- CIMB Niaga 股東在 2025-06-26 同意分拆，目標 2026-05-04 營運。找到的報導只到 2026 年 1 月，實際是否已經營運仍未驗證。就算還沒分拆，兩條指的也是同一個單位，所以併了不會對到別家銀行。https://example.invalid/kumparanbisnis/unit-usaha-syariah-cimb-niaga-ditargetkan-mulai-beroperasi-4-mei-2026-25LINZK4YdZ

**第 3 項的依據。** 金流商會給伊斯蘭業務單位一個獨立的三方碼。以 `TopPay.php` 為例，Bank Kalbar 是 `123`、Kalbar UUS 是 `1230`；BPD Kaltim 是 `124`、Kaltim UUS 是 `1240`；BPD Sumatera Barat 是 `118`、它的 UUS 是 `1180`。現在這些 UUS 都對到一般戶的標準銀行，同一個標準銀行底下就有兩個三方碼。
- 馬幣的伊斯蘭子行併在母行條目，跟這裡的做法相反。這是既有決策，見下方馬幣小節，這次不改。

**第 4 項的依據。** Axis 在 2024-07-15 完成 Citi 印度零售客戶的搬遷。Citi 的 IFSC 從 2024-07-05 起停用，存款帳號不變，改用 Axis 的 IFSC。Citi 在印度還有企業金融業務，不算整家合併，所以不套用「舊名收進現在那家」。目前沒有任何金流商在 INR 列出 Citibank。https://www.axis.bank.in/forward-together/migrate-banking-services-citi-new-ifsc-and-micr-for-banking-branches

**第 5 項的理由：`standard_name` 是各站平台銀行的名稱鍵。** `cash-flow:sync` 拿 `standard_name` 去基底層找平台銀行，名稱完全相同才算找到（`SyncCashFlowsCommand::findPlatformBankByName`）。清單上改了 `standard_name`，下次有人跑 sync，就會在基底層多建一列新名稱的平台銀行。所以清單只改 `name`、把新名稱加進別名；平台銀行要改名，留到第二階段用 `rename-bank` 逐站做。
- 適用的條目：`VND_OCEANBANK`（name 改 Modern Bank of Vietnam Limited，官方英文名，2024-12-18 生效，https://bizhub.vn/ocean-bank-officially-renamed-modern-bank-of-vietnam-limited-mbv-post364776.html）、`IDR_RIAUSYARIAH`（改 Bank Riau Kepri Syariah）、`IDR_VICTORIASYARIAH`（改 Bank Syariah Nasional）。

**實作前驗的那件事，結果是會影響。** 讀碼確認舊條目從分檔拿掉後，`cash-flow:sync` 會停用舊名那列平台銀行的 `cash_flow_banks`，所以改成上方「修訂」寫的做法。

### 第一階段實作結果（2026-10-12，分支 `refactor/payment-bank-convergence`）

**改了什麼**
- 分檔：31 筆舊條目標 `retired_into`（IDR 30、VND 1）；新增 3 筆只寫進 DB 的 UUS 條目（`IDR_KALBARUUS`、`IDR_KALTIMUUS`、`IDR_NAGARIUUS`）。
- 改 `name`、不改 `standard_name`：`IDR_RIAUSYARIAH` → Bank Riau Kepri Syariah；`IDR_VICTORIASYARIAH` → Bank Syariah Nasional；`IDR_BTPN` → Bank SMBC Indonesia；`IDR_FAMAINTERNATIONAL` → Super Bank Indonesia；`VND_OCEANBANK` → Modern Bank of Vietnam Limited。
- 別名：INR 九家已合併銀行的舊名加進接手那家；`IDR_MAYBANKSYARIAHINDONESIAUUS` 補「Maybank Syariah」（Maybank Indonesia 的伊斯蘭業務單位至今仍在營運，這個名稱歸它，不隨舊法人併進 Aladin）；刪掉 `IDR_PERMATASYARIAH` 的錯字別名、`MYR_AEON` 的「AEON Credit」。
- 程式：新增 `StandardBankRegistry`；`SyncStandardBanksSeeder` 用它產生要寫進 DB 的列，並把已併入別家的列設成停用；`StandardBankNameHelper` 與 `BankMismatchAuditor` 排除只寫進 DB 的條目。

**怎麼驗的**
- `cash-flow:sync` 的比對結果不變：31 家金流商設定檔共 440 個代付銀行名稱，改檔前後各跑一次 `StandardBankNameHelper::matchAgainstConfig`，逐筆 diff 為 0。
- 這次增刪的別名，跟金流商設定檔裡全部 1,927 個銀行名稱比對，沒有一個相同，所以「先比各站既有平台銀行」那條路徑也不受影響。
- 寫進 DB 後：377 列，其中 31 列停用、3 列只寫進 DB；啟用列之間沒有同名爭用（`StandardBankRegistryTest`）。
- 銀行與金流相關測試目錄 926 passed。

**部署後要做的事（發版不等於生效）**
1. 確認各站 `standard_banks` 有 31 列被設成停用，`IDR_KALBARUUS` 等 3 列已建立。seeder 失敗會吞例外、不擋部署，所以要先查。seeder 會在 log 記一筆 `SyncStandardBanksSeeder deactivated retired entries`，列出這次被停用的 id 與 code；回退發版不會把它們改回啟用，要復原就照這份清單處理。
2. 各站跑 `bank:sync-mappings --repair --dry-run`，確認掛在舊條目的銀行列都改指到現在那家，再正式跑。
3. 再跑一般 `bank:sync-mappings`，讓還沒對到標準銀行、名稱是舊名的列對上。

第 1 步已於 2026-10-12 查完：六站都是 31 列停用、3 列 UUS 已建立且啟用。第 2、3 步的六站預演結果與寫入後的回退清單在 [`phase1-mapping-rollback.md`](phase1-mapping-rollback.md)。

### 第二階段：新舊兩筆都列的金流商（53 筆）

這節是第二階段收斂舊名銀行列時的工作清單。同一個金流商把新舊名稱各列一筆、各帶一個碼，併成一列時只能留一個碼。留哪個碼依下面的定案。

另外兩種列法：只列新名的 89 筆不用處理；只列舊名的 32 筆照用它的碼，併列時把碼帶到現在那家的那一列。

**定案（RB，2026-10-16）**：新舊兩個碼都能送，我方只顯示新名稱的銀行選項，所以這 53 列一律留新名稱的碼，舊名的碼從碼表移除。新名稱同時有一般代付碼和 VA 碼時留一般碼。各家要留的碼，以及原本要問金流商、後來改成自己定案的三件事（TopPay 的 CCB 與 UUS 碼、SurePay 的 IDR 代付碼先全部不收），在 [`phase2-provider-questions.md`](phase2-provider-questions.md)。查證時的幾個事實：
- 六站近一年，在已查到、有納入比對的範圍內，舊名碼沒有成功代付紀錄。
- PayPro 官方銀行清單、FPay 兩套代付銀行清單都同時列出新舊名稱，沒有把舊名下架。
- FPay 的碼依商戶帳號發放、會整組換碼，不能照碼表決定，各站的碼要看 `drift_baselines` 快照。
- 三站收斂預演（TPower 22 組、Yes2Win 21 組、Alfawin77 37 組）只有 3 組可自動收，其餘大多因兩列都有啟用中的引用而要人工裁決；同一家金流商兩列的碼不同時，`bank:rename-bank` 會整組阻斷、不寫入。

| 舊條目 → 現在那家 | 金流商 | 兩筆的名稱與碼 |
|---|---|---|
| IDR_SUMITOMOMITSUIINDONESIA → IDR_BTPN | TopPay | Bank Sumitomo Mitsui Indonesia `045`、Bank BTPN `213` |
| IDR_METROEXPRESS → IDR_SHINHAN | TopPay | Bank Metro Express `1520`、Bank Shinhan `152` |
| IDR_CENTRATAMA → IDR_SHINHAN | TopPay | Bank Centratama `5590`、Bank Shinhan `152` |
| IDR_CNB → IDR_SHINHAN | DgPay | CNB `559`、Bank Shinhan `152` |
| IDR_CNB → IDR_SHINHAN | FPay | CNB `105`、Bank Shinhan `68` |
| IDR_CNB → IDR_SHINHAN | PayPro | CNB `0105`、Bank Shinhan `0068` |
| IDR_CNB → IDR_SHINHAN | TopPay | CNB `559`、Bank Shinhan `152` |
| IDR_CNB → IDR_SHINHAN | TtPay | CNB `559`、Bank Shinhan `152` |
| IDR_CNB → IDR_SHINHAN | Vaderpay | CNB `CNBB`、Bank Shinhan `BSHN` |
| IDR_MULTICOR → IDR_CCBINDONESIA | TopPay | Bank Multicor `10006`、Bank China Construction `9490`、CCB Indonesia `088` |
| IDR_ANDA → IDR_CCBINDONESIA | TopPay | Bank Antardaerah `1163`、Bank China Construction `9490`、CCB Indonesia `088` |
| IDR_ANDARA → IDR_OKEINDONESIA | TopPay | Bank Andara `466`、Bank Oke Indonesia `5260` |
| IDR_DINAR → IDR_OKEINDONESIA | TopPay | Bank Dinar `526`、Bank Oke Indonesia `5260` |
| IDR_NUSANTARAPARAHYANGAN → IDR_DANAMON | DgPay | Bank Nusantara Parahyangan `145`、Bank Danamon `011` |
| IDR_NUSANTARAPARAHYANGAN → IDR_DANAMON | FPay | Bank Nusantara Parahyangan `64`、Bank Danamon `4` |
| IDR_NUSANTARAPARAHYANGAN → IDR_DANAMON | PayPro | Bank Nusantara Parahyangan `0064`、Bank Danamon `0004` |
| IDR_NUSANTARAPARAHYANGAN → IDR_DANAMON | TopPay | Bank Nusantara Parahyangan `145`、Bank Danamon `011`、VA Danamon `10017` |
| IDR_NUSANTARAPARAHYANGAN → IDR_DANAMON | TtPay | Bank Nusantara Parahyangan `145`、Bank Danamon `011` |
| IDR_NUSANTARAPARAHYANGAN → IDR_DANAMON | Vaderpay | Bank Nusantara Parahyangan `NTPY`、Bank Danamon `DNMB` |
| IDR_BRISYARIAH → IDR_SYARIAHINDONESIA | TopPay | Bank BRI Syariah `422`、Bank Syariah Indonesia `4510`、VA BSI `10018` |
| IDR_BNISYARIAH → IDR_SYARIAHINDONESIA | TopPay | Bank BNI Syariah `427`、Bank Syariah Indonesia `4510`、VA BSI `10018` |
| IDR_SYARIAHMANDIRI → IDR_SYARIAHINDONESIA | TopPay | Bank Syariah Mandiri `451`、Bank Syariah Indonesia `4510`、VA BSI `10018` |
| IDR_AGRISUUS → IDR_IBKINDONESIA | TopPay | Bank Agris UUS `1160`、IBK `945` |
| IDR_MITRANIAGA → IDR_IBKINDONESIA | TopPay | Bank Mitra Niaga `491`、IBK `945` |
| IDR_BUMIPUTERA → IDR_MNCINTERNASIONAL | TopPay | Bank Bumiputera `4850`、PT Bank MNC Internasional `485` |
| IDR_EKONOMI → IDR_HSBC | SurePay | Bank Ekonomi `Bank Ekonomi`、Bank HSBC `HSBC` |
| IDR_EKONOMI → IDR_HSBC | TopPay | Bank Ekonomi Raharja `087`、Bank HSBC `041` |
| IDR_MUTIARA → IDR_JTRUSTINDONESIA | TopPay | Bank Mutiara `10010`、Bank JTrust Indonesia `095` |
| IDR_QNBKESAWAN → IDR_QNBINDONESIA | TopPay | QNB Kesawan `167`、Bank QNB indonesia `1670` |
| IDR_HIMPUNANSAUDARA1906 → IDR_WOORISAUDARA | TopPay | Bank Himpunan Saudara 1906 `2120`、Bank Woori Saudara `212` |
| IDR_ANGLOMASINTERNATIONAL → IDR_AMAR | TopPay | Anglomas International Bank `531`、Bank AMAR `1162` |
| IDR_ANZPANIN → IDR_ANZINDONESIA | TopPay | Bank ANZ Panin `0610`、ANZ Indonesia `061` |
| IDR_COMMONWEALTH → IDR_OCBCNISP | DgPay | Bank Commonwealth `950`、Bank OCBC NISP `028` |
| IDR_COMMONWEALTH → IDR_OCBCNISP | FPay | Bank Commonwealth `113`、Bank OCBC NISP `12` |
| IDR_COMMONWEALTH → IDR_OCBCNISP | PayPro | Bank Commonwealth `0113`、OCBC Bank `0012` |
| IDR_COMMONWEALTH → IDR_OCBCNISP | TopPay | Bank Commonwealth `950`、OCBC Bank `028` |
| IDR_COMMONWEALTH → IDR_OCBCNISP | TtPay | Bank Commonwealth `950`、Bank OCBC NISP `028` |
| IDR_COMMONWEALTH → IDR_OCBCNISP | Vaderpay | Bank Commonwealth `CMWB`、OCBC Bank `OCBC` |
| IDR_ACEH → IDR_ACEHSYARIAH | FPay | Bank Aceh `39`、Bank Aceh Syariah `40` |
| IDR_ACEH → IDR_ACEHSYARIAH | PayPro | Bank Aceh `0039`、Bank Aceh Syariah `0040` |
| IDR_ACEH → IDR_ACEHSYARIAH | TopPay | Bank Aceh `116`、Bank Aceh Syariah `1161` |
| IDR_ACEH → IDR_ACEHSYARIAH | Vaderpay | Bank Aceh `ACEH`、Bank Aceh Syariah `ACES` |
| IDR_NTB → IDR_NUSATENGGARABARATSYARIAH | FPay | Bank NTB `54`、Bank NTB Syariah `55` |
| IDR_NTB → IDR_NUSATENGGARABARATSYARIAH | PayPro | Bank NTB `0054`、Bank NTB Syariah `0055` |
| IDR_NTB → IDR_NUSATENGGARABARATSYARIAH | TopPay | Bank NTB `128`、Bank NTB Syariah `1280` |
| IDR_NTB → IDR_NUSATENGGARABARATSYARIAH | Vaderpay | Bank NTB `NTBB`、Bank NTB Syariah `NTBS` |
| IDR_RIAU → IDR_RIAUSYARIAH | FPay | Bank Riau `43`、Bank Riau Syariah `44` |
| IDR_RIAU → IDR_RIAUSYARIAH | PayPro | Bank Riau `0043`、Bank Riau Syariah `0044` |
| IDR_RIAU → IDR_RIAUSYARIAH | TopPay | BPD Riau Dan Kepri `119`、BPD Riau Dan Kepri UUS `1190` |
| IDR_RIAU → IDR_RIAUSYARIAH | Vaderpay | Bank Riau `RIAU`、Bank Riau Syariah `RIAS` |
| IDR_CIMBNIAGAUUS → IDR_CIMBNIAGASYARIAH | TopPay | Bank CIMB Niaga UUS `0220`、CIMB Niaga Syariah `583` |
| IDR_TABUNGANNEGARAUUS → IDR_VICTORIASYARIAH | TopPay | Bank Tabungan Negara (BTN) UUS `2000`、Bank Victoria Syariah `405` |
| VND_DONGA → VND_VIKKIDIGITAL | QuickPay | DongABank `2031`、Vikki Digital Bank Limited `2064` |

### 印尼盾（IDR）

| 舊條目 | 現在是哪一家 | 經過 | 來源 |
|---|---|---|---|
| `IDR_SUMITOMOMITSUIINDONESIA` | `IDR_BTPN` | 2019 年併入 BTPN，2024 年 BTPN 改名 Bank SMBC Indonesia。「SMBC Indonesia」別名目前掛在已消滅的舊條目上 | https://example.invalid.id/news/sah-bank-btpn-ganti-nama-jadi-bank-smbc-indonesia |
| `IDR_METROEXPRESS`、`IDR_CENTRATAMA`、`IDR_CNB` | `IDR_SHINHAN` | 2016 年合併成 Bank Shinhan Indonesia；CNB 就是 Centratama Nasional Bank。檔內 CNB 註解寫的「Bank Capital Nusantara」查無依據（未驗證） | https://example.invalid.id/news/shinhan-bank-akuisisi-75-bank-cnb |
| `IDR_WINDUKENTJANAINTERNATIONAL`、`IDR_MULTICOR`、`IDR_ANDA` | `IDR_CCBINDONESIA` | 2008 年 Multicor 併入 Windu Kentjana；2016 年再和 Bank Antardaerah 合併成 CCB Indonesia | https://example.invalid.id/news/resmi-merger-bank-windu-jadi-bank-ccb-indonesia |
| `IDR_ANDARA`、`IDR_DINAR` | `IDR_OKEINDONESIA` | 2017 年 Andara 改名 Oke；2019 年與 Dinar 合併 | https://example.invalid/read/20170831/90/685663/bank-andara-ganti-nama-menjadi-bank-oke-indonesia |
| `IDR_NUSANTARAPARAHYANGAN` | `IDR_DANAMON` | 2019 年併入 Danamon | https://example.invalid.id/news/bank-danamon-resmi-merger-dengan-bank-nusantara-parahyangan |
| `IDR_BRISYARIAH`、`IDR_BNISYARIAH`、`IDR_SYARIAHMANDIRI` | `IDR_SYARIAHINDONESIA` | 2021-02-01 合併成 Bank Syariah Indonesia | https://example.invalid/read/20210127/231/1348808/beroperasi-1-februari-2021-ini-logo-dan-susunan-direksi-bank-syariah-indonesia |
| `IDR_MAYBANKSYARIAHINDONESIA` | `IDR_ALADINSYARIAH` | 2019 年改名 Bank Net Indonesia Syariah，2021 年再改名 Bank Aladin Syariah。現在的「Maybank Syariah」品牌是 Maybank Indonesia 的伊斯蘭業務單位，應該歸 `IDR_MAYBANKSYARIAHINDONESIAUUS` | https://example.invalid.id/news/diakuisisi-bank-maybank-syariah-indonesia-berubah-menjadi-bank-net-indonesia-syariah |
| `IDR_AGRISUUS` 與 `IDR_MITRANIAGA` | `IDR_IBKINDONESIA` | 2019 年 Bank Agris 與 Mitraniaga 合併成 Bank IBK Indonesia。Bank Agris 是否真的有伊斯蘭業務單位，未驗證 | https://example.invalid/market/20190409144126-17-65477/bank-ibk-indonesia-nama-baru-hasil-merger-bank-agrs-naga |
| `IDR_BUMIPUTERA` | `IDR_MNCINTERNASIONAL` | 2014 年改名 | https://example.invalid/read/20141021/90/266762/icb-bumiputera-berubah-menjadi-bank-mnc-internasional |
| `IDR_EKONOMI` | `IDR_HSBC` | 2017 年成為 Bank HSBC Indonesia | https://example.invalid.id/news/bank-hsbc-indonesia-hasil-integrasi-dua-bank |
| `IDR_MUTIARA`（含 Bank Century） | `IDR_JTRUSTINDONESIA` | 2014 年被 J Trust 收購 | https://example.invalid/news/2014/11/21/j-trust-officially-takes-over-mutiara |
| `IDR_QNBKESAWAN` | `IDR_QNBINDONESIA` | 2014 年改名 | https://example.invalid.id/news/bank-qnb-kesawan-resmi-berganti-nama |
| `IDR_HIMPUNANSAUDARA1906` | `IDR_WOORISAUDARA` | 2014 年合併 | https://example.invalid/news/2014/09/23/himpunan-saudara-woori-plan-merger |
| `IDR_ANGLOMASINTERNATIONAL` | `IDR_AMAR` | 2014 年改名 | https://example.invalid/read/20150212/90/401707/amin-bank-ganti-nama-jadi-amar-bank |
| `IDR_ANZPANIN` | `IDR_ANZINDONESIA` | 2012 年改名 | https://example.invalid.id/news/anz-panin-bank-berubah-nama-menjadi-bank-anz-indonesia |
| `IDR_COMMONWEALTH` | `IDR_OCBCNISP` | 2024-09-01 併入 OCBC Indonesia | https://example.invalid/read/20240611/90/1773112/siap-siap-bank-commonwealth-bakal-gabung-ke-ocbc-indonesia-nisp-1-september-2024 |
| `IDR_FAMAINTERNATIONAL` | 同一家，改名 Superbank | 2023 年改名，清單沒有 Superbank 別名，「Superbank」比不中 | https://example.invalid.id/news/transformasi-jadi-bank-digital-bank-fama-ganti-nama-jadi-superbank |

同一家銀行被拆成兩條的（整行轉成伊斯蘭銀行，或同一個伊斯蘭業務單位被列兩次）：

- `IDR_ACEH` 與 `IDR_ACEHSYARIAH`、`IDR_NTB` 與 `IDR_NUSATENGGARABARATSYARIAH`、`IDR_RIAU` 與 `IDR_RIAUSYARIAH`：這三家地方銀行都是整行轉成伊斯蘭銀行（2016、2018、2022 年）。現行品牌名「Bank Riau Kepri Syariah」目前會被比到 `IDR_RIAU`。
- `IDR_PERMATASYARIAH` 與 `IDR_PERMATAUUS`：同一個伊斯蘭業務單位，至今沒有分拆。別名 `BankPermataSymariah` 是錯字。
- `IDR_CIMBNIAGASYARIAH` 與 `IDR_CIMBNIAGAUUS`：同一個單位，已獲准分拆成獨立銀行，目標 2026 年 5 月營運，實際有沒有開始營運未驗證。
- `IDR_TABUNGANNEGARAUUS` 與 `IDR_VICTORIASYARIAH`：BTN 的伊斯蘭業務單位已分拆並入 Bank Victoria Syariah，2025 年改名 Bank Syariah Nasional，清單比不中這個新名稱。

另外，`IDR_KALBAR`、`IDR_KALTIM`、`IDR_NAGARI` 把伊斯蘭業務單位的名稱收在一般戶條目，跟其他地方銀行另開條目的做法不一致。

以上三段怎麼收，見「第一階段待決項的拍板」第 1～3 項。

### 越南盾（VND）

- `VND_DONGA` 與 `VND_VIKKIDIGITAL` 是同一家：2025-02-15 改名 Vikki Digital Bank，客戶帳號延續。TPower 兩種名稱的銀行列都有。https://en.vietstock.vn/2025/02/donga-bank-renamed-as-vikki-digital-bank-37-600940.htm
- `VND_OCEANBANK` 的 `name` 還是舊的股份公司名，`standard_name` 是一人有限責任公司名，兩者不一致。新名 MBV 已補成別名。`name` 改成 Modern Bank of Vietnam Limited，`standard_name` 不動，理由見「第一階段待決項的拍板」第 5 項。

### 印度盧比（INR）

清單裡沒有任何已合併銀行的舊名稱：Vijaya、Dena（併入 Bank of Baroda）、Syndicate（併入 Canara）、Allahabad（併入 Indian Bank）、Oriental Bank of Commerce、United Bank of India（併入 PNB）、Andhra、Corporation（併入 Union Bank）、Lakshmi Vilas（併入 DBS India）。
依上面的決策，這些舊名稱都要收進接手那家的條目。目前多數舊名稱比不中任何條目，會進人工複核；但 United Bank of India 會被 Bank of India 的關鍵字比中，這是錯的。

上段公營銀行合併的來源：https://www.businesstoday.in/sectors/banks/10-public-sector-banks-to-merge-into-4-today-all-you-need-to-know/story/399792.html

Citibank 印度的零售業務 2024 年 7 月已移轉到 Axis，但 Citibank 在印度仍有企業業務，不算整家合併。條目保留並補註解，見「第一階段待決項的拍板」第 4 項。

### 馬幣（MYR）

- 伊斯蘭子行（CIMB Islamic、RHB Islamic 等）在法律上是獨立法人，但併進母行條目是既有決策（見 `MYR_AFFIN` 的註解）。各站也沒有同一群組同時啟用母行與子行兩列，所以只記錄，不建議改。
- `MYR_AEON` 的別名 `AEON Credit` 是另一家消費信貸公司，不是 AEON Bank。
- Yes2Win 有兩列啟用中的「(MYR) - OGX Bank」，還沒對到標準銀行，會被 GX Bank 的關鍵字比中。OGX 是哪一家，未驗證。

## 二、比對機制本身的問題（在長期分支上做）

- **三個字母以下的關鍵字會跨字比中。** 五個幣別共有幾十個這種關鍵字（`bri`、`bni`、`hd`、`sea`、`gx` 等）。這次只修了實測會誤判的幾個；根本解法是讓 helper 對短關鍵字只做整詞比對，不做子字串比對。
- **回填命令的模糊比對沒有照清單順序。** `BankStandardMappingService::loadStandards()` 讀 `standard_banks` 時沒有 ORDER BY，實際順序大致照 id。所以「變體條目要排在一般條目之前」這條規則，在人工複核清單這條路不成立。建議改成照清單順序，或比照 helper 依關鍵字長度給分。

## 三、只影響人工複核清單的小問題

這節的項目不會自動寫錯資料，只會讓人工複核清單的推薦不準。

- `VND_ACB` 的關鍵字 `asia` 會比中 Southeast Asia Bank、Bank Central Asia。
- `VND_CAKEDIGITAL` 排在 `VND_VPBANK` 之後，「CAKE by VPBank」會被推薦成 VPBank。
- `VND_SHB` 的關鍵字 `shb` 會比中 Shinhan 的簡稱 SHBVN。
- `MYR_PUBLIC` 的關鍵字 `public` 會比中「Bangkok Bank Public Company Limited」。
- `SGD_SINGINVESTMENTSFINANCE` 缺 TPower 正式站名稱「Singapore Investments & Finance Limited」這個別名。目前已經對到正確的標準銀行，但重跑逐字比對時會比不中。

## 未驗證

- 「Bank Capital Nusantara」這個名稱是否存在。
- Bank Agris 是否有伊斯蘭業務單位。
- Bank CIMB Niaga Syariah 是否已經開始營運。
- 越南條目已填的 SWIFT 只依既有知識比對，沒有逐條上網查。
- `VND_ACB` 的別名 Saigon Asia Commercial Bank、`VND_HD` 的別名 Hung Vuong 出處不明。
