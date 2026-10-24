<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ModifySpCalcUnlimitBonus1221 extends Migration
{
    protected $procedure_name = 'SP_calc_unlimit_bonus';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(IN SummaryDate datetime)
BEGIN
	# 無限代計算
    # 2020-10-05 Mars 無限代計算unlimit_systemrate依agents.cash_flow_group_id分組
    # 2020-12-17 Mars 無限代計算valid_bet_rate改用vendor_info_games
    
	DECLARE MaxLevel int;
    DECLARE StartTime datetime;
    DECLARE EndTime datetime;
    DECLARE AdminUser int;
    
    SET AdminUser = 0;
    SET SummaryDate = IFNULL(SummaryDate, DATE(date_add(NOW(), interval -16 HOUR)));
    SET StartTime = DATE_ADD(SummaryDate, interval -8 HOUR);
    SET EndTime = DATE_ADD(StartTime, interval 1 DAY);
    SET MaxLevel = (SELECT MAX(agent_level) FROM member_agent);
    
	IF EXISTS(SELECT * FROM unlimit_bonusreport WHERE summary_date = SummaryDate) THEN
		#先扣除unlimit_summary_bonus已經計算的數據
		REPLACE INTO unlimit_summary_bonus(member_id, performance_amount, bonus_amount, withdraw_amount, created_at, updated_at, admin)	
		SELECT br.member_id
			, IFNULL(sb.performance_amount, 0) - IFNULL(br.performance_amount, 0) AS performance_amount
			, IFNULL(sb.bonus_amount, 0) - IFNULL(br.bonus_amount, 0) AS bonus_amount
			, IFNULL(sb.withdraw_amount, 0) AS withdraw_amount
			, IFNULL(sb.created_at, NOW()) AS created_at
			, NOW() AS updated_at
			, AdminUser
		FROM
		(
			SELECT member_id
			,IFNULL(performance_direct + performance_team, 0) AS performance_amount
			,IFNULL(bonus_direct + bonus_team, 0) AS bonus_amount
			FROM unlimit_bonusreport 
			WHERE summary_date = SummaryDate
		) AS br 
        LEFT JOIN unlimit_summary_bonus AS sb ON br.member_id = sb.member_id;
        
        #刪除unlimit_bonusreport對應的數據
		DELETE FROM unlimit_bonusreport 
		WHERE summary_date = SummaryDate;
    END IF;

    WHILE MaxLevel > 0 
    DO
		INSERT INTO unlimit_bonusreport(member_id, summary_date, performance, performance_direct, performance_team
			, bonus_direct, bonus_team, bonus_amount, created_at, updated_at, admin)
		SELECT B.member_id
			, SummaryDate
			, B.performance
            , B.performance_direct
            , B.performance_team
            , TRUNCATE(B.bonus_direct, 4)
			, IFNULL(TRUNCATE(SUM((B.bonus_amount - L.bonus_amount) / 10000.00 * L.performance_team), 4), 0) AS bonus_team
			, B.bonus_amount
            , NOW()
			, NOW()
            , AdminUser
		FROM
		(
			SELECT D.member_id
			, D.performance
            , D.performance_direct
            , D.performance_team
			, D.performance_direct * S.bonus_amount / 10000.00 AS bonus_direct
			, S.bonus_amount
			FROM
			(
                SELECT Z.member_id
					, IFNULL(SUM(Z.bet * IFNULL(IFNULL(VIG.valid_bet_rate, V.valid_bet_rate), 0)), 0) AS performance
                    , IFNULL(SUM(Z.bet_direct * IFNULL(IFNULL(VIG.valid_bet_rate, V.valid_bet_rate), 0)), 0) AS performance_direct
                    , IFNULL(SUM(Z.bet_team * IFNULL(IFNULL(VIG.valid_bet_rate, V.valid_bet_rate), 0)), 0) AS performance_team
                    , IFNULL(SUM(Z.bet_direct * IFNULL(IFNULL(VIG.valid_bet_rate, V.valid_bet_rate), 0)) 
							+ SUM(Z.bet_team * IFNULL(IFNULL(VIG.valid_bet_rate, V.valid_bet_rate), 0)), 0) AS performance_total
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
						FROM summary_bet_records_team AS B
						JOIN member_agent AS MA ON B.member_id = MA.member_id
						JOIN agents AS A ON A.id = MA.parent_id
						WHERE B.summary_time >= StartTime AND B.summary_time < EndTime
						AND MA.agent_level = MaxLevel
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
					) AS AV ON AV.vendor_id = T.vendor_id AND T.hierarchy_path LIKE CONCAT(AV.hierarchy_path, '%')
					GROUP BY T.member_id, T.game_id HAVING MAX(AV.indexs)
				) AS Z
                JOIN vendors AS V ON V.id = Z.vendor_id
                JOIN vendor_info_games AS VIG ON VIG.game_id = Z.game_id AND VIG.vendor_info_id = Z.vendor_info_id
                GROUP BY Z.member_id
			) AS D 
            JOIN members AS M ON D.member_id = M.id
            JOIN agents AS A ON M.parent = A.id
			JOIN unlimit_systemrate AS S 
				ON A.cash_flow_group_id = S.agent_group_id 
				AND D.performance_total >= S.min_amount 
                AND (D.performance_total <= S.max_amount || S.max_amount = 0)
		) AS B LEFT JOIN
		(
			SELECT A.topper_member_id
				, B.member_id
				, B.bonus_amount
                , (performance_direct + performance_team) AS performance_team
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
		, SummaryDate
		, B.performance_direct	#直屬業績
		, B.performance_team		#團隊業績(不含直屬)
		, TRUNCATE(B.bonus_direct, 4)  #直屬業績獎勵
		, IFNULL(TRUNCATE(SUM((B.bonus_amount - L.bonus_amount) / 10000.00 * L.performance_team), 4), 0) AS bonus_team #團隊業績獎勵
		, B.bonus_amount
		, AdminUser
	FROM
	(
		#直屬業績
		SELECT D.parent_id
			, D.performance_direct
			, D.performance_team
			, D.performance_direct * S.bonus_amount / 10000.00 AS bonus_direct
			, S.bonus_amount
		FROM
		(
			SELECT A.parent_id
				, IFNULL(SUM(B.performance), 0) AS performance_direct #直屬業績
				, IFNULL(SUM(B.performance_direct) + SUM(B.performance_team), 0) AS performance_team #團隊業績(不含直屬)
				, IFNULL(SUM(B.performance) + SUM(B.performance_direct) + SUM(B.performance_team), 0) AS performance_total #全部業績
			FROM unlimit_bonusreport AS B
			JOIN member_agent AS A ON B.member_id = A.member_id
			WHERE B.summary_date = SummaryDate
			AND A.agent_level = MaxLevel
			GROUP BY A.parent_id
		) AS D 
        JOIN agents AS A ON D.parent_id = A.id
		JOIN unlimit_systemrate AS S 
			ON A.cash_flow_group_id = S.agent_group_id 
			AND D.performance_total >= S.min_amount 
            AND (D.performance_total <= S.max_amount || S.max_amount = 0)
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
