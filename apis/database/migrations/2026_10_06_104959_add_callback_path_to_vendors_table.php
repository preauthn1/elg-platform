<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// target/tickets#4400 廠商管理：無縫錢包回調路徑後綴可由後台覆寫；NULL 代表沿用 Driver 宣告的預設（HasCallbackPath）
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::table('vendors', function (Blueprint $table) {
            $table->string('callback_path', 191)->nullable()->after('whitelist')->comment('無縫錢包回調路徑後綴；NULL 代表用 Driver 預設');
        });

        // SchemaMonitor 靜默跳過防護：斷言欄位已生效，否則拋錯不讓 migration 被記為完成
        if (!Schema::hasColumn('vendors', 'callback_path')) {
            throw new RuntimeException('vendors.callback_path 未生效（SchemaMonitor 可能靜默跳過），中止');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('vendors', function (Blueprint $table) {
            $table->dropColumn('callback_path');
        });

        // 與 up 對稱：SchemaMonitor 靜默跳過時欄位仍在，拋錯不讓回滾被記為完成
        if (Schema::hasColumn('vendors', 'callback_path')) {
            throw new RuntimeException('vendors.callback_path 移除未生效（SchemaMonitor 可能靜默跳過），中止');
        }
    }
};
