<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSpTransferBetRecordSbo extends Migration
{
    protected $procedure_name = 'SP_transfer_bet_record_sbo';

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
    #一般呼叫方式: CALL SP_transfer_bet_record_sbo(NULL,NULL,NULL);
    #重算呼叫方式: CALL SP_transfer_bet_record_sbo('2020-05-30 10:00:00','2020-05-30 10:59:59',1);
    
	DECLARE p_ExecStart datetime;
    DECLARE p_ExecEnd datetime;
    DECLARE p_VendorID INT;
    DECLARE p_VendorCode VARCHAR(10);
    
    SET p_VendorID = 18;
    SET p_VendorCode = 'sbo';
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
		SET p_EndTime = IFNULL((SELECT MAX(updated_at) FROM sbo_bet_records WHERE updated_at > p_StartTime), p_StartTime);
    END IF;
 
    #轉入時間段內數據
    INSERT INTO total_bet_records(summary_time, member_id, vendor_id, bet_record_id, game_id, reference_no, bet, valid_bet, win_lose, bet_time, payout_time, admin)
	SELECT DATE_ADD(DATE(t.create_time), INTERVAL HOUR(t.create_time) HOUR) AS summary_time
			,t.member_id AS member_id
			,p_VendorID AS vendor_id
			,t.bet_record_id as bet_record_id
			,t.game_id AS game_id
			,t.reference_no AS reference_no
			,t.bet AS bet
			,t.bet AS valid_bet
			,t.win_lose AS win_lose
			,t.create_time AS bet_time
			,t.create_time AS payout_time 
			,0 AS admin
    FROM (
        SELECT max(b.id) as bet_record_id
			, m.id as member_id
			, IFNULL(b.bet_id, '') as reference_no
			, g.id as game_id
            , IFNULL(SUM(bet_amount), 0) as bet
            , IFNULL(SUM(win_amount - bet_amount), 0) as win_lose
            , MAX(create_time) as create_time
        FROM (
			SELECT id, player_name, game_code, bet_id, trans_type
			,IFNULL((
					SELECT SUM(s.bet_amount) AS bet
					FROM sbo_bet_records AS s
					WHERE r.bet_id = s.bet_id and s.create_time < r.create_time
					HAVING SUM(CASE WHEN s.trans_type = 'Payoff' THEN 1 ELSE 0 END) = 0
			), 0) AS bet_amount, win_amount, create_time
			FROM sbo_bet_records as r
			WHERE trans_type = 'Payoff'
			AND updated_at > p_StartTime and updated_at <= p_EndTime
			UNION ALL
			SELECT id, player_name, game_code, bet_id, trans_type, 0 AS bet_amount, win_amount, create_time
			FROM sbo_bet_records as r
			WHERE trans_type = 'cancelPayoff'
			AND updated_at > p_StartTime and updated_at <= p_EndTime
		) AS b
		JOIN sbo_accounts AS v ON b.player_name = v.username AND v.member_id > 0
		JOIN members AS m ON m.id = v.member_id
		LEFT JOIN games AS g ON g.vendor_id = p_VendorID AND b.game_code = g.game_type
		GROUP BY m.id, b.bet_id
    ) AS t
	ON DUPLICATE KEY UPDATE bet = t.bet
		, valid_bet = t.bet
		, win_lose = t.win_lose
        , bet_time = t.create_time
        , payout_time = t.create_time;
    
    SET p_ExecEnd = NOW();
    
    #更新控制表
    IF p_IsRecalculated = 0 THEN
        INSERT INTO summary_control_tables(vendor_id, vendor_code, stat_start_time, stat_end_time, exec_start_time, exec_end_time)
        VALUES(p_VendorId, p_VendorCode, p_StartTime, p_EndTime, p_ExecStart, p_ExecEnd)
        ON DUPLICATE KEY UPDATE stat_start_time = p_StartTime, stat_end_time = p_EndTime, exec_start_time = p_ExecStart, exec_end_time = p_ExecEnd;
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
