# 通道幣別與錢包幣別的拆分

> 2026-09-10 初版；**2026-09-17 會後更新**：決策 3 撤回、兩題待拍板結案、新增決策 6～8。本檔只記決策，不寫背景與替代方案。名詞定義以 repo 根 [`GLOSSARY.md`](../../../GLOSSARY.md) 為準，決議編號以 [`multi-currency-wallet-decisions.md`](multi-currency-wallet-decisions.md) 為準，實測數據見文末附錄。

## 一、已拍板

| # | 決策 | 理由 |
|---|---|---|
| 1 | **「銀行幣別」併入「通道幣別」**，`GLOSSARY.md` 移除該詞條，`_Avoid_` 保留該詞 | `banks.currency_id` 記的是會員提款時實際收到的幣別，與存款側 `agent_cash_flows.currency_id` 是同一個概念，只是掛在提款側。若兩者等於錢包幣別，`banks.rate` 不必存在（附錄 A） |
| 2 | **`currencies` 六站維持一份共用清單** | 一份代碼登記處對一份 `CurrencyEnum`，seeder 一份、解析鏈跨站行為一致、開新站直接套。各站的差異落在引用端，不落在主檔 |
| 3 | ⛔ **09-17 撤回後半**：「不另建 `wallet_currencies`」維持，「在 `currencies` 加旗標欄位」撤回，改由決策 6 的主檔清洗取代。以下為原文。<br>**不另建 `wallet_currencies`，改在 `currencies` 加旗標欄位表達用途** | 「這個幣別能不能當錢包幣別」全平台只有一個答案，是屬性不是關係；另建表要決定 id 空間並遷移 `member_wallets` 的引用，換到的東西一樣 |
| 4 | **`withdrawal_orders` 要加幣別欄位** | 該表目前完全沒有幣別欄位，`amount`（通道幣別）與 `point`（錢包幣別）兩種金額並存卻無從辨識，多幣別上線後答不出「這筆提款扣哪個錢包」 |
| 5 | **訂單加兩個幣別欄位，不是一個** | 提款側缺的是兩種幣別，只加通道幣別仍答不出扣哪個錢包。且 Dev 實測確認：一次 `ALTER` 加兩欄只消耗 **1 次** row version 額度（上限 64），分兩批加會多吃一次（附錄 B） |
| 6 | **`currencies` 主檔清洗：清掉七個不是幣別的代碼，不加用途旗標**（09-17，決議㉛） | 清除 `VND2`(4)／`KVND`(5)／`IDR2`(7)／`KIDR`(8)／`FON`(11)／`GCASH`(12)／`PayMaya`(15)。主檔清乾淨之後每一筆都是真幣別，「這個代碼能不能當錢包幣別」這個問題就不存在了，旗標沒有東西可標。主檔仍是六站共用一份（決策 2 不變），長期同步走 `SyncCurrenciesSeeder`；清洗本身要另寫一次性命令，因為該 seeder 只增不改不刪 |
| 7 | **虛擬貨幣的 id 全部重編到千位數，含既有的 `USDT`(10)／`BUSD`(13)／`TON`(20)**（09-17，決議㉛） | 法幣約 178 種，前段留給法幣。與決策 6 併成同一個工作包，因為兩者都在改主檔 id，引用面只需要盤一次。🔴 引用面尚未盤點，是這個工作包的第一步 |
| 8 | **千分制是顯示方式：帳本永遠存元，`agent_group_currencies` 加「顯示單位」欄（1 或 1000）**（09-17，決議㉜） | 通道幣別與錢包幣別都只認 `VND`／`IDR`。API 金額一律回元並帶出顯示單位，前端負責格式化與輸入換算；後台 SR 報表的顯示單位跟著代理組別走。尺度只是呈現，後端永遠只認元，1000 倍的錯誤就只可能發生在顯示層、不會發生在錢上 |

## 二、待拍板（09-17 兩題皆已結案）

