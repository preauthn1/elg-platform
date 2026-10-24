# 資料表設計

> 這份管遊戲商專屬資料表（`{tname}_accounts`、`{tname}_bet_records`、`{tname}_deal_records`）的必要欄位、索引、打賞類扣款怎麼存，以及 Model 慣例。設計 migration 或建 Model 時讀。

每個遊戲商至少需要兩張資料表：`{tname}_accounts`（遊戲帳號）與 `{tname}_bet_records`（投注紀錄）。

> **職責邊界**：本節只定義遊戲商專屬 schema（表名、必要欄位與索引）。Migration 的通用結構與安全原則以 `/db-migration` command 為準；容器、白標環境與 `migrate` / `db:seed` 寫入確認以 `whitelabel-container-exec` skill 為準，不在本 skill 重定義。

> **🚫 不要使用 `vendorAccountColumns` / `vendorBetRecordColumns` macro**（`MacroProvider` 內，只給既有 vendor 用，新 vendor 不用）。macro 的索引設計冗餘：accounts 的單欄索引與複合索引前導欄重複、`idx_*_pool_pick` 整顆多餘（理由見本檔「{tname}_accounts 必要欄位與索引」）；bet_records 對 8 個欄位全上單欄索引，熱表寫入放大且多數用不到。新 vendor 一律**逐行明列欄位**、**索引依實際查詢路徑收斂**、**沒用到的欄位不建**。藍本：APIs `database/migrations/2026_08_31_000001_create_aigamingsw_accounts_table.php` 與 `..._000002_create_aigamingsw_bet_records_table.php`。

> **資料表命名規則**：`xxx` 必須使用 `database/data/vendors.php` 中對應遊戲商的 `tname` 欄位值，而非遊戲商顯示名稱或其他衍生命名。例如 `tname = 'rectanglesw'` → 資料表名為 `rectanglesw_accounts` 與 `rectanglesw_bet_records`。Model 的 `$table` 屬性必須與 migration 建立的資料表名稱一致。

## {tname}_accounts 必要欄位與索引

必須包含以下欄位，並建立對應索引：

| 欄位 | 說明 |
|------|------|
| `vendor_info_id` | 遊戲商設定編號 |
| `member_id` | 會員系統編號 |
| `username` | 玩家帳號 |
| `status` | 狀態 0:關閉, 1:正常（活動系統透過 status=0 注銷帳號） |

> **注意**：系統有一隻 `add_status_column_to_vendor_account` 補救 migration，會自動為缺少 `status` 的舊版 `xxx_accounts` 補欄位。新建資料表**不應依賴此機制**，`status` 必須在原始 migration 中就包含。

### 額外欄位設計原則

`{tname}_accounts` 的必要欄位只有上表四個。其他欄位**依 `api.md` 決定**，判斷依據：

1. **建立帳號 API 的回傳值**：若 create player API 回傳的參數（如 token、player_id、secret）在後續 `getGameUrl` 或回調中需要用到，就建對應欄位儲存。
2. **`getGameUrl` 的需求**：若進入遊戲時需要寫入或讀取某個值（如每次產生新的 session token 供三方驗證 session），就建對應欄位。

> 欄位命名跟著 `api.md` 語意走，不強制使用特定名稱（`session_token`、`player_token`、`third_party_id` 等視情況而定）。有以這些欄位作為查詢條件時，必須建立對應索引。
>
> 🚫 **會輪換的 token 只能用在「驗證 session」類端點，不可當動錢回調的帳號查詢鍵**（理由與正確作法見 [single-wallet.md](single-wallet.md)「建立遊戲帳號（createAccount）」的紅線）。要當查詢鍵的必須是不可變識別（注單上的 `vendor_username`、三方 player_id）。

標準寫法（逐行明列；防 Deadlock 背景詳見 [config-and-tools.md](config-and-tools.md)「遊戲帳號綁定並發規範」）：

