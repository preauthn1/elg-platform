<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddColumnsTradeNoTableIgsBetRecords extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('igs_bet_records', 'trade_no'))
        {
            Schema::table('igs_bet_records', function (Blueprint $table) {
                $table->renameColumn('game_id', 'trade_no');
                $table->unique(['user_id', 'trade_no'], 'uniqle_user_id_trade_no');
            });
        }
        if (Schema::hasColumn('igs_bet_records', 'amount_before'))
        {
            Schema::table('igs_bet_records', function (Blueprint $table) {
                $table->dropColumn('amount_before');
            });
        }
        if (!Schema::hasColumn('igs_bet_records', 'type'))
        {
            Schema::table('igs_bet_records', function (Blueprint $table) {
                $table->integer('type')->default(0)->comment('交易类型');
            });
        }
        if (!Schema::hasColumn('igs_bet_records', 'remark'))
        {
            Schema::table('igs_bet_records', function (Blueprint $table) {
                $table->string('remark')->comment('备注说明');
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
        if (Schema::hasColumn('igs_bet_records', 'trade_no'))
        {
            Schema::table('igs_bet_records', function (Blueprint $table) {
                $table->renameColumn('trade_no', 'game_id');
                $index_exists = collect(DB::select("SHOW INDEXES FROM igs_bet_records"))->pluck('Key_name')->contains('uniqle_user_id_trade_no');
                if ($index_exists) {
                    $table->dropUnique("uniqle_user_id_trade_no");
                }
            });
        }
        if (Schema::hasColumn('igs_bet_records', 'type'))
        {
            Schema::table('igs_bet_records', function (Blueprint $table) {
                $table->dropColumn('type');
            });
        }
        if (Schema::hasColumn('igs_bet_records', 'remark'))
        {
            Schema::table('igs_bet_records', function (Blueprint $table) {
                $table->dropColumn('remark');
            });
        }
    }
}
