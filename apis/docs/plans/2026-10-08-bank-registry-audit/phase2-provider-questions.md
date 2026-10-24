# 第二階段：各金流商新舊名稱銀行碼的收斂清單

這份文件列出第二階段收斂舊名銀行列時，各金流商碼表要留哪個碼，以及仍要向金流商確認的事。
清單的來源是盤點計劃「第二階段：新舊兩筆都列的金流商」那張 53 列的表（[`bank-registry-audit-plan.md`](bank-registry-audit-plan.md)）。

## 為什麼要先定碼

同一家金流商在碼表裡，同一家銀行的新舊名稱各列一筆、各帶一個碼。舊名銀行列併進現在那家時，只能留一個碼。
收斂工具不會替我們選碼：同一家金流商在兩列各有一個啟用中的碼，而且碼不同時，`bank:rename-bank` 會整組阻斷、一筆都不寫（`RenameBankCommand.php` 的 divergent 判斷）。

## 定案

定案分兩件事：前端給會員看哪個銀行選項，以及送給金流商哪個三方碼。兩件事各自決定，金流商文件用的名稱只影響送碼，不影響顯示。

**顯示：只顯示新名稱（RB，2026-10-16）。** 同一家銀行改過名，前端的銀行選項只留新名稱一個。舊名稱的銀行列收斂進新名稱那列，會員綁定一起搬過去，舊名稱那列停用。

**送碼：依各金流商的清單決定（RB，2026-10-16、2026-10-19）。** 會員選的都是新名稱，送給金流商的三方碼依該金流商清單的狀況分三種：

| 金流商清單的狀況 | 送哪個碼 | 碼表怎麼改 |
|---|---|---|
| 新舊名稱都有列 | 新名稱的碼 | 舊名稱的碼從碼表移除。新舊兩個碼都能送，留新名稱的碼。 |
| 只列舊名稱 | 舊名稱的碼 | 碼不刪，條目名稱改成新名稱、碼不變。金流商沒有更新清單時，舊碼是我們手上唯一的碼。 |
| 只列新名稱 | 新名稱的碼 | 不用改。 |

- 「新舊名稱都有列」就是下面各金流商那幾張表，適用每一家。
  - 金流商的清單本身也對得上：PayPro 官方銀行清單、FPay 兩套代付銀行清單，都同時列出新舊兩個名稱，沒有把舊名下架。
  - 法人合併的組（CNB→Shinhan、Nusantara Parahyangan→Danamon、Commonwealth→OCBC 這類）同樣留新名稱的碼，因為舊銀行的帳戶已經轉到存續銀行。
- 「只列舊名稱」時，同一條通道若有好幾個舊名稱對到同一家銀行，**留最新那個名稱的碼**，其他舊名稱的碼移除（RB，2026-10-19）。例如 CCB Indonesia：Bank Antardaerah 先併入 Bank Windu Kentjana International，Windu Kentjana 再改名為 CCB Indonesia，所以留 Windu Kentjana 的碼。
- 「只列舊名稱」要把條目名稱改掉，不能原樣留著：`cash-flow:sync` 是用名稱比對找銀行列，名稱留舊的，舊名列停用之後比對不到，可能會另外新建一列，舊銀行又長回前端（推測，未驗證）。
- 「只列舊名稱」的舊碼現在還送不送得到，碼表看不出來。要用近期的成功代付實績佐證，FPay 可以用它的查詢提款銀行清單介面現查；查不到實績的，標「未驗證」。
- 哪些金流商、哪些銀行屬於「只列舊名稱」，清單見下方「只列舊名稱的銀行」那節，共 32 筆。

**新名稱同時有一般代付碼和 VA 碼時，留一般代付碼（RB，2026-10-16）。** VA 是 Virtual Account（虛擬帳號），是銀行發給商家或錢包的專屬帳號，不是會員自己開的存款戶。TopPay 的 VA 碼（`10011`～`10018`）只列在代付銀行碼表，代收用的是另一套付款方式名稱，裡面沒有 VA 碼（[`TopPay/api.md`](../../integrations/payments/TopPay/api.md) 第 9.1、9.2 節）。我方的提款銀行選項是讓會員填自己的存款帳號，所以送一般碼才對。TopPay 第 9 組留 `011`、第 10～12 組留 `4510`。

未驗證：一般存款帳號送 VA 碼會被拒還是照樣到帳，文件沒寫。Alfawin77 曾用 `10018` 成功代付一筆到 BSI，那筆的收款帳號是不是 VA，沒有查。

## 原本要問金流商的三件事：都已定案，不必問（RB，2026-10-16）

原本列了三題要問金流商。對照官方文件之後，三題都能自己定。

**同一家銀行在金流商有好幾個碼時，留我方碼表裡已經在用的那個碼。** 理由：金流商碼表重複的原因，跟我方要做銀行收斂的原因相同，都是同一家銀行收錄了好幾個別名，每個別名各給一個碼。官方清單新舊碼並存、不下架，跟 FPay 的情況一樣。

1. **TopPay：CCB Indonesia 留 `088`。** TopPay 官方印尼代付碼表（[`TopPay/api.md`](../../integrations/payments/TopPay/api.md) 第 9.2 節）裡，CCB Indonesia 有三個碼：`9490` BANK CHINA CONSTRUCTION、`088` CCB Indonesia、`036` BANK_CCB_INDONESIA。原本以為第 5、6 組是「`9490`／`088` 二選一」，其實三個碼都是同一家。
   - `088` 是我方碼表與正式站對照列現在掛的碼，改動最小。`9490`、`036` 不收。
   - 別跟其他銀行搞混：`949` CTBC Indonesia 是中國信託，`069` 是 Bank of China，兩家都跟 CCB 無關。
   - Alfawin77 的 cfb 297（平台銀行 621「Bank of China」）掛的是 `9490`，這是錯的，要改回官方的 `069`。推測是碼表裡「Bank China Construction」排在 Bank of China 後面，同步時把碼蓋掉了（未驗證）。
2. **TopPay：伊斯蘭業務單位（UUS）留 `1230`、`1240`、`1180`。** 官方另有第二套 UUS 碼，例如 Kalbar UUS 也有 `727`、Kaltim UUS 也有 `729`，這些不收。
   - 部分印尼銀行分一般戶與 UUS，在前端是兩個獨立的銀行選項，金流商代碼也不同，所以 UUS 要有自己的標準銀行條目，不可併進一般戶，也不可隱藏不給選。
   - 標準銀行檔本來就照這個原則，多數 UUS 都有獨立條目。只有 Nagari、Kalbar、Kaltim 三個 UUS 條目還標著 `sync_visible => false`（暫時不讓同步看到），碼定了之後要拿掉這個標記，讓會員選得到。
3. **SurePay：印尼盾代付的碼先全部不收。** 規格書沒有列出任何印尼的代付收款碼，理由與做法見下方 SurePay 那節。

前提（未驗證）：同一家銀行的每個碼都能用。FPay 是對方確認過的，TopPay 只是從官方清單新舊碼並存推論。送出的碼萬一不能用，金流商會拒絕這筆代付，訂單轉 29 後換通道重發，不會送錯銀行。CCB 與這三家 UUS 在 Alfawin77 歷來都沒有任何代付紀錄。

## TopPay（27 組，正式站只有 Alfawin77 在用）

| 組 | 舊名稱與碼 | 現在那家的名稱與碼 |
|---|---|---|
| 1 | Bank Sumitomo Mitsui Indonesia `045` | Bank BTPN `213` |
| 2 | Bank Metro Express `1520` | Bank Shinhan `152` |
| 3 | Bank Centratama `5590` | Bank Shinhan `152` |
| 4 | CNB `559` | Bank Shinhan `152` |
| 5 | Bank Multicor `10006` | Bank China Construction `9490`、CCB Indonesia `088` |
| 6 | Bank Antardaerah `1163` | Bank China Construction `9490`、CCB Indonesia `088` |
| 7 | Bank Andara `466` | Bank Oke Indonesia `5260` |
| 8 | Bank Dinar `526` | Bank Oke Indonesia `5260` |
| 9 | Bank Nusantara Parahyangan `145` | Bank Danamon `011`、VA Danamon `10017` |
| 10 | Bank BRI Syariah `422` | Bank Syariah Indonesia `4510`、VA BSI `10018` |
| 11 | Bank BNI Syariah `427` | Bank Syariah Indonesia `4510`、VA BSI `10018` |
| 12 | Bank Syariah Mandiri `451` | Bank Syariah Indonesia `4510`、VA BSI `10018` |
| 13 | Bank Agris UUS `1160` | IBK `945` |
| 14 | Bank Mitra Niaga `491` | IBK `945` |
| 15 | Bank Bumiputera `4850` | PT Bank MNC Internasional `485` |
| 16 | Bank Ekonomi Raharja `087` | Bank HSBC `041` |
| 17 | Bank Mutiara `10010` | Bank JTrust Indonesia `095` |
| 18 | QNB Kesawan `167` | Bank QNB indonesia `1670` |
| 19 | Bank Himpunan Saudara 1906 `2120` | Bank Woori Saudara `212` |
| 20 | Anglomas International Bank `531` | Bank AMAR `1162` |
| 21 | Bank ANZ Panin `0610` | ANZ Indonesia `061` |
| 22 | Bank Commonwealth `950` | OCBC Bank `028` |
| 23 | Bank Aceh `116` | Bank Aceh Syariah `1161` |
| 24 | Bank NTB `128` | Bank NTB Syariah `1280` |
| 25 | BPD Riau Dan Kepri `119` | BPD Riau Dan Kepri UUS `1190` |
| 26 | Bank CIMB Niaga UUS `0220` | CIMB Niaga Syariah `583` |
| 27 | Bank Tabungan Negara (BTN) UUS `2000` | Bank Victoria Syariah `405` |

## FPay（6 組）

