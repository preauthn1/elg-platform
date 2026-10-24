<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ModifySpSummaryBetRecordByAgent20210318 extends Migration
{
    protected $procedure_name = 'SP_summary_bet_record_by_agent';

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
    
    DECLARE StartTime, EndTime DATETIME;
    DECLARE Admin INT DEFAULT 0;
    
    IF p_summary_time IS NULL THEN
		SET p_summary_time = DATE_ADD(DATE(NOW()), INTERVAL -1 DAY);
	ELSE
		SET p_summary_time = p_summary_time;
    END IF;
    
	SET StartTime = DATE_ADD(p_summary_time, INTERVAL -8 HOUR);
	SET EndTime = date_add(StartTime, INTERVAL 1 DAY);
    
    INSERT INTO summary_bet_record_agents(summary_time, agent_id, vendor_id, total_bet, total_win_lose, total_commission, total_v_jackpot, admin)
    SELECT StartTime, z.agent_id, z.vendor_id, z.total_bet, z.total_win_lose, z.total_commission, z.total_v_jackpot, Admin
    FROM (SELECT a.id as agent_id
			, r.vendor_id
			, SUM(r.bet) AS total_bet
			, SUM(r.win_lose) AS total_win_lose
			, SUM(r.commission) AS total_commission
			, SUM(r.v_jackpot) AS total_v_jackpot
		FROM summary_bet_records as r
		JOIN members as m on m.id = r.member_id
		JOIN agents as a on a.id = m.parent 
		WHERE r.summary_time >= StartTime
		AND r.summary_time < EndTime
		GROUP BY a.id, r.vendor_id
		ORDER BY a.id, r.vendor_id
	) AS z
	ON DUPLICATE KEY UPDATE total_bet = IFNULL(z.total_bet, 0)
		, total_win_lose = IFNULL(z.total_win_lose, 0)
		, total_commission = IFNULL(z.total_commission, 0)
		, total_v_jackpot = IFNULL(z.total_v_jackpot, 0);
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
