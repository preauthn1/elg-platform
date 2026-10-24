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
        SchemaMonitor::create('agent_member_field_settings', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('流水號');
            $table->integer('agent_group_id')->comment('設置分組ID (agent_groups type=6)');
            $table->integer('currency_id')->default(0)->comment('幣別ID (currencies.id, 0=不分幣別)');
            $table->tinyInteger('page_type')->comment('頁面類型 1:註冊 2:登入');
            $table->string('field_key', 50)->comment('欄位鍵 (請參考 MemberFieldKeyEnum)');
            $table->tinyInteger('field_group')->default(1)->comment('層級/欄位組 (分步註冊的step)');
            $table->integer('sort')->default(0)->comment('顯示順序');
            $table->tinyInteger('is_required')->default(0)->comment('是否必填 0:否 1:是');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:停用 1:啟用');
            $table->json('extra_config')->nullable()->comment('欄位子設定JSON (密碼規則/長度/銀行類型等)');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');

            $table->unique(['agent_group_id', 'currency_id', 'page_type', 'field_key'], 'uk_group_currency_page_field');
            $table->index(['agent_group_id', 'status'], 'idx_group_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_member_field_settings');
    }
};
