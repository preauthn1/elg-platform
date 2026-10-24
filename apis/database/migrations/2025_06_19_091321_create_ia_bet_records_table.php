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
        SchemaMonitor::create('ia_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->bigInteger('rowID')->comment('唯一id');
            $table->tinyInteger('activity_id')->comment('活動id');
            $table->decimal('betamount', 18, 4)->comment('投注金額');
            $table->decimal('winamount', 18, 4)->comment('贏錢金額');
            $table->tinyInteger('money_type')->comment('金錢類型，1=>現金，2=>虛擬幣');
            $table->tinyInteger('currency_id')->comment('幣種id');
            $table->decimal('exchange_rate', 18, 7)->comment('換人民幣匯率');
            $table->tinyInteger('show_scale')->comment('幣種顯示縮放比例');
            $table->string('desc', 191)->comment('描述');
            $table->dateTime('create_time')->comment('生成時間');
            $table->string('username', 20)->index()->comment('用戶名');
            $table->string('userip', 50)->comment('下注ip');
            $table->string('cdnip', 50)->comment('下注的cdnip');
            $table->integer('client_type')->index()->comment('客戶使用的客戶端 1-pc 2-wap 3-app');
            $table->string('order_id', 50)->comment('活動注單號');
            $table->string('activity_name', 50)->index()->comment('活動名稱');
            $table->dateTime('utc_create_time')->nullable()->index()->comment('遊戲時間(UTC+0)');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('ia_bet_records');
    }
};
