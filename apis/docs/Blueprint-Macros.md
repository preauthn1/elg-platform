# Blueprint Macros

自訂 `Illuminate\Database\Schema\Blueprint` macro，統一定義重複出現的欄位組合。
所有 macro 在 `app/Providers/MacroProvider.php` 的 `boot()` 內註冊。

## 使用限制

**Macro 的欄位定義一旦 commit 就不可變更。**

Migration 的本質是可重播的歷史紀錄——任何時間點、任何環境執行 `migrate:fresh` 都必須產生一致的 schema。Macro 若被修改，已跑過 migration 的舊環境不受影響，但新環境會長出不同的 schema，造成難以察覺的環境差異。

遵守規則：

- ✅ 新增欄位：另開 `ALTER TABLE` migration
- ✅ 需要不同預設值或新欄位組合：新增另一個 macro（如 `vendorAccountColumnsV2`）
- ❌ 直接修改現有 macro 的欄位定義、預設值、型別

---

## `timeColumns()`

加入標準時間戳欄位。

```php
$table->timeColumns();
```

**產生欄位：**

| 欄位 | 型別 | 說明 |
|---|---|---|
| `created_at` | `timestamp` | `DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `timestamp` | `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

> `vendorAccountColumns` 與 `vendorBetRecordColumns` 內部皆呼叫此 macro，一般不需單獨使用。非 vendor 的自訂資料表若想沿用相同時間欄位格式，可直接呼叫。

---

## `vendorAccountColumns()`

建立遊戲商玩家帳號表（`{tname}_accounts`）的標準欄位與索引。

```php
$table->vendorAccountColumns(
    usernameLength: 32,       // 預設
    uniqueUsername: false,    // 預設
    withSessionToken: [REDACTED],  // 預設
);
```

**固定欄位：**

| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | `increments` | 主鍵 |
| `vendor_info_id` | `integer` | 商戶編號 |
| `username` | `string` | 玩家帳號（長度由 `$usernameLength` 決定） |
| `member_id` | `integer` | 會員系統編號 |
| `admin` | `integer` | 管理員編號 |
| `status` | `tinyInteger` | 帳號狀態（`0`:關閉 `1`:正常） |
| `created_at` / `updated_at` | `timestamp` | 由 `timeColumns()` 產生 |

**固定索引：**

| 索引名 | 欄位 | 說明 |
|---|---|---|
| `idx_{tname}_bind_lookup` | `(vendor_info_id, member_id, status)` | 查詢已綁定帳號 |
| `idx_{tname}_pool_pick` | `(vendor_info_id, status, member_id, id)` | 帳號池分配（防 Deadlock） |

> `{tname}` 從資料表名自動推導：`kissh5sw_accounts` → `kissh5sw`。

**參數說明：**

| 參數 | 型別 | 預設 | 說明 |
|---|---|---|---|
| `$usernameLength` | `int` | `32` | `username` 欄位長度 |
| `$uniqueUsername` | `bool` | `false` | `true` → UNIQUE index；`false` → 一般 index |
| `$withSessionToken` | `bool` | `false` | `true` → 加 `session_token varchar(200) nullable` 與 `idx_{tname}_session_token` |

`status tinyInteger default 1` 固定包含，不可關閉。

**使用範例：**

```php
// 最常見：用全部預設
SchemaMonitor::create('xxx_accounts', function (Blueprint $table) {
    $table->vendorAccountColumns();
});

// username 全域唯一、帳號長度 50
SchemaMonitor::create('xxx_accounts', function (Blueprint $table) {
    $table->vendorAccountColumns(usernameLength: 50, uniqueUsername: true);
});

// 有 session_token（getGameUrl 每次更新 token 供回調查找玩家）
SchemaMonitor::create('xxx_accounts', function (Blueprint $table) {
    $table->vendorAccountColumns(withSessionToken: [REDACTED];
    // 追加遊戲商專屬欄位...
});
```

> **`withSessionToken` 注意**：欄名固定為 `session_token`。若遊戲商文件用其他名稱（`auth_token`、`player_token`），不要開此參數，手動宣告欄位與索引。

---

## `vendorBetRecordColumns()`

建立遊戲商注單表（`{tname}_bet_records`）的標準欄位與索引。

```php
$table->vendorBetRecordColumns(
    uniqueBetId: true,      // 預設
    withReferenceNo: true,  // 預設
);
```

**固定欄位：**

| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | `increments` | 主鍵 |
| `vendor_info_id` | `integer index` | 遊戲商設定編號 |
| `member_id` | `integer index` | 會員系統編號 |
| `username` | `string(50) index` | 玩家帳號 |
| `bet_id` | `string(100)` | 注單號（unique 或 index，由 `$uniqueBetId` 決定） |
| `game_code` | `string(50) index` | 遊戲代碼 |
| `bet` | `decimal(18,4)` | 下注金額 |
| `valid_bet` | `decimal(18,4)` | 有效投注 |
| `win` | `decimal(18,4)` | 派彩金額 |
| `win_lose` | `decimal(18,4)` | 輸贏金額 |
| `bonus` | `decimal(18,4)` | 活動獎金 |
| `jackpot` | `decimal(18,4)` | Jackpot 獎金 |
| `rolling_chip` | `decimal(18,4)` | 禮碼金額 |
| `bet_time` | `timestamp index` | 投注時間 |
| `payout_time` | `timestamp nullable index` | 派彩時間 |
| `status` | `tinyInteger index` | 注單狀態（`0`:建立中 `1`:已結算 `2`:取消/回滾） |
| `info` | `text nullable` | 原始請求資料 |
| `created_at` / `updated_at` | `timestamp` | 由 `timeColumns()` 產生 |

**參數說明：**

| 參數 | 型別 | 預設 | 說明 |
|---|---|---|---|
| `$uniqueBetId` | `bool` | `true` | `true` → `bet_id` UNIQUE；`false` → 一般 index（Round 層級設計時使用） |
| `$withReferenceNo` | `bool` | `true` | `true` → 加 `reference_no varchar(50) UNIQUE`；Round 層級（冪等鍵移至 `deal_records`）設 `false` |

**使用範例：**

```php
// Transaction 層級（預設，多數遊戲商）
SchemaMonitor::create('xxx_bet_records', function (Blueprint $table) {
    $table->vendorBetRecordColumns();
    // 追加遊戲商專屬欄位...
});

// Round 層級（1 round 多 transactionNo，有 deal_records 表）
SchemaMonitor::create('xxx_bet_records', function (Blueprint $table) {
    $table->vendorBetRecordColumns(uniqueBetId: false, withReferenceNo: false);
});
```

> **`reference_no` 命名規範**：欄名固定為 `reference_no`，不得保留遊戲商原始命名（`result_id`、`transaction_no` 等），於 Controller 中轉換後存入。
