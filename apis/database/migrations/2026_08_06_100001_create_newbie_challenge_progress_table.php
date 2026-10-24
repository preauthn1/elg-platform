<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::create('newbie_challenge_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('config_id')->index()->comment('newbie_challenge_configs.id');
            $table->unsignedInteger('member_id')->unique()->comment('members.id，一人僅可參加一次');
            $table->tinyInteger('stage')->default(1)->index()->comment('狀態; 1:進行中, 2:待領取彩金, 3:彩金任務中, 4:彩金任務完成(整個活動終態), 6:已放棄；無「已失效」狀態，2 的 48hr 彩金領取窗口過期與否一律由 bonus_window_expire_at 即時判斷，不落地為狀態值');
            $table->decimal('activity_reward', 18, 4)->comment('領取的活動金快照');
            $table->unsignedInteger('challenge_flow_limit_id')->nullable()->default(null)->comment('flow_limits.id，第二階段挑戰流水鎖，目標/累計直接讀該筆flow_limit/reach');
            $table->decimal('unlocked_bonus_amount', 18, 4)->nullable()->default(null)->comment('挑戰完成後算出的可解鎖彩金(10~1888)');
            $table->unsignedInteger('bonus_flow_limit_id')->nullable()->default(null)->comment('flow_limits.id，第四階段彩金流水鎖，倒數計時後第一筆充值核銷彩金時建立');
            $table->timestamp('bonus_window_expire_at')->nullable()->default(null)->index()->comment('彩金領取資格48hr倒數到期時間');
            $table->unsignedInteger('deposit_order_id')->nullable()->default(null)->comment('orders.id，觸發彩金核銷的儲值訂單');
            $table->decimal('deposit_amount', 18, 4)->nullable()->default(null)->comment('觸發核銷的該筆儲值金額');
            $table->decimal('claimed_bonus_amount', 18, 4)->nullable()->default(null)->comment('核銷的彩金金額，流水跑完撥款後金額不變、只是撥入主錢包');
            $table->decimal('principal_total', 18, 4)->default(0)->comment('挑戰完成後累積入金，即時查orders表算總額；BONUS_COMPLETED後固定為撥款當下的歷史快照，純顯示用不建立流水鎖');

            $table->timestamp('joined_at')->nullable()->default(null)->comment('領取活動金時間');
            $table->timestamp('challenge_completed_at')->nullable()->default(null)->comment('挑戰流水達標時間');
            $table->timestamp('bonus_completed_at')->nullable()->default(null)->comment('彩金流水達標撥款時間');
            $table->timestamp('principal_unlocked_at')->nullable()->default(null)->comment('本金解鎖時間');
            $table->timestamp('completed_at')->nullable()->default(null)->comment('整體活動完成時間');
            $table->timestamp('quit_at')->nullable()->default(null)->comment('放棄活動時間');

            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'))->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('newbie_challenge_progress');
    }
};
