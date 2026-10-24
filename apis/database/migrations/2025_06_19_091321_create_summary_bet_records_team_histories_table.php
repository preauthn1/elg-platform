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
        SchemaMonitor::create('summary_bet_records_team_histories', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('流水號');
            $table->dateTime('summary_time')->index()->comment('時間(日)');
            $table->unsignedInteger('member_id')->index()->comment('會員系統編號');
            $table->unsignedInteger('vendor_id')->index()->comment('遊戲商系統編號');
            $table->string('game_id', 191)->nullable()->index()->comment('遊戲系統編號');
            $table->decimal('bet', 18, 4)->default(0)->comment('投注金額-自己');
            $table->decimal('bet_direct', 18, 4)->default(0)->comment('投注金額-直屬');
            $table->decimal('bet_team', 18, 4)->default(0)->comment('投注金額-團隊');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('贏得金額-自己');
            $table->decimal('win_lose_direct', 18, 4)->default(0)->comment('贏得金額-直屬');
            $table->decimal('win_lose_team', 18, 4)->default(0)->comment('贏得金額-團隊');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
            $table->integer('admin')->default(0)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('summary_bet_records_team_histories');
    }
};
