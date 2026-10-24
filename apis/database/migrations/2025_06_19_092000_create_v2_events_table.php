<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class CreateV2EventsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!SchemaMonitor::hasTable('event_v2')) {
            SchemaMonitor::create('event_v2', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name')->comment('活動名稱');
                $table->string('schedule')->nullable()->default(null)->comment('活動重製時間');
                $table->integer('game_type_id')->nullable()->default(null)->comment('活動遊戲類型ID');
                $table->string('handler_class')->nullable()->comment('處理該活動邏輯的類別');
                $table->timestamps();
            });

            DB::table('event_v2')->insert([
                ['name' => '註冊', 'schedule' => null, 'handler_class' => 'RegisterService'],
                ['name' => '每日登入', 'schedule' => '0 0 * * *', 'handler_class' => 'DailyFreeService'],
                ['name' => '每月儲值', 'schedule' => '0 0 1 * *', 'handler_class' => 'MonthlyDepositService'],
                ['name' => '每月儲值2', 'schedule' => '0 0 1 * *', 'handler_class' => 'MonthlyDeposit2Service'],
                // ['name' => '每日存送', 'schedule' => '0 0 * * *', 'handler_class' => 'DailyCheckinDepositBonusService'],
                // ['name' => 'Major Jackpot', 'schedule' => null, 'handler_class' => 'MajorJackpotService'],
                // ['name' => 'Grand Jackpot', 'schedule' => null, 'handler_class' => 'GrandJackpotService'],
                ['name' => '不限次儲值', 'schedule' => null, 'handler_class' => 'DepositService'],
                ['name' => '每日儲值', 'schedule' => '0 0 * * *', 'handler_class' => 'DailyDepositService'],
                ['name' => '每日區間儲值', 'schedule' => '0 0 * * *', 'handler_class' => 'DailyDepositRangeService'],
                ['name' => '每周累積儲值', 'schedule' => '0 0 * * 1', 'handler_class' => 'WeeklyDepositService'],
                ['name' => '每日儲值組合', 'schedule' => '0 0 * * *', 'handler_class' => 'DailyDepositComboService'],
                ['name' => '邀請好友', 'schedule' => null, 'handler_class' => 'InviteFriendService'],
                ['name' => '下線傭金', 'schedule' => null, 'handler_class' => 'DownlineCommissionService'],
            ]);
        }

        if (!SchemaMonitor::hasTable('event_v2_details')) {
            SchemaMonitor::create('event_v2_details', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('event_v2_id')->comment('活動ID')->index();
                $table->integer('agent_id')->comment('代理ID')->index();
                $table->string('condition_formula')->nullable()->default(null)->comment('領獎條件公式，例如 deposit >= 20 或 20 <= deposit <= 49.99');
                $table->json('condition_config')->nullable()->default(null)->comment('領獎條件客製化設定');
                $table->string('reward_formula')->nullable()->default(null)->comment('活動獎勵計算公式，例如 "deposit * 0.5"');
                $table->json('reward_config')->nullable()->default(null)->comment('活動獎勵計算客製化設定');
                $table->string('balance_formula')->nullable()->default(null)->comment('餘額限制的動態公式，例如 "(deposit + bonus) * 3"');
                $table->json('balance_config')->nullable()->default(null)->comment('餘額限制客製化設定');
                $table->string('flow_formula')->nullable()->default(null)->comment('流水限制的動態公式，例如 "(deposit + bonus) * 3"');
                $table->json('flow_config')->nullable()->default(null)->comment('流水限制客製化設定');
                $table->string('withdraw_formula')->nullable()->default(null)->comment('提款限額的動態公式，例如 "bonus * 3"');
                $table->json('withdraw_config')->nullable()->default(null)->comment('提款限額客製化設定');
                $table->timestamp('start_time')->nullable()->useCurrent(false)->comment('活動開始時間');
                $table->timestamp('end_time')->nullable()->useCurrent(false)->comment('活動結束時間');
                $table->boolean('status')->default(true)->comment('是否啟用該活動');
                $table->timestamps();
            });
        }

        if (!SchemaMonitor::hasTable('event_v2_detail_langs')) {
            SchemaMonitor::create('event_v2_detail_langs', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('event_v2_detail_id')->comment('活動詳細ID')->index();
                $table->integer('language_id')->comment('語系iD')->index();
                $table->string('name')->comment('活動名稱');
                $table->string('image')->nullable()->comment('活動圖片');
                $table->string('reward_name')->nullable()->comment('獎勵名稱');
                $table->longText('content')->nullable()->default(null)->comment('預設顯示活動內容');
                $table->timestamps();

            });
        }

        if (!Schema::hasTable('event_v2_user_joins')) {
            SchemaMonitor::create('event_v2_user_joins', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('event_v2_detail_id')->comment('活動詳細ID')->index();
                $table->integer('member_id')->comment('會員ID')->index();
                $table->integer('order_id')->default(0)->comment('order ID')->index();
                $table->decimal('reward', 18, 4)->default(0)->comment('獎勵金額');
                $table->decimal('balance_limit', 18, 4)->nullable()->default(null)->comment('餘額限制');
                $table->decimal('flow_limit', 18, 4)->nullable()->default(null)->comment('流水限制');
                $table->decimal('withdraw_limit', 18, 4)->nullable()->default(null)->comment('提款限額');
                $table->decimal('withdraw', 18, 4)->nullable()->default(null)->comment('提款金額');
                $table->string('ip', 20)->nullable()->comment('IP')->index();
                $table->boolean('status')->comment('1:參加2:完成3:放棄');
                $table->timestamps();
                $table->index('created_at');
            });
        }

        if (!Schema::hasTable('event_v2_invites')) {
            SchemaMonitor::create('event_v2_invites', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('event_v2_user_join_id')->comment('活動參加ID')->index();
                $table->integer('member_id')->comment('會員ID')->index();
                $table->timestamps();
            });
        }

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        SchemaMonitor::dropIfExists('event_v2');
        SchemaMonitor::dropIfExists('event_v2_details');
        SchemaMonitor::dropIfExists('event_v2_user_joins');
        SchemaMonitor::dropIfExists('event_v2_invites');
    }
}
