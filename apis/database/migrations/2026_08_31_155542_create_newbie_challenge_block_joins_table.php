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
        SchemaMonitor::create('newbie_challenge_block_joins', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('config_id')->index()->comment('newbie_challenge_configs.id');
            $table->unsignedInteger('member_id')->index()->comment('members.id');
            $table->unsignedInteger('last_block_record_id')->nullable()->default(null)->index()->comment('最新一筆阻擋紀錄id');
            $table->unsignedInteger('progress_id')->nullable()->default(null)->index()->comment('後台放行後、玩家真的 join 成功回填的 newbie_challenge_progress.id');
            $table->timestamp('allowed_time')->nullable()->default(null)->index()->comment('後台放行時間');
            $table->tinyInteger('status')->default(1)->comment('狀態；見 NewbieChallengeBlockJoinStatusEnum');

            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'))->index();
        });

        SchemaMonitor::create('newbie_challenge_block_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('block_join_id')->index()->comment('newbie_challenge_block_joins.id');
            $table->tinyInteger('block_type')->comment('阻擋維度；見 NewbieChallengeBlockTypeEnum(IP/手機/真實姓名)');
            $table->string('message')->comment('繁體中文錯誤訊息快照');
            $table->string('ip')->index()->comment('玩家當下IP');

            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'))->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('newbie_challenge_block_joins');
        SchemaMonitor::dropIfExists('newbie_challenge_block_records');
    }
};
