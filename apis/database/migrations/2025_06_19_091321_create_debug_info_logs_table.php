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
        SchemaMonitor::create('debug_info_logs', function (Blueprint $table) {
            $table->comment('主要是提供前端紀錄一些debug log資訊');
            $table->increments('id')->comment('流水號');
            $table->string('category', 50)->nullable()->comment('分類');
            $table->longText('msg')->comment('紀錄');
            $table->string('memo_1', 500)->nullable()->comment('備註1');
            $table->string('memo_2', 500)->nullable()->comment('備註2');
            $table->bigInteger('number_value_1')->nullable()->comment('數字資訊1');
            $table->bigInteger('number_value_2')->nullable()->comment('數字資訊2');
            $table->string('user_ip', 100)->nullable()->comment('IP位址');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->string('user_identity', 50)->nullable()->comment('用戶識別資訊');
            $table->string('info_level', 50)->nullable()->comment('資訊等級');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('debug_info_logs');
    }
};
