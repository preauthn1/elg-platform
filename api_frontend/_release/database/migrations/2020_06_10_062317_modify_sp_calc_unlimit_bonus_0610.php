<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ModifySpCalcUnlimitBonus0610 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure_name = 'SP_calc_unlimit_bonus';
        $procedure = "CREATE PROCEDURE `".$procedure_name."`(IN SummaryDate datetime)
BEGIN
	DECLARE MaxLevel int;
    DECLARE StartTime datetime;
    DECLARE EndTime datetime;
    DECLARE AdminUser int;
    SET AdminUser = 0;
    SET SummaryDate = IFNULL(SummaryDate,DATE(date_add(NOW(),interval 8 HOUR)) - 1);
    SET StartTime = DATE_ADD(SummaryDate, interval -8 HOUR);
    SET EndTime = DATE_ADD(StartTime, interval 1 DAY);
    SET MaxLevel = (SELECT MAX(agent_level) FROM member_agent);
	IF EXISTS(SELECT * FROM unlimit_bonusreport WHERE summary_date = SummaryDate) THEN
		#先扣除unlimit_summary_bonus已經計算的數據
		REPLACE INTO unlimit_summary_bonus(member_id, performance_amount, bonus_amount, withdraw_amount, created_at, updated_at, admin)	
		SELECT br.member_id
		,ifnull(sb.performance_amount, 0) - ifnull(br.performance_amount, 0) AS performance_amount
		,ifnull(sb.bonus_amount,0) - ifnull(br.bonus_amount, 0) AS bonus_amount
        ,ifnull(sb.withdraw_amount,0) AS withdraw_amount
        ,ifnull(sb.created_at,NOW()) AS created_at
		,NOW() AS updated_at
        ,AdminUser
		FROM
		(
			SELECT member_id
			,ifnull(performance_direct + performance_team, 0) AS performance_amount
			,ifnull(bonus_direct + bonus_team, 0) AS bonus_amount
			FROM unlimit_bonusreport 
			WHERE summary_date = SummaryDate
		) AS br LEFT JOIN unlimit_summary_bonus AS sb ON br.member_id = sb.member_id;
        #刪除unlimit_bonusreport對應的數據
		DELETE FROM unlimit_bonusreport 
		WHERE summary_date = SummaryDate;
    END IF;

    WHILE MaxLevel > 0 
    DO
		INSERT INTO unlimit_bonusreport(member_id,summary_date,performance,performance_direct,performance_team
        ,bonus_direct,bonus_team,bonus_amount,created_at,updated_at,admin)
		SELECT B.member_id,SummaryDate
		,B.performance,B.performance_direct,B.performance_team,B.bonus_direct
		,ifnull(SUM((B.bonus_amount - L.bonus_amount) / 10000.00 * L.performance_team), 0) AS bonus_team
		,B.bonus_amount,NOW(),NOW(),AdminUser
		FROM
		(
			SELECT D.member_id
			,D.performance,D.performance_direct,D.performance_team
			,D.performance_direct * S.bonus_amount / 10000.00 AS bonus_direct
			,S.bonus_amount
			FROM
			(
				SELECT B.member_id
				,ifnull(SUM(B.Bet * IFNULL(IFNULL(G.valid_bet_rate,V.valid_bet_rate),0)),0) AS performance
				,ifnull(SUM(B.Bet_direct * IFNULL(IFNULL(G.valid_bet_rate,V.valid_bet_rate),0)),0) AS performance_direct
				,ifnull(SUM(B.Bet_team * IFNULL(IFNULL(G.valid_bet_rate,V.valid_bet_rate),0)),0) AS performance_team
				,ifnull(SUM(B.Bet_direct * IFNULL(IFNULL(G.valid_bet_rate,V.valid_bet_rate),0)) 
                + SUM(B.Bet_team * IFNULL(IFNULL(G.valid_bet_rate,V.valid_bet_rate),0)),0) AS performance_total
				FROM summary_bet_records_team AS B
				JOIN member_agent AS A ON B.member_id = A.member_id
                LEFT JOIN games AS G ON B.game_id = G.id
                LEFT JOIN vendors AS V ON B.vendor_id = V.id
				WHERE B.summary_time >= StartTime AND B.summary_time < EndTime
				AND A.agent_level = MaxLevel
				GROUP BY B.member_id
			) AS D 
			JOIN unlimit_systemrate AS S ON D.performance_total >= S.min_amount AND D.performance_total <= S.max_amount
		) AS B LEFT JOIN
		(
			SELECT A.topper_member_id,B.member_id,B.bonus_amount,(performance_direct + performance_team) AS performance_team
			FROM unlimit_bonusreport AS B
			JOIN member_agent AS A ON B.member_id = A.member_id
			WHERE B.summary_date = SummaryDate
			AND A.agent_level = MaxLevel + 1
		) AS L ON L.topper_member_id = B.member_id
		GROUP BY B.member_id;
        SET MaxLevel = MaxLevel - 1;
    END WHILE;
    
	#無限代 代理計算
	SET MaxLevel = 1;
	IF EXISTS(SELECT * FROM unlimit_bonusreport_agents WHERE summary_date = SummaryDate) THEN
        #刪除unlimit_bonusreport_agents對應的數據
		DELETE FROM unlimit_bonusreport_agents 
		WHERE summary_date = SummaryDate;
    END IF;
	
	INSERT INTO unlimit_bonusreport_agents(agent_id,summary_date,performance_direct,performance_team
    ,bonus_direct,bonus_team,bonus_amount,admin)
	SELECT B.parent_id
	,SummaryDate
	,B.performance_direct	#直屬業績
	,B.performance_team		#團隊業績(不含直屬)
	,B.bonus_direct  #直屬業績獎勵
	,ifnull(SUM((B.bonus_amount - L.bonus_amount) / 10000.00 * L.performance_team), 0) AS bonus_team #團隊業績獎勵
	,B.bonus_amount
	,AdminUser
	FROM
	(
		#直屬業績
		SELECT D.parent_id
		,D.performance_direct
		,D.performance_team
		,D.performance_direct * S.bonus_amount / 10000.00 AS bonus_direct
		,S.bonus_amount
		FROM
		(
			SELECT A.parent_id
			,ifnull(SUM(B.performance),0) AS performance_direct #直屬業績
			,ifnull(SUM(B.performance_direct) + SUM(B.performance_team),0) AS performance_team #團隊業績(不含直屬)
			,ifnull(SUM(B.performance) + SUM(B.performance_direct) + SUM(B.performance_team),0) AS performance_total #全部業績
			FROM unlimit_bonusreport AS B
			JOIN member_agent AS A ON B.member_id = A.member_id
			WHERE B.summary_date = SummaryDate
			AND A.agent_level = MaxLevel
			GROUP BY A.parent_id
		) AS D 
		JOIN unlimit_systemrate AS S ON D.performance_total >= S.min_amount AND D.performance_total <= S.max_amount
	) AS B LEFT JOIN
	(
		SELECT A.parent_id,B.bonus_amount,(performance_direct + performance_team) AS performance_team
		FROM unlimit_bonusreport AS B
		JOIN member_agent AS A ON B.member_id = A.member_id
		WHERE B.summary_date = SummaryDate
		AND A.agent_level = MaxLevel
	) AS L ON L.parent_id = B.parent_id
	GROUP BY B.parent_id;
END";
        DB::unprepared('DROP procedure IF EXISTS '.$procedure_name.'');
        DB::unprepared($procedure);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $procedure_name = 'SP_calc_unlimit_bonus';
        DB::unprepared('DROP procedure IF EXISTS '.$procedure_name.'');
    }
}
