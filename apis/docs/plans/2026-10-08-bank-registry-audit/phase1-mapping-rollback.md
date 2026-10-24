# 第一階段部署後寫入：預演結果與回退清單

這份是「部署後要做的事」第 2、3 步寫入正式站之前的紀錄。它列出六站預演時每一列平台銀行會被改成什麼，寫入後如果要復原，照這份清單改回去。

- 預演時間：2026-10-12，六站都跑 `bank:sync-mappings --repair --dry-run` 與 `bank:sync-mappings --dry-run`，沒有寫入。
- 名詞：「平台銀行列」是 `banks` 表的一列；「掛在哪家」指它的 `standard_bank_id` 指向 `standard_banks` 的哪一個 code。
- 表格裡寫 code 不寫 id：`standard_banks` 的 id 在各站可能不同，復原時先用 code 查出該站的 id。

## 怎麼復原

- **重新核對既有對應（`--repair`）改的列**：把 `standard_bank_id` 改回「原本掛」那一欄的 code 對應的 id。
- **補上空白對應（一般同步）補的列**：這些列寫入前都是空的，復原就是改回 NULL。
- 復原前先確認該列寫入後沒有被其他命令再改過（`standard_bank_id` 仍是「改成」或「對到」那一欄的值），不是的話先停下來查，不要直接蓋回去。
- 兩個命令都沒有內建備份，這份清單就是回退依據。

## 重新核對既有對應（`--repair`）：六站共 172 列

主體是 31 家已併入別家的舊銀行，掛在它們名下的平台銀行列改掛到現在那家。另外新加坡幾列的名稱本身就是企業戶或母行分行（例如「HSBC Coorperation」「Malayan Banking Berhad, Singapore Branch」），改掛到 `SGD_HSBC_CORP`、`SGD_MAYBANK_CORP`。

### TPower

| 原本掛 → 改成 | 列數 | bank_id |
|---|---|---|
| `IDR_ACEH` → `IDR_ACEHSYARIAH` | 3 | 1575,1831,1892 |
| `IDR_ANDA` → `IDR_CCBINDONESIA` | 3 | 1564,1867,1928 |
| `IDR_CNB` → `IDR_SHINHAN` | 3 | 1641,1983,2021 |
| `IDR_COMMONWEALTH` → `IDR_OCBCNISP` | 3 | 1649,1989,2027 |
| `IDR_DINAR` → `IDR_OKEINDONESIA` | 3 | 1632,1976,2014 |
| `IDR_EKONOMI` → `IDR_HSBC` | 3 | 1803,1868,1929 |
| `IDR_NTB` → `IDR_NUSATENGGARABARATSYARIAH` | 3 | 1590,1842,1903 |
| `IDR_NUSANTARAPARAHYANGAN` → `IDR_DANAMON` | 3 | 1600,1963,2001 |
| `IDR_RIAU` → `IDR_RIAUSYARIAH` | 3 | 1579,1833,1894 |
| `IDR_WINDUKENTJANAINTERNATIONAL` → `IDR_CCBINDONESIA` | 3 | 1551,1815,1876 |
| `SGD_HSBC` → `SGD_HSBC_CORP` | 2 | 466,755 |
| `VND_DONGA` → `VND_VIKKIDIGITAL` | 3 | 1721,1737,1753 |

### Yes2Win

| 原本掛 → 改成 | 列數 | bank_id |
|---|---|---|
| `IDR_ACEH` → `IDR_ACEHSYARIAH` | 3 | 164,266,815 |
| `IDR_ANDA` → `IDR_CCBINDONESIA` | 3 | 165,267,804 |
| `IDR_CNB` → `IDR_SHINHAN` | 3 | 210,312,881 |
| `IDR_COMMONWEALTH` → `IDR_OCBCNISP` | 3 | 889,1167,1304 |
| `IDR_DINAR` → `IDR_OKEINDONESIA` | 3 | 216,318,872 |
| `IDR_EKONOMI` → `IDR_HSBC` | 3 | 1469,1480,1502 |
| `IDR_NTB` → `IDR_NUSATENGGARABARATSYARIAH` | 3 | 190,292,830 |
| `IDR_NUSANTARAPARAHYANGAN` → `IDR_DANAMON` | 3 | 189,291,840 |
| `IDR_RIAU` → `IDR_RIAUSYARIAH` | 3 | 194,296,819 |
| `IDR_WINDUKENTJANAINTERNATIONAL` → `IDR_CCBINDONESIA` | 3 | 255,357,791 |
| `VND_DONGA` → `VND_VIKKIDIGITAL` | 3 | 1395,1411,1427 |

