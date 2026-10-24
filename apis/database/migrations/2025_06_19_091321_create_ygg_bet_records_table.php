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
        SchemaMonitor::create('ygg_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('record_id')->index()->comment('投注記錄流水號');
            $table->string('reference', 32)->index()->comment('投注記錄編號');
            $table->string('topOrg', 32)->nullable()->comment('TopOrg帳戶');
            $table->string('org', 32)->nullable()->comment('Org組織帳戶');
            $table->string('loginname', 32)->nullable()->index()->comment('玩家帳號');
            $table->string('currency', 10)->nullable()->comment('幣種');
            $table->string('type', 32)->nullable()->index()->comment('類型');
            $table->decimal('amount', 18, 4)->nullable()->comment('投注金額');
            $table->decimal('beforeAmount', 18, 4)->nullable()->comment('投注前餘額');
            $table->decimal('afterAmount', 18, 4)->nullable()->comment('投注後餘額');
            $table->decimal('result', 18, 4)->nullable()->comment('輸贏');
            $table->string('gameName', 50)->nullable()->index()->comment('遊戲名稱');
            $table->string('DCGameID', 20)->nullable()->index()->comment('遊戲ID');
            $table->dateTime('createTime')->index()->comment('投注時間');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
            $table->boolean('is_endround')->nullable()->default(false);

            $table->unique(['reference', 'type'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('ygg_bet_records');
    }
};
