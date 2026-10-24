---
name: game-integration
description: 遊戲商整合技能。當任務涉及新接 / 實作遊戲商、單一錢包（AbstractSingleWallet / getGameUrl）/ 轉帳錢包（AbstractTransferWallet / transferToVendor / transferToLocal）、回調扣款與派彩、`make:vendor` 腳手架，或牽涉 Vendor / Wallet / VendorIdEnum 等遊戲商相關實作時自動載入。
argument-hint: "[遊戲商名稱] [transfer-wallet|single-wallet|回調|全部]"
---

你是一位資深 PHP/Laravel 架構師，正在協助整合遊戲商到博弈平台。

依照本手冊的規範完成任務：$ARGUMENTS

> **三方規格 SSOT（對接第一步）**：實作任何 Driver / Controller **之前**，先把廠商三方文檔整理成 AI 與後人都能讀的 markdown，落檔到 APIs repo `docs/integrations/vendors/<VendorName>/api.md`，對接全程以它為實作規格依據（建檔步驟見「新增遊戲商完整上線步驟」的步驟 0、定位與命名見 [reference/artisan-and-docs.md](reference/artisan-and-docs.md)「遊戲商整合文檔」）。本手冊下文提到的「遊戲商 API 文件」一律指該 vendor 的 `api.md`。**既有 vendor 改動時**：若同目錄已存在 `integration.md`，讀／建 `api.md` 後**仍須優先參閱 `integration.md`**（已驗證落差與踩坑，比廠商原文更貼近真實行為，詳見 [reference/artisan-and-docs.md](reference/artisan-and-docs.md)「遊戲商整合文檔」讀取規範）。

> **驗收契約（單錢包）**：本 skill 的 [`reference/acceptance-contract.md`](reference/acceptance-contract.md) 是單錢包接入的逐項驗收清單（A–I 九區＋J 區 QA 手動清單；自動化測試規範由 `reference/testing.md` 承擔、AI agent 依其產出測試）。三個必經對照時點：(1) **開工時**：複製為該 vendor 的 `docs/integrations/vendors/<VendorName>/acceptance.md`，先通讀一遍知道驗收標準再動工；(2) **開 MR 時**：實作者逐項自檢勾選後，**將勾選完成的 contract 一併附上 MR**：repo 內的 `acceptance.md` 隨 feature 分支提交進 MR diff，並把勾選清單貼進 MR description（GitLab 會渲染成可勾的 checkbox），reviewer 對照這份表審、勾不了的項目必須寫明原因；(3) **正式代理開啟前**（「新增遊戲商完整上線步驟」步驟 10 的 ②）：reviewer 與 QA 完成簽核、全數勾選才放行。

> **復用檢查（動手寫任何邏輯前）**：先確認是否已有同事做過類似功能，避免重工：(a) 搜既有代碼：`app/Services`、`app/Helpers`、`Common` 與同類 Vendor / AbstractWallet 實作；(b) `git log --grep='<功能關鍵字>'` 查歷史 commit；(c) 有 gitlab MCP 時搜相關 MR。查到類似實作先比對：能復用就直接復用；需要整段複製時，改為抽共用方法（Trait / Abstract / Service），禁止複製貼上改值。

---

## 術語對照（強制統一）

> 跨章節必須使用一致術語。同一個概念用不同詞彙會讓 reviewer 與後續維護者誤判語意——尤其「累加」過去同時被用來指「BR 欄位數值疊加」與「transaction_log 共用同一筆」，是高頻誤解源。

| 業務概念 | 統一中文 | 技術對應 | 別用 |
|---|---|---|---|
| BR 欄位數值疊加 | **累加** | `Common::numAdd($br->bet, $amount, 4)` | - |
| 同 type+vendor 連續寫入共用 log | **延用** | `TransactionLogCreateService::updateOrLockTransactionLog` 更新同一筆 | 累加（保留給 BR）|
| 建立新資源（BR / log / 注單 / migration / 資料表） | **新建** | `create()` | 建新（語序不通順）|
| Vendor 退回 BR.bet 的業務動作 | **取消投注** | rollback / refund endpoint | Vendor rollback / Vendor refund |
| 派彩被扣回（含部分扣回與全沖銷至 0） | **結算改單** | `isWinAdjustment` / `SEAMLESS_WALLET_CHANGE` (29) | 派彩調整（雙向不精準）|
| 一般投注 / 結算 / 追加派彩 | **投注結算** | `SEAMLESS_WALLET_BET_SETTLE` (27) | fallback / 預設分支（描述代碼機制非業務）|
| 同 round 多次 settle 累進派彩 | **多段累進派彩** | PTSW live 多手 / split / 保險、PPSW BonusWin 等 | 追加派彩 / 累進派彩 |
| 經由聚合商接入的遊戲商 | **集成** | Driver 實作 `HasAggregatorChannel`（聚合商的子遊戲商掛在其 `array_fill_keys` 群組內，共用該 Driver 的宣告；見 [artisan-and-docs.md](reference/artisan-and-docs.md)「接入通道與回調路徑宣告」） | 聚合、aggregated |
| 我方直接對接原廠、不經聚合商的遊戲商 | **直連** | Driver 未實作 `HasAggregatorChannel` | 原廠 |