### Alfawin77

| 原本掛 → 改成 | 列數 | bank_id |
|---|---|---|
| `IDR_ACEH` → `IDR_ACEHSYARIAH` | 3 | 49,162,562 |
| `IDR_AGRISUUS` → `IDR_IBKINDONESIA` | 3 | 441,501,696 |
| `IDR_ANDA` → `IDR_CCBINDONESIA` | 3 | 50,163,564 |
| `IDR_ANDARA` → `IDR_OKEINDONESIA` | 3 | 443,503,698 |
| `IDR_ANGLOMASINTERNATIONAL` → `IDR_AMAR` | 3 | 444,504,699 |
| `IDR_ANZPANIN` → `IDR_ANZINDONESIA` | 1 | 445 |
| `IDR_BNISYARIAH` → `IDR_SYARIAHINDONESIA` | 1 | 451 |
| `IDR_BRISYARIAH` → `IDR_SYARIAHINDONESIA` | 1 | 452 |
| `IDR_BUMIPUTERA` → `IDR_MNCINTERNASIONAL` | 3 | 454,514,706 |
| `IDR_CENTRATAMA` → `IDR_SHINHAN` | 3 | 455,515,722 |
| `IDR_CIMBNIAGAUUS` → `IDR_CIMBNIAGASYARIAH` | 1 | 457 |
| `IDR_CNB` → `IDR_SHINHAN` | 3 | 88,201,658 |
| `IDR_COMMONWEALTH` → `IDR_OCBCNISP` | 3 | 28,151,580 |
| `IDR_DINAR` → `IDR_OKEINDONESIA` | 3 | 91,204,583 |
| `IDR_EKONOMI` → `IDR_HSBC` | 3 | 464,524,709 |
| `IDR_HIMPUNANSAUDARA1906` → `IDR_WOORISAUDARA` | 3 | 466,526,711 |
| `IDR_MAYBANKSYARIAHINDONESIA` → `IDR_ALADINSYARIAH` | 1 | 476 |
| `IDR_METROEXPRESS` → `IDR_SHINHAN` | 3 | 478,538,715 |
| `IDR_MITRANIAGA` → `IDR_IBKINDONESIA` | 3 | 479,539,716 |
| `IDR_MULTICOR` → `IDR_CCBINDONESIA` | 3 | 481,541,718 |
| `IDR_MUTIARA` → `IDR_JTRUSTINDONESIA` | 3 | 480,540,717 |
| `IDR_NTB` → `IDR_NUSATENGGARABARATSYARIAH` | 3 | 72,185,616 |
| `IDR_NUSANTARAPARAHYANGAN` → `IDR_DANAMON` | 3 | 71,184,619 |
| `IDR_PERMATAUUS` → `IDR_PERMATASYARIAH` | 1 | 485 |
| `IDR_QNBKESAWAN` → `IDR_QNBINDONESIA` | 3 | 487,719,751 |
| `IDR_RIAU` → `IDR_RIAUSYARIAH` | 3 | 76,189,630 |
| `IDR_SUMITOMOMITSUIINDONESIA` → `IDR_BTPN` | 3 | 45,158,720 |
| `IDR_SYARIAHMANDIRI` → `IDR_SYARIAHINDONESIA` | 1 | 474 |
| `IDR_TABUNGANNEGARAUUS` → `IDR_VICTORIASYARIAH` | 1 | 453 |
| `IDR_WINDUKENTJANAINTERNATIONAL` → `IDR_CCBINDONESIA` | 3 | 40,153,651 |