> ✅ **題 A 結案**：旗標整個撤回（決策 6），所以「旗標由誰維護」不再是問題。營運能調整的是 `agent_group_currencies`（哪一群代理開放哪些幣別），主檔本身走版控與 seeder。`CurrencyEnum::isWalletAllowed()` 的排除名單在主檔清洗完成後失去作用，屆時移除；清洗完成前先把 `USDT`／`TON`／`BUSD` 從排除名單拿掉（決議㉚）。
>
> ✅ **題 B 結案**：不是「全留只改 `status`」也不是「只剔零引用者」，而是決策 6 的清洗名單。`PGK`／`AUD`／`PHP`／`USD`／`CNY` 是標準法幣，留著；`BUSD` 留著並重編。下表保留原文供追溯。

| # | 題目 | 選項 | 影響 |
|---|---|---|---|
| A | 旗標是**營運可即時調整的設定**，還是**從 `database/data/currency_aliases.php` 同步下來的投影** | 營運可調＝DB 是真相，`CurrencyEnum::isWalletAllowed()` 要退位或改查表；版控投影＝改用途要發版，Enum 保留為程式層防線 | 決定旗標欄位是否進後台、`CurrencyContractTest` 的守備範圍、以及 Task 10 每日稽核要比對什麼 |
| B | `currencies` 的低使用 id 如何處置：`IDR2`(7)／`KIDR`(8)／`PGK`(17)／`AUD`(18) **四個完全零引用**；`BUSD`(13) 僅 VWorld 1 列 `order_types`；`PHP`(22)／`USD`(23)／`CNY`(24) 是 09-08 新增的預備列；`VND2`(4)／`KVND`(5) 設定面零引用但 **IA 注單有資料** | 全留只改 `status`／剔除完全零引用者 | `orders` 已於 09-10 掃過（VWorld 全表 750 萬列，變體 id 零筆）；要剔除仍需掃 `order_histories`（VWorld 1,457 萬列）。⚠️ 剔除只是「不再由 seeder 補」，既有 DB 列要另外清——`SyncCurrenciesSeeder` 是只增不改不刪 |

## 三、結構：存款與提款兩側對稱

| | 存款側 | 提款側 |
|---|---|---|
| 通道實體 | `agent_cash_flows` | `banks` |
| 通道幣別 | `agent_cash_flows.currency_id` | `banks.currency_id` |
| 入帳比例 | `agent_cash_flows.rate` | `banks.rate` |
| 換算方向 | `amount × rate = point` | `amount × rate = point` |
| 換算位置 | `OrderService.php:76` | `WithdrawalService.php:259` |
| 通道幣別金額 | `orders.amount` | `withdrawal_orders.amount` |
| 錢包幣別金額 | `orders.point` | `withdrawal_orders.point` |
| 訂單上的通道幣別 | `orders.currency_id` | **無欄位** |
| 訂單上的錢包幣別 | **無欄位** | **無欄位** |

兩側的入帳比例方向一致，都是「1 單位通道幣別換得多少錢包幣別」；差別只在存款是會員支付的金額、提款是會員收取的金額。

## 四、欄位設計（依決策 4、5）

| 表 | 新欄位 | 定義 | 語意 | 回填來源 |
|---|---|---|---|---|
| `withdrawal_orders` | `currency_id` | `int unsigned NOT NULL DEFAULT 0` | 通道幣別快照 | `banks.currency_id`（join `bank_id`） |
| `withdrawal_orders` | `wallet_currency_id` | `int unsigned NOT NULL DEFAULT 0` | 錢包幣別快照 | 等錢包幣別解析鏈就位 |
| `orders` | `wallet_currency_id` | `int unsigned NOT NULL DEFAULT 0` | 錢包幣別快照 | 同上 |

欄位註解一律寫「幣別 `currencies.id`；0 為尚未回填」。⚠️ 與 `member_wallets.currency_id` 的註解（「非貨幣錢包固定 0」）**形似但語意不同**：那裡的 `0` 是「這個錢包本來就不計貨幣」（禮碼、活動點數），這裡的 `0` 是「還沒回填」。兩者不可混為一談。

