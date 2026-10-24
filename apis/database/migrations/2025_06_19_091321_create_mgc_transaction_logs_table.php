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
        SchemaMonitor::create('mgc_transaction_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('order_id', 191)->default('0')->unique()->comment('transaction_log.id');
            $table->string('trade_id', 191)->default('0')->unique()->comment('注单唯一Id, ThemeId');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->integer('type')->default(0)->comment('類別 0:轉回平台,1:轉入遊戲商');
            $table->string('username', 30)->comment('玩家帳號');
            $table->decimal('money', 18, 4)->default(0)->comment('轉帳金額');
            $table->integer('time')->default(0)->comment('已使用api的次數');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:未處理,1:已處理');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('mgc_transaction_logs');
    }
};