```php
SchemaMonitor::create('xxx_accounts', function (Blueprint $table) {
    $table->increments('id');
    $table->integer('vendor_info_id')->default(0)->comment('商戶編號');
    $table->string('username', 50)->unique()->comment('玩家帳號');   // 應否 UNIQUE 見下方說明
    $table->integer('member_id')->default(0)->comment('會員系統編號');
    $table->integer('admin')->default(0)->comment('管理員編號');
    $table->tinyInteger('status')->default(1)->comment('狀態 0:關閉,1:正常');
    $table->timestamp('created_at')->useCurrent()->comment('建立日期');
    $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

    // 一顆通吃：綁定 Query 1 已綁定查詢、Query 2 帳號池分配（member_id=0，ORDER BY id 走
    // InnoDB 隱含 PK 尾綴）、帳號池補貨計數、客服依會員反查（member_id 領頭前綴）
    $table->index(['member_id', 'vendor_info_id', 'status'], 'idx_xxx_bind_lookup');

    // 加遊戲商專屬欄位（session_token 等三方驗證用欄位自行宣告欄位＋索引）
    // ...
});
```

索引收斂原則（accounts）：

- 綁定流程的 Query 1（已綁定查詢：`vendor_info_id + member_id + status` 等值）與 Query 2（帳號池分配：`member_id=0` 等值 + `ORDER BY id` + `FOR UPDATE SKIP LOCKED`）是**同三欄純等值**——`(member_id, vendor_info_id, status)` 一顆通吃；`member_id` 領頭讓「依會員反查遊戲帳號」也吃前綴。
- **不要建 `idx_*_pool_pick (vendor_info_id, status, member_id, id)`**（舊 macro 遺留）：全等值下欄位順序不影響可用性，與 bind_lookup 整顆重複；尾掛的 `id` 亦無效果——InnoDB 二級索引隱含 PK 尾綴，`ORDER BY id` 天然走索引序（已以 EXPLAIN 驗證無 filesort）。
- 不另建 `vendor_info_id` / `member_id` 單欄索引（前者被複合索引覆蓋、後者由複合索引前導欄覆蓋）。

### `username` 應否 UNIQUE

多數 vendor 的 playerID 在他們系統內全域唯一（用 portal prefix 區隔多代理），本地 `username` 應對應設 `UNIQUE`：

- 阻擋並發 createAccount race 產生重覆 row
- 阻擋人工 SQL 誤插入
- 對齊「local username = 三方 playerID」1-to-1 關係

例外：若 vendor 允許不同代理 / 不同 vendor_info 共用同一 playerID（罕見，通常是聚合器型 SW），改用 `(vendor_info_id, username)` 複合 UNIQUE。確認方式：讀 `api.md`「playerID prefix 邏輯」或實際呼叫 register API 同 playerID 兩次看是否被拒。

## {tname}_bet_records 必要欄位與索引

欄位分兩類：**固定必建**與**依 vendor 回調決定**（沒用到就不建，不要「以防萬一」先建）：

| 欄位 | 建立時機 | 說明 |
|------|------|------|
| `vendor_info_id` / `member_id` / `username` | 必建 | 歸戶 |
| `bet_id` | 必建 | 注單唯一 ID（**UNIQUE，冪等鍵**）。**標準化欄位名**：三方唯一交易識別碼原始命名不一（`ticketNum` / `transactionId` / `log_id` / `orderNo`…），一律於 Controller 轉存此欄，不得保留原始命名另開欄位 |
| `game_code` | 必建 | 遊戲代碼 |
| `bet` / `valid_bet` / `win` / `win_lose` / `rolling_chip` | 必建 | 金額欄（`rolling_chip` 由 `changeBalance` 自動維護） |
| `bet_time` / `payout_time` | 必建 | 投注 / 派彩時間（`payout_time` nullable） |
| `status` | 必建 | `tinyInteger default 0`：0 建立中 / 1 已結算 / 2 取消回滾 |
| `info` | 必建 | `text nullable`，原始請求資料 |
| `reference_no` | **需要跨筆關聯時才建**（同回合多筆交易的回合號、或派彩筆回存投注筆的 `bet_id`） | 無程式作用，純粹方便 RD debug 目視關聯，**不建索引**；單筆制 vendor 不建此欄，TBR.reference_no 由 `getBetId()` fallback 自動帶入 |
| `bonus` | **vendor 有活動獎金／純紅利回調才建** | 例：cashBonus 類純派彩 |
| `jackpot` | **vendor 有 jackpot 回調才建** | |
| `is_tip` / `bet_type` | **vendor 支援打賞 / 道具購買才建**（詳見本檔「打賞 / Tip 等『不計有效投注』的特殊扣款」） | 與一般注單區分的旗標 |