⚠️ **既有欄位的型別本來就不一致**（09-10 實查 VWorld）：`member_wallets` 與 `agent_cash_flows` 是 `int unsigned`，`orders`／`order_types`／`banks` 是 `int`；`orders.currency_id` 更是 `NOT NULL` 卻**沒有預設值**。新欄位採 `int unsigned NOT NULL DEFAULT 0`——理由是 `0` 是本專案既有的「尚未回填」sentinel、`unsigned` 語意正確，且 `NOT NULL` 配預設值才能 INSTANT 加欄位而不影響既有 INSERT。**不追改既有欄位**，那是另一件事。

**為什麼要快照而不是靠 `bank_id`／`order_type_id` 推導**：`banks.currency_id` 之後會被改（該表正在 `payment-bank-convergence` 收斂中）。同理於 `rate` 存在訂單上——`2026_03_16_000001_refactor_payment_channel_schema.php` 註解明寫「`rate` 已是建單快照」。

**執行順序**：

1. 四個欄位 `ADD COLUMN ... ALGORITHM=INSTANT`（不同時建索引、不加 FK，否則 INSTANT 失效）
2. 回填 `withdrawal_orders.currency_id` ← `banks.currency_id`
3. `wallet_currency_id` 先留 `0` 當「尚未回填」的 sentinel

第 3 步不能提前，因為**會員主錢包目前根本沒有幣別欄位**：主錢包的餘額在 `members.balance`，而 `members` 表沒有 `currency_id`。`member_wallets` 裡只有禮碼(1002)、活動(1003／3)與已廢除待清的 2，**沒有 `wallet_type_id = 1` 的主錢包列**（附錄 B）。主錢包搬進 `member_wallets` 是第一期的範圍（見 `multi-currency-wallet-design.md` 的「中央錢包」詞條），幣別值則要等 `agent_group_currencies`（決議㉕）解析鏈就位。

## 五、與既有決議的關係

| 決議 | 關係 |
|---|---|
| **決議三**（單位變體不刪列、**不加資料庫旗標**、改在 `CurrencyEnum` 加白名單） | 🔴 **決策 3 推翻其中的「不加資料庫旗標」**。白名單本身（`CurrencyEnum::walletAllowed()`）已於第二期 Task 8 實作完成，去留取決於待拍板題 A<br>🔄 **09-17**：旗標也撤回了（決策 6）。決議三留下來有效的部分是「千分制用 `rate` 表達、`currency_id` 維持 `VND`」，由決策 8 補上玩家端的顯示單位 |
| **決議㉖**（USDT 對應 USD，TON／BUSD 不比照） | ⛔ **09-17 已整項撤回**（決議㉚）：USDT 是獨立錢包幣別，TON 也可以開戶。Task 8 白名單對這三個代碼的排除要拿掉 |
| **決議㉘④**（`agent_cash_flows` 必須加 `wallet_currency_id`） | 同一條路線的存款側。本檔把它延伸到訂單層與提款側 |
| **決議㉙**（`agent_cash_flows` 不補唯一鍵） | 不受影響 |

## 附錄 A：判定「銀行幣別＝通道幣別」的實測

VWorld `withdrawal_orders` join `banks` 全表（2026-09-10）：

| `banks.type` | 幣別 | 提款筆數 | 最近一筆 | `rate ≠ 1` |
|---|---|---|---|---|
| 1 銀行帳號 | MYR | 1,243,697 | 2026-09-09 | 6 |
| 4 | SGD | 3,491 | 2026-09-09 | 3,401 |
| 1 | VND | 2,102 | 2024-12 | 0 |
| 2 電子錢包 | MMK | 1,538 | 2025-04 | 0 |
| 3 加密貨幣 | USDT | 992 | 2026-08-03 | 978 |
| 3 加密貨幣 | TON | 3 | 2026-01 | 3 |

若銀行幣別等於錢包幣別，`rate` 應恆為 1。MYR 那 124 萬筆幾乎恆為 1（MYR 銀行對 MYR 錢包），但 USDT 與 SGD 絕大多數不是 1 ⇒ 兩者之間隔著換算，那正是通道幣別的性質。主力場景是單幣別，是這個誤解長期未被發現的原因。