---

## 🚫 動錢回調安全紅線（速查表）

> **這張表上的每一條，違反後果都是「有人可以主動、重複觸發，每次都拿到錢」**——不是帳務失真，是真的少一筆錢。
> 其中 R4 與 R13 已在生產環境造成實際財損。
> **接新遊戲商時必須逐條對照，不可跳過**；判定「本協定不適用」時，要在該 vendor 的 `integration.md` 寫明理由，不可只在心裡跳過。
> 本表只是索引，條文與既有實作對照寫在各章；**規則的權威出處是那些 class 自己的 docblock**。

### 交易與鎖

| # | 紅線 | 詳見 |
|---|---|---|
| R1 | 交易內第一個 SQL 必須是鎖會員列，在那之前不得有任何一般讀（含 lazy load） | [single-wallet.md](reference/single-wallet.md)「交易與鎖（R1～R3）」 |
| R2 | 同一 controller 內所有動錢分支的鎖序必須一致：先會員、後注單 | [single-wallet.md](reference/single-wallet.md)「交易與鎖（R1～R3）」 |
| R3 | 修 stale snapshot 不能只修 controller——共用 Service 內的 `updateOrCreate` 同樣是一般讀 | [single-wallet.md](reference/single-wallet.md)「交易與鎖（R1～R3）」 |

### 冪等與重送

| # | 紅線 | 詳見 |
|---|---|---|
| R4 | 撤單／退款的冪等鍵必須是「原下注」的識別，不可用撤單請求自己的交易號 | [single-wallet.md](reference/single-wallet.md)「冪等與重送（R4～R10）」 |
| R5 | 冪等字串比對必須與該欄位的 collation 同一把尺（大小寫**與** PAD SPACE 兩件事） | [single-wallet.md](reference/single-wallet.md)「冪等與重送（R4～R10）」 |
| R6 | 我方底線前綴標記是獨占命名空間，入庫前必須剝除三方同名鍵 | [single-wallet.md](reference/single-wallet.md)「冪等與重送（R4～R10）」 |
| R7 | 冪等只比「決定動到哪一筆」的關鍵欄位加金額，禁止比整包 payload | [single-wallet.md](reference/single-wallet.md)「冪等與重送（R4～R10）」 |
| R8 | 用來比對的型別字串必須封成 Enum | [single-wallet.md](reference/single-wallet.md)「冪等與重送（R4～R10）」 |
| R9 | 閘門粒度必須對齊三方的退款粒度：multi-bet 局以「單筆下注」為鍵 | [single-wallet.md](reference/single-wallet.md)「冪等與重送（R4～R10）」 |
| R10 | 扣款類端點的重送守衛必須排在餘額檢查之前 | [single-wallet.md](reference/single-wallet.md)「冪等與重送（R4～R10）」 |

### 取消與退款

| # | 紅線 | 詳見 |
|---|---|---|
| R11 | 終態守衛必須抽成單一方法，掛在每一條會動錢包的分支；補帳命令改過的狀態同屬終態 | [single-wallet.md](reference/single-wallet.md)「取消與退款（R11～R15）」 |
| R12 | 三方帶來的金額與我方紀錄不符一律拒收，注單金額不接受三方覆寫 | [single-wallet.md](reference/single-wallet.md)「取消與退款（R11～R15）」 |
| R13 | 退款前必須驗證「原下注真的扣過款」，並對退款額設上下界 | [single-wallet.md](reference/single-wallet.md)「取消與退款（R11～R15）」 |
| R14 | 取消／退款一律把 `bet`／`valid_bet`／`win`／`win_lose` 歸零，金額交由 `changeBalance()` 依差額推得 | [single-wallet.md](reference/single-wallet.md)「取消與退款（R11～R15）」 |
| R15 | 部分退款不可一律翻終態：退完才翻，否則留原狀態並改由冪等守衛擋重送 | [single-wallet.md](reference/single-wallet.md)「取消與退款（R11～R15）」 |