FPay 的碼是依商戶帳號發放的，而且會整組換碼（見 [`FPay/integration.md`](../../integrations/payments/FPay/integration.md) 第 3 節）。同一個號碼在不同商戶帳號可能代表不同銀行。例如碼 `12` 在一套清單裡是 OCBC NISP，在另一套是 Mandiri。
所以各站的清單不向 FPay 要，改由我方用 FPay 的查詢提款銀行清單介面（`POST /wallet/withdraw_bank_list`）逐個代付設定查。這件事已經有排程在做：`cash-flow:audit-fpay-bank-list` 每天 08:00 查一次，清單有變才在 `drift_baselines` 表存一份新快照。

**快照查到的結果**（2026-10-13 從各站從庫讀 `drift_baselines`，最近一份快照都是 09-18）：

- 有 IDR FPay 代付設定的只有三站：TPower（代付設定 264、272）、Yes2Win（118、120）、Alfawin77（57、58）。VWorld 沒有 FPay 快照；VPlus33、Me77 只有 MYR。所以這 6 組只跟這三站有關。
- Yes2Win、Alfawin77 用的是同一套碼（118 家），和碼表一致。
- TPower 是另一套碼（123 家），碼表的 12 個碼在這套裡只有 `12` 存在，而且是 Mandiri。TPower 的碼照舊用 `remap-tpower-fpay-idr-bankcodes` 處理，不靠 `cash-flow:sync`。

| 組 | 舊名稱與碼（Yes2Win、Alfawin77） | 現在那家的名稱與碼（Yes2Win、Alfawin77） | 舊名稱與碼（TPower） | 現在那家的名稱與碼（TPower） |
|---|---|---|---|---|
| 1 | CNB `105` | Bank SHINHAN `68` | Central Nasional Bank `581` | Bank Shinhan `552` |
| 2 | Bank Nusantara Parahyangan `64` | Bank Danamon `4` | Bank Nusantara Parahyangan `548` | Bank Danamon `27` |
| 3 | Bank COMMONWEALTH `113` | Bank OCBC NISP `12` | PT Bank Commonwealth `311` | OCBC NISP `307` |
| 4 | Bank Aceh `39` | Bank Aceh Syariah `40` | Bank Aceh `524` | Bank Aceh Syariah `525` |
| 5 | Bank NTB `54` | Bank NTB Syariah `55` | Bank NTB `538` | Bank NTB Syariah `539` |
| 6 | Bank Riau `43` | Bank Riau Syariah `44` | Bank Riau Kepri `528` | Bank Riau Syariah `529` |

未驗證：快照是 09-18 的。排程只在清單有變、而且差異已處理時才換快照，所以 09-18 之後若 FPay 改過清單、差異還沒處理，快照不會反映。改碼表之前可以用 `cash-flow:third-party-query <代付設定 id> --raw` 現查一次確認。

## PayPro（6 組，正式站 TPower、Yes2Win 在用）

| 組 | 舊名稱與碼 | 現在那家的名稱與碼 |
|---|---|---|
| 1 | CNB `0105` | Bank Shinhan `0068` |
| 2 | Bank Nusantara Parahyangan `0064` | Bank Danamon `0004` |
| 3 | Bank Commonwealth `0113` | OCBC Bank `0012` |
| 4 | Bank Aceh `0039` | Bank Aceh Syariah `0040` |
| 5 | Bank NTB `0054` | Bank NTB Syariah `0055` |
| 6 | Bank Riau `0043` | Bank Riau Syariah `0044` |

## DgPay（3 組，正式站 TPower、Yes2Win 在用）

| 組 | 舊名稱與碼 | 現在那家的名稱與碼 |
|---|---|---|
| 1 | CNB `559` | Bank Shinhan `152` |
| 2 | Bank Nusantara Parahyangan `145` | Bank Danamon `011` |
| 3 | Bank Commonwealth `950` | Bank OCBC NISP `028` |

## VaderPay（6 組，正式站 Alfawin77、Yes2Win 在用）

| 組 | 舊名稱與碼 | 現在那家的名稱與碼 |
|---|---|---|
| 1 | CNB `CNBB` | Bank Shinhan `BSHN` |
| 2 | Bank Nusantara Parahyangan `NTPY` | Bank Danamon `DNMB` |
| 3 | Bank Commonwealth `CMWB` | OCBC Bank `OCBC` |
| 4 | Bank Aceh `ACEH` | Bank Aceh Syariah `ACES` |
| 5 | Bank NTB `NTBB` | Bank NTB Syariah `NTBS` |
| 6 | Bank Riau `RIAU` | Bank Riau Syariah `RIAS` |

## SurePay（1 組，正式站 TPower、Yes2Win 有 IDR 對照列）

| 組 | 舊名稱與碼 | 現在那家的名稱與碼 |
|---|---|---|
| 1 | Bank Ekonomi `Bank Ekonomi` | Bank HSBC `HSBC` |

**定案：SurePay 的印尼盾代付碼先全部不收（RB，2026-10-16）。** 碼表的 IDR 代付銀行整段移除，HSBC 和 Bank Ekonomi 也一起移除，不適用「留新名稱的碼」。

依據是 SurePay API 規格書 v2.22（GitLab 專案 365 的上傳附件，頁尾版號寫 2.10）。

- **規格書沒有列出任何印尼的代付收款碼。** 附錄 A.1 的標題寫明 Type 的意思：`D` 是代收（Deposit），`P` 是代付（Payout）。印尼表的 13 個碼全部只標 `D`：銀行 7 個 `MANDIRI`、`BRI`、`BCA`、`BNI`、`CIMB`、`MAYBANK`、`DANAMON`，錢包與其他 6 個 `LINKAJA`、`OVO`、`QRIS`、`DANA`、`SHOPEEPAY`、`ALFAMART`。同一份附錄的馬來西亞、新加坡、泰國表都有標 `P` 的碼，越南標 `DP`，只有印尼一個 `P` 都沒有。
- **SurePay 有開印尼盾代付服務，只是沒給碼。** 規格書附錄 C 的沙箱代付列了印尼的測試碼；我方 `SurePay/api.md` 記錄過 Dev 的 IDR 合約實測，合約裡有一條代付通道；IDR 代付費率 1.7%（工單 #3514、#3515）。我方代付把會員的銀行碼放進 `destbankcode` 送出，印尼這個欄位該填什麼，規格書沒寫。
- **原本怎麼寫、錯在哪。** 10-13 寫成「SurePay 沒有印尼盾代付」，錯在把「沒列代付碼」當成「沒有代付服務」。後來改成「代付收款銀行以 A.1 為準」，錯在 A.1 印尼表標的是代收，拿代收清單當代付清單沒有根據。
- 以後要開 SurePay 印尼盾代付，要先向 SurePay 要代付收款碼清單，再補回碼表。

碼表要怎麼改（未實作）：

- SurePay 碼表 `withdrawal` 裡幣別 IDR 的那個通道整段移除（69 個碼）。MYR 代付不動。
- 跑 `cash-flow:sync` 時，SurePay 的 IDR 代付對照全部會被停用。
- 停用後各站的印尼盾提款銀行都還有別家代付接得住，會員綁定零影響。2026-10-16 從六站從庫唯讀查：
  - 同步只會動碼表 `withdrawal.cash_flow_id` 指到的 cash_flow 98（SurePay - Withdrawal）。啟用中的印尼盾代付通道掛 98 的，只有 TPower（代理組別 674、688）與 Yes2Win（代理組別 7、12）。這四個代理組別都另有 FPay（68）、PayPro（102）、DGPay（120）三條印尼盾代付通道。
  - 這四個代理組別裡，有 SurePay 對照的銀行列共 62～68 列。只有 3 列在 FPay、PayPro、DGPay 都沒有啟用中的對照：Bank Ekonomi Raharja、Bank IFI、Koperasi Intidana。三列在兩站四個代理組別都是零會員綁定。
  - 比對方式是用銀行列的代碼（`banks.code`）找 `cash_flow_banks.bank_code` 相同、`status=1` 的列。有對照列不等於該通道的可選銀行清單（`available_banks`）一定有列這家，這點沒查（未驗證）。
  - VWorld 代理組別 118 有一條啟用中的舊 SurePay 代付（cash_flow 32），同步不會動它；它沒有啟用中的對照列，也從來沒有提款單。Alfawin77、Me77、VPlus33 沒有 SurePay 印尼盾代付。

正式資料：

- Yes2Win（cash_flow 98）近期 IDR 代付都失敗，沒有一筆成功。10-14 有 3 筆送 `BRI` 回餘額不足（`-101`）；10-14～10-15 有 5 筆送 `BRI` 回 Unauthorised access（`-201`）。06-07 送 `Bank Riau`、03-04 送 `Bank Bumi Artha` 也都回餘額不足。這兩種錯誤看起來是權限和餘額問題，證明不了哪些碼能用（未驗證）。
- TPower 的 SurePay 代付近一年 18 筆，0 筆成功（沒有分幣別）。
- 啟用中的 HSBC／Ekonomi 對照列：TPower cfb 977、985，Yes2Win cfb 552、560。

## TtPay（3 組，目前沒有站在用這些碼）

| 組 | 舊名稱與碼 | 現在那家的名稱與碼 |
|---|---|---|
| 1 | CNB `559` | Bank Shinhan `152` |
| 2 | Bank Nusantara Parahyangan `145` | Bank Danamon `011` |
| 3 | Bank Commonwealth `950` | Bank OCBC NISP `028` |

repo 裡沒有 TtPay 的文件。正式站 TPower、Yes2Win 的 TtPay 代付只有 MYR 對照列。

## QuickPay（1 組，目前沒有站在用這些碼）

| 組 | 舊名稱與碼 | 現在那家的名稱與碼 |
|---|---|---|
| 1 | DongABank `2031` | Vikki Digital Bank Limited `2064` |

文件只有名稱碼 `DONGA`，`2031` 和 `2064` 都沒有列出。六站都沒有這兩個碼的對照列。

## 只列舊名稱的銀行（2026-10-19 盤點，唯讀）

