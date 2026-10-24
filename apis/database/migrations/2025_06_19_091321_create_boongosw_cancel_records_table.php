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
        SchemaMonitor::create('boongosw_cancel_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('username', 50)->comment('boongosw_accounts.username');
            $table->bigInteger('bet_id')->default(0)->index()->comment('boongosw_bet_records.bet_id');
            $table->bigInteger('bet_record_id')->default(0)->index()->comment('boongosw_bet_records.id');
            $table->decimal('api_bet', 18, 4)->default(0)->comment('API回傳的bet');
            $table->decimal('api_win', 18, 4)->default(0)->comment('API回傳的win');
            $table->tinyInteger('status')->default(0)->comment('處理狀態 0:待處理,1:成功,2:失敗');
            $table->text('response_data')->nullable()->comment('response data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('boongosw_cancel_records');
    }
};
