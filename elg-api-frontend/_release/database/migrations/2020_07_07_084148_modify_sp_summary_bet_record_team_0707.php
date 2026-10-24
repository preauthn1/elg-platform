<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ModifySpSummaryBetRecordTeam0707 extends Migration
{
    protected $procedure_name = 'SP_summary_bet_record_team';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `" . $this->procedure_name . "`(IN p_summary_time DATETIME)
BEGIN
	# 2020-07-07 mars 		增加無限代關閉
    
	DECLARE MaxLevel int;
    DECLARE StartTime datetime;
    DECLARE EndTime datetime;
    DECLARE AdminUser int;
    SET AdminUser = 0;
    
    #判斷是否有傳入參數，沒有則抓取前一小時時間區間進行統計
    IF p_summary_time is null THEN
		SET EndTime = DATE_ADD(CURDATE(), interval HOUR(NOW()) HOUR);
		SET StartTime = DATE_ADD(EndTime, interval -1 HOUR);
	ELSE
		SET StartTime = p_summary_time;
		SET EndTime = DATE_ADD(StartTime, interval 1 HOUR);
    END IF;

    SET MaxLevel = (SELECT MAX(agent_level) FROM member_agent);
    
    #判斷是否已經統計過，有則先進行刪除
    IF EXISTS(SELECT * FROM summary_bet_records_team WHERE summary_time = StartTime) THEN
		DELETE FROM summary_bet_records_team
        WHERE summary_time = StartTime;
    END IF;
    
    WHILE MaxLevel > 0 
    DO
        INSERT INTO summary_bet_records_team(summary_time,member_id,vendor_id,game_id,bet,bet_direct,bet_team
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
			FROM member_agent AS MA
            JOIN agents AS A ON a.id = MA.parent_id AND A.agent_mode = 1
			JOIN summary_bet_records AS B ON B.member_id = MA.member_id
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
			FROM member_agent AS MA
            JOIN agents AS A ON a.id = MA.parent_id AND A.agent_mode = 1
			JOIN summary_bet_records_team AS B ON B.member_id = MA.member_id
			WHERE MA.agent_level = (MaxLevel + 1)
            AND B.summary_time >= StartTime AND B.summary_time < EndTime
			GROUP BY MA.member_id,B.vendor_id,B.game_id
		) AS D
		GROUP BY D.member_id,D.vendor_id,D.game_id;
        
        SET MaxLevel = MaxLevel - 1;
    END WHILE;
END";
        DB::unprepared('DROP procedure IF EXISTS ' . $this->procedure_name . '');
        DB::unprepared($procedure);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::unprepared('DROP procedure IF EXISTS ' . $this->procedure_name . '');
    }
}