### Me77

沒有要改的列。

### VWorld

| 原本掛 → 改成 | 列數 | bank_id |
|---|---|---|
| `IDR_ACEH` → `IDR_ACEHSYARIAH` | 1 | 758 |
| `IDR_ANDA` → `IDR_CCBINDONESIA` | 1 | 747 |
| `IDR_CNB` → `IDR_SHINHAN` | 1 | 821 |
| `IDR_COMMONWEALTH` → `IDR_OCBCNISP` | 1 | 829 |
| `IDR_DINAR` → `IDR_OKEINDONESIA` | 1 | 812 |
| `IDR_NTB` → `IDR_NUSATENGGARABARATSYARIAH` | 1 | 773 |
| `IDR_NUSANTARAPARAHYANGAN` → `IDR_DANAMON` | 1 | 783 |
| `IDR_RIAU` → `IDR_RIAUSYARIAH` | 1 | 762 |
| `IDR_WINDUKENTJANAINTERNATIONAL` → `IDR_CCBINDONESIA` | 1 | 734 |
| `SGD_HSBC` → `SGD_HSBC_CORP` | 3 | 286,317,466 |
| `SGD_MAYBANK` → `SGD_MAYBANK_CORP` | 5 | 228,265,292,323,472 |
| `VND_DONGA` → `VND_VIKKIDIGITAL` | 2 | 534,1159 |

### VPlus33

| 原本掛 → 改成 | 列數 | bank_id |
|---|---|---|
| `IDR_ACEH` → `IDR_ACEHSYARIAH` | 1 | 142 |
| `IDR_ANDA` → `IDR_CCBINDONESIA` | 1 | 131 |
| `IDR_CNB` → `IDR_SHINHAN` | 1 | 205 |
| `IDR_COMMONWEALTH` → `IDR_OCBCNISP` | 1 | 97 |
| `IDR_DINAR` → `IDR_OKEINDONESIA` | 1 | 196 |
| `IDR_NTB` → `IDR_NUSATENGGARABARATSYARIAH` | 1 | 157 |
| `IDR_NUSANTARAPARAHYANGAN` → `IDR_DANAMON` | 1 | 167 |
| `IDR_RIAU` → `IDR_RIAUSYARIAH` | 1 | 146 |
| `IDR_WINDUKENTJANAINTERNATIONAL` → `IDR_CCBINDONESIA` | 1 | 118 |
| `VND_DONGA` → `VND_VIKKIDIGITAL` | 2 | 48,276 |

## 補上空白對應（一般同步）：六站共 41 列

只列信心度 95 以上、會自動寫入的列；依據欄的 name 是名稱完全相同，alias 是對到別名。Alfawin77 沒有要補的列。

### TPower

| bank_id | 平台銀行名稱 | 對到 | 依據 |
|---|---|---|---|
| 464 | (SGD) - GPAY | `SGD_GOOGLEPAY` | alias（95）|
| 470 | (SGD) - Jpmorgan Chase Bank | `SGD_JPMORGANCHASE` | name（100）|
| 472 | (SGD) - Malayan Banking Berhad | `SGD_MAYBANK_CORP` | alias（95）|
| 480 | (SGD) - The Bank Of Tokyo-Mitsubishi | `SGD_MUFG` | alias（95）|
| 753 | (SGD) - GPAY | `SGD_GOOGLEPAY` | alias（95）|
| 759 | (SGD) - Jpmorgan Chase Bank | `SGD_JPMORGANCHASE` | name（100）|
| 761 | (SGD) - Malayan Banking Berhad | `SGD_MAYBANK_CORP` | alias（95）|
| 769 | (SGD) - The Bank Of Tokyo-Mitsubishi | `SGD_MUFG` | alias（95）|
| 1478 | (VND) - Indovina Bank | `VND_INDOVINA` | name（100）|
| 1517 | (VND) - Indovina Bank | `VND_INDOVINA` | name（100）|
| 1667 | (VND) - Indovina Bank | `VND_INDOVINA` | name（100）|
| 1712 | (VND) - Vietnam Bank for Social Policies | `VND_VIETNAMSOCIALPOLICIES` | name（100）|
| 1716 | (VND) - Vietnam Bank for Social Policies | `VND_VIETNAMSOCIALPOLICIES` | name（100）|
| 1718 | (VND) - Vietnam Bank for Social Policies | `VND_VIETNAMSOCIALPOLICIES` | name（100）|
| 1731 | (VND) - Indovina Bank Limited | `VND_INDOVINA` | alias（95）|
| 1747 | (VND) - Indovina Bank Limited | `VND_INDOVINA` | alias（95）|
| 1763 | (VND) - Indovina Bank Limited | `VND_INDOVINA` | alias（95）|

