<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ModifySpJobVendorHourRerun20220519 extends Migration
{
    protected $procedure_name = 'SP_Job_Vendor_Hour_Rerun';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `".$this->procedure_name."`(
	IN p_Date DATE,
    IN p_Vendor_Id INT
)
BEGIN
	
    # SP_Job_Vendor_Hour_Record重跑
	# 一般呼叫方式: CALL SP_Job_Vendor_Hour_Rerun(NULL, NULL);
    # 重算呼叫方式: CALL SP_Job_Vendor_Hour_Rerun('2021-01-01', 22);
    
    # 2022/05/19 Mars			增加寫入最後投注時間
    
    DECLARE ExcuteTime, StartTime, EndTime DateTime;
    DECLARE Str VARCHAR(500);
    DECLARE i INT DEFAULT 1; #
    DECLARE ExecNum INT DEFAULT 24; #運行次數
    DECLARE IntervalSeconds DECIMAL(5, 3) DEFAULT 1;	#間隔秒數
    
    IF (p_Date IS NULL) THEN
		SET p_Date = DATE_SUB(CURDATE(), INTERVAL 1 DAY);
    END IF;
    
    SET ExcuteTime = DATE_SUB(p_Date, INTERVAL 8 HOUR);
    
    
    WHILE ( i <= ExecNum) DO
		SET StartTime = ExcuteTime;
        SET EndTime = DATE_ADD(StartTime, INTERVAL 1 HOUR);

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
			AND summary_time >= StartTime 
            AND summary_time < EndTime 
			GROUP BY summary_time, vendor_id, member_id, game_id
		) AS t
		ON DUPLICATE KEY UPDATE 
			bet = t.bet,
            win = t.win,
            last_bet_time = t.last_bet_time;
    
        # 延遲
        SELECT i, ExcuteTime, StartTime, EndTime, SLEEP(IntervalSeconds);
        SET ExcuteTime = DATE_ADD(ExcuteTime, INTERVAL 1 HOUR);
        SET i = i + 1;
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
