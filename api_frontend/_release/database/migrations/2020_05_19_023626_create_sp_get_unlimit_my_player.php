<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpGetUnlimitMyPlayer extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
		$procedureName = "SP_get_unlimit_my_player";
		$procedure = "CREATE PROCEDURE `".$procedureName."`(IN p_memberid INT,IN p_starttime DATETIME,IN p_endtime DATETIME)
BEGIN
	SELECT MA.member_id, M.username as member, B.TeamBet as teamContribution, B.SelfBet as memberContribution, MA.TeamCount as teamCount, MA.DirectCount as memberCount
	,CASE WHEN MA.TeamCount > 0 THEN 2 ELSE 1 END memberType
	FROM
	(
		SELECT U.member_id
		,SUM(CASE WHEN L.member_id != U.member_id THEN 1 ELSE 0 END) AS TeamCount
		,SUM(CASE WHEN L.topper_member_id = U.member_id THEN 1 ELSE 0 END) AS DirectCount
		FROM member_agent AS U LEFT JOIN member_agent AS L 
		ON L.agent_relation LIKE CONCAT('%,',U.member_id,',%')
		WHERE U.topper_member_id = p_memberid
		GROUP BY U.member_id
	) AS MA 
	LEFT JOIN
	(
		SELECT U.member_id
		,SUM(B.bet) SelfBet
		,SUM(B.bet_direct + B.bet_team) AS TeamBet
		FROM member_agent AS U
		JOIN summary_bet_records_team AS B ON U.member_id = B.member_id
		WHERE U.topper_member_id = p_memberid AND B.summary_time >= p_starttime AND B.summary_time <= p_endtime
		GROUP BY U.member_id
	) AS B ON MA.member_id = B.member_id
	JOIN members AS M ON M.id = MA.member_id;
END";
        DB::unprepared('DROP procedure IF EXISTS '.$procedureName.'');
        DB::unprepared($procedure);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $procedureName = 'SP_get_unlimit_my_player';
        DB::unprepared('DROP procedure IF EXISTS '.$procedureName.'');
    }
}
