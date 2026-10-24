# Artisan 命令與遊戲商整合文檔

> 這份管兩件事：接遊戲商會用到的 artisan 命令與遊戲清單同步介面；APIs repo 裡 `api.md`、`integration.md` 這兩份整合文檔的命名、定位與讀取規範。跑上線步驟的命令、掛遊戲清單同步介面，或建立與補寫整合文檔時讀。

## Artisan 命令

```bash
php artisan vendor:add_vendor_info                  # 新增 vendor_info 商戶設定
php artisan update_game_list --vendor-id={vendorId} # 初次同步遊戲列表（--direct 繞過 queue、--dry-run 乾跑）
php artisan vendor:import_game_list {vendorId}      # 從 database/data/game_list_json_{vendorId}.json 匯入
php artisan vendor:check-config {vendorId} {agentId}
php artisan create_vendor_account --vendor-id={vendorId}  # 補建帳號池（--direct 繞過 queue、--dry-run 乾跑）
php artisan fetch_bet_record --vendor={vendorId} --dry-run # 拉注單／三方比對，見 reconcile.md「拉注單（三方主動查詢補注單）」
php artisan cache:clear                             # 改完 vendor_info 要清：VendorService 的清單快取 TTL 1 小時
```

> 🚫 **商戶設定的快取沒有專用的清除命令，只能 `cache:clear`**。`VendorService::getVendorIds()`／`getVendorInfos()` 以 agent id 為 key 快取一小時且無專用 flush，改完商戶設定要立刻生效只能 `cache:clear`。

> **Docker 執行格式**：`docker exec -it php-fpm8 bash -c "cd /var/www/APIs && <command>"`

### 遊戲清單同步介面約束（HasGameList / ShouldSyncGameList）

`update_game_list` 依 Driver 實作的介面決定同步行為，**不以 vendor id 區段判斷**：

| 介面 | 定位 | 行為 |
|---|---|---|
| `App\Vendors\Contracts\HasGameList` | 契約層 | 宣告 `getGameList(GetGameListParamsData): GetGameListResultData`。可被 `update_game_list --vendor-id={id}` 手動觸發（含 Olympus 後台系統操作），**不進自動排程** |
| `App\Vendors\Contracts\ShouldSyncGameList`（extends `HasGameList`） | 註冊層 marker | 標記即納入每小時自動排程；排程逐商派發 `SyncVendorGameList` Job 至 queue，Job 具唯一性（`ShouldBeUnique`），前一輪未消化完不會重複入列 |