這節列出金流商代付碼表裡「只列舊名稱、沒列新名稱」的條目。照「定案」節的送碼規則，這些碼不刪，條目改掛新名稱。

**結論：共 32 筆，分屬 10 家金流商。六站近一年（2025-10-19 之後）沒有任何一筆用這些碼成功代付，所以每一個碼現在能不能送，都是未驗證。** 32 筆和盤點計劃「只列舊名的 32 筆」相同。其中 SurePay 5 筆屬於印尼盾代付整段移除，不適用。

| 舊名稱 → 新名稱（標準條目） | 只列舊名稱的金流商與碼 | 有站在用的通道 |
|---|---|---|
| Bank Aceh → `IDR_ACEHSYARIAH` | DgPay `116`、TtPay `116`、SurePay `Bank Aceh` | DgPay：TPower、Yes2Win |
| Bank Riau → `IDR_RIAUSYARIAH` | DgPay `119`、TtPay `119`、SurePay `Bank Riau` | DgPay：TPower、Yes2Win |
| Bank NTB → `IDR_NUSATENGGARABARATSYARIAH` | DgPay `128`、TtPay `128`、SurePay `Bank NTB` | DgPay：TPower、Yes2Win |
| Bank Windu Kentjana International → `IDR_CCBINDONESIA` | DgPay `036`、FPay `15`、PayPro `0015`、TtPay `036`、VaderPay `BWKI`、SurePay `Bank Windu` | DgPay、PayPro：TPower、Yes2Win（PayPro 另有 Alfawin77）；FPay：TPower、Yes2Win、Alfawin77；VaderPay：Alfawin77 |
| Bank Antardaerah → `IDR_CCBINDONESIA` | DgPay `088`、FPay `28`、PayPro `0028`、TtPay `088`、VaderPay `ATDR`、SurePay `Bank Anda` | 同上 |
| Bank Dinar → `IDR_OKEINDONESIA` | DgPay `526`、FPay `96`、PayPro `0096`、TtPay `526`、VaderPay `DINA` | 同上 |
| Bank Maybank Syariah Indonesia → `IDR_ALADINSYARIAH` | TopPay `947` | Alfawin77 |
| Bank Permata UUS → `IDR_PERMATASYARIAH` | TopPay `0130` | Alfawin77 |
| DongA Bank → `VND_VIKKIDIGITAL` | FPay `8`、PayEssence `104`、VtPay `DAB`、YeePay `970406` | FPay、VtPay、YeePay：TPower、Yes2Win；PayEssence 無站在用 |

TtPay 的印尼盾代付沒有站在用。Me77、VPlus33 沒有印尼盾與越南盾代付通道。TPower 的 FPay 是另一套碼，上表的 FPay 碼不適用 TPower，照舊用 `remap-tpower-fpay-idr-bankcodes` 處理。

**CCB Indonesia 的兩個舊名稱：留 Windu Kentjana 的碼（RB，2026-10-19）。** Windu Kentjana 和 Antardaerah 在 DgPay、FPay、PayPro、TtPay、VaderPay 各列一筆，照「定案」節「留最新那個名稱的碼」：

| 金流商 | 留（Windu Kentjana） | 移除（Antardaerah） |
|---|---|---|
| DgPay | `036` | `088` |
| FPay | `15` | `28` |
| PayPro | `0015` | `0028` |
| TtPay | `036` | `088` |
| VaderPay | `BWKI` | `ATDR` |

SurePay 的 `Bank Windu`、`Bank Anda` 屬於印尼盾代付整段移除，不適用。未驗證：上述更名先後是依公開沿革，repo 裡沒有出處。Yes2Win 的 CCB 那列（`Y2WID095`）在 DGPay 掛的本來就是 `036`、FPay 掛 `15`、PayPro 掛 `0015`，與這個決定相同。

**TopPay `947`：改掛 Aladin，碼不變（RB，2026-10-19）。** `947` 在 TopPay 碼表的名稱是 Maybank Syariah Indonesia，這家已改名為 Bank Aladin Syariah。照「定案」節的送碼規則，條目名稱改成 Aladin、碼留 `947`。只影響 Alfawin77（TopPay 只有這站在用）。同一份碼表另有 `0160` Maybank Syariah UUS，是另一個條目，這次不動。

`947` 近一年沒有實績，可能的原因是會員在前端找的是新名稱 Aladin，選不到掛著舊名稱的那一列（推測，未驗證）。

**唯一一筆實際送出的紀錄：** Yes2Win 2026-07-13 用 DgPay 送 Bank Riau `119`，金流商回「Bank Account is not valid, not active, or blocked」，同步拒絕。這是帳戶層級的錯誤，證明不了 `119` 能不能送到 Riau Syariah。

**判定方式：**
- 舊名稱的範圍，取標準銀行檔裡標了 `retired_into` 的條目，共 31 條（IDR 30、VND 1）。新名稱的範圍，取它們指到的條目。
- 比對範圍是逐家金流商碼表的代付通道，共 1447 筆條目。每筆用 `StandardBankNameHelper` 的設定檔比對（信心度至少 80）對到標準條目。
- 判定規則：同一個通道裡有條目對到舊名稱，卻沒有任何條目對到它的新名稱，就算「只列舊名稱」。
- 設定檔比對不經各站資料庫。實際跑 sync 時，比對會先經過各站代理組別 0 的銀行列，結果可能不同；目前沒有逐站乾跑 sync（未驗證）。
- 成功代付的判定，以提款單狀態 4、24 為準。TPower 的代付紀錄表 2026-02 之後的區段查詢逾時，所以 TPower 只看提款單。

**沒涵蓋的範圍：**
- 不在碼表裡的舊通道沒盤點，例如舊 DGPay（cash_flow 54）、AllPay、VWorld 的舊 SurePay（cash_flow 32）。
- 改了名卻沒標 `retired_into` 的銀行不在範圍內，例如 Fama → Superbank。

## 接下來

### 施工順序：先讓新名稱的列接得住，再搬會員綁定，最後才移除舊名稱的碼

這節定的是第二階段每一站的施工先後。原則只有一條：**舊名稱的碼要等到全站都沒有人在用，才從碼表移除。**

先講三個決定順序的事實，都是讀程式碼確認的：

- **代付查碼看的是銀行碼，不看是哪一列。** 代付要送給金流商的三方碼，是用「金流商、幣別、平台銀行碼（`banks.code`）」去查對照列（`cash_flow_banks`）或通道的可選銀行清單（代付設定 `available_banks`）。對照列不分代理組別，所以舊名稱的碼一停用，全站所有還綁在舊名列上的會員都會受影響（`RenameBankCommand` 類別說明、`CrossCodeConvergenceService::gatherEvidence()`）。
- **`cash-flow:sync` 會先把該金流商的對照列全部停用，再只把碼表裡還有的重新啟用。** 舊名稱的碼一移除，舊名列的對照列就停在停用（`SyncCashFlowsCommand::createCashFlowBankRelation()` 與 `bank:fix-cfb-provider-code` 類別說明）。sync 不會改可選銀行清單。
- **`bank:rename-bank` 在兩種情況會整組阻斷、一筆不寫。** 第一種：同一個金流商在舊名列、新名列都有啟用中的對照列，而且三方碼不同（類別說明的第 4 種阻斷）。第二種：同一份可選銀行清單裡新舊兩個碼都在，而且三方碼不同（第 5 種阻斷）。第二階段的組，兩種條件幾乎都成立。

所以「先移除舊碼」會斷付，「先搬綁定」又會被阻斷。下面的順序把兩件事拆開：

