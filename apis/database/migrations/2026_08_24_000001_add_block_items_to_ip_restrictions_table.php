<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * 風控IP限制追加阻擋項目（4096）：一筆規則可多選 註冊 / 登入 / 簡訊驗證。
 *
 * 三個獨立旗標欄位（非 JSON/bitmask），與風控會員限制 member_restriction_rules
 * 的 is_disabled / withdrawal_locked 同一套寫法，SQL 可直接條件過濾。
 *
 * 欄位一律 default 0（往後新增規則的項目由後台明確指定），
 * 既有資料在 up() 內回填 block_login=1——本功能原本就只擋登入，回填後行為不變。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('ip_restrictions', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('ip_restrictions', 'block_register')) {
                $table->tinyInteger('block_register')->default(0)->after('ip')->comment('阻擋項目-註冊 1:是 0:否');
            }
            if (!SchemaMonitor::hasColumn('ip_restrictions', 'block_login')) {
                $table->tinyInteger('block_login')->default(0)->after('block_register')->comment('阻擋項目-登入 1:是 0:否');
            }
            if (!SchemaMonitor::hasColumn('ip_restrictions', 'block_sms')) {
                $table->tinyInteger('block_sms')->default(0)->after('block_login')->comment('阻擋項目-簡訊驗證 1:是 0:否');
            }
        });

        // 既有規則語意＝禁止登入，回填後 enforcement 行為不變（新表無資料時為 no-op）
        DB::table('ip_restrictions')->update(['block_login' => 1]);
    }

    public function down(): void
    {
        SchemaMonitor::table('ip_restrictions', function (Blueprint $table) {
            foreach (['block_register', 'block_login', 'block_sms'] as $column) {
                if (SchemaMonitor::hasColumn('ip_restrictions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