## 附錄 B：DDL 成本與錢包幣別現況

**表規模與 INSTANT 額度**（`TOTAL_ROW_VERSIONS` 上限 64）：

| 表 | VWorld 列數／大小 | TPower 列數／大小 | `TOTAL_ROW_VERSIONS` |
|---|---|---|---|
| `withdrawal_orders` | 1,470,932／570 MB | 1,431,170／655 MB | 兩站皆 **0** |
| `orders` | 7,499,734／4.9 GB | 13,278,912／7.7 GB | 兩站皆 **0** |
| `order_histories` | 14,571,792／6.3 GB | 18,181,229／8.1 GB | 各 1／0 |

MySQL 版本 8.0.37-google（Dev 與六站相同）。

✅ **2026-09-10 於 Dev 實測驗證**：以 `CREATE TABLE LIKE withdrawal_orders` 建零資料影子表，執行

```sql
ALTER TABLE zz_instant_probe
  ADD COLUMN currency_id INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN wallet_currency_id INT UNSIGNED NOT NULL DEFAULT 0,
  ALGORITHM=INSTANT;
```

結果：成功，耗時 **122.98 ms**，`N_COLS` 30 → 32、`TOTAL_ROW_VERSIONS` 0 → **1**。row version 遞增是走 INSTANT 的證據——INPLACE 或 COPY 會重建表並把該值歸零。測完即 `DROP TABLE`，正表未動。

⭐ **兩欄只消耗 1 次 row version 額度**（不是 2 次）⇒ 分兩批加欄位會多吃一次 64 次上限中的配額，這是「一次加兩欄」的具體依據。

⚠️ 這個驗證涵蓋的是「MySQL 接不接受 INSTANT」，不涵蓋正式站的併發影響——INSTANT 只改 metadata，但仍需短暫的 metadata lock，長交易佔著表時會排隊。

**`member_wallets` 現況**（2026-09-10 實查）：**這張表不含主錢包**。`wallet_type_id` 依 `MemberWallet.php:17` 為「1 一般／3 活動／1002 禮碼為保留類型；2、1004 已廢除存量待清；1003 非貨幣、2026-09-03 已裁決廢除但前置未完成」，實查兩站**都沒有任何 `wallet_type_id = 1` 的列**：

| 站 | 1002 禮碼 | 1003 | 2（已廢除待清） | 3 活動 | **1 主錢包** |
|---|---|---|---|---|---|
| VWorld | 267,093 | 1,770 | 75,380 | 1,304 | **0** |
| TPower | 144,267 | 295,148 | 70,443 | 4,036 | **0** |

`currency_id ≠ 0` 的列極少：VWorld 527（MYR 525／SGD 2）、TPower 1,001（全 MYR）。其餘全為 `0`，那是「非貨幣錢包固定 0」的既有語意（禮碼與活動點數本來就不計貨幣），**不是「尚未回填」**。

⇒ 會員主錢包餘額仍在 `members.balance`（`WithdrawalService.php:274` 直接讀它比對提款金額），而 `members` 表**沒有 `currency_id` 欄位**。這與 `multi-currency-wallet-design.md` 的「中央錢包」詞條一致：主錢包搬進 `member_wallets` 是第一期要做的事。

## 未驗證項

- `order_histories`、`shareholder_*` 系列的 `currency_id` 寫入端不在 `app/` 也不在 `database/procedures/`，語意未確認，推測由舊系統寫入
- 舊系統（API_Agent／API_Frontend／API_External）是否讀 `currencies.status` 未查；新系統只有 `CurrencyController.php:35` 與 `Bank.php:69` 兩處
- `withdrawal_payout_records`（代付明細，VWorld 942,625 列／1.5 GB、TPower 1,517,670 列／2.8 GB）同樣無幣別欄位，是否要一併補未決
- VPlus33 全程未查（該白標未進 dbc，MCP 連線關閉）
