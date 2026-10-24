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
        SchemaMonitor::create('external_api_keys', function (Blueprint $table) {
            $table->comment('開放給外部使用的api key');
            $table->increments('id');
            $table->string('merchant_id', 191)->unique()->comment('帳號');
            $table->string('secret_key', 36)->comment('密鑰(可使用MYSQL select UUID() 隨機生成)');
            $table->text('agentIds')->nullable()->comment('開放代理ID, 使用 "," 區隔, 若包含*則代表可查詢所有代理線');
            $table->text('whitelist')->nullable()->comment('IP 白名單, 使用 "," 區隔, 若包含*則代表不限IP');
            $table->boolean('status')->default(false)->index()->comment('1:啟用, 0:停用');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('external_api_keys');
    }
};