### Yes2Win

| bank_id | 平台銀行名稱 | 對到 | 依據 |
|---|---|---|---|
| 575 | (IDR) - PT Bank Pan Indonesia | `IDR_PANIN` | alias（95）|
| 1355 | (VND) - Indovina Bank | `VND_INDOVINA` | name（100）|
| 1364 | (VND) - Vietnam Bank for Social Policies | `VND_VIETNAMSOCIALPOLICIES` | name（100）|
| 1369 | (VND) - Indovina Bank | `VND_INDOVINA` | name（100）|
| 1378 | (VND) - Vietnam Bank for Social Policies | `VND_VIETNAMSOCIALPOLICIES` | name（100）|
| 1383 | (VND) - Indovina Bank | `VND_INDOVINA` | name（100）|
| 1392 | (VND) - Vietnam Bank for Social Policies | `VND_VIETNAMSOCIALPOLICIES` | name（100）|
| 1421 | (VND) - Indovina Bank Limited | `VND_INDOVINA` | alias（95）|
| 1437 | (VND) - Indovina Bank Limited | `VND_INDOVINA` | alias（95）|

### Me77

| bank_id | 平台銀行名稱 | 對到 | 依據 |
|---|---|---|---|
| 26 | Malaysia Islamic Bank | `MYR_BANKISLAM` | alias（95）|
| 59 | Malaysia Islamic Bank | `MYR_BANKISLAM` | alias（95）|
| 84 | Malaysia Islamic Bank | `MYR_BANKISLAM` | alias（95）|

### VWorld

| bank_id | 平台銀行名稱 | 對到 | 依據 |
|---|---|---|---|
| 284 | (SGD) - GPAY | `SGD_GOOGLEPAY` | alias（95）|
| 290 | (SGD) - Jpmorgan Chase Bank | `SGD_JPMORGANCHASE` | name（100）|
| 300 | (SGD) - The Bank Of Tokyo-Mitsubishi | `SGD_MUFG` | alias（95）|
| 315 | (SGD) - GPAY | `SGD_GOOGLEPAY` | alias（95）|
| 321 | (SGD) - Jpmorgan Chase Bank | `SGD_JPMORGANCHASE` | name（100）|
| 331 | (SGD) - The Bank Of Tokyo-Mitsubishi | `SGD_MUFG` | alias（95）|
| 464 | (SGD) - GPAY | `SGD_GOOGLEPAY` | alias（95）|
| 470 | (SGD) - Jpmorgan Chase Bank | `SGD_JPMORGANCHASE` | name（100）|
| 480 | (SGD) - The Bank Of Tokyo-Mitsubishi | `SGD_MUFG` | alias（95）|
| 586 | AFFIN ISLAMIC BANK | `MYR_AFFIN` | alias（95）|

### VPlus33

| bank_id | 平台銀行名稱 | 對到 | 依據 |
|---|---|---|---|
| 60 | AGRI BANK | `VND_AGRIBANK` | alias（95）|
| 94 | PT Bank Pan Indonesia | `IDR_PANIN` | alias（95）|