1. **寫入前準備。** 照第一階段的做法產出回退清單，並備份 `banks`、`cash_flow_banks`、代付設定的 `available_banks`、`member_bank_accounts` 四張表。每站寫入都要逐站確認。從第 2 步做完到第 5 步做完之前，不准跑 `agent:add-agent-cash-flow` 與 `cash-flow:auto-sync`，理由寫在第 5 步。
2. **補齊新名稱列的對照，只加不減。** 這步讓新名稱的列在每一條通道都接得住，舊名稱的列照舊能用，所以沒有人會斷。
   - 碼表裡同時列新舊名稱的金流商不用改，新名稱的碼本來就在。例如 PayPro 的碼表已經有 Aceh、Riau、NTB 三家 Syariah 的碼（`0040`、`0044`、`0055`），只是 Yes2Win 的資料庫裡還沒有這三列對照，跑一次 sync 就會補上（未驗證：還沒乾跑 sync 確認比對得到）。
   - 碼表**只列舊名稱**的金流商，照「定案」節的送碼規則，在碼表新增一筆新名稱的條目，碼跟舊名稱那筆相同。這一步先不刪舊名稱那筆，第 7 步才收。Yes2Win 碰到的是 DGPay：Bank Aceh `116`、Bank Riau `119`、Bank NTB `128`。未驗證：DGPay 文件沒有寫 `116` 能不能送到 Aceh Syariah 的帳戶，要先查實績。
   - 這步只補 8 筆：DgPay、TtPay 的 Aceh Syariah、Riau Syariah、NTB Syariah 各 3 筆，加上 TopPay 的 Aladin Syariah `947`、Permata Syariah `0130`。
   - **CCB Indonesia、Bank Oke Indonesia、Vikki Digital Bank 三家不在這步補（RB，2026-10-19）。** 這三家的新名稱在 Yes2Win、TPower、VWorld、VPlus33 的基底層都沒有對應的列，碼表一加新條目，sync 就會新建一列，多出新的跨碼組（見「預演：其他五站」節最後）。這三家整組不做第 4、5 步，留到第 7 步用改名處理。
   - 碼表改完、部署之後跑 `cash-flow:sync`。這時舊名稱的碼還在碼表裡，舊名列的對照列會照舊啟用。跑法有三條限制：
     - 用 `--ids` 限定這次改到的金流商，逐站跑。
     - TPower 排除 FPay（cash_flow 68）的印尼盾，那站的 FPay 是另一套碼。
     - 跑完每站重跑 `bank:converge-cross-code` 預演，確認新多出的組都在預期內。
   - ⚠️ sync 找平台銀行列只看基底層、用名稱比對，找不到就新建一列（`SyncCashFlowsCommand::findPlatformBankByName()`、`createPlatformBank()`）。新名稱在某站找不到對應列，就會多出一列，變成新的跨碼組。實查結果見「預演：其他五站」節最後的「新名稱在各站會不會新建列」。
   - 可選銀行清單**不要**先補新碼。第 4 步的 `rename-bank` 遇到清單裡只有舊碼時，會把那一筆的銀行碼改成新名列的碼、三方碼不變（`RenameBankCommand::alignSingleAcf()`）。先補新碼反而會讓新舊碼並存，原本不擋的組變成第 5 種阻斷，要多列一筆放行。
   - 驗收：每一組在走對照列的通道上，「舊名稱的碼查得到的，新名稱的碼也查得到」。各家代付走對照列還是走可選銀行清單，由環境變數 `PAYOUT_BANK_LOOKUP_PROVIDERS` 逐家決定，一家只走一邊（`WithdrawalPayoutTrait::convertPayoutBankCode()`）。各站的值見下面「各站代付查碼走哪一邊」；對照列這邊仍然每一家都補齊，因為名單之後會逐家打開，sync 也是照碼表管對照列。

   **各站代付查碼走哪一邊（2026-10-19，讀部署專案 gff-cicd 的 `main` 分支）：**
   - 正式站全部是空值，也就是除了下一點的兩家，每一家代付都查可選銀行清單、不查對照列。名單由 `helm-charts/apis/.env.tpl` 從各站 values 檔取 `app.switch.payout_bank_lookup_providers`，預設空字串（`values.yaml`）。Alfawin77、TPower、Yes2Win、VPlus33、VWB2C、VWorldMY、Wintoto、Vietwin88 的 values 檔都沒有設這個值。
   - 例外：VaderPay、TakaPay 在自己的 `Withdrawal` 裡直接查對照列，不經過這個名單（`Providers/VaderPay/Withdrawal.php:53`、`Providers/TakaPay/Withdrawal.php:53`），所以這兩家在正式站一直都查對照列。
   - Dev 填了 `FPay`（`values_dev.yaml`，部署專案 MR !19，2026-10-07 合併）。
   - 對這次合併的意義：正式站今天會不會斷付，看的是可選銀行清單；`rename-bank` 會把清單裡的碼一起改掉。對照列要等該家金流商進了名單才會生效，但仍要照施工順序補齊，名單打開那天才不會斷。
   - 未驗證：各站實際跑著的程式有沒有用到最新的樣板，要看各站最近一次部署的時間；這個值是 2026-10-06 才加進樣板的，在那之前任何站都填不了。
3. **解開 `rename-bank` 的阻斷（已實作：`--accept-provider-code`）。** 第 2 步做完之後，新舊兩個碼仍然並存，而且三方碼不同，`rename-bank` 還是會擋。現有的三支修正工具互相卡住：
   - `bank:fix-acf-bank-entry` 不准把舊項目的三方碼改成新項目已經在用的值。
   - `bank:remove-acf-bank-entry` 只准刪「銀行列已經停用」的項目。
   - 舊名列還有會員綁定，停不了。

   這道阻斷要人確認的，正是「舊名列上的會員改送新名稱的三方碼可不可以」。「定案」那節已經回答可以。所以 `rename-bank` 加了明示選項 `--accept-provider-code=<cash_flow_id>:<舊名列三方碼>:<新名列三方碼>`，可重複帶，由執行的人逐筆列出放行：
   - 三個值逐字相符才放行；有一筆阻斷沒列到，整組照舊零寫入。
   - 只放行三方碼不同這一種；限額、`info` 不同，或新名列的對照列已停用，照舊阻斷。
   - 一筆同時涵蓋該通道的對照列與可選銀行清單兩層。停用中的代付設定也要列，因為可選銀行清單的改寫不看代付設定是否啟用。
   - 被放行的對照列改為留置，舊名的項目先留著，到第 7 步再清。
   - 每筆要列的值，照本文件各金流商表與「只列舊名稱的銀行」那節核對，不從站內其他通道推。

   Yes2Win 代理組別 7 的 Aceh 那組，唯讀預演帶 `--accept-provider-code=68:39:40 --accept-provider-code=104:ACEH:ACES`，結果 exit 0、沒有寫入。
4. **代理組別層：搬會員綁定。** 逐組跑 `bank:rename-bank --agent-group=<代理組別> --from=<舊名列> --to=<新名列>`，先預演、核對，再執行。這步會把會員綁定、提款單、可選銀行清單裡的碼改到新名列，再停用並標刪舊名列。代理組別層的銀行列沒有自己的對照列，查碼都靠第 2 步補好的新名稱的碼。
5. **基底層：收掉舊名列（群組 0）。** 對基底層（群組 0）跑同樣的 `rename-bank`。這步要緊接第 4 步做：基底層的舊名列還啟用時，只要有人跑 `agent:add-agent-cash-flow`（`cash-flow:auto-sync` 也會串接它），`AgentBankSyncService::syncToAgentGroup()` 就會照基底層把代理組別層的舊名列長回來，會員又選得到舊名稱。這兩支都沒有排程，所以靠人工紀律擋。每天 07:40 的 `bank:sync-projection` 只讀代理組別層的列、投影到 `agent_banks`，不會長回舊名列（`BankProjectionService::agentBankCandidates()`）。只列舊名稱的通道（例如 DGPay）掛在基底層舊名列上的對照列，會在這步改掛到新名列。
6. **確認全站舊名稱的碼沒有人在用。** 逐一查：啟用中的會員綁定、狀態 21／22／29 的提款單（29 可以重發，重發時會再查一次碼）、可選銀行清單，三項都要是 0。查的範圍是全站所有代理組別，不只第 4 步做過的那幾組，因為對照列不分代理組別。
7. **移除舊名稱的碼。** 照「定案」與「原本要問金流商的三件事」兩節改碼表，內容如下。部署後跑 `cash-flow:sync`，舊名列的對照列會被停用，這時已經沒有人在用。最後用 `cash-flow:align-available-banks --prune-duplicate-entries` 或 `bank:remove-acf-bank-entry` 清掉可選銀行清單裡的舊名項目。
   - 新舊名稱都有列的，舊名稱的碼從碼表移除。
   - 只列舊名稱的，刪掉舊名稱那筆條目，第 2 步加的新名稱條目留著，碼不變。這類的碼不能刪，刪了這家金流商就送不了這間銀行。
   - TopPay 的 Bank of China 改回 `069`。
   - SurePay 的 IDR 代付通道整段移除。
   - Nagari、Kalbar、Kaltim 三個 UUS 條目拿掉 `sync_visible => false`，讓會員選得到。
   - CCB Indonesia、Bank Oke Indonesia、Vikki Digital Bank 三家用改名收，做法見下面「第 7 步：三家用改名收」。
   - Alfawin77 停用中的 AllPay（cash_flow 50）清單：第 4、5 步合併時被放行留置的舊名項目（`COMM`、`SUMB`、`CNBI`、`BNPH`），用 `bank:remove-acf-bank-entry` 清掉。這時舊名列已經停用，這支命令准刪。決定見「預演：其他五站」節最後「要先決定的四件事」第 2 件。
   - Alfawin77 TopPay（cash_flow 94）的 8 家 VA 碼改回一般碼。做法已定案用改法 A：碼表註解掉 8 筆 VA 條目，標準銀行檔拿掉 7 個「VA 某銀行」別名，sync 後清單用 `bank:fix-acf-bank-entry` 改回一般碼。成因與改法選項見下面「第 7 步：TopPay 的 VA 碼為什麼蓋掉一般碼」。決定見「要先決定的四件事」第 3 件。

#### 第 7 步：TopPay 的 VA 碼為什麼蓋掉一般碼（2026-10-19，讀代碼加唯讀查詢）

VA 是 Virtual Account（虛擬帳號），銀行發給商家或錢包的專屬帳號，不是會員自己的存款戶。「定案」節已定：新名稱同時有一般碼和 VA 碼時留一般碼。但 Alfawin77 的 TopPay 現在 8 家都送 VA 碼，原因如下。

**成因：標準銀行檔把「VA 某銀行」當成那家銀行的別名，sync 又是一家銀行只寫一列對照列，後寫的蓋掉先寫的。**

1. **碼表同一家銀行列了兩筆。** TopPay 碼表（`providers/TopPay.php`）的代付銀行，一般碼在前（例如第 161 行 Bank Danamon `011`），VA 碼在最後一段（第 276～284 行「Virtual Account (代付)」，例如 VA Danamon `10017`）。
2. **兩筆都比對到同一家標準銀行。** sync 對碼表每一筆先用 `StandardBankNameHelper::matchBankName()` 找標準名稱（`SyncCashFlowsCommand.php:1832`）。標準銀行檔 `Data/banks/IDR.php` 把 `VA Mandiri`、`VA BNI`、`VA BCA`、`VA Danamon`、`VA Permata`、`VA CIMB`、`VA BSI` 直接列成一般銀行的別名（例如第 107 行），是 TopPay 印尼接入時一起加的（commit `acb317fe7`）。VA BRI 沒有別名，但關鍵字 `bri` 也會對到 BRI。在 Alfawin77 用容器實際呼叫比對函式（只讀）的結果：
   - 7 家是「別名比對」，信心度 90，例如「(IDR) - VA Danamon」對到「(IDR) - Bank Danamon」。
   - VA BRI 是「關鍵字比對」，信心度 85，對到「(IDR) - Bank Rakyat Indonesia」。
   - 一般碼那筆是名稱完全相同，信心度 100。