### 來源驗證與憑證

| # | 紅線 | 詳見 |
|---|---|---|
| R16 | 每個接入的動錢 vendor 至少要有一道有效來源驗證：IP 白名單或驗簽 | [single-wallet.md](reference/single-wallet.md)「來源驗證與憑證（R16～R21）」 |
| R17 | 憑證未設定時一律拒收並記 warning，禁止靜默放行 | [single-wallet.md](reference/single-wallet.md)「來源驗證與憑證（R16～R21）」 |
| R18 | 憑證等值比對一律 `hash_equals()`，禁止 `==` | [single-wallet.md](reference/single-wallet.md)「來源驗證與憑證（R16～R21）」 |
| R19 | 排在解密／驗簽**之前**的查詢屬未認證輸入，一律綁定參數 | [single-wallet.md](reference/single-wallet.md)「來源驗證與憑證（R16～R21）」 |
| R20 | 授權檢查必須排在任何取列鎖與「會洩漏存在與否」的查詢之前 | [single-wallet.md](reference/single-wallet.md)「來源驗證與憑證（R16～R21）」 |
| R21 | 以注單反查帳號時，查詢必須限縮在該商戶（`vendor_info`）範圍內 | [single-wallet.md](reference/single-wallet.md)「來源驗證與憑證（R16～R21）」 |

### 回應與錯誤碼

| # | 紅線 | 詳見 |
|---|---|---|
| R22 | 錯誤碼依「三方收到後還會不會再送」挑，不依語意貼切；`match` 的 `default` 落在可重試側 | [single-wallet.md](reference/single-wallet.md)「錯誤碼與回應（R22～R26）」 |
| R23 | 冪等擋下後的回應必須與首次成功同形（帶三方判定成功所需的完整欄位與餘額） | [single-wallet.md](reference/single-wallet.md)「錯誤碼與回應（R22～R26）」 |
| R24 | 回給三方的 ID 欄位必須自始至終取同一張表的鍵 | [single-wallet.md](reference/single-wallet.md)「錯誤碼與回應（R22～R26）」 |
| R25 | 取到錢包鎖後必須檢查自收到請求起的耗時，逾三方 deadline 即 rollback 不入帳 | [single-wallet.md](reference/single-wallet.md)「錯誤碼與回應（R22～R26）」 |
| R26 | 任何守衛判定「重送／拒收」時一律記 warning，帶齊 `member_id`、金額與判定依據 | [single-wallet.md](reference/single-wallet.md)「錯誤碼與回應（R22～R26）」 |

### 命令與補帳

| # | 紅線 | 詳見 |
|---|---|---|
| R27 | 會動錢包的補帳命令必須取回調用的那把同局 named lock，取不到即以 failed 收單 | [reconcile.md](reference/reconcile.md)「補帳／退款命令的規約」 |

> 📌 **這些紅線要怎麼寫測試才驗得到，見 [reference/testing.md](reference/testing.md)「安全規則怎麼驗」**。這批事故裡有多個缺陷是「測試綠著卻仍在生產爆掉」——併發競態用循序重送測不出來、驗簽測試沿用產線同一支 helper 等於自己跟自己比對。
>
> ⚠️ **有些「看起來像 bug」的寫法是刻意的**（刻意不加鎖、刻意不遮罩、刻意保留某種語意）。動手「修正」之前先查 [reference/deliberate-decisions.md](reference/deliberate-decisions.md)（**刻意不做的決定**），那裡列了每一條的理由與出處。

---

## 兩種錢包架構（選型）

| 架構 | 繼承類別 | 適用場景 |
|------|---------|---------|
| Transfer Wallet | `AbstractTransferWallet` | 玩家需先把餘額轉入遊戲商，每次進出遊戲都需轉帳 |
| Single Wallet | `AbstractSingleWallet` | 共用錢包，遊戲商透過回調扣款，無需轉帳 |

遊戲商類別目錄：

