<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpCalcPointBarrelWinlose20210111 extends Migration
{
    protected $procedure_name = 'SP_calc_point_barrel_winlose';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(IN p_summary_date DATE)
BEGIN

	# 分桶當日業績計算
    # call SP_calc_point_barrel_winlose(null);
    # call SP_calc_point_barrel_winlose('2021-01-01');
    #
    # 2020/01/06 Mars		Add
    
    DECLARE SummaryTime DATETIME;
    DECLARE PB INT Default 130;
    DECLARE SettingKey INT Default 6100;
    
    IF p_summary_date IS NULL THEN
		SET SummaryTime = DATE_ADD(CURDATE(), INTERVAL -2 DAY);
	ELSE
		SET SummaryTime = DATE_ADD(p_summary_date, INTERVAL -1 DAY);
    END IF;
    
    SET SummaryTime = DATE_ADD(DATE(SummaryTime), INTERVAL 16 hour);

    # 計算當日總代業績
    INSERT INTO summary_point_barrel_winloses(summary_time, agent_id, vendor_id, turnover, game_spending, game_profit, admin)
    SELECT SummaryTime
			, a.agent_id
			, 0 AS vendor_id 
			, SUM(a.total_bet) AS turnover
            , SUM(a.total_bet) - (SUM(a.total_win_lose) * -1) AS game_spending
			, 0 AS game_profit
            , 0
	FROM (
		SELECT a1.agent_id
				, sbra.total_bet AS total_bet
                , sbra.total_win_lose AS total_win_lose
		FROM summary_bet_record_agents AS sbra
		JOIN agents AS a ON a.id = sbra.agent_id
		JOIN (
			SELECT a.id AS agent_id
			FROM agents AS a 
			JOIN agent_groups AS ag on ag.id = a.customized_setting_group_id AND ag.agent_group_type = 1
			JOIN agent_customized_settings AS acs ON acs.agent_group_id = ag.id AND acs.setting_key = SettingKey AND acs.value = 1
			WHERE a.parent = 1 AND a.host_id = 0
		) AS a1 ON a.hierarchy_path LIKE CONCAT('/1/', a1.agent_id, '/%')
		WHERE sbra.summary_time = SummaryTime
	) AS a
    GROUP BY a.agent_id 
    UNION ALL
    SELECT SummaryTime
			, a.agent_id
			, a.vendor_id
			, SUM(a.total_bet) as turnover
            , SUM(a.total_bet) - (SUM(a.total_win_lose) * -1) AS game_spending
			, (SUM(a.total_win_lose) * -1) as game_profit
            , 0
	FROM (
		SELECT a1.agent_id
				, sbra.vendor_id
                , sbra.total_bet
                , sbra.total_win_lose
		FROM summary_bet_record_agents AS sbra
		JOIN agents AS a ON a.id = sbra.agent_id
		JOIN (
			SELECT a.id AS agent_id
			FROM agents AS a 
			JOIN agent_groups AS ag ON ag.id = a.customized_setting_group_id AND ag.agent_group_type = 1
			JOIN agent_customized_settings AS acs ON acs.agent_group_id = ag.id AND acs.setting_key = SettingKey AND acs.value = 1
			WHERE a.parent = 1 AND a.host_id = 0
		) AS a1 ON a.hierarchy_path LIKE CONCAT('/1/', a1.agent_id, '/%')
		WHERE sbra.summary_time = SummaryTime
	) AS a
	GROUP BY a.agent_id, a.vendor_id;
	
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
