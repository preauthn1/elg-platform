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
        SchemaMonitor::create('livechat_bots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('agent_id')->index()->comment('綁定的代理 ID');
            $table->string('name', 50)->unique()->comment('Bot 識別名稱（如 default、brand_a）');
            $table->string('api_key', 64)->unique()->comment('Bot API key（明文）');
            $table->string('vip_url', 255)->nullable()->comment('該 bot 的 VIP 頁面 URL');
            $table->boolean('is_active')->default(true)->comment('啟用旗標');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('livechat_bots');
    }
};
