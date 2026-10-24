<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddUnlimitBonusreportAgentColumn0612 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('unlimit_bonusreport_agents', function (Blueprint $table) {
            $table->integer('status')
                ->after('bonus_amount')
                ->default(0)
                ->comment('狀態: 1000:success');
            $table->datetime('payout_time')
                ->after('status')
                ->nullable()
                ->comment('發放時間');
            $table->string('remark',255)
                ->after('payout_time')
                ->default('')
                ->comment('備註');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('unlimit_bonusreport_agents', function (Blueprint $table) {
            $table->dropColumn('status');
            $table->dropColumn('payout_time');
            $table->dropColumn('remark');
        });
    }
}
