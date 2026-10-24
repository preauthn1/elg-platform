<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpTransferBetRecordIsb extends Migration
{
    protected $procedure_name = 'SP_transfer_bet_record_isb';

    /**
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
    #一般呼叫方式: CALL SP_transfer_bet_record_isb(NULL,NULL,NULL);
    #重算呼叫方式: CALL SP_transfer_bet_record_isb('2020-05-30 10:00:00','2020-05-30 10:59:59',1);
    
	DECLARE p_ExecStart datetime;
    DECLARE p_ExecEnd datetime;
    DECLARE p_VendorID INT;
    DECLARE p_VendorCode VARCHAR(10);
    
    SET p_VendorID = 14;
    SET p_VendorCode = 'isb';
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
		SET p_EndTime = IFNULL((SELECT MAX(updated_at) FROM isb_bet_records WHERE created_at > p_StartTime), p_StartTime);
    END IF;
    
    #轉入時間段內數據
	INSERT INTO total_bet_records(summary_time, member_id, vendor_id, bet_record_id, game_id, reference_no, bet, valid_bet, win_lose, bet_time, payout_time, admin)
    SELECT z.summary_time, z.member_id, p_VendorID AS vendor_id, z.bet_record_id, z.game_id, z.reference_no, z.bet, z.valid_bet, z.win_lose, z.bet_time, z.payout_time, 0 AS admin
    FROM (
		SELECT DATE_ADD(DATE(b.time), INTERVAL HOUR(b.time) HOUR) AS summary_time
			,m.id AS member_id
			,b.id as bet_record_id
			,IFNULL(g.id,0) AS game_id
			,IFNULL(b.roundid,'') AS reference_no
			,IFNULL(SUM(b1.amount) / 100, 0) AS bet 
			,IFNULL(SUM(b1.amount) / 100, 0) AS valid_bet
			,IFNULL((SUM(b.amount) / 100 - SUM(b1.amount) / 100), 0) AS win_lose
			,b1.time AS bet_time
			,b.time AS payout_time
		FROM isb_bet_records AS b
		JOIN isb_accounts AS v ON v.player_id = b.playerid AND v.member_id > 0 
		JOIN members AS m ON m.id = v.member_id
		JOIN isb_bet_records AS b1 ON b1.type = 'BET' AND b1.time <= b.time AND b1.roundid = b.roundid
		LEFT JOIN games AS g ON g.vendor_id = p_VendorID AND g.game_type = b.gameid 
		WHERE b.updated_at > p_StartTime and b.updated_at <= p_EndTime 
        AND b.type = 'WIN'
		GROUP BY b.roundid
	) AS z
	ON DUPLICATE KEY UPDATE bet = z.bet
		, valid_bet = z.valid_bet
		, win_lose = z.win_lose
        , bet_time = z.bet_time
        , payout_time = z.payout_time;
    
    SET p_ExecEnd = NOW();
    
    #更新控制表
    IF p_IsRecalculated = 0 THEN
		UPDATE summary_control_tables
		SET stat_start_time = p_StartTime, stat_end_time = p_EndTime
		,exec_start_time = p_ExecStart, exec_end_time = p_ExecEnd
		WHERE vendor_id = p_VendorID;
    END IF;
    #寫紀錄
    INSERT INTO summary_control_logs(vendor_id, stat_start_time, stat_end_time, exec_start_time, exec_end_time)
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
