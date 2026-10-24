<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpTransferBetRecordPussy888 extends Migration
{
    protected $procedure_name = 'SP_transfer_bet_record_pussy888';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $procedure = "CREATE PROCEDURE `" . $this->procedure_name . "`(IN p_StartTime datetime, IN p_EndTime datetime, IN p_IsRecalculated INT)
BEGIN
	#如果要重算特定時間(天or小時)，EX: 2020/06/01 10點資料，p_StartTime = '2020/06/01 10:00:00', p_EndTime = '2020/06/01 10:59:59'
    #p_IsRecalculated: 是否是從新計算，0 or null = 否，1 = 是
    #一般呼叫方式: CALL SP_transfer_bet_record_pussy888(NULL,NULL,NULL);
    #重算呼叫方式: CALL SP_transfer_bet_record_pussy888('2020-05-30 10:00:00','2020-05-30 10:59:59',1);
    
	DECLARE p_ExecStart datetime;
    DECLARE p_ExecEnd datetime;
    DECLARE p_VendorID INT;
    DECLARE p_VendorCode VARCHAR(10);
    
    SET p_VendorID = 23;
    SET p_VendorCode = 'pussy888';
    SET p_ExecStart = NOW();
    SET p_IsRecalculated = IFNULL(p_IsRecalculated, 0);
    
	IF p_StartTime IS NULL THEN
		#取上次排程完成時間
		SET p_StartTime = IFNULL((SELECT stat_end_time FROM summary_control_tables WHERE vendor_id = p_VendorID), DATE_ADD(NOW(), INTERVAL -1 SECOND));
	ELSE 
		#如果有指定起始時間，往前推一秒(因為後續該秒不會被納入計算範圍)
		SET p_StartTime = DATE_ADD(p_StartTime, INTERVAL -1 SECOND);
    END IF;
	IF p_EndTime IS NULL THEN
		#取當前最後一筆訂單時間
		SET p_EndTime = IFNULL((SELECT MAX(updated_at) FROM pussy888_bet_records WHERE updated_at > p_StartTime), p_StartTime);
    END IF;

    #轉入時間段內數據
    INSERT INTO total_bet_records(summary_time, member_id, vendor_id, bet_record_id, game_id, reference_no, bet, valid_bet, win_lose, bet_time, payout_time, admin)
	SELECT DATE_ADD(DATE(b.bet_time_utc), INTERVAL HOUR(b.bet_time_utc) HOUR) AS summary_time
		,m.id AS member_id
		,p_VendorID AS vendor_id
        ,b.id as bet_record_id
		,IFNULL(g.id, 0) AS game_id
		,IFNULL(b.uuid, '') AS reference_no
		,IFNULL(b.bet, 0) AS bet
		,IFNULL(b.bet, 0) AS valid_bet
		,IFNULL(b.win, 0) - IFNULL(b.bet, 0) AS win_lose
		,b.bet_time_utc AS bet_time
		,b.bet_time_utc AS payout_time
		,0 AS admin
	FROM pussy888_bet_records AS b
	JOIN pussy888_accounts AS v ON b.account = v.player_id AND v.member_id > 0
	JOIN members AS m ON m.id = v.member_id
	LEFT JOIN games AS g ON g.vendor_id = p_VendorID AND g.game_type = b.game_id 
	WHERE b.updated_at > p_StartTime AND b.updated_at <= p_EndTime
    AND b.game_id > 0
	ON DUPLICATE KEY UPDATE bet = IFNULL(b.bet, 0)
		, valid_bet = IFNULL(b.bet, 0)
        , win_lose = IFNULL(b.win, 0) - IFNULL(b.bet, 0)
        , bet_time = b.bet_time_utc
        , payout_time = b.bet_time_utc;
    SET p_ExecEnd = NOW();
    
    #更新控制表
    IF p_IsRecalculated = 0 THEN
		UPDATE summary_control_tables
		SET stat_start_time = p_StartTime, stat_end_time = p_EndTime
		,exec_start_time = p_ExecStart, exec_end_time = p_ExecEnd
		WHERE vendor_id = p_VendorID;
    END IF;
    #寫紀錄
    INSERT INTO summary_control_logs(vendor_id,stat_start_time,stat_end_time,exec_start_time,exec_end_time)
    VALUES(p_VendorID, p_StartTime, p_EndTime, p_ExecStart, p_ExecEnd);
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
