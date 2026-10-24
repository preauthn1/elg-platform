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
        SchemaMonitor::create('agent_register_limits', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('流水號');
            $table->integer('agent_group_id')->comment('設置分組ID (agent_groups type=6)');
            $table->integer('currency_id')->default(0)->comment('幣別ID (currencies.id, 0=不分幣別)');
            $table->string('field_key', 50)->comment('限制欄位鍵 email/phone/ip_address/name/bank_account');
            $table->tinyInteger('platform_enabled')->default(0)->comment('整個平台上限開關 0:關 1:開');
            $table->integer('platform_max_limit')->nullable()->comment('整個平台最大限制值 (同值可被重複註冊上限)');
            $table->tinyInteger('custom_enabled')->default(0)->comment('自訂逐值上限開關 0:關 1:開');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');

            $table->unique(['agent_group_id', 'currency_id', 'field_key'], 'uk_group_currency_field');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_register_limits');
    }
};