> **不建 `bonus` / `jackpot` 時**：Model 端對應的 `$bonusColumn` / `$jackpotColumn` 仍須**顯式宣告為 `null`**（getter 回 `'0'`，`changeBalance` 公式安全）——`BetRecordColumnParityTest` 強制每支 BetRecord model 自行宣告全部欄位映射，繼承基底預設會紅。

標準寫法（逐行明列，以 AiGamingSW 為藍本）：

```php
SchemaMonitor::create('xxx_bet_records', function (Blueprint $table) {
    $table->increments('id');
    $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
    $table->integer('member_id')->default(0)->comment('會員系統編號');
    $table->string('username', 50)->comment('玩家帳號');
    $table->string('bet_id', 100)->unique()->comment('注單號（三方 transactionId），冪等鍵');
    $table->string('game_code', 50)->comment('遊戲代碼');
    $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
    $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效投注');
    $table->decimal('win', 18, 4)->default(0)->comment('派彩金額');
    $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
    $table->decimal('rolling_chip', 18, 4)->default(0)->comment('禮碼金額');
    $table->timestamp('bet_time')->comment('投注時間');
    $table->timestamp('payout_time')->nullable()->comment('派彩時間');
    $table->tinyInteger('status')->default(0)->comment('注單狀態 0:建立中 1:已結算 2:取消/回滾');
    $table->text('info')->nullable()->comment('原始請求資料');
    $table->timestamp('created_at')->useCurrent()->comment('建立日期');
    $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

    // 對帳三表 SUM 對齊以 payout_time 切窗（time_field=payout_time）
    $table->index('payout_time', 'idx_xxx_br_payout_time');
    // 特殊情況以投注時間切窗查單（如未結算單排查）
    $table->index('bet_time', 'idx_xxx_br_bet_time');
});
```

索引收斂原則（bet_records，熱表寫入放大敏感）：

- 基準三顆：**冪等鍵 UNIQUE**（`bet_id`；Round 層級的冪等鍵移至 `deal_records.reference_no`）＋ **`payout_time`**（對帳三表切窗）＋ **`bet_time`**（特殊情況查單）。
- **不建** `username` / `game_code` / `status` / `vendor_info_id` 單欄索引，也**不建** `(member_id, bet_time)` 之類的會員維度索引——後台不查 vendor BR，會員維度報表走 TBR / SBR；status 基數過低；單 vendor 專屬表毋須 vendor_info_id 過濾。實際多出的查詢路徑出現時再補。

