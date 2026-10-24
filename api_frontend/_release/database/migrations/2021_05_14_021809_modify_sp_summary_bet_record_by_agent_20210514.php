<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ModifySpSummaryBetRecordByAgent20210514 extends Migration
{
    protected $procedure_name = 'SP_transfer_bet_record_fishing';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `" . $this->procedure_name . "`(IN p_summary_time DATETIME)
BEGIN
	# 將Summary Bet Record的資料依代理及遊戲商做彙整
	#一般呼叫方式: CALL SP_summary_bet_record_by_agent(NULL);
    #重算呼叫方式: CALL SP_summary_bet_record_by_agent('2021-01-01 00:00:00');
    
    # 2020/12/14 Mars 			Add
    # 2020/12/14 Mars 			Add total_commission
    # 2021/04/13 Mars 			Add total_jackpot, total_v_jackpot, game_id
    # 2021/05/13 Mars 			Add total_cost
    
    DECLARE StartTime, EndTime DATETIME;
    DECLARE Admin INT DEFAULT 0;
    
    IF p_summary_time IS NULL THEN
		SET p_summary_time = DATE_ADD(DATE(NOW()), INTERVAL -1 DAY);
	ELSE
		SET p_summary_time = p_summary_time;
    END IF;
    
	SET StartTime = DATE_ADD(p_summary_time, INTERVAL -8 HOUR);
	SET EndTime = DATE_ADD(StartTime, INTERVAL 1 DAY);
    
    INSERT INTO summary_bet_record_agents(summary_time, agent_id, vendor_id, game_type_id
        , total_bet, total_win_lose, total_jackpot, total_share_amount, total_commission
        , total_v_jackpot, total_cost, admin)
    SELECT StartTime, z.agent_id, z.vendor_id, z.game_type_id
			, z.total_bet, z.total_win_lose, z.total_jackpot, z.total_share_amount, z.total_commission
			, z.total_v_jackpot, z.total_cost, Admin
    FROM (
		SELECT a.id AS agent_id
			, r.vendor_id
            , IFNULL(g.type_id, 0) AS game_type_id
			, SUM(r.bet) AS total_bet
			, SUM(r.win_lose) AS total_win_lose
			, SUM(r.jackpot) AS total_jackpot
			, SUM(r.share_amount) AS total_share_amount
			, SUM(r.commission) AS total_commission
			, SUM(r.v_jackpot) AS total_v_jackpot
			, SUM(r.cost) AS total_cost
		FROM summary_bet_records AS r
		JOIN members AS m ON m.id = r.member_id
		JOIN agents AS a ON a.id = m.parent 
        LEFT JOIN games AS g ON g.id = r.game_id 
		WHERE r.summary_time >= StartTime
		AND r.summary_time < EndTime
		GROUP BY a.id, r.vendor_id, g.type_id
		ORDER BY a.id, r.vendor_id
	) AS z
	ON DUPLICATE KEY UPDATE total_bet = IFNULL(z.total_bet, 0)
		, total_win_lose = IFNULL(z.total_win_lose, 0)
		, total_jackpot = IFNULL(z.total_jackpot, 0)
		, total_share_amount = IFNULL(z.total_share_amount, 0)
		, total_commission = IFNULL(z.total_commission, 0)
		, total_v_jackpot = IFNULL(z.total_v_jackpot, 0)
		, total_cost = IFNULL(z.total_cost, 0);
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
