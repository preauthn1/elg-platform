<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::table('agents', function (Blueprint $table) {
            $table->integer('member_field_setting_group_id')
                ->nullable()
                ->after('vendor_customize_group_id')
                ->comment('會員註冊/登入欄位設置組別ID (agent_groups type=6)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('agents', function (Blueprint $table) {
            $table->dropColumn('member_field_setting_group_id');
        });
    }
};
