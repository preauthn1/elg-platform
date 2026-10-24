<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSummaryBetRecordsTeamTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('summary_bet_records_team', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->datetime('summary_time')->comment('時間(日)');
            $table->integer('member_id')
                ->unsigned()
                ->comment('會員系統編號');
            $table->integer('vendor_id')
                ->unsigned()
                ->comment('遊戲商系統編號');
            $table->string('game_id', 191)
                ->nullable()
                ->comment('遊戲系統編號');
            $table->decimal('bet', 18, 4)->default(0)->comment('投注金額-自己');
            $table->decimal('bet_direct', 18, 4)->default(0)->comment('投注金額-直屬');
            $table->decimal('bet_team', 18, 4)->default(0)->comment('投注金額-團隊');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('贏得金額-自己');
            $table->decimal('win_lose_direct', 18, 4)->default(0)->comment('贏得金額-直屬');
            $table->decimal('win_lose_team', 18, 4)->default(0)->comment('贏得金額-團隊');
            $table->timestamps();
            $table->integer('admin')->default(0)->comment('更新者ID');

            //index
            $table->index('member_id');
            $table->index('summary_time');

            $table->index('vendor_id');
            $table->index('game_id');
        });
       

		$procedureName = "SP_summary_bet_record_team";
		$procedure = "CREATE DEFINER=`".env('DB_USERNAME_READ')."`@`%` PROCEDURE `".$procedureName."`()
BEGIN
	DECLARE MaxLevel int;
    DECLARE StartTime datetime;
    DECLARE EndTime datetime;
    DECLARE AdminUser int;
    SET AdminUser = 0;
    SET StartTime = DATE_ADD(CURDATE(), interval HOUR(NOW()) HOUR);
    SET EndTime = DATE_ADD(StartTime, interval 1 HOUR);
    SET MaxLevel = (SELECT MAX(agent_level) FROM ".env('DB_DATABASE').".member_agent);

    WHILE MaxLevel > 0 
    DO
        INSERT INTO ".env('DB_DATABASE').".summary_bet_records_team(summary_time,member_id,vendor_id,game_id,bet,bet_direct,bet_team
		,win_lose,win_lose_direct,win_lose_team,admin)
		SELECT StartTime,D.member_id,D.vendor_id,D.game_id
		,SUM(D.bet) AS bet
		,SUM(D.bet_direct) AS bet_directbet
		,SUM(D.bet_team) AS bet_team
		,SUM(D.win_lose) AS win_lose
		,SUM(D.win_lose_direct) AS win_lose_direct
		,SUM(D.win_lose_team) AS win_lose_team
        ,AdminUser
		FROM
		(
			SELECT MA.member_id,B.vendor_id,B.game_id
			,sum(B.bet) AS bet
			,sum(B.win_lose) AS win_lose
			,0 AS bet_direct
			,0 AS win_lose_direct
			,0 AS bet_team
			,0 AS win_lose_team
			FROM ".env('DB_DATABASE').".member_agent AS MA
			JOIN ".env('DB_DATABASE').".summary_bet_records AS B ON B.member_id = MA.member_id
			WHERE MA.agent_level = MaxLevel
            AND B.summary_time >= StartTime AND B.summary_time < EndTime
			GROUP BY MA.member_id,B.vendor_id,B.game_id
			UNION ALL
			SELECT MA.topper_member_id AS member_id,B.vendor_id,B.game_id
			,0 AS bet
			,0 AS win
			,sum(B.bet) AS bet_direct
			,sum(B.win_lose) AS win_lose_direct
			,sum(B.bet_direct + B.bet_team) AS bet_team
			,sum(B.win_lose_direct + B.win_lose_team) AS win_lose_team
			FROM ".env('DB_DATABASE').".member_agent AS MA
			JOIN ".env('DB_DATABASE').".summary_bet_records_team AS B ON B.member_id = MA.member_id
			WHERE MA.agent_level = (MaxLevel + 1)
            AND B.summary_time >= StartTime AND B.summary_time < EndTime
			GROUP BY MA.member_id,B.vendor_id,B.game_id
		) AS D
		GROUP BY D.member_id,D.vendor_id,D.game_id;
        SET MaxLevel = MaxLevel - 1;
    END WHILE;
    
END";

        DB::unprepared("DROP procedure IF EXISTS ".$procedureName."");
        DB::unprepared($procedure);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('summary_bet_records_team', function (Blueprint $table) {
            //drop index
            $table->dropIndex('member_id');
            $table->dropIndex('summary_time');
            $table->dropIndex('vendor_id');
            $table->dropIndex('game_id');
        });
        Schema::dropIfExists('summary_bet_records_team');
    }
}