> **注意**：標準化欄位名是 `bet_id`（見上表），**不是** `reference_no`。`reference_no` 是 debug 回連結欄——**無程式作用，只是方便 RD 目視關聯**（存回合號、或派彩筆回存投注筆的 `bet_id`）。⚠️ **DstPlaySW 把兩欄存反了**（`bet_id` 存回合號變 non-unique、`reference_no` 存唯一交易號當冪等鍵），屬**錯誤示範勿仿效**；既有 vendor 不回頭改**欄位**，但 PPSW 起頭的這批歷史樣板（PPSW / NEX4D / KISSH5 / RECTANGLE / PXPLAY / RGSW / MEGAH5 / METAGAMING / DSTPLAY / SLOT4D 共 10 家）的 Model `$betIdColumn` 已改指向各表真正的唯一鍵 `reference_no`，否則 `getBetId()` 回傳非唯一值、餵給 `TBR.reference_no` 與 log 會有歧義（`6659e7a91`）。**新 vendor 一律唯一交易號進 `bet_id`，`$betIdColumn` 也就指 `bet_id`。**
>
> **EpicWinSW 等舊版實作缺少 `vendor_info_id`、`member_id`、`win` 等欄位，屬於歷史遺留問題，不應作為新實作的參考依據。**

### Transaction 層級 vs Round 層級 BR

依 vendor 的 roundID / transactionNo 基數，選一種：

| 設計 | bet_id（UNIQUE 冪等鍵） | reference_no | `{tname}_deal_records` 表 | 適用場景 |
|---|---|---|---|---|
| **Transaction 層級**（預設） | 三方唯一交易號（transactionNo / log_id…） | vendor 另有回合號才建：存回合號（debug 回連結、不建索引）；無回合號不建（如 AiGamingSW） | 不需要 | 每筆交易一列 BR 的 vendor（多數 RNG slot；含同回合多筆交易各自成列者） |
| **Round 層級彙總** | roundID（一回合一筆 BR，金額累加） | 不建 | **必須**：交易號存 `deal_records.reference_no`（UNIQUE 冪等鍵） | 一 round 多 transactionNo 且以回合彙總的 vendor（Live Baccarat / Roulette / 多碼下注） |

採 round 層級時：
- BR 以 roundID 為唯一鍵，bet / valid_bet / win 用 `Common::numAdd` 累加（[single-wallet.md](single-wallet.md)「同 round 多次 bet 必須累加，禁止覆寫（重要）」）
- 同 round 多個 transactionNo 各自寫一筆 `{tname}_deal_records`，由該表持有 `reference_no UNIQUE`
- 冪等防線轉移到 deal_records 層，配合 [single-wallet.md](single-wallet.md)「INSERT-first 冪等模式」

`{tname}_deal_records` 表必要欄位：

```php
$table->increments('id');
$table->integer('vendor_info_id')->default(0);
$table->integer('member_id')->default(0);
$table->string('username', 100);
$table->unsignedInteger('bet_record_id')->nullable()->comment('FK xxx_bet_records.id；rollback 無對應 BR 時可 nullable');
$table->string('bet_id', 100)->comment('vendor roundID');
$table->string('reference_no', 100)->unique()->comment('vendor transactionNo — 冪等鍵');
$table->tinyInteger('action')->comment('vendor action / event code');
$table->decimal('amount', 18, 4)->default(0);
$table->decimal('balance_before', 18, 4)->nullable();
$table->decimal('balance_after', 18, 4)->nullable();
$table->json('info')->nullable()->comment('原始 callback payload');
$table->timestamps();

$table->index(['vendor_info_id', 'member_id', 'username', 'bet_id', 'bet_record_id', 'action', 'created_at']);
```

> 注意：此 `{tname}_deal_records` 是 **vendor 專屬**的審計表，與全域 `deal_records`（錢包異動紀錄）**完全不同**，不要混淆。

## 打賞 / Tip 等「不計有效投注」的特殊扣款

部分遊戲商（直播、捕魚、棋牌類常見）提供**打賞 / 小費 / 道具購買**等功能，特性如下：

- 玩家錢包**真的會被扣款**（須產生 `deal_records` 並影響餘額）
- 遊戲商**不視為投注**（不出現在派彩流程，無對應 `win` / `valid_bet`）
- 因此**不應寫入** `total_bet_records`，避免被計入有效投注、流水、返水、活動結算

### 處理規則