> 📌 兩個介面（連同 `ShouldScheduleAccountCreation`／`SupportsAccountCreation`／`SupportsBetRecordFetch`／`ShouldScheduleBetRecordFetch`／`ShouldSummaryBetRecord`）**全部放在 `App\Vendors\Contracts\`**，`App\Vendors\` 根命名空間沒有這些介面。

使用規則：

- **要自動抓** → Driver `implements ShouldSyncGameList`（不需額外實作方法，`getGameList` 契約由父介面承擔）
- **不要自動抓（純手動）** → 只 `implements HasGameList`
- **退出排程** → 將 `implements ShouldSyncGameList` 降級為 `HasGameList`，契約保留
- ⚠️ **同一 Driver 掛多個 vendor id**（聚合商的子遊戲商，或 WudiSW／FumaSW 本體與 FREE 變體）標記 `ShouldSyncGameList` 後，該 Driver 底下所有 vendor id 一併納入排程（這是排程範圍的機制，不代表集成；集成判定見「接入通道與回調路徑宣告」）
- 排程卡住需救援時，`update_game_list --vendor-id={id} --direct` 同步執行（繞過 Job 唯一鎖、不派發 queue）；`--dry-run` 乾跑核對清單不寫入

### 接入通道與回調路徑宣告（HasAggregatorChannel／HasCallbackPath）

後台「遊戲商管理 › 廠商管理」要顯示每家遊戲商的接入通道（經哪一家聚合商接進來，或直連）與回調路徑。這兩件事只有 Driver 知道，所以由 Driver 自己宣告。聚合商是把多家遊戲商包成同一套 API 轉給我方的中間商（EliteHub、TitanHub、Top55SW、GMSW、target、PGSSW、DCT；EliteHub 就是代碼裡的 TTHSW）。

| 介面 | 定位 | 行為 |
|---|---|---|
| `App\Vendors\Contracts\HasAggregatorChannel` | 契約層（集成 Driver 實作） | 宣告 `public static function aggregatorChannel(): VendorChannelEnum`，回傳這家經由的聚合商。未實作即為直連，列表以字串 `direct` 表示（`direct` 不是 `VendorChannelEnum` 的值） |
| `App\Vendors\Contracts\HasCallbackPath` | 契約層（單錢包 Driver 實作） | 宣告 `public static function defaultCallbackPath(): string`，回傳 `/external/api/sw/<prefix>`。`vendors.callback_path` 有值用 DB，NULL 時用這個預設；轉帳錢包不實作 |

兩個方法都是 static。推導統一走 `App\Services\Vendor\VendorDriverCapabilityService`：它只讀 `config('vendor.driver')` 的 class 字串、用 `is_a($class, X::class, true)` 判斷有沒有實作，不實例化 Driver（實例化會連帶建構三方設定，列表不需要）。`config/vendor.php` 沒掛 Driver 的舊遊戲商，通道與預設回調路徑都回 null（代表 apis 無從得知），不會被當成直連。

使用規則：

- **全新聚合商** → 先在 `App\Enums\Vendor\VendorChannelEnum` 加 case（值用聚合商的對外名稱，例 `'EliteHub'`；case 宣告順序就是後台下拉的排列順序），再讓該聚合商的 Driver `implements HasAggregatorChannel`
- **既有聚合商加子遊戲商** → 只要把 vendor id 加進 `config/vendor.php` 該聚合商的 `array_fill_keys` 群組；子遊戲商共用同一支 Driver，接入通道自動繼承，不必另外宣告
- **直連** → 不實作 `HasAggregatorChannel`
- **單錢包** → 一律實作 `HasCallbackPath`，宣告值與路由前綴逐字一致（細節見 [single-wallet.md](single-wallet.md)「回調 Controller 骨架」）
- 🚫 **不要**用「同一支 Driver 掛幾個 vendor id」或 `vendors.third_party_code` 判斷是不是集成。反例兩個：WudiSW／FumaSW 的本體與免盤費（FREE）變體共用 Driver 也共用代碼，卻是直連；Titan 底下的 Connect93／Live22 各有獨立 Driver，卻屬 TitanHub
- 推導結果和運營對不上時，回頭改 Driver 的宣告；接入通道不開放後台人工覆寫

> 決策出處：APIs repo `docs/adr/0003-vendor-channel-and-callback-declared-by-driver.md`（target/tickets#4400）。

---

## 遊戲商整合文檔

接遊戲商時，於 **APIs repo** 建立整合文檔目錄（貼著程式碼維護，不在本 plugin）：

```
docs/integrations/vendors/<VendorName>/
├── api.md          # 廠商原文規格（廠商說什麼；API＋簽名＋錯誤碼，接入必建）
└── integration.md  # 一手踩坑心得（接入才知道、文件＋程式碼查不到的；有踩坑才建）
```

### 命名規則（重要）

`<VendorName>` **必須對齊程式碼識別名**，即 `app/Vendors/{VendorName}/` 目錄名（亦即 `config/vendor.php` 以 `VendorIdEnum::XXX->value` 註冊的 Driver 對應名），**不可用廠商對外品牌名**；單一錢包版本沿用 `SW` 後綴與轉帳錢包版本區分目錄。品牌名只寫進文檔標題與內文，不進目錄名。

| 廠商品牌 | Vendor 目錄名（＝整合文檔目錄名） |
|---|---|
| Pragmatic Play SW | `PPSW`（→ `vendors/PPSW/`） |
| Ace333 轉帳錢包 / 單一錢包 | `Ace333` 與 `Ace333SW` 分開兩個目錄 |

### 定位界線（一刀切）

| | `api.md` | `integration.md` |
|---|---|---|
| 回答 | **廠商說什麼** | **我方接入時踩到什麼** |
| 內容 | 廠商原文規格（API＋簽名＋錯誤碼），照搬不詮釋 | 文件與實作的已驗證落差、三方文件自身錯誤/矛盾、踩過的坑、實測確認的陷阱、維運注意 |
| source of truth | 廠商文件 | 接入經驗（無法從文件或程式碼推導） |
| 何時建 | 接入必建 | **有踩坑才建，沒有就不建** |

#### integration.md litmus test

寫進 `integration.md` 前先自問：**這條資訊，讀 `api.md` ＋ 讀 `Driver` / `Controller` 程式碼就能得到嗎？**

- **能得到** → ❌ 不要寫。程式碼是 source of truth，逆向整理的摘要只會 drift。
- **只有實際接入／踩坑／三方往返才知道** → ✅ 才寫。

| ❌ 不該進（逆向摘要，會 drift） | ✅ 該進（一手心得） |
|---|---|
| Driver ↔ API 方法對應表 | 文件寫 X 實測是 Y 的**已驗證**落差（附驗證方式） |
| `vendor_infos.info` 欄位對照表 | 三方文件自身的錯誤／過期欄位（附 incident／commit／工單／日期） |
| 純複述程式碼的狀態機／流程圖 | 實測確認的時區／簽名／正負號陷阱 |
| 純複述廠商規格的錯誤碼表 | 維運注意（維護期、注單保留期、對帳窗口）＋ code review 安全缺口 |

> 不建 per-vendor `test-cases.md`；測試依本 skill 的 `reference/testing.md` 寫在 `tests/` 下。

> **讀取規範（重要）**：接遊戲／金流商**沒有「對接卡」概念**，必讀的只有兩類文件：三方 API 文檔 `api.md`（廠商原文）＋ 對接心得 `integration.md`（我方接入時踩到、文件＋程式碼查不到的坑）；單錢包 vendor 另有階段性的 `acceptance.md`（由本 skill `reference/acceptance-contract.md` 複製而來的驗收清單），開工、開 MR、正式代理開啟前三個時點才讀。**任何需要參閱 `api.md` 的場景，必須先檢查同目錄是否有 `integration.md`；若存在，視為重點知識優先參閱**：它比廠商原文更貼近真實行為。接入過程中**踩到坑、或發現文件與實作的已驗證落差時**才補進對應的 `integration.md`（依上方 litmus test 判斷，不是「覺得重要」就寫）。

> **命名規範**：整合文檔一律於 `docs/integrations/vendors/<VendorName>/` 下，三方 API 文檔 `api.md` ＋ 對接心得 `integration.md`；單錢包 vendor 加驗收清單 `acceptance.md`。

現有對接心得：見 APIs repo `docs/integrations/vendors/*/integration.md`——**目錄本身即權威清單**，不在此處列舉以免 drift（上線步驟建檔後不需回頭更新本 skill）。
