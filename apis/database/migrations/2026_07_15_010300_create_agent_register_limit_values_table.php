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
        SchemaMonitor::create('agent_register_limit_values', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('流水號');
            $table->integer('register_limit_id')->comment('所屬註冊限制ID (agent_register_limits.id)');
            $table->string('value', 191)->comment('限制的具體值 (email/phone/ip等)');
            $table->integer('max_limit')->comment('此值的最高限額 (可被重複註冊上限)');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            $table->index(['register_limit_id', 'value'], 'idx_limit_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_register_limit_values');
    }
};