3. **一家銀行只有一列對照列，後寫的贏。** 對照列用「金流商、幣別、平台銀行列」找既有列（`createCashFlowBankRelation()`，第 2435～2439 行），找到就把三方碼改成這一筆的碼（第 2458～2461 行）。碼表照順序跑，VA 那筆排在後面，所以每次 sync 最後留下的都是 VA 碼。這 8 列對照列都沒有人工覆寫標記，每次都會被改。
4. **可選銀行清單也是 VA 碼。** 代付設定 65、68 的清單裡，這 8 家全是 VA 碼（唯讀查詢）。清單是 `agent:add-agent-cash-flow` 依對照列重算出來的（`FixAcfBankEntryCommand.php:69` 的類別說明），所以對照列錯，清單跟著錯。sync 不改清單，所以改了碼表、跑完 sync，清單還要另外改。

| 銀行 | 一般碼（要留） | VA 碼（現在送的） |
|---|---|---|
| Bank Rakyat Indonesia（BRI） | `002` | `10011` |
| Bank Mandiri | `008` | `10012` |
| CIMB Niaga | `022` | `10013` |
| Bank Permata | `013` | `10014` |
| Bank Negara Indonesia（BNI） | `009` | `10015` |
| Bank Central Asia（BCA） | `014` | `10016` |
| Bank Danamon | `011` | `10017` |
| Bank Syariah Indonesia（BSI） | `4510` | `10018` |

清單裡還有一筆 `10010`，是 Bank Mutiara 本來的碼（碼表第 221 行），不是 VA，不在這次範圍。

**已定案：用改法 A（RB，2026-10-19）。** 碼表註解掉 8 筆 VA 條目並寫明理由，標準銀行檔同時拿掉 7 個「VA 某銀行」別名。理由：只改碼表的話，別名還在，以後別家碼表出現「VA 某銀行」又會蓋掉一般碼；驗收時查過這 7 個別名在 docs 以外只出現在這兩個檔，各站也沒有名稱是 VA 開頭的銀行列，拿掉不會讓任何一站的銀行列對不到標準銀行。

**改法選項（定案前的比較）：**

| 選項 | 做法 | 影響 | 推薦度 |
|---|---|---|---|
| A. 碼表註解掉 8 筆 VA 條目，同時拿掉標準銀行檔的 7 個「VA 某銀行」別名 | 碼表那段改成註解並寫明理由，比照 FPay 碼表把測試環境那段註解掉的做法；別名一起拿掉，免得以後別家碼表出現 VA 名稱又被對到一般銀行 | 下次 sync 這 8 列對照列會換回一般碼。我方從此不送 VA 碼；會員填的是自己的存款帳號，本來就該送一般碼 | 推薦 |
| B. 只註解掉碼表的 8 筆 VA 條目，別名不動 | 同 A，少改一個檔 | 效果同 A。但別名還在，以後別家碼表出現「VA 某銀行」，又會蓋掉一般碼 | 可考慮 |
| C. 只拿掉別名，碼表不動 | 想讓 VA 名稱比對不到 | **擋不住**：關鍵字比對（例如 `danamon` 包含在「vadanamon」裡）照樣給 85 分，超過 80 的門檻，VA 那筆還是會對到一般銀行 | 不建議 |
| D. 碼表裡把 VA 那段移到一般碼前面 | 讓一般碼變成後寫的那筆 | 靠順序才對，之後有人調整碼表順序就又錯；VA 碼仍會被比對進來 | 不建議 |
| E. 8 列對照列都標人工覆寫，改成一般碼 | 用 `bank:fix-cfb-provider-code` 逐列改並標記 | 只修好 Alfawin77 這 8 列，碼表的問題還在；換站或新建列就再發生 | 不建議 |

**不管選哪個，清單都要另外改。** 選 A 或 B 部署後，在 Alfawin77 跑 `cash-flow:sync --ids=94`，對照列會換回一般碼（讀代碼推論，未實跑）。清單要用 `bank:fix-acf-bank-entry` 把代付設定 65、68 的 8 個項目改成上表的一般碼。

**未驗證：**
- 一般存款帳號送 VA 碼會不會入帳，文件沒寫。BSI 用 `10018` 成功過一筆，那筆收款帳號是不是 VA 沒查。
- 碼表裡一般碼那 8 個碼現在能不能送，沒有查實績。TopPay 官方代付碼表有列這些碼，同一節也寫明 `10011`～`10019` 是 VA 碼、不是會員自己的存款帳戶（[`TopPay/api.md`](../../integrations/payments/TopPay/api.md) 第 9.2 節）。

#### 第 7 步：三家用改名收（CCB Indonesia、Bank Oke Indonesia、Vikki Digital Bank）

這三家在多數站只有舊名稱的列，沒有新名稱的列。做法是把舊名列直接改名，碼不變，所以會員綁定、對照列、可選銀行清單都不用搬，也不會斷付。

- **改名用 `bank:set-name`。** 這支命令只改 `banks.name` 一欄，先預演、加 `--execute` 才寫入，審計寫在 `bank_dedup_logs`（`action=set_bank_name`），沒有還原命令，要還原就照審計的原名稱再改一次（`SetBankNameCommand` 類別說明）。
- **基底層和每個代理組別的同碼列都要一起改。** 只改代理組別層，下一次 `agent:add-agent-cash-flow` 會照基底層的名稱蓋回去。
- **目標名稱用標準名稱**：`(IDR) - Bank CCB Indonesia`、`(IDR) - Bank Oke Indonesia`、`(VND) - Vikki Digital Bank`（`Data/banks/IDR.php`、`VND.php` 的 `standard_name`）。碼表裡舊名稱那筆條目，同一次改成同一個名稱、碼不變。CCB 的兩個舊名稱照「只列舊名稱的銀行」節，只留 Windu Kentjana 那筆改名，Antardaerah 那筆刪掉。
- **先後順序：** 碼表改名的版本部署後，先跑 `bank:set-name`，再跑 `cash-flow:sync`。反過來先跑 sync，碼表的新名稱比對不到還叫舊名的列，就會新建一列。部署到改名之間不准跑 sync 與 `agent:add-agent-cash-flow`；這兩支都沒有排程（`routes/console.php`）。
- **同名會擋：** 同一個代理組別、同幣別裡已經有一列叫目標名稱時，`set-name` 整批不寫。下表是各站現況（2026-10-19 唯讀查詢，只列啟用、未標刪的列）：

| 站 | CCB | Oke | Vikki | 能不能直接改名 |
|---|---|---|---|---|
| Yes2Win | 只有 Windu Kentjana（g0、7、12），0 綁定 | 只有 Dinar（g0、7、12），0 綁定 | 只有 DongA（g0、7、12），g7 有 3 筆綁定 | 可以 |
| TPower | 只有 Windu Kentjana（g0、674、688），0 綁定 | 只有 Dinar（g0、674、688），0 綁定 | DongA 在 g0、674、688（674 有 2 筆綁定）；**g674、g688 另有 Vikki 列（`VND0074`），共 5 筆綁定，基底層沒有** | CCB、Oke 可以；Vikki 會撞同名 |
| VWorld | 只有 Windu Kentjana（g0） | 只有 Dinar（g0） | 只有 DongA（g0） | 可以 |
| VPlus33 | 只有 Windu Kentjana（g0） | 只有 Dinar（g0） | DongA 兩列：`VND0042`、`VND0005`，都在 g0 | CCB、Oke 可以；Vikki 兩列不能改成同名 |
| Alfawin77 | **Windu Kentjana 與 CCB 兩組列並存**（g0、7、14），全部 0 綁定 | **Dinar 與 Oke 兩組列並存**（g0、7、14），全部 0 綁定 | 沒有越南盾 | 會撞同名 |

撞同名的三處（TPower Vikki、VPlus33 DongA、Alfawin77 CCB 與 Oke）要先用 `bank:rename-bank` 把兩列併成一列，再改名。

**合併要排在碼表部署之後，跟改名同一個時段連著跑（RB，2026-10-19）。** 合併完、改名前，保留列還叫舊名稱。以 TPower 為例，原本綁 Vikki 的 5 位會員，會看到銀行名稱變成「DongA Commercial Joint Stock Bank」。改名要等碼表改名的版本部署後才能跑，所以合併也排在部署之後，兩步連著做，讓會員看到舊名稱的時間只有幾分鐘。

**合併方向：保留舊名列，把新名列併進來，再把舊名列改成新名稱（RB，2026-10-19）。** 理由是各金流商的碼大多掛在舊名列的碼上，保留它就不用搬碼。合併後保留列沿用 DongA 列的匯率與限額。TPower 已定案限額改成 Vikki 列的值、匯率維持 DongA 列的值，做法見「要先決定的四件事」第 1 件。

**三處逐一查過，照這個方向合併都不會有通道斷掉（2026-10-19，唯讀 SQL 加讀 `RenameBankCommand`；實跑預演結果見本小節最後的「實跑預演」）。** 斷掉的意思是：合併後有通道用被併列的碼查得到、用保留列的碼查不到。查證時對照列與可選銀行清單兩條路都看：正式站目前除了 VaderPay、TakaPay 都查清單（見施工順序第 2 步「各站代付查碼走哪一邊」），但對照列是名單打開之後要走的路，所以一起確認。

- **先懂一件事：對照列只會在基底層那次合併時被改掛。** 各站的對照列都掛在基底層的列上，`rename-bank` 只改「掛在被併列上」的對照列，所以只跑代理組別層，對照列一列都不會動（`RenameBankCommand` 約 :554-570、:773-778）。

| 站 | 要跑哪幾層 | 會被擋、要放行的 | 會員綁定與在途單 |
|---|---|---|---|
| TPower Vikki → DongA | g674、g688（基底層沒有 Vikki 列，不用跑） | QuickPay 清單（代付設定 331、332）Vikki `2064` 對 DongA `2031`，放行 `88:2064:2031` | Vikki 5 筆、DongA 2 筆；在途單 0 |
| VPlus33 DongA 兩列 | 基底層（只有這層有越南盾列） | 不會擋：兩列的對照列在不同通道（FPay 掛 `VND0005`、PayEssence 掛 `VND0042`），也沒有越南盾代付設定 | 0 |
| Alfawin77 CCB → Windu | **基底層一定要跑**，加上 g7、g14 | 不會擋 | 0 |
| Alfawin77 Oke → Dinar | 基底層、g7、g14 | TopPay 兩層都擋：對照列 Oke `5260` 對 Dinar `526`，清單（代付設定 65、68）也是，放行 `94:5260:526` | 0 |

