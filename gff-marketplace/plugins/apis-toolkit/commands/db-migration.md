---
description: 建立或更新 Laravel database migration，遵守 SchemaMonitor 與 APIs 專案的 migration 安全原則。
argument-hint: "<變更描述>"
disable-model-invocation: true
---

變更描述：$ARGUMENTS

---

## 職責與委派

- 本 command 是 migration 檔案結構、`SchemaMonitor`、欄位／索引安全原則與 Model 同步規則的唯一來源。
- 容器位置、白標解析、`DB_CONNECTION` 注入、環境標示與寫入確認，完整委派給 [`whitelabel-container-exec` skill](../skills/whitelabel-container-exec/SKILL.md)，本 command 不另定一套執行協議。

## 執行流程

1. 解析 `$ARGUMENTS`，確認目標資料表、欄位、型別、nullable/default、索引需求；資訊不足時先提問，**不要直接建立 migration**。
2. 確認既有 migration / model / table 命名慣例。
3. 建立 migration 檔（遵守下方 SchemaMonitor 與安全原則）。
4. 若需要，建立或更新 Model（雙向關聯）。
5. 依 `whitelabel-container-exec` 選定並標示目標環境，再執行 `php artisan migrate --pretend` 檢查 SQL。
6. 列出 pretend SQL 與風險；**未經使用者文字確認，不執行正式 `php artisan migrate`**。

---

## SchemaMonitor 優先原則

**所有 Migration 必須使用 `App\Facades\SchemaMonitor` 取代原生 `Schema`**：

- `SchemaMonitor::create($table, $callback)` — 建立新表，取代 `Schema::create()`
- `SchemaMonitor::table($table, $callback, $maxRows)` — 修改表結構，超過 `$maxRows`（預設 50 萬）列時自動中止
- `SchemaMonitor::dropIfExists($table, $maxRows)` — 刪除表，超過 `$maxRows` 列時自動中止

```php
<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('payment_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });

        // 修改既有大表（自訂安全上限 100 萬列）
        SchemaMonitor::table('orders', function (Blueprint $table) {
            $table->string('remark')->nullable()->after('amount');
        }, maxRows: 1_000_000);
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('payment_logs');
    }
};
```

## Migration 安全原則

**新增欄位**：必須提供 `default` 值或設為 `nullable`；金額欄位用 `DECIMAL(20,4) NOT NULL DEFAULT 0`；Enum 欄位用 `TINYINT NOT NULL DEFAULT 1` 加 COMMENT。

**刪除欄位**：禁止在同一個 Migration 同時新增＋刪除欄位；刪除必須是獨立的 Migration，在下一個 Release 才執行。

**建立索引**：複合索引的欄位順序為等值查詢欄位在前，範圍查詢欄位在後。

**資料回填**：大量資料回填禁止在 Migration 內用 `DB::table()->update()`，改用獨立的 Artisan Command 支援 `--dry-run` 與分批處理。

## Model 同步建立

Migration 完成後，**必須檢查每張涉及的資料表是否有對應的 Model**。若 `app/Models/{ModelName}.php` 不存在，則一併建立。

```php
class PaymentLog extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'status' => 'integer',
        ];
    }
}
```

根據 Migration 的外鍵推斷關聯，並**雙向補齊**（`belongsTo` + `hasMany`）。只宣告當下業務確實會用到的關聯。

## 驗證與正式執行

1. 先依 `whitelabel-container-exec` 解析目標環境並組裝容器命令；指定白標時必須注入對應 `DB_CONNECTION`，禁止修改 `.env`。
2. 先跑 `php artisan migrate --pretend`，回報目標環境與 SQL。
3. 正式 `php artisan migrate` 屬寫入命令；依該 skill 的確認格式取得文字確認後，才可在同一目標環境執行。
4. 若後續還需 `db:seed`，視為另一個寫入命令，必須再次列明環境、命令與影響並取得確認。

## 輸出清單

1. `database/migrations/{timestamp}_{migration_name}.php`
2. `app/Models/{ModelName}.php`（不存在時建立）
3. 對方 Model 補充關聯方法（若需雙向補齊）
4. （若有資料回填）`app/Console/Commands/{FeatureName}MigrateCommand.php`
