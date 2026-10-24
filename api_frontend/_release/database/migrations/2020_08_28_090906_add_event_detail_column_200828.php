<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddEventDetailColumn200828 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('event_details', 'game_join_event_used'))
        {
            Schema::table('event_details', function (Blueprint $table) {
                $table->integer('game_join_event_used')
                    ->after('game_id')
                    ->default(1)
                    ->comment('是否使用games的join_bet_event / join_payout_event');
            });
        }

        if (!Schema::hasColumn('lucky_draws', 'event_detail_id'))
        {
            Schema::table('lucky_draws', function (Blueprint $table) {
                $table->integer('event_detail_id')
                    ->after('event_id')
                    ->default(0)
                    ->comment('event_details.id');
            });
        }

        if (Schema::hasColumn('lucky_draws', 'start_time'))
        {
            Schema::table('lucky_draws', function (Blueprint $table) {
                $table->dropColumn('start_time');
            });
        }
        if (Schema::hasColumn('lucky_draws', 'end_time'))
        {
            Schema::table('lucky_draws', function (Blueprint $table) {
                $table->dropColumn('end_time');
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
        if (Schema::hasColumn('event_details', 'game_event_used'))
        {
            Schema::table('event_details', function (Blueprint $table) {
                $table->dropColumn('game_join_event_used');
            });
        }

        if (Schema::hasColumn('lucky_draws', 'event_detail_id'))
        {
            Schema::table('lucky_draws', function (Blueprint $table) {
                $table->dropColumn('event_detail_id');
            });
        }

        if (!Schema::hasColumn('lucky_draws', 'start_time'))
        {
            Schema::table('lucky_draws', function (Blueprint $table) {
                $table->datetime('start_time')
                    ->after('no');
            });
        }
        if (!Schema::hasColumn('lucky_draws', 'end_time'))
        {
            Schema::table('lucky_draws', function (Blueprint $table) {
                $table->datetime('end_time')
                    ->after('start_time');
            });
        }
    }
}
