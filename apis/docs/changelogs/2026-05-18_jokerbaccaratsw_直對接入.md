# JokerBaccaratSW 直對單錢包遊戲商接入

> 日期：2026-05-18
> Ticket：target/tickets#3081「[GFF][TP] joker baccarat 單錢包(直對)接入」
> 分支：`feature/jokerbaccaratsw-integration`
> 上線時程：2026-05-18 09:00 (GMT+8) Joker 服務重啟
> 影響範圍：`app/Enums/Vendor/`、`app/Vendors/JokerBaccaratSW/`、`app/Http/Controllers/External/SingleWallets/`、`database/data/vendors.php`、`database/migrations/`、`routes/external/sw.php`、`config/vendor.php`

## 摘要

新增 Joker Baccarat 直對單錢包接入（`JOKER_BACCARAT_SW=235`、tname=`jkbsw`），取代既有透過 PGSSW 聚合器路由的方式。原有 `JOKER_BACCARAT=177` 經 PegasusSW Driver 的路由維持不變，本次新增獨立 vendor、獨立資料表與 Controller，避免污染既有資料。

## 架構決策

- **獨立 vendor 而非取代既有路由**：沿用 `JOKER_BACCARAT=177` PGSSW 路由不動，新增 `JOKER_BACCARAT_SW=235` 作為直對 vendor；兩條路由獨立運作，不混用歷史資料
- **BR 採 round 層級彙總**：`jkbsw_bet_records.bet_id=roundID UNIQUE`，bet / win 在同 round 內以 `Common::numAdd` 累加；transaction 層細節下放給 `jkbsw_deal_records.reference_no=transactionNo UNIQUE`
- **Lobby 模式**：透過 `/merchant/game/open-lobby` 取得遊戲 URL，callback 不帶 `tableCode`，BR.game_code 統一寫入 `jkbsw`；6 桌（001~006）由玩家在 Joker 大廳自選
- **簽章機制**：3DES-ECB-PKCS7 僅用於 merchant API；seamless callback 不帶簽章，走 IP 白名單驗證

## Added

- **Enum / 配置**
  - `app/Enums/Vendor/VendorIdEnum.php`: 新增 `case JOKER_BACCARAT_SW = 235`
  - `database/data/vendors.php`: tname=`jkbsw`、third_party_code=`JKBSW`、is_single_wallet=1
  - `config/vendor.php`: 註冊 `JokerBaccaratSW\Driver`

- **資料表（migration `2026_05_18_162713_create_jkbsw_tables.php`）**

  | Table | 主要欄位 / UNIQUE |
  |---|---|
  | `jkbsw_accounts` | `username UNIQUE`（玩家帳號） |
  | `jkbsw_bet_records` | `bet_id UNIQUE`（= Joker roundID，round 層級彙總） |
  | `jkbsw_deal_records` | `reference_no UNIQUE`（= transactionNo，transaction 層細節） |

- **Driver / Trait（`app/Vendors/JokerBaccaratSW/`）**
  - `Driver.php`：實作 `createAccount`（§3.2 標準寫法）、`getGameUrl`（open-lobby）
  - `JokerBaccaratSWTrait.php`：3DES-ECB-PKCS7 簽章 + HTTP 共用邏輯
  - `VendorInfoDetail.php`：商戶設定欄位定義

- **Seamless Callback Controller / 路由**
  - `app/Http/Controllers/External/SingleWallets/JokerBaccaratSwController.php`
  - `routes/external/sw.php`: 新增 3 條 callback 路由
  - 設計重點：
    - INSERT-first 冪等：先 INSERT `jkbsw_deal_records`，碰到 UNIQUE 衝突即視為重送
    - `callAction` 集中前置驗證：account / round / 金額型別檢查統一處理
    - `DomainException` 觸發 rollback 並回對應錯誤碼
    - `handleWin` 改用 `create()` 而非 `createOrUpdate`（避免觸發 TBR service 的 `id ===` 分支取錯狀態，順手修正）

- **測試（29 tests, 195 assertions）**
  - `SignatureTest`（5）：3DES 簽章 round-trip / 邊界
  - `DriverTest`（6）：createAccount / getGameUrl / API 錯誤路徑
  - `CallbackTest`（14）：bet / win / cancel / refund / 冪等
  - `MultiBetTest`（4）：同 round 多筆 transaction 累加
  - 共用 `TestCase` 抽出 Http::fake / vendor seeding

## 新增 Skill 條目

- `.claude/skills/game-integration/SKILL.md` 新增：
  - §3.3.3 INSERT-first 冪等模式
  - §3.3.4 Controller 共通樣板（callAction 前置驗證 + DomainException rollback）
  - §4.1 username UNIQUE 約束
  - §4.2 round-level BR 彙總慣例
  - §4.4 Model 慣例（命名、relation、scope）

## 本次 commits

1. `feat(vendor): 新增 JokerBaccaratSW 直對單錢包遊戲商 enum 與配置`
2. `feat(vendor): 新增 jkbsw 三張資料表與 Model`
3. `feat(vendor): 實作 JokerBaccaratSW Driver 與 簽章/HTTP Trait`
4. `feat(vendor): 新增 JokerBaccaratSW Seamless callback Controller 與路由`
5. `skill(game-integration): 記錄 JokerBaccaratSW 接入過程整理的設計慣例`
6. `feat(vendor): 註冊 JokerBaccaratSW Driver 到 config/vendor.php`
7. `test(vendor): JokerBaccaratSW 完整單元 / Feature 測試（29 tests, 195 assertions）`

## Fixed（順手修）

- **`handleWin` TBR 狀態取錯**：原本誤用 `createOrUpdate` 觸發 TotalBetRecord service 內部 `id ===` 分支判斷失準；改用 `create()` 改走 `updateOrCreate(vendor_id, bet_record_id)` 正常路徑

## 後續事項

1. **RD-Studio-01 專案另起 PR**（不在本 repo）
   - 新增 `App\Services\SummaryBetRecords\JokerBaccaratSW`（`$timeField='payout_time'`）
   - `VendorEnum` 兩個常數
   - `config/summary_bet_record.php` 註冊
   - `VendorBetRecordRerun` 加 case
2. **正式環境上線前**
   - 填入正式商戶 `API_ID` / `API_TOKEN`
   - `vendor_infos.status` 由 0 切成 1
3. **IP 白名單雙向開通**
   - Joker callback 來源 IP 加入後台白名單
   - APIs outbound IP 通知 Joker 客服加白
4. **桌台級統計（如需）**：當前 BR.game_code 一律 `jkbsw`，若要分桌統計需透過 Joker 報表 API 補回 tableCode 對應

## 對開發者的影響

- 既有 `JOKER_BACCARAT=177` 走 PGSSW 路由不變，**不要**把 177 的歷史資料搬遷到 235
- 新增測試或 fixture 時，jkbsw 三張表獨立，不要 join 既有 pegasus 表
- BR 寫入請走 `bet_id=roundID` UNIQUE + `Common::numAdd` 累加，transaction 細節走 `jkbsw_deal_records`
