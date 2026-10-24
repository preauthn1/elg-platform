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
        SchemaMonitor::create('event_progressive_jackpot_user_join_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->comment('會員系統編號');
            $table->timestamp('summary_start_time')->nullable()->comment('統計時間，type=1(解鎖彩金) SP 寫入時用來避免同會員同遊戲類型同區間重複累加；type=2/3 由 PHP 端寫入，固定為 NULL');
            $table->integer('level')->default(0)->comment('彩金等級，對應 event_progressive_jackpot_settings.level')->index();
            $table->integer('type')->comment('類型 1:解鎖彩金, 2:領取彩金, 3:修改狀態');
            $table->integer('game_type')->default(0)->comment('遊戲類型');
            $table->decimal('rate', 10, 4)->default(0)->comment('投注轉換比例');
            $table->decimal('bet', 18, 4)->default(0)->comment('投注金額');
            $table->decimal('before_point', 18, 4)->default(0)->comment('異動前已解鎖金額');
            $table->decimal('after_point', 18, 4)->default(0)->comment('異動後已解鎖金額');
            $table->decimal('before_receive', 18, 4)->default(0)->comment('異動前已領取金額');
            $table->decimal('after_receive', 18, 4)->default(0)->comment('異動後已領取金額');
            $table->integer('before_status')->default(0)->comment('異動前狀態');
            $table->integer('after_status')->default(0)->comment('異動後狀態');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作者ID');

            $table->unique(['member_id', 'type', 'game_type', 'summary_start_time'], 'unique_member_type_game_type_summary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_progressive_jackpot_user_join_logs');
    }
};