- **TPower：** Vikki 的碼只出現在 QuickPay 的清單，FPay、YeePay、PayEssence 的對照列與 FPay、YeePay、VtPay 的清單都只有 DongA。合併後 Vikki 的會員反而多出三條通道。QuickPay 的 `2031`、`2064` 哪個能送都未驗證，官方文件只列 `DONGA`（見 QuickPay 那節）。反方向（保留 Vikki）會讓清單改成 Vikki 的碼，但對照列不動，走對照列的通道會查不到碼。
- **VPlus33：** 兩列名稱都是 DongA，沒有新舊之分。建議保留 `VND0042`（id 276），它的名稱 `(VND) - DongA Bank` 和碼表、標準名稱逐字相同。另外，FPay 對照列（`VND0005`）的三方碼是 `654`，碼表現行是 `8`（`654` 那行已註解掉）；`654` 是測試環境的碼，查證見本小節最後的「實跑預演」。
- **Alfawin77 CCB：** 會斷的風險只在 TopPay。TopPay 的對照列 409 掛在 CCB 的基底層列（721），漏跑基底層的話它還是 `TPID144`，TopPay 走對照列就會斷付。基底層合併會把它改掛到 Windu，三方碼 `088` 不變，符合 TopPay 的定案。
- **Alfawin77 Oke：** **最終送 `5260`，`526` 只是過渡。** 「定案」節的 TopPay 第 8 組已經定了：Dinar `526` 和 Oke `5260` 是同一家，留新名稱的碼 `5260`。合併時照「保留舊名列」放行 `94:5260:526`，所以從合併到後面改回之前，送的是 `526`。這段期間 Oke、Dinar 兩列都沒有會員綁定，實際上不會有人送出。改回 `5260` 要分兩層：
  - 對照列：保留列改名成 `(IDR) - Bank Oke Indonesia`，碼表 Dinar `526` 那筆刪掉後跑 sync，sync 會照碼表把這列的三方碼更新成 `5260`（推測，未驗證）。
  - 可選銀行清單：sync 不改清單，要另外把 `TPID082` 那筆的三方碼改成 `5260`。被併的 Oke 項目也用 `5260`，所以要先用 `bank:remove-acf-bank-entry` 刪掉它（這時 Oke 列已經停用），再用 `bank:fix-acf-bank-entry` 改碼（兩支命令的阻斷條件見施工順序第 3 步；這個順序未實跑驗證）。
- 合併後 Alfawin77 g7 會殘留兩列停用、未標刪的同碼列（516 `TPID144`、559 `TPID187`），不擋合併。

**實跑預演：三處 8 組全部通過（2026-10-19，`bank:rename-bank` 不帶 `--execute`）。** 這步把上面用 SQL 推出來的判斷，用命令本身再跑一次。8 組都是 exit 0、沒有寫入，放行值也都對上。

| 站 | 代理組別 | 被併列 → 保留列 | 放行 | 對照列改掛 | 提醒 |
|---|---|---|---|---|---|
| TPower | 674 | 1774 Vikki `VND0074` → 1753 DongA `VND0059` | `88:2064:2031`，對上清單 331 | 無 | 無 |
| TPower | 688 | 1775 Vikki `VND0074` → 1737 DongA `VND0059` | `88:2064:2031`，對上清單 332 | 無 | 無 |
| VPlus33 | 0 | 48 `VND0005` → 276 `VND0042` | 不用 | cfb 5（FPay）`VND0005` → `VND0042` | 無 |
| Alfawin77 | 7 | 753 CCB `TPID187` → 40 Windu `TPID033` | 不用 | 無 | 無 |
| Alfawin77 | 14 | 456 CCB `TPID144` → 153 Windu `TPID033` | 不用 | 無 | 基底層還有啟用中的 `TPID144` |
| Alfawin77 | 0 | 721 CCB `TPID144` → 651 Windu `TPID033` | 不用 | cfb 409（TopPay）`TPID144` → `TPID033` | 無 |
| Alfawin77 | 7 | 548 Oke `TPID176` → 91 Dinar `TPID082` | `94:5260:526`，對上清單 68 | 無 | 基底層還有啟用中的 `TPID176` |
| Alfawin77 | 14 | 488 Oke `TPID176` → 204 Dinar `TPID082` | `94:5260:526`，對上清單 65 | 無 | 基底層還有啟用中的 `TPID176` |
| Alfawin77 | 0 | 725 Oke `TPID176` → 583 Dinar `TPID082` | `94:5260:526`，對上對照列 420 | 無 | 無 |

- **跟 SQL 推算一致的地方。** Alfawin77 的 cfb 409 確實會在基底層那次改掛到 Windu。TPower 基底層沒有 Vikki 列，兩組都沒有改掛任何對照列。
- **「基底層還有啟用中的同碼列」的提醒：** 只跑代理組別層的話，之後有人跑 sync，被併的 CCB、Oke 會照基底層長回來。這就是 Alfawin77 一定要同一個時段把基底層一起跑的原因，跟施工順序第 5 步相同。
- **Oke 基底層的對照列 420 會留置、不改掛。** 它掛在被併列 725 上，三方碼 `5260`；合併後 725 停用，查碼改由保留列 583 自己的對照列回 `526`。420 留到第 7 步再清，這是放行選項的設計（施工順序第 3 步）。
- **VPlus33 的 cfb 5 會帶著三方碼 `654` 改掛到保留列。** 合併後 FPay 用 `VND0042` 查 DongA 會拿到 `654`。這個碼是錯的，但目前沒有影響，查證如下（唯讀 SQL 加讀碼表與 git 紀錄）：
  - **`8` 才是正式碼，`654` 是 FPay 測試環境的碼。** FPay 碼表的越南盾分兩段：1～46 是正式環境、生效中，DongA 是 `8`；650～687 是測試環境、整段註解掉（`providers/FPay.php` 第 80、125 行，commit `af27c8502`「FPay 測試與正式環境銀行代碼不同」）。TPower、Yes2Win 的 FPay 越南盾清單快照（`drift_baselines`）裡，DongA 也都是 `8`。
  - **不一致的原因：碼表換成正式碼後，VPlus33 沒有重跑 FPay 越南盾的 sync。** VPlus33 的 FPay 越南盾對照列共 38 筆，三方碼全落在 650～687，最後更新都是 2025-11-26。TPower 跑過 sync，650 那段已全部停用、1～46 那段啟用中。
  - **目前沒有影響：** VPlus33 沒有任何 FPay 越南盾的代付設定（只有馬幣），兩列 DongA 從 2025-10 起也沒有提款單。
  - **清法：** 合併後對 VPlus33 跑一次 FPay 越南盾 sync，38 筆會一起換成正式碼。sync 會先停用舊列，再在保留列 276 新建一列 `8`，cfb 5 停在停用（讀 `SyncCashFlowsCommand` 推論，未實跑）。
  - 未驗證：FPay 的銀行代號每個商戶帳號各一套，VPlus33 的帳號清單裡 DongA 是不是 `8`，要用它的帳號實查才確定；`8` 是 TPower、Yes2Win 帳號的實測值。
- **預演沒有印會員綁定與提款單的數量**，命令的類別說明要求執行前自己查。TPower 另用唯讀 SQL 查過：Vikki 列上啟用中的綁定 g674 有 4 筆、g688 有 1 筆，DongA 列 g674 有 2 筆；沒有會員同時綁在兩列上，所以不會產生重複綁定。
8. **寫入後複驗。** 每站重跑 `bank:converge-cross-code` 預演和 `bank:verify-payout-bank-parity`，結果和寫入前比對。

### 預演：Yes2Win（2026-10-16，唯讀）

這次預演沒有寫入任何資料。`bank:converge-cross-code` 在容器裡用 Yes2Win 從庫跑；逐組的 `rename-bank` 預演被本機的自動權限檢查擋下，改用唯讀 SQL 照 `RenameBankCommand::buildPlan()` 的阻斷規則逐組重算。Yes2Win 的範圍是代理組別 7、12 與基底層。啟用中的印尼盾代付通道有 FPay、PayPro、DGPay、SurePay 四家，VaderPay 那兩份代付設定是停用的。

**結論：照上面的順序做，Yes2Win 沒有會員會斷付。前提是第 2、3 步先完成。**

- **影響範圍。** `converge-cross-code` 列出 16 組 `multi_live`，也就是第二階段的 8 家銀行，代理組別 7、12 各 8 組。舊名列上啟用中的會員綁定共 14 筆，分在 5 列：Bank Aceh 10 筆（兩個代理組別各 5）、Bank Riau 2 筆（各 1）、Bank Antardaerah 2 筆（代理組別 12）。狀態 21／22／29 的提款單 0 筆。代理組別 0、7、12 以外，沒有任何列在用這 8 個舊名稱的碼。
- **新名列現況接不住的組。** Aceh、NTB、Riau 三組的新名列（Syariah）只有 FPay 一條通道，PayPro、DGPay 的對照列和可選銀行清單都沒有新名稱的碼。SurePay 的印尼盾代付本來就要整段移除，不算在內。不做第 2 步就搬綁定，Aceh 的 10 位、Riau 的 2 位會員會少掉 PayPro 和 DGPay 兩條通道，只剩 FPay。其餘 5 組（Danamon、OCBC NISP、HSBC、CCB、Shinhan）在每條通道新舊碼都齊全。
- **第 2 步補完之後。** 16 組都做到「舊名稱的碼在哪幾條通道查得到，新名稱的碼也都查得到」。這 14 筆綁定搬到新名列後，每一筆原本能走的通道都還在，所以零斷付。這是用目前的資料推算的，第 2 步做完要重查一次。
- **`rename-bank` 現況全部會被阻斷。** 代理組別層 16 組都撞第 5 種阻斷：FPay、PayPro、DGPay 三份啟用中的可選銀行清單裡，新舊兩個碼都在，三方碼不同。基底層 8 組都撞第 4 種阻斷，共 13 列舊名列的對照列，和新名列同一個金流商的對照列兩邊都啟用，三方碼不同。這就是第 3 步要先解的原因。
- **反例：先移除舊碼會斷付。** 第 7 步排到第 4 步之前做的話，舊名列的對照列會在 sync 時全部停用，這 14 位會員在走對照列的通道上會查不到碼。
- **順帶發現（未處理）。** Yes2Win 的 PayPro 對照列，Bank NTB 掛 `0057`，碼表寫 `0054`。下一次 sync 會把它改成 `0054`，哪一個才對還沒查。

