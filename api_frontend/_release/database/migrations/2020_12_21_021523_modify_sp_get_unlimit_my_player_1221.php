<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ModifySpGetUnlimitMyPlayer1221 extends Migration
{
    protected $procedure_name = 'SP_get_unlimit_my_player';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(IN p_memberid INT,IN p_starttime DATETIME,IN p_endtime DATETIME)
BEGIN

	# 我的推廣
    # 2020-12-17 Mars 			無限代轉換比例從games.valid_bet_rate改為使用vendor_info_games.valid_bet_rate
    
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
        SELECT Z.member_id
			, IFNULL(SUM(Z.bet * IFNULL(IFNULL(VIG.valid_bet_rate, V.valid_bet_rate), 0)), 0) AS SelfBet
            , IFNULL(SUM(Z.bet_direct * IFNULL(IFNULL(VIG.valid_bet_rate, V.valid_bet_rate), 0)) 
					+ SUM(Z.bet_team * IFNULL(IFNULL(VIG.valid_bet_rate, V.valid_bet_rate), 0)), 0) AS TeamBet
		FROM (
			SELECT T.member_id, T.vendor_id, T.game_id, T.bet, T.bet_direct, T.bet_team, AV.vendor_info_id
			FROM (
				SELECT B.member_id
						, A.hierarchy_path
						, B.vendor_id
						, B.game_id
						, SUM(B.Bet) as bet
						, SUM(B.Bet_direct) as bet_direct
						, SUM(B.Bet_team) as bet_team
				FROM member_agent AS MA
				JOIN summary_bet_records_team AS B ON B.member_id = MA.member_id
				JOIN agents AS A ON A.id = MA.parent_id
				WHERE B.summary_time >= p_starttime AND B.summary_time <= p_endtime
				AND MA.topper_member_id = p_memberid
				GROUP BY B.member_id, B.game_id
			) AS T
			JOIN (
				SELECT av.agent_id
						, av.vendor_id
						, a.hierarchy_path
						, av.vendor_info_id
						, LOCATE(CONCAT('/', av.agent_id), a.hierarchy_path) AS indexs
				FROM agent_vendors AS av
				JOIN agents AS a ON a.id = av.agent_id AND a.host_id = 0
				WHERE av.status = 1
			) AS AV ON AV.vendor_id = T.vendor_id AND T.hierarchy_path LIKE concat(AV.hierarchy_path, '%')
			GROUP BY T.member_id, T.game_id HAVING MAX(AV.indexs)
		) AS Z
        JOIN vendors AS V ON V.id = Z.vendor_id
        JOIN vendor_info_games AS VIG ON VIG.game_id = Z.game_id AND VIG.vendor_info_id = Z.vendor_info_id
        GROUP BY Z.member_id
	) AS B ON MA.member_id = B.member_id
	JOIN members AS M ON M.id = MA.member_id;
END";
        DB::unprepared('DROP procedure IF EXISTS '.$this->procedure_name.'');
        DB::unprepared($procedure);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::unprepared('DROP procedure IF EXISTS '.$this->procedure_name.'');
    }
}