```
app/Vendors/{VendorName}/
├── Driver.php        # 主要類別（繼承 AbstractTransferWallet 或 AbstractSingleWallet）
└── DriverMethod.php  # 方法實作（可選，邏輯複雜時拆分）
```

腳手架：
```bash
php artisan make:vendor   # 互動式選擇 single-wallet 或 transfer-wallet
```

---

## 做到哪一步讀哪一份

本手冊主檔只放術語、紅線速查表、選型與上線步驟；各階段的規則拆在 `reference/` 底下。下表左欄的情況一出現，就必須先打開右欄那份再動手，不可只憑速查表的一行摘要實作。

| 什麼情況／哪個上線步驟 | 必讀 |
|---|---|
| 步驟 0：開工前整理三方規格 `api.md`、判斷要不要寫 `integration.md`；任何要讀 `api.md` 的時候 | [reference/artisan-and-docs.md](reference/artisan-and-docs.md)「遊戲商整合文檔」 |
| 步驟 0、開 MR 前、正式代理開啟前（單一錢包） | [reference/acceptance-contract.md](reference/acceptance-contract.md) |
| 步驟 4：選型是轉帳錢包，寫 Driver 的轉帳、查餘額、轉帳狀態確認 | [reference/transfer-wallet.md](reference/transfer-wallet.md) |
| 步驟 4、9：選型是單一錢包，寫 Driver、`createAccount()`、回調 Controller，或改任何會動錢包的回調（下注、結算、取消、退款、調整） | [reference/single-wallet.md](reference/single-wallet.md) |
| 對照速查表某一條紅線（R1～R26）的完整條文、藍本與事故出處 | [reference/single-wallet.md](reference/single-wallet.md)（速查表「詳見」欄指到的節） |
| 步驟 4：組 `getGameUrl()` 的回應、Driver 打三方 API 的錯誤處理；步驟 10：設定 `vendor_infos.info` 的 key；處理遊戲帳號綁定 | [reference/config-and-tools.md](reference/config-and-tools.md) |
| 步驟 4、5：判斷這家是集成還是直連、Driver 要不要宣告 `HasAggregatorChannel`／`HasCallbackPath`；在既有聚合商底下新增子遊戲商 | [reference/artisan-and-docs.md](reference/artisan-and-docs.md)「接入通道與回調路徑宣告」 |
| 步驟 6：設計 `{tname}_accounts`／`{tname}_bet_records`／`{tname}_deal_records` 的 migration、建 Model；遊戲商有打賞或道具購買 | [reference/schema.md](reference/schema.md) |
| 步驟 10：單一錢包設定 `rate`，或回調要換算金額、組餘額回應 | [reference/single-wallet.md](reference/single-wallet.md)「vendor_info.rate 換算」 |
| 步驟 10、11：跑 `vendor:add_vendor_info`、`update_game_list`、`create_vendor_account` 等命令，掛遊戲清單同步介面 | [reference/artisan-and-docs.md](reference/artisan-and-docs.md)「Artisan 命令」 |
| 步驟 13、15：註冊 `config/summary_bet_record.php`、建對帳卡；寫會動錢包的補帳／退款命令（R27）；OP 補跑；拉注單 | [reference/reconcile.md](reference/reconcile.md) |
| 寫任何測試，或要驗證某條紅線真的被守住 | [reference/testing.md](reference/testing.md) |
| 想「修正」一段看起來像 bug 的既有寫法（漏鎖、不遮罩、錯誤碼看似不對）之前 | [reference/deliberate-decisions.md](reference/deliberate-decisions.md) |

---

## 測試規範

單一錢包回調、轉帳錢包 Driver、Vendor 命令的測試覆蓋情境、目錄結構、`Http::fake` 標準格式統一收在：

- [`reference/testing.md`](reference/testing.md) — 各類 callback 與 Driver 必測情境清單與強制要求

新增遊戲商時依此規範補齊對應測試（單錢包：每個 callback 一支測試檔；轉帳錢包：三種轉帳狀態必覆蓋）。

---

## 新增遊戲商完整上線步驟

> `VendorIdEnum` 的值為所有子專案共用，新增前需**跨專案確認**。

