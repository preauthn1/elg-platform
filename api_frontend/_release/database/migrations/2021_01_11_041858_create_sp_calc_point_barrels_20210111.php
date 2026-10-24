<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpCalcPointBarrels20210111 extends Migration
{
    protected $procedure_name = 'SP_calc_point_barrels';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(IN p_summary_time DATETIME)
BEGIN
	# 分桶計算
    # 2020/12/30 Mars		Add
    
    DECLARE MaxLevel, SettlementId INT DEFAULT 0;
    DECLARE SummaryTime DATETIME;
    DECLARE PB INT Default 130;
    DECLARE SettingKey INT Default 6100;
    DECLARE PersonalSettingKey INT Default 95001;  #分桶股份佔成數
    
    IF p_summary_time IS NULL THEN
		SET SummaryTime = DATE_ADD(CURDATE(), INTERVAL -2 DAY);
	ELSE
		SET SummaryTime = DATE_ADD(p_summary_time, INTERVAL -1 DAY);
    END IF;
    
    SET SummaryTime = DATE_ADD(DATE(SummaryTime), INTERVAL 16 hour);
    
    # 計算代理階層
    SELECT MAX(z.level) INTO MaxLevel
    FROM (
		SELECT a.id, a.username, a.hierarchy_path, (length(a.hierarchy_path) - length(REPLACE(a.hierarchy_path, '/', ''))) AS level
		FROM agents as a 
		WHERE a.host_id = 0
	) AS z;
    
    # 分桶計算
	WHILE MaxLevel > 1 
    DO
        # Calc Settlement
		INSERT INTO summary_point_barrels(summary_time, agent_id, parent_id, vendor_id, hierarchy_path, share_point, total_turnover, total_nett_profit, self_turnover, self_nett_profit)
        SELECT SummaryTime
				, z.agent_id
                , z.parent_id
                , SettlementId
                , z.hierarchy_path
                , z.share_point
                , z.total_turnover
                , z.total_nett_profit
                , (z.total_turnover - z.self_turnover)
                , (z.total_nett_profit - z.self_nett_profit)
        FROM (
			SELECT x.agent_id
					, x.parent_id
					, x.vendor_id
					, x.hierarchy_path
					, `aps`.`value` AS share_point
					, x.total_turnover
                    , TRUNCATE(x.game_profit * (aps.value / PB) * (x.total_bet / x.turnover), 4) AS total_nett_profit
					, IFNULL(y.self_turnover, 0) AS self_turnover 
					, IFNULL(y.self_nett_profit, 0) AS self_nett_profit
            FROM (
				SELECT l.agent_id
						, l.parent_id
						, sbra.vendor_id
						, l.hierarchy_path
						, IFNULL(SUM(sbra.total_bet), 0) AS total_turnover
						, IFNULL(t.game_profit, 0) AS game_profit
						, IFNULL(SUM(sbra.total_bet), 0) AS total_bet
						, IFNULL(t.turnover, 0) AS turnover
				FROM (
					SELECT a.id as agent_id
							, a.parent as parent_id
							, a.username
							, a.hierarchy_path
							, (length(a.hierarchy_path) - length(REPLACE(a.hierarchy_path, '/', ''))) AS level
					FROM agents as a 
					WHERE a.host_id = 0
				) AS l
				JOIN agents AS a ON a.host_id = 0 
					AND ((length(a.hierarchy_path) - length(REPLACE(a.hierarchy_path, '/', ''))) >= MaxLevel)
					AND (a.hierarchy_path like CONCAT(l.hierarchy_path, '/%') or a.id = l.agent_id)
				JOIN agent_groups AS ag ON ag.id = a.customized_setting_group_id AND ag.agent_group_type = 1
				JOIN agent_customized_settings AS acs ON acs.agent_group_id = ag.id AND acs.setting_key = SettingKey AND acs.value = 1
				JOIN (
					SELECT agent_id
							, summary_time
							, SUM(total_bet) AS total_bet
							, 0 AS vendor_id
					FROM summary_bet_record_agents
					WHERE summary_time = SummaryTime
					GROUP BY agent_id
				) AS sbra ON sbra.agent_id = a.id 
				JOIN summary_point_barrel_winloses AS t ON t.vendor_id = sbra.vendor_id AND t.summary_time = SummaryTime
				WHERE l.level = MaxLevel
				GROUP BY l.agent_id HAVING SUM(sbra.total_bet) > 0
            ) AS x
			LEFT JOIN agent_personal_settings AS aps ON aps.agent_id = x.agent_id AND aps.setting_id = PersonalSettingKey 
			LEFT JOIN (
				SELECT parent_id
						, SettlementId AS vendor_id
						, SUM(total_turnover) AS self_turnover
						, SUM(total_nett_profit) AS self_nett_profit
				FROM summary_point_barrels
				WHERE summary_time = SummaryTime AND vendor_id = SettlementId
				GROUP BY summary_time, parent_id
			) AS y ON y.parent_id = x.agent_id AND y.vendor_id = x.vendor_id
		) AS z
        ON DUPLICATE KEY UPDATE total_turnover = z.total_turnover
			, total_nett_profit = z.total_nett_profit
            , self_turnover = (z.total_turnover - z.self_turnover)
            , self_nett_profit = (z.total_nett_profit - z.self_nett_profit);
        
		# Calc Vendor
		INSERT INTO summary_point_barrels(summary_time, agent_id, parent_id, vendor_id, hierarchy_path, share_point, total_turnover, total_nett_profit, self_turnover, self_nett_profit)
        SELECT SummaryTime
				, z.agent_id
                , z.parent_id
                , z.vendor_id
                , z.hierarchy_path
                , z.share_point
                , z.total_turnover
                , z.total_nett_profit
                , (z.total_turnover - z.self_turnover)
                , (z.total_nett_profit - z.self_nett_profit)
        FROM (
			SELECT x.agent_id
					, x.parent_id
					, x.vendor_id
					, x.hierarchy_path
					, `aps`.`value` AS share_point
					, x.total_turnover
                    , TRUNCATE(x.game_profit * (aps.value / PB) * (x.total_bet / x.turnover), 4) AS total_nett_profit
					, IFNULL(y.self_turnover, 0) AS self_turnover 
					, IFNULL(y.self_nett_profit, 0) AS self_nett_profit
            FROM (
				SELECT l.agent_id
						, l.parent_id
						, sbra.vendor_id
						, l.hierarchy_path
						#, `aps`.`value` AS share_point
						, IFNULL(SUM(sbra.total_bet), 0) AS total_turnover
						#, TRUNCATE((IFNULL(t.game_profit, 0) * (aps.value / PB) * (IFNULL(SUM(sbra.total_bet), 0) / t.turnover)), 4) AS total_nett_profit
						, IFNULL(t.game_profit, 0) AS game_profit
						, IFNULL(SUM(sbra.total_bet), 0) AS total_bet
						, IFNULL(t.turnover, 0) AS turnover
				FROM (
					SELECT a.id as agent_id
							, a.parent as parent_id
							, a.username
							, a.hierarchy_path
							, (length(a.hierarchy_path) - length(REPLACE(a.hierarchy_path, '/', ''))) AS level
					FROM agents as a 
					WHERE a.host_id = 0
				) AS l
				JOIN agents AS a ON a.host_id = 0 
					AND ((length(a.hierarchy_path) - length(REPLACE(a.hierarchy_path, '/', ''))) >= MaxLevel)
					AND (a.hierarchy_path like CONCAT(l.hierarchy_path, '/%') or a.id = l.agent_id)
				JOIN agent_groups AS ag ON ag.id = a.customized_setting_group_id AND ag.agent_group_type = 1
				JOIN agent_customized_settings AS acs ON acs.agent_group_id = ag.id AND acs.setting_key = SettingKey AND acs.value = 1
				JOIN summary_bet_record_agents AS sbra ON sbra.agent_id = a.id
					AND sbra.summary_time = SummaryTime
				JOIN summary_point_barrel_winloses AS t ON t.vendor_id = sbra.vendor_id  AND t.summary_time = SummaryTime
					AND t.summary_time = SummaryTime
				WHERE l.level = MaxLevel
				GROUP BY l.agent_id, sbra.vendor_id 
			) AS x
			LEFT JOIN agent_personal_settings AS aps ON aps.agent_id = x.agent_id AND aps.setting_id = PersonalSettingKey 
			LEFT JOIN (
				SELECT parent_id
						, vendor_id
						, SUM(total_turnover) AS self_turnover
						, SUM(total_nett_profit) AS self_nett_profit
				FROM summary_point_barrels
				WHERE summary_time = SummaryTime
				GROUP BY summary_time, parent_id, vendor_id
			) AS y ON y.parent_id = x.agent_id AND y.vendor_id = x.vendor_id
		) AS z
        ON DUPLICATE KEY UPDATE total_turnover = z.total_turnover
			, total_nett_profit = z.total_nett_profit
            , self_turnover = (z.total_turnover - z.self_turnover)
            , self_nett_profit = (z.total_nett_profit - z.self_nett_profit);
	
		SET MaxLevel = MaxLevel - 1;
    END WHILE;
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
