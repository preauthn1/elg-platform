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
        SchemaMonitor::create('mega888_daily_wins', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->date('date')->index()->comment('日期');
            $table->integer('type')->index()->comment('類型，1:mega電子遊戲2:BG視訊');
            $table->string('loginId', 191)->index()->comment('玩家編號');
            $table->decimal('bet', 13, 7)->default(0)->comment('投注金額');
            $table->decimal('win', 13, 7)->default(0)->comment('贏得金額');
            $table->decimal('yieId')->comment('收益率');
            $table->string('userId', 191)->comment('玩家id');
            $table->string('name', 191)->comment('玩家暱稱');
            $table->string('memo', 191)->nullable()->comment('備註');
            $table->string('tel', 191)->nullable()->comment('電話');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');

            $table->unique(['date', 'type', 'loginId'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('mega888_daily_wins');
    }
};