輸出存在本次 session 的暫存目錄（`phase2_order/`），不是永久檔案。

### 預演：其他五站（2026-10-19，唯讀）

方法同 Yes2Win：`bank:converge-cross-code` 與 `bank:rename-bank` 都不帶 `--execute`，其餘用唯讀 SQL。這次權限檢查沒有擋，`rename-bank` 預演在 TPower、Alfawin77 各跑 5 次，VWorld 跑 2 次。第 2 步還沒上線，所以下面是現況；第 2 步完成後要重跑。

**結論：Me77、VPlus33、VWorld 不受影響。TPower、Alfawin77 照施工順序做，印尼盾不會斷付。原本有四件事要先決定，2026-10-19 都已定案，見本節最後。**

| 站 | 第二階段的組 | 舊名列上的會員綁定 | 在途單（21／22／29） | 重點 |
|---|---|---|---|---|
| TPower | 9 家 × 代理組別 674、688，另有基底層 | 只有越南盾 DongA 在 674 有 2 筆，印尼盾全部 0 | 0 | 預演 5 組帶放行後都 exit 0 |
| Alfawin77 | TopPay 那一批，代理組別 7、14 與基底層 | 25 筆（7 有 18 筆、14 有 7 筆） | 0 | g7 的 OCBC、BTPN 被停用中的 AllPay 清單擋下 |
| VWorld | 只在基底層，7 組 | 0 | 0 | 沒有啟用中的通道接這幾家；Aceh 放行 `68:39:40` 後 exit 0 |
| VPlus33 | 只在基底層，9 組 | 0 | 0 | 沒有印尼盾、越南盾代付通道 |
| Me77 | 無 | — | — | 沒有第二階段的列 |

**跟 Yes2Win 不同的地方：**

- 「只走可選銀行清單的通道」在第 4 步不會斷：`rename-bank` 會把清單裡的舊碼改成新碼。會斷的只有「走對照列、對照列又沒有新碼」的通道，所以第 2 步只補對照列。
- 停用中的代付設定也會擋。`rename-bank` 檢查清單時不看代付設定是否啟用；Alfawin77 停用中的 AllPay（cash_flow 50）清單有 `COMM`／`OCBC`、`SUMB`／`BTPN`、`CNBI`／`SHB`、`BNPH`／`DANAMON` 這幾組。處理方式見本節最後「要先決定的四件事」第 2 件。
- `converge-cross-code` 不會列出基底層（群組 0）的組。VWorld、VPlus33 的成對列都只在基底層，要靠 SQL 另外盤點。
- 舊 DGPay（cash_flow 54）與 AllPay（cash_flow 50）不在碼表裡，`cash-flow:sync` 管不到。這兩家沒有對照列，只靠清單，所以由第 4 步的改寫接手，碼沿用舊的。
- TPower 的 CCB 在代理組別層與基底層用的碼不同（`IDR0137`／`IDR0042`），而且沒有名稱叫 CCB 的列，新名列只能選 Windu Kentjana。g674 的預演會把一列 FPay 對照（三方碼 `513`）改掛到 Windu 那列，那一列原本已經有 `502`。查碼依 id 排序會先取到 `502`，碼本身沒錯，但會多留一筆碼不同的對照列。

**要先決定的四件事：**

1. **TPower 的 DongA → Vikki（越南盾）：兩列的匯率與限額不同。已定案：限額以 Vikki 列為準，匯率維持 DongA 列（RB，2026-10-19）。**
   - 匯率（`banks.rate`，`Bank` model 的欄位說明是「匯率」）：代理組別 674 兩列相同，都是 0.000158157；代理組別 688 的 DongA 是 0.000157083，Vikki 是 0.000149432。
   - 限額：兩個代理組別都一樣，DongA 是 50,000～200,000,000，Vikki 是 300,000～100,000,000。
   - `rename-bank` 不比對這幾個欄位，合併後保留列沿用自己的值。合併方向照「第 7 步：三家用改名收」保留 DongA 列，合併後保留列（1753、1737）的處理如下：
     - 限額：改成 Vikki 列的值，用 `bank:set-amount-limits --bank=1753 --bank=1737 --min=300000 --max=100000000`。代理組別層的列，之後的 sync 與 `agent:add-agent-cash-flow` 不會蓋掉（該命令類別說明的「sync-safe」節）。
     - 匯率：不改，維持 DongA 列的值。匯率決定提款要扣多少點（`WithdrawalService.php:273` 用提款金額乘上 `banks.rate`）。代理組別 688 越南盾 86 列裡，只有 Vikki 那列是 0.000149432，比 DongA 列的 0.000157083 低約 4.9%；照它改，這列的提款會少扣點。而且 sync 本來就把匯率當成同一代理組別、同一幣別只有一個值：`agent:add-agent-cash-flow` 會拿 id 最小那列的匯率套到同步的每一列（`AgentBankSyncService::syncToAgentGroup()`），g688 那個值就是 0.000157083，改了也會被蓋回去。代理組別 674 兩列的匯率本來就相同。
   - 合併前的綁定：Vikki 列 g674 有 4 筆、g688 有 1 筆；DongA 列 g674 有 2 筆。改成 Vikki 的值之後，DongA 列上這 2 位會員的提款下限會從 50,000 提高到 300,000，上限會從 200,000,000 降到 100,000,000。
2. **Alfawin77 停用中的 AllPay 清單：已定案，合併時帶放行值，不先清（RB，2026-10-19）。** 舊名稱的項目留在清單裡，第 7 步再清。
   - 理由：`bank:remove-acf-bank-entry` 只准刪銀行列已經停用的項目。合併前舊名列還在用，刪不了。這幾份清單停用中，不會送出代付。
   - 現況：AllPay（cash_flow 50）在 Alfawin77 共 12 份代付設定，只有 28（g14）啟用；最後一筆 AllPay 代付紀錄是 2026-03-12。28 的清單裡，這幾家各只有一個碼（Danamon `DANAMON`、OCBC `OCBC`、Sumitomo `SMBC`），沒有新舊並存，所以不擋。
   - 要放行的新舊並存組合有四種，比原本記的多一種 Danamon：
     - `COMM`／`OCBC`（Commonwealth／OCBC NISP）：代付設定 3、7、12、34（g7）與 18、22、26、35（g14）。
     - `SUMB`／`BTPN`（Sumitomo／BTPN）：34、35。3、18 只有 `BTPN`。
     - `CNBI`／`SHB`（CNB／Shinhan）：3、18。34、35 只有 `CNBI`。
     - `BNPH`／`DANAMON`（Nusantara Parahyangan／Danamon）：3、18。
   - 放行值：`50:COMM:OCBC`、`50:SUMB:BTPN`、`50:CNBI:SHB`、`50:BNPH:DANAMON`。只在代理組別層用；AllPay 沒有對照列，基底層不必帶。
   - 代付設定 14、28 的 Sumitomo 只有 `SMBC` 一個碼。合併時清單那筆的銀行碼會改成 BTPN 那列的碼，三方碼 `SMBC` 不變（`RenameBankCommand::alignSingleAcf()`）。預演不印這類改寫，這點是讀程式碼推的。
3. **Alfawin77 TopPay 的 VA 碼：已定案，合併時照現在實際送出的碼放行，8 家改回一般碼整批排到第 7 步（RB，2026-10-19）。**
   - 範圍比原本寫的大，不只 Danamon、BSI。TopPay 對照列裡有 8 家掛的都是 VA 碼：BRI `10011`、Mandiri `10012`、CIMB Niaga `10013`、Permata `10014`、BNI `10015`、BCA `10016`、Danamon `10017`、BSI `10018`。這 8 列對照列是 285、335、382、353、296、293、310、383，都掛在基底層，`manual_override_at` 都是空的，也就是不是人工覆寫的。
   - 清單也一樣：代付設定 65（g14，啟用）、68（g7，停用）的清單裡，Danamon（`TPID009`）是 `10017`，BSI（`TPID069`）是 `10018`。
   - 原因推測：碼表 `app/Console/Commands/CashFlow/Data/providers/TopPay.php` 同時有「Bank Danamon 011」（:161）、「Bank Syariah Indonesia 4510」（:140）與 VA 條目（:277-284）。推測是 sync 用名稱比對時，VA 那筆蓋掉了一般碼（未驗證；master 的碼表相同）。
   - 原本寫「放行不會生效，要先用 `bank:fix-cfb-provider-code` 改回定案碼」，這是錯的。放行比對的是兩列現在的三方碼，`10017` 就是 Danamon 列現在的碼，照實帶 `94:145:10017` 就對得上。而且只改對照列不夠：正式站各站 `PAYOUT_BANK_LOOKUP_PROVIDERS` 都是空值，TopPay 查的是清單，清單也是 VA 碼。
   - 實際影響只有 Danamon 一家。Nusantara Parahyangan 併進 Danamon 時放行 `94:145:10017`，代理組別層對上清單 68、65，基底層對上對照列 349。BSI 的三個舊名列（BNI Syariah、BRI Syariah、Syariah Mandiri，只在 g14）在 TopPay 的對照列和清單都沒有碼，合併不必放行，`10018` 不會擋。
   - 送出的碼不會因為合併而改變：Nusantara Parahyangan 三列（g0、g7、g14）都沒有會員綁定，Danamon 列上的會員現在送的就是 `10017`。
   - 實績：近一年 TopPay 只有 43 筆代付紀錄，最後一筆 05-17；BSI 用 `10018` 成功過一筆。
   - 第 7 步要做的：照改法 A 改碼表與標準銀行檔，讓 VA 條目不再搶走一般銀行的碼，sync 後對照列換回一般碼，清單用 `bank:fix-acf-bank-entry` 改。見施工順序第 7 步。