| 項目 | 一般投注 (bet/result) | 打賞 / Tip |
|---|---|---|
| `deal_records` | ✅ 建立（扣款／派彩） | ✅ 建立（純扣款） |
| `xxx_bet_records` | ✅ 建立 | ⚠️ **依遊戲商定義決定**（見下） |
| `total_bet_records` | ✅ 建立（時機依 [single-wallet.md](single-wallet.md)「total_bet_record 建立時機」準則：預設結算才建） | ❌ **絕對不建立** |
| `summary_bet_records` | ✅ 由 `artisan summary_bet_record` 命令彙總 | ❌ 不應出現 |
| 影響玩家餘額 | ✅ | ✅ |
| 計入有效投注 / 流水 | ✅ | ❌ |
| 計入返水 / 活動結算 | ✅ | ❌ |

### `xxx_bet_records` 是否寫入？

依遊戲商回調語意決定，兩種設計擇一即可，但**必須能與一般投注明確區分**：

1. **不寫入 BR，只寫 deal_records**（推薦）
   - 適用打賞屬於「純消費／非博弈」語意的遊戲商
   - `deal_records` 用獨立的 `type`（例如 `tip`、`gift`、`purchase`）標記
2. **寫入 BR 但加旗標**
   - 若遊戲商把打賞與一般注單放同一支回調，難以在收件時就區分
   - BR 增加 `is_tip` / `bet_type` 欄位，**TBR 寫入路徑必須過濾掉打賞**
   - `artisan summary_bet_record` 命令亦需過濾（避免被計入小時彙總）

### Controller 實作要點

```php
public function bet(Request $request)
{
    // 1. 帳號狀態檢查（見 single-wallet.md「投注前檢核」）
    if ($this->account->status == 0) { return $this->errorResponse(...); }

    DB::transaction(function () use ($request) {
        // 2. 扣款（一律走 deal_records）
        $dealType = $this->isTipPayload($request) ? DealTypeEnum::TIP : DealTypeEnum::BET;
        $this->createDealRecord($dealType, $amount);

        // 3. 寫入投注紀錄（若採設計 1 則跳過）
        if (! $this->isTipPayload($request)) {
            $this->createBetRecord(...);
            // 注意：TBR 建立時機依 single-wallet.md「total_bet_record 建立時機」準則（預設結算才建）
        }
    });
}
```

### 防呆檢查清單

- [ ] 打賞是否會被任何路徑寫進 `total_bet_records`？（grep `TotalBetRecordService::create` 的呼叫點是否漏濾）
- [ ] `artisan summary_bet_record` 命令的來源 query 是否過濾打賞？
- [ ] 退款 / 對帳工具（如 `xxx:fix-records`）是否誤把打賞當作 stuck BR 補寫 TBR？
- [ ] 對帳 SOP 內三表合計是否需排除打賞口徑（建議在 BR 加 `is_tip` 欄位時，所有彙總 SQL 都加上 `AND is_tip = 0`）

> **規則底線**：玩家錢包扣款 ≠ 有效投注。`deal_records` 紀錄資金流動，`total_bet_records` 紀錄博弈行為，兩者語意不同，打賞只屬前者。

## Model 慣例

新建的 `{Tname}Account` / `{Tname}BetRecord` / `{Tname}DealRecord` Model 必須在 class docblock 加上 `@mixin \Eloquent` 與欄位 `@property` 聲明：

```php
/**
 * @property int $id
 * @property int $vendor_info_id
 * @property int $member_id
 * @property string $username
 * @property int $status
 *
 * @mixin \Eloquent
 */
class XxxAccount extends AbstractAccount
{
    protected $table = 'xxx_accounts';
}
```

- `@mixin \Eloquent` 讓 IDE 認得 `where()` / `create()` / `first()` 等 Eloquent 動態方法，不再噴「Method not found」誤報
- `@property` 聲明讓 IDE 能對欄位存取做自動完成與型別推斷
- AFBSW / RectangleSwAccount 等既有 Model 範例都是此格式
