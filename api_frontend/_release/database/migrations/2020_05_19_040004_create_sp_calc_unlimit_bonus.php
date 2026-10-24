<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpCalcUnlimitBonus extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
		$procedureName = 'SP_calc_unlimit_bonus';
		$procedure = 'CREATE PROCEDURE `' . $procedureName . '`(IN SummaryDate datetime)
BEGIN
	DECLARE MaxLevel int;
    DECLARE StartTime datetime;
    DECLARE EndTime datetime;
    DECLARE AdminUser int;
    SET AdminUser = 0;
    SET SummaryDate = IFNULL(SummaryDate,CURDATE() - 1);
    SET StartTime = DATE_ADD(SummaryDate, interval -8 HOUR);
    SET EndTime = DATE_ADD(StartTime, interval 1 DAY);
    SET MaxLevel = (SELECT MAX(agent_level) FROM member_agent);

	DELETE FROM unlimit_bonusreport 
    WHERE summary_date = SummaryDate;
	
    WHILE MaxLevel > 0 
    DO
		INSERT INTO unlimit_bonusreport(member_id,summary_date,performance,performance_direct,performance_team
        ,bonus_direct,bonus_team,bonus_amount,created_at,updated_at,admin)
		SELECT B.member_id,SummaryDate
		,B.performance,B.performance_direct,B.performance_team,B.bonus_direct
		,SUM((B.bonus_amount - L.bonus_amount) * floor(L.performance_team/10000)) AS bonus_team
		,B.bonus_amount,NOW(),NOW(),AdminUser
		FROM
		(
			SELECT D.member_id
			,D.performance,D.performance_direct,D.performance_team
			,floor((D.performance+D.performance_direct) /10000) * S.bonus_amount AS bonus_direct
			,S.bonus_amount
			FROM
			(
				SELECT B.member_id
				,SUM(B.Bet) AS performance
				,SUM(B.Bet_direct) AS performance_direct
				,SUM(B.Bet_team) AS performance_team
				,SUM(B.Bet) + SUM(B.Bet_direct) +SUM(B.Bet_team) AS performance_total
				FROM summary_bet_records_team AS B
				JOIN member_agent AS A ON B.member_id = A.member_id
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
    
END';
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
		$procedureName = 'SP_calc_unlimit_bonus';
        DB::unprepared('DROP procedure IF EXISTS '.$procedureName.'');
    }
}