**起手（步驟 0）：先落檔三方規格 `api.md`，並複製驗收契約**。單錢包 vendor 同時把本 skill 的 `reference/acceptance-contract.md` 複製為 `docs/integrations/vendors/<VendorName>/acceptance.md`，全程對照、開 MR 前自檢、正式代理開啟前完成簽核（時點見文首「驗收契約」段）。動任何程式碼前，先把廠商提供的三方文檔（PDF／網頁／信件規格）整理成 markdown，建立 APIs repo `docs/integrations/vendors/<VendorName>/api.md`（目錄名對齊 [reference/artisan-and-docs.md](reference/artisan-and-docs.md)「遊戲商整合文檔」命名規則，API＋簽名＋錯誤碼於單檔內分節）。後續步驟 1–13 的 Driver / Migration / Controller 實作**一律以此 `api.md` 為規格依據**。

1. 更新 `app/Enums/Vendor/VendorIdEnum.php`
2. 更新 `database/data/vendors.php`，**並同步補 canonical 注單表清單** `database/data/bet_records/tables.php`
    - 只要 `vendors.php` 有 `tname`，本清單就必須有條目引用它的 id；生產庫還沒建表時標 `registry_only`，等跨白標稽核報出「registry_only 但 DB 已有」再改標 `vendor`
    - `BetRecordTableRegistryParityTest` 會擋。照著既有 vendor 抄時，注單表登記仍要逐項對照本節，**不能只照抄既有 vendor 的做法**；實況比對走 `php artisan bet-record:audit-tables`
3. 以腳手架建立 Driver：`php artisan make:vendor`
    - 單錢包腳手架產生的 `defaultCallbackPath()` 回傳 `/external/api/sw/TODO` 佔位值，等步驟 9 設好路由後回填
4. 實作 `app/Vendors/{VendorName}/Driver.php`
5. 在 `config/vendor.php` 以 `VendorIdEnum::XXX->value` 為 key 註冊 Driver class
    - 接的是既有聚合商的子遊戲商時，註冊在該聚合商的 `array_fill_keys` 群組內，接入通道自動繼承；全新聚合商見 [reference/artisan-and-docs.md](reference/artisan-and-docs.md)「接入通道與回調路徑宣告」
6. 建立 Migration：遊戲商 schema 依 [reference/schema.md](reference/schema.md)；通用結構與安全原則依 `/db-migration` command
7. 依 `whitelabel-container-exec` 選定並標示環境，先跑 `php artisan migrate --pretend`；回報 SQL 後取得文字確認，才執行正式 `php artisan migrate`
8. 再次列明相同目標環境與 `php artisan db:seed --class=SyncVendorSeeder` 的影響，取得文字確認後才執行
9. （Single Wallet）實作回調 Controller，設定 `routes/external/sw.php`
    - Controller 首行要宣告 `declare(strict_types=1);`。`make:vendor` 只產 Driver 與 Model，不產 Controller，這行要自己寫；APIs 規定所有 PHP 檔都要宣告，沒有例外
    - 把 Driver 的 `defaultCallbackPath()` 回填成這家的路由前綴（`/external/api/sw/<prefix>`），兩邊要逐字一致；`DriverCallbackPathDeclarationTest` 會擋格式不符、殘留 TODO 與路由不存在，但是否為本家前綴要人工核對
10. 執行 `php artisan vendor:add_vendor_info` 建立 vendor_info，設定以下欄位：
    - `currency`、`rate`：幣別與匯率（須跟 PM 確認）
    - `account_prefix`：區分不同商戶玩家帳號的前綴
    - `transfer_min_amount`：若遊戲商不允許轉出小數點以下金額，設為 `1`
    - `prepared_account_number`：開發期間設為 `1`，確認帳號建立正常後再調整
    - `status`：**開發環境直接設為 `1`**；正式環境保持 `0`，設定完 `info` 欄位後再手動開啟
    - **開發測試環境固定使用代理 ID：`4249`**（腳本會自動帶入 `1`，故實際關聯代理為 `[1, 4249]`）

    **正式環境雙代理配置**：正式環境白標（非 `Dev` / `mysql`）**須執行兩次** `vendor:add_vendor_info`，分別掛到測試代理與正式代理——代理 id 查白標 registry `plugins/apis-toolkit/registry/whitelabels.metadata.yaml`（正式代理＝`rootAgentId`、測試代理＝`testRootAgentId`，測試代理直接掛在正式代理下 `agents.parent == rootAgentId`）：
      - **① 測試代理**（registry `testRootAgentId`）：`status` 設 `1`（啟用），供測試人員驗收遊戲商配置——上方步驟 10 的「正式環境保持 `0`」**只適用正式代理**，測試代理 row 必須啟用才驗得了；
      - **② 正式代理**（registry `rootAgentId`）：`status` 先保持 `0`，待測試人員在測試代理驗收無誤，才手動開啟（`status=1`）對外。

      （開發環境沿用上方固定代理 `4249`，無此雙代理分流。）