4. **Alfawin77 新舊兩列限額不同：已定案，以新名稱那列為準，合併後不另外調（RB，2026-10-19）。**
   - 理由：跟第 1 件 TPower Vikki「限額照新名稱」一致。
   - 做法：合併方向是舊名列併進新名列，保留列就是新名稱那列，合併後自然沿用新名稱列的限額，不必跑 `bank:set-amount-limits`。
   - 現況：g14 啟用中的印尼盾列，117 列是 10,000～50,000,000，50 列是 10,000～20,000,000；g7 有 137 列上限是 20,000,000，8 列是 50,000,000。
   - 下表 21 組裡，上限會變的只有 g14 的五組：Centratama、Metro Express 併進 Shinhan，BNI Syariah、BRI Syariah、Syariah Mandiri 併進 BSI，上限從 20,000,000 提高到 50,000,000。五列裡只有 BNI Syariah 那列有 1 筆會員綁定。其餘組在 g7、g14、基底層新舊兩列的限額都相同。
   - 未驗證：TopPay 那一批的其他組（例如 g14 Agris）沒有逐組核對限額。

**Alfawin77 實跑預演：受第 2、3 件影響的 21 組全部通過（2026-10-19，`bank:rename-bank` 不帶 `--execute`）。** 範圍是 OCBC NISP、BTPN、Shinhan、Danamon、BSI 五家在 g7、g14 與基底層的所有舊名列。合併方向照 Alfawin77 TopPay 那批：舊名列併進新名列，保留新名稱那列。21 組都是 exit 0、印出「dry-run：未寫入任何資料」，也都沒有「放行值沒有對上任何阻斷」的提醒。

放行值裡的 cash_flow：50 是 AllPay，54 是舊 DGPay，68 是 FPay，94 是 TopPay，102 是 PayPro，104 是 VaderPay。代理組別層的放行都對上清單，基底層的放行都對上對照列。

| 站 | 代理組別 | 被併列 → 保留列 | 放行值 | 結果 |
|---|---|---|---|---|
| Alfawin77 | 7 | 71 Nusantara Parahyangan `TPID062` → 9 Danamon `TPID009` | `50:BNPH:DANAMON`、`54:145:011`、`68:64:4`、`102:0064:0004`、`94:145:10017`、`104:NTPY:DNMB` | exit 0，對上清單 3、51、57、62、68、73 |
| Alfawin77 | 14 | 184 Nusantara Parahyangan → 132 Danamon | 同 g7 | exit 0，對上清單 18、50、58、60、65、71 |
| Alfawin77 | 0 | 619 Nusantara Parahyangan → 659 Danamon | `68:64:4`、`94:145:10017`、`104:NTPY:DNMB` | exit 0，對上對照列 1946、349、234 |
| Alfawin77 | 7 | 28 Commonwealth `TPID028` → 14 OCBC NISP `TPID014` | `50:COMM:OCBC`、`54:950:028`、`68:113:12`、`102:0113:0012`、`94:950:028`、`104:CMWB:OCBC` | exit 0，對上清單 3、7、12、34、51、57、62、68、73 |
| Alfawin77 | 14 | 151 Commonwealth → 137 OCBC NISP | 同 g7 | exit 0，對上清單 18、22、26、35、50、58、60、65、71 |
| Alfawin77 | 0 | 580 Commonwealth → 688 OCBC NISP | `68:113:12`、`94:950:028`、`104:CMWB:OCBC` | exit 0，對上對照列 1995、308、195 |
| Alfawin77 | 7 | 45 Sumitomo Mitsui `TPID038` → 86 BTPN `TPID077` | `50:SUMB:BTPN`、`94:045:213` | exit 0，對上清單 34、68 |
| Alfawin77 | 14 | 158 Sumitomo Mitsui → 199 BTPN | 同 g7 | exit 0，對上清單 35、65 |
| Alfawin77 | 0 | 720 Sumitomo Mitsui → 575 BTPN | `94:045:213` | exit 0，對上對照列 408 |
| Alfawin77 | 7 | 88 CNB `TPID079` → 117 Shinhan `TPID108` | `50:CNBI:SHB`、`54:559:152`、`68:105:68`、`102:0105:0068`、`94:559:152`、`104:CNBB:BSHN` | exit 0，對上清單 3、51、57、62、68、73 |
| Alfawin77 | 14 | 201 CNB → 230 Shinhan | 同 g7 | exit 0，對上清單 18、50、58、60、65、71 |
| Alfawin77 | 0 | 658 CNB → 636 Shinhan | `68:105:68`、`94:559:152`、`104:CNBB:BSHN` | exit 0，對上對照列 1987、306、273 |
| Alfawin77 | 7 | 515 Centratama `TPID143` → 117 Shinhan | `94:5590:152` | exit 0，對上清單 68 |
| Alfawin77 | 14 | 455 Centratama → 230 Shinhan | `94:5590:152` | exit 0，對上清單 65 |
| Alfawin77 | 0 | 722 Centratama → 636 Shinhan | `94:5590:152` | exit 0，對上對照列 417 |
| Alfawin77 | 7 | 538 Metro Express `TPID166` → 117 Shinhan | `94:1520:152` | exit 0，對上清單 68 |
| Alfawin77 | 14 | 478 Metro Express → 230 Shinhan | `94:1520:152` | exit 0，對上清單 65 |
| Alfawin77 | 0 | 715 Metro Express → 636 Shinhan | `94:1520:152` | exit 0，對上對照列 403 |
| Alfawin77 | 14 | 451 BNI Syariah `TPID139` → 191 BSI `TPID069` | 不用 | exit 0 |
| Alfawin77 | 14 | 452 BRI Syariah `TPID140` → 191 BSI | 不用 | exit 0 |
| Alfawin77 | 14 | 474 Syariah Mandiri `TPID162` → 191 BSI | 不用 | exit 0 |

- **21 組都不改掛任何對照列**，命令印「本次零改指」。基底層被放行的對照列（例如 349、308、306）會留置在停用的舊名列上，查碼改由保留列自己的對照列回新名稱的碼，留到第 7 步再清。
- **代理組別層每一組都提醒「基底層還有啟用中的同碼列」。** 所以 g7、g14 跑完要同一個時段跑基底層，理由同施工順序第 5 步。基底層沒有 BSI 的舊名列，BSI 三組只在 g14。
- **會員綁定（預演不印，另用唯讀 SQL 查）：** 舊名列上啟用中的綁定有 g7 Commonwealth 8 筆、Sumitomo 7 筆、CNB 1 筆，g14 Commonwealth 1 筆、BNI Syariah 1 筆，其餘 16 列都是 0。

**未驗證：**

- 各站 `PAYOUT_BANK_LOOKUP_PROVIDERS` 的值：已查，正式站全部是空值，見施工順序第 2 步「各站代付查碼走哪一邊」。
- 部分組的放行值是照 `buildPlan()` 的規則用 SQL 推的，沒有實跑預演：TPower 的 OCBC、HSBC、NTB、Riau、Shinhan；Alfawin77 上表 21 組以外的組，例如 g14 Agris。Alfawin77 的 g7 CNB、g14 Commonwealth 已經在上表實跑過。
- 上表每一組都是各自對現況預演的。Shinhan 三個舊名列、BSI 三個舊名列各自併進同一個保留列，實際執行時是一組接一組寫入，前一組寫入後下一組的結果會不會變，沒有預演過（推測不會，因為放行比對的是各自舊名列的碼）。
- 預演不印清單裡「只有舊碼」那類改寫（`alignSingleAcf()`），例如代付設定 14、28 的 `SMBC`。這類改寫的結果是讀程式碼推的。
- TopPay `947` 在 Alfawin77 站上不存在：對照列和兩份 TopPay 清單都沒有。Bank Aladin 三列（g0、g7、g14）與 Maybank Syariah（g14）都是 0 綁定。

**新名稱在各站會不會新建列（2026-10-19，唯讀）：** 用各站資料庫實際呼叫 sync 的比對與找列函式（只讀），測第 2 步新增的 22 筆條目。

- Aceh Syariah、Riau Syariah、NTB Syariah、Aladin、Permata Syariah：在 Me77 以外各站，都對到既有的新名列，不會新建列。
- **CCB Indonesia、Bank Oke Indonesia、Vikki Digital Bank：在 Yes2Win、TPower、VWorld、VPlus33 都找不到對應列，sync 會新建。** 所以這三家的 14 筆已撤出第 2 步，第 2 步只補其餘 8 筆，見施工順序第 2、7 步。
  - 新建的列會掛在同一家標準銀行底下，所以 CCB 會從 2 列變 3 列，Oke、Vikki 會各多出一組跨碼組。
  - 代付不會斷：舊名列的對照列照舊，碼也相同。
  - 新列只在基底層。要等有人跑 `agent:add-agent-cash-flow`，才會被複製到代理組別，變成前端的重複選項。
- Alfawin77：只有 Vikki 會新建，因為那站從沒同步過越南盾。CCB、Oke 都對到 TopPay 以前建的列（721、725）。
- Me77：基底層沒有任何印尼盾、越南盾列，所以全部都會新建。跟這次的改動無關，Me77 本來就不跑這兩個幣別的 sync。
- 同一條通道裡，22 筆新條目都沒有跟其他條目掛到同一列，所以不會蓋掉別筆的三方碼。
