<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ModifySpJobVendorHourRecord20220519 extends Migration
{
    protected $procedure_name = 'SP_Job_Vendor_Hour_Record';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(
	IN p_DateHour DATETIME,
    IN p_Vendor_Id INT
)
BEGIN
	# 游戏记录每个人每个游戏商每小时结算（排除真人游戏）
    # 取代原RD-Studio-01 SummaryVendorHourRecord
    # 原本只计算10分钟前的当下小时数据, 因部份注单回来超过10分,造成部份数据未被统计, 所以改成计算前二个小时
    #一般呼叫方式: CALL SP_Job_Vendor_Hour_Record(NULL,NULL);
    #重算呼叫方式: CALL SP_Job_Vendor_Hour_Record('2020-05-30 10:00:00', 1);
    
    # 2021/01/04 Mars 			增加(p_Vendor_Id is null or vendor_id = p_Vendor_Id)
    # 2021/06/08 Mars 			預設計算時間為1 hour修改為3 hour
    # 2022/05/19 Mars			增加寫入最後投注時間
    
    DECLARE StartTime, EndTime DATETIME;
    
    IF p_DateHour IS NULL OR p_DateHour = '' THEN
        SET EndTime = DATE_ADD(CURDATE(), INTERVAL HOUR(NOW()) HOUR);
        SET StartTime = DATE_ADD(EndTime, INTERVAL -3 HOUR);
    ELSE
		SET StartTime = DATE_ADD(DATE(p_DateHour), INTERVAL HOUR(p_DateHour) HOUR);
		SET EndTime = DATE_ADD(StartTime, INTERVAL 1 HOUR);
    END IF;
    
    INSERT INTO vendor_hour_records(
		date_hour, member_id, vendor_id, game_id,
        bet, bet_received, win, win_received, last_bet_time
	)
    SELECT t.summary_time, t.member_id, t.vendor_id, t.game_id,
		t.bet, 0, t.win, 0, t.last_bet_time
    FROM (
		SELECT summary_time
				, member_id
                , vendor_id
                , game_id
                , TRUNCATE(sum(valid_bet), 2) AS bet
                , TRUNCATE(sum(CASE WHEN win_lose > 0 THEN win_lose ELSE 0 END), 2) AS win
                , MAX(payout_time) AS last_bet_time
		FROM total_bet_records 
		WHERE (p_Vendor_Id IS NULL OR vendor_id = p_Vendor_Id)
		AND summary_time BETWEEN StartTime AND EndTime 
		GROUP BY summary_time, vendor_id, member_id, game_id
	) AS t
	ON DUPLICATE KEY UPDATE 
		bet = t.bet,
        win = t.win,
        last_bet_time = t.last_bet_time;
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