11. 若遊戲商有提供取得遊戲列表 API，在 Driver 實作 `getGameList()` 並依同步策略掛介面（`ShouldSyncGameList` = 納入每小時自動排程；只掛 `HasGameList` = 純手動，詳見 [reference/artisan-and-docs.md](reference/artisan-and-docs.md)「遊戲清單同步介面約束」），再執行初次同步：
    ```bash
    php artisan update_game_list --vendor-id={vendorId}
    ```
12. 驗證上架資料是否完整：
    ```sql
    SET @VendorId = XXX;
    SELECT * FROM vendors           WHERE id = @VendorId AND status = 1;
    SELECT * FROM vendor_infos      WHERE vendor_id = @VendorId AND status = 1;
    SELECT * FROM agent_vendors     WHERE vendor_id = @VendorId AND status = 1;
    SELECT * FROM games             WHERE vendor_id = @VendorId AND status = 1 ORDER BY id DESC;
    SELECT * FROM agent_games       WHERE game_id IN (SELECT id FROM games WHERE vendor_id = @VendorId AND status = 1) ORDER BY game_id DESC;
    SELECT * FROM vendor_info_games WHERE game_id IN (SELECT id FROM games WHERE vendor_id = @VendorId) ORDER BY game_id DESC;
    SELECT * FROM vendor_customized_items WHERE vendor_id = @VendorId;
    ```
    每筆查詢都應有紀錄。若測試環境未出現遊戲，嘗試清除快取。
13. 若該遊戲商要走整點排程補帳，於本專案 `config/summary_bet_record.php` 註冊一筆設定（詳見 [reference/reconcile.md](reference/reconcile.md)「新增步驟」）。
14. **回補對接文檔**：`api.md` 已於步驟 0 建立。接入完成後：(a) 若 `api.md` 整理時有**漏抄／抄錯廠商原文**，回頭校正（仍只還原廠商說法，不寫入我方詮釋）；(b) 若發現**廠商原文與實測不符**或踩到坑，依 [reference/artisan-and-docs.md](reference/artisan-and-docs.md)「integration.md litmus test」 補 `integration.md`，**不可**改寫進 `api.md`（維持 api.md=廠商原文、integration.md=已驗證落差 的邊界）。
> **在既有聚合商底下新增子遊戲商**（EliteHub（TTHSW）、target、GMSW、DCT 這類，子遊戲商共用聚合商的 Driver）不必走完整流程，改動固定四個檔：`VendorIdEnum` 新增 case、`config/vendor.php` 併進該聚合商群組、`database/data/vendors.php` 新增一筆、`database/data/bet_records/tables.php` 的該聚合商 `vendor_ids` 補上新 id（藍本：`f103c2a93` 一次加 28 家）。Driver／Controller／migration 全部沿用聚合商既有的，不另建目錄；接入通道與回調路徑隨 Driver 自動繼承，不必另外宣告。

15. **建立對帳卡**：於 APIs repo 建 `docs/reconcile/vendors/{VendorName}.md`（專屬表卡複製 `_template-dedicated.md`、聚合商共用表複製 `_template-aggregator.md`）。此卡是 `reconcile-bet-records`／`repair-bet-records` 的**硬依賴**（Step 0 gate），未建或缺必填段，未來該 vendor 對帳直接 STOP。卡要記兩件對帳缺一不可的事：
    - **(1) 我方 BR 怎麼撈**：BR 有效投注篩選謂詞（多數 = `status={SETTLE}` 加排除條件）、對帳用的日期欄位（`time_field`，須與 [reference/reconcile.md](reference/reconcile.md)「新增步驟」註冊的 `config/summary_bet_record.php` 同步）、投注紀錄各時間欄位的時區與 UTC 換算公式。
    - **(2) 怎麼回核三方**：三表 SUM 對齊後，如何從三方重抓注單逐筆確認與我方 BR 吻合，即取資料來源／命令、三方欄位 → BR 欄位 mapping、金額口徑（尤其 win 是否含 jackpot）。
    卡型判定、必填契約與填寫流程以 `reconcile-bet-records` skill §6.1／§6.2 為準，本步不複述。
